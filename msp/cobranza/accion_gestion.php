<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CobranzaGestionService.php';

msp2RequireAccess();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') msp2Redirect('pendientes/index.php');

$accion=strtoupper(trim((string)($_POST['accion']??'')));
$contrato=filter_input(INPUT_POST,'id_contrato',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$arrendatario=filter_input(INPUT_POST,'id_arrendatario',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$usuario=(int)($_SESSION['usuario']['id']??0);
$returnTo='cobranza/gestionar.php?id_contrato='.(int)$contrato;
$bandeja=trim((string)($_POST['return_to']??''));
if($bandeja!==''&&preg_match('#^pendientes/index\.php(?:\?[A-Za-z0-9_\-\.\[\]%=&]*)?$#',$bandeja)===1)$returnTo.='&return_to='.rawurlencode($bandeja);
try{
    if(!$contrato)throw new RuntimeException('El contrato no es válido.');
    $service=new CobranzaGestionService($conn);
    if($accion==='REGISTRAR_GESTION'){
        if(!$arrendatario)throw new RuntimeException('El arrendatario no es válido.');
        $id=$service->registrarGestion((int)$contrato,(int)$arrendatario,(int)($_POST['id_tipo_gestion']??0),(int)($_POST['id_resultado_gestion']??0),trim((string)($_POST['fecha_gestion']??'')),(string)($_POST['persona_contactada']??''),(string)($_POST['observacion']??''),$_POST['proxima_fecha_seguimiento']??null,$usuario);
        msp2SetFlash('success','Gestión de cobranza #'.$id.' registrada. La deuda financiera no fue modificada.');
    }elseif($accion==='REGISTRAR_COMPROMISO'){
        if(!$arrendatario)throw new RuntimeException('El arrendatario no es válido.');
        [$ok,$monto]=msp2NormalizeDecimalInput($_POST['monto_comprometido']??null,2);if(!$ok||$monto===null)throw new RuntimeException('El monto comprometido no es válido.');
        $id=$service->registrarCompromiso((int)$contrato,(int)$arrendatario,(float)$monto,trim((string)($_POST['fecha_comprometida']??'')),(string)($_POST['observacion']??''),$usuario);
        msp2SetFlash('success','Compromiso de pago #'.$id.' registrado sin alterar la deuda.');
    }elseif($accion==='CANCELAR_COMPROMISO'){
        $service->cancelarCompromiso((int)($_POST['id_compromiso_pago']??0),(string)($_POST['motivo_cancelacion']??''),$usuario);msp2SetFlash('success','Compromiso cancelado.');
    }elseif($accion==='GENERAR_AVISO'){
        $id=$service->crearAviso((int)$contrato,(int)($_POST['id_plantilla_aviso']??0),$usuario);msp2SetFlash('success','Aviso de cobranza generado. Revisa la vista previa antes de registrar su entrega.');msp2Redirect('cobranza/aviso.php?id_aviso='.$id.'&return_to='.rawurlencode($returnTo));
    }elseif($accion==='REGISTRAR_ENVIO_AVISO'){
        $id=$service->registrarEnvioAviso((int)($_POST['id_aviso_cobranza']??0),(string)($_POST['medio_envio']??''),(string)($_POST['observacion_envio']??''),$usuario);msp2SetFlash('success','Entrega del aviso registrada y añadida al historial como gestión #'.$id.'.');
    }else throw new RuntimeException('La acción de cobranza no es válida.');
}catch(Throwable $e){msp2SetFlash($e instanceof RuntimeException?'warning':'danger',$e instanceof RuntimeException?$e->getMessage():'No fue posible completar la acción de cobranza.');}
msp2Redirect($returnTo);
