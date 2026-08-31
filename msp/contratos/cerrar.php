<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosCerrarRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['deuda_garantia/index.php', 'tiendas/index.php', 'contratos/index.php'];
    $allowContratoEditar = preg_match('/^contratos\/(editar|ficha)\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;

    if (!in_array($redirectTo, $allowed, true) && !$allowContratoEditar) {
        $redirectTo = 'contratos/index.php';
    }

    msp2Redirect($redirectTo);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$fechaTerminoEfectivaRaw = trim((string) ($_POST['fecha_termino_efectiva'] ?? ''));
$motivoCierre = msp2NormalizeText((string) ($_POST['motivo_cierre'] ?? ''));
$idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'El contrato indicado no es válido.');
    msp2ContratosCerrarRedirectFromPost();
}

if ($motivoCierre === '') {
    msp2SetFlash('warning', 'Debes indicar un motivo para terminar operativamente el contrato.');
    msp2ContratosCerrarRedirectFromPost();
}
if (mb_strlen($motivoCierre) > 500) {
    msp2SetFlash('warning', 'El motivo no puede superar 500 caracteres.');
    msp2ContratosCerrarRedirectFromPost();
}

if ($fechaTerminoEfectivaRaw === '') {
    msp2SetFlash('warning', 'Debes indicar la fecha de término efectiva.');
    msp2ContratosCerrarRedirectFromPost();
}
$fechaTerminoEfectiva = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTerminoEfectivaRaw);
if ($fechaTerminoEfectiva === false || $fechaTerminoEfectiva->format('Y-m-d') !== $fechaTerminoEfectivaRaw) {
    msp2SetFlash('warning', 'La fecha de término efectiva no es válida.');
    msp2ContratosCerrarRedirectFromPost();
}
$fechaTerminoEfectivaIso = $fechaTerminoEfectiva->format('Y-m-d');

