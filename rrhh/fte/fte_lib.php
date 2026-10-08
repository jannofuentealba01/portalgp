<?php
declare(strict_types=1);

require_once __DIR__ . '/fte_monthly_engine.php';
require_once __DIR__ . '/fte_calendar_engine.php';
require_once __DIR__ . '/fte_headcount_history.php';
require_once __DIR__ . '/fte_identity.php';
require_once __DIR__ . '/fte_headcount_snapshot.php';
require_once __DIR__ . '/fte_monthly_source_snapshot.php';
require_once __DIR__ . '/fte_attendance_status.php';
require_once __DIR__ . '/fte_monthly_report.php';
require_once __DIR__ . '/fte_geovictoria_batches.php';
require_once __DIR__ . '/fte_runtime_cache.php';

final class FteGeoVictoriaException extends RuntimeException
{
    private string $reason;
    private int $providerStatus;

    public function __construct(string $reason, int $providerStatus = 0, string $message = '')
    {
        $this->reason = $reason;
        $this->providerStatus = $providerStatus;
        parent::__construct($message !== '' ? $message : 'GeoVictoria request failed: ' . $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function providerStatus(): int
    {
        return $this->providerStatus;
    }
}

final class FteAttendanceBatchException extends RuntimeException
{
    private string $reason;
    private int $providerStatus;

    public function __construct(string $reason, int $providerStatus = 0, string $message = '')
    {
        $this->reason = $reason;
        $this->providerStatus = $providerStatus;
        parent::__construct($message !== '' ? $message : 'FTE attendance batch failed: ' . $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function providerStatus(): int
    {
        return $this->providerStatus;
    }
}

function fte_load_config(): array
{
    $base = require __DIR__ . '/fte_config.php';
    $localFile = __DIR__ . '/fte_config.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $base = array_merge($base, $local);
        }
    }
    return $base;
}

function fte_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fte_arr_get(array $source, string $path)
{
    $current = $source;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }
    return $current;
}

function fte_first_non_empty(array $source, array $paths): string
{
    foreach ($paths as $path) {
        $value = strpos($path, '.') !== false ? fte_arr_get($source, $path) : ($source[$path] ?? null);
        if ($value === null || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '') {
            return $text;
        }
    }
    return '';
}

function fte_normalize_identifier($value): string
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', (string)$value));
}

function fte_normalize_cost_center($value): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$value));
    return strtoupper($text);
}

function fte_cost_center_parts(array $person): array
{
    $objectPaths = [
        'current_job.cost_center',
        'current_employment.cost_center',
        'employment.cost_center',
        'cost_center',
        'centro_costo',
    ];
    foreach ($objectPaths as $path) {
        $value = strpos($path, '.') !== false ? fte_arr_get($person, $path) : ($person[$path] ?? null);
        if ($value === null || $value === '') {
            continue;
        }
        if (is_array($value)) {
            $code = fte_normalize_cost_center($value['code'] ?? '');
            $name = trim((string)($value['name'] ?? $value['description'] ?? $value['label'] ?? ''));
            if ($code !== '' || $name !== '') {
                return ['code' => $code !== '' ? $code : fte_normalize_cost_center($name), 'name' => $name];
            }
            continue;
        }
        $code = fte_normalize_cost_center($value);
        if ($code !== '') {
            return ['code' => $code, 'name' => ''];
        }
    }

    $name = fte_first_non_empty($person, [
        'cost_center_name',
        'cost_center_description',
        'centro_costo_name',
        'centro_costo.description',
        'centro_costo.descripcion',
    ]);
    return ['code' => fte_normalize_cost_center($name), 'name' => $name];
}

function fte_normalize_buk_job(array $raw): ?array
{
    $startDate = fte_buk_date($raw['start_date'] ?? $raw['active_since'] ?? null);
    $endDate = fte_buk_date($raw['end_date'] ?? $raw['active_until'] ?? null);
    $costCenter = fte_normalize_cost_center($raw['cost_center'] ?? '');
    if ($startDate === null && $endDate === null && $costCenter === '') {
        return null;
    }
    return [
        'job_id' => isset($raw['id']) && is_numeric($raw['id']) ? (int)$raw['id'] : null,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'cost_center_code' => $costCenter,
        'cost_center_name' => '',
        'weekly_hours' => isset($raw['weekly_hours']) && is_numeric($raw['weekly_hours']) ? (float)$raw['weekly_hours'] : null,
        'working_schedule_type' => trim((string)($raw['working_schedule_type'] ?? '')),
        'without_wage' => (bool)($raw['without_wage'] ?? false),
    ];
}

function fte_buk_date($value): ?string
{
    $text = trim((string)$value);
    if ($text === '' || !preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $match)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
    return $date && $date->format('Y-m-d') === $match[1] ? $match[1] : null;
}

