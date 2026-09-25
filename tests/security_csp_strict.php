<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/security.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};

$root = dirname(__DIR__);
$nonce = pgpCspNonce();
$handler = 'window.__pgpInjected=true';
$inlineStyle = 'background:url(https://attacker.invalid/csp-test)';
$sample = '<!doctype html><html><head><style>.sample{display:block}</style></head>'
    . '<body><button style="' . $inlineStyle . '" onclick="' . $handler . '">Aceptar</button>'
    . '<script>window.__pgpInjected=true;</script></body></html>';
$prepared = pgpCspPrepareHtml($sample, $nonce);
$policy = pgpCspPolicy(
    $nonce,
    $prepared['script_attribute_hashes'],
    $prepared['style_attribute_hashes']
);

$assert(!str_contains($policy, "'unsafe-inline'"), 'la política no contiene unsafe-inline');
$assert(str_contains($policy, "script-src 'self' 'nonce-{$nonce}'"), 'script-src exige el nonce de la respuesta');
$assert(str_contains($policy, "style-src 'self' 'nonce-{$nonce}'"), 'style-src exige el nonce de la respuesta');
$assert($prepared['html'] === $sample, 'el procesador final no modifica ni autoriza HTML inyectado');
$assert(substr_count($prepared['html'], 'nonce="' . $nonce . '"') === 0, 'un script o estilo inyectado no recibe nonce');
$assert(
    !str_contains($policy, "'sha256-" . base64_encode(hash('sha256', $handler, true)) . "'"),
    'un evento ajeno a las plantillas no se autoriza'
);
$assert(
    !str_contains($policy, "'sha256-" . base64_encode(hash('sha256', $inlineStyle, true)) . "'"),
    'un estilo ajeno a las plantillas no se autoriza'
);
$assert(
    pgpCspPolicy($nonce) !== ''
    && str_contains(pgpCspPolicy($nonce), "script-src-attr 'none'")
    && str_contains(pgpCspPolicy($nonce), "style-src-attr 'none'"),
    'sin atributos heredados la política los bloquea completamente'
);
$trustedHandler = "return confirm('¿Continuar?');";
$trustedStyle = 'max-height: 260px;';
$eventAttribute = pgpCspEventAttribute('onsubmit', $trustedHandler);
$styleAttribute = pgpCspStyleAttribute($trustedStyle);
$registered = pgpCspAttributeRegistry();
$trustedPolicy = pgpCspPolicy($nonce, $registered['script'], $registered['style']);
$assert(str_starts_with($eventAttribute, 'onsubmit='), 'la plantilla puede declarar un evento confiable exacto');
$assert(str_starts_with($styleAttribute, 'style='), 'la plantilla puede declarar un estilo confiable exacto');
$assert(
    str_contains($trustedPolicy, "'sha256-" . base64_encode(hash('sha256', $trustedHandler, true)) . "'"),
    'el evento declarado por PHP se autoriza mediante su hash exacto'
);
$assert(
    str_contains($trustedPolicy, "'sha256-" . base64_encode(hash('sha256', $trustedStyle, true)) . "'"),
    'el estilo declarado por PHP se autoriza mediante su hash exacto'
);
$assert(pgpCspNonce() === pgpCspNonce(), 'el nonce es estable dentro de una respuesta');
$assert(preg_match('/^[A-Za-z0-9_-]{20,}$/', pgpCspNonce()) === 1, 'el nonce tiene formato y entropía adecuados');

$htaccess = (string) file_get_contents($root . '/.htaccess');
$securitySource = (string) file_get_contents($root . '/security.php');
$assert(!str_contains($htaccess, "'unsafe-inline'"), '.htaccess no conserva unsafe-inline');
$assert(!str_contains($securitySource, "'unsafe-inline'"), 'security.php no conserva unsafe-inline');
$assert(str_contains($htaccess, '<FilesMatch "\\.(?:html?|svg)$">'), 'Apache protege documentos HTML y SVG estáticos');
$assert(
    is_file($root . '/config/csp_inline_attribute_hashes.php'),
    'existe la lista de hashes generada desde plantillas confiables'
);
$assert(
    is_file($root . '/scripts/csp_templates.php'),
    'existe la herramienta de mantenimiento de nonces y hashes'
);

$loginHtml = (string) file_get_contents('http://localhost/portalgp/login.php');
$livePolicy = '';
foreach ($http_response_header ?? [] as $headerLine) {
    if (stripos((string) $headerLine, 'Content-Security-Policy:') === 0) {
        $livePolicy = trim(substr((string) $headerLine, strlen('Content-Security-Policy:')));
    }
}
$assert($livePolicy !== '', 'login entrega CSP por HTTP');
$assert(!str_contains($livePolicy, "'unsafe-inline'"), 'la CSP HTTP real no contiene unsafe-inline');
$assert(preg_match("~'nonce-([A-Za-z0-9_-]+)'~", $livePolicy, $nonceMatch) === 1, 'la CSP HTTP real contiene un nonce');

$liveNonce = (string) ($nonceMatch[1] ?? '');
$inlineElements = preg_match_all('~<(?:script|style)\\b~i', $loginHtml);
$noncedElements = preg_match_all(
    '~<(?:script|style)\\b[^>]*\\bnonce=["\']' . preg_quote($liveNonce, '~') . '["\'][^>]*>~i',
    $loginHtml
);
$assert($inlineElements !== false && $inlineElements > 0, 'login contiene elementos cubiertos por CSP');
$assert($noncedElements === $inlineElements, 'todos los scripts y estilos de login usan el nonce anunciado');

$mockupHeaders = get_headers('http://localhost/portalgp/msp/portal_arrendatarios_mockup.html');
$mockupStatus = is_array($mockupHeaders) ? (string) ($mockupHeaders[0] ?? '') : '';
$assert(str_contains($mockupStatus, '403'), 'el mockup HTML de desarrollo no está expuesto por HTTP');

echo "PASS security_csp_strict ({$checks}/{$checks} comprobaciones)." . PHP_EOL;
