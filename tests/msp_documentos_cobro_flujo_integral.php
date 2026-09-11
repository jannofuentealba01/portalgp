<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/services/DocumentoCobroTrazabilidadService.php';

$failed = 0;
$checks = 0;
$check = static function (string $label, callable $test) use (&$failed, &$checks): void {
    $checks++;
    try {
        $ok = (bool) $test();
    } catch (Throwable $e) {
        $ok = false;
        $label .= ' (' . $e->getMessage() . ')';
    }
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed++;
    }
};

$check('Mayo 2026 existe en borrador con UF oficial', static function () use ($conn): bool {
    $row = $conn->query("SELECT estado_cierre, valor_uf FROM dbo.msp_cierre_mensual WHERE periodo_facturacion='2026-05-01'")->fetch();
    return is_array($row) && (int) $row['estado_cierre'] === 1 && abs((float) $row['valor_uf'] - 40133.50) < 0.001;
});

$check('Enero y febrero reflejan estado Calculado', static function () use ($conn): bool {
    return (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_cierre_mensual WHERE periodo_facturacion IN ('2026-01-01','2026-02-01') AND estado_cierre=2")->fetchColumn() === 2;
});

$check('Todos los documentos tienen evento de emisión', static function () use ($conn): bool {
    return (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro dc WHERE NOT EXISTS(SELECT 1 FROM dbo.msp_documentos_cobro_eventos ev WHERE ev.id_documento_cobro=dc.id_documento_cobro AND ev.tipo_evento=N'EMISION')")->fetchColumn() === 0;
});

$check('El registro individual de eventos funciona y revierte limpio', static function () use ($conn): bool {
    $doc = $conn->query('SELECT TOP (1) id_documento_cobro FROM dbo.msp_documentos_cobro ORDER BY id_documento_cobro')->fetch();
    if (!is_array($doc)) {
        return false;
    }
    $id = (int) $doc['id_documento_cobro'];
    $conn->beginTransaction();
    try {
        $antesStmt = $conn->prepare('SELECT COUNT(*) FROM dbo.msp_documentos_cobro_eventos WHERE id_documento_cobro=:id');
        $antesStmt->execute([':id' => $id]);
        $antes = (int) $antesStmt->fetchColumn();
        DocumentoCobroTrazabilidadService::registrar($conn, $id, 'AJUSTE', 'SISTEMA', null, ['prueba' => true]);
        $antesStmt->execute([':id' => $id]);
        $despues = (int) $antesStmt->fetchColumn();
        return $despues === $antes + 1;
    } finally {
        $conn->rollBack();
    }
});

$check('La trazabilidad de regeneración funciona y revierte limpio', static function () use ($conn): bool {
    $doc = $conn->query('SELECT TOP (1) periodo_facturacion, id_tienda FROM dbo.msp_documentos_cobro ORDER BY id_documento_cobro')->fetch();
    if (!is_array($doc)) {
        return false;
    }
    $conn->beginTransaction();
    try {
        $antes = (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_eventos WHERE tipo_evento=N'REGENERACION'")->fetchColumn();
        DocumentoCobroTrazabilidadService::registrarGeneracion(
            $conn,
            (string) $doc['periodo_facturacion'],
            1,
            'ALL',
            (string) (int) $doc['id_tienda']
        );
        $despues = (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_documentos_cobro_eventos WHERE tipo_evento=N'REGENERACION'")->fetchColumn();
        return $despues > $antes;
    } finally {
        $conn->rollBack();
    }
});

$check('La vista admite enlace directo filtroDocumento', static function (): bool {
    $source = file_get_contents(dirname(__DIR__) . '/msp/documentos_cobro/index.php');
    return is_string($source) && str_contains($source, "filter_input(INPUT_GET, 'filtroDocumento'") && str_contains($source, 'doc-target');
});

$check('El modo demo no exige activar envío real', static function (): bool {
    $source = file_get_contents(dirname(__DIR__) . '/msp/cobros/services/EnvioLotesProgramadosService.php');
    return is_string($source) && str_contains($source, 'bool $permitirDemo = false') && str_contains($source, 'if (!$permitirDemo && !msp2MailTenantDeliveryEnabled($conn))');
});

$check('La programación ya no llama al cambio de fecha de emisión', static function (): bool {
    $source = file_get_contents(dirname(__DIR__) . '/msp/cobros/services/EnvioLotesProgramadosService.php');
    return is_string($source) && !str_contains($source, 'self::legacyApplyFechaEmisionProgramadaToLoteDocs');
});

$check('Los antecedentes históricos incompletos quedaron identificados', static function () use ($conn): bool {
    $ops = (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_pago_contrato_operaciones o WHERE NOT EXISTS(SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle d WHERE d.id_pago_contrato_operacion=o.id_pago_contrato_operacion) AND ISNULL(o.trazabilidad_incompleta,0)=0")->fetchColumn();
    $refs = (int) $conn->query("SELECT COUNT(*) FROM dbo.msp_movimientos_saldo_favor_tienda m LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro WHERE ((m.id_pago IS NOT NULL AND p.id_pago IS NULL) OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL)) AND NOT EXISTS(SELECT 1 FROM dbo.msp_referencias_financieras_historicas h WHERE h.tipo_origen=N'SALDO_FAVOR_MOVIMIENTO' AND h.id_origen=m.id_movimiento_saldo_favor)")->fetchColumn();
    return $ops === 0 && $refs === 0;
});

echo PHP_EOL . 'Resultado: ' . ($checks - $failed) . '/' . $checks . ' pruebas correctas.' . PHP_EOL;
exit($failed === 0 ? 0 : 1);
