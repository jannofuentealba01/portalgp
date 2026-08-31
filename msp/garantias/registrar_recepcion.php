<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('garantias/recepciones.php');
}

function msp2RecepcionGarantiaFail(string $message): never
{
    msp2SetFlash('warning', $message);
    msp2Redirect('garantias/recepciones.php');
}

$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
// Compatibilidad con formularios antiguos: si llega una garantía individual, resolvemos su contrato.
$idGarantiaLegacy = filter_input(INPUT_POST, 'id_garantia', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$fecha = trim((string) ($_POST['fecha_recepcion'] ?? ''));
$medio = strtoupper(trim((string) ($_POST['medio_recepcion'] ?? '')));
$modalidad = strtoupper(trim((string) ($_POST['modalidad_recepcion'] ?? 'ABONO')));
$referencia = msp2NormalizeText((string) ($_POST['referencia'] ?? ''));
$bancoEmisor = msp2NormalizeText((string) ($_POST['banco_emisor'] ?? ''));
$numeroCheque = msp2NormalizeText((string) ($_POST['numero_cheque'] ?? ''));
$fechaCheque = trim((string) ($_POST['fecha_cheque'] ?? ''));
$observaciones = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));
$idCuentaBanco = filter_input(INPUT_POST, 'id_cuenta_banco', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
[$montoOk, $monto] = msp2NormalizeDecimalInput(trim((string) ($_POST['monto_recibido'] ?? '')), 2);
[$montoPactadoOk, $montoPactado] = msp2NormalizeDecimalInput(trim((string) ($_POST['monto_pactado'] ?? '')), 2);

if (!in_array($modalidad, ['ABONO','TOTAL'], true)) {
    msp2RecepcionGarantiaFail('Selecciona si registrarás un abono o el pago total pendiente.');
}
if ((!$idContrato && !$idGarantiaLegacy) || ($modalidad==='ABONO' && (!$montoOk || $monto === null || (float) $monto <= 0))) {
    msp2RecepcionGarantiaFail('La garantía o el monto recibido no son válidos.');
}
$fechaObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
if (!$fechaObj || $fechaObj->format('Y-m-d') !== $fecha) {
    msp2RecepcionGarantiaFail('La fecha de recepción no es válida.');
}
if (!in_array($medio, ['EFECTIVO', 'TRANSFERENCIA', 'CHEQUE'], true)) {
    msp2RecepcionGarantiaFail('Selecciona un medio de recepción válido.');
}
if (mb_strlen($referencia) > 200 || mb_strlen($bancoEmisor) > 120 || mb_strlen($numeroCheque) > 80 || mb_strlen($observaciones) > 500) {
    msp2RecepcionGarantiaFail('Uno de los textos supera el largo permitido.');
}
if ($medio === 'TRANSFERENCIA' && ($referencia === '' || !$idCuentaBanco)) {
    msp2RecepcionGarantiaFail('La transferencia requiere referencia y cuenta bancaria de destino.');
}
if ($medio === 'CHEQUE') {
    if ($numeroCheque === '' || $bancoEmisor === '') {
        msp2RecepcionGarantiaFail('El cheque requiere banco emisor y número de cheque.');
    }
    if ($fechaCheque !== '') {
        $fechaChequeObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaCheque);
        if (!$fechaChequeObj || $fechaChequeObj->format('Y-m-d') !== $fechaCheque) {
            msp2RecepcionGarantiaFail('La fecha del cheque no es válida.');
        }
    }
}

