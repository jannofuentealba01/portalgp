<?php
declare(strict_types=1);
require __DIR__ . '/../rrhh/fte/fte_lib.php';
$passed=0;$failed=0;
$assert=static function(bool $ok,string $label)use(&$passed,&$failed):void{
    if($ok){$passed++;echo "[OK] {$label}\n";}else{$failed++;echo "[FAIL] {$label}\n";}
};
$calendar=fte_calendar_calculate_month(2026,8);
$people=[['identifier'=>'1-9','normalized_identifier'=>'19','person_name'=>'Persona congelada','buk_employee_id'=>'buk-test','active'=>true,'active_since'=>'2026-01-01','active_until'=>null,'jobs'=>[['job_id'=>1,'start_date'=>'2026-01-01','end_date'=>null,'cost_center_code'=>'TEST','cost_center_name'=>'Prueba']]]];
$vacations=[['employee_id'=>'buk-test','start_date'=>'2026-08-10','end_date'=>'2026-08-11']];
$licences=[['employee_id'=>'buk-test','start_date'=>'2026-08-20','end_date'=>'2026-08-20']];
$attendance=['19'=>['2026-08-03'=>['accomplished_extra_time_seconds'=>900]]];
$diagnostics=['successful_identifiers'=>['19'],'failed_identifiers'=>[],'unmatched_identifiers'=>[]];
$bundle=fte_monthly_source_snapshot_from_data('2026-08',$people,$vacations,$licences,$attendance,$diagnostics,$calendar,'2026-09-01T12:00:00-04:00');
$assert(count($bundle['sources'])===5,'congela las cinco fuentes requeridas');
$assert($bundle['complete']===true,'marca completa una captura sin fallos');
$assert(strlen($bundle['hash'])===64,'genera hash integral SHA-256');
$assert($bundle['headcount']['hash']===$bundle['hash'],'liga la dotacion al paquete integral');
$assert($bundle['sources']['GEOVICTORIA_ASISTENCIA']['record_count']===1,'cuenta las marcaciones congeladas');
$assert(($bundle['sources']['GEOVICTORIA_DIAGNOSTICOS']['payload']['calendar']['period']??'')==='2026-08','congela el calendario usado por el cierre');
$assert(($bundle['sources']['GEOVICTORIA_DIAGNOSTICOS']['payload']['calendar']['validation']['intermediate_rounding_applied']??true)===false,'congela la politica de precision sin redondeos');
$changed=$attendance;$changed['19']['2026-08-03']['accomplished_extra_time_seconds']=901;
$changedBundle=fte_monthly_source_snapshot_from_data('2026-08',$people,$vacations,$licences,$changed,$diagnostics,$calendar,'2026-09-01T12:00:00-04:00');
$assert($changedBundle['hash']!==$bundle['hash'],'un cambio de origen crea otro hash integral');
$changedCalendar=$calendar;$changedCalendar['days'][0]['theoretical_hours']+=0.25;$changedCalendar['theoretical_hours_per_person']+=0.25;$changedCalendar['workday_count']=count(array_filter($changedCalendar['days'],static fn(array $day):bool=>(float)$day['theoretical_hours']>0));$changedCalendar['validation']=fte_calendar_validate_result($changedCalendar,true);
$changedCalendarBundle=fte_monthly_source_snapshot_from_data('2026-08',$people,$vacations,$licences,$attendance,$diagnostics,$changedCalendar,'2026-09-01T12:00:00-04:00');
$assert($changedCalendarBundle['hash']!==$bundle['hash'],'un cambio de calendario crea otra fotografia integral');
$partialDiagnostics=$diagnostics;$partialDiagnostics['failed_identifiers']=['19'];
$partial=fte_monthly_source_snapshot_from_data('2026-08',$people,$vacations,$licences,$attendance,$partialDiagnostics,$calendar,'2026-09-01T12:00:00-04:00');
$assert($partial['complete']===false,'un fallo GeoVictoria impide una captura completa');
$validationBundle=fte_monthly_source_snapshot_from_data('2026-08',$people,[],[],$attendance,$diagnostics,$calendar,'2026-09-01T12:00:00-04:00');
$validationSources=[];
foreach($validationBundle['sources'] as $key=>$source){$validationSources[$key]=$source['payload'];}
$validation=fte_monthly_source_snapshot_preclose_validation('2026-08',$validationSources,true,true);
$validationByKey=[];
foreach($validation['checks'] as $check){$validationByKey[$check['key']]=$check;}
$assert(($validation['can_approve']??false)===true && ($validation['status']??'')==='LISTA','la validacion previa habilita una fotografia completamente conciliada');
$assert(count($validation['checks']??[])===10,'la validacion previa ejecuta los diez controles del cierre');
$assert(($validationByKey['nominal_reconciliation']['status']??'')==='OK','el detalle nominal conciliado queda explicitamente correcto');

