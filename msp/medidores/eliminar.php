<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2MedidoresDeleteRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['locales/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'locales/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('locales/index.php');
}

$idMedidor = filter_input(INPUT_POST, 'id_medidor', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idMedidor === false || $idMedidor === null) {
    msp2SetFlash('warning', 'El medidor indicado no es válido.');
    msp2MedidoresDeleteRedirectFromPost();
}

try {
    if (msp2TableExists($conn, 'msp_lecturas_medidores')) {
        $lecturasStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_lecturas_medidores WHERE id_medidor = :id_medidor');
        $lecturasStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
        $lecturasStmt->execute();

        if ((int) $lecturasStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar el medidor porque tiene lecturas asociadas.');
            msp2MedidoresDeleteRedirectFromPost();
        }
    }

    if (msp2TableExists($conn, 'msp_cobros_servicios') && msp2TableExists($conn, 'msp_lecturas_medidores')) {
        $cobrosStmt = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_cobros_servicios cs
             INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
             WHERE lm.id_medidor = :id_medidor'
        );
        $cobrosStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
        $cobrosStmt->execute();

        if ((int) $cobrosStmt->fetchColumn() > 0) {
            msp2SetFlash('warning', 'No puedes eliminar el medidor porque tiene cobros asociados.');
            msp2MedidoresDeleteRedirectFromPost();
        }
    }

    $deleteStmt = $conn->prepare('DELETE FROM dbo.msp_medidores WHERE id_medidor = :id_medidor');
    $deleteStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
    $deleteStmt->execute();

    if ($deleteStmt->rowCount() === 0) {
        msp2SetFlash('warning', 'El medidor que intentas eliminar ya no existe.');
        msp2MedidoresDeleteRedirectFromPost();
    }

    msp2SetFlash('success', 'El medidor fue eliminado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible eliminar el medidor. Revisa dependencias o la estructura de la base.');
}

msp2MedidoresDeleteRedirectFromPost();
