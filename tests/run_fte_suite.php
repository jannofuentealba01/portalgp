<?php
declare(strict_types=1);

/**
 * Ejecutor unico de las pruebas FTE.
 *
 * Uso normal (pruebas puras):
 *   php tests/run_fte_suite.php
 *
 * Incluyendo pruebas transaccionales contra PORTALGP local:
 *   php tests/run_fte_suite.php --with-db
 */
$withDatabase = in_array('--with-db', $argv, true);
$self = basename(__FILE__);
$files = glob(__DIR__ . '/fte_*.php') ?: [];
sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$files = array_values(array_filter($files, static function (string $file) use ($self, $withDatabase): bool {
    $name = basename($file);
    if ($name === $self || $name === 'fte_compare_worker.php') {
        return false;
    }
    if (!$withDatabase && str_ends_with($name, '_db.php')) {
        return false;
    }
    return true;
}));

$failed = [];
foreach ($files as $file) {
    $name = basename($file);
    echo "\n=== {$name} ===\n";
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed[] = $name;
    }
}

echo "\n=== RESUMEN FTE ===\n";
echo 'Suites ejecutadas: ' . count($files) . "\n";
if ($failed !== []) {
    echo 'Suites fallidas: ' . implode(', ', $failed) . "\n";
    exit(1);
}
echo "Todas las suites FTE finalizaron correctamente.\n";
exit(0);