function fte_normalize_buk_person(array $raw): ?array
{
    $identifier = fte_first_non_empty($raw, ['rut', 'identification', 'id_number', 'national_id', 'document_number']);
    $normalizedIdentifier = fte_normalize_identifier($identifier);
    if ($normalizedIdentifier === '') {
        return null;
    }

    $fullName = fte_first_non_empty($raw, ['full_name']);
    if ($fullName === '') {
        $fullName = trim((string)($raw['first_name'] ?? '') . ' ' . (string)($raw['last_name'] ?? ''));
    }

    $status = fte_first_non_empty($raw, ['status', 'employment_status', 'state', 'situation']);
    $active = null;
    if (array_key_exists('active', $raw)) {
        $active = filter_var($raw['active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    } elseif ($status !== '') {
        $normalizedStatus = strtolower($status);
        if (in_array($normalizedStatus, ['activo', 'active', 'vigente'], true)) {
            $active = true;
        } elseif (in_array($normalizedStatus, ['inactivo', 'inactive', 'desvinculado', 'terminado'], true)) {
            $active = false;
        }
    }

    $costCenter = fte_cost_center_parts($raw);
    $jobs = [];
    foreach (($raw['jobs'] ?? []) as $rawJob) {
        if (!is_array($rawJob)) {
            continue;
        }
        $job = fte_normalize_buk_job($rawJob);
        if ($job !== null) {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn(array $left, array $right): int =>
        strcmp((string)($left['start_date'] ?? ''), (string)($right['start_date'] ?? ''))
        ?: ((int)($left['job_id'] ?? 0) <=> (int)($right['job_id'] ?? 0)));
    $activeSince = fte_buk_date($raw['active_since'] ?? null);
    $activeUntil = fte_buk_date($raw['active_until'] ?? null);
    $employmentPeriods = [];
    if ($activeSince !== null || $activeUntil !== null || $active !== null) {
        $employmentPeriods[] = [
            'start_date' => $activeSince,
            'end_date' => $activeUntil,
            'start_source' => 'Buk employee.active_since',
            'end_source' => 'Buk employee.active_until',
            'verified' => true,
        ];
    }
    return [
        'buk_employee_id' => fte_first_non_empty($raw, ['id']),
        'identifier' => $identifier,
        'normalized_identifier' => $normalizedIdentifier,
        'person_name' => $fullName !== '' ? $fullName : $identifier,
        'cost_center_code' => $costCenter['code'],
        'cost_center_name' => $costCenter['name'],
        'status' => $status,
        'active' => $active,
        'active_since' => $activeSince,
        'active_until' => $activeUntil,
        // Fechas de la relacion laboral del trabajador. Nunca se infieren
        // desde current_job ni jobs: esos registros tambien cambian por
        // traslados internos de cargo o centro de costo.
        'employment_start_source' => 'Buk employee.active_since',
        'employment_end_source' => 'Buk employee.active_until',
        'employment_dates_verified' => true,
        'employment_periods' => $employmentPeriods,
        'jobs' => $jobs,
    ];
}

/** Optional, diagnostic-only observer. It never receives URLs, headers or payloads. */
function fte_performance_emit(?callable $observer, array $event): void
{
    if ($observer === null) {
        return;
    }
    try {
        $observer($event);
    } catch (Throwable $ignored) {
        // A measurement must never change the response or calculation.
    }
}

function fte_performance_phase(array $config, string $phase, int $started): void
{
    fte_performance_emit($config['_performance_observer'] ?? null, [
        'type' => 'phase', 'phase' => $phase,
        'seconds' => (hrtime(true) - $started) / 1e9,
    ]);
}

function fte_http_json(
    string $url,
    array $headers = [],
    ?array $jsonPayload = null,
    int $timeout = 45,
    array $curlOptions = [],
    ?callable $observer = null,
    string $source = 'http'
): array
{
    $ch = curl_init($url);
    $httpHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($jsonPayload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($jsonPayload, JSON_UNESCAPED_UNICODE);
        $httpHeaders[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $httpHeaders;
    }
    curl_setopt_array($ch, array_replace($options, $curlOptions));
    $started = hrtime(true);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    $status = (int)($info['http_code'] ?? 0);
    curl_close($ch);

    $decoded = null;
    if ($raw !== false && $raw !== null && $raw !== '') {
        $tmp = json_decode((string)$raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decoded = $tmp;
        }
    }
    fte_performance_emit($observer, [
        'type' => 'http', 'source' => $source, 'status' => $status, 'errno' => $errno,
        'seconds' => (hrtime(true) - $started) / 1e9,
        'ttfb_seconds' => (float)($info['starttransfer_time'] ?? 0),
        'bytes' => is_string($raw) ? strlen($raw) : 0,
    ]);
    return [
        'ok' => $errno === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'errno' => $errno,
        'error' => $error,
        'raw' => $raw,
        'json' => $decoded,
    ];
}

function fte_geovictoria_curl_options(): array
{
    // GeoVictoria deja algunas conexiones HTTP/2 abiertas sin completar el
    // handshake TLS. Forzar HTTP/1.1 evita esos timeouts intermitentes.
    return [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1];
}

function fte_geovictoria_login_response(
    array $config,
    string $url,
    array $credentials,
    int $timeoutSeconds,
    int $attempt
): array {
    if (isset($config['_geovictoria_login_handler']) && is_callable($config['_geovictoria_login_handler'])) {
        $response = $config['_geovictoria_login_handler']($url, $credentials, $timeoutSeconds, $attempt);
        if (!is_array($response)) {
            return ['ok' => false, 'status' => 0, 'errno' => 0, 'json' => null];
        }
        return $response;
    }

    return fte_http_json(
        $url,
        [],
        $credentials,
        $timeoutSeconds,
        fte_geovictoria_curl_options(),
        $config['_performance_observer'] ?? null,
        'geo_auth'
    );
}

function fte_geovictoria_token_cache_path(array $config): string
{
    $configured = trim((string)($config['geovictoria_token_cache_file'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }
    $scope = fte_geovictoria_cache_scope($config);
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'portalgp-geovictoria-' . $scope . '.json';
}

function fte_geovictoria_shared_token(array $config): ?string
{
    $path = fte_geovictoria_token_cache_path($config);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    $cached = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($cached) || !is_string($cached['token'] ?? null)
        || !hash_equals(fte_geovictoria_cache_scope($config), (string)($cached['scope'] ?? ''))
        || (int)($cached['expires_at'] ?? 0) <= time() + 30) {
        return null;
    }
    return $cached['token'];
}

function fte_geovictoria_store_shared_token(array $config, string $token, int $expiresAt): void
{
    $path = fte_geovictoria_token_cache_path($config);
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $payload = json_encode(['scope' => fte_geovictoria_cache_scope($config), 'token' => $token, 'expires_at' => $expiresAt], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || @file_put_contents($temporary, $payload, LOCK_EX) === false) {
        return;
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
    }
}

function fte_geovictoria_forget_shared_token(array $config): void
{
    $path = fte_geovictoria_token_cache_path($config);
    if (is_file($path)) {
        @unlink($path);
    }
}

function fte_buk_headers(array $config): array
{
    $token = (string)$config['buk_token'];
    return [
        'auth_token: ' . $token,
    ];
}

function fte_assert_https_url(string $url, string $provider): void
{
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https' || empty($parts['host'])) {
        throw new RuntimeException('La URL de ' . $provider . ' debe ser HTTPS y valida.');
    }
}

function fte_assert_buk_config(array $config): void
{
    fte_assert_https_url(trim((string)($config['buk_base_url'] ?? '')), 'Buk');
    if (trim((string)($config['buk_token'] ?? '')) === '') {
        throw new RuntimeException('Buk no configurado. Falta definir el token en la configuracion local o el entorno.');
    }
}

function fte_assert_geovictoria_config(array $config): void
{
    fte_assert_https_url(trim((string)($config['geovictoria_base_url'] ?? '')), 'GeoVictoria');
    if (trim((string)($config['geovictoria_user'] ?? '')) === '' || trim((string)($config['geovictoria_password'] ?? '')) === '') {
        throw new RuntimeException('GeoVictoria no configurado. Faltan credenciales en la configuracion local o el entorno.');
    }
}

function fte_redact_error_message(string $message, array $config): string
{
    foreach (['buk_token', 'geovictoria_user', 'geovictoria_password'] as $key) {
        $secret = trim((string)($config[$key] ?? ''));
        if ($secret !== '') {
            $message = str_replace($secret, '[REDACTED]', $message);
        }
    }
    return preg_replace('/[\r\n\t]+/', ' ', $message) ?: 'Error no disponible';
}

function fte_extract_list($payload): array
{
    if (is_array($payload)) {
        if (array_keys($payload) === range(0, count($payload) - 1)) {
            return $payload;
        }
        foreach (['data', 'people', 'employees', 'items', 'vacations', 'licences', 'licenses', 'absences'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }
    }
    return [];
}

function fte_response_has_next_page(array $payload, int $page, int $rowCount, int $requestedPageSize): bool
{
    $pagination = $payload['pagination'] ?? null;
    if (is_array($pagination)) {
        if (!empty($pagination['next'])) {
            return true;
        }
        $totalPages = (int)($pagination['total_pages'] ?? 0);
        if ($totalPages > 0) {
            return $page < $totalPages;
        }
        return false;
    }
    return $rowCount >= $requestedPageSize;
}

function fte_fetch_buk_people(array $config, bool $activeOnly = true, ?array &$identityDiagnostics = null): array
{
    fte_assert_buk_config($config);
    $identityPolicyHash = hash('sha256', json_encode([
        'identity' => $config['identity_exclusions'] ?? [],
        'attendance' => $config['attendance_exclusions'] ?? [],
    ], JSON_UNESCAPED_UNICODE));
    $cacheKey = 'buk:people:' . fte_buk_cache_scope($config) . '|' . ($activeOnly ? 'active' : 'history') . '|identity-v3-employment-dates|' . $identityPolicyHash;
    $cacheTtl = max(0, (int)($config['buk_people_cache_ttl_seconds'] ?? 300));
    $memoryCacheAllowed = session_status() === PHP_SESSION_ACTIVE || !empty($config['_fte_session_detached']);
    $cachedSource = fte_runtime_cache_read($config, $cacheKey, $cacheTtl);
    if ($cachedSource !== null && is_array($cachedSource['data']['people'] ?? null)
        && is_array($cachedSource['data']['identity_diagnostics'] ?? null)) {
        $identityDiagnostics = $cachedSource['data']['identity_diagnostics'];
        fte_performance_emit($config['_performance_observer'] ?? null, ['type' => 'cache', 'source' => 'buk_people', 'scope' => $activeOnly ? 'active' : 'history', 'hit' => true]);
        return $cachedSource['data']['people'];
    }
    if ($memoryCacheAllowed && $cacheTtl > 0 && empty($config['_refresh_buk_cache'])) {
        $cache = $_SESSION['fte_buk_people_caches'][$cacheKey] ?? null;
        if (is_array($cache)
            && min((int)($cache['expires_at'] ?? 0), (int)($cache['created_at'] ?? 0) + $cacheTtl) > fte_runtime_cache_now($config)
            && is_array($cache['people'] ?? null)) {
            $identityDiagnostics = is_array($cache['identity_diagnostics'] ?? null)
                ? $cache['identity_diagnostics']
                : [];
            return $cache['people'];
        }
    }
    $rowsCreatedAt = null;
    $rows = fte_fetch_buk_people_rows($config, $cacheTtl, $rowsCreatedAt);
    $people = [];
    foreach ($rows as $row) {
        $person = fte_normalize_buk_person($row);
        if ($person !== null && (!$activeOnly || $person['active'] !== false)) {
            $people[] = $person;
        }
    }
    $people = fte_identity_unify_people($people, $config, $identityDiagnostics);
    $namesCreatedAt = null;
    $names = fte_fetch_buk_cost_center_names($config, $namesCreatedAt);
    foreach ($people as &$person) {
        $code = (string)($person['cost_center_code'] ?? '');
        if (($person['cost_center_name'] ?? '') === '' && $code !== '' && isset($names[$code])) {
            $person['cost_center_name'] = $names[$code];
        }
        foreach ($person['jobs'] as &$job) {
            $jobCode = (string)($job['cost_center_code'] ?? '');
            if ($jobCode !== '' && isset($names[$jobCode])) {
                $job['cost_center_name'] = $names[$jobCode];
            }
        }
        unset($job);
    }
    unset($person);
    if ($cacheTtl > 0 && $namesCreatedAt !== null) {
        $createdAt = min((int)$rowsCreatedAt, $namesCreatedAt);
        $entry = ['created_at' => $createdAt, 'expires_at' => $createdAt + $cacheTtl,
            'people' => $people, 'identity_diagnostics' => $identityDiagnostics ?? []];
        if ($memoryCacheAllowed) {
            $_SESSION['fte_buk_people_caches'][$cacheKey] = $entry;
            if (count($_SESSION['fte_buk_people_caches']) > 4) {
                array_shift($_SESSION['fte_buk_people_caches']);
            }
        }
        fte_runtime_cache_write($config, $cacheKey, ['people' => $people,
            'identity_diagnostics' => $identityDiagnostics ?? []], $cacheTtl, $createdAt);
    }
    return $people;
}

/** Raw full roster is identical for active/history; filter before identity merging, as before. */
function fte_fetch_buk_people_rows(array $config, int $cacheTtl, ?int &$createdAt): array
{
    $base = rtrim((string)$config['buk_base_url'], '/');
    $country = trim((string)$config['buk_country']);
    $key = 'buk:employees:pages-v1:' . fte_buk_cache_scope($config);
    $cached = fte_runtime_cache_read($config, $key, $cacheTtl);
    if ($cached !== null && is_array($cached['data']['rows'] ?? null)) {
        $createdAt = (int)$cached['created_at'];
        fte_performance_emit($config['_performance_observer'] ?? null, ['type' => 'cache', 'source' => 'buk_people_raw', 'hit' => true]);
        return $cached['data']['rows'];
    }
    $path = "/api/v1/{$country}/employees";
    $rows = [];
    for ($page = 1; $page <= 50; $page++) {
        $query = [
            'page' => $page,
            'per_page' => 100,
            'page_size' => 100,
            'include' => 'department,sub_department,position,employment,contract,organizational_unit,company_area,team,current_job,current_employment',
        ];
        $url = $base . $path . '?' . http_build_query($query);
        $response = fte_buk_http_response($config, $url, 'buk_people');
        if (!$response['ok']) {
            $status = (int)($response['status'] ?? 0);
            if ($status === 401 || $status === 403) {
                throw new RuntimeException('Token Buk rechazado.');
            }
            if ($status === 404) {
                throw new RuntimeException('Endpoint de trabajadores Buk no encontrado.');
            }
            if ($status === 429) {
                throw new RuntimeException('Buk alcanzo el limite temporal de solicitudes.');
            }
            throw new RuntimeException('No fue posible consultar trabajadores en Buk (HTTP ' . $status . ').');
        }
        if (!is_array($response['json'])) {
            throw new RuntimeException('Buk respondio con un formato no reconocido.');
        }
        if (!array_is_list($response['json']) && !array_filter(['data', 'people', 'employees', 'items'],
            static fn(string $key): bool => isset($response['json'][$key]) && is_array($response['json'][$key]))) {
            throw new RuntimeException('Buk no entrego una lista reconocible de trabajadores.');
        }
        $pageRows = fte_extract_list($response['json']);
        if (!$pageRows) {
            if (fte_response_has_next_page($response['json'], $page, 0, 100)) {
                throw new RuntimeException('Buk entrego una pagina vacia antes de completar la nomina.');
            }
            break;
        }
        foreach ($pageRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = $row;
        }
        if (!fte_response_has_next_page($response['json'], $page, count($pageRows), 100)) {
            break;
        }
        if ($page === 50) {
            throw new RuntimeException('Buk excedio el limite de paginas; nomina incompleta.');
        }
    }
    $createdAt = fte_runtime_cache_now($config);
    fte_runtime_cache_write($config, $key, ['rows' => $rows], $cacheTtl, $createdAt);
    return $rows;
}

function fte_build_buk_cost_centers(array $people): array
{
    $centers = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $code = fte_normalize_cost_center($person['cost_center_code'] ?? '');
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($code === '' || $identifier === '') {
            continue;
        }
        if (!isset($centers[$code])) {
            $centers[$code] = [
                'cost_center_code' => $code,
                'cost_center_name' => trim((string)($person['cost_center_name'] ?? '')),
                'identifiers' => [],
            ];
        }
        $name = trim((string)($person['cost_center_name'] ?? ''));
        if ($centers[$code]['cost_center_name'] === '' && $name !== '') {
            $centers[$code]['cost_center_name'] = $name;
        }
        $centers[$code]['identifiers'][$identifier] = true;
    }
    ksort($centers, SORT_NATURAL);
    return array_values(array_map(static function (array $center): array {
        $center['people_count'] = count($center['identifiers']);
        unset($center['identifiers']);
        return $center;
    }, $centers));
}

function fte_fetch_buk_cost_center_names(array $config, ?int &$createdAt = null): array
{
    $ttl = max(0, (int)($config['buk_people_cache_ttl_seconds'] ?? 300));
    $key = 'buk:areas:v1:' . fte_buk_cache_scope($config);
    $cached = fte_runtime_cache_read($config, $key, $ttl);
    if ($cached !== null && is_array($cached['data']['names'] ?? null)) {
        $createdAt = (int)$cached['created_at'];
        return $cached['data']['names'];
    }
    $country = trim((string)$config['buk_country']);
    $result = fte_buk_fetch_all($config, "/api/v1/{$country}/organization/areas/", [
        'status' => 'both',
        'page_size' => 1000,
        'per_page' => 1000,
    ], 50);
    if (!$result['ok']) {
        $createdAt = null;
        return [];
    }
    $names = [];
    foreach ($result['items'] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = fte_normalize_cost_center($row['cost_center'] ?? $row['code'] ?? '');
        $name = trim((string)($row['name'] ?? $row['description'] ?? ''));
        if ($code !== '' && $name !== '') {
            $names[$code] = $name;
        }
    }
    $createdAt = fte_runtime_cache_now($config);
    fte_runtime_cache_write($config, $key, ['names' => $names], $ttl, $createdAt);
    return $names;
}

function fte_buk_http_response(array $config, string $url, string $source): array
{
    if (isset($config['_buk_http_handler']) && is_callable($config['_buk_http_handler'])) {
        return $config['_buk_http_handler']($url, $source);
    }
    return fte_http_json($url, fte_buk_headers($config), null, 30, [], $config['_performance_observer'] ?? null, $source);
}

function fte_buk_fetch_all(array $config, string $path, array $query = [], int $maxPages = 20): array
{
    $base = rtrim((string)$config['buk_base_url'], '/');
    $items = [];
    $lastStatus = 0;
    $lastError = '';

    for ($page = 1; $page <= $maxPages; $page++) {
        $pageQuery = $query;
        $pageQuery['page'] = $page;
        if (!isset($pageQuery['page_size'])) {
            $pageQuery['page_size'] = 100;
        }
        if (!isset($pageQuery['per_page'])) {
            $pageQuery['per_page'] = 100;
        }

        $url = $base . $path . '?' . http_build_query($pageQuery);
        $response = fte_buk_http_response($config, $url, 'buk_other');
        $lastStatus = (int)($response['status'] ?? 0);
        $lastError = trim((string)($response['error'] ?? ''));
        if (!$response['ok'] || !is_array($response['json'])) {
            return ['ok' => false, 'items' => $items, 'status' => $lastStatus, 'error' => $lastError];
        }

        $pageRows = fte_extract_list($response['json']);
        if (!$pageRows) {
            break;
        }
        foreach ($pageRows as $row) {
            if (is_array($row)) {
                $items[] = $row;
            }
        }
        if (!fte_response_has_next_page($response['json'], $page, count($pageRows), (int)$pageQuery['page_size'])) {
            break;
        }
        if ($page === $maxPages) {
            return ['ok' => false, 'items' => [], 'status' => $lastStatus, 'error' => 'Paginacion incompleta'];
        }
    }

    return ['ok' => true, 'items' => $items, 'status' => $lastStatus, 'error' => ''];
}

function fte_absence_employee_keys(array $item): array
{
    return fte_identity_aliases_for_absence($item);
}

function fte_absence_date_value(array $item, array $paths): string
{
    foreach ($paths as $path) {
        $value = strpos($path, '.') !== false ? fte_arr_get($item, $path) : ($item[$path] ?? null);
        if ($value === null || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text, $m)) {
                return $m[0];
            }
            return $text;
        }
    }
    return '';
}

function fte_absence_detail(array $item, string $fallback): string
{
    $parts = [];
    foreach ([
        'type',
        'type.name',
        'absence_type',
        'absence_type.name',
        'licence_type',
        'licence_type.name',
        'licence_type_code',
        'reason',
        'reason.name',
        'description',
    ] as $path) {
        $value = strpos($path, '.') !== false ? fte_arr_get($item, $path) : ($item[$path] ?? null);
        if ($value === null || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '' && !in_array($text, $parts, true)) {
            $parts[] = $text;
        }
    }
    return $parts ? implode(' · ', array_slice($parts, 0, 2)) : $fallback;
}

function fte_index_absence_items(array $items, string $kind, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $indexed = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $startText = fte_absence_date_value($item, ['start_date', 'date', 'from', 'application_start_date']);
        $endText = fte_absence_date_value($item, ['end_date', 'to', 'application_end_date', 'return_date']);
        if ($startText === '') {
            continue;
        }
        if ($endText === '') {
            $endText = $startText;
        }
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', substr($startText, 0, 10));
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', substr($endText, 0, 10));
        if (!$start || !$end) {
            continue;
        }
        if ($end < $from || $start > $to) {
            continue;
        }
        $rangeStart = $start < $from ? $from : $start;
        $rangeEnd = $end > $to ? $to : $end;
        $detail = fte_absence_detail($item, $kind);
        $keys = fte_absence_employee_keys($item);
        if (!$keys) {
            continue;
        }
        for ($day = $rangeStart; $day <= $rangeEnd; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            foreach ($keys as $key) {
                $indexed[$key][$date] = [
                    'kind' => $kind,
                    'reason' => $detail,
                    'from' => $start->format('Y-m-d'),
                    'to' => $end->format('Y-m-d'),
                ];
            }
        }
    }
    return $indexed;
}

function fte_merge_absence_index(array ...$indexes): array
{
    $merged = [];
    foreach ($indexes as $index) {
        foreach ($index as $key => $byDate) {
            foreach ($byDate as $date => $row) {
                if (!isset($merged[$key][$date])) {
                    $merged[$key][$date] = $row;
                } elseif (($merged[$key][$date]['kind'] ?? '') !== 'Licencia medica' && ($row['kind'] ?? '') === 'Licencia medica') {
                    $merged[$key][$date] = $row;
                }
            }
        }
    }
    return $merged;
}

function fte_build_absence_payload(array $config, array $params): array
{
    [$defaultFrom, $defaultTo] = fte_default_range();
    $fromDate = trim((string)($params['from_date'] ?? $defaultFrom));
    $toDate = trim((string)($params['to_date'] ?? $defaultTo));
    [$from, $to] = fte_validate_range($fromDate, $toDate, (int)$config['max_range_days']);

    $requestedCodes = $params['cost_center_code'] ?? [];
    if (!is_array($requestedCodes)) {
        $requestedCodes = [$requestedCodes];
    }
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));

    $people = fte_fetch_buk_people($config);
    if ($requestedCodes) {
        $people = array_values(array_filter($people, static fn($p) => in_array($p['cost_center_code'], $requestedCodes, true)));
    } else {
        $people = [];
    }

    $country = trim((string)$config['buk_country']);
    $warnings = [];
    $vacations = fte_buk_fetch_all($config, "/api/v1/{$country}/vacations", [
        'start_before' => $to->format('Y-m-d'),
        'end_after' => $from->format('Y-m-d'),
    ]);
    $licences = fte_buk_fetch_all($config, "/api/v1/{$country}/absences/licence", [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ]);
    if (!$vacations['ok']) {
        $warnings[] = 'Buk vacaciones HTTP ' . (int)$vacations['status'];
    }
    if (!$licences['ok']) {
        $warnings[] = 'Buk licencias HTTP ' . (int)$licences['status'];
    }

    $vacationIndex = $vacations['ok'] ? fte_index_absence_items($vacations['items'], 'Vacaciones', $from, $to) : [];
    $licenceIndex = $licences['ok'] ? fte_index_absence_items($licences['items'], 'Licencia medica', $from, $to) : [];
    $reasonIndex = fte_merge_absence_index($vacationIndex, $licenceIndex);

    $reasons = [];
    foreach ($people as $person) {
        $keys = fte_identity_aliases_for_person($person);
        foreach ($keys as $key) {
            if (!isset($reasonIndex[$key])) {
                continue;
            }
            foreach ($reasonIndex[$key] as $date => $row) {
                $id = (string)($person['normalized_identifier'] ?? '');
                if ($id !== '') {
                    $reasons[$id][$date] = $row;
                }
            }
        }
    }

    return [
        'from_date' => $from->format('Y-m-d'),
        'to_date' => $to->format('Y-m-d'),
        'generated_at' => date(DATE_ATOM),
        'requested_cost_center_codes' => $requestedCodes,
        'warnings' => $warnings,
        'people' => $people,
        'reasons' => $reasons,
    ];
}

