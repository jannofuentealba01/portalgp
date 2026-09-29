<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosMovimientoGarantiaRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['contratos/index.php', 'tiendas/index.php', 'arrendatarios/index.php', 'garantias/aplicaciones.php'];
    $allowContratoEditar = preg_match('/^contratos\/editar\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;
    $allowGarantiaFiltro = preg_match('/^garantias\/aplicaciones\.php\?id_contrato_arriendo=[1-9][0-9]*(?:&return_to=[A-Za-z0-9_%\.\-]+)?$/', $redirectTo) === 1;

    if (!in_array($redirectTo, $allowed, true) && !$allowContratoEditar && !$allowGarantiaFiltro) {
        $redirectTo = 'contratos/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idGarantia = filter_input(INPUT_POST, 'id_garantia_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$accionGarantia = msp2NormalizeText((string) ($_POST['accion_garantia'] ?? ''));
$montoMovimientoRaw = trim((string) ($_POST['monto_movimiento'] ?? ''));
$observaciones = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));

if ($idCargoSalida === false || $idCargoSalida === null) {
    msp2SetFlash('warning', 'El cargo indicado no es válido.');
    msp2ContratosMovimientoGarantiaRedirectFromPost();
}

$accionesPermitidas = [
    'RESERVAR',
    'APLICAR_DESDE_DISPONIBLE',
    'APLICAR_DESDE_RESERVADO',
    'LIBERAR_RESERVA',
];
if (!in_array($accionGarantia, $accionesPermitidas, true)) {
    msp2SetFlash('warning', 'La acción de garantía no es válida.');
    msp2ContratosMovimientoGarantiaRedirectFromPost();
}

if ($observaciones !== '' && mb_strlen($observaciones) > 500) {
    msp2SetFlash('warning', 'Las observaciones no pueden superar 500 caracteres.');
    msp2ContratosMovimientoGarantiaRedirectFromPost();
}
if (str_starts_with($accionGarantia, 'APLICAR_') && $observaciones === '') {
    msp2SetFlash('warning', 'Indica el motivo y autorización para aplicar la garantía.');
    msp2ContratosMovimientoGarantiaRedirectFromPost();
}

[$okMontoMovimiento, $montoMovimiento] = msp2NormalizeDecimalInput($montoMovimientoRaw, 2);
if (!$okMontoMovimiento || $montoMovimiento === null || (float) $montoMovimiento <= 0) {
    msp2SetFlash('warning', 'El monto del movimiento no es válido.');
    msp2ContratosMovimientoGarantiaRedirectFromPost();
}

try {
    if (msp2ProcedureExists($conn, 'msp_garantia_tienda_operar_cargo')) {
        $conn->beginTransaction();
        $idGarantiaParam = ($idGarantia !== false && $idGarantia !== null && (int) $idGarantia > 0)
            ? (int) $idGarantia
            : null;

        $stmtSpMovimiento = $conn->prepare(
            'DECLARE @id_movimiento_garantia INT, @estado_cargo_nuevo TINYINT;
             EXEC dbo.msp_garantia_tienda_operar_cargo
                @accion = :accion,
                @id_cargo_contrato_local = NULL,
                @id_cargo_salida = :id_cargo_salida,
                @id_garantia_tienda = :id_garantia_tienda,
                @monto_movimiento = :monto_movimiento,
                @observaciones = :observaciones,
                @id_pago = NULL,
                @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
                @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
             SELECT @id_movimiento_garantia id_movimiento_garantia;'
        );

        $stmtSpMovimiento->bindValue(':accion', $accionGarantia, PDO::PARAM_STR);
        $stmtSpMovimiento->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
        $stmtSpMovimiento->bindValue(':id_garantia_tienda', $idGarantiaParam, $idGarantiaParam !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmtSpMovimiento->bindValue(':monto_movimiento', $montoMovimiento, PDO::PARAM_STR);
        $stmtSpMovimiento->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtSpMovimiento->execute();
        $movimientoCreado=$stmtSpMovimiento->fetch()?:[];$idMovimientoCreado=(int)($movimientoCreado['id_movimiento_garantia']??0);
        if($idMovimientoCreado<=0){throw new RuntimeException('La operación no entregó un movimiento de garantía válido.');}
        $usuario=(int)$_SESSION['usuario']['id'];$up=$conn->prepare("UPDATE dbo.msp_movimientos_garantia SET categoria_aplicacion=N'CARGO_ADICIONAL',motivo_autorizacion=:motivo,id_usuario_solicita=:usuario,id_usuario_autoriza=:usuario WHERE id_movimiento_garantia=:id");$up->execute([':motivo'=>$observaciones!==''?$observaciones:null,':usuario'=>$usuario,':id'=>$idMovimientoCreado]);
        $conn->commit();

        msp2SetFlash('success', 'El movimiento de garantía fue registrado correctamente.');
        msp2ContratosMovimientoGarantiaRedirectFromPost();
    }

    throw new RuntimeException('No está disponible el procedimiento operativo de garantía por tienda.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosMovimientoGarantiaRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible registrar el movimiento de garantía.');
}

msp2ContratosMovimientoGarantiaRedirectFromPost();
