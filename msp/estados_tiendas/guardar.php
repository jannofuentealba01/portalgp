<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('estados_tiendas/index.php');
}

$idEstadoTienda = filter_input(INPUT_POST, 'id_estado_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$descEstado = msp2NormalizeText($_POST['desc_estado'] ?? null);

if ($descEstado === '') {
    msp2SetFlash('warning', 'Debes ingresar una descripción.');
    msp2Redirect('estados_tiendas/index.php');
}

if (mb_strlen($descEstado) > 100) {
    msp2SetFlash('warning', 'La descripción no puede superar los 100 caracteres.');
    msp2Redirect('estados_tiendas/index.php');
}

try {
    $existe = false;
    if ($idEstadoTienda !== false && $idEstadoTienda !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_estado_tiendas WHERE id_estado_tienda = :id_estado_tienda');
        $existsStmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
        $existsStmt->execute();
        $existe = (int) $existsStmt->fetchColumn() > 0;
    }

    $dupStmt = $conn->prepare(
        'SELECT COUNT(*) FROM dbo.msp_estado_tiendas WHERE desc_estado = :desc_estado' .
        ($existe ? ' AND id_estado_tienda <> :id_estado_tienda' : '')
    );
    $dupStmt->bindValue(':desc_estado', $descEstado, PDO::PARAM_STR);
    if ($existe) {
        $dupStmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
    }
    $dupStmt->execute();

    if ((int) $dupStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe un estado con esa descripción.');
        msp2Redirect('estados_tiendas/index.php');
    }

    if ($existe) {
        $stmt = $conn->prepare('UPDATE dbo.msp_estado_tiendas SET desc_estado = :desc_estado WHERE id_estado_tienda = :id_estado_tienda');
        $stmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
        $stmt->bindValue(':desc_estado', $descEstado, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'El estado fue actualizado correctamente.');
    } else {
        $stmt = $conn->prepare('INSERT INTO dbo.msp_estado_tiendas (desc_estado) VALUES (:desc_estado)');
        $stmt->bindValue(':desc_estado', $descEstado, PDO::PARAM_STR);
        $stmt->execute();

        msp2SetFlash('success', 'El estado fue creado correctamente.');
    }
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible guardar el estado. Revisa la estructura de la base o intenta nuevamente.');
}

msp2Redirect('estados_tiendas/index.php');
