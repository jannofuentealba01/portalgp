<?php
declare(strict_types=1);

require_once __DIR__ . '/terrenos_repository.php';
require_once dirname(__DIR__, 2) . '/contabilidad/comercial_service.php';

function ctTerrenosAllowedLines(): array
{
    return [10, 25, 50, 100];
}

function ctTerrenosAllowedSort(): array
{
    return [
        'id_terreno' => 't.id_terreno',
        'rol_asignado' => 't.rol_asignado',
        'superficie_m2' => 't.superficie_m2',
        'comuna_nombre' => 'c.nombre',
        'estado_predial_nombre' => 'ep.nombre',
        'estado_comercial_nombre' => 'ec.nombre',
        'tipo_inmueble_nombre' => 'ti.nombre',
    ];
}

function ctTerrenosParseQuery(array $query): array
{
    $allowedLines = ctTerrenosAllowedLines();
    $allowedSort = ctTerrenosAllowedSort();

    $lineasPorPagina = isset($query['lineas']) && is_numeric((string) $query['lineas']) ? (int) $query['lineas'] : 25;
    if (!in_array($lineasPorPagina, $allowedLines, true)) {
        $lineasPorPagina = 25;
    }

    $paginaActual = isset($query['pagina']) && is_numeric((string) $query['pagina']) ? max(1, (int) $query['pagina']) : 1;
    $filtroTexto = ctNormalizeText((string) ($query['filtroTexto'] ?? ''));
    $filtroCampo = strtolower(trim((string) ($query['filtroCampo'] ?? 'todos')));
    $filtroComuna = ctTerrenosNormalizeIdFilter((string) ($query['filtroComuna'] ?? ''));
    $filtroEstadoPredial = ctTerrenosNormalizeIdFilter((string) ($query['filtroEstadoPredial'] ?? ''));
    $filtroEstadoComercial = ctTerrenosNormalizeIdFilter((string) ($query['filtroEstadoComercial'] ?? ''));
    $filtroTipoInmueble = ctTerrenosNormalizeIdFilter((string) ($query['filtroTipoInmueble'] ?? ''));
    $orden = trim((string) ($query['orden'] ?? 'id_terreno'));
    $direccion = strtolower(trim((string) ($query['dir'] ?? 'desc')));
    $vista = trim((string) ($query['vista'] ?? 'tabla'));
    $modal = trim((string) ($query['modal'] ?? ''));

    if (!isset($allowedSort[$orden])) {
        $orden = 'id_terreno';
    }
    if ($direccion !== 'asc' && $direccion !== 'desc') {
        $direccion = 'desc';
    }
    if ($vista !== 'tabla' && $vista !== 'cards') {
        $vista = 'tabla';
    }
    $allowedCampos = ['todos', 'rol', 'identificacion', 'propietario'];
    if (!in_array($filtroCampo, $allowedCampos, true)) {
        $filtroCampo = 'todos';
    }

    $allowedModals = ['adquisicion', 'titularidad', 'subdivision', 'fusion', 'tasacion', 'venta'];
    if (!in_array($modal, $allowedModals, true)) {
        $modal = '';
    }

    $queryBase = [
        'filtroTexto' => $filtroTexto,
        'filtroCampo' => $filtroCampo,
        'filtroComuna' => $filtroComuna,
        'filtroEstadoPredial' => $filtroEstadoPredial,
        'filtroEstadoComercial' => $filtroEstadoComercial,
        'filtroTipoInmueble' => $filtroTipoInmueble,
        'lineas' => $lineasPorPagina,
        'orden' => $orden,
        'dir' => $direccion,
        'vista' => $vista,
    ];

    return [
        'lineasPermitidas' => $allowedLines,
        'sortPermitidos' => $allowedSort,
        'lineasPorPagina' => $lineasPorPagina,
        'paginaActual' => $paginaActual,
        'filtroTexto' => $filtroTexto,
        'filtroCampo' => $filtroCampo,
        'filtroComuna' => $filtroComuna,
        'filtroEstadoPredial' => $filtroEstadoPredial,
        'filtroEstadoComercial' => $filtroEstadoComercial,
        'filtroTipoInmueble' => $filtroTipoInmueble,
        'orden' => $orden,
        'direccion' => $direccion,
        'vista' => $vista,
        'modal' => $modal,
        'queryBase' => $queryBase,
    ];
}

function ctTerrenosNormalizeIdFilter(string $value): string
{
    if (!is_numeric($value)) {
        return '';
    }
    $id = (int) $value;
    return $id > 0 ? (string) $id : '';
}

function ctTerrenosBuildQuery(array $base, array $override = []): string
{
    $merged = array_merge($base, $override);
    foreach ($merged as $key => $value) {
        if ($value === '' || $value === null) {
            unset($merged[$key]);
        }
    }

    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
}

function ctTerrenosRedirectAfterPost(array $base): never
{
    $qs = http_build_query(array_filter($base, static fn($v) => $v !== '' && $v !== null));
    header('Location: ' . ($qs !== '' ? ('?' . $qs) : ''));
    exit();
}

function ctTerrenosNormalizeWritePayload(array $post): array
{
    $rolAsignado = strtoupper(ctNormalizeText((string) ($post['rol_asignado'] ?? '')));
    $rolMatriz = strtoupper(ctNormalizeText((string) ($post['rol_matriz'] ?? '')));
    $identificacionPropiedad = ctNormalizeText((string) ($post['identificacion_propiedad'] ?? ''));
    $superficieM2 = ctTerrenosNormalizeSuperficie((string) ($post['superficie_m2'] ?? ''));

    $idComuna = isset($post['id_comuna']) && is_numeric((string) $post['id_comuna']) ? (int) $post['id_comuna'] : 0;
    $idEstadoPredial = isset($post['id_estado_predial']) && is_numeric((string) $post['id_estado_predial']) ? (int) $post['id_estado_predial'] : 0;
    $idEstadoComercial = isset($post['id_estado_comercial']) && is_numeric((string) $post['id_estado_comercial']) ? (int) $post['id_estado_comercial'] : 0;
    $idTipoInmueble = isset($post['id_tipo_inmueble']) && is_numeric((string) $post['id_tipo_inmueble']) ? (int) $post['id_tipo_inmueble'] : 0;

    return [
        'rol_asignado' => $rolAsignado,
        'rol_matriz' => $rolMatriz !== '' ? $rolMatriz : null,
        'identificacion_propiedad' => $identificacionPropiedad !== '' ? $identificacionPropiedad : null,
        'superficie_m2' => $superficieM2,
        'id_comuna' => $idComuna,
        'id_estado_predial' => $idEstadoPredial,
        'id_estado_comercial' => $idEstadoComercial,
        'id_tipo_inmueble' => $idTipoInmueble,
    ];
}

function ctTerrenosNormalizeSuperficie(string $rawValue): float
{
    $value = trim($rawValue);
    if ($value === '') {
        throw new RuntimeException('Debes ingresar la superficie del terreno.');
    }

    $value = preg_replace('/\s+/', '', $value);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Superficie inválida.');
    }

    $value = preg_replace('/[^0-9,\.]/', '', $value);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException('Superficie inválida.');
    }

    $hasComma = strpos($value, ',') !== false;
    $hasDot = strpos($value, '.') !== false;
    if ($hasComma && $hasDot) {
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        if ($lastComma !== false && $lastDot !== false && $lastComma > $lastDot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($hasComma) {
        $value = str_replace(',', '.', $value);
    }

    if (!is_numeric($value)) {
        throw new RuntimeException('Superficie inválida. Usa un número positivo.');
    }

    $number = (float) $value;
    if ($number <= 0) {
        throw new RuntimeException('La superficie debe ser mayor a cero.');
    }

    return round($number, 2);
}

function ctTerrenosNormalizePorcentaje(string $rawValue, string $label = 'porcentaje'): float
{
    $value = trim($rawValue);
    if ($value === '') {
        throw new RuntimeException('Debes ingresar el ' . $label . '.');
    }

    $value = str_replace('%', '', $value);
    $value = str_replace(',', '.', $value);
    if (!is_numeric($value)) {
        throw new RuntimeException('El ' . $label . ' es inválido.');
    }

    $number = round((float) $value, 2);
    if ($number <= 0 || $number > 100) {
        throw new RuntimeException('El ' . $label . ' debe ser mayor a 0 y menor o igual a 100.');
    }

    return $number;
}

function ctTerrenosNormalizeDate(string $rawValue, string $label): string
{
    $value = trim($rawValue);
    if ($value === '') {
        throw new RuntimeException('Debes ingresar ' . $label . '.');
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if (!($dt instanceof DateTimeImmutable) || $dt->format('Y-m-d') !== $value) {
        throw new RuntimeException('La ' . $label . ' es inválida. Usa formato YYYY-MM-DD.');
    }

    return $value;
}

function ctTerrenosNormalizeOptionalDate(string $rawValue, string $label): ?string
{
    $value = trim($rawValue);
    if ($value === '') {
        return null;
    }

    return ctTerrenosNormalizeDate($value, $label);
}

function ctTerrenosNormalizeOperacionDocumento(string $rawValue): ?string
{
    $value = ctNormalizeText($rawValue);
    if ($value === '') {
        return null;
    }

    $len = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($len > 255) {
        throw new RuntimeException('El documento fuente excede el máximo de 255 caracteres.');
    }

    return $value;
}

function ctTerrenosCurrentUserId(): int
{
    $idUsuario = (int) ($_SESSION['usuario']['id'] ?? 0);
    if ($idUsuario <= 0) {
        throw new RuntimeException('No fue posible identificar al usuario actual para registrar historial.');
    }
    return $idUsuario;
}

function ctTerrenosParseTerrenosIdsInput(string $rawValue, string $label, int $minCount = 1): array
{
    $clean = trim($rawValue);
    if ($clean === '') {
        throw new RuntimeException('Debes indicar ' . $label . '.');
    }

    $parts = preg_split('/[\s,;]+/', $clean) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value === '') {
            continue;
        }

        if (!ctype_digit($value)) {
            throw new RuntimeException('Formato inválido en ' . $label . '. Usa solo IDs numéricos separados por coma.');
        }

        $id = (int) $value;
        if ($id <= 0) {
            continue;
        }
        $ids[$id] = true;
    }

    $result = array_map('intval', array_keys($ids));
    if (count($result) < $minCount) {
        throw new RuntimeException('Debes indicar al menos ' . $minCount . ' ID(s) en ' . $label . '.');
    }

    return $result;
}

