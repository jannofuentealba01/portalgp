<?php
declare(strict_types=1);

require dirname(__DIR__) . '/db.php';

$passed = 0;
$failed = 0;
$check = static function (string $label, callable $assertion) use (&$passed, &$failed): void {
    try {
        if (!$assertion()) {
            throw new RuntimeException('La condición no se cumplió.');
        }
        ++$passed;
        echo "[OK] $label\n";
    } catch (Throwable $e) {
        ++$failed;
        echo "[FAIL] $label: {$e->getMessage()}\n";
    }
};

$identity = $conn->query("SELECT SUSER_SNAME() login_name,IS_SRVROLEMEMBER('sysadmin') sysadmin,IS_MEMBER('db_owner') db_owner,HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','CREATE TABLE') create_table,HAS_PERMS_BY_NAME(N'dbo.msp_tesoreria_solicitar_reapertura_caja','OBJECT','EXECUTE') execute_runtime,HAS_PERMS_BY_NAME(N'dbo.msp_schema_migrations','OBJECT','UPDATE') update_registry")->fetch(PDO::FETCH_ASSOC);
$check('La aplicación usa la cuenta técnica', fn(): bool => ($identity['login_name'] ?? '') === 'portalgp_runtime');
$check('La cuenta técnica no es sysadmin', fn(): bool => (int) ($identity['sysadmin'] ?? 1) === 0);
$check('La cuenta técnica no es db_owner', fn(): bool => (int) ($identity['db_owner'] ?? 1) === 0);
$check('La cuenta técnica no puede crear tablas', fn(): bool => (int) ($identity['create_table'] ?? 1) === 0);
$check('La cuenta técnica puede ejecutar procedimientos operativos', fn(): bool => (int) ($identity['execute_runtime'] ?? 0) === 1);
$check('La cuenta técnica no puede alterar el registro de migraciones', fn(): bool => (int) ($identity['update_registry'] ?? 1) === 0);

$permission = $conn->query("SELECT id FROM dbo.cr_permisos WHERE nombre_permiso=N'MSP Tesoreria'")->fetchColumn();
$check('Existe el permiso MSP Tesoreria', fn(): bool => (int) $permission > 0);

$admin = $conn->query("SELECT u.id,u.estado_id,u.rol_id,u.security_version,r.nombre_rol FROM dbo.cr_usuarios u INNER JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName=N'admin_2'")->fetch(PDO::FETCH_ASSOC);
$check('admin_2 sigue activo y con rol Administrador', fn(): bool => (int) ($admin['id'] ?? 0) === 1030 && (int) ($admin['estado_id'] ?? 0) === 1 && ($admin['nombre_rol'] ?? '') === 'Administrador');
$adminPermission = $conn->query("SELECT rp.lectura,rp.escritura,rp.eliminacion FROM dbo.cr_usuarios u INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id INNER JOIN dbo.cr_permisos p ON p.id=rp.permiso_id WHERE u.UserName=N'admin_2' AND p.nombre_permiso=N'MSP Tesoreria'")->fetch(PDO::FETCH_ASSOC);
$check('admin_2 tiene Tesorería completa', fn(): bool => (int) ($adminPermission['lectura'] ?? 0) === 1 && (int) ($adminPermission['escritura'] ?? 0) === 1 && (int) ($adminPermission['eliminacion'] ?? 0) === 1);

foreach (['msp_tesoreria_solicitar_reapertura_caja', 'msp_tesoreria_resolver_reapertura_caja', 'msp_tesoreria_reabrir_caja'] as $procedure) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM sys.procedures WHERE schema_id=SCHEMA_ID(N'dbo') AND name=:name");
    $stmt->execute([':name' => $procedure]);
    $check("Existe el procedimiento $procedure", fn(): bool => (int) $stmt->fetchColumn() === 1);
}
$check('Existe la tabla de solicitudes de reapertura', fn(): bool => (int) $conn->query("SELECT COUNT(*) FROM sys.tables WHERE schema_id=SCHEMA_ID(N'dbo') AND name=N'msp_tesoreria_solicitudes_reapertura_caja'")->fetchColumn() === 1);

$resolverDefinition = (string) $conn->query("SELECT OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_tesoreria_resolver_reapertura_caja'))")->fetchColumn();
$check('La resolución impide autoaprobar', fn(): bool => str_contains($resolverDefinition, '@id_solicitante = @id_usuario_resuelve'));
$check('La resolución exige permiso de eliminación', fn(): bool => str_contains($resolverDefinition, 'rp.eliminacion = 1'));
$directDefinition = (string) $conn->query("SELECT OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_tesoreria_reabrir_caja'))")->fetchColumn();
$check('La reapertura directa quedó deshabilitada', fn(): bool => str_contains($directDefinition, 'flujo de solicitud y resolución'));

