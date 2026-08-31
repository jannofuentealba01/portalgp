<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('comunas/index.php');
}

$idComuna = filter_input(INPUT_POST, 'id_comuna', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idComuna === false || $idComuna === null) {
    msp2SetFlash('warning', 'La comuna indicada no es válida.');
    msp2Redirect('comunas/index.php');
}

try {
    if (msp2TableExists($conn, 'msp_arrendatarios')) {
        $usageStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_arrendatarios WHERE id_comuna = :id_comuna');
        $usageStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
        $usageStmt->execute();

        if ((int) $usageStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar la comuna porque está asignada a uno o más arrendatarios.');
            msp2Redirect('comunas/index.php');
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_comunas WHERE id_comuna = :id_comuna');
    $deleteStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'La comuna que intentas eliminar ya no existe.');
        msp2Redirect('comunas/index.php');
    }

    msp2SetFlash('success', 'La comuna fue eliminada correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar la comuna. Revisa dependencias o la estructura de la base.');
}

msp2Redirect('comunas/index.php');
