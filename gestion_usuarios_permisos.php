<?php
include 'db.php';
include 'permisos.php';
include 'Templates/header.php';

if (!isset($_SESSION['usuario'])) {
    echo "<script>alert('Debes iniciar sesi&oacute;n.'); window.location.href = 'login.php';</script>";
    exit();
}

if (!tienePermiso($_SESSION['usuario']['id'], 'Administrar Usuarios')) {
    echo "<script>alert('No tienes permisos.'); window.location.href = '/portalgp/index.php';</script>";
    exit();
}

$stmt = $conn->prepare("SELECT id, nombre_rol FROM cr_roles");
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <title>Gesti&oacute;n de Usuarios y Permisos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        html, body {
            height: 100%;
        }
        body {
            display: flex;
            flex-direction: column;
            background-color: #f4f4f9;
            margin: 0;
        }
        main {
            flex: 1 0 auto;
        }
        footer {
            flex-shrink: 0;
        }
        h1 {
            color: #003399;
            font-size: 26px;
            margin-bottom: 20px;
        }
        .box-container {
            max-width: 700px;
            margin: auto;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 40px;
            text-align: center;
        }
        .btn-custom {
            background-color: #0288d1 !important;
            border-color: #0288d1 !important;
            font-weight: bold;
            color: #fff !important;
        }
        .btn-custom:hover {
            background-color: #0277bd !important;
            border-color: #0277bd !important;
        }
    </style>
</head>
<body>
<main class="flex-shrink-0 d-flex align-items-center justify-content-center p-4">
    <div class="box-container">
        <h1>Gesti&oacute;n de Usuarios y Permisos</h1>
        <div class="row row-cols-1 g-3">
            <div class="col">
                <a href="crear_usuario.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-person-plus-fill me-1"></i> Crear Usuario
                </a>
            </div>
            <div class="col">
                <a href="editar_usuario.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-pencil-square me-1"></i> Editar Usuario
                </a>
            </div>
            <div class="col">
                <a href="crear_permiso.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-shield-lock-fill me-1"></i> Crear Permiso
                </a>
            </div>
            <div class="col">
                <a href="asignar_permisos_roles.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-person-check-fill me-1"></i> Asignar Permisos a Roles
                </a>
            </div>
            <div class="col">
                <a href="listar_permisos.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-list-check me-1"></i> Listar Permisos
                </a>
            </div>
            <div class="col">
                <a href="editar_eliminar_permisos.php" class="btn btn-primary btn-custom w-100 shadow-sm">
                    <i class="bi bi-sliders2-vertical me-1"></i> Editar Permisos
                </a>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include 'Templates/footer.php'; ?>
</body>
</html>
