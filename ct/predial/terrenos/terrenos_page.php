<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/terrenos_service.php';
require_once dirname(__DIR__, 2) . '/templates/components/form_field.php';
require_once dirname(__DIR__, 2) . '/templates/components/searchable_select.php';
require_once dirname(__DIR__, 2) . '/templates/components/crud_table.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Terrenos';
$hidePageHeading = true;
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';
$pageMaxWidth = 1440;

$state = ctTerrenosParseQuery($_GET);
ctTerrenosHandleAjax($conn, $_GET);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctTerrenosHandlePost($conn, $_POST, $state['queryBase']);
}

$catalogData = ctTerrenosFetchCatalogs($conn);
$pageData = ctTerrenosFetchPage($conn, $state);

$lineasPermitidas = $state['lineasPermitidas'];
$lineasPorPagina = $state['lineasPorPagina'];
$filtroTexto = $state['filtroTexto'];
$filtroCampo = $state['filtroCampo'];
$filtroComuna = $state['filtroComuna'];
$filtroEstadoPredial = $state['filtroEstadoPredial'];
$filtroEstadoComercial = $state['filtroEstadoComercial'];
$filtroTipoInmueble = $state['filtroTipoInmueble'];
$orden = $state['orden'];
$direccion = $state['direccion'];
$vista = $state['vista'];
$modal = $state['modal'];
$formRestore = ctTerrenosPullFormStateForModal($modal);
$queryBase = $state['queryBase'];

$terrenos = $pageData['terrenos'];
$terrenosError = $pageData['terrenosError'];
$totalRegistros = $pageData['totalRegistros'];
$totalPaginas = $pageData['totalPaginas'];
$paginaActual = $pageData['paginaActual'];
$paginationItems = $pageData['paginationItems'];

$catalogosError = $catalogData['error'];
$comunas = $catalogData['comunas'];
$estadosPrediales = $catalogData['estadosPrediales'];
$estadosComerciales = $catalogData['estadosComerciales'];
$tiposInmueble = $catalogData['tiposInmueble'];
$terrenosSelector = $catalogData['terrenosSelector'];
$tercerosSelector = $catalogData['tercerosSelector'];
$tiposTasacion = $catalogData['tiposTasacion'];
$entidadesFinancieras = $catalogData['entidadesFinancieras'];
$tasacionesSelector = $catalogData['tasacionesSelector'];

ob_start();
?>
<?php
$terrenosCssFile = __DIR__ . '/assets/terrenos.css';
$terrenosCssVersion = is_file($terrenosCssFile) ? (string) ((int) filemtime($terrenosCssFile)) : '1';
?>
<link rel="stylesheet" href="/portalgp/ct/predial/terrenos/assets/terrenos.css?v=<?php echo ctEscape($terrenosCssVersion); ?>">
<?php ctRenderSearchableSelectAssets(); ?>
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<?php
$formRestoreJson = json_encode(
    $formRestore,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
if (!is_string($formRestoreJson)) {
    $formRestoreJson = '{}';
}
?>
<script>window.ctTerrenosFormRestore = <?php echo $formRestoreJson; ?>;</script>
<script src="/portalgp/ct/predial/terrenos/assets/terrenos.js"></script>
<script src="/portalgp/ct/contabilidad/assets/comercial.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__, 2) . '/templates/module_shell.php';
