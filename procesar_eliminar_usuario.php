<?php
include 'db.php';

$id = $_POST['id'] ?? null;

if ($id) {
    try {
        // Eliminar el rol asociado
        $stmt_role = $conn->prepare("DELETE FROM cr_usuario_roles WHERE usuario_id = :id");
        $stmt_role->bindParam(':id', $id);
        $stmt_role->execute();

        // Eliminar el usuario
        $stmt_user = $conn->prepare("DELETE FROM cr_usuarios WHERE Id = :id");
        $stmt_user->bindParam(':id', $id);
        $stmt_user->execute();

        echo "<script>alert('Usuario eliminado exitosamente.'); window.location.href = 'editar_usuario.php';</script>";
    } catch (PDOException $e) {
        echo "<script>alert('No se pudo eliminar el usuario debido a que tiene registros de prestamos asociados.'); window.location.href = 'editar_usuario.php';</script>";
    }
} else {
    echo "<script>alert('ID de usuario no proporcionado.'); window.location.href = 'editar_usuario.php';</script>";
}
?>
