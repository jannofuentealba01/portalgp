<?php
declare(strict_types=1);

$envString = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false || trim((string) $value) === '' ? $default : trim((string) $value);
};

$envBool = static function (string $key, bool $default): bool {
    $value = getenv($key);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
};

$defaults = [
    'server' => 'localhost',
    'database' => 'PORTALGP',
    'username' => '',
    'password' => '',
    'encrypt' => false,
    'trust_server_certificate' => true,
    'environment' => 'local-development',
];

$localPath = __DIR__ . '/database.local.php';
if (is_file($localPath)) {
    $localConfig = require $localPath;
    if (is_array($localConfig)) {
        $defaults = array_replace($defaults, $localConfig);
    }
}

return [
    'server' => $envString('PORTALGP_DB_SERVER', (string) $defaults['server']),
    'database' => $envString('PORTALGP_DB_DATABASE', (string) $defaults['database']),
    'username' => $envString('PORTALGP_DB_USERNAME', (string) $defaults['username']),
    'password' => $envString('PORTALGP_DB_PASSWORD', (string) $defaults['password']),
    'encrypt' => $envBool('PORTALGP_DB_ENCRYPT', (bool) $defaults['encrypt']),
    'trust_server_certificate' => $envBool('PORTALGP_DB_TRUST_SERVER_CERTIFICATE', (bool) $defaults['trust_server_certificate']),
    'environment' => $envString('PORTALGP_ENV', (string) $defaults['environment']),
];
