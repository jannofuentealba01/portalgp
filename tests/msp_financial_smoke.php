<?php
declare(strict_types=1);

/* Pruebas de humo MSP. Solo lectura: no inserta, actualiza ni elimina datos. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once dirname(__DIR__) . '/db.php';

$checks = [];
$check = static function (string $name, callable $fn) use (&$checks): void {
    try { $value = $fn(); $checks[] = [$name, $value === true, $value === true ? 'OK' : (string)$value]; }
    catch (Throwable $e) { $checks[] = [$name, false, $e->getMessage()]; }
};
$exists = static function (string $type, string $name) use ($conn): bool {
    $s = $conn->prepare('SELECT CASE WHEN OBJECT_ID(:name,:type) IS NULL THEN 0 ELSE 1 END');
    $s->execute([':name'=>'dbo.'.$name, ':type'=>$type]); return (bool)$s->fetchColumn();
};

foreach (['msp_contratos_arriendo','msp_contrato_locales','msp_documentos_cobro','msp_documentos_cobro_detalle','msp_garantias','msp_movimientos_garantia','msp_cierre_mensual','msp_documentos_cobro_eventos'] as $t) {
    $check('Tabla '.$t, fn() => $exists('U',$t));
}
$check('Catálogo prioridad de imputación', fn() => $exists('U', 'msp_prioridades_imputacion_pago'));
foreach (['msp_generar_documentos_cobro_periodo','msp_registrar_pago_documento','msp_garantia_aplicar_documento','msp_tesoreria_reabrir_caja'] as $p) {
    $check('Procedimiento '.$p, fn() => $exists('P',$p));
}
foreach (['msp_vw_garantias_control_integral','msp_vw_garantias_resumen'] as $v) {
    $check('Vista '.$v, fn() => $exists('V',$v));
}
$check('Documentos sin contrato inválido', static function() use ($conn): bool {
    return (int)$conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro dc LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=dc.id_contrato_arriendo WHERE dc.id_contrato_arriendo IS NOT NULL AND c.id_contrato_arriendo IS NULL")->fetchColumn() === 0;
});
$check('Detalles sin documento inválido', static function() use ($conn): bool {
    return (int)$conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_detalle d LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=d.id_documento_cobro WHERE dc.id_documento_cobro IS NULL")->fetchColumn() === 0;
});
$check('Eventos con contrato válido', static function() use ($conn): bool {
    return (int)$conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_eventos e LEFT JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=e.id_contrato_arriendo WHERE c.id_contrato_arriendo IS NULL")->fetchColumn() === 0;
});
$check('Asientos balanceados', static function() use ($conn): bool {
    return (int)$conn->query("SELECT COUNT(*) FROM (SELECT a.id_asiento_contable FROM dbo.msp_acc_asientos a JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable WHERE a.estado_asiento=1 GROUP BY a.id_asiento_contable HAVING ABS(SUM(d.debe)-SUM(d.haber))>0.01) x")->fetchColumn() === 0;
});

$failed = 0; foreach ($checks as [$name,$ok,$message]) { echo ($ok ? '[OK] ' : '[FAIL] ').$name.' - '.$message.PHP_EOL; if (!$ok) $failed++; }
echo 'Resultado: '.(count($checks)-$failed).'/'.count($checks).' pruebas correctas.'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
