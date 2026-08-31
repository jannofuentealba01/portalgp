<?php
include 'db.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    // Verificar que ambos campos estén completos
    if (!empty($username) && !empty($password)) {
        // Consultar el usuario en la base de datos
        $sql = "SELECT * FROM cr_usuarios WHERE UserName = :username";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Si se encuentra el usuario, validar estado y contraseña
        if ($user) {
            // Comprobar si el usuario está habilitado (estado_id == 1)
            if ((int)$user['estado_id'] === 1) {
                // Validar la contraseña
                if (password_verify($password, $user['password_hash'])) {
                    // Consulta para obtener los roles del usuario
                    $stmt_roles = $conn->prepare("
                        SELECT r.nombre_rol
                        FROM cr_usuario_roles ur
                        JOIN cr_roles r ON ur.rol_id = r.Id
                        WHERE ur.usuario_id = :usuario_id
                    ");
                    $stmt_roles->bindParam(':usuario_id', $user['Id'], PDO::PARAM_INT);
                    $stmt_roles->execute();
                    $roles = $stmt_roles->fetchAll(PDO::FETCH_COLUMN);

                    // Almacenar datos del usuario y roles en la sesión
                    $_SESSION['usuario'] = [
                        'id' => $user['id'],
                        'UserName' => $user['UserName'],
                        'nombre_completo' => $user['nombre_completo'],
                        'correo_electronico' => $user['correo_electronico'],
                        'roles' => $roles // Almacenar los roles como un array
                    ];

                    // Redireccionar al index.php
                    header('Location: index.php');
                    exit();
                } else {
                    echo "<script>alert('Contraseña incorrecta.'); window.location.href = 'login.php';</script>";
                }
            } else {
                echo "<script>alert('Usuario inhabilitado. Contacta al administrador.'); window.location.href = 'login.php';</script>";
            }
        } else {
            echo "<script>alert('Usuario no encontrado.'); window.location.href = 'login.php';</script>";
        }
    } else {
        echo "<script>alert('Por favor ingrese sus credenciales.'); window.location.href = 'login.php';</script>";
    }
}
?>
