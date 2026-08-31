<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') msp2Redirect('tesoreria/control_diario.php');
$caja=filter_input(INPUT_POST,'id_cuenta_caja',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$banco=filter_input(INPUT_POST,'id_cuenta_banco',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$fecha=trim((string)($_POST['fecha_deposito']??''));
$referencia=msp2NormalizeText((string)($_POST['referencia_deposito']??''));
$observaciones=msp2NormalizeText((string)($_POST['observaciones']??''));
[$okMonto,$monto]=msp2NormalizeDecimalInput((string)($_POST['monto_deposito']??''),2);
$date=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
if(!$caja||!$banco||!$okMonto||$monto===null||(float)$monto<=0||!$date||$date->format('Y-m-d')!==$fecha||$referencia===''||mb_strlen($referencia)>200||mb_strlen($observaciones)>500){
    msp2SetFlash('warning','Completa correctamente los datos del depósito.');
    msp2Redirect('tesoreria/control_diario.php');
}
try{
    if(!msp2ProcedureExists($conn,'msp_tesoreria_registrar_deposito')) throw new RuntimeException('Falta instalar el procedimiento de depósitos de tesorería.');
    $stmt=$conn->prepare('EXEC dbo.msp_tesoreria_registrar_deposito @id_cuenta_caja=:caja,@id_cuenta_banco=:banco,@fecha_deposito=:fecha,@monto_deposito=:monto,@referencia_deposito=:referencia,@observaciones=:observaciones,@id_usuario=:usuario');
    $stmt->execute([':caja'=>(int)$caja,':banco'=>(int)$banco,':fecha'=>$fecha,':monto'=>$monto,':referencia'=>$referencia,':observaciones'=>$observaciones!==''?$observaciones:null,':usuario'=>(int)$_SESSION['usuario']['id']]);
    $result=$stmt->fetch()?:[];
    msp2SetFlash('success','Depósito #'.(int)($result['id_deposito_tesoreria']??0).' registrado. Saldo de caja restante: $ '.number_format((float)($result['saldo_caja_restante']??0),2,',','.').'.');
}catch(Throwable $e){
    msp2SetFlash($e instanceof RuntimeException?'warning':'danger',$e instanceof RuntimeException?$e->getMessage():'No fue posible registrar el depósito. Verifica saldo, cuenta y referencia.');
}
msp2Redirect('tesoreria/control_diario.php?fecha='.rawurlencode($fecha));
