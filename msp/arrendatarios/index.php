<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$toastFlash = null;
 $undoToast = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flashMeta = $flash['meta'] ?? null;
    if (is_array($flashMeta) && is_array($flashMeta['undo'] ?? null)) {
        $undoToast = $flashMeta['undo'];
    }
    $flash = null;
}
$tablaExiste = false;
$arrendatarios = [];
$comunas = [];
$estados = [];
$correosPorArrendatario = [];
$telefonosPorArrendatario = [];
$tiendasLocalesPorArrendatario = [];
$contratosPorArrendatario = [];
$resumenContratoPorTienda = [];
$ordenarArrendatariosPorLocalDisponible = false;
$moduloContratoResumenDisponible = false;
$loadError = null;
$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;

if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroNombre = msp2NormalizeText($_GET['filtroNombre'] ?? null);
$filtroRut = msp2NormalizeText($_GET['filtroRut'] ?? null);
$filtroRutSinFormato = msp2RutSanitize($filtroRut);
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));
$filtroTipo = trim((string) ($_GET['filtroTipo'] ?? ''));

function arrLocalSortTuple(string $raw): array
{
    $code = strtoupper(trim($raw));
    if ($code === '') {
        return [5, 999, '', 999999, '', $code];
    }

    if (preg_match('/^([A-Z])-([0-9]+)([A-Z]?)$/', $code, $m) === 1) {
        $block = $m[1];
        $num = (int) $m[2];
        $suffix = $m[3] ?? '';
        return [0, ord($block), $block, $num, $suffix, $code];
    }

    if (preg_match('/^[A-Z]$/', $code) === 1) {
        return [1, ord($code), $code, 0, '', $code];
    }

    if (preg_match('/^[0-9]+$/', $code) === 1) {
        return [2, (int) $code, '', (int) $code, '', $code];
    }

    $namedRank = match (true) {
        $code === 'PELUQUERIA' => 0,
        $code === 'GYM' => 1,
        $code === 'OBRA' => 2,
        $code === 'MODULAR' => 3,
        str_starts_with($code, 'ESPACIO') => 4,
        default => 999,
    };
    if ($namedRank !== 999) {
        return [3, $namedRank, '', 0, '', $code];
    }

    return [4, 999, '', 999999, '', $code];
}

function arrLocalSortCompare(string $a, string $b): int
{
    $ka = arrLocalSortTuple($a);
    $kb = arrLocalSortTuple($b);
    $len = min(count($ka), count($kb));
    for ($i = 0; $i < $len; $i++) {
        if ($ka[$i] === $kb[$i]) {
            continue;
        }
        return ($ka[$i] <=> $kb[$i]);
    }
    return 0;
}

