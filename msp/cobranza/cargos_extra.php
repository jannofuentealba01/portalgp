<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/templates/components/monto_clp_input.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$loadError = null;
$tablaExiste = false;
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$periodoYm = trim((string) ($_GET['periodo'] ?? $_POST['periodo'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $periodoYm)) {
    $periodoYm = (new DateTimeImmutable('today'))->format('Y-m');
}

function ceParseMonthToFirstDay(string $periodo): ?string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodo);
    if (!$d || $d->format('Y-m') !== $periodo) {
        return null;
    }

    return $d->format('Y-m-01');
}

function ceManualAdjustmentWindow(string $periodoFacturacion): ?array
{
    $periodoDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($periodoDate === false || $periodoDate->format('Y-m-d') !== $periodoFacturacion) {
        return null;
    }

    $prevMonth = $periodoDate->modify('first day of previous month');
    if ($prevMonth === false) {
        return null;
    }

    $minDate = $prevMonth->format('Y-m-01');
    $maxDate = $prevMonth->modify('last day of this month')->format('Y-m-d');

    return [
        'min' => $minDate,
        'max' => $maxDate,
        'default' => $maxDate,
    ];
}

function ceFmtMonto(mixed $value): string
{
    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function ceFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $d = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m-Y') : (string) $value;
}

function ceRedirect(string $periodo): never
{
    msp2Redirect('cobranza/cargos_extra.php?periodo=' . urlencode($periodo));
}

try {
    $requiredTables = [
        'msp_cargos_salida',
        'msp_tipos_cargo_salida',
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_locales',
        'msp_tiendas',
    ];

    $missing = [];
    foreach ($requiredTables as $table) {
        if (!msp2TableExists($conn, $table)) {
            $missing[] = $table;
        }
    }

    if ($missing !== []) {
        $loadError = 'Faltan tablas requeridas: `' . implode('`, `', $missing) . '`.';
    } else {
        $tablaExiste = true;
    }
} catch (PDOException) {
    $loadError = 'No fue posible validar la estructura para cargos extra.';
}

