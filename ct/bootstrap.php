<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$permisosPath = dirname(__DIR__) . '/permisos.php';
if (is_file($permisosPath)) {
    require_once $permisosPath;
}

function ctStartSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    if (!headers_sent()) {
        $isHttps = (
            (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        );

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

ctStartSession();

function ctBaseUrl(): string
{
    return '/portalgp/ct';
}

function ctUrl(string $path = ''): string
{
    $base = ctBaseUrl();
    if ($path === '') {
        return $base . '/ct_menu.php';
    }

    return $base . '/' . ltrim($path, '/');
}

function ctRedirect(string $path = ''): never
{
    header('Location: ' . ctUrl($path));
    exit();
}

function ctEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ctSetFlash(string $type, string $message, array $meta = []): void
{
    $payload = [
        'type' => trim($type) !== '' ? trim($type) : 'info',
        'message' => trim($message),
    ];

    if ($meta !== []) {
        $payload['meta'] = $meta;
    }

    $_SESSION['ct_flash'] = $payload;
}

function ctPullFlash(): ?array
{
    if (!isset($_SESSION['ct_flash']) || !is_array($_SESSION['ct_flash'])) {
        return null;
    }

    $flash = $_SESSION['ct_flash'];
    unset($_SESSION['ct_flash']);
    return $flash;
}

function ctCsrfToken(): string
{
    $token = $_SESSION['ct_csrf_token'] ?? null;
    if (!is_string($token) || strlen($token) < 32) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['ct_csrf_token'] = $token;
    }

    return $token;
}

function ctCsrfVerify(?string $providedToken): bool
{
    if (!is_string($providedToken) || $providedToken === '') {
        return false;
    }

    return hash_equals(ctCsrfToken(), $providedToken);
}

function ctIsAjaxRequest(): bool
{
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    return str_contains($accept, 'application/json');
}

function ctAbortInvalidCsrf(): never
{
    if (ctIsAjaxRequest()) {
        http_response_code(419);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'message' => 'La sesion de seguridad expiro. Recarga la pagina e intenta nuevamente.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }

    ctSetFlash('warning', 'La sesion de seguridad expiro. Recarga la pantalla e intenta nuevamente.');
    ctRedirect();
}

function ctRequireValidCsrfToken(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        return;
    }

    $token = trim((string) ($_POST['_csrf'] ?? ''));
    if ($token === '') {
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token = is_string($headerToken) ? trim($headerToken) : '';
    }

    if (!ctCsrfVerify($token)) {
        ctAbortInvalidCsrf();
    }
}

function ctCsrfField(): void
{
    echo '<input type="hidden" name="_csrf" value="' . ctEscape(ctCsrfToken()) . '">';
}

function ctRenderCsrfAutoFieldScript(): void
{
    static $rendered = false;
    if ($rendered) {
        return;
    }

    $rendered = true;
    $tokenJson = json_encode(
        ctCsrfToken(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($tokenJson)) {
        return;
    }

    echo '<script>(function(){'
        . 'const token=' . $tokenJson . ';'
        . 'const ensure=function(form){'
        . 'if(!(form instanceof HTMLFormElement)){return;}'
        . 'const method=(form.getAttribute("method")||"get").toLowerCase();'
        . 'if(method!=="post"){return;}'
        . 'let input=form.querySelector(\'input[name="_csrf"]\');'
        . 'if(!input){input=document.createElement("input");input.type="hidden";input.name="_csrf";form.appendChild(input);}'
        . 'input.value=token;'
        . '};'
        . 'const scan=function(){document.querySelectorAll("form").forEach(ensure);};'
        . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",scan,{once:true});}else{scan();}'
        . 'document.addEventListener("submit",function(event){ensure(event.target);},true);'
        . 'window.ctCsrfToken=token;'
        . '})();</script>';
}

function ctPermissionAliases(string $permission): array
{
    $base = trim($permission);
    if ($base === '') {
        return [];
    }

    $aliases = [$base];

    if (strcasecmp($base, 'CT') === 0) {
        $aliases[] = 'CT Terrenos';
    } elseif (strcasecmp($base, 'CT Terrenos') === 0) {
        $aliases[] = 'CT';
    }

    $unique = [];
    foreach ($aliases as $alias) {
        $alias = trim($alias);
        if ($alias === '' || in_array($alias, $unique, true)) {
            continue;
        }
        $unique[] = $alias;
    }

    return $unique;
}

function ctPermissionExistsInCatalog(string $permission): bool
{
    $name = trim($permission);
    if ($name === '') {
        return false;
    }

    global $conn;
    if (!isset($conn) || !($conn instanceof PDO)) {
        return false;
    }

    try {
        $stmt = $conn->prepare('SELECT TOP (1) 1 FROM cr_permisos WHERE nombre_permiso = :nombre');
        $stmt->bindValue(':nombre', $name, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $exception) {
        return false;
    }
}

function ctRequireAccess(string $permission = 'CT'): void
{
    if (!isset($_SESSION['usuario']['id'])) {
        header('Location: /portalgp/login.php');
        exit();
    }

    $idUsuario = (int) $_SESSION['usuario']['id'];
    if ($permission !== '' && function_exists('tienePermiso') && $idUsuario > 0) {
        $permissions = ctPermissionAliases($permission);
        if ($permissions === []) {
            $permissions = [$permission];
        }

        $mustEnforce = false;
        foreach ($permissions as $candidate) {
            if (ctPermissionExistsInCatalog($candidate)) {
                $mustEnforce = true;
                break;
            }
        }

        $hasPermission = false;
        foreach ($permissions as $candidate) {
            try {
                if (tienePermiso($idUsuario, $candidate)) {
                    $hasPermission = true;
                    break;
                }
            } catch (Throwable $exception) {
                continue;
            }
        }

        if ($mustEnforce && !$hasPermission) {
            ctSetFlash('warning', 'No tienes permiso para esta seccion.');
            header('Location: /portalgp/index.php');
            exit();
        }
    }

    ctRequireValidCsrfToken();
}

function ctRenderFlash(?array $flash): void
{
    if (!is_array($flash)) {
        return;
    }

    include __DIR__ . '/templates/components/flash.php';
}

function ctNormalizeText(?string $value): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));
    return $normalized ?? '';
}

