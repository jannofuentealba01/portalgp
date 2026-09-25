<?php
return [
    'permission' => 'Ver Dashboard FTE',
    'buk_base_url' => getenv('BUK_BASE_URL') ?: 'https://grupopatagual.buk.cl',
    'buk_token' => getenv('BUK_API_TOKEN') ?: (getenv('BUK_TOKEN') ?: ''),
    'buk_country' => getenv('BUK_COUNTRY') ?: 'chile',
    'buk_people_cache_ttl_seconds' => (int)(getenv('BUK_PEOPLE_CACHE_TTL_SECONDS') ?: 300),
    'geovictoria_base_url' => getenv('GEOVICTORIA_BASE_URL') ?: 'https://customerapi.geovictoria.com/api/v1',
    'geovictoria_user' => getenv('GEOVICTORIA_API_USER') ?: (getenv('GEOVICTORIA_USER') ?: ''),
    'geovictoria_password' => getenv('GEOVICTORIA_API_PASSWORD') ?: '',
    'geovictoria_token_ttl_seconds' => (int)(getenv('GEOVICTORIA_TOKEN_TTL_SECONDS') ?: 1200),
    'geovictoria_attendance_batch_size' => (int)(getenv('GEOVICTORIA_ATTENDANCE_BATCH_SIZE') ?: 195),
    'geovictoria_attendance_batch_max_range_days' => 31,
    'geovictoria_attendance_max_records_per_request' => 1500,
    'geovictoria_short_range_timeout_seconds' => 45,
    'geovictoria_month_range_timeout_seconds' => 90,
    'geovictoria_unmatched_cache_ttl_seconds' => 300,
    'geovictoria_attendance_min_interval_seconds' => (float)(getenv('GEOVICTORIA_ATTENDANCE_MIN_INTERVAL_SECONDS') ?: 0.35),
    'monthly_snapshot_storage_dir' => getenv('FTE_MONTHLY_SNAPSHOT_STORAGE_DIR') ?: '',
    'failure_delay_policy' => [
        'policy_version' => 2,
        'short_delay_threshold_minutes' => (float)(getenv('FTE_SHORT_DELAY_THRESHOLD_MINUTES') ?: 5),
        'short_delay_required_compensation_minutes' => (float)(getenv('FTE_SHORT_DELAY_REQUIRED_COMPENSATION_MINUTES') ?: 15),
        'enforce_short_delay_minimum' => filter_var(
            getenv('FTE_ENFORCE_SHORT_DELAY_MINIMUM') ?: '1',
            FILTER_VALIDATE_BOOLEAN
        ),
        // PlannedInterval + cero marcaciones no demuestra por si solo una
        // ausencia injustificada. Se conserva como pendiente nominal y solo
        // puede activarse tras confirmacion de RR.HH.
        'include_full_failures' => filter_var(
            getenv('FTE_INCLUDE_FULL_FAILURES') ?: '0',
            FILTER_VALIDATE_BOOLEAN
        ),
        'include_partial_non_worked' => true,
        'include_early_leave' => true,
    ],
    'overtime_policy' => [
        // RR.HH. definio que el FTE mensual considera horas extra realizadas
        // de lunes a viernes. Sabados y domingos quedan auditados, no sumados.
        'allowed_iso_weekdays' => array_values(array_filter(array_map(
            'intval',
            explode(',', getenv('FTE_OVERTIME_ALLOWED_ISO_WEEKDAYS') ?: '1,2,3,4,5')
        ), static fn(int $day): bool => $day >= 1 && $day <= 7)),
        'exclude_disallowed_weekdays' => true,
        'source_field' => 'AccomplishedExtraTime',
    ],
    // Solo se excluye de la dotacion a quien no puede asignarse a la estructura
    // organizacional. La ausencia en GeoVictoria no elimina a una persona que
    // Buk mantiene vigente: se controla por separado como cobertura pendiente.
    'identity_exclusions' => [
        ['identifier' => '8.993.776-0', 'name' => 'Carlos Ricardo Garrido Hernandez', 'reason' => 'Sin CECO vigente confirmado; fuera de la dotacion FTE hasta regularizar la asignacion.'],
    ],
    'attendance_exclusions' => [
        ['identifier' => '15.615.841-0', 'name' => 'Cesar Esteban Villarroel Valencia', 'reason' => 'Vigente en Buk y sin registro conciliable en GeoVictoria.'],
        ['identifier' => '15.592.785-2', 'name' => 'Gonzalo Igor Sanhueza Aravalé', 'reason' => 'Vigente en Buk y sin registro conciliable en GeoVictoria.'],
        ['identifier' => '10.814.989-2', 'name' => 'Pablo Alejandro Beletti Muñoz', 'reason' => 'Vigente en Buk y sin registro conciliable en GeoVictoria.'],
        ['identifier' => '12.304.560-2', 'name' => 'Silvana Jeannette Parra Cofre', 'reason' => 'Vigente en Buk y sin registro conciliable en GeoVictoria.'],
        ['identifier' => '27.136.626-4', 'name' => 'Tobias Ott', 'reason' => 'Vigente en Buk y sin registro conciliable en GeoVictoria.'],
    ],
    'max_range_days' => 93,
];
