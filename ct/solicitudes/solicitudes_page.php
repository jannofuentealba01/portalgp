<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/solicitudes_service.php';
require_once dirname(__DIR__) . '/templates/components/form_field.php';
require_once dirname(__DIR__) . '/templates/components/crud_table.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';
require_once dirname(__DIR__) . '/templates/components/searchable_multiselect.php';
require_once dirname(__DIR__, 2) . '/templates/components/section_header.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Solicitudes';
$hidePageHeading = true;
$showMainMenuButton = false;
$showCtMenuBackButton = false;
$flashMode = 'toast';
$pageMaxWidth = 1480;

$state = ctSolicitudesParseQuery($_GET);

if (
    $state['idSolicitud'] > 0
    && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
) {
    $fichaHref = ctUrl('solicitudes/ficha.php') . ctSolicitudesBuildQuery($state['queryBase'], [
        'id' => $state['idSolicitud'],
        'pagina' => $state['pagina'],
    ]);
    header('Location: ' . $fichaHref);
    exit();
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctSolicitudesHandlePost($conn, $_POST, $state);
}

$catalogs = ctSolicitudesFetchCatalogs($conn);
$pageData = ctSolicitudesFetchPage($conn, $state);
$detailData = null;

$tipos = $catalogs['tipos'];
$estados = $catalogs['estados'];
$areas = $catalogs['areas'];
$areasCreate = $catalogs['areasCreate'];
$tipoAreaConfig = $catalogs['tipoAreaConfig'];
$tipoAreaParticipanteDefaults = $catalogs['tipoAreaParticipanteDefaults'];
$participantesCatalog = $catalogs['participantesCatalog'];
$participantesByAreaCreate = $catalogs['participantesByAreaCreate'];
$comunas = $catalogs['comunas'];
$tiposInmueble = $catalogs['tiposInmueble'];
$terceros = $catalogs['terceros'];
$solicitantesFiltro = $catalogs['solicitantesFiltro'];

$rows = $pageData['rows'];
$solicitanteMap = $pageData['solicitanteMap'];
$totalRegistros = $pageData['totalRegistros'];
$totalPaginas = $pageData['totalPaginas'];
$paginaActual = $pageData['paginaActual'];
$paginationItems = $pageData['paginationItems'];

$currentUserId = ctSolicitudesCurrentUserId();
$canCreateSolicitud = ctSolicitudesCanCreateSolicitud($conn, $currentUserId);

ob_start();
ctRenderSearchableSelectAssets();
ctRenderSearchableMultiSelectAssets();
require __DIR__ . '/views/list.php';
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__) . '/templates/module_shell.php';
