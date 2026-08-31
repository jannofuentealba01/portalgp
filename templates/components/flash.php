<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderFlash')) {
    /**
     * @param array{type?: string, message?: string}|null $flash
     */
    function gpRenderFlash(?array $flash): void
    {
        if (!is_array($flash)) {
            return;
        }

        $message = trim((string) ($flash['message'] ?? ''));
        if ($message === '') {
            return;
        }

        $type = (string) ($flash['type'] ?? 'info');
        $variant = gpComponentVariant($type);
        $icon = gpComponentIconForVariant($type);
        ?>
        <div class="alert alert-<?php echo gpComponentEscape($variant); ?> d-flex align-items-start gap-2" role="alert">
            <i class="bi <?php echo gpComponentEscape($icon); ?>" aria-hidden="true"></i>
            <div><?php echo gpComponentEscape($message); ?></div>
        </div>
        <?php
    }
}
