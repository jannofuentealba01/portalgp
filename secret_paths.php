<?php
declare(strict_types=1);

/** Returns the local secret store, which must remain outside htdocs. */
function pgpSecretsDirectory(): string
{
    $configured = getenv('PORTALGP_SECRETS_DIR');
    $directory = is_string($configured) && trim($configured) !== ''
        ? trim($configured)
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'portalgp_secrets';

    $normalized = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
    $webRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, __DIR__), DIRECTORY_SEPARATOR);
    if ($normalized === '' || str_starts_with(strtolower($normalized . DIRECTORY_SEPARATOR), strtolower($webRoot . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('El almacén de secretos debe estar fuera del directorio público de PortalGP.');
    }
    return $normalized;
}

function pgpSecretConfigPath(string $name): string
{
    if (preg_match('/^[a-z][a-z0-9_]*\.php$/', $name) !== 1) {
        throw new InvalidArgumentException('Nombre de configuración secreta no permitido.');
    }
    return pgpSecretsDirectory() . DIRECTORY_SEPARATOR . $name;
}

/** @return array<string,mixed> */
function pgpLoadSecretConfig(string $name): array
{
    $path = pgpSecretConfigPath($name);
    if (!is_file($path)) {
        return [];
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('La configuración externa no tiene un formato válido.');
    }
    return $loaded;
}
