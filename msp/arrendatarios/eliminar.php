<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('arrendatarios/index.php');
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idArrendatario === false || $idArrendatario === null) {
    msp2SetFlash('warning', 'El arrendatario indicado no es válido.');
    msp2Redirect('arrendatarios/index.php');
}

try {
    $conn->beginTransaction();

    if (msp2TableExists($conn, 'msp_tiendas')) {
        $usageStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_tiendas WHERE id_arrendatario = :id_arrendatario');
        $usageStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $usageStmt->execute();

        if ((int) $usageStmt->fetchColumn() > 0) {
            $conn->rollBack();
            msp2SetFlash('warning', 'No puedes eliminar el arrendatario porque tiene tiendas asociadas.');
            msp2Redirect('arrendatarios/index.php');
        }
    }

    if (msp2TableExists($conn, 'msp_arrendatarios_correos')) {
        $deleteCorreosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_correos WHERE id_arrendatario = :id_arrendatario');
        $deleteCorreosStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $deleteCorreosStmt->execute();
    }

    if (msp2TableExists($conn, 'msp_arrendatarios_telefonos')) {
        $deleteTelefonosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_telefonos WHERE id_arrendatario = :id_arrendatario');
        $deleteTelefonosStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $deleteTelefonosStmt->execute();
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios WHERE id_arrendatario = :id_arrendatario');
    $deleteStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        $conn->rollBack();
        msp2SetFlash('warning', 'El arrendatario que intentas eliminar ya no existe.');
        msp2Redirect('arrendatarios/index.php');
    }

    $conn->commit();
    msp2SetFlash('success', 'El arrendatario fue eliminado correctamente.');
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('danger', 'No fue posible eliminar el arrendatario. Revisa dependencias o la estructura de la base.');
}

msp2Redirect('arrendatarios/index.php');
