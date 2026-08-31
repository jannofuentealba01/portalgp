<?php
declare(strict_types=1);

function omFmtPeriodo(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $d = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('m-Y') : $value;
}

function omFmtFecha(?string $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    $d = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $d ? $d->format('d-m-Y') : $value;
}

function omFmtNum(mixed $value, int $decimals = 2): string
{
    if ($value === null || $value === '') {
        return '0';
    }

    return number_format((float) $value, $decimals, ',', '.');
}

function omResolveCaFile(): ?string
{
    $caFile = dirname(__DIR__, 2) . '/config/cacert.pem';
    return is_file($caFile) ? $caFile : null;
}

function omFetchUrl(string $url, int $timeoutSeconds = 6): ?string
{
    $caFile = omResolveCaFile();
    $https = str_starts_with(strtolower($url), 'https://');
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl !== false) {
            $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_ENCODING=>'',CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>max(2,min($timeoutSeconds,8)),CURLOPT_TIMEOUT=>$timeoutSeconds,CURLOPT_HTTP_VERSION=>CURL_HTTP_VERSION_1_1,CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_HTTPHEADER=>['accept: application/json']];
            if ($https) { $options[CURLOPT_SSL_VERIFYPEER]=true; $options[CURLOPT_SSL_VERIFYHOST]=2; if ($caFile!==null) $options[CURLOPT_CAINFO]=$caFile; }
            curl_setopt_array($curl,$options); $response=curl_exec($curl); curl_close($curl);
            if (is_string($response) && $response!=='') return $response;
        }
    }
    $opts=['http'=>['method'=>'GET','timeout'=>$timeoutSeconds,'header'=>"Accept: application/json\r\n"]];
    if ($https) { $opts['ssl']=['verify_peer'=>true,'verify_peer_name'=>true]; if ($caFile!==null) $opts['ssl']['cafile']=$caFile; }
    $response=@file_get_contents($url,false,stream_context_create($opts));
    return is_string($response) ? $response : null;
}
function omParseBoostrHolidayDates(?string $payload): array
{
    if ($payload === null || trim($payload) === '') {
        return [];
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'success') {
        return [];
    }

    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }

    $dates = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = $row['date'] ?? $row['fecha'] ?? null;
        if (!is_string($date)) {
            continue;
        }
        $date = substr($date, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $dates[] = $date;
        }
    }

    return $dates;
}

function omHolidaysTableAvailable(PDO $conn): bool
{
    return msp2TableExists($conn, 'msp_feriados');
}

function omFetchHolidayDatesFromDb(PDO $conn, int $year): array
{
    $stmt = $conn->prepare(
        'SELECT fecha
         FROM dbo.msp_feriados
         WHERE activo = 1
           AND YEAR(fecha) = :year
         ORDER BY fecha ASC'
    );
    $stmt->bindValue(':year', $year, PDO::PARAM_INT);
    $stmt->execute();

    $dates = [];
    while (($row = $stmt->fetch()) !== false) {
        $date = $row['fecha'] ?? null;
        if (!is_string($date)) {
            continue;
        }
        $date = substr($date, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $dates[] = $date;
        }
    }

    return $dates;
}

