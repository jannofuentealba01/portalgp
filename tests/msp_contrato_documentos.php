<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/msp/services/ContratoDocumentoService.php';

function assertContratoDocumento(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

assertContratoDocumento(
    ContratoDocumentoService::maxBytes() === 15 * 1024 * 1024,
    'El límite debe ser exactamente 15 MB.'
);
$tipos = ContratoDocumentoService::tipos();
foreach (['CONTRATO','ANEXO','FINIQUITO','CERTIFICADO','ACTA','OTRO'] as $tipo) {
    assertContratoDocumento(isset($tipos[$tipo]), 'Falta el tipo documental ' . $tipo . '.');
}

$pdfValido = tempnam(sys_get_temp_dir(), 'msp_pdf_');
$archivoFalso = tempnam(sys_get_temp_dir(), 'msp_fake_');
if ($pdfValido === false || $archivoFalso === false) {
    throw new RuntimeException('No fue posible preparar archivos temporales para la prueba.');
}
try {
    file_put_contents($pdfValido, "%PDF-2.0\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    file_put_contents($archivoFalso, "contenido que no corresponde a un pdf\n");
    $reflection = new ReflectionMethod(ContratoDocumentoService::class, 'contieneFirmaPdf');
    $reflection->setAccessible(true);
    assertContratoDocumento((bool) $reflection->invoke(null, $pdfValido), 'Debe aceptar la firma PDF 2.0.');
    assertContratoDocumento(!(bool) $reflection->invoke(null, $archivoFalso), 'Debe rechazar contenido sin firma PDF.');
} finally {
    @unlink($pdfValido);
    @unlink($archivoFalso);
}

$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/msp/services/ContratoDocumentoService.php');
assertContratoDocumento(
    str_contains($serviceSource, "[1, 2]") && str_contains($serviceSource, 'término operativo'),
    'El servicio debe bloquear administración después del término operativo.'
);
assertContratoDocumento(
    str_contains($serviceSource, "hash_file('sha256'") && str_contains($serviceSource, 'hash_equals'),
    'La carga y la descarga deben validar SHA-256.'
);

$patch = (string) file_get_contents(dirname(__DIR__) . '/msp/db/patch_contrato_documentos_adjuntos.sql');
foreach (['15728640','ruta_relativa','hash_sha256','bytes_archivo','id_documento_reemplazado'] as $token) {
    assertContratoDocumento(str_contains($patch, $token), 'El parche SQL no contiene ' . $token . '.');
}

$uploadEndpoint = (string) file_get_contents(dirname(__DIR__) . '/msp/contratos/subir_documento.php');
$annulEndpoint = (string) file_get_contents(dirname(__DIR__) . '/msp/contratos/anular_documento.php');
$downloadEndpoint = (string) file_get_contents(dirname(__DIR__) . '/msp/contratos/descargar_documento.php');
$contractSheet = (string) file_get_contents(dirname(__DIR__) . '/msp/contratos/ficha.php');
$contractDocumentsJs = (string) file_get_contents(dirname(__DIR__) . '/msp/assets/contratos_ficha_documentos.js');
assertContratoDocumento(
    str_contains($uploadEndpoint, "msp2RequireAccess('MSP Operacion', 'escritura')"),
    'La carga debe exigir permiso de escritura.'
);
assertContratoDocumento(
    str_contains($annulEndpoint, "msp2RequireAccess('MSP Operacion', 'escritura')"),
    'La anulación debe exigir permiso de escritura.'
);
assertContratoDocumento(
    str_contains($downloadEndpoint, "msp2RequireAccess('MSP Operacion', 'lectura')")
        && str_contains($downloadEndpoint, 'X-Content-Type-Options: nosniff')
        && str_contains($downloadEndpoint, "\$modoInline ? 'inline' : 'attachment'"),
    'La descarga debe exigir lectura y entregar el PDF con cabeceras seguras.'
);
assertContratoDocumento(
    str_contains($contractSheet, 'id="documentos-adjuntos"')
        && str_contains($contractSheet, 'Documentos de cobro')
        && str_contains($contractSheet, 'enctype="multipart/form-data"'),
    'La ficha debe separar los adjuntos PDF de los documentos de cobro.'
);
assertContratoDocumento(
    str_contains($contractSheet, "msp2CurrentUserHasPermission('MSP Operacion', 'escritura')")
        && !str_contains($contractSheet, "'admin_2'")
        && !str_contains($contractSheet, "'respinoza'"),
    'La administración debe depender del permiso funcional y no de nombres de usuario.'
);
assertContratoDocumento(
    str_contains($contractDocumentsJs, 'file.size > maxBytes')
        && str_contains($contractDocumentsJs, "endsWith('.pdf')"),
    'La interfaz debe validar extensión y límite de 15 MB antes de enviar.'
);

echo "OK: almacenamiento e interfaz PDF contractual, límite de 15 MB, permisos, integridad y bloqueo por término verificados.\n";