function fte_default_range(): array
{
    $end = new DateTimeImmutable('yesterday');
    $start = $end->modify('-13 days');
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

function fte_validate_range(string $fromDate, string $toDate, int $maxDays): array
{
    $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
    $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
    if (!$from || !$to || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate) {
        throw new RuntimeException('Rango de fechas invalido.');
    }
    if ($from > $to) {
        throw new RuntimeException('La fecha desde no puede ser mayor que la fecha hasta.');
    }
    $days = (int)$from->diff($to)->days + 1;
    if ($days > $maxDays) {
        throw new RuntimeException('El rango no puede superar ' . $maxDays . ' dias.');
    }
    return [$from, $to, $days];
}

function fte_iter_dates(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $dates = [];
    for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
        $dates[] = $day->format('Y-m-d');
    }
    return $dates;
}

function fte_geovictoria_token(array $config, int $timeoutSeconds = 30): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && empty($config['_fte_session_detached'])) {
        session_start();
    }
    $cached = $_SESSION['fte_geovictoria_token'] ?? null;
    $expires = (int)($_SESSION['fte_geovictoria_token_expires'] ?? 0);
    $scope = fte_geovictoria_cache_scope($config);
    if (is_string($cached) && $cached !== '' && $expires > time()
        && hash_equals($scope, (string)($_SESSION['fte_geovictoria_token_scope'] ?? ''))) {
        return $cached;
    }
    fte_assert_geovictoria_config($config);
    $sharedToken = fte_geovictoria_shared_token($config);
    if ($sharedToken !== null) {
        $_SESSION['fte_geovictoria_token'] = $sharedToken;
        $_SESSION['fte_geovictoria_token_scope'] = $scope;
        $_SESSION['fte_geovictoria_token_expires'] = time() + (int)$config['geovictoria_token_ttl_seconds'];
        return $sharedToken;
    }
    $user = trim((string)$config['geovictoria_user']);
    $password = trim((string)$config['geovictoria_password']);
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/Login';
    $attempts = max(1, min(3, (int)($config['geovictoria_login_attempts'] ?? 3)));
    $attemptTimeout = max(1, min(
        30,
        $timeoutSeconds,
        (int)($config['geovictoria_login_attempt_timeout_seconds'] ?? 12)
    ));
    $response = ['ok' => false, 'status' => 0, 'errno' => 0, 'json' => null];
    $reason = 'authentication';
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $response = fte_geovictoria_login_response(
            $config,
            $url,
            ['User' => $user, 'Password' => $password],
            $attemptTimeout,
            $attempt
        );
        if ($response['ok'] && is_array($response['json']) && !empty($response['json']['token'])) {
            break;
        }
        $reason = fte_geovictoria_failure_reason($response);
        if (in_array($reason, ['authentication', 'invalid_response'], true) || $attempt === $attempts) {
            break;
        }
        usleep((int)(250_000 * $attempt));
    }
    if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['token'])) {
        throw new FteGeoVictoriaException($reason, (int)($response['status'] ?? 0), 'GeoVictoria no entrego token de autenticacion.');
    }
    $_SESSION['fte_geovictoria_token'] = (string)$response['json']['token'];
    $_SESSION['fte_geovictoria_token_scope'] = $scope;
    $_SESSION['fte_geovictoria_token_expires'] = time() + (int)$config['geovictoria_token_ttl_seconds'];
    fte_geovictoria_store_shared_token(
        $config,
        (string)$_SESSION['fte_geovictoria_token'],
        (int)$_SESSION['fte_geovictoria_token_expires']
    );
    return (string)$_SESSION['fte_geovictoria_token'];
}

