<?php
declare(strict_types=1);

require_once __DIR__ . '/fte_monthly_engine.php';
require_once __DIR__ . '/fte_calendar_engine.php';
require_once __DIR__ . '/fte_headcount_history.php';
require_once __DIR__ . '/fte_attendance_status.php';
require_once __DIR__ . '/fte_monthly_report.php';

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
    return [
        'buk_employee_id' => fte_first_non_empty($raw, ['id']),
        'identifier' => $identifier,
        'normalized_identifier' => $normalizedIdentifier,
        'person_name' => $fullName !== '' ? $fullName : $identifier,
        'cost_center_code' => $costCenter['code'],
        'cost_center_name' => $costCenter['name'],
        'status' => $status,
        'active' => $active,
        'active_since' => fte_buk_date($raw['active_since'] ?? null),
        'active_until' => fte_buk_date($raw['active_until'] ?? null),
        'jobs' => $jobs,
    ];
}

function fte_http_json(string $url, array $headers = [], ?array $jsonPayload = null, int $timeout = 45): array
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
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)(curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0);
    curl_close($ch);

    $decoded = null;
    if ($raw !== false && $raw !== null && $raw !== '') {
        $tmp = json_decode((string)$raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $decoded = $tmp;
        }
    }
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'errno' => $errno,
        'error' => $error,
        'raw' => $raw,
        'json' => $decoded,
    ];
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

