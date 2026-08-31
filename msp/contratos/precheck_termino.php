<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

header('Content-Type: application/json; charset=UTF-8');

$idContratoArriendoRaw = trim((string) ($_GET['id_contrato_arriendo'] ?? ''));
$fechaTerminoEfectivaRaw = trim((string) ($_GET['fecha_termino_efectiva'] ?? ''));

$idContratoArriendo = filter_var($idContratoArriendoRaw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContratoArriendo === false || $idContratoArriendo === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Contrato inválido.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if ($fechaTerminoEfectivaRaw === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Debes indicar fecha de término efectiva.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$fechaTerminoEfectiva = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTerminoEfectivaRaw);
if ($fechaTerminoEfectiva === false || $fechaTerminoEfectiva->format('Y-m-d') !== $fechaTerminoEfectivaRaw) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Fecha de término efectiva inválida.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
$fechaTerminoEfectivaIso = $fechaTerminoEfectiva->format('Y-m-d');

try {
    $requiredTables = ['msp_contratos_arriendo', 'msp_contrato_locales'];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            throw new RuntimeException('Falta la tabla `' . $tableName . '` para validar término.');
        }
    }

    $stmtContrato = $conn->prepare(
        'SELECT c.id_tienda, c.estado_contrato, c.fecha_inicio
         FROM dbo.msp_contratos_arriendo c
         WHERE c.id_contrato_arriendo = :id_contrato_arriendo'
    );
    $stmtContrato->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch();
    if ($contrato === false) {
        throw new RuntimeException('El contrato no existe.');
    }

    $idTienda = (int) ($contrato['id_tienda'] ?? 0);
    $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
    $fechaInicioContrato = (string) ($contrato['fecha_inicio'] ?? '');
    if ($fechaInicioContrato !== '' && $fechaTerminoEfectivaIso < $fechaInicioContrato) {
        throw new RuntimeException('La fecha de término efectiva no puede ser menor a la fecha de inicio del contrato.');
    }

    $cargosPendientes = 0;
    if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
        $stmtCargosNuevo = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_cargos_contrato_local ccl
             INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local = ccl.id_contrato_local
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
               AND ccl.estado_cargo IN (1,2)'
        );
        $stmtCargosNuevo->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargosNuevo->execute();
        $cargosPendientes += (int) $stmtCargosNuevo->fetchColumn();
    }
    if (msp2TableExists($conn, 'msp_cargos_salida')) {
        if (msp2TableExists($conn, 'msp_cargos_contrato_local')) {
            $stmtCargosLegacy = $conn->prepare(
                'SELECT COUNT(*)
                 FROM dbo.msp_cargos_salida cs
                 WHERE cs.id_contrato_arriendo = :id_contrato_arriendo
                   AND cs.estado_cargo IN (1,2)
                   AND NOT EXISTS (
                        SELECT 1
                        FROM dbo.msp_cargos_contrato_local cclx
                        WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
                   )'
            );
        } else {
            $stmtCargosLegacy = $conn->prepare(
                'SELECT COUNT(*)
                 FROM dbo.msp_cargos_salida cs
                 WHERE cs.id_contrato_arriendo = :id_contrato_arriendo
                   AND cs.estado_cargo IN (1,2)'
            );
        }
        $stmtCargosLegacy->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtCargosLegacy->execute();
        $cargosPendientes += (int) $stmtCargosLegacy->fetchColumn();
    }

    $garantiasReservadas = 0;
    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtGarantiasRes = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_vw_garantias_control_integral gr
             INNER JOIN dbo.msp_garantias g ON g.id_garantia = gr.id_garantia
             WHERE g.id_contrato_arriendo = :id_contrato_arriendo
               AND g.estado_garantia <> 6
               AND gr.monto_reservado > 0'
        );
        $stmtGarantiasRes->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtGarantiasRes->execute();
        $garantiasReservadas = (int) $stmtGarantiasRes->fetchColumn();
    }

    $deudaVencidaCount = 0;
    $documentos = [];
    if ($idTienda > 0 && msp2TableExists($conn, 'msp_documentos_cobro')) {
        $stmtDeudaVencidaCount = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_documentos_cobro dc
             WHERE dc.id_tienda = :id_tienda
               AND (dc.id_contrato_arriendo = :id_contrato_arriendo OR dc.id_contrato_arriendo IS NULL)
               AND dc.estado_documento IN (2,3)
               AND dc.saldo_pendiente > 0
               AND dc.fecha_vencimiento <= :fecha_corte'
        );
        $stmtDeudaVencidaCount->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
        $stmtDeudaVencidaCount->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtDeudaVencidaCount->bindValue(':fecha_corte', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
        $stmtDeudaVencidaCount->execute();
        $deudaVencidaCount = (int) $stmtDeudaVencidaCount->fetchColumn();

        if ($deudaVencidaCount > 0) {
            $stmtDocs = $conn->prepare(
                'SELECT TOP (10)
                    dc.numero_documento,
                    dc.fecha_vencimiento,
                    dc.saldo_pendiente
                 FROM dbo.msp_documentos_cobro dc
                 WHERE dc.id_tienda = :id_tienda
                   AND (dc.id_contrato_arriendo = :id_contrato_arriendo OR dc.id_contrato_arriendo IS NULL)
                   AND dc.estado_documento IN (2,3)
                   AND dc.saldo_pendiente > 0
                   AND dc.fecha_vencimiento <= :fecha_corte
                 ORDER BY dc.fecha_vencimiento ASC, dc.id_documento_cobro ASC'
            );
            $stmtDocs->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
            $stmtDocs->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
            $stmtDocs->bindValue(':fecha_corte', $fechaTerminoEfectivaIso, PDO::PARAM_STR);
            $stmtDocs->execute();
            while (($row = $stmtDocs->fetch()) !== false) {
                $documentos[] = [
                    'numero_documento' => msp2NormalizeText((string) ($row['numero_documento'] ?? '')),
                    'fecha_vencimiento' => (string) ($row['fecha_vencimiento'] ?? ''),
                    'saldo_pendiente' => msp2FormatoDecimal($row['saldo_pendiente'] ?? 0, 2, '$ '),
                ];
            }
        }
    }

    $locales = [];
    if (msp2TableExists($conn, 'msp_locales')) {
        $stmtLocales = $conn->prepare(
            'SELECT l.cdo_local
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
             WHERE cl.id_contrato_arriendo = :id_contrato_arriendo
               AND cl.estado_relacion = 1
             ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
        );
        $stmtLocales->bindValue(':id_contrato_arriendo', $idContratoArriendo, PDO::PARAM_INT);
        $stmtLocales->execute();
        while (($row = $stmtLocales->fetch()) !== false) {
            $codigo = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
            if ($codigo !== '') {
                $locales[] = $codigo;
            }
        }
        $locales = array_values(array_unique($locales));
    }

    $bloqueos = [];
    $avisos = [];
    if (!in_array($estadoContrato, [1, 2], true)) {
        $bloqueos[] = 'El estado actual del contrato no permite término.';
    }
    if ($cargosPendientes > 0) {
        $avisos[] = 'Quedan cargos pendientes o reservados para el proceso de cierre.';
    }
    if ($garantiasReservadas > 0) {
        $avisos[] = 'Quedan saldos reservados de garantía para el proceso de cierre.';
    }
    if ($deudaVencidaCount > 0) {
        $avisos[] = 'Existen documentos vencidos con saldo pendiente.';
    }

    echo json_encode([
        'ok' => true,
        'can_terminate' => $bloqueos === [],
        'bloqueos' => $bloqueos,
        'avisos' => $avisos,
        'summary' => [
            'estado_contrato' => $estadoContrato,
            'cargos_pendientes' => $cargosPendientes,
            'garantias_reservadas' => $garantiasReservadas,
            'documentos_vencidos' => $deudaVencidaCount,
        ],
        'locales' => $locales,
        'documentos' => $documentos,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => msp2NormalizeText($exception->getMessage()) !== '' ? msp2NormalizeText($exception->getMessage()) : 'No se pudo validar término de contrato.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
