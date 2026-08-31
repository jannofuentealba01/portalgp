<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

msp2RequireAccess();

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
    [
        'id' => 'admin',
        'section_ids' => ['admin'],
    ],
    [
        'id' => 'facturacion',
        'section_ids' => ['facturacion'],
    ],
    [
        'id' => 'cobranza',
        'section_ids' => ['cobranza'],
    ],
    [
        'id' => 'reportes',
        'section_ids' => ['reportes'],
    ],
];
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP – Mercado San Pedro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        /* ── Hero ── */
        .mspv2-hero {
            padding: 0 0 20px;
            display: flex;
            align-items: center;
            position: relative;
        }

        .mspv2-hero-back {
            flex-shrink: 0;
            z-index: 1;
        }

        .mspv2-hero-title {
            font-size: 22px;
            font-weight: 700;
            color: var(--color-primary);
            margin: 0;
            line-height: 1.2;
            position: absolute;
            left: 0;
            right: 0;
            text-align: center;
            pointer-events: none;
        }

        /* ── Grid de secciones ── */
        .mspv2-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            width: 100%;
            max-width: 100%;
            margin: 0 auto;
        }

        .mspv2-column {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .box-container-full.mspv2-shell {
            width: min(1320px, 95vw) !important;
            max-width: min(1320px, 95vw) !important;
            padding: 20px !important;
        }

        /* ── Columna de sección ── */
        .mspv2-section {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(16,24,40,0.06);
            display: flex;
            flex-direction: column;
        }

        .mspv2-section-body {
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .mspv2-subsection {
            border: 1px solid var(--color-border);
            border-radius: 10px;
            background: #fff;
            overflow: hidden;
        }

        .mspv2-subsection-head {
            padding: 10px 12px;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8fafc;
        }

        .mspv2-subsection-head i {
            color: var(--color-primary);
            font-size: 14px;
        }

        .mspv2-subsection-label {
            margin: 0;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--color-primary);
        }

        /* Cabecera de sección — paleta enterprise */
        .mspv2-section-head {
            padding: 14px 18px;
            background: var(--color-primary-soft);
            border-bottom: 1px solid var(--color-border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .mspv2-section-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            background: rgba(11, 58, 110, 0.1);
            color: var(--color-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }

        .mspv2-section-info {
            flex: 1;
            min-width: 0;
        }

        .mspv2-section-label {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--color-primary);
            margin: 0;
        }

        /* Lista de items dentro de la sección */
        .mspv2-items {
            padding: 12px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
        }

        .mspv2-subsection .mspv2-items {
            padding: 10px;
        }

        /* ── Card de módulo ── */
        .mspv2-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            border-radius: 9px;
            border: 1px solid transparent;
            background: transparent;
            color: var(--color-text);
            text-decoration: none;
            transition: background 0.16s ease, border-color 0.16s ease, transform 0.16s ease, box-shadow 0.16s ease;
            cursor: pointer;
        }

        .mspv2-card:hover {
            background: var(--color-primary-soft);
            border-color: var(--color-border);
            color: var(--color-text);
            transform: translateX(3px);
            box-shadow: 0 3px 10px rgba(16,24,40,0.07);
        }

        .mspv2-card:focus-visible {
            outline: 3px solid var(--focus-ring);
            outline-offset: 2px;
        }

        .mspv2-card-icon {
            width: 32px;
            height: 32px;
            border-radius: 7px;
            background: var(--color-surface-soft);
            color: var(--color-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
            transition: background 0.16s ease;
        }

        .mspv2-card:hover .mspv2-card-icon {
            background: rgba(11, 58, 110, 0.12);
        }

        .mspv2-card-body {
            flex: 1;
            min-width: 0;
        }

        .mspv2-card-label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            margin: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mspv2-card-arrow {
            font-size: 13px;
            color: var(--color-text-muted);
            flex-shrink: 0;
            opacity: 0;
            transform: translateX(-4px);
            transition: opacity 0.16s ease, transform 0.16s ease;
        }

        .mspv2-card:hover .mspv2-card-arrow {
            opacity: 1;
            transform: translateX(0);
        }

        /* Estado deshabilitado */
        .mspv2-card.mspv2-card-disabled {
            opacity: 0.5;
            cursor: default;
            pointer-events: none;
        }

        /* ── Responsive ── */
        @media (max-width: 1280px) {
            .mspv2-grid {
                grid-template-columns: repeat(2, minmax(250px, 1fr));
                width: 100%;
            }
            .box-container-full.mspv2-shell {
                width: 96vw !important;
                max-width: 96vw !important;
            }
        }

        @media (max-width: 600px) {
            .mspv2-hero-title { font-size: 18px; }
            .mspv2-grid { grid-template-columns: 1fr; gap: 12px; }
            .box-container-full.mspv2-shell {
                width: 98vw !important;
                max-width: 98vw !important;
                padding: 14px !important;
            }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__) . '/templates/header.php'; ?>

<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-full mspv2-shell" data-tour="menu-root">

    <!-- Hero -->
    <div class="mspv2-hero" data-tour="menu-header">
        <div class="mspv2-hero-back">
            <a href="/portalgp/index.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al menú principal
            </a>
            <a href="<?php echo msp2Escape(msp2Url('ayuda/index.php')); ?>" class="btn btn-outline-info btn-sm ms-2">
                <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Ayuda
            </a>
            <button type="button" class="btn btn-outline-info btn-sm ms-2" id="mspStartMenuTour" data-tour="menu-help">
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.6/dist/driver.js.iife.js"></script>
<script src="<?php echo msp2Escape(msp2Url('assets/msp_tour_menu.js')); ?>"></script>
<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
</body>
</html>
