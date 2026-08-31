<?php
declare(strict_types=1);

$undoToast = $undoToast ?? null;
if (!is_array($undoToast)) {
    return;
}

$message = trim((string) ($undoToast['message'] ?? ''));
$actionPath = trim((string) ($undoToast['action_path'] ?? ''));
$buttonLabel = trim((string) ($undoToast['button_label'] ?? 'Deshacer'));
$fields = $undoToast['fields'] ?? [];

if ($message === '' || $actionPath === '' || !is_array($fields)) {
    return;
}
?>
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index: 1091;">
    <div
        class="toast text-bg-dark border-0"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        data-msp-undo-toast="1"
        data-delay-ms="5000">
        <div class="d-flex align-items-center">
            <div class="toast-body small"><?php echo msp2Escape($message); ?></div>
            <form method="post" action="<?php echo msp2Escape(msp2Url($actionPath)); ?>" class="me-2">
                <?php foreach ($fields as $fieldName => $fieldValue): ?>
                    <input type="hidden" name="<?php echo msp2Escape((string) $fieldName); ?>" value="<?php echo msp2Escape((string) $fieldValue); ?>">
                <?php endforeach; ?>
                <button type="submit" class="btn btn-sm btn-light"><?php echo msp2Escape($buttonLabel !== '' ? $buttonLabel : 'Deshacer'); ?></button>
            </form>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Cerrar"></button>
        </div>
    </div>
</div>
<script src="<?php echo msp2Escape(msp2Url('assets/msp_undo_toast.js')); ?>"></script>