try {
    $requiredTables = ['msp_arrendatarios', 'msp_comunas', 'msp_estado_arrendatarios', 'msp_arrendatarios_correos', 'msp_arrendatarios_telefonos'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para la gestión de arrendatarios: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/msp_a1.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base del módulo de arrendatarios.';
}

if ($tablaExiste) {
    try {
        $comunasStmt = $conn->query('SELECT id_comuna, desc_comuna FROM dbo.msp_comunas ORDER BY desc_comuna ASC');
        $comunas = $comunasStmt->fetchAll();

        $estadosStmt = $conn->query('SELECT id_estado_arrendatario, desc_estado FROM dbo.msp_estado_arrendatarios ORDER BY id_estado_arrendatario ASC');
        $estados = $estadosStmt->fetchAll();

        $tablaTiendasExiste = msp2TableExists($conn, 'msp_tiendas');
        $tablaLocalesExiste = msp2TableExists($conn, 'msp_locales');
        $tablaContratoLocalesExiste = msp2TableExists($conn, 'msp_contrato_locales');
        $tablaContratosExiste = msp2TableExists($conn, 'msp_contratos_arriendo');
        $ordenarArrendatariosPorLocalDisponible = $tablaTiendasExiste && $tablaLocalesExiste && $tablaContratoLocalesExiste && $tablaContratosExiste;

        if (
            $tablaTiendasExiste
            && $tablaLocalesExiste
            && $tablaContratoLocalesExiste
            && $tablaContratosExiste
        ) {
            $moduloContratoResumenDisponible = $tablaContratosExiste;
        }

        $conditions = [];
        $params = [];

        if ($filtroNombre !== '') {
            $conditions[] = "ISNULL(a.nombre_locatario, '') LIKE :filtro_nombre";
            $params[':filtro_nombre'] = '%' . $filtroNombre . '%';
        }

        if ($filtroRut !== '') {
            if ($filtroRutSinFormato !== '') {
                $conditions[] = "(ISNULL(a.rut, '') LIKE :filtro_rut OR REPLACE(ISNULL(a.rut, ''), '-', '') LIKE :filtro_rut_sin_guion)";
                $params[':filtro_rut'] = '%' . $filtroRut . '%';
                $params[':filtro_rut_sin_guion'] = '%' . $filtroRutSinFormato . '%';
            } else {
                $conditions[] = "ISNULL(a.rut, '') LIKE :filtro_rut";
                $params[':filtro_rut'] = '%' . $filtroRut . '%';
            }
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 'a.id_estado_arrendatario = :filtro_estado';
            $params[':filtro_estado'] = (int) $filtroEstado;
        }

        if ($filtroTipo === 'empresa') {
            $conditions[] = 'a.es_empresa = 1';
        } elseif ($filtroTipo === 'persona') {
            $conditions[] = 'a.es_empresa = 0';
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);

        $localSortApplySql = '';
        $orderBySql = 'a.nombre_locatario ASC';
        if ($ordenarArrendatariosPorLocalDisponible) {
            $localSortApplySql =
                "OUTER APPLY (
                    SELECT TOP 1
                        lsort.cdo_local
                    FROM dbo.msp_tiendas tsort
                    INNER JOIN dbo.msp_contratos_arriendo csort
                        ON csort.id_tienda = tsort.id_tienda
                    INNER JOIN dbo.msp_contrato_locales clsort
                        ON clsort.id_contrato_arriendo = csort.id_contrato_arriendo
                    INNER JOIN dbo.msp_locales lsort
                        ON lsort.id_local = clsort.id_local
                    WHERE tsort.id_arrendatario = a.id_arrendatario
                      AND clsort.estado_relacion = 1
                      AND csort.estado_contrato IN (1,2,3)
                    ORDER BY
                        CASE
                            WHEN clsort.fecha_inicio <= CONVERT(date, SYSDATETIME())
                             AND (clsort.fecha_termino IS NULL OR clsort.fecha_termino >= CONVERT(date, SYSDATETIME()))
                             AND csort.fecha_inicio <= CONVERT(date, SYSDATETIME())
                             AND (csort.fecha_termino_efectiva IS NULL OR csort.fecha_termino_efectiva >= CONVERT(date, SYSDATETIME()))
                                THEN 0
                            ELSE 1
                        END,
                        CASE WHEN clsort.fecha_termino IS NULL THEN 0 ELSE 1 END,
                        ISNULL(clsort.fecha_termino, CONVERT(date, '9999-12-31')) DESC,
                        ISNULL(clsort.fecha_inicio, CONVERT(date, '1900-01-01')) DESC,
                        " . msp2LocalCodeNaturalOrderSql('lsort.cdo_local') . "
                ) primerLocal";
            $orderBySql =
                "CASE WHEN NULLIF(LTRIM(RTRIM(primerLocal.cdo_local)), '') IS NULL THEN 1 ELSE 0 END, "
                . msp2LocalCodeNaturalOrderSql('primerLocal.cdo_local')
                . ', a.nombre_locatario ASC';
        }

        $countStmt = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_arrendatarios a WHERE $whereClause");

        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $countStmt->execute();
        $totalRegistros = (int) $countStmt->fetchColumn();
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $stmt = $conn->prepare(
            "SELECT
                a.id_arrendatario,
                a.rut,
                a.es_empresa,
                a.nombre_locatario,
                a.nombre_representante,
                a.direccion,
                a.id_comuna,
                a.id_estado_arrendatario,
                c.desc_comuna,
                e.desc_estado,
                principal.correo_principal,
                ISNULL(correos.total_correos, 0) AS total_correos,
                principalTelefono.telefono_principal,
                ISNULL(telefonos.total_telefonos, 0) AS total_telefonos
             FROM dbo.msp_arrendatarios a
             INNER JOIN dbo.msp_estado_arrendatarios e ON e.id_estado_arrendatario = a.id_estado_arrendatario
             LEFT JOIN dbo.msp_comunas c ON c.id_comuna = a.id_comuna
             OUTER APPLY (
                SELECT TOP 1 ac.correo AS correo_principal
                FROM dbo.msp_arrendatarios_correos ac
                WHERE ac.id_arrendatario = a.id_arrendatario
                ORDER BY ac.es_principal DESC, ac.id_arrendatario_correo ASC
             ) principal
             OUTER APPLY (
                SELECT COUNT(*) AS total_correos
                FROM dbo.msp_arrendatarios_correos ac
                WHERE ac.id_arrendatario = a.id_arrendatario
             ) correos
             OUTER APPLY (
                SELECT TOP 1 at.telefono AS telefono_principal
                FROM dbo.msp_arrendatarios_telefonos at
                WHERE at.id_arrendatario = a.id_arrendatario
                ORDER BY at.es_principal DESC, at.id_arrendatario_telefono ASC
             ) principalTelefono
             OUTER APPLY (
                SELECT COUNT(*) AS total_telefonos
                FROM dbo.msp_arrendatarios_telefonos at
                WHERE at.id_arrendatario = a.id_arrendatario
             ) telefonos
             {$localSortApplySql}
             WHERE $whereClause
             ORDER BY {$orderBySql}
             OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY"
        );

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lineas', $lineasPorPagina, PDO::PARAM_INT);
        $stmt->execute();
        $arrendatarios = $stmt->fetchAll();

        if ($arrendatarios !== []) {
            $ids = [];
            foreach ($arrendatarios as $arrendatario) {
                $ids[] = (int) $arrendatario['id_arrendatario'];
            }

            $ids = array_values(array_unique($ids));

            if ($ids !== []) {
                $placeholders = [];
                foreach ($ids as $index => $id) {
                    $placeholders[] = ':id_' . $index;
                }

                $correosStmt = $conn->prepare(
                    'SELECT id_arrendatario, correo, es_principal
                     FROM dbo.msp_arrendatarios_correos
                     WHERE id_arrendatario IN (' . implode(', ', $placeholders) . ')
                     ORDER BY id_arrendatario ASC, es_principal DESC, id_arrendatario_correo ASC'
                );

                foreach ($ids as $index => $id) {
                    $correosStmt->bindValue(':id_' . $index, $id, PDO::PARAM_INT);
                }

                $correosStmt->execute();
                $rowsCorreos = $correosStmt->fetchAll();

                foreach ($rowsCorreos as $rowCorreo) {
                    $idArr = (int) $rowCorreo['id_arrendatario'];
                    if (!isset($correosPorArrendatario[$idArr])) {
                        $correosPorArrendatario[$idArr] = [];
                    }

                    $correosPorArrendatario[$idArr][] = [
                        'correo' => (string) $rowCorreo['correo'],
                        'es_principal' => (int) $rowCorreo['es_principal'],
                    ];
                }

                $telefonosStmt = $conn->prepare(
                    'SELECT id_arrendatario, telefono, es_principal
                     FROM dbo.msp_arrendatarios_telefonos
                     WHERE id_arrendatario IN (' . implode(', ', $placeholders) . ')
                     ORDER BY id_arrendatario ASC, es_principal DESC, id_arrendatario_telefono ASC'
                );

                foreach ($ids as $index => $id) {
                    $telefonosStmt->bindValue(':id_' . $index, $id, PDO::PARAM_INT);
                }

                $telefonosStmt->execute();
                $rowsTelefonos = $telefonosStmt->fetchAll();

                foreach ($rowsTelefonos as $rowTelefono) {
                    $idArr = (int) $rowTelefono['id_arrendatario'];
                    if (!isset($telefonosPorArrendatario[$idArr])) {
                        $telefonosPorArrendatario[$idArr] = [];
                    }

                    $telefonosPorArrendatario[$idArr][] = [
                        'telefono' => (string) $rowTelefono['telefono'],
                        'es_principal' => (int) $rowTelefono['es_principal'],
                    ];
                }

                /*
                 * La tabla principal se presenta por contrato. No se toma solo
                 * el contrato vigente de cada tienda: cada contrato conserva su
                 * propio registro y sus locales vinculados.
                 */
                if (
                    msp2TableExists($conn, 'msp_tiendas')
                    && msp2TableExists($conn, 'msp_contrato_locales')
                    && msp2TableExists($conn, 'msp_contratos_arriendo')
                    && msp2TableExists($conn, 'msp_locales')
                ) {
                    $contratosPorArrStmt = $conn->prepare(
                        'SELECT
                            ca.id_arrendatario,
                            ca.id_contrato_arriendo,
                            ca.estado_contrato,
                            ca.fecha_inicio,
                            ca.fecha_termino_pactada,
                            ca.fecha_termino_efectiva,
                            t.nombre_comercial,
                            l.cdo_local
                         FROM dbo.msp_contratos_arriendo ca
                         INNER JOIN dbo.msp_tiendas t ON t.id_tienda = ca.id_tienda
                         LEFT JOIN dbo.msp_contrato_locales cl
                            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                         LEFT JOIN dbo.msp_locales l ON l.id_local = cl.id_local
                         WHERE ca.id_arrendatario IN (' . implode(', ', $placeholders) . ')
                         ORDER BY
                            ca.id_arrendatario ASC,
                            CASE WHEN ca.estado_contrato IN (1, 2, 3) THEN 0 ELSE 1 END,
                            ca.fecha_inicio DESC,
                            ca.id_contrato_arriendo DESC,
                            ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
                    );
                    foreach ($ids as $index => $id) {
                        $contratosPorArrStmt->bindValue(':id_' . $index, $id, PDO::PARAM_INT);
                    }
                    $contratosPorArrStmt->execute();
                    $contratosAgrupados = [];
                    while (($contratoRow = $contratosPorArrStmt->fetch()) !== false) {
                        $idArrContrato = (int) ($contratoRow['id_arrendatario'] ?? 0);
                        $idContrato = (int) ($contratoRow['id_contrato_arriendo'] ?? 0);
                        if ($idArrContrato <= 0 || $idContrato <= 0) {
                            continue;
                        }
                        if (!isset($contratosAgrupados[$idArrContrato][$idContrato])) {
                            $contratosAgrupados[$idArrContrato][$idContrato] = [
                                'id_contrato_arriendo' => $idContrato,
                                'estado_contrato' => (int) ($contratoRow['estado_contrato'] ?? 0),
                                'fecha_inicio' => (string) ($contratoRow['fecha_inicio'] ?? ''),
                                'fecha_termino_pactada' => (string) ($contratoRow['fecha_termino_pactada'] ?? ''),
                                'fecha_termino_efectiva' => (string) ($contratoRow['fecha_termino_efectiva'] ?? ''),
                                'nombre_tienda' => msp2NormalizeText((string) ($contratoRow['nombre_comercial'] ?? '')),
                                'locales' => [],
                            ];
                        }
                        $codigoLocal = msp2NormalizeLocalCode((string) ($contratoRow['cdo_local'] ?? ''));
                        if ($codigoLocal !== '') {
                            $contratosAgrupados[$idArrContrato][$idContrato]['locales'][$codigoLocal] = $codigoLocal;
                        }
                    }
                    foreach ($contratosAgrupados as $idArrContrato => $contratosAgrupadosArr) {
                        foreach ($contratosAgrupadosArr as &$contratoAgrupado) {
                            $localesContrato = array_values($contratoAgrupado['locales']);
                            usort($localesContrato, static fn (string $a, string $b): int => arrLocalSortCompare($a, $b));
                            $contratoAgrupado['locales'] = $localesContrato;
                        }
                        unset($contratoAgrupado);
                        $contratosPorArrendatario[(int) $idArrContrato] = array_values($contratosAgrupadosArr);
                    }
                }

                if (msp2TableExists($conn, 'msp_tiendas') && msp2TableExists($conn, 'msp_contrato_locales') && msp2TableExists($conn, 'msp_contratos_arriendo') && msp2TableExists($conn, 'msp_locales')) {
                    $tiendasStmt = $conn->prepare(
                        'SELECT
                            t.id_arrendatario,
                            t.id_tienda,
                            t.nombre_comercial,
                            t.id_rubro,
                            t.id_estado_tienda,
                            t.fecha_inicio,
                            l.cdo_local,
                            cl.fecha_inicio AS fecha_inicio_relacion,
                            cl.fecha_termino AS fecha_termino_relacion
                         FROM dbo.msp_tiendas t
                         LEFT JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_tienda = t.id_tienda
                           AND ca.fecha_inicio <= CONVERT(date, SYSDATETIME())
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= CONVERT(date, SYSDATETIME()))
                           AND ca.estado_contrato IN (1,2,3)
                         LEFT JOIN dbo.msp_contrato_locales cl
                            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                           AND cl.estado_relacion = 1
                         LEFT JOIN dbo.msp_locales l
                            ON l.id_local = cl.id_local
                         WHERE t.id_arrendatario IN (' . implode(', ', $placeholders) . ')
                         ORDER BY t.id_arrendatario ASC, t.id_tienda ASC, cl.fecha_inicio DESC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
                    );

                    foreach ($ids as $index => $id) {
                        $tiendasStmt->bindValue(':id_' . $index, $id, PDO::PARAM_INT);
                    }

                    $tiendasStmt->execute();
                    $tiendasRows = $tiendasStmt->fetchAll();
                    $today = new DateTimeImmutable('today');
                    $idsTiendas = [];

                    foreach ($tiendasRows as $tiendaRowId) {
                        $idTiendaRow = isset($tiendaRowId['id_tienda']) ? (int) $tiendaRowId['id_tienda'] : 0;
                        if ($idTiendaRow > 0) {
                            $idsTiendas[] = $idTiendaRow;
                        }
                    }

                    $idsTiendas = array_values(array_unique($idsTiendas));

                    if ($moduloContratoResumenDisponible && $idsTiendas !== []) {
                        $placeholdersTiendas = [];
                        foreach ($idsTiendas as $indexTienda => $idTiendaContrato) {
                            $placeholdersTiendas[] = ':id_tienda_' . $indexTienda;
                        }

                        $contratosResumenStmt = $conn->prepare(
                            'WITH contratos_rank AS (
                                SELECT
                                    c.id_tienda,
                                    c.id_contrato_arriendo,
                                    c.estado_contrato,
                                    c.fecha_inicio,
                                    c.fecha_termino_pactada,
                                    ROW_NUMBER() OVER (
                                        PARTITION BY c.id_tienda
                                        ORDER BY
                                            CASE WHEN c.estado_contrato IN (1, 2, 3) THEN 0 ELSE 1 END,
                                            ISNULL(c.fecha_inicio, CONVERT(date, \'1900-01-01\')) DESC,
                                            c.id_contrato_arriendo DESC
                                    ) AS rn
                                FROM dbo.msp_contratos_arriendo c
                                WHERE c.id_tienda IN (' . implode(', ', $placeholdersTiendas) . ')
                            )
                            SELECT
                                cr.id_tienda,
                                cr.id_contrato_arriendo,
                                cr.estado_contrato,
                                cr.fecha_inicio,
                                cr.fecha_termino_pactada
                            FROM contratos_rank cr
                            WHERE cr.rn = 1'
                        );

                        foreach ($idsTiendas as $indexTienda => $idTiendaContrato) {
                            $contratosResumenStmt->bindValue(':id_tienda_' . $indexTienda, $idTiendaContrato, PDO::PARAM_INT);
                        }

                        $contratosResumenStmt->execute();
                        $rowsContratosResumen = $contratosResumenStmt->fetchAll();
                        $idsContratos = [];

                        foreach ($rowsContratosResumen as $rowContratoResumen) {
                            $idTiendaResumen = isset($rowContratoResumen['id_tienda']) ? (int) $rowContratoResumen['id_tienda'] : 0;
                            $idContratoResumen = isset($rowContratoResumen['id_contrato_arriendo']) ? (int) $rowContratoResumen['id_contrato_arriendo'] : 0;
                            if ($idTiendaResumen <= 0 || $idContratoResumen <= 0) {
                                continue;
                            }

                            $idsContratos[] = $idContratoResumen;
                            $resumenContratoPorTienda[$idTiendaResumen] = [
                                'id_contrato_arriendo' => $idContratoResumen,
                                'estado_contrato' => isset($rowContratoResumen['estado_contrato']) ? (int) $rowContratoResumen['estado_contrato'] : 0,
                                'fecha_inicio' => $rowContratoResumen['fecha_inicio'] ? (new DateTimeImmutable((string) $rowContratoResumen['fecha_inicio']))->format('Y-m-d') : '',
                                'fecha_termino_pactada' => $rowContratoResumen['fecha_termino_pactada'] ? (new DateTimeImmutable((string) $rowContratoResumen['fecha_termino_pactada']))->format('Y-m-d') : '',
                                'garantia_disponible' => 0.0,
                                'garantia_reservada' => 0.0,
                                'deuda_activa' => 0.0,
                            ];
                        }

                        $idsContratos = array_values(array_unique($idsContratos));

                        if ($idsContratos !== [] && msp2TableExists($conn, 'msp_vw_deuda_garantia_local')) {
                            $placeholdersContratos = [];
                            foreach ($idsContratos as $indexContrato => $idContratoDeuda) {
                                $placeholdersContratos[] = ':id_contrato_' . $indexContrato;
                            }

                            $deudaGarantiaStmt = $conn->prepare(
                                'SELECT
                                    dg.id_contrato_arriendo,
                                    ISNULL(SUM(dg.saldo_disponible), 0) AS garantia_disponible,
                                    ISNULL(SUM(dg.saldo_reservado), 0) AS garantia_reservada,
                                    ISNULL(SUM(dg.total_cargos_pendientes + dg.total_cargos_reservados), 0) AS deuda_activa
                                 FROM dbo.msp_vw_deuda_garantia_local dg
                                 WHERE dg.id_contrato_arriendo IN (' . implode(', ', $placeholdersContratos) . ')
                                 GROUP BY dg.id_contrato_arriendo'
                            );

                            foreach ($idsContratos as $indexContrato => $idContratoDeuda) {
                                $deudaGarantiaStmt->bindValue(':id_contrato_' . $indexContrato, $idContratoDeuda, PDO::PARAM_INT);
                            }

                            $deudaGarantiaStmt->execute();
                            $deudaByContrato = [];
                            while (($rowDeuda = $deudaGarantiaStmt->fetch()) !== false) {
                                $idContratoDeuda = isset($rowDeuda['id_contrato_arriendo']) ? (int) $rowDeuda['id_contrato_arriendo'] : 0;
                                if ($idContratoDeuda <= 0) {
                                    continue;
                                }

                                $deudaByContrato[$idContratoDeuda] = [
                                    'garantia_disponible' => (float) ($rowDeuda['garantia_disponible'] ?? 0),
                                    'garantia_reservada' => (float) ($rowDeuda['garantia_reservada'] ?? 0),
                                    'deuda_activa' => (float) ($rowDeuda['deuda_activa'] ?? 0),
                                ];
                            }

                            foreach ($resumenContratoPorTienda as $idTiendaResumen => $resumenContrato) {
                                $idContratoResumen = (int) ($resumenContrato['id_contrato_arriendo'] ?? 0);
                                if ($idContratoResumen <= 0 || !isset($deudaByContrato[$idContratoResumen])) {
                                    continue;
                                }

                                $resumenContratoPorTienda[$idTiendaResumen]['garantia_disponible'] = (float) ($deudaByContrato[$idContratoResumen]['garantia_disponible'] ?? 0);
                                $resumenContratoPorTienda[$idTiendaResumen]['garantia_reservada'] = (float) ($deudaByContrato[$idContratoResumen]['garantia_reservada'] ?? 0);
                                $resumenContratoPorTienda[$idTiendaResumen]['deuda_activa'] = (float) ($deudaByContrato[$idContratoResumen]['deuda_activa'] ?? 0);
                            }
                        }
                    }

                    $tmpMap = [];
                    foreach ($tiendasRows as $tiendaRow) {
                        $idArr = (int) $tiendaRow['id_arrendatario'];
                        $idTienda = (int) $tiendaRow['id_tienda'];
                        $nombreTienda = msp2NormalizeText((string) ($tiendaRow['nombre_comercial'] ?? ''));
                        if ($nombreTienda === '') {
                            $nombreTienda = 'Tienda #' . $idTienda;
                        }

                        if (!isset($tmpMap[$idArr])) {
                            $tmpMap[$idArr] = [];
                        }
                        if (!isset($tmpMap[$idArr][$idTienda])) {
                            $tmpMap[$idArr][$idTienda] = [
                                'id_tienda' => $idTienda,
                                'nombre' => $nombreTienda,
                                'id_rubro' => (int) ($tiendaRow['id_rubro'] ?? 0),
                                'id_estado_tienda' => (int) ($tiendaRow['id_estado_tienda'] ?? 0),
                                'fecha_inicio' => $tiendaRow['fecha_inicio'] ? (new DateTimeImmutable((string) $tiendaRow['fecha_inicio']))->format('Y-m-d') : '',
                                'locales' => [],
                                'resumen_contrato' => $resumenContratoPorTienda[$idTienda] ?? null,
                            ];
                        }

                        $codigoLocal = msp2NormalizeLocalCode((string) ($tiendaRow['cdo_local'] ?? ''));
                        if ($codigoLocal !== '') {
                            $fechaInicioOcup = $tiendaRow['fecha_inicio_relacion'] ? new DateTimeImmutable((string) $tiendaRow['fecha_inicio_relacion']) : null;
                            $fechaTerminoOcup = $tiendaRow['fecha_termino_relacion'] ? new DateTimeImmutable((string) $tiendaRow['fecha_termino_relacion']) : null;

                            $ocupacionActiva = false;
                            if ($fechaInicioOcup !== null && $fechaInicioOcup <= $today) {
                                $ocupacionActiva = $fechaTerminoOcup === null || $fechaTerminoOcup >= $today;
                            }

                            if (!$ocupacionActiva) {
                                continue;
                            }

                            $tmpMap[$idArr][$idTienda]['locales'][] = $codigoLocal;
                        }
                    }

                    foreach ($tmpMap as $idArr => $tiendasArr) {
                        $tiendasFormateadas = [];
                        foreach ($tiendasArr as $tiendaInfo) {
                            $locales = array_values(array_unique($tiendaInfo['locales']));
                            usort($locales, static fn(string $a, string $b): int => arrLocalSortCompare($a, $b));
                            $tiendasFormateadas[] = [
                                'id_tienda' => (int) ($tiendaInfo['id_tienda'] ?? 0),
                                'nombre' => (string) $tiendaInfo['nombre'],
                                'id_rubro' => (int) ($tiendaInfo['id_rubro'] ?? 0),
                                'id_estado_tienda' => (int) ($tiendaInfo['id_estado_tienda'] ?? 0),
                                'fecha_inicio' => (string) ($tiendaInfo['fecha_inicio'] ?? ''),
                                'locales' => $locales,
                                'resumen_contrato' => is_array($tiendaInfo['resumen_contrato'] ?? null) ? $tiendaInfo['resumen_contrato'] : null,
                            ];
                        }

                        // Ordena tiendas por su primer local ocupado (criterio operativo visual).
                        usort(
                            $tiendasFormateadas,
                            static function (array $a, array $b): int {
                                $aFirst = (string) (($a['locales'][0] ?? 'ZZZ'));
                                $bFirst = (string) (($b['locales'][0] ?? 'ZZZ'));
                                $cmpLocal = arrLocalSortCompare($aFirst, $bFirst);
                                if ($cmpLocal !== 0) {
                                    return $cmpLocal;
                                }

                                return strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
                            }
                        );

                        $tiendasLocalesPorArrendatario[(int) $idArr] = $tiendasFormateadas;
                    }
                }
            }
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar los arrendatarios. Detalle técnico: ' . $exception->getMessage();
    }
}

if ($tablaExiste && $totalPaginas > 1) {
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

$queryBase = $_GET;
unset($queryBase['pagina']);

function buildMsp2ArrendatariosQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2ArrendatarioEstadoBadge(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo', 'vigente', 'habilitado' => 'bg-success',
        'inactivo' => 'bg-secondary',
        'bloqueado', 'moroso' => 'bg-danger',
        default => 'bg-light text-dark',
    };
}

function msp2ContratoEstadoLabel(int $estado): string
{
    return match ($estado) {
        1 => 'Borrador',
        2 => 'Vigente',
        3 => 'En proceso de cierre',
        4 => 'Cerrado',
        5 => 'Anulado',
        default => 'Sin estado',
    };
}

function msp2ContratoEstadoBadge(int $estado): string
{
    return match ($estado) {
        1 => 'bg-secondary',
        2 => 'bg-success',
        3 => 'bg-warning text-dark',
        4 => 'bg-dark',
        5 => 'bg-danger',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Arrendatarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css?v=<?php echo rawurlencode((string) filemtime(dirname(__DIR__, 2) . '/styles.css')); ?>">
    <style>
        .picker-select-btn {
            border: 1px solid #ced4da;
            background-color: #fff;
            color: #212529;
        }

        .picker-select-btn:hover,
        .picker-select-btn:focus,
        .picker-select-btn:active,
        .picker-select-btn.show {
            border-color: #86b7fe;
            background-color: #fff;
            color: #212529;
            box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .25);
        }
    </style>
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index msp-tenants-index">
        <header class="msp-management-page-header msp-tenants-page-header">
            <div class="msp-tenants-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Arrendatarios</h1>
            <div class="d-flex flex-wrap gap-2 msp-management-actions msp-tenants-actions">
                <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalImportarArrendatarios">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar arrendatarios
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearArrendatario">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Registrar arrendatario
                </button>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 msp-management-filters msp-tenants-filters align-items-end">
                <div class="col-12 col-sm-6 col-lg-3">
                    <label for="filtroNombre" class="form-label">Nombre locatario</label>
                    <input type="text" id="filtroNombre" name="filtroNombre" class="form-control" value="<?php echo msp2Escape($filtroNombre); ?>" placeholder="Buscar por nombre">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="filtroRut" class="form-label">RUT</label>
                    <input type="text" id="filtroRut" name="filtroRut" class="form-control" value="<?php echo msp2Escape($filtroRut); ?>" placeholder="Buscar por RUT">
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="filtroTipo" class="form-label">Tipo</label>
                    <select id="filtroTipo" name="filtroTipo" class="form-select">
                        <option value="">(Todos)</option>
                        <option value="empresa" <?php echo $filtroTipo === 'empresa' ? 'selected' : ''; ?>>Empresa</option>
                        <option value="persona" <?php echo $filtroTipo === 'persona' ? 'selected' : ''; ?>>Persona natural</option>
                    </select>
                </div>
                <div class="col-12 col-sm-6 col-lg-2">
                    <label for="filtroEstado" class="form-label">Estado</label>
                    <select id="filtroEstado" name="filtroEstado" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estados as $estado): ?>
                            <option value="<?php echo (int) $estado['id_estado_arrendatario']; ?>" <?php echo $filtroEstado === (string) $estado['id_estado_arrendatario'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estado['desc_estado']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-4 col-lg-1">
                    <label for="lineas" class="form-label">Líneas</label>
                    <select id="lineas" name="lineas" class="form-select">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-8 col-lg-2 d-grid">
                    <button type="submit" class="btn btn-primary msp-tenant-filter-submit">Filtrar</button>
                </div>
            </form>

            <div class="msp-management-table-responsive">
                <table class="table align-middle text-center msp-management-table msp-tenants-table">
                    <thead class="table-light">
                        <tr>
                            <th class="tenant-name">Arrendatario</th>
                            <th class="tenant-rut">RUT</th>
                            <th class="tenant-local">Local contratado</th>
                            <th class="tenant-contract">Contrato</th>
                            <th class="tenant-actions">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($arrendatarios)): ?>
                            <tr>
                                <td colspan="5" class="text-muted">
                                    <?php echo ($filtroNombre === '' && $filtroRut === '' && $filtroEstado === '' && $filtroTipo === '') ? 'No hay arrendatarios registrados todavía.' : 'Sin resultados para los filtros actuales.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($arrendatarios as $index => $arrendatario): ?>
                                <?php
                                $idArrendatarioRow = (int) $arrendatario['id_arrendatario'];
                                $correosArrendatario = $correosPorArrendatario[$idArrendatarioRow] ?? [];
                                $telefonosArrendatario = $telefonosPorArrendatario[$idArrendatarioRow] ?? [];
                                $tiendasLocales = $tiendasLocalesPorArrendatario[$idArrendatarioRow] ?? [];
                                $correosJson = json_encode($correosArrendatario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if ($correosJson === false) {
                                    $correosJson = '[]';
                                }

                                $telefonosJson = json_encode($telefonosArrendatario, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                if ($telefonosJson === false) {
                                    $telefonosJson = '[]';
                                }
                                ?>
                                <?php
                                $contratosArrendatario = $contratosPorArrendatario[$idArrendatarioRow] ?? [];
                                if ($contratosArrendatario === []) {
                                    $contratosArrendatario = [[
                                        'id_contrato_arriendo' => 0,
                                        'estado_contrato' => 0,
                                        'nombre_tienda' => '',
                                        'locales' => [],
                                    ]];
                                }
                                ?>
                                <?php foreach ($contratosArrendatario as $contratoFila): ?>
                                    <?php
                                    $idContratoFila = (int) ($contratoFila['id_contrato_arriendo'] ?? 0);
                                    $estadoContratoFila = (int) ($contratoFila['estado_contrato'] ?? 0);
                                    $localesContratoFila = is_array($contratoFila['locales'] ?? null) ? $contratoFila['locales'] : [];
                                    $nombreTiendaContrato = msp2NormalizeText((string) ($contratoFila['nombre_tienda'] ?? ''));
                                    ?>
                                    <tr>
                                        <td class="text-start tenant-name">
                                            <strong
                                                class="tenant-name-text"
                                                title="<?php echo msp2Escape($arrendatario['nombre_locatario']); ?>">
                                                <?php echo msp2Escape($arrendatario['nombre_locatario']); ?>
                                            </strong>
                                        </td>
                                        <td class="text-start tenant-rut"><?php echo msp2Escape(msp2RutFormatDisplay($arrendatario['rut'])); ?></td>
                                        <td class="text-start tenant-local">
                                            <?php if ($localesContratoFila === []): ?>
                                                <span class="text-muted">-</span>
                                            <?php else: ?>
                                                <span class="fw-semibold"><?php echo msp2Escape(implode(' / ', array_map('strval', $localesContratoFila))); ?></span>
                                            <?php endif; ?>
                                            <?php if ($nombreTiendaContrato !== ''): ?><div class="small text-muted"><?php echo msp2Escape($nombreTiendaContrato); ?></div><?php endif; ?>
                                        </td>
                                        <td class="text-start tenant-contract">
                                            <?php if ($idContratoFila > 0): ?>
                                                <a href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContratoFila)); ?>" class="fw-semibold text-decoration-none"><?php echo $idContratoFila; ?></a>
                                                <span class="badge <?php echo msp2Escape(msp2ContratoEstadoBadge($estadoContratoFila)); ?> ms-1"><?php echo msp2Escape(msp2ContratoEstadoLabel($estadoContratoFila)); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin contrato</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tenant-actions">
                                            <div class="table-actions">
                                                <a href="<?php echo msp2Escape(msp2Url('arrendatarios/ficha.php?id_arrendatario=' . $idArrendatarioRow)); ?>" class="btn btn-primary btn-sm" aria-label="Ver ficha 360 de <?php echo msp2Escape($arrendatario['nombre_locatario']); ?>"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Ver ficha</a>
                                                <button type="button" class="btn btn-outline-primary btn-sm js-edit-arrendatario" data-bs-toggle="modal" data-bs-target="#modalEditarArrendatario"
                                                    data-id="<?php echo (int) $arrendatario['id_arrendatario']; ?>" data-rut="<?php echo msp2Escape(msp2RutFormatDisplay($arrendatario['rut'])); ?>" data-es-empresa="<?php echo (int) $arrendatario['es_empresa']; ?>" data-nombre="<?php echo msp2Escape($arrendatario['nombre_locatario']); ?>" data-representante="<?php echo msp2Escape((string) ($arrendatario['nombre_representante'] ?? '')); ?>" data-correos="<?php echo msp2Escape($correosJson); ?>" data-telefonos="<?php echo msp2Escape($telefonosJson); ?>" data-direccion="<?php echo msp2Escape((string) ($arrendatario['direccion'] ?? '')); ?>" data-comuna="<?php echo (int) ($arrendatario['id_comuna'] ?? 0); ?>" data-estado="<?php echo (int) $arrendatario['id_estado_arrendatario']; ?>" aria-label="Editar arrendatario <?php echo msp2Escape($arrendatario['nombre_locatario']); ?>">
                                                    <i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Editar</span>
                                                </button>
                                                <?php if ($idContratoFila > 0): ?>
                                                    <a href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . $idContratoFila)); ?>" class="btn btn-outline-primary btn-sm" aria-label="Ver contrato ID <?php echo $idContratoFila; ?>"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Contrato</a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 gap-2">
                <div class="small text-muted">
                    Arrendatarios encontrados: <strong><?php echo number_format($totalRegistros, 0, ',', '.'); ?></strong>
                    | Página <strong><?php echo $paginaActual; ?></strong> de <strong><?php echo $totalPaginas; ?></strong>
                </div>

                <?php if ($totalPaginas > 1): ?>
                    <nav aria-label="Paginación de arrendatarios">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2ArrendatariosQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildMsp2ArrendatariosQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2ArrendatariosQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade msp-tenant-modal" id="modalImportarArrendatarios" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('arrendatarios/importar.php')); ?>" enctype="multipart/form-data">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Importar arrendatarios desde Excel</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="excel_file_arrendatarios" class="form-label">Archivo</label>
                    <input type="file" class="form-control" id="excel_file_arrendatarios" name="excel_file" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="d-flex justify-content-end mb-3">
                    <a href="<?php echo msp2Escape(msp2Url('arrendatarios/plantilla.php')); ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar plantilla Excel
                    </a>
                </div>
                <div class="alert alert-info mb-0">
                    Columnas obligatorias: <code>rut</code>, <code>nombre_locatario</code>.<br>
                    Opcionales: <code>nombre_representante</code>, <code>correos</code>, <code>telefonos</code>, <code>direccion</code>, <code>comuna</code>.<br>
                    <code>es_empresa</code> es opcional (si viene vacío, se toma como <strong>Persona natural</strong>).<br>
                    Si la comuna no existe en catálogo, se crea automáticamente al confirmar importación.<br>
                    Estado se asigna automáticamente a <strong>Activo</strong>.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Ver vista previa
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-tenant-modal" id="modalCrearArrendatario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-tenant-dialog">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('arrendatarios/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Registrar arrendatario</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-12 col-md-4">
                        <label for="crear_rut" class="form-label">RUT</label>
                        <input type="text" class="form-control" id="crear_rut" name="rut" maxlength="20" required>
                        <div class="form-text">Se acepta `212179507`, `21217950-7` o `21.217.950-7`.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="crear_es_empresa" class="form-label">Tipo de arrendatario</label>
                        <select class="form-select" id="crear_es_empresa" name="es_empresa" required>
                            <option value="0">Persona natural</option>
                            <option value="1">Empresa</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="crear_estado" class="form-label">Estado</label>
                        <select class="form-select" id="crear_estado" name="id_estado_arrendatario" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_arrendatario']; ?>">
                                    <?php echo msp2Escape($estado['desc_estado']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="crear_nombre_locatario" class="form-label">Nombre locatario</label>
                        <input type="text" class="form-control" id="crear_nombre_locatario" name="nombre_locatario" maxlength="200" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="crear_nombre_representante" class="form-label">Nombre representante</label>
                        <input type="text" class="form-control" id="crear_nombre_representante" name="nombre_representante" maxlength="200">
                    </div>
                    <div class="col-12">
                        <hr class="my-1">
                        <p class="fw-semibold mb-0">Información de contacto</p>
                    </div>
                    <div class="col-12 col-lg-6 msp-tenant-contact-group">
                        <label class="form-label d-block mb-1">Correos</label>
                        <div id="crear_correos_container" class="vstack gap-2"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btn_agregar_correo_crear">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar correo
                        </button>
                        <div class="form-text">Puedes registrar múltiples correos y marcar uno como principal.</div>
                    </div>
                    <div class="col-12 col-lg-6 msp-tenant-contact-group">
                        <label class="form-label d-block mb-1">Teléfonos</label>
                        <div id="crear_telefonos_container" class="vstack gap-2"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btn_agregar_telefono_crear">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar teléfono
                        </button>
                        <div class="form-text">Puedes registrar múltiples teléfonos y marcar uno como principal.</div>
                    </div>
                    <div class="col-12">
                        <hr class="my-1">
                        <p class="fw-semibold mb-0">Ubicación</p>
                    </div>
                    <div class="col-12 col-md-8">
                        <label for="crear_direccion" class="form-label">Dirección</label>
                        <input type="text" class="form-control" id="crear_direccion" name="direccion" maxlength="250">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="crear_id_comuna" class="form-label">Comuna</label>
                        <select class="form-select" id="crear_id_comuna" name="id_comuna">
                            <option value="">Sin comuna</option>
                            <?php foreach ($comunas as $comuna): ?>
                                <option value="<?php echo (int) $comuna['id_comuna']; ?>">
                                    <?php echo msp2Escape($comuna['desc_comuna']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar arrendatario</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-tenant-modal" id="modalEditarArrendatario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-tenant-dialog">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('arrendatarios/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar arrendatario</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_arrendatario" id="edit_id_arrendatario">
                <div class="row g-2">
                    <div class="col-12 col-md-4">
                        <label for="edit_rut" class="form-label">RUT</label>
                        <input type="text" class="form-control" id="edit_rut" name="rut" maxlength="20" required>
                        <div class="form-text">Al guardar, el sistema normaliza el RUT al formato interno estándar.</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_es_empresa" class="form-label">Tipo de arrendatario</label>
                        <select class="form-select" id="edit_es_empresa" name="es_empresa" required>
                            <option value="0">Persona natural</option>
                            <option value="1">Empresa</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_estado" class="form-label">Estado</label>
                        <select class="form-select" id="edit_estado" name="id_estado_arrendatario" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_arrendatario']; ?>">
                                    <?php echo msp2Escape($estado['desc_estado']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_nombre_locatario" class="form-label">Nombre locatario</label>
                        <input type="text" class="form-control" id="edit_nombre_locatario" name="nombre_locatario" maxlength="200" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_nombre_representante" class="form-label">Nombre representante</label>
                        <input type="text" class="form-control" id="edit_nombre_representante" name="nombre_representante" maxlength="200">
                    </div>
                    <div class="col-12">
                        <hr class="my-1">
                        <p class="fw-semibold mb-0">Información de contacto</p>
                    </div>
                    <div class="col-12 col-lg-6 msp-tenant-contact-group">
                        <label class="form-label d-block mb-1">Correos</label>
                        <div id="edit_correos_container" class="vstack gap-2"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btn_agregar_correo_editar">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar correo
                        </button>
                        <div class="form-text">Marca un correo principal para usarlo en listados.</div>
                    </div>
                    <div class="col-12 col-lg-6 msp-tenant-contact-group">
                        <label class="form-label d-block mb-1">Teléfonos</label>
                        <div id="edit_telefonos_container" class="vstack gap-2"></div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="btn_agregar_telefono_editar">
                            <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar teléfono
                        </button>
                        <div class="form-text">Marca un teléfono principal para usarlo en listados.</div>
                    </div>
                    <div class="col-12">
                        <hr class="my-1">
                        <p class="fw-semibold mb-0">Ubicación</p>
                    </div>
                    <div class="col-12 col-md-8">
                        <label for="edit_direccion" class="form-label">Dirección</label>
                        <input type="text" class="form-control" id="edit_direccion" name="direccion" maxlength="250">
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="edit_id_comuna" class="form-label">Comuna</label>
                        <select class="form-select" id="edit_id_comuna" name="id_comuna">
                            <option value="">Sin comuna</option>
                            <?php foreach ($comunas as $comuna): ?>
                                <option value="<?php echo (int) $comuna['id_comuna']; ?>">
                                    <?php echo msp2Escape($comuna['desc_comuna']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

<?php include dirname(__DIR__) . '/templates/components/undo_toast.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const sanitizeRut = (value) => value.toUpperCase().replace(/[^0-9K]/g, '');
    const formatRut = (value) => {
        const clean = sanitizeRut(value);

        if (clean.length < 2) {
            return value.trim();
        }

        const body = clean.slice(0, -1);
        const dv = clean.slice(-1);
        const formattedBody = body.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

        return `${formattedBody}-${dv}`;
    };

    const createContactRow = (config, index, value = '', esPrincipal = false) => {
        const row = document.createElement('div');
        row.className = 'input-group js-contact-row';

        const prepend = document.createElement('span');
        prepend.className = 'input-group-text';

        const radio = document.createElement('input');
        radio.type = 'radio';
        radio.className = 'form-check-input mt-0 js-contact-principal';
        radio.name = config.principalName;
        radio.value = String(index);
        radio.checked = esPrincipal;
        radio.setAttribute('aria-label', config.principalAriaLabel);
        prepend.appendChild(radio);

        const input = document.createElement('input');
        input.type = config.inputType;
        input.className = 'form-control js-contact-input';
        input.name = `${config.inputName}[${index}]`;
        input.maxLength = config.maxLength;
        input.placeholder = config.placeholder;
        input.value = value;

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn btn-outline-danger js-remove-contact';
        removeBtn.innerHTML = '<i class="bi bi-trash" aria-hidden="true"></i>';

        row.appendChild(prepend);
        row.appendChild(input);
        row.appendChild(removeBtn);

        return row;
    };

    const reindexContacts = (container, config) => {
        const rows = Array.from(container.querySelectorAll('.js-contact-row'));

        rows.forEach((row, index) => {
            const input = row.querySelector('.js-contact-input');
            const radio = row.querySelector('.js-contact-principal');

            if (input) {
                input.name = `${config.inputName}[${index}]`;
            }

            if (radio) {
                radio.value = String(index);
            }
        });

        const radios = rows.map((row) => row.querySelector('.js-contact-principal')).filter(Boolean);
        if (radios.length > 0 && !radios.some((radio) => radio.checked)) {
            radios[0].checked = true;
        }
    };

    const ensureContactRows = (container, config) => {
        if (container.querySelectorAll('.js-contact-row').length === 0) {
            container.appendChild(createContactRow(config, 0, '', true));
        }
        reindexContacts(container, config);
    };

    const appendContactRow = (container, config, value = '', esPrincipal = false) => {
        const nextIndex = container.querySelectorAll('.js-contact-row').length;
        container.appendChild(createContactRow(config, nextIndex, value, esPrincipal));
        reindexContacts(container, config);
    };

    const renderContacts = (container, config, items) => {
        container.innerHTML = '';

        if (!Array.isArray(items) || items.length === 0) {
            container.appendChild(createContactRow(config, 0, '', true));
            return;
        }

        let principalAlreadyMarked = false;

        items.forEach((item, index) => {
            const value = (item && typeof item[config.itemKey] === 'string') ? item[config.itemKey] : '';
            const esPrincipal = item && (item.es_principal === 1 || item.es_principal === true);
            const markPrincipal = esPrincipal && !principalAlreadyMarked;

            if (markPrincipal) {
                principalAlreadyMarked = true;
            }

            container.appendChild(createContactRow(config, index, value, markPrincipal));
        });

        reindexContacts(container, config);
    };

    const registerContactContainerEvents = (container, config) => {
        const addButton = document.getElementById(config.addButtonId);
        if (addButton) {
            addButton.addEventListener('click', () => {
                appendContactRow(container, config, '', false);
                const newRowInput = container.querySelector('.js-contact-row:last-child .js-contact-input');
                if (newRowInput) {
                    newRowInput.focus();
                }
            });
        }

        container.addEventListener('click', (event) => {
            const button = event.target.closest('.js-remove-contact');
            if (!button) {
                return;
            }

            const row = button.closest('.js-contact-row');
            if (!row) {
                return;
            }

            const rows = container.querySelectorAll('.js-contact-row');
            if (rows.length <= 1) {
                const input = row.querySelector('.js-contact-input');
                const radio = row.querySelector('.js-contact-principal');
                if (input) {
                    input.value = '';
                }
                if (radio) {
                    radio.checked = true;
                }
                return;
            }

            row.remove();
            reindexContacts(container, config);
        });
    };

    const parseJsonArray = (raw) => {
        try {
            const parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    const crearCorreosContainer = document.getElementById('crear_correos_container');
    const editarCorreosContainer = document.getElementById('edit_correos_container');
    const crearTelefonosContainer = document.getElementById('crear_telefonos_container');
    const editarTelefonosContainer = document.getElementById('edit_telefonos_container');

    const correosConfigCrear = {
        inputName: 'correos',
        principalName: 'correo_principal',
        principalAriaLabel: 'Correo principal',
        inputType: 'email',
        maxLength: 200,
        placeholder: 'correo@dominio.cl',
        addButtonId: 'btn_agregar_correo_crear',
        itemKey: 'correo',
    };
    const correosConfigEditar = {
        ...correosConfigCrear,
        addButtonId: 'btn_agregar_correo_editar',
    };

    const telefonosConfigCrear = {
        inputName: 'telefonos',
        principalName: 'telefono_principal',
        principalAriaLabel: 'Teléfono principal',
        inputType: 'text',
        maxLength: 50,
        placeholder: '+56 9 1234 5678',
        addButtonId: 'btn_agregar_telefono_crear',
        itemKey: 'telefono',
    };
    const telefonosConfigEditar = {
        ...telefonosConfigCrear,
        addButtonId: 'btn_agregar_telefono_editar',
    };

    if (crearCorreosContainer) {
        registerContactContainerEvents(crearCorreosContainer, correosConfigCrear);
        ensureContactRows(crearCorreosContainer, correosConfigCrear);
    }

    if (editarCorreosContainer) {
        registerContactContainerEvents(editarCorreosContainer, correosConfigEditar);
        ensureContactRows(editarCorreosContainer, correosConfigEditar);
    }

    if (crearTelefonosContainer) {
        registerContactContainerEvents(crearTelefonosContainer, telefonosConfigCrear);
        ensureContactRows(crearTelefonosContainer, telefonosConfigCrear);
    }

    if (editarTelefonosContainer) {
        registerContactContainerEvents(editarTelefonosContainer, telefonosConfigEditar);
        ensureContactRows(editarTelefonosContainer, telefonosConfigEditar);
    }

    const modalCrear = document.getElementById('modalCrearArrendatario');
    if (modalCrear) {
        modalCrear.addEventListener('show.bs.modal', () => {
            const form = modalCrear.querySelector('form');
            if (form) {
                form.reset();
            }
            if (crearCorreosContainer) {
                renderContacts(crearCorreosContainer, correosConfigCrear, []);
            }
            if (crearTelefonosContainer) {
                renderContacts(crearTelefonosContainer, telefonosConfigCrear, []);
            }
        });
    }

    document.querySelectorAll('.js-edit-arrendatario').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_arrendatario').value = button.dataset.id || '';
            document.getElementById('edit_rut').value = button.dataset.rut || '';
            document.getElementById('edit_es_empresa').value = button.dataset.esEmpresa || '0';
            document.getElementById('edit_nombre_locatario').value = button.dataset.nombre || '';
            document.getElementById('edit_nombre_representante').value = button.dataset.representante || '';
            document.getElementById('edit_direccion').value = button.dataset.direccion || '';
            document.getElementById('edit_id_comuna').value = button.dataset.comuna && button.dataset.comuna !== '0' ? button.dataset.comuna : '';
            document.getElementById('edit_estado').value = button.dataset.estado || '';

            if (editarCorreosContainer) {
                renderContacts(editarCorreosContainer, correosConfigEditar, parseJsonArray(button.dataset.correos));
            }

            if (editarTelefonosContainer) {
                renderContacts(editarTelefonosContainer, telefonosConfigEditar, parseJsonArray(button.dataset.telefonos));
            }
        });
    });

    ['crear_rut', 'edit_rut'].forEach((fieldId) => {
        const field = document.getElementById(fieldId);

        if (!field) {
            return;
        }

        field.addEventListener('blur', () => {
            field.value = formatRut(field.value);
        });
    });
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
