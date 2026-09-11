<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/msp/pagos/respaldo_excel_helper.php';

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

$payment = $conn->query(
    "SELECT TOP(1) p.id_pago,p.medio_pago,t.nombre_comercial,dc.nombre_arrendatario_snapshot
     FROM dbo.msp_pagos p
     INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=p.id_documento_cobro
     INNER JOIN dbo.msp_tiendas t ON t.id_tienda=dc.id_tienda
     ORDER BY p.id_pago"
)->fetch(PDO::FETCH_ASSOC);
$assert(is_array($payment), 'existe al menos un pago operativo para probar la búsqueda');
$paymentFilters = msp2PagosNormalizeFilters(['filtroGeneral' => '#' . (int) $payment['id_pago']]);
$paymentSearch = msp2PagosBuildFilters($paymentFilters);
$ids = $fetchIds(
    "SELECT p.id_pago
     FROM dbo.msp_pagos p
     INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=p.id_documento_cobro
     INNER JOIN dbo.msp_tiendas t ON t.id_tienda=dc.id_tienda
     WHERE {$paymentSearch['where']}",
    $paymentSearch['params']
);
$assert($ids === [(int) $payment['id_pago']], 'Pagos interpreta #ID como coincidencia exacta');
$tenantToken = preg_split('/\s+/u', trim((string) $payment['nombre_arrendatario_snapshot']), 2)[0] ?? '';
$mediumToken = preg_split('/\s+/u', trim((string) $payment['medio_pago']), 2)[0] ?? '';
$paymentSearch = msp2PagosBuildFilters(msp2PagosNormalizeFilters([
    'filtroGeneral' => trim($mediumToken . ' ' . $tenantToken),
]));
$ids = $fetchIds(
    "SELECT p.id_pago
     FROM dbo.msp_pagos p
     INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=p.id_documento_cobro
     INNER JOIN dbo.msp_tiendas t ON t.id_tienda=dc.id_tienda
     WHERE {$paymentSearch['where']}",
    $paymentSearch['params']
);
$assert(in_array((int) $payment['id_pago'], $ids, true), 'Pagos combina palabras de medio de pago y arrendatario');

$closureFields = [
    'c.id_contrato_arriendo', 't.nombre_comercial', 'a.nombre_locatario', 'a.rut',
    "REPLACE(REPLACE(REPLACE(a.rut,N'.',N''),N'-',N''),N' ',N'')",
    'loc.locales', "REPLACE(REPLACE(loc.locales,N'-',N''),N'.',N'')",
];
$closureFrom = "
    FROM dbo.msp_contratos_arriendo c
    INNER JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
    INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario
    OUTER APPLY (
        SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),z.cdo_local),N' / ') WITHIN GROUP (ORDER BY z.cdo_local) locales
        FROM (SELECT DISTINCT l.cdo_local FROM dbo.msp_contrato_locales cl INNER JOIN dbo.msp_locales l ON l.id_local=cl.id_local WHERE cl.id_contrato_arriendo=c.id_contrato_arriendo) z
    ) loc";