$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/msp/bootstrap.php');
$page = (string) file_get_contents(dirname(__DIR__) . '/msp/tesoreria/reaperturas.php');
$check('Las rutas de Tesorería usan permiso propio', fn(): bool => str_contains($bootstrap, "'/msp/tesoreria/'") && str_contains($bootstrap, "return 'MSP Tesoreria'"));
$check('La interfaz ya no permite escoger un autorizador', fn(): bool => !str_contains($page, 'id_usuario_autoriza'));
$check('La interfaz resuelve con la sesión del aprobador', fn(): bool => str_contains($page, 'resolver_reapertura_caja.php'));

$check('El flujo completo solicita, aprueba, reabre y audita de forma transaccional', function () use ($conn): bool {
    $idCaja = (int) $conn->query("SELECT TOP(1) id_cuenta_tesoreria FROM dbo.msp_tesoreria_cuentas WHERE tipo_cuenta=N'CAJA' AND activo=1 ORDER BY id_cuenta_tesoreria")->fetchColumn();
    $idSolicitante = (int) $conn->query("SELECT id FROM dbo.cr_usuarios WHERE UserName=N'admin_2' AND estado_id=1")->fetchColumn();
    $resolverStmt = $conn->prepare("SELECT TOP(1)u.id FROM dbo.cr_usuarios u INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id=u.rol_id INNER JOIN dbo.cr_permisos p ON p.id=rp.permiso_id WHERE u.estado_id=1 AND u.id<>:solicitante AND p.nombre_permiso=N'MSP Tesoreria' AND rp.lectura=1 AND rp.escritura=1 AND rp.eliminacion=1 ORDER BY u.id");
    $resolverStmt->execute([':solicitante' => $idSolicitante]);
    $idResolutor = (int) $resolverStmt->fetchColumn();
    if ($idCaja <= 0 || $idSolicitante <= 0 || $idResolutor <= 0) {
        throw new RuntimeException('Falta una caja activa o un segundo autorizador habilitado.');
    }

    $conn->beginTransaction();
    try {
        $insert = $conn->prepare("INSERT dbo.msp_tesoreria_cierres_caja(id_cuenta_tesoreria,fecha_cierre,saldo_apertura,total_entradas,total_salidas,saldo_sistema,efectivo_contado,diferencia,estado_cierre,observaciones,id_usuario) OUTPUT INSERTED.id_cierre_caja VALUES(:caja,'1900-01-01',0,0,0,0,0,0,N'CUADRADO',N'Prueba transaccional',:usuario)");
        $insert->execute([':caja' => $idCaja, ':usuario' => $idSolicitante]);
        $idCierre = (int) $insert->fetchColumn();

        $request = $conn->prepare('EXEC dbo.msp_tesoreria_solicitar_reapertura_caja @id_cierre_caja=:cierre,@motivo=:motivo,@id_usuario_solicita=:usuario');
        $request->execute([':cierre' => $idCierre, ':motivo' => 'Prueba de doble control segura', ':usuario' => $idSolicitante]);
        $idSolicitud = (int) (($request->fetch(PDO::FETCH_ASSOC)['id_solicitud_reapertura'] ?? 0));
        $request->closeCursor();

        $resolve = $conn->prepare('EXEC dbo.msp_tesoreria_resolver_reapertura_caja @id_solicitud_reapertura=:solicitud,@decision=N\'APROBAR\',@observacion=N\'Prueba transaccional\',@id_usuario_resuelve=:usuario');
        $resolve->execute([':solicitud' => $idSolicitud, ':usuario' => $idResolutor]);
        $resolve->fetch(PDO::FETCH_ASSOC);
        $resolve->closeCursor();

        $verify = $conn->prepare("SELECT COUNT(*) FROM dbo.msp_tesoreria_solicitudes_reapertura_caja s INNER JOIN dbo.msp_tesoreria_cierres_caja c ON c.id_cierre_caja=s.id_cierre_caja INNER JOIN dbo.msp_tesoreria_bitacora_reapertura_caja b ON b.id_cierre_caja=c.id_cierre_caja WHERE s.id_solicitud_reapertura=:solicitud AND s.estado_solicitud=N'APROBADA' AND s.id_usuario_solicita=:solicitante AND s.id_usuario_resuelve=:resolutor AND c.estado_cierre=N'REABIERTA' AND b.id_usuario_solicita=:solicitante2 AND b.id_usuario_autoriza=:resolutor2");
        $verify->execute([':solicitud' => $idSolicitud, ':solicitante' => $idSolicitante, ':resolutor' => $idResolutor, ':solicitante2' => $idSolicitante, ':resolutor2' => $idResolutor]);
        $ok = (int) $verify->fetchColumn() === 1;
        $conn->rollBack();
        return $ok;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
});

echo "Resultado: $passed OK, $failed fallidas.\n";
exit($failed === 0 ? 0 : 1);
