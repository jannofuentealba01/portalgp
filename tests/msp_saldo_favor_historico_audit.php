<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once dirname(__DIR__) . '/db.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $checks++;
    echo '[OK] ' . $message . PHP_EOL;
};
$scalar = static fn(string $sql): int => (int) $conn->query($sql)->fetchColumn();
$mustFail = static function (callable $operation, string $message) use ($conn, $assert): void {
    $failed = false;
    try {
        $conn->beginTransaction();
        $operation();
        $conn->rollBack();
    } catch (Throwable) {
        $failed = true;
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
    }
    $assert($failed, $message);
};

$assert(
    $scalar("SELECT COUNT(*) FROM sys.tables WHERE object_id=OBJECT_ID(N'dbo.msp_saldo_favor_auditoria_historica')") === 1,
    'existe el registro de auditoría histórica'
);

$orphansSql = "
    SELECT COUNT(*)
    FROM dbo.msp_movimientos_saldo_favor_tienda m
    LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago
    LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro
    WHERE (m.id_pago IS NOT NULL AND p.id_pago IS NULL)
       OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL)";
$assert($scalar($orphansSql) === 60, 'los 60 movimientos históricos identificados permanecen conservados');

$assert(
    $scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_auditoria_historica") === 60,
    'los 60 movimientos tienen clasificación individual e inmutable'
);
$assert(
    $scalar("SELECT COUNT(DISTINCT CASE WHEN id_movimiento_saldo_favor<id_movimiento_contrapartida THEN CONCAT(id_movimiento_saldo_favor,N':',id_movimiento_contrapartida) ELSE CONCAT(id_movimiento_contrapartida,N':',id_movimiento_saldo_favor) END) FROM dbo.msp_saldo_favor_auditoria_historica") === 30,
    'los movimientos forman 30 pares únicos'
);
$assert(
    $scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_auditoria_historica WHERE clasificacion=N'APLICACION_COMPENSADA'") === 50,
    '25 pares corresponden a aplicaciones compensadas'
);
$assert(
    $scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_auditoria_historica WHERE clasificacion=N'EXCEDENTE_COMPENSADO'") === 10,
    '5 pares corresponden a excedentes compensados'
);

