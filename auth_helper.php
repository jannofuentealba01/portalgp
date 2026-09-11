<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendor/autoload.php';
pgpSecurityStartSession();

function pgpBuildUserSession(array $user, array $roles, string $loginSource = 'local'): array
{
    return [
        'id' => $user['id'] ?? $user['Id'] ?? null,
        'UserName' => $user['UserName'] ?? '',
        'nombre_completo' => $user['nombre_completo'] ?? '',
        'correo_electronico' => $user['correo_electronico'] ?? '',
        'url_logo' => $user['url_logo'] ?? '',
        'roles' => $roles,
        'login_source' => $loginSource,
    ];
}

function pgpFetchUserRoles(PDO $conn, int $usuarioId): array
{
    $stmt = $conn->prepare(
        "SELECT r.nombre_rol
         FROM cr_usuarios u
         JOIN cr_roles r ON u.rol_id = r.id
         WHERE u.id = :usuario_id AND u.estado_id=1"
    );
    $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function pgpLoginUserRecord(PDO $conn, array $user, string $loginSource = 'local'): void
{
    $userId = (int)($user['id'] ?? $user['Id'] ?? 0);
    $securityUser = pgpSecurityUser($conn, $userId);
    if (!$securityUser || (int)$securityUser['estado_id'] !== 1) {
        throw new RuntimeException('La cuenta no está habilitada.');
    }
    $roles = $userId > 0 ? pgpFetchUserRoles($conn, $userId) : [];

    session_regenerate_id(true);
    $_SESSION['usuario'] = pgpBuildUserSession($user, $roles, $loginSource);
    $_SESSION['pgp_security_version'] = (int)$securityUser['security_version'];
    unset($_SESSION['pgp_csrf'], $_SESSION['msp2_csrf_token'], $_SESSION['ct_csrf_token']);
}

function pgpFindUserByUsername(PDO $conn, string $username): ?array
{
    $stmt = $conn->prepare("SELECT TOP 1 * FROM cr_usuarios WHERE UserName = :username");
    $stmt->bindValue(':username', trim($username));
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function pgpFindEnabledUserByEmail(PDO $conn, string $email): ?array
{
    $normalized = mb_strtolower(trim($email), 'UTF-8');
    if ($normalized === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT TOP 1 *
         FROM cr_usuarios
         WHERE estado_id = 1
           AND LOWER(LTRIM(RTRIM(correo_electronico))) = :correo"
    );
    $stmt->bindValue(':correo', $normalized);
    $stmt->execute();

    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function pgpRedirectToLogin(string $message): void
{
    $message = pgpSafePublicMessage($message, 'auth.redirect', 'No fue posible completar el inicio de sesión.');
    header('Location: login.php?login_error=' . rawurlencode($message));
    exit();
}

function pgpMicrosoftAuthConfig(): array
{
    $envCfg = [
        'tenant_id' => getenv('MS_ENTRA_TENANT_ID') ?: '',
        'client_id' => getenv('MS_ENTRA_CLIENT_ID') ?: '',
        'client_secret' => getenv('MS_ENTRA_CLIENT_SECRET') ?: '',
        'client_secret_previous' => getenv('MS_ENTRA_CLIENT_SECRET_PREVIOUS') ?: '',
        'redirect_uri' => getenv('MS_ENTRA_REDIRECT_URI') ?: '',
        'allowed_domains' => getenv('MS_ENTRA_ALLOWED_DOMAINS') ?: '',
    ];

    $fileCfg = [];
    foreach ([__DIR__ . '/microsoft_auth_config.php', pgpSecretConfigPath('entra.php')] as $file) {
        $loaded = @include $file;
        if (is_array($loaded)) {
            $fileCfg = array_merge($fileCfg, $loaded);
        }
    }

    $allowedDomains = $fileCfg['allowed_domains'] ?? [];
    if (!is_array($allowedDomains)) {
        $allowedDomains = array_filter(array_map('trim', explode(',', (string)$allowedDomains)));
    }

    $envAllowedDomains = array_filter(array_map('trim', explode(',', (string)$envCfg['allowed_domains'])));

    return [
        'tenant_id' => trim((string)($envCfg['tenant_id'] ?: ($fileCfg['tenant_id'] ?? ''))),
        'client_id' => trim((string)($envCfg['client_id'] ?: ($fileCfg['client_id'] ?? ''))),
        'client_secret' => trim((string)($envCfg['client_secret'] ?: ($fileCfg['client_secret'] ?? ''))),
        'client_secret_previous' => trim((string)($envCfg['client_secret_previous'] ?: ($fileCfg['client_secret_previous'] ?? ''))),
        'redirect_uri' => trim((string)($envCfg['redirect_uri'] ?: ($fileCfg['redirect_uri'] ?? ''))),
        'allowed_domains' => array_values(array_map(
            static fn(string $domain): string => mb_strtolower(trim($domain), 'UTF-8'),
            $envAllowedDomains !== [] ? $envAllowedDomains : $allowedDomains
        )),
    ];
}

function pgpCurrentBaseUrl(): string
{
    $https = $_SERVER['HTTPS'] ?? '';
    $isHttps = (!empty($https) && strtolower((string)$https) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

function pgpCurrentAppBasePath(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = trim(dirname($scriptName), '/');
    return $dir === '' || $dir === '.' ? '' : '/' . $dir;
}

function pgpMicrosoftRedirectUri(array $config): string
{
    if (!empty($config['redirect_uri'])) {
        return (string)$config['redirect_uri'];
    }

    return pgpCurrentBaseUrl() . pgpCurrentAppBasePath() . '/microsoft_callback.php';
}

function pgpMicrosoftDefaultScopes(): array
{
    return [
        'openid',
        'profile',
        'email',
        'offline_access',
        'User.Read',
    ];
}

function pgpMicrosoftAuthorizeUrl(array $config, string $state, string $nonce): string
{
    $params = [
        'client_id' => (string)$config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => pgpMicrosoftRedirectUri($config),
        'response_mode' => 'query',
        'scope' => implode(' ', pgpMicrosoftDefaultScopes()),
        'state' => $state,
        'nonce' => $nonce,
        'prompt' => 'select_account',
    ];

    return 'https://login.microsoftonline.com/' . rawurlencode((string)$config['tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query($params);
}

function pgpStoreMicrosoftTokens(array $tokenJson): void
{
    $_SESSION['ms_graph_auth'] = [
        'access_token' => (string)($tokenJson['access_token'] ?? ''),
        'refresh_token' => (string)($tokenJson['refresh_token'] ?? ''),
        'expires_at' => time() + max(60, ((int)($tokenJson['expires_in'] ?? 3600)) - 120),
        'scope' => trim((string)($tokenJson['scope'] ?? '')),
        'token_type' => trim((string)($tokenJson['token_type'] ?? 'Bearer')),
    ];
}

function pgpClearMicrosoftTokens(): void
{
    unset($_SESSION['ms_graph_auth']);
}

function pgpMicrosoftProfileFromVerifiedClaims(array $claims): array
{
    return [
        'mail' => trim((string)($claims['email'] ?? '')),
        'userPrincipalName' => trim((string)($claims['preferred_username'] ?? $claims['upn'] ?? '')),
        'displayName' => trim((string)($claims['name'] ?? '')),
        'id' => trim((string)($claims['oid'] ?? $claims['sub'] ?? '')),
    ];
}

function pgpMicrosoftJwks(array $config, bool $forceRefresh = false): array
{
    $tenantId = trim((string)($config['tenant_id'] ?? ''));
    if ($tenantId === '') {
        throw new RuntimeException('Tenant Microsoft no configurado.');
    }
    $cachePath = pgpSecretsDirectory() . DIRECTORY_SEPARATOR . 'entra_jwks_cache.json';
    if (!$forceRefresh && is_file($cachePath) && (time() - (int)filemtime($cachePath)) < 21600) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && isset($cached['keys']) && is_array($cached['keys'])) {
            return $cached;
        }
    }

    $url = 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/discovery/v2.0/keys';
    [$status, $body] = pgpHttpGetPublicJson($url);
    $jwks = json_decode($body, true);
    if ($status < 200 || $status >= 300 || !is_array($jwks) || !isset($jwks['keys']) || !is_array($jwks['keys'])) {
        throw new RuntimeException('Microsoft no entregó claves públicas válidas.');
    }
    $temporary = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, json_encode($jwks, JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
        @chmod($temporary, 0600);
        @rename($temporary, $cachePath);
    }
    if (is_file($temporary)) {
        @unlink($temporary);
    }
    return $jwks;
}

function pgpValidateMicrosoftIdToken(string $idToken, array $config, string $expectedNonce): array
{
    if ($idToken === '' || $expectedNonce === '' || substr_count($idToken, '.') !== 2) {
        throw new RuntimeException('Token de identidad incompleto.');
    }
    $segments = explode('.', $idToken);
    $header = json_decode(\Firebase\JWT\JWT::urlsafeB64Decode($segments[0]), true);
    if (!is_array($header)
        || !hash_equals('RS256', (string)($header['alg'] ?? ''))
        || trim((string)($header['kid'] ?? '')) === '') {
        throw new RuntimeException('Algoritmo o clave de firma Microsoft no permitidos.');
    }

    $decode = static function (array $jwks) use ($idToken): array {
        \Firebase\JWT\JWT::$leeway = 300;
        $keys = \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256');
        return (array)\Firebase\JWT\JWT::decode($idToken, $keys);
    };

    try {
        $claims = $decode(pgpMicrosoftJwks($config));
    } catch (Throwable) {
        // Microsoft rotates signing keys. Refresh once before failing closed.
        $claims = $decode(pgpMicrosoftJwks($config, true));
    }

    pgpValidateMicrosoftClaims($claims, $config, $expectedNonce);
    return $claims;
}

function pgpValidateMicrosoftClaims(array $claims, array $config, string $expectedNonce): void
{
    $tenantId = trim((string)($config['tenant_id'] ?? ''));
    $clientId = trim((string)($config['client_id'] ?? ''));
    $issuer = 'https://login.microsoftonline.com/' . strtolower($tenantId) . '/v2.0';
    $actualIssuer = strtolower(rtrim(trim((string)($claims['iss'] ?? '')), '/'));
    $audience = $claims['aud'] ?? '';
    $audienceValid = is_array($audience)
        ? in_array($clientId, array_map('strval', $audience), true)
        : hash_equals($clientId, (string)$audience);

    if ($actualIssuer !== strtolower($issuer)
        || !hash_equals(strtolower($tenantId), strtolower(trim((string)($claims['tid'] ?? ''))))
        || !$audienceValid
        || !isset($claims['exp'])
        || !hash_equals($expectedNonce, (string)($claims['nonce'] ?? ''))
        || trim((string)($claims['oid'] ?? $claims['sub'] ?? '')) === '') {
        throw new RuntimeException('Las declaraciones del token Microsoft no son válidas.');
    }
}

function pgpMicrosoftProfileEmail(array $profile): string
{
    return trim((string)($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
}

function pgpCleanupMicrosoftOauthStates(): void
{
    $states = $_SESSION['ms_oauth_states'] ?? [];
    if (!is_array($states)) {
        unset($_SESSION['ms_oauth_states']);
        return;
    }

    $now = time();
    $validStates = [];
    foreach ($states as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $value = trim((string)($entry['value'] ?? ''));
        $createdAt = (int)($entry['created_at'] ?? 0);
        $nonce = trim((string)($entry['nonce'] ?? ''));
        if ($value === '' || $nonce === '' || $createdAt <= 0 || ($now - $createdAt) > 900) {
            continue;
        }

        $validStates[] = [
            'value' => $value,
            'created_at' => $createdAt,
            'nonce' => $nonce,
        ];
    }

    $_SESSION['ms_oauth_states'] = array_slice($validStates, -5);
}

function pgpPushMicrosoftOauthState(string $state, string $nonce): void
{
    pgpCleanupMicrosoftOauthStates();

    $states = $_SESSION['ms_oauth_states'] ?? [];
    if (!is_array($states)) {
        $states = [];
    }

    $states[] = [
        'value' => $state,
        'created_at' => time(),
        'nonce' => $nonce,
    ];

    $_SESSION['ms_oauth_states'] = array_slice($states, -5);
    unset($_SESSION['ms_oauth_state']);
}

/** @return array{state:string,nonce:string}|null */
function pgpConsumeMicrosoftOauthRequest(string $state): ?array
{
    $candidate = trim($state);
    if ($candidate === '') {
        return null;
    }

    pgpCleanupMicrosoftOauthStates();

    $states = $_SESSION['ms_oauth_states'] ?? [];
    if (is_array($states)) {
        foreach ($states as $index => $entry) {
            $value = (string)($entry['value'] ?? '');
            if ($value !== '' && hash_equals($value, $candidate)) {
                unset($states[$index]);
                $_SESSION['ms_oauth_states'] = array_values($states);
                unset($_SESSION['ms_oauth_state']);
                return ['state' => $candidate, 'nonce' => (string)($entry['nonce'] ?? '')];
            }
        }
    }

    unset($_SESSION['ms_oauth_state']);
    return null;
}

function pgpEmailDomain(string $email): string
{
    $normalized = mb_strtolower(trim($email), 'UTF-8');
    $parts = explode('@', $normalized);
    return count($parts) === 2 ? trim($parts[1]) : '';
}

function pgpIsAllowedMicrosoftEmail(string $email, array $config): bool
{
    $allowedDomains = $config['allowed_domains'] ?? [];
    if (!is_array($allowedDomains) || $allowedDomains === []) {
        return true;
    }

    $domain = pgpEmailDomain($email);
    if ($domain === '') {
        return false;
    }

    return in_array($domain, $allowedDomains, true);
}

function pgpEnvInt(string $name, int $default, int $min, int $max): int
{
    $raw = getenv($name);
    if ($raw === false || $raw === '') {
        return $default;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false) {
        return $default;
    }

    return max($min, min($max, $value));
}

function pgpMsHttpTimeoutSeconds(): int
{
    return pgpEnvInt('MS_HTTP_TIMEOUT_SECONDS', 60, 5, 300);
}

function pgpMsHttpConnectTimeoutSeconds(): int
{
    $timeout = pgpMsHttpTimeoutSeconds();
    $connectTimeout = pgpEnvInt('MS_HTTP_CONNECT_TIMEOUT_SECONDS', 15, 3, 120);
    return min($connectTimeout, $timeout);
}

function pgpMsIsRetryableCurlError(int $errorNo): bool
{
    return in_array($errorNo, [6, 7, 28, 35], true);
}

function pgpMsHttpRequestFallback(string $url, string $method, array $headers, ?string $body): array
{
    $httpOptions = [
        'method' => strtoupper($method),
        'header' => implode("\r\n", $headers),
        'timeout' => pgpMsHttpTimeoutSeconds(),
        'ignore_errors' => true,
    ];

    if ($body !== null) {
        $httpOptions['content'] = $body;
    }

    $context = stream_context_create([
        'http' => $httpOptions,
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $httpCode = isset($matches[1]) ? (int)$matches[1] : 0;

    if ($response === false) {
        throw new RuntimeException('No fue posible contactar a Microsoft.');
    }

    return [$httpCode, $response];
}

function pgpMsCurlRequestWithFallback(string $url, array $curlOptions, string $fallbackMethod, array $headers, ?string $fallbackBody, string $errorPrefix): array
{
    $attemptOptions = [$curlOptions];

    if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
        $ipv4Options = $curlOptions;
        $ipv4Options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        $attemptOptions[] = $ipv4Options;
    }

    $lastError = '';
    $lastErrorNo = 0;
    $lastHttpCode = 0;

    foreach ($attemptOptions as $options) {
        $ch = curl_init($url);
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $lastError = curl_error($ch);
        $lastErrorNo = (int)curl_errno($ch);
        curl_close($ch);

        if ($response !== false) {
            return [$lastHttpCode, $response];
        }

        if (!pgpMsIsRetryableCurlError($lastErrorNo)) {
            break;
        }
    }

    try {
        return pgpMsHttpRequestFallback($url, $fallbackMethod, $headers, $fallbackBody);
    } catch (Throwable $fallbackError) {
        $suffix = $lastError !== '' ? (' (cURL ' . $lastErrorNo . ': ' . $lastError . ')') : '';
        throw new RuntimeException($errorPrefix . $suffix);
    }
}

function pgpHttpPostForm(string $url, array $data): array
{
    $body = http_build_query($data);
    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'Content-Length: ' . strlen($body),
    ];

    if (function_exists('curl_init')) {
        return pgpMsCurlRequestWithFallback($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => pgpMsHttpTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => pgpMsHttpConnectTimeoutSeconds(),
        ], 'POST', $headers, $body, 'No fue posible contactar a Microsoft.');
    }

    return pgpMsHttpRequestFallback($url, 'POST', $headers, $body);
}

function pgpHttpGetPublicJson(string $url): array
{
    $headers = ['Accept: application/json'];
    if (function_exists('curl_init')) {
        return pgpMsCurlRequestWithFallback($url, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => pgpMsHttpTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => pgpMsHttpConnectTimeoutSeconds(),
        ], 'GET', $headers, null, 'No fue posible consultar las claves de Microsoft.');
    }
    return pgpMsHttpRequestFallback($url, 'GET', $headers, null);
}

function pgpHttpGetJson(string $url, string $bearerToken): array
{
    $headers = [
        'Authorization: Bearer ' . $bearerToken,
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        return pgpMsCurlRequestWithFallback($url, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => pgpMsHttpTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => pgpMsHttpConnectTimeoutSeconds(),
        ], 'GET', $headers, null, 'No fue posible consultar Microsoft Graph.');
    }

    return pgpMsHttpRequestFallback($url, 'GET', $headers, null);
}
