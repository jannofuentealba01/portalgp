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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .msp-hub-header { display:grid; grid-template-columns:1fr auto 1fr; align-items:center; gap:12px; margin-bottom:16px; }
        .msp-hub-header h1 { margin:0; color:var(--color-primary); font-size:clamp(1.45rem, 2vw, 2rem); text-align:center; }
        .msp-hub-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; }
        .msp-hub-card { border:1px solid var(--color-border); border-radius:12px; background:var(--color-surface); box-shadow:0 2px 8px rgba(16,24,40,.06); padding:18px; display:flex; align-items:center; gap:16px; min-width:0; }
        .msp-hub-icon { width:48px; height:48px; border-radius:11px; display:grid; place-items:center; flex:0 0 auto; font-size:1.35rem; }
        .msp-hub-content { min-width:0; flex:1; }
        .msp-hub-content h2 { margin:0 0 4px; font-size:1.12rem; }
        .msp-hub-content p { margin:0 0 10px; color:var(--color-text-muted); font-size:.92rem; line-height:1.35; }
        @media (max-width:767.98px) {
            .msp-hub-header { grid-template-columns:1fr; }
            .msp-hub-header h1 { grid-row:1; }
            .msp-hub-header > :first-child { grid-row:2; justify-self:start; }
            .msp-hub-grid { grid-template-columns:1fr; }
            .msp-hub-card { align-items:flex-start; padding:14px; }
        }
    </style>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
