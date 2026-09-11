<?php
declare(strict_types=1);
require __DIR__ . '/../rrhh/fte/fte_lib.php';
$n = 0;
function audit_check(bool $ok, string $message): void {
    global $n;
    if (!$ok) { throw new RuntimeException($message); }
    $n++; echo "OK: $message\n";
}
try { fte_validate_range('2026-02-31', '2026-03-05', 93); $rejected = false; }
catch (RuntimeException $e) { $rejected = true; }
audit_check($rejected, 'Rechaza fechas inexistentes');
$mixed = fte_monthly_calculate_report([
 ['ceco'=>'A','headcount'=>1,'theoretical_hours_per_person'=>8],
 ['ceco'=>'B','headcount'=>1,'theoretical_hours_per_person'=>0],
]);
audit_check($mixed['totals']['fte'] === null, 'No publica total FTE parcial');
$calendar = ['period'=>'2026-08','theoretical_hours_per_person'=>8,'days'=>[
 ['date'=>'2026-08-03','theoretical_hours'=>8],
 ['date'=>'2026-08-08','theoretical_hours'=>0],
]];
$people = [['normalized_identifier'=>'19','active_since'=>'2026-01-01','cost_center_code'=>'A']];
$history = fte_headcount_build_month($people, 2026, 8, $calendar);
$attendance = ['19'=>[
 '2026-08-03'=>['delay_hours'=>2,'authorized_overtime_hours'=>1],
 '2026-08-08'=>['authorized_overtime_hours'=>5],
]];
$r = fte_monthly_build_report($calendar,$history,$people,['19'=>['2026-08-03'=>['kind'=>'Vacaciones']]],$attendance);
audit_check($r['totals']['authorized_overtime_hours'] === 1.0, 'Excluye horas extra del sabado');
audit_check($r['totals']['loss_components']['failure_delay_hours'] === 0.0, 'Evita doble descuento por ausencia y atraso');
echo "$n pruebas aprobadas.\n";
