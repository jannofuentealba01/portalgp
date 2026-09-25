<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};

$root = dirname(__DIR__);
$htaccess = (string) file_get_contents($root . '/.htaccess');
$securitySource = (string) file_get_contents($root . '/security.php');
$generatedCsp = pgpCspPolicy('stage3-test-nonce');
$requiredCsp = [
    "default-src 'self'",
    "base-uri 'self'",
    "object-src 'none'",
    "frame-src 'none'",
    "frame-ancestors 'self'",
    "form-action 'self'",
    "script-src 'self' 'nonce-stage3-test-nonce'",
    "script-src-attr 'none'",
    "style-src 'self' 'nonce-stage3-test-nonce'",
    "style-src-attr 'none'",
    "img-src 'self' data: blob: https:",
    "font-src 'self' data:",
    "connect-src 'self'",
    "worker-src 'self' blob:",
];
foreach ($requiredCsp as $directive) {
    $assert(str_contains($generatedCsp, $directive), 'CSP dinámica contiene ' . $directive);
}
$assert(!str_contains($generatedCsp, "'unsafe-inline'"), 'la CSP dinámica no permite unsafe-inline');
$assert(!str_contains($htaccess, "'unsafe-inline'"), 'la política Apache para HTML estático no permite unsafe-inline');
$assert(str_contains($securitySource, "ob_start('pgpCspFinalizeOutput')"), 'PHP protege la salida completa antes de enviarla');

foreach ([
    'X-Content-Type-Options',
    'Referrer-Policy',
    'X-Frame-Options',
    'X-Permitted-Cross-Domain-Policies',
    'Cross-Origin-Opener-Policy',
    'Cross-Origin-Resource-Policy',
    'Permissions-Policy',
    'Strict-Transport-Security',
] as $headerName) {
    $assert(str_contains($htaccess, $headerName), 'la configuración incluye ' . $headerName);
}
$assert(
    str_contains($htaccess, 'no-store, private, max-age=0')
    && str_contains($htaccess, 'public, max-age=604800, immutable'),
    'la caché diferencia respuestas PHP sensibles de recursos estáticos'
);
$assert(str_contains($htaccess, '<If "%{HTTPS} == \'on\'">'), 'HSTS se limita a conexiones HTTPS');

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$externalFrontend = [];
$localReferences = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'html'], true)) {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match_all('~(?:src|href)=["\'](https://(?:cdn\.jsdelivr\.net|stackpath\.bootstrapcdn\.com|unpkg\.com|cdnjs\.cloudflare\.com)[^"\']*)~i', $source, $matches)) {
        foreach ($matches[1] as $url) {
            $externalFrontend[$url] = true;
        }
    }
    if (preg_match_all('~(?:src|href)=["\'](/portalgp/assets/vendor/[^?"\']+)~i', $source, $matches)) {
        foreach ($matches[1] as $url) {
            $localReferences[$url] = true;
        }
    }
}
$assert($externalFrontend === [], 'no quedan scripts, estilos o fuentes cargados desde CDN');
$assert(count($localReferences) >= 11, 'el inventario detecta los recursos frontend locales versionados');
$missing = [];
foreach (array_keys($localReferences) as $url) {
    $path = $root . '/' . substr($url, strlen('/portalgp/'));
    if (!is_file($path) || filesize($path) <= 0) {
        $missing[] = $url;
    }
}
$assert($missing === [], 'todas las referencias locales apuntan a archivos existentes');

