<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

ctRequireAccess('CT');

$pageTitle = 'Capa Tributaria';
$pageSubtitle = 'Avaluos, contribuciones, estados SII y exenciones.';
$pageDescription = 'Pantalla base para construir los procesos tributarios del modulo CT en base a la capa 30 y sus procedimientos de la capa 31.';
$highlights = [
    ['label' => 'Avaluo', 'value' => 'ct_avaluo_terreno'],
    ['label' => 'Contribucion', 'value' => 'ct_contribucion_terreno'],
    ['label' => 'SII', 'value' => 'ct_estado_sii, ct_destino_sii, ct_condicion_rol'],
];

require dirname(__DIR__) . '/templates/module_shell.php';

