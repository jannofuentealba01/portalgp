<?php
if (!empty($GLOBALS['msp2_quick_access_rendered'])) {
    return;
}

$GLOBALS['msp2_quick_access_rendered'] = true;
$quickAccessSections = msp2QuickAccessMenuSections();
$currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
?>
<button type="button" class="msp-quick-access-hot-edge" id="mspQuickAccessHotEdge" aria-label="Abrir accesos MSP" data-bs-toggle="offcanvas" data-bs-target="#mspQuickAccessOffcanvas" aria-controls="mspQuickAccessOffcanvas">
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
</button>

<div class="offcanvas offcanvas-start msp-quick-access" tabindex="-1" id="mspQuickAccessOffcanvas" aria-labelledby="mspQuickAccessTitle">
    <div class="offcanvas-header">
        <h2 class="msp-quick-access-title" id="mspQuickAccessTitle">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
            Accesos MSP
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
    </div>
    <div class="offcanvas-body msp-quick-access-body">
        <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="msp-quick-access-home">
            <i class="bi bi-house-door" aria-hidden="true"></i>
            Menú principal MSP
        </a>

        <?php foreach ($quickAccessSections as $section): ?>
            <?php
            $sectionLabel = (string) ($section['label'] ?? 'Sección');
            $sectionIcon = (string) ($section['icon'] ?? 'bi-grid');
            $items = (array) ($section['items'] ?? []);
            ?>
            <section class="msp-quick-access-section" aria-label="<?php echo msp2Escape($sectionLabel); ?>">
                <h3 class="msp-quick-access-section-title">
                    <i class="bi <?php echo msp2Escape($sectionIcon); ?>" aria-hidden="true"></i>
                    <?php echo msp2Escape($sectionLabel); ?>
                </h3>
                <div class="msp-quick-access-list">
                    <?php foreach ($items as $item): ?>
                        <?php
                        $label = (string) ($item['label'] ?? 'Módulo');
                        $icon = (string) ($item['icon'] ?? 'bi-grid');
                        $href = (string) ($item['href'] ?? '#');
                        $enabled = (bool) ($item['enabled'] ?? false);
                        $hrefPath = (string) (parse_url($href, PHP_URL_PATH) ?? '');
                        $isActive = $hrefPath !== '' && $hrefPath === $currentPath;
                        ?>
                        <?php if ($enabled): ?>
                            <a href="<?php echo msp2Escape($href); ?>" class="msp-quick-access-link<?php echo $isActive ? ' is-active' : ''; ?>">
                                <span class="msp-quick-access-item-icon" aria-hidden="true">
                                    <i class="bi <?php echo msp2Escape($icon); ?>"></i>
                                </span>
                                <span class="msp-quick-access-item-label"><?php echo msp2Escape($label); ?></span>
                                <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                                    <span class="badge rounded-pill text-bg-danger"><?php echo (int) $item['badge']; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <div class="msp-quick-access-disabled" aria-disabled="true">
                                <span class="msp-quick-access-item-icon" aria-hidden="true">
                                    <i class="bi <?php echo msp2Escape($icon); ?>"></i>
                                </span>
                                <span class="msp-quick-access-item-label"><?php echo msp2Escape($label); ?></span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</div>
