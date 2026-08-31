<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/terceros_service.php';
require_once dirname(__DIR__, 2) . '/templates/components/form_field.php';
require_once dirname(__DIR__, 2) . '/templates/components/form_switch.php';
require_once dirname(__DIR__, 2) . '/templates/components/searchable_select.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Terceros';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

$state = ctTercerosParseQuery($_GET);
if ((string) ($_GET['descargar_plantilla'] ?? '') === '1') {
    try {
        ctTercerosImportDownloadTemplateXlsx();
    } catch (Throwable $exception) {
        ctSetFlash('danger', 'No fue posible generar la plantilla Excel. Ejecuta `composer install` en la raíz del proyecto e intenta nuevamente.');
        ctTercerosRedirectAfterPost($state['queryBase']);
    }
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctTercerosHandlePost($conn, $_POST, $_FILES, $state['queryBase']);
}

$pageData = ctTercerosFetchPage($conn, $state);

$lineasPermitidas = $state['lineasPermitidas'];
$lineasPorPagina = $state['lineasPorPagina'];
$filtroNombre = $state['filtroNombre'];
$filtroRut = $state['filtroRut'];
$filtroRutDisplay = $state['filtroRutDisplay'];
$filtroTipo = $state['filtroTipo'];
$filtroRelacion = $state['filtroRelacion'];
$orden = $state['orden'];
$direccion = $state['direccion'];
$vista = $state['vista'];
$queryBase = $state['queryBase'];

$terceros = $pageData['terceros'];
$tercerosError = $pageData['tercerosError'];
$totalRegistros = $pageData['totalRegistros'];
$totalPaginas = $pageData['totalPaginas'];
$paginaActual = $pageData['paginaActual'];
$paginationItems = $pageData['paginationItems'];
$importPreview = ctTercerosImportGetPreview();
$openImportPreviewModal = ctTercerosImportConsumePreviewOpenFlag();

ob_start();
?>
<link rel="stylesheet" href="/portalgp/ct/predial/terceros/assets/terceros.css">
<?php ctRenderSearchableSelectAssets(); ?>
<?php require __DIR__ . '/views/list.php'; ?>
<?php require __DIR__ . '/views/modals.php'; ?>
<script src="/portalgp/ct/predial/terceros/assets/terceros.js"></script>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__, 2) . '/templates/module_shell.php';