function omUpsertHolidaysToDb(PDO $conn, array $rows, string $fuente = 'boostr'): int
{
    if ($rows === []) {
        return 0;
    }

    $stmt = $conn->prepare(
        "IF EXISTS (SELECT 1 FROM dbo.msp_feriados WHERE fecha = :fecha_exists)
            UPDATE dbo.msp_feriados
            SET titulo = :titulo_update,
                tipo = :tipo_update,
                inalienable = :inalienable_update,
                fuente = :fuente_update,
                activo = 1,
                updated_at = SYSDATETIME()
            WHERE fecha = :fecha_update
         ELSE
            INSERT INTO dbo.msp_feriados (
                fecha, titulo, tipo, inalienable, fuente, activo, created_at, updated_at
            ) VALUES (
                :fecha_insert, :titulo_insert, :tipo_insert, :inalienable_insert, :fuente_insert, 1, SYSDATETIME(), SYSDATETIME()
            )"
    );

    $affected = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $fecha = $row['date'] ?? $row['fecha'] ?? null;
        if (!is_string($fecha)) {
            continue;
        }
        $fecha = substr($fecha, 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
            continue;
        }

        $titulo = (string) ($row['title'] ?? $row['titulo'] ?? '');
        $tipo = $row['type'] ?? $row['tipo'] ?? null;
        $inalienable = (int) (($row['inalienable'] ?? $row['irrenunciable'] ?? 0) ? 1 : 0);

        $tipoValue = $tipo !== null ? (string) $tipo : null;

        $stmt->bindValue(':fecha_exists', $fecha, PDO::PARAM_STR);
        $stmt->bindValue(':fecha_update', $fecha, PDO::PARAM_STR);
        $stmt->bindValue(':fecha_insert', $fecha, PDO::PARAM_STR);

        $stmt->bindValue(':titulo_update', $titulo, PDO::PARAM_STR);
        $stmt->bindValue(':titulo_insert', $titulo, PDO::PARAM_STR);

        $stmt->bindValue(':tipo_update', $tipoValue, $tipoValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':tipo_insert', $tipoValue, $tipoValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->bindValue(':inalienable_update', $inalienable, PDO::PARAM_INT);
        $stmt->bindValue(':inalienable_insert', $inalienable, PDO::PARAM_INT);

        $stmt->bindValue(':fuente_update', $fuente, PDO::PARAM_STR);
        $stmt->bindValue(':fuente_insert', $fuente, PDO::PARAM_STR);
        $stmt->execute();
        $affected++;
    }

    return $affected;
}

function omFetchHolidayDatesForYear(PDO $conn, int $year): array
{
    if (!omHolidaysTableAvailable($conn)) {
        return [];
    }

    $dates = omFetchHolidayDatesFromDb($conn, $year);
    if ($dates !== []) {
        return $dates;
    }

    $rows = [];
    $localFile = dirname(__DIR__, 2) . '/config/holidays/' . $year . '.json';
    if (is_file($localFile)) {
        $localPayload = @file_get_contents($localFile);
        if (is_string($localPayload)) {
            $decoded = json_decode($localPayload, true);
            if (is_array($decoded) && ($decoded['status'] ?? null) === 'success' && is_array($decoded['data'] ?? null)) {
                $rows = $decoded['data'];
            }
        }
    }

    if ($rows === []) {
        $payload = omFetchUrl('https://api.boostr.cl/holidays/' . $year . '.json', 8);
        if ($payload !== null) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && ($decoded['status'] ?? null) === 'success' && is_array($decoded['data'] ?? null)) {
                $rows = $decoded['data'];
            }
        }
    }

    if ($rows !== []) {
        omUpsertHolidaysToDb($conn, $rows, 'boostr');
        $dates = omFetchHolidayDatesFromDb($conn, $year);
    }

    return $dates;
}

function omFetchBoostrHolidaysForYear(int $year): array
{
    if ($year < 1900 || $year > 2100) {
        return [];
    }

    $localFile = dirname(__DIR__, 2) . '/config/holidays/' . $year . '.json';
    if (is_file($localFile)) {
        $localPayload = @file_get_contents($localFile);
        if (is_string($localPayload)) {
            $dates = omParseBoostrHolidayDates($localPayload);
            if ($dates !== []) {
                return $dates;
            }
            $decodedLocal = json_decode($localPayload, true);
            if (is_array($decodedLocal)) {
                $dates = [];
                foreach ($decodedLocal as $row) {
                    if (is_string($row) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row) === 1) {
                        $dates[] = $row;
                    }
                }
                if ($dates !== []) {
                    return $dates;
                }
            }
        }
    }

    $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'msp_boostr_holidays_' . $year . '.json';
    $cacheTtl = 86400;

    if (is_file($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        if ($mtime !== false && (time() - $mtime) < $cacheTtl) {
            $cached = @file_get_contents($cacheFile);
            if (is_string($cached)) {
                $dates = omParseBoostrHolidayDates($cached);
                if ($dates !== []) {
                    return $dates;
                }
            }
        }
    }

    $url = 'https://api.boostr.cl/holidays/' . $year . '.json';
    $payload = omFetchUrl($url, 8);
    $dates = omParseBoostrHolidayDates($payload);
    if ($payload !== null && $dates !== []) {
        @file_put_contents($cacheFile, $payload);
    }

    return $dates;
}

