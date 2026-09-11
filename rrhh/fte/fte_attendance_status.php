<?php
declare(strict_types=1);

function fte_attendance_resolve_status(array $facts): array
{
    $providerOk = (bool)($facts['provider_ok'] ?? true);
    $contractActive = (bool)($facts['contract_active'] ?? true);
    $identityMatched = (bool)($facts['identity_matched'] ?? true);
    $plannedWork = array_key_exists('planned_work', $facts) ? $facts['planned_work'] : null;
    $punchCount = max(0, (int)($facts['punch_count'] ?? 0));
    $hasEntry = (bool)($facts['has_entry'] ?? false);
    $hasExit = (bool)($facts['has_exit'] ?? false);
    $article22 = (bool)($facts['article_22'] ?? false);
    $permissionHours = max(0.0, (float)($facts['permission_hours'] ?? 0));
    $absenceType = fte_attendance_normalize_absence_type((string)($facts['absence_type'] ?? ''));

    if (!$contractActive) {
        return fte_attendance_status_result('FUERA_DE_CONTRATO', 'Fuera de contrato', false, false, false);
    }
    if (!$providerOk) {
        return fte_attendance_status_result('ERROR_GEOVICTORIA', 'Datos de GeoVictoria no disponibles', true, false, false);
    }
    if (!$identityMatched) {
        return fte_attendance_status_result('NO_CONCILIADO', 'No conciliado entre Buk y GeoVictoria', true, false, false);
    }

    $justified = [
        'VACACIONES' => 'Vacaciones',
        'LICENCIA' => 'Licencia médica',
        'ACCIDENTE' => 'Accidente',
        'PERMISO_DIA' => 'Permiso de día',
    ];
    if (isset($justified[$absenceType])) {
        return fte_attendance_status_result($absenceType, $justified[$absenceType], false, true, true);
    }
    if ($article22) {
        return fte_attendance_status_result('ARTICULO_22', 'Artículo 22 / exento de marcación', false, true, false);
    }
    if ($plannedWork === false) {
        return fte_attendance_status_result('DIA_LIBRE', 'Día libre o sin jornada esperada', false, true, false);
    }
    if ($permissionHours > 0 || $absenceType === 'PERMISO_HORA') {
        return fte_attendance_status_result('PERMISO_HORA', 'Permiso por horas', false, true, true);
    }
    if ($punchCount > 0 && $hasEntry && $hasExit) {
        return fte_attendance_status_result('TRABAJADO', 'Trabajado', false, true, false);
    }
    if ($punchCount > 0) {
        return fte_attendance_status_result('MARCACION_INCOMPLETA', 'Marcación incompleta', true, false, false);
    }
    if ($plannedWork === true) {
        return fte_attendance_status_result('SIN_MARCACION', 'Sin marcación con jornada informada', true, false, false);
    }
    return fte_attendance_status_result('SIN_JORNADA_INFORMADA', 'Sin jornada esperada informada', true, false, false);
}

function fte_attendance_normalize_absence_type(string $value): string
{
    // strtoupper no transforma vocales acentuadas sin mbstring. Quitamos
    // primero los acentos en ambas variantes para que el estado sea estable.
    $normalized = strtr(trim($value), [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
    ]);
    $normalized = strtr(strtoupper($normalized), [
        ' ' => '_', '-' => '_',
    ]);
    return match ($normalized) {
        'VACACION', 'VACACIONES' => 'VACACIONES',
        'LICENCIA', 'LICENCIA_MEDICA' => 'LICENCIA',
        'ACCIDENTE' => 'ACCIDENTE',
        'PERMISO', 'PERMISO_DIA' => 'PERMISO_DIA',
        'PERMISO_HORA', 'PERMISO_HORAS' => 'PERMISO_HORA',
        default => '',
    };
}

function fte_attendance_status_result(
    string $code,
    string $label,
    bool $requiresReview,
    bool $resolved,
    bool $countsAsUnavailable
): array {
    return [
        'code' => $code,
        'label' => $label,
        'requires_review' => $requiresReview,
        'resolved' => $resolved,
        'counts_as_unavailable' => $countsAsUnavailable,
        'is_unjustified_absence' => false,
    ];
}

function fte_attendance_summarize_statuses(array $personDays): array
{
    $counts = [];
    $requiresReview = 0;
    foreach ($personDays as $row) {
        if (!is_array($row)) {
            continue;
        }
        $code = trim((string)($row['attendance_status'] ?? ''));
        if ($code === '') {
            continue;
        }
        $counts[$code] = ($counts[$code] ?? 0) + 1;
        if (!empty($row['attendance_requires_review'])) {
            $requiresReview++;
        }
    }
    ksort($counts);
    return ['counts' => $counts, 'requires_review' => $requiresReview];
}
