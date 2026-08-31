<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verificar que todos los datos necesarios estén presentes
    if (!isset($_POST['id'], $_POST['nombre_completo'], $_POST['correo_electronico'], $_POST['estado_id'], $_POST['rol_id'])) {
        echo "<script>alert('Error: Datos incompletos para la actualización.'); window.history.back();</script>";
        exit();
    }

    // Obtener datos del formulario
    $id = intval($_POST['id']);
    $nombre_completo = trim($_POST['nombre_completo']);
    $correo_electronico = trim($_POST['correo_electronico']);
    $estado_id = intval($_POST['estado_id']);
    $rol_id = intval($_POST['rol_id']);
    $password_hash = empty($_POST['password_hash']) ? null : password_hash(trim($_POST['password_hash']), PASSWORD_DEFAULT);

    try {
        // Verificar si el usuario existe
        $sqlCheck = "SELECT * FROM cr_usuarios WHERE id = :id";
        $stmtCheck = $conn->prepare($sqlCheck);
        $stmtCheck->bindParam(':id', $id, PDO::PARAM_INT);
        $stmtCheck->execute();

        if ($stmtCheck->rowCount() === 0) {
            echo "<script>alert('Error: Usuario no encontrado.'); window.history.back();</script>";
            exit();
        }

        // Preparar consulta de actualización
        $sql = "UPDATE cr_usuarios SET 
                    nombre_completo = :nombre_completo,
                    correo_electronico = :correo_electronico,
                    estado_id = :estado_id,
                    rol_id = :rol_id";

        // Si se proporciona una nueva contraseña, actualizarla también
        if (!is_null($password_hash)) {
            $sql .= ", password_hash = :password_hash";
        }

        $sql .= " WHERE id = :id";

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':nombre_completo', $nombre_completo);
        $stmt->bindParam(':correo_electronico', $correo_electronico);
        $stmt->bindParam(':estado_id', $estado_id, PDO::PARAM_INT);
        $stmt->bindParam(':rol_id', $rol_id, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        if (!is_null($password_hash)) {
            $stmt->bindParam(':password_hash', $password_hash);
        }

        $stmt->execute();

        // Redirigir con un mensaje visual
        echo "<script>alert('Usuario actualizado correctamente.'); window.location.href = 'editar_usuario.php';</script>";
        exit();
    } catch (PDOException $e) {
        // Manejo de errores
        error_log("Error al actualizar el usuario: " . $e->getMessage());
        echo "<script>alert('Error: Ocurrió un error al actualizar el usuario.'); window.history.back();</script>";
        exit();
    }
} else {
    // Si se intenta acceder al archivo directamente
    echo "<script>alert('Error: Acceso no permitido.'); window.history.back();</script>";
    exit();
}
?>
