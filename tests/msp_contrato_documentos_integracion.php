<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

// Mantiene disponibles las cabeceras mientras se crean las sesiones HTTP de prueba.
ob_start();

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/security.php';

$fixtureDir = rtrim((string) getenv('MSP_DOC_FIXTURE_DIR'), "\\/");
if ($fixtureDir === '' || !is_dir($fixtureDir)) {
    fwrite(STDERR, "Define MSP_DOC_FIXTURE_DIR con los PDF temporales de la prueba.\n");
    exit(2);
}

$machine = (string) $conn
    ->query("SELECT CAST(SERVERPROPERTY(N'MachineName') AS nvarchar(200))")
    ->fetchColumn();
if (strcasecmp($machine, 'KVILLEGAS') !== 0) {
    fwrite(STDERR, "Esta prueba reversible solo está habilitada en la instancia local controlada.\n");
    exit(3);
}

$baseUrl = 'http://localhost/portalgp/msp';
$storageRoot = 'C:\\xampp\\msp_storage\\contratos';
$marker = 'B3_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
$sessions = [];
$createdPaths = [];
$assertions = 0;
$replacementPath = null;

function assertIntegration(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
    echo '[OK] ' . $message . PHP_EOL;
}

/** @return array{code:int,type:string,headers:string,body:string} */
function httpIntegration(string $url, ?array $post, ?string $sessionId): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if (is_string($sessionId) && $sessionId !== '') {
        curl_setopt($curl, CURLOPT_COOKIE, 'PHPSESSID=' . $sessionId);
    }
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($curl);
    if (!is_string($raw)) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Falló la solicitud HTTP local: ' . $error);
    }
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    $result = [
        'code' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
        'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
    curl_close($curl);
    return $result;
}

function createIntegrationSession(array $user, string $csrf): string
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ini_set('session.use_strict_mode', '0');
    session_id('mspdoc' . bin2hex(random_bytes(12)));
    session_start();
    $sessionId = session_id();
    $_SESSION = [
        'usuario' => [
            'id' => (int) $user['id'],
            'UserName' => (string) $user['UserName'],
            'rol_id' => (int) $user['rol_id'],
        ],
        'pgp_security_version' => (int) $user['security_version'],
        'portal_audience' => 'internal',
        'msp2_csrf_token' => $csrf,
    ];
    session_write_close();
    return $sessionId;
}

function uploadIntegration(
    string $baseUrl,
    string $sessionId,
    string $csrf,
    int $contractId,
    string $file,
    string $description,
    ?int $replacementId = null
): array {
    $post = [
        '_csrf' => $csrf,
        'id_contrato_arriendo' => (string) $contractId,
        'tipo_documento' => 'ANEXO',
        'fecha_documento' => date('Y-m-d'),
        'descripcion' => $description,
        'archivo' => new CURLFile(
            $file,
            mime_content_type($file) ?: 'application/octet-stream',
            basename($file)
        ),
    ];
    if ($replacementId !== null) {
        $post['id_documento_reemplazado'] = (string) $replacementId;
    }
    return httpIntegration($baseUrl . '/contratos/subir_documento.php', $post, $sessionId);
}