$search = msp2BuildSearchCondition('optica A5', $closureFields, 'stage2_close', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $closureFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert(in_array(4, $ids, true), 'Término y cierre busca sin tildes y por local');
$search = msp2BuildSearchCondition('#4', $closureFields, 'stage2_close_exact', 'c.id_contrato_arriendo');
$ids = $fetchIds('SELECT c.id_contrato_arriendo ' . $closureFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert($ids === [4], 'Término y cierre localiza un contrato exacto con #ID');

$storeFields = [
    't.id_tienda', 't.nombre_comercial', 'a.nombre_locatario',
    "REPLACE(REPLACE(REPLACE(ISNULL(a.rut,N''),N'.',N''),N'-',N''),N' ',N'')",
    'r.nombre_rubro', 'e.desc_estado',
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),CONCAT(ls.cdo_local,N' ',ISNULL(ls.desc_local,N''))),N' ') FROM dbo.msp_ocupacion_locales os INNER JOIN dbo.msp_locales ls ON ls.id_local=os.id_local WHERE os.id_tienda=t.id_tienda)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),REPLACE(REPLACE(ls.cdo_local,N'-',N''),N'.',N'')),N' ') FROM dbo.msp_ocupacion_locales os INNER JOIN dbo.msp_locales ls ON ls.id_local=os.id_local WHERE os.id_tienda=t.id_tienda)",
];
$storeFrom = ' FROM dbo.msp_tiendas t INNER JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=t.id_arrendatario INNER JOIN dbo.msp_rubros r ON r.id_rubro=t.id_rubro INNER JOIN dbo.msp_estado_tiendas e ON e.id_estado_tienda=t.id_estado_tienda';
$search = msp2BuildSearchCondition('ivon A2', $storeFields, 'stage2_store', 't.id_tienda');
$ids = $fetchIds('SELECT t.id_tienda' . $storeFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert(in_array(1, $ids, true), 'Tiendas combina arrendatario y local en cualquier orden');
$search = msp2BuildSearchCondition('#1', $storeFields, 'stage2_store_exact', 't.id_tienda');
$ids = $fetchIds('SELECT t.id_tienda' . $storeFrom . ' WHERE ' . $search['sql'], $search['params']);
$assert($ids === [1], 'Tiendas diferencia #1 de identificadores que contienen el dígito 1');

$tenantFields = [
    'a.id_arrendatario', 'a.nombre_locatario', 'a.nombre_representante',
    "REPLACE(REPLACE(REPLACE(ISNULL(a.rut,N''),N'.',N''),N'-',N''),N' ',N'')",
    'a.direccion',
    "(SELECT cs.desc_comuna FROM dbo.msp_comunas cs WHERE cs.id_comuna=a.id_comuna)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),acs.correo),N' ') FROM dbo.msp_arrendatarios_correos acs WHERE acs.id_arrendatario=a.id_arrendatario)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),ats.telefono),N' ') FROM dbo.msp_arrendatarios_telefonos ats WHERE ats.id_arrendatario=a.id_arrendatario)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),CONCAT(ts.nombre_comercial,N' ',cas.id_contrato_arriendo,N' ',ls.cdo_local,N' ',ISNULL(ls.desc_local,N''))),N' ') FROM dbo.msp_tiendas ts INNER JOIN dbo.msp_contratos_arriendo cas ON cas.id_tienda=ts.id_tienda INNER JOIN dbo.msp_contrato_locales cls ON cls.id_contrato_arriendo=cas.id_contrato_arriendo INNER JOIN dbo.msp_locales ls ON ls.id_local=cls.id_local WHERE ts.id_arrendatario=a.id_arrendatario)",
    "(SELECT STRING_AGG(CONVERT(NVARCHAR(MAX),REPLACE(REPLACE(ls.cdo_local,N'-',N''),N'.',N'')),N' ') FROM dbo.msp_tiendas ts INNER JOIN dbo.msp_contratos_arriendo cas ON cas.id_tienda=ts.id_tienda INNER JOIN dbo.msp_contrato_locales cls ON cls.id_contrato_arriendo=cas.id_contrato_arriendo INNER JOIN dbo.msp_locales ls ON ls.id_local=cls.id_local WHERE ts.id_arrendatario=a.id_arrendatario)",
];
$search = msp2BuildSearchCondition('ivon A2', $tenantFields, 'stage2_tenant', 'a.id_arrendatario');
$ids = $fetchIds('SELECT a.id_arrendatario FROM dbo.msp_arrendatarios a WHERE ' . $search['sql'], $search['params']);
$assert(in_array(1, $ids, true), 'Arrendatarios busca por nombre y local asociado');
$search = msp2BuildSearchCondition('#1', $tenantFields, 'stage2_tenant_exact', 'a.id_arrendatario');
$ids = $fetchIds('SELECT a.id_arrendatario FROM dbo.msp_arrendatarios a WHERE ' . $search['sql'], $search['params']);
$assert($ids === [1], 'Arrendatarios admite acceso exacto por #ID');

$meter = $conn->query('SELECT TOP(1) id_medidor FROM dbo.msp_medidores ORDER BY id_medidor')->fetchColumn();
$assert((int) $meter > 0, 'existe al menos un medidor para probar el catálogo');
$meterFields = ['m.id_medidor', 'm.codigo_medidor', 'm.alias_medidor', 'm.numero_serie', 'l.cdo_local', "REPLACE(REPLACE(l.cdo_local,N'-',N''),N'.',N'')", 'l.desc_local', 'ts.codigo_servicio', 'ts.nombre_servicio'];
$search = msp2BuildSearchCondition('#' . (int) $meter, $meterFields, 'stage2_meter', 'm.id_medidor');
$ids = $fetchIds('SELECT m.id_medidor FROM dbo.msp_medidores m INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio WHERE ' . $search['sql'], $search['params']);
$assert($ids === [(int) $meter], 'Medidores admite coincidencia exacta por #ID');
$search = msp2BuildSearchCondition('agua A4', $meterFields, 'stage2_meter_words', 'm.id_medidor');
$ids = $fetchIds('SELECT m.id_medidor FROM dbo.msp_medidores m INNER JOIN dbo.msp_locales l ON l.id_local=m.id_local INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio=m.id_tipo_servicio WHERE ' . $search['sql'], $search['params']);
$assert($ids !== [], 'Medidores combina servicio y local en una consulta');

$bank = $conn->query('SELECT TOP(1) id_banco,nombre_banco FROM dbo.msp_bancos ORDER BY id_banco')->fetch(PDO::FETCH_ASSOC);
$assert(is_array($bank), 'existe al menos un banco para probar el catálogo');
$search = msp2BuildSearchCondition('#' . (int) $bank['id_banco'], ['id_banco', 'nombre_banco', 'codigo_banco'], 'stage2_bank', 'id_banco');
$ids = $fetchIds('SELECT id_banco FROM dbo.msp_bancos WHERE ' . $search['sql'], $search['params']);
$assert($ids === [(int) $bank['id_banco']], 'Bancos admite coincidencia exacta por #ID');

$root = dirname(__DIR__);
$sources = [
    'msp/pagos/index.php' => ['filtroGeneral', 'ivon transferencia, RUT, documento o #25'],
    'msp/pagos/respaldo_excel_helper.php' => ['msp2BuildSearchCondition', "'p.id_pago'"],
    'msp/documentos_cobro/index.php' => ['data-documento-search', 'window.mspSearch.matches', 'buscarDocumentoVisible'],
    'msp/cierre/index.php' => ['msp2BuildSearchCondition', '$activosVisibles = $activos', '$cerradosVisibles = $cerrados'],
    'msp/cierre_mensual/index.php' => ['msp2SearchQuery', 'periodo_facturacion, 126) = :filtro'],
    'msp/catalogos/medidores.php' => ['msp2BuildSearchCondition', "'m.id_medidor'"],
    'msp/catalogos/bancos.php' => ['msp2BuildSearchCondition', "'id_banco'"],
    'msp/catalogos/feriados.php' => ['msp2BuildSearchCondition', 'CONVERT(CHAR(10),fecha,126)'],
    'msp/rubros/index.php' => ['msp2BuildSearchCondition', "'id_rubro'"],
    'msp/comunas/index.php' => ['msp2BuildSearchCondition', "'id_comuna'"],
    'msp/estados_arrendatarios/index.php' => ['msp2BuildSearchCondition', "'id_estado_arrendatario'"],
    'msp/estados_tiendas/index.php' => ['msp2BuildSearchCondition', "'id_estado_tienda'"],
    'msp/estados_locales/index.php' => ['msp2BuildSearchCondition', "'id_estado_local'"],
    'msp/tiendas/index.php' => ['msp2BuildSearchCondition', 'window.mspSearch.matches'],
    'msp/arrendatarios/index.php' => ['filtroGeneral', 'msp2BuildSearchCondition'],
];
foreach ($sources as $relative => $needles) {
    $source = (string) file_get_contents($root . '/' . $relative);
    foreach ($needles as $needle) {
        $assert(str_contains($source, $needle), $relative . ' usa el estándar común: ' . $needle);
    }
}
$closureSource = (string) file_get_contents($root . '/msp/cierre/index.php');
$assert(!str_contains($closureSource, 'array_slice($activos'), 'Término y cierre no corta silenciosamente contratos activos');
$assert(!str_contains($closureSource, 'array_slice($cerrados'), 'Término y cierre no corta silenciosamente contratos cerrados');
$monthlySource = (string) file_get_contents($root . '/msp/cierre_mensual/index.php');
$assert(!str_contains($monthlySource, 'LIKE :filtro'), 'Cierre mensual no usa coincidencia parcial para períodos');
$assert(str_contains((string) file_get_contents($root . '/msp/pagos/index.php'), 'OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY'), 'Pagos conserva paginación explícita sin omitir resultados');
$assert(str_contains((string) file_get_contents($root . '/msp/tiendas/index.php'), 'OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY'), 'Tiendas conserva paginación explícita');
$assert(str_contains((string) file_get_contents($root . '/msp/arrendatarios/index.php'), 'OFFSET :offset ROWS FETCH NEXT :lineas ROWS ONLY'), 'Arrendatarios conserva paginación explícita');

$admin = $conn->query("SELECT TOP(1) u.estado_id,r.nombre_rol FROM dbo.cr_usuarios u JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2'")->fetch(PDO::FETCH_ASSOC);
$assert(is_array($admin) && (int) $admin['estado_id'] === 1 && (string) $admin['nombre_rol'] === 'Administrador', 'admin_2 permanece activo como Administrador');

echo "PASS: {$checks}/{$checks} comprobaciones.\n";
