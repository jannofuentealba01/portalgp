<?php
$type = $flash['type'] ?? 'info';
$message = $flash['message'] ?? '';

if ($message === '') {
    return;
}

$map = [
    'success' => 'success',
    'error' => 'danger',
    'warning' => 'warning',
    'info' => 'info',
];

$alertType = $map[$type] ?? 'info';
?>
<?php if ($type === 'success'): ?>
    <style>
        .msp-success-plane {
            position: fixed;
            top: 1.1rem;
            right: 1.1rem;
            z-index: 1105;
            pointer-events: none;
            animation: msp-success-plane-fade 1.2s ease-out forwards;
        }
        .msp-success-plane__badge {
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
            transform: translateX(-1px) rotate(-14deg) scale(.78);
            animation: msp-success-plane-pop 1.2s cubic-bezier(.2,.9,.2,1) forwards;
        }
        @keyframes msp-success-plane-pop {
            0% { transform: translateX(-1px) rotate(-14deg) scale(.78); opacity: .4; }
            30% { transform: translateX(-1px) rotate(-10deg) scale(1.07); opacity: 1; }
            100% { transform: translateX(-1px) rotate(-14deg) scale(1); opacity: 1; }
        }
        @keyframes msp-success-plane-fade {
            0%, 70% { opacity: 1; }
            100% { opacity: 0; }
        }
    </style>
    <div class="msp-success-plane" aria-hidden="true">
        <div class="msp-success-plane__badge">
            <i class="bi bi-send-fill"></i>
        </div>
    </div>
<?php endif; ?>
<div class="alert alert-<?php echo msp2Escape($alertType); ?> d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <div><?php echo msp2Escape($message); ?></div>
</div>
