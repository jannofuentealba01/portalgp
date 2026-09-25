<?php
declare(strict_types=1);
require __DIR__ . '/../db.php';
require __DIR__ . '/../rrhh/fte/fte_lib.php';
$passed=0;$failed=0;
$assert=static function(bool $ok,string $label)use(&$passed,&$failed):void{
    if($ok){$passed++;echo "[OK] {$label}\n";}else{$failed++;echo "[FAIL] {$label}\n";}
};
$userId=(int)$conn->query('SELECT TOP (1) id FROM dbo.cr_usuarios ORDER BY id')->fetchColumn();
if($userId<=0)throw new RuntimeException('No existe usuario de prueba.');
$calendarConfig=fte_calendar_load_config();
$calendarConfig['date_overrides']['2099-10-01']=['hours'=>1.25,'reason'=>'Calendario congelado de prueba'];
$calendar=fte_calendar_calculate_month(2099,10,$calendarConfig);
$liveCalendar=fte_calendar_calculate_month(2099,10);
$people=[['identifier'=>'1-9','normalized_identifier'=>'19','person_name'=>'Prueba fuentes FTE','buk_employee_id'=>'fte-source-test','active'=>true,'active_since'=>'2099-01-01','active_until'=>null,'jobs'=>[['job_id'=>99001,'start_date'=>'2099-01-01','end_date'=>null,'cost_center_code'=>'FTE-SOURCE','cost_center_name'=>'Prueba fuentes']]]];
$diagnostics=['successful_identifiers'=>['19'],'failed_identifiers'=>[],'unmatched_identifiers'=>[]];
$bundle=fte_monthly_source_snapshot_from_data('2099-10',$people,[],[],['19'=>[]],$diagnostics,$calendar,'2099-11-01T10:00:00-03:00');
$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'fte-snapshot-db-'.bin2hex(random_bytes(5));
$conn->beginTransaction();
try{
    $saved=fte_monthly_source_snapshot_save($conn,$bundle,$userId,$temp);
    $assert(($saved['created']??false)===true,'guarda el paquete integral en borrador');
    $assert(($saved['source_count']??0)===5,'persiste las cinco fuentes en SQL');
    $assert(($saved['is_full_snapshot']??false)===true,'marca completo el paquete persistido');
    $assert(($saved['preclose_validation']['status']??'')==='LISTA','ejecuta la validacion previa sobre las fuentes persistidas');
    $assert(($saved['can_approve']??false)===true,'habilita la aprobacion solo cuando los controles previos estan correctos');
    $same=fte_monthly_source_snapshot_save($conn,$bundle,$userId,$temp);
    $assert(($same['unchanged']??false)===true,'el guardado integral es idempotente');
    $blockedDiagnostics=$diagnostics;
    $blockedDiagnostics['identity']=['missing_identifier_records'=>1,'ambiguous_aliases'=>[]];
    $blockedCalendar=fte_calendar_calculate_month(2099,9);
    $blockedBundle=fte_monthly_source_snapshot_from_data('2099-09',$people,[],[],['19'=>[]],$blockedDiagnostics,$blockedCalendar,'2099-10-01T10:00:00-03:00');
    $blockedSaved=fte_monthly_source_snapshot_save($conn,$blockedBundle,$userId,$temp);
    $blocked=false;
    try{
        fte_monthly_source_snapshot_approve($conn,'2099-09',$userId,1,'Debe bloquearse',$temp);
    }catch(DomainException $exception){
        $blocked=str_contains($exception->getMessage(),'La validacion previa impide aprobar el mes');
    }
    $assert(($blockedSaved['is_full_snapshot']??false)===true && ($blockedSaved['can_approve']??true)===false,'una fuente completa puede quedar bloqueada por inconsistencias nominales');
    $assert($blocked,'el servidor impide aprobar por una ruta directa cuando la validacion previa tiene pendientes');
    $approved=fte_monthly_source_snapshot_approve($conn,'2099-10',$userId,1,'Prueba integral',$temp);
    $assert(($approved['status']??'')==='APROBADO','aprueba el paquete integral');
    $loaded=fte_monthly_source_snapshot_load_approved($conn,'2099-10');
    $assert(count($loaded['sources']??[])===5,'reconstruye las cinco fuentes aprobadas');
    $report=fte_build_monthly_payload(fte_load_config(),['period'=>'2099-10','include_attendance'=>'0'],$conn);
    $assert(($report['headcount_source']??'')==='APPROVED_FULL_SNAPSHOT','el informe usa el paquete aprobado sin fuentes en vivo');
    $assert(($report['official_source_snapshot']['source_count']??0)===5,'el informe identifica las cinco fuentes oficiales');
    $assert(($report['calendar_source']??'')==='APPROVED_SOURCE_SNAPSHOT','el informe usa el calendario congelado');
    $assert(abs((float)($report['calendar']['theoretical_hours_per_person']??0)-(float)$calendar['theoretical_hours_per_person'])<0.000000001,'conserva las horas teoricas aprobadas');
    $assert(abs((float)$calendar['theoretical_hours_per_person']-(float)$liveCalendar['theoretical_hours_per_person'])>0.000000001,'la prueba distingue el calendario congelado del calendario vigente');
    $rejected=false;
    try{
        $stmt=$conn->prepare('UPDATE dbo.fte_dotacion_snapshot_fuentes SET fecha_captura=DATEADD(second,1,fecha_captura) WHERE id_fte_dotacion_snapshot=:id');
        $stmt->execute([':id'=>(int)$approved['id']]);
    }catch(PDOException $exception){$rejected=true;}
    $assert($rejected,'el trigger bloquea cambios en fuentes aprobadas');
}finally{
    if($conn->inTransaction())$conn->rollBack();
    foreach(['2099-09','2099-10'] as $period){
        $dir=$temp.DIRECTORY_SEPARATOR.$period.DIRECTORY_SEPARATOR.'v1';
        foreach(glob($dir.DIRECTORY_SEPARATOR.'*')?:[] as $file){if(is_file($file))unlink($file);}
        @rmdir($dir);@rmdir($temp.DIRECTORY_SEPARATOR.$period);
    }
    @rmdir($temp);
}
$count=$conn->query("SELECT COUNT(*) FROM dbo.fte_dotacion_snapshots WHERE periodo IN ('2099-09-01','2099-10-01')")->fetchColumn();
$assert((int)$count===0,'revierte todos los datos de prueba');
echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed===0?0:1);
