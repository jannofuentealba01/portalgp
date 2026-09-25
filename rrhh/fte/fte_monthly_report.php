<?php
declare(strict_types=1);

function fte_monthly_normalize_source_label($value): string
{
    if (is_array($value)) {
        $value = $value['name'] ?? $value['description'] ?? $value['label'] ?? '';
    }
    $normalized = strtr(trim((string)$value), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
    ]);
    return strtoupper(preg_replace('/\s+/', ' ', $normalized) ?: '');
}

/**
 * Buk publica accidentes laborales dentro del endpoint de licencias. Esta
 * clasificación se usa solo en el informe mensual hasta contar con la regla
 * definitiva de conciliación de RR.HH.
 */
function fte_monthly_buk_licence_kind(array $item): string
{
    $labels = [];
    foreach (['licence_type', 'licence_type_code', 'risk_type', 'motivo'] as $key) {
        $label = fte_monthly_normalize_source_label($item[$key] ?? '');
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    $classificationText = implode(' | ', $labels);
    foreach (['ACCIDENTE DE TRABAJO', 'ACCIDENTE DEL TRABAJO', 'ACCIDENTE LABORAL', 'ACCIDENTE DE TRAYECTO'] as $accidentLabel) {
        if (str_contains($classificationText, $accidentLabel)) {
            return 'Accidente';
        }
    }
    return 'Licencia medica';
}

function fte_monthly_partition_buk_licences(array $items): array
{
    $partitioned = ['licences' => [], 'accidents' => []];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $bucket = fte_monthly_buk_licence_kind($item) === 'Accidente' ? 'accidents' : 'licences';
        $partitioned[$bucket][] = $item;
    }
    return $partitioned;
}

function fte_monthly_absence_priority(string $kind): int
{
    return match (fte_attendance_normalize_absence_type($kind)) {
        'ACCIDENTE' => 30,
        'LICENCIA' => 20,
        'VACACIONES' => 10,
        default => 0,
    };
}

/** Tipos de ausencia que participaron en la resolución diaria. */
function fte_monthly_absence_row_kinds(array $row): array
{
    $kinds = [];
    $kind = fte_attendance_normalize_absence_type((string)($row['kind'] ?? ''));
    if ($kind !== '') {
        $kinds[$kind] = true;
    }
    foreach (($row['overlapping_absence_kinds'] ?? []) as $overlappingKind) {
        $normalized = fte_attendance_normalize_absence_type((string)$overlappingKind);
        if ($normalized !== '') {
            $kinds[$normalized] = true;
        }
    }
    return array_keys($kinds);
}

/**
 * Evita que una licencia o vacación oculte un accidente del mismo día y
 * conserva los tipos superpuestos para que el cruce nunca quede silencioso.
 */
function fte_monthly_merge_absence_indexes(array ...$indexes): array
{
    $merged = [];
    foreach ($indexes as $index) {
        foreach ($index as $key => $byDate) {
            if (!is_array($byDate)) {
                continue;
            }
            foreach ($byDate as $date => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $currentPriority = isset($merged[$key][$date])
                    ? fte_monthly_absence_priority((string)($merged[$key][$date]['kind'] ?? ''))
                    : -1;
                $newPriority = fte_monthly_absence_priority((string)($row['kind'] ?? ''));
                if (!isset($merged[$key][$date])) {
                    $merged[$key][$date] = $row;
                    continue;
                }
                $current = $merged[$key][$date];
                $resolved = $newPriority > $currentPriority ? $row : $current;
                $resolvedKinds = array_values(array_unique(array_merge(
                    fte_monthly_absence_row_kinds($current),
                    fte_monthly_absence_row_kinds($row)
                )));
                if (count($resolvedKinds) > 1) {
                    sort($resolvedKinds, SORT_STRING);
                    $resolved['overlapping_absence_kinds'] = $resolvedKinds;
                }
                $merged[$key][$date] = $resolved;
            }
        }
    }
    return $merged;
}

/**
 * Distribuye la capacidad diaria entre conceptos ordenados por prioridad.
 *
 * `coverage_hours` representa el tramo de la jornada que el concepto justifica
 * o reclama. `loss_hours` representa cuánto de ese tramo reduce efectivamente
 * el FTE. La distinción permite que un permiso con goce bloquee una falla del
 * mismo día sin transformarse en horas no disponibles.
 */
function fte_monthly_resolve_daily_loss_priority(float $theoreticalHours, array $concepts): array
{
    $theoreticalHours = max(0.0, $theoreticalHours);
    $remainingHours = $theoreticalHours;
    $ownerSelected = false;
    $allocations = [];
    $candidateLossHours = 0.0;
    $appliedLossHours = 0.0;
    $candidateCoverageHours = 0.0;
    $activeConcepts = 0;

    foreach ($concepts as $key => $concept) {
        if (!is_array($concept)) {
            $concept = [];
        }
        $coverageHours = min($theoreticalHours, max(0.0, (float)($concept['coverage_hours'] ?? 0.0)));
        $lossHours = min($coverageHours, max(0.0, (float)($concept['loss_hours'] ?? $coverageHours)));
        $remainingBefore = $remainingHours;
        $allocatedCoverageHours = !$ownerSelected ? min($coverageHours, $remainingHours) : 0.0;
        $appliedConceptLossHours = min($lossHours, $allocatedCoverageHours);
        if ($coverageHours > 0.0 && !$ownerSelected) {
            $ownerSelected = true;
            $remainingHours = max(0.0, $remainingHours - $allocatedCoverageHours);
        }
        $suppressedCoverageHours = max(0.0, $coverageHours - $allocatedCoverageHours);
        $suppressedLossHours = max(0.0, $lossHours - $appliedConceptLossHours);
        if ($coverageHours > 0.0) {
            $activeConcepts++;
        }
        $candidateCoverageHours += $coverageHours;
        $candidateLossHours += $lossHours;
        $appliedLossHours += $appliedConceptLossHours;
        if ($coverageHours <= 0.0) {
            $resolution = 'SIN_SENAL';
        } elseif ($allocatedCoverageHours <= 0.0) {
            $resolution = 'OMITIDO_POR_PRIORIDAD';
        } elseif ($allocatedCoverageHours + 0.0000001 < $coverageHours) {
            $resolution = 'APLICADO_PARCIAL_POR_LIMITE_DE_JORNADA';
        } elseif ($lossHours <= 0.0) {
            $resolution = 'COBERTURA_JUSTIFICADA_SIN_DESCUENTO';
        } else {
            $resolution = 'APLICADO';
        }
        $allocations[(string)$key] = [
            'label' => trim((string)($concept['label'] ?? $key)),
            'candidate_coverage_hours' => $coverageHours,
            'candidate_loss_hours' => $lossHours,
            'remaining_before_hours' => $remainingBefore,
            'allocated_coverage_hours' => $allocatedCoverageHours,
            'applied_loss_hours' => $appliedConceptLossHours,
            'suppressed_coverage_hours' => $suppressedCoverageHours,
            'suppressed_loss_hours' => $suppressedLossHours,
            'resolution' => $resolution,
        ];
    }

    return [
        'theoretical_hours' => $theoreticalHours,
        'candidate_coverage_hours' => $candidateCoverageHours,
        'allocated_coverage_hours' => $theoreticalHours - $remainingHours,
        'candidate_loss_hours' => $candidateLossHours,
        'applied_loss_hours' => $appliedLossHours,
        'avoided_duplicate_loss_hours' => max(0.0, $candidateLossHours - $appliedLossHours),
        'suppressed_coverage_hours' => array_sum(array_column($allocations, 'suppressed_coverage_hours')),
        'remaining_hours' => $remainingHours,
        'active_concept_count' => $activeConcepts,
        'has_overlap' => $activeConcepts > 1,
        'allocations' => $allocations,
    ];
}

/** Clasificación provisional utilizada exclusivamente por el informe mensual. */
function fte_monthly_time_off_kind(array $timeOff): string
{
    $label = fte_monthly_normalize_source_label(
        $timeOff['type_description']
            ?? $timeOff['TimeOffTypeDescription']
            ?? $timeOff['description']
            ?? ''
    );
    if (!str_contains($label, 'PERMISO')) {
        return '';
    }
    foreach (['HORA', 'PARCIAL', '1/2', 'MEDIA JORNADA', 'MEDIO DIA'] as $partialLabel) {
        if (str_contains($label, $partialLabel)) {
            return 'PERMISO_HORA';
        }
    }
    return 'PERMISO_DIA';
}

/**
 * Clasifica la incidencia economica de un permiso diario. Solo las etiquetas
 * explicitas se resuelven automaticamente: nunca se presume el goce de sueldo.
 */
function fte_monthly_day_permission_pay_status(array $timeOff): string
{
    if (fte_monthly_time_off_kind($timeOff) !== 'PERMISO_DIA') {
        return 'NO_APLICA';
    }
    $label = fte_monthly_normalize_source_label(
        $timeOff['type_description']
            ?? $timeOff['TimeOffTypeDescription']
            ?? $timeOff['description']
            ?? ''
    );
    foreach (['SIN GOCE', 'SIN REMUNERACION', 'SIN REMUNERACIONES', 'SIN SUELDO', 'NO REMUNERADO'] as $pattern) {
        if (str_contains($label, $pattern)) {
            return 'SIN_GOCE';
        }
    }
    foreach (['CON GOCE', 'CON REMUNERACION', 'CON REMUNERACIONES', 'CON SUELDO', 'REMUNERADO'] as $pattern) {
        if (str_contains($label, $pattern)) {
            return 'CON_GOCE';
        }
    }
    return 'PENDIENTE';
}

function fte_monthly_is_vacation_time_off(array $timeOff): bool
{
    $label = fte_monthly_normalize_source_label(
        $timeOff['type_description']
            ?? $timeOff['TimeOffTypeDescription']
            ?? $timeOff['description']
            ?? ''
    );
    return str_contains($label, 'VACACION');
}

function fte_monthly_is_accident_time_off(array $timeOff): bool
{
    $label = fte_monthly_normalize_source_label(
        $timeOff['type_description']
            ?? $timeOff['TimeOffTypeDescription']
            ?? $timeOff['description']
            ?? ''
    );
    if (str_contains($label, 'ACCIDENTE COMUN')) {
        return false;
    }
    foreach (['ACCIDENTE DE TRABAJO', 'ACCIDENTE DEL TRABAJO', 'ACCIDENTE LABORAL', 'ACCIDENTE DE TRAYECTO'] as $pattern) {
        if (str_contains($label, $pattern)) {
            return true;
        }
    }
    return $label === 'ACCIDENTE';
}

function fte_monthly_is_medical_leave_time_off(array $timeOff): bool
{
    if (fte_monthly_is_accident_time_off($timeOff)) {
        return false;
    }
    $label = fte_monthly_normalize_source_label(
        $timeOff['type_description']
            ?? $timeOff['TimeOffTypeDescription']
            ?? $timeOff['description']
            ?? ''
    );
    return str_contains($label, 'LICENCIA')
        || str_contains($label, 'ENFERMEDAD')
        || str_contains($label, 'REPOSO MEDICO');
}

/** Evidencia diaria de licencia medica disponible en GeoVictoria. */
function fte_monthly_medical_leave_evidence(array $mark): array
{
    $medicalRecords = [];
    $accidentRecords = [];
    foreach (($mark['time_offs'] ?? []) as $timeOff) {
        if (!is_array($timeOff)) {
            continue;
        }
        $label = trim((string)($timeOff['type_description'] ?? $timeOff['TimeOffTypeDescription'] ?? '')) ?: 'Sin tipo';
        $record = [
            'external_id' => trim((string)($timeOff['external_id'] ?? $timeOff['Id'] ?? '')),
            'label' => $label,
        ];
        if (fte_monthly_is_accident_time_off($timeOff)) {
            $accidentRecords[] = $record;
        } elseif (fte_monthly_is_medical_leave_time_off($timeOff)) {
            $medicalRecords[] = $record;
        }
    }
    return [
        'has_medical_leave' => $medicalRecords !== [],
        'has_accident' => $accidentRecords !== [],
        'medical_records' => $medicalRecords,
        'accident_records' => $accidentRecords,
    ];
}

/**
 * Obtiene la evidencia diaria de vacaciones desde GeoVictoria.
 * Prioriza la duración explícita del TimeOff, luego HNT y solo usa media
 * jornada teórica como respaldo cuando el tipo declara que es parcial.
 */
function fte_monthly_vacation_evidence(array $mark, float $theoreticalHours): array
{
    $theoreticalHours = max(0.0, $theoreticalHours);
    $records = [];
    $labels = [];
    $explicitHours = 0.0;
    $declaresPartial = false;
    foreach (($mark['time_offs'] ?? []) as $timeOff) {
        if (!is_array($timeOff) || !fte_monthly_is_vacation_time_off($timeOff)) {
            continue;
        }
        $label = trim((string)($timeOff['type_description'] ?? $timeOff['TimeOffTypeDescription'] ?? '')) ?: 'Vacaciones';
        $normalizedLabel = fte_monthly_normalize_source_label($label);
        foreach (['PARCIAL', '1/2', 'MEDIA JORNADA', 'MEDIO DIA'] as $partialLabel) {
            if (str_contains($normalizedLabel, $partialLabel)) {
                $declaresPartial = true;
                break;
            }
        }
        $duration = max(0.0, fte_monthly_time_off_duration_hours($timeOff));
        $explicitHours += $duration;
        $labels[$label] = true;
        $records[] = [
            'external_id' => trim((string)($timeOff['external_id'] ?? $timeOff['Id'] ?? '')),
            'label' => $label,
            'duration_hours' => $duration,
        ];
    }
    if (!$records) {
        return [
            'has_vacation' => false,
            'is_partial' => false,
            'hours' => 0.0,
            'source' => 'SIN_REGISTRO_GEOVICTORIA',
            'labels' => [],
            'records' => [],
        ];
    }

    if ($explicitHours > 0.0) {
        $hours = min($theoreticalHours, $explicitHours);
        $source = 'GEOVICTORIA_TIME_OFF_DURATION';
    } else {
        $nonWorkedHours = min($theoreticalHours, max(0.0, (float)($mark['non_worked_hours'] ?? 0.0)));
        if ($nonWorkedHours > 0.0 && $nonWorkedHours < $theoreticalHours) {
            $hours = $nonWorkedHours;
            $source = 'GEOVICTORIA_HNT';
        } elseif ($declaresPartial) {
            $hours = $theoreticalHours / 2;
            $source = 'MEDIA_JORNADA_TEORICA_RESPALDO';
        } else {
            $hours = $theoreticalHours;
            $source = 'JORNADA_TEORICA_COMPLETA';
        }
    }
    $isPartial = $theoreticalHours > 0.0 && $hours + 0.000000001 < $theoreticalHours;

    return [
        'has_vacation' => true,
        'is_partial' => $isPartial,
        'hours' => max(0.0, $hours),
        'source' => $source,
        'labels' => array_keys($labels),
        'records' => $records,
    ];
}

function fte_monthly_time_off_labels(array $mark): array
{
    $labels = [];
    foreach (($mark['time_offs'] ?? []) as $timeOff) {
        if (!is_array($timeOff)) {
            continue;
        }
        $label = trim((string)($timeOff['type_description'] ?? $timeOff['TimeOffTypeDescription'] ?? ''));
        if ($label !== '') {
            $labels[$label] = true;
        }
    }
    return array_keys($labels);
}

function fte_monthly_clock_seconds($value): ?int
{
    $text = trim((string)$value);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $text, $match)) {
        return null;
    }
    $hours = (int)$match[1];
    $minutes = (int)$match[2];
    $seconds = (int)($match[3] ?? 0);
    if ($hours > 23 || $minutes > 59 || $seconds > 59) {
        return null;
    }
    return ($hours * 3600) + ($minutes * 60) + $seconds;
}

function fte_monthly_time_off_duration_hours(array $timeOff): float
{
    $amount = fte_duration_hours($timeOff['amount_hours'] ?? $timeOff['AmountHours'] ?? '');
    if ($amount > 0) {
        return $amount;
    }
    $start = fte_monthly_clock_seconds($timeOff['start_time'] ?? $timeOff['StartTime'] ?? '');
    $end = fte_monthly_clock_seconds($timeOff['end_time'] ?? $timeOff['EndTime'] ?? '');
    if ($start === null || $end === null || $end <= $start) {
        return 0.0;
    }
    return ($end - $start) / 3600;
}

function fte_monthly_permission_hours_for_mark(array $mark, float $theoreticalHours): array
{
    $dayPermissionRecords = [];
    $dayPermissionStatuses = [];
    $hourPermissionRequestedHours = 0.0;
    $hourPermissionRecords = [];
    foreach (($mark['time_offs'] ?? []) as $timeOff) {
        if (!is_array($timeOff)) {
            continue;
        }
        $kind = fte_monthly_time_off_kind($timeOff);
        if ($kind === 'PERMISO_DIA') {
            $status = fte_monthly_day_permission_pay_status($timeOff);
            $label = trim((string)($timeOff['type_description'] ?? $timeOff['TimeOffTypeDescription'] ?? '')) ?: 'Permiso sin tipo';
            $dayPermissionStatuses[$status] = true;
            $dayPermissionRecords[] = [
                'external_id' => trim((string)($timeOff['external_id'] ?? $timeOff['Id'] ?? '')),
                'label' => $label,
                'pay_status' => $status,
            ];
        } elseif ($kind === 'PERMISO_HORA') {
            $durationHours = fte_monthly_time_off_duration_hours($timeOff);
            $hourPermissionRequestedHours += $durationHours;
            $hourPermissionRecords[] = [
                'external_id' => trim((string)($timeOff['external_id'] ?? $timeOff['Id'] ?? '')),
                'label' => trim((string)($timeOff['type_description'] ?? $timeOff['TimeOffTypeDescription'] ?? '')) ?: 'Permiso por hora',
                'duration_hours' => $durationHours,
                'amount_hours' => trim((string)($timeOff['amount_hours'] ?? $timeOff['AmountHours'] ?? '')),
                'start_time' => trim((string)($timeOff['start_time'] ?? $timeOff['StartTime'] ?? '')),
                'end_time' => trim((string)($timeOff['end_time'] ?? $timeOff['EndTime'] ?? '')),
            ];
        }
    }
    $theoreticalHours = max(0.0, $theoreticalHours);
    $nonWorkedAvailable = array_key_exists('non_worked_hours', $mark);
    $nonWorkedHours = min($theoreticalHours, max(0.0, (float)($mark['non_worked_hours'] ?? 0.0)));
    // AmountHours describe el permiso autorizado, no necesariamente la
    // perdida efectiva. Cuando GeoVictoria entrega HNT, el FTE usa esa
    // perdida real y la limita por la duracion autorizada para no inflarla.
    $hourMeasuredHours = $nonWorkedAvailable
        ? min($hourPermissionRequestedHours, $nonWorkedHours)
        : $hourPermissionRequestedHours;
    $hourCandidateHours = min($theoreticalHours, max(0.0, $hourMeasuredHours));
    $hourMeasurementSource = $nonWorkedAvailable
        ? 'GEOVICTORIA_NON_WORKED_HOURS_LIMITADO_POR_PERMISO'
        : 'DURACION_PERMISO_SIN_HNT_DISPONIBLE';
    if ($dayPermissionRecords) {
        $statuses = array_keys($dayPermissionStatuses);
        $dayStatus = count($statuses) === 1 ? $statuses[0] : 'PENDIENTE';
        return [
            'day_hours' => $dayStatus === 'SIN_GOCE' ? $theoreticalHours : 0.0,
            'hour_hours' => 0.0,
            'paid_day_hours' => $dayStatus === 'CON_GOCE' ? $theoreticalHours : 0.0,
            'pending_day_hours' => $dayStatus === 'PENDIENTE' ? $theoreticalHours : 0.0,
            'day_status' => $dayStatus,
            'day_records' => $dayPermissionRecords,
            'hour_candidate_hours' => $hourCandidateHours,
            'hour_requested_hours' => min($theoreticalHours, max(0.0, $hourPermissionRequestedHours)),
            'hour_non_worked_hours' => $nonWorkedHours,
            'hour_measurement_source' => $hourMeasurementSource,
            'hour_records' => $hourPermissionRecords,
        ];
    }
    return [
        'day_hours' => 0.0,
        'hour_hours' => $hourCandidateHours,
        'paid_day_hours' => 0.0,
        'pending_day_hours' => 0.0,
        'day_status' => 'NO_APLICA',
        'day_records' => [],
        'hour_candidate_hours' => $hourCandidateHours,
        'hour_requested_hours' => min($theoreticalHours, max(0.0, $hourPermissionRequestedHours)),
        'hour_non_worked_hours' => $nonWorkedHours,
        'hour_measurement_source' => $hourMeasurementSource,
        'hour_records' => $hourPermissionRecords,
    ];
}

