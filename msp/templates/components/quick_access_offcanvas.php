<?php
if (!empty($GLOBALS['msp2_quick_access_rendered'])) {
    return;
}

$GLOBALS['msp2_quick_access_rendered'] = true;
$quickAccessSections = msp2QuickAccessMenuSections();
$currentPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
?>
<style>
    .msp-quick-access {
        --msp-qa-icon-bg: rgba(11, 58, 110, 0.09);
        --msp-qa-hover-bg: #f4f7fb;
        --msp-qa-section-bg: #f8fafc;
        --bs-offcanvas-width: min(380px, 94vw);
        width: min(380px, 94vw) !important;
        max-height: 100vh;
    }

    .msp-quick-access-hot-edge {
        position: fixed;
        top: 0;
        left: 0;
        width: 34px;
        height: 100vh;
        z-index: 1038;
        padding: 0;
        border: 0;
        background: transparent;
        color: var(--color-primary, #0b3a6e);
        cursor: pointer;
    }

    .msp-quick-access-hot-edge i {
        position: absolute;
        top: 50%;
        left: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 66px;
        border: 1px solid rgba(11, 58, 110, 0.2);
        border-left: 0;
        border-radius: 0 10px 10px 0;
        background: rgba(255, 255, 255, 0.92);
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.12);
        font-size: 18px;
        line-height: 1;
        transform: translateY(-50%);
        transition: background 0.16s ease, border-color 0.16s ease, color 0.16s ease, transform 0.16s ease;
    }

    .msp-quick-access-hot-edge::after {
        content: "";
        position: absolute;
        top: 30%;
        left: 0;
        width: 4px;
        height: 40%;
        border-radius: 0 6px 6px 0;
        background: rgba(11, 58, 110, 0.18);
        opacity: 0;
        transition: opacity 0.16s ease, width 0.16s ease;
    }

    .msp-quick-access-hot-edge:hover::after,
    .msp-quick-access-hot-edge:focus-visible::after {
        width: 7px;
        opacity: 1;
    }

    .msp-quick-access-hot-edge:hover i,
    .msp-quick-access-hot-edge:focus-visible i {
        background: #fff;
        border-color: rgba(11, 58, 110, 0.35);
        color: var(--color-primary-hover, #0a325f);
        transform: translateY(-50%) translateX(2px);
    }

    .msp-quick-access .offcanvas-header {
        border-bottom: 1px solid var(--color-border, #d7dee8);
        min-height: 64px;
        padding: 12px 16px;
    }

    .msp-quick-access-title {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0;
        color: var(--color-primary, #0b3a6e);
        font-size: 18px;
        font-weight: 700;
    }

    .msp-quick-access-title i {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: var(--msp-qa-icon-bg);
        font-size: 15px;
    }

    .msp-quick-access-body {
        flex: 1 1 auto !important;
        display: flex;
        flex-direction: column;
        gap: 10px;
        min-height: 0;
        max-height: calc(100vh - 64px);
        padding: 12px 12px 16px;
        background: var(--color-bg, #f5f7fb);
        overflow-x: hidden;
        overflow-y: scroll !important;
        overscroll-behavior: contain;
        scrollbar-gutter: stable;
    }

    .msp-quick-access-home {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        gap: 10px;
        min-height: 40px;
        padding: 8px 10px;
        border: 1px solid var(--color-border, #d7dee8);
        border-radius: 8px;
        background: #fff;
        color: var(--color-primary, #0b3a6e);
        font-size: 14px;
        font-weight: 700;
        text-decoration: none;
    }

    .msp-quick-access-home:hover {
        background: var(--msp-qa-hover-bg);
        color: var(--color-primary-hover, #0a325f);
    }

    .msp-quick-access-section {
        flex: 0 0 auto;
        border: 1px solid var(--color-border, #d7dee8);
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
    }

    .msp-quick-access-section-title {
        display: flex;
        align-items: center;
        gap: 9px;
        margin: 0;
        min-height: 36px;
        padding: 8px 10px;
        background: var(--msp-qa-section-bg);
        border-bottom: 1px solid var(--color-border, #d7dee8);
        color: var(--color-primary, #0b3a6e);
        font-size: 11.5px;
        font-weight: 800;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .msp-quick-access-list {
        display: flex;
        flex-direction: column;
        gap: 2px;
        padding: 6px;
    }

    .msp-quick-access-link,
    .msp-quick-access-disabled {
        display: flex;
        align-items: center;
        gap: 8px;
        min-height: 34px;
        padding: 5px 8px;
        border-radius: 6px;
        color: var(--color-text, #1f2937);
        text-decoration: none;
    }

    .msp-quick-access-link:hover,
    .msp-quick-access-link.is-active {
        background: var(--msp-qa-hover-bg);
        color: var(--color-primary, #0b3a6e);
    }

    .msp-quick-access-link:focus-visible,
    .msp-quick-access-home:focus-visible {
        outline: 3px solid var(--focus-ring, rgba(11, 58, 110, 0.3));
        outline-offset: 2px;
    }

    .msp-quick-access-item-icon {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        background: var(--msp-qa-icon-bg);
        color: var(--color-primary, #0b3a6e);
        font-size: 13px;
    }

    .msp-quick-access-item-label {
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-size: 13.5px;
        font-weight: 600;
    }

    .msp-quick-access-disabled {
        opacity: 0.48;
    }

    @media (hover: none), (pointer: coarse), (max-width: 768px) {
        .msp-quick-access-hot-edge {
            display: none;
        }
    }
</style>

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

<script>
(function () {
    let attempts = 0;

    const init = function () {
        const hotEdge = document.getElementById('mspQuickAccessHotEdge');
        const panel = document.getElementById('mspQuickAccessOffcanvas');
        if (!hotEdge || !panel) {
            return;
        }

        if (!window.bootstrap || !window.bootstrap.Offcanvas) {
            attempts += 1;
            if (attempts <= 40) {
                window.setTimeout(init, 100);
            }
            return;
        }

        const supportsHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
        if (!supportsHover || hotEdge.dataset.mspQuickAccessReady === '1') {
            return;
        }

        hotEdge.dataset.mspQuickAccessReady = '1';
        let openTimer = 0;
        let closeTimer = 0;
        const openPanel = function () {
            if (panel.classList.contains('show')) {
                return;
            }
            window.bootstrap.Offcanvas.getOrCreateInstance(panel).show();
        };
        const scheduleOpen = function () {
            window.clearTimeout(openTimer);
            openTimer = window.setTimeout(openPanel, 180);
        };
        const cancelOpen = function () {
            window.clearTimeout(openTimer);
        };
        const closePanel = function () {
            if (!panel.classList.contains('show') || panel.matches(':hover') || hotEdge.matches(':hover')) {
                return;
            }
            window.bootstrap.Offcanvas.getOrCreateInstance(panel).hide();
        };
        const scheduleClose = function () {
            window.clearTimeout(closeTimer);
            closeTimer = window.setTimeout(closePanel, 260);
        };
        const cancelClose = function () {
            window.clearTimeout(closeTimer);
        };

        hotEdge.addEventListener('mouseenter', scheduleOpen);
        hotEdge.addEventListener('mouseleave', cancelOpen);
        hotEdge.addEventListener('focus', scheduleOpen);
        hotEdge.addEventListener('blur', cancelOpen);
        panel.addEventListener('mouseenter', cancelClose);
        panel.addEventListener('mouseleave', scheduleClose);
        panel.addEventListener('hidden.bs.offcanvas', function () {
            cancelOpen();
            cancelClose();
        });
    };

    init();
})();
</script>
