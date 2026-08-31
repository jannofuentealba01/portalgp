<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderUndoToast')) {
    /**
     * @param array{
     *   message?: string,
     *   action?: string,
     *   button_label?: string,
     *   fields?: array<string, scalar|null>,
     *   delay_ms?: int
     * }|null $undoToast
     */
    function gpRenderUndoToast(?array $undoToast): void
    {
        if (!is_array($undoToast)) {
            return;
        }

        $message = trim((string) ($undoToast['message'] ?? ''));
        $action = trim((string) ($undoToast['action'] ?? ''));
        $buttonLabel = trim((string) ($undoToast['button_label'] ?? 'Deshacer'));
        $fields = is_array($undoToast['fields'] ?? null) ? $undoToast['fields'] : [];
        $delayMs = (int) ($undoToast['delay_ms'] ?? 5000);

        if ($message === '' || $action === '') {
            return;
        }
        ?>
        <div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1091;">
            <div
                class="toast text-bg-dark border-0"
                role="status"
                aria-live="polite"
                aria-atomic="true"
                data-gp-undo-toast="1"
                data-delay-ms="<?php echo gpComponentEscape($delayMs); ?>">
                <div class="d-flex align-items-center">
                    <div class="toast-body small"><?php echo gpComponentEscape($message); ?></div>
                    <form method="post" action="<?php echo gpComponentEscape($action); ?>" class="me-2">
                        <?php foreach ($fields as $fieldName => $fieldValue): ?>
                            <input type="hidden" name="<?php echo gpComponentEscape((string) $fieldName); ?>" value="<?php echo gpComponentEscape((string) $fieldValue); ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-light"><?php echo gpComponentEscape($buttonLabel !== '' ? $buttonLabel : 'Deshacer'); ?></button>
                    </form>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
                </div>
            </div>
        </div>
        <?php gpRenderUndoToastAssets(); ?>
        <?php
    }
}

if (!function_exists('gpRenderUndoToastAssets')) {
    function gpRenderUndoToastAssets(): void
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
                document.querySelectorAll('[data-gp-undo-toast]').forEach((toastEl) => {
                    if (!(toastEl instanceof HTMLElement) || toastEl.dataset.bound === '1') return;
                    toastEl.dataset.bound = '1';
                    const delay = Number.parseInt(toastEl.dataset.delayMs || '5000', 10);
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
