<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/terrenos_service.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$idTerreno = isset($_GET['id_terreno']) && is_numeric((string) $_GET['id_terreno'])
    ? (int) $_GET['id_terreno']
    : 0;

$volverRaw = trim((string) ($_GET['volver'] ?? ''));
$volverLimpio = preg_replace('/[^a-zA-Z0-9_\-=&%]/', '', $volverRaw);
if (!is_string($volverLimpio)) {
    $volverLimpio = '';
}
$volverHref = ctUrl('predial/terrenos/index.php') . ($volverLimpio !== '' ? ('?' . ltrim($volverLimpio, '?')) : '');

$fichaError = null;
$terreno = null;
$titularesVigentes = [];
$eventos = [];
$historialLista = [];
$trazabilidad = [
    'max_saltos' => 5,
    'terrenos' => [],
    'operaciones' => [],
];

try {
    $ficha = ctTerrenosFetchFichaTerreno($conn, $idTerreno);
    $terreno = is_array($ficha['terreno'] ?? null) ? $ficha['terreno'] : null;
    $titularesVigentes = is_array($ficha['titularesVigentes'] ?? null) ? $ficha['titularesVigentes'] : [];
    $eventos = is_array($ficha['eventos'] ?? null) ? $ficha['eventos'] : [];
    $historialLista = is_array($ficha['historialLista'] ?? null) ? $ficha['historialLista'] : [];
    $trazabilidad = is_array($ficha['trazabilidad'] ?? null) ? $ficha['trazabilidad'] : $trazabilidad;
} catch (Throwable $exception) {
    $fichaError = $exception->getMessage();
}

$pageTitle = $terreno !== null
    ? ('Ficha terreno #' . (int) ($terreno['id_terreno'] ?? 0))
    : 'Ficha terreno';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

ob_start();
?>
<link rel="stylesheet" href="/portalgp/ct/predial/terrenos/assets/terrenos.css">
<?php require __DIR__ . '/views/terreno_ficha.php'; ?>
<script>
(() => {
    const parseSqlDateTimeUtc = (rawValue) => {
        const value = String(rawValue || '').trim();
        if (value === '') return null;
        const normalized = value.replace(' ', 'T');
        const iso = normalized.endsWith('Z') ? normalized : `${normalized}Z`;
        const date = new Date(iso);
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const parseSqlDateUtc = (rawValue) => {
        const value = String(rawValue || '').trim();
        if (value === '') return null;
        const date = new Date(`${value}T00:00:00`);
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const fmtDateTime = new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    const fmtDate = new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });

    document.querySelectorAll('[data-ct-local-datetime]').forEach((el) => {
        const date = parseSqlDateTimeUtc(el.getAttribute('data-ct-local-datetime'));
        if (!(date instanceof Date)) return;
        el.textContent = fmtDateTime.format(date);
    });

    document.querySelectorAll('[data-ct-local-date]').forEach((el) => {
        const date = parseSqlDateUtc(el.getAttribute('data-ct-local-date'));
        if (!(date instanceof Date)) return;
        el.textContent = fmtDate.format(date);
    });
})();
</script>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__, 2) . '/templates/module_shell.php';
