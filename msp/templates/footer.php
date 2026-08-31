<?php
$year = date('Y');
?>
<footer class="container py-4 small text-muted">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <span>MSP · Mercado San Pedro</span>
        <span><?php echo msp2Escape($year); ?></span>
    </div>
</footer>
<?php include __DIR__ . '/components/quick_access_offcanvas.php'; ?>
<?php include __DIR__ . '/components/confirm_action_modal.php'; ?>
