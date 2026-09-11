<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/msp/bootstrap.php';
require_once dirname(__DIR__) . '/msp/templates/components/searchable_select.php';
require_once dirname(__DIR__) . '/ct/contabilidad/comercial_repository.php';
require_once dirname(__DIR__) . '/ct/predial/terceros/terceros_repository.php';
require_once dirname(__DIR__) . '/ct/predial/terrenos/terrenos_repository.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo 'OK: ' . $message . PHP_EOL;
};
$rejects = static function (callable $operation): bool {
    try {
        $operation();
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};

$assert(msp2SqlIdentifier('dbo.msp_locales') === '[dbo].[msp_locales]', 'identificadores SQL válidos se delimitan');
$assert($rejects(static fn() => msp2SqlIdentifier('dbo.msp_locales; DROP TABLE x')), 'identificadores SQL con instrucciones se rechazan');
$assert($rejects(static fn() => msp2SqlIdentifier('dbo.[msp_locales]')), 'no se aceptan delimitadores proporcionados por el llamador');
$assert(str_contains(msp2LocalCodeNaturalOrderSql('l.cdo_local'), 'l.cdo_local'), 'orden natural acepta una columna simple');
$assert($rejects(static fn() => msp2LocalCodeNaturalOrderSql('l.cdo_local DESC; DROP TABLE x')), 'orden natural rechaza expresiones arbitrarias');
$assert($rejects(static fn() => ctComercialRepoListTerrenos($conn, [], 't.id_terreno; DROP TABLE x', 0, 10)), 'repositorio comercial rechaza orden SQL no permitido');
$assert($rejects(static fn() => ctTercerosRepoList($conn, [], 'rut; DROP TABLE x', 0, 10)), 'repositorio de terceros rechaza orden SQL no permitido');
$assert($rejects(static fn() => ctTerrenosRepoList($conn, [], 't.rol_asignado; DROP TABLE x', 0, 10)), 'repositorio de terrenos rechaza orden SQL no permitido');

$payload = '<img src=x onerror=alert(1)>';
ob_start();
msp2RenderSearchableSelectField([
    'input_name' => 'security_test',
    'input_id' => 'security_test',
    'options' => [[
        'value' => $payload,
        'label' => $payload,
        // A string is deliberately not considered trusted HTML.
        'label_html' => $payload,
        'attrs' => ['source' => $payload],
    ]],
]);
$rendered = (string) ob_get_clean();
$assert(!str_contains($rendered, $payload), 'el selector no imprime HTML no confiable');
$assert(str_contains($rendered, '&lt;img src=x onerror=alert(1)&gt;'), 'el selector escapa texto y atributos');

ob_start();
msp2RenderSearchableSelectField([
    'input_name' => 'trusted_test',
    'input_id' => 'trusted_test',
    'options' => [[
        'value' => '1',
        'label' => 'Local A-1',
        'label_html' => msp2SearchableSelectTrustedHtml('<strong>Local A-1</strong>'),
    ]],
]);
$trustedRendered = (string) ob_get_clean();
$assert(str_contains($trustedRendered, '<strong>Local A-1</strong>'), 'el marcado interno explícitamente confiable conserva su formato');

$root = dirname(__DIR__);
$mustNotContain = [
    'crear_usuario.php' => ["<?= \$rol['nombre_rol'] ?>"],
    'editar_usuario.php' => ["<?= \$rol['nombre_rol'] ?>", "<?= \$estado['estado'] ?>"],
    'msp/garantias/reversas.php' => ['<?php echo $error;?>'],
    'msp/contabilidad/submayor_garantias.php' => ['<?php echo $error;?>'],
];
foreach ($mustNotContain as $relative => $needles) {
    $source = (string) file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        $assert(!str_contains($source, $needle), $relative . ' no conserva la salida vulnerable auditada');
    }
}

$admin = $conn->query("SELECT u.id,u.estado_id,u.rol_id,
    (SELECT COUNT(*) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) AS permisos
    FROM dbo.cr_usuarios u WHERE u.UserName=N'admin_2'")->fetch(PDO::FETCH_ASSOC);
$assert(is_array($admin) && (int) $admin['id'] > 0, 'admin_2 continúa existiendo');
$assert((int) $admin['estado_id'] === 1, 'admin_2 continúa habilitado');
$assert((int) $admin['rol_id'] === 1 && (int) $admin['permisos'] >= 22, 'admin_2 conserva su rol y no pierde permisos');

echo 'RESULTADO: ' . $checks . ' comprobaciones correctas.' . PHP_EOL;
