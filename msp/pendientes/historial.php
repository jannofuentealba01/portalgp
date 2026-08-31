<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/PendientesService.php';
require_once dirname(__DIR__) . '/services/PendientesGestionService.php';

msp2RequireAccess();

$clave = trim((string) ($_GET['clave'] ?? ''));
$meta = null;
$historial = [];
$error = null;
try {
    $gestion = new PendientesGestionService($conn);
    $meta = $gestion->obtener($clave);
    $historial = $gestion->historial($clave);
} catch (Throwable) {
    $error = 'No fue posible consultar el historial solicitado.';
}

$acciones = [
    'ASIGNAR' => 'Responsable asignado',
    'TOMAR_REVISION' => 'Pendiente tomado en revisión',
    'POSPONER' => 'Pendiente pospuesto',
    'REABRIR' => 'Pendiente reabierto',
    'COMENTAR' => 'Comentario registrado',
    'LIBERAR_ASIGNACION' => 'Asignación liberada',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial del pendiente</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__) . '/templates/header.php'; ?>
<main class="gp-main p-4">
    <div class="box-container-full mx-auto" style="max-width: 980px;">
        <a href="index.php" class="btn btn-outline-secondary btn-sm mb-3"><i class="bi bi-arrow-left me-1"></i>Volver a pendientes</a>
        <p class="section-kicker text-center">MSP / Pendientes</p>
        <h1 class="form-title text-center mb-4">Historial de gestión</h1>
        <?php if ($error !== null): ?><div class="alert alert-danger"><?php echo msp2Escape($error); ?></div><?php endif; ?>
        <?php if ($error === null): ?>
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><span class="small text-muted d-block">Estado</span><strong><?php echo msp2Escape(str_replace('_', ' ', (string) ($meta['estado_revision'] ?? 'ABIERTO'))); ?></strong></div>
                    <div class="col-md-4"><span class="small text-muted d-block">Responsable</span><strong><?php echo msp2Escape((string) ($meta['usuario_asignado'] ?? 'Sin asignar')); ?></strong></div>
                    <div class="col-md-4"><span class="small text-muted d-block">Pospuesto hasta</span><strong><?php echo msp2Escape((string) ($meta['pospuesto_hasta'] ?? '-')); ?></strong></div>
                    <?php if (!empty($meta['comentario_interno'])): ?><div class="col-12"><span class="small text-muted d-block">Último comentario</span><?php echo nl2br(msp2Escape((string) $meta['comentario_interno'])); ?></div><?php endif; ?>
                </div>
            </div></div>
            <div class="card border-0 shadow-sm"><div class="card-body">
                <h2 class="h5 mb-3">Acciones registradas</h2>
                <?php if ($historial === []): ?><p class="text-muted mb-0">Este pendiente todavía no tiene acciones de gestión.</p><?php endif; ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($historial as $evento): ?>
                        <div class="list-group-item px-0 py-3">
                            <div class="d-flex flex-wrap justify-content-between gap-2"><strong><?php echo msp2Escape($acciones[(string) $evento['accion']] ?? (string) $evento['accion']); ?></strong><time class="text-muted small"><?php echo msp2Escape(date('d-m-Y H:i', strtotime((string) $evento['fecha_registro']))); ?></time></div>
                            <div class="small text-muted">Por <?php echo msp2Escape((string) ($evento['usuario_accion'] ?? 'Usuario')); ?></div>
                            <?php if (!empty($evento['comentario'])): ?><div class="mt-2"><?php echo nl2br(msp2Escape((string) $evento['comentario'])); ?></div><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div></div>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
