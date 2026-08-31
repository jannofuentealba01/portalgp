<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/saldo_favor_periodo_helper.php';

msp2RequireAccess();

function pmResolvePeriodoYm(string $raw): ?string
{
    $value = trim($raw);
    if ($value === '' || preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m', $value);
    if ($date === false || $date->format('Y-m') !== $value) {
        return null;
    }

    return $value;
}

function pmPeriodoToIsoDate(string $periodoYm): string
{
    return $periodoYm . '-01';
}

function pmNormalizeSignedDecimalInput(mixed $value, int $scale = 2): array
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return [true, 0.0];
    }

    $sign = 1;
    if ($raw[0] === '+' || $raw[0] === '-') {
        $sign = $raw[0] === '-' ? -1 : 1;
        $raw = substr($raw, 1);
    }

    if ($raw === '') {
        return [false, null];
    }

    [$ok, $normalized] = msp2NormalizeDecimalInput($raw, $scale);
    if (!$ok || $normalized === null) {
        return [false, null];
    }

    $number = (float) $normalized;
    if ($sign < 0) {
        $number *= -1;
    }

    return [true, round($number, $scale)];
}

function pmFormatMoney(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function pmFormatDate(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('d-m-Y');
}

function pmMapPagoError(string $message): string
{
    if (str_contains($message, '50061')) {
        return 'Documento invalido.';
    }
    if (str_contains($message, '50062')) {
        return 'Fecha de pago invalida.';
    }
    if (str_contains($message, '50063')) {
        return 'Monto de pago invalido.';
    }
    if (str_contains($message, '50064')) {
        return 'Documento no existe.';
    }
    if (str_contains($message, '50065')) {
        return 'Documento sin saldo pendiente.';
    }
    if (str_contains($message, '50041')) {
        return 'Documento anulado.';
    }
    if (str_contains($message, '50042')) {
        return 'El pago excede el monto permitido para el documento.';
    }

    return 'No fue posible registrar el pago para esta fila.';
}

function pmValidateBaseStructure(PDO $conn): ?string
{
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_pagos',
        'msp_tiendas',
        'msp_contratos_arriendo',
        'msp_contrato_locales',
    ];

    $missing = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missing[] = $tableName;
        }
    }

    if ($missing !== []) {
        return 'Faltan tablas requeridas: `' . implode('`, `', $missing) . '`.';
    }

    if (!msp2ProcedureExists($conn, 'msp_registrar_pago_documento')) {
        return 'No existe el procedimiento `dbo.msp_registrar_pago_documento`. Ejecuta `db/msp_documento_pago.sql`.';
    }

    return null;
}

function pmFetchDocumentosPeriodo(PDO $conn, string $periodoIso): array
{
    $hasFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $hasFechaTerminoLocal = msp2ColumnExists($conn, 'msp_contrato_locales', 'fecha_termino');

    $condicionTerminoContrato = $hasFechaTerminoEfectiva
        ? '(ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= :periodo_ca_termino)'
        : '1 = 1';
    $condicionTerminoLocal = $hasFechaTerminoLocal
        ? '(cl.fecha_termino IS NULL OR cl.fecha_termino >= :periodo_cl_termino)'
        : '1 = 1';

    $sql = "SELECT
                dc.id_documento_cobro,
                dc.id_tienda,
                dc.periodo_facturacion,
                dc.numero_documento,
                dc.estado_documento,
                dc.monto_total,
                dc.saldo_pendiente,
                dc.nombre_arrendatario_snapshot,
                dc.rut_arrendatario_snapshot,
                COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo) AS id_contrato_arriendo
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_contrato_arriendo
                FROM dbo.msp_contratos_arriendo ca
                WHERE ca.id_tienda = dc.id_tienda
                  AND ca.fecha_inicio <= EOMONTH(:periodo_ca_fin)
                  AND $condicionTerminoContrato
                  AND ca.estado_contrato IN (1,2,3)
                  AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_contrato_locales cl
                        WHERE cl.id_contrato_arriendo = ca.id_contrato_arriendo
                          AND cl.estado_relacion = 1
                          AND cl.fecha_inicio <= EOMONTH(:periodo_cl_fin)
                          AND $condicionTerminoLocal
                  )
                ORDER BY ca.fecha_inicio DESC, ca.id_contrato_arriendo DESC
            ) contrato_vigente
            WHERE dc.periodo_facturacion = :periodo_doc
              AND dc.estado_documento IN (2,3)
              AND dc.saldo_pendiente > 0
              AND COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo) IS NOT NULL
            ORDER BY nombre_tienda ASC, dc.id_documento_cobro ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':periodo_doc', $periodoIso, PDO::PARAM_STR);
    $stmt->bindValue(':periodo_ca_fin', $periodoIso, PDO::PARAM_STR);
    $stmt->bindValue(':periodo_cl_fin', $periodoIso, PDO::PARAM_STR);
    if ($hasFechaTerminoEfectiva) {
        $stmt->bindValue(':periodo_ca_termino', $periodoIso, PDO::PARAM_STR);
    }
    if ($hasFechaTerminoLocal) {
        $stmt->bindValue(':periodo_cl_termino', $periodoIso, PDO::PARAM_STR);
    }
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

