<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

ctRequireAccess('CT');

$pageTitle = 'Capa Construccion';
$pageSubtitle = 'Proyectos, construcciones, permisos y estados de recepcion.';
$pageDescription = 'Pantalla base para implementar los flujos de construccion sobre las tablas de la capa 20 y sus procedimientos de la capa 21.';
$highlights = [
    ['label' => 'Proyecto', 'value' => 'ct_proyecto_construccion'],
    ['label' => 'Construcciones', 'value' => 'ct_construccion, ct_construccion_estado'],
    ['label' => 'Permisos', 'value' => 'ct_permiso_recepcion, ct_inspector_municipal'],
];

require dirname(__DIR__) . '/templates/module_shell.php';

