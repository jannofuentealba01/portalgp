<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

const PGP_LOGIN_WINDOW_SECONDS = 900;
const PGP_LOGIN_THRESHOLD = 8;
const PGP_LOGIN_MAX_BLOCK_SECONDS = 900;

function pgpLoginKey(string $type, string $value): string
{
    return hash('sha256', $type . '|' . mb_strtolower(trim($value), 'UTF-8'));
}

function pgpLoginOrigin(array $server): string
{
    // Deliberately ignore forwarding headers unless a trusted proxy is configured later.
    $address = trim((string)($server['REMOTE_ADDR'] ?? 'unknown'));
    return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : 'unknown';
}

function pgpLoginKeys(string $username, array $server): array
{
    return [
        ['hash'=>pgpLoginKey('CUENTA', $username), 'type'=>'CUENTA'],
        ['hash'=>pgpLoginKey('ORIGEN', pgpLoginOrigin($server)), 'type'=>'ORIGEN'],
    ];
}

function pgpLoginRetryAfter(PDO $db, string $username, array $server): int
{
    $keys = pgpLoginKeys($username, $server);
    $stmt = $db->prepare('SELECT DATEDIFF(SECOND,SYSDATETIME(),bloqueado_hasta)
        FROM dbo.cr_login_intentos WHERE clave_hash=:hash AND bloqueado_hasta>SYSDATETIME()');
    $wait = 0;
    foreach ($keys as $key) {
        $stmt->execute([':hash'=>$key['hash']]);
        $wait = max($wait, (int)($stmt->fetchColumn() ?: 0));
    }
    return max(0, $wait);
}

function pgpRecordLoginFailure(PDO $db, string $username, array $server): int
{
    $db->beginTransaction();
    try {
        $select = $db->prepare('SELECT intentos_fallidos,DATEDIFF(SECOND,ventana_inicio,SYSDATETIME()) AS edad_ventana
            FROM dbo.cr_login_intentos WITH(UPDLOCK,HOLDLOCK) WHERE clave_hash=:hash');
        $insert = $db->prepare('INSERT INTO dbo.cr_login_intentos(clave_hash,tipo_clave,intentos_fallidos)
            VALUES(:hash,:type,1)');
        $update = $db->prepare('UPDATE dbo.cr_login_intentos SET intentos_fallidos=:attempts,
            ventana_inicio=CASE WHEN CAST(:reset_window AS INT)=1 THEN SYSDATETIME() ELSE ventana_inicio END,
            ultimo_intento=SYSDATETIME(),
            bloqueado_hasta=CASE WHEN CAST(:block_seconds AS INT)>0 THEN DATEADD(SECOND,CAST(:block_seconds2 AS INT),SYSDATETIME()) ELSE NULL END
            WHERE clave_hash=:hash');
        foreach (pgpLoginKeys($username, $server) as $key) {
            $select->execute([':hash'=>$key['hash']]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $insert->execute([':hash'=>$key['hash'], ':type'=>$key['type']]);
                continue;
            }
            $resetWindow = (int)($row['edad_ventana'] ?? 0) > PGP_LOGIN_WINDOW_SECONDS;
            $attempts = $resetWindow ? 1 : ((int)$row['intentos_fallidos'] + 1);
            $exponent = max(0, min(5, $attempts - PGP_LOGIN_THRESHOLD));
            $blockSeconds = $attempts >= PGP_LOGIN_THRESHOLD
                ? min(PGP_LOGIN_MAX_BLOCK_SECONDS, 30 * (2 ** $exponent))
                : 0;
            $update->execute([
                ':attempts'=>$attempts,
                ':reset_window'=>$resetWindow ? 1 : 0,
                ':block_seconds'=>$blockSeconds,
                ':block_seconds2'=>$blockSeconds,
                ':hash'=>$key['hash'],
            ]);
        }
        // Bounded opportunistic cleanup; no user or credential data is stored here.
        $db->exec("DELETE FROM dbo.cr_login_intentos WHERE ultimo_intento<DATEADD(DAY,-2,SYSDATETIME())");
        $db->commit();
        return pgpLoginRetryAfter($db, $username, $server);
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}

function pgpRecordLoginSuccess(PDO $db, string $username): void
{
    $stmt = $db->prepare("DELETE FROM dbo.cr_login_intentos WHERE clave_hash=:hash AND tipo_clave='CUENTA'");
    $stmt->execute([':hash'=>pgpLoginKey('CUENTA', $username)]);
}

function pgpGenericLoginFailure(int $retryAfter = 0): never
{
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
    }
    // Same public message for unknown, disabled, wrong-password and limited accounts.
    pgpRedirectToLogin('No fue posible iniciar sesión. Revisa tus credenciales o espera unos minutos antes de intentarlo nuevamente.');
}
