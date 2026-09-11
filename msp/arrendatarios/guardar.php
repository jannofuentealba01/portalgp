<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('arrendatarios/index.php');
}

$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$rutInput = msp2NormalizeText($_POST['rut'] ?? null);
$rut = msp2RutNormalizeDb($rutInput);
$esEmpresaInput = trim((string) ($_POST['es_empresa'] ?? ''));
$esEmpresa = $esEmpresaInput === '1' ? 1 : 0;
$nombreLocatario = msp2NormalizeText($_POST['nombre_locatario'] ?? null);
$nombreRepresentante = msp2NormalizeText($_POST['nombre_representante'] ?? null);
$direccion = msp2NormalizeText($_POST['direccion'] ?? null);
$idEstadoArrendatario = filter_input(INPUT_POST, 'id_estado_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idComunaRaw = trim((string) ($_POST['id_comuna'] ?? ''));
$idComuna = null;

$correosInput = $_POST['correos'] ?? [];
$correoPrincipalInput = trim((string) ($_POST['correo_principal'] ?? ''));
$telefonosInput = $_POST['telefonos'] ?? [];
$telefonoPrincipalInput = trim((string) ($_POST['telefono_principal'] ?? ''));

function msp2ArrNormalizePhone(string $value): ?string
{
    $phone = trim($value);
    if ($phone === '' || preg_match('/^\+?[0-9\s()\-]+$/', $phone) !== 1) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '56')) {
        $national = substr($digits, 2);
        return '+56 ' . substr($national, 0, 1) . ' ' . substr($national, 1, 4) . ' ' . substr($national, 5, 4);
    }
    if (strlen($digits) === 9) {
        return substr($digits, 0, 1) . ' ' . substr($digits, 1, 4) . ' ' . substr($digits, 5, 4);
    }

    return null;
}

if ($idComunaRaw !== '') {
    $idComuna = filter_var($idComunaRaw, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);

    if ($idComuna === false) {
        msp2SetFlash('warning', 'La comuna indicada no es válida.');
        msp2Redirect('arrendatarios/index.php');
    }
}

if ($rutInput === '' || $nombreLocatario === '') {
    msp2SetFlash('warning', 'Debes ingresar al menos RUT y nombre del locatario.');
    msp2Redirect('arrendatarios/index.php');
}

if ($rut === null) {
    msp2SetFlash('warning', 'El RUT no es válido: revisa el número y su dígito verificador.');
    msp2Redirect('arrendatarios/index.php');
}

if (!in_array($esEmpresaInput, ['0', '1'], true)) {
    msp2SetFlash('warning', 'Debes seleccionar un tipo de arrendatario válido.');
    msp2Redirect('arrendatarios/index.php');
}

if ($idEstadoArrendatario === false || $idEstadoArrendatario === null) {
    msp2SetFlash('warning', 'Debes seleccionar un estado válido para el arrendatario.');
    msp2Redirect('arrendatarios/index.php');
}

if (mb_strlen($rut) > 20 || mb_strlen($nombreLocatario) > 200 || mb_strlen($nombreRepresentante) > 200 || mb_strlen($direccion) > 250) {
    msp2SetFlash('warning', 'Uno o más campos superan el largo permitido.');
    msp2Redirect('arrendatarios/index.php');
}

if (mb_strlen($nombreLocatario) < 2 || preg_match('/[\p{L}]/u', $nombreLocatario) !== 1) {
    msp2SetFlash('warning', 'El nombre del locatario debe contener al menos dos caracteres y una letra.');
    msp2Redirect('arrendatarios/index.php');
}

if ($nombreRepresentante !== '' && (mb_strlen($nombreRepresentante) < 2 || preg_match('/[\p{L}]/u', $nombreRepresentante) !== 1)) {
    msp2SetFlash('warning', 'El nombre del representante no tiene un formato válido.');
    msp2Redirect('arrendatarios/index.php');
}

if (!is_array($correosInput)) {
    $correosInput = [];
}

if (!is_array($telefonosInput)) {
    $telefonosInput = [];
}

if (count($correosInput) > 5 || count($telefonosInput) > 5) {
    msp2SetFlash('warning', 'Puedes registrar como máximo cinco correos y cinco teléfonos por arrendatario.');
    msp2Redirect('arrendatarios/index.php');
}

$correos = [];
$seenCorreos = [];

