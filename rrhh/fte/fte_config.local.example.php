<?php
return [
    'buk_base_url' => 'https://grupopatagual.buk.cl',
    'buk_token' => 'token_buk',
    'buk_country' => 'chile',
    'geovictoria_base_url' => 'https://customerapi.geovictoria.com/api/v1',
    'geovictoria_user' => 'usuario_geovictoria',
    'geovictoria_password' => 'clave_geovictoria',
    'geovictoria_attendance_batch_size' => 195,
    'geovictoria_attendance_batch_max_range_days' => 31,
    'geovictoria_attendance_max_records_per_request' => 1500,
    'geovictoria_short_range_timeout_seconds' => 45,
    'geovictoria_month_range_timeout_seconds' => 90,
    'geovictoria_unmatched_cache_ttl_seconds' => 300,
    // Debe estar fuera de la carpeta publica htdocs.
    'monthly_snapshot_storage_dir' => 'C:\\xampp\\portal_data\\fte\\snapshots',
];
