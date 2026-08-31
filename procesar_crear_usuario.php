<?php
include 'db.php';

$username = $_POST['username'] ?? '';
$nombre_completo = $_POST['nombre_completo'] ?? '';
$correo_electronico = $_POST['correo_electronico'] ?? '';
$password = $_POST['password'] ?? '';
$rol_id = $_POST['rol_id'] ?? '';
$estado = $_POST['estado'] ?? '';

// Validar que todos los campos estén presentes
if (!$username || !$nombre_completo || !$correo_electronico || !$password || !$rol_id || !$estado) {
    echo "<script>alert('Por favor completa todos los campos.'); window.history.back();</script>";
    exit;
}

try {
    // Verificar si el correo electrónico ya existe
    $stmt = $conn->prepare("SELECT COUNT(*) FROM cr_usuarios WHERE correo_electronico = :correo_electronico");
    $stmt->bindParam(':correo_electronico', $correo_electronico);
    $stmt->execute();
    $emailExists = $stmt->fetchColumn();

    if ($emailExists) {
        echo "<script>alert('El correo electrónico ya está registrado. Por favor utiliza otro.'); window.history.back();</script>";
        exit;
    }

    // Verificar si el nombre de usuario ya existe
    $stmt = $conn->prepare("SELECT COUNT(*) FROM cr_usuarios WHERE UserName = :username");
    $stmt->bindParam(':username', $username);
    $stmt->execute();
    $usernameExists = $stmt->fetchColumn();

    if ($usernameExists) {
        echo "<script>alert('El nombre de usuario ya está registrado. Por favor utiliza otro.'); window.history.back();</script>";
        exit;
    }

    // Insertar el nuevo usuario
    $password_hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("
        INSERT INTO cr_usuarios (UserName, nombre_completo, correo_electronico, password_hash, rol_id, estado_id)
        VALUES (:username, :nombre_completo, :correo_electronico, :password, :rol_id, :estado)
    ");
    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':nombre_completo', $nombre_completo);
    $stmt->bindParam(':correo_electronico', $correo_electronico);
    $stmt->bindParam(':password', $password_hash);
    $stmt->bindParam(':rol_id', $rol_id);
    $stmt->bindParam(':estado', $estado);
    $stmt->execute();

    echo "<script>alert('Usuario creado exitosamente.'); window.location.href = 'editar_usuario.php';</script>";
} catch (Exception $e) {
    echo "<script>alert('Error al crear el usuario: " . $e->getMessage() . "'); window.history.back();</script>";
}
?>
