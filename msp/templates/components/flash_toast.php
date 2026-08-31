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
<style>
    .msp-success-burst {
        position: fixed;
        top: 1.1rem;
        right: 1.1rem;
        z-index: 1105;
        pointer-events: none;
        display: none;
    }
    .msp-success-burst.is-active {
        display: block;
        animation: msp-success-burst-fade 1.2s ease-out forwards;
    }
    .msp-success-burst__badge {
        width: 56px;
        height: 56px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: radial-gradient(circle at 32% 28%, #93c5fd 0%, #2563eb 58%, #1e3a8a 100%);
        color: #fff;
        box-shadow: 0 14px 30px rgba(30, 58, 138, 0.34);
        font-size: 1.5rem;
        transform: scale(.7);
        opacity: .35;
        animation: msp-success-burst-pop 1.2s cubic-bezier(.2,.9,.2,1) forwards;
    }
    .msp-success-burst__plane {
        transform: translateX(-1px) rotate(-14deg);
    }
    .msp-success-burst__ok {
        position: absolute;
        right: -4px;
        bottom: -2px;
        width: 20px;
        height: 20px;
        border-radius: 999px;
        background: #16a34a;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.72rem;
        box-shadow: 0 6px 12px rgba(22, 163, 74, 0.34);
        animation: msp-success-burst-ok-pop .9s cubic-bezier(.2,.9,.2,1) .15s both;
    }
    .msp-success-burst__spark {
        position: absolute;
        width: 7px;
        height: 7px;
        border-radius: 999px;
        background: #3b82f6;
        opacity: 0;
        animation: msp-success-burst-spark 1.2s ease-out forwards;
    }
    .msp-success-burst__spark--1 { top: -6px; left: 24px; animation-delay: .02s; }
    .msp-success-burst__spark--2 { top: 14px; right: -9px; animation-delay: .09s; }
    .msp-success-burst__spark--3 { bottom: 7px; right: -5px; animation-delay: .15s; }
    .msp-success-burst__spark--4 { bottom: -8px; left: 21px; animation-delay: .11s; }
    .msp-success-burst__spark--5 { bottom: 13px; left: -10px; animation-delay: .06s; }
    .msp-success-burst__spark--6 { top: 9px; left: -8px; animation-delay: .13s; }

    @keyframes msp-success-burst-pop {
        0% { transform: scale(.7); opacity: .35; }
        30% { transform: scale(1.08); opacity: 1; }
        100% { transform: scale(1); opacity: 1; }
    }
    @keyframes msp-success-burst-fade {
        0%, 70% { opacity: 1; }
        100% { opacity: 0; }
    }
    @keyframes msp-success-burst-ok-pop {
        0% { transform: scale(.5); opacity: 0; }
        100% { transform: scale(1); opacity: 1; }
    }
    @keyframes msp-success-burst-spark {
        0% { transform: translate(0, 0) scale(.5); opacity: 0; }
        20% { opacity: .95; }
        100% { transform: translate(var(--sx, 0), var(--sy, 0)) scale(1.15); opacity: 0; }
    }
    .msp-success-burst__spark--1 { --sx: 0px; --sy: -16px; }
    .msp-success-burst__spark--2 { --sx: 15px; --sy: -6px; }
    .msp-success-burst__spark--3 { --sx: 13px; --sy: 9px; }
    .msp-success-burst__spark--4 { --sx: 0px; --sy: 16px; }
    .msp-success-burst__spark--5 { --sx: -14px; --sy: 8px; }
    .msp-success-burst__spark--6 { --sx: -13px; --sy: -7px; }
</style>
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
<script src="<?php echo msp2Escape(msp2Url('assets/msp_flash_toast.js')); ?>"></script>
