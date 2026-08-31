<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/comercial_service.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Comercial y Ventas';
$hidePageHeading = true;
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';
$pageMaxWidth = 1440;

$state = ctComercialParseQuery($_GET);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctComercialHandlePost($conn, $_POST, $state['queryBase']);
}

$pageData = ctComercialFetchPage($conn, $state);

$rows = $pageData['rows'];
$totalRegistros = (int) $pageData['totalRegistros'];
$totalPaginas = (int) $pageData['totalPaginas'];
$paginaActual = (int) $pageData['paginaActual'];

$filtroTexto = (string) $state['filtroTexto'];
$filtroEstadoComercial = (int) $state['filtroEstadoComercial'];
$lineas = (int) $state['lineas'];
$lineasPermitidas = $state['lineasPermitidas'];
$orden = (string) $state['orden'];
$dir = (string) $state['dir'];
$queryBase = $state['queryBase'];

$estadosComerciales = ctComercialRepoListEstadosComerciales($conn);
$tiposTasacion = ctComercialRepoListTiposTasacion($conn);
$terrenosSelector = ctComercialRepoListTerrenosSelector($conn);
$tercerosSelector = ctComercialRepoListTercerosSelector($conn);
$tasacionesSelector = ctComercialRepoListTasacionesSelector($conn);

ob_start();
?>
<link rel="stylesheet" href="/portalgp/ct/contabilidad/assets/comercial.css">
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/contabilidad/assets/comercial.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__) . '/templates/module_shell.php';
