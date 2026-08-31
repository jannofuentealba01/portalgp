<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_once __DIR__ . '/tipos_inmueble_service.php';
require_once dirname(__DIR__, 3) . '/templates/components/form_field.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Catálogo de Tipos de Inmueble';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctCatalogoTiposInmuebleHandlePost($conn, $_POST);
}

$data = ctCatalogoTiposInmuebleFetchData($conn);
$rows = $data['rows'];
$error = $data['error'];

ob_start();
?>
<link rel="stylesheet" href="/portalgp/ct/administracion/catalogo/tipos_inmueble/assets/tipos_inmueble.css">
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/administracion/catalogo/tipos_inmueble/assets/tipos_inmueble.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();
require dirname(__DIR__, 3) . '/templates/module_shell.php';
