<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once __DIR__ . '/terrenos_service.php';
require_once dirname(__DIR__, 2) . '/templates/components/searchable_select.php';

ctRequireAccess('CT');

if (!isset($conn) || !($conn instanceof PDO)) {
    throw new RuntimeException('No hay conexión a base de datos disponible en $conn.');
}

$pageTitle = 'Historial predial';
$pageSubtitle = '';
$pageDescription = '';
$showMainMenuButton = false;
$flashMode = 'toast';

$lineasPermitidas = [25, 50, 100];
$lineasPorPagina = isset($_GET['lineas']) && is_numeric((string) $_GET['lineas'])
    ? (int) $_GET['lineas']
    : 25;
if (!in_array($lineasPorPagina, $lineasPermitidas, true)) {
    $lineasPorPagina = 25;
}

$paginaActual = isset($_GET['pagina']) && is_numeric((string) $_GET['pagina'])
    ? max(1, (int) $_GET['pagina'])
    : 1;

$filtroRol = ctNormalizeText((string) ($_GET['rol'] ?? ''));
$filtroIdTerreno = isset($_GET['id_terreno']) && is_numeric((string) $_GET['id_terreno'])
    ? max(0, (int) $_GET['id_terreno'])
    : 0;
$filtroComuna = isset($_GET['id_comuna']) && is_numeric((string) $_GET['id_comuna'])
    ? max(0, (int) $_GET['id_comuna'])
    : 0;
$filtroTipoOperacion = strtoupper(ctNormalizeText((string) ($_GET['tipo_operacion'] ?? '')));
$historialVista = strtolower(trim((string) ($_GET['vista'] ?? 'eventos')));
if (!in_array($historialVista, ['eventos', 'lista'], true)) {
    $historialVista = 'eventos';
}

