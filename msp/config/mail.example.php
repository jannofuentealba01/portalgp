<?php
declare(strict_types=1);

return [
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => '587',
        'secure' => 'tls',
        'user' => 'tu_cuenta@gmail.com',
        'pass' => 'tu_app_password',
        'from_address' => 'tu_cuenta@gmail.com',
        'from_name' => 'MSP Cobros Demo',
    ],
    'demo' => [
        'to' => 'tu_otra_cuenta@gmail.com',
    ],
];
