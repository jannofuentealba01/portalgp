<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

function pgpUpdateOwnPassword(PDO $db, int $actorId, int $sessionVersion, array $post): int
{
    if ($actorId <= 0 || (isset($post['id']) && (string)$post['id'] !== (string)$actorId)) {
        throw new InvalidArgumentException('Solo puedes modificar tu propio perfil.');
    }
    $current = $post['password_actual'] ?? null;
    $next = $post['nueva_password'] ?? '';
    if (!is_string($current) || $current === '' || !is_string($next)) {
        throw new InvalidArgumentException('Indica la contraseña actual.');
    }
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT UserName,correo_electronico,password_hash,estado_id,security_version FROM dbo.cr_usuarios WITH(UPDLOCK,HOLDLOCK) WHERE id=:id');
        $stmt->execute([':id'=>$actorId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['estado_id'] !== 1 || (int)$user['security_version'] !== $sessionVersion) {
            throw new RuntimeException('La sesión ya no está autorizada. Inicia sesión nuevamente.');
        }
        if (!password_verify($current, (string)$user['password_hash'])) {
            throw new InvalidArgumentException('La contraseña actual no es correcta.');
        }
        if ($next !== '') {
            pgpRequireStrongPassword($next, (string)$user['UserName'], (string)$user['correo_electronico']);
            $update = $db->prepare('UPDATE dbo.cr_usuarios SET password_hash=:hash WHERE id=:id');
            $update->execute([':hash'=>password_hash($next, PASSWORD_BCRYPT), ':id'=>$actorId]);
        }
        $version = (int)pgpSecurityUser($db, $actorId)['security_version'];
        $db->commit();
        return $version;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}
