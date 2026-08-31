<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess();
msp2RequireValidCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tesoreria/conciliacion.php');
}
$idCierre = filter_input(INPUT_POST, 'id_cierre_caja', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idAutoriza = filter_input(INPUT_POST, 'id_usuario_autoriza', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$motivo = msp2NormalizeText((string) ($_POST['motivo'] ?? ''));
$fecha = trim((string) ($_POST['fecha_caja'] ?? date('Y-m-d')));
try {
    if (!$idCierre || !$idAutoriza || mb_strlen($motivo) < 10 || mb_strlen($motivo) > 1000) {
        throw new RuntimeException('Indica un autorizador habilitado y un motivo de al menos 10 caracteres.');
    }
    if (!msp2ProcedureExists($conn, 'msp_tesoreria_reabrir_caja')) {
        throw new RuntimeException('La reapertura autorizada aún no está instalada en la base de datos.');
    }
    $stmt = $conn->prepare('EXEC dbo.msp_tesoreria_reabrir_caja @id_cierre_caja=:cierre,@motivo=:motivo,@id_usuario_solicita=:solicita,@id_usuario_autoriza=:autoriza');
    $stmt->execute([':cierre'=>(int) $idCierre, ':motivo'=>$motivo, ':solicita'=>(int) ($_SESSION['usuario']['id'] ?? 0), ':autoriza'=>(int) $idAutoriza]);
    $stmt->fetch();
    msp2SetFlash('success', 'Caja reabierta correctamente. La operación quedó registrada en la bitácora.');
} catch (Throwable $e) {
    msp2SetFlash($e instanceof RuntimeException ? 'warning' : 'danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible reabrir la caja.');
}
msp2Redirect('tesoreria/conciliacion.php?fecha_caja=' . rawurlencode($fecha));

