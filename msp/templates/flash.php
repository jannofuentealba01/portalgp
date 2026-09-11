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
