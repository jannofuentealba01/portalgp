<?php
require_once 'db.php'; // Asegúrate de incluir la conexión a la base de datos

function tienePermiso($userId, $nombrePermiso) {
    global $conn; // Asegúrate de que la conexión sea accesible
    if (!$conn) {
        die("Error: No se pudo conectar a la base de datos.");
    }

    $query = "
        SELECT rp.*
        FROM cr_rol_permisos rp
        JOIN cr_permisos p ON rp.permiso_id = p.id
        JOIN cr_usuarios u ON rp.rol_id = u.rol_id
        WHERE u.id = :user_id AND p.nombre_permiso = :nombre_permiso
    ";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':nombre_permiso', $nombrePermiso, PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}
