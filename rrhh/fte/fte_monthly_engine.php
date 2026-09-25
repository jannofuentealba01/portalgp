<?php
declare(strict_types=1);

/**
 * Motor puro del informe mensual FTE de RR.HH.
 *
 * No consulta APIs ni base de datos. Recibe horas ya conciliadas y devuelve la
 * cuadratura mensual para que cualquier vista use exactamente la misma fórmula.
 */
function fte_monthly_calculate(array $input): array
{
    $usesProratedTheoreticalHours = array_key_exists('effective_theoretical_hours', $input)
        || array_key_exists('horas_teoricas_proporcionales', $input);
    $aliases = [
        'headcount' => ['headcount', 'dotacion'],
        'theoretical_hours_per_person' => ['theoretical_hours_per_person', 'horas_teoricas_persona'],
        'authorized_overtime_hours' => ['authorized_overtime_hours', 'horas_extra_autorizadas'],
        'vacation_hours' => ['vacation_hours', 'vacaciones'],
        'employment_movement_hours' => ['employment_movement_hours', 'ingresos_salidas'],
        'medical_leave_hours' => ['medical_leave_hours', 'licencias'],
        'accident_hours' => ['accident_hours', 'accidentes'],
        'day_permission_hours' => ['day_permission_hours', 'permisos_dia'],
        'hour_permission_hours' => ['hour_permission_hours', 'permisos_hora'],
        'failure_delay_hours' => ['failure_delay_hours', 'fallas_atrasos'],
    ];

    $values = [];
    foreach ($aliases as $canonical => $acceptedKeys) {
        $raw = 0;
        foreach ($acceptedKeys as $key) {
            if (array_key_exists($key, $input)) {
                $raw = $input[$key];
                break;
            }
        }
        if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric(str_replace(',', '.', trim($raw))))) {
            throw new InvalidArgumentException('El valor ' . $canonical . ' debe ser numerico.');
        }
        $value = (float)str_replace(',', '.', trim((string)$raw));
        if (!is_finite($value) || $value < 0) {
            throw new InvalidArgumentException('El valor ' . $canonical . ' no puede ser negativo ni invalido.');
        }
        $values[$canonical] = $value;
    }

    if (floor($values['headcount']) !== $values['headcount']) {
        throw new InvalidArgumentException('La dotacion debe ser un numero entero de personas.');
    }

    $effectiveTheoreticalHours = null;
    if ($usesProratedTheoreticalHours) {
        $rawEffective = $input['effective_theoretical_hours'] ?? $input['horas_teoricas_proporcionales'] ?? null;
        if (!is_int($rawEffective) && !is_float($rawEffective)
            && !(is_string($rawEffective) && is_numeric(str_replace(',', '.', trim($rawEffective))))) {
            throw new InvalidArgumentException('El valor effective_theoretical_hours debe ser numerico.');
        }
        $effectiveTheoreticalHours = (float)str_replace(',', '.', trim((string)$rawEffective));
        if (!is_finite($effectiveTheoreticalHours) || $effectiveTheoreticalHours < 0) {
            throw new InvalidArgumentException('El valor effective_theoretical_hours no puede ser negativo ni invalido.');
        }
    }

    $lossComponents = [
        'vacation_hours' => $values['vacation_hours'],
        'employment_movement_hours' => $values['employment_movement_hours'],
        'medical_leave_hours' => $values['medical_leave_hours'],
        'accident_hours' => $values['accident_hours'],
        'day_permission_hours' => $values['day_permission_hours'],
        'hour_permission_hours' => $values['hour_permission_hours'],
        'failure_delay_hours' => $values['failure_delay_hours'],
    ];

    $theoreticalHeadcountHours = $values['headcount'] * $values['theoretical_hours_per_person'];
    $effectiveTheoreticalHours ??= $theoreticalHeadcountHours;
    $lossesAppliedToFte = $lossComponents;
    if ($usesProratedTheoreticalHours) {
        // Las horas fuera de la vigencia laboral ya fueron retiradas de las horas
        // teoricas proporcionales. Se conservan como trazabilidad, pero no se
        // descuentan una segunda vez del FTE.
        $lossesAppliedToFte['employment_movement_hours'] = 0.0;
    }
    $lostHours = array_sum($lossesAppliedToFte);
    $adjustedHours = $effectiveTheoreticalHours + $values['authorized_overtime_hours'] - $lostHours;

    $fte = $values['theoretical_hours_per_person'] > 0
        ? $adjustedHours / $values['theoretical_hours_per_person']
        : null;
    $headcountGap = $fte !== null ? $values['headcount'] - $fte : null;
    $unavailableRate = $effectiveTheoreticalHours > 0
        ? $lostHours / $effectiveTheoreticalHours
        : null;
    $availabilityIndex = $unavailableRate !== null ? 1 - $unavailableRate : null;
    $fteToHeadcountRate = $fte !== null && $values['headcount'] > 0
        ? $fte / $values['headcount']
        : null;

    $warnings = [];
    if ($effectiveTheoreticalHours <= 0) {
        $warnings[] = 'ZERO_THEORETICAL_HOURS';
    }
    if ($adjustedHours < 0) {
        $warnings[] = 'NEGATIVE_ADJUSTED_HOURS';
    }
    if ($lostHours > $effectiveTheoreticalHours + $values['authorized_overtime_hours']) {
        $warnings[] = 'LOSSES_EXCEED_AVAILABLE_HOURS';
    }
    if ($fte !== null && $fte > $values['headcount']) {
        $warnings[] = 'FTE_EXCEEDS_HEADCOUNT';
    }

    // La precisión se conserva completa; el formato visual se aplica fuera del motor.
    return [
        'headcount' => (int)$values['headcount'],
        'theoretical_hours_per_person' => $values['theoretical_hours_per_person'],
        'theoretical_headcount_hours' => $theoreticalHeadcountHours,
        'effective_theoretical_hours' => $effectiveTheoreticalHours,
        'uses_prorated_theoretical_hours' => $usesProratedTheoreticalHours,
        'authorized_overtime_hours' => $values['authorized_overtime_hours'],
        'loss_components' => $lossComponents,
        'loss_components_applied_to_fte' => $lossesAppliedToFte,
        'lost_hours' => $lostHours,
        'adjusted_hours' => $adjustedHours,
        'fte' => $fte,
        'headcount_fte_gap' => $headcountGap,
        'unavailable_hours_rate' => $unavailableRate,
        'availability_index' => $availabilityIndex,
        'fte_to_headcount_rate' => $fteToHeadcountRate,
        'warnings' => $warnings,
        'labels' => [
            'headcount_fte_gap' => 'Brecha Dotacion/FTE',
            'unavailable_hours_rate' => 'Tasa de horas no disponibles',
        ],
    ];
}

