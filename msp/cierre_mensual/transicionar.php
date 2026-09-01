<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CierreMensualService.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cierre_mensual/index.php');
}

$idCierre = filter_input(INPUT_POST, 'id_cierre_mensual', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$estadoEsperado = filter_input(INPUT_POST, 'estado_esperado', FILTER_VALIDATE_INT);
$estadoDestino = filter_input(INPUT_POST, 'estado_destino', FILTER_VALIDATE_INT);
$motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');

if ($idCierre === false || $idCierre === null || $estadoEsperado === false || $estadoEsperado === null || $estadoDestino === false || $estadoDestino === null) {
    msp2SetFlash('warning', 'La transición solicitada no es válida.');
    msp2Redirect('cierre_mensual/index.php');
}

try {
    (new CierreMensualService($conn))->transicionar(
        (int) $idCierre,
        (int) $estadoEsperado,
        (int) $estadoDestino,
        $motivo !== '' ? $motivo : ((int) $estadoDestino === CierreMensualService::BORRADOR ? '' : 'Confirmación desde Cierre mensual'),
        isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : null
    );
    msp2SetFlash('success', 'Período actualizado a ' . CierreMensualService::etiqueta((int) $estadoDestino) . '.');
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible cambiar el estado del período.');
}

msp2Redirect('cierre_mensual/index.php');
