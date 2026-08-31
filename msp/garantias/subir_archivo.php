<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';require_once __DIR__.'/archivo_helper.php';msp2RequireAccess();
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')msp2Redirect('garantias/recepciones.php');
$origen=strtoupper(trim((string)($_POST['origen']??'')));$idRecepcion=filter_input(INPUT_POST,'id_recepcion_garantia',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$idDevolucion=filter_input(INPUT_POST,'id_devolucion_garantia',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);$file=$_FILES['archivo']??null;
try{
 if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Selecciona un archivo válido.');
 $size=(int)($file['size']??0);if($size<=0||$size>10*1024*1024)throw new RuntimeException('El archivo debe pesar entre 1 byte y 10 MB.');
 $tmp=(string)($file['tmp_name']??'');$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($tmp);$allowed=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'];if(!isset($allowed[$mime]))throw new RuntimeException('Solo se permiten archivos PDF, JPG o PNG.');
 if($origen==='RECEPCION'&&$idRecepcion){$stmt=$conn->prepare('SELECT id_garantia FROM dbo.msp_garantia_recepciones WHERE id_recepcion_garantia=:id AND estado_recepcion<>N\'ANULADA\'');$stmt->execute([':id'=>(int)$idRecepcion]);$idGarantia=(int)($stmt->fetchColumn()?:0);$tipo='COMPROBANTE_RECEPCION';}
 elseif($origen==='DEVOLUCION'&&$idDevolucion){$stmt=$conn->prepare('SELECT id_garantia FROM dbo.msp_garantia_devoluciones WHERE id_devolucion_garantia=:id AND estado_devolucion<>N\'ANULADA\'');$stmt->execute([':id'=>(int)$idDevolucion]);$idGarantia=(int)($stmt->fetchColumn()?:0);$tipo='COMPROBANTE_DEVOLUCION';}
 else throw new RuntimeException('El origen del respaldo no es válido.');
 if($idGarantia<=0)throw new RuntimeException('La operación asociada no existe o está anulada.');
 $root=msp2GarantiaArchivoEnsureRoot();$sub=date('Y').DIRECTORY_SEPARATOR.date('m');$dir=$root.DIRECTORY_SEPARATOR.$sub;if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('No fue posible preparar la carpeta del respaldo.');
 $original=basename((string)($file['name']??'respaldo'));$stored=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$absolute=$dir.DIRECTORY_SEPARATOR.$stored;if(!move_uploaded_file($tmp,$absolute))throw new RuntimeException('No fue posible almacenar el archivo.');
 $relative=str_replace(DIRECTORY_SEPARATOR,'/',$sub.DIRECTORY_SEPARATOR.$stored);$hash=hash_file('sha256',$absolute);
 try{$stmt=$conn->prepare('INSERT dbo.msp_garantia_archivos(id_garantia,id_recepcion_garantia,id_devolucion_garantia,tipo_documento,nombre_archivo,ruta_relativa,mime_type,hash_sha256,bytes_archivo,id_usuario) VALUES(:garantia,:recepcion,:devolucion,:tipo,:nombre,:ruta,:mime,:hash,:bytes,:usuario)');$stmt->execute([':garantia'=>$idGarantia,':recepcion'=>$origen==='RECEPCION'?(int)$idRecepcion:null,':devolucion'=>$origen==='DEVOLUCION'?(int)$idDevolucion:null,':tipo'=>$tipo,':nombre'=>$original,':ruta'=>$relative,':mime'=>$mime,':hash'=>$hash,':bytes'=>$size,':usuario'=>(int)$_SESSION['usuario']['id']]);}catch(Throwable $dbError){@unlink($absolute);throw $dbError;}
 msp2SetFlash('success','Respaldo cargado correctamente.');
}catch(Throwable $e){msp2SetFlash($e instanceof RuntimeException?'warning':'danger',$e instanceof RuntimeException?$e->getMessage():'No fue posible cargar el respaldo.');}
msp2GarantiaArchivoRedirect($origen);
