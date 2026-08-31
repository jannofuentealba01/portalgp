<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

msp2RequireAccess();

$menuItems = array_values(array_filter(
    msp2QuickAccessCatalogItems(),
    static function (array $item): bool {
        $href = (string) ($item['href'] ?? '');
        if (str_contains($href, '/reportes/trazabilidad.php')) {
            return false;
        }

        if (str_contains($href, '/catalogos/feriados.php')) {
            return false;
        }

        if (str_contains($href, '/cobros/reglas_cobro_auto.php')) {
            return false;
        }

        return true;
    }
));
?>
<!DOCTYPE html>
<html lang="es" class="module-menu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Catálogos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body>
<?php include dirname(__DIR__) . '/templates/header.php'; ?>
<main class="d-flex align-items-center py-4">
    <div class="menu-wrapper">
        <section class="menu-panel">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú MSP
                </a>
                <span class="section-kicker">MSP / Catálogo</span>
            </div>

            <h1 class="menu-title">Catálogos</h1>
            <p class="menu-subtitle">Datos base para la operación inicial de MSP.</p>

            <div class="row row-cols-1 row-cols-md-2 g-3">
                <?php foreach ($menuItems as $item): ?>
                    <div class="col">
                        <?php if ($item['enabled']): ?>
                            <a href="<?php echo msp2Escape($item['href']); ?>" class="module-link">
                                <i class="bi <?php echo msp2Escape($item['icon']); ?>" aria-hidden="true"></i>
                                <span>
                                    <strong class="d-block"><?php echo msp2Escape($item['label']); ?></strong>
                                    <small class="text-muted"><?php echo msp2Escape($item['caption']); ?></small>
                                </span>
                            </a>
                        <?php else: ?>
                            <div class="module-link module-link-disabled" aria-disabled="true">
                                <i class="bi <?php echo msp2Escape($item['icon']); ?>" aria-hidden="true"></i>
                                <span>
                                    <strong class="d-block"><?php echo msp2Escape($item['label']); ?></strong>
                                    <small class="text-muted"><?php echo msp2Escape($item['caption']); ?></small>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
</body>
</html>
