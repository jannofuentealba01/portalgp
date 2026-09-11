<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/garantias/archivo_helper.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};

$redacted = pgpRedactLogMessage(
    "Authorization: Bearer abc.def.ghi access_token=secreto usuario@empresa.cl 12.345.678-5\nsegunda linea"
);
$assert(!str_contains($redacted, 'abc.def.ghi'), 'los Bearer tokens se eliminan de los logs');
$assert(!str_contains($redacted, 'secreto'), 'los secretos OAuth se eliminan de los logs');
$assert(!str_contains($redacted, 'usuario@empresa.cl'), 'los correos se eliminan de los logs');
$assert(!str_contains($redacted, '12.345.678-5'), 'los RUT se eliminan de los logs');
$assert(!str_contains($redacted, "\n"), 'los saltos de línea se eliminan de los logs');

$technical = pgpSafePublicMessage(
    'SQLSTATE[42S02]: Invalid object name dbo.secreto in C:\\xampp\\htdocs\\portalgp\\msp\\x.php',
    'test.stage2.public_message',
    'No fue posible completar la operación.'
);
$assert(!str_contains($technical, 'SQLSTATE') && !str_contains($technical, 'dbo.secreto'), 'los errores técnicos no llegan al navegador');
$assert(str_starts_with($technical, 'No fue posible completar la operación. Referencia:'), 'el error público conserva una referencia trazable');
$assert(
    pgpSafePublicMessage('El monto debe ser mayor que cero.', 'test.stage2.business_message', 'Error.') === 'El monto debe ser mayor que cero.',
    'los mensajes de validación de negocio se conservan'
);

$previousAudience = $_SESSION['portal_audience'] ?? null;
unset($_SESSION['portal_audience']);
$assert(pgpSessionAudience() === 'internal', 'las sesiones existentes continúan como internas');
$_SESSION['portal_audience'] = 'arrendatario';
$assert(pgpSessionAudience() === 'arrendatario', 'la audiencia externa queda identificada explícitamente');
$_SESSION['portal_audience'] = 'valor_invalido';
$assert(pgpSessionAudience() === 'invalid', 'una audiencia desconocida se rechaza');
if ($previousAudience === null) {
    unset($_SESSION['portal_audience']);
} else {
    $_SESSION['portal_audience'] = $previousAudience;
}

$mspBootstrapSource = (string) file_get_contents(dirname(__DIR__) . '/msp/bootstrap.php');
$ctBootstrapSource = (string) file_get_contents(dirname(__DIR__) . '/ct/bootstrap.php');
$assert(
    str_contains($mspBootstrapSource, 'pgpRequireInternalAudience();')
    && str_contains($ctBootstrapSource, 'pgpRequireInternalAudience();'),
    'MSP y CT rechazan sesiones marcadas para el futuro portal de arrendatarios'
);

$root = dirname(__DIR__);
$mspRoot = $root . '/msp';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mspRoot));
$idEntrypoints = [];
$uploadEntrypoints = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $source = (string) file_get_contents($path);
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (preg_match('/\$_(?:GET|POST|REQUEST)\s*\[[^\]]*(?:id|id_|_id)|filter_input\([^\r\n]*(?:id|id_|_id)/i', $source) === 1) {
        $idEntrypoints[$relative] = $source;
    }
    if (str_contains($source, '$_FILES')) {
        $uploadEntrypoints[$relative] = $source;
    }
}
$assert(count($idEntrypoints) >= 80, 'el inventario cubre las rutas MSP que reciben identificadores');
$unguarded = array_filter(
    $idEntrypoints,
    static fn(string $source): bool => preg_match(
        '/msp2Require(?:Any)?Access\s*\(|msp2DocumentosTiendaRequireAny\s*\(/',
        $source
    ) !== 1
);
$assert($unguarded === [], 'todas las rutas MSP con identificadores exigen autorización del módulo');

$unvalidatedUploads = [];
foreach ($uploadEntrypoints as $relative => $source) {
    $usesCentralSpreadsheetValidator = str_contains($source, 'msp2ValidateSpreadsheetUpload(');
    $usesGuaranteeValidator = $relative === 'msp/garantias/subir_archivo.php'
        && str_contains($source, 'msp2GarantiaArchivoValidateContent(')
        && str_contains($source, 'is_uploaded_file(');
    $usesDocumentPdfValidator = $relative === 'msp/documentos_tienda/cargar.php'
        && str_contains($source, 'is_uploaded_file(')
        && str_contains($source, 'new finfo(FILEINFO_MIME_TYPE)')
        && str_contains($source, "\$magic !== '%PDF-'");
    if (!$usesCentralSpreadsheetValidator && !$usesGuaranteeValidator && !$usesDocumentPdfValidator) {
        $unvalidatedUploads[] = $relative;
    }
}
$assert(count($uploadEntrypoints) >= 9, 'el inventario cubre todos los cargadores MSP actuales');
$assert($unvalidatedUploads === [], 'todos los cargadores MSP usan validación central o validación de firma');

