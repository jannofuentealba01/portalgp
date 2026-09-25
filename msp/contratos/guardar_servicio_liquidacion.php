<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2ServicioLiquidacionRedirect(int $idContratoArriendo): never
{
    $query = $idContratoArriendo > 0 ? '?id_contrato_arriendo=' . $idContratoArriendo : '';
    msp2Redirect('contratos/liquidacion_final.php' . $query);
}

function msp2ServicioLiquidacionFecha(string $value, string $label, bool $required = true): ?string
{
    $value = trim($value);
    if ($value === '' && !$required) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new RuntimeException($label . ' no es válida.');
    }

    return $value;
}

function msp2ServicioLiquidacionDecimal(string $value, string $label, bool $required = false): ?float
{
    $value = trim($value);
    if ($value === '' && !$required) {
        return null;
    }

    [$valid, $normalized] = msp2NormalizeDecimalInput($value, 4);
    if (!$valid || $normalized === null || $normalized < 0) {
        throw new RuntimeException($label . ' debe ser un número mayor o igual a cero.');
    }

    return (float) $normalized;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2Redirect('contratos/index.php');
}

$idContratoArriendo = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$idLiquidacionServicio = filter_input(INPUT_POST, 'id_liquidacion_servicio', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$accion = strtoupper(trim((string) ($_POST['accion'] ?? '')));
$idUsuario = isset($_SESSION['usuario']['id']) ? (int) $_SESSION['usuario']['id'] : 0;

if ($idContratoArriendo === false || $idContratoArriendo === null) {
    msp2SetFlash('warning', 'El contrato indicado no es válido.');
    msp2ServicioLiquidacionRedirect(0);
}
if ($idLiquidacionServicio === false || $idLiquidacionServicio === null) {
    msp2SetFlash('warning', 'El servicio de liquidación indicado no es válido.');
    msp2ServicioLiquidacionRedirect((int) $idContratoArriendo);
}
if (!in_array($accion, ['REGISTRAR_CONSUMO', 'MARCAR_NO_APLICA', 'CONFIRMAR_CONCILIADO', 'REABRIR_PENDIENTE'], true)) {
    msp2SetFlash('warning', 'La acción solicitada no es válida.');
    msp2ServicioLiquidacionRedirect((int) $idContratoArriendo);
}
if ($idUsuario <= 0) {
    msp2SetFlash('warning', 'No fue posible identificar al usuario para registrar la trazabilidad.');
    msp2ServicioLiquidacionRedirect((int) $idContratoArriendo);
}

try {
    foreach (['msp_liquidacion_servicios', 'msp_liquidacion_servicio_consumos', 'msp_contrato_locales', 'msp_contratos_arriendo'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta la tabla `' . $table . '`. Ejecuta el parche de servicios tardíos.');
        }
    }

    $stmtServicio = $conn->prepare(
        'SELECT ls.id_liquidacion_servicio, ls.estado_liquidacion, ls.fecha_termino_operativo,
                cl.id_contrato_arriendo, ca.estado_contrato, l.cdo_local,
                ts.codigo_servicio, m.codigo_medidor
         FROM dbo.msp_liquidacion_servicios ls
         INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ls.id_contrato_local
         INNER JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
         INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
         INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = ls.id_tipo_servicio
         LEFT JOIN dbo.msp_medidores m ON m.id_medidor = ls.id_medidor
         WHERE ls.id_liquidacion_servicio = :id_liquidacion_servicio'
    );
    $stmtServicio->bindValue(':id_liquidacion_servicio', $idLiquidacionServicio, PDO::PARAM_INT);
    $stmtServicio->execute();
    $servicio = $stmtServicio->fetch(PDO::FETCH_ASSOC);
    if ($servicio === false || (int) $servicio['id_contrato_arriendo'] !== (int) $idContratoArriendo) {
        throw new RuntimeException('El servicio no pertenece al contrato seleccionado.');
    }
    if ((int) ($servicio['estado_contrato'] ?? 0) !== 3) {
        throw new RuntimeException('Solo se pueden registrar servicios tardíos mientras el contrato está en proceso de cierre.');
    }

    $motivo = msp2NormalizeText((string) ($_POST['observaciones'] ?? ''));
    if (mb_strlen($motivo) > 500) {
        throw new RuntimeException('La observación no puede superar 500 caracteres.');
    }

    $conn->beginTransaction();

    if ($accion === 'REGISTRAR_CONSUMO') {
        if ((int) ($servicio['estado_liquidacion'] ?? 0) === 4) {
            throw new RuntimeException('El servicio está marcado como no aplicable. Reábrelo antes de registrar un consumo.');
        }
        if ((int) ($servicio['estado_liquidacion'] ?? 0) === 3) {
            throw new RuntimeException('El servicio ya fue conciliado y no admite nuevos consumos.');
        }

        $periodoMes = trim((string) ($_POST['periodo_emision_mes'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $periodoMes)) {
            throw new RuntimeException('El período de emisión debe tener formato AAAA-MM.');
        }
        $periodoEmision = msp2ServicioLiquidacionFecha($periodoMes . '-01', 'El período de emisión');
        $periodoTermino = substr((string) $servicio['fecha_termino_operativo'], 0, 7) . '-01';
        if ($periodoEmision < $periodoTermino) {
            throw new RuntimeException('El período de emisión no puede ser anterior al mes de término del contrato.');
        }

        if (msp2TableExists($conn, 'msp_documentos_cobro')) {
            $proteccionesDocumento = [];
            if (msp2TableExists($conn, 'msp_pagos')) {
                $proteccionesDocumento[] = 'EXISTS (
                    SELECT 1 FROM dbo.msp_pagos pg
                    WHERE pg.id_documento_cobro = dc.id_documento_cobro
                      AND pg.estado_pago = 1
                )';
            }
            if (msp2TableExists($conn, 'msp_envio_lote_documentos')) {
                $proteccionesDocumento[] = 'EXISTS (
                    SELECT 1 FROM dbo.msp_envio_lote_documentos eld
                    WHERE eld.id_documento_cobro = dc.id_documento_cobro
                )';
            }
            if ($proteccionesDocumento !== []) {
                $stmtPeriodoProtegido = $conn->prepare(
                    'SELECT COUNT(*)
                     FROM dbo.msp_documentos_cobro dc
                     WHERE dc.id_contrato_arriendo = :id_contrato_arriendo
                       AND dc.periodo_facturacion = :periodo_emision
                       AND (' . implode(' OR ', $proteccionesDocumento) . ')'
                );
                $stmtPeriodoProtegido->execute([
                    ':id_contrato_arriendo' => $idContratoArriendo,
                    ':periodo_emision' => $periodoEmision,
                ]);
                if ((int) $stmtPeriodoProtegido->fetchColumn() > 0) {
                    throw new RuntimeException('Ese período ya tiene un documento enviado o con pagos. Selecciona un período abierto posterior o usa una corrección autorizada.');
                }
            }
        }
        $fechaDesde = msp2ServicioLiquidacionFecha((string) ($_POST['fecha_desde_consumo'] ?? ''), 'La fecha inicial', false);
        $fechaHasta = msp2ServicioLiquidacionFecha((string) ($_POST['fecha_hasta_consumo'] ?? ''), 'La fecha final');
        if ($fechaDesde !== null && $fechaDesde > $fechaHasta) {
            throw new RuntimeException('La fecha inicial no puede ser posterior a la fecha final.');
        }

        $referencia = msp2NormalizeText((string) ($_POST['referencia_origen'] ?? ''));
        if ($referencia === '' || mb_strlen($referencia) > 100) {
            throw new RuntimeException('Debes ingresar una referencia de origen de hasta 100 caracteres.');
        }

        $lecturaAnterior = msp2ServicioLiquidacionDecimal((string) ($_POST['lectura_anterior'] ?? ''), 'La lectura anterior');
        $lecturaActual = msp2ServicioLiquidacionDecimal((string) ($_POST['lectura_actual'] ?? ''), 'La lectura actual');
        $consumoAsignado = msp2ServicioLiquidacionDecimal((string) ($_POST['consumo_asignado'] ?? ''), 'El consumo asignado');
        if ($lecturaAnterior !== null && $lecturaActual !== null && $lecturaActual < $lecturaAnterior) {
            throw new RuntimeException('La lectura actual no puede ser menor que la lectura anterior.');
        }

        [$montoValido, $montoNormalizado] = msp2NormalizeDecimalInput((string) ($_POST['monto_asignado'] ?? ''), 2);
        if (!$montoValido || $montoNormalizado === null || $montoNormalizado <= 0) {
            throw new RuntimeException('El monto asignado debe ser mayor que cero.');
        }

        if ($fechaHasta > (string) $servicio['fecha_termino_operativo'] && $motivo === '') {
            throw new RuntimeException('Explica en la observación cómo se separó el consumo posterior al término del contrato.');
        }

        $stmtInsert = $conn->prepare(
            'INSERT INTO dbo.msp_liquidacion_servicio_consumos
                (id_liquidacion_servicio, periodo_emision, fecha_desde_consumo, fecha_hasta_consumo,
                 referencia_origen, lectura_anterior, lectura_actual, consumo_asignado, monto_asignado,
                 tipo_asignacion, estado_consumo, observaciones, id_usuario)
             VALUES
                (:id_liquidacion_servicio, :periodo_emision, :fecha_desde_consumo, :fecha_hasta_consumo,
                 :referencia_origen, :lectura_anterior, :lectura_actual, :consumo_asignado, :monto_asignado,
                 2, 1, :observaciones, :id_usuario)'
        );
        $stmtInsert->bindValue(':id_liquidacion_servicio', $idLiquidacionServicio, PDO::PARAM_INT);
        $stmtInsert->bindValue(':periodo_emision', $periodoEmision, PDO::PARAM_STR);
        $stmtInsert->bindValue(':fecha_desde_consumo', $fechaDesde, $fechaDesde === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtInsert->bindValue(':fecha_hasta_consumo', $fechaHasta, PDO::PARAM_STR);
        $stmtInsert->bindValue(':referencia_origen', $referencia, PDO::PARAM_STR);
        $stmtInsert->bindValue(':lectura_anterior', $lecturaAnterior, $lecturaAnterior === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtInsert->bindValue(':lectura_actual', $lecturaActual, $lecturaActual === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtInsert->bindValue(':consumo_asignado', $consumoAsignado, $consumoAsignado === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmtInsert->bindValue(':monto_asignado', (string) $montoNormalizado, PDO::PARAM_STR);
        $stmtInsert->bindValue(':observaciones', $motivo !== '' ? $motivo : null, $motivo !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtInsert->bindValue(':id_usuario', $idUsuario, PDO::PARAM_INT);
        $stmtInsert->execute();

        $stmtUpdate = $conn->prepare(
            'UPDATE dbo.msp_liquidacion_servicios
             SET estado_liquidacion = 2,
                 id_usuario_actualizacion = :id_usuario,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio'
        );
        $stmtUpdate->execute([
            ':id_usuario' => $idUsuario,
            ':id_liquidacion_servicio' => $idLiquidacionServicio,
        ]);
        $mensaje = 'Consumo tardío registrado. Quedó pendiente de emisión en la segunda etapa.';
        $detalleEvento = 'Consumo tardío registrado para ' . (string) $servicio['codigo_servicio'] . ' del local ' . (string) $servicio['cdo_local'] . ', referencia ' . $referencia . '.';
    } elseif ($accion === 'MARCAR_NO_APLICA') {
        if ($motivo === '') {
            throw new RuntimeException('Debes indicar por qué el servicio no aplica.');
        }
        $stmtConsumos = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_liquidacion_servicio_consumos
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio
               AND estado_consumo <> 3'
        );
        $stmtConsumos->execute([':id_liquidacion_servicio' => $idLiquidacionServicio]);
        if ((int) $stmtConsumos->fetchColumn() > 0) {
            throw new RuntimeException('No puedes marcar el servicio como no aplicable porque tiene consumos registrados.');
        }

        $stmtUpdate = $conn->prepare(
            'UPDATE dbo.msp_liquidacion_servicios
             SET estado_liquidacion = 4,
                 observaciones = :observaciones,
                 id_usuario_actualizacion = :id_usuario,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio'
        );
        $stmtUpdate->execute([
            ':observaciones' => $motivo,
            ':id_usuario' => $idUsuario,
            ':id_liquidacion_servicio' => $idLiquidacionServicio,
        ]);
        $mensaje = 'El servicio quedó marcado como no aplicable.';
        $detalleEvento = 'Servicio ' . (string) $servicio['codigo_servicio'] . ' del local ' . (string) $servicio['cdo_local'] . ' marcado como no aplicable.';
    } elseif ($accion === 'CONFIRMAR_CONCILIADO') {
        if ((int) ($servicio['estado_liquidacion'] ?? 0) !== 2) {
            throw new RuntimeException('Solo se puede conciliar un servicio cuyo consumo ya fue recibido.');
        }
        if ($motivo === '') {
            throw new RuntimeException('Debes dejar una observación de conciliación.');
        }

        $stmtConsumos = $conn->prepare(
            'SELECT
                SUM(CASE WHEN estado_consumo = 1 THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN estado_consumo = 2 THEN 1 ELSE 0 END) AS emitidos
             FROM dbo.msp_liquidacion_servicio_consumos
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio
               AND estado_consumo <> 3'
        );
        $stmtConsumos->execute([':id_liquidacion_servicio' => $idLiquidacionServicio]);
        $resumenConsumos = $stmtConsumos->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($resumenConsumos['pendientes'] ?? 0) > 0) {
            throw new RuntimeException('Aún existen consumos tardíos pendientes de emisión.');
        }
        if ((int) ($resumenConsumos['emitidos'] ?? 0) === 0) {
            throw new RuntimeException('No existe un consumo emitido que permita conciliar el servicio.');
        }

        $stmtUpdate = $conn->prepare(
            'UPDATE dbo.msp_liquidacion_servicios
             SET estado_liquidacion = 3,
                 observaciones = :observaciones,
                 id_usuario_actualizacion = :id_usuario,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio'
        );
        $stmtUpdate->execute([
            ':observaciones' => $motivo,
            ':id_usuario' => $idUsuario,
            ':id_liquidacion_servicio' => $idLiquidacionServicio,
        ]);
        $mensaje = 'El servicio tardío quedó conciliado.';
        $detalleEvento = 'Servicio ' . (string) $servicio['codigo_servicio'] . ' del local ' . (string) $servicio['cdo_local'] . ' confirmado como conciliado.';
    } else {
        if (!in_array((int) ($servicio['estado_liquidacion'] ?? 0), [3,4], true)) {
            throw new RuntimeException('Solo se puede reabrir un servicio conciliado o marcado como no aplicable.');
        }
        if ($motivo === '') {
            throw new RuntimeException('Debes indicar el motivo de reapertura.');
        }

        $stmtUpdate = $conn->prepare(
            'UPDATE dbo.msp_liquidacion_servicios
             SET estado_liquidacion = 1,
                 observaciones = :observaciones,
                 id_usuario_actualizacion = :id_usuario,
                 fecha_actualizacion = SYSDATETIME()
             WHERE id_liquidacion_servicio = :id_liquidacion_servicio'
        );
        $stmtUpdate->execute([
            ':observaciones' => $motivo,
            ':id_usuario' => $idUsuario,
            ':id_liquidacion_servicio' => $idLiquidacionServicio,
        ]);
        $mensaje = 'El servicio volvió a estado pendiente.';
        $detalleEvento = 'Servicio ' . (string) $servicio['codigo_servicio'] . ' del local ' . (string) $servicio['cdo_local'] . ' reabierto como pendiente.';
    }

    if (msp2TableExists($conn, 'msp_historial_contrato')) {
        $stmtHistorial = $conn->prepare(
            "INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES
                (:id_contrato_arriendo, N'ACTUALIZACION', :id_usuario, :detalle_evento, :motivo_evento)"
        );
        $stmtHistorial->execute([
            ':id_contrato_arriendo' => $idContratoArriendo,
            ':id_usuario' => $idUsuario,
            ':detalle_evento' => $detalleEvento,
            ':motivo_evento' => $motivo !== '' ? $motivo : 'Registro de servicio tardío',
        ]);
    }

    $conn->commit();
    msp2SetFlash('success', $mensaje);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    $flashType = 'danger';
    $message = 'No fue posible guardar el servicio tardío.';
    if ($exception instanceof PDOException && str_contains($exception->getMessage(), 'UQ_msp_liq_consumo_referencia')) {
        $flashType = 'warning';
        $message = 'Ya existe un consumo con esa referencia para el servicio seleccionado.';
    } elseif (!($exception instanceof PDOException) && $exception instanceof RuntimeException) {
        $flashType = 'warning';
        $message = $exception->getMessage();
    }
    msp2SetFlash($flashType, $message);
}

msp2ServicioLiquidacionRedirect((int) $idContratoArriendo);
