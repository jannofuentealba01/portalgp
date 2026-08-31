<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
$requiredFiles=['patch_gestion_cobranza_operacional.sql','patch_convenios_pago_cuotas.sql','patch_comercial_vacancia_documentos.sql','patch_liquidacion_final.sql','patch_cierre_deuda_historica.sql','patch_tesoreria_reapertura_caja.sql','patch_documentos_cobro_eventos.sql','patch_arriendo_inicio_prorrata.sql','patch_contabilidad_devolucion_garantia.sql'];
$requiredObjects=['msp_tesoreria_reabrir_caja','msp_convenio_crear','msp_vw_convenios_pago_estado','msp_vacancia_abrir','msp_vw_vacancia_locales','msp_liquidaciones_finales','msp_deudas_historicas'];
$errors=[]; $root=__DIR__;
foreach($requiredFiles as $file){if(!is_file($root.DIRECTORY_SEPARATOR.$file))$errors[]='Falta archivo SQL: '.$file;}
foreach($requiredObjects as $obj){$q=$conn->prepare("SELECT COUNT(*) FROM sys.objects WHERE name=:n AND is_ms_shipped=0");$q->execute([':n'=>$obj]);if((int)$q->fetchColumn()===0)$errors[]='Falta objeto BD: '.$obj;}
$result=['ok'=>$errors===[],'archivos_requeridos'=>count($requiredFiles),'objetos_requeridos'=>count($requiredObjects),'errores'=>$errors,'fecha'=>date('c')];
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
exit($errors===[]?0:1);