$guaranteeDownload = (string) file_get_contents($root . '/msp/garantias/descargar_archivo.php');
$assert(
    str_contains($guaranteeDownload, "msp2RequireAccess('MSP Cobranza','lectura')")
    && str_contains($guaranteeDownload, 'realpath(')
    && str_contains($guaranteeDownload, 'hash_equals('),
    'la descarga de garantías exige permiso, contención de ruta e integridad SHA-256'
);
$storageRoot = str_replace('\\', '/', msp2GarantiaArchivosRoot());
$normalizedWebRoot = rtrim(str_replace('\\', '/', $root), '/');
$assert(!str_starts_with(strtolower($storageRoot), strtolower($normalizedWebRoot . '/')), 'los respaldos de garantías se almacenan fuera del webroot');

$ctImporter = (string) file_get_contents($root . '/ct/predial/terceros/terceros_import_service.php');
$assert(
    str_contains($ctImporter, 'new finfo(FILEINFO_MIME_TYPE)')
    && str_contains($ctImporter, 'LIBXML_NONET')
    && str_contains($ctImporter, '$zip->numFiles'),
    'el importador CT valida MIME, limita el ZIP y bloquea recursos XML externos'
);

if (class_exists('ZipArchive')) {
    $xlsxPath = tempnam(sys_get_temp_dir(), 'pgp_xlsx_');
    if (!is_string($xlsxPath)) {
        throw new RuntimeException('No se pudo crear el XLSX temporal de prueba.');
    }
    $zip = new ZipArchive();
    $zip->open($xlsxPath, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    $zip->close();
    $assert(msp2ValidateXlsxArchive($xlsxPath) === null, 'un XLSX con estructura mínima válida se acepta');
    @unlink($xlsxPath);

    $badPath = tempnam(sys_get_temp_dir(), 'pgp_xlsx_bad_');
    if (!is_string($badPath)) {
        throw new RuntimeException('No se pudo crear el XLSX malicioso temporal.');
    }
    $zip = new ZipArchive();
    $zip->open($badPath, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<Types/>');
    $zip->addFromString('xl/workbook.xml', '<workbook/>');
    $zip->addFromString('../salida.txt', 'no permitido');
    $zip->close();
    $assert(msp2ValidateXlsxArchive($badPath) !== null, 'un XLSX con ruta interna traversal se rechaza');
    @unlink($badPath);
}

$fakePdf = tempnam(sys_get_temp_dir(), 'pgp_pdf_');
if (!is_string($fakePdf)) {
    throw new RuntimeException('No se pudo crear el PDF temporal de prueba.');
}
file_put_contents($fakePdf, "contenido ajeno\n%PDF-1.7");
$assert(!msp2GarantiaArchivoValidateContent($fakePdf, 'application/pdf'), 'un archivo con PDF incrustado y cabecera falsa se rechaza');
file_put_contents($fakePdf, "%PDF-1.7\n%%EOF");
$assert(msp2GarantiaArchivoValidateContent($fakePdf, 'application/pdf'), 'un archivo con firma PDF correcta se acepta');
@unlink($fakePdf);

$admin = $conn->query(
    "SELECT TOP 1 u.id,u.estado_id,u.rol_id,u.password_hash,u.security_version,
        (SELECT COUNT(*) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) AS permisos
     FROM dbo.cr_usuarios u WHERE u.UserName=N'admin_2'"
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($admin) && (int) ($admin['id'] ?? 0) === 1030, 'admin_2 continúa existiendo con su identificador original');
$assert((int) ($admin['estado_id'] ?? 0) === 1, 'admin_2 continúa habilitado');
$assert((int) ($admin['rol_id'] ?? 0) === 1 && (int) ($admin['permisos'] ?? 0) >= 22, 'admin_2 conserva su rol y no pierde permisos');
$assert(trim((string) ($admin['password_hash'] ?? '')) !== '', 'la contraseña de admin_2 permanece configurada');

echo 'PASS security_stage2_completion (' . $checks . ' comprobaciones)' . PHP_EOL;
