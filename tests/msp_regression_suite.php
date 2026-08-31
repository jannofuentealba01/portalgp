<?php
declare(strict_types=1);
/* Suite de regresión MSP. Solo lectura: no crea, modifica ni elimina datos. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require_once dirname(__DIR__).'/db.php';
$checks=[];$check=function(string $name,callable $fn)use(&$checks):void{try{$v=$fn();$checks[]=[$name,$v===true,$v===true?'OK':(string)$v];}catch(Throwable $e){$checks[]=[$name,false,$e->getMessage()];}};
$exists=fn(string $type,string $name):bool=> (function()use($type,$name,$conn){$s=$conn->prepare('SELECT CASE WHEN OBJECT_ID(:n,:t) IS NULL THEN 0 ELSE 1 END');$s->execute([':n'=>'dbo.'.$name,':t'=>$type]);return(bool)$s->fetchColumn();})();
foreach(['msp_contratos_arriendo','msp_contrato_locales','msp_documentos_cobro','msp_pagos','msp_garantias','msp_convenios_pago','msp_convenio_pago_cuotas','msp_liquidaciones_finales','msp_vacancias_locales'] as $t)$check('Esquema '.$t,fn()=>$exists('U',$t));
foreach(['msp_generar_documentos_cobro_periodo','msp_registrar_pago_documento','msp_convenio_crear','msp_vacancia_abrir','msp_tesoreria_reabrir_caja'] as $p)$check('Procedimiento '.$p,fn()=>$exists('P',$p));
$check('Contratos locales sin padre',fn()=>(int)$conn->query("SELECT COUNT(*) FROM dbo.msp_contrato_locales cl LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=cl.id_contrato_arriendo WHERE c.id_contrato_arriendo IS NULL")->fetchColumn()===0);
$check('Documentos con contrato inválido',fn()=>(int)$conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro d LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=d.id_contrato_arriendo WHERE d.id_contrato_arriendo IS NOT NULL AND c.id_contrato_arriendo IS NULL")->fetchColumn()===0);
$check('Pagos sin documento',fn()=>(int)$conn->query("SELECT COUNT(*) FROM dbo.msp_pagos p LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=p.id_documento_cobro WHERE d.id_documento_cobro IS NULL")->fetchColumn()===0);
$check('Garantías sin contrato',fn()=>(int)$conn->query("SELECT COUNT(*) FROM dbo.msp_garantias g LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo WHERE c.id_contrato_arriendo IS NULL")->fetchColumn()===0);
$check('Cuotas sin convenio',fn()=>(int)$conn->query("SELECT COUNT(*) FROM dbo.msp_convenio_pago_cuotas q LEFT JOIN dbo.msp_convenios_pago c ON c.id_convenio_pago=q.id_convenio_pago WHERE c.id_convenio_pago IS NULL")->fetchColumn()===0);
$check('Liquidaciones duplicadas',fn()=>(int)$conn->query("SELECT COUNT(*) FROM (SELECT id_contrato_arriendo FROM dbo.msp_liquidaciones_finales GROUP BY id_contrato_arriendo HAVING COUNT(*)>1)x")->fetchColumn()===0);
$check('Asientos balanceados',fn()=>(int)$conn->query("SELECT COUNT(*) FROM (SELECT a.id_asiento_contable FROM dbo.msp_acc_asientos a JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable WHERE a.estado_asiento=1 GROUP BY a.id_asiento_contable HAVING ABS(SUM(d.debe)-SUM(d.haber))>0.01)x")->fetchColumn()===0);
$failed=0;foreach($checks as[$n,$ok,$m]){echo($ok?'[OK] ':'[FAIL] ').$n.' - '.$m.PHP_EOL;if(!$ok)$failed++;}echo 'Resultado: '.(count($checks)-$failed).'/'.count($checks).' pruebas correctas.'.PHP_EOL;exit($failed===0?0:1);

