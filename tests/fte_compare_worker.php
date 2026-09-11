<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../rrhh/fte/fte_lib.php';
$report = fte_build_monthly_payload(fte_load_config(), ['period'=>'2026-08','include_attendance'=>'0']);
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
