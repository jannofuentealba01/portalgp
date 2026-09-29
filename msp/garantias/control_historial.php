<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess('MSP Reportes');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Control e historial de garantías | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <header class="msp-hub-header" data-gp-commandbar>
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('garantias/index.php')); ?>">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Garantías
            </a>
        </div>
        <div>
            <h1>Control e historial de garantías</h1>
        </div>
        <div></div>
    </header>

    <div class="msp-hub-grid">
        <article class="msp-hub-card">
            <div class="msp-hub-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Historial de garantías</h2>
                <p>Revisa el registro completo de recepciones, reservas, aplicaciones, devoluciones, ajustes y reversas, agrupado por arrendatario.</p>
                <a class="btn btn-primary btn-sm msp-hub-action" href="<?php echo msp2Escape(msp2Url('garantias/historial.php')); ?>">Ver historial completo</a>
            </div>
        </article>
        <article class="msp-hub-card">
            <div class="msp-hub-icon"><i class="bi bi-clipboard-data" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Control de garantías</h2>
                <p>Consulta los montos pactados, recibidos, disponibles, reservados, aplicados y devueltos, junto con sus alertas.</p>
                <a class="btn btn-primary btn-sm msp-hub-action" href="<?php echo msp2Escape(msp2Url('garantias/reporte.php')); ?>">Ingresar al control</a>
            </div>
        </article>
    </div>
</main>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
