<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';msp2RequireAccess();
msp2RequireValidCsrfToken();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')msp2Redirect('garantias/devoluciones.php');
$idGarantia=filter_input(INPUT_POST,'id_garantia',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$idCuenta=filter_input(INPUT_POST,'id_cuenta_tesoreria',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$fecha=trim((string)($_POST['fecha_devolucion']??''));$medio=strtoupper(trim((string)($_POST['medio_devolucion']??'')));$beneficiario=msp2NormalizeText((string)($_POST['beneficiario']??''));$rut=msp2NormalizeText((string)($_POST['rut_beneficiario']??''));$banco=msp2NormalizeText((string)($_POST['banco_destino']??''));$cuenta=msp2NormalizeText((string)($_POST['cuenta_destino']??''));$ref=msp2NormalizeText((string)($_POST['referencia_transferencia']??''));$motivo=msp2NormalizeText((string)($_POST['motivo_autorizacion']??''));$obs=msp2NormalizeText((string)($_POST['observaciones']??''));[$okMonto,$monto]=msp2NormalizeDecimalInput((string)($_POST['monto_devolucion']??''),2);
$date=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
if(!$idGarantia||!$idCuenta||!$okMonto||$monto===null||(float)$monto<=0||!$date||$date->format('Y-m-d')!==$fecha||!in_array($medio,['EFECTIVO','TRANSFERENCIA'],true)||$beneficiario===''||$motivo===''||mb_strlen($beneficiario)>200||mb_strlen($motivo)>500||mb_strlen($obs)>500){msp2SetFlash('warning','Completa correctamente los datos y el motivo de autorización.');msp2Redirect('garantias/devoluciones.php');}
if($medio==='TRANSFERENCIA'&&($banco===''||$cuenta===''||$ref==='')){msp2SetFlash('warning','La transferencia requiere banco, cuenta destino y referencia.');msp2Redirect('garantias/devoluciones.php');}
try{
 if(!msp2ProcedureExists($conn,'msp_garantia_devolver_operativa'))throw new RuntimeException('Falta instalar la devolución operativa de garantías.');
 $stmt=$conn->prepare('EXEC dbo.msp_garantia_devolver_operativa @id_garantia=:garantia,@id_cuenta_tesoreria=:cuenta_origen,@fecha_devolucion=:fecha,@monto_devolucion=:monto,@medio_devolucion=:medio,@beneficiario=:beneficiario,@rut_beneficiario=:rut,@banco_destino=:banco,@cuenta_destino=:cuenta_destino,@referencia_transferencia=:referencia,@numero_cheque=NULL,@fecha_cheque=NULL,@observaciones=:observaciones,@id_usuario=:usuario,@motivo_autorizacion=:motivo,@id_usuario_autoriza=:autoriza');
 $usuario=(int)$_SESSION['usuario']['id'];$stmt->execute([':garantia'=>(int)$idGarantia,':cuenta_origen'=>(int)$idCuenta,':fecha'=>$fecha,':monto'=>$monto,':medio'=>$medio,':beneficiario'=>$beneficiario,':rut'=>$rut!==''?$rut:null,':banco'=>$medio==='TRANSFERENCIA'?$banco:null,':cuenta_destino'=>$medio==='TRANSFERENCIA'?$cuenta:null,':referencia'=>$medio==='TRANSFERENCIA'?$ref:null,':observaciones'=>$obs!==''?$obs:null,':usuario'=>$usuario,':motivo'=>$motivo,':autoriza'=>$usuario]);$result=$stmt->fetch()?:[];
 msp2SetFlash('success','Devolución #'.(int)($result['id_devolucion_garantia']??0).' emitida correctamente. Saldo de origen restante: $ '.number_format((float)($result['saldo_origen_restante']??0),2,',','.').'.');
}catch(Throwable $e){$raw=$e->getMessage();$friendly='No fue posible registrar la devolución. Revisa los saldos y obligaciones pendientes.';if(mb_stripos($raw,'cargos pendientes o reservados')!==false){$friendly='No se puede devolver esta garantía todavía: el local tiene cargos pendientes o reservados. Revisa/aplica esos cargos antes de solicitar la devolución.';}elseif($e instanceof RuntimeException){$friendly=$raw;}msp2SetFlash('warning',$friendly);}
msp2Redirect('garantias/devoluciones.php');


