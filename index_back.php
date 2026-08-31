<?php
include 'db.php';
include 'Templates/header.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

$nombreCompleto = $_SESSION['usuario']['nombre_completo'];
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <title>Inicio - Portal Grupo Patagual</title>
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
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
            margin-bottom: 10px;
        }
        .welcome-box {
            max-width: 700px;
            margin: auto;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            padding: 40px;
            text-align: center;
        }
        .personal-welcome {
            font-size: 18px;
            color: #555;
            margin-bottom: 15px;
        }
        .menu-btn {
            background-color: #0288d1 !important;
            border-color: #0288d1 !important;
            color: white !important;
            font-weight: bold;
        }
        .menu-btn:hover {
            background-color: #0277bd !important;
            border-color: #0277bd !important;
        }
    </style>
</head>
<body>
    <main class="flex-shrink-0 d-flex align-items-center justify-content-center p-4">
        <div class="welcome-box">
            <h1>Portal Grupo Patagual</h1>
            <p class="personal-welcome">&iexcl;Bienvenido, <?php echo htmlspecialchars($nombreCompleto); ?>!</p>
            <p>Todo en un solo lugar</p>

            <div class="row row-cols-1 row-cols-md-2 g-3 mt-4">
                <div class="col">
                    <a href="contabilidad/contabilidad.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-calculator me-1"></i> Contabilidad
                    </a>
                </div>
                <div class="col">
                    <a href="ti/ti.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-pc me-1"></i> TI
                    </a>
                </div>
				 <div class="col">
                    <a href="msp/index.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-people-fill me-1"></i> Mercado San Pedro
                    </a>
                </div>
                <div class="col">
                    <a href="gestion_usuarios_permisos.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-people-fill me-1"></i> Gesti&oacute;n de Usuarios y Permisos
                    </a>
                </div>
                <div class="col">
                    <a href="qbo/index.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-filetype-csv me-1"></i> CSV a QBO
                    </a>
					
                </div>
                <div class="col">
                    <a href="ppto/menu.php" class="btn btn-primary menu-btn w-100 shadow-sm">
                        <i class="bi bi-clipboard-data me-1"></i> Presupuestos
                    </a>
                </div>
            </div>
			
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'Templates/footer.php'; ?>
</body>
</html>