function fte_geovictoria_failure_reason(array $response): string
{
    $status = (int)($response['status'] ?? 0);
    $errno = (int)($response['errno'] ?? 0);
    $body = is_array($response['json'] ?? null) ? $response['json'] : [];
    $category = strtolower(trim((string)($body['CategoryException'] ?? $body['categoryException'] ?? '')));
    $description = strtolower(trim((string)($body['Description'] ?? $body['description'] ?? '')));
    $code = trim((string)($body['Code'] ?? $body['code'] ?? ''));
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return 'timeout';
    }
    if ($status === 429) {
        return 'rate_limit';
    }
    if ($status === 401 || $status === 403) {
        return 'authentication';
    }
    if ($status === 400 && ($code === '0007' || str_contains($category, 'nonexistenceofdata') || str_contains($description, 'not users'))) {
        return 'nonexistent_identity';
    }
    if ($status === 400 && ($code === '0008' || $code === '0123' || str_contains($category, 'outoflimit') || str_contains($description, 'greater than'))) {
        return 'out_of_limit';
    }
    if ($status >= 500) {
        return 'provider_unavailable';
    }
    if ($status === 400) {
        return 'bad_request';
    }
    if ($errno !== 0) {
        return 'transport_error';
    }
    return 'unexpected_response';
}

function fte_geovictoria_post(array $config, string $endpoint, array $payload, int $timeoutSeconds = 75): array
{
    if (isset($config['_geovictoria_post_handler']) && is_callable($config['_geovictoria_post_handler'])) {
        $result = $config['_geovictoria_post_handler']($endpoint, $payload, $timeoutSeconds);
        if (!is_array($result)) {
            throw new FteGeoVictoriaException('unexpected_response', 0, 'GeoVictoria entrego una respuesta no valida.');
        }
        return $result;
    }

    $timeoutSeconds = max(1, min(75, $timeoutSeconds));
    $token = fte_geovictoria_token($config, min(30, $timeoutSeconds));
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/' . ltrim($endpoint, '/');
    $response = fte_http_json(
        $url,
        ['Authorization: Bearer ' . $token],
        $payload,
        $timeoutSeconds,
        fte_geovictoria_curl_options(),
        $config['_performance_observer'] ?? null,
        'geo_attendance'
    );
    if (($response['status'] === 401 || $response['status'] === 403)
        && (session_status() === PHP_SESSION_ACTIVE || !empty($config['_fte_session_detached']))) {
        unset($_SESSION['fte_geovictoria_token'], $_SESSION['fte_geovictoria_token_expires']);
        fte_geovictoria_forget_shared_token($config);
        $token = fte_geovictoria_token($config, min(30, $timeoutSeconds));
        $response = fte_http_json(
            $url,
            ['Authorization: Bearer ' . $token],
            $payload,
            $timeoutSeconds,
            fte_geovictoria_curl_options(),
            $config['_performance_observer'] ?? null,
            'geo_attendance_auth_retry'
        );
    }
    if (!$response['ok']) {
        $reason = fte_geovictoria_failure_reason($response);
        throw new FteGeoVictoriaException(
            $reason,
            (int)($response['status'] ?? 0),
            'GeoVictoria HTTP ' . (int)($response['status'] ?? 0) . ' (' . $reason . ').'
        );
    }
    if (!is_array($response['json'])) {
        throw new FteGeoVictoriaException('invalid_response', (int)($response['status'] ?? 0), 'GeoVictoria respondio sin JSON valido.');
    }
    return $response['json'];
}

function fte_parse_timestamp($value): ?DateTimeImmutable
{
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^\/Date\((\d+)/', $text, $m)) {
        return (new DateTimeImmutable('@' . ((int)floor(((int)$m[1]) / 1000))))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
    if (preg_match('/^\d{14}$/', $text)) {
        $dt = DateTimeImmutable::createFromFormat('YmdHis', $text);
        return $dt ?: null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}/', $text)) {
        try {
            return new DateTimeImmutable(str_replace(' ', 'T', $text));
        } catch (Throwable $e) {
            return null;
        }
    }
    return null;
}

function fte_collect_attendance_times($node, array &$dates): void
{
    if (!is_array($node)) {
        $dt = fte_parse_timestamp($node);
        if ($dt) {
            $key = $dt->format('Y-m-d');
            $dates[$key][] = $dt->format(DateTimeInterface::ATOM);
        }
        return;
    }
    foreach ($node as $key => $value) {
        $keyText = strtolower((string)$key);
        if (!is_array($value)) {
            $dt = fte_parse_timestamp($value);
            if ($dt && preg_match('/date|time|entry|exit|in|out|mark|punch|fecha|hora|entrada|salida/', $keyText)) {
                $dateKey = $dt->format('Y-m-d');
                $dates[$dateKey][] = $dt->format(DateTimeInterface::ATOM);
            }
            continue;
        }
        fte_collect_attendance_times($value, $dates);
    }
}

function fte_collect_geovictoria_punches(array $user): array
{
    $dates = [];
    $intervals = $user['PlannedInterval'] ?? [];
    if (!is_array($intervals)) {
        return $dates;
    }

    foreach ($intervals as $interval) {
        if (!is_array($interval)) {
            continue;
        }
        $punches = $interval['Punches'] ?? [];
        if (!is_array($punches)) {
            continue;
        }
        foreach ($punches as $punch) {
            if (!is_array($punch)) {
                continue;
            }
            $dt = fte_parse_timestamp($punch['Date'] ?? null);
            if (!$dt) {
                continue;
            }
            $day = $dt->format('Y-m-d');
            $typeText = strtolower(trim((string)($punch['ShiftPunchType'] ?? $punch['Type'] ?? '')));
            $kind = 'unknown';
            if (strpos($typeText, 'entrada') !== false || strpos($typeText, 'ingreso') !== false || strpos($typeText, 'in') === 0) {
                $kind = 'entry';
            } elseif (strpos($typeText, 'salida') !== false || strpos($typeText, 'out') === 0) {
                $kind = 'exit';
            }
            $dates[$day][] = [
                'kind' => $kind,
                'time' => $dt->format(DateTimeInterface::ATOM),
            ];
        }
    }

    return $dates;
}

function fte_extract_users($payload): array
{
    if (is_array($payload)) {
        if (isset($payload['Users']) && is_array($payload['Users'])) {
            return $payload['Users'];
        }
        if (isset($payload['Data']) && is_array($payload['Data'])) {
            return $payload['Data'];
        }
        if (array_keys($payload) === range(0, count($payload) - 1)) {
            return $payload;
        }
    }
    return [];
}

function fte_identifier_from_geo_user(array $user): string
{
    foreach (['Identifier', 'identifier', 'UserIdentifier', 'Rut', 'RUT', 'Document'] as $key) {
        if (!empty($user[$key])) {
            return fte_normalize_identifier($user[$key]);
        }
    }
    return '';
}

function fte_attendance_batch_size(array $config, int $rangeDays): int
{
    if ($rangeDays >= 28 && !empty($config['geovictoria_monthly_individual_requests'])) {
        return 0;
    }
    $maxRangeDays = max(0, (int)($config['geovictoria_attendance_batch_max_range_days'] ?? 0));
    if ($rangeDays < 1 || $maxRangeDays < 1 || $rangeDays > $maxRangeDays) {
        return 0;
    }
    $configured = max(1, min(195, (int)($config['geovictoria_attendance_batch_size'] ?? 195)));
    $maxRecords = max(1, (int)($config['geovictoria_attendance_max_records_per_request'] ?? 1500));
    return max(1, min($configured, (int)floor($maxRecords / $rangeDays)));
}

