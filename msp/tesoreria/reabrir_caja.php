<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
msp2RequireAccess('MSP Tesoreria', 'escritura');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tesoreria/reaperturas.php');
}

$idCierre = filter_input(INPUT_POST, 'id_cierre_caja', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$motivo = msp2NormalizeText((string) ($_POST['motivo'] ?? ''));

try {
    if (!$idCierre || mb_strlen($motivo) < 10 || mb_strlen($motivo) > 1000) {
        throw new RuntimeException('Indica un motivo de entre 10 y 1000 caracteres.');
    }
    if (!msp2ProcedureExists($conn, 'msp_tesoreria_solicitar_reapertura_caja')) {
        throw new RuntimeException('El flujo seguro de reapertura aún no está instalado en la base de datos.');
    }
    $stmt = $conn->prepare('EXEC dbo.msp_tesoreria_solicitar_reapertura_caja @id_cierre_caja=:cierre,@motivo=:motivo,@id_usuario_solicita=:solicita');
    $stmt->execute([
        ':cierre' => (int) $idCierre,
        ':motivo' => $motivo,
        ':solicita' => (int) ($_SESSION['usuario']['id'] ?? 0),
    ]);
    $stmt->fetch();
    msp2SetFlash('success', 'Solicitud registrada. Otro usuario autorizado debe aprobarla desde su propia sesión.');
} catch (Throwable $e) {
    msp2SetFlash($e instanceof RuntimeException ? 'warning' : 'danger', $e instanceof RuntimeException ? $e->getMessage() : 'No fue posible registrar la solicitud de reapertura.');
}

msp2Redirect('tesoreria/reaperturas.php');
