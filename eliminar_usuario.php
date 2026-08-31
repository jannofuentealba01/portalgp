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
    echo "<script>alert('No tienes permiso para crear usuarios.'); window.location.href = '/herramientasmsp/index.php';</script>";
    exit();
}

// Obtener roles desde la base de datos
$stmt = $conn->prepare("SELECT id, nombre_rol FROM cr_roles");
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Habilitar la visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Verificar si el ID de usuario está presente en la URL
$userId = $_GET['id'] ?? null;

if (!$userId) {
    echo "<script>alert('ID de usuario no especificado.'); window.location.href = 'lista_usuarios.php';</script>";
    exit();
}

// Confirmar que el usuario realmente existe
$stmt = $conn->prepare("SELECT * FROM cr_usuarios WHERE Id = :id");
$stmt->bindParam(':id', $userId, PDO::PARAM_INT);
$stmt->execute();
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    echo "<script>alert('Usuario no encontrado.'); window.location.href = 'lista_usuarios.php';</script>";
    exit();
}

// Verificar si el usuario tiene registros asociados en cr_usuario_roles
$stmtCheckRoles = $conn->prepare("SELECT COUNT(*) FROM cr_usuario_roles WHERE usuario_id = :id");
$stmtCheckRoles->bindParam(':id', $userId, PDO::PARAM_INT);
$stmtCheckRoles->execute();
$registrosAsociados = $stmtCheckRoles->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !$registrosAsociados) {
    try {
        // Eliminar el usuario de la base de datos
        $stmtDeleteUser = $conn->prepare("DELETE FROM cr_usuarios WHERE Id = :id");
        $stmtDeleteUser->bindParam(':id', $userId, PDO::PARAM_INT);
        
        if ($stmtDeleteUser->execute()) {
            echo "<script>alert('Usuario eliminado correctamente.'); window.location.href = 'lista_usuarios.php';</script>";
        } else {
            echo "<script>alert('Error al eliminar el usuario.'); window.location.href = 'lista_usuarios.php';</script>";
        }
    } catch (PDOException $e) {
        echo "Error en la eliminación del usuario: " . $e->getMessage();
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eliminar Usuario</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f8fb;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            flex-direction: column;
        }
        .container {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 400px;
            text-align: center;
        }
        h2 {
            color: #333;
        }
        p {
            color: #666;
            margin-bottom: 20px;
        }
        button {
            padding: 10px 20px;
            background-color: #dc3545;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #c82333;
        }
        a {
            display: inline-block;
            margin-top: 15px;
            color: #007bff;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>

<div class="container">
    <h2>Eliminar Usuario</h2>
    <?php if ($registrosAsociados): ?>
        <p>No se puede eliminar al usuario <strong><?php echo htmlspecialchars($usuario['nombre_completo']); ?></strong> porque tiene registros asociados.</p>
        <a href="lista_usuarios.php">Volver a la lista de usuarios</a>
    <?php else: ?>
        <p>¿Estás seguro de que deseas eliminar al usuario <strong><?php echo htmlspecialchars($usuario['nombre_completo']); ?></strong>?</p>
        <form method="POST">
            <button type="submit">Eliminar Usuario</button>
        </form>
        <a href="lista_usuarios.php">Cancelar</a>
    <?php endif; ?>
</div>

<?php include 'Templates/footer.php'; ?>

</body>
</html>