function fte_attendance_timeout_seconds(array $config, int $rangeDays): int
{
    if (fte_attendance_batch_size($config, $rangeDays) < 1) {
        return 0;
    }
    if ($rangeDays <= 7) {
        return max(1, (int)($config['geovictoria_short_range_timeout_seconds'] ?? 45));
    }
    return max(1, (int)($config['geovictoria_month_range_timeout_seconds'] ?? 90));
}

function fte_attendance_batch_exception(FteGeoVictoriaException $exception): FteAttendanceBatchException
{
    return new FteAttendanceBatchException(
        $exception->reason(),
        $exception->providerStatus(),
        $exception->getMessage()
    );
}

function fte_attendance_batch_post(
    array $config,
    array $payload,
    array &$state
): array {
    $deadlineDisabled = !empty($state['deadline_disabled']);
    $remaining = $deadlineDisabled ? null : (float)$state['deadline_at'] - microtime(true);
    if (!$deadlineDisabled && $remaining <= 0) {
        throw new FteAttendanceBatchException('deadline', 0, 'La consulta supero el tiempo total permitido.');
    }
    if ((int)$state['request_count'] > 0) {
        $pause = max(0.0, (float)($config['geovictoria_attendance_min_interval_seconds'] ?? 0.35));
        if ($pause > 0) {
            if (!$deadlineDisabled && $pause >= $remaining) {
                throw new FteAttendanceBatchException('deadline', 0, 'La consulta supero el tiempo total permitido.');
            }
            usleep((int)round($pause * 1_000_000));
        }
    }
    $remaining = $deadlineDisabled ? null : (float)$state['deadline_at'] - microtime(true);
    if (!$deadlineDisabled && $remaining <= 0) {
        throw new FteAttendanceBatchException('deadline', 0, 'La consulta supero el tiempo total permitido.');
    }
    $state['request_count']++;
    try {
        return fte_geovictoria_post(
            $config,
            'AttendanceBook',
            $payload,
            $deadlineDisabled ? 75 : max(1, min(75, (int)ceil($remaining)))
        );
    } catch (FteGeoVictoriaException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        throw new FteGeoVictoriaException('provider_unavailable', 0, $exception->getMessage());
    }
}

function fte_attendance_response_identifiers(array $raw): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn($user): string => is_array($user) ? fte_identifier_from_geo_user($user) : '',
        fte_extract_users($raw)
    ))));
}

function fte_attendance_known_unmatched(array $config, string $identifier): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE && empty($config['_fte_session_detached'])) {
        return false;
    }
    $ttl = max(0, (int)($config['geovictoria_unmatched_cache_ttl_seconds'] ?? 300));
    if ($ttl === 0) {
        return false;
    }
    $key = hash('sha256', fte_geo_unmatched_cache_key($config, $identifier));
    $expiresAt = (int)($_SESSION['fte_geovictoria_unmatched_cache'][$key] ?? 0);
    if ($expiresAt <= time()) {
        unset($_SESSION['fte_geovictoria_unmatched_cache'][$key]);
        return fte_runtime_cache_read($config, fte_geo_unmatched_cache_key($config, $identifier), $ttl) !== null;
    }
    return true;
}

function fte_attendance_remember_unmatched(array $config, string $identifier): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && empty($config['_fte_session_detached'])) {
        return;
    }
    $ttl = max(0, (int)($config['geovictoria_unmatched_cache_ttl_seconds'] ?? 300));
    if ($ttl === 0) {
        return;
    }
    $_SESSION['fte_geovictoria_unmatched_cache'][hash('sha256', fte_geo_unmatched_cache_key($config, $identifier))] = time() + $ttl;
    fte_runtime_cache_write($config, fte_geo_unmatched_cache_key($config, $identifier), ['confirmed_nonexistent' => true], $ttl);
}

function fte_attendance_fallback_person(
    array $config,
    array $person,
    string $start,
    string $end,
    array &$attendance,
    array &$diagnostics,
    array &$state
): void {
    $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
    if ($identifier === '') {
        return;
    }
    $candidates = array_values(array_unique(array_filter([
        $identifier,
        trim((string)($person['identifier'] ?? '')),
    ])));
    foreach ($candidates as $candidate) {
        $diagnostics['fallback_requests']++;
        try {
            $raw = fte_attendance_batch_post($config, [
                'StartDate' => $start,
                'EndDate' => $end,
                'UserIds' => $candidate,
            ], $state);
        } catch (FteGeoVictoriaException $exception) {
            if ($exception->reason() === 'nonexistent_identity') {
                fte_attendance_remember_unmatched($config, $identifier);
                break;
            }
            if ($exception->reason() === 'bad_request') {
                continue;
            }
            throw fte_attendance_batch_exception($exception);
        }
        $returned = fte_attendance_response_identifiers($raw);
        $unexpected = array_values(array_diff($returned, [$identifier]));
        if ($unexpected) {
            throw new FteAttendanceBatchException('identity_integrity', 0, 'GeoVictoria devolvio una identidad no solicitada.');
        }
        if (in_array($identifier, $returned, true)) {
            fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
            $diagnostics['successful_identifiers'][] = $identifier;
            return;
        }
    }
    $diagnostics['successful_identifiers'][] = $identifier;
    $diagnostics['unmatched_identifiers'][] = $identifier;
}

function fte_attendance_fetch_chunk(
    array $config,
    array $peopleByIdentifier,
    array $identifiers,
    string $start,
    string $end,
    array &$attendance,
    array &$diagnostics,
    array &$state
): void {
    if (!$identifiers) {
        return;
    }
    $diagnostics['batch_requests']++;
    try {
        $raw = fte_attendance_batch_post($config, [
            'StartDate' => $start,
            'EndDate' => $end,
            'UserIds' => implode(',', $identifiers),
        ], $state);
    } catch (FteGeoVictoriaException $exception) {
        if (count($identifiers) > 1 && in_array($exception->reason(), ['out_of_limit', 'nonexistent_identity', 'bad_request'], true)) {
            $middle = (int)ceil(count($identifiers) / 2);
            fte_attendance_fetch_chunk($config, $peopleByIdentifier, array_slice($identifiers, 0, $middle), $start, $end, $attendance, $diagnostics, $state);
            fte_attendance_fetch_chunk($config, $peopleByIdentifier, array_slice($identifiers, $middle), $start, $end, $attendance, $diagnostics, $state);
            return;
        }
        if (count($identifiers) === 1 && $exception->reason() === 'nonexistent_identity') {
            $identifier = $identifiers[0];
            fte_attendance_remember_unmatched($config, $identifier);
            $diagnostics['successful_identifiers'][] = $identifier;
            $diagnostics['unmatched_identifiers'][] = $identifier;
            return;
        }
        if (count($identifiers) === 1 && $exception->reason() === 'bad_request') {
            $diagnostics['failed_identifiers'][] = $identifiers[0];
            return;
        }
        throw fte_attendance_batch_exception($exception);
    }

    $returned = fte_attendance_response_identifiers($raw);
    $unexpected = array_values(array_diff($returned, $identifiers));
    if ($unexpected) {
        throw new FteAttendanceBatchException('identity_integrity', 0, 'GeoVictoria devolvio identidades no solicitadas.');
    }
    fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
    foreach ($returned as $identifier) {
        $diagnostics['successful_identifiers'][] = $identifier;
    }
    $missing = array_values(array_diff($identifiers, $returned));
    foreach ($missing as $identifier) {
        if (fte_attendance_known_unmatched($config, $identifier)) {
            $diagnostics['successful_identifiers'][] = $identifier;
            $diagnostics['unmatched_identifiers'][] = $identifier;
            $diagnostics['cached_unmatched_count']++;
            continue;
        }
        if (isset($peopleByIdentifier[$identifier])) {
            fte_attendance_fallback_person(
                $config,
                $peopleByIdentifier[$identifier],
                $start,
                $end,
                $attendance,
                $diagnostics,
                $state
            );
        }
    }
}

function fte_attendance_individual_post(array $config, array $payload, array &$diagnostics): array
{
    $attempts = max(1, min(2, (int)($config['geovictoria_individual_attempts'] ?? 2)));
    $timeout = max(1, min(75, (int)($config['geovictoria_individual_timeout_seconds'] ?? 20)));
    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $diagnostics['request_count']++;
        try {
            return fte_geovictoria_post($config, 'AttendanceBook', $payload, $timeout);
        } catch (FteGeoVictoriaException $exception) {
            $transient = in_array(
                $exception->reason(),
                ['timeout', 'rate_limit', 'provider_unavailable', 'transport_error', 'unexpected_response'],
                true
            );
            if (!$transient || $attempt === $attempts) {
                throw $exception;
            }
            usleep((int)(250_000 * $attempt));
        }
    }
    throw new FteGeoVictoriaException('provider_unavailable', 0, 'GeoVictoria no completo la consulta individual.');
}

function fte_geovictoria_multi_post(
    array $config,
    string $endpoint,
    array $payloads,
    int $timeoutSeconds
): array {
    if (isset($config['_geovictoria_multi_handler']) && is_callable($config['_geovictoria_multi_handler'])) {
        $responses = $config['_geovictoria_multi_handler']($endpoint, $payloads, $timeoutSeconds);
        if (!is_array($responses) || count($responses) !== count($payloads)) {
            throw new FteGeoVictoriaException('unexpected_response', 0, 'GeoVictoria entrego un lote concurrente no valido.');
        }
        return array_values($responses);
    }

    if (!function_exists('curl_multi_init')) {
        throw new FteGeoVictoriaException('transport_error', 0, 'cURL multi no esta disponible.');
    }
    $token = fte_geovictoria_token($config, min(30, $timeoutSeconds));
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/' . ltrim($endpoint, '/');
    $multi = curl_multi_init();
    $handles = [];
    foreach (array_values($payloads) as $index => $payload) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => max(1, min(75, $timeoutSeconds)),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    $waveStarted = hrtime(true);
    do {
        $multiStatus = curl_multi_exec($multi, $running);
        if ($running > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) {
                usleep(10_000);
            }
        }
    } while ($running > 0 && $multiStatus === CURLM_OK);

    $responses = [];
    foreach ($handles as $index => $handle) {
        $raw = curl_multi_getcontent($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)(curl_getinfo($handle, CURLINFO_HTTP_CODE) ?: 0);
        $info = curl_getinfo($handle);
        fte_performance_emit($config['_performance_observer'] ?? null, [
            'type' => 'http', 'source' => $config['_performance_geo_round'] ?? 'geo_initial',
            'status' => $status, 'errno' => $errno,
            'seconds' => (float)($info['total_time'] ?? 0),
            'ttfb_seconds' => (float)($info['starttransfer_time'] ?? 0),
            'bytes' => is_string($raw) ? strlen($raw) : 0,
        ]);
        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $candidate = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $candidate;
            }
        }
        $responses[$index] = [
            'ok' => $errno === 0 && $status >= 200 && $status < 300,
            'status' => $status,
            'errno' => $errno,
            'error' => $error,
            'raw' => $raw,
            'json' => $decoded,
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    fte_performance_emit($config['_performance_observer'] ?? null, [
        'type' => 'wave', 'source' => $config['_performance_geo_round'] ?? 'geo_initial',
        'seconds' => (hrtime(true) - $waveStarted) / 1e9, 'requests' => count($payloads),
    ]);
    ksort($responses);
    return array_values($responses);
}