function ctTerrenosParseTitularesInput(string $rawValue, string $fechaPorDefecto): array
{
    $lines = preg_split('/\r\n|\r|\n/', $rawValue) ?: [];
    $titulares = [];

    foreach ($lines as $idx => $line) {
        $row = trim((string) $line);
        if ($row === '') {
            continue;
        }

        $parts = array_map('trim', explode('|', $row));
        if (count($parts) < 2) {
            throw new RuntimeException('Formato de titularidad inválido en línea ' . ($idx + 1) . '. Usa: id_tercero|porcentaje|vigente_desde|vigente_hasta');
        }

        if (!ctype_digit($parts[0])) {
            throw new RuntimeException('ID de tercero inválido en línea ' . ($idx + 1) . '.');
        }

        $idTercero = (int) $parts[0];
        $porcentaje = ctTerrenosNormalizePorcentaje($parts[1], 'porcentaje de titularidad (línea ' . ($idx + 1) . ')');
        $vigenteDesde = isset($parts[2]) && $parts[2] !== ''
            ? ctTerrenosNormalizeDate($parts[2], 'vigente_desde de titularidad (línea ' . ($idx + 1) . ')')
            : $fechaPorDefecto;
        $vigenteHasta = isset($parts[3]) && $parts[3] !== ''
            ? ctTerrenosNormalizeDate($parts[3], 'vigente_hasta de titularidad (línea ' . ($idx + 1) . ')')
            : null;

        if ($vigenteHasta !== null && $vigenteHasta < $vigenteDesde) {
            throw new RuntimeException('Vigencia inválida en línea ' . ($idx + 1) . ': vigente_hasta no puede ser menor a vigente_desde.');
        }

        $titulares[] = [
            'id_tercero' => $idTercero,
            'porcentaje_derecho' => $porcentaje,
            'vigente_desde' => $vigenteDesde,
            'vigente_hasta' => $vigenteHasta,
        ];
    }

    if ($titulares === []) {
        throw new RuntimeException('Debes informar al menos una titularidad inicial.');
    }

    return $titulares;
}

function ctTerrenosParseTitularesFromTable(array $post, string $fechaPorDefecto): array
{
    $idsRaw = isset($post['titulares_id_tercero']) && is_array($post['titulares_id_tercero'])
        ? $post['titulares_id_tercero']
        : [];
    $porcentajesRaw = isset($post['titulares_porcentaje_derecho']) && is_array($post['titulares_porcentaje_derecho'])
        ? $post['titulares_porcentaje_derecho']
        : [];
    $desdeRaw = isset($post['titulares_vigente_desde']) && is_array($post['titulares_vigente_desde'])
        ? $post['titulares_vigente_desde']
        : [];
    $hastaRaw = isset($post['titulares_vigente_hasta']) && is_array($post['titulares_vigente_hasta'])
        ? $post['titulares_vigente_hasta']
        : [];

    $maxRows = max(count($idsRaw), count($porcentajesRaw), count($desdeRaw), count($hastaRaw));
    $titulares = [];

    for ($i = 0; $i < $maxRows; $i++) {
        $idRaw = trim((string) ($idsRaw[$i] ?? ''));
        $pctRaw = trim((string) ($porcentajesRaw[$i] ?? ''));
        $desdeValue = trim((string) ($desdeRaw[$i] ?? ''));
        $hastaValue = trim((string) ($hastaRaw[$i] ?? ''));

        if ($idRaw === '' && $pctRaw === '' && $desdeValue === '' && $hastaValue === '') {
            continue;
        }

        if ($idRaw === '' || !ctype_digit($idRaw)) {
            throw new RuntimeException('Debes seleccionar un tercero válido en la tabla de titulares iniciales (fila ' . ($i + 1) . ').');
        }

        if ($pctRaw === '') {
            throw new RuntimeException('Debes ingresar el porcentaje en la tabla de titulares iniciales (fila ' . ($i + 1) . ').');
        }

        $idTercero = (int) $idRaw;
        $porcentaje = ctTerrenosNormalizePorcentaje($pctRaw, 'porcentaje de titularidad (fila ' . ($i + 1) . ')');
        $vigenteDesde = $desdeValue !== ''
            ? ctTerrenosNormalizeDate($desdeValue, 'vigente_desde de titularidad (fila ' . ($i + 1) . ')')
            : $fechaPorDefecto;
        $vigenteHasta = $hastaValue !== ''
            ? ctTerrenosNormalizeDate($hastaValue, 'vigente_hasta de titularidad (fila ' . ($i + 1) . ')')
            : null;

        if ($vigenteHasta !== null && $vigenteHasta < $vigenteDesde) {
            throw new RuntimeException('Vigencia inválida en fila ' . ($i + 1) . ': vigente_hasta no puede ser menor a vigente_desde.');
        }

        $titulares[] = [
            'id_tercero' => $idTercero,
            'porcentaje_derecho' => $porcentaje,
            'vigente_desde' => $vigenteDesde,
            'vigente_hasta' => $vigenteHasta,
        ];
    }

    if ($titulares === []) {
        throw new RuntimeException('Debes agregar al menos un titular inicial.');
    }

    return $titulares;
}

function ctTerrenosParseSubdivisionResultadosFromTable(array $post): array
{
    $rolesRaw = isset($post['subdivision_result_rol_asignado']) && is_array($post['subdivision_result_rol_asignado'])
        ? $post['subdivision_result_rol_asignado']
        : [];
    $superficiesRaw = isset($post['subdivision_result_superficie_m2']) && is_array($post['subdivision_result_superficie_m2'])
        ? $post['subdivision_result_superficie_m2']
        : [];

    $maxRows = max(count($rolesRaw), count($superficiesRaw));
    $resultados = [];
    $rolesSeen = [];

    for ($i = 0; $i < $maxRows; $i++) {
        $rolRaw = trim((string) ($rolesRaw[$i] ?? ''));
        $superficieRaw = trim((string) ($superficiesRaw[$i] ?? ''));

        if ($rolRaw === '' && $superficieRaw === '') {
            continue;
        }

        $rolAsignado = strtoupper(ctNormalizeText($rolRaw));
        if ($rolAsignado === '') {
            throw new RuntimeException('Debes ingresar el rol asignado en la subdivisión (fila ' . ($i + 1) . ').');
        }

        $lenRol = function_exists('mb_strlen') ? mb_strlen($rolAsignado) : strlen($rolAsignado);
        if ($lenRol > 30) {
            throw new RuntimeException('El rol asignado en subdivisión excede 30 caracteres (fila ' . ($i + 1) . ').');
        }

        if ($superficieRaw === '') {
            throw new RuntimeException('Debes ingresar la superficie en subdivisión (fila ' . ($i + 1) . ').');
        }
        $superficie = ctTerrenosNormalizeSuperficie($superficieRaw);

        if (isset($rolesSeen[$rolAsignado])) {
            throw new RuntimeException('No puedes repetir el rol asignado en resultados de subdivisión: ' . $rolAsignado . '.');
        }
        $rolesSeen[$rolAsignado] = true;

        $resultados[] = [
            'rol_asignado' => $rolAsignado,
            'superficie_m2' => $superficie,
        ];
    }

    if (count($resultados) < 2) {
        throw new RuntimeException('Debes ingresar al menos 2 terrenos resultado en la subdivisión.');
    }

    return $resultados;
}

function ctTerrenosValidateTitularesPayload(PDO $conn, array $titulares): void
{
    $suma = 0.0;
    foreach ($titulares as $index => $titular) {
        $idTercero = (int) ($titular['id_tercero'] ?? 0);
        if ($idTercero <= 0 || !ctTerrenosRepoTerceroExistsById($conn, $idTercero)) {
            throw new RuntimeException('El tercero de la titularidad en línea ' . ($index + 1) . ' no existe.');
        }

        $suma += (float) ($titular['porcentaje_derecho'] ?? 0);
    }

    if (round($suma, 2) !== 100.00) {
        throw new RuntimeException('La suma de porcentajes de titularidad inicial debe ser exactamente 100.00.');
    }
}

function ctTerrenosValidateTerrenoExists(PDO $conn, int $idTerreno, string $label): void
{
    if ($idTerreno <= 0) {
        throw new RuntimeException('ID inválido para ' . $label . '.');
    }

    $row = ctTerrenosRepoFindById($conn, $idTerreno);
    if (!is_array($row)) {
        throw new RuntimeException('No existe el terreno indicado para ' . $label . '.');
    }
}

function ctTerrenosValidateWritePayload(PDO $conn, array $payload, ?int $excludeIdTerreno = null): void
{
    $len = static function (string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    };

    if ((string) $payload['rol_asignado'] === '') {
        throw new RuntimeException('Debes ingresar el rol asignado.');
    }

    if ($len((string) $payload['rol_asignado']) > 30) {
        throw new RuntimeException('Rol asignado excede el máximo de 30 caracteres.');
    }
    if (ctTerrenosRepoRolAsignadoExists($conn, (string) $payload['rol_asignado'], $excludeIdTerreno)) {
        throw new RuntimeException('Ya existe un terreno con el rol asignado "' . (string) $payload['rol_asignado'] . '".');
    }
    if ($payload['rol_matriz'] !== null && $len((string) $payload['rol_matriz']) > 30) {
        throw new RuntimeException('Rol matriz excede el máximo de 30 caracteres.');
    }
    if ($payload['identificacion_propiedad'] !== null && $len((string) $payload['identificacion_propiedad']) > 120) {
        throw new RuntimeException('Identificación de propiedad excede el máximo de 120 caracteres.');
    }

    if ((float) $payload['superficie_m2'] <= 0) {
        throw new RuntimeException('La superficie debe ser mayor a cero.');
    }

    if ((int) $payload['id_comuna'] <= 0) {
        throw new RuntimeException('Debes seleccionar una comuna.');
    }
    if ((int) $payload['id_estado_predial'] <= 0) {
        throw new RuntimeException('Debes seleccionar un estado.');
    }
    if ((int) $payload['id_tipo_inmueble'] <= 0) {
        throw new RuntimeException('Debes seleccionar un tipo.');
    }

    if (!ctTerrenosRepoComunaExistsById($conn, (int) $payload['id_comuna'])) {
        throw new RuntimeException('La comuna seleccionada no existe o fue eliminada.');
    }
    if (!ctTerrenosRepoEstadoPredialExistsById($conn, (int) $payload['id_estado_predial'])) {
        throw new RuntimeException('El estado seleccionado no existe.');
    }
    if ((int) $payload['id_estado_comercial'] > 0 && !ctTerrenosRepoEstadoComercialExistsById($conn, (int) $payload['id_estado_comercial'])) {
        throw new RuntimeException('El estado comercial seleccionado no existe.');
    }
    if (!ctTerrenosRepoTipoInmuebleExistsById($conn, (int) $payload['id_tipo_inmueble'])) {
        throw new RuntimeException('El tipo seleccionado no existe o está inactivo.');
    }
}

