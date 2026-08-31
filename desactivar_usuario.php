<?php
require 'db.php';

$id_usuario = $_GET['id'];
$stmt = $conn->prepare("UPDATE usuarios SET estado = 0 WHERE id_usuario = :id_usuario");
$stmt->execute([':id_usuario' => $id_usuario]);

header("Location: gestionar_usuarios.php");
exit;
?>
