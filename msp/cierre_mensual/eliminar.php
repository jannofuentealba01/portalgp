<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

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
    if (msp2TableExists($conn, 'msp_procesos_cobro_servicio')) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_procesos_cobro_servicio WHERE id_cierre_mensual = :id');
        $stmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar un cierre con procesos asociados.');
            msp2Redirect('cierre_mensual/index.php');
        }
    }

    if (msp2TableExists($conn, 'msp_documentos_cobro')) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_documentos_cobro WHERE periodo_facturacion = (SELECT periodo_facturacion FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual = :id)');
        $stmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
        $stmt->execute();
        if ((int) $stmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar un cierre con documentos asociados.');
            msp2Redirect('cierre_mensual/index.php');
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual = :id');
    $deleteStmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'El cierre que intentas eliminar ya no existe.');
        msp2Redirect('cierre_mensual/index.php');
    }

    msp2SetFlash('success', 'Cierre eliminado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar el cierre mensual.');
}

msp2Redirect('cierre_mensual/index.php');
