<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('rubros/index.php');
}

$idRubro = filter_input(INPUT_POST, 'id_rubro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idRubro === false || $idRubro === null) {
    msp2SetFlash('warning', 'El rubro indicado no es válido.');
    msp2Redirect('rubros/index.php');
}

try {
    if (msp2TableExists($conn, 'msp_tiendas')) {
        $usageStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_tiendas WHERE id_rubro = :id_rubro');
        $usageStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
        $usageStmt->execute();

        if ((int) $usageStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar el rubro porque está asignado a una o más tiendas.');
            msp2Redirect('rubros/index.php');
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_rubros WHERE id_rubro = :id_rubro');
    $deleteStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'El rubro que intentas eliminar ya no existe.');
        msp2Redirect('rubros/index.php');
    }

    msp2SetFlash('success', 'El rubro fue eliminado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar el rubro. Revisa dependencias o la estructura de la base.');
}

msp2Redirect('rubros/index.php');
