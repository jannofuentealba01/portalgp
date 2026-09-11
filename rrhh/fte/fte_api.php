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
        $people = fte_fetch_buk_people($config);
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
    if ($action === 'monthly') {
        @set_time_limit(300);
        $payload = fte_build_monthly_payload($config, $_GET);
        fte_json_response(['ok' => true, 'data' => $payload]);
    }
    $payload = fte_build_payload($config, $_GET);
    fte_json_response(['ok' => true, 'data' => $payload]);
} catch (Throwable $e) {
    $reference = strtoupper(bin2hex(random_bytes(4)));
    error_log('FTE API [' . $reference . '] action=' . preg_replace('/[^a-z_]/i', '', (string)($action ?? 'unknown')) . ' error=' . fte_redact_error_message($e->getMessage(), $config));
    fte_json_response([
        'ok' => false,
        'error' => 'No fue posible consultar los datos FTE. Revisa la configuracion de las APIs.',
        'reference' => $reference,
    ], 502);
}