$assert(
    $scalar("
        SELECT COUNT(*)
        FROM dbo.msp_saldo_favor_auditoria_historica a
        LEFT JOIN dbo.msp_saldo_favor_auditoria_historica b
          ON b.id_movimiento_saldo_favor=a.id_movimiento_contrapartida
        WHERE b.id_movimiento_saldo_favor IS NULL
           OR b.id_movimiento_contrapartida<>a.id_movimiento_saldo_favor
           OR b.id_tienda<>a.id_tienda
           OR b.id_pago_historico<>a.id_pago_historico
           OR b.id_documento_historico<>a.id_documento_historico
           OR ABS(a.monto_movimiento+b.monto_movimiento)>0.005
           OR b.tipo_movimiento<>CASE a.tipo_movimiento WHEN 1 THEN 3 WHEN 2 THEN 4 WHEN 3 THEN 1 WHEN 4 THEN 2 END") === 0,
    'cada registro apunta a una contrapartida recíproca y exacta'
);
$assert(
    $scalar("
        SELECT COUNT(*)
        FROM (
            SELECT id_tienda,id_pago_historico,id_documento_historico,SUM(monto_movimiento) neto
            FROM dbo.msp_saldo_favor_auditoria_historica
            GROUP BY id_tienda,id_pago_historico,id_documento_historico
            HAVING ABS(SUM(monto_movimiento))>0.005
        ) x") === 0,
    'todos los pares tienen efecto financiero neto cero'
);
$assert(
    $scalar("
        SELECT COUNT(*)
        FROM dbo.msp_movimientos_saldo_favor_tienda m
        LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago
        LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro
        LEFT JOIN dbo.msp_saldo_favor_auditoria_historica a ON a.id_movimiento_saldo_favor=m.id_movimiento_saldo_favor
        WHERE ((m.id_pago IS NOT NULL AND p.id_pago IS NULL)
            OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL))
          AND a.id_auditoria IS NULL") === 0,
    'no queda ningún movimiento histórico huérfano sin auditar'
);

$assert(
    $scalar("
        SELECT COUNT(*)
        FROM dbo.msp_saldo_favor_auditoria_historica h
        JOIN dbo.msp_acc_asientos a
          ON a.tabla_origen=N'msp_movimientos_saldo_favor_tienda'
         AND a.id_origen=h.id_movimiento_saldo_favor
         AND a.estado_asiento=1
        WHERE h.tipo_movimiento=2") === 0,
    'ninguna aplicación histórica ya revertida conserva un asiento activo'
);
$assert(
    $scalar("
        SELECT COUNT(*)
        FROM dbo.msp_saldo_favor_auditoria_historica h
        WHERE h.tipo_movimiento=2
          AND NOT EXISTS(
              SELECT 1 FROM dbo.msp_acc_asientos a
              WHERE a.tabla_origen=N'msp_movimientos_saldo_favor_tienda'
                AND a.id_origen=h.id_movimiento_saldo_favor
                AND a.estado_asiento=2
          )") === 0,
    'las 25 aplicaciones conservan su asiento original anulado'
);
$assert(
    $scalar("
        SELECT COUNT(*)
        FROM dbo.msp_saldo_favor_auditoria_historica h
        WHERE h.tipo_movimiento=2
          AND NOT EXISTS(
              SELECT 1
              FROM dbo.msp_acc_asientos original
              JOIN dbo.msp_acc_asientos reversa
                ON reversa.id_asiento_reversado=original.id_asiento_contable
               AND reversa.estado_asiento=3
              WHERE original.tabla_origen=N'msp_movimientos_saldo_favor_tienda'
                AND original.id_origen=h.id_movimiento_saldo_favor
                AND original.estado_asiento=2
          )") === 0,
    'las 25 aplicaciones poseen una contrapartida contable de reversa'
);
$assert(
    $scalar("SELECT COUNT(*) FROM dbo.msp_saldos_favor_tienda s OUTER APPLY(SELECT SUM(m.monto_movimiento) total FROM dbo.msp_movimientos_saldo_favor_tienda m WHERE m.id_tienda=s.id_tienda)x WHERE ABS(s.saldo_disponible-ISNULL(x.total,0))>0.005") === 0,
    'los saldos por tienda continúan coincidiendo con el libro de movimientos'
);
$assert(
    $scalar("SELECT COUNT(*) FROM dbo.msp_saldo_favor_auditoria_historica a LEFT JOIN dbo.msp_referencias_financieras_historicas r ON r.tipo_origen=N'SALDO_FAVOR_MOVIMIENTO' AND r.id_origen=a.id_movimiento_saldo_favor WHERE r.id_referencia_historica IS NULL OR r.referencia NOT LIKE N'%efecto neto $0%'") === 0,
    'cada movimiento conserva una referencia histórica explicativa'
);
$assert(
    $scalar("SELECT COUNT(*) FROM sys.triggers WHERE name=N'TR_msp_saldo_favor_auditoria_historica_inmutable' AND is_disabled=0") === 1,
    'el registro de auditoría tiene protección de inmutabilidad activa'
);

$mustFail(function () use ($conn): void {
    $id = (int) $conn->query('SELECT TOP(1) id_auditoria FROM dbo.msp_saldo_favor_auditoria_historica ORDER BY id_auditoria')->fetchColumn();
    $stmt = $conn->prepare('UPDATE dbo.msp_saldo_favor_auditoria_historica SET evidencia=evidencia WHERE id_auditoria=:id');
    $stmt->execute([':id' => $id]);
}, 'la auditoría histórica no admite modificaciones');

$admin = $conn->query("SELECT TOP(1) u.id,u.estado_id,u.security_version,r.nombre_rol,(SELECT COUNT(DISTINCT rp.permiso_id) FROM dbo.cr_rol_permisos rp WHERE rp.rol_id=u.rol_id) permisos FROM dbo.cr_usuarios u JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2'")->fetch();
$assert(
    is_array($admin)
    && (int) $admin['id'] === 1030
    && (int) $admin['estado_id'] === 1
    && (int) $admin['security_version'] === 0
    && (string) $admin['nombre_rol'] === 'Administrador'
    && (int) $admin['permisos'] >= 22,
    'admin_2 permanece activo y con rol Administrador'
);

echo "PASS: {$checks}/{$checks} comprobaciones.\n";
