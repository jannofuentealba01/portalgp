<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('locales/index.php');
}

try {
    msp2LoadSpreadsheetLibrary();
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible cargar la librería de Excel. Ejecuta `composer install` en la raíz del proyecto e intenta nuevamente.');
    msp2Redirect('locales/index.php');
}

[$uploadOk, $uploadError, $uploadMeta] = msp2ValidateSpreadsheetUpload($_FILES['excel_file'] ?? null, msp2ImportUploadMaxBytes());
if (!$uploadOk || !is_array($uploadMeta)) {
    msp2SetFlash('warning', $uploadError !== '' ? $uploadError : 'Debes seleccionar un archivo válido para importar.');
    msp2Redirect('locales/index.php');
}

$originalName = (string) ($uploadMeta['name'] ?? 'importacion.xlsx');
$uploadTmpPath = (string) ($uploadMeta['tmp_name'] ?? '');

if (!msp2TableExists($conn, 'msp_locales') || !msp2TableExists($conn, 'msp_estado_locales')) {
    msp2SetFlash('warning', 'Faltan tablas base (`msp_locales` o `msp_estado_locales`). Ejecuta `msp/msp_a1.sql`.');
    msp2Redirect('locales/index.php');
}

$idEstadoDisponible = msp2EstadoDisponibleId($conn);

if ($idEstadoDisponible === null) {
    msp2SetFlash('warning', 'No existe el estado `Disponible` en `msp_estado_locales`. Créalo antes de importar.');
    msp2Redirect('locales/index.php');
}

$descEstadoDisponible = 'Disponible';
$stmtEstado = $conn->prepare('SELECT TOP 1 desc_estado FROM dbo.msp_estado_locales WHERE id_estado_local = :id_estado_local');
$stmtEstado->bindValue(':id_estado_local', $idEstadoDisponible, PDO::PARAM_INT);
$stmtEstado->execute();
$estadoDb = $stmtEstado->fetchColumn();

if ($estadoDb !== false) {
    $descEstadoDisponible = trim((string) $estadoDb);
}

function msp2ImportCellToString(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    return trim((string) $value);
}

function msp2ImportFindColumn(array $headers, array $aliases): ?int
{
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $headers)) {
            return (int) $headers[$alias];
        }
    }

    return null;
}

