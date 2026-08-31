<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once __DIR__ . '/estados_comerciales_service.php';
require_once dirname(__DIR__, 3) . '/templates/components/form_field.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Catálogo de Estados Comerciales';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctCatalogoEstadosComercialesHandlePost($conn, $_POST);
}

$data = ctCatalogoEstadosComercialesFetchData($conn);
$rows = $data['rows'];
$error = $data['error'];

ob_start();
?>
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/administracion/catalogo/estados_comerciales/assets/estados_comerciales.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/templates/module_shell.php';
