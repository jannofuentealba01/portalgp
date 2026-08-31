<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/vale_pago_email.php';
require_once dirname(__DIR__) . '/cobranza/mail_templates/comprobante_gastos_pdf.php';
require_once __DIR__ . '/saldo_favor_periodo_helper.php';

msp2RequireAccess();

function msp2ResolvePagoRedirect(): string
{
    $volverA = trim((string) ($_POST['volver_a'] ?? ''));
    $volverQuery = trim((string) ($_POST['volver_query'] ?? ''));

    $path = 'pagos/index.php';
    if ($volverA === 'documentos_cobro') {
        $path = 'documentos_cobro/index.php';
    } elseif ($volverA === 'cobranza_registrar_pago') {
        $path = 'cobranza/registrar_pago.php';
    }

    if ($volverQuery === '' || preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
        return $path;
    }

    return $path . '?' . $volverQuery;
}

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

$redirectTarget = msp2ResolvePagoRedirect();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectTarget);
}

$idDocumentoCobro = filter_input(INPUT_POST, 'id_documento_cobro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaPagoRaw = trim((string) ($_POST['fecha_pago'] ?? ''));
[$montoValido, $montoPagado] = msp2NormalizeDecimalInput($_POST['monto_pagado'] ?? null, 2);
$medioPago = msp2NormalizeText($_POST['medio_pago'] ?? null);
$referenciaPago = msp2NormalizeText($_POST['referencia_pago'] ?? null);
$idBancoCheque = filter_input(INPUT_POST, 'id_banco_cheque', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$numeroCheque = msp2NormalizeText($_POST['numero_cheque'] ?? null);
$bancoCheque = msp2NormalizeText($_POST['banco_cheque'] ?? null);
$observaciones = msp2NormalizeText($_POST['observaciones'] ?? null);
$detalleConceptosRaw = trim((string) ($_POST['detalle_conceptos_json'] ?? ''));
$detalleConceptosJson = null;
$enviarComprobante = trim((string) ($_POST['enviar_comprobante'] ?? '1')) !== '0';
$usarSaldoFavor = trim((string) ($_POST['usar_saldo_favor'] ?? '0')) === '1';
[$montoSaldoFavorValido, $montoSaldoFavor] = msp2NormalizeDecimalInput($_POST['monto_saldo_favor'] ?? null, 2);
$montoSaldoFavor = $usarSaldoFavor && $montoSaldoFavor !== null ? (float) $montoSaldoFavor : 0.0;
$demoEmailConfirmado = trim((string) ($_POST['demo_email_confirmado'] ?? '')) === '1';
$demoEmailOverrideRaw = trim((string) ($_POST['demo_email_override'] ?? ''));
$demoEmailOverride = filter_var($demoEmailOverrideRaw, FILTER_VALIDATE_EMAIL) !== false ? $demoEmailOverrideRaw : '';

if ($idDocumentoCobro === false || $idDocumentoCobro === null) {
    msp2SetFlash('warning', 'Debes seleccionar un documento válido.');
    msp2Redirect($redirectTarget);
}

$fechaPago = DateTime::createFromFormat('Y-m-d', $fechaPagoRaw);
if ($fechaPago === false || $fechaPago->format('Y-m-d') !== $fechaPagoRaw) {
    msp2SetFlash('warning', 'La fecha de pago no tiene un formato valido.');
    msp2Redirect($redirectTarget);
}

if (!$montoValido || $montoPagado === null || (float) $montoPagado <= 0) {
    msp2SetFlash('warning', 'Debes ingresar un monto pagado mayor a cero.');
    msp2Redirect($redirectTarget);
}

if ($usarSaldoFavor && (!$montoSaldoFavorValido || $montoSaldoFavor <= 0)) {
    msp2SetFlash('warning', 'Debes indicar un monto válido de saldo a favor.');
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

if ($medioPago === 'Cheque') {
    if ($numeroCheque === '') {
        msp2SetFlash('warning', 'Para pagos con cheque debes ingresar N° cheque y banco.');
        msp2Redirect($redirectTarget);
    }

    $tablaBancosExiste = false;
    try {
        $tablaBancosExiste = msp2TableExists($conn, 'msp_bancos');
    } catch (PDOException) {
        $tablaBancosExiste = false;
    }

    if ($tablaBancosExiste) {
        if ($idBancoCheque === false || $idBancoCheque === null) {
            msp2SetFlash('warning', 'Debes seleccionar un banco válido para pago con cheque.');
            msp2Redirect($redirectTarget);
        }
        try {
            $stmtBanco = $conn->prepare(
                'SELECT TOP 1 nombre_banco
                 FROM dbo.msp_bancos
                 WHERE id_banco = :id_banco
                   AND activo = 1'
            );
            $stmtBanco->bindValue(':id_banco', $idBancoCheque, PDO::PARAM_INT);
            $stmtBanco->execute();
            $nombreBanco = $stmtBanco->fetchColumn();
            if (!is_string($nombreBanco) || trim($nombreBanco) === '') {
                msp2SetFlash('warning', 'El banco seleccionado no existe o está inactivo.');
                msp2Redirect($redirectTarget);
            }
            $bancoCheque = trim($nombreBanco);
        } catch (PDOException) {
            msp2SetFlash('danger', 'No fue posible validar el banco seleccionado.');
            msp2Redirect($redirectTarget);
        }
    }

    if ($bancoCheque === '') {
        msp2SetFlash('warning', 'Para pagos con cheque debes ingresar N° cheque y banco.');
        msp2Redirect($redirectTarget);
    }

    $referenciaPago = $numeroCheque;
}

if (mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones superan el largo permitido.');
    msp2Redirect($redirectTarget);
}

$montoObjetivoConceptos = (float) $montoPagado;
try {
    $stmtSaldoDoc = $conn->prepare(
        'SELECT dc.saldo_pendiente
         FROM dbo.msp_documentos_cobro dc
         WHERE dc.id_documento_cobro = :id_documento_cobro'
    );
    $stmtSaldoDoc->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmtSaldoDoc->execute();
    $saldoDoc = $stmtSaldoDoc->fetchColumn();
    if ($saldoDoc !== false && $saldoDoc !== null) {
        $saldoPendiente = round((float) $saldoDoc, 2);
        if ($saldoPendiente >= 0 && $saldoPendiente < $montoObjetivoConceptos) {
            $montoObjetivoConceptos = $saldoPendiente;
        }
    }
} catch (PDOException) {
    // La validacion fina la refuerza el procedimiento SQL.
}

if ($detalleConceptosRaw !== '') {
    try {
        $detalleDecode = json_decode($detalleConceptosRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        msp2SetFlash('warning', 'La distribución por concepto no tiene un formato válido.');
        msp2Redirect($redirectTarget);
    }

    if (!is_array($detalleDecode) || $detalleDecode === []) {
        msp2SetFlash('warning', 'Debes indicar al menos un concepto con monto para registrar el pago.');
        msp2Redirect($redirectTarget);
    }

    $detalleNormalizado = [];
    $sumaConceptos = 0.0;

    foreach ($detalleDecode as $item) {
        if (!is_array($item)) {
            msp2SetFlash('warning', 'La distribución por concepto contiene filas inválidas.');
            msp2Redirect($redirectTarget);
        }

        $idTipo = isset($item['id_tipo_item_documento']) ? (int) $item['id_tipo_item_documento'] : 0;
        $montoConceptoRaw = $item['monto'] ?? null;
        if (is_int($montoConceptoRaw) || is_float($montoConceptoRaw)) {
            $montoConceptoRaw = (string) $montoConceptoRaw;
        } elseif (!is_string($montoConceptoRaw) && $montoConceptoRaw !== null) {
            $montoConceptoRaw = null;
        }
        [$montoConceptoValido, $montoConcepto] = msp2NormalizeDecimalInput($montoConceptoRaw, 2);

        if ($idTipo <= 0 || !$montoConceptoValido || $montoConcepto === null || (float) $montoConcepto <= 0) {
            msp2SetFlash('warning', 'La distribución por concepto contiene conceptos o montos inválidos.');
            msp2Redirect($redirectTarget);
        }

        if (!isset($detalleNormalizado[$idTipo])) {
            $detalleNormalizado[$idTipo] = 0.0;
        }

        $detalleNormalizado[$idTipo] = round($detalleNormalizado[$idTipo] + (float) $montoConcepto, 2);
        $sumaConceptos = round($sumaConceptos + (float) $montoConcepto, 2);
    }

    if (abs($sumaConceptos - $montoObjetivoConceptos) > 0.01) {
        msp2SetFlash('warning', 'La suma de conceptos debe coincidir con el monto aplicado al documento.');
        msp2Redirect($redirectTarget);
    }

    $detallePayload = [];
    foreach ($detalleNormalizado as $idTipo => $montoConcepto) {
        if ($montoConcepto <= 0) {
            continue;
        }
        $detallePayload[] = [
            'id_tipo_item_documento' => (int) $idTipo,
            'monto' => round((float) $montoConcepto, 2),
        ];
    }

    if ($detallePayload === []) {
        msp2SetFlash('warning', 'Debes indicar al menos un concepto con monto para registrar el pago.');
        msp2Redirect($redirectTarget);
    }

    try {
        $detalleConceptosJson = json_encode($detallePayload, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        msp2SetFlash('warning', 'No fue posible procesar la distribución por concepto.');
        msp2Redirect($redirectTarget);
    }
}

$pagoGuardadoOk = false;
$montoSaldoFavorAplicado = 0.0;
$enTransaccion = false;
$saldoFavorPeriodoSyncError = null;

try {
    if ($usarSaldoFavor && $montoSaldoFavor > 0) {
        $conn->beginTransaction();
        $enTransaccion = true;

        $stmtSaldoFavor = $conn->prepare(
            'EXEC dbo.msp_aplicar_saldo_favor_documento
                @id_documento_cobro = :id_documento_cobro,
                @fecha_pago = :fecha_pago,
                @monto_aplicar = :monto_aplicar,
                @observaciones = :observaciones'
        );
        $stmtSaldoFavor->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
        $stmtSaldoFavor->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
        $stmtSaldoFavor->bindValue(':monto_aplicar', $montoSaldoFavor, PDO::PARAM_STR);
        $stmtSaldoFavor->bindValue(':observaciones', null, PDO::PARAM_NULL);
        $stmtSaldoFavor->execute();
        $resultadoSaldoFavor = $stmtSaldoFavor->fetch() ?: [];
        $montoSaldoFavorAplicado = isset($resultadoSaldoFavor['monto_aplicado'])
            ? (float) $resultadoSaldoFavor['monto_aplicado']
            : (float) $montoSaldoFavor;
    }

    $stmt = $conn->prepare(
        'EXEC dbo.msp_registrar_pago_documento
            @id_documento_cobro = :id_documento_cobro,
            @fecha_pago = :fecha_pago,
            @monto_pagado = :monto_pagado,
            @medio_pago = :medio_pago,
            @referencia_pago = :referencia_pago,
            @observaciones = :observaciones,
            @detalle_conceptos_json = :detalle_conceptos_json'
    );
    $stmt->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_pago', $fechaPagoRaw, PDO::PARAM_STR);
    $stmt->bindValue(':monto_pagado', $montoPagado, PDO::PARAM_STR);
    $stmt->bindValue(':medio_pago', $medioPago === '' ? null : $medioPago, $medioPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':referencia_pago', $referenciaPago === '' ? null : $referenciaPago, $referenciaPago === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':observaciones', $observaciones === '' ? null : $observaciones, $observaciones === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindValue(':detalle_conceptos_json', $detalleConceptosJson, $detalleConceptosJson === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->execute();
    $resultado = $stmt->fetch() ?: [];
    $idPagoGenerado = isset($resultado['id_pago_generado']) ? (int) $resultado['id_pago_generado'] : 0;
    $montoAplicado = isset($resultado['monto_aplicado_documento']) ? (float) $resultado['monto_aplicado_documento'] : (float) $montoPagado;
    $montoSaldoFavor = isset($resultado['monto_saldo_favor_generado']) ? (float) $resultado['monto_saldo_favor_generado'] : 0.0;

    if ($montoSaldoFavor > 0.005) {
        try {
            $saldoFavorPeriodoOk = msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
                $conn,
                $idPagoGenerado,
                (int) $idDocumentoCobro,
                $montoSaldoFavor,
                $fechaPagoRaw
            );
            if (!$saldoFavorPeriodoOk) {
                $saldoFavorPeriodoSyncError = 'No se pudo crear o actualizar el item de saldo a favor del periodo siguiente.';
            }
        } catch (Throwable $syncException) {
            $saldoFavorPeriodoSyncError = $syncException->getMessage();
        }
    }

    if ($enTransaccion) {
        $conn->commit();
        $enTransaccion = false;
    }

    if ($montoSaldoFavor > 0 && $montoSaldoFavorAplicado > 0) {
        $msgSaldoFavor = 'El pago fue registrado. Se aplicaron $ ' . number_format($montoAplicado, 2, ',', '.')
            . ' al documento, se usaron $ ' . number_format($montoSaldoFavorAplicado, 2, ',', '.')
            . ' de saldo a favor y el excedente ($ ' . number_format($montoSaldoFavor, 2, ',', '.')
            . ') quedó como saldo a favor de la tienda.';
        if ($saldoFavorPeriodoSyncError === null) {
            $msgSaldoFavor = rtrim($msgSaldoFavor, '.') . ' y se asignó automáticamente al período siguiente (Paso 2).';
        }
        msp2SetFlash(
            'success',
            $msgSaldoFavor
        );
    } elseif ($montoSaldoFavor > 0) {
        $msgSaldoFavor = 'El pago fue registrado. Se aplicaron $ ' . number_format($montoAplicado, 2, ',', '.')
            . ' al documento y el excedente ($ ' . number_format($montoSaldoFavor, 2, ',', '.')
            . ') quedó como saldo a favor de la tienda.';
        if ($saldoFavorPeriodoSyncError === null) {
            $msgSaldoFavor = rtrim($msgSaldoFavor, '.') . ' y se asignó automáticamente al período siguiente (Paso 2).';
        }
        msp2SetFlash(
            'success',
            $msgSaldoFavor
        );
    } elseif ($montoSaldoFavorAplicado > 0) {
        msp2SetFlash(
            'success',
            'El pago fue registrado. Se aplicaron $ ' . number_format($montoSaldoFavorAplicado, 2, ',', '.')
            . ' desde el saldo a favor de la tienda.'
        );
    } else {
        msp2SetFlash('success', 'El pago fue registrado correctamente.');
    }
    if ($saldoFavorPeriodoSyncError !== null) {
        msp2SetFlash(
            'warning',
            'El pago fue registrado, pero no se pudo reflejar automáticamente el excedente en el período siguiente. Verifica que ese período exista y esté en estado Borrador.'
        );
    }
    $pagoGuardadoOk = true;
} catch (PDOException $exception) {
    if ($enTransaccion && $conn->inTransaction()) {
        $conn->rollBack();
        $enTransaccion = false;
    }
    $message = $exception->getMessage();

    if (str_contains($message, '50081')) {
        msp2SetFlash('warning', 'Debes seleccionar un documento válido.');
    } elseif (str_contains($message, '50082')) {
        msp2SetFlash('warning', 'Debes indicar la fecha de aplicación.');
    } elseif (str_contains($message, '50083')) {
        msp2SetFlash('warning', 'El documento seleccionado no existe.');
    } elseif (str_contains($message, '50084')) {
        msp2SetFlash('warning', 'El documento ya no tiene saldo pendiente para aplicar saldo a favor.');
    } elseif (str_contains($message, '50085')) {
        msp2SetFlash('warning', 'La tienda no tiene saldo a favor disponible.');
    } elseif (str_contains($message, '50086')) {
        msp2SetFlash('warning', 'Debes ingresar un monto a aplicar mayor a cero.');
    } elseif (str_contains($message, '50087')) {
        msp2SetFlash('warning', 'El monto supera el saldo a favor disponible de la tienda.');
    } elseif (str_contains($message, '50088')) {
        msp2SetFlash('warning', 'El monto supera el saldo pendiente del documento.');
    } elseif (str_contains($message, '50061')) {
        msp2SetFlash('warning', 'Debes seleccionar un documento válido.');
    } elseif (str_contains($message, '50062')) {
        msp2SetFlash('warning', 'La fecha de pago no tiene un formato válido.');
    } elseif (str_contains($message, '50063')) {
        msp2SetFlash('warning', 'Debes ingresar un monto pagado mayor a cero.');
    } elseif (str_contains($message, '50064')) {
        msp2SetFlash('warning', 'El documento seleccionado no existe.');
    } elseif (str_contains($message, '50065')) {
        msp2SetFlash('warning', 'El documento ya no tiene saldo pendiente para registrar pagos.');
    } elseif (str_contains($message, '50041')) {
        msp2SetFlash('warning', 'No se pueden registrar pagos sobre documentos anulados.');
    } elseif (str_contains($message, '50042')) {
        msp2SetFlash('warning', 'El monto ingresado genera sobrepago. Ajusta el valor y vuelve a intentar.');
    } elseif (str_contains($message, '50120') || str_contains($message, '50124')) {
        msp2SetFlash('warning', 'Hay conceptos inválidos para este documento. Recarga el detalle y vuelve a intentar.');
    } elseif (str_contains($message, '50121')) {
        msp2SetFlash('warning', 'La suma de conceptos no coincide con el monto aplicado al documento.');
    } elseif (str_contains($message, '50122')) {
        msp2SetFlash('warning', 'Uno o más conceptos exceden el saldo disponible.');
    } elseif (str_contains($message, '50123')) {
        msp2SetFlash('warning', 'No fue posible distribuir el pago por concepto. Revisa los saldos e intenta nuevamente.');
    } elseif (str_contains($message, '50125') || str_contains($message, '50127')) {
        msp2SetFlash('warning', 'Debes indicar una distribución válida por concepto.');
    } elseif (str_contains($message, 'has too many arguments specified')) {
        msp2SetFlash('danger', 'La base de datos no tiene habilitado pagos por concepto. Ejecuta `db/patch_pagos_por_concepto.sql`.');
    } else {
        msp2SetFlash('danger', 'No fue posible registrar el pago. Revisa la estructura de la base o intenta nuevamente.');
    }
}

/* -------------------------------------------------------
   Envío de vale de pago por correo (best-effort).
   Modo demo  → si mail.php tiene demo.to, el correo
                siempre se envía ahí (útil para validar).
   Modo real  → requiere correo del arrendatario en BD.
   En cualquier caso, un fallo SMTP no revierte el pago.
   ------------------------------------------------------- */
try {
    if ($pagoGuardadoOk && $idDocumentoCobro !== null && $idDocumentoCobro > 0) {

        // Determinar modo de envío.
        $mailCfg    = mspMailConfig();
        $demoTo     = trim((string) ($mailCfg['demo']['to'] ?? ''));
        $isDemoMode = $demoTo !== '' && filter_var($demoTo, FILTER_VALIDATE_EMAIL) !== false;
        $envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);

        // Traer datos del arrendatario/documento (siempre; el correo es opcional en demo).
        $stmtMail = $conn->prepare(
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
                    AND ca_loc.estado_contrato IN (1,2,3)
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
        $stmtMail->bindValue(':id_documento_cobro', $idDocumentoCobro, PDO::PARAM_INT);
        $stmtMail->execute();
        $mailRow = $stmtMail->fetch();

        if (!is_array($mailRow)) {
            // No se pudo obtener datos del documento; no enviamos.
            throw new RuntimeException('No se encontraron datos del documento para el correo.');
        }

        $correoReal  = trim((string) ($mailRow['correo_principal'] ?? ''));
        $correoValido = filter_var($correoReal, FILTER_VALIDATE_EMAIL) !== false;

        // El envío puede omitirse explícitamente desde UI.
        // En modo demo además requiere confirmación con correo destino.
        $debeEnviar = $enviarComprobante
            && ($isDemoMode ? $demoEmailConfirmado : ($envioArrendatariosHabilitado && $correoValido && $correoReal !== ''));

        if ($debeEnviar) {
            // Reconstruir detalle de conceptos con nombres desde BD.
            $detalleParaEmail = [];
            if ($detalleConceptosRaw !== '') {
                $decoded = json_decode($detalleConceptosRaw, true);
                if (is_array($decoded)) {
                    $tiposIds = array_values(array_filter(array_map(
                        static fn(array $d): int => (int) ($d['id_tipo_item_documento'] ?? 0),
                        $decoded
                    )));
                    $nombresMap = [];
                    $codigosMap = [];
                    $detalleItemsMap = [];
                    if ($tiposIds !== []) {
                        $phs = [];
                        foreach ($tiposIds as $i => $tid) {
                            $phs[] = ':tid_' . $i;
                        }
                        $stmtTipos = $conn->prepare(
                            'SELECT id_tipo_item_documento, nombre_item, codigo_item'
                            . ' FROM dbo.msp_tipo_item_documento'
                            . ' WHERE id_tipo_item_documento IN (' . implode(',', $phs) . ')'
                        );
                        foreach ($tiposIds as $i => $tid) {
                            $stmtTipos->bindValue(':tid_' . $i, $tid, PDO::PARAM_INT);
                        }
                        $stmtTipos->execute();
                        foreach ($stmtTipos->fetchAll() as $t) {
                            $tidMap = (int) ($t['id_tipo_item_documento'] ?? 0);
                            if ($tidMap <= 0) {
                                continue;
                            }
                            $nombresMap[$tidMap] = trim((string) ($t['nombre_item'] ?? $t['codigo_item'] ?? ''));
                            $codigosMap[$tidMap] = trim((string) ($t['codigo_item'] ?? ''));
                        }

                        $stmtDetalleItems = $conn->prepare(
                            "SELECT
                                d.id_tipo_item_documento,
                                STUFF((
                                    SELECT N' | ' + LEFT(LTRIM(RTRIM(ISNULL(d2.descripcion_item, N''))), 120)
                                    FROM dbo.msp_documentos_cobro_detalle d2
                                    WHERE d2.id_documento_cobro = :id_documento_cobro_det
                                      AND d2.id_tipo_item_documento = d.id_tipo_item_documento
                                      AND NULLIF(LTRIM(RTRIM(ISNULL(d2.descripcion_item, N''))), N'') IS NOT NULL
                                    ORDER BY d2.orden_item
                                    FOR XML PATH(''), TYPE
                                ).value('.', 'NVARCHAR(MAX)'), 1, 3, N'') AS detalle_items
                             FROM dbo.msp_documentos_cobro_detalle d
                             WHERE d.id_documento_cobro = :id_documento_cobro_list
                               AND d.id_tipo_item_documento IN (" . implode(',', $phs) . ")
                             GROUP BY d.id_tipo_item_documento"
                        );
                        $stmtDetalleItems->bindValue(':id_documento_cobro_det', $idDocumentoCobro, PDO::PARAM_INT);
                        $stmtDetalleItems->bindValue(':id_documento_cobro_list', $idDocumentoCobro, PDO::PARAM_INT);
                        foreach ($tiposIds as $i => $tid) {
                            $stmtDetalleItems->bindValue(':tid_' . $i, $tid, PDO::PARAM_INT);
                        }
                        $stmtDetalleItems->execute();
                        foreach ($stmtDetalleItems->fetchAll() ?: [] as $drow) {
                            $tidDetalle = (int) ($drow['id_tipo_item_documento'] ?? 0);
                            if ($tidDetalle <= 0) {
                                continue;
                            }
                            $detalleItemsMap[$tidDetalle] = trim((string) ($drow['detalle_items'] ?? ''));
                        }
                    }
                    foreach ($decoded as $d) {
                        $tid = (int) ($d['id_tipo_item_documento'] ?? 0);
                        $detalleParaEmail[] = [
                            'id_tipo_item_documento' => $tid,
                            'nombre_item'            => $nombresMap[$tid] ?? ('Concepto #' . $tid),
                            'codigo_item'            => $codigosMap[$tid] ?? '',
                            'monto'                  => (float) ($d['monto'] ?? 0),
                            'detalle_items'          => $detalleItemsMap[$tid] ?? '',
                        ];
                    }
                }
            }

            $pagoDataEmail = [
                'id_pago'           => 0,
                'fecha_pago'        => $fechaPagoRaw,
                'monto_pagado'      => (float) $montoPagado,
                'monto_aplicado'    => $montoAplicado,
                'saldo_favor_aplicado' => $montoSaldoFavorAplicado,
                'medio_pago'        => $medioPago,
                'referencia_pago'   => $referenciaPago,
                'banco'             => $bancoCheque,
                'observaciones'     => $observaciones,
                'detalle_conceptos' => $detalleParaEmail,
            ];
            $arrDataEmail = [
                'nombre_arrendatario' => (string) ($mailRow['nombre_arrendatario'] ?? ''),
                'rut'                 => (string) ($mailRow['rut'] ?? ''),
                'correo_principal'    => $correoReal,
            ];
            $docDataEmail = [
                'numero_documento'      => (string) ($mailRow['numero_documento'] ?? ''),
                'periodo_ym'            => (string) ($mailRow['periodo_ym'] ?? ''),
                'locales_contrato'      => (string) ($mailRow['locales_contrato'] ?? ''),
                'saldo_pendiente_nuevo' => (float) ($mailRow['saldo_pendiente'] ?? 0),
            ];
            [$subject, $bodyHtml, $altText] = rpBuildValeEmailContent(
                $pagoDataEmail,
                $arrDataEmail,
                $docDataEmail
            );

            $attachComprobantePdf = ((float) ($docDataEmail['saldo_pendiente_nuevo'] ?? 0)) <= 0.005;
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

            // Destino y etiqueta demo.
            $destinoDemo   = $demoEmailOverride !== '' ? $demoEmailOverride : $demoTo;
            $destino       = $isDemoMode ? $destinoDemo : $correoReal;
            $destinoNombre = $isDemoMode ? 'Demo MSP' : (string) ($mailRow['nombre_arrendatario'] ?? '');

            $mail = mspMailBuildSmtp();
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $bodyHtml;
            $mail->AltBody = $altText;
            $mail->addAddress($destino, $destinoNombre);
            if (is_string($comprobantePdfBytes) && $comprobantePdfBytes !== '') {
                $mail->addStringAttachment($comprobantePdfBytes, $comprobantePdfName, 'base64', 'application/pdf');
            }
            $mail->send();
        }
    }
} catch (Throwable) {
    // Envío de correo es best-effort: no interrumpe el flujo.
}

msp2Redirect($redirectTarget);
