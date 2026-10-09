<?php
declare(strict_types=1);

// Read-only catalog inspection. Supports stdin execution on the deployed host.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__);
$configName = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, 7);
    }
    if (str_starts_with($argument, '--config=')) {
        $configName = substr($argument, 9);
    }
}
require_once $root . '/secret_paths.php';
require_once $root . '/database_connection.php';
$config = $configName === null
    ? require $root . '/config/database.php'
    : pgpLoadSecretConfig($configName);
if ($config === []) {
    fwrite(STDERR, 'Missing external database configuration.' . PHP_EOL);
    exit(2);
}
try {
    $dsn = pgpSqlConnectionDsn($config) . ';LoginTimeout=15';
    $db = new PDO($dsn, $config['username'] ?: null, $config['password'] ?: null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 15);
} catch (Throwable $exception) {
    fwrite(STDERR, 'SQL inspection connection failed; SQLSTATE=' . $exception->getCode() . PHP_EOL);
    exit(1);
}
$report = ['configuration' => [
    'environment' => $config['environment'] ?? 'unspecified',
    'encrypt' => (bool) $config['encrypt'],
    'trust_server_certificate' => (bool) $config['trust_server_certificate'],
]];
if (in_array('--verify-certificate', $argv, true)) {
    try {
        $strictDsn = pgpSqlConnectionDsn(array_replace($config, [
            'encrypt' => true, 'trust_server_certificate' => false,
        ])) . ';LoginTimeout=15';
        $strictDb = new PDO($strictDsn, $config['username'] ?: null, $config['password'] ?: null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $strictDb->query('SELECT 1')->fetchColumn();
        $report['certificate_probe'] = ['status' => 'validated'];
        unset($strictDb);
    } catch (PDOException $exception) {
        $report['certificate_probe'] = ['status' => 'failed', 'sqlstate' => $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null,
            'certificate_error' => preg_match('/certificate|certificado|SSL Provider|TLS/i', $exception->getMessage()) === 1];
    }
}
$queries = [
    'identity' => "SELECT DB_NAME() database_name, ORIGINAL_LOGIN() login_name, USER_NAME() database_user,
        IS_SRVROLEMEMBER('sysadmin') sysadmin, IS_MEMBER('db_owner') db_owner,
        IS_MEMBER('db_datareader') db_datareader, IS_MEMBER('db_datawriter') db_datawriter,
        HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','CONTROL') control_database,
        HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','ALTER') alter_database,
        HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','BACKUP DATABASE') backup_database,
        HAS_PERMS_BY_NAME(NULL,'SERVER','CONTROL SERVER') control_server,
        HAS_PERMS_BY_NAME(NULL,'SERVER','VIEW SERVER STATE') view_server_state",
    'server' => "SELECT CONVERT(varchar(100),SERVERPROPERTY('ProductVersion')) product_version,
        CONVERT(varchar(100),SERVERPROPERTY('ProductLevel')) product_level,
        CONVERT(varchar(100),SERVERPROPERTY('ProductUpdateLevel')) update_level,
        CONVERT(varchar(100),SERVERPROPERTY('Edition')) edition",
    'transport' => 'SELECT encrypt_option,auth_scheme,net_transport FROM sys.dm_exec_connections WHERE session_id=@@SPID',
    'database_options' => 'SELECT state_desc,recovery_model_desc,page_verify_option_desc,is_encrypted,
        is_trustworthy_on,is_db_chaining_on,containment_desc FROM sys.databases WHERE database_id=DB_ID()',
    'effective_database_permissions' => "SELECT permission_name FROM sys.fn_my_permissions(NULL,'DATABASE')
        WHERE permission_name IN ('CONTROL','ALTER','CREATE TABLE','ALTER ANY USER','ALTER ANY ROLE',
            'BACKUP DATABASE','BACKUP LOG','SELECT','INSERT','UPDATE','DELETE','EXECUTE') ORDER BY permission_name",
    'role_membership' => "SELECT r.name role_name FROM sys.database_role_members m
        JOIN sys.database_principals r ON r.principal_id=m.role_principal_id
        WHERE m.member_principal_id=USER_ID() ORDER BY r.name",
    'runtime_permissions' => "SELECT p.class_desc,p.permission_name,p.state_desc,COUNT(*) permission_count
        FROM sys.database_permissions p JOIN sys.database_principals u ON u.principal_id=p.grantee_principal_id
        WHERE u.name=N'portalgp_runtime_role' GROUP BY p.class_desc,p.permission_name,p.state_desc
        ORDER BY p.class_desc,p.permission_name,p.state_desc",
    'runtime_principals' => "SELECT name,type_desc FROM sys.database_principals
        WHERE name IN(N'portalgp_runtime',N'portalgp_runtime_role')",
    'critical_object_access' => "SELECT o.name object_name,
        HAS_PERMS_BY_NAME(N'dbo.'+o.name,'OBJECT','SELECT') can_select,
        HAS_PERMS_BY_NAME(N'dbo.'+o.name,'OBJECT','INSERT') can_insert,
        HAS_PERMS_BY_NAME(N'dbo.'+o.name,'OBJECT','UPDATE') can_update,
        HAS_PERMS_BY_NAME(N'dbo.'+o.name,'OBJECT','DELETE') can_delete,
        HAS_PERMS_BY_NAME(N'dbo.'+o.name,'OBJECT','ALTER') can_alter
        FROM sys.objects o WHERE o.type='U' AND o.name IN
        (N'cr_usuarios',N'cr_roles',N'cr_permisos',N'cr_rol_permisos',N'cr_seguridad_bitacora',
         N'msp_schema_migrations',N'msp_documentos_cobro_versiones',N'msp_cierre_mensual_eliminaciones',
         N'msp_cierre_mensual_transiciones',N'msp_saldo_favor_auditoria_historica') ORDER BY o.name",
    'constraint_summary' => "SELECT 'FOREIGN_KEY' constraint_type,COUNT(*) total,
        COALESCE(SUM(CONVERT(int,is_disabled)),0) disabled,COALESCE(SUM(CONVERT(int,is_not_trusted)),0) untrusted
        FROM sys.foreign_keys WHERE OBJECT_NAME(parent_object_id) LIKE N'msp[_]%'
        UNION ALL SELECT 'CHECK',COUNT(*),COALESCE(SUM(CONVERT(int,is_disabled)),0),
        COALESCE(SUM(CONVERT(int,is_not_trusted)),0) FROM sys.check_constraints
        WHERE OBJECT_NAME(parent_object_id) LIKE N'msp[_]%'",
    'unsafe_constraints' => "SELECT name,OBJECT_NAME(parent_object_id) table_name,is_disabled,is_not_trusted
        FROM sys.foreign_keys WHERE (is_disabled=1 OR is_not_trusted=1) AND OBJECT_NAME(parent_object_id) LIKE N'msp[_]%'
        UNION ALL SELECT name,OBJECT_NAME(parent_object_id),is_disabled,is_not_trusted
        FROM sys.check_constraints WHERE (is_disabled=1 OR is_not_trusted=1) AND OBJECT_NAME(parent_object_id) LIKE N'msp[_]%'",
    'untrusted_check_definitions' => "SELECT name,OBJECT_NAME(parent_object_id) table_name,definition
        FROM sys.check_constraints WHERE is_not_trusted=1 AND OBJECT_NAME(parent_object_id) LIKE N'msp[_]%'",
    'untrusted_check_violations' => "SELECT 'CK_msp_tesoreria_cierre_estado' constraint_name,COUNT_BIG(*) invalid_rows
        FROM dbo.msp_tesoreria_cierres_caja WHERE NOT(estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA',N'REABIERTA'))
        UNION ALL SELECT 'CK_msp_contratos_fechas',COUNT_BIG(*) FROM dbo.msp_contratos_arriendo
        WHERE NOT((fecha_termino_pactada IS NULL OR fecha_termino_pactada>=fecha_inicio)
            AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva>=fecha_inicio))
        UNION ALL SELECT 'CK_msp_correcciones_estado_v2',COUNT_BIG(*) FROM dbo.msp_correcciones
        WHERE NOT(estado_correccion IN(N'BORRADOR',N'ANALIZADA',N'PENDIENTE_APROBACION',N'APROBADA',
            N'EJECUTANDO',N'EJECUTADA',N'ERROR',N'RECHAZADA',N'CANCELADA'))",
    'triggers' => "SELECT t.name,OBJECT_NAME(t.parent_id) table_name,t.is_disabled
        FROM sys.triggers t WHERE t.name LIKE N'%seguridad%' OR t.name LIKE N'%security%'
        OR t.name LIKE N'%inmutable%' ORDER BY t.name",
    'module_security' => "SELECT COUNT(*) modules,
        COALESCE(SUM(CASE WHEN execute_as_principal_id=-2 THEN 1 ELSE 0 END),0) execute_as_owner,
        COALESCE(SUM(CASE WHEN execute_as_principal_id IS NOT NULL THEN 1 ELSE 0 END),0) explicit_context,
        COALESCE(SUM(CASE WHEN definition IS NULL THEN 1 ELSE 0 END),0) unavailable_definition,
        COALESCE(SUM(CASE WHEN definition LIKE N'%xp_cmdshell%' OR definition LIKE N'%OPENROWSET%'
            OR definition LIKE N'%OPENQUERY%' THEN 1 ELSE 0 END),0) external_execution
        FROM sys.sql_modules",
    'linked_server_count' => 'SELECT COUNT(*) visible_linked_servers FROM sys.servers WHERE is_linked=1',
    'server_features' => "SELECT name,CONVERT(int,value_in_use) enabled FROM sys.configurations WHERE name IN
        (N'xp_cmdshell',N'Ole Automation Procedures',N'Ad Hoc Distributed Queries',N'cross db ownership chaining',N'remote access')",
    'backup_summary' => "SELECT [type],COUNT(*) retained_history_entries,MAX(backup_finish_date) latest_finish,
        MAX(CASE WHEN is_copy_only=0 THEN backup_finish_date END) latest_regular_finish,
        SUM(CASE WHEN is_copy_only=0 THEN 1 ELSE 0 END) regular_backups,
        SUM(CASE WHEN has_backup_checksums=1 THEN 1 ELSE 0 END) with_checksums,
        SUM(CASE WHEN key_algorithm IS NOT NULL AND key_algorithm<>N'NO_Encryption' THEN 1 ELSE 0 END) encrypted_backups
        FROM msdb.dbo.backupset WHERE database_name=DB_NAME() GROUP BY [type]",
    'database_audit_count' => 'SELECT COUNT(*) specifications,SUM(CONVERT(int,is_state_enabled)) enabled FROM sys.database_audit_specifications',
    'xp_cmdshell_public_grants' => "SELECT p.state_desc,p.permission_name,u.name principal_name
        FROM master.sys.database_permissions p JOIN master.sys.database_principals u ON u.principal_id=p.grantee_principal_id
        JOIN master.sys.all_objects o ON o.object_id=p.major_id
        WHERE o.name=N'xp_cmdshell' AND p.permission_name='EXECUTE' AND u.name=N'public'",
    'operational_accounts' => "SELECT u.UserName,u.estado_id,r.nombre_rol FROM dbo.cr_usuarios u
        JOIN dbo.cr_roles r ON r.id=u.rol_id WHERE u.UserName IN(N'admin_2',N'respinoza')",
];
foreach ($queries as $name => $sql) {
    try {
        $report[$name] = ['status' => 'ok', 'rows' => $db->query($sql)->fetchAll()];
    } catch (PDOException $exception) {
        $report[$name] = ['status' => 'unavailable', 'sqlstate' => $exception->getCode(),
            'driver_code' => $exception->errorInfo[1] ?? null];
    }
}
if (in_array('--xp-inventory', $argv, true)) {
    $inventory = ['status' => 'partial', 'safe_to_disable' => false,
        'limitation' => 'Visible catalog only; does not execute jobs, commands or change configuration.'];
    $catalogQueries = [
        'master' => [
            'visibility' => "SELECT IS_SRVROLEMEMBER('sysadmin') sysadmin,
                HAS_PERMS_BY_NAME(NULL,'SERVER','VIEW ANY DEFINITION') view_any_definition,
                HAS_PERMS_BY_NAME(NULL,'SERVER','VIEW SERVER STATE') view_server_state",
            'effective_xp_access' => "SELECT HAS_PERMS_BY_NAME(N'sys.xp_cmdshell','OBJECT','EXECUTE') can_execute",
            'explicit_xp_permissions' => "SELECT p.state_desc,p.permission_name,u.name principal_name
                FROM sys.database_permissions p JOIN sys.database_principals u ON u.principal_id=p.grantee_principal_id
                JOIN sys.all_objects o ON o.object_id=p.major_id
                WHERE o.name=N'xp_cmdshell' AND p.permission_name=N'EXECUTE'",
            'proxy_credential_visibility' => "SELECT COUNT(*) visible_xp_proxy_credentials FROM sys.credentials
                WHERE name=N'##xp_cmdshell_proxy_account##'",
            'database_scope' => "SELECT COUNT(*) visible_user_databases,
                SUM(CASE WHEN state=0 AND HAS_DBACCESS(name)=1 THEN 1 ELSE 0 END) accessible_online_databases
                FROM sys.databases WHERE database_id>4",
        ],
        'msdb' => [
            'visibility' => "SELECT HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','CONTROL') control_database,
                HAS_PERMS_BY_NAME(DB_NAME(),'DATABASE','VIEW DEFINITION') view_definition",
            // Counts only: job command bodies can contain credentials.
            'job_steps' => "SELECT s.subsystem,COUNT(*) visible_steps,
                SUM(CASE WHEN j.enabled=1 THEN 1 ELSE 0 END) enabled_job_steps,
                SUM(CASE WHEN s.command LIKE N'%xp_cmdshell%' THEN 1 ELSE 0 END) direct_xp_references
                FROM dbo.sysjobsteps s JOIN dbo.sysjobs j ON j.job_id=s.job_id GROUP BY s.subsystem",
            'proxies' => 'SELECT COUNT(*) visible_proxies,SUM(CONVERT(int,enabled)) enabled_proxies FROM dbo.sysproxies',
            'proxy_subsystems' => 'SELECT subsystem_id,COUNT(*) visible_assignments FROM dbo.sysproxysubsystem GROUP BY subsystem_id',
        ],
    ];
    foreach ($catalogQueries as $catalog => $checks) {
        $catalogConfig = $config;
        $catalogConfig['database'] = $catalog;
        try {
            $catalogDb = new PDO(pgpSqlConnectionDsn($catalogConfig) . ';LoginTimeout=10',
                $config['username'] ?: null, $config['password'] ?: null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $catalogDb->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 10);
        } catch (PDOException $exception) {
            $inventory[$catalog] = ['status' => 'unavailable', 'sqlstate' => $exception->getCode()];
            continue;
        }
        foreach ($checks as $name => $sql) {
            try {
                $inventory[$catalog][$name] = ['status' => 'ok', 'rows' => $catalogDb->query($sql)->fetchAll()];
            } catch (PDOException $exception) {
                $inventory[$catalog][$name] = ['status' => 'unavailable', 'sqlstate' => $exception->getCode(),
                    'driver_code' => $exception->errorInfo[1] ?? null];
            }
        }
        unset($catalogDb);
    }
    $inventory['modules_by_database'] = [];
    try {
        $databases = $db->query('SELECT name FROM sys.databases WHERE database_id>4 AND state=0 AND HAS_DBACCESS(name)=1')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($databases as $name) {
            $quoted = '[' . str_replace(']', ']]', (string) $name) . ']';
            try {
                $rows = $db->query("SELECT COUNT(*) visible_modules,
                    SUM(CASE WHEN definition IS NULL THEN 1 ELSE 0 END) unavailable_definitions,
                    SUM(CASE WHEN definition LIKE N'%xp_cmdshell%' THEN 1 ELSE 0 END) direct_xp_references
                    FROM $quoted.sys.sql_modules")->fetchAll();
                $inventory['modules_by_database'][] = ['database' => $name, 'status' => 'ok', 'rows' => $rows];
            } catch (PDOException $exception) {
                $inventory['modules_by_database'][] = ['database' => $name, 'status' => 'unavailable',
                    'sqlstate' => $exception->getCode()];
            }
        }
    } catch (PDOException $exception) {
        $inventory['database_enumeration'] = ['status' => 'unavailable', 'sqlstate' => $exception->getCode()];
    }
    // Even a complete SQL catalog does not inventory external consumers or
    // dynamically assembled calls. This diagnostic never authorizes disablement.
    $report['xp_dependencies'] = $inventory;
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
