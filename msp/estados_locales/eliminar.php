<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('estados_locales/index.php');
}

$idEstadoLocal = filter_input(INPUT_POST, 'id_estado_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idEstadoLocal === false || $idEstadoLocal === null) {
    msp2SetFlash('warning', 'El estado indicado no es válido.');
    msp2Redirect('estados_locales/index.php');
}

try {
    if (msp2TableExists($conn, 'msp_locales')) {
        $usageStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_locales WHERE id_estado_local = :id_estado_local');
        $usageStmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
        $usageStmt->execute();

        if ((int) $usageStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar el estado porque está asignado a uno o más locales.');
            msp2Redirect('estados_locales/index.php');
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_estado_locales WHERE id_estado_local = :id_estado_local');
    $deleteStmt->bindValue(':id_estado_local', $idEstadoLocal, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'El estado que intentas eliminar ya no existe.');
        msp2Redirect('estados_locales/index.php');
    }

    msp2SetFlash('success', 'El estado fue eliminado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar el estado. Revisa dependencias o la estructura de la base.');
}

msp2Redirect('estados_locales/index.php');
