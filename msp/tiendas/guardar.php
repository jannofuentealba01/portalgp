<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasRedirectFromPost(): never
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
$idArrendatario = filter_input(INPUT_POST, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idRubro = filter_input(INPUT_POST, 'id_rubro', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idEstadoTienda = filter_input(INPUT_POST, 'id_estado_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$nombreComercial = msp2NormalizeText($_POST['nombre_comercial'] ?? null);
$fechaInicioRaw = trim((string) ($_POST['fecha_inicio'] ?? ''));
$codLocalesRaw = trim((string) ($_POST['cod_locales'] ?? '')); // Compatibilidad UI: ya no se persiste desde Tiendas.
$fechaInicioOcupRaw = trim((string) ($_POST['fecha_inicio_ocupacion'] ?? '')); // Compatibilidad UI.
$fechaTerminoOcupRaw = trim((string) ($_POST['fecha_termino_ocupacion'] ?? '')); // Compatibilidad UI.
$fechaInicioContratoRaw = trim((string) ($_POST['fecha_inicio_contrato'] ?? ''));
$fechaTerminoPactadaRaw = trim((string) ($_POST['fecha_termino_pactada'] ?? ''));
$montoArriendoPactadoRaw = trim((string) ($_POST['monto_arriendo_pactado'] ?? ''));
$rubroContratoRaw = msp2NormalizeText($_POST['rubro_contrato'] ?? null);
$garantiaCodLocalesRaw = $_POST['garantia_cod_local'] ?? [];
$garantiaFechasRaw = $_POST['garantia_fecha_constitucion'] ?? [];
$garantiaMontosRaw = $_POST['garantia_monto_inicial'] ?? [];
$garantiaObservacionesRaw = $_POST['garantia_observaciones'] ?? [];
$fechaInicio = null;

if ($idArrendatario === false || $idArrendatario === null || $idRubro === false || $idRubro === null || $idEstadoTienda === false || $idEstadoTienda === null || $nombreComercial === '') {
    msp2SetFlash('warning', 'Debes ingresar arrendatario, nombre comercial, rubro y estado.');
    msp2TiendasRedirectFromPost();
}

if (mb_strlen($nombreComercial) > 200) {
    msp2SetFlash('warning', 'El nombre comercial supera 200 caracteres.');
    msp2TiendasRedirectFromPost();
}

if ($fechaInicioRaw !== '') {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $fechaInicioRaw);
    if ($dt === false || $dt->format('Y-m-d') !== $fechaInicioRaw) {
        msp2SetFlash('warning', 'La fecha de inicio no es válida.');
        msp2TiendasRedirectFromPost();
    }

    $fechaInicio = $dt->format('Y-m-d');
}

// Ocupacion ahora se administra solo en modulo Contratos.
// Conservamos lectura de los campos por compatibilidad de formulario, pero no los persistimos aqui.

if (!is_array($garantiaCodLocalesRaw) || !is_array($garantiaFechasRaw) || !is_array($garantiaMontosRaw) || !is_array($garantiaObservacionesRaw)) {
    msp2SetFlash('warning', 'El formato de garantías no es válido.');
    msp2TiendasRedirectFromPost();
}

try {
    if ($idTienda !== false && $idTienda !== null) {
        $existsStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_tiendas WHERE id_tienda = :id_tienda');
        $existsStmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $existsStmt->execute();

        if ((int) $existsStmt->fetchColumn() === 0) {
            msp2SetFlash('warning', 'La tienda que intentas editar ya no existe.');
            msp2TiendasRedirectFromPost();
        }
    }

    $arrStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_arrendatarios WHERE id_arrendatario = :id_arrendatario');
    $arrStmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $arrStmt->execute();
    if ((int) $arrStmt->fetchColumn() === 0) {
        msp2SetFlash('warning', 'El arrendatario seleccionado no existe.');
        msp2TiendasRedirectFromPost();
    }

    $rubroStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_rubros WHERE id_rubro = :id_rubro');
    $rubroStmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
    $rubroStmt->execute();
    if ((int) $rubroStmt->fetchColumn() === 0) {
        msp2SetFlash('warning', 'El rubro seleccionado no existe.');
        msp2TiendasRedirectFromPost();
    }

    $estadoStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_estado_tiendas WHERE id_estado_tienda = :id_estado_tienda');
    $estadoStmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
    $estadoStmt->execute();
    if ((int) $estadoStmt->fetchColumn() === 0) {
        msp2SetFlash('warning', 'El estado seleccionado no existe.');
        msp2TiendasRedirectFromPost();
    }

    $hasDatosContrato = $fechaInicioContratoRaw !== ''
        || $fechaTerminoPactadaRaw !== ''
        || $montoArriendoPactadoRaw !== ''
        || $rubroContratoRaw !== '';
    $hasDatosGarantia = false;
    $maxGarantiasLegacy = max(count($garantiaCodLocalesRaw), count($garantiaFechasRaw), count($garantiaMontosRaw), count($garantiaObservacionesRaw));
    for ($i = 0; $i < $maxGarantiasLegacy; $i++) {
        $codigoLocal = msp2NormalizeLocalCode((string) ($garantiaCodLocalesRaw[$i] ?? ''));
        $fechaRaw = trim((string) ($garantiaFechasRaw[$i] ?? ''));
        $montoRaw = trim((string) ($garantiaMontosRaw[$i] ?? ''));
        $observaciones = msp2NormalizeText((string) ($garantiaObservacionesRaw[$i] ?? ''));
        if ($codigoLocal !== '' || $fechaRaw !== '' || $montoRaw !== '' || $observaciones !== '') {
            $hasDatosGarantia = true;
            break;
        }
    }
    $legacyContratoInput = $hasDatosContrato || $hasDatosGarantia;
    if ($legacyContratoInput) {
        msp2SetFlash(
            'warning',
            'La gestión de contrato y garantía ya no se realiza desde Tiendas. Usa el módulo Contratos.'
        );
        msp2TiendasRedirectFromPost();
    }

    $stmtInsertTienda = $conn->prepare(
        'INSERT INTO dbo.msp_tiendas
            (id_rubro, id_arrendatario, id_estado_tienda, nombre_comercial, fecha_inicio)
         VALUES
            (:id_rubro, :id_arrendatario, :id_estado_tienda, :nombre_comercial, :fecha_inicio)'
    );

    $stmtUpdateTienda = $conn->prepare(
        'UPDATE dbo.msp_tiendas
         SET id_rubro = :id_rubro,
             id_arrendatario = :id_arrendatario,
             id_estado_tienda = :id_estado_tienda,
             nombre_comercial = :nombre_comercial,
             fecha_inicio = :fecha_inicio
         WHERE id_tienda = :id_tienda'
    );

    $conn->beginTransaction();

    if ($idTienda !== false && $idTienda !== null) {
        $stmt = $stmtUpdateTienda;
        $stmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    } else {
        $stmt = $stmtInsertTienda;
    }

    $stmt->bindValue(':id_rubro', $idRubro, PDO::PARAM_INT);
    $stmt->bindValue(':id_arrendatario', $idArrendatario, PDO::PARAM_INT);
    $stmt->bindValue(':id_estado_tienda', $idEstadoTienda, PDO::PARAM_INT);
    $stmt->bindValue(':nombre_comercial', $nombreComercial, PDO::PARAM_STR);
    $stmt->bindValue(':fecha_inicio', $fechaInicio, $fechaInicio !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();

    $idTiendaGuardada = $idTienda !== false && $idTienda !== null ? (int) $idTienda : 0;
    if ($idTiendaGuardada <= 0) {
        $idTiendaGuardada = (int) $conn->lastInsertId();
        if ($idTiendaGuardada <= 0) {
            $identityStmt = $conn->query('SELECT CAST(SCOPE_IDENTITY() AS INT)');
            $idTiendaGuardada = (int) $identityStmt->fetchColumn();
        }
    }

    if ($idTiendaGuardada <= 0) {
        throw new RuntimeException('No fue posible determinar el id de la tienda guardada.');
    }

    $conn->commit();

    $mensajeExito = $idTienda ? 'La tienda fue actualizada correctamente.' : 'La tienda fue creada correctamente.';
    msp2SetFlash('success', $mensajeExito);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2TiendasRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible guardar la tienda. Revisa la estructura de la base o intenta nuevamente.');
}

msp2TiendasRedirectFromPost();