$flash = msp2PullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$tablaOk = false;
$loadError = null;
$processNotice = null;
$executionReport = null;
$documentosPeriodo = [];
$documentosMap = [];

$today = new DateTimeImmutable('today');
$defaultPeriodoYm = $today->format('Y-m');

$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$periodoYmInput = trim((string) ($isPost ? ($_POST['periodo_ym'] ?? $defaultPeriodoYm) : ($_GET['periodo_ym'] ?? $defaultPeriodoYm)));
$periodoYm = pmResolvePeriodoYm($periodoYmInput) ?? $defaultPeriodoYm;
$periodoIso = pmPeriodoToIsoDate($periodoYm);

$fechaPagoInput = trim((string) ($_POST['fecha_pago'] ?? $today->format('Y-m-d')));
$medioPagoInput = msp2NormalizeText((string) ($_POST['medio_pago'] ?? 'Transferencia'));
$referenciaPagoInput = msp2NormalizeText((string) ($_POST['referencia_pago'] ?? ''));
$observacionesInput = msp2NormalizeText((string) ($_POST['observaciones'] ?? 'Reconstruccion de historial de pagos (carga masiva).'));

$ajustesInput = $_POST['ajuste'] ?? [];
if (!is_array($ajustesInput)) {
    $ajustesInput = [];
}

$seleccionadosInput = $_POST['seleccionados'] ?? [];
if (!is_array($seleccionadosInput)) {
    $seleccionadosInput = [];
}
$seleccionadosLookup = [];
foreach ($seleccionadosInput as $rawId) {
    $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        continue;
    }
    $seleccionadosLookup[(int) $id] = true;
}

try {
    $loadError = pmValidateBaseStructure($conn);
    $tablaOk = $loadError === null;

    if ($tablaOk) {
        $documentosPeriodo = pmFetchDocumentosPeriodo($conn, $periodoIso);
        foreach ($documentosPeriodo as $doc) {
            $docId = (int) ($doc['id_documento_cobro'] ?? 0);
            if ($docId > 0) {
                $documentosMap[$docId] = $doc;
            }
        }
    }
} catch (PDOException) {
    $tablaOk = false;
    $loadError = 'No fue posible validar/cargar la base de pago masivo.';
}

