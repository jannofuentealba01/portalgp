<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/msp/search_helper.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo '[OK] ' . $message . PHP_EOL;
};
$fetchIds = static function (string $sql, array $params) use ($conn): array {
    $stmt = $conn->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
};

$assert(msp2SearchTokens('  ivon   comercial  ') === ['ivon', 'comercial'], 'la consulta se separa en palabras independientes');
$assert(msp2SearchNormalizeComparable('ÓPTICA ROLDÁN') === 'optica roldan', 'la normalización ignora mayúsculas y tildes');
$assert(msp2SearchRelevance('ivon comercial', ['COMERCIAL IVON']) < 4, 'la relevancia admite palabras desordenadas');
$assert((int) $conn->query("SELECT CASE WHEN N'Pérez' COLLATE Modern_Spanish_CI_AI LIKE N'%perez%' THEN 1 ELSE 0 END")->fetchColumn() === 1, 'SQL compara sin distinguir tildes ni mayúsculas');

$contractFrom = "
    FROM dbo.msp_contratos_arriendo c
    INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
    INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
    OUTER APPLY (
        SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),CONCAT(lq.cdo_local,N' ',ISNULL(lq.desc_local,N''))),N' ') texto_locales
        FROM dbo.msp_contrato_locales clq
        INNER JOIN dbo.msp_locales lq ON lq.id_local=clq.id_local
        WHERE clq.id_contrato_arriendo=c.id_contrato_arriendo
    ) busqueda_locales";
$contractFields = [
    't.nombre_comercial', 'a.nombre_locatario', 'a.rut',
    "REPLACE(REPLACE(REPLACE(a.rut,N'.',N''),N'-',N''),N' ',N'')",
    'c.id_contrato_arriendo', 'busqueda_locales.texto_locales',
    "REPLACE(REPLACE(busqueda_locales.texto_locales,N'-',N''),N'.',N'')",
];

$search = msp2BuildSearchCondition('ivon comercial A2', $contractFields, 'test_contract', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $contractFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert(in_array(1, $ids, true), 'Contratos encuentra palabras repartidas entre arrendatario y local');

$search = msp2BuildSearchCondition('optica A-5', $contractFields, 'test_accent', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $contractFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert(in_array(4, $ids, true), 'Contratos encuentra ÓPTICA al escribir optica');

$search = msp2BuildSearchCondition('773662320', $contractFields, 'test_rut', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $contractFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert(in_array(4, $ids, true), 'Contratos encuentra un RUT aunque se escriba sin puntos ni guion');

$search = msp2BuildSearchCondition('#1', $contractFields, 'test_exact', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $contractFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert($ids === [1], '#1 busca el contrato exacto y no contratos que solamente contienen 1');

$localFields = [
    'l.cdo_local', "REPLACE(REPLACE(l.cdo_local,N'-',N''),N'.',N'')", 'l.desc_local', 'e.desc_estado', 'l.id_local',
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),CONCAT(mq.codigo_medidor,N' ',mq.alias_medidor,N' ',mq.numero_serie,N' ',tsq.codigo_servicio,N' ',tsq.nombre_servicio)),N' ') FROM dbo.msp_medidores mq INNER JOIN dbo.msp_tipos_servicio tsq ON tsq.id_tipo_servicio=mq.id_tipo_servicio WHERE mq.id_local=l.id_local)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),CONCAT(tq.nombre_comercial,N' ',aq.nombre_locatario,N' ',aq.rut,N' ',cq.id_contrato_arriendo)),N' ') FROM dbo.msp_contrato_locales clq INNER JOIN dbo.msp_contratos_arriendo cq ON cq.id_contrato_arriendo=clq.id_contrato_arriendo INNER JOIN dbo.msp_tiendas tq ON tq.id_tienda=cq.id_tienda INNER JOIN dbo.msp_arrendatarios aq ON aq.id_arrendatario=cq.id_arrendatario WHERE clq.id_local=l.id_local)",
];
$search = msp2BuildSearchCondition('agua A4', $localFields, 'test_local_meter', 'l.id_local');
$ids = $fetchIds('SELECT l.id_local FROM dbo.msp_locales l INNER JOIN dbo.msp_estado_locales e ON e.id_estado_local=l.id_estado_local WHERE ' . $search['sql'], $search['params']);
$assert(in_array(5, $ids, true), 'Locales encuentra por servicio y código de local');

