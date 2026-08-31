<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
msp2RequireAccess();
msp2RequireValidCsrfToken();

$q=msp2NormalizeText((string)($_POST['q']??''));
$idContratoFiltro=filter_input(INPUT_POST,'id_contrato_arriendo',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$idContratoFiltro=($idContratoFiltro===false||$idContratoFiltro===null)?0:(int)$idContratoFiltro;
$redirectParams=[];
if($idContratoFiltro>0)$redirectParams['id_contrato_arriendo']=$idContratoFiltro;
if($q!=='' && $idContratoFiltro<=0)$redirectParams['q']=$q;
$redirect='garantias/aplicaciones.php'.($redirectParams!==[]?'?'.http_build_query($redirectParams):'');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')msp2Redirect($redirect);

$idGarantia=filter_input(INPUT_POST,'id_garantia',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$idDocumento=filter_input(INPUT_POST,'id_documento_cobro',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$idTipo=filter_input(INPUT_POST,'id_tipo_item_documento',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$fecha=trim((string)($_POST['fecha_aplicacion']??''));
$obs=msp2NormalizeText((string)($_POST['observaciones']??''));
[$okMonto,$monto]=msp2NormalizeDecimalInput((string)($_POST['monto_aplicar']??''),2);
$date=DateTimeImmutable::createFromFormat('!Y-m-d',$fecha);
if(!$idGarantia||!$idDocumento||!$idTipo||!$okMonto||$monto===null||(float)$monto<=0||!$date||$date->format('Y-m-d')!==$fecha||$obs===''||mb_strlen($obs)>500){msp2SetFlash('warning','Completa la aplicación e indica el motivo/autorización.');msp2Redirect($redirect);}

try{
    if(!msp2ProcedureExists($conn,'msp_garantia_aplicar_documento'))throw new RuntimeException('Falta instalar la aplicación de garantía sobre documentos.');
    $stmt=$conn->prepare('DECLARE @id_pago INT,@id_movimiento INT; EXEC dbo.msp_garantia_aplicar_documento @id_documento_cobro=:documento,@id_garantia=:garantia,@fecha_pago=:fecha,@monto_aplicar=:monto,@observaciones=:observaciones,@id_pago_generado=@id_pago OUTPUT,@id_movimiento_garantia=@id_movimiento OUTPUT,@id_tipo_item_documento=:tipo,@id_usuario=:usuario; SELECT @id_pago id_pago,@id_movimiento id_movimiento;');
    $stmt->execute([':documento'=>(int)$idDocumento,':garantia'=>(int)$idGarantia,':fecha'=>$fecha,':monto'=>$monto,':observaciones'=>$obs!==''?$obs:null,':tipo'=>(int)$idTipo,':usuario'=>(int)$_SESSION['usuario']['id']]);
    $result=$stmt->fetch()?:[];$idMov=(int)($result['id_movimiento']??0);$usuario=(int)$_SESSION['usuario']['id'];
    if($idMov>0){$tipoStmt=$conn->prepare('SELECT codigo_item FROM dbo.msp_tipo_item_documento WHERE id_tipo_item_documento=:id');$tipoStmt->execute([':id'=>(int)$idTipo]);$codigo=strtoupper((string)($tipoStmt->fetchColumn()?:'OTRO'));$categoria=in_array($codigo,['ARRIENDO','SERVICIOS','MANTENIMIENTO'],true)?$codigo:'OTRO';$up=$conn->prepare('UPDATE dbo.msp_movimientos_garantia SET categoria_aplicacion=:categoria,motivo_autorizacion=:motivo,id_usuario_solicita=:usuario,id_usuario_autoriza=:usuario WHERE id_movimiento_garantia=:id');$up->execute([':categoria'=>$categoria,':motivo'=>$obs,':usuario'=>$usuario,':id'=>$idMov]);}
    msp2SetFlash('success','Garantía aplicada correctamente. Pago #'.(int)($result['id_pago']??0).' y movimiento #'.(int)($result['id_movimiento']??0).' registrados.');
}catch(Throwable $e){msp2SetFlash($e instanceof RuntimeException?'warning':'danger',$e instanceof RuntimeException?$e->getMessage():'No fue posible aplicar la garantía al documento.');}
msp2Redirect($redirect);