/** Regla auditable para consolidar fallas, atrasos y salidas anticipadas. */
function fte_monthly_failure_delay_policy(array $overrides = []): array
{
    $policy = array_merge([
        'policy_version' => 2,
        'short_delay_threshold_minutes' => 5.0,
        'short_delay_required_compensation_minutes' => 15.0,
        'enforce_short_delay_minimum' => true,
        'include_full_failures' => false,
        'include_partial_non_worked' => true,
        'include_early_leave' => true,
    ], $overrides);
    $policy['policy_version'] = max(1, (int)$policy['policy_version']);
    $policy['short_delay_threshold_minutes'] = max(0.0, (float)$policy['short_delay_threshold_minutes']);
    $policy['short_delay_required_compensation_minutes'] = max(0.0, (float)$policy['short_delay_required_compensation_minutes']);
    foreach (['enforce_short_delay_minimum', 'include_full_failures', 'include_partial_non_worked', 'include_early_leave'] as $key) {
        $policy[$key] = filter_var($policy[$key], FILTER_VALIDATE_BOOLEAN);
    }
    return $policy;
}

/** Regla auditable para horas extra efectivamente realizadas. */
function fte_monthly_overtime_policy(array $overrides = []): array
{
    $policy = array_merge([
        'policy_version' => 1,
        'source_field' => 'AccomplishedExtraTime',
        'allowed_iso_weekdays' => [1, 2, 3, 4, 5],
        'exclude_disallowed_weekdays' => true,
    ], $overrides);
    $rawDays = $policy['allowed_iso_weekdays'] ?? [];
    if (is_string($rawDays)) {
        $rawDays = explode(',', $rawDays);
    }
    $allowedDays = [];
    foreach (is_array($rawDays) ? $rawDays : [] as $day) {
        $day = (int)$day;
        if ($day >= 1 && $day <= 7) {
            $allowedDays[$day] = true;
        }
    }
    $policy['policy_version'] = max(1, (int)$policy['policy_version']);
    $policy['source_field'] = trim((string)$policy['source_field']) ?: 'AccomplishedExtraTime';
    $policy['allowed_iso_weekdays'] = array_map('intval', array_keys($allowedDays ?: array_fill_keys([1, 2, 3, 4, 5], true)));
    sort($policy['allowed_iso_weekdays'], SORT_NUMERIC);
    $policy['exclude_disallowed_weekdays'] = filter_var(
        $policy['exclude_disallowed_weekdays'],
        FILTER_VALIDATE_BOOLEAN
    );
    return $policy;
}

function fte_monthly_overtime_hours_for_mark(
    array $mark,
    string $date,
    array $policyOverrides = []
): array {
    $policy = fte_monthly_overtime_policy($policyOverrides);
    $accomplishedHours = max(0.0, (float)($mark['accomplished_overtime_hours'] ?? 0.0));
    $authorizedReferenceHours = max(0.0, (float)($mark['authorized_overtime_hours'] ?? 0.0));
    $accomplishedAvailable = array_key_exists('accomplished_overtime_available', $mark)
        ? (bool)$mark['accomplished_overtime_available']
        : array_key_exists('accomplished_overtime_hours', $mark);
    $conflictTypes = [];
    $messages = [];
    try {
        $isoWeekday = (int)(new DateTimeImmutable($date))->format('N');
    } catch (Throwable $exception) {
        $isoWeekday = 0;
        $conflictTypes[] = 'FECHA_HORAS_EXTRA_INVALIDA';
        $messages[] = 'La fecha de las horas extra no es valida; no se sumaron horas.';
    }
    $allowed = $isoWeekday > 0 && in_array($isoWeekday, $policy['allowed_iso_weekdays'], true);
    $excludedByWeekday = $policy['exclude_disallowed_weekdays'] && !$allowed;
    $appliedHours = $excludedByWeekday ? 0.0 : $accomplishedHours;
    if (!$accomplishedAvailable && $authorizedReferenceHours > 0.0) {
        $conflictTypes[] = 'HORAS_REALIZADAS_NO_INFORMADAS';
        $messages[] = 'GeoVictoria informa horas autorizadas, pero no entrego AccomplishedExtraTime; no se inventaron horas realizadas.';
    }
    return [
        'policy' => $policy,
        'source_field' => $policy['source_field'],
        'iso_weekday' => $isoWeekday,
        'weekday_allowed' => $allowed,
        'accomplished_available' => $accomplishedAvailable,
        'accomplished_hours' => $accomplishedHours,
        'authorized_reference_hours' => $authorizedReferenceHours,
        'applied_hours' => $appliedHours,
        'excluded_hours' => max(0.0, $accomplishedHours - $appliedHours),
        'excluded_by_weekday' => $excludedByWeekday && $accomplishedHours > 0.0,
        'conflict_type' => $conflictTypes[0] ?? '',
        'conflict_types' => array_values(array_unique($conflictTypes)),
        'message' => implode(' ', array_values(array_unique($messages))),
    ];
}

/**
 * Resuelve una sola pérdida diaria. NonWorkedHours puede contener atraso o
 * salida anticipada; por eso se usa el mayor entre ese agregado y la suma de
 * puntualidad, en lugar de sumar ambos y duplicar minutos.
 */
function fte_monthly_failure_delay_hours_for_mark(
    array $mark,
    float $theoreticalHours,
    bool $hasJustifiedAbsence,
    array $policyOverrides = []
): array {
    $policy = fte_monthly_failure_delay_policy($policyOverrides);
    $theoreticalHours = max(0.0, $theoreticalHours);
    $rawDelayHours = max(0.0, (float)($mark['delay_raw_hours'] ?? $mark['delay_hours'] ?? 0.0));
    $delayAfterCompensationHours = max(0.0, (float)(
        $mark['delay_after_compensation_hours'] ?? $mark['delay_hours'] ?? $rawDelayHours
    ));
    $delayCompensationAvailable = array_key_exists('delay_after_compensation_available', $mark)
        ? (bool)$mark['delay_after_compensation_available']
        : array_key_exists('delay_after_compensation_hours', $mark);
    $rawEarlyLeaveHours = max(0.0, (float)($mark['early_leave_raw_hours'] ?? $mark['early_leave_hours'] ?? 0.0));
    $earlyLeaveAfterCompensationHours = max(0.0, (float)(
        $mark['early_leave_after_compensation_hours'] ?? $mark['early_leave_hours'] ?? $rawEarlyLeaveHours
    ));
    $earlyLeaveCompensationAvailable = array_key_exists('early_leave_after_compensation_available', $mark)
        ? (bool)$mark['early_leave_after_compensation_available']
        : array_key_exists('early_leave_after_compensation_hours', $mark);
    $nonWorkedHours = max(0.0, (float)($mark['non_worked_hours'] ?? 0.0));
    $shortDelayApplies = $rawDelayHours > 0.0
        && ($rawDelayHours * 60.0) <= $policy['short_delay_threshold_minutes'] + 0.0000001;
    $shortDelayMinimumApplied = $policy['enforce_short_delay_minimum']
        && $shortDelayApplies
        && $delayAfterCompensationHours > 0.0;
    $effectiveDelayHours = $shortDelayMinimumApplied
        ? max($delayAfterCompensationHours, $policy['short_delay_required_compensation_minutes'] / 60.0)
        : $delayAfterCompensationHours;
    $effectiveEarlyLeaveHours = $policy['include_early_leave'] ? $earlyLeaveAfterCompensationHours : 0.0;
    $partialNonWorkedHours = $policy['include_partial_non_worked'] ? $nonWorkedHours : 0.0;
    $punchCount = max(0, (int)($mark['punch_count'] ?? 0));
    $presentKnown = array_key_exists('present', $mark);
    $present = (bool)($mark['present'] ?? false);
    $scheduled = (bool)($mark['scheduled_interval'] ?? false);
    $unconfirmedNoPunch = $theoreticalHours > 0.0
        && $scheduled
        && $presentKnown
        && !$present
        && $punchCount === 0;
    $fullFailure = $policy['include_full_failures'] && $unconfirmedNoPunch;
    $punctualityHours = $effectiveDelayHours + $effectiveEarlyLeaveHours;
    $consolidatedPartialHours = max($partialNonWorkedHours, $punctualityHours);
    $overlapAvoidedHours = max(0.0, $partialNonWorkedHours + $punctualityHours - $consolidatedPartialHours);
    $conflictTypes = [];
    $conflictMessages = [];
    if ($rawDelayHours > 0.0 && !$delayCompensationAvailable) {
        $conflictTypes[] = 'COMPENSACION_ATRASO_NO_INFORMADA';
        $conflictMessages[] = 'GeoVictoria no informó el valor posterior a compensación; se utilizó el atraso bruto como respaldo.';
    }
    if ($rawEarlyLeaveHours > 0.0 && !$earlyLeaveCompensationAvailable) {
        $conflictTypes[] = 'COMPENSACION_SALIDA_NO_INFORMADA';
        $conflictMessages[] = 'GeoVictoria no informó la salida posterior a compensación; se utilizó el valor bruto como respaldo.';
    }
    $incompleteWithoutMeasure = $presentKnown
        && $present
        && array_key_exists('complete', $mark)
        && !$mark['complete']
        && $consolidatedPartialHours <= 0.0;
    if ($incompleteWithoutMeasure && !$hasJustifiedAbsence) {
        $conflictTypes[] = 'MARCACION_INCOMPLETA_SIN_PERDIDA_CUANTIFICABLE';
        $conflictMessages[] = 'Existe una marcación incompleta sin horas de pérdida cuantificables; no se inventó un descuento.';
    }
    if ($unconfirmedNoPunch && !$policy['include_full_failures'] && !$hasJustifiedAbsence) {
        $conflictTypes[] = 'JORNADA_SIN_MARCACION_REQUIERE_CONFIRMACION';
        $conflictMessages[] = 'La jornada programada no tiene marcaciones, pero GeoVictoria no confirma por si solo una falta injustificada; queda pendiente sin descuento.';
    }

    if ($hasJustifiedAbsence) {
        $appliedHours = 0.0;
        $resolution = 'OMITIDO_POR_AUSENCIA_O_PERMISO_JUSTIFICADO';
        $basis = 'JUSTIFICADO';
    } elseif ($fullFailure) {
        $appliedHours = $theoreticalHours;
        $resolution = 'FALLA_COMPLETA_SIN_MARCACION';
        $basis = 'JORNADA_TEORICA';
    } elseif ($unconfirmedNoPunch && !$policy['include_full_failures']) {
        $appliedHours = 0.0;
        $resolution = 'PENDIENTE_CONFIRMACION_SIN_DESCUENTO';
        $basis = 'SIN_MARCACION_NO_CONFIRMADA';
    } else {
        $appliedHours = min($theoreticalHours, max(0.0, $consolidatedPartialHours));
        if ($appliedHours <= 0.0) {
            $resolution = $incompleteWithoutMeasure ? 'PENDIENTE_REVISION_SIN_DESCUENTO' : 'SIN_PERDIDA_APLICABLE';
            $basis = 'NINGUNA';
        } elseif ($partialNonWorkedHours >= $punctualityHours) {
            $resolution = 'FALLA_PARCIAL_HORAS_NO_TRABAJADAS';
            $basis = 'NON_WORKED_HOURS';
        } else {
            $resolution = 'ATRASO_Y_SALIDA_POST_COMPENSACION';
            $basis = 'ATRASO_MAS_SALIDA';
        }
    }

    return [
        'policy' => $policy,
        'applied_hours' => $appliedHours,
        'resolution' => $resolution,
        'basis' => $basis,
        'full_failure' => $fullFailure,
        'unconfirmed_no_punch' => $unconfirmedNoPunch && !$hasJustifiedAbsence,
        'pending_full_failure_hours' => $unconfirmedNoPunch && !$hasJustifiedAbsence && !$policy['include_full_failures']
            ? $theoreticalHours
            : 0.0,
        'has_justification' => $hasJustifiedAbsence,
        'raw_delay_hours' => $rawDelayHours,
        'delay_after_compensation_hours' => $delayAfterCompensationHours,
        'delay_compensated_hours' => max(0.0, $rawDelayHours - $delayAfterCompensationHours),
        'effective_delay_hours' => $effectiveDelayHours,
        'short_delay_policy_applies' => $shortDelayApplies,
        'short_delay_minimum_applied' => $shortDelayMinimumApplied,
        'raw_early_leave_hours' => $rawEarlyLeaveHours,
        'early_leave_after_compensation_hours' => $earlyLeaveAfterCompensationHours,
        'early_leave_compensated_hours' => max(0.0, $rawEarlyLeaveHours - $earlyLeaveAfterCompensationHours),
        'effective_early_leave_hours' => $effectiveEarlyLeaveHours,
        'non_worked_hours' => $nonWorkedHours,
        'punctuality_hours' => $punctualityHours,
        'consolidated_partial_hours' => $consolidatedPartialHours,
        'overlap_avoided_hours' => $overlapAvoidedHours,
        'conflict_type' => $conflictTypes[0] ?? '',
        'conflict_types' => array_values(array_unique($conflictTypes)),
        'message' => implode(' ', array_values(array_unique($conflictMessages))),
    ];
}

function fte_monthly_summarize_permissions(array $attendance): array
{
    $summary = [
        'status' => 'CALCULADO_PRELIMINAR',
        'time_off_records' => 0,
        'classified_permission_records' => 0,
        'day_permission_records' => 0,
        'day_permission_with_pay_records' => 0,
        'day_permission_without_pay_records' => 0,
        'day_permission_pending_records' => 0,
        'hour_permission_records' => 0,
        'hour_permission_records_without_duration' => 0,
        'vacation_records' => 0,
        'medical_leave_records' => 0,
        'accident_records' => 0,
        'other_time_off_records' => 0,
        'types' => [],
    ];
    $seen = [];
    foreach ($attendance as $identifier => $byDate) {
        if (!is_array($byDate)) {
            continue;
        }
        foreach ($byDate as $date => $mark) {
            if (!is_array($mark)) {
                continue;
            }
            foreach (($mark['time_offs'] ?? []) as $timeOff) {
                if (!is_array($timeOff)) {
                    continue;
                }
                $externalId = trim((string)($timeOff['external_id'] ?? ''));
                $dedupeKey = $externalId !== ''
                    ? (string)$identifier . '|' . (string)$date . '|' . $externalId
                    : hash('sha256', (string)$identifier . '|' . (string)$date . '|' . json_encode($timeOff));
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $summary['time_off_records']++;
                $kind = fte_monthly_time_off_kind($timeOff);
                $dayPayStatus = fte_monthly_day_permission_pay_status($timeOff);
                $isVacation = fte_monthly_is_vacation_time_off($timeOff);
                $isMedicalLeave = fte_monthly_is_medical_leave_time_off($timeOff);
                $isAccident = fte_monthly_is_accident_time_off($timeOff);
                $label = trim((string)($timeOff['type_description'] ?? '')) ?: 'Sin tipo';
                if (!isset($summary['types'][$label])) {
                    $classification = $kind === 'PERMISO_DIA'
                        ? 'PERMISO_DIA_' . $dayPayStatus
                        : ($kind ?: ($isVacation
                            ? 'VACACIONES'
                            : ($isAccident ? 'ACCIDENTE' : ($isMedicalLeave ? 'LICENCIA_MEDICA' : 'OTRO'))));
                    $summary['types'][$label] = [
                        'type' => $label,
                        'classification' => $classification,
                        'records' => 0,
                    ];
                }
                $summary['types'][$label]['records']++;
                if ($kind === 'PERMISO_DIA') {
                    $summary['classified_permission_records']++;
                    $summary['day_permission_records']++;
                    if ($dayPayStatus === 'CON_GOCE') {
                        $summary['day_permission_with_pay_records']++;
                    } elseif ($dayPayStatus === 'SIN_GOCE') {
                        $summary['day_permission_without_pay_records']++;
                    } else {
                        $summary['day_permission_pending_records']++;
                    }
                } elseif ($kind === 'PERMISO_HORA') {
                    $summary['classified_permission_records']++;
                    $summary['hour_permission_records']++;
                    if (fte_monthly_time_off_duration_hours($timeOff) <= 0) {
                        $summary['hour_permission_records_without_duration']++;
                    }
                } elseif ($isVacation) {
                    $summary['vacation_records']++;
                } elseif ($isAccident) {
                    $summary['accident_records']++;
                } elseif ($isMedicalLeave) {
                    $summary['medical_leave_records']++;
                } else {
                    $summary['other_time_off_records']++;
                }
            }
        }
    }
    ksort($summary['types'], SORT_NATURAL | SORT_FLAG_CASE);
    $summary['types'] = array_values($summary['types']);
    return $summary;
}

function fte_monthly_quality_worker_rows(array $people, array $identifiers): array
{
    $wanted = [];
    foreach ($identifiers as $identifier) {
        $normalized = fte_normalize_identifier($identifier);
        if ($normalized !== '') {
            $wanted[$normalized] = true;
        }
    }
    if (!$wanted) {
        return [];
    }

    $rows = [];
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $normalized = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($normalized === '' || !isset($wanted[$normalized])) {
            continue;
        }
        $rows[] = [
            'identifier' => trim((string)($person['identifier'] ?? $normalized)),
            'person_name' => trim((string)($person['person_name'] ?? '')) ?: 'Sin nombre informado',
            'cost_center_code' => fte_normalize_cost_center($person['cost_center_code'] ?? ''),
            'cost_center_name' => trim((string)($person['cost_center_name'] ?? '')),
        ];
        unset($wanted[$normalized]);
    }
    foreach (array_keys($wanted) as $normalized) {
        $rows[] = [
            'identifier' => $normalized,
            'person_name' => 'Sin correspondencia en la nomina consultada',
            'cost_center_code' => '',
            'cost_center_name' => '',
        ];
    }
    usort($rows, static fn(array $left, array $right): int =>
        strcasecmp((string)$left['person_name'], (string)$right['person_name'])
        ?: strcmp((string)$left['identifier'], (string)$right['identifier']));
    return $rows;
}