function ctTerrenosValidateAdquisicionPayload(PDO $conn, array $payload): void
{
    $len = static function (string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    };

    if ((string) $payload['rol_asignado'] === '') {
        throw new RuntimeException('Debes ingresar el rol asignado.');
    }

    if ($len((string) $payload['rol_asignado']) > 30) {
        throw new RuntimeException('Rol asignado excede el máximo de 30 caracteres.');
    }
    if (ctTerrenosRepoRolAsignadoExists($conn, (string) $payload['rol_asignado'])) {
        throw new RuntimeException('Ya existe un terreno con el rol asignado "' . (string) $payload['rol_asignado'] . '".');
    }
    if ($payload['rol_matriz'] !== null && $len((string) $payload['rol_matriz']) > 30) {
        throw new RuntimeException('Rol matriz excede el máximo de 30 caracteres.');
    }
    if ($payload['identificacion_propiedad'] !== null && $len((string) $payload['identificacion_propiedad']) > 120) {
        throw new RuntimeException('Identificación de propiedad excede el máximo de 120 caracteres.');
    }

    if ((float) $payload['superficie_m2'] <= 0) {
        throw new RuntimeException('La superficie debe ser mayor a cero.');
    }

    if ((int) $payload['id_comuna'] <= 0) {
        throw new RuntimeException('Debes seleccionar una comuna.');
    }
    if ((int) $payload['id_tipo_inmueble'] <= 0) {
        throw new RuntimeException('Debes seleccionar un tipo.');
    }

    if (!ctTerrenosRepoComunaExistsById($conn, (int) $payload['id_comuna'])) {
        throw new RuntimeException('La comuna seleccionada no existe o fue eliminada.');
    }
    if (!ctTerrenosRepoTipoInmuebleExistsById($conn, (int) $payload['id_tipo_inmueble'])) {
        throw new RuntimeException('El tipo seleccionado no existe o está inactivo.');
    }
}

function ctTerrenosResolveEstadoComercialId(PDO $conn, int $requestedId, ?int $fallbackCurrentId = null): int
{
    if ($requestedId > 0) {
        if (!ctTerrenosRepoEstadoComercialExistsById($conn, $requestedId)) {
            throw new RuntimeException('El estado comercial seleccionado no existe.');
        }
        return $requestedId;
    }

    if ($fallbackCurrentId !== null && $fallbackCurrentId > 0) {
        if (!ctTerrenosRepoEstadoComercialExistsById($conn, $fallbackCurrentId)) {
            throw new RuntimeException('El estado comercial actual del terreno ya no existe.');
        }
        return $fallbackCurrentId;
    }

    $defaultId = ctTerrenosRepoFirstEstadoComercialId($conn);
    if ($defaultId <= 0) {
        $defaultId = ctTerrenosRepoEnsureEstadoComercialDefault($conn);
    }
    if ($defaultId <= 0) {
        throw new RuntimeException('No fue posible resolver un estado comercial por defecto.');
    }

    return $defaultId;
}

function ctTerrenosModalForAction(string $accion): string
{
    return match ($accion) {
        'registrar_adquisicion' => 'adquisicion',
        'registrar_titularidad' => 'titularidad',
        'registrar_subdivision' => 'subdivision',
        'registrar_fusion' => 'fusion',
        'registrar_tasacion' => 'tasacion',
        'registrar_venta' => 'venta',
        default => '',
    };
}

function ctTerrenosActionForModal(string $modal): string
{
    return match (trim($modal)) {
        'adquisicion' => 'registrar_adquisicion',
        'titularidad' => 'registrar_titularidad',
        'subdivision' => 'registrar_subdivision',
        'fusion' => 'registrar_fusion',
        'tasacion' => 'registrar_tasacion',
        'venta' => 'registrar_venta',
        default => '',
    };
}

function ctTerrenosFormStateSessionKey(): string
{
    return 'ct_terrenos_form_state';
}

function ctTerrenosFormStateNormalizeValue($value)
{
    if (is_array($value)) {
        $normalized = [];
        foreach ($value as $k => $v) {
            if (!is_string($k) && !is_int($k)) {
                continue;
            }
            $normalized[$k] = ctTerrenosFormStateNormalizeValue($v);
        }
        return $normalized;
    }

    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        return $value;
    }

    return '';
}

function ctTerrenosStoreFormState(string $accion, array $post): void
{
    $modal = ctTerrenosModalForAction($accion);
    if ($modal === '') {
        return;
    }

    $payload = $post;
    unset($payload['_csrf'], $payload['accion']);

    if (!isset($_SESSION[ctTerrenosFormStateSessionKey()]) || !is_array($_SESSION[ctTerrenosFormStateSessionKey()])) {
        $_SESSION[ctTerrenosFormStateSessionKey()] = [];
    }

    $_SESSION[ctTerrenosFormStateSessionKey()][$accion] = ctTerrenosFormStateNormalizeValue($payload);
}

function ctTerrenosClearFormState(string $accion): void
{
    $key = ctTerrenosFormStateSessionKey();
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return;
    }

    unset($_SESSION[$key][$accion]);
}

function ctTerrenosPullFormStateForModal(string $modal): array
{
    $accion = ctTerrenosActionForModal($modal);
    if ($accion === '') {
        return [];
    }

    $key = ctTerrenosFormStateSessionKey();
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return [];
    }

    $payload = $_SESSION[$key][$accion] ?? null;
    unset($_SESSION[$key][$accion]);

    if (!is_array($payload)) {
        return [];
    }

    return [
        'accion' => $accion,
        'payload' => $payload,
    ];
}

