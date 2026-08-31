<footer class="gp-footer">
    <p>&copy; Grupo Patagual <?php echo date('Y'); ?>. Todos los derechos reservados.</p>
</footer>
<?php if (str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/portalgp/msp/') && function_exists('msp2QuickAccessSections')): ?>
    <?php include __DIR__ . '/../msp/templates/components/quick_access_offcanvas.php'; ?>
<?php endif; ?>
