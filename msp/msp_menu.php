<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

msp2RequireAccess();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$flash = msp2PullFlash();

$sections = msp2QuickAccessMenuSections();

$sectionsById = [];
foreach ($sections as $section) {
    $sectionId = (string) ($section['id'] ?? '');
    if ($sectionId === '') {
        continue;
    }
    $sectionsById[$sectionId] = $section;
}

$menuColumns = [
    ['id' => 'alta', 'section_ids' => ['alta']],
    ['id' => 'operacion', 'section_ids' => ['operacion']],
    ['id' => 'cobranza', 'section_ids' => ['cobranza']],
    ['id' => 'cierre', 'section_ids' => ['cierre']],
    ['id' => 'reportes', 'section_ids' => ['reportes']],
    ['id' => 'configuracion', 'section_ids' => ['configuracion']],
];
$menuColumns = array_values(array_filter(
    $menuColumns,
    static function (array $column) use ($sectionsById): bool {
        foreach ((array) ($column['section_ids'] ?? []) as $sectionId) {
            if (isset($sectionsById[(string) $sectionId])) {
                return true;
            }
        }
        return false;
    }
));
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP – Mercado San Pedro</title>
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-5.3.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/bootstrap-icons-1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/assets/vendor/driver.js-1.3.6/driver.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__) . '/templates/header.php'; ?>

<main class="gp-main d-flex align-items-start justify-content-center p-3 p-md-4">
    <div class="box-container-full mspv2-shell" data-tour="menu-root">

    <!-- Hero -->
    <div class="mspv2-hero" data-tour="menu-header" data-gp-commandbar>
        <div class="mspv2-hero-back">
            <a href="/portalgp/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú principal
            </a>
            <a href="<?php echo msp2Escape(msp2Url('ayuda/index.php')); ?>" class="btn btn-outline-secondary btn-sm ms-2">
                <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Ayuda
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm ms-2" id="mspStartMenuTour" data-tour="menu-help">
                <i class="bi bi-play-circle me-1" aria-hidden="true"></i>Ver tutorial
            </button>
        </div>
        <h1 class="mspv2-hero-title">Mercado San Pedro</h1>
    </div>

    <?php msp2RenderFlash($flash); ?>

    <!-- Grid de secciones -->
    <div class="mspv2-grid" role="navigation" aria-label="Módulos del sistema">
        <?php foreach ($menuColumns as $column): ?>
            <div class="mspv2-column" data-menu-col="<?= msp2Escape((string) ($column['id'] ?? '')) ?>">
                <?php foreach ((array) ($column['section_ids'] ?? []) as $sectionId): ?>
                    <?php
                    $sect = $sectionsById[(string) $sectionId] ?? null;
                    if (!is_array($sect)) {
                        continue;
                    }
                    ?>
                    <div
                        class="mspv2-section"
                        role="region"
                        aria-labelledby="sect-<?= htmlspecialchars((string) ($sect['id'] ?? '')) ?>"
                        data-tour="<?= msp2Escape('menu-' . (string) ($sect['id'] ?? 'section')) ?>"
                    >
                        <div class="mspv2-section-head">
                            <span class="mspv2-section-icon" aria-hidden="true">
                                <i class="bi <?= htmlspecialchars((string) ($sect['icon'] ?? 'bi-grid')) ?>"></i>
                            </span>
                            <div class="mspv2-section-info">
                                <p class="mspv2-section-label" id="sect-<?= htmlspecialchars((string) ($sect['id'] ?? '')) ?>">
                                    <?= htmlspecialchars((string) ($sect['label'] ?? 'Sección')) ?>
                                </p>
                            </div>
                        </div>

                        <div class="mspv2-items">
                            <?php foreach ((array) ($sect['items'] ?? []) as $item): ?>
                                <?php if ((bool) ($item['enabled'] ?? false)): ?>
                                    <a href="<?= msp2Escape((string) ($item['href'] ?? '#')) ?>" class="mspv2-card">
                                        <span class="mspv2-card-icon" aria-hidden="true">
                                            <i class="bi <?= msp2Escape((string) ($item['icon'] ?? 'bi-grid')) ?>"></i>
                                        </span>
                                        <span class="mspv2-card-body">
                                            <strong class="mspv2-card-label"><?= msp2Escape((string) ($item['label'] ?? 'Módulo')) ?></strong>
                                        </span>
                                        <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                                            <span class="badge rounded-pill text-bg-danger"><?= (int) $item['badge'] ?></span>
                                        <?php endif; ?>
                                        <i class="bi bi-chevron-right mspv2-card-arrow" aria-hidden="true"></i>
                                    </a>
                                <?php else: ?>
                                    <div class="mspv2-card mspv2-card-disabled" aria-disabled="true">
                                        <span class="mspv2-card-icon" aria-hidden="true">
                                            <i class="bi <?= msp2Escape((string) ($item['icon'] ?? 'bi-grid')) ?>"></i>
                                        </span>
                                        <span class="mspv2-card-body">
                                            <strong class="mspv2-card-label"><?= msp2Escape((string) ($item['label'] ?? 'Módulo')) ?></strong>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
    </div>
</main>

<script src="/portalgp/assets/vendor/bootstrap-5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="/portalgp/assets/vendor/driver.js-1.3.6/driver.js.iife.js"></script>
<script src="<?php echo msp2Escape(msp2Url('assets/msp_tour_menu.js')); ?>"></script>
<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
</body>
</html>
