<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/solicitudes_service.php';
require_once dirname(__DIR__) . '/templates/components/form_field.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';
require_once dirname(__DIR__) . '/templates/components/searchable_multiselect.php';
require_once dirname(__DIR__, 2) . '/templates/components/section_header.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Ficha de solicitud';
$hidePageHeading = true;
$showMainMenuButton = false;
$showCtMenuBackButton = false;
$flashMode = 'toast';
$pageMaxWidth = 1480;

$state = ctSolicitudesParseQuery($_GET);
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    ctSolicitudesHandlePost($conn, $_POST, $state);
}

if ($state['idSolicitud'] <= 0) {
    ctSetFlash('warning', 'Selecciona una solicitud para abrir la ficha.');
    $volverHref = ctUrl('solicitudes/index.php') . ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => $state['pagina']]);
    header('Location: ' . $volverHref);
    exit();
}

$catalogs = ctSolicitudesFetchCatalogs($conn);
$detailData = ctSolicitudesFetchDetail($conn, $state['idSolicitud']);
if (!is_array($detailData)) {
    ctSetFlash('danger', 'La solicitud indicada no existe o ya no está disponible.');
    $volverHref = ctUrl('solicitudes/index.php') . ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => $state['pagina']]);
    header('Location: ' . $volverHref);
    exit();
}

ctSolicitudesEnsureReadable(
    $detailData['solicitud'],
    ctSolicitudesCurrentUserId(),
    $detailData['participantes']
);

$areas = $catalogs['areas'];
$comunas = $catalogs['comunas'];
$tiposInmueble = $catalogs['tiposInmueble'];
$terceros = $catalogs['terceros'];
$participantesCatalog = $catalogs['participantesCatalog'];

$participanteSelectOptions = [];
foreach ($participantesCatalog as $participanteCatalog) {
    $idParticipante = (int) ($participanteCatalog['id_participante_solicitud'] ?? 0);
    if ($idParticipante <= 0) {
        continue;
    }
    $label = (string) ($participanteCatalog['nombre'] ?? '');
    $email = trim((string) ($participanteCatalog['email'] ?? ''));
    if ($email !== '') {
        $label .= ' (' . $email . ')';
    }
    $participanteSelectOptions[] = [
        'value' => (string) $idParticipante,
        'label' => $label,
        'search' => strtolower($label),
    ];
}

$participanteMultiOptions = [];
foreach ($participanteSelectOptions as $opt) {
    $participanteMultiOptions[] = [
        'code' => (string) $opt['value'],
        'label' => (string) $opt['label'],
        'search' => (string) $opt['search'],
    ];
}

$currentUserId = ctSolicitudesCurrentUserId();
$canCommentGeneral = ctSolicitudesCanCommentSolicitud($conn, $detailData['solicitud'], $currentUserId, null);
$canCommentByArea = [];
foreach (($detailData['panelesArea'] ?? []) as $panelArea) {
    $idArea = (int) (($panelArea['area']['id_area_solicitud'] ?? 0));
    if ($idArea <= 0) {
        continue;
    }
    $canCommentByArea[$idArea] = ctSolicitudesCanCommentSolicitud($conn, $detailData['solicitud'], $currentUserId, $idArea);
}
$volverHref = ctUrl('solicitudes/index.php') . ctSolicitudesBuildQuery($state['queryBase'], ['pagina' => $state['pagina']]);

ob_start();
ctRenderSearchableSelectAssets();
ctRenderSearchableMultiSelectAssets();
require __DIR__ . '/views/ficha.php';
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__) . '/templates/module_shell.php';
