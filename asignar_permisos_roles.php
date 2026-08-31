<?php
// Incluir el archivo de encabezado y conexión a la base de datos
include 'db.php';
include 'permisos.php';
include 'Templates/header.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario'])) {
    echo "<script>alert('Debes iniciar sesión.'); window.location.href = 'login.php';</script>";
    exit();
}

// Validar si el usuario tiene el permiso necesario
if (!tienePermiso($_SESSION['usuario']['id'], 'Permisos')) {
    echo "<script>alert('No tienes permisos.'); window.location.href = '/portalgp/index.php';</script>";
    exit();
}

// Obtener roles y permisos
$roles = $conn->query("SELECT * FROM cr_roles")->fetchAll(PDO::FETCH_ASSOC);
$permisos = $conn->query("SELECT * FROM cr_permisos")->fetchAll(PDO::FETCH_ASSOC);

// Manejar el envío del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rol_id = $_POST['rol_id'];
    $permisos_seleccionados = $_POST['permisos'] ?? [];

    try {
        $conn->beginTransaction();

        // Eliminar todos los permisos actuales del rol
        $stmt_delete = $conn->prepare("DELETE FROM cr_rol_permisos WHERE rol_id = :rol_id");
        $stmt_delete->bindParam(':rol_id', $rol_id);
        $stmt_delete->execute();

        // Insertar los permisos seleccionados
        $stmt_insert = $conn->prepare("INSERT INTO cr_rol_permisos (rol_id, permiso_id) VALUES (:rol_id, :permiso_id)");
        $stmt_insert->bindParam(':rol_id', $rol_id);

        foreach ($permisos_seleccionados as $permiso_id) {
            $stmt_insert->bindParam(':permiso_id', $permiso_id);
            $stmt_insert->execute();
        }

        $conn->commit();
        echo "<script>alert('Permisos actualizados exitosamente.'); window.location.href = 'asignar_permisos_roles.php';</script>";
    } catch (Exception $e) {
        $conn->rollBack();
        echo "<script>alert('Error al actualizar los permisos: " . $e->getMessage() . "');</script>";
    }
}

// Manejar la selección de rol
$rol_seleccionado = $_GET['rol_id'] ?? $roles[0]['id'] ?? null;
$permisos_asignados = [];
if ($rol_seleccionado) {
    $stmt = $conn->prepare("SELECT permiso_id FROM cr_rol_permisos WHERE rol_id = :rol_id");
    $stmt->bindParam(':rol_id', $rol_seleccionado);
    $stmt->execute();
    $permisos_asignados = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asignar Permisos a Roles</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 800px;
            margin: 50px auto;
            background-color: #ffffff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        .container h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        .form-group select, .form-group input[type="checkbox"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .checkbox-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 4px;
            background-color: #f9f9f9;
        }
        .checkbox-item {
            margin-bottom: 10px;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 10px;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            color: white;
            background-color: #007bff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 15px;
        }
        .btn:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Asignar Permisos a Roles</h2>
    <form method="GET" action="">
        <div class="form-group">
            <label for="rol_id">Seleccionar Rol:</label>
            <select id="rol_id" name="rol_id" onchange="this.form.submit()">
                <?php foreach ($roles as $rol): ?>
                <option value="<?= $rol['id'] ?>" <?= $rol['id'] == $rol_seleccionado ? 'selected' : '' ?>><?= htmlspecialchars($rol['nombre_rol']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>

    <form method="POST">
        <input type="hidden" name="rol_id" value="<?= htmlspecialchars($rol_seleccionado) ?>">
        <div class="form-group">
            <label>Seleccionar Permisos:</label>
            <div class="checkbox-list">
                <?php foreach ($permisos as $permiso): ?>
                <div class="checkbox-item">
                    <label>
                        <input type="checkbox" name="permisos[]" value="<?= $permiso['id'] ?>" <?= in_array($permiso['id'], $permisos_asignados) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($permiso['nombre_permiso']) ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn">Guardar Cambios</button>
    </form>
</div>
<?php include 'Templates/footer.php'; ?>
</body>
</html>