$search = msp2BuildSearchCondition('ivon A-2', $localFields, 'test_local_tenant', 'l.id_local');
$ids = $fetchIds('SELECT l.id_local FROM dbo.msp_locales l INNER JOIN dbo.msp_estado_locales e ON e.id_estado_local=l.id_estado_local WHERE ' . $search['sql'], $search['params']);
$assert(in_array(2, $ids, true), 'Locales encuentra por arrendatario asociado y código');

$guaranteeCte = "WITH garantia_consolidada AS (
    SELECT MIN(id_garantia) id_garantia,id_contrato_arriendo,nombre_locatario,rut,nombre_comercial,
           STRING_AGG(CONVERT(NVARCHAR(MAX),cdo_local),N' / ') WITHIN GROUP(ORDER BY cdo_local) cdo_local,
           STRING_AGG(CONVERT(NVARCHAR(MAX),ISNULL(desc_local,N'')),N' ') desc_local_busqueda,
           STRING_AGG(CONVERT(NVARCHAR(MAX),id_garantia),N' ') ids_garantia_busqueda
    FROM dbo.msp_vw_garantias_control_integral
    GROUP BY id_contrato_arriendo,nombre_locatario,rut,nombre_comercial
) ";
$guaranteeFields = [
    'g.nombre_locatario', 'g.rut', 'g.nombre_comercial', 'g.cdo_local',
    "REPLACE(REPLACE(g.cdo_local,N'-',N''),N'.',N'')",
    'g.desc_local_busqueda', 'g.id_contrato_arriendo', 'g.ids_garantia_busqueda',
];
$search = msp2BuildSearchCondition('ivon A2', $guaranteeFields, 'test_guarantee_words');
$ids = $fetchIds($guaranteeCte . 'SELECT g.id_contrato_arriendo FROM garantia_consolidada g WHERE ' . $search['sql'], $search['params']);
$assert(in_array(1, $ids, true), 'Garantías evalúa todos los datos consolidados antes de devolver resultados');

$guaranteeId = (int) $conn->query('SELECT TOP(1) id_garantia FROM dbo.msp_vw_garantias_control_integral ORDER BY id_garantia')->fetchColumn();
$guaranteeContract = (int) $conn->query('SELECT TOP(1) id_contrato_arriendo FROM dbo.msp_vw_garantias_control_integral WHERE id_garantia=' . $guaranteeId)->fetchColumn();
$search = msp2BuildSearchCondition(
    '#' . $guaranteeId,
    $guaranteeFields,
    'test_guarantee_exact',
    "EXISTS(SELECT 1 FROM dbo.msp_vw_garantias_control_integral gx WHERE gx.id_contrato_arriendo=g.id_contrato_arriendo AND gx.id_garantia={{id}})"
);
$ids = $fetchIds($guaranteeCte . 'SELECT g.id_contrato_arriendo FROM garantia_consolidada g WHERE ' . $search['sql'], $search['params']);
$assert($ids === [$guaranteeContract], '#número localiza exactamente la garantía solicitada');

$root = dirname(__DIR__);
$sources = [
    'msp/contratos/index.php' => ['msp2BuildSearchCondition', 'busqueda_locales.texto_locales'],
    'msp/garantias/index.php' => ['msp2BuildSearchCondition', 'garantia_consolidada'],
    'msp/locales/index.php' => ['msp2BuildSearchCondition', 'msp_medidores'],
    'msp/cobranza/deudores_exarrendatarios.php' => ['msp2BuildSearchCondition', 'cobranza_buscar'],
    'msp/templates/components/searchable_select.php' => ['window.mspSearch.matches'],
    'msp/templates/components/searchable_multiselect.php' => ['window.mspSearch.matches'],
];
foreach ($sources as $relative => $needles) {
    $source = (string) file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        $assert(str_contains($source, $needle), $relative . ' utiliza el estándar común: ' . $needle);
    }
}
$assert(!str_contains((string) file_get_contents($root . '/msp/garantias/index.php'), 'TOP (100)'), 'Garantías ya no limita candidatos antes de evaluar la búsqueda');

$admin = $conn->query("SELECT TOP(1) u.estado_id,r.nombre_rol FROM dbo.cr_usuarios u JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2'")->fetch(PDO::FETCH_ASSOC);
$assert(is_array($admin) && (int) $admin['estado_id'] === 1 && (string) $admin['nombre_rol'] === 'Administrador', 'admin_2 permanece activo como Administrador');

echo "PASS: {$checks}/{$checks} comprobaciones.\n";
