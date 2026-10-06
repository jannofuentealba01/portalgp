<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$root = dirname(__DIR__);
$failed = 0;
$checks = 0;

$check = static function (bool $condition, string $label) use (&$failed, &$checks): void {
    $checks++;
    echo ($condition ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
};

$lotes = (string) file_get_contents($root . '/msp/cobros/services/EnvioLotesProgramadosService.php');
$demo = (string) file_get_contents($root . '/msp/cobros/services/EnvioDemoService.php');
$reenvio = (string) file_get_contents($root . '/msp/documentos_cobro/reenviar_individual.php');
$respaldos = (string) file_get_contents($root . '/msp/pagos/archivos_pdf_helper.php');
$vale = (string) file_get_contents($root . '/msp/documentos_cobro/vale_lib.php');

$check(
    str_contains($lotes, '[$filename, $pdf] = msp2BuildDocumentoCobroValePdf($conn, $docId);')
        && !str_contains($lotes, 'msp2BuildDocumentoCobroValeResumenPdf('),
    'El lote mensual adjunta el vale de cobro completo'
);
$check(
    str_contains($demo, '[$valeFilename, $valePdf] = msp2BuildDocumentoCobroValePdf($conn, $docId);')
        && !str_contains($demo, 'msp2BuildDocumentoCobroValeResumenPdf('),
    'El envío demo usa el mismo vale completo sin enviar correos durante esta prueba'
);
$check(
    str_contains($respaldos, '[$filename, $bytes] = msp2BuildDocumentoCobroValePdf($conn, $sourceId);'),
    'Respaldo PDFs materializa el mismo vale de cobro completo'
);
$check(
    preg_match(
        "/'web-doc-individual',\\s*false,\\s*true\\s*\\)/s",
        $reenvio
    ) === 1,
    'El reenvío individual usa el vale completo y permite reenviar un documento ya enviado'
);
$check(
    str_contains($lotes, 'bool $permitirDocumentosYaEnviados = false')
        && str_contains($lotes, 'if (!$permitirDocumentosYaEnviados)'),
    'La excepción de duplicados está separada del tipo de PDF adjunto'
);
$check(
    str_contains($vale, 'function msp2BuildDocumentoCobroValePdf(')
        && str_contains($vale, '<div class="section-title">Arriendo</div>')
        && str_contains($vale, '<div class="section-title">Electricidad</div>')
        && str_contains($vale, '<div class="section-title">Gas</div>')
        && str_contains($vale, '<div class="section-title">Agua</div>')
        && str_contains($vale, '<td>Total a pagar</td>'),
    'El vale completo conserva desglose de arriendo, servicios y total a pagar'
);

echo PHP_EOL . 'Resultado: ' . ($checks - $failed) . '/' . $checks . ' pruebas correctas.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
