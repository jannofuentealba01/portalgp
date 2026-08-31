<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

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
         FROM cr_usuario_roles ur
         JOIN cr_roles r ON ur.rol_id = r.Id
         WHERE ur.usuario_id = :usuario_id"
    );
    $stmt->bindValue(':usuario_id', $usuarioId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function pgpLoginUserRecord(PDO $conn, array $user, string $loginSource = 'local'): void
{
    $userId = (int)($user['id'] ?? $user['Id'] ?? 0);
    $roles = $userId > 0 ? pgpFetchUserRoles($conn, $userId) : [];

    session_regenerate_id(true);
    $_SESSION['usuario'] = pgpBuildUserSession($user, $roles, $loginSource);
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
    header('Location: login.php?login_error=' . rawurlencode($message));
    exit();
}

function pgpMicrosoftAuthConfig(): array
{
    $envCfg = [
        'tenant_id' => getenv('MS_ENTRA_TENANT_ID') ?: '',
        'client_id' => getenv('MS_ENTRA_CLIENT_ID') ?: '',
        'client_secret' => getenv('MS_ENTRA_CLIENT_SECRET') ?: '',
        'redirect_uri' => getenv('MS_ENTRA_REDIRECT_URI') ?: '',
        'allowed_domains' => getenv('MS_ENTRA_ALLOWED_DOMAINS') ?: '',
    ];

    $fileCfg = [];
    foreach ([__DIR__ . '/microsoft_auth_config.php', __DIR__ . '/microsoft_auth_config.local.php'] as $file) {
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

function pgpMicrosoftAuthorizeUrl(array $config, string $state): string
{
    $params = [
        'client_id' => (string)$config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => pgpMicrosoftRedirectUri($config),
        'response_mode' => 'query',
        'scope' => implode(' ', pgpMicrosoftDefaultScopes()),
        'state' => $state,
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

function pgpBase64UrlDecode(string $value): string
{
    $padded = str_pad(strtr($value, '-_', '+/'), strlen($value) % 4 === 0 ? strlen($value) : strlen($value) + 4 - strlen($value) % 4, '=', STR_PAD_RIGHT);
    $decoded = base64_decode($padded, true);
    return $decoded === false ? '' : $decoded;
}

function pgpDecodeMicrosoftIdToken(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) < 2) {
        return [];
    }

    $payload = json_decode(pgpBase64UrlDecode($parts[1]), true);
    return is_array($payload) ? $payload : [];
}

function pgpMicrosoftProfileFromIdToken(array $tokenJson, array $config): array
{
    $claims = pgpDecodeMicrosoftIdToken((string)($tokenJson['id_token'] ?? ''));
    if ($claims === []) {
        return [];
    }

    $audience = (string)($claims['aud'] ?? '');
    $tenantId = (string)($claims['tid'] ?? '');
    $expiresAt = (int)($claims['exp'] ?? 0);

    if ($audience !== (string)$config['client_id']) {
        return [];
    }

    if ($tenantId !== '' && strcasecmp($tenantId, (string)$config['tenant_id']) !== 0) {
        return [];
    }

    if ($expiresAt > 0 && $expiresAt < (time() - 300)) {
        return [];
    }

    return [
        'mail' => trim((string)($claims['email'] ?? '')),
        'userPrincipalName' => trim((string)($claims['preferred_username'] ?? $claims['upn'] ?? '')),
        'displayName' => trim((string)($claims['name'] ?? '')),
        'id' => trim((string)($claims['oid'] ?? $claims['sub'] ?? '')),
    ];
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
        if ($value === '' || $createdAt <= 0 || ($now - $createdAt) > 900) {
            continue;
        }

        $validStates[] = [
            'value' => $value,
            'created_at' => $createdAt,
        ];
    }

    $_SESSION['ms_oauth_states'] = array_slice($validStates, -5);
}

function pgpPushMicrosoftOauthState(string $state): void
{
    pgpCleanupMicrosoftOauthStates();

    $states = $_SESSION['ms_oauth_states'] ?? [];
    if (!is_array($states)) {
        $states = [];
    }

    $states[] = [
        'value' => $state,
        'created_at' => time(),
    ];

    $_SESSION['ms_oauth_states'] = array_slice($states, -5);
    $_SESSION['ms_oauth_state'] = $state;
}

function pgpConsumeMicrosoftOauthState(string $state): bool
{
    $candidate = trim($state);
    if ($candidate === '') {
        return false;
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
                return true;
            }
        }
    }

    $legacyState = (string)($_SESSION['ms_oauth_state'] ?? '');
    if ($legacyState !== '' && hash_equals($legacyState, $candidate)) {
        unset($_SESSION['ms_oauth_state']);
        return true;
    }

    return false;
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

    $context = stream_context_create(['http' => $httpOptions]);
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
            CURLOPT_TIMEOUT => pgpMsHttpTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => pgpMsHttpConnectTimeoutSeconds(),
        ], 'POST', $headers, $body, 'No fue posible contactar a Microsoft.');
    }

    return pgpMsHttpRequestFallback($url, 'POST', $headers, $body);
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
            CURLOPT_TIMEOUT => pgpMsHttpTimeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => pgpMsHttpConnectTimeoutSeconds(),
        ], 'GET', $headers, null, 'No fue posible consultar Microsoft Graph.');
    }

    return pgpMsHttpRequestFallback($url, 'GET', $headers, null);
}
