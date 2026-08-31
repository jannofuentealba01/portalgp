<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosTraspasoRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['contratos/index.php'];
    $allowFicha = preg_match('/^contratos\/ficha\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;

    if (!in_array($redirectTo, $allowed, true) && !$allowFicha) {
        $redirectTo = 'contratos/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContratoOrigen = filter_input(INPUT_POST, 'id_contrato_origen', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idArrendatarioDestino = filter_input(INPUT_POST, 'id_arrendatario_destino', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaTraspasoRaw = trim((string) ($_POST['fecha_traspaso'] ?? ''));
$motivo = msp2NormalizeText((string) ($_POST['motivo_traspaso'] ?? ''));
$idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

if ($idContratoOrigen === false || $idContratoOrigen === null) {
    msp2SetFlash('warning', 'El contrato origen no es válido.');
    msp2ContratosTraspasoRedirectFromPost();
}
if ($idArrendatarioDestino === false || $idArrendatarioDestino === null) {
    msp2SetFlash('warning', 'Debes seleccionar un arrendatario destino válido.');
    msp2ContratosTraspasoRedirectFromPost();
}
if ($fechaTraspasoRaw === '') {
    msp2SetFlash('warning', 'Debes indicar la fecha de traspaso.');
    msp2ContratosTraspasoRedirectFromPost();
}
$fechaTraspaso = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTraspasoRaw);
if ($fechaTraspaso === false || $fechaTraspaso->format('Y-m-d') !== $fechaTraspasoRaw) {
    msp2SetFlash('warning', 'La fecha de traspaso no es válida.');
    msp2ContratosTraspasoRedirectFromPost();
}
if ($fechaTraspaso->format('Y-m-d') !== $fechaTraspaso->modify('last day of this month')->format('Y-m-d')) {
    msp2SetFlash('warning', 'El traspaso por cambio de razón social solo se permite con fecha de fin de mes.');
    msp2ContratosTraspasoRedirectFromPost();
}
if ($motivo === '') {
    msp2SetFlash('warning', 'Debes indicar un motivo de traspaso.');
    msp2ContratosTraspasoRedirectFromPost();
}
if (mb_strlen($motivo) > 500) {
    msp2SetFlash('warning', 'El motivo no puede superar 500 caracteres.');
    msp2ContratosTraspasoRedirectFromPost();
}
if ($idUsuarioSesion <= 0) {
    msp2SetFlash('warning', 'No fue posible identificar al usuario para registrar el traspaso.');
    msp2ContratosTraspasoRedirectFromPost();
}

try {
    if (!msp2ProcedureExists($conn, 'msp_contrato_traspasar_razon_social')) {
        throw new RuntimeException('No está disponible el procedimiento de traspaso. Ejecuta el patch de DB correspondiente.');
    }

    $stmt = $conn->prepare(
        'DECLARE @id_contrato_destino INT;
         EXEC dbo.msp_contrato_traspasar_razon_social
            @id_contrato_origen = :id_contrato_origen,
            @id_arrendatario_destino = :id_arrendatario_destino,
            @fecha_traspaso = :fecha_traspaso,
            @motivo = :motivo,
            @id_usuario = :id_usuario,
            @id_contrato_destino = @id_contrato_destino OUTPUT;
         SELECT @id_contrato_destino AS id_contrato_destino;'
    );
    $stmt->bindValue(':id_contrato_origen', (int) $idContratoOrigen, PDO::PARAM_INT);
    $stmt->bindValue(':id_arrendatario_destino', (int) $idArrendatarioDestino, PDO::PARAM_INT);
    $stmt->bindValue(':fecha_traspaso', $fechaTraspaso->format('Y-m-d'), PDO::PARAM_STR);
    $stmt->bindValue(':motivo', $motivo, PDO::PARAM_STR);
    $stmt->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
    $stmt->execute();

    $idContratoDestino = 0;
    do {
        $row = $stmt->fetch();
        if ($row !== false && isset($row['id_contrato_destino'])) {
            $idContratoDestino = (int) $row['id_contrato_destino'];
            break;
        }
    } while ($stmt->nextRowset());

    if ($idContratoDestino <= 0) {
        throw new RuntimeException('El traspaso no devolvió contrato destino. Intenta nuevamente.');
    }

    msp2SetFlash('success', 'Traspaso registrado. Nuevo contrato #' . $idContratoDestino . ' creado y contrato origen enviado a proceso de cierre.');
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosTraspasoRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible completar el traspaso del contrato.');
}

msp2ContratosTraspasoRedirectFromPost();
