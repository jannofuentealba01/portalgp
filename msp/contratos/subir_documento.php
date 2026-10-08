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
$idLocal = filter_input(INPUT_POST, 'id_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idReemplazado = filter_input(INPUT_POST, 'id_documento_reemplazado', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idContrato = is_int($idContrato) ? $idContrato : 0;
$idLocal = is_int($idLocal) ? $idLocal : null;
$idReemplazado = is_int($idReemplazado) ? $idReemplazado : null;
$usuario = (int) ($_SESSION['usuario']['id'] ?? 0);

try {
    $resultado = ContratoDocumentoService::cargar(
        $conn,
        $idContrato,
        is_array($_FILES['archivo'] ?? null) ? $_FILES['archivo'] : [],
        (string) ($_POST['tipo_documento'] ?? ''),
        isset($_POST['fecha_documento']) ? (string) $_POST['fecha_documento'] : null,
        isset($_POST['descripcion']) ? (string) $_POST['descripcion'] : null,
        $usuario,
        $idLocal,
        $idReemplazado
    );
    msp2SetFlash(
        'success',
        !empty($resultado['reemplazo'])
            ? 'El PDF fue reemplazado y la versión anterior quedó conservada en la trazabilidad.'
            : 'El PDF fue adjuntado correctamente al contrato.'
    );
} catch (Throwable $error) {
    msp2SetFlash(
        $error instanceof RuntimeException ? 'warning' : 'danger',
        $error instanceof RuntimeException ? $error->getMessage() : 'No fue posible adjuntar el PDF al contrato.'
    );
}

msp2Redirect($idContrato > 0
    ? 'contratos/ficha.php?id_contrato_arriendo=' . $idContrato . '#documentos-adjuntos'
    : 'contratos/index.php');
