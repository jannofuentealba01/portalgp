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
    'metadata usa caché APCu compartido con expiración segura' => str_contains($bootstrap, 'function msp2SchemaMetadataSharedCacheAvailable(')
        && str_contains($bootstrap, 'apcu_fetch($sharedCacheKey, $cacheHit)')
        && str_contains($bootstrap, 'apcu_store($sharedCacheKey, $metadata, 300)')
        && str_contains($bootstrap, 'apcu_delete(msp2SchemaMetadataSharedCacheKey())'),
    'Control Diario usa rango mensual visible' => str_contains($controlDiario, '$dataPeriodStart')
        && str_contains($controlDiario, '$dataPeriodEndExclusive'),
    'Control Diario no filtra datos con YEAR(columna)' => preg_match('/WHERE\s+YEAR\s*\(/i', $controlDiario) !== 1,
    'permisos de corrección se calculan fuera de ciclos' => substr_count($controlDiario, 'msp2CurrentUserHasPermission(') === 3,
    'dependencias financieras se agrupan antes de unir' => str_contains($controlDiario, '$protectedDocumentSources')
        && str_contains($controlDiario, 'protected_doc.id_documento_cobro=doc.id_documento_cobro'),
    'Control Diario limita sus viajes SQL a ocho por carga' => substr_count($controlDiario, '$conn->prepare(') === 4
        && substr_count($controlDiario, '$conn->query(') === 1
        && substr_count($controlDiario, 'msp2FetchReadBatch($conn,') === 3,
    'lecturas agrupadas conservan resultados independientes' => str_contains($bootstrap, 'function msp2FetchReadBatch(')
        && str_contains($bootstrap, 'final class Msp2BufferedReadRows'),
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
