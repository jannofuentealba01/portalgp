<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();
$descuentosHabilitados = msp2DescuentosArriendoEnabled();

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$loadError = null;
$rows = [];
$contratoInfo = null;
$descuentosCatalogo = [];
$historialDescuentos = [];

function msp2ArriendoReglasFmtInput(mixed $value, int $decimals): string
{
    if ($value === null || $value === '') {
        return '';
    }

    if (!is_numeric((string) $value)) {
        return '';
    }

    return number_format((float) $value, $decimals, '.', '');
}

function msp2ArriendoReglasFmtDescuentoLabel(array $row): string
{
    $nombre = trim((string) ($row['nombre_descuento'] ?? ''));
    $tipo = strtoupper(trim((string) ($row['tipo_monto'] ?? '')));
    $valor = (float) ($row['valor_descuento'] ?? 0);
    $desde = substr((string) ($row['periodo_desde'] ?? ''), 0, 7);
    $hastaRaw = substr((string) ($row['periodo_hasta'] ?? ''), 0, 7);
    $hasta = $hastaRaw !== '' ? $hastaRaw : 'abierto';

    $montoLabel = $tipo === 'UF_FIJO'
        ? ('UF ' . number_format($valor, 2, ',', '.'))
        : ('$ ' . number_format($valor, 0, ',', '.'));

    $base = $nombre !== '' ? $nombre : ('Descuento #' . (int) ($row['id_descuento_arriendo'] ?? 0));
    return $base . ' | ' . $montoLabel . ' | ' . $desde . ' a ' . $hasta;
}

function msp2ArriendoReglasFmtDateTime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '-';
    }

    try {
        $dt = new DateTimeImmutable($raw);
        return $dt->format('d-m-Y H:i');
    } catch (Throwable) {
        return $raw;
    }
}

$idContratoArriendo = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

