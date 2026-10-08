<?php
declare(strict_types=1);

/** Public durations only: never URLs, provider payloads, users or credentials. */
function fte_public_server_timing(array $phases, float $totalSeconds, float $serializationSeconds): string
{
    $names = ['bootstrap_auth' => 'auth', 'buk_people_calendar_headcount' => 'roster_calendar',
        'buk_vacations' => 'vacations', 'buk_licences' => 'licences', 'absence_normalization' => 'absences',
        'attendance_total' => 'attendance', 'calculation_and_report' => 'calculation'];
    $items = [];
    foreach ($names as $phase => $name) {
        if (isset($phases[$phase]) && is_numeric($phases[$phase])) {
            $seconds = (float)$phases[$phase];
            if (is_finite($seconds) && $seconds >= 0) { $items[] = $name . ';dur=' . number_format($seconds * 1000, 3, '.', ''); }
        }
    }
    foreach (['serialization' => $serializationSeconds, 'fte_total' => $totalSeconds] as $name => $seconds) {
        if (is_finite($seconds) && $seconds >= 0) { $items[] = $name . ';dur=' . number_format($seconds * 1000, 3, '.', ''); }
    }
    return implode(', ', $items);
}
