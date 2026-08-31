<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasEditarCargoRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['tiendas/index.php', 'arrendatarios/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'tiendas/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tiendas/index.php');
}

$idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idTipoCargoSalida = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$codLocalCargo = msp2NormalizeLocalCode((string) ($_POST['cod_local_cargo'] ?? ''));
$fechaCargoRaw = trim((string) ($_POST['fecha_cargo'] ?? ''));
$periodoReferenciaMesRaw = trim((string) ($_POST['periodo_referencia_mes'] ?? ''));
$servicioReferencia = msp2NormalizeText((string) ($_POST['servicio_referencia'] ?? ''));
$descripcionCargo = msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? ''));
$montoCargoRaw = trim((string) ($_POST['monto_cargo'] ?? ''));
$observaciones = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));

if ($idCargoSalida === false || $idCargoSalida === null) {
    msp2SetFlash('warning', 'El cargo a editar no es válido.');
    msp2TiendasEditarCargoRedirectFromPost();
}

if ($idTipoCargoSalida === false || $idTipoCargoSalida === null) {
    msp2SetFlash('warning', 'Debes seleccionar un tipo de cargo.');
    msp2TiendasEditarCargoRedirectFromPost();
}

if ($codLocalCargo === '' || mb_strlen($codLocalCargo) > 20) {
    msp2SetFlash('warning', 'Debes seleccionar un local válido.');
    msp2TiendasEditarCargoRedirectFromPost();
}

if ($descripcionCargo === '' || mb_strlen($descripcionCargo) > 500) {
    msp2SetFlash('warning', 'La descripción del cargo es obligatoria y debe tener máximo 500 caracteres.');
    msp2TiendasEditarCargoRedirectFromPost();
}

if ($servicioReferencia !== '' && mb_strlen($servicioReferencia) > 30) {
    msp2SetFlash('warning', 'El servicio de referencia no puede superar 30 caracteres.');
    msp2TiendasEditarCargoRedirectFromPost();
}

if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones no pueden superar 500 caracteres.');
    msp2TiendasEditarCargoRedirectFromPost();
}

[$okMontoCargo, $montoCargo] = msp2NormalizeDecimalInput($montoCargoRaw, 2);
if (!$okMontoCargo || $montoCargo === null || (float) $montoCargo <= 0) {
    msp2SetFlash('warning', 'El monto del cargo no es válido.');
    msp2TiendasEditarCargoRedirectFromPost();
}

$fechaCargo = null;
if ($fechaCargoRaw === '') {
    $fechaCargo = (new DateTimeImmutable('today'))->format('Y-m-d');
} else {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fechaCargoRaw);
    if ($dt === false || $dt->format('Y-m-d') !== $fechaCargoRaw) {
        msp2SetFlash('warning', 'La fecha del cargo no es válida.');
        msp2TiendasEditarCargoRedirectFromPost();
    }
    $fechaCargo = $dt->format('Y-m-d');
}

$periodoReferencia = null;
if ($periodoReferenciaMesRaw !== '') {
    if (preg_match('/^\d{4}-\d{2}$/', $periodoReferenciaMesRaw) !== 1) {
        msp2SetFlash('warning', 'El periodo de referencia no es válido.');
        msp2TiendasEditarCargoRedirectFromPost();
    }

    $periodoReferencia = $periodoReferenciaMesRaw . '-01';
    $dtPeriodo = DateTimeImmutable::createFromFormat('Y-m-d', $periodoReferencia);
    if ($dtPeriodo === false || $dtPeriodo->format('Y-m-d') !== $periodoReferencia) {
        msp2SetFlash('warning', 'El periodo de referencia no es válido.');
        msp2TiendasEditarCargoRedirectFromPost();
    }
}

