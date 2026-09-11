<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CierreMensualService.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cierre_mensual/index.php');
}

$idCierre = filter_input(INPUT_POST, 'id_cierre_mensual', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idCierre === false || $idCierre === null) {
    msp2SetFlash('warning', 'El cierre indicado no es válido.');
    msp2Redirect('cierre_mensual/index.php');
}

try {
    (new CierreMensualService($conn))->eliminarBorrador(
        (int) $idCierre,
        'Eliminación manual de borrador vacío desde Cierre mensual',
        isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null
    );
    msp2SetFlash('success', 'Cierre eliminado correctamente.');
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible eliminar el cierre mensual.');
}

msp2Redirect('cierre_mensual/index.php');
