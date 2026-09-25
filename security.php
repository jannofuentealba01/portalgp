<?php
declare(strict_types=1);

// Shared primitives. Including this file neither connects nor redirects.

/**
 * Validates only passwords that are being created or changed. Existing hashes
 * (including admin_2) are deliberately not revalidated or rewritten.
 */
function pgpPasswordPolicyError(string $password, string $username = '', string $email = ''): ?string
{
    $length = mb_strlen($password, 'UTF-8');
    if ($length < 12) {
        return 'La contraseña debe tener al menos 12 caracteres.';
    }
    if ($length > 128) {
        return 'La contraseña no puede superar los 128 caracteres.';
    }
    if (trim($password) === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $password) === 1) {
        return 'La contraseña contiene caracteres no permitidos.';
    }

    $normalized = mb_strtolower(trim($password), 'UTF-8');
    $common = [
        'password', 'password123', 'contraseña', 'contraseña123', '123456789012',
        'administrador', 'administrator', 'qwerty123456', 'portalgp2026',
    ];
    if (in_array($normalized, $common, true)) {
        return 'La contraseña es demasiado predecible. Usa una frase más difícil de adivinar.';
    }

    $identifiers = [trim($username)];
    $emailLocal = strstr(trim($email), '@', true);
    if (is_string($emailLocal)) {
        $identifiers[] = $emailLocal;
    }
    foreach ($identifiers as $identifier) {
        $identifier = mb_strtolower(trim($identifier), 'UTF-8');
        if (mb_strlen($identifier, 'UTF-8') >= 4 && str_contains($normalized, $identifier)) {
            return 'La contraseña no debe contener el usuario ni la parte principal del correo.';
        }
    }

    return null;
}

function pgpRequireStrongPassword(string $password, string $username = '', string $email = ''): void
{
    $error = pgpPasswordPolicyError($password, $username, $email);
    if ($error !== null) {
        throw new InvalidArgumentException($error);
    }
}

/** Neutralizes values that spreadsheet programs could execute as formulas. */
function pgpSpreadsheetSafeCell(mixed $value): mixed
{
    if (!is_string($value) || $value === '') {
        return $value;
    }
    if (preg_match('/^[\x09\x0D]/', $value) === 1 || preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1) {
        return "'" . $value;
    }
    return $value;
}

function pgpSpreadsheetSafeRow(array $row): array
{
    return array_map('pgpSpreadsheetSafeCell', $row);
}

/** Removes credentials and personal identifiers before text reaches a log. */
function pgpRedactLogMessage(string $message): string
{
    $message = str_replace(["\r", "\n", "\0"], ' ', $message);
    $patterns = [
        '~\b(?:Authorization\s*:\s*)?Bearer\s+[A-Za-z0-9._\~+\-/]+=*~i' => 'Bearer [REDACTED]',
        '~(["\']?(?:access_token|refresh_token|id_token|client_secret|password|password_hash|authorization_code|code)["\']?\s*[:=]\s*)["\']?[^\s,;}&"\']+~i' => '$1[REDACTED]',
        '~\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b~i' => '[EMAIL_REDACTED]',
        '~\b\d{1,2}\.\d{3}\.\d{3}-[0-9Kk]\b|\b\d{7,8}-[0-9Kk]\b~' => '[RUT_REDACTED]',
    ];
    $redacted = preg_replace(array_keys($patterns), array_values($patterns), $message);
    return substr(is_string($redacted) ? $redacted : '[REDACTED]', 0, 4000);
}

/** Writes one sanitized exception record and returns its correlation reference. */
function pgpLogException(Throwable $exception, string $context): string
{
    try {
        $reference = strtoupper(bin2hex(random_bytes(4)));
    } catch (Throwable) {
        $reference = strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));
    }
    $safeContext = preg_replace('/[^A-Za-z0-9_.\/-]/', '_', $context) ?: 'app';
    error_log(sprintf(
        '[PortalGP][%s][%s] %s: %s',
        $safeContext,
        $reference,
        get_class($exception),
        pgpRedactLogMessage($exception->getMessage())
    ));
    return $reference;
}

/** Encodes values for direct placement in an HTML <script> block. */
function pgpJsonForHtml(mixed $value, string $fallback = 'null'): string
{
    try {
        $json = json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return is_string($json) ? $json : $fallback;
    } catch (Throwable $exception) {
        pgpLogException($exception, 'json_html');
        return $fallback;
    }
}

