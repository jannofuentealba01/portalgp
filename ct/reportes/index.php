<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

ctRequireAccess('CT');

$pageTitle = 'Reportes CT';
$pageSubtitle = 'Consultas transversales por capa y seguimiento operacional.';
$pageDescription = 'Pantalla base para consolidar reportes del modulo CT. Puedes partir reutilizando consultas de ct/db/90_ct_consultas.sql.';
$highlights = [
    ['label' => 'Fuente', 'value' => 'ct/db/90_ct_consultas.sql'],
    ['label' => 'Cobertura', 'value' => 'Predial, Construccion, Tributaria, Contabilidad'],
    ['label' => 'Salida', 'value' => 'Vista web, exportable y resumen ejecutivo'],
];

require dirname(__DIR__) . '/templates/module_shell.php';

