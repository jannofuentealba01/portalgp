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
    echo "<script>alert('No tienes permisos.'); window.location.href = '/herramientasmsp/index.php';</script>";
    exit();
}

// Obtener roles desde la base de datos
$stmt = $conn->prepare("SELECT id, nombre_rol FROM cr_roles");
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener los permisos
$permisos = $conn->query("SELECT * FROM cr_permisos")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Permisos</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 1200px;
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
        .btn {
            display: inline-block;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .btn-success {
            background-color: #28a745;
            color: white;
            border: none;
            cursor: pointer;
        }
        .btn-success:hover {
            background-color: #218838;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .table thead {
            background-color: #007bff;
            color: white;
        }
        .table th, .table td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
        }
        .table tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .table tbody tr:hover {
            background-color: #f1f1f1;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Gestión de Permisos</h2>
    <a href="crear_permiso.php" class="btn btn-success">Crear Nuevo Permiso</a>
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre del Permiso</th>
                <th>Descripción</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($permisos as $permiso): ?>
            <tr>
                <td><?= htmlspecialchars($permiso['id']) ?></td>
                <td><?= htmlspecialchars($permiso['nombre_permiso']) ?></td>
                <td><?= htmlspecialchars($permiso['descripcion']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'Templates/footer.php'; ?>
</body>
</html>
