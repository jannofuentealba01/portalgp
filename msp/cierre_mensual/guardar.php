<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/services/CierreMensualService.php';

msp2RequireAccess();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('cierre_mensual/index.php');
}

$idCierre = filter_input(INPUT_POST, 'id_cierre_mensual', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$periodoRaw = trim((string) ($_POST['periodo'] ?? ''));
$fechaUfRaw = trim((string) ($_POST['fecha_valor_uf'] ?? ''));
$valorUfRaw = $_POST['valor_uf'] ?? null;
$observaciones = trim((string) ($_POST['observaciones'] ?? ''));

if ($periodoRaw === '') {
    msp2SetFlash('warning', 'Debes indicar el periodo del cierre.');
    msp2Redirect('cierre_mensual/index.php');
}

$periodoDate = DateTimeImmutable::createFromFormat('!Y-m', $periodoRaw);
if ($periodoDate === false || $periodoDate->format('Y-m') !== $periodoRaw) {
    msp2SetFlash('warning', 'El periodo debe tener formato YYYY-MM.');
    msp2Redirect('cierre_mensual/index.php');
}

$periodoFacturacion = $periodoDate->format('Y-m-01');

$dateUf = DateTimeImmutable::createFromFormat('Y-m-d', $fechaUfRaw);
if ($dateUf === false) {
    msp2SetFlash('warning', 'La fecha valor UF no es válida.');
    msp2Redirect('cierre_mensual/index.php');
}

[$valorUfValido, $valorUfNormalizado] = msp2NormalizeDecimalInput(is_string($valorUfRaw) ? $valorUfRaw : null, 6);
if (!$valorUfValido || $valorUfNormalizado === null) {
    msp2SetFlash('warning', 'El valor UF debe ser un número válido mayor o igual a cero.');
    msp2Redirect('cierre_mensual/index.php');
}

try {
    if (!msp2TableExists($conn, 'msp_cierre_mensual')) {
        msp2SetFlash('warning', 'Falta la tabla `msp_cierre_mensual`. Ejecuta `msp/db/msp_cobro_servicios.sql`.');
        msp2Redirect('cierre_mensual/index.php');
    }

    if ($idCierre !== null && $idCierre !== false) {
        $estadoStmt = $conn->prepare('SELECT estado_cierre FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual = :id');
        $estadoStmt->execute([':id' => $idCierre]);
        $estadoActual = $estadoStmt->fetchColumn();
        if ($estadoActual === false) {
            throw new RuntimeException('El cierre ya no existe.');
        }
        if ((int) $estadoActual !== CierreMensualService::BORRADOR) {
            throw new RuntimeException('Para editar el período primero debes devolverlo a Borrador.');
        }
        $stmt = $conn->prepare(
            'UPDATE dbo.msp_cierre_mensual
             SET periodo_facturacion = :periodo,
                 fecha_valor_uf = :fecha_uf,
                 valor_uf = :valor_uf,
                 observaciones = :observaciones
             WHERE id_cierre_mensual = :id'
        );
        $stmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO dbo.msp_cierre_mensual
                (periodo_facturacion, fecha_valor_uf, valor_uf, estado_cierre, observaciones)
             VALUES
                (:periodo, :fecha_uf, :valor_uf, :estado, :observaciones)'
        );
    }

    $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmt->bindValue(':fecha_uf', $dateUf->format('Y-m-d'), PDO::PARAM_STR);
    $stmt->bindValue(':valor_uf', $valorUfNormalizado, PDO::PARAM_STR);
    if ($idCierre === null || $idCierre === false) {
        $stmt->bindValue(':estado', CierreMensualService::BORRADOR, PDO::PARAM_INT);
    }
    $stmt->bindValue(':observaciones', $observaciones !== '' ? $observaciones : null, $observaciones !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();

    msp2SetFlash('success', $idCierre ? 'Cierre actualizado correctamente.' : 'Cierre creado correctamente.');
} catch (Throwable $exception) {
    msp2SetFlash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'No fue posible guardar el cierre mensual.');
}

msp2Redirect('cierre_mensual/index.php');