if ($tablaExiste && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $accion = trim((string) ($_POST['accion'] ?? ''));

    if ($accion === 'crear_cargo_extra') {
        $periodoFacturacion = ceParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? ceManualAdjustmentWindow($periodoFacturacion) : null;
        $targetRaw = trim((string) ($_POST['target_contrato_local'] ?? ''));
        $idTipoCargo = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaCargo = trim((string) ($_POST['fecha_cargo'] ?? ''));
        $descripcionCargo = mb_substr(msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? '')), 0, 500, 'UTF-8');
        $observacionesCargo = mb_substr(msp2NormalizeText((string) ($_POST['observaciones_cargo'] ?? '')), 0, 500, 'UTF-8');
        [$okMonto, $montoCargo] = msp2NormalizeDecimalInput((string) ($_POST['monto_cargo'] ?? ''), 2);

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido para registrar cargo extra.');
            ceRedirect($periodoYm);
        }

        if (!preg_match('/^(\d+):(\d+)$/', $targetRaw, $parts)) {
            msp2SetFlash('warning', 'Debes seleccionar un local/contrato válido para el cargo.');
            ceRedirect($periodoYm);
        }

        $idContrato = (int) ($parts[1] ?? 0);
        $idLocal = (int) ($parts[2] ?? 0);

        if ($idTipoCargo === false || $idTipoCargo === null) {
            msp2SetFlash('warning', 'Debes seleccionar el tipo de cargo.');
            ceRedirect($periodoYm);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCargo) !== 1) {
            $fechaCargo = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaCargo < $manualAdjustMin || $fechaCargo > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . ceFmtFecha($manualAdjustMin) . ' y ' . ceFmtFecha($manualAdjustMax) . '.');
            ceRedirect($periodoYm);
        }

        if (!$okMonto || $montoCargo === null || (float) $montoCargo <= 0) {
            msp2SetFlash('warning', 'El monto del cargo debe ser mayor a 0.');
            ceRedirect($periodoYm);
        }

        try {
            $validTargetStmt = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 SELECT TOP 1 1
                 FROM dbo.msp_contratos_arriendo ca
                 INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                 WHERE ca.id_contrato_arriendo = :id_contrato
                   AND cl.id_local = :id_local
                   AND cl.estado_relacion = 1
                   AND cl.fecha_inicio <= EOMONTH(@periodo)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                   AND ca.fecha_inicio <= EOMONTH(@periodo)
                   AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                   AND ca.estado_contrato IN (1,2,3)"
            );
            $validTargetStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $validTargetStmt->bindValue(':id_contrato', $idContrato, PDO::PARAM_INT);
            $validTargetStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $validTargetStmt->execute();

            if ($validTargetStmt->fetchColumn() === false) {
                throw new RuntimeException('El local/contrato seleccionado no está vigente para el período.');
            }

            $validTipoStmt = $conn->prepare(
                "SELECT TOP 1 1
                 FROM dbo.msp_tipos_cargo_salida
                 WHERE id_tipo_cargo_salida = :id_tipo
                   AND activo = 1"
            );
            $validTipoStmt->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $validTipoStmt->execute();
            if ($validTipoStmt->fetchColumn() === false) {
                throw new RuntimeException('El tipo de cargo seleccionado no está activo.');
            }

            $ins = $conn->prepare(
                "INSERT INTO dbo.msp_cargos_salida (
                    id_contrato_arriendo,
                    id_local,
                    id_tipo_cargo_salida,
                    fecha_cargo,
                    origen_cargo,
                    periodo_referencia,
                    descripcion_cargo,
                    monto_cargo,
                    es_estimado,
                    estado_cargo,
                    observaciones
                 ) VALUES (
                    :id_contrato,
                    :id_local,
                    :id_tipo,
                    :fecha_cargo,
                    4,
                    :periodo,
                    :descripcion,
                    :monto,
                    0,
                    1,
                    :observaciones
                 )"
            );
            $ins->bindValue(':id_contrato', $idContrato, PDO::PARAM_INT);
            $ins->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
            $ins->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $ins->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
            $ins->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $ins->bindValue(':descripcion', $descripcionCargo, PDO::PARAM_STR);
            $ins->bindValue(':monto', (string) $montoCargo, PDO::PARAM_STR);
            $ins->bindValue(':observaciones', $observacionesCargo !== '' ? $observacionesCargo : null, $observacionesCargo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $ins->execute();

            msp2SetFlash('success', 'Cargo extra registrado correctamente y quedará pendiente para el documento.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible registrar el cargo extra.');
        }

        ceRedirect($periodoYm);
    }

    if ($accion === 'actualizar_cargo_extra') {
        $periodoFacturacion = ceParseMonthToFirstDay($periodoYm);
        $manualAdjustWindow = $periodoFacturacion !== null ? ceManualAdjustmentWindow($periodoFacturacion) : null;
        $idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $idTipoCargo = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $fechaCargo = trim((string) ($_POST['fecha_cargo'] ?? ''));
        $descripcionCargo = mb_substr(msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? '')), 0, 500, 'UTF-8');
        $observacionesCargo = mb_substr(msp2NormalizeText((string) ($_POST['observaciones_cargo'] ?? '')), 0, 500, 'UTF-8');
        [$okMonto, $montoCargo] = msp2NormalizeDecimalInput((string) ($_POST['monto_cargo'] ?? ''), 2);

        if ($periodoFacturacion === null || !is_array($manualAdjustWindow)) {
            msp2SetFlash('warning', 'Periodo invalido.');
            ceRedirect($periodoYm);
        }

        if ($idCargoSalida === false || $idCargoSalida === null) {
            msp2SetFlash('warning', 'Cargo invalido.');
            ceRedirect($periodoYm);
        }

        if ($idTipoCargo === false || $idTipoCargo === null) {
            msp2SetFlash('warning', 'Debes seleccionar el tipo de cargo.');
            ceRedirect($periodoYm);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCargo) !== 1) {
            $fechaCargo = (string) ($manualAdjustWindow['default'] ?? '');
        }

        $manualAdjustMin = (string) ($manualAdjustWindow['min'] ?? '');
        $manualAdjustMax = (string) ($manualAdjustWindow['max'] ?? '');
        if ($manualAdjustMin === '' || $manualAdjustMax === '' || $fechaCargo < $manualAdjustMin || $fechaCargo > $manualAdjustMax) {
            msp2SetFlash('warning', 'La fecha del ajuste manual debe estar entre ' . ceFmtFecha($manualAdjustMin) . ' y ' . ceFmtFecha($manualAdjustMax) . '.');
            ceRedirect($periodoYm);
        }

        if (!$okMonto || $montoCargo === null || (float) $montoCargo <= 0) {
            msp2SetFlash('warning', 'El monto del cargo debe ser mayor a 0.');
            ceRedirect($periodoYm);
        }

        try {
            $upd = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 UPDATE dbo.msp_cargos_salida
                 SET id_tipo_cargo_salida = :id_tipo,
                     fecha_cargo = :fecha_cargo,
                     descripcion_cargo = :descripcion,
                     monto_cargo = :monto,
                     observaciones = :observaciones
                 WHERE id_cargo_salida = :id_cargo
                   AND id_documento_cobro IS NULL
                   AND estado_cargo IN (1,2)
                   AND ISNULL(periodo_referencia, @periodo) = @periodo"
            );
            $upd->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $upd->bindValue(':id_tipo', (int) $idTipoCargo, PDO::PARAM_INT);
            $upd->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
            $upd->bindValue(':descripcion', $descripcionCargo, PDO::PARAM_STR);
            $upd->bindValue(':monto', (string) $montoCargo, PDO::PARAM_STR);
            $upd->bindValue(':observaciones', $observacionesCargo !== '' ? $observacionesCargo : null, $observacionesCargo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $upd->bindValue(':id_cargo', (int) $idCargoSalida, PDO::PARAM_INT);
            $upd->execute();

            if ($upd->rowCount() <= 0) {
                throw new RuntimeException('El cargo ya no está pendiente o no pertenece al período seleccionado.');
            }

            msp2SetFlash('success', 'Cargo extra actualizado correctamente.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible actualizar el cargo extra.');
        }

        ceRedirect($periodoYm);
    }

    if ($accion === 'cancelar_cargo_extra') {
        $periodoFacturacion = ceParseMonthToFirstDay($periodoYm);
        $idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $motivoCancelacion = mb_substr(msp2NormalizeText((string) ($_POST['confirm_reason'] ?? '')), 0, 500, 'UTF-8');

        if ($periodoFacturacion === null) {
            msp2SetFlash('warning', 'Periodo invalido.');
            ceRedirect($periodoYm);
        }

        if ($idCargoSalida === false || $idCargoSalida === null) {
            msp2SetFlash('warning', 'Cargo invalido.');
            ceRedirect($periodoYm);
        }

        try {
            $upd = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 DECLARE @motivo NVARCHAR(500) = :motivo;
                 UPDATE dbo.msp_cargos_salida
                 SET estado_cargo = 5,
                     observaciones = CASE
                         WHEN @motivo = '' THEN observaciones
                         WHEN observaciones IS NULL OR LTRIM(RTRIM(observaciones)) = '' THEN @motivo
                         ELSE CONCAT(observaciones, N' | Cancelado: ', @motivo)
                     END
                 WHERE id_cargo_salida = :id_cargo
                   AND id_documento_cobro IS NULL
                   AND estado_cargo IN (1,2)
                   AND ISNULL(periodo_referencia, @periodo) = @periodo"
            );
            $upd->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $upd->bindValue(':motivo', $motivoCancelacion, PDO::PARAM_STR);
            $upd->bindValue(':id_cargo', (int) $idCargoSalida, PDO::PARAM_INT);
            $upd->execute();

            if ($upd->rowCount() <= 0) {
                throw new RuntimeException('El cargo ya no está pendiente o no pertenece al período seleccionado.');
            }

            msp2SetFlash('success', 'Cargo extra cancelado correctamente.');
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible cancelar el cargo extra.');
        }

        ceRedirect($periodoYm);
    }
}

