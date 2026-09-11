<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
$mode = (string)($argv[1] ?? '');
$query = json_decode((string)($argv[2] ?? '{}'), true);
if (!is_array($query)) {
    throw new InvalidArgumentException('Consulta de prueba inválida.');
}

$routes = [
    'arrendatarios_template' => 'msp/arrendatarios/plantilla.php',
    'tiendas_template' => 'msp/tiendas/plantilla.php',
    'locales_template' => 'msp/locales/plantilla.php',
    'medidores_template' => 'msp/medidores/plantilla.php',
    'medidores_import_template' => 'msp/medidores/plantilla_import.php',
    'pagos_contrato_template' => 'msp/cobranza/plantilla_pagos_contrato.php',
    'lecturas_template' => 'msp/cobros/plantilla_lecturas.php',
    'pagos_backup' => 'msp/pagos/exportar_respaldo.php',
    'agua_report' => 'msp/cobros/reporte_consumo_agua.php',
    'luz_report' => 'msp/cobros/reporte_consumo_electrico.php',
    'gas_report' => 'msp/cobros/reporte_consumo_gas.php',
    'aging_pdf' => 'msp/contabilidad/aging_pdf.php',
    'terceros_template' => 'ct/predial/terceros/index.php',
];
if (!isset($routes[$mode])) {
    throw new InvalidArgumentException('Exportación de prueba no permitida.');
}

session_set_save_handler(new class implements SessionHandlerInterface {
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $max_lifetime): int|false { return 0; }
});
session_start();

require $root . '/db.php';
$userStmt = $conn->query("SELECT TOP 1 u.id,u.UserName,u.nombre_completo,u.correo_electronico,u.rol_id,u.security_version,r.nombre_rol FROM dbo.cr_usuarios u LEFT JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2' AND u.estado_id=1");
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($user)) {
    throw new RuntimeException('admin_2 no está habilitado para la prueba.');
}
$user['roles'] = [(string)($user['nombre_rol'] ?? 'Administrador')];
$_SESSION = ['usuario' => $user, 'pgp_security_version' => (int)$user['security_version']];

$_GET = $query;
$_POST = [];
$_FILES = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/portalgp/' . $routes[$mode];
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] . ($query === [] ? '' : '?' . http_build_query($query));
ini_set('display_errors', '0');

require $root . '/' . $routes[$mode];
