<?php
declare(strict_types=1);

return [
    'work_rules' => [
        [
            'code' => 'JORNADA_44_HORAS',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-04-26',
            'weekday_hours' => [1 => 9.0, 2 => 9.0, 3 => 9.0, 4 => 9.0, 5 => 8.0],
        ],
        [
            'code' => 'JORNADA_42_HORAS',
            'effective_from' => '2026-04-27',
            'effective_to' => null,
            'weekday_hours' => [1 => 8.5, 2 => 8.5, 3 => 8.5, 4 => 8.5, 5 => 8.0],
        ],
    ],
    // Días no contabilizados en el Excel de referencia. Deben confirmarse con
    // RR.HH. antes de tratar esta lista como calendario corporativo oficial.
    'non_working_days' => [
        '2026-01-01' => 'No laborable observado en planilla RR.HH.',
        '2026-01-02' => 'Excepcion observada en planilla RR.HH.; pendiente confirmar',
        '2026-04-03' => 'No laborable observado en planilla RR.HH.',
        '2026-05-01' => 'No laborable observado en planilla RR.HH.',
        '2026-05-21' => 'No laborable observado en planilla RR.HH.',
        '2026-06-29' => 'No laborable observado en planilla RR.HH.',
        '2026-07-16' => 'No laborable observado en planilla RR.HH.',
    ],
    'date_overrides' => [],
];
