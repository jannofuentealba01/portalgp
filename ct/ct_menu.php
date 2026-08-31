<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/config/menu.php';

ctRequireAccess('CT');

$flash = ctPullFlash();
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$usuario = $_SESSION['usuario'] ?? [];
$nombreUsuario = (string) (
    $usuario['nombre_completo']
    ?? $usuario['nombre']
    ?? $usuario['UserName']
    ?? $usuario['usuario']
    ?? 'Usuario'
);

$sections = ctMenuSections();
$hiddenSectionIds = [
    'contabilidad',
    'reportes',
];
$sections = array_values(array_filter(array_map(static function (array $section): array {
    $items = is_array($section['items'] ?? null) ? $section['items'] : [];
    $enabledItems = array_values(array_filter($items, static fn(array $item): bool => (bool) ($item['enabled'] ?? false)));
    $section['items'] = $enabledItems;
    return $section;
}, $sections), static fn(array $section): bool => (
    is_array($section['items'] ?? null)
    && $section['items'] !== []
    && !in_array((string) ($section['id'] ?? ''), $hiddenSectionIds, true)
)));
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CT | Gestión de Terrenos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .ctv-shell {
            width: min(1320px, 96vw);
            margin: 0 auto;
            padding: 20px;
        }

        .ctv-hero {
            border: 1px solid var(--color-border);
            border-radius: 14px;
            background: linear-gradient(135deg, #ffffff 0%, #f7fbff 100%);
            padding: 18px;
            box-shadow: var(--shadow-soft);
            margin-bottom: 14px;
        }

        .ctv-hero-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .ctv-hero h1 {
            margin: 0 0 4px 0;
            color: var(--color-primary);
            font-size: 24px;
        }

        .ctv-hero p {
            margin: 0;
            color: var(--color-text-muted);
            font-size: 13px;
        }

        .ctv-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .ctv-section {
            border: 1px solid var(--color-border);
            border-radius: 12px;
            overflow: hidden;
            background: #fff;
            box-shadow: var(--shadow-soft);
        }

        .ctv-section-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid var(--color-border);
            background: var(--color-primary-soft);
        }

        .ctv-section-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(11, 58, 110, 0.1);
            color: var(--color-primary);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            flex-shrink: 0;
        }

        .ctv-section-title {
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 12px;
            font-weight: 700;
            color: var(--color-primary);
        }

        .ctv-items {
            padding: 10px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .ctv-card {
            border: 1px solid #dbe3ed;
            border-radius: 10px;
            padding: 10px 12px;
            color: var(--color-text);
            text-decoration: none;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            transition: border-color 0.16s ease, background 0.16s ease, transform 0.16s ease;
        }

        .ctv-card:hover {
            border-color: #b7c9de;
            background: #f8fbff;
            color: var(--color-text);
            transform: translateX(2px);
        }

        .ctv-card-disabled {
            opacity: 0.62;
            pointer-events: none;
            background: #f8fafc;
        }

        .ctv-card-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            background: #eff6ff;
            color: #1e40af;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }

        .ctv-card-label {
            display: block;
            margin: 0;
            font-weight: 700;
            font-size: 13px;
            color: #0f172a;
        }

        .ctv-card-caption {
            display: block;
            margin: 1px 0 0 0;
            color: var(--color-text-muted);
            font-size: 11.5px;
            line-height: 1.35;
        }

        .ctv-chip {
            display: inline-flex;
            margin-top: 6px;
            padding: 1px 7px;
            border-radius: 999px;
            border: 1px solid #d6dde8;
            background: #f8fafc;
            color: #475569;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        @media (max-width: 1100px) {
            .ctv-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__) . '/templates/header.php'; ?>
<?php ctRenderCsrfAutoFieldScript(); ?>

<main class="gp-main d-flex align-items-center justify-content-center py-4">
    <div class="ctv-shell">
        <section class="ctv-hero">
            <div class="ctv-hero-top">
                <div>
                    <h1>CT | Gestión de Terrenos</h1>
                    <p>Base modular para cartera predial, movimientos, valor, tributos y reportes. Usuario: <?php echo ctEscape($nombreUsuario); ?></p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="/portalgp/index.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Menú principal
                    </a>
                    <a href="<?php echo ctEscape(ctUrl('docs/modulo_terrenos_diseno.md')); ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Diseño
                    </a>
                    <a href="<?php echo ctEscape(ctUrl('db/README.md')); ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-filetype-sql me-1" aria-hidden="true"></i>Scripts SQL
                    </a>
                </div>
            </div>
        </section>

        <?php ctRenderFlash($flash); ?>
        <?php include __DIR__ . '/templates/components/flash_toast.php'; ?>

        <div class="ctv-grid" role="navigation" aria-label="Módulos CT">
            <?php foreach ($sections as $section): ?>
                <?php
                $sectionId = (string) ($section['id'] ?? '');
                $sectionLabel = (string) ($section['label'] ?? 'Sección');
                $sectionIcon = (string) ($section['icon'] ?? 'bi-grid-3x3-gap-fill');
                $items = is_array($section['items'] ?? null) ? $section['items'] : [];
                ?>
                <section class="ctv-section" aria-labelledby="ct-section-<?php echo ctEscape($sectionId); ?>">
                    <header class="ctv-section-head">
                        <span class="ctv-section-icon" aria-hidden="true">
                            <i class="bi <?php echo ctEscape($sectionIcon); ?>"></i>
                        </span>
                        <h2 class="ctv-section-title" id="ct-section-<?php echo ctEscape($sectionId); ?>"><?php echo ctEscape($sectionLabel); ?></h2>
                    </header>
                    <div class="ctv-items">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $label = (string) ($item['label'] ?? 'Módulo');
                            $caption = (string) ($item['caption'] ?? '');
                            $icon = (string) ($item['icon'] ?? 'bi-grid');
                            $href = (string) ($item['href'] ?? '#');
                            ?>
                            <a href="<?php echo ctEscape($href); ?>" class="ctv-card">
                                <span class="ctv-card-icon" aria-hidden="true"><i class="bi <?php echo ctEscape($icon); ?>"></i></span>
                                <span>
                                    <strong class="ctv-card-label"><?php echo ctEscape($label); ?></strong>
                                    <span class="ctv-card-caption"><?php echo ctEscape($caption); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</main>

<?php include __DIR__ . '/templates/components/confirm_action_modal.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__) . '/templates/footer.php'; ?>
</body>
</html>