function ctTerrenosHandlePost(PDO $conn, array $post, array $queryBase): never
{
    $accion = trim((string) ($post['accion'] ?? ''));

    if ($accion === 'registrar_tasacion' || $accion === 'registrar_venta') {
        ctComercialHandlePost($conn, $post, $queryBase);
    }

    try {
        if ($accion === 'crear_terreno') {
            $payload = ctTerrenosNormalizeWritePayload($post);
            ctTerrenosValidateWritePayload($conn, $payload);
            $idEstadoComercial = ctTerrenosResolveEstadoComercialId(
                $conn,
                (int) $payload['id_estado_comercial'],
                null
            );

            ctTerrenosRepoInsert(
                $conn,
                (string) $payload['rol_asignado'],
                is_string($payload['rol_matriz']) ? $payload['rol_matriz'] : null,
                is_string($payload['identificacion_propiedad']) ? $payload['identificacion_propiedad'] : null,
                (float) $payload['superficie_m2'],
                (int) $payload['id_comuna'],
                (int) $payload['id_estado_predial'],
                $idEstadoComercial,
                (int) $payload['id_tipo_inmueble']
            );
            ctSetFlash('success', 'Terreno creado correctamente.');
            ctTerrenosClearFormState($accion);
            $queryBase['pagina'] = 1;
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'registrar_adquisicion') {
            $payload = ctTerrenosNormalizeWritePayload($post);
            ctTerrenosValidateAdquisicionPayload($conn, $payload);
            $payload['id_estado_predial'] = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
            $payload['id_estado_comercial'] = ctTerrenosRepoEnsureEstadoComercialDefault($conn);
            $payload['fecha_adquisicion'] = ctTerrenosNormalizeDate(
                (string) ($post['fecha_adquisicion'] ?? ''),
                'la fecha de adquisición'
            );
            $payload['documento_fuente'] = ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? ''));

            $titulares = ctTerrenosParseTitularesFromTable(
                $post,
                (string) $payload['fecha_adquisicion']
            );
            ctTerrenosValidateTitularesPayload($conn, $titulares);

            $result = ctTerrenosRepoCrearAdquisicion($conn, $payload, $titulares, ctTerrenosCurrentUserId());
            ctSetFlash(
                'success',
                'Adquisición registrada correctamente. Terreno #' . (int) ($result['id_terreno'] ?? 0)
                . ' / Operación #' . (int) ($result['id_operacion'] ?? 0) . '.'
            );
            ctTerrenosClearFormState($accion);
            $queryBase['pagina'] = 1;
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'registrar_titularidad') {
            $idTerreno = isset($post['id_terreno']) && is_numeric((string) $post['id_terreno']) ? (int) $post['id_terreno'] : 0;
            $idTercero = isset($post['id_tercero']) && is_numeric((string) $post['id_tercero']) ? (int) $post['id_tercero'] : 0;
            $porcentaje = ctTerrenosNormalizePorcentaje((string) ($post['porcentaje_derecho'] ?? ''), 'porcentaje de derecho');
            $vigenteDesde = ctTerrenosNormalizeDate((string) ($post['vigente_desde'] ?? ''), 'vigente_desde');
            $vigenteHasta = ctTerrenosNormalizeOptionalDate((string) ($post['vigente_hasta'] ?? ''), 'vigente_hasta');
            $cerrarVigenteActual = isset($post['cerrar_vigente_actual'])
                && in_array((string) $post['cerrar_vigente_actual'], ['1', 'on', 'true'], true);

            if ($vigenteHasta !== null && $vigenteHasta < $vigenteDesde) {
                throw new RuntimeException('Vigencia inválida: vigente_hasta no puede ser menor a vigente_desde.');
            }

            ctTerrenosValidateTerrenoExists($conn, $idTerreno, 'titularidad');
            if ($idTercero <= 0 || !ctTerrenosRepoTerceroExistsById($conn, $idTercero)) {
                throw new RuntimeException('El tercero seleccionado no existe.');
            }

            $idTitularidad = ctTerrenosRepoRegistrarTitularidad($conn, [
                'id_terreno' => $idTerreno,
                'id_tercero' => $idTercero,
                'vigente_desde' => $vigenteDesde,
                'vigente_hasta' => $vigenteHasta,
                'porcentaje_derecho' => $porcentaje,
                'cerrar_vigente_actual' => $cerrarVigenteActual,
            ]);

            ctSetFlash('success', 'Titularidad registrada correctamente (#' . $idTitularidad . ').');
            ctTerrenosClearFormState($accion);
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'registrar_subdivision') {
            $idTerrenoOrigen = isset($post['id_terreno_origen']) && is_numeric((string) $post['id_terreno_origen'])
                ? (int) $post['id_terreno_origen']
                : 0;
            $resultadosSubdivision = ctTerrenosParseSubdivisionResultadosFromTable($post);
            foreach ($resultadosSubdivision as $resultadoSubdivision) {
                $rolResultado = (string) ($resultadoSubdivision['rol_asignado'] ?? '');
                if (ctTerrenosRepoRolAsignadoExists($conn, $rolResultado)) {
                    throw new RuntimeException('Ya existe un terreno con el rol asignado "' . $rolResultado . '".');
                }
            }

            ctTerrenosValidateTerrenoExists($conn, $idTerrenoOrigen, 'subdivisión (origen)');

            $origenSubdivision = ctTerrenosRepoFindSubdivisionOrigenById($conn, $idTerrenoOrigen);
            if (!is_array($origenSubdivision)) {
                throw new RuntimeException('No fue posible obtener la información del terreno origen.');
            }
            $idEstadoDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
            if ((int) ($origenSubdivision['id_estado_predial'] ?? 0) !== $idEstadoDisponible) {
                throw new RuntimeException('El terreno origen debe estar en estado Disponible para registrar subdivisión.');
            }
            $superficieOrigen = round((float) ($origenSubdivision['superficie_m2'] ?? 0), 2);
            $sumaResultados = 0.0;
            foreach ($resultadosSubdivision as $rowResultado) {
                $sumaResultados += round((float) ($rowResultado['superficie_m2'] ?? 0), 2);
            }
            if ((int) round($sumaResultados * 100) !== (int) round($superficieOrigen * 100)) {
                throw new RuntimeException(
                    'La suma de superficie de resultados (' . number_format($sumaResultados, 2, '.', '')
                    . ') debe coincidir con la superficie del origen (' . number_format($superficieOrigen, 2, '.', '') . ').'
                );
            }

            $idOperacion = ctTerrenosRepoRegistrarSubdivision(
                $conn,
                [
                    'id_terreno_origen' => $idTerrenoOrigen,
                    'resultados' => $resultadosSubdivision,
                    'fecha_operacion' => ctTerrenosNormalizeDate((string) ($post['fecha_operacion'] ?? ''), 'la fecha de operación'),
                    'documento_fuente' => ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? '')),
                ],
                ctTerrenosCurrentUserId()
            );

            ctSetFlash('success', 'Subdivisión registrada correctamente. Operación #' . $idOperacion . '.');
            ctTerrenosClearFormState($accion);
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'registrar_fusion') {
            $idsOrigen = ctTerrenosParseTerrenosIdsInput(
                (string) ($post['ids_terrenos_origen'] ?? ''),
                'los terrenos origen de la fusión',
                2
            );
            $resultadoModo = strtolower(trim((string) ($post['fusion_resultado_modo'] ?? 'nuevo')));
            if ($resultadoModo !== 'nuevo' && $resultadoModo !== 'existente') {
                $resultadoModo = 'nuevo';
            }
            $idEstadoDisponible = ctTerrenosRepoEnsureEstadoPredialDisponible($conn);
            $idEstadoSubdividido = ctTerrenosRepoEnsureEstadoPredialSubdividido($conn);

            foreach ($idsOrigen as $idOrigen) {
                ctTerrenosValidateTerrenoExists($conn, (int) $idOrigen, 'fusión (origen)');
                $origenFusion = ctTerrenosRepoFindById($conn, (int) $idOrigen);
                if (!is_array($origenFusion) || (int) ($origenFusion['id_estado_predial'] ?? 0) !== $idEstadoDisponible) {
                    throw new RuntimeException('Todos los terrenos origen de la fusión deben estar en estado Disponible.');
                }
            }

            $payloadFusion = [
                'ids_terrenos_origen' => $idsOrigen,
                'fecha_operacion' => ctTerrenosNormalizeDate((string) ($post['fecha_operacion'] ?? ''), 'la fecha de operación'),
                'documento_fuente' => ctTerrenosNormalizeOperacionDocumento((string) ($post['documento_fuente'] ?? '')),
                'resultado_modo' => $resultadoModo,
            ];

            if ($resultadoModo === 'existente') {
                $idTerrenoResultado = isset($post['id_terreno_resultado']) && is_numeric((string) $post['id_terreno_resultado'])
                    ? (int) $post['id_terreno_resultado']
                    : 0;
                ctTerrenosValidateTerrenoExists($conn, $idTerrenoResultado, 'fusión (resultado)');
                if (in_array($idTerrenoResultado, $idsOrigen, true)) {
                    throw new RuntimeException('El terreno resultado no puede ser parte de los orígenes de la fusión.');
                }
                $resultadoFusion = ctTerrenosRepoFindById($conn, $idTerrenoResultado);
                if (!is_array($resultadoFusion) || (int) ($resultadoFusion['id_estado_predial'] ?? 0) !== $idEstadoSubdividido) {
                    throw new RuntimeException('El terreno resultado existente debe estar en estado Subdividido.');
                }
                $payloadFusion['id_terreno_resultado'] = $idTerrenoResultado;
            } else {
                $rolResultadoNuevo = strtoupper(ctNormalizeText((string) ($post['fusion_resultado_nuevo_rol_asignado'] ?? '')));
                if ($rolResultadoNuevo === '') {
                    throw new RuntimeException('Debes ingresar el rol asignado del nuevo terreno resultado.');
                }
                $lenRol = function_exists('mb_strlen') ? mb_strlen($rolResultadoNuevo) : strlen($rolResultadoNuevo);
                if ($lenRol > 30) {
                    throw new RuntimeException('El rol asignado del nuevo resultado excede 30 caracteres.');
                }
                if (ctTerrenosRepoRolAsignadoExists($conn, $rolResultadoNuevo)) {
                    throw new RuntimeException('Ya existe un terreno con el rol asignado "' . $rolResultadoNuevo . '".');
                }
                $payloadFusion['resultado_nuevo_rol_asignado'] = $rolResultadoNuevo;
            }

            $idOperacion = ctTerrenosRepoRegistrarFusion(
                $conn,
                $payloadFusion,
                ctTerrenosCurrentUserId()
            );

            ctSetFlash('success', 'Fusión registrada correctamente. Operación #' . $idOperacion . '.');
            ctTerrenosClearFormState($accion);
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'editar_terreno') {
            $idTerreno = isset($post['id_terreno']) && is_numeric((string) $post['id_terreno']) ? (int) $post['id_terreno'] : 0;
            if ($idTerreno <= 0) {
                throw new RuntimeException('ID de terreno inválido para actualizar.');
            }
            $current = ctTerrenosRepoFindById($conn, $idTerreno);
            if (!is_array($current)) {
                throw new RuntimeException('El terreno que intentas actualizar ya no existe.');
            }

            $payload = ctTerrenosNormalizeWritePayload($post);
            ctTerrenosValidateWritePayload($conn, $payload, $idTerreno);
            $idEstadoComercial = ctTerrenosResolveEstadoComercialId(
                $conn,
                (int) $payload['id_estado_comercial'],
                (int) ($current['id_estado_comercial'] ?? 0)
            );

            ctTerrenosRepoUpdate(
                $conn,
                $idTerreno,
                (string) $payload['rol_asignado'],
                is_string($payload['rol_matriz']) ? $payload['rol_matriz'] : null,
                is_string($payload['identificacion_propiedad']) ? $payload['identificacion_propiedad'] : null,
                (float) $payload['superficie_m2'],
                (int) $payload['id_comuna'],
                (int) $payload['id_estado_predial'],
                $idEstadoComercial,
                (int) $payload['id_tipo_inmueble']
            );

            ctSetFlash('success', 'Terreno actualizado correctamente.');
            ctTerrenosRedirectAfterPost($queryBase);
        }

        if ($accion === 'eliminar_terreno') {
            $idTerreno = isset($post['id_terreno']) && is_numeric((string) $post['id_terreno']) ? (int) $post['id_terreno'] : 0;
            if ($idTerreno <= 0) {
                throw new RuntimeException('ID de terreno inválido.');
            }

            ctTerrenosRepoDelete($conn, $idTerreno);
            ctSetFlash('success', 'Terreno eliminado correctamente.');
            ctTerrenosRedirectAfterPost($queryBase);
        }

        ctSetFlash('warning', 'Acción no reconocida.');
        ctTerrenosRedirectAfterPost($queryBase);
    } catch (Throwable $exception) {
        $rawErrorMessage = $exception->getMessage();
        $errorMessage = $rawErrorMessage;

        if (
            stripos($rawErrorMessage, 'REFERENCE constraint') !== false
            || stripos($rawErrorMessage, 'restriccion REFERENCE') !== false
            || stripos($rawErrorMessage, 'restricción REFERENCE') !== false
            || stripos($rawErrorMessage, 'Instruccion DELETE en conflicto') !== false
            || stripos($rawErrorMessage, 'Instrucción DELETE en conflicto') !== false
            || stripos($rawErrorMessage, 'FK_ct_titularidad_terreno_terreno') !== false
        ) {
            $errorMessage = 'No se puede eliminar este terreno porque tiene registros relacionados (titularidad, operaciones, ventas u otros).';
        } elseif (
            stripos($rawErrorMessage, '2627') !== false
            || stripos($rawErrorMessage, '2601') !== false
            || stripos($rawErrorMessage, 'duplicate key') !== false
            || stripos($rawErrorMessage, 'clave duplicada') !== false
            || stripos($rawErrorMessage, 'UQ_ct_terreno_rol_asignado') !== false
            || stripos($rawErrorMessage, 'UX_ct_terreno_rol_asignado') !== false
        ) {
            $errorMessage = 'No se puede registrar porque ya existe un terreno con ese rol asignado.';
        } elseif (stripos($rawErrorMessage, 'FOREIGN KEY') !== false) {
            $errorMessage = 'Alguno de los IDs enviados no existe o no es válido.';
        } elseif (
            stripos($rawErrorMessage, 'Titularidad inválida') !== false
            || stripos($rawErrorMessage, 'Titularidad invalida') !== false
            || stripos($rawErrorMessage, '50021') !== false
            || stripos($rawErrorMessage, '50022') !== false
        ) {
            $errorMessage = 'No fue posible registrar la titularidad: revisa vigencias y que el porcentaje vigente no supere 100.';
        }

        ctSetFlash('danger', $errorMessage);

        $redirect = $queryBase;
        $modal = ctTerrenosModalForAction($accion);
        if ($modal !== '') {
            ctTerrenosStoreFormState($accion, $post);
            $redirect['modal'] = $modal;
        }

        ctTerrenosRedirectAfterPost($redirect);
    }
}

