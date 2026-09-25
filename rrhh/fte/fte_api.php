<?php
declare(strict_types=1);

require __DIR__ . '/../../db.php';
require __DIR__ . '/../../permisos.php';
require __DIR__ . '/fte_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

function fte_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fte_apply_attendance_timeout(array &$config, int $rangeDays): void
{
    $timeoutSeconds = fte_attendance_timeout_seconds($config, $rangeDays);
    if ($timeoutSeconds < 1) {
        return;
    }
    $requestStartedAt = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    $config['_request_deadline_at'] = $requestStartedAt + $timeoutSeconds;
    $config['_request_timeout_seconds'] = $timeoutSeconds;
    @set_time_limit($timeoutSeconds + 5);
}

function fte_attendance_public_error(FteAttendanceBatchException $exception, string $reference, int $timeoutSeconds): void
{
    $reason = $exception->reason();
    $status = 502;
    $code = 'FTE_GEOVICTORIA_ERROR';
    $message = 'GeoVictoria no estuvo disponible para completar la consulta.';
    if (in_array($reason, ['deadline', 'timeout'], true)) {
        $status = 504;
        $code = 'FTE_QUERY_TIMEOUT';
        $message = 'La consulta supero el tiempo maximo de ' . max(1, $timeoutSeconds) . ' segundos. Intenta nuevamente o reduce los centros de costo seleccionados.';
    } elseif ($reason === 'rate_limit') {
        $status = 429;
        $code = 'FTE_GEOVICTORIA_RATE_LIMIT';
        $message = 'GeoVictoria limito temporalmente las consultas. Espera unos minutos antes de volver a intentar.';
    } elseif ($reason === 'authentication') {
        $code = 'FTE_GEOVICTORIA_AUTH';
        $message = 'GeoVictoria rechazo la autenticacion. Se requiere revisar la credencial configurada.';
    } elseif ($reason === 'identity_integrity') {
        $code = 'FTE_GEOVICTORIA_IDENTITY';
        $message = 'GeoVictoria devolvio identidades que no corresponden a la consulta. Los resultados no fueron publicados.';
    } elseif ($reason === 'invalid_response') {
        $code = 'FTE_GEOVICTORIA_RESPONSE';
        $message = 'GeoVictoria entrego una respuesta incompleta o no reconocida.';
    }
    fte_json_response([
        'ok' => false,
        'error' => $message,
        'error_code' => $code,
        'reference' => $reference,
    ], $status);
}

$config = fte_load_config();
if (!isset($_SESSION['usuario'])) {
    fte_json_response(['ok' => false, 'error' => 'Sesion no iniciada.'], 401);
}

$permission = (string)($config['permission'] ?? 'Ver Dashboard FTE');
if (!function_exists('tienePermiso') || !tienePermiso($_SESSION['usuario']['id'], $permission)) {
    fte_json_response(['ok' => false, 'error' => 'No tienes permiso para consultar FTE.'], 403);
}