/**
 * Keeps technical details in the server log and returns a stable public error.
 */
function pgpPublicException(Throwable $exception, string $context, string $fallback): string
{
    $reference = pgpLogException($exception, $context);
    return rtrim($fallback) . ' Referencia: ' . $reference . '.';
}

/** Shows only messages intentionally raised by application validation code. */
function pgpPublicOrBusinessException(Throwable $exception, string $context, string $fallback): string
{
    if (in_array(get_class($exception), [RuntimeException::class, InvalidArgumentException::class, DomainException::class], true)) {
        $message = trim($exception->getMessage());
        if ($message !== '') {
            return pgpSafePublicMessage($message, $context, $fallback);
        }
    }
    return pgpPublicException($exception, $context, $fallback);
}

/** Final boundary for legacy code that passes a raw exception message to a flash. */
function pgpSafePublicMessage(string $message, string $context, string $fallback): string
{
    $message = trim($message);
    if ($message === '') {
        return $fallback;
    }
    $technical = preg_match(
        '~SQLSTATE|ODBC\s+Driver|PDOException|Stack\s+trace|Uncaught\s+|Call\s+to\s+undefined|Undefined\s+(?:array\s+key|variable)|Invalid\s+(?:object|column)\s+name|Could\s+not\s+find\s+stored\s+procedure|Cannot\s+insert\s+duplicate\s+key|constraint\s+[\'\"\[]|\b(?:Msg|Error)\s+\d{3,}\b|(?:[A-Z]:\\\\|/(?:var|home|srv)/)[^\r\n]*\.php~i',
        $message
    ) === 1;
    if (!$technical) {
        return $message;
    }
    return pgpPublicException(new RuntimeException($message), $context, $fallback);
}

function pgpSecurityIsHttps(?array $server = null): bool
{
    $server ??= $_SERVER;
    return (!empty($server['HTTPS']) && strtolower((string)$server['HTTPS']) !== 'off')
        || (string)($server['SERVER_PORT'] ?? '') === '443';
}

/** Returns one unpredictable CSP nonce for the current response. */
function pgpCspNonce(): string
{
    static $nonce = null;
    if (is_string($nonce)) {
        return $nonce;
    }
    try {
        $bytes = random_bytes(18);
    } catch (Throwable) {
        $bytes = hash('sha256', uniqid('pgp-csp-', true), true);
    }
    $nonce = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    return $nonce;
}

/** Returns the escaped nonce attribute for a trusted script or style element. */
function pgpCspNonceAttribute(): string
{
    return ' nonce="' . htmlspecialchars(
        pgpCspNonce(),
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    ) . '"';
}

/**
 * Registers hashes created by trusted PHP templates during the current
 * response. Values discovered only in the final HTML are never registered.
 *
 * @return array{script:list<string>,style:list<string>}
 */
function pgpCspAttributeRegistry(?string $kind = null, ?string $value = null): array
{
    static $registry = ['script' => [], 'style' => []];
    if ($kind !== null && $value !== null) {
        if (!array_key_exists($kind, $registry)) {
            throw new InvalidArgumentException('Tipo de atributo CSP no válido.');
        }
        $registry[$kind][base64_encode(hash('sha256', $value, true))] = true;
    }
    return [
        'script' => array_keys($registry['script']),
        'style' => array_keys($registry['style']),
    ];
}

/** Returns one trusted inline style attribute and registers its exact hash. */
function pgpCspStyleAttribute(string $style): string
{
    pgpCspAttributeRegistry('style', $style);
    return 'style="' . htmlspecialchars(
        $style,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    ) . '"';
}

/** Returns one trusted DOM event attribute and registers its exact hash. */
function pgpCspEventAttribute(string $name, string $code): string
{
    $normalizedName = strtolower(trim($name));
    if (preg_match('/^on[a-z][a-z0-9_-]*$/', $normalizedName) !== 1) {
        throw new InvalidArgumentException('Nombre de evento HTML no válido.');
    }
    pgpCspAttributeRegistry('script', $code);
    return $normalizedName . '="' . htmlspecialchars(
        $code,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    ) . '"';
}

