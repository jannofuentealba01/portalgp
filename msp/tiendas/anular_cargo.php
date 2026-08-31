<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasAnularCargoRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['tiendas/index.php', 'arrendatarios/index.php'];

    if (!in_array($redirectTo, $allowed, true)) {
        $redirectTo = 'tiendas/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tiendas/index.php');
}

$accion = trim((string) ($_POST['accion'] ?? 'anular_cargo'));

$idCargoSalida = filter_input(INPUT_POST, 'id_cargo_salida', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idCargoSalida === false || $idCargoSalida === null) {
    msp2SetFlash('warning', 'El cargo indicado no es válido.');
    msp2TiendasAnularCargoRedirectFromPost();
}

try {
    if (!msp2TableExists($conn, 'msp_cargos_salida')) {
        throw new RuntimeException('La tabla `msp_cargos_salida` no está disponible.');
    }

    $stmtCargo = $conn->prepare(
        'SELECT estado_cargo, descripcion_cargo
         FROM dbo.msp_cargos_salida
         WHERE id_cargo_salida = :id_cargo_salida'
    );
    $stmtCargo->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
    $stmtCargo->execute();
    $rowCargo = $stmtCargo->fetch();

    if ($rowCargo === false) {
        throw new RuntimeException('El cargo ya no existe.');
    }

    $estadoCargo = (int) ($rowCargo['estado_cargo'] ?? 0);
    $descripcionCargo = trim((string) ($rowCargo['descripcion_cargo'] ?? ''));
    $labelCargo = $descripcionCargo !== '' ? $descripcionCargo : ('Cargo #' . $idCargoSalida);

    if ($accion === 'deshacer_anular_cargo') {
        if ($estadoCargo !== 5) {
            throw new RuntimeException('Solo se puede deshacer la anulación de cargos anulados.');
        }

        $stmtRestaurar = $conn->prepare(
            'UPDATE dbo.msp_cargos_salida
             SET estado_cargo = 1
             WHERE id_cargo_salida = :id_cargo_salida
               AND estado_cargo = 5'
        );
        $stmtRestaurar->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
        $stmtRestaurar->execute();

        if ($stmtRestaurar->rowCount() <= 0) {
            throw new RuntimeException('No fue posible deshacer la anulación del cargo.');
        }

        msp2SetFlash('success', 'Se deshizo la anulación del cargo.');
        msp2TiendasAnularCargoRedirectFromPost();
    }

    if ($estadoCargo !== 1) {
        throw new RuntimeException('Solo se pueden anular cargos en estado pendiente.');
    }

    $stmtAnular = $conn->prepare(
        'UPDATE dbo.msp_cargos_salida
         SET estado_cargo = 5
         WHERE id_cargo_salida = :id_cargo_salida
           AND estado_cargo = 1'
    );
    $stmtAnular->bindValue(':id_cargo_salida', $idCargoSalida, PDO::PARAM_INT);
    $stmtAnular->execute();

    if ($stmtAnular->rowCount() <= 0) {
        throw new RuntimeException('No fue posible anular el cargo porque cambió de estado.');
    }

    msp2SetFlash('success', 'El cargo fue anulado correctamente.', [
        'undo' => [
            'message' => 'Cargo anulado. Puedes deshacer esta acción.',
            'action_path' => 'tiendas/anular_cargo.php',
            'button_label' => 'Deshacer',
            'fields' => [
                'accion' => 'deshacer_anular_cargo',
                'id_cargo_salida' => (string) $idCargoSalida,
                'redirect_to' => trim((string) ($_POST['redirect_to'] ?? 'tiendas/index.php')),
                'cargo_label' => $labelCargo,
            ],
        ],
    ]);
} catch (Throwable $exception) {
    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2TiendasAnularCargoRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible anular el cargo. Intenta nuevamente.');
}

msp2TiendasAnularCargoRedirectFromPost();
