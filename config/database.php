<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/secret_paths.php';

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
    'encrypt' => true,
    'trust_server_certificate' => true,
    'environment' => 'local-development',
];

$defaults = array_replace($defaults, pgpLoadSecretConfig('database.php'));

$useIntegrated = $envBool('PORTALGP_DB_INTEGRATED', false);

return [
    'server' => $envString('PORTALGP_DB_SERVER', (string) $defaults['server']),
    'database' => $envString('PORTALGP_DB_DATABASE', (string) $defaults['database']),
    'username' => $useIntegrated ? '' : $envString('PORTALGP_DB_USERNAME', (string) $defaults['username']),
    'password' => $useIntegrated ? '' : $envString('PORTALGP_DB_PASSWORD', (string) $defaults['password']),
    'encrypt' => $envBool('PORTALGP_DB_ENCRYPT', (bool) $defaults['encrypt']),
    'trust_server_certificate' => $envBool('PORTALGP_DB_TRUST_SERVER_CERTIFICATE', (bool) $defaults['trust_server_certificate']),
    'environment' => $envString('PORTALGP_ENV', (string) $defaults['environment']),
];