function fte_fetch_attendance_concurrent_individual(
    array $config,
    array $people,
    string $start,
    string $end,
    array &$attendance,
    array &$diagnostics
): void {
    $concurrency = max(2, min(8, (int)($config['geovictoria_monthly_concurrency'] ?? 4)));
    $timeout = max(1, min(75, (int)($config['geovictoria_concurrent_timeout_seconds'] ?? 12)));
    $retryTimeout = max(1, min(75, (int)($config['geovictoria_concurrent_retry_timeout_seconds'] ?? 10)));
    $finalRetryTimeout = max(1, min(75, (int)($config['geovictoria_final_retry_timeout_seconds'] ?? 15)));
    $finalRetryConcurrency = max(1, min(4, (int)($config['geovictoria_final_retry_concurrency'] ?? 2)));
    $entries = [];
    foreach (array_values($people) as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '') {
            continue;
        }
        if (fte_attendance_known_unmatched($config, $identifier)) {
            $diagnostics['successful_identifiers'][] = $identifier;
            $diagnostics['unmatched_identifiers'][] = $identifier;
            $diagnostics['cached_unmatched_count']++;
            continue;
        }
        $entries[$identifier] = [
            'identifier' => $identifier,
            'raw_identifier' => trim((string)($person['identifier'] ?? '')),
            'payload' => [
                'StartDate' => $start,
                'EndDate' => $end,
                'UserIds' => $identifier,
            ],
        ];
    }

    $acceptRaw = static function (array $entry, array $raw) use (&$attendance, &$diagnostics, $config): void {
        $identifier = $entry['identifier'];
        $returned = fte_attendance_response_identifiers($raw);
        $unexpected = array_values(array_diff($returned, [$identifier]));
        if ($unexpected) {
            throw new FteAttendanceBatchException('identity_integrity', 0, 'GeoVictoria devolvio una identidad no solicitada.');
        }
        fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
        $diagnostics['successful_identifiers'][] = $identifier;
        if (!in_array($identifier, $returned, true)) {
            $diagnostics['unmatched_identifiers'][] = $identifier;
        }
    };
    $retryEntries = [];
    $refreshTokenBeforeRetry = false;
    $waves = array_chunk(array_values($entries), $concurrency);
    foreach ($waves as $waveIndex => $wave) {
        if ($waveIndex > 0) {
            $pause = max(0.0, (float)($config['geovictoria_attendance_min_interval_seconds'] ?? 0.35));
            if ($pause > 0) {
                usleep((int)round($pause * 1_000_000));
            }
        }
        $diagnostics['batch_requests']++;
        $diagnostics['request_count'] += count($wave);
        $responses = fte_geovictoria_multi_post(
            $config,
            'AttendanceBook',
            array_column($wave, 'payload'),
            $timeout
        );

        foreach ($wave as $index => $entry) {
            $identifier = $entry['identifier'];
            $response = $responses[$index] ?? ['ok' => false, 'status' => 0, 'errno' => 0, 'json' => null];
            $raw = null;
            if (!empty($response['ok']) && is_array($response['json'] ?? null)) {
                $raw = $response['json'];
            } else {
                $reason = fte_geovictoria_failure_reason($response);
                if (in_array($reason, ['authentication', 'timeout', 'rate_limit', 'provider_unavailable', 'transport_error', 'unexpected_response'], true)) {
                    $retryEntries[$identifier] = $entry;
                    $refreshTokenBeforeRetry = $refreshTokenBeforeRetry || $reason === 'authentication';
                    continue;
                } elseif (in_array($reason, ['bad_request', 'nonexistent_identity'], true)) {
                    $rawIdentifier = $entry['raw_identifier'];
                    if ($rawIdentifier !== '' && $rawIdentifier !== $identifier) {
                        $entry['payload']['UserIds'] = $rawIdentifier;
                        $retryEntries[$identifier] = $entry;
                    } else {
                        fte_attendance_remember_unmatched($config, $identifier);
                        $diagnostics['successful_identifiers'][] = $identifier;
                        $diagnostics['unmatched_identifiers'][] = $identifier;
                    }
                    continue;
                } else {
                    $diagnostics['failed_identifiers'][] = $identifier;
                    continue;
                }
            }
            $acceptRaw($entry, $raw);
        }
    }

    if ($retryEntries) {
        if ($refreshTokenBeforeRetry && (session_status() === PHP_SESSION_ACTIVE || !empty($config['_fte_session_detached']))) {
            unset($_SESSION['fte_geovictoria_token'], $_SESSION['fte_geovictoria_token_expires']);
            fte_geovictoria_forget_shared_token($config);
        }
        usleep(500_000);
        $finalRetryEntries = [];
        foreach (array_chunk(array_values($retryEntries), $concurrency) as $retryWave) {
            $diagnostics['batch_requests']++;
            $diagnostics['fallback_requests'] += count($retryWave);
            $diagnostics['request_count'] += count($retryWave);
            $responses = fte_geovictoria_multi_post(
                array_replace($config, ['_performance_geo_round' => 'geo_retry']),
                'AttendanceBook',
                array_column($retryWave, 'payload'),
                $retryTimeout
            );
            foreach ($retryWave as $index => $entry) {
                $identifier = $entry['identifier'];
                $response = $responses[$index] ?? ['ok' => false, 'status' => 0, 'errno' => 0, 'json' => null];
                if (!empty($response['ok']) && is_array($response['json'] ?? null)) {
                    $acceptRaw($entry, $response['json']);
                    continue;
                }
                $reason = fte_geovictoria_failure_reason($response);
                if (in_array($reason, ['bad_request', 'nonexistent_identity'], true)) {
                    fte_attendance_remember_unmatched($config, $identifier);
                    $diagnostics['successful_identifiers'][] = $identifier;
                    $diagnostics['unmatched_identifiers'][] = $identifier;
                } elseif (in_array($reason, ['authentication', 'timeout', 'rate_limit', 'provider_unavailable', 'transport_error', 'unexpected_response'], true)) {
                    $finalRetryEntries[$identifier] = $entry;
                } else {
                    $diagnostics['failed_identifiers'][] = $identifier;
                }
            }
        }

        if ($finalRetryEntries) {
            usleep(750_000);
            foreach (array_chunk(array_values($finalRetryEntries), $finalRetryConcurrency) as $finalWave) {
                $diagnostics['batch_requests']++;
                $diagnostics['fallback_requests'] += count($finalWave);
                $diagnostics['request_count'] += count($finalWave);
                $responses = fte_geovictoria_multi_post(
                    array_replace($config, ['_performance_geo_round' => 'geo_final_retry']),
                    'AttendanceBook',
                    array_column($finalWave, 'payload'),
                    $finalRetryTimeout
                );
                foreach ($finalWave as $index => $entry) {
                    $identifier = $entry['identifier'];
                    $response = $responses[$index] ?? ['ok' => false, 'status' => 0, 'errno' => 0, 'json' => null];
                    if (!empty($response['ok']) && is_array($response['json'] ?? null)) {
                        $acceptRaw($entry, $response['json']);
                        continue;
                    }
                    $reason = fte_geovictoria_failure_reason($response);
                    if (in_array($reason, ['bad_request', 'nonexistent_identity'], true)) {
                        fte_attendance_remember_unmatched($config, $identifier);
                        $diagnostics['successful_identifiers'][] = $identifier;
                        $diagnostics['unmatched_identifiers'][] = $identifier;
                    } else {
                        $diagnostics['failed_identifiers'][] = $identifier;
                    }
                }
            }
        }
    }

    if (count($diagnostics['successful_identifiers']) === 0 && count($diagnostics['failed_identifiers']) > 0) {
        throw new FteAttendanceBatchException('provider_unavailable', 0, 'GeoVictoria no respondio al inicio de la consulta mensual.');
    }
}

