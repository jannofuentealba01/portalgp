<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2MedidoresRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['locales/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'locales/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('locales/index.php');
}

$idMedidor = filter_input(INPUT_POST, 'id_medidor', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idLocal = filter_input(INPUT_POST, 'id_local', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idTipoServicio = filter_input(INPUT_POST, 'id_tipo_servicio', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$estadoMedidor = filter_input(INPUT_POST, 'estado_medidor', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$estadoMedidor = $estadoMedidor === null || $estadoMedidor === false
    ? filter_input(INPUT_POST, 'id_estado_medidor', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : $estadoMedidor;
$codigoMedidor = strtoupper(msp2NormalizeText($_POST['codigo_medidor'] ?? null));
$aliasMedidor = $codigoMedidor;
$numeroSerie = msp2NormalizeText($_POST['numero_serie'] ?? null);
$valorInicialRaw = $_POST['valor_inicial'] ?? null;
$fechaInstalacionRaw = trim((string) ($_POST['fecha_instalacion'] ?? ''));
$fechaRetiroRaw = trim((string) ($_POST['fecha_retiro'] ?? ''));
$valorInicial = null;
$fechaInstalacion = null;
$fechaRetiro = null;

if ($idLocal === false || $idLocal === null) {
    msp2SetFlash('warning', 'Debes indicar un local válido para el medidor.');
    msp2MedidoresRedirectFromPost();
}

if ($idTipoServicio === false || $idTipoServicio === null) {
    msp2SetFlash('warning', 'Debes seleccionar un servicio válido para el medidor.');
    msp2MedidoresRedirectFromPost();
}

if ($estadoMedidor === false || $estadoMedidor === null) {
    msp2SetFlash('warning', 'Debes seleccionar un estado válido para el medidor.');
    msp2MedidoresRedirectFromPost();
}

if ($codigoMedidor === '') {
    msp2SetFlash('warning', 'Debes ingresar un código de medidor.');
    msp2MedidoresRedirectFromPost();
}

if (mb_strlen($codigoMedidor) > 100 || mb_strlen($aliasMedidor) > 100 || mb_strlen($numeroSerie) > 100) {
    msp2SetFlash('warning', 'El código, alias o número de serie supera el largo permitido.');
    msp2MedidoresRedirectFromPost();
}

[$valorInicialValido, $valorInicialNormalizado] = msp2NormalizeDecimalInput(is_string($valorInicialRaw) ? $valorInicialRaw : null, 6);
if (!$valorInicialValido) {
    msp2SetFlash('warning', 'El valor inicial debe ser un número válido igual o mayor a cero.');
    msp2MedidoresRedirectFromPost();
}

if ($valorInicialNormalizado !== null) {
    $valorInicial = $valorInicialNormalizado;
}

if ($fechaInstalacionRaw !== '') {
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $fechaInstalacionRaw);
    if ($date === false || $date->format('Y-m-d') !== $fechaInstalacionRaw) {
        msp2SetFlash('warning', 'La fecha de instalación no es válida.');
        msp2MedidoresRedirectFromPost();
    }

    $fechaInstalacion = $date->format('Y-m-d');
}

if ($fechaRetiroRaw !== '') {
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRetiroRaw);
    if ($date === false || $date->format('Y-m-d') !== $fechaRetiroRaw) {
        msp2SetFlash('warning', 'La fecha de retiro no es válida.');
        msp2MedidoresRedirectFromPost();
    }

    $fechaRetiro = $date->format('Y-m-d');
}

if ($fechaInstalacion !== null && $fechaRetiro !== null && $fechaRetiro < $fechaInstalacion) {
    msp2SetFlash('warning', 'La fecha de retiro no puede ser menor a la fecha de instalación.');
    msp2MedidoresRedirectFromPost();
}

try {
    $tablasRequeridas = ['msp_medidores', 'msp_tipos_servicio', 'msp_locales'];

    foreach ($tablasRequeridas as $tabla) {
        if (!msp2TableExists($conn, $tabla)) {
            msp2SetFlash('warning', 'Falta la tabla `' . $tabla . '`. Ejecuta `msp/db/msp_cobro_servicios.sql`.');
            msp2MedidoresRedirectFromPost();
        }
    }

    $medidoresTieneValorInicial = msp2ColumnExists($conn, 'msp_medidores', 'valor_inicial');

    if ($idMedidor !== false && $idMedidor !== null) {
        $existsStmt = $conn->prepare('SELECT id_local FROM dbo.msp_medidores WHERE id_medidor = :id_medidor');
        $existsStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
        $existsStmt->execute();
        $idLocalExistente = $existsStmt->fetchColumn();

        if ($idLocalExistente === false) {
            msp2SetFlash('warning', 'El medidor que intentas editar ya no existe.');
            msp2MedidoresRedirectFromPost();
        }
    }

    $localStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_locales WHERE id_local = :id_local');
    $localStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $localStmt->execute();

    if ((int) $localStmt->fetchColumn() === 0) {
        msp2SetFlash('warning', 'El local seleccionado no existe.');
        msp2MedidoresRedirectFromPost();
    }

    $tipoStmt = $conn->prepare(
        "SELECT TOP 1 codigo_servicio
         FROM dbo.msp_tipos_servicio
         WHERE id_tipo_servicio = :id_tipo_servicio"
    );
    $tipoStmt->bindValue(':id_tipo_servicio', $idTipoServicio, PDO::PARAM_INT);
    $tipoStmt->execute();
    $codigoServicio = strtoupper(trim((string) $tipoStmt->fetchColumn()));

    if ($codigoServicio === '') {
        msp2SetFlash('warning', 'El servicio seleccionado no existe.');
        msp2MedidoresRedirectFromPost();
    }

    if (!in_array($codigoServicio, ['AGUA', 'LUZ', 'GAS'], true)) {
        msp2SetFlash('warning', 'Solo se permiten medidores para servicios Agua, Luz o Gas.');
        msp2MedidoresRedirectFromPost();
    }

    if (!in_array((int) $estadoMedidor, [1, 2, 3], true)) {
        msp2SetFlash('warning', 'El estado de medidor seleccionado no es válido.');
        msp2MedidoresRedirectFromPost();
    }

    $duplicateSql = 'SELECT COUNT(*) FROM dbo.msp_medidores WHERE codigo_medidor = :codigo_medidor';
    if ($idMedidor !== false && $idMedidor !== null) {
        $duplicateSql .= ' AND id_medidor <> :id_medidor';
    }

    $duplicateStmt = $conn->prepare($duplicateSql);
    $duplicateStmt->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);

    if ($idMedidor !== false && $idMedidor !== null) {
        $duplicateStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
    }

    $duplicateStmt->execute();

    if ((int) $duplicateStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe un medidor con ese código.');
        msp2MedidoresRedirectFromPost();
    }

    $aliasSql =
        'SELECT COUNT(*)
         FROM dbo.msp_medidores
         WHERE id_local = :id_local
           AND id_tipo_servicio = :id_tipo_servicio
           AND alias_medidor = :alias_medidor';

    if ($idMedidor !== false && $idMedidor !== null) {
        $aliasSql .= ' AND id_medidor <> :id_medidor';
    }

    $aliasStmt = $conn->prepare($aliasSql);
    $aliasStmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $aliasStmt->bindValue(':id_tipo_servicio', $idTipoServicio, PDO::PARAM_INT);
    $aliasStmt->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
    if ($idMedidor !== false && $idMedidor !== null) {
        $aliasStmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
    }
    $aliasStmt->execute();

    if ((int) $aliasStmt->fetchColumn() > 0) {
        msp2SetFlash('warning', 'Ya existe un medidor con ese alias para este local y servicio.');
        msp2MedidoresRedirectFromPost();
    }

    if ($idMedidor !== false && $idMedidor !== null) {
        $setParts = [
            'id_local = :id_local',
            'id_tipo_servicio = :id_tipo_servicio',
            'codigo_medidor = :codigo_medidor',
            'alias_medidor = :alias_medidor',
            'numero_serie = :numero_serie',
        ];

        if ($medidoresTieneValorInicial) {
            $setParts[] = 'valor_inicial = :valor_inicial';
        }

        $setParts[] = 'fecha_instalacion = :fecha_instalacion';
        $setParts[] = 'fecha_retiro = :fecha_retiro';
        $setParts[] = 'estado_medidor = :estado_medidor';

        $stmt = $conn->prepare(
            'UPDATE dbo.msp_medidores
             SET ' . implode(', ', $setParts) . '
             WHERE id_medidor = :id_medidor'
        );
        $stmt->bindValue(':id_medidor', $idMedidor, PDO::PARAM_INT);
    } else {
        $columns = ['id_local', 'id_tipo_servicio', 'codigo_medidor', 'alias_medidor', 'numero_serie'];
        $values = [':id_local', ':id_tipo_servicio', ':codigo_medidor', ':alias_medidor', ':numero_serie'];

        if ($medidoresTieneValorInicial) {
            $columns[] = 'valor_inicial';
            $values[] = ':valor_inicial';
        }

        $columns[] = 'fecha_instalacion';
        $columns[] = 'fecha_retiro';
        $columns[] = 'estado_medidor';
        $values[] = ':fecha_instalacion';
        $values[] = ':fecha_retiro';
        $values[] = ':estado_medidor';

        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_medidores
                (' . implode(', ', $columns) . ')
             VALUES
                (' . implode(', ', $values) . ')'
        );
    }

    $stmt->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $stmt->bindValue(':id_tipo_servicio', $idTipoServicio, PDO::PARAM_INT);
    $stmt->bindValue(':codigo_medidor', $codigoMedidor, PDO::PARAM_STR);
    $stmt->bindValue(':alias_medidor', $aliasMedidor, PDO::PARAM_STR);
    $stmt->bindValue(':numero_serie', $numeroSerie !== '' ? $numeroSerie : null, $numeroSerie !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    if ($medidoresTieneValorInicial) {
        $stmt->bindValue(':valor_inicial', $valorInicial, $valorInicial !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    }
    $stmt->bindValue(':fecha_instalacion', $fechaInstalacion, $fechaInstalacion !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':fecha_retiro', $fechaRetiro, $fechaRetiro !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':estado_medidor', (int) $estadoMedidor, PDO::PARAM_INT);
    $stmt->execute();

    msp2SetFlash('success', $idMedidor ? 'El medidor fue actualizado correctamente.' : 'El medidor fue creado correctamente.');
} catch (PDOException $exception) {
    msp2SetFlash('danger', 'No fue posible guardar el medidor. Revisa la estructura de la base o intenta nuevamente.');
}

msp2MedidoresRedirectFromPost();
