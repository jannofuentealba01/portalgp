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
$loadError = null;

$tiendas = [];
$arrendatarios = [];
$rubros = [];
$estados = [];
$localesActivosPorTienda = [];
$selectorLocalesHabilitado = false;
$localesSelector = [];
$moduloContratoHabilitado = false;
$contratosPorTienda = [];
$moduloCargosHabilitado = false;
$tiposCargoSalida = [];
$cargosPorTienda = [];
$tablaDocumentosCobroExiste = false;

$totalRegistros = 0;
$totalPaginas = 1;
$paginationItems = [];

$lineasPermitidas = [10, 25, 50, 100, 200];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric($_GET['lineas']) ? (int) $_GET['lineas'] : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric($_GET['pagina']) ? max(1, (int) $_GET['pagina']) : 1;
$filtroTexto = msp2NormalizeText($_GET['filtroTexto'] ?? null);
$filtroEstado = trim((string) ($_GET['filtroEstado'] ?? ''));
$filtroRubro = trim((string) ($_GET['filtroRubro'] ?? ''));

try {
    $requiredTables = ['msp_tiendas', 'msp_arrendatarios', 'msp_rubros', 'msp_estado_tiendas'];
    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para gestión de tiendas: `' . implode('`, `', $missingTables) . '`. Ejecuta `msp/msp_a1.sql`.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base de tiendas.';
}

