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
$descComuna = msp2NormalizeText($_POST['desc_comuna'] ?? null);

if ($descComuna === '') {
    msp2SetFlash('warning', 'Debes ingresar un nombre de comuna.');
    msp2Redirect('comunas/index.php');
}

if (mb_strlen($descComuna) > 150) {
    msp2SetFlash('warning', 'El nombre de la comuna no puede superar los 150 caracteres.');
    msp2Redirect('comunas/index.php');
}

try {
    if ($idComuna !== false && $idComuna !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_comunas WHERE id_comuna = :id_comuna');
        $existsStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
        $existsStmt->execute();

        if ((int) $existsStmt->fetchColumn() === 0) {
            msp2SetFlash('warning', 'La comuna que intentas editar ya no existe.');
            msp2Redirect('comunas/index.php');
        }
    }

    $duplicateSql = 'SELECT COUNT(*) FROM dbo.msp_comunas WHERE desc_comuna = :desc_comuna';

    if ($idComuna !== false && $idComuna !== null) {
        $duplicateSql .= ' AND id_comuna <> :id_comuna';
    }

    $duplicateStmt = $conn->prepare($duplicateSql);
    $duplicateStmt->bindValue(':desc_comuna', $descComuna, PDO::PARAM_STR);

    if ($idComuna !== false && $idComuna !== null) {
        $duplicateStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
    }

    $duplicateStmt->execute();

    if ((int) $duplicateStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe una comuna con ese nombre.');
        msp2Redirect('comunas/index.php');
    }

    if ($idComuna !== false && $idComuna !== null) {
        $stmt = $conn->prepare('UPDATE dbo.msp_comunas SET desc_comuna = :desc_comuna WHERE id_comuna = :id_comuna');
        $stmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
        $stmt->bindValue(':desc_comuna', $descComuna, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'La comuna fue actualizada correctamente.');
    } else {
        $stmt = $conn->prepare('INSERT INTO dbo.msp_comunas (desc_comuna) VALUES (:desc_comuna)');
        $stmt->bindValue(':desc_comuna', $descComuna, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'La comuna fue creada correctamente.');
    }
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible guardar la comuna. Revisa la estructura de la base o intenta nuevamente.');
}

msp2Redirect('comunas/index.php');