if ($tablaOk && $isPost && trim((string) ($_POST['accion'] ?? '')) === 'ejecutar_lote') {
    $fechaPago = DateTimeImmutable::createFromFormat('Y-m-d', $fechaPagoInput);
    if ($fechaPago === false || $fechaPago->format('Y-m-d') !== $fechaPagoInput) {
        $processNotice = [
            'type' => 'warning',
            'message' => 'La fecha de pago no tiene un formato valido.',
        ];
    } elseif (mb_strlen($medioPagoInput) > 50) {
        $processNotice = [
            'type' => 'warning',
            'message' => 'El medio de pago supera 50 caracteres.',
        ];
    } elseif (mb_strlen($referenciaPagoInput) > 100) {
        $processNotice = [
            'type' => 'warning',
            'message' => 'La referencia supera 100 caracteres.',
        ];
    } elseif (mb_strlen($observacionesInput) > 500) {
        $processNotice = [
            'type' => 'warning',
            'message' => 'Las observaciones superan 500 caracteres.',
        ];
    } elseif ($seleccionadosLookup === []) {
        $processNotice = [
            'type' => 'warning',
            'message' => 'Debes seleccionar al menos un documento para ejecutar el lote.',
        ];
    } else {
        $validRows = [];
        $validationErrors = [];

        foreach (array_keys($seleccionadosLookup) as $idDocumento) {
            $doc = $documentosMap[$idDocumento] ?? null;
            if (!is_array($doc)) {
                $validationErrors[] = [
                    'id_documento_cobro' => $idDocumento,
                    'message' => 'El documento no corresponde al mes seleccionado o ya no tiene saldo pendiente.',
                ];
                continue;
            }

            $saldo = round((float) ($doc['saldo_pendiente'] ?? 0), 2);
            $ajusteRaw = (string) ($ajustesInput[(string) $idDocumento] ?? $ajustesInput[$idDocumento] ?? '0');
            [$ajusteOk, $ajusteValue] = pmNormalizeSignedDecimalInput($ajusteRaw, 2);

            if (!$ajusteOk || $ajusteValue === null) {
                $validationErrors[] = [
                    'id_documento_cobro' => $idDocumento,
                    'message' => 'El ajuste +/- es invalido.',
                ];
                continue;
            }

            $montoFinal = round($saldo + (float) $ajusteValue, 2);
            if ($montoFinal <= 0) {
                $validationErrors[] = [
                    'id_documento_cobro' => $idDocumento,
                    'message' => 'El monto final debe ser mayor a 0 (saldo + ajuste).',
                ];
                continue;
            }

            $validRows[] = [
                'id_documento_cobro' => $idDocumento,
                'saldo_pendiente' => $saldo,
                'ajuste' => (float) $ajusteValue,
                'monto_final' => $montoFinal,
            ];
        }

        $executionReport = [
            'total_seleccionadas' => count($seleccionadosLookup),
            'total_validas' => count($validRows),
            'ok' => 0,
            'failed' => count($validationErrors),
            'monto_programado' => round(array_reduce($validRows, static function (float $carry, array $row): float {
                return $carry + (float) ($row['monto_final'] ?? 0);
            }, 0.0), 2),
            'monto_aplicado' => 0.0,
            'monto_excedente' => 0.0,
            'saldo_favor_periodo_ok' => 0,
            'saldo_favor_periodo_failed' => 0,
            'success' => [],
            'errors' => $validationErrors,
            'saldo_favor_periodo_errors' => [],
        ];

        if ($validRows === []) {
            $processNotice = [
                'type' => 'warning',
                'message' => 'No hay filas validas para ejecutar. Revisa los ajustes +/- y vuelve a intentar.',
            ];
        } else {
            $stmt = $conn->prepare(
                'EXEC dbo.msp_registrar_pago_documento
                    @id_documento_cobro = :id_documento_cobro,
                    @fecha_pago = :fecha_pago,
                    @monto_pagado = :monto_pagado,
                    @medio_pago = :medio_pago,
                    @referencia_pago = :referencia_pago,
                    @observaciones = :observaciones'
            );

            foreach ($validRows as $row) {
                $idDocumento = (int) ($row['id_documento_cobro'] ?? 0);
                $montoFinal = round((float) ($row['monto_final'] ?? 0), 2);

                try {
                    $stmt->bindValue(':id_documento_cobro', $idDocumento, PDO::PARAM_INT);
                    $stmt->bindValue(':fecha_pago', $fechaPagoInput, PDO::PARAM_STR);
                    $stmt->bindValue(':monto_pagado', $montoFinal, PDO::PARAM_STR);
                    $stmt->bindValue(':medio_pago', $medioPagoInput === '' ? null : $medioPagoInput, $medioPagoInput === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(':referencia_pago', $referenciaPagoInput === '' ? null : $referenciaPagoInput, $referenciaPagoInput === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->bindValue(':observaciones', $observacionesInput === '' ? null : $observacionesInput, $observacionesInput === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $stmt->execute();
                    $spRow = $stmt->fetch() ?: [];
                    $stmt->closeCursor();

                    $montoAplicado = isset($spRow['monto_aplicado_documento'])
                        ? round((float) $spRow['monto_aplicado_documento'], 2)
                        : $montoFinal;
                    $montoExcedente = isset($spRow['monto_saldo_favor_generado'])
                        ? round((float) $spRow['monto_saldo_favor_generado'], 2)
                        : max(0.0, round($montoFinal - $montoAplicado, 2));
                    $idPago = isset($spRow['id_pago_generado']) ? (int) $spRow['id_pago_generado'] : 0;
                    $saldoFavorPeriodoOk = null;

                    if ($montoExcedente > 0.005) {
                        try {
                            $saldoFavorPeriodoOk = msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
                                $conn,
                                $idPago,
                                $idDocumento,
                                $montoExcedente,
                                $fechaPagoInput
                            );
                            if ($saldoFavorPeriodoOk) {
                                $executionReport['saldo_favor_periodo_ok']++;
                            } else {
                                $executionReport['saldo_favor_periodo_failed']++;
                                $executionReport['saldo_favor_periodo_errors'][] = [
                                    'id_documento_cobro' => $idDocumento,
                                    'id_pago' => $idPago,
                                    'message' => 'No se pudo crear o actualizar el item de saldo a favor del periodo siguiente.',
                                ];
                            }
                        } catch (Throwable $syncException) {
                            $saldoFavorPeriodoOk = false;
                            $executionReport['saldo_favor_periodo_failed']++;
                            $executionReport['saldo_favor_periodo_errors'][] = [
                                'id_documento_cobro' => $idDocumento,
                                'id_pago' => $idPago,
                                'message' => $syncException->getMessage(),
                            ];
                        }
                    }

                    $executionReport['ok']++;
                    $executionReport['monto_aplicado'] = round((float) $executionReport['monto_aplicado'] + $montoAplicado, 2);
                    $executionReport['monto_excedente'] = round((float) $executionReport['monto_excedente'] + $montoExcedente, 2);
                    $executionReport['success'][] = [
                        'id_documento_cobro' => $idDocumento,
                        'id_pago' => $idPago,
                        'monto_programado' => $montoFinal,
                        'monto_aplicado' => $montoAplicado,
                        'monto_excedente' => $montoExcedente,
                        'saldo_favor_periodo_ok' => $saldoFavorPeriodoOk,
                    ];
                } catch (PDOException $exception) {
                    if ($stmt->errorCode() !== null) {
                        $stmt->closeCursor();
                    }

                    $executionReport['failed']++;
                    $executionReport['errors'][] = [
                        'id_documento_cobro' => $idDocumento,
                        'message' => pmMapPagoError($exception->getMessage()),
                    ];
                }
            }

            $processNotice = [
                'type' => $executionReport['failed'] > 0 || $executionReport['saldo_favor_periodo_failed'] > 0 ? 'warning' : 'success',
                'message' => 'Ejecucion completada. Exitosas: ' . (int) $executionReport['ok'] . ' | Fallidas: ' . (int) $executionReport['failed'] . '.'
                    . ((int) $executionReport['saldo_favor_periodo_failed'] > 0 ? ' Hay excedentes que requieren revisar asignacion al periodo siguiente.' : ''),
            ];

            // Refresca grilla con saldos posteriores a la ejecución.
            $documentosPeriodo = pmFetchDocumentosPeriodo($conn, $periodoIso);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Pago Masivo Mensual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .pm-hero {
            border: 1px solid #d8e6ff;
            background: linear-gradient(135deg, #eef5ff 0%, #ffffff 70%);
            border-radius: 14px;
            padding: 1rem 1.2rem;
            margin-bottom: 1rem;
        }

        .pm-card-soft {
            border: 1px solid #e7ebf3;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(17, 24, 39, 0.05);
        }

        .pm-metrics {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 0.5rem;
        }

        .pm-metric-chip {
            border: 1px solid #dbe3ef;
            background: #f8fafc;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.82rem;
        }

        .pm-table thead th {
            background: #f1f5fb;
            border-bottom-color: #d7e0ee;
            font-weight: 600;
        }

        .pm-table tbody tr:hover {
            background: #f8fbff;
        }

        .pm-table .js-ajuste-input {
            font-variant-numeric: tabular-nums;
        }
    </style>
</head>
<body class="gp-layout bg-light">

<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>

<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide" data-tour="pm-root">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div class="d-flex flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
                </a>
                <a href="<?php echo msp2Escape(msp2Url('pagos/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Pagos
                </a>
                <a href="<?php echo msp2Escape(msp2Url('ayuda/index.php')); ?>" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Ayuda
                </a>
                <button type="button" class="btn btn-outline-info btn-sm" id="mspStartPagoMasivoTour" data-tour="pm-tour-button">
                    <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Ver tutorial
                </button>
            </div>
        </div>

        <div class="pm-hero" data-tour="pm-hero">
            <p class="section-kicker text-center mb-1">MSP / Cobranza</p>
            <h1 class="form-title text-center mb-2">Pago Masivo Mensual</h1>
            <p class="text-muted text-center mb-0">Selecciona mes, marca documentos pagados y ajusta monto con diferencia +/- sobre saldo pendiente.</p>
        </div>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($processNotice !== null): ?>
            <div class="alert alert-<?php echo msp2Escape((string) ($processNotice['type'] ?? 'info')); ?>" role="alert">
                <?php echo msp2Escape((string) ($processNotice['message'] ?? '')); ?>
            </div>
        <?php endif; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <form method="get" id="form_periodo_mes" class="card card-body mb-3 pm-card-soft" data-tour="pm-periodo">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label for="periodo_ym" class="form-label">Mes de pago</label>
                        <input type="month" class="form-control" id="periodo_ym" name="periodo_ym" value="<?php echo msp2Escape($periodoYm); ?>" required>
                        <div class="form-text">Al cambiar el mes, la grilla se actualiza automáticamente.</div>
                    </div>
                </div>
            </form>

            <div class="alert alert-warning border-warning-subtle">
                <strong>Reconstruccion de historial:</strong> este registro masivo se usa para reconstruir pagos historicos y no representa transacciones operativas online en tiempo real.
            </div>

            <?php if ($documentosPeriodo === []): ?>
                <div class="card card-body pm-card-soft">
                    <div class="alert alert-info mb-0">No hay documentos con saldo pendiente para <?php echo msp2Escape($periodoYm); ?> bajo contratos vigentes.</div>
                </div>
            <?php else: ?>
                <?php
                $totalSaldoPeriodo = round(array_reduce($documentosPeriodo, static function (float $carry, array $doc): float {
                    return $carry + round((float) ($doc['saldo_pendiente'] ?? 0), 2);
                }, 0.0), 2);
                ?>
                <form method="post" id="form_pago_masivo_mensual">
                    <?php msp2CsrfField(); ?>
                    <input type="hidden" name="accion" value="ejecutar_lote">
                    <input type="hidden" name="periodo_ym" value="<?php echo msp2Escape($periodoYm); ?>">

                    <div class="card card-body mb-3 pm-card-soft" data-tour="pm-datos-lote">
                        <h2 class="h6 mb-3">Datos comunes del lote</h2>
                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-md-2">
                                <label for="fecha_pago" class="form-label">Fecha pago</label>
                                <input type="date" class="form-control" id="fecha_pago" name="fecha_pago" value="<?php echo msp2Escape($fechaPagoInput); ?>" required>
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="medio_pago" class="form-label">Medio pago</label>
                                <input type="text" class="form-control" id="medio_pago" name="medio_pago" maxlength="50" value="<?php echo msp2Escape($medioPagoInput); ?>" placeholder="Transferencia, deposito...">
                            </div>
                            <div class="col-12 col-md-3">
                                <label for="referencia_pago" class="form-label">Referencia</label>
                                <input type="text" class="form-control" id="referencia_pago" name="referencia_pago" maxlength="100" value="<?php echo msp2Escape($referenciaPagoInput); ?>" placeholder="Nro operacion / comprobante">
                            </div>
                            <div class="col-12 col-md-4">
                                <label for="observaciones" class="form-label">Observaciones comunes</label>
                                <input type="text" class="form-control" id="observaciones" name="observaciones" maxlength="500" value="<?php echo msp2Escape($observacionesInput); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="card card-body mb-3 pm-card-soft" data-tour="pm-tabla-lote">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <h2 class="h6 mb-0">Documentos de <?php echo msp2Escape($periodoYm); ?></h2>
                            <div class="pm-metrics">
                                <span class="pm-metric-chip">Documentos: <strong><?php echo count($documentosPeriodo); ?></strong></span>
                                <span class="pm-metric-chip">Saldo total: <strong><?php echo msp2Escape(pmFormatMoney($totalSaldoPeriodo)); ?></strong></span>
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-3 mb-2 small">
                            <div>Seleccionados: <strong id="resumen_seleccionados">0</strong></div>
                            <div>Monto total lote: <strong id="resumen_monto_lote">$ 0,00</strong></div>
                        </div>

                        <div id="lote_validation_msg" class="alert alert-danger d-none py-2 mb-2"></div>

                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle text-center mb-0 pm-table" id="tabla_pago_masivo_mes">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:40px;">
                                            <input type="checkbox" class="form-check-input" id="check_todos" aria-label="Seleccionar todos">
                                        </th>
                                        <th style="width:90px;">Doc</th>
                                        <th class="text-start">Tienda</th>
                                        <th class="text-start">Arrendatario</th>
                                        <th style="width:120px;" class="text-end">Saldo</th>
                                        <th style="width:160px;">Ajuste +/-</th>
                                        <th style="width:140px;" class="text-end">Monto final</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($documentosPeriodo as $doc): ?>
                                    <?php
                                    $docId = (int) ($doc['id_documento_cobro'] ?? 0);
                                    $saldo = round((float) ($doc['saldo_pendiente'] ?? 0), 2);
                                    $arrendatario = trim((string) ($doc['nombre_arrendatario_snapshot'] ?? ''));
                                    $rutArrendatario = trim((string) ($doc['rut_arrendatario_snapshot'] ?? ''));
                                    $ajusteRaw = (string) ($ajustesInput[(string) $docId] ?? $ajustesInput[$docId] ?? '0');
                                    [$okAjusteRow, $ajusteRow] = pmNormalizeSignedDecimalInput($ajusteRaw, 2);
                                    $ajusteMostrado = $okAjusteRow && $ajusteRow !== null ? (float) $ajusteRow : 0.0;
                                    $montoFinal = round($saldo + $ajusteMostrado, 2);
                                    $isChecked = isset($seleccionadosLookup[$docId]);
                                    ?>
                                    <tr data-row-doc="<?php echo $docId; ?>">
                                        <td>
                                            <input
                                                type="checkbox"
                                                class="form-check-input js-row-check"
                                                name="seleccionados[]"
                                                value="<?php echo $docId; ?>"
                                                <?php echo $isChecked ? 'checked' : ''; ?>
                                                aria-label="Seleccionar documento <?php echo $docId; ?>">
                                        </td>
                                        <td>
                                            <div><strong>#<?php echo $docId; ?></strong></div>
                                            <small class="text-muted"><?php echo msp2Escape((string) ($doc['numero_documento'] ?? '')); ?></small>
                                        </td>
                                        <td class="text-start"><?php echo msp2Escape((string) ($doc['nombre_tienda'] ?? '')); ?></td>
                                        <td class="text-start">
                                            <div><?php echo msp2Escape($arrendatario !== '' ? $arrendatario : '-'); ?></div>
                                            <small class="text-muted"><?php echo msp2Escape($rutArrendatario !== '' ? $rutArrendatario : '-'); ?></small>
                                        </td>
                                        <td class="text-end js-saldo" data-saldo="<?php echo msp2Escape(number_format($saldo, 2, '.', '')); ?>"><?php echo msp2Escape(pmFormatMoney($saldo)); ?></td>
                                        <td>
                                            <input
                                                type="text"
                                                class="form-control form-control-sm text-end js-ajuste-input"
                                                name="ajuste[<?php echo $docId; ?>]"
                                                value="<?php echo msp2Escape(number_format($ajusteMostrado, 2, '.', '')); ?>"
                                                inputmode="decimal"
                                                placeholder="0.00">
                                            <div class="form-text">Base saldo + ajuste</div>
                                        </td>
                                        <td class="text-end fw-bold js-monto-final" data-monto-final="<?php echo msp2Escape(number_format($montoFinal, 2, '.', '')); ?>">
                                            <?php echo msp2Escape(pmFormatMoney($montoFinal)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <button type="submit" class="btn btn-success" id="btn_ejecutar_lote">
                                <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Ejecutar pago masivo
                            </button>
                            <span class="small text-muted align-self-center">Solo se procesan filas marcadas. Si monto final supera saldo, el excedente queda en saldo a favor.</span>
                        </div>
                    </div>
                </form>
            <?php endif; ?>

            <?php if (is_array($executionReport)): ?>
                <?php
                $successRows = is_array($executionReport['success'] ?? null) ? $executionReport['success'] : [];
                $errorRows = is_array($executionReport['errors'] ?? null) ? $executionReport['errors'] : [];
                $saldoFavorPeriodoErrorRows = is_array($executionReport['saldo_favor_periodo_errors'] ?? null) ? $executionReport['saldo_favor_periodo_errors'] : [];
                ?>
                <div class="card card-body mb-3 pm-card-soft">
                    <h2 class="h5 mb-3">Resultado de ejecucion</h2>
                    <div class="row g-2 mb-2">
                        <div class="col-md-3"><strong>Seleccionadas:</strong> <?php echo (int) ($executionReport['total_seleccionadas'] ?? 0); ?></div>
                        <div class="col-md-3"><strong>Exitosas:</strong> <?php echo (int) ($executionReport['ok'] ?? 0); ?></div>
                        <div class="col-md-3"><strong>Fallidas:</strong> <?php echo (int) ($executionReport['failed'] ?? 0); ?></div>
                        <div class="col-md-3"><strong>Monto aplicado:</strong> <?php echo msp2Escape(pmFormatMoney($executionReport['monto_aplicado'] ?? 0)); ?></div>
                        <div class="col-md-3"><strong>Monto programado:</strong> <?php echo msp2Escape(pmFormatMoney($executionReport['monto_programado'] ?? 0)); ?></div>
                        <div class="col-md-3"><strong>Excedente total:</strong> <?php echo msp2Escape(pmFormatMoney($executionReport['monto_excedente'] ?? 0)); ?></div>
                        <div class="col-md-3"><strong>Saldo mes sig. OK:</strong> <?php echo (int) ($executionReport['saldo_favor_periodo_ok'] ?? 0); ?></div>
                        <div class="col-md-3"><strong>Saldo mes sig. revisar:</strong> <?php echo (int) ($executionReport['saldo_favor_periodo_failed'] ?? 0); ?></div>
                    </div>

                    <?php if ($successRows !== []): ?>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered align-middle text-center">
                                <thead class="table-light">
                                    <tr>
                                        <th>Documento</th>
                                        <th>Pago generado</th>
                                        <th>Monto programado</th>
                                        <th>Monto aplicado</th>
                                        <th>Excedente</th>
                                        <th>Mes siguiente</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($successRows, 0, 300) as $row): ?>
                                        <?php
                                        $saldoPeriodoOk = $row['saldo_favor_periodo_ok'] ?? null;
                                        if ($saldoPeriodoOk === true) {
                                            $saldoPeriodoLabel = 'Asignado';
                                        } elseif ($saldoPeriodoOk === false) {
                                            $saldoPeriodoLabel = 'Revisar';
                                        } else {
                                            $saldoPeriodoLabel = '-';
                                        }
                                        ?>
                                        <tr>
                                            <td>#<?php echo (int) ($row['id_documento_cobro'] ?? 0); ?></td>
                                            <td>#<?php echo (int) ($row['id_pago'] ?? 0); ?></td>
                                            <td class="text-end"><?php echo msp2Escape(pmFormatMoney($row['monto_programado'] ?? 0)); ?></td>
                                            <td class="text-end"><?php echo msp2Escape(pmFormatMoney($row['monto_aplicado'] ?? 0)); ?></td>
                                            <td class="text-end"><?php echo msp2Escape(pmFormatMoney($row['monto_excedente'] ?? 0)); ?></td>
                                            <td><?php echo msp2Escape($saldoPeriodoLabel); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($errorRows !== []): ?>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 150px;">Documento</th>
                                        <th>Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($errorRows, 0, 300) as $row): ?>
                                        <tr>
                                            <td>#<?php echo (int) ($row['id_documento_cobro'] ?? 0); ?></td>
                                            <td><?php echo msp2Escape((string) ($row['message'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if ($saldoFavorPeriodoErrorRows !== []): ?>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm table-bordered align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 150px;">Documento</th>
                                        <th style="width: 150px;">Pago</th>
                                        <th>Error saldo mes siguiente</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($saldoFavorPeriodoErrorRows, 0, 300) as $row): ?>
                                        <tr>
                                            <td>#<?php echo (int) ($row['id_documento_cobro'] ?? 0); ?></td>
                                            <td>#<?php echo (int) ($row['id_pago'] ?? 0); ?></td>
                                            <td><?php echo msp2Escape((string) ($row['message'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.js.iife.js"></script>
<script src="<?php echo msp2Escape(msp2Url('assets/msp_tour_pago_masivo.js')); ?>"></script>
<script>
(function () {
    const formPeriodoMes = document.getElementById('form_periodo_mes');
    const periodoInput = document.getElementById('periodo_ym');
    if (formPeriodoMes && periodoInput) {
        periodoInput.addEventListener('change', () => {
            formPeriodoMes.requestSubmit();
        });
    }

    const form = document.getElementById('form_pago_masivo_mensual');
    if (!form) {
        return;
    }

    const table = document.getElementById('tabla_pago_masivo_mes');
    const checkAll = document.getElementById('check_todos');
    const resumenSeleccionados = document.getElementById('resumen_seleccionados');
    const resumenMonto = document.getElementById('resumen_monto_lote');
    const validationMsg = document.getElementById('lote_validation_msg');

    const parseNumber = (raw) => {
        const value = String(raw ?? '').trim().replace(',', '.');
        if (value === '' || value === '-' || value === '+') {
            return 0;
        }
        const n = Number(value);
        if (!Number.isFinite(n)) {
            return NaN;
        }
        return Math.round(n * 100) / 100;
    };

    const fmtMoney = (value) => {
        return '$ ' + Number(value).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    const rowElements = () => Array.from(table.querySelectorAll('tbody tr[data-row-doc]'));

    const recalcRow = (row) => {
        const saldoCell = row.querySelector('.js-saldo');
        const ajusteInput = row.querySelector('.js-ajuste-input');
        const montoCell = row.querySelector('.js-monto-final');
        const check = row.querySelector('.js-row-check');

        if (!saldoCell || !ajusteInput || !montoCell || !check) {
            return { checked: false, valid: true, amount: 0 };
        }

        const saldo = parseNumber(saldoCell.dataset.saldo || '0');
        const ajuste = parseNumber(ajusteInput.value || '0');

        if (!Number.isFinite(ajuste)) {
            row.classList.add('table-danger');
            montoCell.textContent = 'Invalido';
            montoCell.dataset.montoFinal = '0';
            return { checked: check.checked, valid: false, amount: 0 };
        }

        const finalAmount = Math.round((saldo + ajuste) * 100) / 100;
        montoCell.dataset.montoFinal = finalAmount.toFixed(2);
        montoCell.textContent = fmtMoney(finalAmount);

        const valid = finalAmount > 0;
        row.classList.toggle('table-danger', check.checked && !valid);

        return { checked: check.checked, valid, amount: check.checked ? finalAmount : 0 };
    };

    const updateSummary = () => {
        let selectedCount = 0;
        let totalAmount = 0;
        let hasInvalid = false;

        rowElements().forEach((row) => {
            const result = recalcRow(row);
            if (result.checked) {
                selectedCount += 1;
                totalAmount = Math.round((totalAmount + result.amount) * 100) / 100;
                if (!result.valid) {
                    hasInvalid = true;
                }
            }
        });

        if (resumenSeleccionados) {
            resumenSeleccionados.textContent = String(selectedCount);
        }
        if (resumenMonto) {
            resumenMonto.textContent = fmtMoney(totalAmount);
        }

        if (checkAll) {
            const checks = rowElements().map((row) => row.querySelector('.js-row-check')).filter(Boolean);
            const allChecked = checks.length > 0 && checks.every((el) => el.checked);
            const anyChecked = checks.some((el) => el.checked);
            checkAll.checked = allChecked;
            checkAll.indeterminate = !allChecked && anyChecked;
        }

        return { selectedCount, hasInvalid };
    };

    if (checkAll) {
        checkAll.addEventListener('change', () => {
            rowElements().forEach((row) => {
                const check = row.querySelector('.js-row-check');
                if (check) {
                    check.checked = checkAll.checked;
                }
            });
            updateSummary();
        });
    }

    table.addEventListener('input', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        if (!target.classList.contains('js-ajuste-input')) {
            return;
        }
        updateSummary();
    });

    table.addEventListener('change', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) {
            return;
        }
        if (!target.classList.contains('js-row-check')) {
            return;
        }
        updateSummary();
    });

    form.addEventListener('submit', (event) => {
        const status = updateSummary();
        if (validationMsg) {
            validationMsg.classList.add('d-none');
            validationMsg.textContent = '';
        }

        if (status.selectedCount <= 0) {
            event.preventDefault();
            if (validationMsg) {
                validationMsg.textContent = 'Debes seleccionar al menos un documento.';
                validationMsg.classList.remove('d-none');
            }
            return;
        }

        if (status.hasInvalid) {
            event.preventDefault();
            if (validationMsg) {
                validationMsg.textContent = 'Hay filas seleccionadas con monto final invalido (debe ser mayor a 0).';
                validationMsg.classList.remove('d-none');
            }
        }
    });

    updateSummary();
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
