<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/url_helper.php';

$originalEnv = getenv('PORTALGP_CANONICAL_BASE_URL');
$originalTrustProxy = getenv('PORTALGP_TRUST_PROXY_HEADERS');
$originalServer = $_SERVER;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    putenv('PORTALGP_CANONICAL_BASE_URL=https://portal.example.test');
    $_SERVER['HTTP_HOST'] = 'host-inyectado.example';
    $_SERVER['SERVER_NAME'] = 'servidor-interno';
    $_SERVER['SERVER_PORT'] = '80';
    $assert(
        pgpCanonicalBaseUrl() === 'https://portal.example.test',
        'La URL canónica debe prevalecer sobre Host.'
    );

    putenv('PORTALGP_CANONICAL_BASE_URL');
    putenv('PORTALGP_TRUST_PROXY_HEADERS');
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '8080';
    $_SERVER['HTTP_HOST'] = 'host-inyectado.example';
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $assert(
        pgpCanonicalBaseUrl() === 'http://localhost:8080',
        'Sin configuración no debe confiar en Host ni X-Forwarded-Proto.'
    );

    putenv('PORTALGP_TRUST_PROXY_HEADERS=true');
    $assert(
        pgpCanonicalBaseUrl() === 'https://localhost:8080',
        'X-Forwarded-Proto solo debe aceptarse al habilitar un proxy confiable.'
    );
} finally {
    $originalEnv === false
        ? putenv('PORTALGP_CANONICAL_BASE_URL')
        : putenv('PORTALGP_CANONICAL_BASE_URL=' . $originalEnv);
    $originalTrustProxy === false
        ? putenv('PORTALGP_TRUST_PROXY_HEADERS')
        : putenv('PORTALGP_TRUST_PROXY_HEADERS=' . $originalTrustProxy);
    $_SERVER = $originalServer;
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK security_stage5_url\n";