function ctTerrenosBuildPaginationItems(int $paginaActual, int $totalPaginas): array
{
    if ($totalPaginas <= 1) {
        return [];
    }

    $items = [];
    $start = max(1, $paginaActual - 2);
    $end = min($totalPaginas, $paginaActual + 2);

    if ($start > 1) {
        $items[] = ['label' => '1', 'page' => 1, 'active' => false];
        if ($start > 2) {
            $items[] = ['label' => '...', 'page' => null, 'active' => false];
        }
    }

    for ($page = $start; $page <= $end; $page++) {
        $items[] = ['label' => (string) $page, 'page' => $page, 'active' => $page === $paginaActual];
    }

    if ($end < $totalPaginas) {
        if ($end < $totalPaginas - 1) {
            $items[] = ['label' => '...', 'page' => null, 'active' => false];
        }
        $items[] = ['label' => (string) $totalPaginas, 'page' => $totalPaginas, 'active' => false];
    }

    return $items;
}

function ctTerrenosFetchPage(PDO $conn, array $state): array
{
    $terrenos = [];
    $terrenosError = null;
    $totalRegistros = 0;
    $totalPaginas = 1;
    $paginaActual = (int) $state['paginaActual'];
    $lineasPorPagina = (int) $state['lineasPorPagina'];

    try {
        $filtros = [
            'filtroTexto' => $state['filtroTexto'],
            'filtroCampo' => $state['filtroCampo'],
            'filtroComuna' => (int) ($state['filtroComuna'] !== '' ? $state['filtroComuna'] : 0),
            'filtroEstadoPredial' => (int) ($state['filtroEstadoPredial'] !== '' ? $state['filtroEstadoPredial'] : 0),
            'filtroEstadoComercial' => (int) ($state['filtroEstadoComercial'] !== '' ? $state['filtroEstadoComercial'] : 0),
            'filtroTipoInmueble' => (int) ($state['filtroTipoInmueble'] !== '' ? $state['filtroTipoInmueble'] : 0),
        ];

        $totalRegistros = ctTerrenosRepoCount($conn, $filtros);
        $totalPaginas = max(1, (int) ceil($totalRegistros / $lineasPorPagina));
        $paginaActual = min($paginaActual, $totalPaginas);
        $offset = ($paginaActual - 1) * $lineasPorPagina;

        $orderSql = $state['sortPermitidos'][$state['orden']] . ' ' . strtoupper((string) $state['direccion']);
        $terrenos = ctTerrenosRepoList($conn, $filtros, $orderSql, $offset, $lineasPorPagina);
    } catch (Throwable $exception) {
        $terrenosError = 'No fue posible cargar terrenos desde la base de datos.';
    }

    return [
        'terrenos' => $terrenos,
        'terrenosError' => $terrenosError,
        'totalRegistros' => $totalRegistros,
        'totalPaginas' => $totalPaginas,
        'paginaActual' => $paginaActual,
        'paginationItems' => ctTerrenosBuildPaginationItems($paginaActual, $totalPaginas),
    ];
}

function ctTerrenosFetchCatalogs(PDO $conn): array
{
    try {
        return [
            'error' => null,
            'comunas' => ctTerrenosRepoListComunas($conn),
            'estadosPrediales' => ctTerrenosRepoListEstadosPrediales($conn),
            'estadosComerciales' => ctTerrenosRepoListEstadosComerciales($conn),
            'tiposInmueble' => ctTerrenosRepoListTiposInmueble($conn),
            'terrenosSelector' => ctTerrenosRepoListTerrenosSelector($conn),
            'tercerosSelector' => ctTerrenosRepoListTercerosSelector($conn),
            'tiposTasacion' => ctComercialRepoListTiposTasacion($conn),
            'entidadesFinancieras' => ctComercialRepoListEntidadesFinancieras($conn),
            'tasacionesSelector' => ctComercialRepoListTasacionesSelector($conn),
        ];
    } catch (Throwable $exception) {
        return [
            'error' => 'No fue posible cargar catálogos de terrenos. Verifica que estén ejecutadas las capas SQL de predial y contabilidad.',
            'comunas' => [],
            'estadosPrediales' => [],
            'estadosComerciales' => [],
            'tiposInmueble' => [],
            'terrenosSelector' => [],
            'tercerosSelector' => [],
            'tiposTasacion' => [],
            'entidadesFinancieras' => [],
            'tasacionesSelector' => [],
        ];
    }
}

function ctTerrenosSortLink(string $col, array $queryBase, string $currentCol, string $currentDir): string
{
    $nextDir = ($col === $currentCol && $currentDir === 'asc') ? 'desc' : 'asc';
    $query = array_merge($queryBase, ['orden' => $col, 'dir' => $nextDir, 'pagina' => 1]);
    return '?' . http_build_query(array_filter($query, static fn($v) => $v !== '' && $v !== null));
}

function ctTerrenosSortIcon(string $col, string $currentCol, string $currentDir): string
{
    if ($col !== $currentCol) {
        return 'bi-arrow-down-up';
    }
    return $currentDir === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
}

function ctTerrenosFormatSuperficie($superficie): string
{
    return number_format((float) $superficie, 2, ',', '.');
}

function ctTerrenosDisplayValue(?string $value): string
{
    $normalized = trim((string) $value);
    return $normalized === '' ? '-' : $normalized;
}

function ctTerrenosFormatDate(?string $value): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '-';
    }

    $ts = strtotime($normalized);
    if ($ts === false) {
        return $normalized;
    }

    return date('d-m-Y', $ts);
}

function ctTerrenosFormatDateTime(?string $value): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '-';
    }

    $ts = strtotime($normalized);
    if ($ts === false) {
        return $normalized;
    }

    return date('d-m-Y H:i', $ts);
}

function ctTerrenosFormatOperacionLabel(?string $tipoOperacion): string
{
    $tipo = strtoupper(trim((string) $tipoOperacion));
    return match ($tipo) {
        'ADQUISICION' => 'Adquisición',
        'SUBDIVISION' => 'Subdivisión',
        'FUSION' => 'Fusión',
        'TASACION' => 'Tasación',
        'VENTA' => 'Venta',
        default => $tipo !== '' ? ucfirst(strtolower($tipo)) : 'Sin operación',
    };
}

function ctTerrenosTimelineSortKey(?string $value): int
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return 0;
    }

    $ts = strtotime($normalized);
    return $ts === false ? 0 : $ts;
}

function ctTerrenosUsuarioDisplayLabel(int $idUsuario, array $usuarioMap): string
{
    if ($idUsuario <= 0) {
        return '';
    }
    $nombre = trim((string) ($usuarioMap[$idUsuario] ?? ''));
    return $nombre !== '' ? $nombre : ('Usuario #' . $idUsuario);
}

function ctTerrenosFormatTerrenoRelacionLabel(array $row): string
{
    $idTerreno = (int) ($row['id_terreno'] ?? 0);
    $rolAsignado = trim((string) ($row['rol_asignado'] ?? ''));
    if ($idTerreno <= 0) {
        return $rolAsignado !== '' ? $rolAsignado : 'Terreno';
    }
    return '#' . $idTerreno . ($rolAsignado !== '' ? (' (' . $rolAsignado . ')') : '');
}

function ctTerrenosBuildOperacionRelacionLineas(
    int $idTerrenoActual,
    string $tipoOperacion,
    array $rolesActual,
    array $operacionTerrenos
): array {
    $rolesActualSet = [];
    foreach ($rolesActual as $roleRaw) {
        $role = strtoupper(trim((string) $roleRaw));
        if ($role !== '') {
            $rolesActualSet[$role] = true;
        }
    }

    $origenes = [];
    $resultados = [];
    foreach ($operacionTerrenos as $row) {
        $idTerreno = (int) ($row['id_terreno'] ?? 0);
        if ($idTerreno <= 0 || $idTerreno === $idTerrenoActual) {
            continue;
        }

        $rolOperacion = strtoupper(trim((string) ($row['rol_en_operacion'] ?? '')));
        $label = ctTerrenosFormatTerrenoRelacionLabel($row);
        if ($rolOperacion === 'ORIGEN') {
            $origenes[$idTerreno] = $label;
            continue;
        }
        if ($rolOperacion === 'RESULTADO') {
            $resultados[$idTerreno] = $label;
        }
    }

    $origenesList = array_values($origenes);
    $resultadosList = array_values($resultados);
    $isActualOrigen = isset($rolesActualSet['ORIGEN']);
    $isActualResultado = isset($rolesActualSet['RESULTADO']);

    $tipo = strtoupper(trim($tipoOperacion));
    $lineas = [];
    if ($tipo === 'SUBDIVISION') {
        if ($isActualOrigen && $resultadosList !== []) {
            $lineas[] = 'Terrenos resultantes: ' . implode(', ', $resultadosList);
        }
        if ($isActualResultado && $origenesList !== []) {
            $lineas[] = 'Proviene de: ' . implode(', ', $origenesList);
        }
    } elseif ($tipo === 'FUSION') {
        if ($isActualOrigen && $resultadosList !== []) {
            $lineas[] = 'Terreno resultante: ' . implode(', ', $resultadosList);
        }
        if ($isActualResultado && $origenesList !== []) {
            $lineas[] = 'Se forma desde: ' . implode(', ', $origenesList);
        }
    }

    if ($lineas === []) {
        if ($origenesList !== []) {
            $lineas[] = 'Orígenes relacionados: ' . implode(', ', $origenesList);
        }
        if ($resultadosList !== []) {
            $lineas[] = 'Resultados relacionados: ' . implode(', ', $resultadosList);
        }
    }

    return $lineas;
}