function msp2FetchExistingLocalesByCode(PDO $conn, array $codes): array
{
    if ($codes === []) {
        return [];
    }

    $codeKeys = [];
    foreach ($codes as $code) {
        $key = msp2LocalCodeKey((string) $code);
        if ($key !== '') {
            $codeKeys[] = $key;
        }
    }

    if ($codeKeys === []) {
        return [];
    }

    $existing = [];

    foreach (array_chunk(array_values(array_unique($codeKeys)), 500) as $chunk) {
        $placeholders = [];
        $params = [];

        foreach ($chunk as $index => $code) {
            $key = ':code_' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $sql =
            'SELECT
                l.id_local,
                l.cdo_local,
                l.desc_local,
                l.metros_cuadrados,
                l.valor_arriendo_uf,
                l.id_estado_local,
                e.desc_estado
             FROM dbo.msp_locales l
             INNER JOIN dbo.msp_estado_locales e ON e.id_estado_local = l.id_estado_local
             WHERE UPPER(LTRIM(RTRIM(l.cdo_local))) IN (' . implode(', ', $placeholders) . ')';

        $stmt = $conn->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }

        $stmt->execute();

        while (($row = $stmt->fetch()) !== false) {
            $code = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
            $codeKey = msp2LocalCodeKey($code);
            if ($codeKey !== '') {
                $existing[$codeKey] = $row;
            }
        }
    }

    return $existing;
}

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
        msp2Redirect('locales/index.php');
    }

    $headers = [];

    foreach ($rows[0] as $index => $headerValue) {
        $normalized = msp2NormalizeLookupKey(msp2ImportCellToString($headerValue));

        if ($normalized !== '') {
            $headers[$normalized] = $index;
        }
    }

    $columns = [
        'cdo_local' => msp2ImportFindColumn($headers, ['cdo_local', 'codigo_local', 'cod_local', 'local', 'codigo']),
        'metros_cuadrados' => msp2ImportFindColumn($headers, ['metros_cuadrados', 'm2', 'metros', 'superficie']),
        'valor_arriendo_uf' => msp2ImportFindColumn($headers, ['valor_arriendo_uf', 'arriendo_uf', 'valor_uf', 'uf']),
        'desc_local' => msp2ImportFindColumn($headers, ['desc_local', 'descripcion_local', 'descripcion', 'nombre_local']),
    ];

    foreach (['cdo_local', 'metros_cuadrados', 'valor_arriendo_uf'] as $requiredColumn) {
        if ($columns[$requiredColumn] === null) {
            msp2SetFlash('warning', 'Falta la columna obligatoria `' . $requiredColumn . '` en la planilla.');
            msp2Redirect('locales/index.php');
        }
    }

    $filaOrigenPorCodigo = [];
    $candidateCodes = [];

    for ($rowIndex = 1, $rowCount = count($rows); $rowIndex < $rowCount; $rowIndex++) {
        $row = $rows[$rowIndex];

        if (!is_array($row)) {
            continue;
        }

        $cdoLocalRaw = msp2ImportCellToString($row[$columns['cdo_local']] ?? null);
        $metrosRaw = msp2ImportCellToString($row[$columns['metros_cuadrados']] ?? null);
        $arriendoRaw = msp2ImportCellToString($row[$columns['valor_arriendo_uf']] ?? null);
        $descLocalRaw = $columns['desc_local'] !== null ? msp2ImportCellToString($row[$columns['desc_local']] ?? null) : '';

        if ($cdoLocalRaw === '' && $metrosRaw === '' && $arriendoRaw === '' && $descLocalRaw === '') {
            continue;
        }

        $summary['processed']++;

        $filaNumero = $rowIndex + 1;
        $errors = [];

        $cdoLocal = msp2NormalizeLocalCode($cdoLocalRaw);
        $cdoLocalKey = msp2LocalCodeKey($cdoLocal);
        $descLocal = msp2NormalizeText($descLocalRaw);

        if ($cdoLocal === '') {
            $errors[] = 'Código local obligatorio.';
        } elseif (mb_strlen($cdoLocal) > 20) {
            $errors[] = 'Código local supera 20 caracteres.';
        }

        if ($descLocal === '') {
            $descLocal = 'Local ' . $cdoLocal;
        }

        if (mb_strlen($descLocal) > 200) {
            $errors[] = 'Descripción supera 200 caracteres.';
        }

        [$metrosValidos, $metrosCuadrados] = msp2NormalizeDecimalInput($metrosRaw, 2);
        if (!$metrosValidos || $metrosCuadrados === null || (float) $metrosCuadrados < 0) {
            $errors[] = 'm2 debe ser numérico e igual o mayor a 0.';
        }

        [$arriendoValido, $valorArriendoUf] = msp2NormalizeDecimalInput($arriendoRaw, 2);
        if (!$arriendoValido || $valorArriendoUf === null) {
            $errors[] = 'Arriendo UF referencial debe ser numérico e igual o mayor a 0.';
        }

        if ($cdoLocalKey !== '') {
            if (isset($filaOrigenPorCodigo[$cdoLocalKey])) {
                $errors[] = 'Código local duplicado en planilla (primera aparición en fila ' . $filaOrigenPorCodigo[$cdoLocalKey] . ').';
            } else {
                $filaOrigenPorCodigo[$cdoLocalKey] = $filaNumero;
            }
        }

        $isValid = $errors === [];

        if ($isValid) {
            $candidateCodes[] = $cdoLocal;
            $validRows[] = [
                'cdo_local' => $cdoLocal,
                'desc_local' => $descLocal,
                'metros_cuadrados' => $metrosCuadrados,
                'valor_arriendo_uf' => $valorArriendoUf,
                'id_estado_local' => $idEstadoDisponible,
                'desc_estado' => $descEstadoDisponible,
                'row_number' => $filaNumero,
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

        $previewRows[] = [
            'row_number' => $filaNumero,
            'cdo_local' => $cdoLocal,
            'desc_local' => $descLocal,
            'metros_cuadrados' => $metrosCuadrados,
            'valor_arriendo_uf' => $valorArriendoUf,
            'desc_estado' => $descEstadoDisponible,
            'action' => '-',
            'change_details' => [],
            'status' => $isValid ? 'OK' : 'ERROR',
            'errors' => $errors,
        ];
    }

    if ($summary['processed'] === 0) {
        msp2SetFlash('warning', 'La planilla no contiene filas con datos.');
        msp2Redirect('locales/index.php');
    }

    $existingByCode = msp2FetchExistingLocalesByCode($conn, $candidateCodes);

    $validRowsByCode = [];
    foreach ($validRows as $index => $row) {
        $code = $row['cdo_local'];
        $codeKey = msp2LocalCodeKey($code);

        if (!isset($existingByCode[$codeKey])) {
            $summary['creates']++;
            $validRows[$index]['action'] = 'CREAR';
            $validRows[$index]['change_details'] = ['Registro nuevo'];
            $validRowsByCode[$codeKey] = [
                'action' => 'CREAR',
                'change_details' => ['Registro nuevo'],
            ];
            continue;
        }

        $existing = $existingByCode[$codeKey];
        $changeDetails = [];

        $oldDesc = msp2NormalizeText((string) ($existing['desc_local'] ?? ''));
        $newDesc = msp2NormalizeText((string) $row['desc_local']);
        if ($oldDesc !== $newDesc) {
            $changeDetails[] = 'Descripción: ' . $oldDesc . ' -> ' . $newDesc;
        }

        $oldMetros = number_format((float) ($existing['metros_cuadrados'] ?? 0), 2, '.', '');
        $newMetros = number_format((float) ($row['metros_cuadrados'] ?? 0), 2, '.', '');
        if ($oldMetros !== $newMetros) {
            $changeDetails[] = 'm2: ' . $oldMetros . ' -> ' . $newMetros;
        }

        $oldArriendo = number_format((float) ($existing['valor_arriendo_uf'] ?? 0), 2, '.', '');
        $newArriendo = number_format((float) ($row['valor_arriendo_uf'] ?? 0), 2, '.', '');
        if ($oldArriendo !== $newArriendo) {
            $changeDetails[] = 'Arriendo UF referencial: ' . $oldArriendo . ' -> ' . $newArriendo;
        }

        $oldEstado = (int) ($existing['id_estado_local'] ?? 0);
        $newEstado = (int) ($row['id_estado_local'] ?? 0);
        if ($oldEstado !== $newEstado) {
            $changeDetails[] = 'Estado: ' . trim((string) ($existing['desc_estado'] ?? '')) . ' -> ' . (string) $row['desc_estado'];
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

        $validRowsByCode[$codeKey] = [
            'action' => $action,
            'change_details' => $changeDetails,
        ];
    }

    foreach ($previewRows as $index => $row) {
        if ($row['status'] !== 'OK') {
            continue;
        }

        $codeKey = msp2LocalCodeKey((string) $row['cdo_local']);
        if (!isset($validRowsByCode[$codeKey])) {
            continue;
        }

        $previewRows[$index]['action'] = $validRowsByCode[$codeKey]['action'];
        $previewRows[$index]['change_details'] = $validRowsByCode[$codeKey]['change_details'];
    }
} catch (Throwable $exception) {
    msp2SetFlash('danger', 'No fue posible procesar la planilla. Verifica su formato e intenta nuevamente.');
    msp2Redirect('locales/index.php');
}

$token = null;
unset($_SESSION['msp2_locales_import_preview']);

if ($summary['errors'] === 0 && $summary['valid'] > 0) {
    $token = bin2hex(random_bytes(16));

    $_SESSION['msp2_locales_import_preview'] = [
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
    <title>MSP | Vista Previa Importación de Locales</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('locales/index.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a locales
            </a>
            <span class="section-kicker">MSP / Importación Locales</span>
        </div>

        <h1 class="form-title text-center mb-2">Vista Previa de Importación</h1>
        <p class="text-muted text-center mb-4">Archivo: <strong><?php echo msp2Escape($originalName); ?></strong></p>

        <div class="alert alert-secondary" role="alert">
            Estado asignado automáticamente: <strong><?php echo msp2Escape($descEstadoDisponible); ?></strong>.
        </div>

        <div class="row g-2 mb-3">
            <div class="col-12 col-md-2">
                <div class="alert alert-secondary mb-0">Procesadas: <strong><?php echo (int) $summary['processed']; ?></strong></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="alert alert-success mb-0">Válidas: <strong><?php echo (int) $summary['valid']; ?></strong></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="alert alert-primary mb-0">Crear: <strong><?php echo (int) $summary['creates']; ?></strong></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="alert alert-primary mb-0">Actualizar: <strong><?php echo (int) $summary['updates']; ?></strong></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="alert alert-secondary mb-0">Sin cambios: <strong><?php echo (int) $summary['unchanged']; ?></strong></div>
            </div>
            <div class="col-12 col-md-2">
                <div class="alert <?php echo $summary['errors'] > 0 ? 'alert-danger' : 'alert-secondary'; ?> mb-0">Con error: <strong><?php echo (int) $summary['errors']; ?></strong></div>
            </div>
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
            <a href="<?php echo msp2Escape(msp2Url('locales/index.php')); ?>" class="btn btn-outline-secondary">Cancelar</a>
            <?php if ($token !== null): ?>
                <form method="post" action="<?php echo msp2Escape(msp2Url('locales/confirmar_importacion.php')); ?>">
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
                        <th style="width: 90px;">Fila Excel</th>
                        <th style="width: 120px;">Código</th>
                        <th>Descripción</th>
                        <th style="width: 110px;">m2</th>
                        <th style="width: 140px;">Arriendo UF ref.</th>
                        <th style="width: 140px;">Estado</th>
                        <th style="width: 130px;">Acción</th>
                        <th>Comparación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td><?php echo (int) $row['row_number']; ?></td>
                            <td><?php echo msp2Escape((string) $row['cdo_local']); ?></td>
                            <td class="text-start"><?php echo msp2Escape((string) $row['desc_local']); ?></td>
                            <td><?php echo $row['metros_cuadrados'] !== null ? msp2Escape(number_format((float) $row['metros_cuadrados'], 2, ',', '.')) : '-'; ?></td>
                            <td><?php echo $row['valor_arriendo_uf'] !== null ? msp2Escape(number_format((float) $row['valor_arriendo_uf'], 2, ',', '.')) : '-'; ?></td>
                            <td><?php echo msp2Escape((string) $row['desc_estado']); ?></td>
                            <td>
                                <?php
                                    $badge = 'bg-secondary';
                                    if ($row['action'] === 'CREAR') {
                                        $badge = 'bg-success';
                                    } elseif ($row['action'] === 'ACTUALIZAR') {
                                        $badge = 'bg-primary';
                                    } elseif ($row['action'] === 'SIN_CAMBIOS') {
                                        $badge = 'bg-secondary';
                                    }
                                ?>
                                <span class="badge <?php echo $badge; ?>"><?php echo msp2Escape((string) $row['action']); ?></span>
                            </td>
                            <td class="text-start">
                                <?php if ($row['status'] === 'OK'): ?>
                                    <?php if ($row['change_details'] === []): ?>
                                        <span class="text-success">Listo para importar</span>
                                    <?php else: ?>
                                        <?php echo msp2Escape(implode(' | ', $row['change_details'])); ?>
                                    <?php endif; ?>
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
