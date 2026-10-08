<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/ContratoDocumentoService.php';

msp2RequireAccess('MSP Operacion', 'escritura');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idDocumento = filter_input(INPUT_POST, 'id_documento', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContrato = is_int($idContrato) ? $idContrato : 0;
$idDocumento = is_int($idDocumento) ? $idDocumento : 0;
$usuario = (int) ($_SESSION['usuario']['id'] ?? 0);

try {
    ContratoDocumentoService::anular(
        $conn,
        $idContrato,
        $idDocumento,
        (string) ($_POST['motivo_anulacion'] ?? ''),
        $usuario
    );
    msp2SetFlash('success', 'El documento adjunto fue anulado. El archivo físico se conserva para trazabilidad.');
} catch (Throwable $error) {
    msp2SetFlash(
        $error instanceof RuntimeException ? 'warning' : 'danger',
        $error instanceof RuntimeException ? $error->getMessage() : 'No fue posible anular el documento adjunto.'
    );
}

msp2Redirect($idContrato > 0
    ? 'contratos/ficha.php?id_contrato_arriendo=' . $idContrato . '#documentos-adjuntos'
    : 'contratos/index.php');
