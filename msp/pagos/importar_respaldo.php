<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('pagos/index.php');
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` e intenta nuevamente.');
    msp2Redirect('pagos/index.php');
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido para importar.');
    msp2Redirect('pagos/index.php');
}

$requiredTables = [
    'msp_pagos',
    'msp_pagos_detalle_concepto',
    'msp_documentos_cobro',
    'msp_tiendas',
    'msp_tipo_item_documento',
];

foreach ($requiredTables as $tableName) {
    if (!msp2TableExists($conn, $tableName)) {
        msp2SetFlash('warning', 'Falta la tabla `' . $tableName . '` para importar respaldos de pagos.');
        msp2Redirect('pagos/index.php');
    }
}

function msp2PagosBackupIsRowEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (msp2PagosBackupCellString($value) !== '') {
            return false;
        }
    }

    return true;
}

function msp2PagosBackupFetchConceptMap(PDO $conn): array
{
    $stmt = $conn->query(
        'SELECT id_tipo_item_documento, codigo_item, nombre_item
         FROM dbo.msp_tipo_item_documento'
    );
    $rows = $stmt->fetchAll() ?: [];
    $map = [];

    foreach ($rows as $row) {
        $codigo = msp2PagosBackupCellString($row['codigo_item'] ?? '');
        if ($codigo === '') {
            continue;
        }

        $map[$codigo] = [
            'id_tipo_item_documento' => (int) ($row['id_tipo_item_documento'] ?? 0),
            'nombre_item' => msp2PagosBackupCellString($row['nombre_item'] ?? ''),
        ];
    }

    return $map;
}

function msp2PagosBackupFetchTargetDocuments(PDO $conn, array $pairs): array
{
    if ($pairs === []) {
        return [];
    }

    $tiendaIds = [];
    $periodos = [];
    foreach ($pairs as $pair) {
        $idTienda = (int) ($pair['id_tienda'] ?? 0);
        $periodo = substr((string) ($pair['periodo_facturacion'] ?? ''), 0, 10);
        if ($idTienda <= 0 || $periodo === '') {
            continue;
        }

        $tiendaIds[$idTienda] = true;
        $periodos[$periodo] = true;
    }

    if ($tiendaIds === [] || $periodos === []) {
        return [];
    }

    $tiendaValues = array_keys($tiendaIds);
    $periodoValues = array_keys($periodos);
    $tiendaPlaceholders = [];
    $periodoPlaceholders = [];

    foreach ($tiendaValues as $index => $idTienda) {
        $tiendaPlaceholders[] = ':tienda_' . $index;
    }
    foreach ($periodoValues as $index => $periodo) {
        $periodoPlaceholders[] = ':periodo_' . $index;
    }

    $sql =
        'SELECT
            dc.id_documento_cobro,
            dc.id_tienda,
            CONVERT(CHAR(10), dc.periodo_facturacion, 126) AS periodo_facturacion,
            ISNULL(dc.numero_documento, \'\') AS numero_documento,
            dc.estado_documento,
            dc.saldo_pendiente,
            ISNULL(t.nombre_comercial, \'\') AS nombre_comercial
         FROM dbo.msp_documentos_cobro dc
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = dc.id_tienda
         WHERE dc.id_tienda IN (' . implode(', ', $tiendaPlaceholders) . ')
           AND dc.periodo_facturacion IN (' . implode(', ', $periodoPlaceholders) . ')
           AND dc.estado_documento <> 5';

    $stmt = $conn->prepare($sql);
    foreach ($tiendaValues as $index => $idTienda) {
        $stmt->bindValue(':tienda_' . $index, $idTienda, PDO::PARAM_INT);
    }
    foreach ($periodoValues as $index => $periodo) {
        $stmt->bindValue(':periodo_' . $index, $periodo, PDO::PARAM_STR);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $map = [];
    foreach ($rows as $row) {
        $key = (int) ($row['id_tienda'] ?? 0) . '|' . substr((string) ($row['periodo_facturacion'] ?? ''), 0, 10);
        if ($key !== '0|') {
            $map[$key] = $row;
        }
    }

    return $map;
}

function msp2PagosBackupFetchActivePaymentsByDocument(PDO $conn, array $documentIds): array
{
    if ($documentIds === []) {
        return [];
    }

    $placeholders = [];
    foreach ($documentIds as $index => $idDocumento) {
        $placeholders[] = ':doc_' . $index;
    }

    $stmt = $conn->prepare(
        'SELECT id_documento_cobro, COUNT(*) AS total
         FROM dbo.msp_pagos
         WHERE estado_pago = 1
           AND id_documento_cobro IN (' . implode(', ', $placeholders) . ')
         GROUP BY id_documento_cobro'
    );
    foreach ($documentIds as $index => $idDocumento) {
        $stmt->bindValue(':doc_' . $index, $idDocumento, PDO::PARAM_INT);
    }
    $stmt->execute();

    $rows = $stmt->fetchAll() ?: [];
    $map = [];
    foreach ($rows as $row) {
        $map[(int) ($row['id_documento_cobro'] ?? 0)] = (int) ($row['total'] ?? 0);
    }

    return $map;
}

$originalName = (string) ($uploadMeta['name'] ?? 'respaldo_pagos.xlsx');
$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

try {
    $sheets = msp2PagosBackupReadSheets($uploadTmpPath);
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible leer el archivo Excel seleccionado.');
    msp2Redirect('pagos/index.php');
}

$pagosSheetRows = $sheets[msp2PagosBackupSheetPagos()] ?? null;
$detalleSheetRows = $sheets[msp2PagosBackupSheetDetalle()] ?? null;

if (!is_array($pagosSheetRows) || !is_array($detalleSheetRows) || $pagosSheetRows === [] || $detalleSheetRows === []) {
    msp2SetFlash('warning', 'El archivo no contiene las hojas requeridas (`Pagos` y `DetalleConceptos`).');
    msp2Redirect('pagos/index.php');
}

$pagosHeaderMap = msp2PagosBackupBuildHeaderMap((array) ($pagosSheetRows[0] ?? []));
$detalleHeaderMap = msp2PagosBackupBuildHeaderMap((array) ($detalleSheetRows[0] ?? []));
$requiredPagosHeaders = msp2PagosBackupHeadersPagos();
$requiredDetalleHeaders = msp2PagosBackupHeadersDetalle();

foreach ($requiredPagosHeaders as $header) {
    if (!array_key_exists($header, $pagosHeaderMap)) {
        msp2SetFlash('warning', 'La hoja `Pagos` no tiene el encabezado requerido `' . $header . '`.');
        msp2Redirect('pagos/index.php');
    }
}
foreach ($requiredDetalleHeaders as $header) {
    if (!array_key_exists($header, $detalleHeaderMap)) {
        msp2SetFlash('warning', 'La hoja `DetalleConceptos` no tiene el encabezado requerido `' . $header . '`.');
        msp2Redirect('pagos/index.php');
    }
}

$conceptMap = msp2PagosBackupFetchConceptMap($conn);
$detalleByUid = [];
$detalleErrorsByUid = [];

foreach (array_slice($detalleSheetRows, 1) as $rowNumber => $row) {
    if (!is_array($row) || msp2PagosBackupIsRowEmpty($row)) {
        continue;
    }

    $version = msp2PagosBackupCellString($row[$detalleHeaderMap['version']] ?? '');
    $pagoUid = msp2PagosBackupCellString($row[$detalleHeaderMap['pago_uid']] ?? '');
    $ordenConcepto = msp2PagosBackupParseInt($row[$detalleHeaderMap['orden_concepto']] ?? null);
    $codigoItem = msp2PagosBackupCellString($row[$detalleHeaderMap['codigo_item']] ?? '');
    [$okMonto, $montoAplicado] = msp2PagosBackupParseDecimal($row[$detalleHeaderMap['monto_aplicado']] ?? null, 2);

    $error = null;
    if ($version !== msp2PagosBackupVersion()) {
        $error = 'Versión inválida en detalle.';
    } elseif ($pagoUid === '') {
        $error = 'Detalle sin pago_uid.';
    } elseif ($ordenConcepto === null || $ordenConcepto <= 0) {
        $error = 'orden_concepto inválido.';
    } elseif ($codigoItem === '' || !isset($conceptMap[$codigoItem])) {
        $error = 'codigo_item no existe en el catálogo actual.';
    } elseif (!$okMonto || $montoAplicado === null || (float) $montoAplicado <= 0) {
        $error = 'monto_aplicado inválido en detalle.';
    }

    if ($error !== null) {
        if ($pagoUid !== '') {
            $detalleErrorsByUid[$pagoUid] = $error;
        }
        continue;
    }

    if (!isset($detalleByUid[$pagoUid])) {
        $detalleByUid[$pagoUid] = [];
    }

    $detalleByUid[$pagoUid][] = [
        'orden_concepto' => $ordenConcepto,
        'codigo_item' => $codigoItem,
        'nombre_item' => $conceptMap[$codigoItem]['nombre_item'],
        'monto_aplicado' => (float) $montoAplicado,
        'id_tipo_item_documento' => (int) $conceptMap[$codigoItem]['id_tipo_item_documento'],
        'row_number' => $rowNumber + 2,
    ];
}

$parsedRows = [];
$pairs = [];
$duplicateUidMap = [];
$duplicateOrderMap = [];

foreach (array_slice($pagosSheetRows, 1) as $rowNumber => $row) {
    if (!is_array($row) || msp2PagosBackupIsRowEmpty($row)) {
        continue;
    }

    $version = msp2PagosBackupCellString($row[$pagosHeaderMap['version']] ?? '');
    $pagoUid = msp2PagosBackupCellString($row[$pagosHeaderMap['pago_uid']] ?? '');
    $ordenReplay = msp2PagosBackupParseInt($row[$pagosHeaderMap['orden_replay']] ?? null);
    $idPagoOrigen = msp2PagosBackupParseInt($row[$pagosHeaderMap['id_pago_origen']] ?? null);
    $idDocumentoOrigen = msp2PagosBackupParseInt($row[$pagosHeaderMap['id_documento_origen']] ?? null);
    $idTienda = msp2PagosBackupParseInt($row[$pagosHeaderMap['id_tienda']] ?? null);
    $nombreComercial = msp2PagosBackupCellString($row[$pagosHeaderMap['nombre_comercial']] ?? '');
    $periodoFacturacion = msp2PagosBackupParseDate($row[$pagosHeaderMap['periodo_facturacion']] ?? null);
    $numeroDocumento = msp2PagosBackupCellString($row[$pagosHeaderMap['numero_documento']] ?? '');
    $rutArrendatario = msp2PagosBackupCellString($row[$pagosHeaderMap['rut_arrendatario']] ?? '');
    $nombreArrendatario = msp2PagosBackupCellString($row[$pagosHeaderMap['nombre_arrendatario']] ?? '');
    $fechaPago = msp2PagosBackupParseDate($row[$pagosHeaderMap['fecha_pago']] ?? null);
    [$okMontoPagado, $montoPagado] = msp2PagosBackupParseDecimal($row[$pagosHeaderMap['monto_pagado']] ?? null, 2);
    [$okMontoAplicado, $montoAplicado] = msp2PagosBackupParseDecimal($row[$pagosHeaderMap['monto_aplicado_documento']] ?? null, 2);
    [$okSaldoGenerado, $montoSaldoGenerado] = msp2PagosBackupParseDecimal($row[$pagosHeaderMap['monto_saldo_favor_generado']] ?? null, 2);
    $aplicaDesdeSaldoFavor = msp2PagosBackupParseBoolFlag($row[$pagosHeaderMap['aplica_desde_saldo_favor']] ?? null);
    $medioPago = msp2PagosBackupCellString($row[$pagosHeaderMap['medio_pago']] ?? '');
    $referenciaPago = msp2PagosBackupCellString($row[$pagosHeaderMap['referencia_pago']] ?? '');
    $observaciones = msp2PagosBackupCellString($row[$pagosHeaderMap['observaciones']] ?? '');
    $estadoPagoRow = msp2PagosBackupCellString($row[$pagosHeaderMap['estado_pago']] ?? '');

    $error = null;
    if ($version !== msp2PagosBackupVersion()) {
        $error = 'Versión inválida.';
    } elseif ($pagoUid === '') {
        $error = 'pago_uid obligatorio.';
    } elseif ($ordenReplay === null || $ordenReplay <= 0) {
        $error = 'orden_replay inválido.';
    } elseif ($idPagoOrigen === null || $idPagoOrigen <= 0) {
        $error = 'id_pago_origen inválido.';
    } elseif ($idDocumentoOrigen === null || $idDocumentoOrigen <= 0) {
        $error = 'id_documento_origen inválido.';
    } elseif ($idTienda === null || $idTienda <= 0) {
        $error = 'id_tienda inválido.';
    } elseif ($periodoFacturacion === null) {
        $error = 'periodo_facturacion inválido.';
    } elseif ($fechaPago === null) {
        $error = 'fecha_pago inválida.';
    } elseif (!$okMontoPagado || $montoPagado === null || (float) $montoPagado <= 0) {
        $error = 'monto_pagado inválido.';
    } elseif (!$okMontoAplicado || $montoAplicado === null || (float) $montoAplicado <= 0) {
        $error = 'monto_aplicado_documento inválido.';
    } elseif (!$okSaldoGenerado || $montoSaldoGenerado === null || (float) $montoSaldoGenerado < 0) {
        $error = 'monto_saldo_favor_generado inválido.';
    } elseif ($aplicaDesdeSaldoFavor === null) {
        $error = 'aplica_desde_saldo_favor inválido.';
    } elseif ($estadoPagoRow !== 'Aplicado') {
        $error = 'Solo se pueden importar pagos con estado Aplicado.';
    }

    if ($error === null) {
        $montoPagadoFloat = round((float) $montoPagado, 2);
        $montoAplicadoFloat = round((float) $montoAplicado, 2);
        $montoSaldoFloat = round((float) $montoSaldoGenerado, 2);

        if ($aplicaDesdeSaldoFavor === 1) {
            if (abs($montoSaldoFloat) > 0.01) {
                $error = 'Un pago desde saldo a favor no puede generar saldo adicional.';
            } elseif (abs($montoPagadoFloat - $montoAplicadoFloat) > 0.01) {
                $error = 'El monto pagado debe coincidir con el monto aplicado para pagos desde saldo a favor.';
            }
        } elseif ($montoPagadoFloat + 0.01 < $montoAplicadoFloat) {
            $error = 'monto_pagado no puede ser menor que monto_aplicado_documento.';
        } elseif (abs(($montoPagadoFloat - $montoAplicadoFloat) - $montoSaldoFloat) > 0.01) {
            $error = 'El saldo a favor generado no coincide con la diferencia entre monto pagado y monto aplicado.';
        }
    }

    $duplicateUidMap[$pagoUid] = ($duplicateUidMap[$pagoUid] ?? 0) + 1;
    if ($ordenReplay !== null) {
        $duplicateOrderMap[$ordenReplay] = ($duplicateOrderMap[$ordenReplay] ?? 0) + 1;
    }

    $documentKey = ($idTienda ?? 0) . '|' . ($periodoFacturacion ?? '');
    $documentLabel = 'Tienda #' . (int) ($idTienda ?? 0) . ' / ' . ($periodoFacturacion ?? '-');

    $parsedRows[] = [
        'status' => $error === null ? 'PENDING' : 'ERROR',
        'error' => $error,
        'pago_uid' => $pagoUid,
        'orden_replay' => $ordenReplay ?? 0,
        'id_pago_origen' => $idPagoOrigen ?? 0,
        'id_documento_origen' => $idDocumentoOrigen ?? 0,
        'id_tienda' => $idTienda ?? 0,
        'nombre_comercial' => $nombreComercial,
        'periodo_facturacion' => $periodoFacturacion ?? '',
        'numero_documento' => $numeroDocumento,
        'rut_arrendatario' => $rutArrendatario,
        'nombre_arrendatario' => $nombreArrendatario,
        'fecha_pago' => $fechaPago ?? '',
        'monto_pagado' => round((float) ($montoPagado ?? 0), 2),
        'monto_aplicado_documento' => round((float) ($montoAplicado ?? 0), 2),
        'monto_saldo_favor_generado' => round((float) ($montoSaldoGenerado ?? 0), 2),
        'aplica_desde_saldo_favor' => $aplicaDesdeSaldoFavor ?? 0,
        'medio_pago' => $medioPago,
        'referencia_pago' => $referenciaPago,
        'observaciones' => $observaciones,
        'estado_pago' => $estadoPagoRow,
        'document_key' => $documentKey,
        'document_label' => $documentLabel,
        'target_document_id' => 0,
        'detalle_rows' => $detalleByUid[$pagoUid] ?? [],
        'source_row' => $rowNumber + 2,
    ];

    if ($error === null) {
        $pairs[] = [
            'id_tienda' => $idTienda,
            'periodo_facturacion' => $periodoFacturacion,
        ];
    }
}

$documentsMap = msp2PagosBackupFetchTargetDocuments($conn, $pairs);
$targetDocumentIds = [];

foreach ($parsedRows as $index => $row) {
    if (($row['status'] ?? '') === 'ERROR') {
        continue;
    }

    $pagoUid = (string) ($row['pago_uid'] ?? '');
    $documentKey = (string) ($row['document_key'] ?? '');
    $error = null;

    if (($duplicateUidMap[$pagoUid] ?? 0) > 1) {
        $error = 'pago_uid duplicado en la hoja Pagos.';
    } elseif (($duplicateOrderMap[(int) ($row['orden_replay'] ?? 0)] ?? 0) > 1) {
        $error = 'orden_replay duplicado.';
    } elseif (isset($detalleErrorsByUid[$pagoUid])) {
        $error = $detalleErrorsByUid[$pagoUid];
    } elseif (($row['detalle_rows'] ?? []) === []) {
        $error = 'No existen detalles por concepto para el pago.';
    } else {
        $sumDetalle = 0.0;
        foreach ((array) $row['detalle_rows'] as $detalleItem) {
            $sumDetalle += (float) ($detalleItem['monto_aplicado'] ?? 0);
        }
        if (abs(round($sumDetalle, 2) - round((float) ($row['monto_aplicado_documento'] ?? 0), 2)) > 0.01) {
            $error = 'La suma de conceptos no coincide con el monto aplicado al documento.';
        }
    }

    if ($error === null) {
        $targetDocument = $documentsMap[$documentKey] ?? null;
        if (!is_array($targetDocument)) {
            $error = 'No existe documento destino para la tienda y período indicados.';
        } else {
            $numeroOrigen = trim((string) ($row['numero_documento'] ?? ''));
            $numeroDestino = trim((string) ($targetDocument['numero_documento'] ?? ''));
            if ($numeroOrigen !== '' && $numeroDestino !== '' && $numeroOrigen !== $numeroDestino) {
                $error = 'El número de documento actual no coincide con el respaldo.';
            } else {
                $parsedRows[$index]['target_document_id'] = (int) ($targetDocument['id_documento_cobro'] ?? 0);
                $parsedRows[$index]['document_label'] =
                    '#' . (int) ($targetDocument['id_documento_cobro'] ?? 0)
                    . ' | '
                    . (string) ($targetDocument['nombre_comercial'] ?? '')
                    . ' | '
                    . substr((string) ($targetDocument['periodo_facturacion'] ?? ''), 0, 10);
                $targetDocumentIds[(int) ($targetDocument['id_documento_cobro'] ?? 0)] = true;
            }
        }
    }

    if ($error !== null) {
        $parsedRows[$index]['status'] = 'ERROR';
        $parsedRows[$index]['error'] = $error;
    }
}

$activePaymentsMap = msp2PagosBackupFetchActivePaymentsByDocument($conn, array_keys($targetDocumentIds));

$validRows = [];
foreach ($parsedRows as $index => $row) {
    if (($row['status'] ?? '') === 'ERROR') {
        continue;
    }

    $targetDocumentId = (int) ($row['target_document_id'] ?? 0);
    if (($activePaymentsMap[$targetDocumentId] ?? 0) > 0) {
        $parsedRows[$index]['status'] = 'ERROR';
        $parsedRows[$index]['error'] = 'El documento destino ya tiene pagos aplicados activos.';
        continue;
    }

    $detallePayload = [];
    foreach ((array) ($row['detalle_rows'] ?? []) as $detalleItem) {
        $detallePayload[] = [
            'id_tipo_item_documento' => (int) ($detalleItem['id_tipo_item_documento'] ?? 0),
            'monto' => number_format((float) ($detalleItem['monto_aplicado'] ?? 0), 2, '.', ''),
        ];
    }

    $parsedRows[$index]['status'] = 'OK';
    $validRows[] = [
        'pago_uid' => (string) ($row['pago_uid'] ?? ''),
        'orden_replay' => (int) ($row['orden_replay'] ?? 0),
        'id_documento_cobro' => $targetDocumentId,
        'fecha_pago' => (string) ($row['fecha_pago'] ?? ''),
        'monto_pagado' => number_format((float) ($row['monto_pagado'] ?? 0), 2, '.', ''),
        'monto_aplicado_documento' => number_format((float) ($row['monto_aplicado_documento'] ?? 0), 2, '.', ''),
        'monto_saldo_favor_generado' => number_format((float) ($row['monto_saldo_favor_generado'] ?? 0), 2, '.', ''),
        'aplica_desde_saldo_favor' => (int) ($row['aplica_desde_saldo_favor'] ?? 0),
        'medio_pago' => (string) ($row['medio_pago'] ?? ''),
        'referencia_pago' => (string) ($row['referencia_pago'] ?? ''),
        'observaciones' => (string) ($row['observaciones'] ?? ''),
        'detalle_conceptos_json' => json_encode($detallePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

usort($parsedRows, static function (array $a, array $b): int {
    $orderA = (int) ($a['orden_replay'] ?? 0);
    $orderB = (int) ($b['orden_replay'] ?? 0);
    if ($orderA === $orderB) {
        return strcmp((string) ($a['pago_uid'] ?? ''), (string) ($b['pago_uid'] ?? ''));
    }

    return $orderA <=> $orderB;
});

usort($validRows, static function (array $a, array $b): int {
    return ((int) ($a['orden_replay'] ?? 0)) <=> ((int) ($b['orden_replay'] ?? 0));
});

msp2PagosPreviewSessionClear();
msp2PagosPreviewSessionWrite([
    'version' => msp2PagosBackupVersion(),
    'original_name' => $originalName,
    'created_at' => date('c'),
    'rows' => $parsedRows,
    'valid_rows' => $validRows,
]);

$errorCount = 0;
foreach ($parsedRows as $row) {
    if (($row['status'] ?? '') === 'ERROR') {
        $errorCount++;
    }
}

if ($validRows === []) {
    msp2SetFlash('warning', 'La previsualización no tiene pagos válidos para importar.');
} elseif ($errorCount > 0) {
    msp2SetFlash('warning', 'La previsualización quedó con ' . $errorCount . ' error(es). Puedes confirmar para importar solo filas OK, o corregir/descartar el preview.');
} else {
    msp2SetFlash('success', 'Previsualización lista: ' . count($validRows) . ' pagos válidos para importar.');
}

msp2Redirect('pagos/index.php');
