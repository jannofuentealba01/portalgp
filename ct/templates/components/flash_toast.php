<?php
declare(strict_types=1);

$toastFlash = $toastFlash ?? null;
if (!is_array($toastFlash)) {
    return;
}

$type = trim((string) ($toastFlash['type'] ?? ''));
$message = trim((string) ($toastFlash['message'] ?? ''));
if ($message === '') {
    return;
}

$variantByType = [
    'success' => 'success',
    'warning' => 'warning',
    'error' => 'danger',
    'danger' => 'danger',
    'info' => 'info',
];

$iconByType = [
    'success' => 'bi-check-circle-fill',
    'warning' => 'bi-exclamation-triangle-fill',
    'error' => 'bi-x-octagon-fill',
    'danger' => 'bi-x-octagon-fill',
    'info' => 'bi-info-circle-fill',
];

$variant = $variantByType[$type] ?? 'info';
$icon = $iconByType[$type] ?? 'bi-info-circle-fill';
?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
    <div
        class="toast text-bg-<?php echo ctEscape($variant); ?> border-0"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-ct-flash-toast="1"
        data-delay-ms="3000">
        <div class="d-flex align-items-center">
            <div class="toast-body">
                <i class="bi <?php echo ctEscape($icon); ?> me-1" aria-hidden="true"></i><?php echo ctEscape($message); ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
    </div>
</div>
<script src="<?php echo ctEscape(ctUrl('assets/ct_flash_toast.js')); ?>"></script>

