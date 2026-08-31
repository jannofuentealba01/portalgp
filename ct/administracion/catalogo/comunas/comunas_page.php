<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once __DIR__ . '/comunas_service.php';
require_once dirname(__DIR__, 3) . '/templates/components/form_field.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Catálogo de Comunas';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctCatalogoComunasHandlePost($conn, $_POST);
}

$data = ctCatalogoComunasFetchData($conn);
$rows = $data['rows'];
$error = $data['error'];

ob_start();
?>
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/administracion/catalogo/comunas/assets/comunas.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/templates/module_shell.php';
