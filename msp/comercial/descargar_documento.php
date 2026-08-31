<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php'; msp2RequireAccess();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$id){http_response_code(400);exit('Documento inválido.');}
$q=$conn->prepare("SELECT nombre_archivo,ruta_archivo FROM dbo.msp_centro_documental WHERE id_documento=:id AND estado='ACTIVO'");$q->execute([':id'=>$id]);$d=$q->fetch(PDO::FETCH_ASSOC);
if(!$d){http_response_code(404);exit('Documento no encontrado.');}
$base=realpath(dirname(__DIR__,2));$path=realpath((string)$d['ruta_archivo']);
if($path===false||$base===false||(strtolower(substr($path,0,strlen($base)))!==strtolower($base))){http_response_code(403);exit('Ruta de documento no autorizada.');}
if(!is_file($path)){http_response_code(404);exit('Archivo no disponible.');}
$mime=function_exists('mime_content_type')?(mime_content_type($path)?:'application/octet-stream'):'application/octet-stream';header('Content-Type: '.$mime);header('Content-Disposition: attachment; filename="'.str_replace('"','',basename((string)$d['nombre_archivo'])).'"');header('Content-Length: '.filesize($path));readfile($path);
