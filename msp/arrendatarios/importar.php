<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('arrendatarios/index.php');
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` en la raíz del proyecto e intenta nuevamente.');
    msp2Redirect('arrendatarios/index.php');
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido para importar.');
    msp2Redirect('arrendatarios/index.php');
}

$originalName = (string) ($uploadMeta['name'] ?? 'importacion.xlsx');
$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

$requiredTables = [
    'msp_arrendatarios',
    'msp_comunas',
    'msp_estado_arrendatarios',
    'msp_arrendatarios_correos',
    'msp_arrendatarios_telefonos',
];

foreach ($requiredTables as $tableName) {
    if (!msp2TableExists($conn, $tableName)) {
        msp2SetFlash('warning', 'Falta la tabla `' . $tableName . '`. Ejecuta `msp/msp_a1.sql` actualizado.');
        msp2Redirect('arrendatarios/index.php');
    }
}

function msp2ArrImportCellToString($value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function msp2ArrImportFindColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $headers)) {
            return (int) $headers[$alias];
        }
    }

    return null;
}

function msp2ArrImportParseTipo(string $raw): ?int
{
    $key = msp2NormalizeLookupKey($raw);

    if ($key === '') {
        return null;
    }

    $empresa = [
        'empresa',
        'persona_juridica',
        'juridica',
        '1',
        'emp',
    ];

    $persona = [
        'persona_natural',
        'persona',
        'natural',
        '0',
        'pn',
    ];

    if (in_array($key, $empresa, true)) {
        return 1;
    }

    if (in_array($key, $persona, true)) {
        return 0;
    }

    return null;
}

function msp2ArrImportParseList(string $raw, int $maxLength, bool $validateEmail): array
{
    if (trim($raw) === '') {
        return [[], []];
    }

    $parts = preg_split('/[;|,\n\r]+/', $raw);

    if (!is_array($parts)) {
        return [[], ['Formato inválido en lista de contactos.']];
    }

    $values = [];
    $errors = [];
    $seen = [];

    foreach ($parts as $part) {
        $value = msp2NormalizeText((string) $part);

        if ($value === '') {
            continue;
        }

        if ($validateEmail) {
            $value = mb_strtolower($value, 'UTF-8');
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[] = 'Un valor supera el largo máximo de ' . $maxLength . ' caracteres.';
            continue;
        }

        if ($validateEmail && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Correo inválido: ' . $value . '.';
            continue;
        }

        $dedupeKey = $validateEmail
            ? mb_strtolower($value, 'UTF-8')
            : (preg_replace('/\s+/', '', mb_strtolower($value, 'UTF-8')) ?? mb_strtolower($value, 'UTF-8'));

        if (isset($seen[$dedupeKey])) {
            continue;
        }

        $seen[$dedupeKey] = true;
        $values[] = $value;
    }

    return [$values, $errors];
}

function msp2ArrImportFetchComunas(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_comuna, desc_comuna FROM dbo.msp_comunas');
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $desc = msp2NormalizeText((string) ($row['desc_comuna'] ?? ''));
        if ($desc === '') {
            continue;
        }

        $key = msp2NormalizeLookupKey($desc);
        if ($key === '') {
            continue;
        }

        $map[$key] = [
            'id_comuna' => (int) $row['id_comuna'],
            'desc_comuna' => $desc,
        ];
    }

    return $map;
}

function msp2ArrImportEstadoActivo(PDO $conn): ?array
{
    $stmt = $conn->prepare(
        'SELECT TOP 1 id_estado_arrendatario, desc_estado
         FROM dbo.msp_estado_arrendatarios
         WHERE LTRIM(RTRIM(LOWER(desc_estado))) = LTRIM(RTRIM(LOWER(:desc_estado)))
         ORDER BY id_estado_arrendatario ASC'
    );
    $stmt->bindValue(':desc_estado', 'Activo', PDO::PARAM_STR);
    $stmt->execute();

    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }

    return [
        'id_estado_arrendatario' => (int) $row['id_estado_arrendatario'],
        'desc_estado' => msp2NormalizeText((string) $row['desc_estado']),
    ];
}

function msp2ArrImportFetchExistingByRut(PDO $conn, array $ruts): array
{
    if ($ruts === []) {
        return [];
    }

    $existing = [];

    foreach (array_chunk(array_values(array_unique($ruts)), 500) as $chunk) {
        $placeholders = [];

        foreach ($chunk as $index => $rut) {
            $placeholders[] = ':rut_' . $index;
        }

        $sql =
            'SELECT
                a.id_arrendatario,
                a.rut,
                a.es_empresa,
                a.nombre_locatario,
                a.nombre_representante,
                a.direccion,
                a.id_comuna,
                a.id_estado_arrendatario,
                c.desc_comuna,
                e.desc_estado
             FROM dbo.msp_arrendatarios a
             LEFT JOIN dbo.msp_comunas c ON c.id_comuna = a.id_comuna
             INNER JOIN dbo.msp_estado_arrendatarios e ON e.id_estado_arrendatario = a.id_estado_arrendatario
             WHERE a.rut IN (' . implode(', ', $placeholders) . ')';

        $stmt = $conn->prepare($sql);

        foreach ($chunk as $index => $rut) {
            $stmt->bindValue(':rut_' . $index, $rut, PDO::PARAM_STR);
        }

        $stmt->execute();

        while (($row = $stmt->fetch()) !== false) {
            $rut = msp2NormalizeText((string) ($row['rut'] ?? ''));
            if ($rut !== '') {
                $existing[$rut] = $row;
            }
        }
    }

    return $existing;
}

function msp2ArrImportFetchContactMap(PDO $conn, array $ids, string $tableName, string $idColumn, string $valueColumn): array
{
    if ($ids === []) {
        return [];
    }

    $map = [];

    foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
        $placeholders = [];

        foreach ($chunk as $index => $id) {
            $placeholders[] = ':id_' . $index;
        }

        $sql =
            'SELECT id_arrendatario, ' . $valueColumn . ' AS value_item, es_principal
             FROM ' . $tableName . '
             WHERE id_arrendatario IN (' . implode(', ', $placeholders) . ')
             ORDER BY id_arrendatario ASC, es_principal DESC, ' . $idColumn . ' ASC';

        $stmt = $conn->prepare($sql);

        foreach ($chunk as $index => $id) {
            $stmt->bindValue(':id_' . $index, (int) $id, PDO::PARAM_INT);
        }

        $stmt->execute();

        while (($row = $stmt->fetch()) !== false) {
            $idArr = (int) $row['id_arrendatario'];
            if (!isset($map[$idArr])) {
                $map[$idArr] = [];
            }

            $map[$idArr][] = [
                'value' => msp2NormalizeText((string) ($row['value_item'] ?? '')),
                'es_principal' => (int) ($row['es_principal'] ?? 0),
            ];
        }
    }

    return $map;
}

function msp2ArrImportCanonicalEmails(array $emails): array
{
    $list = [];
    foreach ($emails as $email) {
        $value = mb_strtolower(msp2NormalizeText((string) $email), 'UTF-8');
        if ($value !== '') {
            $list[] = $value;
        }
    }

    $list = array_values(array_unique($list));
    sort($list);

    return $list;
}

function msp2ArrImportCanonicalPhones(array $phones): array
{
    $list = [];
    foreach ($phones as $phone) {
        $value = msp2NormalizeText((string) $phone);
        if ($value === '') {
            continue;
        }

        $key = preg_replace('/\s+/', '', mb_strtolower($value, 'UTF-8'));
        $list[] = $key === null ? mb_strtolower($value, 'UTF-8') : $key;
    }

    $list = array_values(array_unique($list));
    sort($list);

    return $list;
}

$estadoActivo = msp2ArrImportEstadoActivo($conn);
if ($estadoActivo === null) {
    msp2SetFlash('warning', 'No existe el estado `Activo` en `msp_estado_arrendatarios`. Créalo antes de importar.');
    msp2Redirect('arrendatarios/index.php');
}

$comunasMap = msp2ArrImportFetchComunas($conn);

$summary = [
    'processed' => 0,
    'valid' => 0,
    'errors' => 0,
    'creates' => 0,
    'updates' => 0,
    'unchanged' => 0,
];

$previewRows = [];
$validRows = [];
$erroresMuestra = [];

try {
    $rows = msp2ReadSpreadsheetRows($uploadTmpPath, true, true, false, true);

    if ($rows === [] || !isset($rows[0]) || !is_array($rows[0])) {
        msp2SetFlash('warning', 'La planilla no contiene datos para importar.');
        msp2Redirect('arrendatarios/index.php');
    }

    $headers = [];
    foreach ($rows[0] as $index => $headerValue) {
        $normalized = msp2NormalizeLookupKey(msp2ArrImportCellToString($headerValue));
        if ($normalized !== '') {
            $headers[$normalized] = $index;
        }
    }

    $columns = [
        'rut' => msp2ArrImportFindColumn($headers, ['rut']),
        'es_empresa' => msp2ArrImportFindColumn($headers, ['es_empresa', 'tipo_arrendatario', 'tipo', 'esempresa']),
        'nombre_locatario' => msp2ArrImportFindColumn($headers, ['nombre_locatario', 'nombre_locatario_razon_social', 'nombre', 'locatario', 'razon_social']),
        'nombre_representante' => msp2ArrImportFindColumn($headers, ['nombre_representante', 'representante', 'rep_legal']),
        'correos' => msp2ArrImportFindColumn($headers, ['correos', 'correo', 'emails', 'email']),
        'telefonos' => msp2ArrImportFindColumn($headers, ['telefonos', 'telefono', 'fonos', 'fonos_contacto']),
        'direccion' => msp2ArrImportFindColumn($headers, ['direccion']),
        'comuna' => msp2ArrImportFindColumn($headers, ['comuna', 'desc_comuna']),
    ];

    foreach (['rut', 'nombre_locatario'] as $requiredColumn) {
        if ($columns[$requiredColumn] === null) {
            msp2SetFlash('warning', 'Falta la columna obligatoria `' . $requiredColumn . '` en la planilla.');
            msp2Redirect('arrendatarios/index.php');
        }
    }

    $filaOrigenPorRut = [];
    $candidateRuts = [];

    for ($rowIndex = 1, $rowCount = count($rows); $rowIndex < $rowCount; $rowIndex++) {
        $row = $rows[$rowIndex];
        if (!is_array($row)) {
            continue;
        }

        $rutRaw = msp2ArrImportCellToString($row[$columns['rut']] ?? null);
        $tipoRaw = $columns['es_empresa'] !== null ? msp2ArrImportCellToString($row[$columns['es_empresa']] ?? null) : '';
        $nombreRaw = msp2ArrImportCellToString($row[$columns['nombre_locatario']] ?? null);
        $representanteRaw = $columns['nombre_representante'] !== null ? msp2ArrImportCellToString($row[$columns['nombre_representante']] ?? null) : '';
        $correosRaw = $columns['correos'] !== null ? msp2ArrImportCellToString($row[$columns['correos']] ?? null) : '';
        $telefonosRaw = $columns['telefonos'] !== null ? msp2ArrImportCellToString($row[$columns['telefonos']] ?? null) : '';
        $direccionRaw = $columns['direccion'] !== null ? msp2ArrImportCellToString($row[$columns['direccion']] ?? null) : '';
        $comunaRaw = $columns['comuna'] !== null ? msp2ArrImportCellToString($row[$columns['comuna']] ?? null) : '';

        if ($rutRaw === '' && $tipoRaw === '' && $nombreRaw === '' && $representanteRaw === '' && $correosRaw === '' && $telefonosRaw === '' && $direccionRaw === '' && $comunaRaw === '') {
            continue;
        }

        $summary['processed']++;
        $filaNumero = $rowIndex + 1;
        $errors = [];

        $rut = msp2RutNormalizeDb($rutRaw);
        if ($rut === null) {
            $errors[] = 'RUT inválido.';
        }

        $tipoNormalizado = msp2NormalizeText($tipoRaw);
        if ($tipoNormalizado === '') {
            $esEmpresa = 0;
        } else {
            $esEmpresa = msp2ArrImportParseTipo($tipoRaw);
            if ($esEmpresa === null) {
                $errors[] = 'Tipo arrendatario inválido (usa Persona natural o Empresa).';
            }
        }

        $nombreLocatario = msp2NormalizeText($nombreRaw);
        if ($nombreLocatario === '') {
            $errors[] = 'Nombre locatario obligatorio.';
        } elseif (mb_strlen($nombreLocatario) > 200) {
            $errors[] = 'Nombre locatario supera 200 caracteres.';
        }

        $nombreRepresentante = msp2NormalizeText($representanteRaw);
        if (mb_strlen($nombreRepresentante) > 200) {
            $errors[] = 'Nombre representante supera 200 caracteres.';
        }

        $direccion = msp2NormalizeText($direccionRaw);
        if (mb_strlen($direccion) > 250) {
            $errors[] = 'Dirección supera 250 caracteres.';
        }

        $comunaDesc = msp2NormalizeText($comunaRaw);
        $idComuna = null;
        $descComunaResolved = '';
        $comunaPendingCreate = false;

        if ($comunaDesc !== '') {
            if (mb_strlen($comunaDesc) > 150) {
                $errors[] = 'Comuna supera 150 caracteres.';
            }

            $comunaKey = msp2NormalizeLookupKey($comunaDesc);
            if ($comunaKey === '') {
                $errors[] = 'Comuna inválida.';
            } elseif (!isset($comunasMap[$comunaKey])) {
                $descComunaResolved = $comunaDesc;
                $comunaPendingCreate = true;
            } else {
                $idComuna = (int) $comunasMap[$comunaKey]['id_comuna'];
                $descComunaResolved = (string) $comunasMap[$comunaKey]['desc_comuna'];
            }
        }

        [$correosLista, $correosErrors] = msp2ArrImportParseList($correosRaw, 200, true);
        [$telefonosLista, $telefonosErrors] = msp2ArrImportParseList($telefonosRaw, 50, false);

        foreach ($correosErrors as $error) {
            $errors[] = $error;
        }

        foreach ($telefonosErrors as $error) {
            $errors[] = $error;
        }

        $correosRows = [];
        foreach ($correosLista as $indexCorreo => $correoItem) {
            $correosRows[] = [
                'correo' => $correoItem,
                'es_principal' => $indexCorreo === 0 ? 1 : 0,
            ];
        }

        $telefonosRows = [];
        foreach ($telefonosLista as $indexTelefono => $telefonoItem) {
            $telefonosRows[] = [
                'telefono' => $telefonoItem,
                'es_principal' => $indexTelefono === 0 ? 1 : 0,
            ];
        }

        if ($rut !== null) {
            if (isset($filaOrigenPorRut[$rut])) {
                $errors[] = 'RUT duplicado en planilla (primera aparición en fila ' . $filaOrigenPorRut[$rut] . ').';
            } else {
                $filaOrigenPorRut[$rut] = $filaNumero;
            }
        }

        $isValid = $errors === [];

        if ($isValid) {
            $tipoLabel = $esEmpresa === 1 ? 'Empresa' : 'Persona natural';

            $validRows[] = [
                'rut' => $rut,
                'es_empresa' => $esEmpresa,
                'tipo_label' => $tipoLabel,
                'nombre_locatario' => $nombreLocatario,
                'nombre_representante' => $nombreRepresentante,
                'correos' => $correosRows,
                'telefonos' => $telefonosRows,
                'direccion' => $direccion,
                'id_comuna' => $idComuna,
                'desc_comuna' => $descComunaResolved,
                'comuna_pending_create' => $comunaPendingCreate,
                'id_estado_arrendatario' => (int) $estadoActivo['id_estado_arrendatario'],
                'desc_estado' => (string) $estadoActivo['desc_estado'],
                'row_number' => $filaNumero,
                'action' => '-',
                'change_details' => [],
            ];

            $candidateRuts[] = $rut;
            $summary['valid']++;
        } else {
            $summary['errors']++;
            if (count($erroresMuestra) < 5) {
                $erroresMuestra[] = 'Fila ' . $filaNumero . ': ' . implode(' ', $errors);
            }
        }

        $previewRows[] = [
            'row_number' => $filaNumero,
            'rut' => $rut ?? msp2NormalizeText($rutRaw),
            'tipo_label' => ($esEmpresa === 1 ? 'Empresa' : ($esEmpresa === 0 ? 'Persona natural' : msp2NormalizeText($tipoRaw))),
            'nombre_locatario' => $nombreLocatario,
            'nombre_representante' => $nombreRepresentante,
            'correos_display' => $correosLista === [] ? '-' : implode(' | ', $correosLista),
            'telefonos_display' => $telefonosLista === [] ? '-' : implode(' | ', $telefonosLista),
            'direccion' => $direccion,
            'desc_comuna' => $descComunaResolved !== '' ? $descComunaResolved : ($comunaDesc !== '' ? $comunaDesc : '-'),
            'comuna_pending_create' => $comunaPendingCreate,
            'desc_estado' => (string) $estadoActivo['desc_estado'],
            'action' => '-',
            'change_details' => [],
            'status' => $isValid ? 'OK' : 'ERROR',
            'errors' => $errors,
        ];
    }

    if ($summary['processed'] === 0) {
        msp2SetFlash('warning', 'La planilla no contiene filas con datos.');
        msp2Redirect('arrendatarios/index.php');
    }

    $existingByRut = msp2ArrImportFetchExistingByRut($conn, $candidateRuts);

    $arrIds = [];
    foreach ($existingByRut as $existingRow) {
        $arrIds[] = (int) $existingRow['id_arrendatario'];
    }

    $existingCorreosMap = msp2ArrImportFetchContactMap($conn, $arrIds, 'dbo.msp_arrendatarios_correos', 'id_arrendatario_correo', 'correo');
    $existingTelefonosMap = msp2ArrImportFetchContactMap($conn, $arrIds, 'dbo.msp_arrendatarios_telefonos', 'id_arrendatario_telefono', 'telefono');

    $validRowsByRut = [];

    foreach ($validRows as $index => $row) {
        $rut = (string) $row['rut'];

        if (!isset($existingByRut[$rut])) {
            $summary['creates']++;
            $createDetails = ['Registro nuevo'];
            if ((bool) ($row['comuna_pending_create'] ?? false) && msp2NormalizeText((string) ($row['desc_comuna'] ?? '')) !== '') {
                $createDetails[] = 'Comuna nueva se creará: ' . msp2NormalizeText((string) $row['desc_comuna']);
            }

            $validRows[$index]['action'] = 'CREAR';
            $validRows[$index]['change_details'] = $createDetails;
            $validRowsByRut[$rut] = [
                'action' => 'CREAR',
                'change_details' => $createDetails,
            ];
            continue;
        }

        $existing = $existingByRut[$rut];
        $changeDetails = [];

        $oldTipo = (int) ($existing['es_empresa'] ?? 0);
        $newTipo = (int) $row['es_empresa'];
        if ($oldTipo !== $newTipo) {
            $oldTipoLabel = $oldTipo === 1 ? 'Empresa' : 'Persona natural';
            $newTipoLabel = $newTipo === 1 ? 'Empresa' : 'Persona natural';
            $changeDetails[] = 'Tipo: ' . $oldTipoLabel . ' -> ' . $newTipoLabel;
        }

        $oldNombre = msp2NormalizeText((string) ($existing['nombre_locatario'] ?? ''));
        $newNombre = msp2NormalizeText((string) $row['nombre_locatario']);
        if ($oldNombre !== $newNombre) {
            $changeDetails[] = 'Nombre locatario: ' . $oldNombre . ' -> ' . $newNombre;
        }

        $oldRepresentante = msp2NormalizeText((string) ($existing['nombre_representante'] ?? ''));
        $newRepresentante = msp2NormalizeText((string) $row['nombre_representante']);
        if ($oldRepresentante !== $newRepresentante) {
            $changeDetails[] = 'Representante actualizado';
        }

        $oldDireccion = msp2NormalizeText((string) ($existing['direccion'] ?? ''));
        $newDireccion = msp2NormalizeText((string) $row['direccion']);
        if ($oldDireccion !== $newDireccion) {
            $changeDetails[] = 'Dirección actualizada';
        }

        $oldComuna = $existing['id_comuna'] === null ? null : (int) $existing['id_comuna'];
        $newComuna = $row['id_comuna'] === null ? null : (int) $row['id_comuna'];
        $oldComunaDesc = msp2NormalizeText((string) ($existing['desc_comuna'] ?? ''));
        $newComunaDesc = msp2NormalizeText((string) ($row['desc_comuna'] ?? ''));
        $newComunaPendingCreate = (bool) ($row['comuna_pending_create'] ?? false);

        if ($newComunaPendingCreate && $newComunaDesc !== '') {
            $normalizedOldComuna = msp2NormalizeLookupKey($oldComunaDesc);
            $normalizedNewComuna = msp2NormalizeLookupKey($newComunaDesc);

            if ($normalizedOldComuna !== $normalizedNewComuna) {
                $changeDetails[] = 'Comuna nueva se creará: ' . $newComunaDesc;
            }
        } elseif ($oldComuna !== $newComuna) {
            $oldComunaDesc = msp2NormalizeText((string) ($existing['desc_comuna'] ?? ''));
            $changeDetails[] = 'Comuna: ' . ($oldComunaDesc !== '' ? $oldComunaDesc : 'Sin comuna') . ' -> ' . ($newComunaDesc !== '' ? $newComunaDesc : 'Sin comuna');
        }

        $oldEstado = (int) ($existing['id_estado_arrendatario'] ?? 0);
        $newEstado = (int) $row['id_estado_arrendatario'];
        if ($oldEstado !== $newEstado) {
            $changeDetails[] = 'Estado: ' . msp2NormalizeText((string) ($existing['desc_estado'] ?? '')) . ' -> ' . msp2NormalizeText((string) ($row['desc_estado'] ?? ''));
        }

        $existingCorreos = $existingCorreosMap[(int) $existing['id_arrendatario']] ?? [];
        $oldCorreos = [];
        foreach ($existingCorreos as $item) {
            $oldCorreos[] = (string) ($item['value'] ?? '');
        }

        $newCorreos = [];
        foreach ((array) $row['correos'] as $item) {
            $newCorreos[] = (string) ($item['correo'] ?? '');
        }

        if (msp2ArrImportCanonicalEmails($oldCorreos) !== msp2ArrImportCanonicalEmails($newCorreos)) {
            $changeDetails[] = 'Correos actualizados';
        }

        $existingTelefonos = $existingTelefonosMap[(int) $existing['id_arrendatario']] ?? [];
        $oldTelefonos = [];
        foreach ($existingTelefonos as $item) {
            $oldTelefonos[] = (string) ($item['value'] ?? '');
        }

        $newTelefonos = [];
        foreach ((array) $row['telefonos'] as $item) {
            $newTelefonos[] = (string) ($item['telefono'] ?? '');
        }

        if (msp2ArrImportCanonicalPhones($oldTelefonos) !== msp2ArrImportCanonicalPhones($newTelefonos)) {
            $changeDetails[] = 'Teléfonos actualizados';
        }

        if ($changeDetails === []) {
            $summary['unchanged']++;
            $action = 'SIN_CAMBIOS';
            $changeDetails = ['Sin cambios detectados'];
        } else {
            $summary['updates']++;
            $action = 'ACTUALIZAR';
        }

        $validRows[$index]['action'] = $action;
        $validRows[$index]['change_details'] = $changeDetails;

        $validRowsByRut[$rut] = [
            'action' => $action,
            'change_details' => $changeDetails,
        ];
    }

    foreach ($previewRows as $index => $row) {
        if ($row['status'] !== 'OK') {
            continue;
        }

        $rut = (string) $row['rut'];
        if (!isset($validRowsByRut[$rut])) {
            continue;
        }

        $previewRows[$index]['action'] = $validRowsByRut[$rut]['action'];
        $previewRows[$index]['change_details'] = $validRowsByRut[$rut]['change_details'];
    }
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible procesar la planilla. Verifica su formato e intenta nuevamente.');
    msp2Redirect('arrendatarios/index.php');
}

$token = null;
unset($_SESSION['msp2_arrendatarios_import_preview']);

if ($summary['errors'] === 0 && $summary['valid'] > 0) {
    $token = bin2hex(random_bytes(16));

    $_SESSION['msp2_arrendatarios_import_preview'] = [
        'token' => $token,
        'created_at' => time(),
        'file_name' => $originalName,
        'summary' => $summary,
        'rows' => $validRows,
    ];
}
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Vista Previa Importación de Arrendatarios</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('arrendatarios/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a arrendatarios
            </a>
            <span class="section-kicker">MSP / Importación Arrendatarios</span>
        </div>

        <h1 class="form-title text-center mb-2">Vista Previa de Importación</h1>
        <p class="text-muted text-center mb-4">Archivo: <strong><?php echo msp2Escape($originalName); ?></strong></p>

        <div class="alert alert-secondary" role="alert">
            Estado asignado automáticamente: <strong><?php echo msp2Escape((string) $estadoActivo['desc_estado']); ?></strong>.
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
            <a href="<?php echo msp2Escape(msp2Url('arrendatarios/index.php')); ?>" class="btn btn-outline-secondary">Cancelar</a>
            <?php if ($token !== null): ?>
                <form method="post" action="<?php echo msp2Escape(msp2Url('arrendatarios/confirmar_importacion.php')); ?>">
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
                        <th>RUT</th>
                        <th>Tipo</th>
                        <th>Nombre locatario</th>
                        <th>Representante</th>
                        <th>Correos</th>
                        <th>Teléfonos</th>
                        <th>Dirección</th>
                        <th>Comuna</th>
                        <th>Estado</th>
                        <th style="width: 130px;">Acción</th>
                        <th>Comparación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['row_number']; ?></td>
                            <td><?php echo msp2Escape((string) $row['rut']); ?></td>
                            <td><?php echo msp2Escape((string) $row['tipo_label']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['nombre_locatario']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) ($row['nombre_representante'] ?: '-')); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['correos_display']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['telefonos_display']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) ($row['direccion'] ?: '-')); ?></td>
                            <td class="text-start">
                                <?php echo msp2Escape((string) $row['desc_comuna']); ?>
                                <?php if ((bool) ($row['comuna_pending_create'] ?? false) && (string) $row['desc_comuna'] !== '-'): ?>
                                    <span class="badge bg-warning text-dark ms-1">Nueva</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo msp2Escape((string) $row['desc_estado']); ?></td>
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
                                    <?php echo msp2Escape(implode(' | ', $row['change_details'])); ?>
                                <?php else: ?>
                                    <span class="text-danger"><?php echo msp2Escape(implode(' ', $row['errors'])); ?></span>
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