function omBusinessDaysOffsetFromMonthStart(string $periodoFacturacion, int $businessDays, array $holidayDates = []): ?int
{
    if ($businessDays <= 0) {
        return 0;
    }

    $start = DateTimeImmutable::createFromFormat('Y-m-d', $periodoFacturacion);
    if ($start === false || $start->format('Y-m-d') !== $periodoFacturacion) {
        return null;
    }

    $holidaySet = [];
    foreach ($holidayDates as $holidayDate) {
        if (is_string($holidayDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate) === 1) {
            $holidaySet[$holidayDate] = true;
        }
    }

    $count = 0;
    $current = $start;
    while (true) {
        $weekday = (int) $current->format('N'); // 1 = Lunes, 7 = Domingo
        $currentDate = $current->format('Y-m-d');
        if ($weekday <= 5 && !isset($holidaySet[$currentDate])) {
            $count++;
            if ($count >= $businessDays) {
                break;
            }
        }
        $current = $current->modify('+1 day');
        if ($current === false) {
            return null;
        }
    }

    $diff = $current->diff($start);
    return (int) $diff->format('%a');
}

function omFormatInputDecimal(mixed $value, int $decimals = 2, bool $integer = false): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $raw = str_replace(',', '.', trim((string) $value));
    if (!is_numeric($raw)) {
        return '';
    }

    if ($integer) {
        $dotPos = strpos($raw, '.');
        if ($dotPos !== false && trim(rtrim(substr($raw, $dotPos + 1), '0')) !== '') {
            return '';
        }

        return (string) (int) $raw;
    }

    return number_format((float) $raw, $decimals, ',', '');
}

function omFormatReadingInput(mixed $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $raw = str_replace(',', '.', trim((string) $value));
    if (!is_numeric($raw)) {
        return '';
    }

    return (string) (int) round((float) $raw);
}

function omIntegerInput(?string $raw, bool $required = false): array
{
    $value = trim((string) $raw);
    if ($value === '') {
        return [$required ? false : true, null];
    }

    if (!preg_match('/^\\d+$/', $value)) {
        return [false, null];
    }

    return [true, $value];
}

function omPostFlag(string $key): bool
{
    if (!isset($_POST[$key])) {
        return false;
    }

    $value = mb_strtolower(trim((string) $_POST[$key]), 'UTF-8');
    return in_array($value, ['1', 'true', 'on', 'yes', 'si'], true);
}

function omIsAjaxRequest(): bool
{
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    if ($requestedWith === 'xmlhttprequest') {
        return true;
    }

    return isset($_POST['ajax']) && (string) $_POST['ajax'] === '1';
}

