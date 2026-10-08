<?php
declare(strict_types=1);

/**
 * Devuelve el origen canónico de PortalGP.
 *
 * En producción debe definirse PORTALGP_CANONICAL_BASE_URL. Si no existe,
 * se usa SERVER_NAME (configuración del servidor) y nunca HTTP_HOST.
 */
function pgpCanonicalBaseUrl(): string
{
    $configured = trim((string) (getenv('PORTALGP_CANONICAL_BASE_URL') ?: ''));
    if ($configured !== '') {
        $parts = parse_url($configured);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        $hasUnexpectedParts = isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ($path !== '' && $path !== '/');

        if (in_array($scheme, ['http', 'https'], true) && $host !== '' && !$hasUnexpectedParts) {
            $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
            return $scheme . '://' . $host . $port;
        }

        error_log('[PortalGP] PORTALGP_CANONICAL_BASE_URL no es una URL de origen válida.');
    }

    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $isHttps = ($https !== '' && strtolower($https) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    $trustProxy = filter_var(
        getenv('PORTALGP_TRUST_PROXY_HEADERS') ?: false,
        FILTER_VALIDATE_BOOL
    );
    if ($trustProxy) {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $isHttps = $forwardedProto === 'https';
        }
    }

    $host = trim((string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (!preg_match('/\A(?:[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?|\[[0-9a-f:]+\])\z/i', $host)) {
        $host = 'localhost';
    }

    $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    $includePort = $port > 0
        && !(($isHttps && $port === 443) || (!$isHttps && $port === 80));

    return ($isHttps ? 'https' : 'http') . '://' . $host . ($includePort ? ':' . $port : '');
}