try {
    if ($idContratoArriendo === false || $idContratoArriendo === null) {
        throw new RuntimeException('Debes indicar un contrato para configurar cobro por local.');
    }

    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_locales',
        'msp_contrato_local_arriendo_regla',
        'msp_tipo_modalidad_arriendo',
        'msp_descuento_arriendo',
        'msp_descuento_arriendo_contrato_local',
    ];
    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }
    if ($missingTables !== []) {
        throw new RuntimeException('Faltan tablas para configuración de arriendo por local: `' . implode('`, `', $missingTables) . '`.');
    }

    $contratoStmt = $conn->prepare(
        "SELECT TOP (1)
            ca.id_contrato_arriendo,
            ca.id_tienda,
            ca.id_arrendatario,
            ca.fecha_inicio,
            ca.fecha_termino_pactada,
            ca.estado_contrato,
            t.nombre_comercial,
            a.nombre_locatario
         FROM dbo.msp_contratos_arriendo ca
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = ca.id_tienda
         LEFT JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = ca.id_arrendatario
         WHERE ca.id_contrato_arriendo = :id_contrato_arriendo"
    );
    $contratoStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $contratoStmt->execute();
    $contratoInfo = $contratoStmt->fetch();
    if ($contratoInfo === false) {
        throw new RuntimeException('No existe el contrato seleccionado.');
    }

    $stmtDescuentos = $conn->query(
        "SELECT
            d.id_descuento_arriendo,
            d.nombre_descuento,
            d.tipo_monto,
            d.valor_descuento,
            CONVERT(CHAR(10), d.periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), d.periodo_hasta, 126) AS periodo_hasta
         FROM dbo.msp_descuento_arriendo d
         WHERE d.estado_descuento = 1
         ORDER BY d.nombre_descuento ASC, d.id_descuento_arriendo ASC"
    );
    while (($rowDesc = $stmtDescuentos->fetch()) !== false) {
        $idDescuento = (int) ($rowDesc['id_descuento_arriendo'] ?? 0);
        if ($idDescuento <= 0) {
            continue;
        }
        $descuentosCatalogo[] = [
            'id_descuento_arriendo' => $idDescuento,
            'nombre_descuento' => (string) ($rowDesc['nombre_descuento'] ?? ''),
            'tipo_monto' => (string) ($rowDesc['tipo_monto'] ?? ''),
            'valor_descuento' => (string) ($rowDesc['valor_descuento'] ?? ''),
            'periodo_desde' => (string) ($rowDesc['periodo_desde'] ?? ''),
            'periodo_hasta' => (string) ($rowDesc['periodo_hasta'] ?? ''),
            'label' => msp2ArriendoReglasFmtDescuentoLabel((array) $rowDesc),
        ];
    }

    $activeDescuentoIdsByContratoLocal = [];
    $historialByContratoLocal = [];

    $asignacionesStmt = $conn->prepare(
        "SELECT
            dcl.id_descuento_arriendo_contrato_local,
            dcl.id_contrato_local,
            dcl.estado_asignacion,
            CONVERT(CHAR(19), dcl.fecha_asignacion, 126) AS fecha_asignacion,
            CONVERT(CHAR(19), dcl.fecha_desasignacion, 126) AS fecha_desasignacion,
            d.id_descuento_arriendo,
            d.nombre_descuento,
            d.tipo_monto,
            d.valor_descuento,
            CONVERT(CHAR(10), d.periodo_desde, 126) AS periodo_desde,
            CONVERT(CHAR(10), d.periodo_hasta, 126) AS periodo_hasta,
            d.estado_descuento
         FROM dbo.msp_descuento_arriendo_contrato_local dcl
         INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = dcl.id_contrato_local
         INNER JOIN dbo.msp_descuento_arriendo d
            ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
         WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
         ORDER BY
            dcl.id_contrato_local ASC,
            dcl.id_descuento_arriendo_contrato_local DESC"
    );
    $asignacionesStmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $asignacionesStmt->execute();
    while (($rowAsig = $asignacionesStmt->fetch()) !== false) {
        $idContratoLocalAsig = (int) ($rowAsig['id_contrato_local'] ?? 0);
        $idDescuentoAsig = (int) ($rowAsig['id_descuento_arriendo'] ?? 0);
        if ($idContratoLocalAsig <= 0 || $idDescuentoAsig <= 0) {
            continue;
        }

        $descuentoInfo = [
            'id_descuento_arriendo' => $idDescuentoAsig,
            'nombre_descuento' => (string) ($rowAsig['nombre_descuento'] ?? ''),
            'tipo_monto' => (string) ($rowAsig['tipo_monto'] ?? ''),
            'valor_descuento' => (string) ($rowAsig['valor_descuento'] ?? ''),
            'periodo_desde' => (string) ($rowAsig['periodo_desde'] ?? ''),
            'periodo_hasta' => (string) ($rowAsig['periodo_hasta'] ?? ''),
        ];
        $descuentoLabel = msp2ArriendoReglasFmtDescuentoLabel($descuentoInfo);

        if (!isset($historialByContratoLocal[$idContratoLocalAsig])) {
            $historialByContratoLocal[$idContratoLocalAsig] = [];
        }
        $historialByContratoLocal[$idContratoLocalAsig][] = [
            'id_descuento_arriendo_contrato_local' => (int) ($rowAsig['id_descuento_arriendo_contrato_local'] ?? 0),
            'id_descuento_arriendo' => $idDescuentoAsig,
            'label' => $descuentoLabel,
            'estado_asignacion' => (int) ($rowAsig['estado_asignacion'] ?? 1),
            'estado_descuento' => (int) ($rowAsig['estado_descuento'] ?? 1),
            'fecha_asignacion' => (string) ($rowAsig['fecha_asignacion'] ?? ''),
            'fecha_desasignacion' => (string) ($rowAsig['fecha_desasignacion'] ?? ''),
            'periodo_desde' => (string) ($rowAsig['periodo_desde'] ?? ''),
            'periodo_hasta' => (string) ($rowAsig['periodo_hasta'] ?? ''),
        ];

        $isActive = (int) ($rowAsig['estado_asignacion'] ?? 1) === 1 && (int) ($rowAsig['estado_descuento'] ?? 1) === 1;
        if ($isActive) {
            if (!isset($activeDescuentoIdsByContratoLocal[$idContratoLocalAsig])) {
                $activeDescuentoIdsByContratoLocal[$idContratoLocalAsig] = [];
            }
            $activeDescuentoIdsByContratoLocal[$idContratoLocalAsig][$idDescuentoAsig] = $idDescuentoAsig;
        }
    }

    $stmt = $conn->prepare(
        "SELECT
            cl.id_contrato_local,
            cl.id_local,
            cl.fecha_inicio AS fecha_inicio_contrato_local,
            cl.fecha_termino AS fecha_termino_contrato_local,
            l.cdo_local,
            l.desc_local,
            l.valor_arriendo_uf AS valor_local_uf_legacy,
            regla.id_regla_arriendo,
            ISNULL(tm.codigo_modalidad, N'UF_ESTATICO') AS codigo_modalidad,
            regla.valor_base_uf,
            regla.valor_base_clp,
            regla.codigo_grupo_modalidad
         FROM dbo.msp_contrato_locales cl
         INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
         OUTER APPLY (
            SELECT TOP (1)
                rr.id_regla_arriendo,
                rr.id_modalidad_arriendo,
                rr.valor_base_uf,
                rr.valor_base_clp,
                rr.codigo_grupo_modalidad,
                rr.prioridad,
                rr.es_default
            FROM dbo.msp_contrato_local_arriendo_regla rr
            WHERE rr.id_contrato_local = cl.id_contrato_local
              AND rr.estado_regla = 1
              AND rr.es_default = 1
            ORDER BY
                rr.prioridad DESC,
                rr.id_regla_arriendo DESC
         ) regla
         LEFT JOIN dbo.msp_tipo_modalidad_arriendo tm
            ON tm.id_modalidad_arriendo = regla.id_modalidad_arriendo
         WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
           AND cl.estado_relacion IN (1,2)
         ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
    );
    $stmt->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $stmt->execute();

    while (($row = $stmt->fetch()) !== false) {
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        if ($idContratoLocal <= 0) {
            continue;
        }

        $codigoLocal = strtoupper(trim((string) ($row['cdo_local'] ?? '')));
        $modalidad = (string) ($row['codigo_modalidad'] ?? 'UF_ESTATICO');
        $valorUf = msp2ArriendoReglasFmtInput($row['valor_base_uf'] ?? null, 6);
        $valorClp = msp2ArriendoReglasFmtInput($row['valor_base_clp'] ?? null, 2);

        if ((int) ($row['id_regla_arriendo'] ?? 0) <= 0) {
            $modalidad = 'UF_ESTATICO';
            if ($valorUf === '') {
                $valorUf = msp2ArriendoReglasFmtInput($row['valor_local_uf_legacy'] ?? null, 6);
            }
            $valorClp = '';
        }

        $activeDescuentoIds = array_values(array_map(
            'intval',
            array_keys($activeDescuentoIdsByContratoLocal[$idContratoLocal] ?? [])
        ));
        sort($activeDescuentoIds, SORT_NUMERIC);

        $rows[] = [
            'id_contrato_local' => $idContratoLocal,
            'id_local' => (int) ($row['id_local'] ?? 0),
            'cdo_local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
            'desc_local' => (string) ($row['desc_local'] ?? ''),
            'id_regla_arriendo' => (int) ($row['id_regla_arriendo'] ?? 0),
            'modalidad' => $modalidad,
            'valor_base_uf' => $valorUf,
            'valor_base_clp' => $valorClp,
            'grupo_modalidad' => (string) ($row['codigo_grupo_modalidad'] ?? ''),
            'descuentos_activos_ids' => $activeDescuentoIds,
            'historial_descuentos' => $historialByContratoLocal[$idContratoLocal] ?? [],
        ];
    }

    foreach ($rows as $rowHist) {
        $idContratoLocalHist = (int) ($rowHist['id_contrato_local'] ?? 0);
        $localLabel = trim((string) ($rowHist['cdo_local'] ?? ''));
        $localDesc = trim((string) ($rowHist['desc_local'] ?? ''));
        foreach ((array) ($rowHist['historial_descuentos'] ?? []) as $histRow) {
            $historialDescuentos[] = [
                'id_contrato_local' => $idContratoLocalHist,
                'local_label' => $localLabel,
                'local_desc' => $localDesc,
                'label' => (string) ($histRow['label'] ?? ''),
                'estado_asignacion' => (int) ($histRow['estado_asignacion'] ?? 1),
                'estado_descuento' => (int) ($histRow['estado_descuento'] ?? 1),
                'fecha_asignacion' => (string) ($histRow['fecha_asignacion'] ?? ''),
                'fecha_desasignacion' => (string) ($histRow['fecha_desasignacion'] ?? ''),
                'periodo_desde' => (string) ($histRow['periodo_desde'] ?? ''),
                'periodo_hasta' => (string) ($histRow['periodo_hasta'] ?? ''),
            ];
        }
    }
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        $loadError = $exception->getMessage();
    } else {
        $loadError = 'No fue posible cargar la configuración de cobro por local.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Cobro por local</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .msp2-kpi {
            border: 1px solid #e6eaef;
            border-radius: .6rem;
            background: #f8fafc;
            padding: .75rem .9rem;
            height: 100%;
        }
        .msp2-kpi-label {
            display: block;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #6c757d;
            margin-bottom: .25rem;
        }
        .msp2-kpi-value {
            font-size: 1.15rem;
            font-weight: 600;
            line-height: 1.2;
            color: #0f172a;
        }
        .msp2-descuento-option {
            display: flex;
            gap: .55rem;
            align-items: flex-start;
            border: 1px solid #e1e6ed;
            border-radius: .5rem;
            padding: .55rem .65rem;
            background: #fbfcfe;
        }
        .msp2-descuento-option:hover {
            border-color: #b9d0f8;
            background: #f3f8ff;
        }
        .msp2-descuento-option .form-check-input {
            margin-top: .15rem;
        }
        .msp2-history-controls {
            border-top: 1px solid #eef1f5;
            border-bottom: 1px solid #eef1f5;
            background: #fafbfd;
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <a href="<?php echo msp2Escape(msp2Url('contratos/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a contratos
            </a>
            <?php if ($descuentosHabilitados && is_array($contratoInfo)): ?>
                <a href="<?php echo msp2Escape(msp2Url('contratos/descuentos_arriendo.php?id_contrato_arriendo=' . (int) ($contratoInfo['id_contrato_arriendo'] ?? 0))); ?>" class="btn btn-outline-dark btn-sm">
                    <i class="bi bi-tags me-1" aria-hidden="true"></i>Catálogo descuentos
                </a>
            <?php endif; ?>
        </div>

        <h1 class="h3 mb-1">Cobro por local</h1>
        <p class="text-muted mb-4">Configuración de modalidad/valor base y aplicación de descuentos por local en una sola vista.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-danger"><?php echo msp2Escape($loadError); ?></div>
        <?php elseif (!is_array($contratoInfo)): ?>
            <div class="alert alert-warning">No se encontró contrato.</div>
        <?php else: ?>
            <?php
            $totalLocales = count($rows);
            ?>
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-body py-3">
                    <div class="row g-3 align-items-stretch">
                        <div class="col-12 col-lg-6">
                            <div class="row g-2">
                                <div class="col-12 col-md-6"><strong>Contrato:</strong> #<?php echo (int) ($contratoInfo['id_contrato_arriendo'] ?? 0); ?></div>
                                <div class="col-12 col-md-6"><strong>Estado:</strong> <?php echo (int) ($contratoInfo['estado_contrato'] ?? 0); ?></div>
                                <div class="col-12"><strong>Tienda:</strong> <?php echo msp2Escape((string) ($contratoInfo['nombre_comercial'] ?? '-')); ?></div>
                                <div class="col-12"><strong>Arrendatario:</strong> <?php echo msp2Escape((string) ($contratoInfo['nombre_locatario'] ?? '-')); ?></div>
                            </div>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="msp2-kpi">
                                <span class="msp2-kpi-label">Locales</span>
                                <div class="msp2-kpi-value"><?php echo $totalLocales; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($rows === []): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center text-muted py-4">
                        El contrato no tiene locales activos para configurar.
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    <strong>Reglas:</strong> <code>UF_ESTATICO</code> exige valor UF. <code>CLP_FIJO</code> exige valor CLP.
                    En esta pantalla defines el valor base por local.
                </div>

                <form method="post" action="<?php echo msp2Escape(msp2Url('contratos/guardar_arriendo_reglas.php')); ?>">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="id_contrato_arriendo" value="<?php echo (int) ($contratoInfo['id_contrato_arriendo'] ?? 0); ?>">

                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white">
                            <strong>1) Valores base por local</strong>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th>Local</th>
                                    <th>Modalidad</th>
                                    <th class="text-end">Valor base UF</th>
                                    <th class="text-end">Valor base CLP</th>
                                    <th class="text-center">Grupo</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0); ?>
                                    <tr class="js-arriendo-row" data-id-contrato-local="<?php echo $idContratoLocal; ?>">
                                        <td>
                                            <div><strong><?php echo msp2Escape((string) ($row['cdo_local'] ?? '')); ?></strong></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($row['desc_local'] ?? '')); ?> | Contrato-local #<?php echo $idContratoLocal; ?></div>
                                        </td>
                                        <td style="min-width: 210px;">
                                            <input type="hidden" name="rows[<?php echo $idContratoLocal; ?>][id_contrato_local]" value="<?php echo $idContratoLocal; ?>">
                                            <select
                                                class="form-select form-select-sm js-modalidad-arriendo"
                                                name="rows[<?php echo $idContratoLocal; ?>][modalidad]"
                                                data-local-code="<?php echo msp2Escape(strtoupper((string) ($row['cdo_local'] ?? ''))); ?>">
                                                <option value="UF_ESTATICO" <?php echo (($row['modalidad'] ?? '') !== 'CLP_FIJO') ? 'selected' : ''; ?>>UF mensual fija</option>
                                                <option value="CLP_FIJO" <?php echo (($row['modalidad'] ?? '') === 'CLP_FIJO') ? 'selected' : ''; ?>>Pesos mensuales fijos</option>
                                            </select>
                                        </td>
                                        <td style="min-width: 150px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end js-valor-uf"
                                                name="rows[<?php echo $idContratoLocal; ?>][valor_base_uf]"
                                                value="<?php echo msp2Escape((string) ($row['valor_base_uf'] ?? '')); ?>"
                                                placeholder="0.000000"
                                                inputmode="decimal">
                                        </td>
                                        <td style="min-width: 165px;">
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end js-valor-clp"
                                                name="rows[<?php echo $idContratoLocal; ?>][valor_base_clp]"
                                                value="<?php echo msp2Escape((string) ($row['valor_base_clp'] ?? '')); ?>"
                                                placeholder="0,00"
                                                inputmode="decimal">
                                        </td>
                                        <td class="text-center small text-muted js-grupo-hint">
                                            <?php echo msp2Escape((string) ((string) ($row['grupo_modalidad'] ?? '') !== '' ? $row['grupo_modalidad'] : '-')); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if ($descuentosHabilitados): ?>
                    <div class="card shadow-sm border-0 mb-4">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <strong>2) Aplicación de descuentos</strong>
                            <span class="small text-muted">Selecciona uno o varios descuentos vigentes por local.</span>
                        </div>
                        <div class="card-body">
                            <?php if ($descuentosCatalogo === []): ?>
                                <div class="alert alert-warning mb-0">
                                    No hay descuentos activos en catálogo. Primero crea descuentos y luego vuelve a esta vista.
                                </div>
                            <?php else: ?>
                                <div class="accordion" id="descuentosPorLocalAccordion">
                                    <?php foreach ($rows as $rowIndex => $row): ?>
                                        <?php
                                        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
                                        $descuentosActivosIds = array_values(array_map('intval', (array) ($row['descuentos_activos_ids'] ?? [])));
                                        $accordionItemId = 'descuento-local-' . $idContratoLocal;
                                        $isFirstRow = $rowIndex === 0;
                                        ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="heading-<?php echo $accordionItemId; ?>">
                                                <button class="accordion-button <?php echo $isFirstRow ? '' : 'collapsed'; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $accordionItemId; ?>" aria-expanded="<?php echo $isFirstRow ? 'true' : 'false'; ?>" aria-controls="collapse-<?php echo $accordionItemId; ?>">
                                                    <div class="w-100 d-flex justify-content-between align-items-center gap-2 pe-2">
                                                        <span>
                                                            <strong><?php echo msp2Escape((string) ($row['cdo_local'] ?? '')); ?></strong>
                                                            <span class="text-muted small ms-1"><?php echo msp2Escape((string) ($row['desc_local'] ?? '')); ?></span>
                                                        </span>
                                                        <span class="badge text-bg-light border js-descuentos-count" data-local-id="<?php echo $idContratoLocal; ?>">
                                                            <?php echo count($descuentosActivosIds); ?> activo(s)
                                                        </span>
                                                    </div>
                                                </button>
                                            </h2>
                                            <div id="collapse-<?php echo $accordionItemId; ?>" class="accordion-collapse collapse <?php echo $isFirstRow ? 'show' : ''; ?>" aria-labelledby="heading-<?php echo $accordionItemId; ?>" data-bs-parent="#descuentosPorLocalAccordion">
                                                <div class="accordion-body">
                                                    <div class="row g-2">
                                                        <?php foreach ($descuentosCatalogo as $descuentoCat): ?>
                                                            <?php $idDescCat = (int) ($descuentoCat['id_descuento_arriendo'] ?? 0); ?>
                                                            <div class="col-12 col-lg-6">
                                                                <label class="msp2-descuento-option">
                                                                    <input
                                                                        type="checkbox"
                                                                        class="form-check-input js-descuento-check"
                                                                        data-local-id="<?php echo $idContratoLocal; ?>"
                                                                        name="rows[<?php echo $idContratoLocal; ?>][ids_descuento_arriendo][]"
                                                                        value="<?php echo $idDescCat; ?>"
                                                                        <?php echo in_array($idDescCat, $descuentosActivosIds, true) ? 'checked' : ''; ?>>
                                                                    <span class="small"><?php echo msp2Escape((string) ($descuentoCat['label'] ?? 'Descuento')); ?></span>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-white d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar cobro por local
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$descuentosHabilitados): ?>
                        <div class="d-flex justify-content-end mb-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1" aria-hidden="true"></i>Guardar cobro por local
                            </button>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($descuentosHabilitados): ?>
                <div class="card shadow-sm border-0">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <strong>Historial de descuentos aplicados</strong>
                        <span class="small text-muted js-history-count">
                            <?php echo count($historialDescuentos); ?> registro(s)
                        </span>
                    </div>
                    <div class="p-3 msp2-history-controls">
                        <div class="row g-2">
                            <div class="col-12 col-md-7">
                                <label for="historySearchInput" class="form-label form-label-sm mb-1">Buscar local</label>
                                <input id="historySearchInput" type="search" class="form-control form-control-sm js-history-search" placeholder="Ej: A-1, B-4, nombre del local">
                            </div>
                            <div class="col-12 col-md-5">
                                <label for="historyStateFilter" class="form-label form-label-sm mb-1">Estado</label>
                                <select id="historyStateFilter" class="form-select form-select-sm js-history-state">
                                    <option value="all">Todos</option>
                                    <option value="active">Activa</option>
                                    <option value="inactive">Desasignada</option>
                                    <option value="catalog_inactive">Activa (catálogo inactivo)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light">
                            <tr>
                                <th>Local</th>
                                <th>Descuento</th>
                                <th>Vigencia descuento</th>
                                <th>Estado asignación</th>
                                <th>Fecha asignación</th>
                                <th>Fecha desasignación</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php if ($historialDescuentos === []): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No hay historial de descuentos para este contrato.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($historialDescuentos as $hist): ?>
                                    <?php
                                    $estadoAsignacion = (int) ($hist['estado_asignacion'] ?? 1);
                                    $estadoDescuento = (int) ($hist['estado_descuento'] ?? 1);
                                    $badgeClass = $estadoAsignacion === 1 ? 'text-bg-success' : 'text-bg-secondary';
                                    $badgeLabel = $estadoAsignacion === 1 ? 'Activa' : 'Desasignada';
                                    $stateKey = $estadoAsignacion === 1 ? 'active' : 'inactive';
                                    if ($estadoAsignacion === 1 && $estadoDescuento !== 1) {
                                        $badgeClass = 'text-bg-warning';
                                        $badgeLabel = 'Activa (catálogo inactivo)';
                                        $stateKey = 'catalog_inactive';
                                    }
                                    $periodoDesde = substr((string) ($hist['periodo_desde'] ?? ''), 0, 7);
                                    $periodoHastaRaw = substr((string) ($hist['periodo_hasta'] ?? ''), 0, 7);
                                    $periodoHasta = $periodoHastaRaw !== '' ? $periodoHastaRaw : 'abierto';
                                    $localSearch = strtolower(trim((string) ($hist['local_label'] ?? '') . ' ' . (string) ($hist['local_desc'] ?? '')));
                                    ?>
                                    <tr class="js-history-row" data-local-search="<?php echo msp2Escape($localSearch); ?>" data-history-state="<?php echo msp2Escape($stateKey); ?>">
                                        <td>
                                            <div><strong><?php echo msp2Escape((string) ($hist['local_label'] ?? '')); ?></strong></div>
                                            <div class="small text-muted"><?php echo msp2Escape((string) ($hist['local_desc'] ?? '')); ?></div>
                                        </td>
                                        <td><?php echo msp2Escape((string) ($hist['label'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape($periodoDesde . ' a ' . $periodoHasta); ?></td>
                                        <td><span class="badge <?php echo msp2Escape($badgeClass); ?>"><?php echo msp2Escape($badgeLabel); ?></span></td>
                                        <td><?php echo msp2Escape(msp2ArriendoReglasFmtDateTime((string) ($hist['fecha_asignacion'] ?? ''))); ?></td>
                                        <td><?php echo msp2Escape(msp2ArriendoReglasFmtDateTime((string) ($hist['fecha_desasignacion'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($historialDescuentos !== []): ?>
                        <div class="text-center text-muted py-3 border-top d-none js-history-empty-state">
                            No hay resultados con los filtros aplicados.
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const rows = Array.from(document.querySelectorAll('.js-arriendo-row'));
    const refreshRow = (row) => {
        const modalidadEl = row.querySelector('.js-modalidad-arriendo');
        const valorUfEl = row.querySelector('.js-valor-uf');
        const valorClpEl = row.querySelector('.js-valor-clp');
        const grupoHintEl = row.querySelector('.js-grupo-hint');

        if (!(modalidadEl instanceof HTMLSelectElement) || !(valorUfEl instanceof HTMLInputElement) || !(valorClpEl instanceof HTMLInputElement)) {
            return;
        }

        const modalidad = String(modalidadEl.value || '');
        valorUfEl.disabled = modalidad !== 'UF_ESTATICO';
        valorClpEl.disabled = modalidad !== 'CLP_FIJO';

        if (grupoHintEl instanceof HTMLElement) {
            if (modalidad === 'CLP_FIJO') {
                grupoHintEl.textContent = 'CLP_FIJO_CONTRATO';
            } else {
                grupoHintEl.textContent = '-';
            }
        }
    };

    rows.forEach((row) => {
        const modalidadEl = row.querySelector('.js-modalidad-arriendo');
        if (modalidadEl instanceof HTMLSelectElement) {
            modalidadEl.addEventListener('change', () => refreshRow(row));
        }
        refreshRow(row);
    });

    const descuentoChecks = Array.from(document.querySelectorAll('.js-descuento-check'));
    const refreshDescuentoCount = (localId) => {
        const checksByLocal = descuentoChecks.filter((el) => String(el.dataset.localId || '') === String(localId));
        const activos = checksByLocal.filter((el) => el.checked).length;
        document.querySelectorAll('.js-descuentos-count').forEach((badge) => {
            if (!(badge instanceof HTMLElement)) {
                return;
            }
            if (String(badge.dataset.localId || '') !== String(localId)) {
                return;
            }
            badge.textContent = `${activos} activo(s)`;
        });
    };

    descuentoChecks.forEach((check) => {
        const localId = String(check.dataset.localId || '');
        check.addEventListener('change', () => refreshDescuentoCount(localId));
        refreshDescuentoCount(localId);
    });

    const historyRows = Array.from(document.querySelectorAll('.js-history-row'));
    const historySearchInput = document.querySelector('.js-history-search');
    const historyStateSelect = document.querySelector('.js-history-state');
    const historyCount = document.querySelector('.js-history-count');
    const historyEmptyState = document.querySelector('.js-history-empty-state');

    const applyHistoryFilters = () => {
        if (!(historySearchInput instanceof HTMLInputElement) || !(historyStateSelect instanceof HTMLSelectElement)) {
            return;
        }

        const query = String(historySearchInput.value || '').trim().toLowerCase();
        const state = String(historyStateSelect.value || 'all');
        let visible = 0;

        historyRows.forEach((row) => {
            if (!(row instanceof HTMLElement)) {
                return;
            }
            const localSearch = String(row.dataset.localSearch || '').toLowerCase();
            const rowState = String(row.dataset.historyState || '');
            const matchesQuery = query === '' || localSearch.includes(query);
            const matchesState = state === 'all' || rowState === state;
            const shouldShow = matchesQuery && matchesState;

            row.classList.toggle('d-none', !shouldShow);
            if (shouldShow) {
                visible++;
            }
        });

        if (historyCount instanceof HTMLElement) {
            historyCount.textContent = `${visible} registro(s)`;
        }
        if (historyEmptyState instanceof HTMLElement) {
            historyEmptyState.classList.toggle('d-none', visible > 0);
        }
    };

    if (historyRows.length > 0) {
        if (historySearchInput instanceof HTMLInputElement) {
            historySearchInput.addEventListener('input', applyHistoryFilters);
        }
        if (historyStateSelect instanceof HTMLSelectElement) {
            historyStateSelect.addEventListener('change', applyHistoryFilters);
        }
        applyHistoryFilters();
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
