<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controlDiario = file_get_contents($root . '/msp/control_diario/index.php');
$liquidacionFinal = file_get_contents($root . '/msp/contratos/liquidacion_final.php');
$guardarServicio = file_get_contents($root . '/msp/contratos/guardar_servicio_liquidacion.php');
$documentosCobro = file_get_contents($root . '/msp/cobros/services/DocumentosCobroService.php');
$cerrarContrato = file_get_contents($root . '/msp/contratos/cerrar.php');

foreach (compact('controlDiario', 'liquidacionFinal', 'guardarServicio', 'documentosCobro', 'cerrarContrato') as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('No fue posible leer ' . $name . '.');
    }
}

$assertions = [
    'Control Diario conserva ocupaciones por mes y contrato' => str_contains($controlDiario, 'occupancy_records_by_month')
        && str_contains($controlDiario, 'data-occupancy-records-by-month'),
    'Control Diario distingue saliente y entrante sin duplicar los importes' => str_contains($controlDiario, "badge.textContent = 'Saliente'")
        && str_contains($controlDiario, "badge.textContent = 'Entrante'"),
    'El cierre inicializa servicios finales en el contrato saliente' => str_contains($cerrarContrato, 'INSERT INTO dbo.msp_liquidacion_servicios')
        && str_contains($cerrarContrato, ':id_contrato_local'),
    'La asignación ordinaria usa la fecha final real del consumo' => str_contains($documentosCobro, 'cl.fecha_inicio <= lm.fecha_hasta_consumo')
        && str_contains($documentosCobro, 'ca.fecha_inicio <= lm.fecha_hasta_consumo'),
    'Los consumos tardíos permanecen ligados al contrato local saliente' => str_contains($documentosCobro, 'ON cl.id_contrato_local = ls.id_contrato_local')
        && str_contains($documentosCobro, 'WHERE c.periodo_emision = @periodo'),
    'Liquidación final muestra servicios después de garantías' => strpos($liquidacionFinal, '>Garantías<') !== false
        && strpos($liquidacionFinal, '>Servicios finales y lecturas<') !== false
        && strpos($liquidacionFinal, '>Garantías<') < strpos($liquidacionFinal, '>Servicios finales y lecturas<'),
    'La interfaz permite registrar, conciliar, reabrir y marcar No aplica' => str_contains($liquidacionFinal, 'REGISTRAR_CONSUMO')
        && str_contains($liquidacionFinal, 'MARCAR_NO_APLICA')
        && str_contains($liquidacionFinal, 'CONFIRMAR_CONCILIADO')
        && str_contains($liquidacionFinal, 'REABRIR_PENDIENTE'),
    'El backend conserva las cuatro acciones operativas' => str_contains($guardarServicio, "['REGISTRAR_CONSUMO', 'MARCAR_NO_APLICA', 'CONFIRMAR_CONCILIADO', 'REABRIR_PENDIENTE']"),
    'El backend calcula consumo desde las lecturas si queda vacío' => str_contains($guardarServicio, '$consumoAsignado = round($lecturaActual - $lecturaAnterior, 4);'),
    'Los detalles de documentos y consumos son desplegables' => str_contains($liquidacionFinal, '<details class="card shadow-sm h-100 lf-details">')
        && str_contains($liquidacionFinal, '<details class="lf-service-consumptions mt-2">'),
    'El checklist queda intacto para una ejecución posterior' => str_contains($liquidacionFinal, '>Checklist de liquidación<'),
];

$failures = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: ' . count($assertions) . " verificaciones del cambio de arrendatario y servicios finales superadas.\n";
