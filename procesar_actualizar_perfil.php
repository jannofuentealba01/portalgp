<?php
session_start();
include 'db.php';

// Obtener datos del formulario
$id = $_POST['id'] ?? null;
$nombre_completo = $_POST['nombre_completo'] ?? null;
$correo_electronico = $_POST['correo_electronico'] ?? null;
$password_actual = $_POST['password_actual'] ?? null;
$nueva_password = $_POST['nueva_password'] ?? null;

// Validación de ID
if (!$id) {
    $_SESSION['mensaje'] = "ID de usuario no especificado.";
    header("Location: profile.php");
    exit();
}

try {
    // Iniciar transacción
    $conn->beginTransaction();

    // Verificar la contraseña actual
    $stmt = $conn->prepare("SELECT password_hash FROM cr_usuarios WHERE Id = :id");
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password_actual, $user['password_hash'])) {
        $_SESSION['mensaje'] = "La contraseña actual es incorrecta.";
        header("Location: profile.php");
        exit();
    }

    // Actualizar datos personales si es administrador
    if ($_SESSION['usuario']['rol'] === 'Administrador' && $nombre_completo && $correo_electronico) {
        $sql = "UPDATE cr_usuarios 
                SET nombre_completo = :nombre_completo, correo_electronico = :correo_electronico 
                WHERE Id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':nombre_completo', $nombre_completo);
        $stmt->bindParam(':correo_electronico', $correo_electronico);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
    }

    // Actualizar contraseña si se proporciona una nueva
    if (!empty($nueva_password)) {
        $password_hash = password_hash($nueva_password, PASSWORD_BCRYPT);
        $sql_password = "UPDATE cr_usuarios SET password_hash = :password WHERE Id = :id";
        $stmt_password = $conn->prepare($sql_password);
        $stmt_password->bindParam(':password', $password_hash);
        $stmt_password->bindParam(':id', $id);
        $stmt_password->execute();
    }

    // Confirmar transacción
    $conn->commit();

    $_SESSION['mensaje'] = "Perfil actualizado exitosamente.";
    header("Location: profile.php");
    exit();

} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['mensaje'] = "Error al actualizar el perfil: " . $e->getMessage();
    header("Location: profile.php");
    exit();
}
?>