try {
    $conn->beginTransaction();

    if (!$idContrato && $idGarantiaLegacy) {
        $stmtContrato = $conn->prepare('SELECT id_contrato_arriendo FROM dbo.msp_garantias WHERE id_garantia=:id AND estado_garantia<>6');
        $stmtContrato->execute([':id'=>(int)$idGarantiaLegacy]);
        $idContrato = (int)($stmtContrato->fetchColumn() ?: 0);
    }
    $stmtGarantias = $conn->prepare(
        'SELECT g.id_garantia,g.monto_inicial,
                ISNULL((SELECT SUM(r.monto_recibido) FROM dbo.msp_garantia_recepciones r WITH (UPDLOCK, HOLDLOCK)
                        WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N\'CONFIRMADA\'),0) AS recibido
         FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
         WHERE g.id_contrato_arriendo=:contrato AND g.estado_garantia<>6
         ORDER BY g.id_garantia'
    );
    $stmtGarantias->execute([':contrato'=>(int)$idContrato]);
    $garantiasContrato = $stmtGarantias->fetchAll() ?: [];
    if ($garantiasContrato === []) {
        throw new RuntimeException('El contrato no tiene una garantía activa.');
    }
    $pactado = round(array_sum(array_map(static fn(array $g): float => (float)$g['monto_inicial'], $garantiasContrato)), 2);
    $recibido = round(array_sum(array_map(static fn(array $g): float => (float)$g['recibido'], $garantiasContrato)), 2);
    if ($pactado <= 0) {
        if (!$montoPactadoOk || $montoPactado === null || (float) $montoPactado <= 0) {
            throw new RuntimeException('Indica un monto pactado mayor que cero para esta garantía.');
        }
        $pactado = round((float) $montoPactado, 2);
        if ($pactado + 0.009 < round($recibido + (float) $monto, 2)) {
            throw new RuntimeException('El monto recibido no puede superar el monto pactado de la garantía.');
        }
        $pactado = round((float)$montoPactado, 2);
        $stmtActualizarPactado = $conn->prepare('UPDATE dbo.msp_garantias SET monto_inicial=:monto WHERE id_garantia=:id');
        $stmtActualizarPactado->execute([':monto'=>$pactado, ':id'=>(int)$garantiasContrato[0]['id_garantia']]);
        $garantiasContrato[0]['monto_inicial'] = $pactado;
    }
    $pendienteContrato = round($pactado-$recibido, 2);
    if ($modalidad === 'TOTAL') {
        $monto = $pendienteContrato;
        $montoOk = $monto > 0;
    }
    if (!$montoOk || $monto === null || (float)$monto <= 0) {
        throw new RuntimeException('El contrato no tiene un saldo de garantía pendiente por recibir.');
    }
    if (round($recibido + (float) $monto, 2) > $pactado + 0.009) {
        throw new RuntimeException('El ingreso supera el monto pendiente de la garantía.');
    }

    // Distribuye un abono entre las filas históricas por local, sin permitir recepciones duplicadas.
    $restante = round((float)$monto, 2);
    $asignaciones = [];
    foreach ($garantiasContrato as $g) {
        $capacidad = max(0, round((float)$g['monto_inicial'] - (float)$g['recibido'], 2));
        if ($capacidad <= 0 || $restante <= 0) continue;
        $parte = min($capacidad, $restante);
        $asignaciones[] = ['id_garantia'=>(int)$g['id_garantia'], 'monto'=>round($parte,2)];
        $restante = round($restante - $parte, 2);
    }
    if ($restante > 0.009) throw new RuntimeException('No fue posible distribuir el monto recibido dentro del monto pactado.');

    if ($medio === 'TRANSFERENCIA') {
        $stmtCuenta = $conn->prepare('SELECT id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WITH (UPDLOCK, HOLDLOCK) WHERE id_cuenta_tesoreria=:id AND tipo_cuenta=N\'BANCO\' AND activo=1');
        $stmtCuenta->execute([':id' => (int) $idCuentaBanco]);
    } else {
        $stmtCuenta = $conn->prepare('SELECT id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WITH (UPDLOCK, HOLDLOCK) WHERE codigo_cuenta=N\'CAJA_GENERAL\' AND activo=1');
        $stmtCuenta->execute();
    }
    $idCuenta = (int) ($stmtCuenta->fetchColumn() ?: 0);
    if ($idCuenta <= 0) {
        throw new RuntimeException('No existe una cuenta de tesorería activa para registrar el ingreso.');
    }
    if ($medio === 'TRANSFERENCIA') {
        $stmtBloqueo = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_tesoreria_conciliaciones WHERE id_cuenta_tesoreria=:cuenta AND fecha_hasta>=:fecha');
    } else {
        $stmtBloqueo = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_tesoreria_cierres_caja WHERE id_cuenta_tesoreria=:cuenta AND fecha_cierre>=:fecha');
    }
    $stmtBloqueo->execute([':cuenta'=>$idCuenta,':fecha'=>$fecha]);
    if ((int)$stmtBloqueo->fetchColumn()>0) {
        throw new RuntimeException('No se puede registrar la recepción en una fecha ya cerrada o conciliada.');
    }

    $stmtMovimiento = $conn->prepare(
        'INSERT INTO dbo.msp_tesoreria_movimientos
            (id_cuenta_tesoreria,fecha_movimiento,tipo_movimiento,naturaleza,monto,medio_pago,referencia,id_recepcion_garantia,estado_movimiento,observaciones,id_usuario)
         VALUES (:cuenta,:fecha,N\'RECEPCION_GARANTIA\',\'E\',:monto,:medio,:referencia,:recepcion,N\'VIGENTE\',:observaciones,:usuario)'
    );
    $stmtRecepcion = $conn->prepare(
        'INSERT INTO dbo.msp_garantia_recepciones
            (id_garantia,fecha_recepcion,monto_recibido,medio_recepcion,referencia,banco_emisor,numero_cheque,fecha_cheque,estado_recepcion,observaciones,id_usuario)
         OUTPUT INSERTED.id_recepcion_garantia
         VALUES (:garantia,:fecha,:monto,:medio,:referencia,:banco,:cheque,:fecha_cheque,N\'CONFIRMADA\',:observaciones,:usuario)'
    );
    foreach ($asignaciones as $asignacion) {
        $stmtRecepcion->execute([
            ':garantia'=>$asignacion['id_garantia'], ':fecha'=>$fecha, ':monto'=>$asignacion['monto'], ':medio'=>$medio,
            ':referencia'=>$referencia!==''?$referencia:null, ':banco'=>$bancoEmisor!==''?$bancoEmisor:null,
            ':cheque'=>$numeroCheque!==''?$numeroCheque:null, ':fecha_cheque'=>$fechaCheque!==''?$fechaCheque:null,
            ':observaciones'=>$observaciones!==''?$observaciones:null, ':usuario'=>(int)$_SESSION['usuario']['id'],
        ]);
        $idRecepcion=(int)$stmtRecepcion->fetchColumn();
        $stmtMovimiento->execute([
            ':cuenta'=>$idCuenta, ':fecha'=>$fecha, ':monto'=>$asignacion['monto'], ':medio'=>$medio,
            ':referencia'=>$referencia!==''?$referencia:($numeroCheque!==''?$numeroCheque:null),
            ':recepcion'=>$idRecepcion, ':observaciones'=>$observaciones!==''?$observaciones:null,
            ':usuario'=>(int)$_SESSION['usuario']['id'],
        ]);
    }

    $conn->commit();
    msp2SetFlash('success', ($modalidad==='TOTAL'?'Pago total':'Abono').' de garantía registrado y reflejado en tesorería.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible registrar la recepción de garantía.');
}

msp2Redirect('garantias/recepciones.php');
