<?php
declare(strict_types=1);

require __DIR__ . '/../rrhh/fte/fte_attendance_status.php';

$passed = 0;
$failed = 0;
$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "[OK] {$label}\n";
    } else {
        $failed++;
        echo "[FAIL] {$label}\n";
    }
};
$status = static fn(array $facts): array => fte_attendance_resolve_status($facts);

$assert($status(['contract_active' => false])['code'] === 'FUERA_DE_CONTRATO', 'distingue persona fuera de contrato');
$assert($status(['provider_ok' => false, 'planned_work' => true])['code'] === 'ERROR_GEOVICTORIA', 'un error de proveedor no se convierte en ausencia');
$assert($status(['identity_matched' => false])['code'] === 'NO_CONCILIADO', 'distingue identidad no conciliada');
$assert($status(['absence_type' => 'Vacaciones'])['code'] === 'VACACIONES', 'distingue vacaciones');
$assert($status(['absence_type' => 'Licencia médica'])['code'] === 'LICENCIA', 'distingue licencia medica');
$assert($status(['absence_type' => 'Accidente'])['code'] === 'ACCIDENTE', 'distingue accidente');
$assert($status(['absence_type' => 'Permiso día'])['code'] === 'PERMISO_DIA', 'distingue permiso de dia');
$assert($status(['article_22' => true])['code'] === 'ARTICULO_22', 'distingue articulo 22');
$assert($status(['planned_work' => false])['code'] === 'DIA_LIBRE', 'distingue dia libre');
$assert($status(['permission_hours' => 2])['code'] === 'PERMISO_HORA', 'distingue permiso por horas');
$assert($status(['planned_work' => true, 'punch_count' => 2, 'has_entry' => true, 'has_exit' => true])['code'] === 'TRABAJADO', 'distingue jornada trabajada completa');
$assert($status(['planned_work' => true, 'punch_count' => 1, 'has_entry' => true])['code'] === 'MARCACION_INCOMPLETA', 'distingue marcacion incompleta');
$assert($status(['planned_work' => true, 'punch_count' => 0])['code'] === 'SIN_MARCACION', 'distingue falta de marcas con jornada informada');
$assert($status(['planned_work' => null, 'punch_count' => 0])['code'] === 'SIN_JORNADA_INFORMADA', 'no llama ausencia a un dia sin jornada conocida');

foreach (['ERROR_GEOVICTORIA', 'NO_CONCILIADO', 'MARCACION_INCOMPLETA', 'SIN_MARCACION', 'SIN_JORNADA_INFORMADA'] as $code) {
    $facts = match ($code) {
        'ERROR_GEOVICTORIA' => ['provider_ok' => false],
        'NO_CONCILIADO' => ['identity_matched' => false],
        'MARCACION_INCOMPLETA' => ['punch_count' => 1, 'has_entry' => true],
        'SIN_MARCACION' => ['planned_work' => true],
        default => ['planned_work' => null],
    };
    $resolved = $status($facts);
    $assert($resolved['requires_review'] === true && $resolved['is_unjustified_absence'] === false, $code . ' requiere revision y no prueba falta injustificada');
}

$summary = fte_attendance_summarize_statuses([
    ['attendance_status' => 'TRABAJADO', 'attendance_requires_review' => false],
    ['attendance_status' => 'SIN_MARCACION', 'attendance_requires_review' => true],
    ['attendance_status' => 'SIN_MARCACION', 'attendance_requires_review' => true],
]);
$assert(($summary['counts']['SIN_MARCACION'] ?? 0) === 2, 'resume cantidad por estado');
$assert($summary['requires_review'] === 2, 'resume casos que requieren revision');

echo "Resultado: {$passed} OK, {$failed} fallidas.\n";
exit($failed === 0 ? 0 : 1);
