<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Shared\Date as PhpSpreadsheetDate;

msp2RequireAccess();

/** @var string $importContext */
$importContext = defined('MSP2_IMPORT_CONTEXT') && is_string(MSP2_IMPORT_CONTEXT)
    ? MSP2_IMPORT_CONTEXT
    : msp2NormalizeText((string) ($_POST['import_context'] ?? 'tiendas'));
$importContext = in_array($importContext, ['tiendas', 'contratos'], true) ? $importContext : 'tiendas';
$redirectRoute = $importContext === 'contratos' ? 'contratos/index.php' : 'tiendas/index.php';
$confirmRoute = $importContext === 'contratos' ? 'contratos/confirmar_importacion.php' : 'tiendas/confirmar_importacion.php';
$sessionPreviewKey = $importContext === 'contratos' ? 'msp2_contratos_import_preview' : 'msp2_tiendas_import_preview';
$vistaTitulo = $importContext === 'contratos' ? 'Vista Previa Importación de Contratos' : 'Vista Previa Importación de Tiendas';
$volverLabel = $importContext === 'contratos' ? 'Volver a contratos' : 'Volver a tiendas';
$sectionLabel = $importContext === 'contratos' ? 'MSP / Importación Contratos' : 'MSP / Importación Tiendas';
$modoContratoImport = $importContext === 'contratos';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect($redirectRoute);
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` e intenta nuevamente.');
    msp2Redirect($redirectRoute);
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido para importar.');
    msp2Redirect($redirectRoute);
}

$originalName = (string) ($uploadMeta['name'] ?? 'importacion.xlsx');
$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

$requiredTables = [
    'msp_tiendas',
    'msp_arrendatarios',
    'msp_locales',
    'msp_ocupacion_locales',
    'msp_rubros',
    'msp_estado_tiendas',
];

foreach ($requiredTables as $tableName) {
    if (!msp2TableExists($conn, $tableName)) {
        msp2SetFlash('warning', 'Falta la tabla `' . $tableName . '`. Ejecuta `msp/msp_a1.sql` actualizado.');
        msp2Redirect($redirectRoute);
    }
}

function msp2TiendaImportCellToString($value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function msp2TiendaImportFindColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $headers)) {
            return (int) $headers[$alias];
        }
    }

    return null;
}

function msp2TiendaImportParseDate($value, bool $allowEmpty, ?string $defaultValue = null): array
{
    $raw = msp2TiendaImportCellToString($value);

    if ($raw === '') {
        if ($defaultValue !== null) {
            return [true, $defaultValue, null];
        }

        return [$allowEmpty, null, $allowEmpty ? null : 'Fecha obligatoria.'];
    }

    if (is_numeric($raw)) {
        try {
            $dt = PhpSpreadsheetDate::excelToDateTimeObject((float) $raw);
            return [true, $dt->format('Y-m-d'), null];
        } catch (Throwable $exception) {
            return [false, null, 'Fecha inválida.'];
        }
    }

    $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y', 'Y/m/d'];
    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $raw);
        if ($dt !== false && $dt->format($format) === $raw) {
            return [true, $dt->format('Y-m-d'), null];
        }
    }

    // Permite mes/año (ej. 10-2025, 10/2025, 2025-10, 2025/10) y lo normaliza al primer día del mes.
    $monthYearFormats = ['m-Y', 'm/Y', 'Y-m', 'Y/m'];
    foreach ($monthYearFormats as $format) {
        $dt = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        if ($dt !== false && $dt->format($format) === $raw) {
            return [true, $dt->format('Y-m-01'), null];
        }
    }

    return [false, null, 'Fecha inválida.'];
}

function msp2TiendaImportParseClpAmount($value): array
{
    if ($value === null) {
        return [true, null, null];
    }

    if (is_int($value) || is_float($value)) {
        $numeric = (float) $value;
        if ($numeric < 0) {
            return [false, null, 'Monto garantía no puede ser negativo.'];
        }

        return [true, number_format($numeric, 2, '.', ''), null];
    }

    $raw = msp2TiendaImportCellToString($value);
    if ($raw === '') {
        return [true, null, null];
    }

    $raw = str_replace(['$', 'CLP', 'clp', ' '], '', $raw);
    [$ok, $normalized] = msp2NormalizeDecimalInput($raw, 2);
    if (!$ok || $normalized === null) {
        return [false, null, 'Monto garantía inválido. Usa CLP sin texto extra (ej: 1200000 o $1.200.000).'];
    }

    return [true, $normalized, null];
}

function msp2TiendaImportParseUfAmount($value): array
{
    if ($value === null) {
        return [true, null, null];
    }

    if (is_int($value) || is_float($value)) {
        $numeric = (float) $value;
        if ($numeric < 0) {
            return [false, null, 'Valor UF arriendo no puede ser negativo.'];
        }

        return [true, number_format($numeric, 4, '.', ''), null];
    }

    $raw = msp2TiendaImportCellToString($value);
    if ($raw === '') {
        return [true, null, null];
    }

    [$ok, $normalized] = msp2NormalizeDecimalInput($raw, 4);
    if (!$ok || $normalized === null) {
        return [false, null, 'Valor UF arriendo inválido. Usa número mayor o igual a 0.'];
    }

    return [true, $normalized, null];
}

function msp2TiendaImportParseArriendoModalidad($value): array
{
    $raw = strtoupper(msp2NormalizeText((string) $value));
    if ($raw === '') {
        return [true, 'UF_ESTATICO', 'UF estático'];
    }

    $aliases = [
        'UF' => 'UF_ESTATICO',
        'UF_ESTATICO' => 'UF_ESTATICO',
        'ESTATICO' => 'UF_ESTATICO',
        'CLP' => 'CLP_FIJO',
        'CLP_FIJO' => 'CLP_FIJO',
        'PESOS' => 'CLP_FIJO',
    ];
    $labels = [
        'UF_ESTATICO' => 'UF estático',
        'CLP_FIJO' => 'CLP fijo',
    ];

    if (!isset($aliases[$raw])) {
        return [false, null, null];
    }

    $canonical = $aliases[$raw];
    return [true, $canonical, $labels[$canonical] ?? $canonical];
}

function msp2TiendaImportParseLocales(string $raw): array
{
    if (trim($raw) === '') {
        return [[], ['Debes indicar al menos un código local en `cod_locales`.']];
    }

    $parts = preg_split('/[;|,\n\r]+/', $raw);
    if (!is_array($parts)) {
        return [[], ['Formato inválido en `cod_locales`.']];
    }

    $codes = [];
    $seen = [];

    foreach ($parts as $part) {
        $code = msp2NormalizeLocalCode((string) $part);
        $codeKey = msp2LocalCodeKey($code);
        if ($code === '') {
            continue;
        }

        if (mb_strlen($code) > 20) {
            return [[], ['Código local supera 20 caracteres: ' . $code . '.']];
        }

        if (isset($seen[$codeKey])) {
            continue;
        }

        $seen[$codeKey] = true;
        $codes[] = $code;
    }

    if ($codes === []) {
        return [[], ['Debes indicar al menos un código local válido.']];
    }

    return [$codes, []];
}

function msp2TiendaImportFetchArrendatarios(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_arrendatario, rut, nombre_locatario FROM dbo.msp_arrendatarios');
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $rut = msp2NormalizeText((string) ($row['rut'] ?? ''));
        if ($rut !== '') {
            $map[$rut] = [
                'id_arrendatario' => (int) $row['id_arrendatario'],
                'nombre_locatario' => msp2NormalizeText((string) ($row['nombre_locatario'] ?? '')),
            ];
        }
    }

    return $map;
}

function msp2TiendaImportFetchLocales(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_local, cdo_local FROM dbo.msp_locales');
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $code = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
        $codeKey = msp2LocalCodeKey($code);
        if ($codeKey !== '') {
            $map[$codeKey] = (int) $row['id_local'];
        }
    }

    return $map;
}

function msp2TiendaImportFetchLookupByDesc(PDO $conn, string $table, string $idColumn, string $descColumn): array
{
    $stmt = $conn->query('SELECT ' . $idColumn . ', ' . $descColumn . ' FROM ' . $table);
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $desc = msp2NormalizeText((string) ($row[$descColumn] ?? ''));
        $key = msp2NormalizeLookupKey($desc);

        if ($desc !== '' && $key !== '') {
            $map[$key] = [
                'id' => (int) $row[$idColumn],
                'desc' => $desc,
            ];
        }
    }

    return $map;
}

function msp2TiendaImportKey(int $idArrendatario, string $nombreComercial): string
{
    return $idArrendatario . '|' . msp2NormalizeLookupKey($nombreComercial);
}

function msp2TiendaImportLocalesKey(array $codes): string
{
    $normalized = [];

    foreach ($codes as $code) {
        $localCode = msp2NormalizeLocalCode((string) $code);
        $localCodeKey = msp2LocalCodeKey($localCode);
        if ($localCodeKey === '') {
            continue;
        }

        $normalized[] = $localCodeKey;
    }

    $normalized = array_values(array_unique($normalized));
    sort($normalized);

    return implode('|', $normalized);
}

function msp2TiendaImportRowKey(int $idArrendatario, string $nombreComercial, array $codes): string
{
    return msp2TiendaImportKey($idArrendatario, $nombreComercial) . '|' . msp2TiendaImportLocalesKey($codes);
}

function msp2TiendaImportLocalesKeyFromOcupaciones(array $ocupaciones): string
{
    $codes = [];
    foreach ($ocupaciones as $ocupacion) {
        $codes[] = (string) ($ocupacion['cdo_local'] ?? '');
    }

    return msp2TiendaImportLocalesKey($codes);
}

function msp2TiendaImportFetchExistingTiendas(PDO $conn, array $arrendatarioIds): array
{
    if ($arrendatarioIds === []) {
        return [];
    }

    $map = [];

    foreach (array_chunk(array_values(array_unique($arrendatarioIds)), 500) as $chunk) {
        $placeholders = [];
        foreach ($chunk as $index => $id) {
            $placeholders[] = ':id_' . $index;
        }

        $sql =
            'SELECT
                t.id_tienda,
                t.id_arrendatario,
                t.nombre_comercial,
                t.fecha_inicio,
                t.id_rubro,
                t.id_estado_tienda,
                r.nombre_rubro,
                e.desc_estado
             FROM dbo.msp_tiendas t
             INNER JOIN dbo.msp_rubros r ON r.id_rubro = t.id_rubro
             INNER JOIN dbo.msp_estado_tiendas e ON e.id_estado_tienda = t.id_estado_tienda
             WHERE t.id_arrendatario IN (' . implode(', ', $placeholders) . ')';

        $stmt = $conn->prepare($sql);
        foreach ($chunk as $index => $id) {
            $stmt->bindValue(':id_' . $index, (int) $id, PDO::PARAM_INT);
        }

        $stmt->execute();

        while (($row = $stmt->fetch()) !== false) {
            $key = msp2TiendaImportKey((int) $row['id_arrendatario'], (string) $row['nombre_comercial']);
            if (!isset($map[$key])) {
                $map[$key] = [];
            }
            $map[$key][] = $row;
        }
    }

    return $map;
}

function msp2TiendaImportFetchOcupaciones(PDO $conn, array $tiendaIds): array
{
    if ($tiendaIds === []) {
        return [];
    }

    $map = [];

    foreach (array_chunk(array_values(array_unique($tiendaIds)), 500) as $chunk) {
        $placeholders = [];
        foreach ($chunk as $index => $id) {
            $placeholders[] = ':id_' . $index;
        }

        $sql =
            'SELECT
                ol.id_tienda,
                l.cdo_local,
                ol.fecha_inicio,
                ol.fecha_termino
             FROM dbo.msp_ocupacion_locales ol
             INNER JOIN dbo.msp_locales l ON l.id_local = ol.id_local
             WHERE ol.id_tienda IN (' . implode(', ', $placeholders) . ')';

        $stmt = $conn->prepare($sql);
        foreach ($chunk as $index => $id) {
            $stmt->bindValue(':id_' . $index, (int) $id, PDO::PARAM_INT);
        }

        $stmt->execute();

        while (($row = $stmt->fetch()) !== false) {
            $idTienda = (int) $row['id_tienda'];
            if (!isset($map[$idTienda])) {
                $map[$idTienda] = [];
            }

            $map[$idTienda][] = [
                'cdo_local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
                'fecha_inicio' => $row['fecha_inicio'] ? (new DateTimeImmutable((string) $row['fecha_inicio']))->format('Y-m-d') : null,
                'fecha_termino' => $row['fecha_termino'] ? (new DateTimeImmutable((string) $row['fecha_termino']))->format('Y-m-d') : null,
            ];
        }
    }

    return $map;
}

function msp2TiendaImportCanonicalOcupaciones(array $items): array
{
    $keys = [];

    foreach ($items as $item) {
        $code = msp2LocalCodeKey((string) ($item['cdo_local'] ?? ''));
        $fi = $item['fecha_inicio'] ?? null;
        $ft = $item['fecha_termino'] ?? null;

        if ($code === '' || $fi === null) {
            continue;
        }

        $keys[] = $code . '|' . $fi . '|' . ($ft ?? 'NULL');
    }

    $keys = array_values(array_unique($keys));
    sort($keys);

    return $keys;
}

$defaultRubro = 'Sin rubro';
$defaultEstado = 'Activo';

$arrendatariosMap = msp2TiendaImportFetchArrendatarios($conn);
$localesMap = msp2TiendaImportFetchLocales($conn);
$rubrosMap = msp2TiendaImportFetchLookupByDesc($conn, 'dbo.msp_rubros', 'id_rubro', 'nombre_rubro');
$estadosMap = msp2TiendaImportFetchLookupByDesc($conn, 'dbo.msp_estado_tiendas', 'id_estado_tienda', 'desc_estado');

$summary = [
    'processed' => 0,
    'valid' => 0,
    'errors' => 0,
    'creates' => 0,
    'updates' => 0,
    'unchanged' => 0,
];

$previewRows = [];
$previewByRowNumber = [];
$validRows = [];
$erroresMuestra = [];

try {
    $rows = msp2ReadSpreadsheetRows($uploadTmpPath, true, true, false, true);

    if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
        msp2SetFlash('warning', 'La planilla no contiene datos para importar.');
        msp2Redirect($redirectRoute);
    }

    $headers = [];
    foreach ($rows[0] as $index => $headerValue) {
        $normalized = msp2NormalizeLookupKey(msp2TiendaImportCellToString($headerValue));
        if ($normalized !== '') {
            $headers[$normalized] = $index;
        }
    }

    $columns = [
        'rut_arrendatario' => msp2TiendaImportFindColumn($headers, ['rut_arrendatario', 'rut']),
        'nombre_comercial' => msp2TiendaImportFindColumn($headers, ['nombre_comercial', 'tienda']),
        'cod_locales' => msp2TiendaImportFindColumn($headers, ['cod_locales', 'cdo_locales', 'locales']),
        'rubro' => msp2TiendaImportFindColumn($headers, ['rubro']),
        'estado_tienda' => msp2TiendaImportFindColumn($headers, ['estado_tienda', 'estado']),
        'fecha_inicio_tienda' => msp2TiendaImportFindColumn($headers, ['fecha_inicio_tienda', 'fecha_inicio']),
        'fecha_inicio_ocupacion' => msp2TiendaImportFindColumn($headers, ['fecha_inicio_ocupacion', 'fecha_inicio_locales']),
        'fecha_termino_ocupacion' => msp2TiendaImportFindColumn($headers, ['fecha_termino_ocupacion', 'fecha_termino_locales']),
        'garantia_clp' => msp2TiendaImportFindColumn($headers, ['garantia_clp', 'garantia', 'monto_garantia', 'monto_garantia_clp']),
        'modalidad_arriendo' => msp2TiendaImportFindColumn($headers, ['modalidad_arriendo', 'arriendo_modalidad', 'modalidad', 'tipo_arriendo']),
        'valor_arriendo_uf' => msp2TiendaImportFindColumn($headers, ['valor_arriendo_uf', 'arriendo_uf', 'uf_arriendo']),
        'valor_arriendo_clp' => msp2TiendaImportFindColumn($headers, ['valor_arriendo_clp', 'arriendo_clp', 'clp_arriendo']),
        'descuento_arriendo_clp' => msp2TiendaImportFindColumn($headers, ['descuento_arriendo_clp', 'descuento_clp_arriendo', 'descuento_arriendo']),
    ];

    foreach (['rut_arrendatario', 'nombre_comercial', 'cod_locales'] as $requiredColumn) {
        if ($columns[$requiredColumn] === null) {
            msp2SetFlash('warning', 'Falta la columna obligatoria `' . $requiredColumn . '` en la planilla.');
            msp2Redirect($redirectRoute);
        }
    }

    $filaOrigenPorKey = [];

    for ($rowIndex = 1, $rowCount = count($rows); $rowIndex < $rowCount; $rowIndex++) {
        $row = $rows[$rowIndex];
        if (!is_array($row)) {
            continue;
        }

        $rutRaw = msp2TiendaImportCellToString($row[$columns['rut_arrendatario']] ?? null);
        $nombreRaw = msp2TiendaImportCellToString($row[$columns['nombre_comercial']] ?? null);
        $localesRaw = msp2TiendaImportCellToString($row[$columns['cod_locales']] ?? null);
        $rubroRaw = $columns['rubro'] !== null ? msp2TiendaImportCellToString($row[$columns['rubro']] ?? null) : '';
        $estadoRaw = $columns['estado_tienda'] !== null ? msp2TiendaImportCellToString($row[$columns['estado_tienda']] ?? null) : '';
        $fiTiendaRaw = $columns['fecha_inicio_tienda'] !== null ? ($row[$columns['fecha_inicio_tienda']] ?? null) : null;
        $fiOcupRaw = $columns['fecha_inicio_ocupacion'] !== null ? ($row[$columns['fecha_inicio_ocupacion']] ?? null) : null;
        $ftOcupRaw = $columns['fecha_termino_ocupacion'] !== null ? ($row[$columns['fecha_termino_ocupacion']] ?? null) : null;
        $garantiaClpRaw = $columns['garantia_clp'] !== null ? ($row[$columns['garantia_clp']] ?? null) : null;
        $modalidadArriendoRaw = $columns['modalidad_arriendo'] !== null ? msp2TiendaImportCellToString($row[$columns['modalidad_arriendo']] ?? null) : '';
        $valorArriendoUfRaw = $columns['valor_arriendo_uf'] !== null ? ($row[$columns['valor_arriendo_uf']] ?? null) : null;
        $valorArriendoClpRaw = $columns['valor_arriendo_clp'] !== null ? ($row[$columns['valor_arriendo_clp']] ?? null) : null;
        $descuentoArriendoClpRaw = $columns['descuento_arriendo_clp'] !== null ? ($row[$columns['descuento_arriendo_clp']] ?? null) : null;

        if (
            $rutRaw === ''
            && $nombreRaw === ''
            && $localesRaw === ''
            && $rubroRaw === ''
            && $estadoRaw === ''
            && msp2TiendaImportCellToString($fiTiendaRaw) === ''
            && msp2TiendaImportCellToString($fiOcupRaw) === ''
            && msp2TiendaImportCellToString($ftOcupRaw) === ''
            && msp2TiendaImportCellToString($garantiaClpRaw) === ''
            && $modalidadArriendoRaw === ''
            && msp2TiendaImportCellToString($valorArriendoUfRaw) === ''
            && msp2TiendaImportCellToString($valorArriendoClpRaw) === ''
            && msp2TiendaImportCellToString($descuentoArriendoClpRaw) === ''
        ) {
            continue;
        }

        $summary['processed']++;
        $filaNumero = $rowIndex + 1;
        $errors = [];

        $rut = msp2RutNormalizeDb($rutRaw);
        if ($rut === null) {
            $errors[] = 'RUT arrendatario inválido.';
        }

        $arrendatarioId = null;
        $arrendatarioNombre = '';
        if ($rut !== null) {
            if (!isset($arrendatariosMap[$rut])) {
                $errors[] = 'No existe arrendatario para RUT ' . $rut . '.';
            } else {
                $arrendatarioId = (int) $arrendatariosMap[$rut]['id_arrendatario'];
                $arrendatarioNombre = (string) $arrendatariosMap[$rut]['nombre_locatario'];
            }
        }

        $nombreComercial = msp2NormalizeText($nombreRaw);
        if ($nombreComercial === '') {
            $errors[] = 'Nombre comercial obligatorio.';
        } elseif (mb_strlen($nombreComercial) > 200) {
            $errors[] = 'Nombre comercial supera 200 caracteres.';
        }

        [$codLocales, $localesErrors] = msp2TiendaImportParseLocales($localesRaw);
        foreach ($localesErrors as $err) {
            $errors[] = $err;
        }

        $ocupaciones = [];
        foreach ($codLocales as $code) {
            $codeKey = msp2LocalCodeKey($code);
            if (!isset($localesMap[$codeKey])) {
                $errors[] = 'No existe local con código `' . $code . '`.';
                continue;
            }

            $ocupaciones[] = [
                'id_local' => (int) $localesMap[$codeKey],
                'cdo_local' => $code,
            ];
        }

        [$fiTiendaOk, $fechaInicioTienda, $fiTiendaErr] = msp2TiendaImportParseDate($fiTiendaRaw, true);
        if (!$fiTiendaOk && $fiTiendaErr !== null) {
            $errors[] = 'Fecha inicio tienda: ' . $fiTiendaErr;
        }

        $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
        $fechaInicioOcupDefault = $fechaInicioTienda ?? $hoy;
        [$fiOcupOk, $fechaInicioOcup, $fiOcupErr] = msp2TiendaImportParseDate($fiOcupRaw, false, $fechaInicioOcupDefault);
        if (!$fiOcupOk || $fechaInicioOcup === null) {
            $errors[] = 'Fecha inicio ocupación: ' . ($fiOcupErr ?? 'inválida.');
        }

        [$ftOcupOk, $fechaTerminoOcup, $ftOcupErr] = msp2TiendaImportParseDate($ftOcupRaw, true);
        if (!$ftOcupOk && $ftOcupErr !== null) {
            $errors[] = 'Fecha término ocupación: ' . $ftOcupErr;
        }

        if ($fechaInicioOcup !== null && $fechaTerminoOcup !== null && $fechaTerminoOcup < $fechaInicioOcup) {
            $errors[] = 'Fecha término ocupación no puede ser menor a fecha inicio ocupación.';
        }

        $garantiaClp = null;
        if ($modoContratoImport) {
            [$garantiaClpOk, $garantiaClp, $garantiaClpErr] = msp2TiendaImportParseClpAmount($garantiaClpRaw);
            if (!$garantiaClpOk && $garantiaClpErr !== null) {
                $errors[] = $garantiaClpErr;
            }
        } elseif (msp2TiendaImportCellToString($garantiaClpRaw) !== '') {
            $errors[] = 'La columna `garantia_clp` ya no se procesa en Importar Tiendas. Usa Importar Contratos.';
        }

        $arriendoExplicit = (
            $modalidadArriendoRaw !== ''
            || msp2TiendaImportCellToString($valorArriendoUfRaw) !== ''
            || msp2TiendaImportCellToString($valorArriendoClpRaw) !== ''
            || msp2TiendaImportCellToString($descuentoArriendoClpRaw) !== ''
        );
        $arriendoModalidad = 'UF_ESTATICO';
        $arriendoModalidadLabel = 'UF estático';
        $arriendoValorUf = null;
        $arriendoValorClp = null;
        $arriendoDescuentoClp = '0.00';

        if ($modoContratoImport) {
            [$modalidadOk, $modalidadCode, $modalidadLabel] = msp2TiendaImportParseArriendoModalidad($modalidadArriendoRaw);
            if (!$modalidadOk || $modalidadCode === null || $modalidadLabel === null) {
                $errors[] = 'Modalidad arriendo inválida. Usa UF_ESTATICO o CLP_FIJO.';
            } else {
                $arriendoModalidad = $modalidadCode;
                $arriendoModalidadLabel = $modalidadLabel;
            }

            [$arriendoUfOk, $arriendoValorUf, $arriendoUfErr] = msp2TiendaImportParseUfAmount($valorArriendoUfRaw);
            if (!$arriendoUfOk && $arriendoUfErr !== null) {
                $errors[] = $arriendoUfErr;
            }

            [$arriendoClpOk, $arriendoValorClp, $arriendoClpErr] = msp2TiendaImportParseClpAmount($valorArriendoClpRaw);
            if (!$arriendoClpOk && $arriendoClpErr !== null) {
                $errors[] = str_replace('Monto garantía', 'Valor CLP arriendo', $arriendoClpErr);
            }

            [$descuentoClpOk, $descuentoClpTmp, $descuentoClpErr] = msp2TiendaImportParseClpAmount($descuentoArriendoClpRaw);
            if (!$descuentoClpOk && $descuentoClpErr !== null) {
                $errors[] = str_replace('Monto garantía', 'Descuento CLP arriendo', $descuentoClpErr);
            } elseif ($descuentoClpTmp !== null) {
                $arriendoDescuentoClp = $descuentoClpTmp;
            }

            if ($arriendoModalidad === 'CLP_FIJO' && $arriendoValorClp === null) {
                $errors[] = 'Modalidad CLP_FIJO requiere `valor_arriendo_clp`.';
            }
        } elseif ($arriendoExplicit) {
            $errors[] = 'Las columnas de arriendo (modalidad/UF/CLP/descuento) solo se procesan en Importar Contratos.';
        }

        $rubroDesc = msp2NormalizeText($rubroRaw);
        if ($rubroDesc === '') {
            $rubroDesc = $defaultRubro;
        }
        if (mb_strlen($rubroDesc) > 150) {
            $errors[] = 'Rubro supera 150 caracteres.';
        }

        $estadoDesc = msp2NormalizeText($estadoRaw);
        if ($estadoDesc === '') {
            $estadoDesc = $defaultEstado;
        }
        if (mb_strlen($estadoDesc) > 100) {
            $errors[] = 'Estado tienda supera 100 caracteres.';
        }

        $rubroKey = msp2NormalizeLookupKey($rubroDesc);
        $estadoKey = msp2NormalizeLookupKey($estadoDesc);

        if ($rubroKey === '') {
            $errors[] = 'Rubro inválido.';
        }

        if ($estadoKey === '') {
            $errors[] = 'Estado tienda inválido.';
        }

        $rubroPendingCreate = $rubroKey !== '' && !isset($rubrosMap[$rubroKey]);
        $estadoPendingCreate = $estadoKey !== '' && !isset($estadosMap[$estadoKey]);

        $localesKey = msp2TiendaImportLocalesKey($codLocales);

        if ($arrendatarioId !== null && $nombreComercial !== '') {
            $groupKey = msp2TiendaImportKey($arrendatarioId, $nombreComercial);
            $rowKey = msp2TiendaImportRowKey($arrendatarioId, $nombreComercial, $codLocales);

            if (isset($filaOrigenPorKey[$rowKey])) {
                $errors[] = 'Tienda duplicada en planilla (primera aparición fila ' . $filaOrigenPorKey[$rowKey] . ').';
            } else {
                $filaOrigenPorKey[$rowKey] = $filaNumero;
            }
        } else {
            $groupKey = null;
        }

        if ($fechaInicioOcup !== null) {
            foreach ($ocupaciones as $idx => $ocupacion) {
                $ocupaciones[$idx]['fecha_inicio'] = $fechaInicioOcup;
                $ocupaciones[$idx]['fecha_termino'] = $fechaTerminoOcup;
            }
        }

        $isValid = $errors === [];

        $previewRows[] = [
            'row_number' => $filaNumero,
            'rut_arrendatario' => $rut ?? msp2NormalizeText($rutRaw),
            'arrendatario_nombre' => $arrendatarioNombre,
            'nombre_comercial' => $nombreComercial,
            'cod_locales_display' => $codLocales === [] ? '-' : implode('; ', $codLocales),
            'rubro' => $rubroDesc,
            'estado_tienda' => $estadoDesc,
            'fecha_inicio_tienda' => $fechaInicioTienda,
            'fecha_inicio_ocupacion' => $fechaInicioOcup,
            'fecha_termino_ocupacion' => $fechaTerminoOcup,
            'garantia_clp' => $garantiaClp,
            'garantia_clp_display' => $garantiaClp !== null ? msp2FormatoDecimal($garantiaClp, 0, '$') : '-',
            'arriendo_modalidad' => $arriendoModalidad,
            'arriendo_modalidad_label' => $arriendoModalidadLabel,
            'arriendo_valor_uf' => $arriendoValorUf,
            'arriendo_valor_uf_display' => $arriendoValorUf !== null ? msp2FormatoDecimal($arriendoValorUf, 4) : '-',
            'arriendo_valor_clp' => $arriendoValorClp,
            'arriendo_valor_clp_display' => $arriendoValorClp !== null ? msp2FormatoDecimal($arriendoValorClp, 0, '$') : '-',
            'arriendo_descuento_clp' => $arriendoDescuentoClp,
            'arriendo_descuento_clp_display' => msp2FormatoDecimal($arriendoDescuentoClp, 0, '$'),
            'arriendo_explicit' => $arriendoExplicit,
            'rubro_pending_create' => $rubroPendingCreate,
            'estado_pending_create' => $estadoPendingCreate,
            'status' => $isValid ? 'OK' : 'ERROR',
            'action' => '-',
            'change_details' => [],
            'errors' => $errors,
        ];

        $previewByRowNumber[$filaNumero] = array_key_last($previewRows);

        if ($isValid && $groupKey !== null && $arrendatarioId !== null) {
            $validRows[] = [
                'row_number' => $filaNumero,
                'group_key' => $groupKey,
                'locales_key' => $localesKey,
                'id_arrendatario' => $arrendatarioId,
                'rut_arrendatario' => $rut,
                'nombre_comercial' => $nombreComercial,
                'rubro_desc' => $rubroDesc,
                'estado_desc' => $estadoDesc,
                'fecha_inicio_tienda' => $fechaInicioTienda,
                'fecha_inicio_ocupacion' => $fechaInicioOcup,
                'fecha_termino_ocupacion' => $fechaTerminoOcup,
                'garantia_clp' => $garantiaClp,
                'arriendo_modalidad' => $arriendoModalidad,
                'arriendo_modalidad_label' => $arriendoModalidadLabel,
                'arriendo_valor_uf' => $arriendoValorUf,
                'arriendo_valor_clp' => $arriendoValorClp,
                'arriendo_descuento_clp' => $arriendoDescuentoClp,
                'arriendo_explicit' => $arriendoExplicit,
                'ocupaciones' => $ocupaciones,
                'rubro_pending_create' => $rubroPendingCreate,
                'estado_pending_create' => $estadoPendingCreate,
                'id_tienda_objetivo' => null,
                'action' => '-',
                'change_details' => [],
            ];

            $summary['valid']++;
        } else {
            $summary['errors']++;
            if (count($erroresMuestra) < 5) {
                $erroresMuestra[] = 'Fila ' . $filaNumero . ': ' . implode(' ', $errors);
            }
        }
    }

    if ($summary['processed'] === 0) {
        msp2SetFlash('warning', 'La planilla no contiene filas con datos.');
        msp2Redirect($redirectRoute);
    }

    $arrendatarioIds = [];
    foreach ($validRows as $row) {
        $arrendatarioIds[] = (int) $row['id_arrendatario'];
    }

    $existingByKey = msp2TiendaImportFetchExistingTiendas($conn, $arrendatarioIds);

    $existingTiendaIds = [];
    foreach ($existingByKey as $rowsGroup) {
        foreach ($rowsGroup as $existingTienda) {
            $existingTiendaIds[] = (int) $existingTienda['id_tienda'];
        }
    }

    $existingOcupacionesMap = msp2TiendaImportFetchOcupaciones($conn, $existingTiendaIds);

    $rowsForSession = [];

    foreach ($validRows as $row) {
        $rowNumber = (int) $row['row_number'];
        $previewIndex = $previewByRowNumber[$rowNumber] ?? null;

        $groupKey = (string) ($row['group_key'] ?? '');
        $importedLocalesKey = (string) ($row['locales_key'] ?? '');
        $existingList = $existingByKey[$groupKey] ?? [];

        $matchingExisting = [];
        foreach ($existingList as $existingCandidate) {
            $candidateId = (int) ($existingCandidate['id_tienda'] ?? 0);
            $candidateLocales = $existingOcupacionesMap[$candidateId] ?? [];
            $candidateLocalesKey = msp2TiendaImportLocalesKeyFromOcupaciones($candidateLocales);

            if ($candidateLocalesKey === $importedLocalesKey) {
                $matchingExisting[] = $existingCandidate;
            }
        }

        if (count($matchingExisting) > 1) {
            $summary['valid']--;
            $summary['errors']++;

            $error = 'Existen múltiples tiendas con mismo arrendatario, nombre comercial y códigos locales en BD.';
            if (count($erroresMuestra) < 5) {
                $erroresMuestra[] = 'Fila ' . $rowNumber . ': ' . $error;
            }

            if ($previewIndex !== null) {
                $previewRows[$previewIndex]['status'] = 'ERROR';
                $previewRows[$previewIndex]['errors'] = [$error];
                $previewRows[$previewIndex]['action'] = '-';
            }
            continue;
        }

        if ($matchingExisting === []) {
            $summary['creates']++;
            $details = ['Registro nuevo'];
            if ($existingList !== []) {
                $details[] = 'Mismo nombre comercial con locales distintos: se creará nueva tienda.';
            }
            if ($modoContratoImport && isset($row['garantia_clp']) && $row['garantia_clp'] !== null) {
                $details[] = 'Garantía CLP por local: ' . msp2FormatoDecimal($row['garantia_clp'], 0, '$');
            }
            if ($modoContratoImport) {
                if ((bool) ($row['arriendo_explicit'] ?? false)) {
                    $details[] = 'Regla arriendo contrato-local: '
                        . (string) ($row['arriendo_modalidad_label'] ?? 'UF estático')
                        . ' | UF ' . (($row['arriendo_valor_uf'] ?? null) !== null ? msp2FormatoDecimal($row['arriendo_valor_uf'], 4) : '-')
                        . ' | CLP ' . (($row['arriendo_valor_clp'] ?? null) !== null ? msp2FormatoDecimal($row['arriendo_valor_clp'], 0, '$') : '-')
                        . ' | Desc. ' . msp2FormatoDecimal($row['arriendo_descuento_clp'] ?? '0.00', 0, '$');
                } else {
                    $details[] = 'Arriendo contrato-local: sin override explícito (usa regla default/fallback).';
                }
            }
            if ((bool) $row['rubro_pending_create']) {
                $details[] = 'Rubro nuevo se creará: ' . (string) $row['rubro_desc'];
            }
            if ((bool) $row['estado_pending_create']) {
                $details[] = 'Estado nuevo se creará: ' . (string) $row['estado_desc'];
            }

            $row['action'] = 'CREAR';
            $row['change_details'] = $details;
            $row['id_tienda_objetivo'] = null;
            $rowsForSession[] = $row;

            if ($previewIndex !== null) {
                $previewRows[$previewIndex]['action'] = 'CREAR';
                $previewRows[$previewIndex]['change_details'] = $details;
            }
            continue;
        }

        $existing = $matchingExisting[0];
        $row['id_tienda_objetivo'] = (int) ($existing['id_tienda'] ?? 0);
        $changeDetails = [];

        $oldRubro = msp2NormalizeText((string) ($existing['nombre_rubro'] ?? ''));
        $newRubro = msp2NormalizeText((string) $row['rubro_desc']);
        if (msp2NormalizeLookupKey($oldRubro) !== msp2NormalizeLookupKey($newRubro)) {
            $changeDetails[] = 'Rubro: ' . $oldRubro . ' -> ' . $newRubro;
        }

        $oldEstado = msp2NormalizeText((string) ($existing['desc_estado'] ?? ''));
        $newEstado = msp2NormalizeText((string) $row['estado_desc']);
        if (msp2NormalizeLookupKey($oldEstado) !== msp2NormalizeLookupKey($newEstado)) {
            $changeDetails[] = 'Estado: ' . $oldEstado . ' -> ' . $newEstado;
        }

        $oldFechaInicioTienda = $existing['fecha_inicio'] ? (new DateTimeImmutable((string) $existing['fecha_inicio']))->format('Y-m-d') : null;
        $newFechaInicioTienda = $row['fecha_inicio_tienda'] ?? null;
        if (($oldFechaInicioTienda ?? '') !== ($newFechaInicioTienda ?? '')) {
            $changeDetails[] = 'Fecha inicio tienda actualizada';
        }

        if ($modoContratoImport && isset($row['garantia_clp']) && $row['garantia_clp'] !== null) {
            $changeDetails[] = 'Garantía contrato/locales actualizada: ' . msp2FormatoDecimal($row['garantia_clp'], 0, '$') . ' por local';
        }
        if ($modoContratoImport && (bool) ($row['arriendo_explicit'] ?? false)) {
            $changeDetails[] = 'Regla arriendo contrato-local actualizada: '
                . (string) ($row['arriendo_modalidad_label'] ?? 'UF estático')
                . ' | UF ' . (($row['arriendo_valor_uf'] ?? null) !== null ? msp2FormatoDecimal($row['arriendo_valor_uf'], 4) : '-')
                . ' | CLP ' . (($row['arriendo_valor_clp'] ?? null) !== null ? msp2FormatoDecimal($row['arriendo_valor_clp'], 0, '$') : '-')
                . ' | Desc. ' . msp2FormatoDecimal($row['arriendo_descuento_clp'] ?? '0.00', 0, '$');
        }

        $oldOcupaciones = $existingOcupacionesMap[(int) $existing['id_tienda']] ?? [];
        $newOcupaciones = [];
        foreach ((array) $row['ocupaciones'] as $ocupacion) {
            $newOcupaciones[] = [
                'cdo_local' => (string) ($ocupacion['cdo_local'] ?? ''),
                'fecha_inicio' => $ocupacion['fecha_inicio'] ?? null,
                'fecha_termino' => $ocupacion['fecha_termino'] ?? null,
            ];
        }

        if (msp2TiendaImportCanonicalOcupaciones($oldOcupaciones) !== msp2TiendaImportCanonicalOcupaciones($newOcupaciones)) {
            $changeDetails[] = 'Locales/ocupación actualizados';
        }

        if ($changeDetails === []) {
            $summary['unchanged']++;
            $row['action'] = 'SIN_CAMBIOS';
            $row['change_details'] = ['Sin cambios detectados'];
        } else {
            $summary['updates']++;
            $row['action'] = 'ACTUALIZAR';
            $row['change_details'] = $changeDetails;
        }

        $rowsForSession[] = $row;

        if ($previewIndex !== null) {
            $previewRows[$previewIndex]['action'] = $row['action'];
            $previewRows[$previewIndex]['change_details'] = $row['change_details'];
        }
    }
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible procesar la planilla. Verifica su formato e intenta nuevamente.');
    msp2Redirect($redirectRoute);
}

$token = null;
unset($_SESSION[$sessionPreviewKey]);

if ($summary['errors'] === 0 && ($summary['creates'] + $summary['updates'] + $summary['unchanged']) > 0 && !empty($rowsForSession)) {
    $token = bin2hex(random_bytes(16));

    $_SESSION[$sessionPreviewKey] = [
        'token' => $token,
        'created_at' => time(),
        'file_name' => $originalName,
        'summary' => $summary,
        'rows' => $rowsForSession,
    ];
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | <?php echo msp2Escape($vistaTitulo); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url($redirectRoute)); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i><?php echo msp2Escape($volverLabel); ?>
            </a>
            <span class="section-kicker"><?php echo msp2Escape($sectionLabel); ?></span>
        </div>

        <h1 class="form-title text-center mb-2">Vista Previa de Importación</h1>
        <p class="text-muted text-center mb-4">Archivo: <strong><?php echo msp2Escape($originalName); ?></strong></p>

        <div class="alert alert-secondary" role="alert">
            Por defecto: <strong>Rubro = <?php echo msp2Escape($defaultRubro); ?></strong>, <strong>Estado = <?php echo msp2Escape($defaultEstado); ?></strong>, <strong>Fecha inicio ocupación = fecha_inicio_tienda (si viene) o hoy</strong>.<br>
            Fechas válidas: <code>YYYY-MM-DD</code>, <code>DD-MM-YYYY</code>, <code>10-2025</code>, <code>2025-10</code>.<br>
            <?php if ($modoContratoImport): ?>
                Si informas <code>garantia_clp</code>, se aplica al contrato en cada local de la tienda.<br>
                Arriendo por contrato-local opcional: <code>modalidad_arriendo</code> (<code>UF_ESTATICO</code> o <code>CLP_FIJO</code>), <code>valor_arriendo_uf</code>, <code>valor_arriendo_clp</code>, <code>descuento_arriendo_clp</code>.
            <?php else: ?>
                Las columnas <code>garantia_clp</code> y de arriendo contrato-local no se procesan en importación de tiendas (se gestionan en Contratos).
            <?php endif; ?>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-2"><div class="alert alert-secondary mb-0">Procesadas: <strong><?php echo (int) $summary['processed']; ?></strong></div></div>
            <div class="col-12 col-md-2"><div class="alert alert-success mb-0">Válidas: <strong><?php echo (int) $summary['valid']; ?></strong></div></div>
            <div class="col-12 col-md-2"><div class="alert alert-primary mb-0">Crear: <strong><?php echo (int) $summary['creates']; ?></strong></div></div>
            <div class="col-12 col-md-2"><div class="alert alert-primary mb-0">Actualizar: <strong><?php echo (int) $summary['updates']; ?></strong></div></div>
            <div class="col-12 col-md-2"><div class="alert alert-secondary mb-0">Sin cambios: <strong><?php echo (int) $summary['unchanged']; ?></strong></div></div>
            <div class="col-12 col-md-2"><div class="alert <?php echo $summary['errors'] > 0 ? 'alert-danger' : 'alert-secondary'; ?> mb-0">Con error: <strong><?php echo (int) $summary['errors']; ?></strong></div></div>
        </div>

        <?php if ($erroresMuestra !== []): ?>
            <div class="alert alert-danger" role="alert">
                <strong>La importación no se puede confirmar todavía.</strong><br>
                <?php foreach ($erroresMuestra as $error): ?>
                    - <?php echo msp2Escape($error); ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($token !== null): ?>
            <div class="alert alert-info" role="alert">
                La confirmación se ejecutará en una transacción: si falla una fila, se revierte toda la carga.
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
            <a href="<?php echo msp2Escape(msp2Url($redirectRoute)); ?>" class="btn btn-outline-secondary">Cancelar</a>
            <?php if ($token !== null): ?>
                <form method="post" action="<?php echo msp2Escape(msp2Url($confirmRoute)); ?>">
                    <input type="hidden" name="token" value="<?php echo msp2Escape($token); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Confirmar importación
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <div class="table-responsive mt-3">
            <table class="table table-bordered table-hover align-middle text-center">
                <thead class="table-light">
                    <tr>
                        <th style="width: 90px;">Fila</th>
                        <th>RUT arrendatario</th>
                        <th>Nombre comercial</th>
                        <th>Cod. locales</th>
                        <th>Rubro</th>
                        <th>Estado</th>
                        <th>F. inicio tienda</th>
                        <th>F. inicio ocup.</th>
                        <th>F. término ocup.</th>
                        <?php if ($modoContratoImport): ?>
                            <th>Garantía CLP</th>
                            <th>Modalidad arriendo</th>
                            <th>Arriendo UF</th>
                            <th>Arriendo CLP</th>
                            <th>Descuento CLP</th>
                        <?php endif; ?>
                        <th style="width: 130px;">Acción</th>
                        <th>Comparación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['row_number']; ?></td>
                            <td><?php echo msp2Escape((string) $row['rut_arrendatario']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['nombre_comercial']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['cod_locales_display']); ?></td>
                            <td class="text-start">
                                <?php echo msp2Escape((string) $row['rubro']); ?>
                                <?php if ((bool) ($row['rubro_pending_create'] ?? false)): ?>
                                    <span class="badge bg-warning text-dark ms-1">Nuevo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-start">
                                <?php echo msp2Escape((string) $row['estado_tienda']); ?>
                                <?php if ((bool) ($row['estado_pending_create'] ?? false)): ?>
                                    <span class="badge bg-warning text-dark ms-1">Nuevo</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo msp2Escape((string) ($row['fecha_inicio_tienda'] ?? '-')); ?></td>
                            <td><?php echo msp2Escape((string) ($row['fecha_inicio_ocupacion'] ?? '-')); ?></td>
                            <td><?php echo msp2Escape((string) ($row['fecha_termino_ocupacion'] ?? '-')); ?></td>
                            <?php if ($modoContratoImport): ?>
                                <td><?php echo msp2Escape((string) ($row['garantia_clp_display'] ?? '-')); ?></td>
                                <td><?php echo msp2Escape((string) ($row['arriendo_modalidad_label'] ?? '-')); ?></td>
                                <td><?php echo msp2Escape((string) ($row['arriendo_valor_uf_display'] ?? '-')); ?></td>
                                <td><?php echo msp2Escape((string) ($row['arriendo_valor_clp_display'] ?? '-')); ?></td>
                                <td><?php echo msp2Escape((string) ($row['arriendo_descuento_clp_display'] ?? '-')); ?></td>
                            <?php endif; ?>
                            <td>
                                <?php
                                    $badge = 'bg-secondary';
                                    if ($row['action'] === 'CREAR') {
                                        $badge = 'bg-success';
                                    } elseif ($row['action'] === 'ACTUALIZAR') {
                                        $badge = 'bg-primary';
                                    }
                                ?>
                                <span class="badge <?php echo $badge; ?>"><?php echo msp2Escape((string) $row['action']); ?></span>
                            </td>
                            <td class="text-start">
                                <?php if ($row['status'] === 'OK'): ?>
                                    <?php echo msp2Escape(implode(' | ', (array) $row['change_details'])); ?>
                                <?php else: ?>
                                    <span class="text-danger"><?php echo msp2Escape(implode(' ', (array) $row['errors'])); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