function omTryAutoClosePeriodoIfReady(PDO $conn, string $periodoFacturacion, string $origen = 'sistema'): array
{
    $base = [
        'changed' => false,
        'eligible' => false,
        'reason' => 'not_evaluated',
        'id_cierre_mensual' => 0,
        'estado_actual' => 0,
    ];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodoFacturacion) !== 1) {
        $base['reason'] = 'periodo_invalido';
        return $base;
    }

    if (!msp2TableExists($conn, 'msp_cierre_mensual')) {
        $base['reason'] = 'tabla_cierre_no_disponible';
        return $base;
    }

    $cierreStmt = $conn->prepare(
        'SELECT TOP (1)
            id_cierre_mensual,
            estado_cierre
         FROM dbo.msp_cierre_mensual
         WHERE periodo_facturacion = :periodo'
    );
    $cierreStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $cierreStmt->execute();
    $cierreRow = $cierreStmt->fetch();
    if ($cierreRow === false) {
        $base['reason'] = 'cierre_no_encontrado';
        return $base;
    }

    $idCierre = (int) ($cierreRow['id_cierre_mensual'] ?? 0);
    $estadoActual = (int) ($cierreRow['estado_cierre'] ?? 0);
    $base['id_cierre_mensual'] = $idCierre;
    $base['estado_actual'] = $estadoActual;

    if ($idCierre <= 0) {
        $base['reason'] = 'cierre_invalido';
        return $base;
    }
    if ($estadoActual === 3) {
        $base['reason'] = 'ya_cerrado';
        return $base;
    }
    if ($estadoActual !== 2) {
        $base['reason'] = 'estado_no_calculado';
        return $base;
    }

    if (!class_exists('PoolDocumentosPeriodoService') || !PoolDocumentosPeriodoService::isAvailable($conn)) {
        $base['reason'] = 'pool_no_disponible';
        return $base;
    }
    if (!msp2TableExists($conn, 'msp_envio_lotes_programados')) {
        $base['reason'] = 'lotes_no_disponible';
        return $base;
    }

    $poolStats = PoolDocumentosPeriodoService::syncPeriodo($conn, $periodoFacturacion);
    $poolPendientes = (int) ($poolStats['pool_pendientes'] ?? 0);
    $poolDocumentados = (int) ($poolStats['pool_documentados'] ?? 0);
    $poolLoteados = (int) ($poolStats['pool_loteados'] ?? 0);

    if ($poolPendientes > 0) {
        $base['reason'] = 'pool_pendiente';
        return $base;
    }
    if ($poolDocumentados <= 0) {
        $base['reason'] = 'sin_documentos_en_pool';
        return $base;
    }
    if ($poolLoteados < $poolDocumentados) {
        $base['reason'] = 'documentos_no_loteados';
        return $base;
    }

    $lotesStmt = $conn->prepare(
        "SELECT
            COUNT(*) AS total_lotes,
            SUM(CASE WHEN estado_lote = 3 THEN 1 ELSE 0 END) AS lotes_completados,
            SUM(CASE WHEN estado_lote IN (1,2,4) THEN 1 ELSE 0 END) AS lotes_activos
         FROM dbo.msp_envio_lotes_programados
         WHERE periodo_facturacion = :periodo"
    );
    $lotesStmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $lotesStmt->execute();
    $lotesRow = $lotesStmt->fetch() ?: [];

    $totalLotes = (int) ($lotesRow['total_lotes'] ?? 0);
    $lotesCompletados = (int) ($lotesRow['lotes_completados'] ?? 0);
    $lotesActivos = (int) ($lotesRow['lotes_activos'] ?? 0);

    if ($totalLotes <= 0) {
        $base['reason'] = 'sin_lotes';
        return $base;
    }
    if ($lotesActivos > 0) {
        $base['reason'] = 'lotes_activos';
        return $base;
    }
    if ($lotesCompletados <= 0) {
        $base['reason'] = 'sin_lotes_completados';
        return $base;
    }

    $base['eligible'] = true;
    $origenNorm = trim($origen);
    if ($origenNorm === '') {
        $origenNorm = 'sistema';
    }
    $bitacora = 'Estado Cerrado automatico [' . (new DateTimeImmutable('now'))->format('Y-m-d H:i:s') . '] Origen: ' . mb_substr($origenNorm, 0, 120, 'UTF-8');

    $updateStmt = $conn->prepare(
        "UPDATE dbo.msp_cierre_mensual
         SET estado_cierre = 3,
             observaciones = LEFT(
                LTRIM(RTRIM(
                    CONCAT(
                        COALESCE(NULLIF(LTRIM(RTRIM(observaciones)), ''), ''),
                        CASE WHEN COALESCE(NULLIF(LTRIM(RTRIM(observaciones)), ''), '') = '' THEN '' ELSE ' | ' END,
                        :bitacora
                    )
                )),
                1000
             )
         WHERE id_cierre_mensual = :id_cierre
           AND estado_cierre = 2"
    );
    $updateStmt->bindValue(':bitacora', $bitacora, PDO::PARAM_STR);
    $updateStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
    $updateStmt->execute();

    if ($updateStmt->rowCount() > 0) {
        $base['changed'] = true;
        $base['reason'] = 'cerrado_auto';
        return $base;
    }

    $base['reason'] = 'sin_cambio';
    return $base;
}

function omJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function omFetchFirstRowsetRow(PDOStatement $stmt): array|false
{
    try {
        while (true) {
            $columnCount = 0;
            try {
                $columnCount = $stmt->columnCount();
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
            }

            if ($columnCount > 0) {
                while (true) {
                    try {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'contains no fields')) {
                            break;
                        }
                        throw $exception;
                    }

                    if ($row === false) {
                        break;
                    }
                    if (is_array($row)) {
                        return $row;
                    }
                }
            }

            try {
                if (!$stmt->nextRowset()) {
                    break;
                }
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
                break;
            }
        }
    } finally {
        try {
            $stmt->closeCursor();
        } catch (Throwable) {
            // El cursor puede quedar ya cerrado si el driver descarta rowsets intermedios.
        }
    }

    return false;
}

function omFetchFirstRowsetRows(PDOStatement $stmt): array
{
    try {
        while (true) {
            $columnCount = 0;
            try {
                $columnCount = $stmt->columnCount();
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
            }

            if ($columnCount > 0) {
                $rows = [];
                while (true) {
                    try {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $exception) {
                        if (str_contains($exception->getMessage(), 'contains no fields')) {
                            break;
                        }
                        throw $exception;
                    }

                    if ($row === false) {
                        break;
                    }
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }

                if ($rows !== []) {
                    return $rows;
                }
            }

            try {
                if (!$stmt->nextRowset()) {
                    break;
                }
            } catch (PDOException $exception) {
                if (!str_contains($exception->getMessage(), 'contains no fields')) {
                    throw $exception;
                }
                break;
            }
        }
    } finally {
        try {
            $stmt->closeCursor();
        } catch (Throwable) {
            // El cursor puede quedar ya cerrado si el driver descarta rowsets intermedios.
        }
    }

    return [];
}

function omEnvString(string $key): string
{
    $value = getenv($key);
    if (!is_string($value)) {
        return '';
    }

    return trim($value);
}

function omMailConfig(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $config = [
        'smtp' => [
            'host' => omEnvString('MAIL_SMTP_HOST'),
            'port' => omEnvString('MAIL_SMTP_PORT'),
            'secure' => omEnvString('MAIL_SMTP_SECURE'),
            'user' => omEnvString('MAIL_SMTP_USER'),
            'pass' => omEnvString('MAIL_SMTP_PASS'),
            'from_address' => omEnvString('MAIL_FROM_ADDRESS'),
            'from_name' => omEnvString('MAIL_FROM_NAME'),
        ],
        'demo' => [
            'to' => omEnvString('MAIL_DEMO_TO'),
        ],
    ];

    $configCandidates = [
        dirname(__DIR__, 2) . '/config/mail.php', // .../msp/config/mail.php
        dirname(__DIR__, 3) . '/config/mail.php', // .../portalgp/config/mail.php
        dirname(__DIR__) . '/config/mail.php',     // legacy fallback
    ];
    $configPath = '';
    foreach ($configCandidates as $candidate) {
        if (is_file($candidate)) {
            $configPath = $candidate;
            break;
        }
    }

    if ($configPath === '') {
        return $config;
    }

    $loaded = require $configPath;
    if (!is_array($loaded)) {
        return $config;
    }

    $smtpLoaded = $loaded['smtp'] ?? null;
    if (is_array($smtpLoaded)) {
        foreach (['host', 'port', 'secure', 'user', 'pass', 'from_address', 'from_name'] as $key) {
            if (!array_key_exists($key, $smtpLoaded)) {
                continue;
            }
            $value = trim((string) $smtpLoaded[$key]);
            if ($value !== '') {
                $config['smtp'][$key] = $value;
            }
        }
    }

    $demoLoaded = $loaded['demo'] ?? null;
    if (is_array($demoLoaded) && array_key_exists('to', $demoLoaded)) {
        $demoTo = trim((string) $demoLoaded['to']);
        if ($demoTo !== '') {
            $config['demo']['to'] = $demoTo;
        }
    }

    return $config;
}

