<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';
require_once dirname(__DIR__) . '/templates/components/searchable_multiselect.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}
$loadError = null;

$filtroTexto = msp2NormalizeText((string) ($_GET['filtroTexto'] ?? ''));
$filtroEstadoRaw = trim((string) ($_GET['filtroEstado'] ?? ''));
$filtroEstado = ctype_digit($filtroEstadoRaw) ? (int) $filtroEstadoRaw : 0;
$lineasPermitidas = [10, 25, 50, 100];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}
$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;

$contratos = [];
$localesPorContrato = [];
$arrendatarios = [];
$tiendas = [];
$localesCatalogo = [];
$arriendoConfigByContrato = [];
$arriendoPeriodoActualByContrato = [];
$arriendoPeriodoFallbackByContrato = [];
$garantiaConfigByContrato = [];
$garantiaMetaByContrato = [];
$localCatalogArriendoMap = [];
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$queryBase = $_GET;
unset($queryBase['pagina']);
$redirectToIndex = 'contratos/index.php';
$redirectToQuery = http_build_query($_GET);
if ($redirectToQuery !== '') {
    $redirectToIndex .= '?' . $redirectToQuery;
}

function buildMsp2ContratosQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

try {
    $requiredTables = ['msp_contratos_arriendo', 'msp_contrato_locales', 'msp_tiendas', 'msp_arrendatarios', 'msp_locales'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para contratos: `' . implode('`, `', $missingTables) . '`.');
    }

    $arrStmt = $conn->query(
        'SELECT id_arrendatario, rut, nombre_locatario
         FROM dbo.msp_arrendatarios
         ORDER BY nombre_locatario ASC'
    );
    $arrendatarios = $arrStmt->fetchAll();

    $tiendaCatalogoStmt = $conn->query(
        'SELECT
            t.id_tienda,
            t.id_arrendatario,
            t.nombre_comercial,
            CASE WHEN ca.id_contrato_arriendo IS NULL THEN 0 ELSE 1 END AS tiene_contrato_activo
         FROM dbo.msp_tiendas t
         INNER JOIN dbo.msp_estado_tiendas et
            ON et.id_estado_tienda = t.id_estado_tienda
         OUTER APPLY (
            SELECT TOP (1) c.id_contrato_arriendo
            FROM dbo.msp_contratos_arriendo c
            WHERE c.id_tienda = t.id_tienda
              AND c.estado_contrato IN (1,2)
            ORDER BY c.id_contrato_arriendo DESC
         ) ca
         WHERE UPPER(LTRIM(RTRIM(et.desc_estado))) NOT IN (N\'INACTIVO\', N\'CERRADO\')
           AND (t.fecha_termino IS NULL OR t.fecha_termino >= CONVERT(date, SYSDATETIME()))
         ORDER BY t.nombre_comercial ASC, t.id_tienda ASC'
    );
    $tiendas = $tiendaCatalogoStmt->fetchAll();

    $localesStmt = $conn->query(
        'SELECT
            l.id_local,
            l.cdo_local,
            l.desc_local,
            l.valor_arriendo_uf,
            CASE WHEN cla.id_contrato_arriendo IS NULL THEN 0 ELSE 1 END AS tiene_contrato_activo
         FROM dbo.msp_locales l
         OUTER APPLY (
            SELECT TOP (1) cl.id_contrato_arriendo
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = l.id_local
              AND cl.estado_relacion IN (1,2)
              AND c.estado_contrato IN (1,2)
            ORDER BY cl.id_contrato_arriendo DESC
         ) cla
         ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
    );
    $localesCatalogo = $localesStmt->fetchAll();
    foreach ($localesCatalogo as $local) {
        $codigo = msp2NormalizeLocalCode((string) ($local['cdo_local'] ?? ''));
        if ($codigo === '') {
            continue;
        }
        $codigoKey = msp2LocalCodeKey($codigo);
        if ($codigoKey === '') {
            continue;
        }
        $localCatalogArriendoMap[$codigoKey] = [
            'codigo' => $codigo,
            'descripcion' => trim((string) ($local['desc_local'] ?? '')),
            'valor_uf_legacy' => isset($local['valor_arriendo_uf']) && is_numeric((string) $local['valor_arriendo_uf'])
                ? number_format((float) $local['valor_arriendo_uf'], 2, '.', '')
                : '',
            'is_obra_modular' => in_array($codigoKey, ['OBRA', 'MODULAR'], true),
        ];
    }

    $conditions = [];
    $params = [];

    if ($filtroTexto !== '') {
        $filtroTextoLike = '%' . $filtroTexto . '%';
        $conditions[] = '(
            ISNULL(t.nombre_comercial, \'\') LIKE :filtro_texto_tienda
            OR ISNULL(a.nombre_locatario, \'\') LIKE :filtro_texto_arrendatario
            OR ISNULL(a.rut, \'\') LIKE :filtro_texto_rut
            OR CAST(c.id_contrato_arriendo AS NVARCHAR(20)) LIKE :filtro_texto_contrato
        )';
        $params[':filtro_texto_tienda'] = $filtroTextoLike;
        $params[':filtro_texto_arrendatario'] = $filtroTextoLike;
        $params[':filtro_texto_rut'] = $filtroTextoLike;
        $params[':filtro_texto_contrato'] = $filtroTextoLike;
    }

    if ($filtroEstado > 0) {
        $conditions[] = 'c.estado_contrato = :filtro_estado';
        $params[':filtro_estado'] = $filtroEstado;
    }

    $whereSql = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

    $fromSql =
        'FROM dbo.msp_contratos_arriendo c
         INNER JOIN dbo.msp_tiendas t ON t.id_tienda = c.id_tienda
         INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = c.id_arrendatario';
    $whereClauseSql = ' WHERE ' . $whereSql;

    $countStmt = $conn->prepare('SELECT COUNT(*) ' . $fromSql . $whereClauseSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalRegistros = (int) $countStmt->fetchColumn();
    $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $lineasPorPagina;

    $contratosStmt = $conn->prepare(
        'SELECT
            c.id_contrato_arriendo,
            c.id_tienda,
            c.id_arrendatario,
            c.fecha_inicio,
            c.fecha_termino_pactada,
            c.monto_arriendo_pactado,
            c.estado_contrato,
            c.fecha_registro,
            a.nombre_locatario,
            a.rut,
            primerLocal.cdo_local_orden
         ' . $fromSql . '
         OUTER APPLY (
            SELECT TOP (1) lsort.cdo_local AS cdo_local_orden
            FROM dbo.msp_contrato_locales clsort
            INNER JOIN dbo.msp_locales lsort ON lsort.id_local = clsort.id_local
            WHERE clsort.id_contrato_arriendo = c.id_contrato_arriendo
              AND clsort.estado_relacion IN (1,2)
            ORDER BY ' . msp2LocalCodeNaturalOrderSql('lsort.cdo_local') . '
         ) primerLocal
         ' . $whereClauseSql . '
         ORDER BY ' . msp2LocalCodeNaturalOrderSql('primerLocal.cdo_local_orden') . ',
                  c.id_contrato_arriendo DESC
         OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY'
    );
    foreach ($params as $key => $value) {
        $contratosStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $contratosStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $contratosStmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
    $contratosStmt->execute();
    $contratos = $contratosStmt->fetchAll();

    $idsContrato = [];
    foreach ($contratos as $row) {
        $idsContrato[] = (int) ($row['id_contrato_arriendo'] ?? 0);
    }
    $idsContrato = array_values(array_unique(array_filter($idsContrato, static fn (int $id): bool => $id > 0)));

    if ($idsContrato !== []) {
        $placeholders = [];
        foreach ($idsContrato as $index => $_id) {
            $placeholders[] = ':id_' . $index;
        }

        $localesContratoStmt = $conn->prepare(
            'SELECT
                cl.id_contrato_arriendo,
                l.cdo_local
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
             WHERE cl.id_contrato_arriendo IN (' . implode(', ', $placeholders) . ')
               AND cl.estado_relacion IN (1,2)
             ORDER BY cl.id_contrato_arriendo DESC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
        );
        foreach ($idsContrato as $index => $_id) {
            $localesContratoStmt->bindValue(':id_' . $index, $_id, PDO::PARAM_INT);
        }
        $localesContratoStmt->execute();

        while (($row = $localesContratoStmt->fetch()) !== false) {
            $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
            $codigo = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
            if ($idContrato <= 0 || $codigo === '') {
                continue;
            }
            if (!isset($localesPorContrato[$idContrato])) {
                $localesPorContrato[$idContrato] = [];
            }
            $localesPorContrato[$idContrato][] = $codigo;
        }

        if (
            msp2TableExists($conn, 'msp_contrato_local_arriendo_regla')
            && msp2TableExists($conn, 'msp_tipo_modalidad_arriendo')
        ) {
            $arriendoConfigStmt = $conn->prepare(
                'SELECT
                    cl.id_contrato_arriendo,
                    l.cdo_local,
                    ISNULL(regla.codigo_modalidad, N\'UF_ESTATICO\') AS codigo_modalidad,
                    regla.valor_base_uf,
                    regla.valor_base_clp,
                    regla.descuento_mensual_clp
                 FROM dbo.msp_contrato_locales cl
                 INNER JOIN dbo.msp_locales l
                    ON l.id_local = cl.id_local
                 OUTER APPLY (
                    SELECT TOP (1)
                        tm.codigo_modalidad,
                        rr.valor_base_uf,
                        rr.valor_base_clp,
                        rr.descuento_mensual_clp,
                        rr.prioridad
                    FROM dbo.msp_contrato_local_arriendo_regla rr
                    LEFT JOIN dbo.msp_tipo_modalidad_arriendo tm
                        ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
                    WHERE rr.id_contrato_local = cl.id_contrato_local
                      AND rr.estado_regla = 1
                      AND rr.es_default = 1
                    ORDER BY rr.prioridad DESC, rr.id_regla_arriendo DESC
                 ) regla
                 WHERE cl.id_contrato_arriendo IN (' . implode(', ', $placeholders) . ')
                   AND cl.estado_relacion IN (1,2)
                 ORDER BY cl.id_contrato_arriendo DESC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            foreach ($idsContrato as $index => $_id) {
                $arriendoConfigStmt->bindValue(':id_' . $index, $_id, PDO::PARAM_INT);
            }
            $arriendoConfigStmt->execute();
            while (($rowCfg = $arriendoConfigStmt->fetch()) !== false) {
                $idContratoCfg = (int) ($rowCfg['id_contrato_arriendo'] ?? 0);
                $codigoCfg = msp2NormalizeLocalCode((string) ($rowCfg['cdo_local'] ?? ''));
                $codigoKeyCfg = msp2LocalCodeKey($codigoCfg);
                if ($idContratoCfg <= 0 || $codigoKeyCfg === '') {
                    continue;
                }
                if (!isset($arriendoConfigByContrato[$idContratoCfg])) {
                    $arriendoConfigByContrato[$idContratoCfg] = [];
                }
                $arriendoConfigByContrato[$idContratoCfg][$codigoKeyCfg] = [
                    'modalidad' => strtoupper(trim((string) ($rowCfg['codigo_modalidad'] ?? 'UF_ESTATICO'))),
                    'valor_base_uf' => isset($rowCfg['valor_base_uf']) && is_numeric((string) $rowCfg['valor_base_uf'])
                        ? number_format((float) $rowCfg['valor_base_uf'], 2, '.', '')
                        : '',
                    'valor_base_clp' => isset($rowCfg['valor_base_clp']) && is_numeric((string) $rowCfg['valor_base_clp'])
                        ? number_format((float) $rowCfg['valor_base_clp'], 0, '.', '')
                        : '',
                    'descuento_mensual_clp' => isset($rowCfg['descuento_mensual_clp']) && is_numeric((string) $rowCfg['descuento_mensual_clp'])
                        ? number_format((float) $rowCfg['descuento_mensual_clp'], 0, '.', '')
                        : '0',
                ];
            }
        }

        if (msp2TableExists($conn, 'msp_contrato_local_arriendo_periodo')) {
            $periodoActualFacturacion = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
            $arriendoPeriodoActualStmt = $conn->prepare(
                'SELECT
                    cl.id_contrato_arriendo,
                    CONVERT(CHAR(10), ap_ref.periodo_facturacion, 126) AS periodo_facturacion,
                    ap_ref.valor_periodo_uf,
                    ap_ref.valor_periodo_clp
                 FROM dbo.msp_contrato_locales cl
                 OUTER APPLY (
                    SELECT TOP (1)
                        ap.periodo_facturacion,
                        ap.valor_periodo_uf,
                        ap.valor_periodo_clp
                    FROM dbo.msp_contrato_local_arriendo_periodo ap
                    WHERE ap.id_contrato_local = cl.id_contrato_local
                      AND ap.estado_periodo = 1
                    ORDER BY
                        CASE
                            WHEN ap.periodo_facturacion = :periodo_actual_eq THEN 0
                            WHEN ap.periodo_facturacion < :periodo_actual_lt THEN 1
                            ELSE 2
                        END ASC,
                        CASE WHEN ap.periodo_facturacion <= :periodo_actual_lte THEN ap.periodo_facturacion END DESC,
                        CASE WHEN ap.periodo_facturacion > :periodo_actual_gt THEN ap.periodo_facturacion END ASC
                 ) ap_ref
                 WHERE cl.id_contrato_arriendo IN (' . implode(', ', $placeholders) . ')
                   AND cl.estado_relacion IN (1,2)
                   AND ap_ref.periodo_facturacion IS NOT NULL'
            );
            $arriendoPeriodoActualStmt->bindValue(':periodo_actual_eq', $periodoActualFacturacion, PDO::PARAM_STR);
            $arriendoPeriodoActualStmt->bindValue(':periodo_actual_lt', $periodoActualFacturacion, PDO::PARAM_STR);
            $arriendoPeriodoActualStmt->bindValue(':periodo_actual_lte', $periodoActualFacturacion, PDO::PARAM_STR);
            $arriendoPeriodoActualStmt->bindValue(':periodo_actual_gt', $periodoActualFacturacion, PDO::PARAM_STR);
            foreach ($idsContrato as $index => $_id) {
                $arriendoPeriodoActualStmt->bindValue(':id_' . $index, $_id, PDO::PARAM_INT);
            }
            $arriendoPeriodoActualStmt->execute();
            while (($rowPeriodo = $arriendoPeriodoActualStmt->fetch()) !== false) {
                $idContratoPeriodo = (int) ($rowPeriodo['id_contrato_arriendo'] ?? 0);
                if ($idContratoPeriodo <= 0) {
                    continue;
                }
                $periodoCargado = substr(trim((string) ($rowPeriodo['periodo_facturacion'] ?? '')), 0, 10);
                $isPeriodoActual = $periodoCargado === $periodoActualFacturacion;
                if ($isPeriodoActual) {
                    if (!isset($arriendoPeriodoActualByContrato[$idContratoPeriodo])) {
                        $arriendoPeriodoActualByContrato[$idContratoPeriodo] = [
                            'uf' => [],
                            'clp' => [],
                        ];
                    }
                } else {
                    if (!isset($arriendoPeriodoFallbackByContrato[$idContratoPeriodo])) {
                        $arriendoPeriodoFallbackByContrato[$idContratoPeriodo] = [
                            'periodo' => '',
                            'uf' => [],
                            'clp' => [],
                        ];
                    }
                    $periodoFallbackActual = (string) ($arriendoPeriodoFallbackByContrato[$idContratoPeriodo]['periodo'] ?? '');
                    if ($periodoFallbackActual === '' || $periodoCargado > $periodoFallbackActual) {
                        $arriendoPeriodoFallbackByContrato[$idContratoPeriodo] = [
                            'periodo' => $periodoCargado,
                            'uf' => [],
                            'clp' => [],
                        ];
                    } elseif ($periodoCargado !== $periodoFallbackActual) {
                        continue;
                    }
                }

                $destinoPeriodo = $isPeriodoActual
                    ? $arriendoPeriodoActualByContrato[$idContratoPeriodo]
                    : $arriendoPeriodoFallbackByContrato[$idContratoPeriodo];
                if (!is_array($destinoPeriodo)) {
                    $destinoPeriodo = [
                        'uf' => [],
                        'clp' => [],
                    ];
                }
                $valorUfPeriodo = trim((string) ($rowPeriodo['valor_periodo_uf'] ?? ''));
                if ($valorUfPeriodo !== '' && is_numeric($valorUfPeriodo)) {
                    $keyUf = number_format((float) $valorUfPeriodo, 4, '.', '');
                    $destinoPeriodo['uf'][$keyUf] = (float) $valorUfPeriodo;
                }
                $valorClpPeriodo = trim((string) ($rowPeriodo['valor_periodo_clp'] ?? ''));
                if ($valorClpPeriodo !== '' && is_numeric($valorClpPeriodo)) {
                    $keyClp = number_format((float) $valorClpPeriodo, 2, '.', '');
                    $destinoPeriodo['clp'][$keyClp] = (float) $valorClpPeriodo;
                }
                if ($isPeriodoActual) {
                    $arriendoPeriodoActualByContrato[$idContratoPeriodo] = $destinoPeriodo;
                } else {
                    $destinoPeriodo['periodo'] = (string) ($arriendoPeriodoFallbackByContrato[$idContratoPeriodo]['periodo'] ?? $periodoCargado);
                    $arriendoPeriodoFallbackByContrato[$idContratoPeriodo] = $destinoPeriodo;
                }
            }
        }

        if (msp2TableExists($conn, 'msp_garantias')) {
            $tieneGarantiaMedioRecepcion = msp2ColumnExists($conn, 'msp_garantias', 'medio_recepcion');
            $tieneGarantiaReferenciaRecepcion = msp2ColumnExists($conn, 'msp_garantias', 'referencia_recepcion');
            $selectGarantiaMedio = $tieneGarantiaMedioRecepcion
                ? 'g.medio_recepcion'
                : 'CAST(NULL AS NVARCHAR(50)) AS medio_recepcion';
            $selectGarantiaReferencia = $tieneGarantiaReferenciaRecepcion
                ? 'g.referencia_recepcion'
                : 'CAST(NULL AS NVARCHAR(100)) AS referencia_recepcion';
            $garantiaConfigStmt = $conn->prepare(
                'SELECT
                    cl.id_contrato_arriendo,
                    l.cdo_local,
                    gtop.fecha_constitucion,
                    gtop.monto_inicial,
                    gtop.observaciones,
                    gtop.medio_recepcion,
                    gtop.referencia_recepcion
                 FROM dbo.msp_contrato_locales cl
                 INNER JOIN dbo.msp_locales l
                    ON l.id_local = cl.id_local
                 OUTER APPLY (
                    SELECT TOP (1)
                        g.fecha_constitucion,
                        g.monto_inicial,
                        g.observaciones,
                        ' . $selectGarantiaMedio . ',
                        ' . $selectGarantiaReferencia . ',
                        g.id_garantia
                    FROM dbo.msp_garantias g
                    WHERE g.id_contrato_arriendo = cl.id_contrato_arriendo
                      AND g.id_local = cl.id_local
                    ORDER BY g.id_garantia DESC
                 ) gtop
                 WHERE cl.id_contrato_arriendo IN (' . implode(', ', $placeholders) . ')
                   AND cl.estado_relacion IN (1,2)
                 ORDER BY cl.id_contrato_arriendo DESC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            foreach ($idsContrato as $index => $_id) {
                $garantiaConfigStmt->bindValue(':id_' . $index, $_id, PDO::PARAM_INT);
            }
            $garantiaConfigStmt->execute();
            while (($rowGar = $garantiaConfigStmt->fetch()) !== false) {
                $idContratoGar = (int) ($rowGar['id_contrato_arriendo'] ?? 0);
                $codigoGar = msp2NormalizeLocalCode((string) ($rowGar['cdo_local'] ?? ''));
                $codigoKeyGar = msp2LocalCodeKey($codigoGar);
                if ($idContratoGar <= 0 || $codigoKeyGar === '') {
                    continue;
                }
                if (!isset($garantiaConfigByContrato[$idContratoGar])) {
                    $garantiaConfigByContrato[$idContratoGar] = [];
                }
                if (!isset($garantiaMetaByContrato[$idContratoGar])) {
                    $garantiaMetaByContrato[$idContratoGar] = [
                        'medio_recepcion' => '',
                        'referencia_recepcion' => '',
                    ];
                }
                $montoGar = isset($rowGar['monto_inicial']) && is_numeric((string) $rowGar['monto_inicial'])
                    ? number_format((float) $rowGar['monto_inicial'], 0, '.', '')
                    : '';
                if ($montoGar === '') {
                    continue;
                }
                $fechaGarRaw = trim((string) ($rowGar['fecha_constitucion'] ?? ''));
                $fechaGar = '';
                if ($fechaGarRaw !== '') {
                    try {
                        $fechaGar = (new DateTimeImmutable($fechaGarRaw))->format('Y-m-d');
                    } catch (Throwable) {
                        $fechaGar = substr($fechaGarRaw, 0, 10);
                    }
                }
                $garantiaConfigByContrato[$idContratoGar][$codigoKeyGar] = [
                    'habilitada' => true,
                    'monto' => $montoGar,
                    'fecha_constitucion' => $fechaGar,
                    'observaciones' => trim((string) ($rowGar['observaciones'] ?? '')),
                ];
                $medioRecepcion = trim((string) ($rowGar['medio_recepcion'] ?? ''));
                $referenciaRecepcion = trim((string) ($rowGar['referencia_recepcion'] ?? ''));
                if ($medioRecepcion !== '' && ($garantiaMetaByContrato[$idContratoGar]['medio_recepcion'] ?? '') === '') {
                    $garantiaMetaByContrato[$idContratoGar]['medio_recepcion'] = $medioRecepcion;
                }
                if ($referenciaRecepcion !== '' && ($garantiaMetaByContrato[$idContratoGar]['referencia_recepcion'] ?? '') === '') {
                    $garantiaMetaByContrato[$idContratoGar]['referencia_recepcion'] = $referenciaRecepcion;
                }
            }
        }
    }
} catch (Throwable $exception) {
    $loadError = 'No fue posible cargar el módulo de contratos.';
}

$localCatalogArriendoJson = json_encode($localCatalogArriendoMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($localCatalogArriendoJson)) {
    $localCatalogArriendoJson = '{}';
}

if ($loadError === null && $totalPaginas > 1) {
    $pages = [1];
    $start = max(2, $paginaActual - 2);
    $end = min($totalPaginas - 1, $paginaActual + 2);
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = $i;
    }
    if ($totalPaginas > 1) {
        $pages[] = $totalPaginas;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $prev = null;
    foreach ($pages as $page) {
        if ($prev !== null && $page > $prev + 1) {
            $paginationItems[] = 'ellipsis';
        }
        $paginationItems[] = $page;
        $prev = $page;
    }
}

$estadoContratoMap = [
    1 => ['label' => 'Borrador', 'class' => 'text-bg-secondary'],
    2 => ['label' => 'Vigente', 'class' => 'text-bg-success'],
    3 => ['label' => 'En proceso de cierre', 'class' => 'text-bg-warning text-dark'],
    4 => ['label' => 'Terminado', 'class' => 'text-bg-dark'],
    5 => ['label' => 'Anulado', 'class' => 'text-bg-danger'],
];

$fmtFecha = static function (mixed $value): string {
    if (!is_string($value) || trim($value) === '') {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('d-m-Y');
    } catch (Throwable) {
        return '-';
    }
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Contratos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
    <?php msp2RenderSearchableSelectAssets(); ?>
    <?php msp2RenderSearchableMultiSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index msp-contracts-index">
        <header class="msp-management-page-header msp-contracts-page-header">
            <div class="msp-contracts-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Contratos</h1>
            <div class="d-flex flex-wrap gap-2 msp-management-actions msp-contracts-actions">
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalImportarContratos">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar contratos
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoContrato">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nuevo contrato
                </button>
            </div>
        </header>

    <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

    <?php if ($loadError !== null): ?>
        <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
    <?php else: ?>
        <div class="msp-management-filters msp-contracts-filters">
            <form method="get" class="row g-2 align-items-end">
                    <div class="col-12 col-lg-6">
                        <label for="filtroTexto" class="form-label">Contrato, tienda, arrendatario o RUT</label>
                        <input type="text" class="form-control" id="filtroTexto" name="filtroTexto" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Buscar...">
                    </div>
                    <div class="col-12 col-sm-6 col-lg-2">
                        <label for="filtroEstado" class="form-label">Estado</label>
                        <select class="form-select" id="filtroEstado" name="filtroEstado">
                            <option value="">(Todos)</option>
                            <?php foreach ($estadoContratoMap as $idEstado => $dataEstado): ?>
                                <option value="<?php echo $idEstado; ?>" <?php echo $filtroEstado === $idEstado ? 'selected' : ''; ?>>
                                    <?php echo msp2Escape($dataEstado['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-sm-3 col-lg-2">
                        <label for="lineas" class="form-label">Líneas</label>
                        <select class="form-select" id="lineas" name="lineas">
                            <?php foreach ($lineasPermitidas as $lineas): ?>
                                <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                    <?php echo $lineas; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-sm-3 col-lg-2 d-grid">
                        <button type="submit" class="btn btn-primary msp-contract-filter-submit">Filtrar</button>
                    </div>
            </form>
        </div>

        <div class="msp-management-table-responsive">
                <table class="table table-sm align-middle msp-management-table msp-contracts-table">
                    <thead class="table-light">
                        <tr>
                            <th class="contract-id">ID</th>
                            <th class="contract-tenant">Arrendatario</th>
                            <th class="contract-locals">Locales</th>
                            <th class="contract-date">Inicio</th>
                            <th class="contract-date">Término</th>
                            <th class="contract-rent">Base arriendo (referencia)</th>
                            <th class="contract-state">Estado</th>
                            <th class="contract-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($contratos === []): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No hay contratos registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($contratos as $row): ?>
                            <?php
                            $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
                            $locales = $localesPorContrato[$idContrato] ?? [];
                            $estadoId = (int) ($row['estado_contrato'] ?? 0);
                            $estado = $estadoContratoMap[$estadoId] ?? ['label' => 'Desconocido', 'class' => 'text-bg-secondary'];
                            $puedeCerrarOperativo = in_array($estadoId, [1, 2], true);
                            $puedeAnular = in_array($estadoId, [1, 2], true);
                            $puedeTraspasar = in_array($estadoId, [1, 2], true);
                            $puedeCerrarFinanciero = $estadoId === 3;
                            $configContrato = $arriendoConfigByContrato[$idContrato] ?? [];
                            $tieneDinamicoMensual = false;
                            $tieneClpFijo = false;
                            $tieneUfEstatico = false;
                            $valorClpBaseRef = null;
                            $valorUfBaseRef = null;
                            foreach ($configContrato as $cfgLocal) {
                                $modalidadCfg = strtoupper(trim((string) ($cfgLocal['modalidad'] ?? 'UF_ESTATICO')));
                                if ($modalidadCfg === 'DINAMICO_MENSUAL') {
                                    $tieneDinamicoMensual = true;
                                } elseif ($modalidadCfg === 'CLP_FIJO') {
                                    $tieneClpFijo = true;
                                    if ($valorClpBaseRef === null) {
                                        $clpRaw = trim((string) ($cfgLocal['valor_base_clp'] ?? ''));
                                        if ($clpRaw !== '' && is_numeric($clpRaw)) {
                                            $valorClpBaseRef = (float) $clpRaw;
                                        }
                                    }
                                } else {
                                    $tieneUfEstatico = true;
                                    if ($valorUfBaseRef === null) {
                                        $ufRaw = trim((string) ($cfgLocal['valor_base_uf'] ?? ''));
                                        if ($ufRaw !== '' && is_numeric($ufRaw)) {
                                            $valorUfBaseRef = (float) $ufRaw;
                                        }
                                    }
                                }
                            }
                            $baseArriendoRefUi = '-';
                            if ($tieneDinamicoMensual) {
                                $periodoActualConfig = $arriendoPeriodoActualByContrato[$idContrato] ?? ['uf' => [], 'clp' => []];
                                $periodoFallbackConfig = $arriendoPeriodoFallbackByContrato[$idContrato] ?? ['periodo' => '', 'uf' => [], 'clp' => []];
                                $valoresUfActual = array_values((array) ($periodoActualConfig['uf'] ?? []));
                                $valoresClpActual = array_values((array) ($periodoActualConfig['clp'] ?? []));
                                $valoresUfFallback = array_values((array) ($periodoFallbackConfig['uf'] ?? []));
                                $valoresClpFallback = array_values((array) ($periodoFallbackConfig['clp'] ?? []));
                                if ($valoresClpActual !== []) {
                                    if (count($valoresClpActual) === 1) {
                                        $baseArriendoRefUi = 'CLP ' . msp2FormatoDecimal($valoresClpActual[0], 0, '$') . ' (mes actual)';
                                    } else {
                                        $baseArriendoRefUi = 'Dinámico mensual (CLP variable)';
                                    }
                                } elseif ($valoresUfActual !== []) {
                                    if (count($valoresUfActual) === 1) {
                                        $baseArriendoRefUi = 'UF ' . msp2FormatoDecimal($valoresUfActual[0], 2) . ' (mes actual)';
                                    } else {
                                        $baseArriendoRefUi = 'Dinámico mensual (UF variable)';
                                    }
                                } elseif ($valoresClpFallback !== []) {
                                    $periodoLbl = substr((string) ($periodoFallbackConfig['periodo'] ?? ''), 0, 7);
                                    if (count($valoresClpFallback) === 1) {
                                        $baseArriendoRefUi = 'CLP ' . msp2FormatoDecimal($valoresClpFallback[0], 0, '$') . ($periodoLbl !== '' ? ' (' . $periodoLbl . ')' : '');
                                    } else {
                                        $baseArriendoRefUi = 'Dinámico mensual (CLP variable)';
                                    }
                                } elseif ($valoresUfFallback !== []) {
                                    $periodoLbl = substr((string) ($periodoFallbackConfig['periodo'] ?? ''), 0, 7);
                                    if (count($valoresUfFallback) === 1) {
                                        $baseArriendoRefUi = 'UF ' . msp2FormatoDecimal($valoresUfFallback[0], 2) . ($periodoLbl !== '' ? ' (' . $periodoLbl . ')' : '');
                                    } else {
                                        $baseArriendoRefUi = 'Dinámico mensual (UF variable)';
                                    }
                                } else {
                                    $baseArriendoRefUi = 'Dinámico mensual';
                                    if ($tieneClpFijo || $tieneUfEstatico) {
                                        $baseArriendoRefUi .= ' (mixto)';
                                    }
                                }
                            } elseif ($tieneClpFijo) {
                                $baseArriendoRefUi = $valorClpBaseRef !== null
                                    ? ('CLP ' . msp2FormatoDecimal($valorClpBaseRef, 0, '$'))
                                    : 'CLP fijo';
                            } else {
                                $montoUfLegacy = $row['monto_arriendo_pactado'] ?? null;
                                if (is_numeric((string) $montoUfLegacy)) {
                                    $baseArriendoRefUi = 'UF ' . msp2FormatoDecimal($montoUfLegacy, 2);
                                } elseif ($valorUfBaseRef !== null) {
                                    $baseArriendoRefUi = 'UF ' . msp2FormatoDecimal($valorUfBaseRef, 2);
                                }
                            }
                            $arriendoConfigJson = json_encode($arriendoConfigByContrato[$idContrato] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if (!is_string($arriendoConfigJson)) {
                                $arriendoConfigJson = '{}';
                            }
                            $garantiaConfigJson = json_encode($garantiaConfigByContrato[$idContrato] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if (!is_string($garantiaConfigJson)) {
                                $garantiaConfigJson = '{}';
                            }
                            $garantiaMeta = $garantiaMetaByContrato[$idContrato] ?? ['medio_recepcion' => '', 'referencia_recepcion' => ''];
                            ?>
                            <tr>
                                <td class="contract-id"><?php echo $idContrato; ?></td>
                                <td class="contract-tenant"><?php echo msp2Escape((string) ($row['nombre_locatario'] ?? '')); ?></td>
                                <td class="contract-locals"><?php echo $locales !== [] ? msp2Escape(implode(' / ', $locales)) : '-'; ?></td>
                                <td class="contract-date text-nowrap"><?php echo msp2Escape($fmtFecha($row['fecha_inicio'] ?? null)); ?></td>
                                <td class="contract-date text-nowrap"><?php echo msp2Escape($fmtFecha($row['fecha_termino_pactada'] ?? null)); ?></td>
                                <td class="contract-rent"><?php echo msp2Escape($baseArriendoRefUi); ?></td>
                                <td class="contract-state"><span class="badge <?php echo msp2Escape($estado['class']); ?>"><?php echo msp2Escape($estado['label']); ?></span></td>
                                <td class="contract-actions">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <a
                                            href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContrato)); ?>"
                                            class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Ver ficha
                                        </a>
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm js-editar-contrato"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEditarContrato"
                                            data-id-contrato="<?php echo $idContrato; ?>"
                                            data-id-arrendatario="<?php echo (int) ($row['id_arrendatario'] ?? 0); ?>"
                                            data-id-tienda="<?php echo (int) ($row['id_tienda'] ?? 0); ?>"
                                            data-fecha-inicio="<?php echo msp2Escape((string) ($row['fecha_inicio'] ?? '')); ?>"
                                            data-fecha-termino="<?php echo msp2Escape((string) ($row['fecha_termino_pactada'] ?? '')); ?>"
                                            data-monto-arriendo="<?php echo msp2Escape((string) ($row['monto_arriendo_pactado'] ?? '')); ?>"
                                            data-locales="<?php echo msp2Escape(implode(';', $locales)); ?>"
                                            data-arriendo-config="<?php echo msp2Escape($arriendoConfigJson); ?>"
                                            data-garantia-config="<?php echo msp2Escape($garantiaConfigJson); ?>"
                                            data-garantia-medio="<?php echo msp2Escape((string) ($garantiaMeta['medio_recepcion'] ?? '')); ?>">
                                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Editar
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
            <div class="small text-muted">
                Total: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                | Página <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
            </div>

            <?php if ($totalPaginas > 1): ?>
                <nav aria-label="Paginación de contratos">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildMsp2ContratosQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                        </li>
                        <?php foreach ($paginationItems as $item): ?>
                            <?php if ($item === 'ellipsis'): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php else: ?>
                                <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo buildMsp2ContratosQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?php echo buildMsp2ContratosQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </div>
</main>
<?php if ($loadError === null): ?>
    <div class="modal fade" id="modalNuevoContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-new-contract-dialog">
            <form class="modal-content msp-new-contract-modal" method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Nuevo contrato</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToIndex); ?>">
                    <div class="row g-2">
                            <?php
                            $crearArrOptions = [];
                            foreach ($arrendatarios as $arr) {
                                $arrId = (int) ($arr['id_arrendatario'] ?? 0);
                                if ($arrId <= 0) {
                                    continue;
                                }
                                $arrRutRaw = trim((string) ($arr['rut'] ?? ''));
                                $arrRut = msp2RutFormatDisplay($arrRutRaw);
                                $arrNombre = trim((string) ($arr['nombre_locatario'] ?? ''));
                                $arrLabel = ($arrRut !== '' ? '(' . $arrRut . ') ' : '') . $arrNombre;
                                $crearArrOptions[] = [
                                    'value' => (string) $arrId,
                                    'label' => $arrLabel,
                                    'search' => mb_strtolower($arrRutRaw . ' ' . $arrRut . ' ' . $arrNombre, 'UTF-8'),
                                    'attrs' => [
                                        'arrendatario' => (string) $arrId,
                                        'rut' => $arrRut,
                                        'nombre' => $arrNombre,
                                    ],
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12 col-lg-6',
                                'label' => 'Arrendatario',
                                'input_name' => 'id_arrendatario',
                                'input_id' => 'crear_id_arrendatario',
                                'picker_id' => 'crear_arr_picker',
                                'button_id' => 'crear_arr_dropdown_btn',
                                'filter_id' => 'crear_arr_dropdown_filter',
                                'list_id' => 'crear_arr_dropdown_list',
                                'error_id' => 'crear_arr_error',
                                'error_message' => 'Debes seleccionar un arrendatario.',
                                'button_placeholder' => 'Selecciona arrendatario...',
                                'filter_placeholder' => 'Buscar por nombre o RUT',
                                'empty_message' => 'No hay arrendatarios disponibles.',
                                'required' => true,
                                'options' => $crearArrOptions,
                            ]);
                            ?>
                            <?php
                            $crearTiendaOptions = [];
                            foreach ($tiendas as $tienda) {
                                $tiendaId = (int) ($tienda['id_tienda'] ?? 0);
                                $tiendaArrendatarioId = (int) ($tienda['id_arrendatario'] ?? 0);
                                if ($tiendaId <= 0 || $tiendaArrendatarioId <= 0) {
                                    continue;
                                }
                                $tiendaNombre = trim((string) ($tienda['nombre_comercial'] ?? ''));
                                $tieneContratoActivo = ((int) ($tienda['tiene_contrato_activo'] ?? 0)) === 1;
                                $crearTiendaOptions[] = [
                                    'value' => (string) $tiendaId,
                                    'label' => $tiendaNombre !== '' ? $tiendaNombre : ('Tienda #' . $tiendaId),
                                    'search' => mb_strtolower($tiendaNombre, 'UTF-8'),
                                    'attrs' => [
                                        'arrendatario' => (string) $tiendaArrendatarioId,
                                        'contrato-activo' => $tieneContratoActivo ? '1' : '0',
                                    ],
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12 col-lg-6',
                                'label' => 'Tienda',
                                'input_name' => 'id_tienda',
                                'input_id' => 'crear_id_tienda',
                                'picker_id' => 'crear_tienda_picker',
                                'button_id' => 'crear_tienda_dropdown_btn',
                                'filter_id' => 'crear_tienda_dropdown_filter',
                                'list_id' => 'crear_tienda_dropdown_list',
                                'error_id' => 'crear_tienda_error',
                                'error_message' => 'Debes seleccionar una tienda activa y sin contrato vigente.',
                                'button_placeholder' => 'Primero selecciona un arrendatario...',
                                'filter_placeholder' => 'Buscar por nombre comercial',
                                'empty_message' => 'No hay tiendas registradas.',
                                'required' => true,
                                'options' => $crearTiendaOptions,
                            ]);
                            ?>
                            <div class="col-12 mt-0">
                                <div class="form-text">Si el arrendatario aún no tiene una tienda, <a href="<?php echo msp2Escape(msp2Url('tiendas/index.php')); ?>">regístrala en Gestión de Tiendas</a> antes de crear el contrato.</div>
                            </div>
                            <?php
                            $crearLocalesOptions = [];
                            foreach ($localesCatalogo as $local) {
                                $codigo = msp2NormalizeLocalCode((string) ($local['cdo_local'] ?? ''));
                                if ($codigo === '') {
                                    continue;
                                }
                                $tieneContratoActivoLocal = ((int) ($local['tiene_contrato_activo'] ?? 0)) === 1;
                                if ($tieneContratoActivoLocal) {
                                    continue;
                                }
                                $labelLocal = trim((string) ($local['desc_local'] ?? ''));
                                $label = $labelLocal !== '' ? ($codigo . ' - ' . $labelLocal) : $codigo;
                                $crearLocalesOptions[] = [
                                    'code' => $codigo,
                                    'label' => $label,
                                    'search' => mb_strtolower($codigo . ' ' . $label, 'UTF-8'),
                                    'arriendo_uf' => (string) ($local['valor_arriendo_uf'] ?? ''),
                                ];
                            }
                            msp2RenderSearchableMultiSelectField([
                                'wrapper_class' => 'col-12 col-lg-6',
                                'label' => 'Locales',
                                'input_name' => 'cod_locales',
                                'input_id' => 'crear_cod_locales',
                                'picker_id' => 'crear_local_picker',
                                'button_id' => 'crear_local_dropdown_btn',
                                'search_id' => 'crear_locales_buscar',
                                'list_id' => 'crear_locales_list',
                                'selected_container_id' => 'crear_locales_container',
                                'required' => true,
                                'sum_target_id' => 'crear_arriendo_ref',
                                'sum_prefix' => 'Suma referencia UF locales (legado): ',
                                'options' => $crearLocalesOptions,
                            ]);
                            ?>
                        <div class="col-12">
                            <label class="form-label mb-1">Ficha de cobro por local</label>
                            <div class="table-responsive border rounded msp-new-contract-local-table">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Local</th>
                                            <th>Modalidad</th>
                                            <th class="text-end">Valor base UF</th>
                                            <th class="text-end">Valor base CLP</th>
                                            <th>Monto y observaciones garantía</th>
                                        </tr>
                                    </thead>
                                    <tbody id="crear_arriendo_locales_body">
                                        <tr>
                                            <td colspan="5" class="text-muted text-center py-2">Selecciona locales para configurar su cobro y garantía.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="form-text">Configura cada local. <code>CLP_FIJO</code> corresponde al monto mensual del contrato.</div>
                        </div>
                        <div class="col-12 col-md-5 col-xl-3">
                            <label for="crear_garantia_medio_recepcion" class="form-label">Recepción garantía</label>
                            <input type="hidden" id="crear_garantia_medio_recepcion" name="garantia_medio_recepcion" value="Efectivo">
                            <input type="text" class="form-control" value="Efectivo (caja)" readonly>
                            <div class="form-text">Medio fijo por política.</div>
                        </div>
                        <div class="col-6 col-md-3 col-xl-2">
                            <label for="crear_fecha_inicio" class="form-label">Inicio contrato</label>
                            <input type="date" class="form-control" id="crear_fecha_inicio" name="fecha_inicio" required>
                        </div>
                        <div class="col-6 col-md-4 col-xl-2">
                            <label for="crear_fecha_termino" class="form-label">Término pactado</label>
                            <input type="date" class="form-control" id="crear_fecha_termino" name="fecha_termino_pactada">
                        </div>
                        <div class="col-12 col-xl-5">
                            <label for="crear_monto_arriendo" class="form-label">Arriendo base de referencia (UF)</label>
                            <input type="text" class="form-control" id="crear_monto_arriendo" name="monto_arriendo_pactado" placeholder="0,00" inputmode="decimal" autocomplete="off" data-money-decimals="2">
                            <div class="form-text" id="crear_arriendo_ref">Referencia UF de locales: -</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Crear contrato</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalEditarContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/actualizar.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Editar contrato</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_arriendo" id="edit_id_contrato_arriendo">
                    <input type="hidden" name="redirect_to" value="<?php echo msp2Escape($redirectToIndex); ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <?php
                            $editArrOptions = [];
                            foreach ($arrendatarios as $arr) {
                                $arrId = (int) ($arr['id_arrendatario'] ?? 0);
                                if ($arrId <= 0) {
                                    continue;
                                }
                                $arrRutRaw = trim((string) ($arr['rut'] ?? ''));
                                $arrRut = msp2RutFormatDisplay($arrRutRaw);
                                $arrNombre = trim((string) ($arr['nombre_locatario'] ?? ''));
                                $arrLabel = ($arrRut !== '' ? '(' . $arrRut . ') ' : '') . $arrNombre;
                                $editArrOptions[] = [
                                    'value' => (string) $arrId,
                                    'label' => $arrLabel,
                                    'search' => mb_strtolower($arrRutRaw . ' ' . $arrRut . ' ' . $arrNombre, 'UTF-8'),
                                    'attrs' => [
                                        'arrendatario' => (string) $arrId,
                                        'rut' => $arrRut,
                                        'nombre' => $arrNombre,
                                    ],
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12',
                                'label' => 'Arrendatario',
                                'input_name' => 'id_arrendatario',
                                'input_id' => 'edit_id_arrendatario',
                                'picker_id' => 'edit_arr_picker',
                                'button_id' => 'edit_arr_dropdown_btn',
                                'filter_id' => 'edit_arr_dropdown_filter',
                                'list_id' => 'edit_arr_dropdown_list',
                                'error_id' => 'edit_arr_error',
                                'error_message' => 'Debes seleccionar un arrendatario.',
                                'button_placeholder' => 'Selecciona arrendatario...',
                                'filter_placeholder' => 'Buscar por nombre o RUT',
                                'empty_message' => 'No hay arrendatarios disponibles.',
                                'required' => true,
                                'options' => $editArrOptions,
                            ]);
                            ?>
                            <?php
                            $editTiendaOptions = [];
                            foreach ($tiendas as $tienda) {
                                $tiendaId = (int) ($tienda['id_tienda'] ?? 0);
                                $tiendaArrendatarioId = (int) ($tienda['id_arrendatario'] ?? 0);
                                if ($tiendaId <= 0 || $tiendaArrendatarioId <= 0) {
                                    continue;
                                }
                                $tiendaNombre = trim((string) ($tienda['nombre_comercial'] ?? ''));
                                $tieneContratoActivo = ((int) ($tienda['tiene_contrato_activo'] ?? 0)) === 1;
                                $editTiendaOptions[] = [
                                    'value' => (string) $tiendaId,
                                    'label' => $tiendaNombre !== '' ? $tiendaNombre : ('Tienda #' . $tiendaId),
                                    'search' => mb_strtolower($tiendaNombre, 'UTF-8'),
                                    'attrs' => [
                                        'arrendatario' => (string) $tiendaArrendatarioId,
                                        'contrato-activo' => $tieneContratoActivo ? '1' : '0',
                                    ],
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12',
                                'label' => 'Tienda',
                                'input_name' => 'id_tienda',
                                'input_id' => 'edit_id_tienda',
                                'picker_id' => 'edit_tienda_picker',
                                'button_id' => 'edit_tienda_dropdown_btn',
                                'filter_id' => 'edit_tienda_dropdown_filter',
                                'list_id' => 'edit_tienda_dropdown_list',
                                'error_id' => 'edit_tienda_error',
                                'error_message' => 'Debes seleccionar una tienda válida del arrendatario.',
                                'button_placeholder' => 'Selecciona tienda...',
                                'filter_placeholder' => 'Buscar por nombre comercial',
                                'empty_message' => 'No hay tiendas registradas.',
                                'required' => true,
                                'options' => $editTiendaOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12">
                            <?php
                            $editLocalesOptions = [];
                            foreach ($localesCatalogo as $local) {
                                $codigo = msp2NormalizeLocalCode((string) ($local['cdo_local'] ?? ''));
                                if ($codigo === '') {
                                    continue;
                                }
                                $labelLocal = trim((string) ($local['desc_local'] ?? ''));
                                $label = $labelLocal !== '' ? ($codigo . ' - ' . $labelLocal) : $codigo;
                                $editLocalesOptions[] = [
                                    'code' => $codigo,
                                    'label' => $label,
                                    'search' => mb_strtolower($codigo . ' ' . $label, 'UTF-8'),
                                    'arriendo_uf' => (string) ($local['valor_arriendo_uf'] ?? ''),
                                ];
                            }
                            msp2RenderSearchableMultiSelectField([
                                'wrapper_class' => 'col-12',
                                'label' => 'Locales',
                                'input_name' => 'cod_locales',
                                'input_id' => 'edit_cod_locales',
                                'picker_id' => 'edit_local_picker',
                                'button_id' => 'edit_local_dropdown_btn',
                                'search_id' => 'edit_locales_buscar',
                                'list_id' => 'edit_locales_list',
                                'selected_container_id' => 'edit_locales_container',
                                'required' => true,
                                'sum_target_id' => 'edit_arriendo_ref',
                                'sum_prefix' => 'Suma referencia UF locales (legado): ',
                                'options' => $editLocalesOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-1">Ficha de cobro por local</label>
                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Local</th>
                                            <th>Modalidad</th>
                                            <th class="text-end">Valor base UF</th>
                                            <th class="text-end">Valor base CLP</th>
                                            <th>Monto y observaciones garantía</th>
                                        </tr>
                                    </thead>
                                    <tbody id="edit_arriendo_locales_body">
                                        <tr>
                                            <td colspan="5" class="text-muted text-center py-3">Selecciona locales para configurar cobro y garantía por contrato-local.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="form-text">La configuración reemplaza la regla default por contrato-local en cada local activo del contrato.</div>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_garantia_medio_recepcion" class="form-label">Medio recepción garantía</label>
                            <input type="hidden" id="edit_garantia_medio_recepcion" name="garantia_medio_recepcion" value="Efectivo">
                            <input type="text" class="form-control" value="Efectivo (caja)" readonly>
                            <div class="form-text">Fijo por política: toda garantía ingresa como efectivo a caja.</div>
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="edit_fecha_inicio" class="form-label">Inicio contrato</label>
                            <input type="date" class="form-control" id="edit_fecha_inicio" name="fecha_inicio" required>
                        </div>
                        <div class="col-12 col-md-3">
                            <label for="edit_fecha_termino" class="form-label">Término pactado</label>
                            <input type="date" class="form-control" id="edit_fecha_termino" name="fecha_termino_pactada">
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="edit_monto_arriendo" class="form-label">Arriendo base ref. contrato (UF)</label>
                            <input type="text" class="form-control" id="edit_monto_arriendo" name="monto_arriendo_pactado" placeholder="0,00" inputmode="decimal" autocomplete="off" data-money-decimals="2">
                            <div class="form-text" id="edit_arriendo_ref">Suma referencia UF locales (legado): -</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalArriendoDinamicoContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <form class="modal-content" id="form_arriendo_dinamico_contrato" method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_arriendo_periodo_contrato.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Arriendo dinámico anual</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_arriendo" id="dyn_id_contrato_arriendo">
                    <div class="row g-3 mb-2">
                        <div class="col-12 col-md-3">
                            <label for="dyn_anio" class="form-label">Año</label>
                            <input type="number" class="form-control" id="dyn_anio" name="anio" min="2000" max="2100" step="1" required>
                        </div>
                        <div class="col-12 col-md-9">
                            <div class="small text-muted mt-4 pt-2" id="dyn_contrato_detalle">Contrato ID -</div>
                        </div>
                    </div>
                    <div class="alert alert-info py-2 small">
                        Vista anual: una fila por mes y por contrato-local dinámico. Puedes editar valores directamente en la tabla.
                    </div>
                    <div id="dyn_feedback" class="small mb-2"></div>
                    <div class="table-responsive border rounded">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Mes</th>
                                    <th class="text-end">UF período</th>
                                    <th class="text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="dyn_rows_body">
                                <tr>
                                    <td colspan="3" class="text-muted text-center py-3">Selecciona un contrato con modalidad dinámica.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    <button type="submit" class="btn btn-primary" id="dyn_submit_btn">
                        <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar año
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalCerrarContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/cerrar.php')); ?>" data-confirm-message="¿Confirmar término operativo? Se bloqueará si tiene cargos activos o reservas en garantía." data-confirm-title="Confirmar término operativo" data-confirm-variant="danger">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Término operativo</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_arriendo" id="cerrar_id_contrato_arriendo">
                    <input type="hidden" name="redirect_to" value="contratos/index.php">
                    <p class="mb-2">Vas a registrar término operativo del contrato <strong id="cerrar_contrato_label">#-</strong>.</p>
                    <p class="text-muted small mb-3" id="cerrar_contrato_detalle">-</p>
                    <div class="alert alert-info py-2 small">
                        Se libera la ocupación física y el contrato pasa a <strong>proceso de cierre</strong>.
                    </div>
                    <div class="mb-3">
                        <label for="cerrar_fecha_termino_efectiva" class="form-label">Fecha término efectiva</label>
                        <input type="date" class="form-control" id="cerrar_fecha_termino_efectiva" name="fecha_termino_efectiva" required>
                    </div>
                    <div id="cerrar_precheck_estado" class="small text-muted mb-3">Validación pendiente.</div>
                    <div id="cerrar_precheck_detalle" class="small"></div>
                    <label for="cerrar_motivo" class="form-label">Motivo</label>
                    <textarea class="form-control" id="cerrar_motivo" name="motivo_cierre" rows="3" maxlength="500" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger" id="cerrar_submit_btn">Confirmar término operativo</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalAnularContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/anular.php')); ?>" data-confirm-message="¿Confirmar anulación? El contrato no continuará al proceso de cierre y sus locales quedarán disponibles." data-confirm-title="Confirmar anulación" data-confirm-variant="danger">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Anular contrato</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_arriendo" id="anular_id_contrato_arriendo">
                    <p class="mb-2">Vas a anular el contrato <strong id="anular_contrato_label">#-</strong>.</p>
                    <p class="text-muted small mb-3" id="anular_contrato_detalle">-</p>
                    <div class="alert alert-warning py-2 small">
                        Solo procede si no tiene documentos, cálculos mensuales, cargos ni garantías con saldo. La operación conserva el contrato como antecedente y libera sus locales.
                    </div>
                    <label for="anular_motivo" class="form-label">Motivo de anulación</label>
                    <textarea class="form-control" id="anular_motivo" name="motivo_anulacion" rows="3" maxlength="500" required></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Confirmar anulación</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalTraspasarContrato" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/traspasar.php')); ?>" data-confirm-message="¿Confirmar traspaso de contrato? El contrato origen quedará en proceso de cierre." data-confirm-title="Confirmar traspaso" data-confirm-variant="warning">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Traspaso por cambio razón social</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_origen" id="traspaso_id_contrato_origen">
                    <input type="hidden" name="redirect_to" value="contratos/index.php">
                    <p class="mb-2">Contrato origen <strong id="traspaso_contrato_label">#-</strong></p>
                    <p class="text-muted small mb-3" id="traspaso_contrato_detalle">-</p>
                    <div class="alert alert-warning py-2 small">
                        El sistema creará un contrato nuevo en la misma tienda, copiará locales activos y transferirá solo saldo disponible de garantía.
                    </div>
                    <div class="row g-3">
                        <div class="col-12">
                            <?php
                            $traspasoArrOptions = [];
                            foreach ($arrendatarios as $arr) {
                                $arrId = (int) ($arr['id_arrendatario'] ?? 0);
                                if ($arrId <= 0) {
                                    continue;
                                }
                                $arrRutRaw = trim((string) ($arr['rut'] ?? ''));
                                $arrRut = msp2RutFormatDisplay($arrRutRaw);
                                $arrNombre = trim((string) ($arr['nombre_locatario'] ?? ''));
                                $arrLabel = ($arrRut !== '' ? '(' . $arrRut . ') ' : '') . $arrNombre;
                                $traspasoArrOptions[] = [
                                    'value' => (string) $arrId,
                                    'label' => $arrLabel,
                                    'search' => mb_strtolower($arrRutRaw . ' ' . $arrRut . ' ' . $arrNombre, 'UTF-8'),
                                    'attrs' => [
                                        'arrendatario' => (string) $arrId,
                                        'rut' => $arrRut,
                                        'nombre' => $arrNombre,
                                    ],
                                ];
                            }
                            msp2RenderSearchableSelectField([
                                'wrapper_class' => 'col-12',
                                'label' => 'Arrendatario destino',
                                'input_name' => 'id_arrendatario_destino',
                                'input_id' => 'traspaso_id_arrendatario_destino',
                                'picker_id' => 'traspaso_arr_picker',
                                'button_id' => 'traspaso_arr_dropdown_btn',
                                'filter_id' => 'traspaso_arr_dropdown_filter',
                                'list_id' => 'traspaso_arr_dropdown_list',
                                'error_id' => 'traspaso_arr_error',
                                'error_message' => 'Debes seleccionar un arrendatario destino.',
                                'button_placeholder' => 'Selecciona arrendatario destino...',
                                'filter_placeholder' => 'Buscar por nombre o RUT',
                                'empty_message' => 'No hay arrendatarios disponibles.',
                                'required' => true,
                                'options' => $traspasoArrOptions,
                            ]);
                            ?>
                        </div>
                        <div class="col-12 col-md-6">
                            <label for="traspaso_fecha" class="form-label">Fecha de traspaso</label>
                            <input type="date" class="form-control" id="traspaso_fecha" name="fecha_traspaso" required>
                        </div>
                        <div class="col-12">
                            <label for="traspaso_motivo" class="form-label">Motivo</label>
                            <textarea class="form-control" id="traspaso_motivo" name="motivo_traspaso" rows="3" maxlength="500" required></textarea>
                        </div>
                    </div>
                    <div id="traspaso_precheck_estado" class="small text-muted mt-3">Validación pendiente.</div>
                    <div id="traspaso_precheck_detalle" class="small mt-1"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning" id="traspaso_submit_btn">Confirmar traspaso</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalFinalizarCierreFinanciero" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/finalizar_cierre_financiero.php')); ?>" data-confirm-message="¿Confirmar cierre financiero definitivo del contrato?" data-confirm-title="Confirmar cierre financiero" data-confirm-variant="danger">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Cierre financiero</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_contrato_arriendo" id="fin_id_contrato_arriendo">
                    <input type="hidden" name="redirect_to" value="contratos/index.php">
                    <p class="mb-2">Contrato <strong id="fin_contrato_label">#-</strong></p>
                    <p class="text-muted small mb-3" id="fin_contrato_detalle">-</p>
                    <div class="mb-3">
                        <label for="fin_periodo_corte_mes" class="form-label">Periodo de corte</label>
                        <input type="month" class="form-control" id="fin_periodo_corte_mes" name="periodo_corte_mes" required>
                        <div class="form-text">Debe ser el último periodo con facturación generada y lecturas conciliadas.</div>
                    </div>
                    <div class="mb-3">
                        <a href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>" id="fin_link_crear_cierre" class="btn btn-outline-primary btn-sm">
                            Ir a crear/cerrar periodo mensual
                        </a>
                    </div>
                    <label for="fin_motivo_cierre" class="form-label">Motivo (opcional)</label>
                    <textarea class="form-control" id="fin_motivo_cierre" name="motivo_cierre_financiero" rows="2" maxlength="500"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-dark">Confirmar cierre definitivo</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<div class="modal fade" id="modalImportarContratos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('contratos/importar.php')); ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Importar contratos desde Excel</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="excel_file_contratos" class="form-label">Archivo</label>
                    <input type="file" class="form-control" id="excel_file_contratos" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="small text-muted">
                    Columnas opcionales para arriendo por contrato-local: <code>modalidad_arriendo</code> (<code>UF_ESTATICO</code> o <code>CLP_FIJO</code>), <code>valor_arriendo_uf</code>, <code>valor_arriendo_clp</code>, <code>descuento_arriendo_clp</code> y <code>garantia_clp</code>. El primer mes se prorratea automáticamente desde la fecha de inicio.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Cargar archivo
                </button>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const currentLocalDate = () => new Date();
    const toInputDateValue = (date) => {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        const d = String(date.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    };
    const toInputMonthValue = (date) => {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
            return '';
        }
        const y = date.getFullYear();
        const m = String(date.getMonth() + 1).padStart(2, '0');
        return `${y}-${m}`;
    };

    const escapeHtml = (value) => String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    const numberFormatter = {
        0: new Intl.NumberFormat('es-CL', { minimumFractionDigits: 0, maximumFractionDigits: 0 }),
        2: new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
    };
    const localCatalogArriendo = <?php echo $localCatalogArriendoJson; ?>;
    const urlDinamicoContratoGet = '<?php echo msp2Escape(msp2Url('contratos/arriendo_periodo_contrato.php')); ?>';
    const urlDinamicoContratoSave = '<?php echo msp2Escape(msp2Url('contratos/guardar_arriendo_periodo_contrato.php')); ?>';

    const normalizeLocalCode = (value) => {
        const code = String(value || '').trim();
        if (!code) return '';
        const withSuffix = code.match(/^([A-Za-z])-([0-9]+)([A-Za-z])$/);
        if (withSuffix) return `${withSuffix[1].toUpperCase()}-${withSuffix[2]}${withSuffix[3].toLowerCase()}`;
        const withoutSuffix = code.match(/^([A-Za-z])-([0-9]+)$/);
        if (withoutSuffix) return `${withoutSuffix[1].toUpperCase()}-${withoutSuffix[2]}`;
        return code.toUpperCase();
    };
    const localCodeKey = (value) => normalizeLocalCode(value).toUpperCase();
    const parseCodesFromString = (raw) => {
        const parts = String(raw || '').split(/[;|,/\n\r]+/);
        const list = [];
        const seen = new Set();
        parts.forEach((part) => {
            const code = normalizeLocalCode(part);
            const key = localCodeKey(code);
            if (!code || !key || seen.has(key)) return;
            seen.add(key);
            list.push(code);
        });
        return list;
    };

    const defaultArriendoConfigForCode = (codeRaw) => {
        const key = localCodeKey(codeRaw);
        const catalog = localCatalogArriendo[key] || {};
        return {
            modalidad: 'UF_ESTATICO',
            valor_base_uf: String(catalog.valor_uf_legacy || ''),
            valor_base_clp: '',
            descuento_mensual_clp: '0',
        };
    };

    const readArriendoConfigMap = (rawValue) => {
        let parsed = {};
        try {
            parsed = JSON.parse(String(rawValue || '{}')) || {};
        } catch (_error) {
            parsed = {};
        }
        const normalized = {};
        Object.entries(parsed).forEach(([code, cfg]) => {
            const key = localCodeKey(code);
            if (!key || typeof cfg !== 'object' || cfg === null) return;
            normalized[key] = {
                modalidad: String(cfg.modalidad || cfg.codigo_modalidad || 'UF_ESTATICO').toUpperCase(),
                valor_base_uf: String(cfg.valor_base_uf || ''),
                valor_base_clp: String(cfg.valor_base_clp || ''),
                descuento_mensual_clp: String(cfg.descuento_mensual_clp || '0'),
            };
        });
        return normalized;
    };

    const readGarantiaConfigMap = (rawValue) => {
        let parsed = {};
        try {
            parsed = JSON.parse(String(rawValue || '{}')) || {};
        } catch (_error) {
            parsed = {};
        }
        const normalized = {};
        Object.entries(parsed).forEach(([code, cfg]) => {
            const key = localCodeKey(code);
            if (!key || typeof cfg !== 'object' || cfg === null) return;
            const monto = String(cfg.monto ?? cfg.monto_inicial ?? '').trim();
            const habilitadaRaw = cfg.habilitada;
            normalized[key] = {
                habilitada: typeof habilitadaRaw === 'boolean' ? habilitadaRaw : monto !== '',
                monto,
                fecha_constitucion: String(cfg.fecha_constitucion || '').trim(),
                observaciones: String(cfg.observaciones || '').trim(),
            };
        });
        return normalized;
    };

    const ensureModalidad = (value) => {
        const normalized = String(value || '').toUpperCase();
        if (['UF_ESTATICO', 'CLP_FIJO'].includes(normalized)) {
            return normalized;
        }
        return 'UF_ESTATICO';
    };

    const captureContratoLocalStateFromBody = (bodyEl, arriendoState, garantiaState) => {
        if (!(bodyEl instanceof HTMLElement)) return;
        bodyEl.querySelectorAll('tr[data-code-key]').forEach((row) => {
            const codeKey = String(row.dataset.codeKey || '');
            if (!codeKey) return;
            const modalidadEl = row.querySelector('.js-arriendo-modalidad');
            const valorUfEl = row.querySelector('.js-arriendo-valor-uf');
            const valorClpEl = row.querySelector('.js-arriendo-valor-clp');
            const descuentoEl = row.querySelector('.js-arriendo-descuento');
            arriendoState[codeKey] = {
                modalidad: ensureModalidad(modalidadEl instanceof HTMLSelectElement ? modalidadEl.value : 'UF_ESTATICO'),
                valor_base_uf: valorUfEl instanceof HTMLInputElement ? String(valorUfEl.value || '') : '',
                valor_base_clp: valorClpEl instanceof HTMLInputElement ? String(valorClpEl.value || '') : '',
                descuento_mensual_clp: descuentoEl instanceof HTMLInputElement ? String(descuentoEl.value || '0') : '0',
            };
            const enableEl = row.querySelector('.js-garantia-enable');
            const montoEl = row.querySelector('.js-garantia-monto');
            const fechaEl = row.querySelector('.js-garantia-fecha');
            const detailRow = bodyEl.querySelector(`tr[data-detail-for="${codeKey}"]`);
            const obsEl = detailRow instanceof HTMLElement ? detailRow.querySelector('.js-garantia-obs') : null;
            const montoValue = montoEl instanceof HTMLInputElement ? parseMoneyNumber(montoEl.value) : null;
            const enabledByMonto = montoValue !== null && montoValue > 0;
            garantiaState[codeKey] = {
                habilitada: enabledByMonto,
                monto: montoEl instanceof HTMLInputElement ? String(montoEl.value || '') : '',
                fecha_constitucion: fechaEl instanceof HTMLInputElement ? String(fechaEl.value || '') : '',
                observaciones: obsEl instanceof HTMLInputElement ? String(obsEl.value || '') : '',
            };
            if (enableEl instanceof HTMLInputElement) {
                enableEl.value = enabledByMonto ? '1' : '0';
            }
        });
    };

    const applyContratoLocalRowMode = (row) => {
        const modalidadEl = row.querySelector('.js-arriendo-modalidad');
        const valorUfEl = row.querySelector('.js-arriendo-valor-uf');
        const valorClpEl = row.querySelector('.js-arriendo-valor-clp');
        if (modalidadEl instanceof HTMLSelectElement && valorUfEl instanceof HTMLInputElement && valorClpEl instanceof HTMLInputElement) {
            const modalidad = ensureModalidad(modalidadEl.value);
            valorUfEl.disabled = modalidad !== 'UF_ESTATICO';
            valorClpEl.disabled = modalidad !== 'CLP_FIJO';
        }

        const enableEl = row.querySelector('.js-garantia-enable');
        const montoEl = row.querySelector('.js-garantia-monto');
        const fechaEl = row.querySelector('.js-garantia-fecha');
        if (montoEl instanceof HTMLInputElement) {
            const montoValue = parseMoneyNumber(montoEl.value);
            const enabledByMonto = montoValue !== null && montoValue > 0;
            if (enableEl instanceof HTMLInputElement) {
                enableEl.value = enabledByMonto ? '1' : '0';
            }
            if (fechaEl instanceof HTMLInputElement && enabledByMonto && String(fechaEl.value || '').trim() === '') {
                const fallbackDate = String(row.dataset.fallbackDate || '').trim();
                if (fallbackDate !== '') {
                    fechaEl.value = fallbackDate;
                }
            }
        }
    };

    const toggleGarantiaDetailRow = (buttonEl) => {
        if (!(buttonEl instanceof HTMLButtonElement)) return;
        const codeKey = String(buttonEl.dataset.codeKey || '').trim();
        if (codeKey === '') return;
        const row = buttonEl.closest('tr[data-code-key]');
        if (!(row instanceof HTMLTableRowElement)) return;
        const body = row.parentElement;
        if (!(body instanceof HTMLElement)) return;
        const detailRow = body.querySelector(`tr[data-detail-for="${codeKey}"]`);
        if (!(detailRow instanceof HTMLTableRowElement)) return;
        const isHidden = detailRow.classList.contains('d-none');
        detailRow.classList.toggle('d-none', !isHidden);
        buttonEl.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        buttonEl.textContent = isHidden ? 'Ocultar nota' : 'Nota';
    };

    const applyClpFijoContratoVisualMode = (prefix) => {
        const body = document.getElementById(`${prefix}_arriendo_locales_body`);
        if (!(body instanceof HTMLElement)) return;
        const rows = Array.from(body.querySelectorAll('tr[data-code-key]'));
        rows.forEach((row) => {
            row.classList.remove('d-none');
            const codeEl = row.querySelector('.js-local-code');
            const descEl = row.querySelector('.js-local-desc');
            if (codeEl instanceof HTMLElement) {
                codeEl.textContent = String(row.dataset.localCode || codeEl.textContent || '');
            }
            if (descEl instanceof HTMLElement) {
                descEl.textContent = String(row.dataset.localDesc || '');
            }
        });

        if (rows.length <= 1) return;
        const clpRows = rows.filter((row) => {
            const modalidadEl = row.querySelector('.js-arriendo-modalidad');
            return modalidadEl instanceof HTMLSelectElement && ensureModalidad(modalidadEl.value) === 'CLP_FIJO';
        });
        if (clpRows.length !== rows.length) return;

        const firstRow = clpRows[0];
        const locales = clpRows.map((row) => String(row.dataset.localCode || '').trim()).filter((v) => v !== '');
        const codeEl = firstRow.querySelector('.js-local-code');
        const descEl = firstRow.querySelector('.js-local-desc');
        if (codeEl instanceof HTMLElement) {
            codeEl.textContent = 'CONTRATO';
        }
        if (descEl instanceof HTMLElement) {
            descEl.textContent = locales.join(' / ');
        }
        clpRows.slice(1).forEach((row) => row.classList.add('d-none'));
    };

    const syncClpFijoContratoRows = (prefix, arriendoState) => {
        const body = document.getElementById(`${prefix}_arriendo_locales_body`);
        if (!(body instanceof HTMLElement)) return;
        const rows = Array.from(body.querySelectorAll('tr[data-code-key]'));
        if (rows.length <= 1) return;

        const clpRows = rows.filter((row) => {
            const modalidadEl = row.querySelector('.js-arriendo-modalidad');
            return modalidadEl instanceof HTMLSelectElement && ensureModalidad(modalidadEl.value) === 'CLP_FIJO';
        });
        if (clpRows.length === 0) return;

        const firstRow = clpRows[0];
        const firstCode = String(firstRow.dataset.codeKey || '');
        const firstClpEl = firstRow.querySelector('.js-arriendo-valor-clp');
        const firstDescEl = firstRow.querySelector('.js-arriendo-descuento');
        const clpShared = firstClpEl instanceof HTMLInputElement ? String(firstClpEl.value || '') : '';
        const descShared = firstDescEl instanceof HTMLInputElement ? String(firstDescEl.value || '0') : '0';

        clpRows.forEach((row) => {
            const codeKey = String(row.dataset.codeKey || '');
            const modalidadEl = row.querySelector('.js-arriendo-modalidad');
            const clpEl = row.querySelector('.js-arriendo-valor-clp');
            const descEl = row.querySelector('.js-arriendo-descuento');
            if (!(modalidadEl instanceof HTMLSelectElement) || !(clpEl instanceof HTMLInputElement) || !(descEl instanceof HTMLInputElement)) {
                return;
            }

            modalidadEl.value = 'CLP_FIJO';
            clpEl.value = clpShared;
            descEl.value = descShared;
            const isMaster = row === firstRow;
            clpEl.readOnly = !isMaster;
            descEl.readOnly = !isMaster;
            if (!isMaster) {
                clpEl.title = 'Monto CLP fijo del contrato (editable en la primera fila CLP fijo).';
                descEl.title = 'Descuento CLP mensual del contrato (editable en la primera fila CLP fijo).';
            } else {
                clpEl.title = '';
                descEl.title = '';
            }

            if (codeKey !== '') {
                arriendoState[codeKey] = {
                    modalidad: 'CLP_FIJO',
                    valor_base_uf: '',
                    valor_base_clp: String(clpEl.value || ''),
                    descuento_mensual_clp: String(descEl.value || '0'),
                };
            }
        });

        if (firstCode !== '' && arriendoState[firstCode]) {
            arriendoState[firstCode].modalidad = 'CLP_FIJO';
        }
        applyClpFijoContratoVisualMode(prefix);
    };

    const renderArriendoRows = (prefix, selectedCodes, arriendoState, garantiaState, fallbackDate) => {
        const body = document.getElementById(`${prefix}_arriendo_locales_body`);
        if (!(body instanceof HTMLElement)) return;

        captureContratoLocalStateFromBody(body, arriendoState, garantiaState);

        if (!Array.isArray(selectedCodes) || selectedCodes.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Selecciona locales para configurar cobro y garantía por contrato-local.</td></tr>';
            return;
        }

        const rowsHtml = selectedCodes.map((codeRaw) => {
            const code = normalizeLocalCode(codeRaw);
            const key = localCodeKey(code);
            if (!key) return '';
            const catalog = localCatalogArriendo[key] || {};
            const fallback = defaultArriendoConfigForCode(code);
            const arriendoCurrent = arriendoState[key] || fallback;
            const garantiaCurrent = garantiaState[key] || {
                habilitada: false,
                monto: '',
                fecha_constitucion: String(fallbackDate || ''),
                observaciones: '',
            };
            const modalidad = ensureModalidad(arriendoCurrent.modalidad || fallback.modalidad);
            const valorUf = formatNumberValue(arriendoCurrent.valor_base_uf ?? fallback.valor_base_uf ?? '', 2);
            const valorClp = formatNumberValue(arriendoCurrent.valor_base_clp ?? fallback.valor_base_clp ?? '', 0);
            const descuento = formatNumberValue(arriendoCurrent.descuento_mensual_clp ?? fallback.descuento_mensual_clp ?? '0', 0) || '0';
            const habilitada = !!garantiaCurrent.habilitada;
            const montoGarantia = formatNumberValue(garantiaCurrent.monto || '', 0);
            const fechaGarantia = String(garantiaCurrent.fecha_constitucion || fallbackDate || '');
            const observaciones = String(garantiaCurrent.observaciones || '');
            const hasObservaciones = observaciones.trim() !== '';
            arriendoState[key] = {
                modalidad,
                valor_base_uf: valorUf,
                valor_base_clp: valorClp,
                descuento_mensual_clp: descuento,
            };
            garantiaState[key] = {
                habilitada,
                monto: montoGarantia,
                fecha_constitucion: fechaGarantia,
                observaciones,
            };
            const descripcion = String(catalog.descripcion || '').trim();

            return `
                <tr data-code-key="${escapeHtml(key)}" data-fallback-date="${escapeHtml(String(fallbackDate || ''))}" data-local-code="${escapeHtml(code)}" data-local-desc="${escapeHtml(descripcion)}">
                    <td>
                        <div><strong class="js-local-code">${escapeHtml(code)}</strong></div>
                        <div class="small text-muted js-local-desc">${escapeHtml(descripcion)}</div>
                    </td>
                    <td style="min-width: 190px;">
                        <select class="form-select form-select-sm js-arriendo-modalidad" name="local_arriendo_modalidad[${escapeHtml(code)}]">
                            <option value="UF_ESTATICO" ${modalidad === 'UF_ESTATICO' ? 'selected' : ''}>UF mensual fija</option>
                            <option value="CLP_FIJO" ${modalidad === 'CLP_FIJO' ? 'selected' : ''}>Pesos mensuales fijos</option>
                        </select>
                        <input type="hidden" class="js-arriendo-descuento" name="local_arriendo_descuento_clp[${escapeHtml(code)}]" value="${escapeHtml(descuento)}" data-money-decimals="0">
                    </td>
                    <td style="min-width: 150px;">
                        <input type="text" class="form-control form-control-sm text-end js-arriendo-valor-uf" name="local_arriendo_valor_uf[${escapeHtml(code)}]" value="${escapeHtml(valorUf)}" placeholder="0,00" inputmode="decimal" data-money-decimals="2">
                    </td>
                    <td style="min-width: 160px;">
                        <input type="text" class="form-control form-control-sm text-end js-arriendo-valor-clp" name="local_arriendo_valor_clp[${escapeHtml(code)}]" value="${escapeHtml(valorClp)}" placeholder="0" inputmode="decimal" data-money-decimals="0">
                    </td>
                    <td style="min-width: 220px;">
                        <input type="hidden" class="js-garantia-enable" name="local_garantia_habilitada[${escapeHtml(code)}]" value="${habilitada ? '1' : '0'}">
                        <input type="text" class="form-control form-control-sm text-end js-garantia-monto" name="local_garantia_monto[${escapeHtml(code)}]" value="${escapeHtml(montoGarantia)}" placeholder="0" inputmode="decimal" data-money-decimals="0">
                        <input type="date" class="form-control form-control-sm mt-1 js-garantia-fecha d-none" name="local_garantia_fecha[${escapeHtml(code)}]" value="${escapeHtml(fechaGarantia)}">
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-1 js-garantia-toggle" data-code-key="${escapeHtml(key)}" aria-expanded="${hasObservaciones ? 'true' : 'false'}">${hasObservaciones ? 'Ocultar nota' : 'Nota'}</button>
                    </td>
                </tr>
                <tr class="${hasObservaciones ? '' : 'd-none'}" data-detail-for="${escapeHtml(key)}">
                    <td colspan="5" class="bg-light-subtle">
                        <label class="form-label mb-1 small text-muted">Observaciones garantía (${escapeHtml(code)})</label>
                        <input type="text" class="form-control form-control-sm js-garantia-obs" name="local_garantia_observaciones[${escapeHtml(code)}]" value="${escapeHtml(observaciones)}" maxlength="500" placeholder="Opcional">
                    </td>
                </tr>
            `;
        }).join('');

        body.innerHTML = rowsHtml !== '' ? rowsHtml : '<tr><td colspan="5" class="text-muted text-center py-3">Sin locales seleccionados.</td></tr>';
        body.querySelectorAll('tr[data-code-key]').forEach((row) => applyContratoLocalRowMode(row));
        syncClpFijoContratoRows(prefix, arriendoState);
        applyClpFijoContratoVisualMode(prefix);
    };

    const parseMoneyNumber = (rawValue) => {
        const raw = String(rawValue || '').trim();
        if (raw === '') return null;
        let cleaned = raw.replace(/\s+/g, '').replace(/[^\d,.-]/g, '');
        if (cleaned === '') return null;

        const lastComma = cleaned.lastIndexOf(',');
        const lastDot = cleaned.lastIndexOf('.');
        let decimalSep = '';
        if (lastComma >= 0 && lastDot >= 0) {
            decimalSep = lastComma > lastDot ? ',' : '.';
        } else if (lastComma >= 0) {
            decimalSep = ',';
        } else if (lastDot >= 0) {
            const decimals = cleaned.length - lastDot - 1;
            decimalSep = decimals <= 2 ? '.' : '';
        }

        if (decimalSep === ',') {
            cleaned = cleaned.replace(/\./g, '').replace(',', '.');
        } else if (decimalSep === '.') {
            cleaned = cleaned.replace(/,/g, '');
        } else {
            cleaned = cleaned.replace(/[.,]/g, '');
        }

        const value = Number(cleaned);
        if (!Number.isFinite(value)) return null;
        return value;
    };

    const formatNumberValue = (rawValue, decimals = 2) => {
        const parsed = parseMoneyNumber(rawValue);
        if (parsed === null) {
            return '';
        }
        const d = decimals === 0 ? 0 : 2;
        const factor = d === 0 ? 1 : 100;
        const rounded = Math.round(parsed * factor) / factor;
        return numberFormatter[d].format(rounded);
    };

    const formatMoneyInputValue = (inputEl, decimals = 2) => {
        if (!inputEl) return;
        inputEl.value = formatNumberValue(inputEl.value, decimals);
    };

    const formatPeriodoMesLabel = (periodoYm) => {
        const raw = String(periodoYm || '').trim();
        const match = raw.match(/^(\d{4})-(\d{2})$/);
        if (!match) return raw;
        const monthNum = Number.parseInt(match[2], 10);
        const meses = [
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
        ];
        if (!Number.isFinite(monthNum) || monthNum < 1 || monthNum > 12) return raw;
        return meses[monthNum - 1] || raw;
    };

    const normalizeMoneyInputForSubmit = (inputEl) => {
        if (!(inputEl instanceof HTMLInputElement)) return;
        const decimals = Number.parseInt(String(inputEl.dataset.moneyDecimals || '2'), 10) === 0 ? 0 : 2;
        const parsed = parseMoneyNumber(inputEl.value);
        if (parsed === null) {
            inputEl.value = '';
            return;
        }
        if (decimals === 0) {
            inputEl.value = String(Math.round(parsed));
            return;
        }
        const rounded = Math.round(parsed * 100) / 100;
        inputEl.value = rounded.toFixed(2);
    };

    const resetSearchableButton = (buttonId) => {
        const button = document.getElementById(buttonId);
        if (!(button instanceof HTMLButtonElement)) {
            return;
        }
        const placeholder = String(button.dataset.placeholder || 'Selecciona...');
        button.textContent = placeholder;
        button.title = placeholder;
    };

    const selectSearchableValue = ({ hiddenId, listId, buttonId, value }) => {
        const hiddenInput = document.getElementById(hiddenId);
        const list = document.getElementById(listId);
        if (!(hiddenInput instanceof HTMLInputElement) || !(list instanceof HTMLElement)) {
            return;
        }
        const next = String(value || '').trim();
        if (next === '') {
            hiddenInput.value = '';
            if (buttonId) {
                resetSearchableButton(buttonId);
            }
            hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
            hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        const options = Array.from(list.querySelectorAll('.js-searchable-option'));
        const match = options.find((option) => String(option.dataset.value || '') === next && !option.hidden);
        if (match) {
            match.click();
            return;
        }
        hiddenInput.value = next;
        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const crearArriendoState = {};
    const editArriendoState = {};
    const crearGarantiaState = {};
    const editGarantiaState = {};

    let editCurrentTiendaId = '';
    const resetSearchablePicker = (hiddenId, buttonId, placeholder) => {
        const hidden = document.getElementById(hiddenId);
        const button = document.getElementById(buttonId);
        if (hidden instanceof HTMLInputElement) {
            hidden.value = '';
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (button instanceof HTMLButtonElement) {
            button.textContent = placeholder;
            button.title = placeholder;
        }
    };

    const filterTiendasByArrendatario = (mode, keepTiendaId = '') => {
        const arrInput = document.getElementById(`${mode}_id_arrendatario`);
        const tiendaInput = document.getElementById(`${mode}_id_tienda`);
        const tiendaButton = document.getElementById(`${mode}_tienda_dropdown_btn`);
        const tiendaList = document.getElementById(`${mode}_tienda_dropdown_list`);
        if (!(arrInput instanceof HTMLInputElement) || !(tiendaInput instanceof HTMLInputElement) || !(tiendaList instanceof HTMLElement)) {
            return;
        }

        const arrendatarioId = String(arrInput.value || '');
        const currentValue = String(tiendaInput.value || '');
        let currentStillAllowed = false;
        tiendaList.querySelectorAll('.js-searchable-option').forEach((option) => {
            if (!(option instanceof HTMLButtonElement)) return;
            const sameTenant = arrendatarioId !== '' && String(option.dataset.arrendatario || '') === arrendatarioId;
            const optionId = String(option.dataset.value || '');
            const unavailable = option.dataset.contratoActivo === '1' && optionId !== String(keepTiendaId || '');
            const visible = sameTenant && !unavailable;
            option.hidden = !visible;
            option.classList.toggle('d-none', !visible);
            if (visible && optionId === currentValue) currentStillAllowed = true;
        });

        if (!currentStillAllowed) {
            const placeholder = arrendatarioId === ''
                ? 'Primero selecciona un arrendatario...'
                : 'Selecciona una tienda...';
            resetSearchablePicker(`${mode}_id_tienda`, `${mode}_tienda_dropdown_btn`, placeholder);
        } else if (tiendaButton instanceof HTMLButtonElement) {
            const selected = tiendaList.querySelector(`.js-searchable-option[data-value="${CSS.escape(currentValue)}"]`);
            if (selected instanceof HTMLButtonElement) {
                tiendaButton.textContent = String(selected.dataset.label || 'Tienda seleccionada');
            }
        }
    };

    const crearArrInput = document.getElementById('crear_id_arrendatario');
    if (crearArrInput instanceof HTMLInputElement) {
        crearArrInput.addEventListener('change', () => filterTiendasByArrendatario('crear'));
    }
    const editArrInput = document.getElementById('edit_id_arrendatario');
    if (editArrInput instanceof HTMLInputElement) {
        editArrInput.addEventListener('change', () => filterTiendasByArrendatario('edit', editCurrentTiendaId));
    }
    filterTiendasByArrendatario('crear');
    filterTiendasByArrendatario('edit');

    const crearLocalesPicker = window.MspSearchableMultiSelect
        ? window.MspSearchableMultiSelect.get('crear_local_picker')
        : null;
    const crearLocalesHidden = document.getElementById('crear_cod_locales');
    const syncCrearRows = () => {
        const selected = crearLocalesPicker
            ? crearLocalesPicker.getSelected()
            : parseCodesFromString(crearLocalesHidden instanceof HTMLInputElement ? crearLocalesHidden.value : '');
        const fechaInicioCrear = document.getElementById('crear_fecha_inicio');
        const fallbackDate = fechaInicioCrear instanceof HTMLInputElement ? String(fechaInicioCrear.value || '') : '';
        renderArriendoRows('crear', selected, crearArriendoState, crearGarantiaState, fallbackDate);
    };
    if (crearLocalesHidden instanceof HTMLInputElement) {
        crearLocalesHidden.addEventListener('change', syncCrearRows);
    }
    const crearFechaInicioInput = document.getElementById('crear_fecha_inicio');
    if (crearFechaInicioInput instanceof HTMLInputElement) {
        crearFechaInicioInput.addEventListener('change', syncCrearRows);
    }
    const crearArriendoBody = document.getElementById('crear_arriendo_locales_body');
    if (crearArriendoBody instanceof HTMLElement) {
        crearArriendoBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const toggleBtn = target.closest('.js-garantia-toggle');
            if (toggleBtn instanceof HTMLButtonElement) {
                toggleGarantiaDetailRow(toggleBtn);
            }
        });
        crearArriendoBody.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            if (target.classList.contains('js-arriendo-modalidad') || target.classList.contains('js-garantia-monto')) {
                const row = target.closest('tr[data-code-key]');
                if (row) applyContratoLocalRowMode(row);
                syncClpFijoContratoRows('crear', crearArriendoState);
            }
            if (target.classList.contains('js-arriendo-valor-clp') || target.classList.contains('js-arriendo-descuento')) {
                syncClpFijoContratoRows('crear', crearArriendoState);
            }
        });
    }

    const crearModal = document.getElementById('modalNuevoContrato');
    if (crearModal) {
        crearModal.addEventListener('show.bs.modal', () => {
            const hoy = toInputDateValue(currentLocalDate());
            const fechaInicio = document.getElementById('crear_fecha_inicio');
            if (fechaInicio) fechaInicio.value = hoy;
            selectSearchableValue({ hiddenId: 'crear_id_arrendatario', listId: 'crear_arr_dropdown_list', buttonId: 'crear_arr_dropdown_btn', value: '' });
            resetSearchablePicker('crear_id_tienda', 'crear_tienda_dropdown_btn', 'Primero selecciona un arrendatario...');
            filterTiendasByArrendatario('crear');
            if (crearLocalesPicker) crearLocalesPicker.clear();
            Object.keys(crearArriendoState).forEach((key) => delete crearArriendoState[key]);
            Object.keys(crearGarantiaState).forEach((key) => delete crearGarantiaState[key]);
            syncCrearRows();
        });
    }

    const editLocalesPicker = window.MspSearchableMultiSelect
        ? window.MspSearchableMultiSelect.get('edit_local_picker')
        : null;
    const editLocalesHidden = document.getElementById('edit_cod_locales');
    const syncEditRows = () => {
        const selected = editLocalesPicker
            ? editLocalesPicker.getSelected()
            : parseCodesFromString(editLocalesHidden instanceof HTMLInputElement ? editLocalesHidden.value : '');
        const fechaInicioEdit = document.getElementById('edit_fecha_inicio');
        const fallbackDate = fechaInicioEdit instanceof HTMLInputElement ? String(fechaInicioEdit.value || '') : '';
        renderArriendoRows('edit', selected, editArriendoState, editGarantiaState, fallbackDate);
    };
    if (editLocalesHidden instanceof HTMLInputElement) {
        editLocalesHidden.addEventListener('change', syncEditRows);
    }
    const editFechaInicioInput = document.getElementById('edit_fecha_inicio');
    if (editFechaInicioInput instanceof HTMLInputElement) {
        editFechaInicioInput.addEventListener('change', syncEditRows);
    }
    const editArriendoBody = document.getElementById('edit_arriendo_locales_body');
    if (editArriendoBody instanceof HTMLElement) {
        editArriendoBody.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            const toggleBtn = target.closest('.js-garantia-toggle');
            if (toggleBtn instanceof HTMLButtonElement) {
                toggleGarantiaDetailRow(toggleBtn);
            }
        });
        editArriendoBody.addEventListener('change', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) return;
            if (target.classList.contains('js-arriendo-modalidad') || target.classList.contains('js-garantia-monto')) {
                const row = target.closest('tr[data-code-key]');
                if (row) applyContratoLocalRowMode(row);
                syncClpFijoContratoRows('edit', editArriendoState);
            }
            if (target.classList.contains('js-arriendo-valor-clp') || target.classList.contains('js-arriendo-descuento')) {
                syncClpFijoContratoRows('edit', editArriendoState);
            }
        });
    }

    const sanitizeNumericInput = (inputEl) => {
        if (!(inputEl instanceof HTMLInputElement)) return;
        const raw = String(inputEl.value || '');
        const caret = inputEl.selectionStart ?? raw.length;
        const before = raw.slice(0, caret);
        const cleaned = raw.replace(/[^\d.,]/g, '');
        if (cleaned === raw) {
            return;
        }
        const cleanedBefore = before.replace(/[^\d.,]/g, '');
        inputEl.value = cleaned;
        const pos = cleanedBefore.length;
        inputEl.setSelectionRange(pos, pos);
    };

    document.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (!target.matches('input[data-money-decimals]')) return;
        sanitizeNumericInput(target);
    });

    document.addEventListener('blur', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLInputElement)) return;
        if (!target.matches('input[data-money-decimals]')) return;
        const decimals = Number.parseInt(String(target.dataset.moneyDecimals || '2'), 10);
        formatMoneyInputValue(target, decimals === 0 ? 0 : 2);
    }, true);

    const crearForm = document.querySelector('#modalNuevoContrato form');
    if (crearForm instanceof HTMLFormElement) {
        crearForm.addEventListener('submit', (event) => {
            const tiendaInput = document.getElementById('crear_id_tienda');
            if (!(tiendaInput instanceof HTMLInputElement) || String(tiendaInput.value || '') === '') {
                event.preventDefault();
                const button = document.getElementById('crear_tienda_dropdown_btn');
                const error = document.getElementById('crear_tienda_error');
                if (button instanceof HTMLButtonElement) button.classList.add('is-invalid');
                if (error instanceof HTMLElement) error.classList.remove('d-none');
                return;
            }
            crearForm.querySelectorAll('input[data-money-decimals]').forEach((inputEl) => normalizeMoneyInputForSubmit(inputEl));
        });
    }

    const editForm = document.querySelector('#modalEditarContrato form');
    if (editForm instanceof HTMLFormElement) {
        editForm.addEventListener('submit', (event) => {
            const tiendaInput = document.getElementById('edit_id_tienda');
            if (!(tiendaInput instanceof HTMLInputElement) || String(tiendaInput.value || '') === '') {
                event.preventDefault();
                const button = document.getElementById('edit_tienda_dropdown_btn');
                const error = document.getElementById('edit_tienda_error');
                if (button instanceof HTMLButtonElement) button.classList.add('is-invalid');
                if (error instanceof HTMLElement) error.classList.remove('d-none');
                return;
            }
            editForm.querySelectorAll('input[data-money-decimals]').forEach((inputEl) => normalizeMoneyInputForSubmit(inputEl));
        });
    }

    document.querySelectorAll('.js-editar-contrato').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idContrato = String(btn.dataset.idContrato || '');
            document.getElementById('edit_id_contrato_arriendo').value = idContrato;
            const idArrendatario = String(btn.dataset.idArrendatario || '');
            const idTienda = String(btn.dataset.idTienda || '');
            editCurrentTiendaId = idTienda;
            selectSearchableValue({ hiddenId: 'edit_id_arrendatario', listId: 'edit_arr_dropdown_list', buttonId: 'edit_arr_dropdown_btn', value: idArrendatario });
            filterTiendasByArrendatario('edit', editCurrentTiendaId);
            selectSearchableValue({ hiddenId: 'edit_id_tienda', listId: 'edit_tienda_dropdown_list', buttonId: 'edit_tienda_dropdown_btn', value: idTienda });
            document.getElementById('edit_fecha_inicio').value = String(btn.dataset.fechaInicio || '');
            document.getElementById('edit_fecha_termino').value = String(btn.dataset.fechaTermino || '');
            document.getElementById('edit_monto_arriendo').value = String(btn.dataset.montoArriendo || '');
            const editGarantiaMedio = document.getElementById('edit_garantia_medio_recepcion');
            if (editGarantiaMedio instanceof HTMLInputElement) {
                editGarantiaMedio.value = 'Efectivo';
            }
            formatMoneyInputValue(document.getElementById('edit_monto_arriendo'), 2);
            const editLocalesRaw = String(btn.dataset.locales || '');
            const editPickerInstance = (window.MspSearchableMultiSelect
                ? window.MspSearchableMultiSelect.get('edit_local_picker')
                : null) || editLocalesPicker;
            Object.keys(editArriendoState).forEach((key) => delete editArriendoState[key]);
            Object.keys(editGarantiaState).forEach((key) => delete editGarantiaState[key]);
            const arriendoConfig = readArriendoConfigMap(String(btn.dataset.arriendoConfig || '{}'));
            Object.entries(arriendoConfig).forEach(([codeKey, cfg]) => {
                editArriendoState[codeKey] = {
                    modalidad: ensureModalidad(cfg.modalidad),
                    valor_base_uf: String(cfg.valor_base_uf || ''),
                    valor_base_clp: String(cfg.valor_base_clp || ''),
                    descuento_mensual_clp: String(cfg.descuento_mensual_clp || '0'),
                };
            });
            const garantiaConfig = readGarantiaConfigMap(String(btn.dataset.garantiaConfig || '{}'));
            Object.entries(garantiaConfig).forEach(([codeKey, cfg]) => {
                editGarantiaState[codeKey] = {
                    habilitada: !!cfg.habilitada,
                    monto: String(cfg.monto || ''),
                    fecha_constitucion: String(cfg.fecha_constitucion || ''),
                    observaciones: String(cfg.observaciones || ''),
                };
            });
            if (editPickerInstance) {
                editPickerInstance.setSelectedFromString(editLocalesRaw);
            } else {
                document.getElementById('edit_cod_locales').value = editLocalesRaw;
            }
            syncEditRows();
        });
    });

    const editModal = document.getElementById('modalEditarContrato');
    if (editModal) {
        editModal.addEventListener('shown.bs.modal', () => {
            const hiddenLocales = document.getElementById('edit_cod_locales');
            const instance = window.MspSearchableMultiSelect
                ? window.MspSearchableMultiSelect.get('edit_local_picker')
                : null;
            if (hiddenLocales instanceof HTMLInputElement && instance) {
                instance.setSelectedFromString(String(hiddenLocales.value || ''));
            }
            syncEditRows();
        });
    }

    const dynModal = document.getElementById('modalArriendoDinamicoContrato');
    const dynForm = document.getElementById('form_arriendo_dinamico_contrato');
    const dynContratoInput = document.getElementById('dyn_id_contrato_arriendo');
    const dynAnioInput = document.getElementById('dyn_anio');
    const dynDetalle = document.getElementById('dyn_contrato_detalle');
    const dynRowsBody = document.getElementById('dyn_rows_body');
    const dynFeedback = document.getElementById('dyn_feedback');
    const dynSubmitBtn = document.getElementById('dyn_submit_btn');

    const dynSetFeedback = (type, message) => {
        if (!(dynFeedback instanceof HTMLElement)) return;
        const safe = escapeHtml(String(message || ''));
        if (!type || safe === '') {
            dynFeedback.innerHTML = '';
            return;
        }
        const cls = type === 'success'
            ? 'text-success'
            : (type === 'warning' ? 'text-warning' : 'text-danger');
        dynFeedback.innerHTML = `<span class="${cls}">${safe}</span>`;
    };

    const dynRenderRows = (rows) => {
        if (!(dynRowsBody instanceof HTMLElement)) return;
        if (!Array.isArray(rows) || rows.length === 0) {
            dynRowsBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">No hay filas dinámicas para este contrato/año.</td></tr>';
            if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = true;
            return;
        }

        dynRowsBody.innerHTML = rows.map((row) => {
            const idContratoLocal = Number.parseInt(String(row?.id_contrato_local || '0'), 10);
            if (!Number.isFinite(idContratoLocal) || idContratoLocal <= 0) return '';
            const cdoLocal = String(row?.cdo_local || '');
            const descLocal = String(row?.desc_local || '');
            const periodo = String(row?.periodo || '');
            const periodoLabel = formatPeriodoMesLabel(periodo);
            const valorUf = formatNumberValue(row?.valor_periodo_uf || '', 2);
            const valorClp = formatNumberValue(row?.valor_periodo_clp || '', 0);
            const descuento = formatNumberValue(row?.descuento_periodo_clp || '0', 0) || '0';
            const estado = row?.tiene_periodo ? 'Cargado' : 'Pendiente';
            const estadoClass = row?.tiene_periodo ? 'text-bg-success' : 'text-bg-warning text-dark';
            const rowKey = String(row?.row_key || `${idContratoLocal}_${periodo.replace('-', '')}`);
            return `
                <tr>
                    <td title="${escapeHtml(periodo)}">${escapeHtml(periodoLabel)}</td>
                    <td style="min-width: 140px;">
                        <input type="hidden" name="rows[${escapeHtml(rowKey)}][id_contrato_local]" value="${idContratoLocal}">
                        <input type="hidden" name="rows[${escapeHtml(rowKey)}][periodo]" value="${escapeHtml(periodo)}">
                        <input type="hidden" name="rows[${escapeHtml(rowKey)}][valor_periodo_clp]" value="${escapeHtml(valorClp)}">
                        <input type="hidden" name="rows[${escapeHtml(rowKey)}][descuento_periodo_clp]" value="${escapeHtml(descuento)}">
                        <input type="text" class="form-control form-control-sm text-end" name="rows[${escapeHtml(rowKey)}][valor_periodo_uf]" value="${escapeHtml(valorUf)}" placeholder="0,00" inputmode="decimal" data-money-decimals="2">
                    </td>
                    <td class="text-center"><span class="badge ${estadoClass}">${estado}</span></td>
                </tr>
            `;
        }).join('');

        if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = false;
    };

    const dynLoadRows = () => {
        if (!(dynContratoInput instanceof HTMLInputElement) || !(dynAnioInput instanceof HTMLInputElement)) {
            return;
        }
        const idContrato = Number.parseInt(String(dynContratoInput.value || '0'), 10);
        const anio = Number.parseInt(String(dynAnioInput.value || '0'), 10);
        if (!Number.isFinite(idContrato) || idContrato <= 0 || !Number.isFinite(anio) || anio < 2000 || anio > 2100) {
            dynSetFeedback('warning', 'Selecciona contrato y año para cargar.');
            return;
        }

        if (dynRowsBody instanceof HTMLElement) {
            dynRowsBody.innerHTML = '<tr><td colspan="3" class="text-muted text-center py-3">Cargando configuración anual...</td></tr>';
        }
        if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = true;
        dynSetFeedback('', '');

        const qs = new URLSearchParams({
            id_contrato_arriendo: String(idContrato),
            anio: String(anio),
        });

        fetch(`${urlDinamicoContratoGet}?${qs.toString()}`, {
            method: 'GET',
            headers: { 'Accept': 'application/json' },
        })
            .then(async (res) => {
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data || data.ok !== true) {
                    const msg = data && data.message ? String(data.message) : 'No fue posible cargar datos dinámicos.';
                    throw new Error(msg);
                }
                dynRenderRows(Array.isArray(data.rows) ? data.rows : []);
            })
                .catch((error) => {
                    if (dynRowsBody instanceof HTMLElement) {
                        dynRowsBody.innerHTML = '<tr><td colspan="3" class="text-danger text-center py-3">No fue posible cargar la configuración anual.</td></tr>';
                    }
                    if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = true;
                    dynSetFeedback('danger', error instanceof Error ? error.message : 'No fue posible cargar datos dinámicos.');
                });
    };

    document.querySelectorAll('.js-dinamico-contrato').forEach((btn) => {
        btn.addEventListener('click', () => {
            if (!(dynContratoInput instanceof HTMLInputElement) || !(dynAnioInput instanceof HTMLInputElement)) {
                return;
            }
            const idContrato = String(btn.dataset.idContrato || '').trim();
            dynContratoInput.value = idContrato;
            if (dynAnioInput.value.trim() === '') {
                dynAnioInput.value = String(new Date().getFullYear());
            }
            if (dynDetalle instanceof HTMLElement) {
                const arr = String(btn.dataset.arrendatario || '-');
                const locales = String(btn.dataset.locales || '-');
                dynDetalle.textContent = `Contrato ID ${idContrato} | Arrendatario: ${arr} | Locales: ${locales}`;
            }
            dynLoadRows();
        });
    });

    if (dynAnioInput instanceof HTMLInputElement) {
        dynAnioInput.addEventListener('change', dynLoadRows);
    }

    if (dynModal instanceof HTMLElement) {
        dynModal.addEventListener('show.bs.modal', () => {
            dynSetFeedback('', '');
            if (dynAnioInput instanceof HTMLInputElement && dynAnioInput.value.trim() === '') {
                dynAnioInput.value = String(new Date().getFullYear());
            }
        });
    }

    if (dynForm instanceof HTMLFormElement) {
        dynForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!(dynContratoInput instanceof HTMLInputElement) || !(dynAnioInput instanceof HTMLInputElement)) {
                return;
            }

            dynForm.querySelectorAll('input[data-money-decimals]').forEach((inputEl) => normalizeMoneyInputForSubmit(inputEl));

            const formData = new FormData(dynForm);
            formData.set('id_contrato_arriendo', String(dynContratoInput.value || ''));
            formData.set('anio', String(dynAnioInput.value || ''));

            if (window.msp2CsrfToken && !String(formData.get('_csrf') || '').trim()) {
                formData.set('_csrf', String(window.msp2CsrfToken));
            }

            if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = true;
            dynSetFeedback('', '');

            fetch(urlDinamicoContratoSave, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': String(window.msp2CsrfToken || ''),
                },
                body: formData,
            })
                .then(async (res) => {
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok || !data || data.ok !== true) {
                        const msg = data && data.message ? String(data.message) : 'No fue posible guardar.';
                        throw new Error(msg);
                    }
                    dynSetFeedback('success', String(data.message || 'Carga anual guardada.'));
                    dynLoadRows();
                })
                .catch((error) => {
                    dynSetFeedback('danger', error instanceof Error ? error.message : 'No fue posible guardar la carga anual.');
                    if (dynSubmitBtn instanceof HTMLButtonElement) dynSubmitBtn.disabled = false;
                });
        });
    }

    document.querySelectorAll('.js-cerrar-contrato').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idContrato = String(btn.dataset.idContrato || '');
            const arrendatario = String(btn.dataset.arrendatario || '-');
            const locales = String(btn.dataset.locales || '-');
            const idInput = document.getElementById('cerrar_id_contrato_arriendo');
            const label = document.getElementById('cerrar_contrato_label');
            const detalle = document.getElementById('cerrar_contrato_detalle');
            const motivo = document.getElementById('cerrar_motivo');
            const fechaTermino = document.getElementById('cerrar_fecha_termino_efectiva');
            const precheckEstado = document.getElementById('cerrar_precheck_estado');
            const precheckDetalle = document.getElementById('cerrar_precheck_detalle');
            const submitBtn = document.getElementById('cerrar_submit_btn');

            if (idInput) idInput.value = idContrato;
            if (label) label.textContent = '#' + idContrato;
            if (detalle) detalle.textContent = `Arrendatario: ${arrendatario} | Locales: ${locales}`;
            if (motivo) motivo.value = '';
            if (fechaTermino) fechaTermino.value = toInputDateValue(currentLocalDate());
            if (precheckEstado) precheckEstado.textContent = 'Validando...';
            if (precheckDetalle) precheckDetalle.innerHTML = '';
            if (submitBtn) submitBtn.disabled = true;

            const runPrecheck = () => {
                const contrato = (idInput?.value || '').trim();
                const fecha = (fechaTermino?.value || '').trim();
                if (!contrato || !fecha) {
                    if (precheckEstado) precheckEstado.textContent = 'Debes indicar contrato y fecha.';
                    if (submitBtn) submitBtn.disabled = true;
                    return;
                }

                const urlBase = '<?php echo msp2Escape(msp2Url('contratos/precheck_termino.php')); ?>';
                const qs = new URLSearchParams({
                    id_contrato_arriendo: contrato,
                    fecha_termino_efectiva: fecha,
                });

                fetch(`${urlBase}?${qs.toString()}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                })
                    .then((res) => res.json().catch(() => ({})))
                    .then((data) => {
                        if (!data || data.ok !== true) {
                            const msg = data && data.message ? String(data.message) : 'No fue posible validar.';
                            if (precheckEstado) precheckEstado.innerHTML = `<span class="text-danger">${escapeHtml(msg)}</span>`;
                            if (precheckDetalle) precheckDetalle.innerHTML = '';
                            if (submitBtn) submitBtn.disabled = true;
                            return;
                        }

                        const bloqueos = Array.isArray(data.bloqueos) ? data.bloqueos : [];
                        const avisos = Array.isArray(data.avisos) ? data.avisos : [];
                        const localesActivos = Array.isArray(data.locales) ? data.locales : [];
                        const docs = Array.isArray(data.documentos) ? data.documentos : [];
                        if (bloqueos.length === 0) {
                            if (precheckEstado) precheckEstado.innerHTML = '<span class="text-success">Sin bloqueos. Puedes terminar el contrato.</span>';
                            if (submitBtn) submitBtn.disabled = false;
                        } else {
                            if (precheckEstado) precheckEstado.innerHTML = `<span class="text-danger">${escapeHtml(bloqueos.join(' '))}</span>`;
                            if (submitBtn) submitBtn.disabled = true;
                        }

                        const chunks = [];
                        if (localesActivos.length > 0) {
                            chunks.push(`<div><strong>Locales activos:</strong> ${escapeHtml(localesActivos.join(' / '))}</div>`);
                        }
                        if (avisos.length > 0) {
                            const avisoHtml = avisos.map((a) => `<li>${escapeHtml(String(a || ''))}</li>`).join('');
                            chunks.push(`<div class="mt-1"><strong>Avisos:</strong><ul class="mb-0">${avisoHtml}</ul></div>`);
                        }
                        if (docs.length > 0) {
                            const docsHtml = docs.map((d) => {
                                const n = String(d.numero_documento || '').trim() || 's/n';
                                const f = String(d.fecha_vencimiento || '').trim();
                                const s = String(d.saldo_pendiente || '').trim();
                                return `<li>${escapeHtml(n)} (${escapeHtml(f)}, ${escapeHtml(s)})</li>`;
                            }).join('');
                            chunks.push(`<div class="mt-1"><strong>Documentos vencidos:</strong><ul class="mb-0">${docsHtml}</ul></div>`);
                        }
                        if (precheckDetalle) precheckDetalle.innerHTML = chunks.join('');
                    })
                    .catch(() => {
                        if (precheckEstado) precheckEstado.innerHTML = '<span class="text-danger">No fue posible validar (error de red).</span>';
                        if (precheckDetalle) precheckDetalle.innerHTML = '';
                        if (submitBtn) submitBtn.disabled = true;
                    });
            };

            runPrecheck();
            if (fechaTermino) {
                fechaTermino.onchange = runPrecheck;
            }
        });
    });

    document.querySelectorAll('.js-anular-contrato').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idContrato = String(btn.dataset.idContrato || '');
            const arrendatario = String(btn.dataset.arrendatario || '-');
            const locales = String(btn.dataset.locales || '-');
            const idInput = document.getElementById('anular_id_contrato_arriendo');
            const label = document.getElementById('anular_contrato_label');
            const detalle = document.getElementById('anular_contrato_detalle');
            const motivo = document.getElementById('anular_motivo');
            if (idInput instanceof HTMLInputElement) idInput.value = idContrato;
            if (label) label.textContent = '#' + idContrato;
            if (detalle) detalle.textContent = `Arrendatario: ${arrendatario} | Locales: ${locales}`;
            if (motivo instanceof HTMLTextAreaElement) motivo.value = '';
        });
    });

    document.querySelectorAll('.js-traspasar-contrato').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idContrato = String(btn.dataset.idContrato || '');
            const idArrendatarioOrigen = String(btn.dataset.idArrendatario || '');
            const arrendatario = String(btn.dataset.arrendatario || '-');
            const locales = String(btn.dataset.locales || '-');
            const idInput = document.getElementById('traspaso_id_contrato_origen');
            const label = document.getElementById('traspaso_contrato_label');
            const detalle = document.getElementById('traspaso_contrato_detalle');
            const fechaInput = document.getElementById('traspaso_fecha');
            const motivo = document.getElementById('traspaso_motivo');
            const estado = document.getElementById('traspaso_precheck_estado');
            const detallePrecheck = document.getElementById('traspaso_precheck_detalle');
            const submitBtn = document.getElementById('traspaso_submit_btn');
            const arrDestInput = document.getElementById('traspaso_id_arrendatario_destino');

            if (idInput instanceof HTMLInputElement) idInput.value = idContrato;
            if (label) label.textContent = '#' + idContrato;
            if (detalle) detalle.textContent = `Arrendatario origen: ${arrendatario} | Locales: ${locales}`;
            if (fechaInput instanceof HTMLInputElement) fechaInput.value = toInputDateValue(currentLocalDate());
            if (motivo instanceof HTMLTextAreaElement) motivo.value = '';
            selectSearchableValue({
                hiddenId: 'traspaso_id_arrendatario_destino',
                listId: 'traspaso_arr_dropdown_list',
                buttonId: 'traspaso_arr_dropdown_btn',
                value: '',
            });
            if (estado) estado.textContent = 'Validando...';
            if (detallePrecheck) detallePrecheck.innerHTML = '';
            if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;

            const runPrecheck = () => {
                const contrato = idInput instanceof HTMLInputElement ? String(idInput.value || '').trim() : '';
                const fecha = fechaInput instanceof HTMLInputElement ? String(fechaInput.value || '').trim() : '';
                const arrDestino = arrDestInput instanceof HTMLInputElement ? String(arrDestInput.value || '').trim() : '';
                if (!contrato || !fecha || !arrDestino) {
                    if (estado) estado.textContent = 'Selecciona arrendatario destino y fecha de traspaso.';
                    if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;
                    return;
                }

                const urlBase = '<?php echo msp2Escape(msp2Url('contratos/precheck_traspaso.php')); ?>';
                const qs = new URLSearchParams({
                    id_contrato_origen: contrato,
                    id_arrendatario_destino: arrDestino,
                    fecha_traspaso: fecha,
                });

                fetch(`${urlBase}?${qs.toString()}`, {
                    method: 'GET',
                    headers: { 'Accept': 'application/json' },
                })
                    .then((res) => res.json().catch(() => ({})))
                    .then((data) => {
                        if (!data || data.ok !== true) {
                            const msg = data && data.message ? String(data.message) : 'No fue posible validar.';
                            if (estado) estado.innerHTML = `<span class="text-danger">${escapeHtml(msg)}</span>`;
                            if (detallePrecheck) detallePrecheck.innerHTML = '';
                            if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;
                            return;
                        }

                        const bloqueos = Array.isArray(data.bloqueos) ? data.bloqueos : [];
                        const avisos = Array.isArray(data.avisos) ? data.avisos : [];
                        const localesActivos = Array.isArray(data.locales) ? data.locales : [];
                        const garantias = Array.isArray(data.garantias) ? data.garantias : [];
                        const totalDisponible = String(data?.summary?.total_garantia_disponible || '-');

                        if (bloqueos.length === 0) {
                            if (estado) estado.innerHTML = '<span class="text-success">Sin bloqueos. Puedes traspasar el contrato.</span>';
                            if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = false;
                        } else {
                            if (estado) estado.innerHTML = `<span class="text-danger">${escapeHtml(bloqueos.join(' '))}</span>`;
                            if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;
                        }

                        const chunks = [];
                        chunks.push(`<div><strong>Locales activos:</strong> ${escapeHtml(localesActivos.join(' / ') || '-')}</div>`);
                        chunks.push(`<div><strong>Saldo disponible a transferir:</strong> ${escapeHtml(totalDisponible)}</div>`);
                        if (garantias.length > 0) {
                            const garHtml = garantias.map((g) => {
                                const local = String(g.local || '-');
                                const disp = String(g.saldo_disponible || '$ 0');
                                const res = String(g.saldo_reservado || '$ 0');
                                return `<li>${escapeHtml(local)}: disponible ${escapeHtml(disp)} | reservado ${escapeHtml(res)}</li>`;
                            }).join('');
                            chunks.push(`<div class=\"mt-1\"><strong>Garantías:</strong><ul class=\"mb-0\">${garHtml}</ul></div>`);
                        }
                        if (avisos.length > 0) {
                            const avisosHtml = avisos.map((a) => `<li>${escapeHtml(String(a || ''))}</li>`).join('');
                            chunks.push(`<div class=\"mt-1\"><strong>Avisos:</strong><ul class=\"mb-0\">${avisosHtml}</ul></div>`);
                        }
                        if (detallePrecheck) detallePrecheck.innerHTML = chunks.join('');
                    })
                    .catch(() => {
                        if (estado) estado.innerHTML = '<span class="text-danger">No fue posible validar (error de red).</span>';
                        if (detallePrecheck) detallePrecheck.innerHTML = '';
                        if (submitBtn instanceof HTMLButtonElement) submitBtn.disabled = true;
                    });
            };

            runPrecheck();
            if (fechaInput instanceof HTMLInputElement) {
                fechaInput.onchange = runPrecheck;
            }
            if (arrDestInput instanceof HTMLInputElement) {
                arrDestInput.onchange = runPrecheck;
                arrDestInput.oninput = runPrecheck;
            }
            if (idArrendatarioOrigen !== '' && arrDestInput instanceof HTMLInputElement) {
                arrDestInput.dataset.arrendatarioOrigen = idArrendatarioOrigen;
            }
        });
    });

    document.querySelectorAll('.js-finalizar-cierre').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idContrato = String(btn.dataset.idContrato || '');
            const arrendatario = String(btn.dataset.arrendatario || '-');
            const locales = String(btn.dataset.locales || '-');
            const idInput = document.getElementById('fin_id_contrato_arriendo');
            const label = document.getElementById('fin_contrato_label');
            const detalle = document.getElementById('fin_contrato_detalle');
            const periodo = document.getElementById('fin_periodo_corte_mes');
            const motivo = document.getElementById('fin_motivo_cierre');
            const linkCierre = document.getElementById('fin_link_crear_cierre');
            const baseCierre = '<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>';

            if (idInput) idInput.value = idContrato;
            if (label) label.textContent = '#' + idContrato;
            if (detalle) detalle.textContent = `Arrendatario: ${arrendatario} | Locales: ${locales}`;
            if (periodo) {
                periodo.value = toInputMonthValue(currentLocalDate());
                if (linkCierre) {
                    linkCierre.href = `${baseCierre}?periodo=${encodeURIComponent(periodo.value)}&step=1`;
                }
                periodo.onchange = () => {
                    if (!linkCierre) return;
                    const p = String(periodo.value || '').trim();
                    linkCierre.href = p !== ''
                        ? `${baseCierre}?periodo=${encodeURIComponent(p)}&step=1`
                        : baseCierre;
                };
            }
            if (motivo) motivo.value = '';
        });
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
