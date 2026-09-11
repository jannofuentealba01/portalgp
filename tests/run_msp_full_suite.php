<?php
declare(strict_types=1);

/* Ejecutor único de regresión funcional, financiera y sintáctica de MSP. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$php = PHP_BINARY;
$failed = 0;
$executed = 0;

$run = static function (string $label, array $command) use (&$failed, &$executed): void {
    $executed++;
    echo PHP_EOL . '===== ' . $label . ' =====' . PHP_EOL;
    $parts = array_map(static fn(string $part): string => escapeshellarg($part), $command);
    passthru(implode(' ', $parts), $status);
    if ($status !== 0) {
        $failed++;
        echo '[FAIL] ' . $label . ' terminó con código ' . $status . PHP_EOL;
    }
};

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/msp', FilesystemIterator::SKIP_DOTS)
);
$phpFiles = [];
foreach ($iterator as $file) {
    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}
sort($phpFiles, SORT_NATURAL | SORT_FLAG_CASE);

echo 'Validando sintaxis de ' . count($phpFiles) . ' archivos PHP de MSP...' . PHP_EOL;
foreach ($phpFiles as $file) {
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($file), $output, $status);
    if ($status !== 0) {
        $failed++;
        echo '[FAIL] Sintaxis: ' . str_replace('\\', '/', substr($file, strlen($root) + 1)) . PHP_EOL;
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
    $output = [];
}
if ($failed === 0) {
    echo '[OK] Todos los archivos PHP de MSP tienen sintaxis válida.' . PHP_EOL;
}

foreach ([
    'Integridad comercial y financiera' => 'msp_commercial_financial_integrity.php',
    'Humo financiero' => 'msp_financial_smoke.php',
    'Regresión MSP' => 'msp_regression_suite.php',
    'Prioridad de pagos' => 'msp_prioridad_imputacion_pagos.php',
    'Saldo a favor de período futuro' => 'msp_saldo_favor_periodo_futuro.php',
    'Auditoría histórica de saldo a favor' => 'msp_saldo_favor_historico_audit.php',
    'Búsqueda unificada etapa 1' => 'msp_search_stage1.php',
    'Búsqueda unificada etapa 2' => 'msp_search_stage2.php',
    'Seguridad financiera etapa 2' => 'security_financial_stage2.php',
    'Seguridad financiera etapa 4' => 'security_financial_stage4_points1_3.php',
    'Seguridad de eliminación, regeneración y reapertura' => 'security_financial_stage4_rollback_regeneration.php',
] as $label => $script) {
    $run($label, [$php, __DIR__ . '/' . $script]);
}

echo PHP_EOL . '===== RESUMEN MSP =====' . PHP_EOL;
echo 'Suites ejecutadas: ' . $executed . PHP_EOL;
echo 'Fallos de sintaxis o suites: ' . $failed . PHP_EOL;
exit($failed === 0 ? 0 : 1);
