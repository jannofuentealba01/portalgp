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

// Procesar el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre_permiso = $_POST['nombre_permiso'];
    $descripcion = $_POST['descripcion'];

    $stmt = $conn->prepare("INSERT INTO cr_permisos (nombre_permiso, descripcion) VALUES (:nombre_permiso, :descripcion)");
    $stmt->bindParam(':nombre_permiso', $nombre_permiso);
    $stmt->bindParam(':descripcion', $descripcion);
    if ($stmt->execute()) {
        echo "<script>alert('Permiso creado exitosamente.'); window.location.href = 'listar_permisos.php';</script>";
    } else {
        echo "<script>alert('Error al crear el permiso.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Permiso</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
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
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group textarea {
            resize: none;
            height: 100px;
        }
        .btn {
            display: inline-block;
            padding: 10px 15px;
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
<div class="container">
    <h2>Crear Permiso</h2>
    <form method="POST">
        <div class="form-group">
            <label for="nombre_permiso">Nombre del Permiso:</label>
            <input type="text" id="nombre_permiso" name="nombre_permiso" required>
        </div>
        <div class="form-group">
            <label for="descripcion">Descripción:</label>
            <textarea id="descripcion" name="descripcion" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Guardar</button>
    </form>
</div>

<?php include 'Templates/footer.php'; ?>
</body>
</html>

