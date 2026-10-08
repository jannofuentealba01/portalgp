<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/secret_paths.php';

$execute = in_array('--execute', $argv, true);
$configName = 'database_remote.php';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--config=')) {
        $configName = substr($argument, strlen('--config='));
    }
}

if (preg_match('/^[a-z][a-z0-9_]*\.php$/', $configName) !== 1) {
    fwrite(STDERR, "Nombre de configuración no permitido.\n");
    exit(2);
}

$config = pgpLoadSecretConfig($configName);
$server = trim((string) ($config['server'] ?? ''));
$database = trim((string) ($config['database'] ?? ''));
$username = trim((string) ($config['username'] ?? ''));
$password = (string) ($config['password'] ?? '');
$encrypt = !empty($config['encrypt']);
$trustServerCertificate = !empty($config['trust_server_certificate']);

if ($server === '' || $database === '' || $username === '' || $password === '') {
    fwrite(STDERR, "La configuración externa está incompleta.\n");
    exit(2);
}
if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Nombre de base no permitido.\n");
    exit(2);
}

$dsn = "sqlsrv:Server={$server};Database={$database};LoginTimeout=30"
    . ';Encrypt=' . ($encrypt ? '1' : '0')
    . ';TrustServerCertificate=' . ($trustServerCertificate ? '1' : '0');

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
        $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 0);
    }

    $metadata = $pdo->query(
        "SELECT ORIGINAL_LOGIN() AS login_name,
                HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'BACKUP DATABASE') AS can_backup,
                CAST(SERVERPROPERTY('InstanceDefaultBackupPath') AS nvarchar(4000)) AS backup_path,
                CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128)) AS product_version,
                DB_NAME() AS database_name"
    )->fetch();
    if (!is_array($metadata)) {
        throw new RuntimeException('SQL Server no devolvió metadata.');
    }

    $safeMetadata = [
        'database' => (string) ($metadata['database_name'] ?? ''),
        'login' => (string) ($metadata['login_name'] ?? ''),
        'can_backup' => (int) ($metadata['can_backup'] ?? 0) === 1,
        'backup_path' => (string) ($metadata['backup_path'] ?? ''),
        'product_version' => (string) ($metadata['product_version'] ?? ''),
        'mode' => $execute ? 'execute' : 'inspect',
    ];

    try {
        $historyStatement = $pdo->prepare(
            "SELECT TOP (20)
                    bs.backup_start_date,
                    bs.backup_finish_date,
                    bs.backup_size,
                    bs.compressed_backup_size,
                    bs.is_copy_only,
                    bs.has_backup_checksums,
                    bmf.physical_device_name
             FROM msdb.dbo.backupset AS bs
             INNER JOIN msdb.dbo.backupmediafamily AS bmf
                ON bmf.media_set_id = bs.media_set_id
             WHERE bs.database_name = :database
               AND bs.[type] = 'D'
             ORDER BY bs.backup_finish_date DESC"
        );
        $historyStatement->execute([':database' => $database]);
        $safeMetadata['latest_full_backups'] = $historyStatement->fetchAll();

        $securityHistoryStatement = $pdo->prepare(
            "SELECT TOP (10)
                    bs.backup_start_date,
                    bs.backup_finish_date,
                    bs.backup_size,
                    bs.compressed_backup_size,
                    bs.is_copy_only,
                    bs.has_backup_checksums,
                    bmf.physical_device_name
             FROM msdb.dbo.backupset AS bs
             INNER JOIN msdb.dbo.backupmediafamily AS bmf
                ON bmf.media_set_id = bs.media_set_id
             WHERE bs.database_name = :database
               AND bs.[type] = 'D'
               AND bmf.physical_device_name LIKE '%COPY[_]ONLY[_]SECURITY%'
             ORDER BY bs.backup_finish_date DESC"
        );
        $securityHistoryStatement->execute([':database' => $database]);
        $safeMetadata['security_backups'] = $securityHistoryStatement->fetchAll();
    } catch (Throwable) {
        $safeMetadata['latest_full_backups'] = 'metadata_not_available';
    }
    echo json_encode($safeMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

    if (!$execute) {
        exit(0);
    }
    if (!$safeMetadata['can_backup']) {
        throw new RuntimeException('La identidad conectada no tiene BACKUP DATABASE.');
    }

    $backupRoot = rtrim($safeMetadata['backup_path'], "\\/");
    if ($backupRoot === '') {
        throw new RuntimeException('SQL Server no informó su ruta predeterminada de respaldo.');
    }

    $separator = str_contains($backupRoot, '\\') ? '\\' : '/';
    $fileName = sprintf(
        '%s_COPY_ONLY_SECURITY_%s.bak',
        $database,
        gmdate('Ymd_His')
    );
    $backupFile = $backupRoot . $separator . $fileName;
    $sqlFile = str_replace("'", "''", $backupFile);
    $sqlDatabase = '[' . str_replace(']', ']]', $database) . ']';

    $pdo->exec(
        "BACKUP DATABASE {$sqlDatabase}
         TO DISK = N'{$sqlFile}'
         WITH COPY_ONLY, COMPRESSION, CHECKSUM, INIT, STATS = 10"
    );

    $recordStatement = $pdo->prepare(
        "SELECT TOP (1)
                bs.backup_start_date,
                bs.backup_finish_date,
                bs.backup_size,
                bs.compressed_backup_size,
                bs.is_copy_only,
                bs.has_backup_checksums,
                bmf.physical_device_name
         FROM msdb.dbo.backupset AS bs
         INNER JOIN msdb.dbo.backupmediafamily AS bmf
            ON bmf.media_set_id = bs.media_set_id
         WHERE bs.database_name = :database
           AND bs.[type] = 'D'
           AND bmf.physical_device_name = :backup_file
         ORDER BY bs.backup_finish_date DESC"
    );
    $recordStatement->execute([
        ':database' => $database,
        ':backup_file' => $backupFile,
    ]);
    $backupRecord = $recordStatement->fetch();

    echo json_encode([
        'backup_file' => $backupFile,
        'backup_created' => true,
        'copy_only' => true,
        'checksum' => true,
        'msdb_recorded' => is_array($backupRecord),
        'msdb_record' => is_array($backupRecord) ? $backupRecord : null,
        'created_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

    try {
        $statement = $pdo->query(
            "RESTORE VERIFYONLY
             FROM DISK = N'{$sqlFile}'
             WITH CHECKSUM"
        );
        do {
            while ($statement->fetch(PDO::FETCH_ASSOC) !== false) {
                // Consume all result sets so VERIFYONLY completes before reporting.
            }
        } while ($statement->nextRowset());
    } catch (PDOException $verifyException) {
        fwrite(
            STDERR,
            "VERIFY_PERMISSION_REQUIRED: el backup fue creado, pero RESTORE VERIFYONLY requiere una identidad administrativa.\n"
        );
        exit(3);
    }

    echo json_encode([
        'backup_file' => $backupFile,
        'copy_only' => true,
        'checksum' => true,
        'restore_verifyonly' => 'ok',
        'completed_at_utc' => gmdate(DATE_ATOM),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'BACKUP_ERROR: ' . preg_replace('/\s+/', ' ', $exception->getMessage()) . PHP_EOL);
    exit(1);
}