foreach ($correosInput as $inputIndex => $correoRaw) {
    $correo = mb_strtolower(msp2NormalizeText((string) $correoRaw), 'UTF-8');

    if ($correo === '') {
        continue;
    }

    if (mb_strlen($correo) > 200) {
        msp2SetFlash('warning', 'Uno de los correos supera los 200 caracteres.');
        msp2Redirect('arrendatarios/index.php');
    }

    if (filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
        msp2SetFlash('warning', 'Uno de los correos ingresados no tiene formato válido.');
        msp2Redirect('arrendatarios/index.php');
    }

    $dedupeKey = mb_strtolower($correo, 'UTF-8');
    if (isset($seenCorreos[$dedupeKey])) {
        continue;
    }

    $seenCorreos[$dedupeKey] = true;

    $correos[] = [
        'input_index' => (string) $inputIndex,
        'correo' => $correo,
        'es_principal' => false,
    ];
}

$telefonos = [];
$seenTelefonos = [];

foreach ($telefonosInput as $inputIndex => $telefonoRaw) {
    $telefonoInput = msp2NormalizeText((string) $telefonoRaw);

    if ($telefonoInput === '') {
        continue;
    }

    $telefono = msp2ArrNormalizePhone($telefonoInput);
    if ($telefono === null) {
        msp2SetFlash('warning', 'Uno de los teléfonos no es válido. Ingresa 9 dígitos nacionales o 11 dígitos comenzando por 56.');
        msp2Redirect('arrendatarios/index.php');
    }

    $dedupeKey = preg_replace('/\D+/', '', $telefono);
    $dedupeKey = $dedupeKey === null ? $telefono : $dedupeKey;

    if (isset($seenTelefonos[$dedupeKey])) {
        continue;
    }

    $seenTelefonos[$dedupeKey] = true;

    $telefonos[] = [
        'input_index' => (string) $inputIndex,
        'telefono' => $telefono,
        'es_principal' => false,
    ];
}

if ($correos !== []) {
    $principalFound = false;

    foreach ($correos as $index => $correoItem) {
        if ($correoItem['input_index'] === $correoPrincipalInput) {
            $correos[$index]['es_principal'] = true;
            $principalFound = true;
            break;
        }
    }

    if (!$principalFound) {
        $correos[0]['es_principal'] = true;
    }
}

if ($telefonos !== []) {
    $principalFound = false;

    foreach ($telefonos as $index => $telefonoItem) {
        if ($telefonoItem['input_index'] === $telefonoPrincipalInput) {
            $telefonos[$index]['es_principal'] = true;
            $principalFound = true;
            break;
        }
    }

    if (!$principalFound) {
        $telefonos[0]['es_principal'] = true;
    }
}

