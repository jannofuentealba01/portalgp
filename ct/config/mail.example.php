<?php
declare(strict_types=1);

return [
    'enabled' => true,
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'port' => '587',
        'secure' => 'tls',
        'user' => 'tu_cuenta@gmail.com',
        'pass' => '',
        'from_address' => 'tu_cuenta@gmail.com',
        'from_name' => 'CT Solicitudes',
    ],
    'demo' => [
        // Si lo dejas con correo válido, TODOS los envíos CT irán a este destino (modo pruebas).
        'to' => '',
    ],
    'blocked_domains' => [
        // Evita enviar a dominios corporativos en demo.
        'grupopatagual.cl',
    ],
];