function omRequireMailerLibrary(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }

    $baseCandidates = [
        dirname(__DIR__, 2), // .../msp
        dirname(__DIR__, 3), // .../portalgp
        dirname(__DIR__, 1), // .../msp/cobros
    ];
    $baseCandidates = array_values(array_unique(array_filter($baseCandidates, static fn ($v): bool => is_string($v) && $v !== '')));

    $autoloadPath = '';
    $projectRoot = '';
    foreach ($baseCandidates as $candidate) {
        $candidateAutoload = rtrim($candidate, '/\\') . '/vendor/autoload.php';
        if (is_file($candidateAutoload)) {
            $autoloadPath = $candidateAutoload;
            $projectRoot = $candidate;
            break;
        }
    }

    if ($autoloadPath === '') {
        throw new RuntimeException('No se encontro vendor/autoload.php para cargar PHPMailer (rutas revisadas: msp/, portalgp/).');
    }

    require_once $autoloadPath;

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $vendorBase = rtrim($projectRoot, '/\\') . '/vendor/phpmailer/phpmailer/src';
        $exceptionPath = $vendorBase . '/Exception.php';
        $mailerPath = $vendorBase . '/PHPMailer.php';
        $smtpPath = $vendorBase . '/SMTP.php';

        if (is_file($exceptionPath) && is_file($mailerPath) && is_file($smtpPath)) {
            require_once $exceptionPath;
            require_once $mailerPath;
            require_once $smtpPath;
        }
    }

    if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        throw new RuntimeException('PHPMailer no esta disponible en el proyecto.');
    }

    $loaded = true;
}

function omBuildSmtpMailerFromEnv(): \PHPMailer\PHPMailer\PHPMailer
{
    omRequireMailerLibrary();

    $mailConfig = omMailConfig();
    $smtpConfig = is_array($mailConfig['smtp'] ?? null) ? $mailConfig['smtp'] : [];

    $host = trim((string) ($smtpConfig['host'] ?? ''));
    $portRaw = trim((string) ($smtpConfig['port'] ?? ''));
    $username = trim((string) ($smtpConfig['user'] ?? ''));
    $password = trim((string) ($smtpConfig['pass'] ?? ''));
    $secureRaw = mb_strtolower(trim((string) ($smtpConfig['secure'] ?? '')), 'UTF-8');
    $fromAddress = trim((string) ($smtpConfig['from_address'] ?? ''));
    $fromName = trim((string) ($smtpConfig['from_name'] ?? ''));

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Falta configuracion SMTP. Revisa msp/config/mail.php (o variables MAIL_SMTP_*).');
    }

    $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    if ($secureRaw !== '') {
        if (in_array($secureRaw, ['tls', 'starttls'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($secureRaw, ['ssl', 'smtps'], true)) {
            $encryption = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } else {
            throw new RuntimeException('MAIL_SMTP_SECURE invalido. Usa `tls` o `ssl`.');
        }
    }

    $port = $portRaw !== '' && ctype_digit($portRaw) ? (int) $portRaw : 0;
    if ($port <= 0) {
        $port = $encryption === \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS ? 465 : 587;
    }

    if ($fromAddress === '') {
        $fromAddress = $username;
    }
    if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('MAIL_FROM_ADDRESS no tiene formato de correo valido.');
    }
    if ($fromName === '') {
        $fromName = 'MSP Cobros Demo';
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = $encryption;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom($fromAddress, $fromName);
    $mail->Timeout = 20;

    return $mail;
}