function ctDb(): PDO
{
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    throw new RuntimeException('No hay conexion PDO disponible en $conn.');
}

function ctLoadSpreadsheetLibrary(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $previousLevel = error_reporting();
    error_reporting($previousLevel & ~E_DEPRECATED & ~E_USER_DEPRECATED);

    try {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
    } finally {
        error_reporting($previousLevel);
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class, false)) {
        ctRegisterSpreadsheetFallbackAutoloaders();
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        throw new RuntimeException('No fue posible cargar PhpSpreadsheet desde vendor/autoload.php ni desde el fallback interno.');
    }

    $loaded = true;
}

function ctRegisterPsr4Fallback(string $prefix, string $baseDirectory): void
{
    static $registered = [];

    if (isset($registered[$prefix])) {
        return;
    }

    $normalizedBaseDirectory = rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR;

    spl_autoload_register(static function (string $class) use ($prefix, $normalizedBaseDirectory): void {
        $prefixLength = strlen($prefix);

        if (strncmp($class, $prefix, $prefixLength) !== 0) {
            return;
        }

        $relativeClass = substr($class, $prefixLength);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';
        $target = $normalizedBaseDirectory . $relativePath;

        if (is_file($target)) {
            require_once $target;
        }
    });

    $registered[$prefix] = true;
}

function ctRequirePhpFiles(array $patterns): void
{
    static $loaded = [];

    foreach ($patterns as $pattern) {
        if (isset($loaded[$pattern])) {
            continue;
        }

        $matches = glob($pattern);
        if ($matches === false) {
            $loaded[$pattern] = true;
            continue;
        }

        sort($matches);
        foreach ($matches as $file) {
            if (is_file($file)) {
                require_once $file;
            }
        }

        $loaded[$pattern] = true;
    }
}

function ctRegisterSpreadsheetFallbackAutoloaders(): void
{
    $vendorDirectory = dirname(__DIR__) . '/vendor';

    ctRegisterPsr4Fallback(
        'PhpOffice\\PhpSpreadsheet\\',
        $vendorDirectory . '/phpoffice/phpspreadsheet/src/PhpSpreadsheet'
    );
    ctRegisterPsr4Fallback(
        'Psr\\SimpleCache\\',
        $vendorDirectory . '/psr/simple-cache/src'
    );
    ctRegisterPsr4Fallback(
        'Complex\\',
        $vendorDirectory . '/markbaker/complex/classes/src'
    );
    ctRegisterPsr4Fallback(
        'Matrix\\',
        $vendorDirectory . '/markbaker/matrix/classes/src'
    );

    ctRequirePhpFiles([
        $vendorDirectory . '/markbaker/complex/classes/src/functions/*.php',
        $vendorDirectory . '/markbaker/complex/classes/src/operations/*.php',
        $vendorDirectory . '/markbaker/matrix/classes/src/Functions/*.php',
        $vendorDirectory . '/markbaker/matrix/classes/src/Operations/*.php',
    ]);
}
