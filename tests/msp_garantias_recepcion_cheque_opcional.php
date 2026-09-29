<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once dirname(__DIR__) . '/db.php';

$root = dirname(__DIR__);
$failed = 0;
$assert = static function (bool $condition, string $message) use (&$failed): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
};

$nullableColumns = (int) $conn->query(
    "SELECT COUNT(*)
     FROM sys.columns
     WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_recepciones')
       AND name IN(N'banco_emisor',N'numero_cheque',N'fecha_cheque')
       AND is_nullable=1"
)->fetchColumn();
$assert($nullableColumns === 3, 'Los tres antecedentes conservan columnas anulables.');

$handler = file_get_contents($root . '/msp/garantias/registrar_recepcion.php');
$assert(
    is_string($handler)
    && !str_contains($handler, 'El cheque requiere banco emisor y número de cheque.')
    && str_contains($handler, "\$fechaCheque !== ''")
    && str_contains($handler, "':cheque'=>\$medio==='CHEQUE'?\$numeroCheque:null"),
    'El servidor acepta antecedentes vacíos y mantiene compatibilidad con la base histórica.'
);

$form = file_get_contents($root . '/msp/garantias/recepciones.php');
$assert(
    is_string($form)
    && substr_count($form, '(opcional)</span>') >= 3
    && !preg_match('/name="(?:banco_emisor|numero_cheque|fecha_cheque)"[^>]*\srequired(?:\s|>)/', $form),
    'La pantalla identifica los tres antecedentes como opcionales.'
);

echo 'Resultado: ' . ($failed === 0 ? 'correcto' : $failed . ' fallo(s)') . PHP_EOL;
exit($failed === 0 ? 0 : 1);