$normalizeDateFilter = static function (string $raw): string {
    $value = trim($raw);
    if ($value === '') {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!($dt instanceof DateTimeImmutable) || $dt->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
};

$fechaDesde = $normalizeDateFilter((string) ($_GET['fecha_desde'] ?? ''));
$fechaHasta = $normalizeDateFilter((string) ($_GET['fecha_hasta'] ?? ''));

if ($fechaDesde !== '' && $fechaHasta !== '' && $fechaHasta < $fechaDesde) {
    [$fechaDesde, $fechaHasta] = [$fechaHasta, $fechaDesde];
}

$queryBase = [
    'rol' => $filtroRol,
    'id_terreno' => $filtroIdTerreno > 0 ? (string) $filtroIdTerreno : '',
    'id_comuna' => $filtroComuna > 0 ? (string) $filtroComuna : '',
    'tipo_operacion' => $filtroTipoOperacion,
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'lineas' => $lineasPorPagina,
    'vista' => $historialVista === 'lista' ? 'lista' : '',
];

$historialError = null;
$historialRows = [];
$totalRegistros = 0;
$totalPaginas = 1;

try {
    $filtrosRepo = [
        'rol' => $filtroRol,
        'id_terreno' => $filtroIdTerreno,
        'id_comuna' => $filtroComuna,
        'tipo_operacion' => $filtroTipoOperacion,
        'fecha_desde' => $fechaDesde,
        'fecha_hasta' => $fechaHasta,
    ];

    $totalRegistros = $historialVista === 'lista'
        ? ctTerrenosRepoHistorialSimpleCount($conn, $filtrosRepo)
        : ctTerrenosRepoHistorialCount($conn, $filtrosRepo);
    $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $lineasPorPagina;

    $historialRows = $historialVista === 'lista'
        ? ctTerrenosRepoHistorialSimpleList($conn, $filtrosRepo, $offset, $lineasPorPagina)
        : ctTerrenosRepoHistorialList($conn, $filtrosRepo, $offset, $lineasPorPagina);
} catch (Throwable $exception) {
    $historialError = 'No fue posible cargar el historial predial.';
}

try {
    $comunas = ctTerrenosRepoListComunas($conn);
    $rolesSelector = ctTerrenosRepoListRolesSelector($conn);
    $tiposOperacion = ctTerrenosRepoListTiposOperacionHistorial($conn);
    foreach (['TASACION', 'VENTA'] as $tipoOperacionExtra) {
        if (!in_array($tipoOperacionExtra, $tiposOperacion, true)) {
            $tiposOperacion[] = $tipoOperacionExtra;
        }
    }
} catch (Throwable $exception) {
    $comunas = [];
    $rolesSelector = [];
    $tiposOperacion = [];
}

$rolOptions = [['value' => '', 'label' => 'Todos', 'search' => 'todos']];
foreach ($rolesSelector as $rolValue) {
    $rol = trim((string) $rolValue);
    if ($rol === '') {
        continue;
    }
    $search = function_exists('mb_strtolower') ? mb_strtolower($rol) : strtolower($rol);
    $rolOptions[] = [
        'value' => $rol,
        'label' => $rol,
        'search' => $search,
    ];
}

$paginationItems = ctTerrenosBuildPaginationItems($paginaActual, $totalPaginas);
$historialEventosHref = ctUrl('predial/terrenos/historial.php')
    . ctTerrenosBuildQuery($queryBase, ['vista' => '', 'pagina' => 1]);
$historialListaHref = ctUrl('predial/terrenos/historial.php')
    . ctTerrenosBuildQuery($queryBase, ['vista' => 'lista', 'pagina' => 1]);
$historialLimpiarHref = ctUrl('predial/terrenos/historial.php')
    . ($historialVista === 'lista' ? '?vista=lista' : '');

$historialEventoLabel = static function (array $row): string {
    $tipo = strtoupper(trim((string) ($row['evento_tipo'] ?? '')));
    if ($tipo === 'OPERACION') {
        return 'Operación: ' . ctTerrenosFormatOperacionLabel((string) ($row['tipo_operacion'] ?? ''));
    }
    if ($tipo === 'ESTADO') {
        return strtoupper(trim((string) ($row['tipo_estado'] ?? ''))) === 'C'
            ? 'Cambio de estado comercial'
            : 'Cambio de estado';
    }
    if ($tipo === 'TITULARIDAD') {
        return 'Titularidad';
    }

    return 'Evento';
};

$historialEventoClass = static function (array $row): string {
    $tipo = strtoupper(trim((string) ($row['evento_tipo'] ?? '')));
    if ($tipo === 'OPERACION') {
        return 'ct-historial-chip-operacion';
    }
    if ($tipo === 'ESTADO') {
        return 'ct-historial-chip-estado';
    }
    if ($tipo === 'TITULARIDAD') {
        return 'ct-historial-chip-titularidad';
    }

    return 'ct-historial-chip-generico';
};

$historialDetalle = static function (array $row): string {
    $tipo = strtoupper(trim((string) ($row['evento_tipo'] ?? '')));

    if ($tipo === 'OPERACION') {
        $parts = [];
        $rolOperacion = trim((string) ($row['rol_en_operacion'] ?? ''));
        $documento = trim((string) ($row['documento_fuente'] ?? ''));
        if ($rolOperacion !== '') {
            $parts[] = 'Rol en operación: ' . $rolOperacion;
        }
        if ($documento !== '') {
            $parts[] = 'Documento: ' . $documento;
        }
        return $parts !== [] ? implode(' | ', $parts) : '-';
    }

    if ($tipo === 'ESTADO') {
        $anterior = trim((string) ($row['estado_anterior_nombre'] ?? ''));
        $nuevo = trim((string) ($row['estado_nuevo_nombre'] ?? ''));
        return ($anterior !== '' ? $anterior : 'Sin estado') . ' -> ' . ($nuevo !== '' ? $nuevo : 'Sin estado');
    }

    if ($tipo === 'TITULARIDAD') {
        $parts = [];
        $idTercero = (int) ($row['id_tercero'] ?? 0);
        $terceroNombre = trim((string) ($row['tercero_nombre'] ?? ''));
        $terceroRut = trim((string) ($row['tercero_rut'] ?? ''));
        $label = ($idTercero > 0 ? ('#' . $idTercero . ' ') : '') . ($terceroNombre !== '' ? $terceroNombre : 'Tercero');
        if ($terceroRut !== '') {
            $label .= ' (' . $terceroRut . ')';
        }
        $parts[] = $label;

        $parts[] = 'Porcentaje: ' . number_format((float) ($row['porcentaje_derecho'] ?? 0), 2, '.', '') . '%';

        $vigenteHasta = trim((string) ($row['vigente_hasta'] ?? ''));
        if ($vigenteHasta !== '') {
            $parts[] = 'Vigente hasta: ' . ctTerrenosFormatDate($vigenteHasta);
        } else {
            $parts[] = 'Vigente';
        }

        return implode(' | ', $parts);
    }

    return '-';
};

$historialSimpleDoc = static function (array $row): string {
    $documento = trim((string) ($row['documento_fuente'] ?? ''));
    if ($documento !== '') {
        return $documento;
    }

    $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
    if ($fuente === 'TASACION') {
        return 'Tasacion #' . (string) ((int) ($row['id_tasacion'] ?? 0));
    }
    if ($fuente === 'VENTA') {
        return 'Venta #' . (string) ((int) ($row['id_venta'] ?? 0));
    }

    $idOperacion = (int) ($row['id_operacion'] ?? 0);
    return $idOperacion > 0 ? ('Operacion #' . (string) $idOperacion) : '-';
};

$historialSimpleTerrenoLabel = static function (array $row): string {
    $idTerreno = (int) ($row['id_terreno'] ?? $row['id_terreno_directo'] ?? 0);
    $rol = trim((string) ($row['rol_asignado'] ?? $row['rol_directo'] ?? ''));
    if ($idTerreno <= 0) {
        return $rol !== '' ? $rol : '-';
    }
    return '#' . (string) $idTerreno . ($rol !== '' ? (' (' . $rol . ')') : '');
};

$historialSimpleLotes = static function (array $row, string $tipo) use ($historialSimpleTerrenoLabel): array {
    $tipo = strtolower(trim($tipo));
    $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
    if ($fuente === 'TASACION' || $fuente === 'VENTA') {
        return $tipo === 'resultado' ? [$historialSimpleTerrenoLabel($row)] : [];
    }

    $participantes = is_array($row['participantes'] ?? null) ? $row['participantes'] : [];
    $lotes = [];
    foreach ($participantes as $participante) {
        $rolOperacion = strtoupper(trim((string) ($participante['rol_en_operacion'] ?? '')));
        $isOrigen = $rolOperacion === 'ORIGEN';
        $isResultado = in_array($rolOperacion, ['RESULTADO', 'ADQUIRIDO'], true);
        if ($tipo === 'origen' && !$isOrigen) {
            continue;
        }
        if ($tipo === 'resultado' && !$isResultado) {
            continue;
        }

        $label = $historialSimpleTerrenoLabel($participante);
        if ($label !== '-') {
            $lotes[$label] = true;
        }
    }

    return array_keys($lotes);
};

$historialSimpleValor = static function (array $row): string {
    $valor = $row['valor_total_uf'] ?? null;
    if (!is_numeric((string) $valor) || (float) $valor <= 0) {
        return '-';
    }
    if (function_exists('ctComercialFormatUf')) {
        return 'UF ' . ctComercialFormatUf($valor);
    }
    return 'UF ' . number_format((float) $valor, 2, ',', '.');
};

$historialSimpleSuperficie = static function (array $row) use ($historialSimpleLotes): string {
    $fuente = strtoupper(trim((string) ($row['fuente'] ?? '')));
    if ($fuente === 'TASACION' || $fuente === 'VENTA') {
        $superficie = $row['superficie_directa'] ?? null;
        return is_numeric((string) $superficie) && (float) $superficie > 0
            ? ctTerrenosFormatSuperficie((float) $superficie)
            : '-';
    }

    $resultadoLabels = $historialSimpleLotes($row, 'resultado');
    $usarResultados = $resultadoLabels !== [];
    $total = 0.0;
    foreach ((is_array($row['participantes'] ?? null) ? $row['participantes'] : []) as $participante) {
        $rolOperacion = strtoupper(trim((string) ($participante['rol_en_operacion'] ?? '')));
        if ($usarResultados && !in_array($rolOperacion, ['RESULTADO', 'ADQUIRIDO'], true)) {
            continue;
        }
        if (!$usarResultados && $rolOperacion !== 'ORIGEN') {
            continue;
        }
        $superficie = $participante['superficie_m2'] ?? null;
        if (is_numeric((string) $superficie)) {
            $total += (float) $superficie;
        }
    }

    return $total > 0 ? ctTerrenosFormatSuperficie($total) : '-';
};

ob_start();
?>
<link rel="stylesheet" href="/portalgp/ct/predial/terrenos/assets/terrenos.css">
<?php ctRenderSearchableSelectAssets(); ?>
<?php require __DIR__ . '/views/historial_list.php'; ?>
<?php
$pageBodyHtml = (string) ob_get_clean();

require dirname(__DIR__, 2) . '/templates/module_shell.php';
