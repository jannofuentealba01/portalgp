<?php
if (!isset($flash) || !is_array($flash)) {
    return;
}
?>
<div class="flash-stack">
    <div class="alert alert-<?php echo htmlspecialchars((string) ($flash['type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> mb-0" role="alert">
        <?php echo htmlspecialchars((string) ($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </div>
</div>
