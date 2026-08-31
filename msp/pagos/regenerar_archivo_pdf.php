<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/archivos_pdf_helper.php';

msp2RequireAccess();

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    msp2Redirect(msp2ArchivosPdfRedirectListUrl());
}

$idArchivo = filter_input(INPUT_POST, 'id_pago_contrato_archivo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idArchivo === false || $idArchivo === null) {
    msp2SetFlash('warning', 'Debes indicar un archivo válido para regenerar.');
    msp2Redirect(msp2ArchivosPdfRedirectListUrl());
}

try {
    $row = msp2ArchivosPdfFindById($conn, (int) $idArchivo);
    if (!is_array($row)) {
        throw new RuntimeException('El archivo solicitado no existe.');
    }
    $originValidation = msp2ArchivosPdfValidateRegenerationOrigin($conn, $row);
    if (!((bool) ($originValidation['ok'] ?? false))) {
        msp2SetFlash('warning', (string) ($originValidation['reason'] ?? 'No se puede regenerar el archivo solicitado.'));
        msp2Redirect(msp2ArchivosPdfRedirectListUrl());
    }

    msp2ArchivosPdfRefreshMaterialized($conn, $row, true);
    msp2SetFlash('success', 'Archivo regenerado correctamente.');
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible regenerar el archivo solicitado.');
}

msp2Redirect(msp2ArchivosPdfRedirectListUrl());
