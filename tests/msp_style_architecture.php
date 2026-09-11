<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$mspRoot = $root . DIRECTORY_SEPARATOR . 'msp';
$viewsRoot = $mspRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'views';
$failures = [];
$checks = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$checks): void {
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$header = (string) file_get_contents($root . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'header.php');
$screenCss = (string) file_get_contents($mspRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'screen.css');
$printCss = (string) file_get_contents($mspRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'print.css');

$assert(str_contains($header, '/msp/assets/screen.css'), 'El header no carga la hoja compartida de pantalla.');
$assert(str_contains($header, '/msp/assets/views/'), 'El header no resuelve la hoja propia de cada vista.');
$assert(
    str_contains($header, '/msp/assets/print.css') && str_contains($header, 'media="print"'),
    'La hoja de impresión no está aislada mediante media="print".'
);
$assert(!str_contains($screenCss, '@media print'), 'screen.css contiene reglas de impresión.');
$assert(str_contains($printCss, '.gp-module-msp'), 'print.css no contiene el ámbito MSP.');

$sharedSelectors = [
    '.picker-select-btn',
    '.msp-hub-card',
    '.msp-mail-sending-overlay',
    '.msp-report-chart-box',
    '.msp-searchable-select-menu',
    '.msp-quick-access',
];
foreach ($sharedSelectors as $selector) {
    $assert(str_contains($screenCss, $selector), "Falta el componente compartido {$selector}.");
}

$screenFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mspRoot));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $path = $file->getPathname();
    $contents = (string) file_get_contents($path);
    $usesScreenHeader = preg_match('~templates[/\\\\]header\.php~', $contents) === 1;
    if (!$usesScreenHeader) {
        continue;
    }

    $screenFiles[] = $path;
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $assert(
        preg_match('~<style(?:\s[^>]*)?>~i', $contents) !== 1,
        "La pantalla {$relative} todavía contiene CSS incrustado."
    );
    $assert(
        preg_match('~@media\s+print~i', $contents) !== 1,
        "La pantalla {$relative} mezcla reglas de impresión."
    );
}

$assert(count($screenFiles) >= 40, 'El inventario detectó menos de 40 pantallas MSP y podría estar incompleto.');

$viewAssets = glob($viewsRoot . DIRECTORY_SEPARATOR . '*.css') ?: [];
$assert(count($viewAssets) >= 30, 'No se encontraron las hojas específicas esperadas para las vistas especiales.');

foreach ($viewAssets as $asset) {
    $contents = (string) file_get_contents($asset);
    $relative = str_replace('\\', '/', substr($asset, strlen($root) + 1));
    $assert(!str_contains($contents, '<?php'), "La hoja {$relative} contiene PHP.");
    $assert(
        preg_match('~@media\s+print~i', $contents) !== 1,
        "La hoja de pantalla {$relative} contiene reglas de impresión."
    );
    foreach ($sharedSelectors as $selector) {
        $assert(
            !str_contains($contents, $selector),
            "La hoja {$relative} repite el componente compartido {$selector}."
        );
    }
}

$documentPaths = [
    $mspRoot . '/contabilidad/aging_pdf.php',
    $mspRoot . '/documentos_cobro/pdf.php',
    $mspRoot . '/garantias/comprobante.php',
    $mspRoot . '/cobranza/mail_templates/vale_pago_pdf.php',
    $mspRoot . '/cobros/mail_templates/vale_cobro_email.php',
];
foreach ($documentPaths as $documentPath) {
    $contents = (string) file_get_contents($documentPath);
    $relative = str_replace('\\', '/', substr($documentPath, strlen($root) + 1));
    $assert(
        !str_contains($contents, '/msp/assets/screen.css'),
        "El documento {$relative} está heredando estilos de pantalla."
    );
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " hallazgo(s) en {$checks} comprobaciones de arquitectura visual.\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }
    exit(1);
}

echo "PASS: {$checks} comprobaciones; " . count($screenFiles) . ' pantallas sin CSS incrustado y '
    . count($viewAssets) . " hojas específicas aisladas.\n";
