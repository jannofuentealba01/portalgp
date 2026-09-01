<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasCargoRedirectFromPost(): never
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

$idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idTipoCargoSalida = filter_input(INPUT_POST, 'id_tipo_cargo_salida', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaCargoRaw = trim((string) ($_POST['fecha_cargo'] ?? ''));
$periodoReferenciaMesRaw = trim((string) ($_POST['periodo_referencia_mes'] ?? ''));
$servicioReferencia = msp2NormalizeText((string) ($_POST['servicio_referencia'] ?? ''));
$descripcionCargo = msp2NormalizeText((string) ($_POST['descripcion_cargo'] ?? ''));
$montoCargoRaw = trim((string) ($_POST['monto_cargo'] ?? ''));
$observaciones = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));

if ($idTienda === false || $idTienda === null) {
    msp2SetFlash('warning', 'La tienda seleccionada no es válida.');
    msp2TiendasCargoRedirectFromPost();
}

if ($idTipoCargoSalida === false || $idTipoCargoSalida === null) {
    msp2SetFlash('warning', 'Debes seleccionar un tipo de cargo.');
    msp2TiendasCargoRedirectFromPost();
}

if ($descripcionCargo === '' || mb_strlen($descripcionCargo) > 500) {
    msp2SetFlash('warning', 'La descripción del cargo es obligatoria y debe tener máximo 500 caracteres.');
    msp2TiendasCargoRedirectFromPost();
}

if ($servicioReferencia !== '' && mb_strlen($servicioReferencia) > 30) {
    msp2SetFlash('warning', 'El servicio de referencia no puede superar 30 caracteres.');
    msp2TiendasCargoRedirectFromPost();
}

if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones no pueden superar 500 caracteres.');
    msp2TiendasCargoRedirectFromPost();
}

[$okMontoCargo, $montoCargo] = msp2NormalizeDecimalInput($montoCargoRaw, 2);
if (!$okMontoCargo || $montoCargo === null || (float) $montoCargo <= 0) {
    msp2SetFlash('warning', 'El monto del cargo no es válido.');
    msp2TiendasCargoRedirectFromPost();
}

$fechaCargo = null;
if ($fechaCargoRaw === '') {
    $fechaCargo = (new DateTimeImmutable('today'))->format('Y-m-d');
} else {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fechaCargoRaw);
    if ($dt === false || $dt->format('Y-m-d') !== $fechaCargoRaw) {
        msp2SetFlash('warning', 'La fecha del cargo no es válida.');
        msp2TiendasCargoRedirectFromPost();
    }
    $fechaCargo = $dt->format('Y-m-d');
}

$periodoReferencia = null;
if ($periodoReferenciaMesRaw !== '') {
    if (preg_match('/^\d{4}-\d{2}$/', $periodoReferenciaMesRaw) !== 1) {
        msp2SetFlash('warning', 'El periodo de referencia no es válido.');
        msp2TiendasCargoRedirectFromPost();
    }

    $periodoReferencia = $periodoReferenciaMesRaw . '-01';
    $dtPeriodo = DateTimeImmutable::createFromFormat('Y-m-d', $periodoReferencia);
    if ($dtPeriodo === false || $dtPeriodo->format('Y-m-d') !== $periodoReferencia) {
        msp2SetFlash('warning', 'El periodo de referencia no es válido.');
        msp2TiendasCargoRedirectFromPost();
    }
}

try {
    $requiredTables = [
        'msp_tiendas',
        'msp_contratos_arriendo',
        'msp_tipos_cargo_salida',
        'msp_cargos_salida',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para registrar cargos.');
        }
    }

    $stmtTienda = $conn->prepare(
        "SELECT COUNT(*)
         FROM dbo.msp_tiendas t
         INNER JOIN dbo.msp_estado_tiendas et
            ON et.id_estado_tienda = t.id_estado_tienda
         WHERE t.id_tienda = :id_tienda
           AND UPPER(LTRIM(RTRIM(et.desc_estado))) NOT IN (N'INACTIVO', N'CERRADO')
           AND (t.fecha_termino IS NULL OR t.fecha_termino >= CONVERT(date, SYSDATETIME()))"
    );
    $stmtTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtTienda->execute();
    if ((int) $stmtTienda->fetchColumn() <= 0) {
        throw new RuntimeException('La tienda seleccionada no existe o se encuentra inactiva.');
    }

    $stmtContrato = $conn->prepare(
        'SELECT TOP 1 id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE id_tienda = :id_tienda
           AND estado_contrato IN (1,2,3)
         ORDER BY id_contrato_arriendo DESC'
    );
    $stmtContrato->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtContrato->execute();
    $idContratoArriendo = (int) $stmtContrato->fetchColumn();
    if ($idContratoArriendo <= 0) {
        throw new RuntimeException('La tienda no tiene contrato activo para registrar cargos.');
    }

    $stmtTipo = $conn->prepare(
        'SELECT
            codigo_tipo_cargo,
            requiere_documento,
            permite_estimacion
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
        throw new RuntimeException('Este tipo de cargo requiere documento asociado y aún no está habilitado en esta etapa.');
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

    $stmtInsertCargo = $conn->prepare(
        'INSERT INTO dbo.msp_cargos_salida
            (id_contrato_arriendo, id_local, id_tipo_cargo_salida, fecha_cargo, origen_cargo, id_documento_cobro, periodo_referencia, servicio_referencia, descripcion_cargo, monto_cargo, es_estimado, estado_cargo, observaciones)
         VALUES
            (:id_contrato_arriendo, NULL, :id_tipo_cargo_salida, :fecha_cargo, :origen_cargo, NULL, :periodo_referencia, :servicio_referencia, :descripcion_cargo, :monto_cargo, :es_estimado, 1, :observaciones)'
    );

    $conn->beginTransaction();

    $stmtInsertCargo->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtInsertCargo->bindValue(':id_tipo_cargo_salida', $idTipoCargoSalida, PDO::PARAM_INT);
    $stmtInsertCargo->bindValue(':fecha_cargo', $fechaCargo, PDO::PARAM_STR);
    $stmtInsertCargo->bindValue(':origen_cargo', $origenCargo, PDO::PARAM_INT);
    $stmtInsertCargo->bindValue(':periodo_referencia', $periodoReferencia, $periodoReferencia !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertCargo->bindValue(':servicio_referencia', $servicioReferencia !== '' ? $servicioReferencia : null, $servicioReferencia !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertCargo->bindValue(':descripcion_cargo', $descripcionCargo, PDO::PARAM_STR);
    $stmtInsertCargo->bindValue(':monto_cargo', $montoCargo, PDO::PARAM_STR);
    $stmtInsertCargo->bindValue(':es_estimado', $esEstimado ? 1 : 0, PDO::PARAM_INT);
    $stmtInsertCargo->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmtInsertCargo->execute();

    $conn->commit();
    msp2SetFlash('success', 'El cargo fue registrado para la tienda correctamente.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2TiendasCargoRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible registrar el cargo. Revisa la estructura o intenta nuevamente.');
}

msp2TiendasCargoRedirectFromPost();
