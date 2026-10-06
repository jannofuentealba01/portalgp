<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once __DIR__ . '/devolucion_service.php';
msp2RequireAccess('MSP Cobranza', 'eliminacion');
msp2RequireValidCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('garantias/index.php');
}
$tipo = strtoupper(trim((string) ($_POST['tipo_origen'] ?? '')));
$id = filter_input(INPUT_POST, 'id_origen', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idTienda = filter_input(INPUT_POST, 'id_garantia_tienda', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$idLegacy = filter_input(INPUT_POST, 'id_garantia', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$motivo = msp2NormalizeText((string) ($_POST['motivo'] ?? ''));
$redirect = $idTienda ? 'garantias/ficha.php?id_garantia_tienda=' . (int) $idTienda
    : ($idLegacy ? 'garantias/ficha.php?id=' . (int) $idLegacy : 'garantias/index.php');
if (!$id || !in_array($tipo, ['RECEPCION', 'DEVOLUCION', 'APLICACION'], true) || $motivo === '' || mb_strlen($motivo) > 500) {
    msp2SetFlash('warning', 'Indica una operación y un motivo válido para la reversa.');
    msp2Redirect($redirect);
}
try {
    $rows = msp2DevolucionQuery($conn, 'EXEC dbo.msp_garantia_tienda_revertir_operacion
        @tipo_origen=:tipo,@id_origen=:id,@fecha_reversa=:fecha,@motivo=:motivo,@id_usuario=:usuario',
        [':tipo' => $tipo, ':id' => (int) $id, ':fecha' => date('Y-m-d'), ':motivo' => $motivo,
         ':usuario' => (int) $_SESSION['usuario']['id']]);
    msp2SetFlash('success', 'Reversa #' . (int) ($rows[0]['id_reversa_garantia'] ?? 0)
        . ' registrada sin borrar el original. Los registros administrativos vinculados quedan anulados.');
} catch (Throwable $error) {
    $messages = [
        51908 => 'La caja está cerrada. Primero debe existir una reapertura autorizada.',
        51909 => 'El período bancario tiene una conciliación registrada; no puede modificarse por esta reversa.',
        52003 => 'El movimiento está conciliado o la caja está cerrada.',
        52005 => 'La devolución no existe o ya fue anulada.',
        53932 => 'La reversa requiere un usuario habilitado.',
        53933 => 'Falta el movimiento original o la fecha de reversa es anterior a su registro.',
    ];
    $code = $error instanceof PDOException ? (int) ($error->errorInfo[1] ?? 0) : 0;
    msp2SetFlash('warning', $messages[$code] ?? pgpPublicOrBusinessException($error,
        'msp.garantias.revertir', 'No fue posible registrar la reversa. No se confirmaron cambios parciales.'));
}
msp2Redirect($redirect);