function ctTerrenosFetchTrazabilidadTerreno(PDO $conn, int $idTerreno, int $maxSaltos = 4): array
{
    if ($idTerreno <= 0) {
        return [
            'max_saltos' => $maxSaltos,
            'terrenos' => [],
            'operaciones' => [],
        ];
    }

    $maxSaltos = max(1, min(8, $maxSaltos));
    $distanciaTerreno = [$idTerreno => 0];
    $operacionesVisitadas = [];
    $frontera = [$idTerreno];

    for ($salto = 0; $salto < $maxSaltos; $salto++) {
        if ($frontera === []) {
            break;
        }

        $idsOperacion = ctTerrenosRepoListOperacionIdsByTerrenoIds($conn, $frontera);
        $nuevasOperaciones = [];
        foreach ($idsOperacion as $idOperacion) {
            $idOp = (int) $idOperacion;
            if ($idOp <= 0 || isset($operacionesVisitadas[$idOp])) {
                continue;
            }
            $operacionesVisitadas[$idOp] = true;
            $nuevasOperaciones[] = $idOp;
        }

        if ($nuevasOperaciones === []) {
            $frontera = [];
            continue;
        }

        $rowsOperacionTerreno = ctTerrenosRepoListOperacionTerrenosByOperacionIds($conn, $nuevasOperaciones);
        $siguienteFrontera = [];
        foreach ($rowsOperacionTerreno as $row) {
            $idRelacionado = (int) ($row['id_terreno'] ?? 0);
            if ($idRelacionado <= 0 || isset($distanciaTerreno[$idRelacionado])) {
                continue;
            }
            $distanciaTerreno[$idRelacionado] = $salto + 1;
            $siguienteFrontera[$idRelacionado] = true;
        }

        $frontera = array_values(array_map('intval', array_keys($siguienteFrontera)));
    }

    $idsOperacionFinal = array_values(array_map('intval', array_keys($operacionesVisitadas)));
    if ($idsOperacionFinal === []) {
        return [
            'max_saltos' => $maxSaltos,
            'terrenos' => [],
            'operaciones' => [],
        ];
    }

    $operacionesRows = ctTerrenosRepoListOperacionesByIds($conn, $idsOperacionFinal);
    $operacionTerrenosRows = ctTerrenosRepoListOperacionTerrenosByOperacionIds($conn, $idsOperacionFinal);

    $operacionHeaders = [];
    foreach ($operacionesRows as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idOperacion <= 0) {
            continue;
        }
        $operacionHeaders[$idOperacion] = $row;
    }

    $operaciones = [];
    $terrenosMap = [];
    foreach ($operacionTerrenosRows as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        $idRelacionado = (int) ($row['id_terreno'] ?? 0);
        if ($idOperacion <= 0 || $idRelacionado <= 0) {
            continue;
        }

        $header = $operacionHeaders[$idOperacion] ?? null;
        $tipoOperacion = is_array($header) ? (string) ($header['tipo_operacion'] ?? '') : '';
        $fechaOperacion = is_array($header) ? (string) ($header['fecha_operacion'] ?? '') : '';
        $fechaRegistro = is_array($header) ? (string) ($header['fecha_registro'] ?? '') : '';
        $documentoFuente = is_array($header) ? trim((string) ($header['documento_fuente'] ?? '')) : '';
        if (!isset($operaciones[$idOperacion])) {
            $operaciones[$idOperacion] = [
                'id_operacion' => $idOperacion,
                'tipo_operacion' => $tipoOperacion,
                'tipo_operacion_label' => ctTerrenosFormatOperacionLabel($tipoOperacion),
                'fecha_operacion' => $fechaOperacion,
                'fecha_registro' => $fechaRegistro,
                'fecha_formateada' => ctTerrenosFormatDateTime($fechaOperacion),
                'documento_fuente' => $documentoFuente,
                'participantes' => [],
                '_sort' => ctTerrenosTimelineSortKey($fechaOperacion),
                '_dist' => 999,
            ];
        }

        $distancia = isset($distanciaTerreno[$idRelacionado]) ? (int) $distanciaTerreno[$idRelacionado] : 999;
        if ($distancia < (int) $operaciones[$idOperacion]['_dist']) {
            $operaciones[$idOperacion]['_dist'] = $distancia;
        }

        $rolPredial = trim((string) ($row['rol_en_operacion'] ?? ''));
        $rolAsignado = trim((string) ($row['rol_asignado'] ?? ''));
        $superficieM2 = (float) ($row['superficie_m2'] ?? 0);
        $participante = [
            'id_terreno' => $idRelacionado,
            'rol_asignado' => $rolAsignado,
            'superficie_m2' => $superficieM2,
            'rol_en_operacion' => $rolPredial,
            'es_actual' => $idRelacionado === $idTerreno,
            'distancia' => $distancia === 999 ? null : $distancia,
        ];
        $operaciones[$idOperacion]['participantes'][] = $participante;

        if ($idRelacionado !== $idTerreno) {
            if (!isset($terrenosMap[$idRelacionado]) || $distancia < (int) ($terrenosMap[$idRelacionado]['distancia'] ?? 999)) {
                $terrenosMap[$idRelacionado] = [
                    'id_terreno' => $idRelacionado,
                    'rol_asignado' => $rolAsignado,
                    'distancia' => $distancia === 999 ? null : $distancia,
                ];
            }
        }
    }

    $terrenosRelacionados = array_values($terrenosMap);
    usort($terrenosRelacionados, static function (array $a, array $b): int {
        $distA = isset($a['distancia']) && is_numeric((string) $a['distancia']) ? (int) $a['distancia'] : 999;
        $distB = isset($b['distancia']) && is_numeric((string) $b['distancia']) ? (int) $b['distancia'] : 999;
        if ($distA !== $distB) {
            return $distA <=> $distB;
        }
        return ((int) ($a['id_terreno'] ?? 0)) <=> ((int) ($b['id_terreno'] ?? 0));
    });

    $operacionesList = array_values($operaciones);
    foreach ($operacionesList as &$op) {
        $participantes = is_array($op['participantes'] ?? null) ? $op['participantes'] : [];
        usort($participantes, static function (array $a, array $b): int {
            $actualA = !empty($a['es_actual']);
            $actualB = !empty($b['es_actual']);
            if ($actualA !== $actualB) {
                return $actualA ? -1 : 1;
            }

            $rolA = strtoupper(trim((string) ($a['rol_en_operacion'] ?? '')));
            $rolB = strtoupper(trim((string) ($b['rol_en_operacion'] ?? '')));
            if ($rolA !== $rolB) {
                return $rolA <=> $rolB;
            }

            return ((int) ($a['id_terreno'] ?? 0)) <=> ((int) ($b['id_terreno'] ?? 0));
        });
        $op['participantes'] = $participantes;
    }
    unset($op);

    usort($operacionesList, static function (array $a, array $b): int {
        $sortA = (int) ($a['_sort'] ?? 0);
        $sortB = (int) ($b['_sort'] ?? 0);
        if ($sortA !== $sortB) {
            return $sortB <=> $sortA;
        }

        $distA = (int) ($a['_dist'] ?? 999);
        $distB = (int) ($b['_dist'] ?? 999);
        if ($distA !== $distB) {
            return $distA <=> $distB;
        }

        return ((int) ($b['id_operacion'] ?? 0)) <=> ((int) ($a['id_operacion'] ?? 0));
    });

    foreach ($operacionesList as &$op) {
        unset($op['_sort'], $op['_dist']);
    }
    unset($op);

    return [
        'max_saltos' => $maxSaltos,
        'terrenos' => $terrenosRelacionados,
        'operaciones' => $operacionesList,
    ];
}

function ctTerrenosFetchFichaTerreno(PDO $conn, int $idTerreno): array
{
    if ($idTerreno <= 0) {
        throw new RuntimeException('ID de terreno inválido.');
    }

    $terreno = ctTerrenosRepoFindFichaById($conn, $idTerreno);
    if (!is_array($terreno)) {
        throw new RuntimeException('El terreno indicado no existe.');
    }

    $titularesVigentes = ctTerrenosRepoListTitularesVigentes($conn, $idTerreno);
    $historial = ctTerrenosFetchHistorialTerreno($conn, $idTerreno);
    $trazabilidad = ctTerrenosFetchTrazabilidadTerreno($conn, $idTerreno, 5);
    $historialLista = ctTerrenosRepoHistorialSimpleList(
        $conn,
        [
            'rol' => '',
            'id_terreno' => $idTerreno,
            'id_comuna' => 0,
            'tipo_operacion' => '',
            'fecha_desde' => '',
            'fecha_hasta' => '',
        ],
        0,
        500
    );

    return [
        'terreno' => $terreno,
        'titularesVigentes' => $titularesVigentes,
        'eventos' => is_array($historial['eventos'] ?? null) ? $historial['eventos'] : [],
        'trazabilidad' => $trazabilidad,
        'historialLista' => $historialLista,
    ];
}

