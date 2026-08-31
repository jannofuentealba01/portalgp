<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';require_once __DIR__.'/archivo_helper.php';msp2RequireAccess();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id){http_response_code(404);exit('Archivo no encontrado.');}
$stmt=$conn->prepare('SELECT * FROM dbo.msp_garantia_archivos WHERE id_garantia_archivo=:id AND estado_archivo=N\'ACTIVO\'');$stmt->execute([':id'=>(int)$id]);$row=$stmt->fetch();if(!$row){http_response_code(404);exit('Archivo no encontrado.');}
$root=realpath(msp2GarantiaArchivosRoot());$path=$root!==false?realpath($root.DIRECTORY_SEPARATOR.str_replace(['/',"\\"],DIRECTORY_SEPARATOR,(string)$row['ruta_relativa'])):false;
if($root===false||$path===false||!str_starts_with(strtolower($path),strtolower($root.DIRECTORY_SEPARATOR))||!is_file($path)){http_response_code(404);exit('El respaldo físico no está disponible.');}
if(!hash_equals((string)$row['hash_sha256'],hash_file('sha256',$path))){http_response_code(409);exit('El archivo no superó la validación de integridad.');}
$name=preg_replace('/[\r\n"]+/','_',basename((string)$row['nombre_archivo']))?:'respaldo';header('Content-Type: '.(string)$row['mime_type']);header('Content-Length: '.filesize($path));header('Content-Disposition: attachment; filename="'.$name.'"');header('X-Content-Type-Options: nosniff');readfile($path);exit;
