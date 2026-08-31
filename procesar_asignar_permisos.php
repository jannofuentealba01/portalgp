<?php
include 'db.php'; // Conexión a la base de datos
session_start();

// Verificar si el usuario es administrador
if (!isset($_SESSION['usuario']['roles']) || !in_array('Administrador', $_SESSION['usuario']['roles'])) {
    echo "<p style='color: red; text-align: center;'>Acceso denegado: Solo los administradores pueden realizar esta acción.</p>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Borrar permisos anteriores
    $conn->exec("DELETE FROM cr_rol_permisos");

    // Procesar permisos seleccionados
    foreach ($_POST['permisos'] as $rol_id => $permisos) {
        foreach ($permisos as $permiso_id => $acciones) {
            $lectura = isset($acciones['lectura']) ? 1 : 0;
            $escritura = isset($acciones['escritura']) ? 1 : 0;
            $eliminacion = isset($acciones['eliminacion']) ? 1 : 0;

            $stmt = $conn->prepare("INSERT INTO cr_rol_permisos (rol_id, permiso_id, lectura, escritura, eliminacion) VALUES (:rol_id, :permiso_id, :lectura, :escritura, :eliminacion)");
            $stmt->bindParam(':rol_id', $rol_id, PDO::PARAM_INT);
            $stmt->bindParam(':permiso_id', $permiso_id, PDO::PARAM_INT);
            $stmt->bindParam(':lectura', $lectura, PDO::PARAM_INT);
            $stmt->bindParam(':escritura', $escritura, PDO::PARAM_INT);
            $stmt->bindParam(':eliminacion', $eliminacion, PDO::PARAM_INT);
            $stmt->execute();
        }
    }

    // Redireccionar de vuelta a la página de asignación de permisos
    header("Location: asignar_permisos.php?success=1");
    exit();
} else {
    echo "<p style='color: red; text-align: center;'>Error: Solicitud no válida.</p>";
    exit;
}
?>
