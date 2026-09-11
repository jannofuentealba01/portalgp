<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ajustes de cobranza | MSP</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <header class="msp-hub-header" data-gp-commandbar>
        <div>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
            </a>
        </div>
        <div>
            <h1>Ajustes de cobranza</h1>
        </div>
        <div></div>
    </header>

    <div class="msp-hub-grid">
        <article class="msp-hub-card">
            <div class="msp-hub-icon bg-danger-subtle text-danger"><i class="bi bi-plus-circle" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Cargos adicionales</h2>
                <p>Registra multas, reparaciones y otros conceptos extraordinarios asociados a una tienda.</p>
                <a class="btn btn-danger btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/cargos_extra.php')); ?>">Gestionar cargos</a>
            </div>
        </article>
        <article class="msp-hub-card">
            <div class="msp-hub-icon bg-success-subtle text-success"><i class="bi bi-wallet2" aria-hidden="true"></i></div>
            <div class="msp-hub-content">
                <h2>Saldo a favor</h2>
                <p>Administra excedentes, rebajas y aplicaciones disponibles para la tienda.</p>
                <a class="btn btn-success btn-sm" href="<?php echo msp2Escape(msp2Url('cobranza/saldo_favor_manual.php')); ?>">Gestionar saldos</a>
            </div>
        </article>
    </div>
</main>
<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html>
