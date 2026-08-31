<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ContratosFinalizarCierreRedirectFromPost(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    $allowed = ['contratos/index.php', 'tiendas/index.php', 'deuda_garantia/index.php'];
    $allowContratoEditar = preg_match('/^contratos\/editar\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;
    $allowContratoFicha = preg_match('/^contratos\/ficha\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1;

    if (!in_array($redirectTo, $allowed, true) && !$allowContratoEditar && !$allowContratoFicha) {
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
$periodoCorteMesRaw = trim((string) ($_POST['periodo_corte_mes'] ?? ''));
$motivoCierre = msp2NormalizeText((string) ($_POST['motivo_cierre_financiero'] ?? ''));
$idUsuarioSesion = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'El contrato indicado no es válido.');
    msp2ContratosFinalizarCierreRedirectFromPost();
}
if ($periodoCorteMesRaw === '' || !preg_match('/^\d{4}-\d{2}$/', $periodoCorteMesRaw)) {
    msp2SetFlash('warning', 'Debes indicar el periodo de corte (AAAA-MM).');
    msp2ContratosFinalizarCierreRedirectFromPost();
}
$periodoCorteIso = $periodoCorteMesRaw . '-01';
$periodoCorteDate = DateTimeImmutable::createFromFormat('Y-m-d', $periodoCorteIso);
if ($periodoCorteDate === false || $periodoCorteDate->format('Y-m-d') !== $periodoCorteIso) {
    msp2SetFlash('warning', 'El periodo de corte no es válido.');
    msp2ContratosFinalizarCierreRedirectFromPost();
}
if ($motivoCierre !== '' && mb_strlen($motivoCierre) > 500) {
    msp2SetFlash('warning', 'El motivo del cierre definitivo no puede superar 500 caracteres.');
    msp2ContratosFinalizarCierreRedirectFromPost();
}
if ($idUsuarioSesion <= 0) {
    msp2SetFlash('warning', 'No fue posible identificar al usuario para registrar bitácora.');
    msp2ContratosFinalizarCierreRedirectFromPost();
}

try {
    $requiredTables = [
        'msp_contratos_arriendo',
        'msp_bitacora_cierre_contrato',
        'msp_contrato_locales',
        'msp_ocupacion_locales',
        'msp_liquidaciones_finales',
        'msp_deudas_historicas',
    ];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para cierre definitivo.');
        }
    }

    $stmtContrato = $conn->prepare(
        'SELECT id_tienda, estado_contrato, fecha_inicio, fecha_termino_efectiva
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch();
    if ($contrato === false) {
        throw new RuntimeException('El contrato no existe.');
    }

    $idTienda = (int) ($contrato['id_tienda'] ?? 0);
    $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
    $fechaInicio = (string) ($contrato['fecha_inicio'] ?? '');
    $fechaTerminoEfectiva = (string) ($contrato['fecha_termino_efectiva'] ?? '');

    if ($estadoContrato === 4) {
        throw new RuntimeException('El contrato ya está cerrado financieramente.');
    }
    if ($estadoContrato === 5) {
        throw new RuntimeException('El contrato está anulado.');
    }
    if ($estadoContrato !== 3) {
        throw new RuntimeException('Primero debes registrar el término operativo (estado proceso de cierre).');
    }

    $periodoMinimo = $fechaTerminoEfectiva !== ''
        ? (new DateTimeImmutable($fechaTerminoEfectiva))->modify('first day of this month')->format('Y-m-d')
        : (($fechaInicio !== '') ? (new DateTimeImmutable($fechaInicio))->modify('first day of this month')->format('Y-m-d') : $periodoCorteIso);
    if ($periodoCorteIso < $periodoMinimo) {
        throw new RuntimeException('El periodo de corte no puede ser anterior al mes de término operativo.');
    }

    if (msp2TableExists($conn, 'msp_cierre_mensual')) {
        $stmtCierreMes = $conn->prepare(
            'SELECT estado_cierre
             FROM dbo.msp_cierre_mensual
             WHERE periodo_facturacion = :periodo_corte'
        );
        $stmtCierreMes->bindValue(':periodo_corte', $periodoCorteIso, PDO::PARAM_STR);
        $stmtCierreMes->execute();
        $estadoCierreMes = $stmtCierreMes->fetchColumn();
        if ($estadoCierreMes === false) {
            throw new RuntimeException(
                'No existe cierre mensual para el periodo de corte seleccionado. '
                . 'Créalo primero en Facturación > Operación mensual.'
            );
        }
        $estadoCierreMesInt = (int) $estadoCierreMes;
        if ($estadoCierreMesInt === 4) {
            throw new RuntimeException('El periodo de corte está anulado.');
        }
        if (!in_array($estadoCierreMesInt, [2, 3], true)) {
            throw new RuntimeException('El periodo de corte debe estar en estado Calculado o Cerrado.');
        }
    }

    $cargosPendientes = 0;
    $deudaCargos = 0.0;
    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $stmtCargosNuevo = $conn->prepare(
            'SELECT SUM(CASE WHEN ccl.estado_cargo = 2 THEN 1 ELSE 0 END) AS cantidad_bloqueante,
                    ISNULL(SUM(CASE
                        WHEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)>0
                        THEN ISNULL(ccl.monto_cargo,0)-ISNULL(ccl.monto_aplicado_garantia,0)-ISNULL(ccl.monto_pagado_directo,0)
                        ELSE 0 END),0) AS saldo
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
               AND ccl.estado_cargo IN (1,2)'
        );
        $stmtCargosNuevo->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargosNuevo->execute();
        $cargoNuevo = $stmtCargosNuevo->fetch(PDO::FETCH_ASSOC) ?: [];
        $cargosPendientes += (int) ($cargoNuevo['cantidad_bloqueante'] ?? 0);
        $deudaCargos += (float) ($cargoNuevo['saldo'] ?? 0);
    }
    if (msp2TableExists($conn, 'msp_cargos_salida')) {
        $stmtCargosLegacy = $conn->prepare(
            'SELECT SUM(CASE WHEN cs.estado_cargo = 2 THEN 1 ELSE 0 END) AS cantidad_bloqueante,
                    ISNULL(SUM(ISNULL(cs.monto_cargo,0)),0) AS saldo
             FROM dbo.msp_cargos_salida cs
             WHERE cs.id_contrato_arriendo = :id_contrato_arriendo
               AND cs.estado_cargo IN (1,2)'
        );
        $stmtCargosLegacy->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargosLegacy->execute();
        $cargoLegacy = $stmtCargosLegacy->fetch(PDO::FETCH_ASSOC) ?: [];
        $cargosPendientes += (int) ($cargoLegacy['cantidad_bloqueante'] ?? 0);
        $deudaCargos += (float) ($cargoLegacy['saldo'] ?? 0);
    }
    if ($cargosPendientes > 0) {
        throw new RuntimeException('No se puede cerrar financieramente: existen cargos reservados. Libera o aplica esas reservas antes de continuar.');
    }

    $garantiaAplicada = 0.0;
    $garantiaDisponible = 0.0;
    $garantiaDevuelta = 0.0;
    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtReservas = $conn->prepare(
            'SELECT ISNULL(SUM(CASE WHEN gr.monto_reservado > 0 THEN 1 ELSE 0 END),0) AS reservas,
                    ISNULL(SUM(ISNULL(gr.monto_aplicado,0)),0) AS aplicada,
                    ISNULL(SUM(ISNULL(gr.monto_disponible,0)),0) AS disponible,
                    ISNULL(SUM(ISNULL(gr.monto_devuelto,0)),0) AS devuelta
             FROM dbo.msp_vw_garantias_control_integral gr
             INNER JOIN dbo.msp_garantias g ON g.id_garantia = gr.id_garantia
             WHERE g.id_contrato_arriendo = :id_contrato_arriendo
               AND g.estado_garantia <> 6'
        );
        $stmtReservas->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtReservas->execute();
        $garantiaResumen = $stmtReservas->fetch(PDO::FETCH_ASSOC) ?: [];
        $reservasGarantia = (int) ($garantiaResumen['reservas'] ?? 0);
        $garantiaAplicada = (float) ($garantiaResumen['aplicada'] ?? 0);
        $garantiaDisponible = (float) ($garantiaResumen['disponible'] ?? 0);
        $garantiaDevuelta = (float) ($garantiaResumen['devuelta'] ?? 0);
        if ($reservasGarantia > 0) {
            throw new RuntimeException('No se puede cerrar financieramente: existen saldos reservados en garantía.');
        }
    }

    $deudaDocumental = 0.0;
    $documentosPendientes = 0;
    if (msp2TableExists($conn, 'msp_documentos_cobro')) {
        $stmtDocsPendientes = $conn->prepare(
             'SELECT COUNT(*) AS cantidad,
                    ISNULL(SUM(ISNULL(dc.saldo_pendiente,0)),0) AS saldo
             FROM dbo.msp_documentos_cobro dc
             OUTER APPLY (
                 SELECT TOP (1) c_hist.id_contrato_arriendo
                 FROM dbo.msp_contratos_arriendo c_hist
                 WHERE c_hist.id_tienda = dc.id_tienda
                   AND c_hist.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                   AND (c_hist.fecha_termino_efectiva IS NULL OR c_hist.fecha_termino_efectiva >= dc.periodo_facturacion)
                   AND c_hist.estado_contrato IN (1,2,3,4)
                 ORDER BY c_hist.fecha_inicio DESC, c_hist.id_contrato_arriendo DESC
             ) contrato_documento
             WHERE COALESCE(dc.id_contrato_arriendo, contrato_documento.id_contrato_arriendo) = :id_contrato_arriendo
               AND dc.periodo_facturacion <= :periodo_corte
               AND dc.estado_documento IN (1,2,3)
               AND dc.saldo_pendiente > 0'
        );
        $stmtDocsPendientes->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtDocsPendientes->bindValue(':periodo_corte', $periodoCorteIso, PDO::PARAM_STR);
        $stmtDocsPendientes->execute();
        $documentosResumen = $stmtDocsPendientes->fetch(PDO::FETCH_ASSOC) ?: [];
        $documentosPendientes = (int) ($documentosResumen['cantidad'] ?? 0);
        $deudaDocumental = (float) ($documentosResumen['saldo'] ?? 0);
    }

    $deudaResidual = max(0.0, $deudaDocumental + $deudaCargos);
    $derivarDeudaHistorica = filter_var($_POST['derivar_deuda_historica'] ?? false, FILTER_VALIDATE_BOOL);
    // Un saldo residual solo puede derivarse cuando la garantía ya fue
    // aplicada o resuelta (devuelta). Si todavía queda disponible, primero
    // debe decidirse formalmente su aplicación/devolución.
    if ($deudaResidual > 0.005 && $garantiaDisponible > 0.005) {
        throw new RuntimeException('No se puede derivar la deuda histórica mientras exista garantía disponible. Aplica o devuelve la garantía y vuelve a liquidar.');
    }
    if ($deudaResidual > 0.005 && !$derivarDeudaHistorica) {
        throw new RuntimeException('Existe saldo residual. Confirma su derivación a Deudores exarrendatarios para cerrar el contrato.');
    }

    if ($fechaTerminoEfectiva !== ''
        && msp2TableExists($conn, 'msp_medidores')
        && msp2TableExists($conn, 'msp_lecturas_medidores')) {
        $stmtLecturasFaltantes = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_medidores m ON m.id_local = cl.id_local
             OUTER APPLY (
                 SELECT TOP (1) lm.periodo_facturacion
                 FROM dbo.msp_lecturas_medidores lm
                 WHERE lm.id_medidor = m.id_medidor
                 ORDER BY lm.periodo_facturacion DESC, lm.id_lectura DESC
             ) ultima
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
               AND (ultima.periodo_facturacion IS NULL
                    OR ultima.periodo_facturacion < :periodo_minimo)'
        );
        $stmtLecturasFaltantes->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtLecturasFaltantes->bindValue(':periodo_minimo', $periodoMinimo, PDO::PARAM_STR);
        $stmtLecturasFaltantes->execute();
        if ((int) $stmtLecturasFaltantes->fetchColumn() > 0) {
            throw new RuntimeException('No se puede cerrar financieramente: faltan lecturas finales del mes de término.');
        }
    }
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

    $idEstadoTiendaCerrado = null;
    if (msp2TableExists($conn, 'msp_estado_tiendas')) {
        $stmtEstadoTiendaCerrado = $conn->prepare(
            "SELECT TOP 1 id_estado_tienda
             FROM dbo.msp_estado_tiendas
             WHERE UPPER(LTRIM(RTRIM(desc_estado))) = N'CERRADO'
             ORDER BY id_estado_tienda ASC"
        );
        $stmtEstadoTiendaCerrado->execute();
        $estadoTienda = $stmtEstadoTiendaCerrado->fetchColumn();
        if ($estadoTienda !== false) {
            $idEstadoTiendaCerrado = (int) $estadoTienda;
        }
    }

    $stmtCerrarContrato = $conn->prepare(
        'UPDATE dbo.msp_contratos_arriendo
         SET estado_contrato = 4
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_contrato = 3'
    );
    $stmtActualizarTienda = null;
    if (!$hayOtroContratoActivo && $idTienda > 0 && msp2TableExists($conn, 'msp_tiendas')) {
        $sets = [];
        if (msp2ColumnExists($conn, 'msp_tiendas', 'fecha_termino')) {
            $sets[] = 'fecha_termino = CASE WHEN fecha_termino IS NULL THEN :fecha_termino ELSE fecha_termino END';
        }
        if ($idEstadoTiendaCerrado !== null && $idEstadoTiendaCerrado > 0) {
            $sets[] = 'id_estado_tienda = :id_estado_tienda';
        }
        if ($sets !== []) {
            $stmtActualizarTienda = $conn->prepare(
                'UPDATE dbo.msp_tiendas
                 SET ' . implode(', ', $sets) . '
                 WHERE id_tienda = :id_tienda'
            );
        }
    }

    $stmtInsertBitacora = $conn->prepare(
        'INSERT INTO dbo.msp_bitacora_cierre_contrato
            (id_contrato_arriendo, id_usuario, estado_contrato_anterior, estado_contrato_nuevo, motivo_cierre)
         VALUES
            (:id_contrato_arriendo, :id_usuario, :estado_contrato_anterior, :estado_contrato_nuevo, :motivo_cierre)'
    );

    $stmtInsertHistorial = null;
    if (msp2TableExists($conn, 'msp_historial_contrato')) {
        $stmtInsertHistorial = $conn->prepare(
            'INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
                (:id_contrato_arriendo, :tipo_evento, :id_usuario, :detalle_evento, :motivo_evento)'
        );
    }

    $stmtLiquidacionExistente = $conn->prepare(
        'SELECT TOP (1) id_liquidacion_final
         FROM dbo.msp_liquidaciones_finales
         WHERE id_contrato_arriendo = :id_contrato_arriendo
         ORDER BY id_liquidacion_final DESC'
    );
    $stmtLiquidacionUpdate = $conn->prepare(
        'UPDATE dbo.msp_liquidaciones_finales
         SET fecha_corte = :fecha_corte,
             deuda = :deuda,
             garantia_disponible = :garantia_disponible,
             garantia_aplicada = :garantia_aplicada,
             garantia_devuelta = :garantia_devuelta,
             saldo_final = :saldo_final,
             estado = N\'APROBADA\',
             observaciones = :observaciones,
             id_usuario = :id_usuario
         WHERE id_liquidacion_final = :id_liquidacion_final'
    );
    $stmtLiquidacionInsert = $conn->prepare(
        'INSERT INTO dbo.msp_liquidaciones_finales
            (id_contrato_arriendo, fecha_corte, deuda, garantia_disponible, garantia_aplicada, garantia_devuelta, saldo_final, estado, observaciones, id_usuario)
         OUTPUT INSERTED.id_liquidacion_final
         VALUES
            (:id_contrato_arriendo, :fecha_corte, :deuda, :garantia_disponible, :garantia_aplicada, :garantia_devuelta, :saldo_final, N\'APROBADA\', :observaciones, :id_usuario)'
    );
    $stmtDeudaHistoricaUpdate = $conn->prepare(
        'UPDATE dbo.msp_deudas_historicas
         SET id_liquidacion_final = :id_liquidacion_final,
             periodo_corte = :periodo_corte,
             fecha_termino_operativo = :fecha_termino_operativo,
             deuda_documental = :deuda_documental,
             deuda_cargos = :deuda_cargos,
             deuda_total = :deuda_total,
             garantia_aplicada = :garantia_aplicada,
             garantia_disponible = :garantia_disponible,
             garantia_devuelta = :garantia_devuelta,
             saldo_residual = :saldo_residual,
             motivo = :motivo,
             id_usuario = :id_usuario,
             fecha_actualizacion = SYSDATETIME()
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_deuda = N\'ACTIVA\''
    );
    $stmtDeudaHistoricaInsert = $conn->prepare(
        'INSERT INTO dbo.msp_deudas_historicas
            (id_contrato_arriendo, id_liquidacion_final, periodo_corte, fecha_termino_operativo,
             deuda_documental, deuda_cargos, deuda_total, garantia_aplicada, garantia_disponible,
             garantia_devuelta, saldo_residual, estado_deuda, motivo, id_usuario)
         VALUES
            (:id_contrato_arriendo, :id_liquidacion_final, :periodo_corte, :fecha_termino_operativo,
             :deuda_documental, :deuda_cargos, :deuda_total, :garantia_aplicada, :garantia_disponible,
             :garantia_devuelta, :saldo_residual, N\'ACTIVA\', :motivo, :id_usuario)'
    );
    $stmtDeudaHistoricaExists = $conn->prepare(
        'SELECT TOP (1) id_deuda_historica
         FROM dbo.msp_deudas_historicas
         WHERE id_contrato_arriendo = :id_contrato_arriendo
           AND estado_deuda = N\'ACTIVA\'
         ORDER BY id_deuda_historica DESC'
    );

    $conn->beginTransaction();

    $observacionesLiquidacion = $motivoCierre !== ''
        ? $motivoCierre
        : ('Liquidación final al periodo ' . $periodoCorteMesRaw);
    $stmtLiquidacionExistente->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtLiquidacionExistente->execute();
    $idLiquidacionFinal = (int) ($stmtLiquidacionExistente->fetchColumn() ?: 0);
    $liquidacionParams = [
        ':fecha_corte' => $periodoCorteIso,
        ':deuda' => $deudaResidual,
        ':garantia_disponible' => $garantiaDisponible,
        ':garantia_aplicada' => $garantiaAplicada,
        ':garantia_devuelta' => $garantiaDevuelta,
        ':saldo_final' => $deudaResidual,
        ':observaciones' => $observacionesLiquidacion,
        ':id_usuario' => $idUsuarioSesion,
    ];
    if ($idLiquidacionFinal > 0) {
        $stmtLiquidacionUpdate->bindValue(':fecha_corte', $liquidacionParams[':fecha_corte'], PDO::PARAM_STR);
        $stmtLiquidacionUpdate->bindValue(':deuda', $liquidacionParams[':deuda']);
        $stmtLiquidacionUpdate->bindValue(':garantia_disponible', $liquidacionParams[':garantia_disponible']);
        $stmtLiquidacionUpdate->bindValue(':garantia_aplicada', $liquidacionParams[':garantia_aplicada']);
        $stmtLiquidacionUpdate->bindValue(':garantia_devuelta', $liquidacionParams[':garantia_devuelta']);
        $stmtLiquidacionUpdate->bindValue(':saldo_final', $liquidacionParams[':saldo_final']);
        $stmtLiquidacionUpdate->bindValue(':observaciones', $liquidacionParams[':observaciones'], PDO::PARAM_STR);
        $stmtLiquidacionUpdate->bindValue(':id_usuario', $liquidacionParams[':id_usuario'], PDO::PARAM_INT);
        $stmtLiquidacionUpdate->bindValue(':id_liquidacion_final', $idLiquidacionFinal, PDO::PARAM_INT);
        $stmtLiquidacionUpdate->execute();
    } else {
        $stmtLiquidacionInsert->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtLiquidacionInsert->bindValue(':fecha_corte', $liquidacionParams[':fecha_corte'], PDO::PARAM_STR);
        $stmtLiquidacionInsert->bindValue(':deuda', $liquidacionParams[':deuda']);
        $stmtLiquidacionInsert->bindValue(':garantia_disponible', $liquidacionParams[':garantia_disponible']);
        $stmtLiquidacionInsert->bindValue(':garantia_aplicada', $liquidacionParams[':garantia_aplicada']);
        $stmtLiquidacionInsert->bindValue(':garantia_devuelta', $liquidacionParams[':garantia_devuelta']);
        $stmtLiquidacionInsert->bindValue(':saldo_final', $liquidacionParams[':saldo_final']);
        $stmtLiquidacionInsert->bindValue(':observaciones', $liquidacionParams[':observaciones'], PDO::PARAM_STR);
        $stmtLiquidacionInsert->bindValue(':id_usuario', $liquidacionParams[':id_usuario'], PDO::PARAM_INT);
        $idLiquidacionFinal = (int) ($stmtLiquidacionInsert->execute() ? ($stmtLiquidacionInsert->fetchColumn() ?: 0) : 0);
        if ($idLiquidacionFinal <= 0) {
            throw new RuntimeException('No fue posible registrar la liquidación final.');
        }
    }

    if ($deudaResidual > 0.005) {
        $deudaHistoricaParams = [
            ':id_contrato_arriendo' => $idContratoArriendo,
            ':id_liquidacion_final' => $idLiquidacionFinal,
            ':periodo_corte' => $periodoCorteIso,
            ':fecha_termino_operativo' => $fechaTerminoEfectiva !== '' ? $fechaTerminoEfectiva : null,
            ':deuda_documental' => $deudaDocumental,
            ':deuda_cargos' => $deudaCargos,
            ':deuda_total' => $deudaResidual,
            ':garantia_aplicada' => $garantiaAplicada,
            ':garantia_disponible' => $garantiaDisponible,
            ':garantia_devuelta' => $garantiaDevuelta,
            ':saldo_residual' => $deudaResidual,
            ':motivo' => $motivoCierre !== '' ? $motivoCierre : 'Saldo residual derivado a Deudores exarrendatarios',
            ':id_usuario' => $idUsuarioSesion,
        ];
        foreach ($deudaHistoricaParams as $param => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmtDeudaHistoricaUpdate->bindValue($param, $value, $type);
        }
        $stmtDeudaHistoricaExists->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtDeudaHistoricaExists->execute();
        $deudaHistoricaExiste = (int) ($stmtDeudaHistoricaExists->fetchColumn() ?: 0) > 0;
        $stmtDeudaHistoricaUpdate->execute();
        if (!$deudaHistoricaExiste) {
            foreach ($deudaHistoricaParams as $param => $value) {
                $type = is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmtDeudaHistoricaInsert->bindValue($param, $value, $type);
            }
            $stmtDeudaHistoricaInsert->execute();
        }
    }

    $stmtCerrarContrato->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtCerrarContrato->execute();
    if ($stmtCerrarContrato->rowCount() <= 0) {
        throw new RuntimeException('No fue posible cerrar financieramente el contrato.');
    }

    if ($stmtActualizarTienda instanceof PDOStatement) {
        if (msp2ColumnExists($conn, 'msp_tiendas', 'fecha_termino')) {
            $fechaTerminoSet = $fechaTerminoEfectiva !== '' ? $fechaTerminoEfectiva : $periodoCorteDate->modify('last day of this month')->format('Y-m-d');
            $stmtActualizarTienda->bindValue(':fecha_termino', $fechaTerminoSet, PDO::PARAM_STR);
        }
        if ($idEstadoTiendaCerrado !== null && $idEstadoTiendaCerrado > 0) {
            $stmtActualizarTienda->bindValue(':id_estado_tienda', $idEstadoTiendaCerrado, PDO::PARAM_INT);
        }
        $stmtActualizarTienda->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtActualizarTienda->execute();
    }

    $stmtInsertBitacora->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':estado_contrato_anterior', 3, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':estado_contrato_nuevo', 4, PDO::PARAM_INT);
    $stmtInsertBitacora->bindValue(':motivo_cierre', $motivoCierre !== '' ? $motivoCierre : ('Cierre financiero al periodo ' . $periodoCorteMesRaw), PDO::PARAM_STR);
    $stmtInsertBitacora->execute();

    if ($stmtInsertHistorial instanceof PDOStatement) {
        $detalle = [
            'origen' => 'contratos/finalizar_cierre_financiero.php',
            'tipo' => 'cierre_financiero',
            'periodo_corte' => $periodoCorteIso,
            'deuda_residual' => round($deudaResidual, 2),
            'deuda_historica_derivada' => $deudaResidual > 0.005,
            'id_liquidacion_final' => $idLiquidacionFinal,
        ];
        $detalleJson = json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmtInsertHistorial->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':tipo_evento', 'CIERRE', PDO::PARAM_STR);
        $stmtInsertHistorial->bindValue(':id_usuario', $idUsuarioSesion, PDO::PARAM_INT);
        $stmtInsertHistorial->bindValue(':detalle_evento', $detalleJson !== false ? $detalleJson : null, $detalleJson !== false ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertHistorial->bindValue(':motivo_evento', $motivoCierre !== '' ? $motivoCierre : null, $motivoCierre !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsertHistorial->execute();
    }

    $conn->commit();
    $mensajeCierre = $deudaResidual > 0.005
        ? 'Cierre financiero completado. El saldo residual fue derivado a Deudores exarrendatarios.'
        : 'Cierre financiero completado. No quedó deuda residual.';
    if ($hayOtroContratoActivo) {
        $mensajeCierre .= ' La tienda conserva su estado porque mantiene otro contrato activo.';
    } else {
        $mensajeCierre .= ' Contrato y tienda quedaron cerrados.';
    }
    msp2SetFlash('success', $mensajeCierre);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    if ($exception instanceof RuntimeException) {
        msp2SetFlash('warning', $exception->getMessage());
        msp2ContratosFinalizarCierreRedirectFromPost();
    }

    msp2SetFlash('danger', 'No fue posible finalizar el cierre definitivo.');
}

msp2ContratosFinalizarCierreRedirectFromPost();