try {
    $requiredTables = [
        'msp_cargos_salida',
        'msp_contratos_arriendo',
        'msp_tipos_cargo_salida',
        'msp_locales',
        'msp_ocupacion_locales',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para editar cargos.');
        }
    }

    $stmtCargo = $conn->prepare(
        'SELECT
            cs.id_contrato_arriendo,
            cs.estado_cargo,
            c.id_tienda
         FROM dbo.msp_cargos_salida cs
         INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = cs.id_contrato_arriendo
         WHERE cs.id_cargo_salida = :id_cargo_salida'
    );
    $stmtCargo->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
    $stmtCargo->execute();
    $cargoActual = $stmtCargo->fetch();
    if ($cargoActual === false) {
        throw new RuntimeException('El cargo que intentas editar ya no existe.');
    }

    $idContratoArriendo = (int) ($cargoActual['id_contrato_arriendo'] ?? 0);
    $idTienda = (int) ($cargoActual['id_tienda'] ?? 0);
    $estadoCargo = (int) ($cargoActual['estado_cargo'] ?? 0);
    if ($idContratoArriendo <= 0 || $idTienda <= 0) {
        throw new RuntimeException('No fue posible identificar el contrato asociado al cargo.');
    }

    if ($estadoCargo !== 1) {
        throw new RuntimeException('Solo se pueden editar cargos en estado pendiente.');
    }

    $stmtLocal = $conn->prepare(
        'SELECT TOP 1 id_local
         FROM dbo.msp_locales
         WHERE UPPER(LTRIM(RTRIM(cdo_local))) = :codigo_key'
    );
    $stmtLocal->bindValue(':codigo_key', msp2LocalCodeKey($codLocalCargo), PDO::PARAM_STR);
    $stmtLocal->execute();
    $idLocal = (int) $stmtLocal->fetchColumn();
    if ($idLocal <= 0) {
        throw new RuntimeException('El local seleccionado no existe.');
    }

    $stmtOcupacion = $conn->prepare(
        'SELECT COUNT(*)
         FROM dbo.msp_ocupacion_locales
         WHERE id_tienda = :id_tienda
           AND id_local = :id_local'
    );
    $stmtOcupacion->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtOcupacion->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $stmtOcupacion->execute();
    if ((int) $stmtOcupacion->fetchColumn() <= 0) {
        throw new RuntimeException('El local no está asociado a la tienda del cargo.');
    }

    $stmtTipo = $conn->prepare(
        'SELECT
            codigo_tipo_cargo,
            requiere_documento
         FROM dbo.msp_tipos_cargo_salida
         WHERE id_tipo_cargo_salida = :id_tipo_cargo_salida
           AND activo = 1'
    );
    $stmtTipo->bindValue(':id_tipo_cargo_salida', $idTipoCargoSalida, PDO::PARAM_INT);
    $stmtTipo->execute();
    $tipoCargo = $stmtTipo->fetch();
    if ($tipoCargo === false) {
        throw new RuntimeException('El tipo de cargo seleccionado no está disponible.');
    }

    $requiereDocumento = ((int) ($tipoCargo['requiere_documento'] ?? 0)) === 1;
    if ($requiereDocumento) {
        throw new RuntimeException('Este tipo de cargo requiere documento asociado y no se puede editar desde este flujo.');
    }

    $codigoTipo = msp2NormalizeText((string) ($tipoCargo['codigo_tipo_cargo'] ?? ''));
    $esEstimado = $codigoTipo === 'SERVICIO_ESTIMADO';
    if ($esEstimado && $servicioReferencia === '') {
        throw new RuntimeException('Para servicio estimado debes indicar el servicio de referencia.');
    }

    $origenCargo = 4;
    if ($esEstimado) {
        $origenCargo = 2;
    } elseif ($codigoTipo === 'MULTA' || $codigoTipo === 'DANOS') {
        $origenCargo = 3;
    }

    $stmtUpdateCargo = $conn->prepare(
        'UPDATE dbo.msp_cargos_salida
         SET id_local = :id_local,
             id_tipo_cargo_salida = :id_tipo_cargo_salida,
             fecha_cargo = :fecha_cargo,
             origen_cargo = :origen_cargo,
             id_documento_cobro = NULL,
             periodo_referencia = :periodo_referencia,
             servicio_referencia = :servicio_referencia,
             descripcion_cargo = :descripcion_cargo,
             monto_cargo = :monto_cargo,
             es_estimado = :es_estimado,
             observaciones = :observaciones
         WHERE id_cargo_salida = :id_cargo_salida
           AND estado_cargo = 1'
    );

    $conn->beginTransaction();

    $stmtUpdateCargo->bindValue(':id_local', $idLocal, PDO::PARAM_INT);
    $stmtUpdateCargo->bindValue(':id_tipo_cargo_salida', $idTipoCargoSalida, PDO::PARAM_INT);
    $stmtUpdateCargo->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
    $stmtUpdateCargo->bindValue(':origen_cargo', $origenCargo, PDO::PARAM_INT);
    $stmtUpdateCargo->bindValue(':periodo_referencia', $periodoReferencia, $periodoReferencia !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpdateCargo->bindValue(':servicio_referencia', $servicioReferencia !== '' ? $servicioReferencia : null, $servicioReferencia !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpdateCargo->bindValue(':descripcion_cargo', $descripcionCargo, PDO::PARAM_STR);
    $stmtUpdateCargo->bindValue(':monto_cargo', $montoCargo, PDO::PARAM_STR);
    $stmtUpdateCargo->bindValue(':es_estimado', $esEstimado ? 1 : 0, PDO::PARAM_INT);
    $stmtUpdateCargo->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtUpdateCargo->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
    $stmtUpdateCargo->execute();

    if ($stmtUpdateCargo->rowCount() <= 0) {
        throw new RuntimeException('No fue posible editar el cargo porque cambió de estado.');
    }

    $conn->commit();
    msp2SetFlash('success', 'El cargo fue actualizado correctamente.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2TiendasEditarCargoRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible editar el cargo. Intenta nuevamente.');
}

msp2TiendasEditarCargoRedirectFromPost();
