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
$nombreRubro = msp2NormalizeText($_POST['nombre_rubro'] ?? null);

if ($nombreRubro === '') {
    msp2SetFlash('warning', 'Debes ingresar un nombre de rubro.');
    msp2Redirect('rubros/index.php');
}

if (mb_strlen($nombreRubro) > 150) {
    msp2SetFlash('warning', 'El nombre del rubro no puede superar los 150 caracteres.');
    msp2Redirect('rubros/index.php');
}

try {
    if ($idRubro !== false && $idRubro !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_rubros WHERE id_rubro = :id_rubro');
        $existsStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
        $existsStmt->execute();

        if ((int) $existsStmt->fetchColumn() === 0) {
            msp2SetFlash('warning', 'El rubro que intentas editar ya no existe.');
            msp2Redirect('rubros/index.php');
        }
    }

    $duplicateSql = 'SELECT COUNT(*) FROM dbo.msp_rubros WHERE nombre_rubro = :nombre_rubro';

    if ($idRubro !== false && $idRubro !== null) {
        $duplicateSql .= ' AND id_rubro <> :id_rubro';
    }

    $duplicateStmt = $conn->prepare($duplicateSql);
    $duplicateStmt->bindValue(':nombre_rubro', $nombreRubro, PDO::PARAM_STR);

    if ($idRubro !== false && $idRubro !== null) {
        $duplicateStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
    }

    $duplicateStmt->execute();

    if ((int) $duplicateStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe un rubro con ese nombre.');
        msp2Redirect('rubros/index.php');
    }

    if ($idRubro !== false && $idRubro !== null) {
        $stmt = $conn->prepare('UPDATE dbo.msp_rubros SET nombre_rubro = :nombre_rubro WHERE id_rubro = :id_rubro');
        $stmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
        $stmt->bindValue(':nombre_rubro', $nombreRubro, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'El rubro fue actualizado correctamente.');
    } else {
        $stmt = $conn->prepare('INSERT INTO dbo.msp_rubros (nombre_rubro) VALUES (:nombre_rubro)');
        $stmt->bindValue(':nombre_rubro', $nombreRubro, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'El rubro fue creado correctamente.');
    }
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible guardar el rubro. Revisa la estructura de la base o intenta nuevamente.');
}

msp2Redirect('rubros/index.php');