$pendingDiagnostics=$diagnostics;
$pendingDiagnostics['unmatched_identifiers']=['19'];
$pendingDiagnostics['identity']=[
    'missing_identifier_records'=>1,
    'ambiguous_aliases'=>[['alias'=>'19','reason'=>'Alias compartido']],
];
$pendingAttendance=['19'=>['2026-08-03'=>['time_offs'=>[['type_description'=>'Permiso administrativo dia']]]]];
$pendingBundle=fte_monthly_source_snapshot_from_data('2026-08',$people,[],[],$pendingAttendance,$pendingDiagnostics,$calendar,'2026-09-01T12:00:00-04:00');
$pendingSources=[];
foreach($pendingBundle['sources'] as $key=>$source){$pendingSources[$key]=$source['payload'];}
$pendingValidation=fte_monthly_source_snapshot_preclose_validation('2026-08',$pendingSources,true,true);
$pendingByKey=[];
foreach($pendingValidation['checks'] as $check){$pendingByKey[$check['key']]=$check;}
$assert(($pendingValidation['can_approve']??true)===false && ($pendingValidation['status']??'')==='BLOQUEADA','la validacion previa bloquea una fotografia con pendientes');
$assert(($pendingByKey['worker_reconciliation']['status']??'')==='PENDIENTE','identifica trabajadores no conciliados');
$assert(($pendingByKey['identity_integrity']['status']??'')==='PENDIENTE','identifica RUT o alias inconsistentes');
$assert(($pendingByKey['permission_classification']['status']??'')==='PENDIENTE','identifica permisos sin clasificar');
$assert(($pendingByKey['nominal_reconciliation']['count']??0)>0,'el detalle nominal conserva el numero de pendientes y no lo convierte en cero');
$missingSourcesValidation=fte_monthly_source_snapshot_preclose_validation('2026-08',[],false,false);
$assert(count($missingSourcesValidation['checks']??[])===10 && ($missingSourcesValidation['can_approve']??true)===false,'sin fotografia mantiene visibles los diez controles como pendientes');
fte_monthly_source_snapshot_assert_closed_period('2026-08',new DateTimeImmutable('2026-09-23'));
$currentRejected=false;
try{fte_monthly_source_snapshot_assert_closed_period('2026-09',new DateTimeImmutable('2026-09-23'));}catch(DomainException $exception){$currentRejected=true;}
$assert($currentRejected,'impide congelar un mes que aun no finaliza');
$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'fte-snapshot-'.bin2hex(random_bytes(5));
try{
    $archive=fte_monthly_source_snapshot_archive($temp,$bundle,1);$verified=true;
    foreach($bundle['sources'] as $key=>$source){$source['archive_path']=$archive['paths'][$key];$verified=$verified&&fte_monthly_source_snapshot_verify_archive($temp,$source);}
    $assert($verified,'el respaldo comprimido conserva todos los hashes');
    $assert(is_file($archive['directory'].DIRECTORY_SEPARATOR.'manifest.json'),'genera un manifiesto auditable');
}finally{
    $dir=$temp.DIRECTORY_SEPARATOR.'2026-08'.DIRECTORY_SEPARATOR.'v1';
    foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[] as $file){if(is_file($file))unlink($file);}
    @rmdir($dir);@rmdir($temp.DIRECTORY_SEPARATOR.'2026-08');@rmdir($temp);
}
echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed===0?0:1);