/**
 * Calcula varios CECO y consolida totales absolutos. Los porcentajes globales se
 * recalculan desde los totales y nunca se obtienen sumando porcentajes por CECO.
 */
function fte_monthly_calculate_report(array $rows): array
{
    $results = [];
    $seenCodes = [];
    $totals = [
        'headcount' => 0,
        'theoretical_headcount_hours' => 0.0,
        'effective_theoretical_hours' => 0.0,
        'authorized_overtime_hours' => 0.0,
        'lost_hours' => 0.0,
        'adjusted_hours' => 0.0,
        'fte' => 0.0,
        'loss_components' => [
            'vacation_hours' => 0.0,
            'employment_movement_hours' => 0.0,
            'medical_leave_hours' => 0.0,
            'accident_hours' => 0.0,
            'day_permission_hours' => 0.0,
            'hour_permission_hours' => 0.0,
            'failure_delay_hours' => 0.0,
        ],
    ];
    $hasCalculableFte = false;
    $hasMissingFte = false;

    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Cada fila mensual debe ser un arreglo.');
        }
        $code = trim((string)($row['cost_center_code'] ?? $row['ceco'] ?? ''));
        if ($code === '') {
            throw new InvalidArgumentException('La fila ' . ($index + 1) . ' no tiene codigo CECO.');
        }
        $normalizedCode = fte_monthly_normalize_ceco($code);
        if (isset($seenCodes[$normalizedCode])) {
            throw new InvalidArgumentException('El CECO ' . $code . ' esta repetido en el informe.');
        }
        $seenCodes[$normalizedCode] = true;

        $calculation = fte_monthly_calculate($row);
        $calculation['cost_center_code'] = $code;
        $calculation['cost_center_name'] = trim((string)($row['cost_center_name'] ?? $row['nombre_ceco'] ?? ''));
        $results[] = $calculation;

        $totals['headcount'] += $calculation['headcount'];
        foreach (['theoretical_headcount_hours', 'effective_theoretical_hours', 'authorized_overtime_hours', 'lost_hours', 'adjusted_hours'] as $key) {
            $totals[$key] += (float)$calculation[$key];
        }
        foreach ($totals['loss_components'] as $key => $unused) {
            $totals['loss_components'][$key] += (float)$calculation['loss_components'][$key];
        }
        if ($calculation['fte'] !== null) {
            $hasCalculableFte = true;
            $totals['fte'] += (float)$calculation['fte'];
        } else {
            $hasMissingFte = true;
        }
    }

    $totals['fte'] = $hasCalculableFte && !$hasMissingFte ? $totals['fte'] : null;
    $totals['headcount_fte_gap'] = $totals['fte'] !== null
        ? $totals['headcount'] - $totals['fte']
        : null;
    $totals['unavailable_hours_rate'] = $totals['effective_theoretical_hours'] > 0
        ? $totals['lost_hours'] / $totals['effective_theoretical_hours']
        : null;
    $totals['availability_index'] = $totals['unavailable_hours_rate'] !== null
        ? 1 - $totals['unavailable_hours_rate']
        : null;
    $totals['fte_to_headcount_rate'] = $totals['fte'] !== null && $totals['headcount'] > 0
        ? $totals['fte'] / $totals['headcount']
        : null;

    return [
        'cost_centers' => $results,
        'totals' => $totals,
        'validation' => [
            'cost_center_count' => count($results),
            'headcount_matches_rows' => $totals['headcount'] === array_sum(array_column($results, 'headcount')),
            'totals_recalculated_from_absolute_values' => true,
        ],
    ];
}

function fte_monthly_normalize_ceco(string $code): string
{
    return strtoupper(preg_replace('/\s+/', ' ', trim($code)) ?: '');
}
