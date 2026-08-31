<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pago_contrato_import_helper.php';
require_once dirname(__DIR__) . '/pagos/saldo_favor_periodo_helper.php';

msp2RequireAccess();

if (!rpcPagoContratoImportIsAdminUser($conn)) {
    rpcPagoContratoImportPreviewClear();
    msp2SetFlash('warning', 'La importación masiva de pagos por contrato está disponible solo para administradores.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

$preview = rpcPagoContratoImportPreviewRead();
if (!is_array($preview)) {
    msp2SetFlash('warning', 'No hay previsualización de importación pendiente.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

$volverQuery = trim((string) ($preview['volver_query'] ?? ''));
if ($volverQuery !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
    $volverQuery = '';
}
$redirectTarget = 'cobranza/registrar_pago_contrato.php' . ($volverQuery !== '' ? ('?' . $volverQuery) : '');

$validRows = $preview['valid_rows'] ?? [];
if (!is_array($validRows) || $validRows === []) {
    msp2SetFlash('warning', 'La previsualización no contiene filas válidas.');
    rpcPagoContratoImportPreviewClear();
    msp2Redirect($redirectTarget);
}

function rpcImportFetchDocumentosDeudaContrato(PDO $conn, int $idContratoArriendo): array
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
                dc.saldo_pendiente
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
                  AND ca.estado_contrato IN (1,2,3)
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

function rpcImportMapPagoError(string $message): string
{
    if (str_contains($message, '50061') || str_contains($message, '50064')) {
        return 'Documento inválido/no existe.';
    }
    if (str_contains($message, '50062')) {
        return 'Fecha de pago inválida.';
    }
    if (str_contains($message, '50063')) {
        return 'Monto de pago inválido.';
    }
    if (str_contains($message, '50065')) {
        return 'Documento sin saldo pendiente.';
    }
    if (str_contains($message, '50041')) {
        return 'Documento anulado.';
    }
    if (str_contains($message, 'has too many arguments specified')) {
        return 'La base no tiene habilitado pagos por concepto.';
    }

    return trim($message) !== '' ? $message : 'Error al registrar pago.';
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_pagos',
        'msp_contratos_arriendo',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta tabla requerida `' . $tableName . '`.');
        }
    }
    if (!msp2ProcedureExists($conn, 'msp_registrar_pago_documento')) {
        throw new RuntimeException('No existe el procedimiento dbo.msp_registrar_pago_documento.');
    }

    usort($validRows, static fn(array $a, array $b): int => ((int) ($a['row_number'] ?? 0)) <=> ((int) ($b['row_number'] ?? 0)));

    $okRows = 0;
    $errorRows = 0;
    $errores = [];
    $totalAplicado = 0.0;

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

    foreach ($validRows as $row) {
        $rowNumber = (int) ($row['row_number'] ?? 0);
        $idContrato = (int) ($row['id_contrato_arriendo'] ?? 0);
        $fechaPago = (string) ($row['fecha_pago'] ?? '');
        $montoPagado = round((float) ($row['monto_pagado'] ?? 0), 2);
        $medioPago = trim((string) ($row['medio_pago'] ?? ''));
        $referenciaPago = trim((string) ($row['referencia_pago'] ?? ''));
        $bancoCheque = trim((string) ($row['banco_cheque'] ?? ''));

        if ($idContrato <= 0 || $fechaPago === '' || $montoPagado <= 0 || $medioPago === '') {
            $errorRows++;
            $errores[] = 'Fila ' . $rowNumber . ': datos base inválidos.';
            continue;
        }

        $obsBase = 'Importación masiva pago por contrato';
        if ($bancoCheque !== '') {
            $obsBase .= ' | Banco: ' . $bancoCheque;
        }

        try {
            $documentosDeuda = rpcImportFetchDocumentosDeudaContrato($conn, $idContrato);
            if ($documentosDeuda === []) {
                throw new RuntimeException('Contrato sin documentos pendientes.');
            }

            $conn->beginTransaction();
            $montoRestante = $montoPagado;
            $totalDocs = count($documentosDeuda);
            $montoAplicadoFila = 0.0;

            foreach ($documentosDeuda as $index => $doc) {
                if ($montoRestante <= 0.005) {
                    break;
                }
                $idDocumento = (int) ($doc['id_documento_cobro'] ?? 0);
                $saldoDoc = round((float) ($doc['saldo_pendiente'] ?? 0), 2);
                if ($idDocumento <= 0 || $saldoDoc <= 0) {
                    continue;
                }

                $esUltimoDocumento = ($index === ($totalDocs - 1));
                $montoIntento = $esUltimoDocumento
                    ? round($montoRestante, 2)
                    : round(min($montoRestante, $saldoDoc), 2);
                if ($montoIntento <= 0.005) {
                    continue;
                }

                $stmtPago->bindValue(':id_documento_cobro', $idDocumento, PDO::PARAM_INT);
                $stmtPago->bindValue(':fecha_pago', $fechaPago, PDO::PARAM_STR);
                $stmtPago->bindValue(':monto_pagado', (string) $montoIntento, PDO::PARAM_STR);
                $stmtPago->bindValue(':medio_pago', $medioPago, PDO::PARAM_STR);
                $stmtPago->bindValue(':referencia_pago', $referenciaPago !== '' ? $referenciaPago : null, $referenciaPago !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtPago->bindValue(':observaciones', $obsBase, PDO::PARAM_STR);
                $stmtPago->bindValue(':detalle_conceptos_json', null, PDO::PARAM_NULL);
                $stmtPago->execute();
                $resPago = $stmtPago->fetch() ?: [];
                $stmtPago->closeCursor();

                $idPagoGenerado = (int) ($resPago['id_pago_generado'] ?? 0);
                $montoAplicadoDoc = isset($resPago['monto_aplicado_documento'])
                    ? round((float) $resPago['monto_aplicado_documento'], 2)
                    : round(min($montoIntento, $saldoDoc), 2);
                $montoExcedente = isset($resPago['monto_saldo_favor_generado'])
                    ? round((float) $resPago['monto_saldo_favor_generado'], 2)
                    : round(max(0.0, $montoIntento - $montoAplicadoDoc), 2);

                $consumido = round($montoAplicadoDoc + $montoExcedente, 2);
                if ($consumido <= 0.0) {
                    $consumido = $montoIntento;
                }

                $montoRestante = round(max(0.0, $montoRestante - $consumido), 2);
                $montoAplicadoFila = round($montoAplicadoFila + $montoAplicadoDoc, 2);

                if ($montoExcedente > 0.005) {
                    msp2PagoRegistrarSaldoFavorPeriodoSiguiente(
                        $conn,
                        $idPagoGenerado,
                        $idDocumento,
                        $montoExcedente,
                        $fechaPago
                    );
                }
            }

            if ($montoAplicadoFila <= 0.005) {
                throw new RuntimeException('No fue posible aplicar el pago en documentos del contrato.');
            }

            $conn->commit();
            $okRows++;
            $totalAplicado = round($totalAplicado + $montoAplicadoFila, 2);
        } catch (Throwable $rowException) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $errorRows++;
            $errores[] = 'Fila ' . $rowNumber . ': ' . rpcImportMapPagoError($rowException->getMessage());
        }
    }

    rpcPagoContratoImportPreviewClear();

    if ($okRows <= 0) {
        $msg = 'No se importaron pagos.';
        if ($errores !== []) {
            $msg .= ' ' . implode(' | ', array_slice($errores, 0, 3));
        }
        msp2SetFlash('danger', $msg);
    } elseif ($errorRows > 0) {
        $msg = 'Importación parcial: ' . $okRows . ' fila(s) ok, ' . $errorRows . ' con error. Total aplicado: $ ' . number_format($totalAplicado, 2, ',', '.');
        if ($errores !== []) {
            $msg .= ' Detalle: ' . implode(' | ', array_slice($errores, 0, 3));
        }
        msp2SetFlash('warning', $msg);
    } else {
        msp2SetFlash('success', 'Importación completada: ' . $okRows . ' fila(s) procesadas. Total aplicado: $ ' . number_format($totalAplicado, 2, ',', '.'));
    }
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible confirmar la importación.');
}

msp2Redirect($redirectTarget);
