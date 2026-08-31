<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

ctRequireAccess('CT');

$pageTitle = 'Catálogos';
$pageSubtitle = 'Mantenedores base del sistema';
$pageDescription = '';
$showMainMenuButton = false;

$items = [
    [
        'label' => 'Comunas',
        'caption' => 'Ubicaciones base para terrenos.',
        'icon' => 'bi-buildings',
        'href' => ctUrl('administracion/catalogo/comunas/index.php'),
        'enabled' => is_file(__DIR__ . '/comunas/index.php'),
    ],
    [
        'label' => 'Estados Prediales',
        'caption' => 'Estados operativos del terreno.',
        'icon' => 'bi-diagram-2',
        'href' => ctUrl('administracion/catalogo/estados_prediales/index.php'),
        'enabled' => is_file(__DIR__ . '/estados_prediales/index.php'),
    ],
    [
        'label' => 'Tipos Inmueble',
        'caption' => 'Clasificación de tipo de terreno.',
        'icon' => 'bi-house-gear',
        'href' => ctUrl('administracion/catalogo/tipos_inmueble/index.php'),
        'enabled' => is_file(__DIR__ . '/tipos_inmueble/index.php'),
    ],
    [
        'label' => 'Estados Comerciales',
        'caption' => 'Estados comerciales del terreno.',
        'icon' => 'bi-kanban',
        'href' => ctUrl('administracion/catalogo/estados_comerciales/index.php'),
        'enabled' => is_file(__DIR__ . '/estados_comerciales/index.php'),
    ],
    [
        'label' => 'Tipos Tasación',
        'caption' => 'Clasificación de tasaciones comerciales.',
        'icon' => 'bi-graph-up-arrow',
        'href' => ctUrl('administracion/catalogo/tipos_tasacion/index.php'),
        'enabled' => is_file(__DIR__ . '/tipos_tasacion/index.php'),
    ],
    [
        'label' => 'Entidades Financieras',
        'caption' => 'Bancos e instituciones de referencia comercial.',
        'icon' => 'bi-bank',
        'href' => ctUrl('administracion/catalogo/entidades_financieras/index.php'),
        'enabled' => is_file(__DIR__ . '/entidades_financieras/index.php'),
    ],
    [
        'label' => 'Usufructuario Tipo',
        'caption' => 'Tipos de titular para operaciones de usufructo.',
        'icon' => 'bi-person-badge',
        'href' => ctUrl('administracion/catalogo/usufructuario_tipos/index.php'),
        'enabled' => is_file(__DIR__ . '/usufructuario_tipos/index.php'),
    ],
];

ob_start();
?>
<style>
    .ct-catalogo-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .ct-catalogo-card {
        border: 1px solid #dbe3ed;
        border-radius: 12px;
        background: #fff;
        padding: 14px;
        text-decoration: none;
        color: inherit;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        transition: border-color .15s ease, background-color .15s ease;
    }
    .ct-catalogo-card:hover { border-color: #b8cce2; background: #f8fbff; color: inherit; }
    .ct-catalogo-icon {
        width: 34px; height: 34px; border-radius: 9px; display: inline-flex;
        align-items: center; justify-content: center; background: #edf6ff; color: #0f4a7d; flex-shrink: 0;
    }
    .ct-catalogo-label { margin: 0; font-weight: 700; font-size: 14px; color: #0f172a; }
    .ct-catalogo-caption { margin: 2px 0 0 0; color: #64748b; font-size: 12px; }
    @media (max-width: 900px) { .ct-catalogo-grid { grid-template-columns: 1fr; } }
</style>

<div class="ct-catalogo-grid">
    <?php foreach ($items as $item): ?>
        <?php if (!(bool) ($item['enabled'] ?? false)) { continue; } ?>
        <a class="ct-catalogo-card" href="<?php echo ctEscape((string) $item['href']); ?>">
            <span class="ct-catalogo-icon" aria-hidden="true"><i class="bi <?php echo ctEscape((string) $item['icon']); ?>"></i></span>
            <span>
                <span class="ct-catalogo-label"><?php echo ctEscape((string) $item['label']); ?></span>
                <span class="ct-catalogo-caption"><?php echo ctEscape((string) $item['caption']); ?></span>
            </span>
        </a>
    <?php endforeach; ?>
</div>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__, 2) . '/templates/module_shell.php';
