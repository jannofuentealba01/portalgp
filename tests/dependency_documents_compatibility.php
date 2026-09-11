<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/msp/bootstrap.php';
require_once $root . '/msp/cobranza/pago_contrato_import_helper.php';
require_once $root . '/msp/pagos/respaldo_excel_helper.php';
require_once $root . '/ct/bootstrap.php';
require_once $root . '/ct/predial/terceros/terceros_import_service.php';
require_once $root . '/msp/documentos_cobro/vale_lib.php';
require_once $root . '/msp/pagos/archivos_pdf_helper.php';

$checks = 0;
$assert = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $checks++;
    echo 'OK: ' . $label . PHP_EOL;
};

$runExport = static function (string $mode, array $query = []) use ($root): string {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $root . '/tests/dependency_export_worker.php', $mode, json_encode($query, JSON_THROW_ON_ERROR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar la exportación ' . $mode . '.');
    }
    fclose($pipes[0]);
    $bytes = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || !is_string($bytes) || $bytes === '') {
        throw new RuntimeException('Falló ' . $mode . ': ' . trim((string)$stderr));
    }
    if (trim((string)$stderr) !== '') {
        throw new RuntimeException('La exportación ' . $mode . ' escribió un diagnóstico: ' . trim((string)$stderr));
    }
    return $bytes;
};

$withTempFile = static function (string $bytes, string $extension, callable $callback): mixed {
    $path = tempnam(sys_get_temp_dir(), 'pgp_dep_');
    if ($path === false) {
        throw new RuntimeException('No se pudo crear archivo temporal.');
    }
    $target = $path . '.' . ltrim($extension, '.');
    @unlink($path);
    file_put_contents($target, $bytes);
    try {
        return $callback($target);
    } finally {
        @unlink($target);
    }
};

msp2LoadSpreadsheetLibrary();
$spreadsheetVersion = \Composer\InstalledVersions::getPrettyVersion('phpoffice/phpspreadsheet');
$dompdfVersion = \Composer\InstalledVersions::getPrettyVersion('dompdf/dompdf');
$assert($spreadsheetVersion === '5.9.0', 'PhpSpreadsheet 5.9.0 cargado por Composer');
$assert($dompdfVersion === 'v3.1.6', 'Dompdf 3.1.6 cargado por Composer');

$xlsxCases = [
    'arrendatarios_template' => [],
    'tiendas_template' => [],
    'locales_template' => [],
    'medidores_template' => [],
    'medidores_import_template' => [],
    'pagos_contrato_template' => [],
    'terceros_template' => ['descargar_plantilla' => '1'],
    'lecturas_luz' => ['__mode' => 'lecturas_template', 'servicio' => 'LUZ', 'periodo' => '2026-06'],
    'lecturas_gas' => ['__mode' => 'lecturas_template', 'servicio' => 'GAS', 'periodo' => '2026-06'],
    'lecturas_agua' => ['__mode' => 'lecturas_template', 'servicio' => 'AGUA', 'periodo' => '2026-06'],
    'pagos_backup' => [],
    'reporte_agua_xlsx' => ['__mode' => 'agua_report', 'periodo' => '2026-04', 'format' => 'xlsx'],
    'reporte_luz_xlsx' => ['__mode' => 'luz_report', 'servicio' => 'LUZ', 'periodo' => '2026-05', 'format' => 'xlsx', 'anadido_siguiente' => '1'],
    'reporte_gas_xlsx' => ['__mode' => 'gas_report', 'servicio' => 'GAS', 'periodo' => '2026-05', 'format' => 'xlsx', 'anadido_siguiente' => '1'],
];

$xlsxBytes = [];
foreach ($xlsxCases as $label => $query) {
    $mode = (string)($query['__mode'] ?? $label);
    unset($query['__mode']);
    $bytes = $runExport($mode, $query);
    $xlsxBytes[$label] = $bytes;
    $valid = $withTempFile($bytes, 'xlsx', static function (string $path): bool {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        try {
            return $book->getSheetCount() >= 1 && $book->getActiveSheet()->getHighestDataRow() >= 1;
        } finally {
            $book->disconnectWorksheets();
        }
    });
    $assert(str_starts_with($bytes, 'PK') && $valid === true, $label . ' genera XLSX legible');
}

