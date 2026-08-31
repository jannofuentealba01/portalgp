<?php
declare(strict_types=1);

function msp2GarantiaArchivosRoot(): string
{
    return dirname(__DIR__,4).DIRECTORY_SEPARATOR.'msp_storage'.DIRECTORY_SEPARATOR.'garantias';
}
function msp2GarantiaArchivoRedirect(string $origen): never
{
    msp2Redirect($origen==='DEVOLUCION'?'garantias/devoluciones.php':'garantias/recepciones.php');
}
function msp2GarantiaArchivoEnsureRoot(): string
{
    $root=msp2GarantiaArchivosRoot();
    if(!is_dir($root)&&!@mkdir($root,0775,true)&&!is_dir($root))throw new RuntimeException('No fue posible crear la carpeta segura de garantías.');
    return $root;
}
function msp2GarantiaArchivoSafeName(string $name): string
{
    $base=pathinfo($name,PATHINFO_FILENAME);$base=preg_replace('/[^A-Za-z0-9_-]+/','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$base)?:$base);$base=trim((string)$base,'_');
    return substr($base!==''?$base:'respaldo',0,100);
}
