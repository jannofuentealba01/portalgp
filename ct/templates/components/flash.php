<?php
declare(strict_types=1);

$type = trim((string) ($flash['type'] ?? 'info'));
$message = trim((string) ($flash['message'] ?? ''));
if ($message === '') {
    return;
}

$map = [
    'success' => 'success',
    'error' => 'danger',
    'danger' => 'danger',
    'warning' => 'warning',
    'info' => 'info',
];
$alertType = $map[$type] ?? 'info';
?>
<div class="alert alert-<?php echo ctEscape($alertType); ?> d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
    <div><?php echo ctEscape($message); ?></div>
</div>