try {
    if (!msp2TableExists($conn, 'msp_arrendatarios_correos')) {
        msp2SetFlash('warning', 'La tabla `msp_arrendatarios_correos` no existe. Ejecuta `msp/msp_a1.sql` actualizado.');
        msp2Redirect('arrendatarios/index.php');
    }

    if (!msp2TableExists($conn, 'msp_arrendatarios_telefonos')) {
        msp2SetFlash('warning', 'La tabla `msp_arrendatarios_telefonos` no existe. Ejecuta `msp/msp_a1.sql` actualizado.');
        msp2Redirect('arrendatarios/index.php');
    }

    $conn->beginTransaction();

    if ($idArrendatario !== false && $idArrendatario !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_arrendatarios WHERE id_arrendatario = :id_arrendatario');
        $existsStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $existsStmt->execute();

        if ((int) $existsStmt->fetchColumn() === 0) {
            throw new RuntimeException('El arrendatario que intentas editar ya no existe.');
        }
    }

    $estadoStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_estado_arrendatarios WHERE id_estado_arrendatario = :id_estado_arrendatario');
    $estadoStmt->bindValue(':id_estado_arrendatario', $idEstadoArrendatario, PDO::PARAM_INT);
    $estadoStmt->execute();

    if ((int) $estadoStmt->fetchColumn() === 0) {
        throw new RuntimeException('El estado seleccionado no existe en el catálogo.');
    }

    if ($idComuna !== null) {
        $comunaStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_comunas WHERE id_comuna = :id_comuna');
        $comunaStmt->bindValue(':id_comuna', $idComuna, PDO::PARAM_INT);
        $comunaStmt->execute();

        if ((int) $comunaStmt->fetchColumn() === 0) {
            throw new RuntimeException('La comuna seleccionada no existe en el catálogo.');
        }
    }

    $duplicateSql = 'SELECT COUNT(*) FROM dbo.msp_arrendatarios WHERE rut = :rut';

    if ($idArrendatario !== false && $idArrendatario !== null) {
        $duplicateSql .= ' AND id_arrendatario <> :id_arrendatario';
    }

    $duplicateStmt = $conn->prepare($duplicateSql);
    $duplicateStmt->bindValue(':rut', $rut, PDO::PARAM_STR);

    if ($idArrendatario !== false && $idArrendatario !== null) {
        $duplicateStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    }

    $duplicateStmt->execute();

    if ((int) $duplicateStmt->fetchColumn() > 0) {
        throw new RuntimeException('Ya existe un arrendatario con ese RUT.');
    }

    if ($idArrendatario !== false && $idArrendatario !== null) {
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_arrendatarios
             SET rut = :rut,
                 es_empresa = :es_empresa,
                 nombre_locatario = :nombre_locatario,
                 nombre_representante = :nombre_representante,
                 direccion = :direccion,
                 id_comuna = :id_comuna,
                 id_estado_arrendatario = :id_estado_arrendatario
             WHERE id_arrendatario = :id_arrendatario'
        );
        $stmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
        $idArrendatarioActual = $idArrendatario;
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_arrendatarios
                (rut, es_empresa, nombre_locatario, nombre_representante, direccion, id_comuna, id_estado_arrendatario)
             VALUES
                (:rut, :es_empresa, :nombre_locatario, :nombre_representante, :direccion, :id_comuna, :id_estado_arrendatario)'
        );
        $idArrendatarioActual = null;
    }

    $stmt->bindValue(':rut', $rut, PDO::PARAM_STR);
    $stmt->bindValue(':es_empresa', $esEmpresa, PDO::PARAM_INT);
    $stmt->bindValue(':nombre_locatario', $nombreLocatario, PDO::PARAM_STR);
    $stmt->bindValue(':nombre_representante', $nombreRepresentante !== '' ? $nombreRepresentante : null, $nombreRepresentante !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':direccion', $direccion !== '' ? $direccion : null, $direccion !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':id_comuna', $idComuna, $idComuna !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
    $stmt->bindValue(':id_estado_arrendatario', $idEstadoArrendatario, PDO::PARAM_INT);
    $stmt->execute();

    if ($idArrendatarioActual === null) {
        $idArrendatarioActual = (int) $conn->lastInsertId();

        if ($idArrendatarioActual <= 0) {
            $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
            $idArrendatarioActual = (int) $identityStmt->fetchColumn();
        }

        if ($idArrendatarioActual <= 0) {
            throw new RuntimeException('No fue posible recuperar el ID del arrendatario creado.');
        }
    }

    $deleteCorreosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_correos WHERE id_arrendatario = :id_arrendatario');
    $deleteCorreosStmt->bindValue(':id_arrendatario', $idArrendatarioActual, PDO::PARAM_INT);
    $deleteCorreosStmt->execute();

    $deleteTelefonosStmt = $conn->prepare('DELETE FROM dbo.msp_arrendatarios_telefonos WHERE id_arrendatario = :id_arrendatario');
    $deleteTelefonosStmt->bindValue(':id_arrendatario', $idArrendatarioActual, PDO::PARAM_INT);
    $deleteTelefonosStmt->execute();

    if ($correos !== []) {
        $insertCorreoStmt = $conn->prepare(
            'INSERT INTO dbo.msp_arrendatarios_correos
                (id_arrendatario, correo, es_principal)
             VALUES
                (:id_arrendatario, :correo, :es_principal)'
        );

        foreach ($correos as $correoItem) {
            $insertCorreoStmt->bindValue(':id_arrendatario', $idArrendatarioActual, PDO::PARAM_INT);
            $insertCorreoStmt->bindValue(':correo', $correoItem['correo'], PDO::PARAM_STR);
            $insertCorreoStmt->bindValue(':es_principal', $correoItem['es_principal'] ? 1 : 0, PDO::PARAM_INT);
            $insertCorreoStmt->execute();
        }
    }

    if ($telefonos !== []) {
        $insertTelefonoStmt = $conn->prepare(
            'INSERT INTO dbo.msp_arrendatarios_telefonos
                (id_arrendatario, telefono, es_principal)
             VALUES
                (:id_arrendatario, :telefono, :es_principal)'
        );

        foreach ($telefonos as $telefonoItem) {
            $insertTelefonoStmt->bindValue(':id_arrendatario', $idArrendatarioActual, PDO::PARAM_INT);
            $insertTelefonoStmt->bindValue(':telefono', $telefonoItem['telefono'], PDO::PARAM_STR);
            $insertTelefonoStmt->bindValue(':es_principal', $telefonoItem['es_principal'] ? 1 : 0, PDO::PARAM_INT);
            $insertTelefonoStmt->execute();
        }
    }

    $conn->commit();
    msp2SetFlash('success', $idArrendatario ? 'El arrendatario fue actualizado correctamente.' : 'El arrendatario fue creado correctamente.');
} catch (RuntimeException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('warning', $exception->getMessage());
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    msp2SetFlash('danger', 'No fue posible guardar el arrendatario. Revisa la estructura de la base o intenta nuevamente.');
}

msp2Redirect('arrendatarios/index.php');