if ($idUsuarioSesion <= 0) {
    msp2SetFlash('warning', 'No fue posible identificar al usuario para registrar bitácora.');
    msp2ContratosCerrarRedirectFromPost();
}

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_contrato_locales',
        'msp_ocupacion_locales',
        'msp_bitacora_cierre_contrato',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para terminar contrato.');
        }
    }

    $tieneFechaTerminoEfectiva = msp2ColumnExists($conn, 'msp_contratos_arriendo', 'fecha_termino_efectiva');
    $moduloHistorialContratoDisponible = msp2TableExists($conn, 'msp_historial_contrato');

    $stmtContrato = $conn->prepare(
        'SELECT estado_contrato, id_tienda, fecha_inicio
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch();
    if ($contrato === false) {
        throw new RuntimeException('El contrato ya no existe.');
    }

    $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
    $idTienda = (int) ($contrato['id_tienda'] ?? 0);
    $fechaInicioContrato = (string) ($contrato['fecha_inicio'] ?? '');

    if ($fechaInicioContrato === '' || $fechaTerminoEfectivaIso < $fechaInicioContrato) {
        throw new RuntimeException('La fecha de término efectiva no puede ser menor a la fecha de inicio del contrato.');
    }
    if ($estadoContrato === 4) {
        throw new RuntimeException('El contrato ya está cerrado financieramente.');
    }
    if ($estadoContrato === 5) {
        throw new RuntimeException('El contrato está anulado y no se puede terminar.');
    }
    if ($estadoContrato === 3) {
        throw new RuntimeException('El contrato ya está en proceso de cierre.');
    }
    if (!in_array($estadoContrato, [1, 2], true)) {
        throw new RuntimeException('El estado actual del contrato no permite término operativo.');
    }

    $stmtLocalesActivos = $conn->prepare(
        'SELECT id_contrato_local, id_local
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_relacion = 1'
    );
    $stmtLocalesActivos->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtLocalesActivos->execute();
    $relacionesActivas = [];
    while (($row = $stmtLocalesActivos->fetch()) !== false) {
        $idContratoLocal = (int) ($row['id_contrato_local'] ?? 0);
        $idLocal = (int) ($row['id_local'] ?? 0);
        if ($idContratoLocal > 0 && $idLocal > 0) {
            $relacionesActivas[] = [
                'id_contrato_local' => $idContratoLocal,
                'id_local' => $idLocal,
            ];
        }
    }

    if ($tieneFechaTerminoEfectiva) {
        $stmtTerminoOperativo = $conn->prepare(
            'UPDATE dbo.msp_contratos_arriendo
             SET estado_contrato = 3,
                 fecha_termino_efectiva = :fecha_termino_efectiva
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND estado_contrato IN (1, 2)'
        );
    } else {
        $stmtTerminoOperativo = $conn->prepare(
            'UPDATE dbo.msp_contratos_arriendo
             SET estado_contrato = 3
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND estado_contrato IN (1, 2)'
        );
    }

    $stmtCerrarRelacion = $conn->prepare(
        'UPDATE dbo.msp_contrato_locales
         SET estado_relacion = 2,
             fecha_termino = CASE WHEN fecha_inicio > :fecha_corte_cmp THEN fecha_inicio ELSE :fecha_corte_set END
         WHERE id_contrato_local = :id_contrato_local
           AND estado_relacion = 1'
    );
    $stmtCerrarOcupacion = $conn->prepare(
        'UPDATE dbo.msp_ocupacion_locales
         SET fecha_termino = CASE WHEN fecha_inicio > :fecha_corte_cmp THEN fecha_inicio ELSE :fecha_corte_set END
         WHERE id_tienda = :id_tienda
           AND id_local = :id_local
           AND (fecha_termino IS NULL OR fecha_termino > :fecha_corte_where)'
    );

    $idEstadoLocalDisponible = null;
    if (msp2TableExists($conn, 'msp_locales') && msp2TableExists($conn, 'msp_estado_locales')) {
        $stmtEstadoLocalDisponible = $conn->prepare(
            "SELECT TOP 1 id_estado_local
             FROM dbo.msp_estado_locales
             WHERE UPPER(LTRIM(RTRIM(desc_estado))) = N'DISPONIBLE'
             ORDER BY id_estado_local ASC"
        );
        $stmtEstadoLocalDisponible->execute();
        $idEstadoLocalDisponibleVal = $stmtEstadoLocalDisponible->fetchColumn();
        if ($idEstadoLocalDisponibleVal !== false) {
            $idEstadoLocalDisponible = (int) $idEstadoLocalDisponibleVal;
        }
    }
    $stmtLiberarLocal = null;
    if ($idEstadoLocalDisponible !== null && $idEstadoLocalDisponible > 0 && msp2TableExists($conn, 'msp_locales')) {
        $stmtLiberarLocal = $conn->prepare(
            'UPDATE dbo.msp_locales
             SET id_estado_local = :id_estado_local_disponible
             WHERE id_local = :id_local_liberar
               AND NOT EXISTS (
                    SELECT 1
                    FROM dbo.msp_ocupacion_locales ox
                    WHERE ox.id_local = :id_local_ocupacion
                      AND ox.fecha_inicio <= :fecha_control_inicio
                      AND (ox.fecha_termino IS NULL OR ox.fecha_termino >= :fecha_control_termino)
               )'
        );
    }

    // Una tienda puede tener contratos sucesivos. Solo se cierra administrativamente
    // cuando no queda otro contrato activo asociado a ella.
    $hayOtroContratoActivo = false;
    if ($idTienda > 0) {
        $stmtOtroContrato = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_contratos_arriendo
             WHERE id_tienda = :id_tienda
               AND id_contrato_arriendo <> :id_contrato_actual
               AND estado_contrato IN (1, 2, 3)'
        );
        $stmtOtroContrato->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtOtroContrato->bindValue(':id_contrato_actual', $idContratoArriendo, PDO::PARAM_INT);
        $stmtOtroContrato->execute();
        $hayOtroContratoActivo = (int) $stmtOtroContrato->fetchColumn() > 0;
    }

    $stmtActualizarTienda = null;
    if (!$hayOtroContratoActivo && $idTienda > 0 && msp2TableExists($conn, 'msp_tiendas') && msp2ColumnExists($conn, 'msp_tiendas', 'fecha_termino')) {
        $stmtActualizarTienda = $conn->prepare(
            'UPDATE dbo.msp_tiendas
             SET fecha_termino = :fecha_termino_tienda
             WHERE id_tienda = :id_tienda'
        );
    }

    $stmtInsertBitacora = $conn->prepare(
        'INSERT INTO dbo.msp_bitacora_cierre_contrato
            (id_contrato_arriendo, id_usuario, estado_contrato_anterior, estado_contrato_nuevo, motivo_cierre)
         VALUES
            (:id_contrato_arriendo, :id_usuario, :estado_contrato_anterior, :estado_contrato_nuevo, :motivo_cierre)'
    );
    $stmtInsertHistorialContrato = null;
    if ($moduloHistorialContratoDisponible) {
        $stmtInsertHistorialContrato = $conn->prepare(
            'INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
                (:id_contrato_arriendo, :tipo_evento, :id_usuario, :detalle_evento, :motivo_evento)'
        );
    }

    $conn->beginTransaction();

    foreach ($relacionesActivas as $relacion) {
        $stmtCerrarRelacion->bindValue(':id_contrato_local', (int) $relacion['id_contrato_local'], PDO::PARAM_INT);
        $stmtCerrarRelacion->bindValue(':fecha_corte_cmp', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
        $stmtCerrarRelacion->bindValue(':fecha_corte_set', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
        $stmtCerrarRelacion->execute();

        if ($idTienda > 0) {
            $stmtCerrarOcupacion->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $stmtCerrarOcupacion->bindValue(':id_local', (int) $relacion['id_local'], PDO::PARAM_INT);
            $stmtCerrarOcupacion->bindValue(':fecha_corte_cmp', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtCerrarOcupacion->bindValue(':fecha_corte_set', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtCerrarOcupacion->bindValue(':fecha_corte_where', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtCerrarOcupacion->execute();
        }

        if ($stmtLiberarLocal instanceof PDOStatement) {
            $idLocalRelacion = (int) $relacion['id_local'];
            $stmtLiberarLocal->bindValue(':id_estado_local_disponible', $idEstadoLocalDisponible, PDO::PARAM_INT);
            $stmtLiberarLocal->bindValue(':id_local_liberar', $idLocalRelacion, PDO::PARAM_INT);
            $stmtLiberarLocal->bindValue(':id_local_ocupacion', $idLocalRelacion, PDO::PARAM_INT);
            $stmtLiberarLocal->bindValue(':fecha_control_inicio', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtLiberarLocal->bindValue(':fecha_control_termino', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtLiberarLocal->execute();
        }
    }

    $stmtTerminoOperativo->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    if ($tieneFechaTerminoEfectiva) {
        $stmtTerminoOperativo->bindValue(':fecha_termino_efectiva', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
    }
    $stmtTerminoOperativo->execute();
    if ($stmtTerminoOperativo->rowCount() <= 0) {
        throw new RuntimeException('No fue posible registrar el término operativo. Intenta nuevamente.');
    }

    if ($stmtActualizarTienda instanceof PDOStatement) {
        $stmtActualizarTienda->bindValue(':fecha_termino_tienda', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
        $stmtActualizarTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtActualizarTienda->execute();
    }

    $stmtInsertBitacora->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':estado_contrato_anterior', $estadoContrato, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':estado_contrato_nuevo', 3, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':motivo_cierre', $motivoCierre, PDO::PARAM_STR);
    $stmtInsertBitacora->execute();

    if ($stmtInsertHistorialContrato instanceof PDOStatement) {
        $detalle = [
            'origen' => 'contratos/cerrar.php',
            'tipo' => 'termino_operativo',
            'estado_anterior' => $estadoContrato,
            'estado_nuevo' => 3,
            'fecha_termino_efectiva' => $fechaTerminoEfectivaIso,
            'relaciones_cerradas' => count($relacionesActivas),
            'tienda_cerrada' => !$hayOtroContratoActivo,
            'otro_contrato_activo' => $hayOtroContratoActivo,
        ];
        $detalleJson = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmtInsertHistorialContrato->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtInsertHistorialContrato->bindValue(':tipo_evento', 'ACTUALIZACION', PDO::PARAM_STR);
        $stmtInsertHistorialContrato->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
        $stmtInsertHistorialContrato->bindValue(':detalle_evento', $detalleJson !== false ? $detalleJson : null, $detalleJson !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertHistorialContrato->bindValue(':motivo_evento', $motivoCierre, PDO::PARAM_STR);
        $stmtInsertHistorialContrato->execute();
    }

    $conn->commit();
    msp2SetFlash('success', 'Término operativo registrado. El contrato quedó en proceso de cierre.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosCerrarRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible registrar el término operativo.');
}

msp2ContratosCerrarRedirectFromPost();
