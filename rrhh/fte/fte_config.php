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
    'geovictoria_attendance_batch_size' => 1,
    'geovictoria_attendance_min_interval_seconds' => (float)(getenv('GEOVICTORIA_ATTENDANCE_MIN_INTERVAL_SECONDS') ?: 0.35),
    'max_range_days' => 93,
];
