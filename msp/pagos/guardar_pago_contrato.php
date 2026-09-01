<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/vale_pago_email.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/vale_pago_pdf.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/comprobante_gastos_pdf.php';
require_once __DIR__ . '/pago_contrato_archivos_helper.php';
require_once __DIR__ . '/saldo_favor_periodo_helper.php';

msp2RequireAccess();

function msp2PagoRequireDompdf(): void
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        $autoloadCandidates = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];

        foreach ($autoloadCandidates as $autoloadPath) {
            if (is_file($autoloadPath)) {
                require_once $autoloadPath;
                break;
            }
        }
    }

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('DomPDF no está disponible en el proyecto.');
    }
}

function msp2PagoComprobanteFilename(string $numeroDocumento): string
{
    $safeDoc = preg_replace('/[^A-Za-z0-9\-_]+/', '_', trim($numeroDocumento));
    $safeDoc = is_string($safeDoc) ? trim($safeDoc, '_') : '';
    if ($safeDoc === '') {
        $safeDoc = 'documento';
    }

    return 'Comprobante_Gastos_' . $safeDoc . '.pdf';
}

function msp2PagoContratoAppendQuery(string $target, array $params): string
{
    if ($params === []) {
        return $target;
    }

    return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($params);
}

function msp2PagoContratoStorePdfDownloads(array $items): ?string
{
    if ($items === []) {
        return null;
    }

    $sessionKey = 'msp2_pago_contrato_pdf_downloads';
    if (!isset($_SESSION[$sessionKey]) || !is_array($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = [];
    }

    $now = time();
    foreach ($_SESSION[$sessionKey] as $token => $batch) {
        if (!is_array($batch) || (int) ($batch['expires_at'] ?? 0) < $now) {
            unset($_SESSION[$sessionKey][$token]);
        }
    }

    $token = bin2hex(random_bytes(16));
    $_SESSION[$sessionKey][$token] = [
        'expires_at' => $now + 900,
        'created_at' => $now,
        'items' => array_values($items),
    ];

    return $token;
}

function msp2PagoContratoPdfDownloadItem(string $type, array $pagoData, array $arrData, array $docData): array
{
    return [
        'type' => $type,
        'pago_data' => $pagoData,
        'arr_data' => $arrData,
        'doc_data' => $docData,
    ];
}

function msp2ResolvePagoContratoRedirect(): string
{
    $returnTo = trim((string) ($_POST['return_to'] ?? ''));
    if ($returnTo !== '' && preg_match('#^cobranza/gestionar\.php\?id_contrato=\d+(?:&return_to=[A-Za-z0-9_\-\.\[%\]=&]*)?$#', $returnTo) === 1) {
        return $returnTo;
    }
    $volverQuery = trim((string) ($_POST['volver_query'] ?? ''));
    $path = 'cobranza/registrar_pago_contrato.php';

    if ($volverQuery === '' || preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
        return $path;
    }

    return $path . '?' . $volverQuery;
}

function msp2PcFetchDocumentosDeudaContrato(PDO $conn, int $idContratoArriendo): array
{
    $hasFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $hasFechaTerminoLocal = msp2ColumnExists($conn, 'msp_contrato_locales', 'fecha_termino');
    $hasContratoLocales = msp2TableExists($conn, 'msp_contrato_locales');

    $condicionTerminoContrato = $hasFechaTerminoEfectiva
        ? '(ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionTerminoLocal = $hasFechaTerminoLocal
        ? '(cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)'
        : '1 = 1';
    $condicionExisteLocal = $hasContratoLocales
        ? "AND EXISTS (
                SELECT 1
                FROM dbo.msp_contrato_locales cl
                WHERE cl.id_contrato_arriendo = ca.id_contrato_arriendo
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoLocal
            )"
        : '';

    $sql = "SELECT
                dc.id_documento_cobro,
                COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_facturacion,
                CONVERT(CHAR(10), dc.fecha_vencimiento, 126) AS fecha_vencimiento,
                dc.saldo_pendiente,
                COALESCE(NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_contrato_arriendo
                FROM dbo.msp_contratos_arriendo ca
                WHERE ca.id_tienda = dc.id_tienda
                  AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                  AND $condicionTerminoContrato
                  AND ca.estado_contrato IN (1,2,3,4)
                  $condicionExisteLocal
                ORDER BY ca.fecha_inicio DESC, ca.id_contrato_arriendo DESC
            ) contrato_vigente
            WHERE dc.estado_documento IN (2,3)
              AND dc.saldo_pendiente > 0
              AND COALESCE(dc.id_contrato_arriendo, contrato_vigente.id_contrato_arriendo) = :id_contrato_arriendo
            ORDER BY
                dc.periodo_facturacion ASC,
                ISNULL(dc.fecha_vencimiento, dc.periodo_facturacion) ASC,
                dc.id_documento_cobro ASC";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

$redirectTarget = msp2ResolvePagoContratoRedirect();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaPagoRaw = trim((string) ($_POST['fecha_pago'] ?? ''));
$fechaPagoMinima = '2025-12-31';
$fechaPagoMaxima = date('Y-m-d');
[$montoValido, $montoPagado] = msp2NormalizeDecimalInput($_POST['monto_pagado'] ?? null, 2);
$medioPago = msp2NormalizeText($_POST['medio_pago'] ?? null);
$referenciaPago = msp2NormalizeText($_POST['referencia_pago'] ?? null);
$idBancoCheque = filter_input(INPUT_POST, 'id_banco_cheque', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$numeroCheque = msp2NormalizeText($_POST['numero_cheque'] ?? null);
$bancoCheque = msp2NormalizeText($_POST['banco_cheque'] ?? null);
$observaciones = msp2NormalizeText($_POST['observaciones'] ?? null);
$referenciaOperacion = msp2NormalizeText($_POST['referencia_operacion'] ?? null);
$enviarComprobante = trim((string) ($_POST['enviar_comprobante'] ?? '1')) !== '0';
$descargarPdfsPago = trim((string) ($_POST['descargar_pdfs_pago'] ?? '0')) === '1';
$demoEmailConfirmado = trim((string) ($_POST['demo_email_confirmado'] ?? '')) === '1';
$demoEmailOverrideRaw = trim((string) ($_POST['demo_email_override'] ?? ''));
$demoEmailOverride = filter_var($demoEmailOverrideRaw, FILTER_VALIDATE_EMAIL) !== false ? $demoEmailOverrideRaw : '';

if ($idArrendatario === false || $idArrendatario === null) {
    msp2SetFlash('warning', 'Debes seleccionar un arrendatario válido.');
    msp2Redirect($redirectTarget);
}
if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'Debes seleccionar un contrato válido.');
    msp2Redirect($redirectTarget);
}

