<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';

msp2RequireAccess();

header('Content-Type: application/json; charset=UTF-8');

$idContratoOrigenRaw = trim((string) ($_GET['id_contrato_origen'] ?? ''));
$idArrendatarioDestinoRaw = trim((string) ($_GET['id_arrendatario_destino'] ?? ''));
$fechaTraspasoRaw = trim((string) ($_GET['fecha_traspaso'] ?? ''));

$idContratoOrigen = filter_var($idContratoOrigenRaw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idContratoOrigen === false || $idContratoOrigen === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Contrato origen inválido.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$idArrendatarioDestino = filter_var($idArrendatarioDestinoRaw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idArrendatarioDestino === false || $idArrendatarioDestino === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Arrendatario destino inválido.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

if ($fechaTraspasoRaw === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Debes indicar fecha de traspaso.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$fechaTraspaso = DateTimeImmutable::createFromFormat('Y-m-d', $fechaTraspasoRaw);
if ($fechaTraspaso === false || $fechaTraspaso->format('Y-m-d') !== $fechaTraspasoRaw) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => 'Fecha de traspaso inválida.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

$fechaTraspasoIso = $fechaTraspaso->format('Y-m-d');
$fechaFinMesIso = $fechaTraspaso->modify('last day of this month')->format('Y-m-d');
$fechaInicioNuevoContratoIso = $fechaTraspaso->modify('first day of next month')->format('Y-m-d');

try {
    if (!msp2TableExists($conn, 'msp_contratos_arriendo') || !msp2TableExists($conn, 'msp_contrato_locales')) {
        throw new RuntimeException('Faltan tablas base de contratos para validar traspaso.');
    }

    $stmtContrato = $conn->prepare(
        'SELECT
            c.id_tienda,
            c.id_arrendatario,
            c.estado_contrato,
            c.fecha_inicio
         FROM dbo.msp_contratos_arriendo c
         WHERE c.id_contrato_arriendo = :id_contrato_origen'
    );
    $stmtContrato->bindValue(':id_contrato_origen', $idContratoOrigen, PDO::PARAM_INT);
    $stmtContrato->execute();
    $contrato = $stmtContrato->fetch();
    if ($contrato === false) {
        throw new RuntimeException('El contrato origen no existe.');
    }

    $idTienda = (int) ($contrato['id_tienda'] ?? 0);
    $idArrendatarioOrigen = (int) ($contrato['id_arrendatario'] ?? 0);
    $estadoContrato = (int) ($contrato['estado_contrato'] ?? 0);
    $fechaInicioContrato = (string) ($contrato['fecha_inicio'] ?? '');

    if (!msp2TableExists($conn, 'msp_arrendatarios')) {
        throw new RuntimeException('No existe tabla de arrendatarios.');
    }

    $stmtArr = $conn->prepare(
        'SELECT nombre_locatario, rut
         FROM dbo.msp_arrendatarios
         WHERE id_arrendatario = :id_arrendatario'
    );
    $stmtArr->bindValue(':id_arrendatario', $idArrendatarioDestino, PDO::PARAM_INT);
    $stmtArr->execute();
    $arrDestino = $stmtArr->fetch();
    if ($arrDestino === false) {
        throw new RuntimeException('El arrendatario destino no existe.');
    }

    $bloqueos = [];
    $avisos = [];

    if (!in_array($estadoContrato, [1, 2], true)) {
        $bloqueos[] = 'El contrato origen debe estar en estado borrador o vigente.';
    }
    if ($fechaInicioContrato !== '' && $fechaTraspasoIso < $fechaInicioContrato) {
        $bloqueos[] = 'La fecha de traspaso no puede ser anterior al inicio del contrato origen.';
    }
    if ($fechaTraspasoIso !== $fechaFinMesIso) {
        $bloqueos[] = 'El traspaso debe registrarse con fecha de fin de mes para mantener continuidad visual en control diario.';
    }
    if ($idArrendatarioDestino === $idArrendatarioOrigen) {
        $avisos[] = 'Seleccionaste el mismo arrendatario del contrato origen.';
    }

    $locales = [];
    if (msp2TableExists($conn, 'msp_locales')) {
        $stmtLocales = $conn->prepare(
            'SELECT l.cdo_local
             FROM dbo.msp_contrato_locales cl
             INNER JOIN dbo.msp_locales l ON l.id_local = cl.id_local
             WHERE cl.id_contrato_arriendo = :id_contrato_origen
               AND cl.estado_relacion = 1
             ORDER BY ' . msp2LocalCodeNaturalOrderSql('l.cdo_local')
        );
        $stmtLocales->bindValue(':id_contrato_origen', $idContratoOrigen, PDO::PARAM_INT);
        $stmtLocales->execute();
        while (($row = $stmtLocales->fetch()) !== false) {
            $codigo = msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? ''));
            if ($codigo !== '') {
                $locales[] = $codigo;
            }
        }
    }
    $locales = array_values(array_unique($locales));
    if ($locales === []) {
        $bloqueos[] = 'El contrato origen no tiene locales activos para traspasar.';
    }

    $garantias = [];
    $tieneReservas = false;
    $totalDisponible = 0.0;
    if (msp2TableExists($conn, 'msp_garantias') && msp2TableExists($conn, 'msp_vw_garantias_control_integral')) {
        $stmtGar = $conn->prepare(
            'SELECT
                g.id_garantia,
                l.cdo_local,
                CAST(ROUND(ISNULL(gr.monto_disponible, 0), 2) AS DECIMAL(18,2)) AS monto_disponible,
                CAST(ROUND(ISNULL(gr.monto_reservado, 0), 2) AS DECIMAL(18,2)) AS monto_reservado
             FROM dbo.msp_garantias g
             INNER JOIN dbo.msp_vw_garantias_control_integral gr ON gr.id_garantia = g.id_garantia
             LEFT JOIN dbo.msp_locales l ON l.id_local = g.id_local
             WHERE g.id_contrato_arriendo = :id_contrato_origen
               AND g.estado_garantia <> 6
             ORDER BY g.id_garantia ASC'
        );
        $stmtGar->bindValue(':id_contrato_origen', $idContratoOrigen, PDO::PARAM_INT);
        $stmtGar->execute();
        while (($row = $stmtGar->fetch()) !== false) {
            $saldoDisponible = (float) ($row['monto_disponible'] ?? 0);
            $saldoReservado = (float) ($row['monto_reservado'] ?? 0);
            if ($saldoReservado > 0) {
                $tieneReservas = true;
            }
            if ($saldoDisponible > 0) {
                $totalDisponible += $saldoDisponible;
            }
            $garantias[] = [
                'id_garantia' => (int) ($row['id_garantia'] ?? 0),
                'local' => msp2NormalizeLocalCode((string) ($row['cdo_local'] ?? '')),
                'saldo_disponible' => msp2FormatoDecimal($saldoDisponible, 2, '$ '),
                'saldo_reservado' => msp2FormatoDecimal($saldoReservado, 2, '$ '),
            ];
        }
    }

    if ($tieneReservas) {
        $bloqueos[] = 'Existen garantías con saldo reservado; primero debes liberar/aplicar esas reservas.';
    }

    if ($totalDisponible <= 0) {
        $avisos[] = 'No hay saldo disponible de garantía para transferir.';
    }

    echo json_encode([
        'ok' => true,
        'can_transfer' => $bloqueos === [],
        'bloqueos' => $bloqueos,
        'avisos' => $avisos,
        'summary' => [
            'id_tienda' => $idTienda,
            'estado_contrato' => $estadoContrato,
            'total_locales' => count($locales),
            'total_garantia_disponible' => msp2FormatoDecimal($totalDisponible, 2, '$ '),
            'fecha_inicio_nuevo_contrato' => $fechaInicioNuevoContratoIso,
        ],
        'arrendatario_destino' => [
            'id' => $idArrendatarioDestino,
            'nombre' => msp2NormalizeText((string) ($arrDestino['nombre_locatario'] ?? '')),
            'rut' => msp2RutFormatDisplay((string) ($arrDestino['rut'] ?? '')),
        ],
        'locales' => $locales,
        'garantias' => $garantias,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'message' => msp2NormalizeText($exception->getMessage()) !== ''
            ? msp2NormalizeText($exception->getMessage())
            : 'No se pudo validar el traspaso.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