function fte_fetch_buk_people(array $config, bool $activeOnly = true): array
{
    fte_assert_buk_config($config);
    $base = rtrim((string)$config['buk_base_url'], '/');
    $country = trim((string)$config['buk_country']);
    $cacheKey = hash('sha256', $base . '|' . $country . '|' . (string)$config['buk_token'] . '|' . ($activeOnly ? 'active' : 'history'));
    $cacheTtl = max(0, (int)($config['buk_people_cache_ttl_seconds'] ?? 300));
    if (session_status() === PHP_SESSION_ACTIVE && $cacheTtl > 0) {
        $cache = $_SESSION['fte_buk_people_cache'] ?? null;
        if (is_array($cache)
            && hash_equals((string)($cache['key'] ?? ''), $cacheKey)
            && (int)($cache['expires_at'] ?? 0) > time()
            && is_array($cache['people'] ?? null)) {
            return $cache['people'];
        }
    }
    $path = "/api/v1/{$country}/employees";
    $people = [];
    for ($page = 1; $page <= 50; $page++) {
        $query = [
            'page' => $page,
            'per_page' => 100,
            'page_size' => 100,
            'include' => 'department,sub_department,position,employment,contract,organizational_unit,company_area,team,current_job,current_employment',
        ];
        $url = $base . $path . '?' . http_build_query($query);
        $response = fte_http_json($url, fte_buk_headers($config), null, 30);
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
        $pageRows = fte_extract_list($response['json']);
        if (!$pageRows) {
            break;
        }
        foreach ($pageRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $person = fte_normalize_buk_person($row);
            if ($person !== null && (!$activeOnly || $person['active'] !== false)) {
                $people[$person['normalized_identifier']] = $person;
            }
        }
        if (!fte_response_has_next_page($response['json'], $page, count($pageRows), 100)) {
            break;
        }
        if ($page === 50) {
            throw new RuntimeException('Buk excedio el limite de paginas; nomina incompleta.');
        }
    }
    $people = array_values($people);
    $names = fte_fetch_buk_cost_center_names($config);
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
    if (session_status() === PHP_SESSION_ACTIVE && $cacheTtl > 0) {
        $_SESSION['fte_buk_people_cache'] = [
            'key' => $cacheKey,
            'expires_at' => time() + $cacheTtl,
            'people' => $people,
        ];
    }
    return $people;
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

function fte_fetch_buk_cost_center_names(array $config): array
{
    $country = trim((string)$config['buk_country']);
    $result = fte_buk_fetch_all($config, "/api/v1/{$country}/organization/areas/", [
        'status' => 'both',
        'page_size' => 1000,
        'per_page' => 1000,
    ], 50);
    if (!$result['ok']) {
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
    return $names;
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
        $response = fte_http_json($url, fte_buk_headers($config), null, 30);
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
    $keys = [];
    foreach ([
        'employee_id',
        'person_id',
        'employee.id',
        'person.id',
        'employee.rut',
        'person.rut',
        'rut',
        'employee.document_number',
        'document_number',
        'employee.identification',
        'identification',
    ] as $path) {
        $value = strpos($path, '.') !== false ? fte_arr_get($item, $path) : ($item[$path] ?? null);
        if ($value === null || is_array($value)) {
            continue;
        }
        $text = trim((string)$value);
        if ($text !== '') {
            $keys[] = $text;
            $normalized = fte_normalize_identifier($text);
            if ($normalized !== '') {
                $keys[] = $normalized;
            }
        }
    }
    return array_values(array_unique($keys));
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
        $keys = array_values(array_unique(array_filter([
            (string)($person['normalized_identifier'] ?? ''),
            fte_normalize_identifier($person['identifier'] ?? ''),
            (string)($person['buk_employee_id'] ?? ''),
        ])));
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

function fte_geovictoria_token(array $config): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $cached = $_SESSION['fte_geovictoria_token'] ?? null;
    $expires = (int)($_SESSION['fte_geovictoria_token_expires'] ?? 0);
    if (is_string($cached) && $cached !== '' && $expires > time()) {
        return $cached;
    }
    fte_assert_geovictoria_config($config);
    $user = trim((string)$config['geovictoria_user']);
    $password = trim((string)$config['geovictoria_password']);
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/Login';
    $response = fte_http_json($url, [], ['User' => $user, 'Password' => $password], 30);
    if (!$response['ok'] || !is_array($response['json']) || empty($response['json']['token'])) {
        throw new RuntimeException('GeoVictoria no entrego token de autenticacion.');
    }
    $_SESSION['fte_geovictoria_token'] = (string)$response['json']['token'];
    $_SESSION['fte_geovictoria_token_expires'] = time() + (int)$config['geovictoria_token_ttl_seconds'];
    return (string)$_SESSION['fte_geovictoria_token'];
}

function fte_geovictoria_post(array $config, string $endpoint, array $payload): array
{
    $token = fte_geovictoria_token($config);
    $url = rtrim((string)$config['geovictoria_base_url'], '/') . '/' . ltrim($endpoint, '/');
    $response = fte_http_json($url, ['Authorization: Bearer ' . $token], $payload, 75);
    if (($response['status'] === 401 || $response['status'] === 403) && session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['fte_geovictoria_token'], $_SESSION['fte_geovictoria_token_expires']);
        $token = fte_geovictoria_token($config);
        $response = fte_http_json($url, ['Authorization: Bearer ' . $token], $payload, 75);
    }
    if (!$response['ok']) {
        throw new RuntimeException('GeoVictoria HTTP ' . $response['status'] . ': ' . trim((string)$response['error']));
    }
    return is_array($response['json']) ? $response['json'] : [];
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

function fte_fetch_attendance(array $config, array $people, DateTimeImmutable $from, DateTimeImmutable $to, ?array &$diagnostics = null): array
{
    $attendance = [];
    $diagnostics = ['successful_identifiers' => [], 'failed_identifiers' => [], 'unmatched_identifiers' => []];
    $start = $from->format('Ymd') . '000000';
    $end = $to->format('Ymd') . '235959';
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
                $raw = fte_geovictoria_post($config, 'AttendanceBook', [
                    'StartDate' => $start,
                    'EndDate' => $end,
                    'UserIds' => $candidate,
                ]);
                fte_merge_attendance_payload($attendance, $raw);
                $returnedIdentifiers = array_values(array_filter(array_map(
                    static fn($user): string => is_array($user) ? fte_identifier_from_geo_user($user) : '',
                    fte_extract_users($raw)
                )));
                if (!in_array($identifier, $returnedIdentifiers, true)) {
                    $diagnostics['unmatched_identifiers'][] = $identifier;
                }
                $success = true;
                break;
            } catch (Throwable $inner) {
                continue;
            }
        }
        $bucket = $success ? 'successful_identifiers' : 'failed_identifiers';
        $diagnostics[$bucket][] = $identifier;
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

function fte_merge_attendance_payload(array &$attendance, array $raw): void
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
            $attendance[$identifier][$date]['geovictoria_worked_hours'] = round(fte_duration_hours($interval['WorkedHours'] ?? ''), 4);
            $attendance[$identifier][$date]['authorized_overtime_hours'] = round(fte_duration_hours($interval['TotalAuthorizedOvertime'] ?? ''), 4);
            $attendance[$identifier][$date]['delay_hours'] = round(fte_duration_hours($interval['DelayTimeAfterCompensation'] ?? $interval['Delay'] ?? ''), 4);
            $attendance[$identifier][$date]['early_leave_hours'] = round(fte_duration_hours($interval['EarlyLeaveTimeAfterCompensation'] ?? $interval['EarlyLeave'] ?? ''), 4);
            $attendance[$identifier][$date]['non_worked_hours'] = round(fte_duration_hours($interval['NonWorkedHours'] ?? ''), 4);
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
                'net_hours' => round($net, 2),
                'extra_hours' => round($extra, 2),
                'fte_day' => round($net / $dailyHours, 3),
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
        $summary['net_hours'] = round($summary['net_hours'], 2);
        $summary['extra_hours'] = round($summary['extra_hours'], 2);
        $summary['fte_days'] = round($summary['fte_days'], 2);
        $summary['avg_fte'] = count($dates) > 0 ? round($summary['fte_days'] / count($dates), 2) : 0;
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