$tiposCargo = [];
$targets = [];
$pendientes = [];
$totalPendiente = 0.0;
$aplicados = [];
$totalAplicado = 0.0;
$hasArrendatarios = false;

if ($tablaExiste) {
    $periodoFacturacion = ceParseMonthToFirstDay($periodoYm);
    if ($periodoFacturacion !== null) {
        $hasArrendatarios = msp2TableExists($conn, 'msp_arrendatarios');
        $tiposStmt = $conn->query(
            "SELECT id_tipo_cargo_salida, nombre_tipo_cargo
             FROM dbo.msp_tipos_cargo_salida
             WHERE activo = 1
             ORDER BY nombre_tipo_cargo ASC"
        );
        $tiposCargo = $tiposStmt->fetchAll() ?: [];

        $targetsStmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             ;WITH targets_raw AS (
                SELECT
                    cl.id_contrato_arriendo,
                    cl.id_local,
                    loc.cdo_local,
                    t.nombre_comercial,
                    " . ($hasArrendatarios
                        ? "COALESCE(
                                NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                                NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                                CONCAT(N'Arrendatario #', a.id_arrendatario)
                            )"
                        : "NULL") . " AS nombre_arrendatario,
                    ROW_NUMBER() OVER (
                        PARTITION BY cl.id_contrato_arriendo, cl.id_local
                        ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
                    ) AS rn
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = ca.id_tienda
                " . ($hasArrendatarios
                    ? "LEFT JOIN dbo.msp_arrendatarios a
                       ON a.id_arrendatario = t.id_arrendatario"
                    : "") . "
                INNER JOIN dbo.msp_locales loc
                    ON loc.id_local = cl.id_local
                WHERE cl.estado_relacion = 1
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
             )
             SELECT
                id_contrato_arriendo,
                id_local,
                cdo_local,
                nombre_comercial,
                nombre_arrendatario
             FROM targets_raw
             WHERE rn = 1
             ORDER BY " . msp2LocalCodeNaturalOrderSql('cdo_local') . ", nombre_comercial ASC"
        );
        $targetsStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $targetsStmt->execute();
        $targets = $targetsStmt->fetchAll() ?: [];

        $pendStmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             SELECT
                cs.id_cargo_salida,
                cs.id_tipo_cargo_salida,
                cs.id_contrato_arriendo,
                cs.id_local,
                cs.fecha_cargo,
                tc.nombre_tipo_cargo,
                loc.cdo_local,
                t.nombre_comercial,
                " . ($hasArrendatarios
                    ? "COALESCE(
                            NULLIF(LTRIM(RTRIM(a.nombre_locatario)), ''),
                            NULLIF(LTRIM(RTRIM(a.nombre_representante)), ''),
                            CONCAT(N'Arrendatario #', a.id_arrendatario)
                        )"
                    : "NULL") . " AS nombre_arrendatario,
                cs.descripcion_cargo,
                cs.observaciones,
                cs.monto_cargo
             FROM dbo.msp_cargos_salida cs
             INNER JOIN dbo.msp_tipos_cargo_salida tc ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
             INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
             LEFT JOIN dbo.msp_locales loc ON loc.id_local = cs.id_local
             LEFT JOIN dbo.msp_tiendas t ON t.id_tienda = ca.id_tienda
             " . ($hasArrendatarios
                ? "LEFT JOIN dbo.msp_arrendatarios a ON a.id_arrendatario = t.id_arrendatario"
                : "") . "
             WHERE cs.id_documento_cobro IS NULL
               AND cs.estado_cargo IN (1,2)
               AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
               AND cs.monto_cargo > 0
             ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
        );
        $pendStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $pendStmt->execute();
        $pendientes = $pendStmt->fetchAll() ?: [];
        foreach ($pendientes as $p) {
            $totalPendiente += (float) ($p['monto_cargo'] ?? 0);
        }

        $aplicadosStmt = $conn->prepare(
            "DECLARE @periodo DATE = :periodo;
             SELECT
                cs.id_cargo_salida,
                cs.fecha_cargo,
                cs.descripcion_cargo,
                cs.monto_cargo,
                tc.nombre_tipo_cargo,
                loc.cdo_local,
                t.nombre_comercial,
                dc.id_documento_cobro,
                dc.numero_documento
             FROM dbo.msp_cargos_salida cs
             INNER JOIN dbo.msp_tipos_cargo_salida tc
                ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = cs.id_documento_cobro
               AND dc.periodo_facturacion = @periodo
             LEFT JOIN dbo.msp_locales loc
                ON loc.id_local = cs.id_local
             LEFT JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             ORDER BY cs.fecha_cargo ASC, cs.id_cargo_salida ASC"
        );
        $aplicadosStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $aplicadosStmt->execute();
        $aplicados = $aplicadosStmt->fetchAll() ?: [];
        foreach ($aplicados as $a) {
            $totalAplicado += (float) ($a['monto_cargo'] ?? 0);
        }
    }
}
$pendientesCount = count($pendientes);
$aplicadosCount = count($aplicados);

