<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess('MSP Tesoreria', 'eliminacion');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tesoreria/reaperturas.php');
}

$idSolicitud = filter_input(INPUT_POST, 'id_solicitud_reapertura', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$decision = strtoupper(trim((string) ($_POST['decision'] ?? '')));
$observacion = msp2NormalizeText((string) ($_POST['observacion'] ?? ''));

try {
    if (!$idSolicitud || !in_array($decision, ['APROBAR', 'RECHAZAR'], true)) {
        throw new RuntimeException('La solicitud o la decisión no es válida.');
    }
    if ($decision === 'RECHAZAR' && mb_strlen($observacion) < 5) {
        throw new RuntimeException('Indica el motivo del rechazo con al menos 5 caracteres.');
    }
    if (mb_strlen($observacion) > 1000) {
        throw new RuntimeException('La observación no puede superar 1000 caracteres.');
    }
    if (!msp2ProcedureExists($conn, 'msp_tesoreria_resolver_reapertura_caja')) {
        throw new RuntimeException('El flujo seguro de reapertura aún no está instalado en la base de datos.');
    }
    $stmt = $conn->prepare('EXEC dbo.msp_tesoreria_resolver_reapertura_caja @id_solicitud_reapertura=:solicitud,@decision=:decision,@observacion=:observacion,@id_usuario_resuelve=:resuelve');
    $stmt->execute([
        ':solicitud' => (int) $idSolicitud,
        ':decision' => $decision,
        ':observacion' => $observacion !== '' ? $observacion : null,
        ':resuelve' => (int) ($_SESSION['usuario']['id'] ?? 0),
    ]);
    $stmt->fetch();
    msp2SetFlash('success', $decision === 'APROBAR' ? 'Solicitud aprobada y caja reabierta.' : 'Solicitud rechazada.');
} catch (Throwable $e) {
    msp2SetFlash($e instanceof RuntimeException ? 'warning' : 'danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible resolver la solicitud de reapertura.');
}

msp2Redirect('tesoreria/reaperturas.php');
