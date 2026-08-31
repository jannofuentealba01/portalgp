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
<html lang="es" class="module-menu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - Portal Grupo Patagual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <main class="flex-shrink-0 d-flex align-items-center">
        <div class="menu-wrapper">
            <section class="menu-panel">
                <h1 class="menu-title">Portal Grupo Patagual</h1>
                <p class="menu-subtitle">Bienvenido, <?php echo htmlspecialchars($nombreCompleto, ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="menu-caption">Accesos principales centralizados en un solo lugar.</p>

                <div class="row row-cols-1 row-cols-md-2 g-3">
                <div class="col">
                    <a href="contabilidad/contabilidad.php" class="module-link">
                        <i class="bi bi-calculator" aria-hidden="true"></i>
                        <span>Contabilidad</span>
                    </a>
                </div>
                <div class="col">
                    <a href="ti/ti.php" class="module-link">
                        <i class="bi bi-pc" aria-hidden="true"></i>
                        <span>TI</span>
                    </a>
                </div>
                 <div class="col">
                    <a href="msp/index.php" class="module-link">
                        <i class="bi bi-shop" aria-hidden="true"></i>
                        <span>Mercado San Pedro</span>
                    </a>
                </div>
                <div class="col">
                    <a href="ct/index.php" class="module-link">
                        <i class="bi bi-geo-alt" aria-hidden="true"></i>
                        <span>CT Terrenos</span>
                    </a>
                </div>
                <div class="col">
                    <a href="sistema/gestion/index.php" class="module-link">
                        <i class="bi bi-people-fill" aria-hidden="true"></i>
                        <span>Gesti&oacute;n del Sistema</span>
                    </a>
                </div>
                <div class="col">
                    <a href="qbo/index.php" class="module-link">
                        <i class="bi bi-filetype-csv" aria-hidden="true"></i>
                        <span>CSV a QBO</span>
                    </a>					
                </div>
                <div class="col">
                    <a href="ppto/menu.php" class="module-link">
                        <i class="bi bi-clipboard-data" aria-hidden="true"></i>
                        <span>Presupuestos</span>
                    </a>
                </div>
            </div>

            </section>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'Templates/footer.php'; ?>
</body>
</html>
