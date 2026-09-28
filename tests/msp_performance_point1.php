<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$security = file_get_contents($root . '/security.php');
$bootstrap = file_get_contents($root . '/msp/bootstrap.php');
$controlDiario = file_get_contents($root . '/msp/control_diario/index.php');

if (!is_string($security) || !is_string($bootstrap) || !is_string($controlDiario)) {
    throw new RuntimeException('No fue posible leer los archivos de optimización MSP.');
}

$assertions = [
    'sesión memoizada por solicitud' => str_contains($security, "['validated_sessions']"),
    'permisos completos memoizados por usuario' => str_contains($security, 'function pgpUserPermissionMap(')
        && str_contains($security, "['user_permissions']"),
    'MSP usa la matriz común de permisos' => str_contains($bootstrap, 'pgpUserPermissionMap($GLOBALS[\'conn\'], $idUsuario)'),
    'metadata se obtiene en una consulta agrupada' => str_contains($bootstrap, "SELECT N'TABLE' AS object_kind")
        && str_contains($bootstrap, "SELECT N'COLUMN'")
        && str_contains($bootstrap, "SELECT N'PROCEDURE'"),
    'Control Diario usa rango mensual visible' => str_contains($controlDiario, '$dataPeriodStart')
        && str_contains($controlDiario, '$dataPeriodEndExclusive'),
    'Control Diario no filtra datos con YEAR(columna)' => preg_match('/WHERE\s+YEAR\s*\(/i', $controlDiario) !== 1,
    'permisos de corrección se calculan fuera de ciclos' => substr_count($controlDiario, 'msp2CurrentUserHasPermission(') === 3,
    'dependencias financieras se agrupan antes de unir' => str_contains($controlDiario, '$protectedDocumentSources')
        && str_contains($controlDiario, 'protected_doc.id_documento_cobro=doc.id_documento_cobro'),
];

$failures = [];
foreach ($assertions as $label => $passed) {
    if (!$passed) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: ' . count($assertions) . " regresiones de rendimiento MSP verificadas." . PHP_EOL;
