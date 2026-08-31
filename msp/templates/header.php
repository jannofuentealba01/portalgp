<?php
$usuario = $_SESSION['usuario'] ?? [];
$nombreUsuario = $usuario['nombre'] ?? $usuario['usuario'] ?? $usuario['email'] ?? null;

msp2RenderCsrfAutoFieldScript();
?>
<header class="msp-header border-bottom bg-white sticky-top">
    <div class="container-fluid py-2 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-2">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Menú
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="offcanvas" data-bs-target="#mspQuickAccessOffcanvas" aria-controls="mspQuickAccessOffcanvas">
                <i class="bi bi-list-ul me-1" aria-hidden="true"></i>Accesos
            </button>
            <span class="fw-semibold">Mercado San Pedro</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($nombreUsuario): ?>
                <span class="small text-muted">Usuario: <?php echo msp2Escape((string) $nombreUsuario); ?></span>
            <?php endif; ?>
            <a href="<?php echo msp2Escape(msp2Url('ayuda/index.php')); ?>" class="btn btn-sm btn-outline-info" title="Ayuda">
                <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Ayuda
            </a>
            <a href="<?php echo msp2Escape(msp2Url('index.php')); ?>" class="btn btn-sm btn-outline-secondary">Inicio</a>
        </div>
    </div>
</header>
