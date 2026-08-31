<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderSectionHeaderAssets')) {
    function gpRenderSectionHeaderAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .gp-section-hero {
            position: relative;
            overflow: hidden;
            margin-bottom: 14px;
            padding: 14px 16px 12px;
            border: 1px solid rgba(11, 58, 110, 0.14);
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 18px rgba(16, 24, 40, 0.06);
        }

        .gp-section-hero::after {
            content: "";
            position: absolute;
            top: -86px;
            right: -76px;
            width: 170px;
            height: 170px;
            border-radius: 50%;
            background: none;
            pointer-events: none;
        }

        .gp-section-hero__inner {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 108px;
        }

        .gp-section-hero__copy {
            min-width: 0;
            width: 100%;
        }

        .gp-section-hero__kicker {
            margin: 0;
            text-align: center;
            color: #2563eb;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 700;
        }

        .gp-section-hero__title-row {
            margin-top: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .gp-section-hero__title {
            margin: 0;
            text-align: center;
            color: #0f172a;
            font-size: 32px;
            line-height: 1.08;
            font-weight: 700;
        }

        .gp-section-hero__desc {
            margin: 8px auto 0;
            max-width: 860px;
            text-align: center;
            color: var(--color-text-muted);
            font-size: 13px;
            line-height: 1.35;
        }

        .gp-section-hero__help {
            border: 0;
            background: transparent;
            color: #2563eb;
            line-height: 1;
            padding: 0;
            font-size: 18px;
        }

        .gp-section-hero__help:hover,
        .gp-section-hero__help:focus {
            color: #1d4ed8;
        }

        .gp-section-hero__back {
            white-space: nowrap;
            position: absolute;
            top: 14px;
            left: 16px;
            z-index: 2;
            margin: 0;
        }

        @media (max-width: 767.98px) {
            .gp-section-hero__title {
                font-size: 28px;
            }

            .gp-section-hero__back {
                top: 10px;
                left: 10px;
            }
        }
        </style>
        <script>
        (() => {
            const initTooltips = () => {
                if (!window.bootstrap || typeof window.bootstrap.Tooltip !== 'function') {
                    return;
                }
                document.querySelectorAll('[data-gp-section-hero] [data-bs-toggle="tooltip"]').forEach((node) => {
                    window.bootstrap.Tooltip.getOrCreateInstance(node);
                });
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initTooltips);
            } else {
                initTooltips();
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('gpRenderSectionHeader')) {
    /**
     * @param array{
     *   kicker?: string,
     *   title?: string,
     *   description?: string,
     *   back_url?: string,
     *   back_label?: string,
     *   back_icon?: string,
     *   help_text?: string,
     *   help_aria_label?: string,
     *   class?: string
     * } $options
     */
    function gpRenderSectionHeader(array $options = []): void
    {
        gpRenderSectionHeaderAssets();

        $kicker = trim((string) ($options['kicker'] ?? ''));
        $title = trim((string) ($options['title'] ?? ''));
        $description = trim((string) ($options['description'] ?? ''));
        $backUrl = trim((string) ($options['back_url'] ?? ''));
        $backLabel = trim((string) ($options['back_label'] ?? 'Volver'));
        $backIcon = trim((string) ($options['back_icon'] ?? 'bi-arrow-left'));
        $helpText = trim((string) ($options['help_text'] ?? ''));
        $helpAriaLabel = trim((string) ($options['help_aria_label'] ?? 'Información de la sección'));
        $className = trim((string) ($options['class'] ?? ''));

        $sectionClass = trim('gp-section-hero ' . $className);
        ?>
        <section class="<?php echo gpComponentEscape($sectionClass); ?>" data-gp-section-hero>
            <div class="gp-section-hero__inner">
                <?php if ($backUrl !== ''): ?>
                    <a href="<?php echo gpComponentEscape($backUrl); ?>" class="btn btn-outline-secondary btn-sm gp-section-hero__back">
                        <i class="bi <?php echo gpComponentEscape($backIcon); ?> me-1" aria-hidden="true"></i><?php echo gpComponentEscape($backLabel); ?>
                    </a>
                <?php endif; ?>
                <div class="gp-section-hero__copy">
                    <?php if ($kicker !== ''): ?>
                        <p class="gp-section-hero__kicker"><?php echo gpComponentEscape($kicker); ?></p>
                    <?php endif; ?>
                    <div class="gp-section-hero__title-row">
                        <h1 class="gp-section-hero__title"><?php echo gpComponentEscape($title); ?></h1>
                        <?php if ($helpText !== ''): ?>
                            <button
                                type="button"
                                class="gp-section-hero__help"
                                data-bs-toggle="tooltip"
                                data-bs-placement="bottom"
                                data-bs-title="<?php echo gpComponentEscape($helpText); ?>"
                                aria-label="<?php echo gpComponentEscape($helpAriaLabel); ?>">
                                <i class="bi bi-question-circle" aria-hidden="true"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($description !== ''): ?>
                        <p class="gp-section-hero__desc"><?php echo gpComponentEscape($description); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }
}