$defaultFecha = (new DateTimeImmutable('today'))->format('Y-m-d');
$manualAdjustDateMin = $defaultFecha;
$manualAdjustDateMax = $defaultFecha;
$manualAdjustDateDefault = $defaultFecha;
$periodoManualWindow = ceParseMonthToFirstDay($periodoYm);
if ($periodoManualWindow !== null) {
    $manualWindow = ceManualAdjustmentWindow($periodoManualWindow);
    if (is_array($manualWindow)) {
        $manualAdjustDateMin = (string) ($manualWindow['min'] ?? $defaultFecha);
        $manualAdjustDateMax = (string) ($manualWindow['max'] ?? $defaultFecha);
        $manualAdjustDateDefault = (string) ($manualWindow['default'] ?? $defaultFecha);
    }
}
$manualAdjustDateRangeUi = ceFmtFecha($manualAdjustDateMin) . ' al ' . ceFmtFecha($manualAdjustDateMax);
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Cobranza | Cargos Extra</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .ce-subtle {
            font-size: 12px;
            color: var(--color-text-muted);
        }

        .ce-picker-btn {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding-right: 2rem;
        }

        .ce-date-range-hint {
            font-size: 0.72rem;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
    <?php msp2RenderMontoClpAssets(); ?>
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-management-index msp-extra-charges-index">
        <header class="msp-management-page-header msp-extra-charges-page-header">
            <div class="msp-extra-charges-back">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
            </div>
            <h1>Cargos Extra</h1>
            <div class="msp-extra-charges-actions">
                <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Documentos de cobro
                </a>
            </div>
        </header>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <section class="msp-extra-charges-section msp-extra-charges-section--register" aria-labelledby="titulo_registro_cargo_extra">
                <div class="msp-extra-charges-section-header">
                    <div>
                        <h2 id="titulo_registro_cargo_extra">Registrar cargo extra</h2>
                        <p>Selecciona el período y registra el cargo que corresponda al local.</p>
                    </div>
                </div>
                    <form method="get" class="row g-2 align-items-end msp-management-filters msp-extra-charges-period" id="form_periodo_cargos_extra">
                        <div class="col-12 col-md-3">
                            <label class="form-label">Periodo</label>
                            <input type="month" class="form-control" id="periodo_cargos_extra" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>" required>
                            <div class="ce-subtle mt-1">Define el período operativo de cobranza.</div>
                        </div>
                    </form>

                    <form method="post" class="msp-extra-charge-entry mb-3" id="form_cargo_extra_rapido">
                                <input type="hidden" name="accion" value="crear_cargo_extra">
                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                <div class="row g-2">
                                    <?php
                                    $targetOptions = [];
                                    foreach ($targets as $target) {
                                        $idContrato = (int) ($target['id_contrato_arriendo'] ?? 0);
                                        $idLocal = (int) ($target['id_local'] ?? 0);
                                        if ($idContrato <= 0 || $idLocal <= 0) {
                                            continue;
                                        }
                                        $targetValue = $idContrato . ':' . $idLocal;
                                        $arrendatarioLabel = trim((string) ($target['nombre_arrendatario'] ?? ''));
                                        if ($arrendatarioLabel === '') {
                                            $arrendatarioLabel = (string) ($target['nombre_comercial'] ?? ('Arrendatario #' . $idContrato));
                                        }
                                        $targetLabel = '(' . (string) ($target['cdo_local'] ?? '-') . ') ' . $arrendatarioLabel;
                                        $targetOptions[] = [
                                            'value' => $targetValue,
                                            'label' => $targetLabel,
                                            'search' => mb_strtolower(
                                                $targetLabel
                                                . ' '
                                                . (string) ($target['nombre_comercial'] ?? '')
                                                . ' contrato #' . $idContrato,
                                                'UTF-8'
                                            ),
                                        ];
                                    }
                                    msp2RenderSearchableSelectField([
                                        'wrapper_class' => 'col-12 col-lg-4',
                                        'label' => 'Local / Arrendatario',
                                        'input_name' => 'target_contrato_local',
                                        'input_id' => 'target_contrato_local',
                                        'picker_id' => 'target_picker',
                                        'button_id' => 'target_dropdown_btn',
                                        'filter_id' => 'target_dropdown_filter',
                                        'list_id' => 'target_dropdown_list',
                                        'error_id' => 'target_picker_error',
                                        'error_message' => 'Debes seleccionar un local/arrendatario.',
                                        'button_placeholder' => 'Selecciona local / arrendatario...',
                                        'filter_placeholder' => 'Buscar por local, arrendatario, tienda o contrato',
                                        'empty_message' => 'No hay locales/contratos vigentes para este período.',
                                        'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start ce-picker-btn',
                                        'required' => true,
                                        'options' => $targetOptions,
                                    ]);
                                    ?>
                                    <?php
                                    $tipoOptions = [];
                                    foreach ($tiposCargo as $tipo) {
                                        $idTipo = (int) ($tipo['id_tipo_cargo_salida'] ?? 0);
                                        if ($idTipo <= 0) {
                                            continue;
                                        }
                                        $tipoLabel = (string) ($tipo['nombre_tipo_cargo'] ?? ('Tipo #' . $idTipo));
                                        $tipoOptions[] = [
                                            'value' => (string) $idTipo,
                                            'label' => $tipoLabel,
                                            'search' => mb_strtolower($tipoLabel, 'UTF-8'),
                                        ];
                                    }
                                    msp2RenderSearchableSelectField([
                                        'wrapper_class' => 'col-12 col-lg-3',
                                        'label' => 'Tipo cargo',
                                        'input_name' => 'id_tipo_cargo_salida',
                                        'input_id' => 'id_tipo_cargo_salida',
                                        'picker_id' => 'tipo_picker',
                                        'button_id' => 'tipo_dropdown_btn',
                                        'filter_id' => 'tipo_dropdown_filter',
                                        'list_id' => 'tipo_dropdown_list',
                                        'error_id' => 'tipo_picker_error',
                                        'error_message' => 'Debes seleccionar un tipo de cargo.',
                                        'button_placeholder' => 'Selecciona tipo de cargo...',
                                        'filter_placeholder' => 'Buscar tipo de cargo',
                                        'empty_message' => 'No hay tipos de cargo activos.',
                                        'button_class' => 'btn btn-outline-secondary dropdown-toggle w-100 text-start ce-picker-btn',
                                        'required' => true,
                                        'options' => $tipoOptions,
                                    ]);
                                    ?>
                                    <div class="col-12 col-lg-2">
                                        <label class="form-label">Fecha cargo</label>
                                        <input
                                            type="date"
                                            class="form-control"
                                            name="fecha_cargo"
                                            value="<?php echo msp2Escape($manualAdjustDateDefault); ?>"
                                            min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                            max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                            required>
                                        <div class="form-text ce-date-range-hint" title="Rango permitido: <?php echo msp2Escape($manualAdjustDateRangeUi); ?>"><?php echo msp2Escape($manualAdjustDateRangeUi); ?></div>
                                    </div>
                                    <?php msp2RenderMontoClpField([
                                        'wrapper_class' => 'col-12 col-lg-3',
                                        'id' => 'monto_cargo_input',
                                        'name' => 'monto_cargo',
                                        'label' => 'Monto',
                                        'hint' => '',
                                    ]); ?>
                                    <div class="col-12">
                                        <label class="form-label">Descripción</label>
                                        <input type="text" class="form-control" name="descripcion_cargo" maxlength="500">
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap justify-content-end align-items-center mt-2 gap-2">
                                    <button type="submit" class="btn btn-outline-primary btn-sm">Agregar cargo extra</button>
                                </div>
                            </form>
            </section>

            <section class="msp-extra-charges-section msp-extra-charges-section--pending" aria-labelledby="titulo_cargos_pendientes">
                <div class="msp-extra-charges-section-header">
                    <div>
                        <h2 id="titulo_cargos_pendientes">Pendientes por aplicar</h2>
                        <p>Se incorporan una sola vez al documento y luego quedan marcados como aplicados.</p>
                    </div>
                    <div class="msp-extra-charges-summary" aria-label="Resumen de cargos pendientes">
                        <span><small>Cantidad</small><strong><?php echo (int) $pendientesCount; ?></strong></span>
                        <span><small>Total</small><strong><?php echo msp2Escape(ceFmtMonto($totalPendiente)); ?></strong></span>
                    </div>
                </div>

                    <?php if ($pendientesCount === 0): ?>
                        <div class="alert alert-success mb-0">
                            No hay cargos extra pendientes para incorporar.
                        </div>
                    <?php else: ?>
                        <div class="msp-management-table-responsive">
                            <table class="table table-sm align-middle mb-0 msp-management-table msp-extra-charges-table">
                                <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Local</th>
                                    <th>Arrendatario</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-end">Acciones</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($pendientes as $row): ?>
                                    <?php $rowIdCargo = (int) ($row['id_cargo_salida'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo msp2Escape(ceFmtFecha((string) ($row['fecha_cargo'] ?? ''))); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['nombre_tipo_cargo'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['cdo_local'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['nombre_arrendatario'] ?? ($row['nombre_comercial'] ?? '-'))); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['descripcion_cargo'] ?? '-')); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(ceFmtMonto($row['monto_cargo'] ?? 0)); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-warning btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modal_editar_cargo_extra"
                                                    data-edit-id="<?php echo $rowIdCargo; ?>"
                                                    data-edit-fecha="<?php echo msp2Escape(substr((string) ($row['fecha_cargo'] ?? ''), 0, 10)); ?>"
                                                    data-edit-tipo-id="<?php echo (int) ($row['id_tipo_cargo_salida'] ?? 0); ?>"
                                                    data-edit-descripcion="<?php echo msp2Escape((string) ($row['descripcion_cargo'] ?? '')); ?>"
                                                    data-edit-observaciones="<?php echo msp2Escape((string) ($row['observaciones'] ?? '')); ?>"
                                                    data-edit-monto="<?php echo msp2Escape((string) ($row['monto_cargo'] ?? '')); ?>">
                                                    <i class="bi bi-pencil-square me-1"></i>Editar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-outline-danger btn-sm"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modal_cancelar_cargo_extra"
                                                    data-cancel-id="<?php echo $rowIdCargo; ?>"
                                                    data-cancel-descripcion="<?php echo msp2Escape((string) ($row['descripcion_cargo'] ?? '-')); ?>">
                                                    <i class="bi bi-x-circle me-1"></i>Eliminar
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="modal fade" id="modal_editar_cargo_extra" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Editar cargo extra</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="accion" value="actualizar_cargo_extra">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                            <input type="hidden" name="id_cargo_salida" id="ce_edit_id_cargo_salida" value="">

                                            <div class="row g-2">
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label">Fecha cargo</label>
                                                    <input
                                                        type="date"
                                                        class="form-control"
                                                        name="fecha_cargo"
                                                        id="ce_edit_fecha_cargo"
                                                        min="<?php echo msp2Escape($manualAdjustDateMin); ?>"
                                                        max="<?php echo msp2Escape($manualAdjustDateMax); ?>"
                                                        required>
                                                </div>
                                                <div class="col-12 col-md-8">
                                                    <label class="form-label">Tipo cargo</label>
                                                    <select class="form-select" name="id_tipo_cargo_salida" id="ce_edit_id_tipo_cargo_salida" required>
                                                        <option value="">Selecciona...</option>
                                                        <?php foreach ($tiposCargo as $tipo): ?>
                                                            <?php $idTipo = (int) ($tipo['id_tipo_cargo_salida'] ?? 0); ?>
                                                            <?php if ($idTipo <= 0) { continue; } ?>
                                                            <option value="<?php echo $idTipo; ?>"><?php echo msp2Escape((string) ($tipo['nombre_tipo_cargo'] ?? ('Tipo #' . $idTipo))); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Descripción</label>
                                                    <input type="text" class="form-control" name="descripcion_cargo" id="ce_edit_descripcion_cargo" maxlength="500">
                                                </div>
                                                <div class="col-12 col-md-4">
                                                    <label class="form-label">Monto</label>
                                                    <input type="text" class="form-control" name="monto_cargo" id="ce_edit_monto_cargo" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Observaciones</label>
                                                    <textarea class="form-control" name="observaciones_cargo" id="ce_edit_observaciones_cargo" rows="3" maxlength="500"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
                                            <button type="submit" class="btn btn-warning"><i class="bi bi-check2-circle me-1"></i>Guardar cambios</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="modal fade" id="modal_cancelar_cargo_extra" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Eliminar cargo pendiente</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                                        </div>
                                        <div class="modal-body">
                                            <input type="hidden" name="accion" value="cancelar_cargo_extra">
                                            <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                            <input type="hidden" name="id_cargo_salida" id="ce_cancel_id_cargo_salida" value="">
                                            <p class="mb-2">Se cancelará el cargo:</p>
                                            <p class="small text-muted mb-3" id="ce_cancel_descripcion_cargo">-</p>
                                            <label class="form-label" for="ce_cancel_reason">Motivo (opcional)</label>
                                            <textarea class="form-control" id="ce_cancel_reason" name="confirm_reason" rows="3" maxlength="500" placeholder="Puedes indicar por qué se cancela este cargo"></textarea>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver</button>
                                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-circle me-1"></i>Eliminar</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
            </section>

            <section class="msp-extra-charges-section msp-extra-charges-section--assigned" aria-labelledby="titulo_cargos_asignados">
                    <div class="msp-extra-charges-section-header">
                        <div>
                            <h2 id="titulo_cargos_asignados">Ya asignados en este período</h2>
                            <p>Estos cargos ya fueron vinculados a documentos del período actual.</p>
                        </div>
                        <div class="msp-extra-charges-summary" aria-label="Resumen de cargos asignados">
                            <span><small>Cantidad</small><strong><?php echo (int) $aplicadosCount; ?></strong></span>
                            <span><small>Total</small><strong><?php echo msp2Escape(ceFmtMonto($totalAplicado)); ?></strong></span>
                        </div>
                    </div>
                    <?php if ($aplicadosCount === 0): ?>
                        <div class="small text-muted">Todavía no hay cargos asignados en este período.</div>
                    <?php else: ?>
                        <div class="msp-management-table-responsive">
                            <table class="table table-sm align-middle mb-0 msp-management-table msp-extra-charges-table">
                                <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Fecha</th>
                                    <th>Tipo</th>
                                    <th>Local</th>
                                    <th>Tienda</th>
                                    <th>Documento</th>
                                    <th>Descripción</th>
                                    <th class="text-end">Monto</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($aplicados as $row): ?>
                                    <tr>
                                        <td><?php echo (int) ($row['id_cargo_salida'] ?? 0); ?></td>
                                        <td><?php echo msp2Escape(ceFmtFecha((string) ($row['fecha_cargo'] ?? ''))); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['nombre_tipo_cargo'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['cdo_local'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['nombre_comercial'] ?? '-')); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['numero_documento'] ?? ('#' . ((int) ($row['id_documento_cobro'] ?? 0))))); ?></td>
                                        <td><?php echo msp2Escape((string) ($row['descripcion_cargo'] ?? '-')); ?></td>
                                        <td class="text-end"><?php echo msp2Escape(ceFmtMonto($row['monto_cargo'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const quickExtraForm = document.getElementById('form_cargo_extra_rapido');
    const extraTargetInput = document.getElementById('target_contrato_local');
    const extraTargetBtn = document.getElementById('target_dropdown_btn');
    const extraTargetError = document.getElementById('target_picker_error');
    const extraTipoInput = document.getElementById('id_tipo_cargo_salida');
    const extraTipoBtn = document.getElementById('tipo_dropdown_btn');
    const extraTipoError = document.getElementById('tipo_picker_error');
    const formPeriodo = document.getElementById('form_periodo_cargos_extra');
    const inputPeriodo = document.getElementById('periodo_cargos_extra');
    const targetPickerReady = extraTargetInput instanceof HTMLInputElement && extraTargetBtn instanceof HTMLButtonElement;
    const tipoPickerReady = extraTipoInput instanceof HTMLInputElement && extraTipoBtn instanceof HTMLButtonElement;

    if (quickExtraForm instanceof HTMLFormElement) {
        quickExtraForm.addEventListener('submit', (event) => {
            let hasError = false;
            if (targetPickerReady && extraTargetInput.value.trim() === '') {
                hasError = true;
                extraTargetBtn.classList.add('is-invalid');
                if (extraTargetError instanceof HTMLDivElement) {
                    extraTargetError.classList.remove('d-none');
                }
            }

            if (tipoPickerReady && extraTipoInput.value.trim() === '') {
                hasError = true;
                extraTipoBtn.classList.add('is-invalid');
                if (extraTipoError instanceof HTMLDivElement) {
                    extraTipoError.classList.remove('d-none');
                }
            }

            if (!hasError) {
                return;
            }

            event.preventDefault();
            if (targetPickerReady && extraTargetInput.value.trim() === '') {
                extraTargetBtn.focus();
                return;
            }
            if (tipoPickerReady && extraTipoInput.value.trim() === '') {
                extraTipoBtn.focus();
            }
        });
    }

    if (formPeriodo instanceof HTMLFormElement && inputPeriodo instanceof HTMLInputElement) {
        inputPeriodo.addEventListener('change', () => {
            if (inputPeriodo.value.trim() !== '') {
                formPeriodo.requestSubmit();
            }
        });
    }

    const modalEditarCargoExtra = document.getElementById('modal_editar_cargo_extra');
    if (modalEditarCargoExtra) {
        modalEditarCargoExtra.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalEditarCargoExtra.querySelector('#ce_edit_id_cargo_salida');
            const fechaInput = modalEditarCargoExtra.querySelector('#ce_edit_fecha_cargo');
            const tipoInput = modalEditarCargoExtra.querySelector('#ce_edit_id_tipo_cargo_salida');
            const descripcionInput = modalEditarCargoExtra.querySelector('#ce_edit_descripcion_cargo');
            const montoInput = modalEditarCargoExtra.querySelector('#ce_edit_monto_cargo');
            const observacionesInput = modalEditarCargoExtra.querySelector('#ce_edit_observaciones_cargo');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-edit-id') || '');
            }
            if (fechaInput instanceof HTMLInputElement) {
                fechaInput.value = String(trigger.getAttribute('data-edit-fecha') || '');
            }
            if (tipoInput instanceof HTMLSelectElement) {
                tipoInput.value = String(trigger.getAttribute('data-edit-tipo-id') || '');
            }
            if (descripcionInput instanceof HTMLInputElement) {
                descripcionInput.value = String(trigger.getAttribute('data-edit-descripcion') || '');
            }
            if (montoInput instanceof HTMLInputElement) {
                montoInput.value = String(trigger.getAttribute('data-edit-monto') || '');
            }
            if (observacionesInput instanceof HTMLTextAreaElement) {
                observacionesInput.value = String(trigger.getAttribute('data-edit-observaciones') || '');
            }
        });
    }

    const modalCancelarCargoExtra = document.getElementById('modal_cancelar_cargo_extra');
    if (modalCancelarCargoExtra) {
        modalCancelarCargoExtra.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget instanceof HTMLElement ? event.relatedTarget : null;
            if (!trigger) {
                return;
            }

            const idInput = modalCancelarCargoExtra.querySelector('#ce_cancel_id_cargo_salida');
            const descripcionNode = modalCancelarCargoExtra.querySelector('#ce_cancel_descripcion_cargo');
            const motivoInput = modalCancelarCargoExtra.querySelector('#ce_cancel_reason');

            if (idInput instanceof HTMLInputElement) {
                idInput.value = String(trigger.getAttribute('data-cancel-id') || '');
            }
            if (descripcionNode instanceof HTMLElement) {
                descripcionNode.textContent = String(trigger.getAttribute('data-cancel-descripcion') || '-');
            }
            if (motivoInput instanceof HTMLTextAreaElement) {
                motivoInput.value = '';
            }
        });
    }

})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
