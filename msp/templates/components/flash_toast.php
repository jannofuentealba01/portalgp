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
$meta = is_array($toastFlash['meta'] ?? null) ? $toastFlash['meta'] : [];
$enableSuccessBurst = !empty($meta['enable_success_burst']) && empty($meta['disable_success_burst']);
?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1090;">
    <div
        class="toast text-bg-<?php echo msp2Escape($variant); ?> border-0"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-msp-flash-toast="1"
        data-flash-type="<?php echo msp2Escape($type); ?>"
        data-delay-ms="3000">
        <div class="d-flex align-items-center">
            <div class="toast-body">
                <i class="bi <?php echo msp2Escape($icon); ?> me-1" aria-hidden="true"></i><?php echo msp2Escape($message); ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
    </div>
</div>
<?php if ($type === 'success' && $enableSuccessBurst): ?>
    <div class="msp-success-burst" data-msp-success-burst="1" aria-hidden="true">
        <div class="msp-success-burst__spark msp-success-burst__spark--1"></div>
        <div class="msp-success-burst__spark msp-success-burst__spark--2"></div>
        <div class="msp-success-burst__spark msp-success-burst__spark--3"></div>
        <div class="msp-success-burst__spark msp-success-burst__spark--4"></div>
        <div class="msp-success-burst__spark msp-success-burst__spark--5"></div>
        <div class="msp-success-burst__spark msp-success-burst__spark--6"></div>
        <div class="msp-success-burst__badge">
            <i class="bi bi-send-fill msp-success-burst__plane" aria-hidden="true"></i>
            <span class="msp-success-burst__ok" aria-hidden="true">
                <i class="bi bi-check2"></i>
            </span>
        </div>
    </div>
<?php endif; ?>
<script<?= function_exists('pgpCspNonceAttribute') ? pgpCspNonceAttribute() : '' ?> src="<?php echo msp2Escape(msp2Url('assets/msp_flash_toast.js')); ?>"></script>