/** HTMX must not create its own unnonced indicator style element. */
function pgpCspHtmxConfigMeta(): string
{
    $config = json_encode([
        'includeIndicatorStyles' => false,
        'inlineScriptNonce' => pgpCspNonce(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    return '<meta name="htmx-config" content="' . htmlspecialchars(
        $config,
        ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
        'UTF-8'
    ) . '">';
}

/**
 * @return array{script:array<string,true>,style:array<string,true>}
 */
function pgpCspTrustedAttributeHashAllowlist(): array
{
    static $allowlist = null;
    if (is_array($allowlist)) {
        return $allowlist;
    }

    $allowlist = ['script' => [], 'style' => []];
    $path = __DIR__ . '/config/csp_inline_attribute_hashes.php';
    if (!is_file($path)) {
        return $allowlist;
    }
    $loaded = require $path;
    if (!is_array($loaded)) {
        return $allowlist;
    }
    foreach (['script', 'style'] as $kind) {
        foreach (($loaded[$kind] ?? []) as $hash) {
            if (is_string($hash) && preg_match('/^[A-Za-z0-9+\/=]{43,44}$/', $hash) === 1) {
                $allowlist[$kind][$hash] = true;
            }
        }
    }
    return $allowlist;
}

/** @param array<string,true> $trustedHashes @return list<string> */
function pgpCspAttributeHashes(string $html, string $attributePattern, array $trustedHashes = []): array
{
    $hashes = [];
    $pattern = '~\s(?:' . $attributePattern . ')\s*=\s*(["\'])(.*?)\1~is';
    if (preg_match_all($pattern, $html, $matches) !== false) {
        foreach ($matches[2] ?? [] as $rawValue) {
            $value = html_entity_decode((string) $rawValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hash = base64_encode(hash('sha256', $value, true));
            if (isset($trustedHashes[$hash])) {
                $hashes[$hash] = true;
            }
        }
    }
    $values = array_keys($hashes);
    sort($values, SORT_STRING);
    return $values;
}

/**
 * Inventories only attributes whose hashes were generated from trusted source
 * templates. It intentionally leaves the rendered HTML unchanged: an injected
 * script/style element therefore never receives the response nonce.
 *
 * @return array{html:string,script_attribute_hashes:list<string>,style_attribute_hashes:list<string>}
 */
function pgpCspPrepareHtml(string $html, string $nonce): array
{
    unset($nonce);
    $allowlist = pgpCspTrustedAttributeHashAllowlist();

    return [
        'html' => $html,
        'script_attribute_hashes' => pgpCspAttributeHashes(
            $html,
            'on[a-z][a-z0-9_-]*',
            $allowlist['script']
        ),
        'style_attribute_hashes' => pgpCspAttributeHashes($html, 'style', $allowlist['style']),
    ];
}

/** @param list<string> $scriptAttributeHashes @param list<string> $styleAttributeHashes */
function pgpCspPolicy(
    string $nonce,
    array $scriptAttributeHashes = [],
    array $styleAttributeHashes = []
): string {
    $nonceSource = "'nonce-" . $nonce . "'";
    $attributeSources = static function (array $hashes): string {
        if ($hashes === []) {
            return "'none'";
        }
        $sources = array_map(
            static fn(string $hash): string => "'sha256-" . $hash . "'",
            array_values(array_unique($hashes))
        );
        return "'unsafe-hashes' " . implode(' ', $sources);
    };

    return implode('; ', [
        "default-src 'self'",
        "base-uri 'self'",
        "object-src 'none'",
        "frame-src 'none'",
        "frame-ancestors 'self'",
        "form-action 'self'",
        "script-src 'self' " . $nonceSource,
        "script-src-elem 'self' " . $nonceSource,
        'script-src-attr ' . $attributeSources($scriptAttributeHashes),
        "style-src 'self' " . $nonceSource,
        "style-src-elem 'self' " . $nonceSource,
        'style-src-attr ' . $attributeSources($styleAttributeHashes),
        "img-src 'self' data: blob: https:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "media-src 'self' blob:",
        "worker-src 'self' blob:",
        "manifest-src 'self'",
    ]);
}

function pgpCspResponseIsHtml(string $buffer): bool
{
    foreach (headers_list() as $headerLine) {
        if (stripos($headerLine, 'Content-Type:') !== 0) {
            continue;
        }
        $contentType = strtolower(trim(substr($headerLine, strlen('Content-Type:'))));
        return str_starts_with($contentType, 'text/html')
            || str_starts_with($contentType, 'application/xhtml+xml');
    }
    return $buffer === ''
        || preg_match('~<!doctype\s+html|<html\b|<(?:script|style)\b~i', $buffer) === 1;
}

function pgpCspFinalizeOutput(string $buffer, int $phase = PHP_OUTPUT_HANDLER_FINAL): string
{
    static $scriptAttributeHashes = [];
    static $styleAttributeHashes = [];
    $nonce = pgpCspNonce();

    if (pgpCspResponseIsHtml($buffer)) {
        $prepared = pgpCspPrepareHtml($buffer, $nonce);
        $buffer = $prepared['html'];
        foreach ($prepared['script_attribute_hashes'] as $hash) {
            $scriptAttributeHashes[$hash] = true;
        }
        foreach ($prepared['style_attribute_hashes'] as $hash) {
            $styleAttributeHashes[$hash] = true;
        }
    }

    if (!headers_sent()) {
        $registeredHashes = pgpCspAttributeRegistry();
        header_remove('Content-Security-Policy');
        header(
            'Content-Security-Policy: ' . pgpCspPolicy(
                $nonce,
                array_values(array_unique(array_merge(
                    array_keys($scriptAttributeHashes),
                    $registeredHashes['script']
                ))),
                array_values(array_unique(array_merge(
                    array_keys($styleAttributeHashes),
                    $registeredHashes['style']
                )))
            )
        );
    }
    return $buffer;
}

function pgpCspStartOutputProtection(): void
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    $started = true;
    ob_start('pgpCspFinalizeOutput');
}

/** Applies the browser security baseline when Apache mod_headers is unavailable. */
function pgpApplySecurityHeaders(?array $server = null): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    pgpCspStartOutputProtection();
    if (function_exists('apache_get_modules') && in_array('mod_headers', apache_get_modules(), true)) {
        return;
    }
    $server ??= $_SERVER;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header('Cache-Control: no-store, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    if (pgpSecurityIsHttps($server)) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function pgpSecurityConfigureSession(?array $server = null): void
{
    if (session_status() !== PHP_SESSION_NONE || headers_sent()) {
        return;
    }
    $isHttps = pgpSecurityIsHttps($server);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function pgpSecurityStartSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        pgpSecurityConfigureSession();
        session_start();
    }
}


function pgpSecurityDestroySession(): void
{
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE && (bool) ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] !== '' ? $params['path'] : '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => (string) ($params['samesite'] ?? 'Lax'),
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Session audience is explicit for external portals; legacy/internal sessions default to internal. */
function pgpSessionAudience(): string
{
    $audience = strtolower(trim((string) ($_SESSION['portal_audience'] ?? 'internal')));
    return in_array($audience, ['internal', 'arrendatario'], true) ? $audience : 'invalid';
}

function pgpRequireInternalAudience(): void
{
    if (pgpSessionAudience() !== 'internal') {
        pgpSecurityAbort(403, 'Esta cuenta no puede acceder al portal interno.');
    }
}

function pgpSecurityUser(PDO $db, int $id): ?array
{
    $stmt = $db->prepare('SELECT u.id,u.UserName,u.estado_id,u.rol_id,u.security_version,r.nombre_rol
        FROM dbo.cr_usuarios u LEFT JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.id=:id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pgpValidateSession(PDO $db): bool
{
    $id = (int) ($_SESSION['usuario']['id'] ?? 0);
    if ($id <= 0) {
        return false;
    }
    $user = pgpSecurityUser($db, $id);
    $version = (int) ($user['security_version'] ?? -1);
    // Adopt existing development sessions only at initial version zero.
    $sessionVersion = (int) ($_SESSION['pgp_security_version'] ?? 0);

    if (!$user || (int) $user['estado_id'] !== 1 || $version !== $sessionVersion) {
        pgpSecurityDestroySession();
        return false;
    }
    $_SESSION['pgp_security_version'] = $version;
    $_SESSION['usuario']['rol_id'] = (int) $user['rol_id'];
    $_SESSION['usuario']['roles'] = $user['nombre_rol'] !== null ? [(string) $user['nombre_rol']] : [];
    unset($_SESSION['usuario']['rol']);
    return true;
}

function pgpSecurityAbort(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo pgpSafePublicMessage($message, 'security.abort', 'No fue posible completar la solicitud.');
    exit;
}

function pgpRequireEnabledSession(PDO $db): void
{
    pgpSecurityStartSession();
    try {
        $valid = pgpValidateSession($db);
    } catch (Throwable $e) {
        pgpLogException($e, 'session');
        pgpSecurityAbort(503, 'No fue posible validar la sesión. Intenta nuevamente.');
    }
    if (!$valid) {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
            header('Location: /portalgp/login.php', true, 303);
            exit;
        }
        pgpSecurityAbort(401, 'Debes iniciar sesión con una cuenta habilitada.');
    }
}

function pgpCsrfToken(): string
{
    pgpSecurityStartSession();
    if (!isset($_SESSION['pgp_csrf']) || !is_string($_SESSION['pgp_csrf'])) {
        $_SESSION['pgp_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['pgp_csrf'];
}

function pgpCsrfField(): void
{
    echo '<input type="hidden" name="_pgp_csrf" value="' . htmlspecialchars(pgpCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function pgpRenderCsrfAutoFieldScript(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }
    $rendered = true;
    $token = pgpJsonForHtml(pgpCsrfToken(), '""');
    echo '<script' . pgpCspNonceAttribute() . '>(function(){const token=' . $token . ';const ensure=function(form){'
        . 'if(!(form instanceof HTMLFormElement)){return;}const method=(form.getAttribute("method")||"get").toLowerCase();'
        . 'if(method!=="post"){return;}let input=form.querySelector("input[name=\\"_pgp_csrf\\"]");'
        . 'if(!input){input=document.createElement("input");input.type="hidden";input.name="_pgp_csrf";form.appendChild(input);}input.value=token;};'
        . 'const scan=function(){document.querySelectorAll("form").forEach(ensure);};'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",scan,{once:true});}else{scan();}'
        . 'document.addEventListener("submit",function(event){ensure(event.target);},true);})();</script>';
}

function pgpVerifyCsrf(mixed $token): bool
{
    return is_string($token) && isset($_SESSION['pgp_csrf'])
        && is_string($_SESSION['pgp_csrf']) && hash_equals($_SESSION['pgp_csrf'], $token);
}

function pgpRequireCsrf(): void
{
    if (!pgpVerifyCsrf($_POST['_pgp_csrf'] ?? null)) {
        pgpSecurityAbort(403, 'La solicitud no tiene un token de seguridad válido. Recarga la página.');
    }
}

/** The request can strengthen the default write requirement, never downgrade it. */
function pgpRequestPermissionAction(?array $server = null, ?array $post = null): string
{
    $server ??= $_SERVER;
    $post ??= $_POST;
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return 'lectura';
    }
    if ($method === 'DELETE') {
        return 'eliminacion';
    }
    $route = pathinfo(str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '')), PATHINFO_FILENAME);
    $candidates = [$route];
    foreach (['action', 'accion', 'accion_garantia'] as $key) {
        if (isset($post[$key]) && is_scalar($post[$key])) {
            $candidates[] = (string) $post[$key];
        }
    }
    $candidates = array_merge($candidates, array_keys($post));
    foreach ($candidates as $candidate) {
        if (preg_match('/(^|_)(eliminar|delete|borrar|remove|anular|revertir|cancelar|descartar|limpiar)(_|$)/i', trim((string) $candidate))) {
            return 'eliminacion';
        }
    }
    return 'escritura';
}

function pgpHasPermission(PDO $db, int $id, string $permission, string $action): bool
{
    if ($id <= 0 || !in_array($action, ['lectura', 'escritura', 'eliminacion'], true)) {
        return false;
    }
    // Column is exclusively from the allowlist above.
    $stmt = $db->prepare('SELECT COUNT(*) FROM dbo.cr_usuarios u
        JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id
        JOIN dbo.cr_permisos p ON p.id=rp.permiso_id
        WHERE u.id=:id AND u.estado_id=1 AND p.nombre_permiso=:permission
          AND rp.lectura=1 AND rp.' . $action . '=1'
        . ($action === 'eliminacion' ? ' AND rp.escritura=1' : ''));
    $stmt->execute([':id' => $id, ':permission' => $permission]);
    return (int) $stmt->fetchColumn() > 0;
}

function pgpCanManagePermissions(PDO $db, int $id): bool
{
    foreach (['Administrar Usuarios', 'Administrar Roles', 'Administrar Permisos', 'Permisos'] as $permission) {
        if (pgpHasPermission($db, $id, $permission, 'escritura')) {
            return true;
        }
    }
    return false;
}