function fte_fetch_attendance(array $config, array $people, DateTimeImmutable $from, DateTimeImmutable $to, ?array &$diagnostics = null): array
{
    $attendance = [];
    $rangeDays = (int)$from->diff($to)->days + 1;
    $batchSize = fte_attendance_batch_size($config, $rangeDays);
    $diagnostics = [
        'successful_identifiers' => [],
        'failed_identifiers' => [],
        'unmatched_identifiers' => [],
        'strategy' => $batchSize > 0 ? 'batch' : 'individual',
        'range_days' => $rangeDays,
        'batch_size' => $batchSize,
        'request_count' => 0,
        'batch_requests' => 0,
        'fallback_requests' => 0,
        'cached_unmatched_count' => 0,
        'total_deadline_disabled' => !empty($config['_attendance_total_deadline_disabled']),
    ];
    $start = $from->format('Ymd') . '000000';
    $end = $to->format('Ymd') . '235959';

    if ($batchSize > 0 && $rangeDays >= 28 && !empty($config['geovictoria_resilient_monthly_batches'])) {
        return fte_attendance_resilient_monthly($config, $people, $start, $end, $batchSize, $diagnostics);
    }

    if ($batchSize > 0) {
        $peopleByIdentifier = [];
        $identifiers = [];
        foreach (array_values($people) as $person) {
            if (!is_array($person)) {
                continue;
            }
            $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
            if ($identifier !== '') {
                $peopleByIdentifier[$identifier] = $person;
                $identifiers[] = $identifier;
            }
        }
        $identifiers = array_values(array_unique($identifiers));
        $timeoutSeconds = fte_attendance_timeout_seconds($config, $rangeDays);
        $configuredDeadline = (float)($config['_request_deadline_at'] ?? 0);
        $deadlineDisabled = !empty($config['_attendance_total_deadline_disabled']);
        $state = [
            'request_count' => 0,
            'deadline_at' => $deadlineDisabled ? null : ($configuredDeadline > 0 ? $configuredDeadline : microtime(true) + $timeoutSeconds),
            'deadline_disabled' => $deadlineDisabled,
        ];
        foreach (array_chunk($identifiers, $batchSize) as $chunk) {
            fte_attendance_fetch_chunk(
                $config,
                $peopleByIdentifier,
                $chunk,
                $start,
                $end,
                $attendance,
                $diagnostics,
                $state
            );
        }
        $diagnostics['request_count'] = (int)$state['request_count'];
        foreach (['successful_identifiers', 'failed_identifiers', 'unmatched_identifiers'] as $key) {
            $diagnostics[$key] = array_values(array_unique($diagnostics[$key]));
        }
        return $attendance;
    }

    if ($rangeDays >= 28
        && !empty($config['geovictoria_monthly_individual_requests'])
        && (int)($config['geovictoria_monthly_concurrency'] ?? 1) > 1) {
        $diagnostics['strategy'] = 'concurrent_individual';
        fte_fetch_attendance_concurrent_individual(
            $config,
            $people,
            $start,
            $end,
            $attendance,
            $diagnostics
        );
        foreach (['successful_identifiers', 'failed_identifiers', 'unmatched_identifiers'] as $key) {
            $diagnostics[$key] = array_values(array_unique($diagnostics[$key]));
        }
        return $attendance;
    }

    $consecutiveProviderFailures = 0;
    $providerFailureLimit = max(1, (int)($config['geovictoria_consecutive_failure_limit'] ?? 3));
    foreach (array_values($people) as $personIndex => $person) {
        $identifier = trim((string)($person['normalized_identifier'] ?? ''));
        if ($identifier === '') {
            continue;
        }
        if ($personIndex > 0) {
            usleep((int)(max(0, (float)$config['geovictoria_attendance_min_interval_seconds']) * 1000000));
        }
        $candidates = array_values(array_unique(array_filter([
            $identifier,
            trim((string)($person['identifier'] ?? '')),
        ])));
        $success = false;
        foreach ($candidates as $candidate) {
            try {
                $raw = fte_attendance_individual_post(
                    $config,
                    [
                        'StartDate' => $start,
                        'EndDate' => $end,
                        'UserIds' => $candidate,
                    ],
                    $diagnostics
                );
                fte_merge_attendance_payload($attendance, $raw, !empty($config['_include_monthly_time_offs']));
                $returnedIdentifiers = fte_attendance_response_identifiers($raw);
                if (!in_array($identifier, $returnedIdentifiers, true)) {
                    $diagnostics['unmatched_identifiers'][] = $identifier;
                }
                $success = true;
                $consecutiveProviderFailures = 0;
                break;
            } catch (FteGeoVictoriaException $inner) {
                if (in_array($inner->reason(), ['bad_request', 'nonexistent_identity'], true)) {
                    continue;
                }
                $consecutiveProviderFailures++;
                if ($consecutiveProviderFailures >= $providerFailureLimit) {
                    throw fte_attendance_batch_exception($inner);
                }
                break;
            } catch (Throwable $inner) {
                $consecutiveProviderFailures++;
                if ($consecutiveProviderFailures >= $providerFailureLimit) {
                    throw new FteAttendanceBatchException('provider_unavailable', 0, $inner->getMessage());
                }
                break;
            }
        }
        $bucket = $success ? 'successful_identifiers' : 'failed_identifiers';
        $diagnostics[$bucket][] = $identifier;
    }
    foreach (['successful_identifiers', 'failed_identifiers', 'unmatched_identifiers'] as $key) {
        $diagnostics[$key] = array_values(array_unique($diagnostics[$key]));
    }
    return $attendance;
}

function fte_duration_hours($value): float
{
    $text = trim((string)$value);
    if (!preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $text, $match)) {
        return 0.0;
    }
    return (int)$match[1] + ((int)$match[2] / 60) + ((int)($match[3] ?? 0) / 3600);
}

function fte_duration_values_hours($value): float
{
    if (!is_array($value)) {
        return fte_duration_hours($value);
    }
    $hours = 0.0;
    foreach ($value as $item) {
        $hours += fte_duration_values_hours($item);
    }
    return $hours;
}

function fte_geovictoria_time_off_record(array $timeOff): array
{
    return [
        'external_id' => trim((string)($timeOff['Id'] ?? $timeOff['id'] ?? '')),
        'type_id' => trim((string)($timeOff['TimeOffTypeId'] ?? $timeOff['type_id'] ?? '')),
        'type_description' => trim((string)($timeOff['TimeOffTypeDescription'] ?? $timeOff['type_description'] ?? '')),
        'origin' => trim((string)($timeOff['TimeOffOrigin'] ?? $timeOff['origin'] ?? '')),
        'starts' => trim((string)($timeOff['Starts'] ?? $timeOff['starts'] ?? '')),
        'ends' => trim((string)($timeOff['Ends'] ?? $timeOff['ends'] ?? '')),
        'start_time' => trim((string)($timeOff['StartTime'] ?? $timeOff['start_time'] ?? '')),
        'end_time' => trim((string)($timeOff['EndTime'] ?? $timeOff['end_time'] ?? '')),
        'amount_hours' => trim((string)($timeOff['AmountHours'] ?? $timeOff['amount_hours'] ?? '')),
    ];
}

function fte_merge_attendance_payload(array &$attendance, array $raw, bool $includeMonthlyTimeOffs = false): void
{
    foreach (fte_extract_users($raw) as $rawUser) {
        if (!is_array($rawUser)) {
            continue;
        }
        $identifier = fte_identifier_from_geo_user($rawUser);
        if ($identifier === '') {
            continue;
        }
        $dates = fte_collect_geovictoria_punches($rawUser);
        foreach ($dates as $date => $punches) {
            usort($punches, static fn($a, $b) => strcmp((string)$a['time'], (string)$b['time']));
            $entryPunches = array_values(array_filter($punches, static fn($p) => ($p['kind'] ?? '') === 'entry'));
            $exitPunches = array_values(array_filter($punches, static fn($p) => ($p['kind'] ?? '') === 'exit'));
            $fallbackTimes = array_map(static fn($p) => (string)$p['time'], $punches);
            $attendance[$identifier][$date] = [
                'present' => count($punches) > 0,
                'first_entry' => $entryPunches[0]['time'] ?? ($fallbackTimes[0] ?? null),
                'last_exit' => $exitPunches[count($exitPunches) - 1]['time'] ?? (count($fallbackTimes) > 1 ? $fallbackTimes[count($fallbackTimes) - 1] : null),
                'punch_count' => count($punches),
                'complete' => count($entryPunches) > 0 && count($exitPunches) > 0,
            ];
        }
        foreach (($rawUser['PlannedInterval'] ?? []) as $interval) {
            if (!is_array($interval)) {
                continue;
            }
            $intervalDate = fte_parse_timestamp($interval['Date'] ?? null);
            if (!$intervalDate) {
                continue;
            }
            $date = $intervalDate->format('Y-m-d');
            if (!isset($attendance[$identifier][$date])) {
                $attendance[$identifier][$date] = [
                    'present' => false,
                    'first_entry' => null,
                    'last_exit' => null,
                    'punch_count' => 0,
                    'complete' => false,
                ];
            }
            $attendance[$identifier][$date]['scheduled_interval'] = true;
            $attendance[$identifier][$date]['geovictoria_worked_hours'] = fte_duration_hours($interval['WorkedHours'] ?? '');
            $attendance[$identifier][$date]['authorized_overtime_hours'] = fte_duration_hours($interval['TotalAuthorizedOvertime'] ?? '');
            $hasAccomplishedOvertime = array_key_exists('AccomplishedExtraTime', $interval)
                || array_key_exists('AccomplishedExtraTimeBefore', $interval)
                || array_key_exists('AccomplishedExtraTimeAfter', $interval);
            $accomplishedOvertime = fte_duration_values_hours($interval['AccomplishedExtraTime'] ?? []);
            if ($accomplishedOvertime <= 0) {
                $accomplishedOvertime = fte_duration_values_hours($interval['AccomplishedExtraTimeBefore'] ?? [])
                    + fte_duration_values_hours($interval['AccomplishedExtraTimeAfter'] ?? []);
            }
            $attendance[$identifier][$date]['accomplished_overtime_hours'] = max(0.0, $accomplishedOvertime);
            $attendance[$identifier][$date]['accomplished_overtime_available'] = $hasAccomplishedOvertime;
            $rawDelayHours = fte_duration_hours($interval['Delay'] ?? '');
            $hasDelayAfterCompensation = array_key_exists('DelayTimeAfterCompensation', $interval);
            $delayAfterCompensationHours = $hasDelayAfterCompensation
                ? fte_duration_hours($interval['DelayTimeAfterCompensation'])
                : $rawDelayHours;
            // Algunas respuestas antiguas solo incluyen el valor posterior a
            // compensacion. En ese caso nunca puede ser mayor que el bruto:
            // lo usamos tambien como minimo bruto para no perder el evento.
            $rawDelayHours = max($rawDelayHours, $delayAfterCompensationHours);
            $rawEarlyLeaveHours = fte_duration_hours($interval['EarlyLeave'] ?? '');
            $hasEarlyLeaveAfterCompensation = array_key_exists('EarlyLeaveTimeAfterCompensation', $interval);
            $earlyLeaveAfterCompensationHours = $hasEarlyLeaveAfterCompensation
                ? fte_duration_hours($interval['EarlyLeaveTimeAfterCompensation'])
                : $rawEarlyLeaveHours;
            $rawEarlyLeaveHours = max($rawEarlyLeaveHours, $earlyLeaveAfterCompensationHours);
            $attendance[$identifier][$date]['delay_raw_hours'] = max(0.0, $rawDelayHours);
            $attendance[$identifier][$date]['delay_after_compensation_hours'] = max(0.0, $delayAfterCompensationHours);
            $attendance[$identifier][$date]['delay_after_compensation_available'] = $hasDelayAfterCompensation;
            $attendance[$identifier][$date]['delay_compensated_hours'] = max(0.0, $rawDelayHours - $delayAfterCompensationHours);
            $attendance[$identifier][$date]['delay_hours'] = max(0.0, $delayAfterCompensationHours);
            $attendance[$identifier][$date]['early_leave_raw_hours'] = max(0.0, $rawEarlyLeaveHours);
            $attendance[$identifier][$date]['early_leave_after_compensation_hours'] = max(0.0, $earlyLeaveAfterCompensationHours);
            $attendance[$identifier][$date]['early_leave_after_compensation_available'] = $hasEarlyLeaveAfterCompensation;
            $attendance[$identifier][$date]['early_leave_compensated_hours'] = max(0.0, $rawEarlyLeaveHours - $earlyLeaveAfterCompensationHours);
            $attendance[$identifier][$date]['early_leave_hours'] = max(0.0, $earlyLeaveAfterCompensationHours);
            $attendance[$identifier][$date]['non_worked_hours'] = fte_duration_hours($interval['NonWorkedHours'] ?? '');
            if ($includeMonthlyTimeOffs) {
                foreach (($interval['TimeOffs'] ?? []) as $timeOff) {
                    if (!is_array($timeOff)) {
                        continue;
                    }
                    $normalizedTimeOff = fte_geovictoria_time_off_record($timeOff);
                    $timeOffKey = $normalizedTimeOff['external_id'] !== ''
                        ? $normalizedTimeOff['external_id']
                        : hash('sha256', json_encode($normalizedTimeOff));
                    $attendance[$identifier][$date]['time_offs'][$timeOffKey] = $normalizedTimeOff;
                }
            }
        }
    }
}

