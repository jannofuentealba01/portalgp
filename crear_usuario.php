<?php
include 'db.php';
include 'permisos.php';
include 'Templates/header.php';

// Verificar si el usuario ha iniciado sesión
if (!isset($_SESSION['usuario'])) {
    echo "<script>alert('Debes iniciar sesión.'); window.location.href = 'login.php';</script>";
    exit();
}



// Validar si el usuario tiene el permiso necesario
if (!tienePermiso($_SESSION['usuario']['id'], 'Administrar Usuarios')) {
    echo "<script>alert('No tienes permisos.'); window.location.href = '/portalgp/index.php';</script>";
    exit();
}

// Obtener roles desde la base de datos
$stmt = $conn->prepare("SELECT id, nombre_rol FROM cr_roles");
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Usuario</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .header {
            background-color: #007bff;
            padding: 10px 20px;
            color: white;
            text-align: right;
        }

        .container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
            margin-top: 40px;
        }

        .footer {
            background-color: #007bff;
            color: white;
            text-align: center;
            padding: 10px;
            font-size: 14px;
        }

        .form-container {
            width: 400px;
            background-color: #ffffff;
            padding: 60px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .header-button {
            position: absolute;
            top: -30px;
            right: 0;
            background-color: #28a745;
            color: white;
            padding: 10px 15px;
            text-decoration: none;
            font-weight: bold;
            border-radius: 5px;
        }

        .form-container h1 {
            text-align: center;
            color: #333;
        }

        .form-container label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }

        .form-container input,
        .form-container select,
        .form-container button {
            width: 100%;
            padding: 10px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ddd;
        }

        .form-container button {
            background-color: #007bff;
            color: white;
            font-weight: bold;
            border: none;
            cursor: pointer;
        }

        .form-container button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

    <div class="container">
        <a href="editar_usuario.php" class="header-button">Ver todos los usuarios</a>

        <div class="form-container">
            <h1>Crear Usuario</h1>
            <form action="procesar_crear_usuario.php" method="POST">
                <label>Nombre de Usuario:</label>
                <input type="text" name="username" required>

                <label>Nombre Completo:</label>
                <input type="text" name="nombre_completo" required>

                <label>Correo Electrónico:</label>
                <input type="email" name="correo_electronico" required>

                <label>Contraseña:</label>
                <input type="password" name="password" required>

                <label>Rol:</label>
                <select name="rol_id" required>
                    <?php foreach ($roles as $rol): ?>
                        <option value="<?= $rol['id'] ?>"><?= $rol['nombre_rol'] ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Estado:</label>
                <select name="estado" required>
                    <option value="1">Habilitado</option>
                    <option value="2">Inhabilitado</option>
                </select>

                <button type="submit">Crear Usuario</button>
            </form>
        </div>
    </div>

</body>
</html>
<?php include 'Templates/footer.php'; ?>
