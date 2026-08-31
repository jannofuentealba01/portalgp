<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/PendientesService.php';
require_once dirname(__DIR__) . '/services/PendientesGestionService.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('dashboard/index.php');
}

$accion = strtoupper(trim((string) ($_POST['accion'] ?? '')));
$clave = trim((string) ($_POST['pendiente_clave'] ?? ''));
$comentario = msp2NormalizeText((string) ($_POST['comentario'] ?? ''));
$idUsuarioAccion = (int) ($_SESSION['usuario']['id'] ?? 0);

try {
    $motor = new PendientesService($conn);
    $gestion = new PendientesGestionService($conn, $motor);
    $meta = match ($accion) {
        'ASIGNAR' => $gestion->asignar(
            $clave,
            (int) filter_input(INPUT_POST, 'id_usuario_asignado', FILTER_VALIDATE_INT),
            $idUsuarioAccion,
            $comentario
        ),
        'TOMAR_REVISION' => $gestion->tomarEnRevision($clave, $idUsuarioAccion, $comentario),
        'POSPONER' => $gestion->posponer($clave, trim((string) ($_POST['pospuesto_hasta'] ?? '')), $idUsuarioAccion, $comentario),
        'REABRIR' => $gestion->reabrir($clave, $idUsuarioAccion, $comentario),
        'COMENTAR' => $gestion->comentar($clave, $comentario, $idUsuarioAccion),
        'LIBERAR_ASIGNACION' => $gestion->liberarAsignacion($clave, $idUsuarioAccion, $comentario),
        default => throw new RuntimeException('La acción solicitada no es válida.'),
    };

    if (msp2IsAjaxRequest()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true, 'message' => 'Pendiente actualizado.', 'meta' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    msp2SetFlash('success', 'Pendiente actualizado correctamente.');
} catch (Throwable $exception) {
    $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible actualizar el pendiente.';
    if (msp2IsAjaxRequest()) {
        http_response_code(422);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    msp2SetFlash('warning', $message);
}

$redirect = trim((string) ($_POST['redirect_to'] ?? ''));
if ($redirect !== 'pendientes/index.php') {
    $redirect = 'dashboard/index.php';
}
msp2Redirect($redirect);
