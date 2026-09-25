<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

if (getenv('PORTALGP_DAST_ISOLATED') !== '1') {
    fwrite(STDERR, "Refusing to run: set PORTALGP_DAST_ISOLATED=1 only for an isolated copy.\n");
    exit(2);
}

$baseUrl = rtrim((string) getenv('PORTALGP_DAST_BASE_URL'), '/');
$sessionDb = (string) getenv('PORTALGP_DAST_WAPITI_DB');
$adminSession = (string) getenv('PORTALGP_DAST_ADMIN_SESSION');
$limitedSession = (string) getenv('PORTALGP_DAST_LIMITED_SESSION');
$limitedCsrf = (string) getenv('PORTALGP_DAST_LIMITED_CSRF');
$cookieName = trim((string) getenv('PORTALGP_DAST_COOKIE_NAME'));
$outputPath = (string) getenv('PORTALGP_DAST_OUTPUT');

if ($cookieName === '') {
    $cookieName = 'PGPDASTSESSID';
}

if (!preg_match('~^http://(?:127\.0\.0\.1|localhost)(?::\d+)?/portalgp/msp$~', $baseUrl)) {
    fwrite(STDERR, "The target must be the isolated loopback MSP URL.\n");
    exit(2);
}
if (!is_file($sessionDb) || $adminSession === '' || $limitedSession === '' || strlen($limitedCsrf) < 32) {
    fwrite(STDERR, "Missing isolated session inputs.\n");
    exit(2);
}
if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $cookieName) !== 1) {
    fwrite(STDERR, "Invalid isolated cookie name.\n");
    exit(2);
}

/** @return array{status:int,location:string,body:string,error:string,duration_ms:int} */
function dastRequest(
    string $method,
    string $url,
    string $sessionId = '',
    array $data = [],
    string $cookieName = 'PGPDASTSESSID'
): array
{
    $ch = curl_init($url);
    $headers = [
        'Accept: text/html,application/json;q=0.9,*/*;q=0.5',
        'User-Agent: PortalGP-DAST-Controlled/1.0',
    ];
    if ($sessionId !== '') {
        $headers[] = 'Cookie: ' . $cookieName . '=' . $sessionId;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&', PHP_QUERY_RFC3986));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/x-www-form-urlencoded']));
    }
    $started = hrtime(true);
    $body = curl_exec($ch);
    $duration = (int) round((hrtime(true) - $started) / 1_000_000);
    $result = [
        'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'location' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        'body' => is_string($body) ? $body : '',
        'error' => curl_error($ch),
        'duration_ms' => $duration,
    ];
    curl_close($ch);
    return $result;
}

