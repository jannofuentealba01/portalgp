<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasDeleteRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['tiendas/index.php', 'arrendatarios/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'tiendas/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tiendas/index.php');
}

$idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idTienda === false || $idTienda === null) {
    msp2SetFlash('warning', 'La tienda indicada no es válida.');
    msp2TiendasDeleteRedirectFromPost();
}

try {
    if (msp2TableExists($conn, 'msp_ocupacion_locales')) {
        $usageStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_ocupacion_locales WHERE id_tienda = :id_tienda');
        $usageStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $usageStmt->execute();

        if ((int) $usageStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar la tienda porque tiene ocupaciones asociadas.');
            msp2TiendasDeleteRedirectFromPost();
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
    $deleteStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'La tienda que intentas eliminar ya no existe.');
        msp2TiendasDeleteRedirectFromPost();
    }

    msp2SetFlash('success', 'La tienda fue eliminada correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar la tienda. Revisa dependencias o la estructura de la base.');
}

msp2TiendasDeleteRedirectFromPost();
