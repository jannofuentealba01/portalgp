<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderFlashToast')) {
    /**
     * @param array{type?: string, message?: string, delay_ms?: int}|null $toastFlash
     */
    function gpRenderFlashToast(?array $toastFlash): void
    {
        if (!is_array($toastFlash)) {
            return;
        }

        $message = trim((string) ($toastFlash['message'] ?? ''));
        if ($message === '') {
            return;
        }

        $type = (string) ($toastFlash['type'] ?? 'info');
        $variant = gpComponentVariant($type);
        $icon = gpComponentIconForVariant($type);
        $delayMs = (int) ($toastFlash['delay_ms'] ?? 3000);
        if ($delayMs < 1000) {
            $delayMs = 3000;
        }
        ?>
        <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
            <div
                class="toast text-bg-<?php echo gpComponentEscape($variant); ?> border-0"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-gp-flash-toast="1"
                data-delay-ms="<?php echo gpComponentEscape($delayMs); ?>">
                <div class="d-flex align-items-center">
                    <div class="toast-body">
                        <i class="bi <?php echo gpComponentEscape($icon); ?> me-1" aria-hidden="true"></i><?php echo gpComponentEscape($message); ?>
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
                </div>
            </div>
        </div>
        <?php gpRenderFlashToastAssets(); ?>
        <?php
    }
}

if (!function_exists('gpRenderFlashToastAssets')) {
    function gpRenderFlashToastAssets(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;
        ?>
        <script>
        (() => {
            const init = () => {
                document.querySelectorAll('[data-gp-flash-toast]').forEach((toastEl) => {
                    if (!(toastEl instanceof HTMLElement) || toastEl.dataset.bound === '1') return;
                    toastEl.dataset.bound = '1';
                    const delay = Number.parseInt(toastEl.dataset.delayMs || '3000', 10);
                    if (window.bootstrap) {
                        window.bootstrap.Toast.getOrCreateInstance(toastEl, { delay }).show();
                    }
                });
            };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
            else init();
        })();
        </script>
        <?php
    }
}
