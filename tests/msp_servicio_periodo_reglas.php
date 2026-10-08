<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/msp/services/ServicioPeriodoReglas.php';

function assertServicioPeriodo(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$luz = ServicioPeriodoReglas::ventanaMedicion('LUZ', '2026-10-01');
assertServicioPeriodo(($luz['periodo_ym'] ?? '') === '2026-09', 'Luz debe usar el mes anterior.');
assertServicioPeriodo(($luz['max'] ?? '') === '2026-09-30', 'La ventana de luz debe cerrar al final del mes anterior.');

$gas = ServicioPeriodoReglas::ventanaMedicion('GAS', '2026-10-01');
assertServicioPeriodo(($gas['periodo_ym'] ?? '') === '2026-09', 'Gas debe usar el mes anterior.');
assertServicioPeriodo(($gas['max'] ?? '') === '2026-10-05', 'Gas debe admitir cinco días del mes siguiente.');

$agua = ServicioPeriodoReglas::ventanaMedicion('AGUA', '2026-10-01');
assertServicioPeriodo(($agua['periodo_ym'] ?? '') === '2026-08', 'Agua debe mantener dos meses de desfase.');
assertServicioPeriodo(($agua['max'] ?? '') === '2026-08-31', 'La ventana de agua debe cerrar dos meses antes.');

assertServicioPeriodo(
    ServicioPeriodoReglas::periodoEmisionSugerido('AGUA', '2026-10-07') === '2026-12',
    'El agua final de octubre debe sugerir emisión en diciembre.'
);
assertServicioPeriodo(
    ServicioPeriodoReglas::periodoEmisionSugerido('LUZ', '2026-10-07') === '2026-11',
    'La luz final de octubre debe sugerir emisión en noviembre.'
);

echo "OK: reglas de desfase de luz, gas y agua verificadas.\n";