$fechaPago = DateTime::createFromFormat('Y-m-d', $fechaPagoRaw);
if ($fechaPago === false || $fechaPago->format('Y-m-d') !== $fechaPagoRaw) {
    msp2SetFlash('warning', 'La fecha de pago no tiene un formato válido.');
    msp2Redirect($redirectTarget);
}
if ($fechaPagoRaw < $fechaPagoMinima) {
    msp2SetFlash('warning', 'La fecha de pago no puede ser anterior al 31-12-2025.');
    msp2Redirect($redirectTarget);
}
if ($fechaPagoRaw > $fechaPagoMaxima) {
    msp2SetFlash('warning', 'La fecha de pago no puede ser futura.');
    msp2Redirect($redirectTarget);
}
if (!$montoValido || $montoPagado === null || (float) $montoPagado <= 0) {
    msp2SetFlash('warning', 'Debes ingresar un monto pagado mayor a cero.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($medioPago) > 50) {
    msp2SetFlash('warning', 'El medio de pago supera el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($referenciaPago) > 100) {
    msp2SetFlash('warning', 'La referencia de pago supera el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($numeroCheque) > 100) {
    msp2SetFlash('warning', 'El N° de cheque supera el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($bancoCheque) > 100) {
    msp2SetFlash('warning', 'El banco supera el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones superan el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strlen($referenciaOperacion) > 100) {
    msp2SetFlash('warning', 'La referencia de operación supera el largo permitido.');
    msp2Redirect($redirectTarget);
}
if (mb_strtoupper($medioPago, 'UTF-8') === 'CHEQUE') {
    if ($numeroCheque === '') {
        $numeroCheque = $referenciaPago;
    }
    if ($numeroCheque === '') {
        msp2SetFlash('warning', 'Para pagos con cheque debes ingresar el N° de cheque.');
        msp2Redirect($redirectTarget);
    }
    if ($bancoCheque === '' && ($idBancoCheque === false || $idBancoCheque === null)) {
        msp2SetFlash('warning', 'Para pagos con cheque debes seleccionar un banco.');
        msp2Redirect($redirectTarget);
    }
    $referenciaPago = $numeroCheque;
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_pagos',
        'msp_contratos_arriendo',
        'msp_arrendatarios',
        'msp_tiendas',
        'msp_pago_contrato_operaciones',
        'msp_pago_contrato_operacion_detalle',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla requerida `' . $tableName . '`.');
        }
    }
    if (!msp2ProcedureExists($conn, 'msp_registrar_pago_documento')) {
        throw new RuntimeException('No existe el procedimiento dbo.msp_registrar_pago_documento.');
    }
} catch (Throwable $validationException) {
    $msg = $validationException->getMessage();
    if (str_contains($msg, 'msp_pago_contrato_operaciones') || str_contains($msg, 'msp_pago_contrato_operacion_detalle')) {
        msp2SetFlash('danger', 'Falta la estructura de pago general por contrato. Ejecuta `db/patch_pago_contrato_operacion_general.sql`.');
    } else {
        msp2SetFlash('danger', 'No fue posible validar la estructura base para pago por contrato.');
    }
    msp2Redirect($redirectTarget);
}

try {
    $stmtContrato = $conn->prepare(
        "SELECT TOP 1
            c.id_contrato_arriendo,
            c.id_arrendatario
         FROM dbo.msp_contratos_arriendo c
         WHERE c.id_contrato_arriendo = :id_contrato_arriendo
           AND c.id_arrendatario = :id_arrendatario"
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->bindValue(':id_arrendatario', (int) $idArrendatario, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contratoRow = $stmtContrato->fetch();

    if (!is_array($contratoRow)) {
        msp2SetFlash('warning', 'El contrato seleccionado no corresponde al arrendatario.');
        msp2Redirect($redirectTarget);
    }
} catch (PDOException) {
    msp2SetFlash('danger', 'No fue posible validar el contrato seleccionado.');
    msp2Redirect($redirectTarget);
}

try {
    $documentosDeuda = msp2PcFetchDocumentosDeudaContrato($conn, (int) $idContratoArriendo);
} catch (PDOException) {
    msp2SetFlash('danger', 'No fue posible obtener la deuda del contrato.');
    msp2Redirect($redirectTarget);
}

if ($documentosDeuda === []) {
    msp2SetFlash('warning', 'El contrato no tiene documentos pendientes para pagar.');
    msp2Redirect($redirectTarget);
}

$montoRestante = round((float) $montoPagado, 2);
$totalAplicado = 0.0;
$totalExcedente = 0.0;
$pagosProcesados = [];
$saldoFavorPeriodoErrores = [];
$pdfArchiveItems = [];
$pdfDownloadItems = [];
$enTransaccion = false;
$pagoGuardadoOk = false;
$mensajePagoExito = '';
$notasPago = [];
$operacionTag = $referenciaOperacion !== '' ? $referenciaOperacion : ('Lote contrato #' . (int) $idContratoArriendo . ' ' . date('YmdHis'));
$obsBase = $observaciones !== '' ? $observaciones : 'Pago único por contrato';
if (mb_strtoupper($medioPago, 'UTF-8') === 'CHEQUE') {
    $obsBase .= ' | Cheque';
    if ($bancoCheque !== '') {
        $obsBase .= ' | Banco: ' . $bancoCheque;
    }
}
$obsOperacion = trim($obsBase . ' | ' . $operacionTag);
$idPagoContratoOperacion = 0;

try {
    $conn->beginTransaction();
    $enTransaccion = true;

    $idUsuario = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null;
    $stmtOperacion = $conn->prepare(
        "INSERT INTO dbo.msp_pago_contrato_operaciones (
            id_arrendatario,
            id_contrato_arriendo,
            fecha_pago,
            monto_total_pagado,
            monto_total_aplicado,
            monto_total_excedente,
            monto_total_no_imputado,
            total_documentos,
            medio_pago,
            referencia_pago,
            referencia_operacion,
            observaciones,
            estado_operacion,
            id_usuario,
            fecha_registro,
            updated_at
         )
         VALUES (
            :id_arrendatario,
            :id_contrato_arriendo,
            :fecha_pago,
            :monto_total_pagado,
            0,
            0,
            0,
            0,
            :medio_pago,
            :referencia_pago,
            :referencia_operacion,
            :observaciones,
            1,
            :id_usuario,
            SYSDATETIME(),
            SYSDATETIME()
         )"
    );
    $stmtOperacion->bindValue(':id_arrendatario', (int) $idArrendatario, PDO::PARAM_INT);
    $stmtOperacion->bindValue(':id_contrato_arriendo', (int) $idContratoArriendo, PDO::PARAM_INT);
    $stmtOperacion->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
    $stmtOperacion->bindValue(':monto_total_pagado', round((float) $montoPagado, 2), PDO::PARAM_STR);
    $stmtOperacion->bindValue(':medio_pago', $medioPago === '' ? null : $medioPago, $medioPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmtOperacion->bindValue(':referencia_pago', $referenciaPago === '' ? null : $referenciaPago, $referenciaPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmtOperacion->bindValue(':referencia_operacion', $operacionTag === '' ? null : $operacionTag, $operacionTag === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmtOperacion->bindValue(':observaciones', $observaciones === '' ? null : $observaciones, $observaciones === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmtOperacion->bindValue(':id_usuario', $idUsuario, $idUsuario !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmtOperacion->execute();
    $idPagoContratoOperacion = (int) $conn->lastInsertId();
    if ($idPagoContratoOperacion <= 0) {
        throw new RuntimeException('No fue posible registrar la cabecera del pago por contrato.');
    }

    $stmtPago = $conn->prepare(
        'EXEC dbo.msp_registrar_pago_documento
            @id_documento_cobro = :id_documento_cobro,
            @fecha_pago = :fecha_pago,
            @monto_pagado = :monto_pagado,
            @medio_pago = :medio_pago,
            @referencia_pago = :referencia_pago,
            @observaciones = :observaciones,
            @detalle_conceptos_json = :detalle_conceptos_json'
    );
    $stmtOperacionDetalle = $conn->prepare(
        "INSERT INTO dbo.msp_pago_contrato_operacion_detalle (
            id_pago_contrato_operacion,
            orden_aplicacion,
            id_pago,
            id_documento_cobro,
            saldo_pendiente_original,
            monto_intentado,
            monto_aplicado,
            monto_excedente,
            monto_consumido,
            monto_restante_luego,
            fecha_registro
         )
         VALUES (
            :id_pago_contrato_operacion,
            :orden_aplicacion,
            :id_pago,
            :id_documento_cobro,
            :saldo_pendiente_original,
            :monto_intentado,
            :monto_aplicado,
            :monto_excedente,
            :monto_consumido,
            :monto_restante_luego,
            SYSDATETIME()
         )"
    );

    $totalDocs = count($documentosDeuda);
    foreach ($documentosDeuda as $index => $doc) {
        if ($montoRestante <= 0.005) {
            break;
        }

        $idDocumentoCobro = (int) ($doc['id_documento_cobro'] ?? 0);
        $saldoPendiente = round((float) ($doc['saldo_pendiente'] ?? 0), 2);
        if ($idDocumentoCobro <= 0 || $saldoPendiente <= 0) {
            continue;
        }

        $esUltimoDocumento = ($index === ($totalDocs - 1));
        $montoIntento = $esUltimoDocumento
            ? round($montoRestante, 2)
            : round(min($montoRestante, $saldoPendiente), 2);

        if ($montoIntento <= 0.005) {
            continue;
        }

        $stmtPago->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
        $stmtPago->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
        $stmtPago->bindValue(':monto_pagado', $montoIntento, PDO::PARAM_STR);
        $stmtPago->bindValue(':medio_pago', $medioPago === '' ? null : $medioPago, $medioPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtPago->bindValue(':referencia_pago', $referenciaPago === '' ? null : $referenciaPago, $referenciaPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtPago->bindValue(':observaciones', $obsOperacion === '' ? null : $obsOperacion, $obsOperacion === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtPago->bindValue(':detalle_conceptos_json', null, PDO::PARAM_NULL);
        $stmtPago->execute();

        $resultado = $stmtPago->fetch() ?: [];
        $idPagoGenerado = isset($resultado['id_pago_generado']) ? (int) $resultado['id_pago_generado'] : 0;
        $montoAplicado = isset($resultado['monto_aplicado_documento'])
            ? round((float) $resultado['monto_aplicado_documento'], 2)
            : round(min($montoIntento, $saldoPendiente), 2);
        $montoExcedente = isset($resultado['monto_saldo_favor_generado'])
            ? round((float) $resultado['monto_saldo_favor_generado'], 2)
            : round(max(0, $montoIntento - $montoAplicado), 2);

        $consumido = round($montoAplicado + $montoExcedente, 2);
        if ($consumido <= 0) {
            $consumido = $montoIntento;
        }

        $montoRestante = round(max(0, $montoRestante - $consumido), 2);
        $totalAplicado = round($totalAplicado + $montoAplicado, 2);
        $totalExcedente = round($totalExcedente + $montoExcedente, 2);

        $ordenAplicacion = count($pagosProcesados) + 1;
        $stmtOperacionDetalle->bindValue(':id_pago_contrato_operacion', $idPagoContratoOperacion, PDO::PARAM_INT);
        $stmtOperacionDetalle->bindValue(':orden_aplicacion', $ordenAplicacion, PDO::PARAM_INT);
        $stmtOperacionDetalle->bindValue(':id_pago', $idPagoGenerado, PDO::PARAM_INT);
        $stmtOperacionDetalle->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
        $stmtOperacionDetalle->bindValue(':saldo_pendiente_original', $saldoPendiente, PDO::PARAM_STR);
        $stmtOperacionDetalle->bindValue(':monto_intentado', $montoIntento, PDO::PARAM_STR);
        $stmtOperacionDetalle->bindValue(':monto_aplicado', $montoAplicado, PDO::PARAM_STR);
        $stmtOperacionDetalle->bindValue(':monto_excedente', $montoExcedente, PDO::PARAM_STR);
        $stmtOperacionDetalle->bindValue(':monto_consumido', $consumido, PDO::PARAM_STR);
        $stmtOperacionDetalle->bindValue(':monto_restante_luego', $montoRestante, PDO::PARAM_STR);
        $stmtOperacionDetalle->execute();

        if ($montoExcedente > 0.005) {
            $syncOk = msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
                $conn,
                $idPagoGenerado,
                $idDocumentoCobro,
                $montoExcedente,
                $fechaPagoRaw
            );
            if (!$syncOk) {
                $saldoFavorPeriodoErrores[] = $idDocumentoCobro;
            }
        }

        $pagosProcesados[] = [
            'id_documento_cobro' => $idDocumentoCobro,
            'id_pago' => $idPagoGenerado,
            'monto_aplicado' => $montoAplicado,
            'monto_excedente' => $montoExcedente,
        ];
    }

    if ($pagosProcesados === []) {
        throw new RuntimeException('No fue posible procesar el pago sobre documentos con saldo pendiente.');
    }

    $stmtOperacionTotales = $conn->prepare(
        "UPDATE dbo.msp_pago_contrato_operaciones
         SET monto_total_aplicado = :monto_total_aplicado,
             monto_total_excedente = :monto_total_excedente,
             monto_total_no_imputado = :monto_total_no_imputado,
             total_documentos = :total_documentos,
             updated_at = SYSDATETIME()
         WHERE id_pago_contrato_operacion = :id_pago_contrato_operacion"
    );
    $stmtOperacionTotales->bindValue(':monto_total_aplicado', $totalAplicado, PDO::PARAM_STR);
    $stmtOperacionTotales->bindValue(':monto_total_excedente', $totalExcedente, PDO::PARAM_STR);
    $stmtOperacionTotales->bindValue(':monto_total_no_imputado', $montoRestante, PDO::PARAM_STR);
    $stmtOperacionTotales->bindValue(':total_documentos', count($pagosProcesados), PDO::PARAM_INT);
    $stmtOperacionTotales->bindValue(':id_pago_contrato_operacion', $idPagoContratoOperacion, PDO::PARAM_INT);
    $stmtOperacionTotales->execute();

    msp2SyncHistoricalDebt($conn, (int) $idContratoArriendo);

    if ($enTransaccion && $conn->inTransaction()) {
        $conn->commit();
        $enTransaccion = false;
    }

    $mensajePagoExito = 'Pago por contrato registrado. Documentos afectados: '
        . count($pagosProcesados)
        . '. Aplicado a deuda: $ '
        . number_format($totalAplicado, 2, ',', '.')
        . '.';
    if ($idPagoContratoOperacion > 0) {
        $mensajePagoExito .= ' Operación general #' . $idPagoContratoOperacion . '.';
    }

    if ($totalExcedente > 0.005) {
        $mensajePagoExito .= ' Excedente enviado a saldo a favor: $ ' . number_format($totalExcedente, 2, ',', '.') . '.';
    }
    if ($montoRestante > 0.005) {
        $mensajePagoExito .= ' Monto no imputado: $ ' . number_format($montoRestante, 2, ',', '.') . '.';
    }

    if ($saldoFavorPeriodoErrores !== []) {
        $notasPago[] = 'Algunos excedentes no se reflejaron automáticamente en período siguiente. Verifica que ese período exista y esté en estado Borrador.';
    }
    $pagoGuardadoOk = true;
} catch (PDOException $exception) {
    if ($enTransaccion && $conn->inTransaction()) {
        $conn->rollBack();
        $enTransaccion = false;
    }

    $message = $exception->getMessage();
    if (str_contains($message, '50061') || str_contains($message, '50064')) {
        msp2SetFlash('warning', 'Uno de los documentos ya no existe o no es válido.');
    } elseif (str_contains($message, '50062')) {
        msp2SetFlash('warning', 'La fecha de pago no tiene un formato válido.');
    } elseif (str_contains($message, '50063')) {
        msp2SetFlash('warning', 'Debes ingresar un monto pagado mayor a cero.');
    } elseif (str_contains($message, '50065')) {
        msp2SetFlash('warning', 'Uno de los documentos ya no tiene saldo pendiente.');
    } elseif (str_contains($message, '50041')) {
        msp2SetFlash('warning', 'No se pueden registrar pagos sobre documentos anulados.');
    } elseif (str_contains($message, 'has too many arguments specified')) {
        msp2SetFlash('danger', 'La base de datos no tiene habilitado pagos por concepto. Ejecuta `db/patch_pagos_por_concepto.sql`.');
    } else {
        msp2SetFlash('danger', 'No fue posible registrar el pago por contrato. Revisa la estructura de la base o intenta nuevamente.');
    }
} catch (Throwable $exception) {
    if ($enTransaccion && $conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', 'No fue posible registrar el pago por contrato.');
}

try {
    if ($pagoGuardadoOk && $pagosProcesados !== []) {
        $mailCfg = mspMailConfig();
        $demoTo = trim((string) ($mailCfg['demo']['to'] ?? ''));
        $isDemoMode = $demoTo !== '' && filter_var($demoTo, FILTER_VALIDATE_EMAIL) !== false;
        $destinoDemo = $demoEmailOverride !== '' ? $demoEmailOverride : $demoTo;
        $envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);

        $stmtMailDoc = $conn->prepare(
            "SELECT
                a.id_arrendatario,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario,
                a.rut,
                cor.correo AS correo_principal,
                CONVERT(CHAR(7), dc.periodo_facturacion, 126) AS periodo_ym,
                COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                COALESCE(NULLIF(loc.locales_contrato, ''), '') AS locales_contrato,
                dc.saldo_pendiente
             FROM dbo.msp_documentos_cobro dc
             INNER JOIN dbo.msp_tiendas t
                ON t.id_tienda = dc.id_tienda
             OUTER APPLY (
                SELECT TOP 1 ca_loc.id_contrato_arriendo
                FROM dbo.msp_contratos_arriendo ca_loc
                WHERE ca_loc.id_contrato_arriendo = dc.id_contrato_arriendo
                   OR (
                        dc.id_contrato_arriendo IS NULL
                    AND ca_loc.id_tienda = dc.id_tienda
                    AND ca_loc.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                    AND (ca_loc.fecha_termino_efectiva IS NULL OR ca_loc.fecha_termino_efectiva >= dc.periodo_facturacion)
                    AND ca_loc.estado_contrato IN (1,2,3,4)
                   )
                ORDER BY ca_loc.fecha_inicio DESC, ca_loc.id_contrato_arriendo DESC
             ) contrato_vigente
             LEFT JOIN dbo.msp_contratos_arriendo ca_doc
                ON ca_doc.id_contrato_arriendo = contrato_vigente.id_contrato_arriendo
             INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = COALESCE(ca_doc.id_arrendatario, t.id_arrendatario)
             OUTER APPLY (
                SELECT
                    STUFF((
                        SELECT N' / ' + l.cdo_local
                        FROM dbo.msp_contrato_locales cl
                        INNER JOIN dbo.msp_locales l
                            ON l.id_local = cl.id_local
                        WHERE cl.id_contrato_arriendo = contrato_vigente.id_contrato_arriendo
                          AND cl.estado_relacion IN (1,2)
                          AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                          AND (
                                cl.fecha_termino IS NULL
                                OR cl.fecha_termino >= dc.periodo_facturacion
                                OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(cl.fecha_termino), MONTH(cl.fecha_termino), 1)) = dc.periodo_facturacion
                          )
                        ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local') . "
                        FOR XML PATH(''), TYPE
                    ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS locales_contrato
             ) loc
             OUTER APPLY (
                SELECT TOP 1 ac.correo
                FROM dbo.msp_arrendatarios_correos ac
                WHERE ac.id_arrendatario = a.id_arrendatario
                ORDER BY ac.es_principal DESC, ac.id_arrendatario_correo ASC
             ) cor
             WHERE dc.id_documento_cobro = :id_documento_cobro"
        );
        $stmtConceptosPago = $conn->prepare(
            "SELECT
                base.id_tipo_item_documento,
                base.nombre_item,
                base.codigo_item,
                base.monto,
                det.detalle_items
             FROM (
                SELECT
                    pdc.id_tipo_item_documento,
                    tid.nombre_item,
                    tid.codigo_item,
                    ROUND(SUM(pdc.monto_aplicado), 2) AS monto
                 FROM dbo.msp_pagos_detalle_concepto pdc
                 INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = pdc.id_tipo_item_documento
                 WHERE pdc.id_pago = :id_pago
                   AND pdc.id_documento_cobro = :id_documento_cobro_base
                 GROUP BY
                    pdc.id_tipo_item_documento,
                    tid.nombre_item,
                    tid.codigo_item
             ) base
             OUTER APPLY (
                SELECT STUFF((
                    SELECT N' | ' + LEFT(LTRIM(RTRIM(ISNULL(d.descripcion_item, N''))), 120)
                    FROM dbo.msp_documentos_cobro_detalle d
                    WHERE d.id_documento_cobro = :id_documento_cobro_det
                      AND d.id_tipo_item_documento = base.id_tipo_item_documento
                      AND NULLIF(LTRIM(RTRIM(ISNULL(d.descripcion_item, N''))), N'') IS NOT NULL
                    ORDER BY d.orden_item
                    FOR XML PATH(''), TYPE
                ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS detalle_items
             ) det"
        );

        foreach ($pagosProcesados as $pagoProcesado) {
            $idDocumentoCobro = (int) ($pagoProcesado['id_documento_cobro'] ?? 0);
            $idPagoGenerado = (int) ($pagoProcesado['id_pago'] ?? 0);
            $montoAplicadoDoc = round((float) ($pagoProcesado['monto_aplicado'] ?? 0), 2);
            $montoExcedenteDoc = round((float) ($pagoProcesado['monto_excedente'] ?? 0), 2);
            $montoIntentoDoc = round($montoAplicadoDoc + $montoExcedenteDoc, 2);
            if ($idDocumentoCobro <= 0 || $montoIntentoDoc <= 0.005) {
                continue;
            }

            $stmtMailDoc->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
            $stmtMailDoc->execute();
            $mailRow = $stmtMailDoc->fetch();
            if (!is_array($mailRow)) {
                continue;
            }

            $correoReal = trim((string) ($mailRow['correo_principal'] ?? ''));
            $correoValido = filter_var($correoReal, FILTER_VALIDATE_EMAIL) !== false;
            $debeEnviar = $enviarComprobante
                && ($isDemoMode ? $demoEmailConfirmado : ($envioArrendatariosHabilitado && $correoValido && $correoReal !== ''));

            $detalleParaDocumento = [];
            if ($idPagoGenerado > 0) {
                $stmtConceptosPago->bindValue(':id_pago', $idPagoGenerado, PDO::PARAM_INT);
                $stmtConceptosPago->bindValue(':id_documento_cobro_base', $idDocumentoCobro, PDO::PARAM_INT);
                $stmtConceptosPago->bindValue(':id_documento_cobro_det', $idDocumentoCobro, PDO::PARAM_INT);
                $stmtConceptosPago->execute();
                foreach ($stmtConceptosPago->fetchAll() ?: [] as $conceptoRow) {
                    $detalleParaDocumento[] = [
                        'id_tipo_item_documento' => (int) ($conceptoRow['id_tipo_item_documento'] ?? 0),
                        'nombre_item' => trim((string) ($conceptoRow['nombre_item'] ?? '')),
                        'codigo_item' => trim((string) ($conceptoRow['codigo_item'] ?? '')),
                        'monto' => (float) ($conceptoRow['monto'] ?? 0),
                        'detalle_items' => trim((string) ($conceptoRow['detalle_items'] ?? '')),
                    ];
                }
            }

            $pagoDataEmail = [
                'id_pago' => $idPagoGenerado,
                'fecha_pago' => $fechaPagoRaw,
                'monto_pagado' => $montoIntentoDoc,
                'monto_aplicado' => $montoAplicadoDoc,
                'saldo_favor_aplicado' => 0.0,
                'medio_pago' => $medioPago,
                'referencia_pago' => $referenciaPago,
                'banco' => $bancoCheque,
                'observaciones' => $observaciones,
                'detalle_conceptos' => $detalleParaDocumento,
            ];
            $arrDataEmail = [
                'nombre_arrendatario' => (string) ($mailRow['nombre_arrendatario'] ?? ''),
                'rut' => (string) ($mailRow['rut'] ?? ''),
                'correo_principal' => $correoReal,
            ];
            $docDataEmail = [
                'numero_documento' => (string) ($mailRow['numero_documento'] ?? ''),
                'periodo_ym' => (string) ($mailRow['periodo_ym'] ?? ''),
                'locales_contrato' => (string) ($mailRow['locales_contrato'] ?? ''),
                'saldo_pendiente_nuevo' => (float) ($mailRow['saldo_pendiente'] ?? 0),
            ];

            $attachComprobantePdf = ((float) ($docDataEmail['saldo_pendiente_nuevo'] ?? 0)) <= 0.005;

            $baseArchiveItem = [
                'id_pago' => $idPagoGenerado,
                'id_documento_cobro' => $idDocumentoCobro,
                'id_contrato_arriendo' => (int) $idContratoArriendo,
                'id_arrendatario' => (int) $idArrendatario,
            ];
            $valeArchiveItem = $baseArchiveItem + msp2PagoContratoPdfDownloadItem(
                'vale_pago',
                $pagoDataEmail,
                $arrDataEmail,
                $docDataEmail
            );
            $pdfArchiveItems[] = $valeArchiveItem;
            if ($attachComprobantePdf) {
                $pdfArchiveItems[] = $baseArchiveItem + msp2PagoContratoPdfDownloadItem(
                    'comprobante_gastos',
                    $pagoDataEmail,
                    $arrDataEmail,
                    $docDataEmail
                );
            }

            if ($descargarPdfsPago) {
                $pdfDownloadItems[] = $valeArchiveItem;
                if ($attachComprobantePdf) {
                    $pdfDownloadItems[] = $baseArchiveItem + msp2PagoContratoPdfDownloadItem(
                        'comprobante_gastos',
                        $pagoDataEmail,
                        $arrDataEmail,
                        $docDataEmail
                    );
                }
            }

            if (!$debeEnviar) {
                continue;
            }

            try {
                [$subject, $bodyHtml, $altText] = rpBuildValeEmailContent(
                    $pagoDataEmail,
                    $arrDataEmail,
                    $docDataEmail
                );

                $comprobantePdfBytes = null;
                $comprobantePdfName = '';
                if ($attachComprobantePdf) {
                    try {
                        [$tplFilename, $tplHtml] = rpBuildComprobanteGastosPdfPayload(
                            $pagoDataEmail,
                            $arrDataEmail,
                            $docDataEmail
                        );
                        msp2PagoRequireDompdf();
                        $pdfOptions = new \Dompdf\Options();
                        $pdfOptions->set('isRemoteEnabled', true);
                        $pdfOptions->set('isHtml5ParserEnabled', true);
                        $pdf = new \Dompdf\Dompdf($pdfOptions);
                        $pdf->setPaper('A4', 'portrait');
                        $pdf->loadHtml($tplHtml, 'UTF-8');
                        $pdf->render();

                        $comprobantePdfBytes = $pdf->output();
                        $comprobantePdfName = trim((string) $tplFilename) !== ''
                            ? (string) $tplFilename
                            : msp2PagoComprobanteFilename((string) ($docDataEmail['numero_documento'] ?? ''));

                        $adjuntoNotice = '<div style="font-family:Arial,sans-serif;background:#e8f5e9;border:1px solid #81c784;'
                            . 'border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#1b5e20;">'
                            . 'Se adjunta <strong>Comprobante de Gastos en PDF</strong>.'
                            . '</div>';
                        $bodyHtml = (string) preg_replace('/<body([^>]*)>/i', '<body$1>' . $adjuntoNotice, $bodyHtml, 1);
                        $altText .= PHP_EOL . 'Adjunto: Comprobante de Gastos en PDF.';
                    } catch (Throwable) {
                        $comprobantePdfBytes = null;
                        $comprobantePdfName = '';
                    }
                }

                $destino = $isDemoMode ? $destinoDemo : $correoReal;
                if (trim($destino) === '') {
                    continue;
                }
                $destinoNombre = $isDemoMode ? 'Demo MSP' : (string) ($mailRow['nombre_arrendatario'] ?? '');

                $mail = mspMailBuildSmtp();
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $bodyHtml;
                $mail->AltBody = $altText;
                $mail->addAddress($destino, $destinoNombre);
                if (is_string($comprobantePdfBytes) && $comprobantePdfBytes !== '') {
                    $mail->addStringAttachment($comprobantePdfBytes, $comprobantePdfName, 'base64', 'application/pdf');
                }
                $mail->send();
            } catch (Throwable) {
                // Envío individual best-effort: no debe impedir las descargas ni otros comprobantes.
            }
        }
    }
} catch (Throwable) {
    // Envío de correo es best-effort: no interrumpe el flujo.
}

if ($pagoGuardadoOk && $pdfArchiveItems !== []) {
    try {
        $archiveResult = msp2PagoContratoArchivosArchiveMany($conn, $pdfArchiveItems);
        if (($archiveResult['errors'] ?? []) !== []) {
            $notasPago[] = 'Hubo respaldos PDF que no se pudieron guardar en el servidor.';
        }
    } catch (Throwable) {
        $notasPago[] = 'No fue posible guardar el respaldo PDF en el servidor.';
    }
}

if ($pagoGuardadoOk && $descargarPdfsPago && $pdfDownloadItems !== []) {
    try {
        $downloadToken = msp2PagoContratoStorePdfDownloads($pdfDownloadItems);
        if (is_string($downloadToken) && $downloadToken !== '') {
            $redirectTarget = msp2PagoContratoAppendQuery($redirectTarget, [
                'pdf_download_token' => $downloadToken,
            ]);
        }
    } catch (Throwable) {
        // La descarga de PDFs es best-effort; el pago ya quedó registrado.
    }
}

if ($pagoGuardadoOk) {
    $mensajeFinal = $mensajePagoExito !== '' ? $mensajePagoExito : 'Pago por contrato registrado.';
    if ($notasPago !== []) {
        $mensajeFinal .= ' Nota: ' . implode(' ', $notasPago);
    }
    msp2SetFlash('success', $mensajeFinal);
}

msp2Redirect($redirectTarget);