/** @return array<int,array{path:string,method:string,enctype:string,params:array<string,array{type:string,value:string}>}> */
function dastLoadCases(string $sessionDb, string $baseUrl): array
{
    $db = new PDO('sqlite:' . $sessionDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $stmt = $db->prepare(
        "SELECT p.path_id,p.path,p.method,p.enctype,x.type,x.position,x.name,
                CAST(x.value1 AS TEXT) AS value1
         FROM paths p
         LEFT JOIN params x ON x.path_id=p.path_id
         WHERE p.path LIKE :target_like
         ORDER BY p.path_id,x.position"
    );
    $stmt->execute([':target_like' => $baseUrl . '/%']);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $byId = [];
    foreach ($rows as $row) {
        $id = (int) $row['path_id'];
        if (!isset($byId[$id])) {
            $path = (string) $row['path'];
            $relative = parse_url($path, PHP_URL_PATH);
            if (!is_string($relative) || !str_starts_with($relative, '/portalgp/msp/')) {
                continue;
            }
            $byId[$id] = [
                'path' => $baseUrl . substr($relative, strlen('/portalgp/msp')),
                'method' => strtoupper((string) $row['method']),
                'enctype' => (string) $row['enctype'],
                'params' => [],
            ];
        }
        $name = (string) ($row['name'] ?? '');
        if ($name !== '') {
            $byId[$id]['params'][$name] = [
                'type' => strtoupper((string) ($row['type'] ?? '')),
                'value' => (string) ($row['value1'] ?? ''),
            ];
        }
    }

    $unique = [];
    foreach ($byId as $case) {
        $names = array_keys($case['params']);
        sort($names);
        $signature = $case['method'] . '|' . $case['path'] . '|' . implode(',', $names);
        $unique[$signature] ??= $case;
    }
    return array_values($unique);
}

function dastHasInternalError(string $body): bool
{
    return preg_match(
        '~SQLSTATE|ODBC\s+Driver|PDOException|Stack\s+trace|Uncaught\s+|Fatal error|[A-Z]:\\\\[^\r\n<]+\.php|/(?:var|home|srv)/[^\r\n<]+\.php~i',
        $body
    ) === 1;
}

$cases = dastLoadCases($sessionDb, $baseUrl);
$phpCases = array_values(array_filter(
    $cases,
    static fn(array $case): bool => str_ends_with(strtolower(parse_url($case['path'], PHP_URL_PATH) ?: ''), '.php')
));
$getCases = array_values(array_filter($phpCases, static fn(array $case): bool => $case['method'] === 'GET'));
$postCases = array_values(array_filter($phpCases, static fn(array $case): bool => $case['method'] === 'POST'));

$findings = [];
$metrics = [
    'discovered_unique_cases' => count($cases),
    'php_get_cases' => count($getCases),
    'php_post_cases' => count($postCases),
    'anonymous_gets_tested' => 0,
    'csrf_posts_tested' => 0,
    'limited_posts_tested' => 0,
    'injection_payload_requests' => 0,
    'requests' => 0,
];

foreach ($getCases as $case) {
    $response = dastRequest('GET', $case['path']);
    $metrics['requests']++;
    $metrics['anonymous_gets_tested']++;
    if ($response['status'] === 200 || dastHasInternalError($response['body'])) {
        $findings[] = [
            'type' => 'anonymous_access',
            'severity' => $response['status'] === 200 ? 'high' : 'medium',
            'path' => $case['path'],
            'status' => $response['status'],
            'detail' => $response['status'] === 200 ? 'PHP route returned content without a session.' : 'Internal error details were exposed.',
        ];
    }
}

foreach ($postCases as $case) {
    $data = [];
    foreach ($case['params'] as $name => $param) {
        if ($param['type'] === 'FILE') {
            continue;
        }
        $data[$name] = $param['value'];
    }
    $data['_csrf'] = 'DAST_INVALID_TOKEN';
    $data['_pgp_csrf'] = 'DAST_INVALID_TOKEN';
    $response = dastRequest('POST', $case['path'], $adminSession, $data, $cookieName);
    $metrics['requests']++;
    $metrics['csrf_posts_tested']++;
    if ($response['status'] !== 419 || dastHasInternalError($response['body'])) {
        $findings[] = [
            'type' => 'csrf_rejection',
            'severity' => 'high',
            'path' => $case['path'],
            'status' => $response['status'],
            'detail' => 'The POST form did not reject an invalid CSRF token with HTTP 419.',
        ];
    }

    $data['_csrf'] = $limitedCsrf;
    $data['_pgp_csrf'] = $limitedCsrf;
    $response = dastRequest('POST', $case['path'], $limitedSession, $data, $cookieName);
    $metrics['requests']++;
    $metrics['limited_posts_tested']++;
    if (!in_array($response['status'], [403, 419], true) || dastHasInternalError($response['body'])) {
        $findings[] = [
            'type' => 'limited_write_access',
            'severity' => 'high',
            'path' => $case['path'],
            'status' => $response['status'],
            'detail' => 'The MSP read-only profile was not stopped with HTTP 403/419.',
        ];
    }
}

$payloads = [
    'sql' => "' OR 1=1-- DASTSQL",
    'xss' => '<svg/onload=confirm("DAST-XSS")>',
];
$skipParams = ['pagina', 'lineas', 'page', 'per_page', 'format', 'step', 'tab', 'focus'];
foreach ($getCases as $case) {
    if ($case['params'] === []) {
        continue;
    }
    $baseData = [];
    foreach ($case['params'] as $name => $param) {
        if ($param['type'] !== 'FILE') {
            $baseData[$name] = $param['value'];
        }
    }
    foreach (array_keys($baseData) as $name) {
        if (in_array(strtolower($name), $skipParams, true) || preg_match('~(?:^|_)id(?:_|$)~i', $name) === 1) {
            continue;
        }
        foreach ($payloads as $kind => $payload) {
            $data = $baseData;
            $data[$name] = $payload;
            $response = dastRequest(
                'GET',
                $case['path'] . '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986),
                $adminSession,
                [],
                $cookieName
            );
            $metrics['requests']++;
            $metrics['injection_payload_requests']++;
            if ($response['status'] >= 500 || dastHasInternalError($response['body'])) {
                $findings[] = [
                    'type' => $kind . '_error_or_disclosure',
                    'severity' => 'high',
                    'path' => $case['path'],
                    'parameter' => $name,
                    'status' => $response['status'],
                    'detail' => 'The injection marker caused a server error or exposed internal details.',
                ];
            }
            if ($kind === 'xss' && str_contains($response['body'], $payload)) {
                $findings[] = [
                    'type' => 'reflected_xss',
                    'severity' => 'high',
                    'path' => $case['path'],
                    'parameter' => $name,
                    'status' => $response['status'],
                    'detail' => 'The exact XSS marker was reflected without HTML encoding.',
                ];
            }
            if ($response['error'] !== '') {
                $findings[] = [
                    'type' => 'transport_error',
                    'severity' => 'medium',
                    'path' => $case['path'],
                    'parameter' => $name,
                    'detail' => $response['error'],
                ];
            }
        }
    }
}

$report = [
    'generated_at' => date(DATE_ATOM),
    'target' => $baseUrl,
    'isolated' => true,
    'metrics' => $metrics,
    'finding_count' => count($findings),
    'findings' => $findings,
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    throw new RuntimeException('Unable to encode the DAST report.');
}
if ($outputPath !== '') {
    file_put_contents($outputPath, $json . PHP_EOL);
}
echo $json, PHP_EOL;
