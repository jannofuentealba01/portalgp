<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once __DIR__ . '/tipos_tasacion_service.php';
require_once dirname(__DIR__, 3) . '/templates/components/form_field.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Catálogo de Tipos de Tasacion';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctCatalogoTiposTasacionHandlePost($conn, $_POST);
}

$data = ctCatalogoTiposTasacionFetchData($conn);
$rows = $data['rows'];
$error = $data['error'];

ob_start();
?>
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/administracion/catalogo/tipos_tasacion/assets/tipos_tasacion.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/templates/module_shell.php';
