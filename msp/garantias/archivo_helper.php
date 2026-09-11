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

function msp2GarantiaArchivoValidateContent(string $path, string $mime): bool
{
    if ($path === '' || !is_file($path)) {
        return false;
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return false;
    }
    $header = (string) fread($handle, 16);
    fclose($handle);
    if ($mime === 'application/pdf') {
        return str_starts_with($header, '%PDF-');
    }
    if ($mime === 'image/jpeg' && !str_starts_with($header, "\xFF\xD8\xFF")) {
        return false;
    }
    if ($mime === 'image/png' && !str_starts_with($header, "\x89PNG\r\n\x1A\n")) {
        return false;
    }
    if ($mime !== 'image/jpeg' && $mime !== 'image/png') {
        return false;
    }
    $image = @getimagesize($path);
    if (!is_array($image)) {
        return false;
    }
    $expected = $mime === 'image/jpeg' ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
    return (int) ($image[2] ?? 0) === $expected;
}