function findIntegrationDocument(PDO $db, string $description): ?array
{
    $stmt = $db->prepare(
        'SELECT TOP(1) * FROM dbo.msp_centro_documental
         WHERE descripcion=:descripcion ORDER BY id_documento DESC'
    );
    $stmt->execute([':descripcion' => $description]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

function countIntegrationFiles(string $root): int
{
    if (!is_dir($root)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile()) {
            $count++;
        }
    }
    return $count;
}

$admin = new PDO(
    'sqlsrv:Server=localhost;Database=PORTALGP;Encrypt=1;TrustServerCertificate=1',
    null,
    null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

try {
    $users = [];
    $stmtUsers = $conn->query(
        "SELECT id,UserName,estado_id,rol_id,security_version
         FROM dbo.cr_usuarios
         WHERE LOWER(UserName) IN(N'admin_2',N'respinoza')"
    );
    foreach ($stmtUsers as $user) {
        $users[strtolower((string) $user['UserName'])] = $user;
    }

    foreach (['admin_2', 'respinoza'] as $username) {
        assertIntegration(
            isset($users[$username]) && (int) $users[$username]['estado_id'] === 1,
            $username . ' está habilitado'
        );
        $permissionMap = pgpUserPermissionMap($conn, (int) $users[$username]['id'], true);
        $flags = $permissionMap['MSP Operacion'] ?? [];
        assertIntegration(
            ($flags['lectura'] ?? false)
                && ($flags['escritura'] ?? false)
                && ($flags['eliminacion'] ?? false),
            $username . ' posee permisos completos MSP Operacion'
        );
    }

    $tokens = [
        'admin_2' => bin2hex(random_bytes(32)),
        'respinoza' => bin2hex(random_bytes(32)),
    ];
    foreach ($tokens as $username => $token) {
        $sessions[$username] = createIntegrationSession($users[$username], $token);
    }

    $activeContracts = $conn->query(
        'SELECT TOP(2) id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE estado_contrato IN(1,2)
         ORDER BY id_contrato_arriendo'
    )->fetchAll(PDO::FETCH_COLUMN);
    $terminatedContract = (int) $conn->query(
        'SELECT TOP(1) id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE estado_contrato IN(3,4,5)
         ORDER BY id_contrato_arriendo'
    )->fetchColumn();
    assertIntegration(
        count($activeContracts) >= 2 && $terminatedContract > 0,
        'hay contratos controlados para probar estados activo y terminado'
    );
    $contractA = (int) $activeContracts[0];
    $contractB = (int) $activeContracts[1];

    $acceptedFiles = [
        ['admin_2', 'normal.pdf', 'normal_admin'],
        ['respinoza', 'escaneado.pdf', 'escaneado_respinoza'],
        ['admin_2', 'version_13.pdf', 'version_13'],
        ['admin_2', 'version_20.pdf', 'version_20'],
        ['respinoza', 'protegido.pdf', 'protegido'],
        ['respinoza', 'con_firma.pdf', 'firma'],
        ['admin_2', 'casi_15mb.pdf', 'casi_15mb'],
    ];
    $firstDocument = null;
    foreach ($acceptedFiles as [$username, $file, $suffix]) {
        $description = $marker . '_' . $suffix;
        $response = uploadIntegration(
            $baseUrl,
            $sessions[$username],
            $tokens[$username],
            $contractA,
            $fixtureDir . '\\' . $file,
            $description
        );
        $document = findIntegrationDocument($conn, $description);
        assertIntegration(
            in_array($response['code'], [302, 303], true)
                && is_array($document)
                && strtoupper((string) $document['estado']) === 'ACTIVO',
            $username . ' carga ' . $file
        );
        $firstDocument ??= $document;
    }

    foreach ([
        ['sobre_15mb.pdf', 'rechazo_tamano'],
        ['falso.pdf', 'rechazo_falso'],
        ['pdf_renombrado.exe', 'rechazo_exe'],
        ['imagen.jpg', 'rechazo_imagen'],
    ] as [$file, $suffix]) {
        $description = $marker . '_' . $suffix;
        uploadIntegration(
            $baseUrl,
            $sessions['admin_2'],
            $tokens['admin_2'],
            $contractA,
            $fixtureDir . '\\' . $file,
            $description
        );
        assertIntegration(
            findIntegrationDocument($conn, $description) === null,
            'se rechaza ' . $file
        );
    }

    $invalidCsrfDescription = $marker . '_csrf_invalido';
    uploadIntegration(
        $baseUrl,
        $sessions['admin_2'],
        'token-invalido',
        $contractA,
        $fixtureDir . '\\normal.pdf',
        $invalidCsrfDescription
    );
    assertIntegration(
        findIntegrationDocument($conn, $invalidCsrfDescription) === null,
        'CSRF inválido no registra documentos'
    );

    $withoutSession = uploadIntegration(
        $baseUrl,
        '',
        bin2hex(random_bytes(32)),
        $contractA,
        $fixtureDir . '\\normal.pdf',
        $marker . '_sin_sesion'
    );
    assertIntegration(
        in_array($withoutSession['code'], [401, 302, 303], true)
            && findIntegrationDocument($conn, $marker . '_sin_sesion') === null,
        'una carga sin sesión queda bloqueada'
    );

    $filesBeforeDuplicate = countIntegrationFiles($storageRoot);
    uploadIntegration(
        $baseUrl,
        $sessions['admin_2'],
        $tokens['admin_2'],
        $contractA,
        $fixtureDir . '\\normal.pdf',
        $marker . '_duplicado'
    );
    $filesAfterDuplicate = countIntegrationFiles($storageRoot);
    assertIntegration(
        findIntegrationDocument($conn, $marker . '_duplicado') === null
            && $filesBeforeDuplicate === $filesAfterDuplicate,
        'un fallo SQL por PDF duplicado no deja archivo huérfano'
    );

    $crossContractDescription = $marker . '_reemplazo_otro_contrato';
    uploadIntegration(
        $baseUrl,
        $sessions['admin_2'],
        $tokens['admin_2'],
        $contractB,
        $fixtureDir . '\\version_20.pdf',
        $crossContractDescription,
        (int) $firstDocument['id_documento']
    );
    $firstState = (string) $conn
        ->query('SELECT estado FROM dbo.msp_centro_documental WHERE id_documento=' . (int) $firstDocument['id_documento'])
        ->fetchColumn();
    assertIntegration(
        findIntegrationDocument($conn, $crossContractDescription) === null
            && strtoupper($firstState) === 'ACTIVO',
        'no se puede reemplazar desde un contrato ajeno'
    );

    $replacementPath = $fixtureDir . '\\reemplazo_unico.pdf';
    file_put_contents(
        $replacementPath,
        (string) file_get_contents($fixtureDir . '\\normal.pdf') . "\n%" . $marker
    );
    $replacementDescription = $marker . '_reemplazo_valido';
    uploadIntegration(
        $baseUrl,
        $sessions['admin_2'],
        $tokens['admin_2'],
        $contractA,
        $replacementPath,
        $replacementDescription,
        (int) $firstDocument['id_documento']
    );
    $replacement = findIntegrationDocument($conn, $replacementDescription);
    $firstState = (string) $conn
        ->query('SELECT estado FROM dbo.msp_centro_documental WHERE id_documento=' . (int) $firstDocument['id_documento'])
        ->fetchColumn();
    assertIntegration(
        is_array($replacement)
            && (int) $replacement['id_documento_reemplazado'] === (int) $firstDocument['id_documento']
            && strtoupper($firstState) === 'ANULADO',
        'reemplazo conserva y anula lógicamente la versión anterior'
    );

    $annulResponse = httpIntegration(
        $baseUrl . '/contratos/anular_documento.php',
        [
            '_csrf' => $tokens['admin_2'],
            'id_contrato_arriendo' => (string) $contractA,
            'id_documento' => (string) $replacement['id_documento'],
            'motivo_anulacion' => $marker . '_motivo',
        ],
        $sessions['admin_2']
    );
    $replacementState = (string) $conn
        ->query('SELECT estado FROM dbo.msp_centro_documental WHERE id_documento=' . (int) $replacement['id_documento'])
        ->fetchColumn();
    assertIntegration(
        in_array($annulResponse['code'], [302, 303], true)
            && strtoupper($replacementState) === 'ANULADO',
        'admin_2 puede anular con trazabilidad'
    );

    $terminatedDescription = $marker . '_carga_terminado';
    uploadIntegration(
        $baseUrl,
        $sessions['respinoza'],
        $tokens['respinoza'],
        $terminatedContract,
        $fixtureDir . '\\normal.pdf',
        $terminatedDescription
    );
    assertIntegration(
        findIntegrationDocument($conn, $terminatedDescription) === null,
        'un contrato terminado rechaza nuevas cargas'
    );

    $contractRow = $conn->query(
        'SELECT id_arrendatario FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo=' . $terminatedContract
    )->fetch(PDO::FETCH_ASSOC);
    $relativePath = 'contrato_' . $terminatedContract . '/' . $marker . '_terminado.pdf';
    $absolutePath = $storageRoot . '\\' . str_replace('/', '\\', $relativePath);
    if (!is_dir(dirname($absolutePath))) {
        mkdir(dirname($absolutePath), 0775, true);
    }
    copy($fixtureDir . '\\normal.pdf', $absolutePath);
    $createdPaths[] = $absolutePath;
    $stmtTerminated = $admin->prepare(
        "INSERT dbo.msp_centro_documental(
            id_contrato_arriendo,id_arrendatario,id_local,nombre_archivo,
            ruta_archivo,ruta_relativa,tipo_documento,descripcion,fecha_documento,
            mime_type,hash_sha256,bytes_archivo,id_documento_reemplazado,
            estado,id_usuario,fecha_registro,fecha_actualizacion
         ) OUTPUT INSERTED.id_documento
         VALUES(
            :contrato,:arrendatario,NULL,:nombre,
            :ruta_archivo,:ruta_relativa,N'FINIQUITO',:descripcion,CAST(GETDATE() AS date),
            N'application/pdf',:hash,:bytes,NULL,
            N'ACTIVO',:usuario,SYSDATETIME(),SYSDATETIME()
         )"
    );
    $stmtTerminated->execute([
        ':contrato' => $terminatedContract,
        ':arrendatario' => (int) $contractRow['id_arrendatario'],
        ':nombre' => $marker . '_terminado.pdf',
        ':ruta_archivo' => $relativePath,
        ':ruta_relativa' => $relativePath,
        ':descripcion' => $marker . '_consulta_terminado',
        ':hash' => hash_file('sha256', $absolutePath),
        ':bytes' => filesize($absolutePath),
        ':usuario' => (int) $users['admin_2']['id'],
    ]);
    $terminatedDocumentId = (int) $stmtTerminated->fetchColumn();
    assertIntegration(
        $terminatedDocumentId > 0,
        'se preparó un adjunto controlado para probar consulta posterior al término'
    );

    $viewAdmin = httpIntegration(
        $baseUrl . '/contratos/descargar_documento.php?id=' . $terminatedDocumentId . '&modo=ver',
        null,
        $sessions['admin_2']
    );
    $downloadRespinoza = httpIntegration(
        $baseUrl . '/contratos/descargar_documento.php?id=' . $terminatedDocumentId,
        null,
        $sessions['respinoza']
    );
    assertIntegration(
        $viewAdmin['code'] === 200
            && str_contains(strtolower($viewAdmin['headers']), 'content-disposition: inline'),
        'admin_2 puede ver PDF después del término'
    );
    assertIntegration(
        $downloadRespinoza['code'] === 200
            && str_contains(strtolower($downloadRespinoza['headers']), 'content-disposition: attachment'),
        'respinoza puede descargar PDF después del término'
    );

    foreach (['admin_2', 'respinoza'] as $username) {
        httpIntegration(
            $baseUrl . '/contratos/anular_documento.php',
            [
                '_csrf' => $tokens[$username],
                'id_contrato_arriendo' => (string) $terminatedContract,
                'id_documento' => (string) $terminatedDocumentId,
                'motivo_anulacion' => $marker . '_bloqueo_' . $username,
            ],
            $sessions[$username]
        );
        $state = (string) $conn
            ->query('SELECT estado FROM dbo.msp_centro_documental WHERE id_documento=' . $terminatedDocumentId)
            ->fetchColumn();
        assertIntegration(
            strtoupper($state) === 'ACTIVO',
            $username . ' no puede anular después del término'
        );
    }

    $replacementAfterTermination = $marker . '_reemplazo_terminado';
    uploadIntegration(
        $baseUrl,
        $sessions['admin_2'],
        $tokens['admin_2'],
        $terminatedContract,
        $fixtureDir . '\\version_13.pdf',
        $replacementAfterTermination,
        $terminatedDocumentId
    );
    assertIntegration(
        findIntegrationDocument($conn, $replacementAfterTermination) === null,
        'no se puede reemplazar después del término'
    );

    $stmtBadPath = $admin->prepare(
        "INSERT dbo.msp_centro_documental(
            id_contrato_arriendo,id_arrendatario,id_local,nombre_archivo,
            ruta_archivo,ruta_relativa,tipo_documento,descripcion,fecha_documento,
            mime_type,hash_sha256,bytes_archivo,id_documento_reemplazado,
            estado,id_usuario,fecha_registro,fecha_actualizacion
         ) OUTPUT INSERTED.id_documento
         VALUES(
            :contrato,:arrendatario,NULL,N'ruta_manipulada.pdf',
            N'../escape.pdf',N'../escape.pdf',N'OTRO',:descripcion,NULL,
            N'application/pdf',:hash,1,NULL,
            N'ACTIVO',:usuario,SYSDATETIME(),SYSDATETIME()
         )"
    );
    $stmtBadPath->execute([
        ':contrato' => $terminatedContract,
        ':arrendatario' => (int) $contractRow['id_arrendatario'],
        ':descripcion' => $marker . '_ruta_manipulada',
        ':hash' => hash('sha256', $marker . '_ruta'),
        ':usuario' => (int) $users['admin_2']['id'],
    ]);
    $badPathDocumentId = (int) $stmtBadPath->fetchColumn();
    $badPathResponse = httpIntegration(
        $baseUrl . '/contratos/descargar_documento.php?id=' . $badPathDocumentId,
        null,
        $sessions['admin_2']
    );
    assertIntegration(
        in_array($badPathResponse['code'], [404, 409], true),
        'una ruta manipulada queda bloqueada'
    );

    $anonymousDownload = httpIntegration(
        $baseUrl . '/contratos/descargar_documento.php?id=' . $terminatedDocumentId,
        null,
        null
    );
    assertIntegration(
        in_array($anonymousDownload['code'], [302, 303], true)
            && str_contains(strtolower($anonymousDownload['headers']), 'location: /portalgp/login.php'),
        'la descarga sin sesión redirige al login'
    );

    echo 'RESULTADO=' . $assertions . ' comprobaciones correctas' . PHP_EOL;
} finally {
    try {
        $stmtDocuments = $admin->prepare(
            'SELECT id_documento,ruta_relativa
             FROM dbo.msp_centro_documental
             WHERE descripcion LIKE :description_marker
                OR nombre_archivo LIKE :filename_marker'
        );
        $stmtDocuments->execute([
            ':description_marker' => $marker . '%',
            ':filename_marker' => $marker . '%',
        ]);
        $rows = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_map(static fn (array $row): int => (int) $row['id_documento'], $rows);
        if ($ids !== []) {
            foreach ($ids as $id) {
                if ($admin->query("SELECT OBJECT_ID(N'dbo.msp_historial_contrato')")->fetchColumn() !== null) {
                    $stmtHistory = $admin->prepare(
                        'DELETE FROM dbo.msp_historial_contrato WHERE detalle_evento LIKE :pattern'
                    );
                    $stmtHistory->execute([':pattern' => '%"id_documento":' . $id . '%']);
                }
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $admin->prepare(
                "UPDATE dbo.msp_centro_documental
                 SET id_documento_reemplazado=NULL
                 WHERE id_documento IN({$placeholders})"
            )->execute($ids);
            $admin->prepare(
                "DELETE FROM dbo.msp_centro_documental
                 WHERE id_documento IN({$placeholders})"
            )->execute($ids);
        }
        foreach ($rows as $row) {
            $relative = str_replace('/', '\\', (string) $row['ruta_relativa']);
            if ($relative !== '' && !str_contains($relative, '..')) {
                $path = $storageRoot . '\\' . $relative;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
        foreach ($createdPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        if (is_string($replacementPath) && is_file($replacementPath)) {
            @unlink($replacementPath);
        }
        foreach ($sessions as $sessionId) {
            $sessionFile = rtrim(session_save_path(), "\\/")
                . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
            if (is_file($sessionFile)) {
                @unlink($sessionFile);
            }
        }
    } catch (Throwable $cleanupError) {
        fwrite(STDERR, '[WARN] Limpieza: ' . $cleanupError->getMessage() . PHP_EOL);
    }
}