function fte_monthly_build_data_quality(
    array $people,
    array $requestedIdentifiers,
    array $attendanceDiagnostics,
    array $permissionSummary,
    array $sourceCoverage,
    bool $includeAttendance
): array {
    $successful = array_values(array_unique(array_filter(array_map(
        'fte_normalize_identifier',
        $attendanceDiagnostics['successful_identifiers'] ?? []
    ))));
    $failed = array_values(array_unique(array_filter(array_map(
        'fte_normalize_identifier',
        $attendanceDiagnostics['failed_identifiers'] ?? []
    ))));
    $unmatched = array_values(array_unique(array_filter(array_map(
        'fte_normalize_identifier',
        $attendanceDiagnostics['unmatched_identifiers'] ?? []
    ))));
    $identity = is_array($attendanceDiagnostics['identity'] ?? null)
        ? $attendanceDiagnostics['identity']
        : [];
    $excludedWorkers = is_array($identity['excluded_workers'] ?? null) ? $identity['excluded_workers'] : [];
    $attendanceExcludedWorkers = is_array($attendanceDiagnostics['attendance_excluded_workers'] ?? null)
        ? $attendanceDiagnostics['attendance_excluded_workers']
        : [];
    $identityIssueCount = (int)($identity['missing_identifier_records'] ?? 0)
        + count($identity['ambiguous_aliases'] ?? []);
    $requested = array_values(array_unique(array_filter(array_map('fte_normalize_identifier', $requestedIdentifiers))));
    $matched = array_values(array_diff($successful, $failed, $unmatched));

    $unclassifiedCategories = [];
    $pendingDayPermissionCategories = [];
    foreach (($permissionSummary['types'] ?? []) as $type) {
        if (!is_array($type) || (int)($type['records'] ?? 0) < 1) {
            continue;
        }
        $category = [
            'type' => trim((string)($type['type'] ?? '')) ?: 'Sin tipo informado',
            'records' => (int)$type['records'],
        ];
        if (($type['classification'] ?? '') === 'OTRO') {
            $unclassifiedCategories[] = $category;
        } elseif (($type['classification'] ?? '') === 'PERMISO_DIA_PENDIENTE') {
            $pendingDayPermissionCategories[] = $category;
        }
    }

    $unavailableSources = [];
    $notLoadedSources = [];
    $partialSources = [];
    foreach ($sourceCoverage as $source) {
        if (!is_array($source)) {
            continue;
        }
        $item = [
            'component' => trim((string)($source['component'] ?? '')),
            'source' => trim((string)($source['source'] ?? '')),
        ];
        $status = strtoupper(trim((string)($source['status'] ?? '')));
        if ($status === 'NO_DISPONIBLE') {
            $unavailableSources[] = $item;
        } elseif ($status === 'NO_CARGADO') {
            $notLoadedSources[] = $item;
        } elseif (str_contains($status, 'PARCIAL')) {
            $partialSources[] = $item;
        }
    }

    $status = 'COMPLETA_PRELIMINAR';
    if ($unavailableSources) {
        $status = 'FUENTES_NO_DISPONIBLES';
    } elseif ($failed || $unmatched || $attendanceExcludedWorkers || $unclassifiedCategories || $pendingDayPermissionCategories || $partialSources || $identityIssueCount > 0) {
        $status = 'PARCIAL_REQUIERE_REVISION';
    } elseif (!$includeAttendance || $notLoadedSources) {
        $status = 'FUENTES_NO_CARGADAS';
    }
    $labels = [
        'COMPLETA_PRELIMINAR' => 'Completa para revision preliminar',
        'PARCIAL_REQUIERE_REVISION' => 'Parcial: requiere revision',
        'FUENTES_NO_DISPONIBLES' => 'Incompleta: una fuente no respondio',
        'FUENTES_NO_CARGADAS' => 'Incompleta: existen fuentes no cargadas',
    ];

    return [
        'status' => $status,
        'status_label' => $labels[$status],
        'workers_requested' => count($requested),
        'workers_matched' => count($matched),
        'workers_not_found_count' => count($unmatched),
        'workers_failed_count' => count($failed),
        'workers_excluded_count' => count($excludedWorkers),
        'attendance_excluded_count' => count($attendanceExcludedWorkers),
        'workers_not_found' => fte_monthly_quality_worker_rows($people, $unmatched),
        'workers_failed' => fte_monthly_quality_worker_rows($people, $failed),
        'workers_excluded' => $excludedWorkers,
        'attendance_excluded_workers' => $attendanceExcludedWorkers,
        'identity_duplicate_records_merged' => (int)($identity['duplicate_records_merged'] ?? 0),
        'identity_ambiguous_alias_count' => count($identity['ambiguous_aliases'] ?? []),
        'identity_missing_identifier_records' => (int)($identity['missing_identifier_records'] ?? 0),
        'identity_source_records' => (int)($identity['source_records'] ?? count($people)),
        'identity_unified_workers' => (int)($identity['unified_workers'] ?? count($people)),
        'identity_ambiguous_aliases' => $identity['ambiguous_aliases'] ?? [],
        'identity_duplicate_identifiers' => $identity['duplicate_identifiers'] ?? [],
        'identity_policy_version' => (int)($identity['policy_version'] ?? 0),
        'unclassified_category_count' => count($unclassifiedCategories),
        'unclassified_record_count' => array_sum(array_column($unclassifiedCategories, 'records')),
        'unclassified_categories' => $unclassifiedCategories,
        'pending_day_permission_category_count' => count($pendingDayPermissionCategories),
        'pending_day_permission_record_count' => array_sum(array_column($pendingDayPermissionCategories, 'records')),
        'pending_day_permission_categories' => $pendingDayPermissionCategories,
        'unavailable_sources' => $unavailableSources,
        'not_loaded_sources' => $notLoadedSources,
        'partial_sources' => $partialSources,
    ];
}

function fte_monthly_metric_availability(
    bool $vacationsAvailable,
    bool $licencesAvailable,
    string $attendanceStatus,
    bool $hasUnclassifiedCategories
): array {
    $attendanceAvailability = match ($attendanceStatus) {
        'DISPONIBLE' => 'PRELIMINAR',
        'PARCIAL' => 'PARCIAL',
        'NO_CARGADO' => 'NO_CARGADO',
        default => 'NO_DISPONIBLE',
    };
    $vacationAvailability = $vacationsAvailable ? 'DISPONIBLE' : 'NO_DISPONIBLE';
    $licenceAvailability = $licencesAvailable ? 'DISPONIBLE' : 'NO_DISPONIBLE';
    $derivedAvailability = $vacationsAvailable
        && $licencesAvailable
        && $attendanceStatus === 'DISPONIBLE'
        && !$hasUnclassifiedCategories
            ? 'PRELIMINAR'
            : 'PRELIMINAR_PARCIAL';

    return [
        'headcount' => 'PRELIMINAR',
        'theoretical_headcount_hours' => 'DISPONIBLE',
        'effective_theoretical_hours' => 'DISPONIBLE',
        'employment_entry_hours' => 'PRELIMINAR',
        'employment_exit_hours' => 'PRELIMINAR',
        'employment_movement_hours' => 'PRELIMINAR',
        'vacation_hours' => $vacationAvailability,
        'medical_leave_hours' => $licenceAvailability,
        'accident_hours' => $licenceAvailability,
        'day_permission_hours' => $attendanceAvailability,
        'hour_permission_hours' => $attendanceAvailability,
        'failure_delay_hours' => $attendanceAvailability,
        'authorized_overtime_hours' => $attendanceAvailability,
        'delay_after_compensation_hours' => $attendanceAvailability,
        'early_leave_after_compensation_hours' => $attendanceAvailability,
        'non_worked_hours' => $attendanceAvailability,
        'lost_hours' => $derivedAvailability,
        'adjusted_hours' => $derivedAvailability,
        'fte' => $derivedAvailability,
        'headcount_fte_gap' => $derivedAvailability,
        'fte_to_headcount_rate' => $derivedAvailability,
        'unavailable_hours_rate' => $derivedAvailability,
    ];
}

/**
 * Suma la jornada teorica solo durante la vigencia laboral real de cada persona.
 * La cifra bruta (dotacion mensual x jornada completa) se conserva aparte para
 * conciliar el Excel; esta cifra proporcional es la base efectiva del FTE.
 */
function fte_monthly_effective_theoretical_hours(array $people, array $dateHours, array $availableCodes): array
{
    $normalizedCodes = array_values(array_unique(array_filter(array_map(
        'fte_normalize_cost_center',
        $availableCodes
    ))));
    $hoursByCode = array_fill_keys($normalizedCodes, 0.0);
    $personHours = [];

    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '') {
            continue;
        }
        foreach ($dateHours as $date => $rawHours) {
            $hours = max(0.0, (float)$rawHours);
            if ($hours <= 0 || !fte_headcount_person_active_on($person, (string)$date)) {
                continue;
            }
            $job = fte_headcount_job_on_date($person, (string)$date);
            $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
            if ($code === '') {
                $code = 'SIN_CECO';
            }
            if (!array_key_exists($code, $hoursByCode)) {
                continue;
            }
            $hoursByCode[$code] += $hours;
            $personHours[$identifier] = ($personHours[$identifier] ?? 0.0) + $hours;
        }
    }

    return [
        'hours_by_cost_center' => $hoursByCode,
        'total_hours' => array_sum($hoursByCode),
        'person_hours' => $personHours,
    ];
}

/**
 * Calcula ingresos/salidas solo con las fechas reales de vinculacion laboral.
 * Los cambios o huecos entre cargos se diagnostican, pero nunca descuentan horas.
 */