function fte_clock(?string $value): string
{
    if (!$value) {
        return '-';
    }
    try {
        return (new DateTimeImmutable($value))->format('H:i');
    } catch (Throwable $e) {
        return '-';
    }
}

function fte_net_hours(?string $entry, ?string $exit, string $startTime, int $lunchMinutes, int $otherMinutes): float
{
    if (!$entry || !$exit) {
        return 0.0;
    }
    try {
        $entryDt = new DateTimeImmutable($entry);
        $exitDt = new DateTimeImmutable($exit);
    } catch (Throwable $e) {
        return 0.0;
    }
    if ($exitDt <= $entryDt) {
        return 0.0;
    }
    [$h, $m] = array_map('intval', explode(':', $startTime . ':0'));
    $floor = $entryDt->setTime($h, $m, 0);
    $effectiveEntry = $entryDt > $floor ? $entryDt : $floor;
    $grossMinutes = max(0, ($exitDt->getTimestamp() - $effectiveEntry->getTimestamp()) / 60);
    return max(0, $grossMinutes - $lunchMinutes - $otherMinutes) / 60;
}

function fte_build_payload(array $config, array $params): array
{
    [$defaultFrom, $defaultTo] = fte_default_range();
    $fromDate = trim((string)($params['from_date'] ?? $defaultFrom));
    $toDate = trim((string)($params['to_date'] ?? $defaultTo));
    [$from, $to] = fte_validate_range($fromDate, $toDate, (int)$config['max_range_days']);

    $requestedCodes = $params['cost_center_code'] ?? [];
    if (!is_array($requestedCodes)) {
        $requestedCodes = [$requestedCodes];
    }
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));

    $dailyHours = max(0.1, (float)($params['daily_hours'] ?? 8));
    $startTime = preg_match('/^\d{2}:\d{2}$/', (string)($params['start_time'] ?? '08:00')) ? (string)$params['start_time'] : '08:00';
    $lunchMinutes = max(0, (int)($params['lunch_minutes'] ?? 60));
    $otherMinutes = max(0, (int)($params['other_minutes'] ?? 0));

    $people = fte_fetch_buk_people($config);
    $costCenters = fte_build_buk_cost_centers($people);

    if ($requestedCodes) {
        $people = array_values(array_filter($people, static fn($p) => in_array($p['cost_center_code'], $requestedCodes, true)));
    } else {
        $people = [];
    }

    $warnings = [];
    $attendance = [];
    $attendanceDiagnostics = ['successful_identifiers' => [], 'failed_identifiers' => [], 'unmatched_identifiers' => []];
    if ($people) {
        try {
            $attendance = fte_fetch_attendance($config, $people, $from, $to, $attendanceDiagnostics);
            $failedCount = count($attendanceDiagnostics['failed_identifiers']);
            $unmatchedCount = count($attendanceDiagnostics['unmatched_identifiers']);
            if ($failedCount > 0) {
                $warnings[] = "GeoVictoria no respondio para {$failedCount} persona(s); sus dias requieren revision.";
            }
            if ($unmatchedCount > 0) {
                $warnings[] = "GeoVictoria no concilio {$unmatchedCount} persona(s) solicitada(s) desde Buk.";
            }
        } catch (FteAttendanceBatchException $e) {
            throw $e;
        } catch (Throwable $e) {
            $warnings[] = 'GeoVictoria no estuvo disponible para esta consulta.';
            error_log('FTE GeoVictoria attendance error: ' . fte_redact_error_message($e->getMessage(), $config));
        }
    }

    $dates = fte_iter_dates($from, $to);
    $days = [];
    $summaries = [];
    $personRows = [];
    foreach ($people as $person) {
        $pid = $person['normalized_identifier'];
        foreach ($dates as $date) {
            $providerFailed = !in_array($pid, $attendanceDiagnostics['successful_identifiers'], true);
            $identityMatched = !in_array($pid, $attendanceDiagnostics['unmatched_identifiers'], true);
            $mark = $attendance[$pid][$date] ?? ['present' => false, 'first_entry' => null, 'last_exit' => null, 'complete' => false];
            $status = fte_attendance_resolve_status([
                'provider_ok' => !$providerFailed,
                'contract_active' => true,
                'identity_matched' => $identityMatched,
                'planned_work' => !empty($mark['scheduled_interval']) ? true : null,
                'punch_count' => (int)($mark['punch_count'] ?? 0),
                'has_entry' => !empty($mark['complete']) && !empty($mark['first_entry']),
                'has_exit' => !empty($mark['complete']) && !empty($mark['last_exit']),
            ]);
            $net = fte_net_hours($mark['first_entry'], $mark['last_exit'], $startTime, $lunchMinutes, $otherMinutes);
            $extra = max(0, $net - $dailyHours);
            $code = $person['cost_center_code'];
            $days[$code][$date]['total'] = ($days[$code][$date]['total'] ?? 0) + 1;
            $days[$code][$date]['present'] = ($days[$code][$date]['present'] ?? 0) + ($mark['present'] ? 1 : 0);
            if (!isset($summaries[$code])) {
                $summaries[$code] = [
                    'cost_center_code' => $code,
                    'cost_center_name' => $person['cost_center_name'],
                    'people_count' => 0,
                    'present_days' => 0,
                    'net_hours' => 0,
                    'extra_hours' => 0,
                    'fte_days' => 0,
                ];
            }
            $summaries[$code]['people_count']++;
            $summaries[$code]['present_days'] += $mark['present'] ? 1 : 0;
            $summaries[$code]['net_hours'] += $net;
            $summaries[$code]['extra_hours'] += $extra;
            $summaries[$code]['fte_days'] += $net / $dailyHours;
            $personRows[] = [
                'date' => $date,
                'identifier' => $person['identifier'],
                'normalized_identifier' => $pid,
                'person_name' => $person['person_name'],
                'cost_center_code' => $code,
                'cost_center_name' => $person['cost_center_name'],
                'present' => (bool)$mark['present'],
                'attendance_status' => $status['code'],
                'attendance_status_label' => $status['label'],
                'attendance_requires_review' => $status['requires_review'],
                'is_unjustified_absence' => $status['is_unjustified_absence'],
                'first_entry' => $mark['first_entry'],
                'last_exit' => $mark['last_exit'],
                'first_entry_clock' => fte_clock($mark['first_entry']),
                'last_exit_clock' => fte_clock($mark['last_exit']),
                'net_hours' => $net,
                'extra_hours' => $extra,
                'fte_day' => $net / $dailyHours,
                'geovictoria_worked_hours' => $mark['geovictoria_worked_hours'] ?? null,
                'authorized_overtime_hours' => $mark['authorized_overtime_hours'] ?? null,
                'delay_hours' => $mark['delay_hours'] ?? null,
                'early_leave_hours' => $mark['early_leave_hours'] ?? null,
                'non_worked_hours' => $mark['non_worked_hours'] ?? null,
            ];
        }
    }

    foreach ($summaries as &$summary) {
        $summary['people_count'] = count(array_unique(array_map(
            static fn($p) => $p['normalized_identifier'],
            array_filter($people, static fn($p) => $p['cost_center_code'] === $summary['cost_center_code'])
        )));
        $summary['net_hours'] = (float)$summary['net_hours'];
        $summary['extra_hours'] = (float)$summary['extra_hours'];
        $summary['fte_days'] = (float)$summary['fte_days'];
        $summary['avg_fte'] = count($dates) > 0 ? $summary['fte_days'] / count($dates) : 0;
    }
    unset($summary);

    $costCenterDays = [];
    foreach ($days as $code => $byDate) {
        foreach ($dates as $date) {
            $costCenterDays[] = [
                'date' => $date,
                'cost_center_code' => $code,
                'present_people_count' => (int)($byDate[$date]['present'] ?? 0),
                'total_people_count' => (int)($byDate[$date]['total'] ?? 0),
            ];
        }
    }

    return [
        'from_date' => $from->format('Y-m-d'),
        'to_date' => $to->format('Y-m-d'),
        'generated_at' => date(DATE_ATOM),
        'requested_cost_center_codes' => $requestedCodes,
        'warnings' => $warnings,
        'cost_centers' => $costCenters,
        'summaries' => array_values($summaries),
        'cost_center_days' => $costCenterDays,
        'people' => $people,
        'person_days' => $personRows,
        'attendance_status_summary' => fte_attendance_summarize_statuses($personRows),
        'settings' => [
            'daily_hours' => $dailyHours,
            'start_time' => $startTime,
            'lunch_minutes' => $lunchMinutes,
            'other_minutes' => $otherMinutes,
        ],
    ];
}