$withTempFile($xlsxBytes['pagos_backup'], 'xlsx', static function (string $path) use ($assert): void {
    $sheets = msp2PagosBackupReadSheets($path);
    $assert(isset($sheets['README'], $sheets['Pagos'], $sheets['DetalleConceptos']), 'respaldo de pagos reimporta sus tres hojas');
    $assert(($sheets['Pagos'][0][0] ?? null) === 'version', 'respaldo de pagos conserva cabeceras');
});

$withTempFile($xlsxBytes['terceros_template'], 'xlsx', static function (string $path) use ($assert): void {
    $raw = ctTercerosImportParseXlsx($path);
    $mapped = ctTercerosImportExtractMappedRows($raw);
    $assert(count($mapped) === 2 && ($mapped[0]['rut'] ?? '') === '12345678-5', 'plantilla CT vuelve a ingresar por el lector XLSX');
});

$withTempFile($xlsxBytes['pagos_contrato_template'], 'xlsx', static function (string $path) use ($assert): void {
    $rows = msp2ReadSpreadsheetRows($path);
    [, $map] = rpcPagoContratoImportLocateHeaderRow($rows);
    $required = [
        rpcPagoContratoImportFindColumn($map, ['arrendatario']),
        rpcPagoContratoImportFindColumn($map, ['contrato']),
        rpcPagoContratoImportFindColumn($map, ['monto']),
        rpcPagoContratoImportFindColumn($map, ['fecha']),
        rpcPagoContratoImportFindColumn($map, ['medio_de_pago']),
    ];
    $assert(!in_array(null, $required, true), 'plantilla de pagos conserva todas las columnas requeridas');
});

$pdfCases = [
    'reporte_agua_pdf' => ['agua_report', ['periodo' => '2026-04', 'format' => 'pdf']],
    'reporte_luz_pdf' => ['luz_report', ['servicio' => 'LUZ', 'periodo' => '2026-05', 'format' => 'pdf', 'anadido_siguiente' => '1']],
    'reporte_gas_pdf' => ['gas_report', ['servicio' => 'GAS', 'periodo' => '2026-05', 'format' => 'pdf', 'anadido_siguiente' => '1']],
    'aging_pdf' => ['aging_pdf', ['periodo' => '2026-05', 'corte_aging' => '2026-05-31']],
];
foreach ($pdfCases as $label => [$mode, $query]) {
    $bytes = $runExport($mode, $query);
    $assert(str_starts_with($bytes, '%PDF-') && strlen($bytes) > 1000, $label . ' genera PDF válido');
}

$idDocumento = (int)$conn->query('SELECT TOP 1 id_documento_cobro FROM dbo.msp_documentos_cobro ORDER BY id_documento_cobro DESC')->fetchColumn();
[$valeName, $valePdf] = msp2BuildDocumentoCobroValePdf($conn, $idDocumento);
[$resumenName, $resumenPdf] = msp2BuildDocumentoCobroValeResumenPdf($conn, $idDocumento);
$assert($valeName !== '' && str_starts_with($valePdf, '%PDF-'), 'vale de documento de cobro compatible con Dompdf');
$assert($resumenName !== '' && str_starts_with($resumenPdf, '%PDF-'), 'resumen de documento de cobro compatible con Dompdf');

$payment = [
    'id_pago' => 999,
    'fecha_pago' => '2026-09-04',
    'medio_pago' => 'TRANSFERENCIA',
    'referencia_pago' => 'TEST',
    'monto_pagado' => 150000,
    'monto_aplicado' => 150000,
    'detalle_conceptos' => [
        ['codigo_item' => 'ARRIENDO', 'nombre_item' => 'Arriendo', 'monto' => 100000],
        ['codigo_item' => 'LUZ', 'nombre_item' => 'Electricidad', 'monto' => 50000],
    ],
];
$tenant = ['nombre_arrendatario' => 'Arrendatario de prueba', 'rut' => '76123456-7'];
$document = ['numero_documento' => 'DOC-TEST', 'periodo_ym' => '2026-09', 'locales_contrato' => 'A-1', 'saldo_pendiente_nuevo' => 0];
foreach (['vale_pago', 'comprobante_gastos'] as $type) {
    $result = msp2ArchivosPdfBuildPdf($conn, $type, ['pago_data' => $payment, 'arr_data' => $tenant, 'doc_data' => $document]);
    $assert(str_starts_with((string)($result['bytes'] ?? ''), '%PDF-'), $type . ' compatible con Dompdf');
}

echo 'RESULTADO: ' . $checks . ' comprobaciones correctas.' . PHP_EOL;