function fte_monthly_employment_movement_hours(array $people, array $dateHours, array $availableCodes): array
{
    $dates = array_keys($dateHours);
    sort($dates, SORT_STRING);
    $hoursByCode = array_fill_keys(array_values(array_unique(array_map('fte_normalize_cost_center', $availableCodes))), 0.0);
    $entryHoursByCode = array_fill_keys(array_keys($hoursByCode), 0.0);
    $exitHoursByCode = array_fill_keys(array_keys($hoursByCode), 0.0);
    $events = [];
    $issues = [];
    $diagnostics = [
        'entry_events' => 0,
        'exit_events' => 0,
        'ceco_changes_at_month_start' => 0,
        'mid_month_job_changes_ignored' => 0,
        'job_intervals_ignored' => 0,
        'unassigned_employment_events' => 0,
        'invalid_employment_ranges' => 0,
        'unverified_employment_date_records' => 0,
    ];
    if (!$dates) {
        return [
            'hours_by_cost_center' => $hoursByCode,
            'entry_hours_by_cost_center' => $entryHoursByCode,
            'exit_hours_by_cost_center' => $exitHoursByCode,
            'entry_events' => [],
            'exit_events' => [],
            'events' => $events,
            'issues' => $issues,
            'diagnostics' => $diagnostics,
        ];
    }

    $monthStart = substr($dates[0], 0, 7) . '-01';
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = trim((string)($person['identifier'] ?? $person['normalized_identifier'] ?? ''));
        $personName = trim((string)($person['person_name'] ?? '')) ?: 'Sin nombre informado';
        $rawEmploymentPeriods = is_array($person['employment_periods'] ?? null)
            ? $person['employment_periods']
            : [];
        if (!$rawEmploymentPeriods) {
            $rawEmploymentPeriods[] = [
                'start_date' => $person['active_since'] ?? null,
                'end_date' => $person['active_until'] ?? null,
                'start_source' => $person['employment_start_source'] ?? 'Buk employee.active_since',
                'end_source' => $person['employment_end_source'] ?? 'Buk employee.active_until',
                'verified' => ($person['employment_dates_verified'] ?? true) === true,
            ];
        }
        $employmentPeriods = [];
        $employmentStarts = [];
        $employmentEnds = [];
        $hasUnverifiedPeriod = false;
        foreach ($rawEmploymentPeriods as $period) {
            if (!is_array($period)) {
                continue;
            }
            if (($period['verified'] ?? ($person['employment_dates_verified'] ?? true)) !== true) {
                $hasUnverifiedPeriod = true;
                continue;
            }
            $employmentStart = fte_headcount_date($period['start_date'] ?? null);
            $employmentEnd = fte_headcount_date($period['end_date'] ?? null);
            if ($employmentStart !== null && $employmentEnd !== null && $employmentStart > $employmentEnd) {
                $diagnostics['invalid_employment_ranges']++;
                $issues[] = [
                    'type' => 'INVALID_EMPLOYMENT_RANGE',
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $employmentStart,
                    'message' => 'La fecha real de ingreso es posterior a la fecha real de termino.',
                ];
                continue;
            }
            $employmentPeriods[] = [
                'start_date' => $employmentStart,
                'end_date' => $employmentEnd,
                'start_source' => (string)($period['start_source'] ?? 'Buk employee.active_since'),
                'end_source' => (string)($period['end_source'] ?? 'Buk employee.active_until'),
            ];
            if ($employmentStart !== null) {
                $employmentStarts[$employmentStart] = true;
            }
            if ($employmentEnd !== null) {
                $employmentEnds[$employmentEnd] = true;
            }
        }
        if ($hasUnverifiedPeriod || (($person['employment_dates_verified'] ?? true) !== true)) {
            $diagnostics['unverified_employment_date_records']++;
        }

        foreach (($person['jobs'] ?? []) as $job) {
            if (!is_array($job)) {
                continue;
            }
            $jobStart = fte_headcount_date($job['start_date'] ?? null);
            $jobEnd = fte_headcount_date($job['end_date'] ?? null);
            if ($jobStart !== null && $jobStart >= $monthStart && $jobStart <= $monthEnd
                && !isset($employmentStarts[$jobStart])) {
                $diagnostics['job_intervals_ignored']++;
                if ($jobStart > $monthStart) {
                    $diagnostics['mid_month_job_changes_ignored']++;
                    $issues[] = [
                        'type' => 'MID_MONTH_CECO_CHANGE',
                        'identifier' => $identifier,
                        'person_name' => $personName,
                        'date' => $jobStart,
                        'cost_center_code' => fte_normalize_cost_center($job['cost_center_code'] ?? ''),
                        'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                        'message' => 'El cambio de cargo o CECO no comienza el primer dia del mes.',
                    ];
                } else {
                    $diagnostics['ceco_changes_at_month_start']++;
                }
            }
            if ($jobEnd !== null && $jobEnd >= $monthStart && $jobEnd < $monthEnd
                && !isset($employmentEnds[$jobEnd])) {
                $diagnostics['job_intervals_ignored']++;
            }
        }

        if (!$employmentPeriods) {
            continue;
        }

        $entryEvents = [];
        $exitEvents = [];
        foreach ($employmentPeriods as $period) {
            $employmentStart = $period['start_date'];
            $employmentEnd = $period['end_date'];
            if ($employmentStart !== null && $employmentStart >= $monthStart && $employmentStart <= $monthEnd) {
                $entryEvents[$employmentStart] = [
                    'kind' => 'entry',
                    'date' => $employmentStart,
                    'hours' => 0.0,
                    'source' => $period['start_source'],
                ];
            }
            if ($employmentEnd !== null && $employmentEnd >= $monthStart && $employmentEnd <= $monthEnd) {
                $exitEvents[$employmentEnd] = [
                    'kind' => 'exit',
                    'date' => $employmentEnd,
                    'hours' => 0.0,
                    'source' => $period['end_source'],
                ];
            }
        }

        foreach ($dateHours as $date => $theoreticalHours) {
            $isEmployed = false;
            foreach ($employmentPeriods as $period) {
                if (($period['start_date'] === null || $period['start_date'] <= $date)
                    && ($period['end_date'] === null || $period['end_date'] >= $date)) {
                    $isEmployed = true;
                    break;
                }
            }
            if ($isEmployed) {
                continue;
            }
            $hours = max(0.0, (float)$theoreticalHours);
            $futureEntries = array_values(array_filter(array_keys($entryEvents), static fn(string $entryDate): bool => $entryDate > $date));
            sort($futureEntries, SORT_STRING);
            if ($futureEntries) {
                $entryEvents[$futureEntries[0]]['hours'] += $hours;
                continue;
            }
            $pastExits = array_values(array_filter(array_keys($exitEvents), static fn(string $exitDate): bool => $exitDate < $date));
            rsort($pastExits, SORT_STRING);
            if ($pastExits) {
                $exitEvents[$pastExits[0]]['hours'] += $hours;
            }
        }
        $personEvents = array_merge(array_values($entryEvents), array_values($exitEvents));

        foreach ($personEvents as $event) {
            $job = fte_headcount_job_on_date($person, $event['date']);
            $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
            if ($code === '') {
                $diagnostics['unassigned_employment_events']++;
                $issues[] = [
                    'type' => 'UNASSIGNED_EMPLOYMENT_EVENT',
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $event['date'],
                    'message' => 'El ingreso o termino real no tiene un CECO asignable en su fecha.',
                ];
                continue;
            }
            if (!array_key_exists($code, $hoursByCode)) {
                continue;
            }
            $hoursByCode[$code] += (float)$event['hours'];
            if ($event['kind'] === 'entry') {
                $entryHoursByCode[$code] += (float)$event['hours'];
            } else {
                $exitHoursByCode[$code] += (float)$event['hours'];
            }
            $diagnostics[$event['kind'] . '_events']++;
            $events[] = [
                'kind' => strtoupper((string)$event['kind']),
                'identifier' => $identifier,
                'rut' => $identifier,
                'person_name' => $personName,
                'date' => (string)$event['date'],
                'hours' => (float)$event['hours'],
                'theoretical_hours' => (float)($dateHours[$event['date']] ?? 0.0),
                'calculated_hours' => (float)$event['hours'],
                'cost_center_code' => $code,
                'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                'source' => (string)$event['source'],
                'rule_applied' => $event['kind'] === 'entry'
                    ? 'HORAS_TEORICAS_ANTERIORES_AL_INGRESO_REAL'
                    : 'HORAS_TEORICAS_POSTERIORES_AL_TERMINO_REAL',
            ];
        }
    }

    usort($events, static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcmp((string)$left['kind'], (string)$right['kind'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($issues, static fn(array $left, array $right): int =>
        strcmp((string)($left['date'] ?? ''), (string)($right['date'] ?? ''))
        ?: strcasecmp((string)($left['person_name'] ?? ''), (string)($right['person_name'] ?? '')));
    $separatedEntryEvents = array_values(array_filter(
        $events,
        static fn(array $event): bool => ($event['kind'] ?? '') === 'ENTRY'
    ));
    $separatedExitEvents = array_values(array_filter(
        $events,
        static fn(array $event): bool => ($event['kind'] ?? '') === 'EXIT'
    ));

    return [
        'hours_by_cost_center' => $hoursByCode,
        'entry_hours_by_cost_center' => $entryHoursByCode,
        'exit_hours_by_cost_center' => $exitHoursByCode,
        'entry_events' => $separatedEntryEvents,
        'exit_events' => $separatedExitEvents,
        'events' => $events,
        'issues' => $issues,
        'diagnostics' => $diagnostics,
    ];
}
function fte_monthly_build_report(
    array $calendar,
    array $headcount,
    array $people,
    array $absenceReasons = [],
    array $attendance = [],
    array $requestedCodes = [],
    array $attendanceDiagnostics = [],
    array $failureDelayPolicy = [],
    array $overtimePolicy = []
): array {
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));
    $calendarValidation = fte_calendar_validate_result($calendar);
    $dateHours = [];
    foreach (($calendar['days'] ?? []) as $day) {
        if (is_array($day) && isset($day['date'])) {
            $dateHours[(string)$day['date']] = max(0.0, (float)($day['theoretical_hours'] ?? 0));
        }
    }
    $hoursPerPerson = (float)$calendarValidation['summed_theoretical_hours'];
    $rows = [];
    $headcountMetricsByCode = [];
    foreach (($headcount['cost_centers'] ?? []) as $center) {
        if (!is_array($center)) {
            continue;
        }
        $code = fte_normalize_cost_center($center['cost_center_code'] ?? '');
        if ($code === '' || ($requestedCodes && !in_array($code, $requestedCodes, true))) {
            continue;
        }
        $headcountMetricsByCode[$code] = [
            'monthly_unique_active_any_time' => (int)($center['active_any_time_headcount'] ?? 0),
            'end_of_month' => (int)($center['end_of_month_headcount'] ?? 0),
            'average_calendar_day' => isset($center['average_calendar_day_headcount'])
                ? (float)$center['average_calendar_day_headcount']
                : null,
            'average_workday' => isset($center['average_workday_headcount'])
                ? (float)$center['average_workday_headcount']
                : null,
        ];
        $rows[$code] = [
            'cost_center_code' => $code,
            'cost_center_name' => trim((string)($center['cost_center_name'] ?? '')),
            'headcount' => (int)($center['active_any_time_headcount'] ?? 0),
            'theoretical_hours_per_person' => $hoursPerPerson,
            'authorized_overtime_hours' => 0.0,
            'vacation_hours' => 0.0,
            'employment_entry_hours' => 0.0,
            'employment_exit_hours' => 0.0,
            'employment_movement_hours' => 0.0,
            'medical_leave_hours' => 0.0,
            'accident_hours' => 0.0,
            'day_permission_hours' => 0.0,
            'hour_permission_hours' => 0.0,
            'failure_delay_hours' => 0.0,
            'attendance_components' => [
                'delay_raw_hours' => 0.0,
                'delay_after_compensation_hours' => 0.0,
                'delay_compensated_hours' => 0.0,
                'delay_applied_hours' => 0.0,
                'early_leave_raw_hours' => 0.0,
                'early_leave_after_compensation_hours' => 0.0,
                'early_leave_compensated_hours' => 0.0,
                'non_worked_hours' => 0.0,
                'full_failure_hours' => 0.0,
                'partial_failure_hours' => 0.0,
                'failure_overlap_avoided_hours' => 0.0,
                'day_permission_with_pay_hours' => 0.0,
                'day_permission_pending_hours' => 0.0,
            ],
        ];
    }

    $effectiveTheoretical = fte_monthly_effective_theoretical_hours($people, $dateHours, array_keys($rows));
    foreach ($rows as $code => &$row) {
        $row['effective_theoretical_hours'] = (float)($effectiveTheoretical['hours_by_cost_center'][$code] ?? 0.0);
    }
    unset($row);

    $employmentMovements = fte_monthly_employment_movement_hours($people, $dateHours, array_keys($rows));
    foreach ($rows as $code => &$row) {
        $row['employment_entry_hours'] = (float)($employmentMovements['entry_hours_by_cost_center'][$code] ?? 0.0);
        $row['employment_exit_hours'] = (float)($employmentMovements['exit_hours_by_cost_center'][$code] ?? 0.0);
        $row['employment_movement_hours'] = $row['employment_entry_hours'] + $row['employment_exit_hours'];
    }
    unset($row);

    $successfulAttendanceIdentifiers = array_fill_keys(array_values(array_filter(array_map(
        'fte_normalize_identifier',
        $attendanceDiagnostics['successful_identifiers'] ?? []
    ))), true);
    $vacationAudit = [
        'policy_version' => 1,
        'source' => 'Buk conciliado con GeoVictoria TimeOffs',
        'full_days' => 0,
        'partial_days' => 0,
        'reconciled_days' => 0,
        'unreconciled_buk_days' => 0,
        'conflict_count' => 0,
        'events' => [],
        'conflicts' => [],
    ];
    $medicalLeaveAudit = [
        'policy_version' => 1,
        'source' => 'Buk conciliado con GeoVictoria TimeOffs',
        'buk_working_days' => 0,
        'buk_non_working_days_ignored' => 0,
        'reconciled_days' => 0,
        'buk_only_days' => 0,
        'geo_only_days' => 0,
        'source_type_conflict_days' => 0,
        'conflict_count' => 0,
        'events' => [],
        'conflicts' => [],
    ];
    $accidentAudit = [
        'policy_version' => 1,
        'source' => 'Buk conciliado con GeoVictoria TimeOffs',
        'buk_working_days' => 0,
        'buk_non_working_days_ignored' => 0,
        'reconciled_days' => 0,
        'buk_only_days' => 0,
        'geo_only_days' => 0,
        'source_type_conflict_days' => 0,
        'buk_medical_overlap_days' => 0,
        'conflict_count' => 0,
        'events' => [],
        'conflicts' => [],
    ];
    $dayPermissionAudit = [
        'policy_version' => 1,
        'source' => 'GeoVictoria TimeOffs',
        'with_pay_records' => 0,
        'without_pay_records' => 0,
        'pending_records' => 0,
        'suppressed_by_priority_records' => 0,
        'deducted_hours' => 0.0,
        'informative_with_pay_hours' => 0.0,
        'pending_hours' => 0.0,
        'events' => [],
        'conflicts' => [],
    ];
    $hourPermissionAudit = [
        'policy_version' => 1,
        'source' => 'GeoVictoria TimeOffs',
        'raw_record_count' => 0,
        'event_count' => 0,
        'requested_hours' => 0.0,
        'measured_non_worked_hours' => 0.0,
        'candidate_hours' => 0.0,
        'applied_event_count' => 0,
        'applied_hours' => 0.0,
        'suppressed_event_count' => 0,
        'suppressed_hours' => 0.0,
        'events' => [],
    ];
    $resolvedFailureDelayPolicy = fte_monthly_failure_delay_policy($failureDelayPolicy);
    $failureDelayAudit = [
        'policy_version' => (int)$resolvedFailureDelayPolicy['policy_version'],
        'source' => 'GeoVictoria PlannedInterval',
        'policy' => $resolvedFailureDelayPolicy,
        'full_failure_records' => 0,
        'full_failure_hours' => 0.0,
        'unconfirmed_no_punch_records' => 0,
        'unconfirmed_no_punch_hours' => 0.0,
        'partial_failure_records' => 0,
        'partial_failure_hours' => 0.0,
        'delay_records' => 0,
        'delay_raw_hours' => 0.0,
        'delay_after_compensation_hours' => 0.0,
        'delay_applied_hours' => 0.0,
        'delay_compensated_hours' => 0.0,
        'early_leave_records' => 0,
        'early_leave_hours' => 0.0,
        'non_worked_records' => 0,
        'non_worked_hours' => 0.0,
        'short_delay_policy_records' => 0,
        'short_delay_minimum_applied_records' => 0,
        'justified_ignored_records' => 0,
        'overlap_avoided_hours' => 0.0,
        'applied_hours' => 0.0,
        'pending_records' => 0,
        'conflict_count' => 0,
        'events' => [],
        'conflicts' => [],
    ];
    $resolvedOvertimePolicy = fte_monthly_overtime_policy($overtimePolicy);
    $overtimeAudit = [
        'policy_version' => (int)$resolvedOvertimePolicy['policy_version'],
        'source' => 'GeoVictoria ' . (string)$resolvedOvertimePolicy['source_field'],
        'policy' => $resolvedOvertimePolicy,
        'reported_records' => 0,
        'reported_hours' => 0.0,
        'applied_records' => 0,
        'applied_hours' => 0.0,
        'excluded_weekend_records' => 0,
        'excluded_weekend_hours' => 0.0,
        'authorized_reference_hours' => 0.0,
        'pending_records' => 0,
        'conflict_count' => 0,
        'events' => [],
        'conflicts' => [],
    ];
    $deduplicationAudit = [
        'policy_version' => 1,
        'scope' => 'TRABAJADOR_FECHA',
        'overlap_days' => 0,
        'candidate_loss_hours' => 0.0,
        'applied_loss_hours' => 0.0,
        'avoided_duplicate_loss_hours' => 0.0,
        'suppressed_coverage_hours' => 0.0,
        'events' => [],
    ];
    $rutByNormalizedIdentifier = [];

    foreach ($people as $person) {
        if (!is_array($person)) {
            continue;
        }
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        if ($identifier === '') {
            continue;
        }
        $personRut = trim((string)($person['identifier'] ?? '')) ?: $identifier;
        $rutByNormalizedIdentifier[$identifier] = $personRut;
        $personName = trim((string)($person['person_name'] ?? '')) ?: 'Sin nombre informado';
        foreach ($dateHours as $date => $theoreticalHours) {
            if (!fte_headcount_person_active_on($person, $date)) {
                continue;
            }
            $job = fte_headcount_job_on_date($person, $date);
            $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
            if (!isset($rows[$code])) {
                continue;
            }
            $reason = $absenceReasons[$identifier][$date] ?? null;
            $kind = fte_attendance_normalize_absence_type((string)($reason['kind'] ?? ''));
            $mark = $attendance[$identifier][$date] ?? null;
            $vacationEvidence = is_array($mark)
                ? fte_monthly_vacation_evidence($mark, (float)$theoreticalHours)
                : fte_monthly_vacation_evidence([], (float)$theoreticalHours);
            $medicalLeaveEvidence = is_array($mark)
                ? fte_monthly_medical_leave_evidence($mark)
                : fte_monthly_medical_leave_evidence([]);
            $geoComparable = isset($successfulAttendanceIdentifiers[$identifier]) || is_array($mark);
            $geoTimeOffLabels = is_array($mark) ? fte_monthly_time_off_labels($mark) : [];
            $absenceKinds = fte_monthly_absence_row_kinds(is_array($reason) ? $reason : []);
            $permissionHours = fte_monthly_permission_hours_for_mark(
                is_array($mark) ? $mark : [],
                (float)$theoreticalHours
            );
            $dayStatus = (string)($permissionHours['day_status'] ?? 'NO_APLICA');
            $vacationCandidateHours = in_array('VACACIONES', $absenceKinds, true)
                ? ($vacationEvidence['has_vacation']
                    ? min((float)$theoreticalHours, max(0.0, (float)$vacationEvidence['hours']))
                    : max(0.0, (float)$theoreticalHours))
                : 0.0;
            $hasFullDayJustification = in_array('ACCIDENTE', $absenceKinds, true)
                || in_array('LICENCIA', $absenceKinds, true)
                || (
                    (float)$theoreticalHours > 0.0
                    && $vacationCandidateHours + 0.0000001 >= (float)$theoreticalHours
                )
                || $dayStatus !== 'NO_APLICA';
            $failureCandidateResolution = fte_monthly_failure_delay_hours_for_mark(
                is_array($mark) ? $mark : [],
                (float)$theoreticalHours,
                $hasFullDayJustification,
                $resolvedFailureDelayPolicy
            );
            $dailyPriority = fte_monthly_resolve_daily_loss_priority((float)$theoreticalHours, [
                'ACCIDENTE' => [
                    'label' => 'Accidente',
                    'coverage_hours' => in_array('ACCIDENTE', $absenceKinds, true) ? $theoreticalHours : 0.0,
                    'loss_hours' => in_array('ACCIDENTE', $absenceKinds, true) ? $theoreticalHours : 0.0,
                ],
                'LICENCIA' => [
                    'label' => 'Licencia medica',
                    'coverage_hours' => in_array('LICENCIA', $absenceKinds, true) ? $theoreticalHours : 0.0,
                    'loss_hours' => in_array('LICENCIA', $absenceKinds, true) ? $theoreticalHours : 0.0,
                ],
                'VACACIONES' => [
                    'label' => 'Vacaciones',
                    'coverage_hours' => $vacationCandidateHours,
                    'loss_hours' => $vacationCandidateHours,
                ],
                'PERMISO_DIA' => [
                    'label' => 'Permiso por dia',
                    'coverage_hours' => $dayStatus !== 'NO_APLICA' ? $theoreticalHours : 0.0,
                    'loss_hours' => (float)($permissionHours['day_hours'] ?? 0.0),
                ],
                'PERMISO_HORA' => [
                    'label' => 'Permiso por hora',
                    'coverage_hours' => (float)($permissionHours['hour_candidate_hours'] ?? $permissionHours['hour_hours'] ?? 0.0),
                    'loss_hours' => (float)($permissionHours['hour_candidate_hours'] ?? $permissionHours['hour_hours'] ?? 0.0),
                ],
                'FALLA_ATRASO' => [
                    'label' => 'Falla o atraso',
                    'coverage_hours' => (float)($failureCandidateResolution['applied_hours'] ?? 0.0),
                    'loss_hours' => (float)($failureCandidateResolution['applied_hours'] ?? 0.0),
                ],
            ]);
            $dailyAllocations = $dailyPriority['allocations'];
            $dailyAccidentHours = (float)$dailyAllocations['ACCIDENTE']['applied_loss_hours'];
            $dailyMedicalLeaveHours = (float)$dailyAllocations['LICENCIA']['applied_loss_hours'];
            $dailyVacationHours = (float)$dailyAllocations['VACACIONES']['applied_loss_hours'];
            $dailyDayPermissionHours = (float)$dailyAllocations['PERMISO_DIA']['applied_loss_hours'];
            $dailyHourPermissionHours = (float)$dailyAllocations['PERMISO_HORA']['applied_loss_hours'];
            $dailyFailureHours = (float)$dailyAllocations['FALLA_ATRASO']['applied_loss_hours'];
            $deduplicationAudit['candidate_loss_hours'] += (float)$dailyPriority['candidate_loss_hours'];
            $deduplicationAudit['applied_loss_hours'] += (float)$dailyPriority['applied_loss_hours'];
            $deduplicationAudit['avoided_duplicate_loss_hours'] += (float)$dailyPriority['avoided_duplicate_loss_hours'];
            $deduplicationAudit['suppressed_coverage_hours'] += (float)$dailyPriority['suppressed_coverage_hours'];
            if ($dailyPriority['has_overlap']) {
                $deduplicationAudit['overlap_days']++;
                $deduplicationAudit['events'][] = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'candidate_loss_hours' => (float)$dailyPriority['candidate_loss_hours'],
                    'applied_loss_hours' => (float)$dailyPriority['applied_loss_hours'],
                    'avoided_duplicate_loss_hours' => (float)$dailyPriority['avoided_duplicate_loss_hours'],
                    'remaining_hours' => (float)$dailyPriority['remaining_hours'],
                    'concepts' => array_filter(
                        $dailyAllocations,
                        static fn(array $allocation): bool =>
                            (float)$allocation['candidate_coverage_hours'] > 0.0
                    ),
                ];
            }
            if ($kind === 'VACACIONES') {
                $vacationHours = $dailyVacationHours;
                $resolution = 'BUK_JORNADA_COMPLETA_SIN_CONCILIACION';
                $conflictType = '';
                $conflictMessage = '';
                if ($vacationEvidence['has_vacation']) {
                    $vacationHours = min((float)$theoreticalHours, max(0.0, (float)$vacationEvidence['hours']));
                    $resolution = $vacationEvidence['is_partial']
                        ? 'VACACION_PARCIAL_GEOVICTORIA'
                        : 'VACACION_COMPLETA_CONCILIADA';
                    $vacationAudit[$vacationEvidence['is_partial'] ? 'partial_days' : 'full_days']++;
                    $vacationAudit['reconciled_days']++;
                } else {
                    $vacationAudit['full_days']++;
                    $vacationAudit['unreconciled_buk_days']++;
                    if ($geoComparable) {
                        $conflictType = $geoTimeOffLabels
                            ? 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO'
                            : 'SOLO_BUK';
                        $conflictMessage = $geoTimeOffLabels
                            ? 'Buk informa vacaciones, pero GeoVictoria registra otro tipo de TimeOff.'
                            : 'Buk informa vacaciones y GeoVictoria no registra vacaciones para la fecha.';
                    }
                }
                $rows[$code]['vacation_hours'] += $vacationHours;
                $event = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => $vacationHours,
                    'buk_has_vacation' => true,
                    'geo_has_vacation' => (bool)$vacationEvidence['has_vacation'],
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'calculation_source' => (string)$vacationEvidence['source'],
                    'source' => 'Buk ausencias / GeoVictoria TimeOffs',
                    'source_records' => $vacationEvidence['records'] ?? [],
                    'rule_applied' => 'VACACIONES_BUK_CONCILIADAS_CON_GEOVICTORIA',
                    'resolution' => $resolution,
                    'conflict_type' => $conflictType,
                ];
                $vacationAudit['events'][] = $event;
                if ($conflictType !== '') {
                    $vacationAudit['conflicts'][] = $event + ['message' => $conflictMessage];
                }
            } elseif ($kind === 'LICENCIA') {
                $licenceHours = $dailyMedicalLeaveHours;
                $rows[$code]['medical_leave_hours'] += $licenceHours;
                $conflictType = '';
                $conflictMessage = '';
                if ($licenceHours <= 0.0) {
                    $resolution = 'DIA_NO_LABORABLE_SIN_DESCUENTO';
                    $medicalLeaveAudit['buk_non_working_days_ignored']++;
                } else {
                    $medicalLeaveAudit['buk_working_days']++;
                    if ($medicalLeaveEvidence['has_medical_leave']) {
                        $resolution = 'LICENCIA_CONCILIADA_BUK_GEOVICTORIA';
                        $medicalLeaveAudit['reconciled_days']++;
                    } elseif (!$geoComparable) {
                        $resolution = 'BUK_JORNADA_LABORAL_SIN_CONCILIACION';
                    } else {
                        $resolution = 'BUK_MANTIENE_DESCUENTO_PENDIENTE_REVISION';
                        if ($geoTimeOffLabels) {
                            $conflictType = 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO';
                            $conflictMessage = $medicalLeaveEvidence['has_accident']
                                ? 'Buk informa licencia medica y GeoVictoria registra accidente para la fecha.'
                                : 'Buk informa licencia medica y GeoVictoria registra otro tipo de TimeOff.';
                            $medicalLeaveAudit['source_type_conflict_days']++;
                        } else {
                            $conflictType = 'SOLO_BUK';
                            $conflictMessage = 'Buk informa licencia medica y GeoVictoria no registra licencia para la fecha.';
                            $medicalLeaveAudit['buk_only_days']++;
                        }
                    }
                }
                $event = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => $licenceHours,
                    'buk_has_medical_leave' => true,
                    'buk_reason' => trim((string)($reason['reason'] ?? 'Licencia medica')),
                    'buk_from' => (string)($reason['from'] ?? ''),
                    'buk_to' => (string)($reason['to'] ?? ''),
                    'geo_has_medical_leave' => (bool)$medicalLeaveEvidence['has_medical_leave'],
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'source' => 'Buk licencias / GeoVictoria TimeOffs',
                    'source_records' => $medicalLeaveEvidence['medical_records'] ?? [],
                    'rule_applied' => 'LICENCIA_BUK_SOBRE_JORNADA_LABORAL',
                    'resolution' => $resolution,
                    'conflict_type' => $conflictType,
                ];
                $medicalLeaveAudit['events'][] = $event;
                if ($conflictType !== '') {
                    $medicalLeaveAudit['conflicts'][] = $event + ['message' => $conflictMessage];
                }
            } elseif ($kind === 'ACCIDENTE') {
                $accidentHours = $dailyAccidentHours;
                $rows[$code]['accident_hours'] += $accidentHours;
                $overlappingKinds = fte_monthly_absence_row_kinds(is_array($reason) ? $reason : []);
                $hasBukMedicalOverlap = in_array('LICENCIA', $overlappingKinds, true);
                $conflictTypes = [];
                $conflictMessages = [];
                if ($accidentHours <= 0.0) {
                    $resolution = 'DIA_NO_LABORABLE_SIN_DESCUENTO';
                    $accidentAudit['buk_non_working_days_ignored']++;
                } else {
                    $accidentAudit['buk_working_days']++;
                    if ($medicalLeaveEvidence['has_accident']) {
                        $resolution = 'ACCIDENTE_CONCILIADO_BUK_GEOVICTORIA';
                        $accidentAudit['reconciled_days']++;
                    } elseif (!$geoComparable) {
                        $resolution = 'BUK_JORNADA_LABORAL_SIN_CONCILIACION';
                    } elseif ($geoTimeOffLabels) {
                        $resolution = 'BUK_MANTIENE_DESCUENTO_PENDIENTE_REVISION';
                        $conflictTypes[] = 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO';
                        $conflictMessages[] = $medicalLeaveEvidence['has_medical_leave']
                            ? 'Buk informa accidente laboral y GeoVictoria registra licencia medica para la fecha.'
                            : 'Buk informa accidente laboral y GeoVictoria registra otro tipo de TimeOff.';
                        $accidentAudit['source_type_conflict_days']++;
                    } else {
                        $resolution = 'BUK_MANTIENE_DESCUENTO_PENDIENTE_REVISION';
                        $conflictTypes[] = 'SOLO_BUK';
                        $conflictMessages[] = 'Buk informa accidente laboral y GeoVictoria no registra accidente para la fecha.';
                        $accidentAudit['buk_only_days']++;
                    }
                }
                if ($hasBukMedicalOverlap) {
                    $conflictTypes[] = 'BUK_LICENCIA_Y_ACCIDENTE_MISMA_FECHA';
                    $conflictMessages[] = 'Buk contiene licencia medica y accidente para la misma jornada; se aplica solo accidente por prioridad y no se duplica el descuento.';
                    $accidentAudit['buk_medical_overlap_days']++;
                }
                $event = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => $accidentHours,
                    'buk_has_accident' => true,
                    'buk_reason' => trim((string)($reason['reason'] ?? 'Accidente laboral')),
                    'buk_from' => (string)($reason['from'] ?? ''),
                    'buk_to' => (string)($reason['to'] ?? ''),
                    'buk_overlapping_absence_kinds' => $overlappingKinds,
                    'geo_has_accident' => (bool)$medicalLeaveEvidence['has_accident'],
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'source' => 'Buk licencias / GeoVictoria TimeOffs',
                    'source_records' => $medicalLeaveEvidence['accident_records'] ?? [],
                    'rule_applied' => 'ACCIDENTE_BUK_CON_PRIORIDAD_DIARIA',
                    'resolution' => $resolution,
                    'conflict_type' => $conflictTypes[0] ?? '',
                    'conflict_types' => array_values(array_unique($conflictTypes)),
                ];
                $accidentAudit['events'][] = $event;
                if ($conflictTypes) {
                    $accidentAudit['conflicts'][] = $event + [
                        'message' => implode(' ', array_values(array_unique($conflictMessages))),
                    ];
                }
            }

            if ($kind !== 'VACACIONES' && $vacationEvidence['has_vacation']) {
                $conflictType = $kind === '' ? 'SOLO_GEOVICTORIA' : 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO';
                $message = $kind === ''
                    ? 'GeoVictoria informa vacaciones y Buk no registra vacaciones para la fecha.'
                    : 'GeoVictoria informa vacaciones, pero Buk registra otra ausencia para la fecha.';
                $conflict = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => 0.0,
                    'buk_has_vacation' => false,
                    'buk_absence_kind' => $kind,
                    'geo_has_vacation' => true,
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'calculation_source' => (string)$vacationEvidence['source'],
                    'resolution' => 'PENDIENTE_REVISION_SIN_DESCUENTO_AUTOMATICO',
                    'conflict_type' => $conflictType,
                    'message' => $message,
                ];
                $vacationAudit['events'][] = $conflict;
                $vacationAudit['conflicts'][] = $conflict;
            }

            if ($kind !== 'LICENCIA'
                && $theoreticalHours > 0
                && $medicalLeaveEvidence['has_medical_leave']) {
                $conflictType = $kind === '' ? 'SOLO_GEOVICTORIA' : 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO';
                $message = $kind === ''
                    ? 'GeoVictoria informa licencia medica y Buk no registra licencia para la fecha.'
                    : 'GeoVictoria informa licencia medica, pero Buk registra otra ausencia para la fecha.';
                $conflict = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => 0.0,
                    'buk_has_medical_leave' => false,
                    'buk_absence_kind' => $kind,
                    'geo_has_medical_leave' => true,
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'resolution' => 'PENDIENTE_REVISION_SIN_DESCUENTO_AUTOMATICO',
                    'conflict_type' => $conflictType,
                    'message' => $message,
                ];
                if ($kind === '') {
                    $medicalLeaveAudit['geo_only_days']++;
                } else {
                    $medicalLeaveAudit['source_type_conflict_days']++;
                }
                $medicalLeaveAudit['events'][] = $conflict;
                $medicalLeaveAudit['conflicts'][] = $conflict;
            }

            if ($kind !== 'ACCIDENTE'
                && $theoreticalHours > 0
                && $medicalLeaveEvidence['has_accident']) {
                $conflictType = $kind === '' ? 'SOLO_GEOVICTORIA' : 'BUK_VS_GEOVICTORIA_TIPO_DISTINTO';
                $message = $kind === ''
                    ? 'GeoVictoria informa accidente laboral y Buk no registra accidente para la fecha.'
                    : 'GeoVictoria informa accidente laboral, pero Buk registra otra ausencia para la fecha.';
                $conflict = [
                    'identifier' => $identifier,
                    'person_name' => $personName,
                    'date' => $date,
                    'cost_center_code' => $code,
                    'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                    'theoretical_hours' => (float)$theoreticalHours,
                    'calculated_hours' => 0.0,
                    'buk_has_accident' => false,
                    'buk_absence_kind' => $kind,
                    'geo_has_accident' => true,
                    'geo_time_off_labels' => $geoTimeOffLabels,
                    'resolution' => 'PENDIENTE_REVISION_SIN_DESCUENTO_AUTOMATICO',
                    'conflict_type' => $conflictType,
                    'conflict_types' => [$conflictType],
                    'message' => $message,
                ];
                if ($kind === '') {
                    $accidentAudit['geo_only_days']++;
                } else {
                    $accidentAudit['source_type_conflict_days']++;
                }
                $accidentAudit['events'][] = $conflict;
                $accidentAudit['conflicts'][] = $conflict;
            }

            if (is_array($mark)) {
                if ($dayStatus !== 'NO_APLICA') {
                    $dayAllocation = $dailyAllocations['PERMISO_DIA'];
                    $daySuppressedByPriority =
                        (float)$dayAllocation['candidate_coverage_hours'] > 0.0
                        && (float)$dayAllocation['allocated_coverage_hours'] <= 0.0;
                    $event = [
                        'identifier' => $identifier,
                        'person_name' => $personName,
                        'date' => $date,
                        'cost_center_code' => $code,
                        'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                        'theoretical_hours' => (float)$theoreticalHours,
                        'pay_status' => $dayStatus,
                        'source' => 'GeoVictoria TimeOffs',
                        'source_records' => $permissionHours['day_records'] ?? [],
                        'labels' => array_values(array_unique(array_map(
                            static fn(array $record): string => (string)($record['label'] ?? 'Permiso sin tipo'),
                            $permissionHours['day_records'] ?? []
                        ))),
                        'candidate_hours' => (float)$dayAllocation['candidate_coverage_hours'],
                        'deducted_hours' => $dailyDayPermissionHours,
                        'informative_hours' => $dayStatus === 'CON_GOCE'
                            ? (float)$dayAllocation['allocated_coverage_hours']
                            : 0.0,
                        'pending_hours' => $dayStatus === 'PENDIENTE'
                            ? (float)$dayAllocation['allocated_coverage_hours']
                            : 0.0,
                        'suppressed_hours' => (float)$dayAllocation['suppressed_coverage_hours'],
                        'resolution' => (string)$dayAllocation['resolution'],
                        'rule_applied' => 'PERMISO_DIA_SEGUN_ESTADO_DE_GOCE_Y_PRIORIDAD_DIARIA',
                    ];
                    if ($daySuppressedByPriority) {
                        $dayPermissionAudit['suppressed_by_priority_records']++;
                    } elseif ($dayStatus === 'SIN_GOCE') {
                        $dayPermissionAudit['without_pay_records']++;
                        $dayPermissionAudit['deducted_hours'] += $event['deducted_hours'];
                    } elseif ($dayStatus === 'CON_GOCE') {
                        $dayPermissionAudit['with_pay_records']++;
                        $dayPermissionAudit['informative_with_pay_hours'] += $event['informative_hours'];
                    } else {
                        $dayPermissionAudit['pending_records']++;
                        $dayPermissionAudit['pending_hours'] += $event['pending_hours'];
                        $event['message'] = 'El permiso por dia no indica si es con o sin goce; no se desconto y debe clasificarse antes de aprobar el mes.';
                        $dayPermissionAudit['conflicts'][] = $event;
                    }
                    $dayPermissionAudit['events'][] = $event;
                }
                $hourRecords = is_array($permissionHours['hour_records'] ?? null)
                    ? $permissionHours['hour_records']
                    : [];
                $hourAllocation = $dailyAllocations['PERMISO_HORA'];
                $hourCandidateHours = (float)$hourAllocation['candidate_coverage_hours'];
                if ($hourRecords || $hourCandidateHours > 0.0) {
                    $hourAppliedHours = $dailyHourPermissionHours;
                    $hourSuppressedHours = (float)$hourAllocation['suppressed_coverage_hours'];
                    $hourEvent = [
                        'identifier' => $identifier,
                        'person_name' => $personName,
                        'date' => $date,
                        'cost_center_code' => $code,
                        'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                        'theoretical_hours' => (float)$theoreticalHours,
                        'source' => 'GeoVictoria TimeOffs',
                        'source_records' => $hourRecords,
                        'labels' => array_values(array_unique(array_map(
                            static fn(array $record): string => (string)($record['label'] ?? 'Permiso por hora'),
                            $hourRecords
                        ))),
                        'candidate_hours' => $hourCandidateHours,
                        'requested_hours' => (float)($permissionHours['hour_requested_hours'] ?? $hourCandidateHours),
                        'measured_non_worked_hours' => (float)($permissionHours['hour_non_worked_hours'] ?? 0.0),
                        'measurement_source' => (string)($permissionHours['hour_measurement_source'] ?? 'DURACION_PERMISO'),
                        'calculated_hours' => $hourAppliedHours,
                        'applied_hours' => $hourAppliedHours,
                        'suppressed_hours' => $hourSuppressedHours,
                        'resolution' => (string)$hourAllocation['resolution'],
                        'rule_applied' => ($permissionHours['hour_measurement_source'] ?? '') === 'GEOVICTORIA_NON_WORKED_HOURS_LIMITADO_POR_PERMISO'
                            ? 'HNT_REAL_LIMITADA_POR_PERMISO_JORNADA_Y_PRIORIDAD'
                            : 'DURACION_PERMISO_SIN_HNT_LIMITADA_POR_JORNADA_Y_PRIORIDAD',
                    ];
                    if ($hourSuppressedHours > 0.0) {
                        $hourEvent['message'] = $hourAppliedHours > 0.0
                            ? 'El permiso se aplico parcialmente porque otro concepto prioritario ya ocupaba parte de la jornada.'
                            : 'El permiso no se desconto porque otro concepto prioritario ya cubria la jornada.';
                        $hourPermissionAudit['suppressed_event_count']++;
                    } elseif ($hourCandidateHours <= 0.0) {
                        $hourEvent['message'] = (float)($permissionHours['hour_requested_hours'] ?? 0.0) > 0.0
                            ? 'El permiso tiene duracion autorizada, pero GeoVictoria no informa horas no trabajadas para descontar.'
                            : 'El registro no contiene una duracion util para el calculo.';
                    }
                    $hourPermissionAudit['raw_record_count'] += count($hourRecords);
                    $hourPermissionAudit['event_count']++;
                    $hourPermissionAudit['requested_hours'] += (float)($permissionHours['hour_requested_hours'] ?? $hourCandidateHours);
                    $hourPermissionAudit['measured_non_worked_hours'] += (float)($permissionHours['hour_non_worked_hours'] ?? 0.0);
                    $hourPermissionAudit['candidate_hours'] += $hourCandidateHours;
                    if ($hourAppliedHours > 0.0) {
                        $hourPermissionAudit['applied_event_count']++;
                    }
                    $hourPermissionAudit['applied_hours'] += $hourAppliedHours;
                    $hourPermissionAudit['suppressed_hours'] += $hourSuppressedHours;
                    $hourPermissionAudit['events'][] = $hourEvent;
                }
                $rows[$code]['day_permission_hours'] += $dailyDayPermissionHours;
                $rows[$code]['hour_permission_hours'] += $dailyHourPermissionHours;
                $rows[$code]['attendance_components']['day_permission_with_pay_hours'] += $dayStatus === 'CON_GOCE'
                    ? (float)$dailyAllocations['PERMISO_DIA']['allocated_coverage_hours']
                    : 0.0;
                $rows[$code]['attendance_components']['day_permission_pending_hours'] += $dayStatus === 'PENDIENTE'
                    ? (float)$dailyAllocations['PERMISO_DIA']['allocated_coverage_hours']
                    : 0.0;
                $hasPermission = $dayStatus !== 'NO_APLICA' || $permissionHours['hour_hours'] > 0;
                $failureAllocation = $dailyAllocations['FALLA_ATRASO'];
                $failureCandidateHours = (float)($failureCandidateResolution['applied_hours'] ?? 0.0);
                $prioritySuppressedFailureHours = max(0.0, $failureCandidateHours - $dailyFailureHours);
                $hasJustifiedAbsence = (float)$failureAllocation['remaining_before_hours'] + 0.0000001
                    < (float)$theoreticalHours;
                $failureResolution = $failureCandidateResolution;
                $failureResolution['candidate_hours'] = $failureCandidateHours;
                $failureResolution['applied_hours'] = $dailyFailureHours;
                $failureResolution['priority_suppressed_hours'] = $prioritySuppressedFailureHours;
                $failureResolution['has_justification'] = $hasJustifiedAbsence;
                if ($prioritySuppressedFailureHours > 0.0) {
                    $failureResolution['resolution'] = $dailyFailureHours > 0.0
                        ? 'APLICADO_PARCIAL_POR_PRIORIDAD'
                        : 'OMITIDO_POR_AUSENCIA_O_PERMISO_PRIORITARIO';
                    $failureResolution['basis'] = 'PRIORIDAD_DIARIA';
                }
                $overtimeResolution = fte_monthly_overtime_hours_for_mark(
                    $mark,
                    $date,
                    $resolvedOvertimePolicy
                );
                $rows[$code]['authorized_overtime_hours'] += $overtimeResolution['applied_hours'];
                $hasOvertimeSignal = $overtimeResolution['accomplished_hours'] > 0.0
                    || $overtimeResolution['authorized_reference_hours'] > 0.0
                    || $overtimeResolution['conflict_types'];
                if ($hasOvertimeSignal) {
                    if ($overtimeResolution['accomplished_hours'] > 0.0) {
                        $overtimeAudit['reported_records']++;
                        $overtimeAudit['reported_hours'] += $overtimeResolution['accomplished_hours'];
                    }
                    if ($overtimeResolution['applied_hours'] > 0.0) {
                        $overtimeAudit['applied_records']++;
                        $overtimeAudit['applied_hours'] += $overtimeResolution['applied_hours'];
                    }
                    if ($overtimeResolution['excluded_by_weekday']) {
                        $overtimeAudit['excluded_weekend_records']++;
                        $overtimeAudit['excluded_weekend_hours'] += $overtimeResolution['excluded_hours'];
                    }
                    $overtimeAudit['authorized_reference_hours'] += $overtimeResolution['authorized_reference_hours'];
                    $event = [
                        'identifier' => $identifier,
                        'person_name' => $personName,
                        'date' => $date,
                        'cost_center_code' => $code,
                        'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                        'iso_weekday' => $overtimeResolution['iso_weekday'],
                        'accomplished_hours' => $overtimeResolution['accomplished_hours'],
                        'authorized_reference_hours' => $overtimeResolution['authorized_reference_hours'],
                        'applied_hours' => $overtimeResolution['applied_hours'],
                        'excluded_hours' => $overtimeResolution['excluded_hours'],
                        'excluded_by_weekday' => $overtimeResolution['excluded_by_weekday'],
                        'resolution' => $overtimeResolution['excluded_by_weekday']
                            ? 'EXCLUIDA_POR_FIN_DE_SEMANA'
                            : ($overtimeResolution['applied_hours'] > 0.0 ? 'APLICADA_LUNES_A_VIERNES' : 'SIN_HORAS_REALIZADAS'),
                        'rule_applied' => 'ACCOMPLISHED_EXTRA_TIME_SOLO_LUNES_A_VIERNES',
                        'conflict_type' => $overtimeResolution['conflict_type'],
                        'conflict_types' => $overtimeResolution['conflict_types'],
                        'message' => $overtimeResolution['message'],
                    ];
                    $overtimeAudit['events'][] = $event;
                    if ($overtimeResolution['conflict_types']) {
                        $overtimeAudit['pending_records']++;
                        $overtimeAudit['conflicts'][] = $event;
                    }
                }
                $rows[$code]['attendance_components']['delay_raw_hours'] += $failureResolution['raw_delay_hours'];
                $rows[$code]['attendance_components']['delay_after_compensation_hours'] += $failureResolution['delay_after_compensation_hours'];
                $rows[$code]['attendance_components']['delay_compensated_hours'] += $failureResolution['delay_compensated_hours'];
                $rows[$code]['attendance_components']['delay_applied_hours'] += min(
                    (float)$failureResolution['effective_delay_hours'],
                    $dailyFailureHours
                );
                $rows[$code]['attendance_components']['early_leave_raw_hours'] += $failureResolution['raw_early_leave_hours'];
                $rows[$code]['attendance_components']['early_leave_after_compensation_hours'] += $failureResolution['early_leave_after_compensation_hours'];
                $rows[$code]['attendance_components']['early_leave_compensated_hours'] += $failureResolution['early_leave_compensated_hours'];
                $rows[$code]['attendance_components']['non_worked_hours'] += $failureResolution['non_worked_hours'];
                $rows[$code]['attendance_components']['failure_overlap_avoided_hours'] +=
                    (float)$failureResolution['overlap_avoided_hours'] + $prioritySuppressedFailureHours;
                $rows[$code]['failure_delay_hours'] += $dailyFailureHours;
                if ($failureResolution['full_failure'] && $prioritySuppressedFailureHours <= 0.0) {
                    $rows[$code]['attendance_components']['full_failure_hours'] += $dailyFailureHours;
                } elseif ($dailyFailureHours > 0.0) {
                    $rows[$code]['attendance_components']['partial_failure_hours'] += $dailyFailureHours;
                }

                $hasFailureSignal = $failureResolution['full_failure']
                    || $failureResolution['raw_delay_hours'] > 0.0
                    || $failureResolution['raw_early_leave_hours'] > 0.0
                    || $failureResolution['non_worked_hours'] > 0.0
                    || $failureResolution['conflict_types'];
                if ($hasFailureSignal) {
                    if ($failureResolution['raw_delay_hours'] > 0.0) {
                        $failureDelayAudit['delay_records']++;
                        $failureDelayAudit['delay_raw_hours'] += $failureResolution['raw_delay_hours'];
                        $failureDelayAudit['delay_after_compensation_hours'] += $failureResolution['delay_after_compensation_hours'];
                        $failureDelayAudit['delay_applied_hours'] += $failureResolution['effective_delay_hours'];
                        $failureDelayAudit['delay_compensated_hours'] += $failureResolution['delay_compensated_hours'];
                    }
                    if ($failureResolution['raw_early_leave_hours'] > 0.0) {
                        $failureDelayAudit['early_leave_records']++;
                        $failureDelayAudit['early_leave_hours'] += $failureResolution['effective_early_leave_hours'];
                    }
                    if ($failureResolution['non_worked_hours'] > 0.0) {
                        $failureDelayAudit['non_worked_records']++;
                        $failureDelayAudit['non_worked_hours'] += $failureResolution['non_worked_hours'];
                    }
                    if ($failureResolution['short_delay_policy_applies']) {
                        $failureDelayAudit['short_delay_policy_records']++;
                    }
                    if ($failureResolution['short_delay_minimum_applied']) {
                        $failureDelayAudit['short_delay_minimum_applied_records']++;
                    }
                    if ($prioritySuppressedFailureHours > 0.0) {
                        $failureDelayAudit['justified_ignored_records']++;
                    }
                    if ($failureResolution['full_failure'] && $prioritySuppressedFailureHours <= 0.0) {
                        $failureDelayAudit['full_failure_records']++;
                        $failureDelayAudit['full_failure_hours'] += $dailyFailureHours;
                    } elseif ($dailyFailureHours > 0.0) {
                        $failureDelayAudit['partial_failure_records']++;
                        $failureDelayAudit['partial_failure_hours'] += $dailyFailureHours;
                    }
                    if (!empty($failureResolution['unconfirmed_no_punch']) && !$hasJustifiedAbsence) {
                        $failureDelayAudit['unconfirmed_no_punch_records']++;
                        $failureDelayAudit['unconfirmed_no_punch_hours'] +=
                            (float)($failureResolution['pending_full_failure_hours'] ?? 0.0);
                    }
                    $failureDelayAudit['overlap_avoided_hours'] +=
                        (float)$failureResolution['overlap_avoided_hours'] + $prioritySuppressedFailureHours;
                    $failureDelayAudit['applied_hours'] += $dailyFailureHours;
                    $event = [
                        'identifier' => $identifier,
                        'person_name' => $personName,
                        'date' => $date,
                        'cost_center_code' => $code,
                        'cost_center_name' => trim((string)($job['cost_center_name'] ?? '')),
                        'theoretical_hours' => (float)$theoreticalHours,
                        'source' => 'GeoVictoria PlannedInterval',
                        'candidate_hours' => $failureCandidateHours,
                        'applied_hours' => $dailyFailureHours,
                        'priority_suppressed_hours' => $prioritySuppressedFailureHours,
                        'resolution' => $failureResolution['resolution'],
                        'basis' => $failureResolution['basis'],
                        'rule_applied' => 'FALLA_ATRASO_RESIDUAL_DESPUES_DE_COMPENSACION_Y_PRIORIDAD',
                        'raw_delay_hours' => $failureResolution['raw_delay_hours'],
                        'delay_after_compensation_hours' => $failureResolution['delay_after_compensation_hours'],
                        'delay_compensated_hours' => $failureResolution['delay_compensated_hours'],
                        'effective_delay_hours' => $failureResolution['effective_delay_hours'],
                        'short_delay_minimum_applied' => $failureResolution['short_delay_minimum_applied'],
                        'early_leave_hours' => $failureResolution['effective_early_leave_hours'],
                        'non_worked_hours' => $failureResolution['non_worked_hours'],
                        'full_failure' => $failureResolution['full_failure'],
                        'unconfirmed_no_punch' => (bool)($failureResolution['unconfirmed_no_punch'] ?? false),
                        'pending_full_failure_hours' => (float)($failureResolution['pending_full_failure_hours'] ?? 0.0),
                        'has_justification' => $hasJustifiedAbsence,
                        'overlap_avoided_hours' => $failureResolution['overlap_avoided_hours'],
                        'conflict_type' => $failureResolution['conflict_type'],
                        'conflict_types' => $failureResolution['conflict_types'],
                        'message' => $failureResolution['message'],
                    ];
                    $failureDelayAudit['events'][] = $event;
                    if ($failureResolution['conflict_types']) {
                        $failureDelayAudit['pending_records']++;
                        $failureDelayAudit['conflicts'][] = $event;
                    }
                }
            }
        }
    }
    $attachRut = static function (array $events) use ($rutByNormalizedIdentifier): array {
        foreach ($events as &$event) {
            $normalized = fte_normalize_identifier($event['identifier'] ?? '');
            $event['rut'] = $rutByNormalizedIdentifier[$normalized] ?? (string)($event['identifier'] ?? '');
        }
        unset($event);
        return $events;
    };
    foreach (['events', 'conflicts'] as $eventList) {
        $vacationAudit[$eventList] = $attachRut($vacationAudit[$eventList] ?? []);
        $medicalLeaveAudit[$eventList] = $attachRut($medicalLeaveAudit[$eventList] ?? []);
        $accidentAudit[$eventList] = $attachRut($accidentAudit[$eventList] ?? []);
        $dayPermissionAudit[$eventList] = $attachRut($dayPermissionAudit[$eventList] ?? []);
        $failureDelayAudit[$eventList] = $attachRut($failureDelayAudit[$eventList] ?? []);
        $overtimeAudit[$eventList] = $attachRut($overtimeAudit[$eventList] ?? []);
    }
    $hourPermissionAudit['events'] = $attachRut($hourPermissionAudit['events']);
    $deduplicationAudit['events'] = $attachRut($deduplicationAudit['events']);

    usort($vacationAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($vacationAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    $vacationAudit['conflict_count'] = count($vacationAudit['conflicts']);
    usort($medicalLeaveAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($medicalLeaveAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    $medicalLeaveAudit['conflict_count'] = count($medicalLeaveAudit['conflicts']);
    usort($accidentAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($accidentAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    $accidentAudit['conflict_count'] = count($accidentAudit['conflicts']);
    usort($dayPermissionAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($dayPermissionAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($hourPermissionAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($failureDelayAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($failureDelayAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    $failureDelayAudit['conflict_count'] = count($failureDelayAudit['conflicts']);
    usort($overtimeAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    usort($overtimeAudit['conflicts'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));
    $overtimeAudit['conflict_count'] = count($overtimeAudit['conflicts']);
    usort($deduplicationAudit['events'], static fn(array $left, array $right): int =>
        strcmp((string)$left['date'], (string)$right['date'])
        ?: strcasecmp((string)$left['person_name'], (string)$right['person_name']));

    $calculated = fte_monthly_calculate_report(array_values($rows));
    $attendanceComponentTotals = [
        'delay_raw_hours' => 0.0,
        'delay_after_compensation_hours' => 0.0,
        'delay_compensated_hours' => 0.0,
        'delay_applied_hours' => 0.0,
        'early_leave_raw_hours' => 0.0,
        'early_leave_after_compensation_hours' => 0.0,
        'early_leave_compensated_hours' => 0.0,
        'non_worked_hours' => 0.0,
        'full_failure_hours' => 0.0,
        'partial_failure_hours' => 0.0,
        'failure_overlap_avoided_hours' => 0.0,
        'day_permission_with_pay_hours' => 0.0,
        'day_permission_pending_hours' => 0.0,
    ];
    $employmentEntryTotal = 0.0;
    $employmentExitTotal = 0.0;
    foreach ($calculated['cost_centers'] as &$calculatedRow) {
        $code = fte_normalize_cost_center($calculatedRow['cost_center_code'] ?? '');
        $entryHours = max(0.0, (float)($rows[$code]['employment_entry_hours'] ?? 0.0));
        $exitHours = max(0.0, (float)($rows[$code]['employment_exit_hours'] ?? 0.0));
        $calculatedRow['loss_components']['employment_entry_hours'] = $entryHours;
        $calculatedRow['loss_components']['employment_exit_hours'] = $exitHours;
        $employmentEntryTotal += $entryHours;
        $employmentExitTotal += $exitHours;
        $components = $rows[$code]['attendance_components'] ?? $attendanceComponentTotals;
        foreach ($attendanceComponentTotals as $component => $unused) {
            $components[$component] = max(0.0, (float)($components[$component] ?? 0));
            $attendanceComponentTotals[$component] += $components[$component];
        }
        $calculatedRow['attendance_components'] = $components;
        $calculatedRow['headcount_metrics'] = $headcountMetricsByCode[$code] ?? [
            'monthly_unique_active_any_time' => 0,
            'end_of_month' => 0,
            'average_calendar_day' => null,
            'average_workday' => null,
        ];
    }
    unset($calculatedRow);

    $calculated['totals']['attendance_components'] = $attendanceComponentTotals;
    $calculated['totals']['loss_components']['employment_entry_hours'] = $employmentEntryTotal;
    $calculated['totals']['loss_components']['employment_exit_hours'] = $employmentExitTotal;
    $calculated['attendance_component_rules'] = [
        'delay_raw_hours' => 'SOLO_INFORMATIVO_ANTES_DE_COMPENSACION',
        'delay_after_compensation_hours' => 'FUENTE_PARA_REGLA_UNIFICADA',
        'delay_compensated_hours' => 'SOLO_INFORMATIVO',
        'delay_applied_hours' => 'INCLUIDO_EN_REGLA_UNIFICADA',
        'early_leave_raw_hours' => 'SOLO_INFORMATIVO_ANTES_DE_COMPENSACION',
        'early_leave_after_compensation_hours' => 'INCLUIDO_EN_REGLA_UNIFICADA',
        'early_leave_compensated_hours' => 'SOLO_INFORMATIVO',
        'non_worked_hours' => 'INCLUIDO_SIN_DUPLICAR_ATRASO_O_SALIDA',
        'full_failure_hours' => 'INCLUIDO_COMO_JORNADA_TEORICA',
        'partial_failure_hours' => 'INCLUIDO_COMO_MAYOR_PERDIDA_DIARIA_CONSOLIDADA',
        'failure_overlap_avoided_hours' => 'SOLO_INFORMATIVO_CONTROL_DE_DUPLICIDAD',
        'day_permission_with_pay_hours' => 'SOLO_INFORMATIVO_NO_REDUCE_FTE',
        'day_permission_pending_hours' => 'PENDIENTE_CLASIFICACION_NO_REDUCE_FTE',
    ];
    $rank = static function (array $items, callable $value): ?array {
        if (!$items) {
            return null;
        }
        usort($items, static fn(array $a, array $b): int => $value($b) <=> $value($a));
        $top = $items[0];
        return [
            'cost_center_code' => $top['cost_center_code'],
            'cost_center_name' => $top['cost_center_name'],
            'value' => $value($top),
        ];
    };

    $calculated['rankings'] = [
        'largest_gap' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['headcount_fte_gap'] ?? 0)),
        'largest_unavailable_rate' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['unavailable_hours_rate'] ?? 0)),
        'most_overtime' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['authorized_overtime_hours'] ?? 0)),
        'most_licence' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['loss_components']['medical_leave_hours'] ?? 0)),
        'most_vacation' => $rank($calculated['cost_centers'], static fn(array $r): float => (float)($r['loss_components']['vacation_hours'] ?? 0)),
    ];
    $calculated['calendar'] = [
        'period' => (string)($calendar['period'] ?? ''),
        'theoretical_hours_per_person' => $hoursPerPerson,
        'workday_count' => (int)($calendar['workday_count'] ?? 0),
        'warnings' => $calendar['warnings'] ?? [],
        'policy' => $calendar['policy'] ?? [
            'version' => 0,
            'intermediate_rounding' => 'NONE',
            'display_rounding' => 'PRESENTATION_ONLY',
        ],
        'validation' => $calendarValidation,
    ];
    $calculated['headcount_rule'] = 'active_any_time_headcount';
    $sumMetric = static function (array $items, string $key): ?float {
        $total = 0.0;
        $hasValue = false;
        foreach ($items as $item) {
            if (!is_array($item) || !array_key_exists($key, $item) || $item[$key] === null) {
                continue;
            }
            $total += (float)$item[$key];
            $hasValue = true;
        }
        return $hasValue ? $total : null;
    };
    $selectedHeadcountMetrics = array_values($headcountMetricsByCode);
    $companyUnique = $requestedCodes
        ? $sumMetric($selectedHeadcountMetrics, 'monthly_unique_active_any_time')
        : (float)($headcount['unique_people_active_any_time'] ?? 0);
    $endOfMonth = $requestedCodes
        ? $sumMetric($selectedHeadcountMetrics, 'end_of_month')
        : (float)($headcount['end_of_month_headcount'] ?? 0);
    $averageCalendarDay = $requestedCodes
        ? $sumMetric($selectedHeadcountMetrics, 'average_calendar_day')
        : (isset($headcount['average_calendar_day_headcount']) ? (float)$headcount['average_calendar_day_headcount'] : null);
    $averageWorkday = $requestedCodes
        ? $sumMetric($selectedHeadcountMetrics, 'average_workday')
        : (isset($headcount['average_workday_headcount']) ? (float)$headcount['average_workday_headcount'] : null);
    $fteHeadcount = (int)($calculated['totals']['headcount'] ?? 0);
    $duplicateAcrossCostCenters = $companyUnique === null ? null : $fteHeadcount - $companyUnique;
    $calculated['headcount_definition'] = [
        'official_metric' => 'monthly_unique_active_any_time',
        'fte_uses' => 'monthly_unique_active_any_time_by_cost_center',
        'scope' => $requestedCodes ? 'selected_cost_centers' : 'all_cost_centers',
        'metrics' => [
            'monthly_unique_active_any_time' => $companyUnique === null ? null : (int)$companyUnique,
            'fte_headcount' => $fteHeadcount,
            'end_of_month' => $endOfMonth === null ? null : (int)$endOfMonth,
            'average_calendar_day' => $averageCalendarDay,
            'average_workday' => $averageWorkday,
        ],
        'cross_cost_center_duplicate_effect' => $duplicateAcrossCostCenters,
        'counting_rules' => [
            'person_active_at_least_one_day_is_included' => true,
            'partial_month_person_is_included' => true,
            'gross_theoretical_hours_use_full_month_for_excel_reconciliation' => true,
            'fte_uses_theoretical_hours_prorated_by_employment_validity' => true,
            'cost_center_assignment_uses_job_valid_on_each_date' => true,
            'employment_movement_uses_buk_employment_dates' => true,
            'cost_center_changes_are_not_employment_movements' => true,
            'cost_center_changes_must_start_on_month_first' => true,
            'job_history_gaps_are_not_employment_movements' => true,
            'calculation_precision_is_not_rounded' => true,
        ],
    ];
    $calculated['employment_movement_definition'] = [
        'policy_version' => 4,
        'source' => 'Buk active_since / active_until',
        'entry_rule' => 'Horas teoricas anteriores a la fecha real de ingreso dentro del mes',
        'exit_rule' => 'Horas teoricas posteriores a la fecha real de termino dentro del mes',
        'calculation_effect' => 'INFORMATIONAL_ONLY',
        'reduces_lost_hours' => false,
        'theoretical_hours_are_prorated' => true,
        'effective_theoretical_hours' => (float)($calculated['totals']['effective_theoretical_hours'] ?? 0.0),
        'cost_center_change_rule' => 'Un traslado interno debe comenzar el primer dia de un mes y nunca se cuenta como ingreso o salida',
        'cost_center_changes_counted' => false,
        'job_intervals_counted' => false,
        'entry_hours' => $employmentEntryTotal,
        'exit_hours' => $employmentExitTotal,
        'total_hours' => $employmentEntryTotal + $employmentExitTotal,
        'entry_event_count' => count($employmentMovements['entry_events'] ?? []),
        'exit_event_count' => count($employmentMovements['exit_events'] ?? []),
        'entry_events' => $employmentMovements['entry_events'] ?? [],
        'exit_events' => $employmentMovements['exit_events'] ?? [],
        'combined_total_is_reconciliation_only' => true,
        'events' => $employmentMovements['events'],
        'issues' => $employmentMovements['issues'],
        'diagnostics' => $employmentMovements['diagnostics'],
    ];
    $vacationAudit['total_hours'] = (float)($calculated['totals']['loss_components']['vacation_hours'] ?? 0.0);
    $vacationAudit['full_day_rule'] = 'Jornada teorica completa del trabajador en la fecha';
    $vacationAudit['partial_day_rule'] = 'Duracion TimeOff; luego HNT; finalmente media jornada teorica como respaldo';
    $vacationAudit['geo_only_rule'] = 'No se descuenta automaticamente y queda pendiente de revision';
    $calculated['vacation_definition'] = $vacationAudit;
    $medicalLeaveAudit['total_hours'] = (float)($calculated['totals']['loss_components']['medical_leave_hours'] ?? 0.0);
    $medicalLeaveAudit['working_day_rule'] = 'Buk define la licencia; se descuenta la jornada teorica correspondiente a cada fecha laborable';
    $medicalLeaveAudit['non_working_day_rule'] = 'Sabados, domingos, festivos y dias con jornada teorica cero no descuentan horas';
    $medicalLeaveAudit['geo_only_rule'] = 'No se descuenta automaticamente y queda pendiente de revision';
    $medicalLeaveAudit['buk_only_rule'] = 'Buk conserva el descuento y el registro queda identificado para revision';
    $calculated['medical_leave_definition'] = $medicalLeaveAudit;
    $accidentAudit['total_hours'] = (float)($calculated['totals']['loss_components']['accident_hours'] ?? 0.0);
    $accidentAudit['working_day_rule'] = 'Buk define el accidente laboral o de trayecto; se descuenta la jornada teorica correspondiente a cada fecha laborable';
    $accidentAudit['non_working_day_rule'] = 'Sabados, domingos, festivos y dias con jornada teorica cero no descuentan horas';
    $accidentAudit['geo_only_rule'] = 'No se descuenta automaticamente y queda pendiente de revision';
    $accidentAudit['buk_only_rule'] = 'Buk conserva el descuento y el registro queda identificado para revision';
    $accidentAudit['overlap_rule'] = 'Si Buk contiene licencia y accidente en la misma jornada, se aplica solo accidente y se registra el cruce para revision';
    $calculated['accident_definition'] = $accidentAudit;
    $dayPermissionAudit['with_pay_rule'] = 'Se informa y no reduce las horas disponibles ni el FTE';
    $dayPermissionAudit['without_pay_rule'] = 'Descuenta la jornada teorica del dia';
    $dayPermissionAudit['pending_rule'] = 'No se descuenta automaticamente y bloquea la aprobacion mensual';
    $calculated['day_permission_definition'] = $dayPermissionAudit;
    $hourPermissionAudit['total_hours'] = (float)($calculated['totals']['loss_components']['hour_permission_hours'] ?? 0.0);
    $hourPermissionAudit['applied_rule'] = 'Usa HNT real de GeoVictoria, limitada por la duracion autorizada del permiso y por la jornada disponible';
    $hourPermissionAudit['priority_rule'] = 'Se aplica despues de accidente, licencia, vacaciones y permiso por dia; nunca duplica horas ya cubiertas';
    $calculated['hour_permission_definition'] = $hourPermissionAudit;
    $overtimeAudit['total_hours'] = (float)($calculated['totals']['authorized_overtime_hours'] ?? 0.0);
    $overtimeAudit['applied_rule'] = 'Suma exclusivamente AccomplishedExtraTime de lunes a viernes';
    $overtimeAudit['authorized_reference_rule'] = 'TotalAuthorizedOvertime se conserva como antecedente y nunca reemplaza horas realizadas';
    $overtimeAudit['weekend_rule'] = 'Sabados y domingos quedan auditados, pero no aumentan las horas ajustadas ni el FTE';
    $calculated['overtime_definition'] = $overtimeAudit;
    $trackedDailyLossHours = 0.0;
    foreach (['accident_hours', 'medical_leave_hours', 'vacation_hours', 'day_permission_hours', 'hour_permission_hours', 'failure_delay_hours'] as $component) {
        $trackedDailyLossHours += (float)($calculated['totals']['loss_components'][$component] ?? 0.0);
    }
    $deduplicationAudit['priority'] = [
        'ACCIDENTE',
        'LICENCIA_MEDICA',
        'VACACIONES',
        'PERMISO_DIA',
        'PERMISO_HORA',
        'FALLA_ATRASO',
    ];
    $deduplicationAudit['daily_capacity_rule'] = 'Cada trabajador y fecha tiene un unico concepto propietario segun prioridad; los conceptos posteriores quedan auditados sin volver a descontarse';
    $deduplicationAudit['partial_day_rule'] = 'Una ausencia parcial conserva sus horas reales y no habilita otro descuento sin evidencia horaria que demuestre un tramo distinto';
    $deduplicationAudit['paid_permission_rule'] = 'Un permiso con goce justifica su cobertura, bloquea fallas superpuestas y no reduce el FTE';
    $deduplicationAudit['overtime_rule'] = 'Las horas extra se calculan separadamente y no compiten por la capacidad de la jornada';
    $deduplicationAudit['tracked_loss_hours'] = $trackedDailyLossHours;
    $deduplicationAudit['matches_report_loss_components'] =
        abs((float)$deduplicationAudit['applied_loss_hours'] - $trackedDailyLossHours) < 0.0000001;
    $calculated['deduplication_definition'] = $deduplicationAudit;
    $calculated['validation']['daily_loss_deduplication_matches'] =
        $deduplicationAudit['matches_report_loss_components'];
    $failureDelayAudit['total_hours'] = (float)($calculated['totals']['loss_components']['failure_delay_hours'] ?? 0.0);
    $failureDelayAudit['full_failure_rule'] = $resolvedFailureDelayPolicy['include_full_failures']
        ? 'Jornada programada sin marcaciones confirmada por RR.HH.: descuenta la jornada teorica'
        : 'Jornada programada sin marcaciones: queda pendiente de confirmacion y no descuenta automaticamente';
    $failureDelayAudit['partial_failure_rule'] = 'Usa el mayor entre NonWorkedHours y atraso mas salida anticipada posteriores a compensacion';
    $failureDelayAudit['justification_rule'] = 'Vacaciones, licencias, accidentes y permisos se excluyen para no duplicar horas';
    $failureDelayAudit['short_delay_rule'] = sprintf(
        'Atraso de hasta %.2f minutos exige %.2f minutos de compensacion; si GeoVictoria conserva residuo, se aplica ese minimo',
        (float)$resolvedFailureDelayPolicy['short_delay_threshold_minutes'],
        (float)$resolvedFailureDelayPolicy['short_delay_required_compensation_minutes']
    );
    $failureDelayAudit['overlap_rule'] = 'NonWorkedHours no se suma nuevamente cuando ya contiene atraso o salida anticipada';
    $calculated['failure_delay_definition'] = $failureDelayAudit;
    if ((int)($failureDelayAudit['unconfirmed_no_punch_records'] ?? 0) > 0) {
        $calculated['warnings'][] = sprintf(
            '%d jornada(s) programada(s) sin marcacion, equivalentes a %.2f hora(s), quedaron pendientes de confirmacion y no redujeron el FTE.',
            (int)$failureDelayAudit['unconfirmed_no_punch_records'],
            (float)$failureDelayAudit['unconfirmed_no_punch_hours']
        );
    }
    if (($employmentMovements['diagnostics']['mid_month_job_changes_ignored'] ?? 0) > 0) {
        $calculated['warnings'][] = sprintf(
            'Se detectaron %d cambio(s) de cargo o CECO iniciado(s) a mitad de mes. Se informan para revision, pero no se cuentan como ingresos/salidas.',
            (int)$employmentMovements['diagnostics']['mid_month_job_changes_ignored']
        );
    }
    if (($employmentMovements['diagnostics']['unassigned_employment_events'] ?? 0) > 0
        || ($employmentMovements['diagnostics']['invalid_employment_ranges'] ?? 0) > 0) {
        $calculated['warnings'][] = 'Existen fechas laborales de ingreso o termino que no pudieron asignarse de forma segura a un CECO; no se inventaron horas de movimiento.';
    }
    if (($employmentMovements['diagnostics']['unverified_employment_date_records'] ?? 0) > 0) {
        $calculated['warnings'][] = 'La fotografia de dotacion usada no conserva fechas laborales verificadas de Buk. Ingresos y salidas quedan pendientes de revision y no se infieren desde asignaciones de CECO.';
    }
    if ($duplicateAcrossCostCenters !== null && $duplicateAcrossCostCenters > 0) {
        $calculated['warnings'][] = sprintf(
            'La dotacion usada por CECO cuenta %d asignacion(es) adicional(es) respecto de las personas unicas del mes. Revisa traslados entre centros de costo.',
            (int)$duplicateAcrossCostCenters
        );
    }
    if ($medicalLeaveAudit['conflict_count'] > 0) {
        $calculated['warnings'][] = sprintf(
            'Se detectaron %d diferencia(s) nominal(es) de licencias medicas entre Buk y GeoVictoria. Buk conserva el calculo y los casos quedan pendientes de revision.',
            (int)$medicalLeaveAudit['conflict_count']
        );
    }
    if ($accidentAudit['conflict_count'] > 0) {
        $calculated['warnings'][] = sprintf(
            'Se detectaron %d diferencia(s) nominal(es) de accidentes entre Buk y GeoVictoria o cruces con licencias. Buk conserva el calculo sin duplicar jornadas y los casos quedan pendientes de revision.',
            (int)$accidentAudit['conflict_count']
        );
    }
    if ($failureDelayAudit['conflict_count'] > 0) {
        $calculated['warnings'][] = sprintf(
            'Se detectaron %d registro(s) de asistencia pendientes: jornadas sin marcacion no confirmadas, compensaciones no cuantificables o marcaciones incompletas. No se inventaron horas y los casos quedan pendientes de revision.',
            (int)$failureDelayAudit['conflict_count']
        );
    }
    if ($overtimeAudit['excluded_weekend_records'] > 0) {
        $calculated['warnings'][] = sprintf(
            'Se excluyeron %.2f hora(s) extra realizada(s) en %d registro(s) de fin de semana, conforme a la regla mensual de RR.HH.',
            (float)$overtimeAudit['excluded_weekend_hours'],
            (int)$overtimeAudit['excluded_weekend_records']
        );
    }
    if ($overtimeAudit['conflict_count'] > 0) {
        $calculated['warnings'][] = sprintf(
            'Existen %d registro(s) con horas autorizadas pero sin AccomplishedExtraTime. No se inventaron horas realizadas y los casos quedan pendientes de revision.',
            (int)$overtimeAudit['conflict_count']
        );
    }
    $calculated['attendance_diagnostics'] = [
        'successful' => count($attendanceDiagnostics['successful_identifiers'] ?? []),
        'failed' => count($attendanceDiagnostics['failed_identifiers'] ?? []),
        'unmatched' => count($attendanceDiagnostics['unmatched_identifiers'] ?? []),
        'request_count' => (int)($attendanceDiagnostics['request_count'] ?? 0),
        'batch_requests' => (int)($attendanceDiagnostics['batch_requests'] ?? 0),
        'fallback_requests' => (int)($attendanceDiagnostics['fallback_requests'] ?? 0),
    ];
    return $calculated;
}

function fte_build_monthly_payload(array $config, array $params, ?PDO $conn = null): array
{
    $period = trim((string)($params['period'] ?? date('Y-m')));
    if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $period, $match)) {
        throw new InvalidArgumentException('El periodo mensual no es valido.');
    }
    $year = (int)$match[1];
    $month = (int)$match[2];
    $requestedCodes = $params['cost_center_code'] ?? [];
    if (!is_array($requestedCodes)) {
        $requestedCodes = [$requestedCodes];
    }
    $requestedCodes = array_values(array_unique(array_filter(array_map('fte_normalize_cost_center', $requestedCodes))));
    $includeAttendance = filter_var($params['include_attendance'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $liveCalendar = fte_calendar_calculate_month($year, $month);
    $frozenBundle = $conn !== null ? fte_monthly_source_snapshot_load_approved($conn, $period) : null;
    $frozenSources = $frozenBundle['sources'] ?? null;
    $calendar = $liveCalendar;
    $calendarSource = 'LIVE_CONFIG';
    $calendarSnapshotWarning = null;
    if ($frozenSources !== null) {
        $frozenCalendar = $frozenSources['GEOVICTORIA_DIAGNOSTICOS']['calendar'] ?? null;
        if (is_array($frozenCalendar)) {
            if (($frozenCalendar['period'] ?? '') !== $period) {
                throw new RuntimeException('La fotografia oficial contiene un calendario de otro periodo.');
            }
            fte_calendar_validate_result($frozenCalendar, true);
            $calendar = $frozenCalendar;
            $calendarSource = 'APPROVED_SOURCE_SNAPSHOT';
        } else {
            $calendarSource = 'LEGACY_LIVE_CONFIG';
            $calendarSnapshotWarning = 'La fotografia oficial es anterior al congelamiento de calendario; las horas teoricas usan la configuracion vigente y requieren revision.';
        }
    }
    $identityDiagnostics = $frozenSources['GEOVICTORIA_DIAGNOSTICOS']['identity'] ?? [];
    if ($frozenSources !== null) {
        $includeAttendance = true;
    }
    $approvedSnapshot = $conn !== null ? fte_headcount_snapshot_load_approved($conn, $period) : null;
    $headcountSource = 'BUK_LIVE';
    if ($approvedSnapshot !== null) {
        $snapshotInputs = fte_headcount_snapshot_report_inputs($approvedSnapshot, $calendar);
        $people = $frozenSources['BUK_PERSONAS'] ?? $snapshotInputs['people'];
        $headcount = $snapshotInputs['headcount'];
        $headcountSource = $frozenSources !== null ? 'APPROVED_FULL_SNAPSHOT' : 'APPROVED_SNAPSHOT';
    } else {
        $people = fte_fetch_buk_people($config, false, $identityDiagnostics);
        $headcount = fte_headcount_build_month($people, $year, $month, $calendar);
    }
    $from = new DateTimeImmutable($calendar['period'] . '-01');
    $to = $from->modify('last day of this month');
    $warnings = $calendarSnapshotWarning !== null ? [$calendarSnapshotWarning] : [];

    if ($frozenSources !== null) {
        $vacations = ['ok' => true, 'items' => $frozenSources['BUK_VACACIONES'] ?? []];
        $licences = ['ok' => true, 'items' => $frozenSources['BUK_LICENCIAS'] ?? []];
    } else {
    $country = trim((string)$config['buk_country']);
    $vacations = fte_buk_fetch_all($config, "/api/v1/{$country}/vacations", [
        'start_before' => $to->format('Y-m-d'),
        'end_after' => $from->format('Y-m-d'),
    ]);
    $licences = fte_buk_fetch_all($config, "/api/v1/{$country}/absences/licence", [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
    ]);
    }
    if (!$vacations['ok']) {
        $warnings[] = 'No fue posible incorporar vacaciones desde Buk.';
    }
    if (!$licences['ok']) {
        $warnings[] = 'No fue posible incorporar licencias desde Buk.';
    }
    $classifiedLicences = fte_monthly_partition_buk_licences($licences['ok'] ? $licences['items'] : []);
    $reasons = fte_monthly_merge_absence_indexes(
        $vacations['ok'] ? fte_index_absence_items($vacations['items'], 'Vacaciones', $from, $to) : [],
        $licences['ok'] ? fte_index_absence_items($classifiedLicences['licences'], 'Licencia medica', $from, $to) : [],
        $licences['ok'] ? fte_index_absence_items($classifiedLicences['accidents'], 'Accidente', $from, $to) : []
    );

    $normalizedReasons = [];
    foreach ($people as $person) {
        $identifier = fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? '');
        foreach (fte_identity_aliases_for_person($person) as $key) {
            if (isset($reasons[$key])) {
                $normalizedReasons[$identifier] = fte_monthly_merge_absence_indexes(
                    [$identifier => $normalizedReasons[$identifier] ?? []],
                    [$identifier => $reasons[$key]]
                )[$identifier];
            }
        }
    }

    $attendance = [];
    $attendancePeople = [];
    $attendanceExcludedWorkers = [];
    $attendanceRequestedIdentifiers = [];
    $attendanceSourceStatus = $includeAttendance ? 'NO_DISPONIBLE' : 'NO_CARGADO';
    $diagnostics = ['successful_identifiers' => [], 'failed_identifiers' => [], 'unmatched_identifiers' => []];
    if ($includeAttendance) {
        $attendanceCandidates = array_values(array_filter($people, static function (array $person) use ($calendar, $requestedCodes): bool {
            foreach (($calendar['days'] ?? []) as $day) {
                $date = (string)($day['date'] ?? '');
                if ($date === '' || !fte_headcount_person_active_on($person, $date)) {
                    continue;
                }
                $job = fte_headcount_job_on_date($person, $date);
                $code = fte_normalize_cost_center($job['cost_center_code'] ?? '');
                if ($code !== '' && (!$requestedCodes || in_array($code, $requestedCodes, true))) {
                    return true;
                }
            }
            return false;
        }));
        $attendancePartition = fte_attendance_partition_people($attendanceCandidates, $config);
        $attendancePeople = $attendancePartition['included'];
        $attendanceExcludedWorkers = $attendancePartition['excluded'];
        $attendanceRequestedIdentifiers = array_values(array_unique(array_filter(array_map(
            static fn(array $person): string => fte_normalize_identifier($person['normalized_identifier'] ?? $person['identifier'] ?? ''),
            $attendancePeople
        ))));
        if ($frozenSources !== null) {
            $attendance = $frozenSources['GEOVICTORIA_ASISTENCIA'] ?? [];
            $diagnostics = $frozenSources['GEOVICTORIA_DIAGNOSTICOS'] ?? $diagnostics;
            $attendanceExcludedWorkers = is_array($diagnostics['attendance_excluded_workers'] ?? null)
                ? $diagnostics['attendance_excluded_workers']
                : $attendanceExcludedWorkers;
            $attendanceSourceStatus = 'DISPONIBLE';
        } else {
        $monthlyAttendanceConfig = $config;
        $monthlyAttendanceConfig['_include_monthly_time_offs'] = true;
        try {
            $attendance = fte_fetch_attendance($monthlyAttendanceConfig, $attendancePeople, $from, $to, $diagnostics);
            $attendanceSourceStatus = $diagnostics['failed_identifiers'] || $diagnostics['unmatched_identifiers']
                ? 'PARCIAL'
                : 'DISPONIBLE';
            if ($diagnostics['failed_identifiers']) {
                $warnings[] = 'GeoVictoria fallo para ' . count($diagnostics['failed_identifiers']) . ' persona(s).';
            }
            if ($diagnostics['unmatched_identifiers']) {
                $warnings[] = 'GeoVictoria no concilio ' . count($diagnostics['unmatched_identifiers']) . ' persona(s).';
            }
        } catch (FteAttendanceBatchException | FteGeoVictoriaException $exception) {
            $attendance = [];
            $diagnostics['successful_identifiers'] = [];
            $diagnostics['unmatched_identifiers'] = [];
            $diagnostics['failed_identifiers'] = $attendanceRequestedIdentifiers;
            $attendanceSourceStatus = 'NO_DISPONIBLE';
            $warnings[] = 'GeoVictoria no respondio; sus conceptos se muestran como No disponible y no como cero.';
        }
        }
        $diagnostics['attendance_excluded_workers'] = $attendanceExcludedWorkers;
        $diagnostics['attendance_excluded_identifiers'] = array_values(array_map(
            static fn(array $worker): string => fte_normalize_identifier($worker['normalized_identifier'] ?? $worker['identifier'] ?? ''),
            $attendanceExcludedWorkers
        ));
        if ($attendanceExcludedWorkers && $attendanceSourceStatus === 'DISPONIBLE') {
            $attendanceSourceStatus = 'PARCIAL';
        }
    } else {
        $warnings[] = 'Horas extra realizadas, permisos y marcaciones GeoVictoria no fueron incorporados en este calculo.';
    }
    if (!isset($diagnostics['identity'])) {
        $diagnostics['identity'] = $identityDiagnostics;
    }

    $report = fte_monthly_build_report(
        $calendar,
        $headcount,
        $people,
        $normalizedReasons,
        $attendance,
        $requestedCodes,
        $diagnostics,
        is_array($config['failure_delay_policy'] ?? null) ? $config['failure_delay_policy'] : [],
        is_array($config['overtime_policy'] ?? null) ? $config['overtime_policy'] : []
    );
    $report['period'] = $period;
    $report['is_preliminary'] = true;
    $report['headcount_source'] = $headcountSource;
    $report['calendar_source'] = $calendarSource;
    $report['headcount_approval_status'] = $approvedSnapshot !== null ? 'OFICIAL_APROBADA' : 'EN_VIVO_NO_APROBADA';
    $report['official_headcount_snapshot'] = $approvedSnapshot !== null ? [
        'id' => (int)$approvedSnapshot['snapshot']['id'],
        'version' => (int)$approvedSnapshot['snapshot']['version'],
        'approved_at' => (string)($approvedSnapshot['snapshot']['approved_at'] ?? ''),
        'approved_by' => (string)($approvedSnapshot['snapshot']['approved_by'] ?? ''),
        'total_unique_people' => (int)$approvedSnapshot['snapshot']['total_unique_people'],
        'detail_rows' => (int)$approvedSnapshot['snapshot']['detail_rows'],
    ] : null;
    $report['official_source_snapshot'] = $frozenBundle !== null ? [
        'id' => (int)$frozenBundle['snapshot']['id'],
        'version' => (int)$frozenBundle['snapshot']['version'],
        'source_count' => (int)$frozenBundle['snapshot']['source_count'],
        'approved_at' => (string)($frozenBundle['snapshot']['approved_at'] ?? ''),
        'approved_by' => (string)($frozenBundle['snapshot']['approved_by'] ?? ''),
    ] : null;
    $report['absence_classification'] = [
        'medical_licence_records' => count($classifiedLicences['licences']),
        'accident_records' => count($classifiedLicences['accidents']),
        'source' => 'Buk',
    ];
    $report['permission_classification'] = $includeAttendance && $attendanceSourceStatus !== 'NO_DISPONIBLE'
        ? fte_monthly_summarize_permissions($attendance)
        : [
            'status' => $includeAttendance ? 'NO_DISPONIBLE' : 'NO_CARGADO',
            'time_off_records' => null,
            'classified_permission_records' => null,
            'day_permission_records' => null,
            'day_permission_with_pay_records' => null,
            'day_permission_without_pay_records' => null,
            'day_permission_pending_records' => null,
            'hour_permission_records' => null,
            'hour_permission_records_without_duration' => null,
            'vacation_records' => null,
            'medical_leave_records' => null,
            'accident_records' => null,
            'other_time_off_records' => null,
            'types' => [],
        ];
    if ($includeAttendance && $attendanceSourceStatus !== 'NO_DISPONIBLE') {
        $rawPendingDayPermissions = (int)($report['permission_classification']['day_permission_pending_records'] ?? 0);
        $effectivePendingDayPermissions = (int)($report['day_permission_definition']['pending_records'] ?? 0);
        $report['permission_classification']['raw_day_permission_pending_records'] = $rawPendingDayPermissions;
        $report['permission_classification']['day_permission_pending_records'] = $effectivePendingDayPermissions;
        $report['permission_classification']['day_permission_suppressed_by_priority_records'] =
            (int)($report['day_permission_definition']['suppressed_by_priority_records'] ?? 0);
        $report['permission_classification']['day_permission_hours'] = (float)($report['totals']['loss_components']['day_permission_hours'] ?? 0);
        $report['permission_classification']['hour_permission_hours'] = (float)($report['totals']['loss_components']['hour_permission_hours'] ?? 0);
        if (($report['permission_classification']['hour_permission_records_without_duration'] ?? 0) > 0) {
            $warnings[] = 'Existen permisos por hora sin duracion utilizable; se muestran como pendientes de revision.';
        }
        if (($report['permission_classification']['other_time_off_records'] ?? 0) > 0) {
            $warnings[] = 'Existen categorias GeoVictoria sin clasificar; revisalas en Calidad de la informacion.';
        }
        if (($report['permission_classification']['day_permission_pending_records'] ?? 0) > 0) {
            $warnings[] = sprintf(
                'Existen %d permiso(s) por dia sin indicar si son con o sin goce. No se descontaron y bloquean la aprobacion mensual.',
                (int)$report['permission_classification']['day_permission_pending_records']
            );
        }
        $warnings[] = 'Fallas y atrasos usan una regla unificada: perdidas parciales, atrasos y salidas posteriores a compensacion. Una jornada programada sin marcaciones queda pendiente y no descuenta la jornada completa hasta que RR.HH. confirme la ausencia; las justificaciones y solapamientos tampoco se duplican.';
    }
    $report['attendance_components_status'] = match ($attendanceSourceStatus) {
        'DISPONIBLE' => 'CARGADO_PRELIMINAR',
        'PARCIAL' => 'CARGADO_PARCIAL',
        'NO_DISPONIBLE' => 'NO_DISPONIBLE',
        default => 'NO_CARGADO',
    };
    if ($frozenBundle !== null) {
        $warnings[] = sprintf(
            'Fuentes oficiales: fotografia integral v%d. Buk y GeoVictoria se leen desde la copia congelada, no desde las APIs en vivo.',
            (int)$frozenBundle['snapshot']['version']
        );
    }
    if ($approvedSnapshot !== null) {
        $warnings[] = sprintf(
            'Dotacion oficial: fotografia mensual v%d aprobada por %s el %s. Cambios posteriores en Buk no modifican este periodo.',
            (int)$approvedSnapshot['snapshot']['version'],
            (string)($approvedSnapshot['snapshot']['approved_by'] ?? 'usuario autorizado'),
            (string)($approvedSnapshot['snapshot']['approved_at'] ?? 'fecha no informada')
        );
    } else {
        $warnings[] = 'Dotacion en vivo no aprobada: personas vigentes al menos un dia por CECO. Guarda y aprueba la fotografia para congelar este periodo.';
    }
    $excludedIdentityCount = count($diagnostics['identity']['excluded_workers'] ?? []);
    if ($excludedIdentityCount > 0) {
        $warnings[] = sprintf(
            '%d trabajador(es) fueron excluidos del calculo por una regla explicita de identidad de RR.HH.; quedan visibles en Calidad de la informacion.',
            $excludedIdentityCount
        );
    }
    $attendanceExcludedCount = count($diagnostics['attendance_excluded_workers'] ?? []);
    if ($attendanceExcludedCount > 0) {
        $warnings[] = sprintf(
            '%d trabajador(es) permanecen en la dotacion Buk, pero no tienen cobertura GeoVictoria conciliada. Sus componentes de asistencia quedan pendientes y bloquean el cierre oficial.',
            $attendanceExcludedCount
        );
    }
    $identityIssueCount = (int)($diagnostics['identity']['missing_identifier_records'] ?? 0)
        + count($diagnostics['identity']['ambiguous_aliases'] ?? []);
    if ($identityIssueCount > 0) {
        $warnings[] = sprintf(
            'La conciliacion de identidad detecto %d incidencia(s) que requieren revision antes del cierre.',
            $identityIssueCount
        );
    }
    if (($headcount['unassigned_person_days'] ?? 0) > 0) {
        $warnings[] = 'Existen dias de trabajadores sin CECO; revisar asignaciones historicas.';
    }
    $vacationDefinition = $report['vacation_definition'] ?? [];
    $vacationConflictCount = (int)($vacationDefinition['conflict_count'] ?? 0);
    $medicalLeaveDefinition = $report['medical_leave_definition'] ?? [];
    $medicalLeaveConflictCount = (int)($medicalLeaveDefinition['conflict_count'] ?? 0);
    $accidentDefinition = $report['accident_definition'] ?? [];
    $accidentConflictCount = (int)($accidentDefinition['conflict_count'] ?? 0);
    $failureDelayDefinition = $report['failure_delay_definition'] ?? [];
    $failureDelayConflictCount = (int)($failureDelayDefinition['conflict_count'] ?? 0);
    $overtimeDefinition = $report['overtime_definition'] ?? [];
    $overtimeConflictCount = (int)($overtimeDefinition['conflict_count'] ?? 0);
    if ($vacationConflictCount > 0) {
        $warnings[] = sprintf(
            'Se detectaron %d conflicto(s) de vacaciones entre Buk y GeoVictoria. Se muestran para revision y no se resolvieron silenciosamente.',
            $vacationConflictCount
        );
    }
    if ($vacations['ok'] && $attendanceSourceStatus !== 'DISPONIBLE') {
        $warnings[] = 'Las vacaciones de Buk se calcularon con jornada completa cuando no hubo evidencia GeoVictoria disponible; las medias jornadas quedan pendientes de conciliacion.';
    }
    if (!$includeAttendance) {
        $report['rankings']['most_overtime'] = null;
    }
    $report['warnings'] = array_values(array_unique(array_merge($warnings, $report['warnings'] ?? [], array_map(
        static fn(string $warning): string => 'Calendario: ' . $warning,
        $calendar['warnings'] ?? []
    ))));
    if ($period > '2026-08') {
        $report['warnings'][] = 'El calendario posterior a agosto de 2026 aun no ha sido contrastado con el cierre mensual de RR.HH.';
    }
    $attendanceCoverageStatus = match ($attendanceSourceStatus) {
        'DISPONIBLE' => 'AUTOMATICO_PRELIMINAR',
        'PARCIAL' => 'PARCIAL_REQUIERE_REVISION',
        'NO_DISPONIBLE' => 'NO_DISPONIBLE',
        default => 'NO_CARGADO',
    };
    $frozenCoverageStatus = $frozenSources !== null ? 'OFICIAL_CONGELADA' : null;
    $frozenSourceLabel = $frozenBundle !== null
        ? 'Fotografia integral aprobada v' . (int)$frozenBundle['snapshot']['version']
        : null;
    $employmentDiagnostics = $report['employment_movement_definition']['diagnostics'] ?? [];
    $employmentIssueCount = (int)($employmentDiagnostics['mid_month_job_changes_ignored'] ?? 0)
        + (int)($employmentDiagnostics['unassigned_employment_events'] ?? 0)
        + (int)($employmentDiagnostics['invalid_employment_ranges'] ?? 0)
        + (int)($employmentDiagnostics['unverified_employment_date_records'] ?? 0);
    $employmentCoverageStatus = $employmentIssueCount > 0
        ? 'PARCIAL_REQUIERE_REVISION'
        : ($frozenSources !== null ? 'OFICIAL_CONGELADA' : 'AUTOMATICO_REGLA_DEFINIDA');
    $employmentSourceLabel = $frozenSourceLabel ?? 'Buk employee.active_since / active_until';
    if (($employmentDiagnostics['unverified_employment_date_records'] ?? 0) > 0) {
        $employmentSourceLabel = 'Fotografia de dotacion sin fechas laborales Buk verificadas';
    }
    if (!$vacations['ok']) {
        $vacationCoverageStatus = 'NO_DISPONIBLE';
    } elseif ($vacationConflictCount > 0) {
        $vacationCoverageStatus = 'PARCIAL_REQUIERE_REVISION';
    } elseif ($attendanceSourceStatus !== 'DISPONIBLE') {
        $vacationCoverageStatus = 'PARCIAL_SIN_CONCILIACION_GEOVICTORIA';
    } else {
        $vacationCoverageStatus = $frozenSources !== null ? 'OFICIAL_CONGELADA_CONCILIADA' : 'AUTOMATICO_CONCILIADO';
    }
    $vacationSourceLabel = $frozenSourceLabel ?? 'Buk + GeoVictoria TimeOffs';
    if (!$licences['ok']) {
        $medicalLeaveCoverageStatus = 'NO_DISPONIBLE';
    } elseif ($medicalLeaveConflictCount > 0) {
        $medicalLeaveCoverageStatus = 'PARCIAL_REQUIERE_REVISION';
    } elseif ($attendanceSourceStatus !== 'DISPONIBLE') {
        $medicalLeaveCoverageStatus = 'PARCIAL_SIN_CONCILIACION_GEOVICTORIA';
    } else {
        $medicalLeaveCoverageStatus = $frozenSources !== null ? 'OFICIAL_CONGELADA_CONCILIADA' : 'AUTOMATICO_CONCILIADO';
    }
    $medicalLeaveSourceLabel = $frozenSourceLabel ?? 'Buk + GeoVictoria TimeOffs';
    if (!$licences['ok']) {
        $accidentCoverageStatus = 'NO_DISPONIBLE';
    } elseif ($accidentConflictCount > 0) {
        $accidentCoverageStatus = 'PARCIAL_REQUIERE_REVISION';
    } elseif ($attendanceSourceStatus !== 'DISPONIBLE') {
        $accidentCoverageStatus = 'PARCIAL_SIN_CONCILIACION_GEOVICTORIA';
    } else {
        $accidentCoverageStatus = $frozenSources !== null ? 'OFICIAL_CONGELADA_CONCILIADA' : 'AUTOMATICO_CONCILIADO';
    }
    $accidentSourceLabel = $frozenSourceLabel ?? 'Buk + GeoVictoria TimeOffs';
    $pendingDayPermissionCount = (int)($report['permission_classification']['day_permission_pending_records'] ?? 0);
    $permissionCoverageStatus = $pendingDayPermissionCount > 0
        ? 'PARCIAL_REQUIERE_CLASIFICACION'
        : ($frozenCoverageStatus ?? $attendanceCoverageStatus);
    if ($attendanceSourceStatus === 'NO_CARGADO' || $attendanceSourceStatus === 'NO_DISPONIBLE') {
        $failureDelayCoverageStatus = $attendanceCoverageStatus;
    } elseif ($failureDelayConflictCount > 0 || $attendanceSourceStatus === 'PARCIAL') {
        $failureDelayCoverageStatus = 'PARCIAL_REQUIERE_REVISION';
    } else {
        $failureDelayCoverageStatus = $frozenSources !== null
            ? 'OFICIAL_CONGELADA_REGLA_DEFINIDA'
            : 'AUTOMATICO_REGLA_DEFINIDA';
    }
    if ($attendanceSourceStatus === 'NO_CARGADO' || $attendanceSourceStatus === 'NO_DISPONIBLE') {
        $overtimeCoverageStatus = $attendanceCoverageStatus;
    } elseif ($overtimeConflictCount > 0 || $attendanceSourceStatus === 'PARCIAL') {
        $overtimeCoverageStatus = 'PARCIAL_REQUIERE_REVISION';
    } else {
        $overtimeCoverageStatus = $frozenSources !== null
            ? 'OFICIAL_CONGELADA_REGLA_DEFINIDA'
            : 'AUTOMATICO_REGLA_DEFINIDA';
    }
    $report['source_coverage'] = [
        [
            'component' => 'Calendario y horas teoricas',
            'status' => $calendarSource === 'APPROVED_SOURCE_SNAPSHOT' ? 'OFICIAL_CONGELADA' : ($calendarSource === 'LEGACY_LIVE_CONFIG' ? 'PARCIAL_REQUIERE_REVISION' : 'AUTOMATICO'),
            'source' => $calendarSource === 'APPROVED_SOURCE_SNAPSHOT' ? 'Fotografia mensual aprobada' : 'Calendario PortalGP vigente',
        ],
        ['component' => 'Dotacion', 'status' => $approvedSnapshot !== null ? 'OFICIAL_APROBADA' : 'AUTOMATICO_REGLA_DEFINIDA', 'source' => $approvedSnapshot !== null ? 'Fotografia mensual aprobada v' . (int)$approvedSnapshot['snapshot']['version'] : 'Buk en vivo'],
        ['component' => 'Ingresos', 'status' => $employmentCoverageStatus, 'source' => $employmentSourceLabel],
        ['component' => 'Salidas', 'status' => $employmentCoverageStatus, 'source' => $employmentSourceLabel],
        ['component' => 'Vacaciones', 'status' => $vacationCoverageStatus, 'source' => $vacationSourceLabel],
        ['component' => 'Licencias medicas', 'status' => $medicalLeaveCoverageStatus, 'source' => $medicalLeaveSourceLabel],
        ['component' => 'Accidentes', 'status' => $accidentCoverageStatus, 'source' => $accidentSourceLabel],
        ['component' => 'Horas extra realizadas', 'status' => $overtimeCoverageStatus, 'source' => $frozenSourceLabel ?? 'GeoVictoria AccomplishedExtraTime (lunes a viernes)'],
        ['component' => 'Fallas y atrasos', 'status' => $failureDelayCoverageStatus, 'source' => $frozenSourceLabel ?? 'GeoVictoria PlannedInterval'],
        ['component' => 'Permisos', 'status' => $permissionCoverageStatus, 'source' => $frozenSourceLabel ?? 'GeoVictoria TimeOffs'],
    ];
    $hasUnclassifiedCategories = (int)($report['permission_classification']['other_time_off_records'] ?? 0) > 0;
    $report['metric_availability'] = fte_monthly_metric_availability(
        (bool)$vacations['ok'],
        (bool)$licences['ok'],
        $attendanceSourceStatus,
        $hasUnclassifiedCategories
    );
    if ($approvedSnapshot !== null) {
        $report['metric_availability']['headcount'] = 'OFICIAL_APROBADA';
    }
    $employmentMetricStatus = $employmentIssueCount > 0
        ? 'PARCIAL_REQUIERE_REVISION'
        : ($frozenSources !== null ? 'OFICIAL_FUENTES_APROBADAS' : 'PRELIMINAR');
    $report['metric_availability']['employment_entry_hours'] = $employmentMetricStatus;
    $report['metric_availability']['employment_exit_hours'] = $employmentMetricStatus;
    $report['metric_availability']['employment_movement_hours'] = $employmentMetricStatus;
    $report['metric_availability']['vacation_hours'] = $vacationCoverageStatus;
    $report['metric_availability']['medical_leave_hours'] = $medicalLeaveCoverageStatus;
    $report['metric_availability']['accident_hours'] = $accidentCoverageStatus;
    $report['metric_availability']['failure_delay_hours'] = $failureDelayCoverageStatus;
    $report['metric_availability']['authorized_overtime_hours'] = $overtimeCoverageStatus;
    if ($pendingDayPermissionCount > 0) {
        $report['metric_availability']['day_permission_hours'] = 'PARCIAL_REQUIERE_CLASIFICACION';
    }
    if ($pendingDayPermissionCount > 0 || $failureDelayConflictCount > 0) {
        $report['metric_availability']['lost_hours'] = 'PRELIMINAR_PARCIAL';
        $report['metric_availability']['adjusted_hours'] = 'PRELIMINAR_PARCIAL';
        $report['metric_availability']['fte'] = 'PRELIMINAR_PARCIAL';
    }
    $report['data_quality'] = fte_monthly_build_data_quality(
        $people,
        $attendanceRequestedIdentifiers,
        $diagnostics,
        $report['permission_classification'],
        $report['source_coverage'],
        $includeAttendance
    );
    $report['data_quality']['employment_movement_issue_count'] = $employmentIssueCount;
    $report['data_quality']['employment_movement_issues'] = $report['employment_movement_definition']['issues'] ?? [];
    $report['data_quality']['employment_movement_diagnostics'] = $employmentDiagnostics;
    $report['data_quality']['vacation_conflict_count'] = $vacationConflictCount;
    $report['data_quality']['vacation_conflicts'] = $vacationDefinition['conflicts'] ?? [];
    $report['data_quality']['medical_leave_conflict_count'] = $medicalLeaveConflictCount;
    $report['data_quality']['medical_leave_conflicts'] = $medicalLeaveDefinition['conflicts'] ?? [];
    $report['data_quality']['accident_conflict_count'] = $accidentConflictCount;
    $report['data_quality']['accident_conflicts'] = $accidentDefinition['conflicts'] ?? [];
    $report['data_quality']['failure_delay_conflict_count'] = $failureDelayConflictCount;
    $report['data_quality']['failure_delay_conflicts'] = $failureDelayDefinition['conflicts'] ?? [];
    $report['data_quality']['overtime_conflict_count'] = $overtimeConflictCount;
    $report['data_quality']['overtime_conflicts'] = $overtimeDefinition['conflicts'] ?? [];
    $report['data_quality']['overtime_excluded_weekend_count'] = (int)($overtimeDefinition['excluded_weekend_records'] ?? 0);
    $report['data_quality']['overtime_excluded_weekend_events'] = array_values(array_filter(
        $overtimeDefinition['events'] ?? [],
        static fn(array $event): bool => !empty($event['excluded_by_weekday'])
    ));
    $dayPermissionDefinition = $report['day_permission_definition'] ?? [];
    $report['data_quality']['day_permission_pending_count'] = (int)($dayPermissionDefinition['pending_records'] ?? 0);
    $report['data_quality']['day_permission_pending'] = $dayPermissionDefinition['conflicts'] ?? [];
    $report['result_status'] = 'PRELIMINAR_NO_OFICIAL';
    $report['result_status_label'] = 'Preliminar · no oficial';
    $report['reconciliation_pending'] = true;
    $report['comparison_scope'] = [
        'concepts' => true,
        'cost_centers' => true,
        'reference_values_affect_calculation' => false,
    ];
    return $report;
}