function ctTerrenosFetchHistorialTerreno(PDO $conn, int $idTerreno): array
{
    if ($idTerreno <= 0) {
        throw new RuntimeException('ID de terreno inválido.');
    }

    $resumen = ctTerrenosRepoFindResumenById($conn, $idTerreno);
    if (!is_array($resumen)) {
        throw new RuntimeException('El terreno indicado no existe.');
    }

    $operaciones = ctTerrenosRepoListHistorialOperaciones($conn, $idTerreno);
    $historialEstados = ctTerrenosRepoListHistorialEstados($conn, $idTerreno);
    $titularidades = ctTerrenosRepoListHistorialTitularidades($conn, $idTerreno);
    $tasaciones = ctTerrenosRepoListHistorialTasaciones($conn, $idTerreno);
    $ventas = ctTerrenosRepoListHistorialVentas($conn, $idTerreno);

    $eventos = [];

    $idsUsuario = [];
    $usuarioPorOperacion = [];
    foreach ($historialEstados as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idUsuario > 0) {
            $idsUsuario[$idUsuario] = true;
        }
        if ($idOperacion > 0 && $idUsuario > 0 && !isset($usuarioPorOperacion[$idOperacion])) {
            $usuarioPorOperacion[$idOperacion] = $idUsuario;
        }
    }
    foreach ($tasaciones as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        if ($idUsuario > 0) {
            $idsUsuario[$idUsuario] = true;
        }
    }
    foreach ($ventas as $row) {
        $idUsuario = (int) ($row['id_usuario'] ?? 0);
        if ($idUsuario > 0) {
            $idsUsuario[$idUsuario] = true;
        }
    }
    $usuarioMap = ctTerrenosRepoResolveUsuariosDisplayMap($conn, array_keys($idsUsuario));

    $operacionesAgrupadas = [];
    foreach ($operaciones as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idOperacion <= 0) {
            continue;
        }

        if (!isset($operacionesAgrupadas[$idOperacion])) {
            $operacionesAgrupadas[$idOperacion] = [
                'id_operacion' => $idOperacion,
                'tipo_operacion' => (string) ($row['tipo_operacion'] ?? ''),
                'fecha_operacion' => (string) ($row['fecha_operacion'] ?? ''),
                'fecha_registro' => (string) ($row['fecha_registro'] ?? ''),
                'documento_fuente' => (string) ($row['documento_fuente'] ?? ''),
                'roles' => [],
            ];
        }

        $rolOperacion = trim((string) ($row['rol_en_operacion'] ?? ''));
        if ($rolOperacion !== '' && !in_array($rolOperacion, $operacionesAgrupadas[$idOperacion]['roles'], true)) {
            $operacionesAgrupadas[$idOperacion]['roles'][] = $rolOperacion;
        }
    }

    $operacionTerrenosMap = [];
    $operacionTerrenosRows = ctTerrenosRepoListOperacionTerrenosByOperacionIds(
        $conn,
        array_keys($operacionesAgrupadas)
    );
    foreach ($operacionTerrenosRows as $row) {
        $idOperacion = (int) ($row['id_operacion'] ?? 0);
        if ($idOperacion <= 0) {
            continue;
        }
        if (!isset($operacionTerrenosMap[$idOperacion])) {
            $operacionTerrenosMap[$idOperacion] = [];
        }
        $operacionTerrenosMap[$idOperacion][] = $row;
    }

    foreach ($operacionesAgrupadas as $op) {
        $idOperacion = (int) ($op['id_operacion'] ?? 0);
        $fechaOperacion = (string) ($op['fecha_operacion'] ?? '');
        $fechaRegistro = (string) ($op['fecha_registro'] ?? '');
        $tipoOperacionRaw = (string) ($op['tipo_operacion'] ?? '');
        $tipoOperacionKey = strtoupper(trim($tipoOperacionRaw));
        $tipoOperacionLabel = ctTerrenosFormatOperacionLabel($tipoOperacionRaw);
        $esOperacionCadena = in_array($tipoOperacionKey, ['SUBDIVISION', 'FUSION'], true);

        $detalle = [];
        $roles = is_array($op['roles'] ?? null) ? $op['roles'] : [];
        if ($roles !== [] && $tipoOperacionKey !== 'ADQUISICION' && !$esOperacionCadena) {
            $detalle[] = 'Rol: ' . implode(', ', $roles);
        }

        $documento = trim((string) ($op['documento_fuente'] ?? ''));
        if ($documento !== '' && !$esOperacionCadena) {
            $detalle[] = 'Documento: ' . $documento;
        }

        $idUsuario = (int) ($usuarioPorOperacion[$idOperacion] ?? 0);
        $usuarioLabel = ctTerrenosUsuarioDisplayLabel($idUsuario, $usuarioMap);
        $lineasRelacion = [];
        if ($tipoOperacionKey !== 'ADQUISICION' && !$esOperacionCadena) {
            $lineasRelacion = ctTerrenosBuildOperacionRelacionLineas(
                $idTerreno,
                $tipoOperacionRaw,
                $roles,
                is_array($operacionTerrenosMap[$idOperacion] ?? null) ? $operacionTerrenosMap[$idOperacion] : []
            );
        }

        $eventos[] = [
            'tipo' => 'operacion',
            'badge' => $tipoOperacionLabel,
            'id_operacion' => $idOperacion,
            'tipo_operacion' => $tipoOperacionKey,
            'fecha' => $fechaRegistro !== '' ? $fechaRegistro : $fechaOperacion,
            'fecha_operacion' => $fechaOperacion,
            'fecha_registro' => $fechaRegistro,
            'fecha_operacion_formateada' => ctTerrenosFormatDate($fechaOperacion),
            'fecha_registro_formateada' => ctTerrenosFormatDateTime($fechaRegistro),
            'usuario_label' => $usuarioLabel,
            'fecha_formateada' => ctTerrenosFormatDateTime($fechaRegistro !== '' ? $fechaRegistro : $fechaOperacion),
            'titulo' => $tipoOperacionLabel,
            'detalle' => $detalle !== [] ? implode(' | ', $detalle) : '',
            'lineas' => $lineasRelacion,
            '_sort' => ctTerrenosTimelineSortKey($fechaRegistro !== '' ? $fechaRegistro : $fechaOperacion),
            '_priority' => 20,
            '_id' => $idOperacion,
        ];
    }

    $compradoresVentasRows = ctTerrenosRepoListVentaCompradoresByVentas(
        $conn,
        array_map(static fn(array $row): int => (int) ($row['id_venta'] ?? 0), $ventas)
    );
    $compradoresPorVenta = [];
    foreach ($compradoresVentasRows as $row) {
        $idVenta = (int) ($row['id_venta'] ?? 0);
        if ($idVenta <= 0) {
            continue;
        }
        if (!isset($compradoresPorVenta[$idVenta])) {
            $compradoresPorVenta[$idVenta] = [];
        }
        $compradoresPorVenta[$idVenta][] = $row;
    }

    foreach ($tasaciones as $tasacion) {
        $idTasacion = (int) ($tasacion['id_tasacion'] ?? 0);
        if ($idTasacion <= 0) {
            continue;
        }

        $fechaTasacion = trim((string) ($tasacion['fecha_tasacion'] ?? ''));
        $fechaRegistroTasacion = trim((string) ($tasacion['fecha_registro'] ?? ''));
        $valorTotalUf = ctComercialFormatUf($tasacion['valor_total_uf'] ?? 0);
        $valorUfM2Raw = $tasacion['valor_uf_m2'] ?? null;
        $valorUfM2 = is_numeric((string) $valorUfM2Raw) ? (float) $valorUfM2Raw : null;
        $tipoTasacionNombre = trim((string) ($tasacion['tipo_tasacion_nombre'] ?? ''));
        $entidadFinancieraNombre = trim((string) ($tasacion['entidad_financiera_nombre'] ?? ''));
        $esReferencial = (int) ($tasacion['es_referencial'] ?? 0) === 1;
        $vigenteDesde = trim((string) ($tasacion['vigente_desde'] ?? ''));
        $vigenteHasta = trim((string) ($tasacion['vigente_hasta'] ?? ''));

        $lineasTasacion = [];
        $lineasTasacion[] = 'Valor total: UF ' . $valorTotalUf;
        if ($valorUfM2 !== null && $valorUfM2 > 0) {
            $lineasTasacion[] = 'Valor UF/m²: ' . number_format($valorUfM2, 4, ',', '.');
        }
        if ($tipoTasacionNombre !== '') {
            $lineasTasacion[] = 'Tipo: ' . $tipoTasacionNombre;
        }
        if ($entidadFinancieraNombre !== '') {
            $lineasTasacion[] = 'Banco: ' . $entidadFinancieraNombre;
        }
        if ($esReferencial) {
            $lineasTasacion[] = 'Marcada como referencial';
        }
        if ($vigenteDesde !== '') {
            $lineasTasacion[] = 'Vigente desde: ' . ctTerrenosFormatDate($vigenteDesde);
        }
        if ($vigenteHasta !== '') {
            $lineasTasacion[] = 'Vigente hasta: ' . ctTerrenosFormatDate($vigenteHasta);
        }

        $idUsuario = (int) ($tasacion['id_usuario'] ?? 0);
        $usuarioLabel = ctTerrenosUsuarioDisplayLabel($idUsuario, $usuarioMap);

        $eventos[] = [
            'tipo' => 'operacion',
            'badge' => 'Tasación',
            'id_operacion' => 0,
            'tipo_operacion' => 'TASACION',
            'fecha' => $fechaRegistroTasacion !== '' ? $fechaRegistroTasacion : $fechaTasacion,
            'fecha_operacion' => $fechaTasacion,
            'fecha_registro' => $fechaRegistroTasacion,
            'fecha_operacion_formateada' => ctTerrenosFormatDate($fechaTasacion),
            'fecha_registro_formateada' => ctTerrenosFormatDateTime($fechaRegistroTasacion),
            'usuario_label' => $usuarioLabel,
            'fecha_formateada' => $fechaRegistroTasacion !== '' ? ctTerrenosFormatDateTime($fechaRegistroTasacion) : ctTerrenosFormatDate($fechaTasacion),
            'titulo' => 'Tasación',
            'detalle' => '',
            'lineas' => $lineasTasacion,
            '_sort' => ctTerrenosTimelineSortKey($fechaRegistroTasacion !== '' ? $fechaRegistroTasacion : $fechaTasacion),
            '_priority' => 20,
            '_id' => $idTasacion,
        ];
    }

    foreach ($ventas as $venta) {
        $idVenta = (int) ($venta['id_venta'] ?? 0);
        if ($idVenta <= 0) {
            continue;
        }

        $fechaVenta = trim((string) ($venta['fecha_venta'] ?? ''));
        $fechaRegistro = trim((string) ($venta['fecha_registro'] ?? ''));
        $valorTotalUf = ctComercialFormatUf($venta['valor_total_uf'] ?? 0);
        $valorUfM2Raw = $venta['valor_venta_uf_m2'] ?? null;
        $valorUfM2 = is_numeric((string) $valorUfM2Raw) ? (float) $valorUfM2Raw : null;
        $idTasacionReferencial = (int) ($venta['id_tasacion_referencial'] ?? 0);

        $lineasVenta = [];
        $lineasVenta[] = 'Valor total: UF ' . $valorTotalUf;
        if ($valorUfM2 !== null && $valorUfM2 > 0) {
            $lineasVenta[] = 'Valor venta UF/m²: ' . number_format($valorUfM2, 4, ',', '.');
        }
        if ($idTasacionReferencial > 0) {
            $lineasVenta[] = 'Tasación referencial: #' . $idTasacionReferencial;
        }

        $compradoresRows = is_array($compradoresPorVenta[$idVenta] ?? null) ? $compradoresPorVenta[$idVenta] : [];
        foreach ($compradoresRows as $comprador) {
            $nombreComprador = trim((string) ($comprador['tercero_nombre'] ?? ''));
            $rutComprador = trim((string) ($comprador['tercero_rut'] ?? ''));
            $labelComprador = $nombreComprador !== '' ? $nombreComprador : 'Comprador sin nombre';
            if ($rutComprador !== '') {
                $labelComprador .= ' (' . $rutComprador . ')';
            }
            $porcentaje = is_numeric((string) ($comprador['porcentaje'] ?? null)) ? (float) $comprador['porcentaje'] : 0.0;
            $linea = $labelComprador . ' - ' . number_format($porcentaje, 2, '.', '') . '%';
            $rolVenta = trim((string) ($comprador['rol_en_venta'] ?? ''));
            if ($rolVenta !== '') {
                $linea .= ' [' . $rolVenta . ']';
            }
            $lineasVenta[] = $linea;
        }

        $idUsuario = (int) ($venta['id_usuario'] ?? 0);
        $usuarioLabel = ctTerrenosUsuarioDisplayLabel($idUsuario, $usuarioMap);
        $fechaEvento = $fechaRegistro !== '' ? $fechaRegistro : $fechaVenta;

        $eventos[] = [
            'tipo' => 'operacion',
            'badge' => 'Venta',
            'id_operacion' => 0,
            'tipo_operacion' => 'VENTA',
            'fecha' => $fechaEvento,
            'fecha_operacion' => $fechaVenta,
            'fecha_registro' => $fechaRegistro,
            'fecha_operacion_formateada' => ctTerrenosFormatDate($fechaVenta),
            'fecha_registro_formateada' => ctTerrenosFormatDateTime($fechaRegistro),
            'usuario_label' => $usuarioLabel,
            'fecha_formateada' => $fechaRegistro !== '' ? ctTerrenosFormatDateTime($fechaRegistro) : ctTerrenosFormatDate($fechaVenta),
            'titulo' => 'Venta',
            'detalle' => '',
            'lineas' => $lineasVenta,
            '_sort' => ctTerrenosTimelineSortKey($fechaEvento),
            '_priority' => 20,
            '_id' => $idVenta,
        ];
    }

    $fechasAdquisicion = [];
    $tiposOperacionPorFecha = [];
    foreach ($operacionesAgrupadas as $op) {
        $tipoOperacion = strtoupper(trim((string) ($op['tipo_operacion'] ?? '')));
        $fechaOperacion = trim((string) ($op['fecha_operacion'] ?? ''));
        if ($fechaOperacion === '') {
            continue;
        }
        $tsOperacion = strtotime($fechaOperacion);
        if ($tsOperacion === false) {
            continue;
        }
        $fechaSolo = date('Y-m-d', $tsOperacion);
        if ($fechaSolo === '') {
            continue;
        }

        if (!isset($tiposOperacionPorFecha[$fechaSolo])) {
            $tiposOperacionPorFecha[$fechaSolo] = [];
        }
        $tiposOperacionPorFecha[$fechaSolo][$tipoOperacion] = true;

        if ($tipoOperacion === 'ADQUISICION') {
            $fechasAdquisicion[$fechaSolo] = true;
        }
    }

    $titularidadesAgrupadas = [];
    foreach ($titularidades as $row) {
        $vigenteDesdeRaw = (string) ($row['vigente_desde'] ?? '');
        $vigenteHastaRaw = trim((string) ($row['vigente_hasta'] ?? ''));
        $groupKey = $vigenteDesdeRaw . '|' . $vigenteHastaRaw;
        if (!isset($titularidadesAgrupadas[$groupKey])) {
            $titularidadesAgrupadas[$groupKey] = [
                'fecha' => $vigenteDesdeRaw,
                'vigente_hasta' => $vigenteHastaRaw,
                'lineas' => [],
                'total' => 0.0,
                'max_id' => 0,
            ];
        }

        $idTitularidad = (int) ($row['id_titularidad'] ?? 0);
        if ($idTitularidad > (int) $titularidadesAgrupadas[$groupKey]['max_id']) {
            $titularidadesAgrupadas[$groupKey]['max_id'] = $idTitularidad;
        }

        $terceroNombre = trim((string) ($row['tercero_nombre'] ?? ''));
        $terceroRut = trim((string) ($row['tercero_rut'] ?? ''));
        $terceroLabel = $terceroNombre !== '' ? $terceroNombre : 'Titular sin nombre';
        if ($terceroRut !== '') {
            $terceroLabel .= ' (' . $terceroRut . ')';
        }

        $porcentaje = (float) ($row['porcentaje_derecho'] ?? 0);
        $titularidadesAgrupadas[$groupKey]['total'] += $porcentaje;
        $titularidadesAgrupadas[$groupKey]['lineas'][] = $terceroLabel
            . ' - '
            . number_format($porcentaje, 2, '.', '')
            . '%';
    }

    $adquisicionTitularesPorFecha = [];
    foreach ($titularidadesAgrupadas as $grupo) {
        $fechaGrupo = (string) ($grupo['fecha'] ?? '');
        $tsGrupo = strtotime($fechaGrupo);
        $fechaGrupoSolo = $tsGrupo === false ? '' : date('Y-m-d', $tsGrupo);
        if ($fechaGrupoSolo === '' || !isset($fechasAdquisicion[$fechaGrupoSolo])) {
            continue;
        }
        $lineasGrupo = is_array($grupo['lineas'] ?? null) ? $grupo['lineas'] : [];
        if (!isset($adquisicionTitularesPorFecha[$fechaGrupoSolo])) {
            $adquisicionTitularesPorFecha[$fechaGrupoSolo] = [
                'lineas' => [],
                'cantidad' => 0,
                'total' => 0.0,
            ];
        }
        $adquisicionTitularesPorFecha[$fechaGrupoSolo]['lineas'] = array_values(array_merge(
            $adquisicionTitularesPorFecha[$fechaGrupoSolo]['lineas'],
            $lineasGrupo
        ));
        $adquisicionTitularesPorFecha[$fechaGrupoSolo]['cantidad'] += count($lineasGrupo);
        $adquisicionTitularesPorFecha[$fechaGrupoSolo]['total'] += (float) ($grupo['total'] ?? 0.0);
    }

    foreach ($eventos as &$eventoOperacion) {
        if (strtolower(trim((string) ($eventoOperacion['tipo'] ?? ''))) !== 'operacion') {
            continue;
        }
        if (strtoupper(trim((string) ($eventoOperacion['tipo_operacion'] ?? ''))) !== 'ADQUISICION') {
            continue;
        }

        $fechaOperacionEvento = trim((string) ($eventoOperacion['fecha_operacion'] ?? ''));
        $tsOperacionEvento = strtotime($fechaOperacionEvento);
        if ($tsOperacionEvento === false) {
            continue;
        }
        $fechaOperacionSolo = date('Y-m-d', $tsOperacionEvento);
        if ($fechaOperacionSolo === '' || !isset($adquisicionTitularesPorFecha[$fechaOperacionSolo])) {
            continue;
        }

        $resumenTitulares = $adquisicionTitularesPorFecha[$fechaOperacionSolo];
        $eventoOperacion['lineas'] = is_array($resumenTitulares['lineas'] ?? null)
            ? $resumenTitulares['lineas']
            : [];
        $cantidadTitulares = is_array($eventoOperacion['lineas'] ?? null) ? count($eventoOperacion['lineas']) : 0;
        $detalleTitularidad = $cantidadTitulares > 1 ? 'Titularidad inicial (copropiedad)' : 'Titularidad inicial';
        $detalleActual = trim((string) ($eventoOperacion['detalle'] ?? ''));
        $eventoOperacion['detalle'] = $detalleActual !== ''
            ? ($detalleActual . ' | ' . $detalleTitularidad)
            : $detalleTitularidad;

        unset($adquisicionTitularesPorFecha[$fechaOperacionSolo]);
    }
    unset($eventoOperacion);

    foreach ($titularidadesAgrupadas as $grupo) {
        $lineas = is_array($grupo['lineas'] ?? null) ? $grupo['lineas'] : [];
        $cantidadTitulares = count($lineas);
        $fechaGrupo = (string) ($grupo['fecha'] ?? '');
        $vigenteHastaRaw = trim((string) ($grupo['vigente_hasta'] ?? ''));
        $tsGrupo = strtotime($fechaGrupo);
        $fechaGrupoSolo = $tsGrupo === false ? '' : date('Y-m-d', $tsGrupo);
        $esTitularidadInicial = $fechaGrupoSolo !== '' && isset($fechasAdquisicion[$fechaGrupoSolo]);
        if ($esTitularidadInicial) {
            continue;
        }
        $tiposFecha = $fechaGrupoSolo !== '' && isset($tiposOperacionPorFecha[$fechaGrupoSolo])
            ? array_keys($tiposOperacionPorFecha[$fechaGrupoSolo])
            : [];
        $esTitularidadHeredada = in_array('SUBDIVISION', $tiposFecha, true) || in_array('FUSION', $tiposFecha, true);

        $detalle = '';
        if ($vigenteHastaRaw !== '') {
            $detalle = 'Vigencia hasta: ' . ctTerrenosFormatDate($vigenteHastaRaw);
        }

        $eventos[] = [
            'tipo' => 'titularidad',
            'badge' => $esTitularidadHeredada ? 'Titularidad heredada' : 'Titularidad',
            'fecha' => $fechaGrupo,
            'fecha_formateada' => ctTerrenosFormatDate($fechaGrupo),
            'titulo' => $esTitularidadHeredada
                ? ($cantidadTitulares > 1 ? 'Titularidad heredada (copropiedad)' : 'Titularidad heredada')
                : ($cantidadTitulares > 1 ? 'Titularidad (copropiedad)' : 'Titularidad'),
            'detalle' => $detalle,
            'lineas' => $lineas,
            '_sort' => ctTerrenosTimelineSortKey($fechaGrupo),
            '_priority' => 10,
            '_id' => (int) ($grupo['max_id'] ?? 0),
        ];
    }

    usort($eventos, static function (array $a, array $b): int {
        $sortA = (int) ($a['_sort'] ?? 0);
        $sortB = (int) ($b['_sort'] ?? 0);
        if ($sortA !== $sortB) {
            return $sortB <=> $sortA;
        }

        $priorityA = (int) ($a['_priority'] ?? 0);
        $priorityB = (int) ($b['_priority'] ?? 0);
        if ($priorityA !== $priorityB) {
            return $priorityB <=> $priorityA;
        }

        $idA = (int) ($a['_id'] ?? 0);
        $idB = (int) ($b['_id'] ?? 0);
        return $idB <=> $idA;
    });

    foreach ($eventos as &$evento) {
        unset($evento['_sort'], $evento['_priority'], $evento['_id']);
    }
    unset($evento);

    return [
        'terreno' => [
            'id_terreno' => (int) ($resumen['id_terreno'] ?? 0),
            'rol_asignado' => trim((string) ($resumen['rol_asignado'] ?? '')),
            'comuna_nombre' => trim((string) ($resumen['comuna_nombre'] ?? '')),
        ],
        'eventos' => $eventos,
    ];
}

function ctTerrenosHandleAjax(PDO $conn, array $query): bool
{
    $ajaxAction = trim((string) ($query['ajax'] ?? ''));
    if ($ajaxAction !== 'historial_terreno') {
        return false;
    }

    header('Content-Type: application/json; charset=utf-8');

    try {
        $idTerreno = isset($query['id_terreno']) && is_numeric((string) $query['id_terreno'])
            ? (int) $query['id_terreno']
            : 0;
        $data = ctTerrenosFetchHistorialTerreno($conn, $idTerreno);
        echo (string) json_encode(
            ['ok' => true, 'data' => $data],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $exception) {
        http_response_code(400);
        echo (string) json_encode(
            ['ok' => false, 'message' => $exception->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    exit();
}