if ($tablaExiste) {
    try {
        $arrStmt = $conn->query(
            'SELECT id_arrendatario, rut, nombre_locatario
             FROM dbo.msp_arrendatarios
             ORDER BY nombre_locatario ASC'
        );
        $arrendatarios = $arrStmt->fetchAll();

        $rubStmt = $conn->query('SELECT id_rubro, nombre_rubro FROM dbo.msp_rubros ORDER BY nombre_rubro ASC');
        $rubros = $rubStmt->fetchAll();

        $estStmt = $conn->query('SELECT id_estado_tienda, desc_estado FROM dbo.msp_estado_tiendas ORDER BY id_estado_tienda ASC');
        $estados = $estStmt->fetchAll();

        $moduloContratoHabilitado = msp2TableExists($conn, 'msp_contratos_arriendo')
            && msp2TableExists($conn, 'msp_locales');
        $tablaDocumentosCobroExiste = msp2TableExists($conn, 'msp_documentos_cobro');
        $moduloCargosHabilitado = msp2TableExists($conn, 'msp_cargos_salida')
            && msp2TableExists($conn, 'msp_tipos_cargo_salida')
            && msp2TableExists($conn, 'msp_contratos_arriendo')
            && msp2TableExists($conn, 'msp_locales');

        if ($moduloCargosHabilitado) {
            $tiposCargoStmt = $conn->query(
                'SELECT
                    id_tipo_cargo_salida,
                    codigo_tipo_cargo,
                    nombre_tipo_cargo,
                    requiere_documento,
                    permite_estimacion
                 FROM dbo.msp_tipos_cargo_salida
                 WHERE activo = 1
                 ORDER BY id_tipo_cargo_salida ASC'
            );
            $tiposCargoSalida = $tiposCargoStmt->fetchAll();
        }

        if (msp2TableExists($conn, 'msp_locales') && msp2TableExists($conn, 'msp_ocupacion_locales')) {
            $selectorStmt = $conn->query(
                'SELECT
                    l.id_local,
                    l.cdo_local,
                    l.desc_local,
                    ocupacionActiva.id_tienda AS id_tienda_ocupante
                 FROM dbo.msp_locales l
                 OUTER APPLY (
                    SELECT TOP 1 ol.id_tienda
                    FROM dbo.msp_ocupacion_locales ol
                    WHERE ol.id_local = l.id_local
                      AND ol.fecha_inicio <= CONVERT(date, SYSDATETIME())
                      AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= CONVERT(date, SYSDATETIME()))
                    ORDER BY ol.fecha_inicio DESC, ol.id_ocupacion_local DESC
                 ) ocupacionActiva
                 ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            $rowsLocales = $selectorStmt->fetchAll();

            foreach ($rowsLocales as $rowLocal) {
                $codigo = msp2NormalizeLocalCode((string) ($rowLocal['cdo_local'] ?? ''));
                if ($codigo === '') {
                    continue;
                }

                $descripcion = msp2NormalizeText((string) ($rowLocal['desc_local'] ?? ''));
                $idOcupante = isset($rowLocal['id_tienda_ocupante']) ? (int) $rowLocal['id_tienda_ocupante'] : 0;

                $localesSelector[] = [
                    'code' => $codigo,
                    'label' => $descripcion !== '' ? ($codigo . ' - ' . $descripcion) : $codigo,
                    'disponible' => $idOcupante <= 0,
                    'id_tienda_ocupante' => $idOcupante > 0 ? $idOcupante : null,
                ];
            }

            $selectorLocalesHabilitado = $localesSelector !== [];
        }

        $conditions = [];
        $params = [];

        if ($filtroTexto !== '') {
            $conditions[] = "(
                ISNULL(t.nombre_comercial, '') LIKE :filtro_nombre
                OR ISNULL(a.nombre_locatario, '') LIKE :filtro_arrendatario
                OR ISNULL(a.rut, '') LIKE :filtro_rut
            )";
            $params[':filtro_nombre'] = '%' . $filtroTexto . '%';
            $params[':filtro_arrendatario'] = '%' . $filtroTexto . '%';
            $params[':filtro_rut'] = '%' . $filtroTexto . '%';
        }

        if ($filtroEstado !== '' && ctype_digit($filtroEstado)) {
            $conditions[] = 't.id_estado_tienda = :filtro_estado';
            $params[':filtro_estado'] = (int) $filtroEstado;
        }

        if ($filtroRubro !== '' && ctype_digit($filtroRubro)) {
            $conditions[] = 't.id_rubro = :filtro_rubro';
            $params[':filtro_rubro'] = (int) $filtroRubro;
        }

        $whereClause = $conditions === [] ? '1=1' : implode(' AND ', $conditions);
        $ordenarTiendasPorLocalDisponible = msp2TableExists($conn, 'msp_ocupacion_locales')
            && msp2TableExists($conn, 'msp_locales');
        $localSortApplySql = '';
        $orderBySql = 't.nombre_comercial ASC, t.id_tienda ASC';
        if ($ordenarTiendasPorLocalDisponible) {
            $localSortApplySql =
                "OUTER APPLY (
                    SELECT TOP 1
                        lsort.cdo_local
                    FROM dbo.msp_ocupacion_locales olsort
                    INNER JOIN dbo.msp_locales lsort
                        ON lsort.id_local = olsort.id_local
                    WHERE olsort.id_tienda = t.id_tienda
                    ORDER BY
                        CASE
                            WHEN olsort.fecha_inicio <= CONVERT(date, SYSDATETIME())
                             AND (olsort.fecha_termino IS NULL OR olsort.fecha_termino >= CONVERT(date, SYSDATETIME()))
                                THEN 0
                            ELSE 1
                        END,
                        CASE WHEN olsort.fecha_termino IS NULL THEN 0 ELSE 1 END,
                        ISNULL(olsort.fecha_termino, CONVERT(date, '9999-12-31')) DESC,
                        ISNULL(olsort.fecha_inicio, CONVERT(date, '1900-01-01')) DESC,
                        " . msp2LocalCodeNaturalOrderSql('lsort.cdo_local') . "
                ) primerLocal";
            $orderBySql =
                "CASE WHEN NULLIF(LTRIM(RTRIM(primerLocal.cdo_local)), '') IS NULL THEN 1 ELSE 0 END, "
                . msp2LocalCodeNaturalOrderSql('primerLocal.cdo_local')
                . ', t.nombre_comercial ASC, t.id_tienda ASC';
        }

        $countStmt = $conn->prepare(
            "SELECT COUNT(*)
             FROM dbo.msp_tiendas t
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
             INNER JOIN dbo.msp_rubros r ON r.id_rubro = t.id_rubro
             INNER JOIN dbo.msp_estado_tiendas e ON e.id_estado_tienda = t.id_estado_tienda
             WHERE $whereClause"
        );

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
                t.id_tienda,
                t.id_arrendatario,
                t.id_rubro,
                t.id_estado_tienda,
                t.nombre_comercial,
                t.fecha_inicio,
                t.fecha_termino,
                t.fecha_registro,
                a.rut,
                a.nombre_locatario,
                r.nombre_rubro,
                e.desc_estado"
                . ($tablaDocumentosCobroExiste
                    ? ",
                ISNULL(docPend.cantidad_docs_pendientes, 0) AS cantidad_docs_pendientes"
                    : '')
                . "
             FROM dbo.msp_tiendas t
             INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario
             INNER JOIN dbo.msp_rubros r ON r.id_rubro = t.id_rubro
             INNER JOIN dbo.msp_estado_tiendas e ON e.id_estado_tienda = t.id_estado_tienda
             " . ($tablaDocumentosCobroExiste
                ? "OUTER APPLY (
                    SELECT COUNT(*) AS cantidad_docs_pendientes
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.id_tienda = t.id_tienda
                      AND dc.estado_documento IN (1,2,3)
                      AND dc.saldo_pendiente > 0
                 ) docPend"
                : '') . "
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
        $tiendas = $stmt->fetchAll();

        $idsTiendasPagina = [];
        foreach ($tiendas as $tienda) {
            $idsTiendasPagina[] = (int) $tienda['id_tienda'];
        }
        $idsTiendasPagina = array_values(array_unique($idsTiendasPagina));

        if ($idsTiendasPagina !== []) {
            $placeholders = [];
            foreach ($idsTiendasPagina as $index => $_id) {
                $placeholders[] = ':id_' . $index;
            }

            if (msp2TableExists($conn, 'msp_ocupacion_locales') && msp2TableExists($conn, 'msp_locales')) {
                $ocupStmt = $conn->prepare(
                    'SELECT
                        ol.id_tienda,
                        l.cdo_local,
                        ol.fecha_inicio,
                        ol.fecha_termino
                     FROM dbo.msp_ocupacion_locales ol
                     INNER JOIN dbo.msp_locales l ON l.id_local = ol.id_local
                     WHERE ol.id_tienda IN (' . implode(', ', $placeholders) . ')
                     ORDER BY ol.id_tienda ASC, ol.fecha_inicio DESC, ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
                );

                foreach ($idsTiendasPagina as $index => $_id) {
                    $ocupStmt->bindValue(':id_' . $index, (int) $_id, PDO::PARAM_INT);
                }

                $ocupStmt->execute();
                $today = new DateTimeImmutable('today');

                while (($row = $ocupStmt->fetch()) !== false) {
                    $idTienda = (int) $row['id_tienda'];
                    if (!isset($localesActivosPorTienda[$idTienda])) {
                        $localesActivosPorTienda[$idTienda] = [];
                    }

                    $cdoLocal = msp2NormalizeText((string) ($row['cdo_local'] ?? ''));
                    if ($cdoLocal === '') {
                        continue;
                    }

                    $fechaInicio = $row['fecha_inicio'] ? new DateTimeImmutable((string) $row['fecha_inicio']) : null;
                    $fechaTermino = $row['fecha_termino'] ? new DateTimeImmutable((string) $row['fecha_termino']) : null;

                    $activo = false;
                    if ($fechaInicio !== null && $fechaInicio <= $today) {
                        $activo = $fechaTermino === null || $fechaTermino >= $today;
                    }

                    if ($activo) {
                        $localesActivosPorTienda[$idTienda][] = $cdoLocal;
                    }
                }

                foreach ($localesActivosPorTienda as $idTienda => $codes) {
                    $codes = array_values(array_unique($codes));
                    sort($codes);
                    $localesActivosPorTienda[$idTienda] = $codes;
                }
            }

            if ($moduloContratoHabilitado) {
                $contratosStmt = $conn->prepare(
                    'SELECT
                        c.id_tienda,
                        c.id_contrato_arriendo,
                        c.estado_contrato,
                        c.fecha_inicio,
                        c.fecha_termino_pactada,
                        c.monto_arriendo_pactado,
                        c.rubro_contrato
                    FROM dbo.msp_contratos_arriendo c
                    WHERE c.id_tienda IN (' . implode(', ', $placeholders) . ')
                      AND c.estado_contrato IN (1,2,3)
                    ORDER BY c.id_tienda ASC, c.id_contrato_arriendo DESC'
                );

                foreach ($idsTiendasPagina as $index => $_id) {
                    $contratosStmt->bindValue(':id_' . $index, (int) $_id, PDO::PARAM_INT);
                }

                $contratosStmt->execute();
                while (($row = $contratosStmt->fetch()) !== false) {
                    $idTienda = (int) $row['id_tienda'];
                    if ($idTienda <= 0) {
                        continue;
                    }

                    if (!isset($contratosPorTienda[$idTienda])) {
                        $contratosPorTienda[$idTienda] = [
                            'id_contrato_arriendo' => (int) $row['id_contrato_arriendo'],
                            'estado_contrato' => isset($row['estado_contrato']) ? (int) $row['estado_contrato'] : 0,
                            'fecha_inicio' => $row['fecha_inicio'] ? (new DateTimeImmutable((string) $row['fecha_inicio']))->format('Y-m-d') : '',
                            'fecha_termino_pactada' => $row['fecha_termino_pactada'] ? (new DateTimeImmutable((string) $row['fecha_termino_pactada']))->format('Y-m-d') : '',
                            'monto_arriendo_pactado' => $row['monto_arriendo_pactado'] !== null
                                ? number_format((float) $row['monto_arriendo_pactado'], 2, '.', '')
                                : '',
                            'rubro_contrato' => msp2NormalizeText((string) ($row['rubro_contrato'] ?? '')),
                        ];
                    }
                }
            }

            if ($moduloCargosHabilitado) {
                $cargosStmt = $conn->prepare(
                    'SELECT
                        c.id_tienda,
                        cs.id_cargo_salida,
                        cs.id_tipo_cargo_salida,
                        l.cdo_local,
                        tc.codigo_tipo_cargo,
                        tc.nombre_tipo_cargo,
                        tc.requiere_documento,
                        cs.fecha_cargo,
                        cs.periodo_referencia,
                        cs.servicio_referencia,
                        cs.descripcion_cargo,
                        cs.monto_cargo,
                        cs.es_estimado,
                        cs.observaciones,
                        cs.estado_cargo
                    FROM dbo.msp_cargos_salida cs
                    INNER JOIN dbo.msp_contratos_arriendo c
                        ON c.id_contrato_arriendo = cs.id_contrato_arriendo
                    INNER JOIN dbo.msp_locales l
                        ON l.id_local = cs.id_local
                    INNER JOIN dbo.msp_tipos_cargo_salida tc
                        ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
                    WHERE c.id_tienda IN (' . implode(', ', $placeholders) . ')
                    ORDER BY c.id_tienda ASC, cs.fecha_cargo DESC, cs.id_cargo_salida DESC'
                );

                foreach ($idsTiendasPagina as $index => $_id) {
                    $cargosStmt->bindValue(':id_' . $index, (int) $_id, PDO::PARAM_INT);
                }

                $cargosStmt->execute();
                while (($row = $cargosStmt->fetch()) !== false) {
                    $idTienda = (int) ($row['id_tienda'] ?? 0);
                    if ($idTienda <= 0) {
                        continue;
                    }

                    if (!isset($cargosPorTienda[$idTienda])) {
                        $cargosPorTienda[$idTienda] = [];
                    }

                    $fechaCargo = (string) ($row['fecha_cargo'] ?? '');
                    $fechaCargoLabel = $fechaCargo !== '' ? (new DateTimeImmutable($fechaCargo))->format('d-m-Y') : '-';
                    $periodoReferencia = (string) ($row['periodo_referencia'] ?? '');
                    $periodoReferenciaLabel = '';
                    $periodoReferenciaInput = '';
                    if ($periodoReferencia !== '') {
                        $periodoReferenciaLabel = (new DateTimeImmutable($periodoReferencia))->format('m-Y');
                        $periodoReferenciaInput = (new DateTimeImmutable($periodoReferencia))->format('Y-m');
                    }

                    $estadoCargo = (int) ($row['estado_cargo'] ?? 0);
                    $requiereDocumento = ((int) ($row['requiere_documento'] ?? 0)) === 1;
                    $cargosPorTienda[$idTienda][] = [
                        'id_cargo_salida' => (int) ($row['id_cargo_salida'] ?? 0),
                        'id_tipo_cargo_salida' => (int) ($row['id_tipo_cargo_salida'] ?? 0),
                        'codigo_local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
                        'codigo_tipo_cargo' => msp2NormalizeText((string) ($row['codigo_tipo_cargo'] ?? '')),
                        'tipo_cargo_label' => msp2NormalizeText((string) ($row['nombre_tipo_cargo'] ?? '')),
                        'fecha_cargo_label' => $fechaCargoLabel,
                        'fecha_cargo_input' => $fechaCargo !== '' ? (new DateTimeImmutable($fechaCargo))->format('Y-m-d') : '',
                        'periodo_referencia_label' => $periodoReferenciaLabel,
                        'periodo_referencia_input' => $periodoReferenciaInput,
                        'servicio_referencia' => msp2NormalizeText((string) ($row['servicio_referencia'] ?? '')),
                        'descripcion_cargo' => msp2NormalizeText((string) ($row['descripcion_cargo'] ?? '')),
                        'monto_input' => number_format((float) ($row['monto_cargo'] ?? 0), 2, '.', ''),
                        'monto_label' => msp2FormatoDecimal($row['monto_cargo'] ?? 0, 2, '$'),
                        'es_estimado' => ((int) ($row['es_estimado'] ?? 0)) === 1,
                        'observaciones' => msp2NormalizeText((string) ($row['observaciones'] ?? '')),
                        'estado_cargo' => $estadoCargo,
                        'estado_label' => msp2CargoEstadoLabel($estadoCargo),
                        'estado_badge' => msp2CargoEstadoBadge($estadoCargo),
                        'anulable' => $estadoCargo === 1,
                        'editable' => $estadoCargo === 1 && !$requiereDocumento,
                    ];
                }
            }
        }
    } catch (Throwable $exception) {
        $loadError = 'No fue posible cargar las tiendas. Detalle técnico: ' . $exception->getMessage();
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
$cargosPorTiendaJson = json_encode(
    $cargosPorTienda,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);

function buildMsp2TiendasQuery(array $base, array $override = []): string
{
    return http_build_query(array_merge($base, $override));
}

function msp2TiendaEstadoBadge(?string $estado): string
{
    $estadoNormalizado = mb_strtolower(trim((string) $estado));

    return match ($estadoNormalizado) {
        'activo' => 'bg-success',
        'inactivo' => 'bg-secondary',
        'cerrada', 'cerrado' => 'bg-danger',
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

function msp2CargoEstadoLabel(int $estado): string
{
    return match ($estado) {
        1 => 'Pendiente',
        2 => 'Reservado',
        3 => 'Aplicado',
        4 => 'Pagado',
        5 => 'Anulado',
        default => 'Sin estado',
    };
}

function msp2CargoEstadoBadge(int $estado): string
{
    return match ($estado) {
        1 => 'bg-warning text-dark',
        2 => 'bg-info text-dark',
        3 => 'bg-primary',
        4 => 'bg-success',
        5 => 'bg-secondary',
        default => 'bg-light text-dark',
    };
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Tiendas</title>
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
    <div class="msp-management-index msp-stores-index">
        <header class="msp-management-page-header msp-stores-page-header">
            <div class="msp-stores-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Tiendas</h1>
            <div class="d-flex flex-wrap gap-2 msp-management-actions msp-stores-actions">
                <a href="<?php echo msp2Escape(msp2Url('locales/index.php')); ?>" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-door-open me-1" aria-hidden="true"></i>Catálogo de locales
                </a>
                <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Ir a contratos
                </a>
                <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Importar contratos
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCrearTienda">
                    <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar tienda
                </button>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" class="row g-2 msp-management-filters msp-stores-filters align-items-end">
                <div class="col-12 col-md-4">
                    <label for="filtroTexto" class="form-label">Nombre, RUT o arrendatario</label>
                    <input type="text" id="filtroTexto" name="filtroTexto" class="form-control" value="<?php echo msp2Escape($filtroTexto); ?>" placeholder="Buscar">
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroRubro" class="form-label">Rubro</label>
                    <select id="filtroRubro" name="filtroRubro" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($rubros as $rubro): ?>
                            <option value="<?php echo (int) $rubro['id_rubro']; ?>" <?php echo $filtroRubro === (string) $rubro['id_rubro'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($rubro['nombre_rubro']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="filtroEstado" class="form-label">Estado</label>
                    <select id="filtroEstado" name="filtroEstado" class="form-select">
                        <option value="">(Todos)</option>
                        <?php foreach ($estados as $estado): ?>
                            <option value="<?php echo (int) $estado['id_estado_tienda']; ?>" <?php echo $filtroEstado === (string) $estado['id_estado_tienda'] ? 'selected' : ''; ?>>
                                <?php echo msp2Escape($estado['desc_estado']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="lineas" class="form-label">Líneas</label>
                    <select id="lineas" name="lineas" class="form-select">
                        <?php foreach ($lineasPermitidas as $lineas): ?>
                            <option value="<?php echo $lineas; ?>" <?php echo $lineasPorPagina === $lineas ? 'selected' : ''; ?>>
                                <?php echo $lineas; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary msp-store-filter-submit">Filtrar</button>
                </div>
            </form>

            <div class="msp-management-table-responsive">
                <table class="table table-hover align-middle text-center msp-management-table msp-stores-table">
                    <thead class="table-light">
                        <tr>
                            <th class="store-number">#</th>
                            <th class="store-name">Nombre comercial</th>
                            <th class="store-tenant">Arrendatario</th>
                            <th class="store-date">Fecha inicio</th>
                            <th class="store-locals">Locales asociados</th>
                            <th class="store-actions">Acciones</th>
                            <th class="store-contract">Contrato</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tiendas)): ?>
                            <tr>
                                <td colspan="7" class="text-muted">
                                    <?php echo ($filtroTexto === '' && $filtroRubro === '' && $filtroEstado === '') ? 'No hay tiendas registradas todavía.' : 'Sin resultados para los filtros actuales.'; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tiendas as $index => $tienda): ?>
                                <?php
                                $idTienda = (int) $tienda['id_tienda'];
                                $localesActivos = $localesActivosPorTienda[$idTienda] ?? [];
                                $localesInput = $localesActivos === [] ? '' : implode(';', $localesActivos);
                                $fechaInicio = $tienda['fecha_inicio'] ? (new DateTimeImmutable((string) $tienda['fecha_inicio']))->format('d-m-Y') : '-';
                                $fechaInicioInput = $tienda['fecha_inicio'] ? (new DateTimeImmutable((string) $tienda['fecha_inicio']))->format('Y-m-d') : '';
                                $contratoData = $contratosPorTienda[$idTienda] ?? null;
                                $localesCargo = $localesActivos;
                                $localesCargo = array_values(array_unique($localesCargo));
                                sort($localesCargo);
                                $localesCargoInput = $localesCargo === [] ? '' : implode(';', $localesCargo);
                                $cargosTienda = $cargosPorTienda[$idTienda] ?? [];
                                $cantidadCargosTienda = count($cargosTienda);
                                ?>
                                <tr>
                                    <td><?php echo (($paginaActual - 1) * $lineasPorPagina) + $index + 1; ?></td>
                                    <td class="text-start store-name"><?php echo msp2Escape((string) $tienda['nombre_comercial']); ?></td>
                                    <td class="text-start store-tenant"><?php echo msp2Escape((string) $tienda['nombre_locatario']); ?></td>
                                    <td class="store-date"><?php echo msp2Escape($fechaInicio); ?></td>
                                    <td class="text-start store-locals">
                                        <?php if ($localesActivos === []): ?>
                                            <span class="badge bg-light text-dark border">Sin locales asociados</span>
                                        <?php else: ?>
                                            <div class="d-flex flex-wrap gap-1">
                                                <?php foreach ($localesActivos as $codigoLocal): ?>
                                                    <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle"><?php echo msp2Escape($codigoLocal); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="store-actions">
                                        <div class="table-actions">
                                            <?php if ($contratoData !== null): ?>
                                                <a
                                                    href="<?php echo msp2Escape(msp2Url('contratos/ficha.php?id_contrato_arriendo=' . (int) ($contratoData['id_contrato_arriendo'] ?? 0))); ?>"
                                                    class="btn btn-outline-primary btn-sm"
                                                    aria-label="Ver ficha del contrato de <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>"
                                                    title="Ver ficha del contrato">
                                                    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                                </a>
                                            <?php else: ?>
                                                <a
                                                    href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>"
                                                    class="btn btn-outline-primary btn-sm"
                                                    aria-label="Crear contrato para <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>">
                                                    <i class="bi bi-plus-square" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($moduloCargosHabilitado): ?>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-warning btn-sm js-cargo-tienda"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalCrearCargo"
                                                    data-id="<?php echo $idTienda; ?>"
                                                    data-label="<?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>"
                                                    data-locales-cargo="<?php echo msp2Escape($localesCargoInput); ?>"
                                                    aria-label="Registrar cargo en <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>">
                                                    <i class="bi bi-cash-coin" aria-hidden="true"></i>
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-dark btn-sm js-ver-cargos-tienda"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modalVerCargosTienda"
                                                    data-id="<?php echo $idTienda; ?>"
                                                    data-label="<?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>"
                                                    aria-label="Ver cargos de <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>">
                                                    <i class="bi bi-list-ul" aria-hidden="true"></i>
                                                    <span class="ms-1"><?php echo $cantidadCargosTienda; ?></span>
                                                </button>
                                            <?php endif; ?>
                                            <button
                                                type="button"
                                                class="btn btn-outline-primary btn-sm js-edit-tienda"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modalEditarTienda"
                                                data-id="<?php echo $idTienda; ?>"
                                                data-arrendatario="<?php echo (int) $tienda['id_arrendatario']; ?>"
                                                data-rubro="<?php echo (int) $tienda['id_rubro']; ?>"
                                                data-estado="<?php echo (int) $tienda['id_estado_tienda']; ?>"
                                                data-nombre="<?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>"
                                                data-fecha="<?php echo msp2Escape($fechaInicioInput); ?>"
                                                data-locales="<?php echo msp2Escape($localesInput); ?>"
                                                aria-label="Editar tienda <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>">
                                                <i class="bi bi-pencil" aria-hidden="true"></i>
                                            </button>
                                            <form
                                                method="post"
                                                action="<?php echo msp2Escape(msp2Url('tiendas/eliminar.php')); ?>"
                                                class="d-inline"
                                                data-confirm-message="¿Eliminar la tienda &quot;<?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>&quot;?"
                                                data-confirm-title="Confirmar eliminación"
                                                data-confirm-variant="danger">
                                                <input type="hidden" name="id_tienda" value="<?php echo $idTienda; ?>">
                                                <button
                                                    type="submit"
                                                    class="btn btn-outline-danger btn-sm"
                                                    aria-label="Eliminar tienda <?php echo msp2Escape((string) $tienda['nombre_comercial']); ?>">
                                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                    <td class="store-contract">
                                        <?php if ($contratoData === null): ?>
                                            <span class="badge bg-light text-dark">Sin contrato</span>
                                        <?php else: ?>
                                            <?php
                                            $estadoContrato = (int) ($contratoData['estado_contrato'] ?? 0);
                                            ?>
                                            <span class="badge <?php echo msp2ContratoEstadoBadge($estadoContrato); ?>">
                                                <?php echo msp2Escape(msp2ContratoEstadoLabel($estadoContrato)); ?>
                                            </span>
                                        <?php endif; ?>
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
                    <nav aria-label="Paginación de tiendas">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $paginaActual <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2TiendasQuery($queryBase, ['pagina' => max(1, $paginaActual - 1)]); ?>" aria-label="Anterior">&laquo;</a>
                            </li>
                            <?php foreach ($paginationItems as $item): ?>
                                <?php if ($item === 'ellipsis'): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php else: ?>
                                    <li class="page-item <?php echo (int) $item === $paginaActual ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo buildMsp2TiendasQuery($queryBase, ['pagina' => $item]); ?>"><?php echo $item; ?></a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <li class="page-item <?php echo $paginaActual >= $totalPaginas ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?<?php echo buildMsp2TiendasQuery($queryBase, ['pagina' => min($totalPaginas, $paginaActual + 1)]); ?>" aria-label="Siguiente">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade msp-store-modal" id="modalCrearTienda" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-store-dialog">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('tiendas/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Agregar tienda</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2">
                    <div class="col-12 col-lg-4">
                        <label for="crear_arrendatario_dropdown_btn" class="form-label">Arrendatario</label>
                        <input type="hidden" id="crear_id_arrendatario" name="id_arrendatario" value="">
                        <div class="dropdown w-100" id="crear_arrendatario_picker">
                            <button
                                class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                type="button"
                                id="crear_arrendatario_dropdown_btn"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                                Seleccionar arrendatario...
                            </button>
                            <div class="dropdown-menu p-2 w-100" aria-labelledby="crear_arrendatario_dropdown_btn">
                                <input type="text" id="crear_arrendatario_dropdown_filter" class="form-control mb-2" placeholder="Buscar por RUT o nombre">
                                <div class="list-group list-group-flush overflow-auto" id="crear_arrendatario_dropdown_list" style="max-height: 220px;">
                                    <?php foreach ($arrendatarios as $arrendatario): ?>
                                        <?php
                                        $arrId = (int) ($arrendatario['id_arrendatario'] ?? 0);
                                        $arrRut = msp2RutFormatDisplay((string) ($arrendatario['rut'] ?? ''));
                                        $arrNombre = (string) ($arrendatario['nombre_locatario'] ?? '');
                                        $arrLabel = $arrRut . ' - ' . $arrNombre;
                                        $arrSearch = mb_strtolower($arrLabel, 'UTF-8');
                                        ?>
                                        <button
                                            type="button"
                                            class="list-group-item list-group-item-action py-2 px-2 js-option-arrendatario-crear"
                                            data-id="<?php echo $arrId; ?>"
                                            data-label="<?php echo msp2Escape($arrLabel); ?>"
                                            data-search="<?php echo msp2Escape($arrSearch); ?>">
                                            <?php echo msp2Escape($arrLabel); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label for="crear_nombre_comercial" class="form-label">Nombre comercial</label>
                        <input type="text" id="crear_nombre_comercial" name="nombre_comercial" class="form-control" maxlength="200" required>
                    </div>
                    <div class="col-12 col-lg-4">
                        <label for="crear_rubro_dropdown_btn" class="form-label">Rubro</label>
                        <input type="hidden" id="crear_id_rubro" name="id_rubro" value="">
                        <div class="dropdown w-100" id="crear_rubro_picker">
                            <button
                                class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                type="button"
                                id="crear_rubro_dropdown_btn"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                                Seleccionar rubro...
                            </button>
                            <div class="dropdown-menu p-2 w-100" aria-labelledby="crear_rubro_dropdown_btn">
                                <input type="text" id="crear_rubro_dropdown_filter" class="form-control mb-2" placeholder="Buscar rubro">
                                <div class="list-group list-group-flush overflow-auto" id="crear_rubro_dropdown_list" style="max-height: 220px;">
                                    <?php foreach ($rubros as $rubro): ?>
                                        <?php
                                        $rubroId = (int) ($rubro['id_rubro'] ?? 0);
                                        $rubroNombre = (string) ($rubro['nombre_rubro'] ?? '');
                                        ?>
                                        <button
                                            type="button"
                                            class="list-group-item list-group-item-action py-2 px-2 js-option-rubro-crear"
                                            data-id="<?php echo $rubroId; ?>"
                                            data-label="<?php echo msp2Escape($rubroNombre); ?>"
                                            data-search="<?php echo msp2Escape(mb_strtolower($rubroNombre, 'UTF-8')); ?>">
                                            <?php echo msp2Escape($rubroNombre); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="crear_id_estado_tienda" class="form-label">Estado</label>
                        <select id="crear_id_estado_tienda" name="id_estado_tienda" class="form-select" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_tienda']; ?>"><?php echo msp2Escape((string) $estado['desc_estado']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="crear_fecha_inicio" class="form-label">Fecha inicio</label>
                        <input type="date" id="crear_fecha_inicio" name="fecha_inicio" class="form-control">
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="crear_fecha_inicio_ocupacion" class="form-label">Inicio ocupación</label>
                        <input type="date" id="crear_fecha_inicio_ocupacion" name="fecha_inicio_ocupacion" class="form-control">
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label for="crear_fecha_termino_ocupacion" class="form-label">Término ocupación</label>
                        <input type="date" id="crear_fecha_termino_ocupacion" name="fecha_termino_ocupacion" class="form-control">
                    </div>
                    <div class="col-12">
                        <label for="crear_locales_buscar" class="form-label">Locales para la tienda</label>
                        <?php if ($selectorLocalesHabilitado): ?>
                            <div class="dropdown w-100 mb-2" id="crear_local_picker">
                                <button
                                    class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                    type="button"
                                    id="crear_local_dropdown_btn"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                    Seleccionar local...
                                </button>
                                <div class="dropdown-menu p-2 w-100" aria-labelledby="crear_local_dropdown_btn">
                                    <input type="text" id="crear_locales_buscar" class="form-control mb-2" placeholder="Buscar local por código o descripción">
                                    <div class="list-group list-group-flush overflow-auto" id="crear_locales_list" style="max-height: 220px;">
                                        <?php foreach ($localesSelector as $local): ?>
                                            <?php
                                            $localCode = (string) ($local['code'] ?? '');
                                            $localLabel = (string) ($local['label'] ?? $localCode);
                                            $localDisplay = $localLabel . ($local['disponible'] ? '' : ' (ocupado)');
                                            $localSearch = mb_strtolower($localDisplay . ' ' . $localCode, 'UTF-8');
                                            ?>
                                            <button
                                                type="button"
                                                class="list-group-item list-group-item-action py-2 px-2 js-local-picker-option"
                                                data-code="<?php echo msp2Escape($localCode); ?>"
                                                data-disponible="<?php echo $local['disponible'] ? '1' : '0'; ?>"
                                                data-ocupante="<?php echo $local['id_tienda_ocupante'] !== null ? (int) $local['id_tienda_ocupante'] : ''; ?>"
                                                data-search="<?php echo msp2Escape($localSearch); ?>"
                                                data-label="<?php echo msp2Escape($localDisplay); ?>">
                                                <?php echo msp2Escape($localDisplay); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div id="crear_locales_container" class="vstack gap-1"></div>
                            <input type="hidden" id="crear_cod_locales" name="cod_locales" value="">
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">No se pudo cargar el selector de locales. Revisa las tablas `msp_locales` y `msp_ocupacion_locales`.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar tienda</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade msp-store-modal" id="modalEditarTienda" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable msp-store-dialog">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('tiendas/guardar.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Editar tienda</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_tienda" id="edit_id_tienda">
                <div class="row g-2">
                    <div class="col-12 col-md-6">
                        <label for="edit_arrendatario_dropdown_btn" class="form-label">Arrendatario</label>
                        <input type="hidden" id="edit_id_arrendatario" name="id_arrendatario" value="">
                        <div class="dropdown w-100" id="edit_arrendatario_picker">
                            <button
                                class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                type="button"
                                id="edit_arrendatario_dropdown_btn"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                                Seleccionar arrendatario...
                            </button>
                            <div class="dropdown-menu p-2 w-100" aria-labelledby="edit_arrendatario_dropdown_btn">
                                <input type="text" id="edit_arrendatario_dropdown_filter" class="form-control mb-2" placeholder="Buscar por RUT o nombre">
                                <div class="list-group list-group-flush overflow-auto" id="edit_arrendatario_dropdown_list" style="max-height: 220px;">
                                    <?php foreach ($arrendatarios as $arrendatario): ?>
                                        <?php
                                        $arrId = (int) ($arrendatario['id_arrendatario'] ?? 0);
                                        $arrRut = msp2RutFormatDisplay((string) ($arrendatario['rut'] ?? ''));
                                        $arrNombre = (string) ($arrendatario['nombre_locatario'] ?? '');
                                        $arrLabel = $arrRut . ' - ' . $arrNombre;
                                        $arrSearch = mb_strtolower($arrLabel, 'UTF-8');
                                        ?>
                                        <button
                                            type="button"
                                            class="list-group-item list-group-item-action py-2 px-2 js-option-arrendatario-edit"
                                            data-id="<?php echo $arrId; ?>"
                                            data-label="<?php echo msp2Escape($arrLabel); ?>"
                                            data-search="<?php echo msp2Escape($arrSearch); ?>">
                                            <?php echo msp2Escape($arrLabel); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_nombre_comercial" class="form-label">Nombre comercial</label>
                        <input type="text" id="edit_nombre_comercial" name="nombre_comercial" class="form-control" maxlength="200" required>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_rubro_dropdown_btn" class="form-label">Rubro</label>
                        <input type="hidden" id="edit_id_rubro" name="id_rubro" value="">
                        <div class="dropdown w-100" id="edit_rubro_picker">
                            <button
                                class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                type="button"
                                id="edit_rubro_dropdown_btn"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                                Seleccionar rubro...
                            </button>
                            <div class="dropdown-menu p-2 w-100" aria-labelledby="edit_rubro_dropdown_btn">
                                <input type="text" id="edit_rubro_dropdown_filter" class="form-control mb-2" placeholder="Buscar rubro">
                                <div class="list-group list-group-flush overflow-auto" id="edit_rubro_dropdown_list" style="max-height: 220px;">
                                    <?php foreach ($rubros as $rubro): ?>
                                        <?php
                                        $rubroId = (int) ($rubro['id_rubro'] ?? 0);
                                        $rubroNombre = (string) ($rubro['nombre_rubro'] ?? '');
                                        ?>
                                        <button
                                            type="button"
                                            class="list-group-item list-group-item-action py-2 px-2 js-option-rubro-edit"
                                            data-id="<?php echo $rubroId; ?>"
                                            data-label="<?php echo msp2Escape($rubroNombre); ?>"
                                            data-search="<?php echo msp2Escape(mb_strtolower($rubroNombre, 'UTF-8')); ?>">
                                            <?php echo msp2Escape($rubroNombre); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_id_estado_tienda" class="form-label">Estado</label>
                        <select id="edit_id_estado_tienda" name="id_estado_tienda" class="form-select" required>
                            <option value="">Seleccionar estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?php echo (int) $estado['id_estado_tienda']; ?>"><?php echo msp2Escape((string) $estado['desc_estado']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_fecha_inicio" class="form-label">Fecha inicio</label>
                        <input type="date" id="edit_fecha_inicio" name="fecha_inicio" class="form-control">
                    </div>
                    <div class="col-12">
                        <label for="edit_locales_buscar" class="form-label">Locales para la tienda</label>
                        <?php if ($selectorLocalesHabilitado): ?>
                            <div class="dropdown w-100 mb-2" id="edit_local_picker">
                                <button
                                    class="btn picker-select-btn w-100 text-start dropdown-toggle"
                                    type="button"
                                    id="edit_local_dropdown_btn"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false">
                                    Seleccionar local...
                                </button>
                                <div class="dropdown-menu p-2 w-100" aria-labelledby="edit_local_dropdown_btn">
                                    <input type="text" id="edit_locales_buscar" class="form-control mb-2" placeholder="Buscar local por código o descripción">
                                    <div class="list-group list-group-flush overflow-auto" id="edit_locales_list" style="max-height: 220px;">
                                        <?php foreach ($localesSelector as $local): ?>
                                            <?php
                                            $localCode = (string) ($local['code'] ?? '');
                                            $localLabel = (string) ($local['label'] ?? $localCode);
                                            $localDisplay = $localLabel . ($local['disponible'] ? '' : ' (ocupado)');
                                            $localSearch = mb_strtolower($localDisplay . ' ' . $localCode, 'UTF-8');
                                            ?>
                                            <button
                                                type="button"
                                                class="list-group-item list-group-item-action py-2 px-2 js-local-picker-option"
                                                data-code="<?php echo msp2Escape($localCode); ?>"
                                                data-disponible="<?php echo $local['disponible'] ? '1' : '0'; ?>"
                                                data-ocupante="<?php echo $local['id_tienda_ocupante'] !== null ? (int) $local['id_tienda_ocupante'] : ''; ?>"
                                                data-search="<?php echo msp2Escape($localSearch); ?>"
                                                data-label="<?php echo msp2Escape($localDisplay); ?>">
                                                <?php echo msp2Escape($localDisplay); ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div id="edit_locales_container" class="vstack gap-2"></div>
                            <input type="hidden" id="edit_cod_locales" name="cod_locales" value="">
                            <div class="form-text">Informativo: la ocupacion de locales ahora se administra en Contratos.</div>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">No se pudo cargar el selector de locales. Revisa las tablas `msp_locales` y `msp_ocupacion_locales`.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_fecha_inicio_ocupacion" class="form-label">Fecha inicio ocupación</label>
                        <input type="date" id="edit_fecha_inicio_ocupacion" name="fecha_inicio_ocupacion" class="form-control">
                    </div>
                    <div class="col-12 col-md-6">
                        <label for="edit_fecha_termino_ocupacion" class="form-label">Fecha término ocupación</label>
                        <input type="date" id="edit_fecha_termino_ocupacion" name="fecha_termino_ocupacion" class="form-control">
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info mb-0">
                            Los datos de contrato y garantía se gestionan en
                            <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="alert-link">Contratos</a>.
                        </div>
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

<?php if ($moduloCargosHabilitado): ?>
    <div class="modal fade" id="modalCrearCargo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('tiendas/guardar_cargo.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Registrar cargo por local</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_tienda" id="cargo_id_tienda">
                    <input type="hidden" name="redirect_to" value="tiendas/index.php">
                    <p class="mb-3">Tienda: <strong id="cargo_tienda_label">-</strong></p>
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="cargo_cod_local" class="form-label">Local</label>
                            <select id="cargo_cod_local" name="cod_local_cargo" class="form-select" required>
                                <option value="">Seleccionar local</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="cargo_id_tipo" class="form-label">Tipo de cargo</label>
                            <select id="cargo_id_tipo" name="id_tipo_cargo_salida" class="form-select" required>
                                <option value="">Seleccionar tipo</option>
                                <?php foreach ($tiposCargoSalida as $tipoCargo): ?>
                                    <?php
                                    $requiereDocumento = ((int) ($tipoCargo['requiere_documento'] ?? 0)) === 1;
                                    $tipoLabel = (string) ($tipoCargo['nombre_tipo_cargo'] ?? '');
                                    if ($requiereDocumento) {
                                        $tipoLabel .= ' (requiere documento)';
                                    }
                                    ?>
                                    <option
                                        value="<?php echo (int) $tipoCargo['id_tipo_cargo_salida']; ?>"
                                        data-codigo="<?php echo msp2Escape((string) ($tipoCargo['codigo_tipo_cargo'] ?? '')); ?>"
                                        data-permite-estimacion="<?php echo ((int) ($tipoCargo['permite_estimacion'] ?? 0)) === 1 ? '1' : '0'; ?>"
                                        data-requiere-documento="<?php echo $requiereDocumento ? '1' : '0'; ?>"
                                        <?php echo $requiereDocumento ? 'disabled' : ''; ?>>
                                        <?php echo msp2Escape($tipoLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="cargo_fecha" class="form-label">Fecha cargo</label>
                            <input type="date" id="cargo_fecha" name="fecha_cargo" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="cargo_periodo_mes" class="form-label">Periodo referencia</label>
                            <input type="month" id="cargo_periodo_mes" name="periodo_referencia_mes" class="form-control">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="cargo_servicio_referencia" class="form-label">Servicio referencia</label>
                            <input type="text" id="cargo_servicio_referencia" name="servicio_referencia" class="form-control" maxlength="30" placeholder="Ej: Agua">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="cargo_monto" class="form-label">Monto</label>
                            <input type="text" id="cargo_monto" name="monto_cargo" class="form-control" inputmode="decimal" placeholder="0.00" required>
                        </div>
                        <div class="col-12">
                            <label for="cargo_descripcion" class="form-label">Descripción</label>
                            <input type="text" id="cargo_descripcion" name="descripcion_cargo" class="form-control" maxlength="500" required>
                        </div>
                        <div class="col-12">
                            <label for="cargo_observaciones" class="form-label">Observaciones</label>
                            <textarea id="cargo_observaciones" name="observaciones" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3 mb-0">
                        Esta etapa registra cargos manuales por local. Los cargos que requieren documento se gestionarán en la siguiente etapa.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Registrar cargo</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalVerCargosTienda" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Cargos por local - <span id="ver_cargos_tienda_label">-</span></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle">
                            <thead class="table-light text-center">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Local</th>
                                    <th>Tipo</th>
                                    <th>Descripción</th>
                                    <th>Periodo</th>
                                    <th>Servicio</th>
                                    <th>Monto</th>
                                    <th>Estado</th>
                                    <th style="width: 120px;">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="ver_cargos_tienda_body">
                                <tr>
                                    <td colspan="9" class="text-center text-muted">Sin registros.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-secondary mb-0">
                        Solo se puede editar o anular cargos en estado <strong>Pendiente</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalEditarCargo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('tiendas/editar_cargo.php')); ?>">
                <div class="modal-header">
                    <h2 class="modal-title fs-5">Editar cargo pendiente</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_cargo_salida" id="edit_cargo_id">
                    <input type="hidden" name="redirect_to" value="tiendas/index.php">
                    <div class="row g-3">
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_cod_local" class="form-label">Local</label>
                            <input type="text" id="edit_cargo_cod_local" class="form-control" readonly>
                            <input type="hidden" id="edit_cargo_cod_local_hidden" name="cod_local_cargo">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_id_tipo" class="form-label">Tipo de cargo</label>
                            <select id="edit_cargo_id_tipo" name="id_tipo_cargo_salida" class="form-select" required>
                                <option value="">Seleccionar tipo</option>
                                <?php foreach ($tiposCargoSalida as $tipoCargo): ?>
                                    <?php
                                    $requiereDocumento = ((int) ($tipoCargo['requiere_documento'] ?? 0)) === 1;
                                    $tipoLabel = (string) ($tipoCargo['nombre_tipo_cargo'] ?? '');
                                    if ($requiereDocumento) {
                                        $tipoLabel .= ' (requiere documento)';
                                    }
                                    ?>
                                    <option
                                        value="<?php echo (int) $tipoCargo['id_tipo_cargo_salida']; ?>"
                                        data-codigo="<?php echo msp2Escape((string) ($tipoCargo['codigo_tipo_cargo'] ?? '')); ?>"
                                        data-permite-estimacion="<?php echo ((int) ($tipoCargo['permite_estimacion'] ?? 0)) === 1 ? '1' : '0'; ?>"
                                        data-requiere-documento="<?php echo $requiereDocumento ? '1' : '0'; ?>"
                                        <?php echo $requiereDocumento ? 'disabled' : ''; ?>>
                                        <?php echo msp2Escape($tipoLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_fecha" class="form-label">Fecha cargo</label>
                            <input type="date" id="edit_cargo_fecha" name="fecha_cargo" class="form-control" required>
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_periodo_mes" class="form-label">Periodo referencia</label>
                            <input type="month" id="edit_cargo_periodo_mes" name="periodo_referencia_mes" class="form-control">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_servicio_referencia" class="form-label">Servicio referencia</label>
                            <input type="text" id="edit_cargo_servicio_referencia" name="servicio_referencia" class="form-control" maxlength="30">
                        </div>
                        <div class="col-12 col-md-4">
                            <label for="edit_cargo_monto" class="form-label">Monto</label>
                            <input type="text" id="edit_cargo_monto" name="monto_cargo" class="form-control" inputmode="decimal" placeholder="0.00" required>
                        </div>
                        <div class="col-12">
                            <label for="edit_cargo_descripcion" class="form-label">Descripción</label>
                            <input type="text" id="edit_cargo_descripcion" name="descripcion_cargo" class="form-control" maxlength="500" required>
                        </div>
                        <div class="col-12">
                            <label for="edit_cargo_observaciones" class="form-label">Observaciones</label>
                            <textarea id="edit_cargo_observaciones" name="observaciones" class="form-control" rows="2" maxlength="500"></textarea>
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

<?php endif; ?>

<?php include dirname(__DIR__) . '/templates/components/undo_toast.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const cargosPorTienda = <?php echo $cargosPorTiendaJson !== false ? $cargosPorTiendaJson : '{}'; ?>;
    const anularCargoAction = '<?php echo msp2Escape(msp2Url('tiendas/anular_cargo.php')); ?>';

    const normalizeLocalCode = (value) => {
        const code = String(value || '').trim();
        if (!code) {
            return '';
        }

        const patternWithSuffix = /^([A-Za-z])-([0-9]+)([A-Za-z])$/;
        const withSuffix = code.match(patternWithSuffix);
        if (withSuffix) {
            return `${withSuffix[1].toUpperCase()}-${withSuffix[2]}${withSuffix[3].toLowerCase()}`;
        }

        const patternWithoutSuffix = /^([A-Za-z])-([0-9]+)$/;
        const withoutSuffix = code.match(patternWithoutSuffix);
        if (withoutSuffix) {
            return `${withoutSuffix[1].toUpperCase()}-${withoutSuffix[2]}`;
        }

        return code;
    };

    const localCodeKey = (value) => normalizeLocalCode(value).toUpperCase();

    const parseCodes = (raw) => {
        if (!raw) {
            return [];
        }

        const parts = String(raw).split(/[;|,\n\r]+/);
        const seen = new Set();
        const codes = [];

        for (const part of parts) {
            const code = normalizeLocalCode(part);
            const key = localCodeKey(code);
            if (!code || seen.has(key)) {
                continue;
            }
            seen.add(key);
            codes.push(code);
        }

        return codes;
    };

    const escapeAttr = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const escapeHtml = (value) => escapeAttr(value);

    const initSearchDropdownPicker = ({
        pickerId,
        buttonId,
        filterId,
        listId,
        hiddenId,
        optionSelector,
        placeholder,
    }) => {
        const pickerEl = document.getElementById(pickerId);
        const buttonEl = document.getElementById(buttonId);
        const filterEl = document.getElementById(filterId);
        const listEl = document.getElementById(listId);
        const hiddenEl = document.getElementById(hiddenId);

        if (!pickerEl || !buttonEl || !filterEl || !listEl || !hiddenEl) {
            return null;
        }

        const dropdownInstance = window.bootstrap
            ? window.bootstrap.Dropdown.getOrCreateInstance(buttonEl)
            : null;

        const options = Array.from(listEl.querySelectorAll(optionSelector));
        const byId = new Map();
        options.forEach((option) => {
            const id = String(option.dataset.id || '').trim();
            if (id !== '') {
                byId.set(id, option);
            }
        });

        const applyFilter = () => {
            const query = filterEl.value.trim().toLowerCase();
            options.forEach((option) => {
                const search = String(option.dataset.search || '').toLowerCase();
                const label = String(option.dataset.label || '').toLowerCase();
                const visible = query === '' || search.includes(query) || label.includes(query);
                option.classList.toggle('d-none', !visible);
            });
        };

        const setSelection = (id, label) => {
            const normalizedId = String(id || '').trim();
            const normalizedLabel = String(label || '').trim();
            hiddenEl.value = normalizedId;
            buttonEl.textContent = normalizedId !== '' && normalizedLabel !== '' ? normalizedLabel : placeholder;
        };

        options.forEach((option) => {
            option.addEventListener('click', () => {
                setSelection(option.dataset.id || '', option.dataset.label || '');
                if (dropdownInstance) {
                    dropdownInstance.hide();
                }
                filterEl.value = '';
                applyFilter();
            });
        });

        filterEl.addEventListener('input', applyFilter);
        pickerEl.addEventListener('shown.bs.dropdown', () => {
            filterEl.focus();
        });

        return {
            setValueById: (id) => {
                const normalizedId = String(id || '').trim();
                const option = normalizedId !== '' ? byId.get(normalizedId) : null;
                if (option) {
                    setSelection(option.dataset.id || '', option.dataset.label || '');
                } else {
                    setSelection('', '');
                }
            },
            clear: () => {
                setSelection('', '');
                filterEl.value = '';
                applyFilter();
            },
        };
    };

    const initLocalPicker = ({ pickerId, dropdownButtonId, listId, searchId, containerId, hiddenId, onSelectionChange }) => {
        const pickerEl = document.getElementById(pickerId);
        const dropdownButtonEl = document.getElementById(dropdownButtonId);
        const listEl = document.getElementById(listId);
        const searchEl = document.getElementById(searchId);
        const containerEl = document.getElementById(containerId);
        const hiddenEl = document.getElementById(hiddenId);
        const dropdownInstance = dropdownButtonEl && window.bootstrap
            ? window.bootstrap.Dropdown.getOrCreateInstance(dropdownButtonEl)
            : null;

        if (!listEl || !containerEl || !hiddenEl) {
            return null;
        }

        const state = {
            selected: [],
            tiendaId: 0,
        };

        const normalizeCode = (value) => normalizeLocalCode(value);

        const syncHidden = () => {
            hiddenEl.value = state.selected.join(';');
        };

        const applySearchVisibility = () => {
            const query = searchEl ? searchEl.value.trim().toLowerCase() : '';
            let visibleAllowed = 0;
            const options = Array.from(listEl.querySelectorAll('.js-local-picker-option'));
            options.forEach((option) => {
                const searchToken = String(option.dataset.search || '').toLowerCase();
                const labelToken = String(option.dataset.label || '').toLowerCase();
                const codeToken = String(option.dataset.code || '').toLowerCase();

                const allowed = option.dataset.allowed === '1';
                const matches = query === '' || searchToken.includes(query) || labelToken.includes(query) || codeToken.includes(query);
                const visible = allowed && matches;
                option.classList.toggle('d-none', !visible);
                option.disabled = !allowed;
                if (visible) {
                    visibleAllowed++;
                }
            });

            if (dropdownButtonEl) {
                dropdownButtonEl.classList.toggle('disabled', visibleAllowed === 0);
            }
        };

        const isOptionAllowed = (option, tiendaId, selectedSet) => {
            if (!option) {
                return false;
            }

            const disponible = option.dataset.disponible === '1';
            const ocupante = Number.parseInt(String(option.dataset.ocupante || ''), 10);
            const ocupadoPorMismaTienda = Number.isFinite(ocupante) && ocupante > 0 && ocupante === tiendaId;
            const yaSeleccionado = selectedSet.has(localCodeKey(option.dataset.code || ''));

            return disponible || ocupadoPorMismaTienda || yaSeleccionado;
        };

        const applyAvailability = () => {
            const tiendaId = Number.parseInt(String(state.tiendaId || ''), 10) || 0;
            const selectedSet = new Set(state.selected.map((code) => localCodeKey(code)));

            Array.from(listEl.querySelectorAll('.js-local-picker-option')).forEach((option) => {
                const allowed = isOptionAllowed(option, tiendaId, selectedSet);
                option.dataset.allowed = allowed ? '1' : '0';
            });

            applySearchVisibility();
        };

        const renderSelected = () => {
            containerEl.innerHTML = '';

            if (state.selected.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'text-muted small';
                empty.textContent = 'Sin locales seleccionados.';
                containerEl.appendChild(empty);
                syncHidden();
                return;
            }

            state.selected.forEach((code) => {
                const row = document.createElement('div');
                row.className = 'input-group';

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'form-control';
                input.value = code;
                input.readOnly = true;

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'btn btn-outline-danger';
                removeBtn.textContent = 'Quitar';
                removeBtn.dataset.code = code;

                row.appendChild(input);
                row.appendChild(removeBtn);
                containerEl.appendChild(row);
            });

            syncHidden();
        };

        const refresh = () => {
            applyAvailability();
            renderSelected();
            if (typeof onSelectionChange === 'function') {
                onSelectionChange([...state.selected]);
            }
        };

        const setContext = (tiendaId, initialCodes = []) => {
            state.tiendaId = Number.parseInt(String(tiendaId || ''), 10) || 0;
            state.selected = [];

            parseCodes(initialCodes.join(';')).forEach((code) => {
                if (!state.selected.includes(code)) {
                    state.selected.push(code);
                }
            });

            refresh();
        };

        if (searchEl) {
            searchEl.addEventListener('input', applySearchVisibility);
        }

        if (pickerEl && searchEl) {
            pickerEl.addEventListener('shown.bs.dropdown', () => {
                searchEl.focus();
            });
        }

        listEl.addEventListener('click', (event) => {
            const option = event.target.closest('.js-local-picker-option');
            if (!option || option.disabled) {
                return;
            }

            const code = normalizeCode(option.dataset.code || '');
            if (!code) {
                return;
            }

            const codeKey = localCodeKey(code);
            if (!state.selected.some((item) => localCodeKey(item) === codeKey)) {
                state.selected.push(code);
                refresh();
            }

            if (searchEl) {
                searchEl.value = '';
                applySearchVisibility();
            }

            if (dropdownInstance) {
                dropdownInstance.hide();
            }
        });

        containerEl.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-code]');
            if (!button) {
                return;
            }

            const code = normalizeCode(button.dataset.code);
            if (!window.confirm(`¿Quitar el local ${code} de esta selección? El cambio solo se aplicará al guardar la tienda.`)) {
                return;
            }
            const codeKey = localCodeKey(code);
            state.selected = state.selected.filter((item) => localCodeKey(item) !== codeKey);
            refresh();
        });

        refresh();

        return {
            setContext,
            clearSearch: () => {
                if (searchEl) {
                    searchEl.value = '';
                }
                applySearchVisibility();
            },
            getSelected: () => [...state.selected],
        };
    };

    const crearArrendatarioPicker = initSearchDropdownPicker({
        pickerId: 'crear_arrendatario_picker',
        buttonId: 'crear_arrendatario_dropdown_btn',
        filterId: 'crear_arrendatario_dropdown_filter',
        listId: 'crear_arrendatario_dropdown_list',
        hiddenId: 'crear_id_arrendatario',
        optionSelector: '.js-option-arrendatario-crear',
        placeholder: 'Seleccionar arrendatario...',
    });

    const crearRubroPicker = initSearchDropdownPicker({
        pickerId: 'crear_rubro_picker',
        buttonId: 'crear_rubro_dropdown_btn',
        filterId: 'crear_rubro_dropdown_filter',
        listId: 'crear_rubro_dropdown_list',
        hiddenId: 'crear_id_rubro',
        optionSelector: '.js-option-rubro-crear',
        placeholder: 'Seleccionar rubro...',
    });

    const editArrendatarioPicker = initSearchDropdownPicker({
        pickerId: 'edit_arrendatario_picker',
        buttonId: 'edit_arrendatario_dropdown_btn',
        filterId: 'edit_arrendatario_dropdown_filter',
        listId: 'edit_arrendatario_dropdown_list',
        hiddenId: 'edit_id_arrendatario',
        optionSelector: '.js-option-arrendatario-edit',
        placeholder: 'Seleccionar arrendatario...',
    });

    const editRubroPicker = initSearchDropdownPicker({
        pickerId: 'edit_rubro_picker',
        buttonId: 'edit_rubro_dropdown_btn',
        filterId: 'edit_rubro_dropdown_filter',
        listId: 'edit_rubro_dropdown_list',
        hiddenId: 'edit_id_rubro',
        optionSelector: '.js-option-rubro-edit',
        placeholder: 'Seleccionar rubro...',
    });

    const pickerCrear = initLocalPicker({
        pickerId: 'crear_local_picker',
        dropdownButtonId: 'crear_local_dropdown_btn',
        listId: 'crear_locales_list',
        searchId: 'crear_locales_buscar',
        containerId: 'crear_locales_container',
        hiddenId: 'crear_cod_locales',
    });

    const pickerEditar = initLocalPicker({
        pickerId: 'edit_local_picker',
        dropdownButtonId: 'edit_local_dropdown_btn',
        listId: 'edit_locales_list',
        searchId: 'edit_locales_buscar',
        containerId: 'edit_locales_container',
        hiddenId: 'edit_cod_locales',
    });

    const modalCrear = document.getElementById('modalCrearTienda');
    if (modalCrear && pickerCrear) {
        modalCrear.addEventListener('show.bs.modal', () => {
            pickerCrear.setContext(0, []);
            pickerCrear.clearSearch();
            if (crearArrendatarioPicker) {
                crearArrendatarioPicker.clear();
            }
            if (crearRubroPicker) {
                crearRubroPicker.clear();
            }
            document.getElementById('crear_id_estado_tienda').value = '';
            document.getElementById('crear_nombre_comercial').value = '';
            document.getElementById('crear_fecha_inicio').value = '';
            document.getElementById('crear_fecha_inicio_ocupacion').value = '';
            document.getElementById('crear_fecha_termino_ocupacion').value = '';
        });
    }

    const modalCrearCargo = document.getElementById('modalCrearCargo');
    if (modalCrearCargo) {
        const inputCargoIdTienda = document.getElementById('cargo_id_tienda');
        const labelCargoTienda = document.getElementById('cargo_tienda_label');
        const selectCargoLocal = document.getElementById('cargo_cod_local');
        const selectCargoTipo = document.getElementById('cargo_id_tipo');
        const inputCargoFecha = document.getElementById('cargo_fecha');
        const inputCargoPeriodo = document.getElementById('cargo_periodo_mes');
        const inputCargoServicio = document.getElementById('cargo_servicio_referencia');
        const inputCargoMonto = document.getElementById('cargo_monto');
        const inputCargoDescripcion = document.getElementById('cargo_descripcion');
        const inputCargoObservaciones = document.getElementById('cargo_observaciones');

        const applyTipoRules = () => {
            const option = selectCargoTipo ? selectCargoTipo.options[selectCargoTipo.selectedIndex] : null;
            const codigoTipo = option ? String(option.dataset.codigo || '') : '';
            const esServicioEstimado = codigoTipo === 'SERVICIO_ESTIMADO';

            if (inputCargoServicio) {
                inputCargoServicio.required = esServicioEstimado;
                if (!esServicioEstimado && inputCargoServicio.value.trim() === '') {
                    inputCargoServicio.required = false;
                }
            }

            if (inputCargoPeriodo) {
                inputCargoPeriodo.required = esServicioEstimado;
            }

            if (inputCargoDescripcion && inputCargoDescripcion.value.trim() === '') {
                inputCargoDescripcion.placeholder = esServicioEstimado
                    ? 'Ej: Estimación manual de agua marzo'
                    : 'Descripción del cargo';
            }
        };

        if (selectCargoTipo) {
            selectCargoTipo.addEventListener('change', applyTipoRules);
        }

        document.querySelectorAll('.js-cargo-tienda').forEach((button) => {
            button.addEventListener('click', () => {
                const tiendaId = String(button.dataset.id || '').trim();
                const tiendaLabel = String(button.dataset.label || '').trim();
                const locales = parseCodes(button.dataset.localesCargo || '');

                if (inputCargoIdTienda) {
                    inputCargoIdTienda.value = tiendaId;
                }
                if (labelCargoTienda) {
                    labelCargoTienda.textContent = tiendaLabel || '-';
                }

                if (selectCargoLocal) {
                    selectCargoLocal.innerHTML = '';
                    const optionDefault = document.createElement('option');
                    optionDefault.value = '';
                    optionDefault.textContent = 'Seleccionar local';
                    selectCargoLocal.appendChild(optionDefault);

                    locales.forEach((codigoLocal) => {
                        const option = document.createElement('option');
                        option.value = codigoLocal;
                        option.textContent = codigoLocal;
                        selectCargoLocal.appendChild(option);
                    });
                }

                const today = new Date();
                const yyyy = today.getFullYear();
                const mm = String(today.getMonth() + 1).padStart(2, '0');
                const dd = String(today.getDate()).padStart(2, '0');
                if (inputCargoFecha) {
                    inputCargoFecha.value = `${yyyy}-${mm}-${dd}`;
                }
                if (inputCargoPeriodo) {
                    inputCargoPeriodo.value = `${yyyy}-${mm}`;
                }
                if (selectCargoTipo) {
                    selectCargoTipo.value = '';
                }
                if (inputCargoServicio) {
                    inputCargoServicio.value = '';
                }
                if (inputCargoMonto) {
                    inputCargoMonto.value = '';
                }
                if (inputCargoDescripcion) {
                    inputCargoDescripcion.value = '';
                }
                if (inputCargoObservaciones) {
                    inputCargoObservaciones.value = '';
                }

                applyTipoRules();
            });
        });
    }

    const modalVerCargosTienda = document.getElementById('modalVerCargosTienda');
    if (modalVerCargosTienda) {
        const labelVerCargosTienda = document.getElementById('ver_cargos_tienda_label');
        const bodyVerCargos = document.getElementById('ver_cargos_tienda_body');
        const modalEditarCargo = document.getElementById('modalEditarCargo');
        const editCargoForm = modalEditarCargo ? modalEditarCargo.querySelector('form') : null;
        const editCargoId = document.getElementById('edit_cargo_id');
        const editCargoLocal = document.getElementById('edit_cargo_cod_local');
        const editCargoLocalHidden = document.getElementById('edit_cargo_cod_local_hidden');
        const editCargoTipo = document.getElementById('edit_cargo_id_tipo');
        const editCargoFecha = document.getElementById('edit_cargo_fecha');
        const editCargoPeriodo = document.getElementById('edit_cargo_periodo_mes');
        const editCargoServicio = document.getElementById('edit_cargo_servicio_referencia');
        const editCargoMonto = document.getElementById('edit_cargo_monto');
        const editCargoDescripcion = document.getElementById('edit_cargo_descripcion');
        const editCargoObservaciones = document.getElementById('edit_cargo_observaciones');
        const modalVerCargosInstance = window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(modalVerCargosTienda)
            : null;
        const modalEditarCargoInstance = modalEditarCargo && window.bootstrap
            ? window.bootstrap.Modal.getOrCreateInstance(modalEditarCargo)
            : null;

        const applyEditTipoRules = () => {
            const option = editCargoTipo ? editCargoTipo.options[editCargoTipo.selectedIndex] : null;
            const codigoTipo = option ? String(option.dataset.codigo || '') : '';
            const esServicioEstimado = codigoTipo === 'SERVICIO_ESTIMADO';

            if (editCargoServicio) {
                editCargoServicio.required = esServicioEstimado;
            }
            if (editCargoPeriodo) {
                editCargoPeriodo.required = esServicioEstimado;
            }
        };

        if (editCargoTipo) {
            editCargoTipo.addEventListener('change', applyEditTipoRules);
        }

        const renderCargosTienda = (idTienda) => {
            if (!bodyVerCargos) {
                return;
            }

            const cargos = Array.isArray(cargosPorTienda[String(idTienda)]) ? cargosPorTienda[String(idTienda)] : [];
            bodyVerCargos.innerHTML = '';

            if (cargos.length === 0) {
                bodyVerCargos.innerHTML = '<tr><td colspan="9" class="text-center text-muted">Sin cargos registrados para esta tienda.</td></tr>';
                return;
            }

            const rowsHtml = cargos.map((cargo) => {
                const estadoBadge = String(cargo.estado_badge || 'bg-light text-dark');
                const estadoLabel = escapeHtml(cargo.estado_label || 'Sin estado');
                const fechaCargo = escapeHtml(cargo.fecha_cargo_label || '-');
                const local = escapeHtml(cargo.codigo_local || '-');
                const tipo = escapeHtml(cargo.tipo_cargo_label || '-');
                const descripcion = escapeHtml(cargo.descripcion_cargo || '-');
                const periodo = escapeHtml(cargo.periodo_referencia_label || '-');
                const servicio = escapeHtml(cargo.servicio_referencia || '-');
                const monto = escapeHtml(cargo.monto_label || '$0,00');
                const idCargo = Number.parseInt(String(cargo.id_cargo_salida || ''), 10);
                const anulable = cargo.anulable === true && Number.isFinite(idCargo) && idCargo > 0;
                const editable = cargo.editable === true && Number.isFinite(idCargo) && idCargo > 0;

                const editCell = editable
                    ? `<button
                            type="button"
                            class="btn btn-outline-primary btn-sm js-btn-editar-cargo"
                            data-id-cargo="${idCargo}"
                            data-id-tipo="${escapeAttr(cargo.id_tipo_cargo_salida || '')}"
                            data-cod-local="${escapeAttr(cargo.codigo_local || '')}"
                            data-fecha-cargo="${escapeAttr(cargo.fecha_cargo_input || '')}"
                            data-periodo="${escapeAttr(cargo.periodo_referencia_input || '')}"
                            data-servicio="${escapeAttr(cargo.servicio_referencia || '')}"
                            data-monto="${escapeAttr(cargo.monto_input || '')}"
                            data-descripcion="${escapeAttr(cargo.descripcion_cargo || '')}"
                            data-observaciones="${escapeAttr(cargo.observaciones || '')}">
                            Editar
                       </button>`
                    : '';

                const actionCell = anulable
                    ? `<form method="post" action="${anularCargoAction}" class="js-form-anular-cargo d-inline-block"
                            data-confirm-message="¿Anular este cargo pendiente?"
                            data-confirm-title="Confirmar anulación"
                            data-confirm-variant="danger">
                            <input type="hidden" name="id_cargo_salida" value="${idCargo}">
                            <input type="hidden" name="redirect_to" value="tiendas/index.php">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Anular</button>
                       </form>`
                    : '<span class="text-muted small">-</span>';

                return `<tr>
                    <td class="text-center">${fechaCargo}</td>
                    <td class="text-center">${local}</td>
                    <td>${tipo}</td>
                    <td>${descripcion}</td>
                    <td class="text-center">${periodo}</td>
                    <td class="text-center">${servicio}</td>
                    <td class="text-end">${monto}</td>
                    <td class="text-center"><span class="badge ${estadoBadge}">${estadoLabel}</span></td>
                    <td class="text-center">${editCell}${editCell !== '' ? ' ' : ''}${actionCell}</td>
                </tr>`;
            });

            bodyVerCargos.innerHTML = rowsHtml.join('');
        };

        document.querySelectorAll('.js-ver-cargos-tienda').forEach((button) => {
            button.addEventListener('click', () => {
                const tiendaId = String(button.dataset.id || '').trim();
                const tiendaLabel = String(button.dataset.label || '').trim();
                if (labelVerCargosTienda) {
                    labelVerCargosTienda.textContent = tiendaLabel || '-';
                }

                renderCargosTienda(tiendaId);
            });
        });

        if (bodyVerCargos) {
            bodyVerCargos.addEventListener('click', (event) => {
                const button = event.target.closest('.js-btn-editar-cargo');
                if (!button || !modalEditarCargo) {
                    return;
                }

                if (editCargoId) {
                    editCargoId.value = String(button.dataset.idCargo || '');
                }
                if (editCargoLocal) {
                    editCargoLocal.value = String(button.dataset.codLocal || '');
                }
                if (editCargoLocalHidden) {
                    editCargoLocalHidden.value = String(button.dataset.codLocal || '');
                }
                if (editCargoTipo) {
                    editCargoTipo.value = String(button.dataset.idTipo || '');
                }
                if (editCargoFecha) {
                    editCargoFecha.value = String(button.dataset.fechaCargo || '');
                }
                if (editCargoPeriodo) {
                    editCargoPeriodo.value = String(button.dataset.periodo || '');
                }
                if (editCargoServicio) {
                    editCargoServicio.value = String(button.dataset.servicio || '');
                }
                if (editCargoMonto) {
                    editCargoMonto.value = String(button.dataset.monto || '');
                }
                if (editCargoDescripcion) {
                    editCargoDescripcion.value = String(button.dataset.descripcion || '');
                }
                if (editCargoObservaciones) {
                    editCargoObservaciones.value = String(button.dataset.observaciones || '');
                }

                applyEditTipoRules();

                if (modalVerCargosInstance) {
                    modalVerCargosInstance.hide();
                }
                if (modalEditarCargoInstance) {
                    modalEditarCargoInstance.show();
                }
            });
        }

        if (editCargoForm) {
            editCargoForm.dataset.confirmMessage = '¿Guardar cambios del cargo pendiente?';
            editCargoForm.dataset.confirmTitle = 'Confirmar actualización';
            editCargoForm.dataset.confirmVariant = 'warning';
        }

    }

    document.querySelectorAll('.js-edit-tienda').forEach((button) => {
        button.addEventListener('click', () => {
            document.getElementById('edit_id_tienda').value = button.dataset.id || '';
            if (editArrendatarioPicker) {
                editArrendatarioPicker.setValueById(button.dataset.arrendatario || '');
            } else {
                document.getElementById('edit_id_arrendatario').value = button.dataset.arrendatario || '';
            }
            if (editRubroPicker) {
                editRubroPicker.setValueById(button.dataset.rubro || '');
            } else {
                document.getElementById('edit_id_rubro').value = button.dataset.rubro || '';
            }
            document.getElementById('edit_id_estado_tienda').value = button.dataset.estado || '';
            document.getElementById('edit_nombre_comercial').value = button.dataset.nombre || '';
            document.getElementById('edit_fecha_inicio').value = button.dataset.fecha || '';
            document.getElementById('edit_fecha_inicio_ocupacion').value = '';
            document.getElementById('edit_fecha_termino_ocupacion').value = '';

            if (pickerEditar) {
                const locales = parseCodes(button.dataset.locales || '');
                pickerEditar.setContext(button.dataset.id || '', locales);
                pickerEditar.clearSearch();
            }
        });
    });

})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
