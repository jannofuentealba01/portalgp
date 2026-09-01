<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2TiendasDeactivateRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['tiendas/index.php', 'arrendatarios/index.php'];
    msp2Redirect(in_array($redirectTo, $allowed, true) ? $redirectTo : 'tiendas/index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('tiendas/index.php');
}

$idTienda = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($idTienda === false || $idTienda === null) {
    msp2SetFlash('warning', 'La tienda indicada no es válida.');
    msp2TiendasDeactivateRedirectFromPost();
}

try {
    $stmtTienda = $conn->prepare(
        "SELECT TOP 1
            t.id_tienda,
            t.nombre_comercial,
            t.fecha_inicio,
            t.fecha_termino,
            t.id_estado_tienda,
            UPPER(LTRIM(RTRIM(e.desc_estado))) AS estado_actual
         FROM dbo.msp_tiendas t
         INNER JOIN dbo.msp_estado_tiendas e
            ON e.id_estado_tienda = t.id_estado_tienda
         WHERE t.id_tienda = :id_tienda"
    );
    $stmtTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtTienda->execute();
    $tienda = $stmtTienda->fetch();

    if (!is_array($tienda)) {
        msp2SetFlash('warning', 'La tienda que intentas desactivar ya no existe.');
        msp2TiendasDeactivateRedirectFromPost();
    }

    if (in_array((string) ($tienda['estado_actual'] ?? ''), ['INACTIVO', 'CERRADO'], true)) {
        msp2SetFlash('info', 'La tienda ya se encuentra inactiva y conserva su historial.');
        msp2TiendasDeactivateRedirectFromPost();
    }

    if (msp2TableExists($conn, 'msp_contratos_arriendo')) {
        $stmtContrato = $conn->prepare(
            'SELECT TOP 1 id_contrato_arriendo
             FROM dbo.msp_contratos_arriendo
             WHERE id_tienda = :id_tienda
               AND estado_contrato IN (1, 2, 3)
             ORDER BY id_contrato_arriendo DESC'
        );
        $stmtContrato->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtContrato->execute();
        $idContratoActivo = (int) ($stmtContrato->fetchColumn() ?: 0);
        if ($idContratoActivo > 0) {
            msp2SetFlash(
                'warning',
                'La tienda tiene un contrato activo. Finaliza primero el contrato desde su ficha para cerrar correctamente cobros, garantía y locales.'
            );
            msp2TiendasDeactivateRedirectFromPost();
        }
    }

    $stmtEstado = $conn->query(
        "SELECT TOP 1 id_estado_tienda
         FROM dbo.msp_estado_tiendas
         WHERE UPPER(LTRIM(RTRIM(desc_estado))) = N'INACTIVO'
         ORDER BY id_estado_tienda ASC"
    );
    $idEstadoInactivo = (int) ($stmtEstado->fetchColumn() ?: 0);
    if ($idEstadoInactivo <= 0) {
        throw new RuntimeException('No existe el estado Inactivo para las tiendas.');
    }

    $conn->beginTransaction();

    $stmtDesactivar = $conn->prepare(
        'UPDATE dbo.msp_tiendas
         SET id_estado_tienda = :id_estado_inactivo,
             fecha_termino = CASE
                WHEN fecha_inicio IS NOT NULL AND fecha_inicio > CONVERT(date, SYSDATETIME()) THEN fecha_inicio
                ELSE CONVERT(date, SYSDATETIME())
             END
         WHERE id_tienda = :id_tienda'
    );
    $stmtDesactivar->bindValue(':id_estado_inactivo', $idEstadoInactivo, PDO::PARAM_INT);
    $stmtDesactivar->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmtDesactivar->execute();

    if (msp2TableExists($conn, 'msp_ocupacion_locales')) {
        $stmtCerrarOcupaciones = $conn->prepare(
            'UPDATE dbo.msp_ocupacion_locales
             SET fecha_termino = CASE
                WHEN fecha_inicio > CONVERT(date, SYSDATETIME()) THEN fecha_inicio
                ELSE CONVERT(date, SYSDATETIME())
             END
             WHERE id_tienda = :id_tienda
               AND (fecha_termino IS NULL OR fecha_termino > CONVERT(date, SYSDATETIME()))'
        );
        $stmtCerrarOcupaciones->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtCerrarOcupaciones->execute();
    }

    $conn->commit();
    msp2SetFlash('success', 'La tienda fue desactivada. Sus contratos, documentos y demás antecedentes históricos se conservaron.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'No fue posible desactivar la tienda.';
    msp2SetFlash('danger', $message);
}

msp2TiendasDeactivateRedirectFromPost();
