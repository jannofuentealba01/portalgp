<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

function msp2AnularContratoRedirect(): never
{
    $redirectTo = trim((string) ($_POST['redirect_to'] ?? ''));
    if (preg_match('/^contratos\/ficha\.php\?id_contrato_arriendo=[1-9][0-9]*$/', $redirectTo) === 1) {
        msp2Redirect($redirectTo);
    }
    msp2Redirect('contratos/index.php');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    msp2AnularContratoRedirect();
}

$idContrato = filter_input(INPUT_POST, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$motivo = msp2NormalizeText((string) ($_POST['motivo_anulacion'] ?? ''));
$idUsuario = (int) ($_SESSION['usuario']['id'] ?? 0);

if ($idContrato === false || $idContrato === null) {
    msp2SetFlash('warning', 'El contrato indicado no es válido.');
    msp2AnularContratoRedirect();
}
if ($motivo === '' || mb_strlen($motivo) > 500) {
    msp2SetFlash('warning', 'Debes indicar un motivo de anulación de hasta 500 caracteres.');
    msp2AnularContratoRedirect();
}
if ($idUsuario <= 0) {
    msp2SetFlash('warning', 'No fue posible identificar al usuario que realiza la anulación.');
    msp2AnularContratoRedirect();
}

try {
    foreach (['msp_contratos_arriendo', 'msp_contrato_locales', 'msp_ocupacion_locales', 'msp_bitacora_cierre_contrato'] as $table) {
        if (!msp2TableExists($conn, $table)) {
            throw new RuntimeException('Falta la tabla `' . $table . '` para anular el contrato.');
        }
    }

    $contratoStmt = $conn->prepare(
        'SELECT id_tienda, estado_contrato
         FROM dbo.msp_contratos_arriendo
         WHERE id_contrato_arriendo = :id_contrato_arriendo'
    );
    $contratoStmt->execute([':id_contrato_arriendo' => (int) $idContrato]);
    $contrato = $contratoStmt->fetch();
    if ($contrato === false) {
        throw new RuntimeException('El contrato no existe.');
    }

    $estadoAnterior = (int) ($contrato['estado_contrato'] ?? 0);
    $idTienda = (int) ($contrato['id_tienda'] ?? 0);
    if ($estadoAnterior === 5) {
        throw new RuntimeException('El contrato ya está anulado.');
    }
    if (!in_array($estadoAnterior, [1, 2], true)) {
        throw new RuntimeException('Solo se pueden anular contratos en estado Borrador o Vigente. Un contrato en cierre o terminado debe seguir su flujo financiero.');
    }

    $bloqueos = [];
    $countByContract = static function (PDO $conn, string $sql, int $idContrato): int {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id_contrato_arriendo' => $idContrato]);
        return (int) $stmt->fetchColumn();
    };

    if (msp2TableExists($conn, 'msp_documentos_cobro')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*) FROM dbo.msp_documentos_cobro
             WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_documento <> 5',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' documento(s) de cobro no anulado(s)';
        }
        if (msp2TableExists($conn, 'msp_pagos')) {
            $cantidad = $countByContract($conn,
                'SELECT COUNT(*)
                 FROM dbo.msp_pagos p
                 INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                 WHERE dc.id_contrato_arriendo = :id_contrato_arriendo AND p.estado_pago = 1',
                (int) $idContrato
            );
            if ($cantidad > 0) {
                $bloqueos[] = $cantidad . ' pago(s) aplicado(s)';
            }
        }
    }
    if (msp2TableExists($conn, 'msp_arriendo_local_snapshot_periodo')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*) FROM dbo.msp_arriendo_local_snapshot_periodo
             WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_snapshot IN (1,2,3)',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' cálculo(s) mensual(es) de arriendo';
        }
    }
    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*)
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo AND ccl.estado_cargo <> 5',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' cargo(s) vigente(s)';
        }
    }
    if (msp2TableExists($conn, 'msp_cargos_salida')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*) FROM dbo.msp_cargos_salida
             WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_cargo <> 5',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' cargo(s) de salida vigente(s)';
        }
    }
    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*)
             FROM dbo.msp_garantias g
             INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
             WHERE g.id_contrato_arriendo = :id_contrato_arriendo
               AND (gr.monto_disponible > 0 OR gr.monto_reservado > 0)',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' garantía(s) con saldo disponible o reservado';
        }
    }
    if (msp2TableExists($conn, 'msp_pool_documentos_periodo')) {
        $cantidad = $countByContract($conn,
            'SELECT COUNT(*) FROM dbo.msp_pool_documentos_periodo
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND (id_documento_cobro IS NOT NULL OR id_lote_envio_ultimo IS NOT NULL OR saldo_aplicado_total <> 0)',
            (int) $idContrato
        );
        if ($cantidad > 0) {
            $bloqueos[] = $cantidad . ' registro(s) en lotes/documentos del período';
        }
    }

    if ($bloqueos !== []) {
        throw new RuntimeException(
            'No se puede anular todavía: ' . implode('; ', $bloqueos)
            . '. Revierte esos movimientos desde la Zona de corrección de Operación mensual y vuelve a intentarlo.'
        );
    }

    $localesStmt = $conn->prepare(
        'SELECT id_contrato_local, id_local, fecha_inicio
         FROM dbo.msp_contrato_locales
         WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_relacion = 1'
    );
    $localesStmt->execute([':id_contrato_arriendo' => (int) $idContrato]);
    $locales = $localesStmt->fetchAll();

    $conn->beginTransaction();

    if (msp2TableExists($conn, 'msp_pool_documentos_periodo')) {
        $deletePoolLimpioStmt = $conn->prepare(
            'DELETE FROM dbo.msp_pool_documentos_periodo
             WHERE id_contrato_arriendo = :id_contrato_arriendo
               AND id_documento_cobro IS NULL
               AND id_lote_envio_ultimo IS NULL
               AND saldo_aplicado_total = 0'
        );
        $deletePoolLimpioStmt->execute([':id_contrato_arriendo' => (int) $idContrato]);
    }

    $updateContratoStmt = $conn->prepare(
        'UPDATE dbo.msp_contratos_arriendo
         SET estado_contrato = 5, fecha_termino_efectiva = NULL
         WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_contrato IN (1,2)'
    );
    $updateContratoStmt->execute([':id_contrato_arriendo' => (int) $idContrato]);
    if ($updateContratoStmt->rowCount() !== 1) {
        throw new RuntimeException('El contrato cambió de estado antes de completar la anulación.');
    }

    $updateRelacionStmt = $conn->prepare(
        'UPDATE dbo.msp_contrato_locales
         SET estado_relacion = 3, fecha_termino = fecha_inicio
         WHERE id_contrato_arriendo = :id_contrato_arriendo AND estado_relacion = 1'
    );
    $updateRelacionStmt->execute([':id_contrato_arriendo' => (int) $idContrato]);

    $deleteOcupacionStmt = $conn->prepare(
        'DELETE FROM dbo.msp_ocupacion_locales
         WHERE id_tienda = :id_tienda AND id_local = :id_local AND fecha_inicio = :fecha_inicio'
    );
    $idsLocales = [];
    foreach ($locales as $local) {
        $idLocal = (int) ($local['id_local'] ?? 0);
        $fechaInicio = (string) ($local['fecha_inicio'] ?? '');
        if ($idLocal <= 0 || $fechaInicio === '') {
            continue;
        }
        $deleteOcupacionStmt->execute([
            ':id_tienda' => $idTienda,
            ':id_local' => $idLocal,
            ':fecha_inicio' => $fechaInicio,
        ]);
        $idsLocales[] = $idLocal;
    }

    $bitacoraStmt = $conn->prepare(
        'INSERT INTO dbo.msp_bitacora_cierre_contrato
            (id_contrato_arriendo, id_usuario, estado_contrato_anterior, estado_contrato_nuevo, motivo_cierre)
         VALUES (:id_contrato_arriendo, :id_usuario, :estado_anterior, 5, :motivo)'
    );
    $bitacoraStmt->execute([
        ':id_contrato_arriendo' => (int) $idContrato,
        ':id_usuario' => $idUsuario,
        ':estado_anterior' => $estadoAnterior,
        ':motivo' => $motivo,
    ]);

    if (msp2TableExists($conn, 'msp_historial_contrato')) {
        $historialStmt = $conn->prepare(
            "INSERT INTO dbo.msp_historial_contrato
                (id_contrato_arriendo, tipo_evento, id_usuario, detalle_evento, motivo_evento)
             VALUES (:id_contrato_arriendo, N'ACTUALIZACION', :id_usuario, N'Contrato anulado y locales liberados.', :motivo)"
        );
        $historialStmt->execute([
            ':id_contrato_arriendo' => (int) $idContrato,
            ':id_usuario' => $idUsuario,
            ':motivo' => $motivo,
        ]);
    }

    if ($idsLocales !== []) {
        msp2SyncLocalStatuses($conn, array_values(array_unique($idsLocales)));
    }

    $conn->commit();
    msp2SetFlash('success', 'Contrato #' . (int) $idContrato . ' anulado correctamente. Sus locales quedaron disponibles.');
} catch (Throwable $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    msp2SetFlash('danger', $exception->getMessage() !== '' ? $exception->getMessage() : 'No fue posible anular el contrato.');
}

msp2AnularContratoRedirect();