try {
    $action = trim((string)($_GET['action'] ?? 'dashboard'));
    if ($action === 'cost_centers') {
        $historyScope = trim((string)($_GET['scope'] ?? '')) === 'history';
        $people = fte_fetch_buk_people($config, !$historyScope);
        fte_json_response([
            'ok' => true,
            'cost_centers' => fte_build_buk_cost_centers($people),
            'warnings' => [],
        ]);
    }
    if ($action === 'absences') {
        $payload = fte_build_absence_payload($config, $_GET);
        fte_json_response(['ok' => true, 'data' => $payload]);
    }
    if ($action === 'headcount_snapshot_status') {
        $period = trim((string)($_GET['period'] ?? ''));
        fte_json_response(['ok' => true, 'snapshot' => fte_monthly_source_snapshot_status($conn, $period)]);
    }
    if ($action === 'headcount_snapshot_details') {
        $period = trim((string)($_GET['period'] ?? ''));
        fte_json_response(['ok' => true, 'data' => fte_monthly_source_snapshot_details($conn, $period)]);
    }
    if ($action === 'save_headcount_snapshot') {
        if (!tienePermiso((int)$_SESSION['usuario']['id'], $permission, 'escritura')) {
            fte_json_response(['ok' => false, 'error' => 'No tienes permiso para guardar fotografias FTE.'], 403);
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            fte_json_response(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
        }
        $csrfToken = $_POST['_pgp_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!function_exists('pgpVerifyCsrf') || !pgpVerifyCsrf($csrfToken)) {
            fte_json_response(['ok' => false, 'error' => 'La solicitud no tiene un token de seguridad valido. Recarga la pagina.'], 403);
        }
        $period = trim((string)($_POST['period'] ?? ''));
        fte_monthly_source_snapshot_assert_closed_period($period);
        @set_time_limit(0);
        $snapshot = fte_monthly_source_snapshot_build($config, $period);
        $saved = fte_monthly_source_snapshot_save(
            $conn, $snapshot, (int)($_SESSION['usuario']['id'] ?? 0),
            fte_monthly_source_snapshot_storage_dir($config)
        );
        fte_json_response(['ok' => true, 'snapshot' => $saved]);
    }
    if ($action === 'approve_headcount_snapshot') {
        if (!tienePermiso((int)$_SESSION['usuario']['id'], $permission, 'eliminacion')) {
            fte_json_response(['ok' => false, 'error' => 'No tienes permiso para aprobar y congelar fotografias FTE.'], 403);
        }
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            fte_json_response(['ok' => false, 'error' => 'Metodo no permitido.'], 405);
        }
        $csrfToken = $_POST['_pgp_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!function_exists('pgpVerifyCsrf') || !pgpVerifyCsrf($csrfToken)) {
            fte_json_response(['ok' => false, 'error' => 'La solicitud no tiene un token de seguridad valido. Recarga la pagina.'], 403);
        }
        $period = trim((string)($_POST['period'] ?? ''));
        $expectedHeadcount = filter_var(
            $_POST['expected_headcount'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]]
        );
        if ($expectedHeadcount === false) {
            throw new InvalidArgumentException('Ingresa una dotacion de control valida antes de aprobar.');
        }
        $approved = fte_monthly_source_snapshot_approve(
            $conn,
            $period,
            (int)($_SESSION['usuario']['id'] ?? 0),
            (int)$expectedHeadcount,
            trim((string)($_POST['approval_note'] ?? '')),
            fte_monthly_source_snapshot_storage_dir($config)
        );
        fte_json_response(['ok' => true, 'snapshot' => $approved]);
    }
    if ($action === 'monthly') {
        @set_time_limit(0);
        $includeAttendance = filter_var($_GET['include_attendance'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($includeAttendance) {
            $config['_attendance_total_deadline_disabled'] = true;
            $config['_request_timeout_seconds'] = 0;
        }
        $payload = fte_build_monthly_payload($config, $_GET, $conn);
        fte_json_response(['ok' => true, 'data' => $payload]);
    }
    [$defaultFrom, $defaultTo] = fte_default_range();
    $requestedFrom = trim((string)($_GET['from_date'] ?? $defaultFrom));
    $requestedTo = trim((string)($_GET['to_date'] ?? $defaultTo));
    [, , $requestedDays] = fte_validate_range($requestedFrom, $requestedTo, (int)$config['max_range_days']);
    fte_apply_attendance_timeout($config, $requestedDays);
    $payload = fte_build_payload($config, $_GET);
    fte_json_response(['ok' => true, 'data' => $payload]);
} catch (Throwable $e) {
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log('FTE API [' . $reference . '] action=' . preg_replace('/[^a-z_]/i', '', (string)($action ?? 'unknown')) . ' error=' . fte_redact_error_message($e->getMessage(), $config));
    if ($e instanceof FteAttendanceBatchException) {
        fte_attendance_public_error($e, $reference, (int)($config['_request_timeout_seconds'] ?? 45));
    }
    if (in_array((string)($action ?? ''), ['headcount_snapshot_status', 'headcount_snapshot_details', 'save_headcount_snapshot', 'approve_headcount_snapshot'], true)
        && in_array(get_class($e), [InvalidArgumentException::class, DomainException::class, RuntimeException::class], true)) {
        fte_json_response([
            'ok' => false,
            'error' => pgpSafePublicMessage($e->getMessage(), 'fte.snapshot', 'No fue posible guardar la fotografia mensual.'),
            'reference' => $reference,
        ], 422);
    }
    fte_json_response([
        'ok' => false,
        'error' => 'No fue posible consultar los datos FTE. Revisa la configuracion de las APIs.',
        'reference' => $reference,
    ], 502);
}
