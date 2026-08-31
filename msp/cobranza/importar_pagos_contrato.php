<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/pago_contrato_import_helper.php';

msp2RequireAccess();

if (!rpcPagoContratoImportIsAdminUser($conn)) {
    rpcPagoContratoImportPreviewClear();
    msp2SetFlash('warning', 'La importación masiva de pagos por contrato está disponible solo para administradores.');
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cobranza/registrar_pago_contrato.php');
}

$volverQuery = trim((string) ($_POST['volver_query'] ?? ''));
if ($volverQuery !== '' && preg_match('/^[A-Za-z0-9_\-\.\[\]%=&]*$/', $volverQuery) !== 1) {
    $volverQuery = '';
}
$redirectTarget = 'cobranza/registrar_pago_contrato.php' . ($volverQuery !== '' ? ('?' . $volverQuery) : '');

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install`.');
    msp2Redirect($redirectTarget);
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido.');
    msp2Redirect($redirectTarget);
}

$originalName = (string) ($uploadMeta['name'] ?? 'importacion_pagos_contrato.xlsx');
$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

if (!msp2TableExists($conn, 'msp_contratos_arriendo') || !msp2TableExists($conn, 'msp_arrendatarios')) {
    msp2SetFlash('warning', 'Faltan tablas base para importar pagos por contrato.');
    msp2Redirect($redirectTarget);
}

function rpcPagoContratoImportReadRows(string $path): array
{
    return msp2WithSpreadsheetCompatibility(static function () use ($path): array {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        $spreadsheet = $reader->load($path);
        try {
            $candidateSheets = [];
            $pagosSheet = $spreadsheet->getSheetByName('Pagos');
            if ($pagosSheet !== null) {
                $candidateSheets[] = $pagosSheet;
            }
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                if ($sheet !== $pagosSheet) {
                    $candidateSheets[] = $sheet;
                }
            }

            $fallbackRows = [];
            foreach ($candidateSheets as $sheet) {
                $sheetRows = $sheet->toArray(null, true, true, false);
                if ($fallbackRows === [] && is_array($sheetRows)) {
                    $fallbackRows = $sheetRows;
                }
                [$headerIndex, $headerMap] = rpcPagoContratoImportLocateHeaderRow(is_array($sheetRows) ? $sheetRows : []);
                if (
                    $headerIndex !== null
                    && rpcPagoContratoImportFindColumn($headerMap, ['arrendatario', 'id_arrendatario', 'rut_arrendatario', 'rut']) !== null
                    && rpcPagoContratoImportFindColumn($headerMap, ['contrato', 'id_contrato', 'id_contrato_arriendo']) !== null
                    && rpcPagoContratoImportFindColumn($headerMap, ['monto', 'monto_pagado']) !== null
                    && rpcPagoContratoImportFindColumn($headerMap, ['fecha', 'fecha_pago']) !== null
                    && rpcPagoContratoImportFindColumn($headerMap, ['medio_de_pago', 'medio_pago', 'medio']) !== null
                ) {
                    return $sheetRows;
                }
            }

            return $fallbackRows;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    });
}

function rpcPagoContratoImportFetchContractMap(PDO $conn, array $contractIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $contractIds), static fn(int $id): bool => $id > 0)));
    if ($ids === []) {
        return [];
    }

    $ph = [];
    foreach ($ids as $i => $id) {
        $ph[] = ':c_' . $i;
    }

    $sql = "SELECT
                c.id_contrato_arriendo,
                c.id_arrendatario,
                UPPER(LTRIM(RTRIM(ISNULL(a.rut, '')))) AS rut_arrendatario,
                COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
            FROM dbo.msp_contratos_arriendo c
            INNER JOIN dbo.msp_arrendatarios a
                ON a.id_arrendatario = c.id_arrendatario
            WHERE c.id_contrato_arriendo IN (" . implode(', ', $ph) . ")";

    $stmt = $conn->prepare($sql);
    foreach ($ids as $i => $id) {
        $stmt->bindValue(':c_' . $i, $id, PDO::PARAM_INT);
    }
    $stmt->execute();

    $map = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $id = (int) ($row['id_contrato_arriendo'] ?? 0);
        if ($id > 0) {
            $map[$id] = $row;
        }
    }
    return $map;
}

function rpcPagoContratoImportIsRowEmpty(array $row): bool
{
    foreach ($row as $value) {
        if (rpcPagoContratoImportCellString($value) !== '') {
            return false;
        }
    }
    return true;
}

try {
    $rows = rpcPagoContratoImportReadRows($uploadTmpPath);
} catch (Throwable) {
    msp2SetFlash('danger', 'No fue posible leer el archivo de importación.');
    msp2Redirect($redirectTarget);
}

if ($rows === [] || !is_array($rows[0] ?? null)) {
    msp2SetFlash('warning', 'El archivo está vacío.');
    msp2Redirect($redirectTarget);
}

[$headerRowIndex, $headerMap] = rpcPagoContratoImportLocateHeaderRow($rows);
$headerRowIndex = is_int($headerRowIndex) ? $headerRowIndex : 0;
$colArrendatario = rpcPagoContratoImportFindColumn($headerMap, ['arrendatario', 'id_arrendatario', 'rut_arrendatario', 'rut']);
$colContrato = rpcPagoContratoImportFindColumn($headerMap, ['contrato', 'id_contrato', 'id_contrato_arriendo']);
$colMonto = rpcPagoContratoImportFindColumn($headerMap, ['monto', 'monto_pagado']);
$colFecha = rpcPagoContratoImportFindColumn($headerMap, ['fecha', 'fecha_pago']);
$colMedio = rpcPagoContratoImportFindColumn($headerMap, ['medio_de_pago', 'medio_pago', 'medio']);
$colRef = rpcPagoContratoImportFindColumn($headerMap, ['ref', 'referencia', 'referencia_pago']);
$colBanco = rpcPagoContratoImportFindColumn($headerMap, ['banco', 'banco_cheque']);

if ($colArrendatario === null || $colContrato === null || $colMonto === null || $colFecha === null || $colMedio === null) {
    $missingColumns = [];
    foreach ([
        'Arrendatario' => $colArrendatario,
        'Contrato' => $colContrato,
        'Monto' => $colMonto,
        'Fecha' => $colFecha,
        'Medio de pago' => $colMedio,
    ] as $columnLabel => $columnIndex) {
        if ($columnIndex === null) {
            $missingColumns[] = $columnLabel;
        }
    }
    msp2SetFlash('warning', 'Faltan columnas requeridas: ' . implode(', ', $missingColumns) . '.');
    msp2Redirect($redirectTarget);
}

$parsedRows = [];
$contractIds = [];

foreach (array_slice($rows, $headerRowIndex + 1) as $idx => $row) {
    if (!is_array($row) || rpcPagoContratoImportIsRowEmpty($row)) {
        continue;
    }

    $arrRaw = rpcPagoContratoImportCellString($row[$colArrendatario] ?? '');
    $contratoRaw = rpcPagoContratoImportCellString($row[$colContrato] ?? '');
    $fechaPago = rpcPagoContratoImportParseDate($row[$colFecha] ?? null);
    [$okMonto, $montoPagado] = rpcPagoContratoImportParseAmount($row[$colMonto] ?? null);
    $medioPago = rpcPagoContratoImportNormalizeMedioPago((string) ($row[$colMedio] ?? ''));
    $referenciaPago = $colRef !== null ? rpcPagoContratoImportCellString($row[$colRef] ?? '') : '';
    $bancoCheque = $colBanco !== null ? rpcPagoContratoImportCellString($row[$colBanco] ?? '') : '';

    $contratoId = (preg_match('/^\d+$/', $contratoRaw) === 1) ? (int) $contratoRaw : 0;
    if ($contratoId > 0) {
        $contractIds[] = $contratoId;
    }

    $parsedRows[] = [
        'status' => 'PENDING',
        'error' => null,
        'row_number' => $headerRowIndex + $idx + 2,
        'arrendatario_raw' => $arrRaw,
        'id_contrato_arriendo' => $contratoId,
        'fecha_pago' => $fechaPago ?? '',
        'monto_pagado' => $okMonto && $montoPagado !== null ? round((float) $montoPagado, 2) : 0.0,
        'medio_pago' => $medioPago ?? '',
        'referencia_pago' => $referenciaPago,
        'banco_cheque' => $bancoCheque,
    ];
}

$contractMap = rpcPagoContratoImportFetchContractMap($conn, $contractIds);
$validRows = [];

foreach ($parsedRows as $i => $row) {
    $error = null;
    $arrRaw = trim((string) ($row['arrendatario_raw'] ?? ''));
    $contratoId = (int) ($row['id_contrato_arriendo'] ?? 0);
    $medioPago = (string) ($row['medio_pago'] ?? '');
    $referenciaPago = trim((string) ($row['referencia_pago'] ?? ''));
    $bancoCheque = trim((string) ($row['banco_cheque'] ?? ''));

    if ($arrRaw === '') {
        $error = 'Arrendatario requerido.';
    } elseif ($contratoId <= 0) {
        $error = 'Contrato inválido.';
    } elseif (($row['fecha_pago'] ?? '') === '') {
        $error = 'Fecha de pago inválida.';
    } elseif ((float) ($row['monto_pagado'] ?? 0) <= 0) {
        $error = 'Monto inválido.';
    } elseif ($medioPago === '') {
        $error = 'Medio de pago inválido.';
    } elseif (!isset($contractMap[$contratoId])) {
        $error = 'Contrato no existe.';
    } else {
        $cRow = $contractMap[$contratoId];
        $idArrContrato = (int) ($cRow['id_arrendatario'] ?? 0);
        $rutContrato = strtoupper(trim((string) ($cRow['rut_arrendatario'] ?? '')));
        $nombreContrato = msp2NormalizeLookupKey((string) ($cRow['nombre_arrendatario'] ?? ''));
        $arrLookup = msp2NormalizeLookupKey($arrRaw);

        $arrOk = false;
        if (preg_match('/^\d+$/', $arrRaw) === 1) {
            $arrOk = ((int) $arrRaw) === $idArrContrato;
        } else {
            $arrRutNorm = strtoupper(str_replace(['.', ' '], '', $arrRaw));
            $rutNorm = str_replace(['.', ' '], '', $rutContrato);
            $arrOk = ($arrRutNorm !== '' && $arrRutNorm === $rutNorm)
                || ($arrLookup !== '' && $nombreContrato !== '' && str_contains($nombreContrato, $arrLookup));
        }

        if (!$arrOk) {
            $error = 'Arrendatario no coincide con el contrato.';
        } elseif ($medioPago === 'Cheque' && $referenciaPago === '') {
            $error = 'Para cheque debes informar referencia (N° cheque).';
        } elseif ($medioPago === 'Cheque' && $bancoCheque === '') {
            $error = 'Para cheque debes informar banco.';
        } else {
            $parsedRows[$i]['id_arrendatario'] = $idArrContrato;
            $parsedRows[$i]['arrendatario_nombre'] = (string) ($cRow['nombre_arrendatario'] ?? '');
        }
    }

    if ($error !== null) {
        $parsedRows[$i]['status'] = 'ERROR';
        $parsedRows[$i]['error'] = $error;
        continue;
    }

    $parsedRows[$i]['status'] = 'OK';
    $validRows[] = [
        'row_number' => (int) ($row['row_number'] ?? 0),
        'id_arrendatario' => (int) ($parsedRows[$i]['id_arrendatario'] ?? 0),
        'id_contrato_arriendo' => $contratoId,
        'fecha_pago' => (string) ($row['fecha_pago'] ?? ''),
        'monto_pagado' => number_format((float) ($row['monto_pagado'] ?? 0), 2, '.', ''),
        'medio_pago' => (string) ($row['medio_pago'] ?? ''),
        'referencia_pago' => (string) ($row['referencia_pago'] ?? ''),
        'banco_cheque' => (string) ($row['banco_cheque'] ?? ''),
        'arrendatario_nombre' => (string) ($parsedRows[$i]['arrendatario_nombre'] ?? ''),
    ];
}

rpcPagoContratoImportPreviewClear();
rpcPagoContratoImportPreviewWrite([
    'original_name' => $originalName,
    'created_at' => date('c'),
    'volver_query' => $volverQuery,
    'rows' => $parsedRows,
    'valid_rows' => $validRows,
]);

$summary = rpcPagoContratoImportSummary(['rows' => $parsedRows]);
if (($summary['ok_rows'] ?? 0) <= 0) {
    msp2SetFlash('warning', 'No hay filas válidas para importar.');
} elseif (($summary['error_rows'] ?? 0) > 0) {
    msp2SetFlash('warning', 'Previsualización lista con errores. Puedes confirmar e importar solo filas OK.');
} else {
    msp2SetFlash('success', 'Previsualización lista: ' . (int) $summary['ok_rows'] . ' filas OK.');
}

msp2Redirect($redirectTarget);
