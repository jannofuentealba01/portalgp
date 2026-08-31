<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/respaldo_excel_helper.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('pagos/index.php');
}

$previewPayload = msp2PagosPreviewSessionRead();
if (!is_array($previewPayload)) {
    msp2SetFlash('warning', 'No hay una previsualización pendiente para limpiar.');
    msp2Redirect('pagos/index.php');
}

$rows = is_array($previewPayload['rows'] ?? null) ? $previewPayload['rows'] : [];
if ($rows === []) {
    msp2SetFlash('warning', 'La previsualización no contiene filas para limpiar.');
    msp2Redirect('pagos/index.php');
}

$rowsOk = [];
$rowsError = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ((string) ($row['status'] ?? '') === 'OK') {
        $rowsOk[] = $row;
    } else {
        $rowsError[] = $row;
    }
}

if ($rowsError === []) {
    msp2SetFlash('info', 'La previsualización ya estaba limpia (sin filas con error).');
    msp2Redirect('pagos/index.php');
}

$validRows = is_array($previewPayload['valid_rows'] ?? null) ? $previewPayload['valid_rows'] : [];

msp2PagosPreviewSessionWrite([
    'version' => (string) ($previewPayload['version'] ?? msp2PagosBackupVersion()),
    'original_name' => (string) ($previewPayload['original_name'] ?? 'respaldo.xlsx'),
    'created_at' => (string) ($previewPayload['created_at'] ?? date('c')),
    'rows' => $rowsOk,
    'valid_rows' => $validRows,
]);

msp2SetFlash(
    'success',
    'Preview limpiado: se descartaron ' . count($rowsError) . ' fila(s) con error y quedaron ' . count($rowsOk) . ' fila(s) OK.'
);
msp2Redirect('pagos/index.php');