$expectedHashes = [
    'assets/vendor/bootstrap-4.5.2/css/bootstrap.min.css' => '5b0fbe5b7ad705f6a937c4998ad02f73d8f0d976fe231b74aef0ec996990c93a',
    'assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css' => '7f1d37f0d90b6385354c2ac10e2bb91563c46bd7a266ed351222ebcac8496c2a',
    'assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js' => 'aa53d582f97eb594c2a5cc5824574707f9ba9837bce3046bfa5f3556860f4e04',
    'assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css' => '3c8f27e6009ccfd710a905e6dcf12d0ee3c6f2ac7da05b0572d3e0d12e736fc8',
    'assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js' => '0833b2e9c3a26c258476c46266e6877fc75218625162e0460be9a3a098a61c6c',
    'assets/vendor/bootstrap-icons-1.10.5/font/bootstrap-icons.css' => 'd8824f7067cdfea38afec7e9ffaf072125266824206d69ef1f112d72153a505e',
    'assets/vendor/bootstrap-icons-1.10.5/font/fonts/bootstrap-icons.woff' => '999550fafbfbc0e0474b3b5c945df287b079d2a23dc07dcc6240a7d2f18e081d',
    'assets/vendor/bootstrap-icons-1.10.5/font/fonts/bootstrap-icons.woff2' => 'cfe45b981d1b91b173361a34cfce5f60893dbd1ac4af2c3ac11fc17552c5401f',
    'assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css' => '4ffa6bea4304d2eda418683f56261685ed47bf00995039f27e5ad62d53938d2d',
    'assets/vendor/bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff' => 'bb1de989b83970f6f4e54de1cd974c5cba55b73582da5e1b225a6d0edf029483',
    'assets/vendor/bootstrap-icons-1.11.3/font/fonts/bootstrap-icons.woff2' => '476adf42b40325098fcfa8b36ab3e769186bb4f6ce6a249753e2e1a9c22bf99e',
    'assets/vendor/chart.js-4.4.3/chart.umd.min.js' => 'd46d97a1fd022c5fb29fa2f45ebcbc32202d73aeebf076ce5f7248f5498fc7d7',
    'assets/vendor/driver.js-1.3.6/driver.css' => '4b398481a7ce8375af4d9f58f39410c73a0b70726fe513686d3d51c10ad76cb5',
    'assets/vendor/driver.js-1.3.6/driver.js.iife.js' => '31d6a387715585cc5507a3dded09eb97969f8167fabfb272648f84c6b2608325',
    'assets/vendor/htmx-1.9.12/htmx.min.js' => '449317ade7881e949510db614991e195c3a099c4c791c24dacec55f9f4a2a452',
];
foreach ($expectedHashes as $relative => $expectedHash) {
    $assert(hash_file('sha256', $root . '/' . $relative) === $expectedHash, 'integridad SHA-256: ' . basename($relative));
}

foreach (['1.10.5', '1.11.3'] as $version) {
    $iconRoot = $root . '/assets/vendor/bootstrap-icons-' . $version . '/font';
    $assert(
        is_file($iconRoot . '/bootstrap-icons.css')
        && is_file($iconRoot . '/fonts/bootstrap-icons.woff')
        && is_file($iconRoot . '/fonts/bootstrap-icons.woff2'),
        'Bootstrap Icons ' . $version . ' conserva CSS y fuentes locales'
    );
}

if (in_array('--live', $argv, true)) {
    $loginHeaders = get_headers('http://localhost/portalgp/login.php', true);
    $assetHeaders = get_headers('http://localhost/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css', true);
    $assert(is_array($loginHeaders) && str_contains((string) ($loginHeaders[0] ?? ''), '200'), 'Apache responde la página de login');
    $assert(isset($loginHeaders['Content-Security-Policy']), 'Apache entrega CSP en una página PHP');
    $liveCsp = (string) ($loginHeaders['Content-Security-Policy'] ?? '');
    $assert(!str_contains($liveCsp, "'unsafe-inline'") && preg_match("~'nonce-[A-Za-z0-9_-]+'~", $liveCsp) === 1, 'la CSP HTTP real usa nonce y no unsafe-inline');
    $assert((string) ($loginHeaders['Cache-Control'] ?? '') === 'no-store, private, max-age=0', 'Apache impide cachear páginas PHP');
    $assert((string) ($assetHeaders['Cache-Control'] ?? '') === 'public, max-age=604800, immutable', 'Apache permite caché inmutable de recursos estáticos');
}

$admin = $conn->query(
    "SELECT TOP 1 u.id,u.estado_id,u.rol_id,
        (SELECT COUNT(*) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) permisos
     FROM dbo.cr_usuarios u WHERE u.UserName=N'admin_2'"
)->fetch(PDO::FETCH_ASSOC);
$assert(
    is_array($admin)
    && (int) ($admin['id'] ?? 0) === 1030
    && (int) ($admin['estado_id'] ?? 0) === 1
    && (int) ($admin['rol_id'] ?? 0) === 1
    && (int) ($admin['permisos'] ?? 0) >= 22,
    'admin_2 conserva estado, rol y no pierde permisos'
);

echo 'PASS security_stage3_http (' . $checks . ' comprobaciones)' . PHP_EOL;
