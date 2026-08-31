<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('arrendatarios/index.php');
}

$token = trim((string) ($_POST['token'] ?? ''));
$preview = $_SESSION['msp2_arrendatarios_import_preview'] ?? null;

if ($token === '' || !is_array($preview) || !isset($preview['token']) || !is_string($preview['token']) || !hash_equals($preview['token'], $token)) {
    msp2SetFlash('warning', 'La vista previa ya no es válida. Vuelve a cargar el archivo.');
    msp2Redirect('arrendatarios/index.php');
}

$createdAt = isset($preview['created_at']) ? (int) $preview['created_at'] : 0;
if ($createdAt <= 0 || (time() - $createdAt) > 1800) {
    unset($_SESSION['msp2_arrendatarios_import_preview']);
    msp2SetFlash('warning', 'La vista previa expiró (30 min). Vuelve a cargar el archivo.');
    msp2Redirect('arrendatarios/index.php');
}

$rows = $preview['rows'] ?? null;
$fileName = (string) ($preview['file_name'] ?? 'archivo');

if (!is_array($rows) || $rows === []) {
    unset($_SESSION['msp2_arrendatarios_import_preview']);
    msp2SetFlash('warning', 'No hay filas válidas para importar.');
    msp2Redirect('arrendatarios/index.php');
}

$requiredTables = [
    'msp_arrendatarios',
    'msp_comunas',
    'msp_estado_arrendatarios',
    'msp_arrendatarios_correos',
    'msp_arrendatarios_telefonos',
];

foreach ($requiredTables as $tableName) {
    if (!msp2TableExists($conn, $tableName)) {
        msp2SetFlash('warning', 'Falta la tabla `' . $tableName . '` para confirmar la importación.');
        msp2Redirect('arrendatarios/index.php');
    }
}

function msp2ArrConfirmComunasMap(PDO $conn): array
{
    $stmt = $conn->query('SELECT id_comuna, desc_comuna FROM dbo.msp_comunas');
    $rows = $stmt->fetchAll();

    $map = [];
    foreach ($rows as $row) {
        $desc = msp2NormalizeText((string) ($row['desc_comuna'] ?? ''));
        $key = msp2NormalizeLookupKey($desc);

        if ($desc === '' || $key === '') {
            continue;
        }

        $map[$key] = (int) $row['id_comuna'];
    }

    return $map;
}

$comunasMap = msp2ArrConfirmComunasMap($conn);

$findStmt = $conn->prepare('SELECT TOP 1 id_arrendatario FROM dbo.msp_arrendatarios WHERE rut = :rut');
$checkEstadoStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_estado_arrendatarios WHERE id_estado_arrendatario = :id_estado_arrendatario');
$checkComunaStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_comunas WHERE id_comuna = :id_comuna');
$findComunaByDescStmt = $conn->prepare(
    'SELECT TOP 1 id_comuna
     FROM dbo.msp_comunas
     WHERE LTRIM(RTRIM(LOWER(desc_comuna))) = LTRIM(RTRIM(LOWER(:desc_comuna)))
     ORDER BY id_comuna ASC'
);
$insertComunaStmt = $conn->prepare('INSERT INTO dbo.msp_comunas (desc_comuna) VALUES (:desc_comuna)');

$insertStmt = $conn->prepare(
    'INSERT INTO dbo.msp_arrendatarios
        (rut, es_empresa, nombre_locatario, nombre_representante, direccion, id_comuna, id_estado_arrendatario)
     VALUES
        (:rut, :es_empresa, :nombre_locatario, :nombre_representante, :direccion, :id_comuna, :id_estado_arrendatario)'
);

$updateStmt = $conn->prepare(
    'UPDATE dbo.msp_arrendatarios
     SET es_empresa = :es_empresa,
         nombre_locatario = :nombre_locatario,
         nombre_representante = :nombre_representante,
         direccion = :direccion,
         id_comuna = :id_comuna,
         id_estado_arrendatario = :id_estado_arrendatario
     WHERE id_arrendatario = :id_arrendatario'
);

$deleteCorreosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_correos WHERE id_arrendatario = :id_arrendatario');
$insertCorreoStmt = $conn->prepare(
    'INSERT INTO dbo.msp_arrendatarios_correos
        (id_arrendatario, correo, es_principal)
     VALUES
        (:id_arrendatario, :correo, :es_principal)'
);

$deleteTelefonosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_telefonos WHERE id_arrendatario = :id_arrendatario');
$insertTelefonoStmt = $conn->prepare(
    'INSERT INTO dbo.msp_arrendatarios_telefonos
        (id_arrendatario, telefono, es_principal)
     VALUES
        (:id_arrendatario, :telefono, :es_principal)'
);

$insertados = 0;
$actualizados = 0;
$sinCambios = 0;

try {
    $conn->beginTransaction();

    foreach ($rows as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('Formato de fila inválido en lote de importación.');
        }

        $rut = msp2NormalizeText((string) ($row['rut'] ?? ''));
        $esEmpresa = isset($row['es_empresa']) ? (int) $row['es_empresa'] : -1;
        $nombreLocatario = msp2NormalizeText((string) ($row['nombre_locatario'] ?? ''));
        $nombreRepresentante = msp2NormalizeText((string) ($row['nombre_representante'] ?? ''));
        $direccion = msp2NormalizeText((string) ($row['direccion'] ?? ''));
        $idComuna = array_key_exists('id_comuna', $row) && $row['id_comuna'] !== null ? (int) $row['id_comuna'] : null;
        $descComunaInput = msp2NormalizeText((string) ($row['desc_comuna'] ?? ''));
        $idEstado = isset($row['id_estado_arrendatario']) ? (int) $row['id_estado_arrendatario'] : 0;
        $action = strtoupper(trim((string) ($row['action'] ?? '')));

        if ($rut === '' || $esEmpresa < 0 || $esEmpresa > 1 || $nombreLocatario === '' || $idEstado <= 0) {
            throw new RuntimeException('Fila inválida durante confirmación de arrendatarios.');
        }

        $checkEstadoStmt->bindValue(':id_estado_arrendatario', $idEstado, PDO::PARAM_INT);
        $checkEstadoStmt->execute();
        if ((int) $checkEstadoStmt->fetchColumn() === 0) {
            throw new RuntimeException('Estado de arrendatario no existe durante confirmación.');
        }

        if ($idComuna !== null) {
            $checkComunaStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
            $checkComunaStmt->execute();
            if ((int) $checkComunaStmt->fetchColumn() === 0) {
                throw new RuntimeException('Comuna no existe durante confirmación.');
            }
        } elseif ($descComunaInput !== '' && $descComunaInput !== '-') {
            $comunaKey = msp2NormalizeLookupKey($descComunaInput);

            if ($comunaKey !== '') {
                if (isset($comunasMap[$comunaKey])) {
                    $idComuna = (int) $comunasMap[$comunaKey];
                } else {
                    $newComunaId = 0;

                    try {
                        $insertComunaStmt->bindValue(':desc_comuna', $descComunaInput, PDO::PARAM_STR);
                        $insertComunaStmt->execute();

                        $newComunaId = (int) $conn->lastInsertId();
                        if ($newComunaId <= 0) {
                            $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
                            $newComunaId = (int) $identityStmt->fetchColumn();
                        }
                    } catch (PDOException $exception) {
                        $newComunaId = 0;
                    }

                    if ($newComunaId <= 0) {
                        $findComunaByDescStmt->bindValue(':desc_comuna', $descComunaInput, PDO::PARAM_STR);
                        $findComunaByDescStmt->execute();
                        $newComunaId = (int) $findComunaByDescStmt->fetchColumn();
                    }

                    if ($newComunaId > 0) {
                        $idComuna = $newComunaId;
                        $comunasMap[$comunaKey] = $newComunaId;
                    } else {
                        // Si no se pudo crear/encontrar, se deja sin comuna.
                        $idComuna = null;
                    }
                }
            }
        }

        $correos = is_array($row['correos'] ?? null) ? $row['correos'] : [];
        $telefonos = is_array($row['telefonos'] ?? null) ? $row['telefonos'] : [];

        $findStmt->bindValue(':rut', $rut, PDO::PARAM_STR);
        $findStmt->execute();
        $idArrendatario = $findStmt->fetchColumn();

        if ($idArrendatario !== false) {
            $idArr = (int) $idArrendatario;

            if ($action === 'SIN_CAMBIOS') {
                $sinCambios++;
                continue;
            }

            $updateStmt->bindValue(':id_arrendatario', $idArr, PDO::PARAM_INT);
            $updateStmt->bindValue(':es_empresa', $esEmpresa, PDO::PARAM_INT);
            $updateStmt->bindValue(':nombre_locatario', $nombreLocatario, PDO::PARAM_STR);
            $updateStmt->bindValue(':nombre_representante', $nombreRepresentante !== '' ? $nombreRepresentante : null, $nombreRepresentante !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updateStmt->bindValue(':direccion', $direccion !== '' ? $direccion : null, $direccion !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $updateStmt->bindValue(':id_comuna', $idComuna, $idComuna !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $updateStmt->bindValue(':id_estado_arrendatario', $idEstado, PDO::PARAM_INT);
            $updateStmt->execute();

            $actualizados++;
        } else {
            $insertStmt->bindValue(':rut', $rut, PDO::PARAM_STR);
            $insertStmt->bindValue(':es_empresa', $esEmpresa, PDO::PARAM_INT);
            $insertStmt->bindValue(':nombre_locatario', $nombreLocatario, PDO::PARAM_STR);
            $insertStmt->bindValue(':nombre_representante', $nombreRepresentante !== '' ? $nombreRepresentante : null, $nombreRepresentante !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertStmt->bindValue(':direccion', $direccion !== '' ? $direccion : null, $direccion !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $insertStmt->bindValue(':id_comuna', $idComuna, $idComuna !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $insertStmt->bindValue(':id_estado_arrendatario', $idEstado, PDO::PARAM_INT);
            $insertStmt->execute();

            $idArr = (int) $conn->lastInsertId();
            if ($idArr <= 0) {
                $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
                $idArr = (int) $identityStmt->fetchColumn();
            }

            if ($idArr <= 0) {
                throw new RuntimeException('No fue posible recuperar el ID del arrendatario insertado.');
            }

            $insertados++;
        }

        $deleteCorreosStmt->bindValue(':id_arrendatario', $idArr, PDO::PARAM_INT);
        $deleteCorreosStmt->execute();

        $deleteTelefonosStmt->bindValue(':id_arrendatario', $idArr, PDO::PARAM_INT);
        $deleteTelefonosStmt->execute();

        foreach ($correos as $correo) {
            if (!is_array($correo)) {
                continue;
            }

            $value = msp2NormalizeText((string) ($correo['correo'] ?? ''));
            if ($value === '') {
                continue;
            }

            $insertCorreoStmt->bindValue(':id_arrendatario', $idArr, PDO::PARAM_INT);
            $insertCorreoStmt->bindValue(':correo', $value, PDO::PARAM_STR);
            $insertCorreoStmt->bindValue(':es_principal', ((int) ($correo['es_principal'] ?? 0)) === 1 ? 1 : 0, PDO::PARAM_INT);
            $insertCorreoStmt->execute();
        }

        foreach ($telefonos as $telefono) {
            if (!is_array($telefono)) {
                continue;
            }

            $value = msp2NormalizeText((string) ($telefono['telefono'] ?? ''));
            if ($value === '') {
                continue;
            }

            $insertTelefonoStmt->bindValue(':id_arrendatario', $idArr, PDO::PARAM_INT);
            $insertTelefonoStmt->bindValue(':telefono', $value, PDO::PARAM_STR);
            $insertTelefonoStmt->bindValue(':es_principal', ((int) ($telefono['es_principal'] ?? 0)) === 1 ? 1 : 0, PDO::PARAM_INT);
            $insertTelefonoStmt->execute();
        }
    }

    $conn->commit();
    unset($_SESSION['msp2_arrendatarios_import_preview']);

    msp2SetFlash(
        'success',
        'Importación completada desde `' . $fileName . '`: ' . $insertados . ' arrendatarios creados, ' . $actualizados . ' actualizados y ' . $sinCambios . ' sin cambios.'
    );
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('danger', 'No fue posible confirmar la importación. Se revirtió toda la transacción.');
}

msp2Redirect('arrendatarios/index.php');
