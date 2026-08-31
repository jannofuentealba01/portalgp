<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$tablaExiste = false;
$loadError = null;
$toastFlash = null;
if (is_array($flash)) {
    $toastFlash = $flash;
    $flash = null;
}

$estadoDocumento = [
    1 => ['label' => 'Borrador', 'badge' => 'text-bg-secondary'],
    2 => ['label' => 'Emitido', 'badge' => 'text-bg-primary'],
    3 => ['label' => 'Pagado parcial', 'badge' => 'text-bg-warning'],
    4 => ['label' => 'Pagado', 'badge' => 'text-bg-success'],
    5 => ['label' => 'Anulado', 'badge' => 'text-bg-danger'],
];

$idArrendatario = filter_input(INPUT_GET, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$filtroPeriodo = trim((string) ($_GET['filtroPeriodo'] ?? ''));

$arrendatariosDisponibles = [];
$arrendatarioIdsDisponibles = [];
$contratosLocalesPorArrendatario = [];
$arrendatarioSeleccionado = null;
$periodosDisponibles = [];
$documentosPeriodo = [];
$conceptosPagoPorDocumento = [];
$tablaPagoDetalleConceptoExiste = false;
$tablaSaldoFavorExiste = false;
$tablaBancosExiste = false;
$bancosDisponibles = [];
$saldoFavorPorTienda = [];

$filtroPeriodoFactura = null;
if ($filtroPeriodo !== '' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) === 1) {
    $periodoParsed = DateTimeImmutable::createFromFormat('!Y-m', $filtroPeriodo);
    if ($periodoParsed instanceof DateTimeImmutable && $periodoParsed->format('Y-m') === $filtroPeriodo) {
        $filtroPeriodoFactura = $periodoParsed->format('Y-m-01');
    }
}

function rpFmtFecha(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $parsed = DateTime::createFromFormat('Y-m-d', substr($value, 0, 10));
    if ($parsed === false) {
        return $value;
    }

    return $parsed->format('d-m-Y');
}

function rpFmtMonto(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function rpFmtRut(?string $rut): string
{
    $value = strtoupper(trim((string) $rut));
    if ($value === '') {
        return '';
    }

    $value = str_replace(['.', ' '], '', $value);
    if (!str_contains($value, '-')) {
        return $value;
    }

    [$num, $dv] = explode('-', $value, 2);
    $num = preg_replace('/\D+/', '', $num ?? '');
    $dv = strtoupper(trim((string) $dv));

    if ($num === '' || $dv === '') {
        return $value;
    }

    return number_format((int) $num, 0, '', '.') . '-' . $dv;
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_documentos_cobro_detalle',
        'msp_tiendas',
        'msp_pagos',
        'msp_arrendatarios',
        'msp_tipo_item_documento',
    ];

    $missingTables = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];
    $tablaPagoDetalleConceptoExiste = msp2TableExists($conn, 'msp_pagos_detalle_concepto') && msp2TableExists($conn, 'msp_tipo_item_documento');
    $tablaSaldoFavorExiste = msp2TableExists($conn, 'msp_saldos_favor_tienda');
    $tablaBancosExiste = msp2TableExists($conn, 'msp_bancos');

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas: `' . implode('`, `', $missingTables) . '`.';
    }
} catch (PDOException) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base de registrar pagos.';
}

if ($tablaExiste) {
    try {
        if ($filtroPeriodoFactura !== null) {
            $stmtArr = $conn->prepare(
                "SELECT DISTINCT
                    a.id_arrendatario,
                    a.rut,
                    COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
                 FROM dbo.msp_documentos_cobro dc
                 INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = dc.id_tienda
                 INNER JOIN dbo.msp_arrendatarios a
                    ON a.id_arrendatario = t.id_arrendatario
                 WHERE dc.periodo_facturacion = :periodo
                 ORDER BY nombre_arrendatario ASC"
            );
            $stmtArr->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtArr->execute();
            $arrendatariosDisponibles = $stmtArr->fetchAll();
        }

        foreach ($arrendatariosDisponibles as $arrDisponible) {
            $arrDisponibleId = (int) ($arrDisponible['id_arrendatario'] ?? 0);
            if ($arrDisponibleId > 0) {
                $arrendatarioIdsDisponibles[$arrDisponibleId] = true;
            }
        }

        $puedeMostrarContratosEnSelector =
            msp2TableExists($conn, 'msp_contratos_arriendo')
            && msp2TableExists($conn, 'msp_contrato_locales')
            && msp2TableExists($conn, 'msp_locales');

        if ($puedeMostrarContratosEnSelector && $filtroPeriodoFactura !== null) {
            $stmtContratosArr = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 SELECT
                    c.id_arrendatario,
                    c.id_contrato_arriendo,
                    l.cdo_local
                 FROM dbo.msp_contratos_arriendo c
                 INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_contrato_arriendo = c.id_contrato_arriendo
                   AND cl.estado_relacion IN (1,2)
                   AND cl.fecha_inicio <= EOMONTH(@periodo)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                 INNER JOIN dbo.msp_locales l
                    ON l.id_local = cl.id_local
                 WHERE c.estado_contrato IN (1,2,3)
                   AND c.fecha_inicio <= EOMONTH(@periodo)
                   AND (c.fecha_termino_efectiva IS NULL OR c.fecha_termino_efectiva >= @periodo)
                 ORDER BY
                    c.id_arrendatario ASC,
                    c.id_contrato_arriendo DESC,
                    " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
            );
            $stmtContratosArr->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtContratosArr->execute();
            $contratosArrRows = $stmtContratosArr->fetchAll() ?: [];

            foreach ($contratosArrRows as $rowContrato) {
                $idArrMap = (int) ($rowContrato['id_arrendatario'] ?? 0);
                $idContratoMap = (int) ($rowContrato['id_contrato_arriendo'] ?? 0);
                if ($idArrMap <= 0 || $idContratoMap <= 0) {
                    continue;
                }

                if (!isset($contratosLocalesPorArrendatario[$idArrMap])) {
                    $contratosLocalesPorArrendatario[$idArrMap] = [];
                }
                if (!isset($contratosLocalesPorArrendatario[$idArrMap][$idContratoMap])) {
                    $contratosLocalesPorArrendatario[$idArrMap][$idContratoMap] = [];
                }

                $codigoLocal = trim((string) ($rowContrato['cdo_local'] ?? ''));
                if ($codigoLocal === '') {
                    continue;
                }
                if (!in_array($codigoLocal, $contratosLocalesPorArrendatario[$idArrMap][$idContratoMap], true)) {
                    $contratosLocalesPorArrendatario[$idArrMap][$idContratoMap][] = $codigoLocal;
                }
            }
        }

        if ($tablaBancosExiste) {
            $stmtBancos = $conn->query(
                "SELECT id_banco, nombre_banco, codigo_banco
                 FROM dbo.msp_bancos
                 WHERE activo = 1
                 ORDER BY nombre_banco ASC"
            );
            $bancosDisponibles = $stmtBancos->fetchAll() ?: [];
        }

        if (
            $filtroPeriodoFactura !== null
            && $idArrendatario !== false
            && $idArrendatario !== null
            && isset($arrendatarioIdsDisponibles[(int) $idArrendatario])
        ) {
            $stmtSel = $conn->prepare(
                "SELECT
                    a.id_arrendatario,
                    a.rut,
                    COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
                 FROM dbo.msp_arrendatarios a
                 WHERE a.id_arrendatario = :id"
            );
            $stmtSel->bindValue(':id', $idArrendatario, PDO::PARAM_INT);
            $stmtSel->execute();
            $arrendatarioSeleccionado = $stmtSel->fetch() ?: null;
        }

        if ($arrendatarioSeleccionado !== null && $filtroPeriodoFactura !== null) {
            $stmtDocs = $conn->prepare(
                "SELECT
                    dc.id_documento_cobro,
                    dc.id_tienda,
                    COALESCE(NULLIF(dc.numero_documento, ''), CONCAT(N'DOC-', dc.id_documento_cobro)) AS numero_documento,
                    dc.fecha_emision,
                    dc.fecha_vencimiento,
                    dc.estado_documento,
                    dc.monto_total,
                    dc.saldo_pendiente,
                    dc.subtotal_arriendo,
                    dc.subtotal_servicios,
                    COALESCE(NULLIF(dc.nombre_tienda_snapshot, ''), NULLIF(t.nombre_comercial, ''), CONCAT(N'Tienda #', t.id_tienda)) AS nombre_tienda,
                    (
                        SELECT COUNT(*)
                        FROM dbo.msp_pagos p
                        WHERE p.id_documento_cobro = dc.id_documento_cobro
                    ) AS cantidad_pagos
                 FROM dbo.msp_documentos_cobro dc
                 INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = dc.id_tienda
                 WHERE t.id_arrendatario = :id_arrendatario
                   AND dc.periodo_facturacion = :periodo
                 ORDER BY nombre_tienda ASC, dc.id_documento_cobro ASC"
            );
            $stmtDocs->bindValue(':id_arrendatario', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
            $stmtDocs->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtDocs->execute();
            $documentosPeriodo = $stmtDocs->fetchAll();

            if ($documentosPeriodo !== [] && $tablaSaldoFavorExiste) {
                $tiendaIds = array_map(
                    static fn(array $doc): int => (int) ($doc['id_tienda'] ?? 0),
                    $documentosPeriodo
                );
                $tiendaIds = array_values(array_unique(array_filter($tiendaIds, static fn(int $id): bool => $id > 0)));

                if ($tiendaIds !== []) {
                    $placeholdersTiendas = [];
                    foreach ($tiendaIds as $index => $tiendaId) {
                        $placeholdersTiendas[] = ':tienda_' . $index;
                    }

                    $stmtSaldoFavor = $conn->prepare(
                        "SELECT
                            sf.id_tienda,
                            sf.saldo_disponible
                         FROM dbo.msp_saldos_favor_tienda sf
                         WHERE sf.id_tienda IN (" . implode(', ', $placeholdersTiendas) . ")"
                    );

                    foreach ($tiendaIds as $index => $tiendaId) {
                        $stmtSaldoFavor->bindValue(':tienda_' . $index, $tiendaId, PDO::PARAM_INT);
                    }

                    $stmtSaldoFavor->execute();
                    while (($saldoFavorRow = $stmtSaldoFavor->fetch()) !== false) {
                        $saldoFavorPorTienda[(int) ($saldoFavorRow['id_tienda'] ?? 0)] = (float) ($saldoFavorRow['saldo_disponible'] ?? 0);
                    }
                }
            }

            if ($documentosPeriodo !== [] && $tablaPagoDetalleConceptoExiste) {
                $documentoIds = [];
                $documentosPorId = [];
                foreach ($documentosPeriodo as $docData) {
                    $docDataId = (int) ($docData['id_documento_cobro'] ?? 0);
                    if ($docDataId <= 0) {
                        continue;
                    }
                    $documentoIds[] = $docDataId;
                    $documentosPorId[$docDataId] = $docData;
                }

                if ($documentoIds !== []) {
                    $placeholders = [];
                    foreach ($documentoIds as $index => $docId) {
                        $placeholders[] = ':doc_' . $index;
                    }

                    $prioridadConcepto = static fn(string $codigoItem): int => msp2PagoPrioridadImputacion($codigoItem);

                    $stmtTipoArriendo = $conn->query(
                        "SELECT TOP 1 tid.id_tipo_item_documento, tid.codigo_item, tid.nombre_item
                         FROM dbo.msp_tipo_item_documento tid
                         WHERE tid.codigo_item = N'ARRIENDO'"
                    );
                    $tipoArriendo = $stmtTipoArriendo ? ($stmtTipoArriendo->fetch() ?: null) : null;

                    $conceptosTotales = [];

                    $stmtConceptosBase = $conn->prepare(
                        "SELECT
                            dcd.id_documento_cobro,
                            tid.id_tipo_item_documento,
                            tid.codigo_item,
                            tid.nombre_item,
                            ROUND(SUM(dcd.subtotal), 2) AS monto_total
                         FROM dbo.msp_documentos_cobro_detalle dcd
                         INNER JOIN dbo.msp_tipo_item_documento tid
                            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                         WHERE dcd.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                         GROUP BY
                            dcd.id_documento_cobro,
                            tid.id_tipo_item_documento,
                            tid.codigo_item,
                            tid.nombre_item"
                    );
                    foreach ($documentoIds as $index => $docId) {
                        $stmtConceptosBase->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }
                    $stmtConceptosBase->execute();
                    while (($concepto = $stmtConceptosBase->fetch()) !== false) {
                        $docId = (int) ($concepto['id_documento_cobro'] ?? 0);
                        $tipoId = (int) ($concepto['id_tipo_item_documento'] ?? 0);
                        $codigoItem = (string) ($concepto['codigo_item'] ?? '');
                        if ($docId <= 0 || $tipoId <= 0 || $codigoItem === '') {
                            continue;
                        }

                        if (!isset($conceptosTotales[$docId])) {
                            $conceptosTotales[$docId] = [];
                        }

                        $conceptosTotales[$docId][$tipoId] = [
                            'id_tipo_item_documento' => $tipoId,
                            'codigo_item' => $codigoItem,
                            'nombre_item' => (string) ($concepto['nombre_item'] ?? $codigoItem),
                            'prioridad' => $prioridadConcepto($codigoItem),
                            'monto_total' => round((float) ($concepto['monto_total'] ?? 0), 2),
                            'monto_aplicado' => 0.0,
                            'saldo' => 0.0,
                        ];
                    }

                    foreach ($documentosPorId as $docId => $docData) {
                        $subtotalArriendo = round((float) ($docData['subtotal_arriendo'] ?? 0), 2);
                        $subtotalServicios = round((float) ($docData['subtotal_servicios'] ?? 0), 2);
                        $montoTotalDoc = round((float) ($docData['monto_total'] ?? 0), 2);
                        $ivaArriendo = round($montoTotalDoc - $subtotalArriendo - $subtotalServicios, 2);

                        if ($ivaArriendo < 0) {
                            $ivaArriendo = 0.0;
                        }

                        if ($subtotalArriendo <= 0 && $ivaArriendo <= 0) {
                            continue;
                        }

                        $tipoArriendoId = (int) ($tipoArriendo['id_tipo_item_documento'] ?? 0);
                        if ($tipoArriendoId <= 0) {
                            continue;
                        }

                        if (!isset($conceptosTotales[$docId])) {
                            $conceptosTotales[$docId] = [];
                        }

                        if (!isset($conceptosTotales[$docId][$tipoArriendoId])) {
                            $conceptosTotales[$docId][$tipoArriendoId] = [
                                'id_tipo_item_documento' => $tipoArriendoId,
                                'codigo_item' => (string) ($tipoArriendo['codigo_item'] ?? 'ARRIENDO'),
                                'nombre_item' => (string) ($tipoArriendo['nombre_item'] ?? 'Arriendo'),
                                'prioridad' => $prioridadConcepto('ARRIENDO'),
                                'monto_total' => round($subtotalArriendo + $ivaArriendo, 2),
                                'monto_aplicado' => 0.0,
                                'saldo' => 0.0,
                            ];
                        } else {
                            $conceptosTotales[$docId][$tipoArriendoId]['monto_total'] = round(
                                (float) ($conceptosTotales[$docId][$tipoArriendoId]['monto_total'] ?? 0) + $ivaArriendo,
                                2
                            );
                        }
                    }

                    $stmtConceptosAplicados = $conn->prepare(
                        "SELECT
                            pdc.id_documento_cobro,
                            pdc.id_tipo_item_documento,
                            ROUND(SUM(CASE WHEN p.estado_pago = 1 THEN pdc.monto_aplicado ELSE 0 END), 2) AS monto_aplicado
                         FROM dbo.msp_pagos_detalle_concepto pdc
                         INNER JOIN dbo.msp_pagos p
                            ON p.id_pago = pdc.id_pago
                         WHERE pdc.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                         GROUP BY
                            pdc.id_documento_cobro,
                            pdc.id_tipo_item_documento"
                    );
                    foreach ($documentoIds as $index => $docId) {
                        $stmtConceptosAplicados->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }
                    $stmtConceptosAplicados->execute();
                    while (($conceptoAplicado = $stmtConceptosAplicados->fetch()) !== false) {
                        $docId = (int) ($conceptoAplicado['id_documento_cobro'] ?? 0);
                        $tipoId = (int) ($conceptoAplicado['id_tipo_item_documento'] ?? 0);
                        if ($docId <= 0 || $tipoId <= 0 || !isset($conceptosTotales[$docId][$tipoId])) {
                            continue;
                        }
                        $conceptosTotales[$docId][$tipoId]['monto_aplicado'] = round((float) ($conceptoAplicado['monto_aplicado'] ?? 0), 2);
                    }

                    foreach ($conceptosTotales as $docId => $conceptosDoc) {
                        $rows = [];
                        foreach ($conceptosDoc as $row) {
                            $montoTotalConcepto = round((float) ($row['monto_total'] ?? 0), 2);
                            $montoAplicadoConcepto = round((float) ($row['monto_aplicado'] ?? 0), 2);
                            $saldoConcepto = round(max(0, $montoTotalConcepto - $montoAplicadoConcepto), 2);

                            if ($saldoConcepto <= 0) {
                                continue;
                            }

                            $row['saldo'] = $saldoConcepto;
                            $rows[] = $row;
                        }

                        usort(
                            $rows,
                            static function (array $a, array $b): int {
                                $pa = (int) ($a['prioridad'] ?? 999);
                                $pb = (int) ($b['prioridad'] ?? 999);
                                if ($pa === $pb) {
                                    return ((int) ($a['id_tipo_item_documento'] ?? 0)) <=> ((int) ($b['id_tipo_item_documento'] ?? 0));
                                }
                                return $pa <=> $pb;
                            }
                        );

                        $conceptosPagoPorDocumento[$docId] = $rows;
                    }
                }
            }
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar Registrar pago. Detalle: ' . $exception->getMessage();
    }
}

$queryBase = $_GET;
$correoDemoConfigRaw = trim((string) (mspMailConfig()['demo']['to'] ?? ''));
$correoDemoConfig = filter_var($correoDemoConfigRaw, FILTER_VALIDATE_EMAIL) !== false ? $correoDemoConfigRaw : '';
$modoCorreoDemoActivo = $correoDemoConfig !== '';
$envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Registrar Pago</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <style>
        .msp-mail-sending-overlay {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(1.5px);
        }
        .msp-mail-sending-box {
            min-width: 250px;
            max-width: 92vw;
            border-radius: 0.85rem;
            border: 1px solid #dbe4f0;
            background: #fff;
            box-shadow: 0 16px 42px rgba(15, 23, 42, 0.18);
            padding: 1rem 1.15rem;
            text-align: center;
        }
        .msp-mail-sending-plane {
            display: inline-block;
            font-size: 1.65rem;
            color: #1d4ed8;
            animation: msp-mail-plane-fly 1.2s ease-in-out infinite;
            transform-origin: center;
        }
        .msp-mail-sending-text {
            margin-top: 0.4rem;
            color: #1f2937;
            font-weight: 600;
            font-size: 0.95rem;
        }
        @keyframes msp-mail-plane-fly {
            0% { transform: translateX(-10px) translateY(2px) rotate(-16deg); opacity: .72; }
            45% { transform: translateX(10px) translateY(-3px) rotate(12deg); opacity: 1; }
            100% { transform: translateX(-10px) translateY(2px) rotate(-16deg); opacity: .72; }
        }
        body.msp-mail-sending-open {
            overflow: hidden;
        }
    </style>
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<?php msp2RenderCsrfAutoFieldScript(); ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
            </a>
            <div class="d-flex gap-2">
                <a href="<?php echo msp2Escape(msp2Url('documentos_cobro/index.php')); ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-receipt me-1" aria-hidden="true"></i>Ir a Documentos de cobro
                </a>
            </div>
        </div>

        <p class="section-kicker text-center">MSP / Cobranza</p>
        <h1 class="form-title text-center mb-2">Registrar Pago</h1>
        <p class="text-muted text-center mb-4">Selecciona arrendatario y período para registrar pagos por documento.</p>

        <?php include dirname(__DIR__) . '/templates/components/flash_toast.php'; ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php endif; ?>

        <?php if ($tablaExiste && $loadError === null): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-12 col-lg-5">
                            <form method="get" class="row g-2 align-items-end" id="form_periodo">
                                <div class="col-12">
                                    <label for="filtroPeriodo" class="form-label">Período</label>
                                    <input
                                        type="month"
                                        id="filtroPeriodo"
                                        name="filtroPeriodo"
                                        class="form-control"
                                        value="<?php echo msp2Escape($filtroPeriodo); ?>"
                                        required>
                                </div>
                            </form>
                        </div>

                        <div class="col-12 col-lg-7">
                            <?php if ($filtroPeriodoFactura === null): ?>
                                <label class="form-label">Arrendatario</label>
                                <div class="form-control bg-info-subtle border-info text-info d-flex align-items-center mb-0">
                                    Primero selecciona un período.
                                </div>
                            <?php else: ?>
                            <form method="get" class="row g-2 align-items-end" id="form_arrendatario">
                                <input type="hidden" name="filtroPeriodo" value="<?php echo msp2Escape($filtroPeriodo); ?>">
                                <div class="col-12">
                                    <?php
                                    $localesOrdenClavePorArrendatario = [];
                                    foreach ($contratosLocalesPorArrendatario as $arrIdLocales => $contratosLocalesArr) {
                                        $localesUnicos = [];
                                        foreach ($contratosLocalesArr as $localesContrato) {
                                            foreach ($localesContrato as $codigoLocalContrato) {
                                                $codigoLocalNorm = trim((string) $codigoLocalContrato);
                                                if ($codigoLocalNorm === '' || in_array($codigoLocalNorm, $localesUnicos, true)) {
                                                    continue;
                                                }
                                                $localesUnicos[] = $codigoLocalNorm;
                                            }
                                        }

                                        if ($localesUnicos === []) {
                                            continue;
                                        }

                                        usort($localesUnicos, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));
                                        $localesOrdenClavePorArrendatario[(int) $arrIdLocales] = $localesUnicos;
                                    }

                                    $arrendatariosOrdenados = $arrendatariosDisponibles;
                                    usort(
                                        $arrendatariosOrdenados,
                                        static function (array $a, array $b) use ($localesOrdenClavePorArrendatario): int {
                                            $arrIdA = (int) ($a['id_arrendatario'] ?? 0);
                                            $arrIdB = (int) ($b['id_arrendatario'] ?? 0);
                                            $localesA = $localesOrdenClavePorArrendatario[$arrIdA] ?? [];
                                            $localesB = $localesOrdenClavePorArrendatario[$arrIdB] ?? [];

                                            $aTieneLocales = $localesA !== [];
                                            $bTieneLocales = $localesB !== [];
                                            if ($aTieneLocales !== $bTieneLocales) {
                                                return $aTieneLocales ? -1 : 1;
                                            }

                                            if ($aTieneLocales && $bTieneLocales) {
                                                $minLen = min(count($localesA), count($localesB));
                                                for ($i = 0; $i < $minLen; $i++) {
                                                    $cmpLocal = msp2CompareLocalCode((string) $localesA[$i], (string) $localesB[$i]);
                                                    if ($cmpLocal !== 0) {
                                                        return $cmpLocal;
                                                    }
                                                }

                                                $cmpCantidad = count($localesA) <=> count($localesB);
                                                if ($cmpCantidad !== 0) {
                                                    return $cmpCantidad;
                                                }
                                            }

                                            $nombreA = mb_strtolower(trim((string) ($a['nombre_arrendatario'] ?? '')), 'UTF-8');
                                            $nombreB = mb_strtolower(trim((string) ($b['nombre_arrendatario'] ?? '')), 'UTF-8');
                                            $cmpNombre = strcmp($nombreA, $nombreB);
                                            if ($cmpNombre !== 0) {
                                                return $cmpNombre;
                                            }

                                            return $arrIdA <=> $arrIdB;
                                        }
                                    );

                                    $arrendatarioOptions = [];
                                    foreach ($arrendatariosOrdenados as $arr) {
                                        $arrId = (int) ($arr['id_arrendatario'] ?? 0);
                                        if ($arrId <= 0) {
                                            continue;
                                        }
                                        $arrRut = rpFmtRut((string) ($arr['rut'] ?? ''));
                                        $arrNombre = trim((string) ($arr['nombre_arrendatario'] ?? ''));
                                        $arrLabel = '(' . $arrRut . ') ' . $arrNombre;
                                        $arrLabelHtml = msp2Escape($arrLabel);
                                        $arrSearch = $arrRut . ' ' . $arrNombre;
                                        $contratosArrendatario = $contratosLocalesPorArrendatario[$arrId] ?? [];
                                        if ($contratosArrendatario !== []) {
                                            $contratosOrdenadosPorLocal = [];
                                            foreach ($contratosArrendatario as $localesContratoArrRaw) {
                                                if (!is_array($localesContratoArrRaw)) {
                                                    continue;
                                                }

                                                $localesContratoArr = [];
                                                foreach ($localesContratoArrRaw as $codigoLocalRaw) {
                                                    $codigoLocal = trim((string) $codigoLocalRaw);
                                                    if ($codigoLocal === '' || in_array($codigoLocal, $localesContratoArr, true)) {
                                                        continue;
                                                    }
                                                    $localesContratoArr[] = $codigoLocal;
                                                }

                                                if ($localesContratoArr === []) {
                                                    continue;
                                                }

                                                usort($localesContratoArr, static fn(string $a, string $b): int => msp2CompareLocalCode($a, $b));
                                                $contratosOrdenadosPorLocal[] = $localesContratoArr;
                                            }

                                            usort(
                                                $contratosOrdenadosPorLocal,
                                                static function (array $a, array $b): int {
                                                    $minLen = min(count($a), count($b));
                                                    for ($i = 0; $i < $minLen; $i++) {
                                                        $cmp = msp2CompareLocalCode((string) $a[$i], (string) $b[$i]);
                                                        if ($cmp !== 0) {
                                                            return $cmp;
                                                        }
                                                    }
                                                    return count($a) <=> count($b);
                                                }
                                            );

                                            $partesContratos = [];
                                            $partesContratosHtml = [];
                                            foreach ($contratosOrdenadosPorLocal as $localesContratoArr) {
                                                $localesLabel = implode(', ', $localesContratoArr);
                                                $partesContratos[] = '(' . $localesLabel . ')';
                                                $partesContratosHtml[] = '(<strong>' . msp2Escape($localesLabel) . '</strong>)';
                                                $arrSearch .= ' ' . $localesLabel;
                                            }

                                            if ($partesContratos !== []) {
                                                $arrLabel .= ' ' . implode(' ', $partesContratos);
                                                $arrLabelHtml .= ' ' . implode(' ', $partesContratosHtml);
                                            }
                                        }
                                        $arrendatarioOptions[] = [
                                            'value' => (string) $arrId,
                                            'label' => $arrLabel,
                                            'label_html' => $arrLabelHtml,
                                            'search' => mb_strtolower($arrSearch, 'UTF-8'),
                                        ];
                                    }
                                    msp2RenderSearchableSelectField([
                                        'wrapper_class' => 'col-12',
                                        'label' => 'Arrendatario',
                                        'input_name' => 'id_arrendatario',
                                        'input_id' => 'id_arrendatario',
                                        'picker_id' => 'arrendatario_picker',
                                        'button_id' => 'arrendatario_dropdown_btn',
                                        'filter_id' => 'arrendatario_dropdown_filter',
                                        'list_id' => 'arrendatario_dropdown_list',
                                        'error_id' => 'arrendatario_error',
                                        'error_message' => 'Debes seleccionar un arrendatario.',
                                        'button_placeholder' => 'Selecciona un arrendatario...',
                                            'filter_placeholder' => 'Buscar por nombre, RUT o contrato',
                                            'empty_message' => 'No hay arrendatarios con documentos en este período.',
                                            'required' => true,
                                            'value' => $arrendatarioSeleccionado !== null ? (string) (int) $arrendatarioSeleccionado['id_arrendatario'] : '',
                                            'options' => $arrendatarioOptions,
                                        ]);
                                        ?>
                                    </div>
                                </form>
                                <?php if (empty($arrendatarioOptions)): ?>
                                    <div class="alert alert-warning mt-2 mb-0">No hay arrendatarios con documentos para este período.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($arrendatarioSeleccionado !== null && $filtroPeriodo !== '' && $documentosPeriodo === []): ?>
                <div class="alert alert-warning">No hay documentos para el arrendatario/período seleccionado.</div>
            <?php endif; ?>

            <?php if ($documentosPeriodo !== []): ?>
                <div class="card">
                    <div class="card-body">
                        <h2 class="h6 mb-3">Documentos del período</h2>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle text-center mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 80px;">Doc</th>
                                        <th class="text-start">Tienda</th>
                                        <th style="width: 120px;">Número</th>
                                        <th style="width: 120px;">Emisión</th>
                                        <th style="width: 120px;">Venc.</th>
                                        <th style="width: 130px;" class="text-end">Monto</th>
                                        <th style="width: 130px;" class="text-end">Saldo</th>
                                        <th style="width: 120px;">Estado</th>
                                        <th style="width: 110px;">Pagos</th>
                                        <th style="width: 150px;">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($documentosPeriodo as $doc): ?>
                                        <?php
                                            $docId = (int) ($doc['id_documento_cobro'] ?? 0);
                                            $tiendaId = (int) ($doc['id_tienda'] ?? 0);
                                            $saldo = (float) ($doc['saldo_pendiente'] ?? 0);
                                            $saldoFavorTienda = (float) ($saldoFavorPorTienda[$tiendaId] ?? 0);
                                            $estadoId = (int) ($doc['estado_documento'] ?? 0);
                                            $estado = $estadoDocumento[$estadoId] ?? ['label' => 'Desconocido', 'badge' => 'text-bg-light text-dark'];
                                            $puedePagar = $saldo > 0 && $estadoId !== 5;
                                            $conceptosDoc = $conceptosPagoPorDocumento[$docId] ?? [];
                                            $conceptosDocJson = '[]';
                                            if ($conceptosDoc !== []) {
                                                $encoded = json_encode($conceptosDoc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                                                if (is_string($encoded) && $encoded !== '') {
                                                    $conceptosDocJson = $encoded;
                                                }
                                            }
                                            $documentoLabel = '#'
                                                . $docId
                                                . ' | '
                                                . (string) ($doc['numero_documento'] ?? '')
                                                . ' | '
                                                . (string) ($doc['nombre_tienda'] ?? '');
                                        ?>
                                        <tr>
                                            <td>#<?php echo $docId; ?></td>
                                            <td class="text-start"><?php echo msp2Escape((string) ($doc['nombre_tienda'] ?? '-')); ?></td>
                                            <td><?php echo msp2Escape((string) ($doc['numero_documento'] ?? '')); ?></td>
                                            <td><?php echo msp2Escape(rpFmtFecha((string) ($doc['fecha_emision'] ?? ''))); ?></td>
                                            <td><?php echo msp2Escape(rpFmtFecha((string) ($doc['fecha_vencimiento'] ?? ''))); ?></td>
                                            <td class="text-end"><?php echo msp2Escape(rpFmtMonto($doc['monto_total'] ?? null)); ?></td>
                                            <td class="text-end fw-semibold"><?php echo msp2Escape(rpFmtMonto($saldo)); ?></td>
                                            <td>
                                                <span class="badge <?php echo msp2Escape((string) $estado['badge']); ?>"><?php echo msp2Escape((string) $estado['label']); ?></span>
                                            </td>
                                            <td><?php echo number_format((int) ($doc['cantidad_pagos'] ?? 0), 0, ',', '.'); ?></td>
                                            <td>
                                                <?php if ($puedePagar): ?>
                                                    <button
                                                        type="button"
                                                        class="btn btn-success btn-sm js-registrar-pago-v2"
                                                        data-id-documento="<?php echo $docId; ?>"
                                                        data-documento-label="<?php echo msp2Escape($documentoLabel); ?>"
                                                        data-saldo="<?php echo msp2Escape((string) number_format($saldo, 2, '.', '')); ?>"
                                                        data-saldo-favor="<?php echo msp2Escape((string) number_format($saldoFavorTienda, 2, '.', '')); ?>"
                                                        data-tienda-label="<?php echo msp2Escape((string) ($doc['nombre_tienda'] ?? ('Tienda #' . $tiendaId))); ?>"
                                                        data-conceptos="<?php echo msp2Escape($conceptosDocJson); ?>">
                                                        <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Registrar pago
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted small">Sin saldo</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<!-- ============================================================
     MODAL — Registrar pago (flujo por concepto)
     ============================================================ -->
<div class="modal fade" id="modalRegistrarPagoV2" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/guardar.php')); ?>" id="form_registrar_pago_v2"
              style="border-radius:var(--gp-radius-lg,12px);overflow:hidden;">
            <!-- Campos ocultos -->
            <input type="hidden" name="id_documento_cobro"  id="v2_id_documento_cobro">
            <input type="hidden" name="detalle_conceptos_json" id="v2_detalle_conceptos_json">
            <input type="hidden" name="monto_pagado"        id="v2_monto_pagado">
            <input type="hidden" name="usar_saldo_favor"    id="v2_usar_saldo_favor_hidden" value="0">
            <input type="hidden" name="monto_saldo_favor"   id="v2_monto_saldo_favor_hidden" value="">
            <input type="hidden" name="enviar_comprobante" id="v2_enviar_comprobante" value="1">
            <input type="hidden" name="demo_email_confirmado" id="v2_demo_email_confirmado" value="">
            <input type="hidden" name="demo_email_override" id="v2_demo_email_override" value="">
            <input type="hidden" name="volver_a"            value="cobranza_registrar_pago">
            <input type="hidden" name="volver_query"        value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">

            <!-- Header -->
            <div class="modal-header" style="background:var(--color-surface,#fff);border-bottom:1px solid var(--color-border,#e5e7eb);">
                <div>
                    <h2 class="modal-title fs-5 mb-0 d-flex align-items-center gap-2">Registrar pago</h2>
                    <div class="small text-muted" id="v2_doc_label" style="margin-top:2px;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <!-- Body -->
            <div class="modal-body" style="background:var(--color-bg,#f9fafb);">

                <!-- Monto pagado (destacado) + Fecha / Medio / Referencia -->
                <div class="row g-2 mb-3 align-items-end">
                    <div class="col-sm-4">
                        <label for="v2_monto_pagado_view" class="form-label mb-1 small fw-bold text-success">
                            <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Monto pagado
                        </label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold" style="background:#f0fdf4;border-color:#16a34a;color:#15803d;">$</span>
                            <input type="text" inputmode="decimal" class="form-control fw-bold" id="v2_monto_pagado_view"
                                   placeholder="0,00" required autocomplete="off"
                                   style="font-size:1.25rem;border-color:#16a34a;box-shadow:0 0 0 1px #bbf7d0;color:#15803d;">
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <label for="v2_fecha_pago" class="form-label mb-1 small fw-semibold">Fecha pago</label>
                        <input type="date" class="form-control form-control-sm" id="v2_fecha_pago" name="fecha_pago"
                               value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-sm-3">
                        <label for="v2_medio_pago" class="form-label mb-1 small fw-semibold">Medio de pago</label>
                        <select id="v2_medio_pago" name="medio_pago" class="form-select form-select-sm">
                            <option value="">Selecciona…</option>
                            <?php
                            foreach (['Transferencia', 'Efectivo', 'Cheque'] as $mp):
                            ?>
                                <option value="<?php echo msp2Escape($mp); ?>"><?php echo msp2Escape($mp); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-2" id="v2_referencia_wrap">
                        <label for="v2_referencia" class="form-label mb-1 small fw-semibold">Referencia</label>
                        <input type="text" class="form-control form-control-sm" id="v2_referencia" name="referencia_pago"
                               maxlength="100" placeholder="N° operación">
                    </div>
                </div>
                <div class="row g-2 mb-3 align-items-end d-none" id="v2_cheque_wrap">
                    <div class="col-sm-4">
                        <label for="v2_numero_cheque" class="form-label mb-1 small fw-semibold">N° Cheque</label>
                        <input type="text" class="form-control form-control-sm" id="v2_numero_cheque" name="numero_cheque"
                               maxlength="100" placeholder="N° cheque">
                    </div>
                    <?php if ($tablaBancosExiste): ?>
                        <div class="col-sm-4">
                            <label class="form-label mb-1 small fw-semibold">Banco</label>
                            <input type="hidden" id="v2_id_banco_cheque" name="id_banco_cheque">
                            <input type="hidden" id="v2_banco_cheque" name="banco_cheque">
                            <div class="dropdown w-100" id="v2_banco_picker">
                                <button
                                    class="btn btn-outline-secondary btn-sm dropdown-toggle w-100 text-start"
                                    type="button"
                                    id="v2_banco_dropdown_btn"
                                    data-bs-toggle="dropdown"
                                    data-bs-auto-close="outside"
                                    aria-expanded="false">
                                    Selecciona banco...
                                </button>
                                <div class="dropdown-menu p-2 w-100">
                                    <input
                                        type="text"
                                        id="v2_banco_dropdown_filter"
                                        class="form-control form-control-sm mb-2"
                                        placeholder="Buscar banco...">
                                    <div class="list-group list-group-flush overflow-auto" id="v2_banco_dropdown_list" style="max-height: 220px;">
                                        <?php if ($bancosDisponibles === []): ?>
                                            <div class="small text-muted px-2 py-1">No hay bancos activos.</div>
                                        <?php else: ?>
                                            <?php foreach ($bancosDisponibles as $banco): ?>
                                                <?php
                                                $idBanco = (int) ($banco['id_banco'] ?? 0);
                                                if ($idBanco <= 0) {
                                                    continue;
                                                }
                                                $nombreBanco = trim((string) ($banco['nombre_banco'] ?? ''));
                                                $codigoBanco = trim((string) ($banco['codigo_banco'] ?? ''));
                                                $labelBanco = $codigoBanco !== '' ? ($nombreBanco . ' (' . $codigoBanco . ')') : $nombreBanco;
                                                $searchBanco = mb_strtolower($labelBanco, 'UTF-8');
                                                ?>
                                                <button
                                                    type="button"
                                                    class="list-group-item list-group-item-action js-v2-banco-option"
                                                    data-value="<?php echo $idBanco; ?>"
                                                    data-label="<?php echo msp2Escape($labelBanco); ?>"
                                                    data-banco-nombre="<?php echo msp2Escape($nombreBanco); ?>"
                                                    data-search="<?php echo msp2Escape($searchBanco); ?>">
                                                    <?php echo msp2Escape($labelBanco); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="invalid-feedback d-block d-none" id="v2_banco_picker_error">Debes seleccionar un banco.</div>
                        </div>
                    <?php else: ?>
                        <div class="col-sm-4">
                            <label for="v2_banco_cheque" class="form-label mb-1 small fw-semibold">Banco</label>
                            <input type="text" class="form-control form-control-sm" id="v2_banco_cheque" name="banco_cheque"
                                   maxlength="100" placeholder="Banco emisor">
                        </div>
                    <?php endif; ?>
                </div>
                <div class="small text-muted mb-3">
                    <i class="bi bi-arrow-down-up me-1" aria-hidden="true"></i>El monto se distribuye en orden de prioridad: <strong>Arriendo → Luz → Gas → Agua → Otros</strong>. El excedente sobre el saldo queda como saldo a favor.
                </div>

                <!-- Saldo a favor -->
                <div class="mt-1 d-none" id="v2_saldo_favor_wrap">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="v2_usar_saldo_favor">
                        <label class="form-check-label" for="v2_usar_saldo_favor">
                            Usar saldo a favor disponible (<span id="v2_saldo_favor_label">-</span>)
                        </label>
                    </div>
                    <div class="row g-2 mt-2 d-none" id="v2_saldo_favor_row">
                        <div class="col-sm-3">
                            <label for="v2_saldo_favor_monto" class="form-label mb-1 small fw-semibold">Monto a aplicar</label>
                            <input type="number" class="form-control form-control-sm" id="v2_saldo_favor_monto"
                                   min="0" step="0.01" placeholder="0.00">
                            <div class="form-text">Máximo: <span id="v2_saldo_favor_max_label">-</span>. Se suma al pago y se informa en el vale.</div>
                        </div>
                    </div>
                </div>

                <!-- Tabla de conceptos -->
                <div style="border-radius:10px;overflow:hidden;border:1px solid var(--color-border,#e5e7eb);background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <table class="table align-middle mb-0" id="v2_tabla_conceptos" style="font-size:.92rem;">
                        <thead>
                            <tr style="background:var(--color-surface,#f3f4f6);">
                                <th class="text-start ps-3" style="font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">Concepto</th>
                                <th class="text-end" style="width:115px;font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">Saldo</th>
                                <th style="width:148px;font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);">A pagar</th>
                                <th style="width:110px;font-weight:600;color:#374151;border-bottom:1px solid var(--color-border,#e5e7eb);" class="text-center pe-2">Pendiente</th>
                            </tr>
                        </thead>
                        <tbody id="v2_conceptos_body">
                            <!-- Generado por JS -->
                        </tbody>
                        <tfoot>
                            <tr style="background:var(--color-surface,#f3f4f6);border-top:2px solid var(--color-border,#e5e7eb);">
                                <th class="text-start ps-3" style="font-size:.85rem;color:#6b7280;">
                                    <button type="button" id="v2_pagar_todo_doc"
                                            class="btn btn-sm btn-outline-success py-0 px-2"
                                            title="Llenar todos los conceptos con su saldo completo">
                                        <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Pagar todo
                                    </button>
                                    <button type="button" id="v2_limpiar_todo_doc"
                                            class="btn btn-sm btn-outline-secondary py-0 px-2 ms-2"
                                            title="Vaciar todos los montos">
                                        <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Limpiar
                                    </button>
                                </th>
                                <th class="text-end pe-2" style="font-size:.85rem;color:#6b7280;">Total aplicado</th>
                                <th class="text-end fw-bold fs-5" id="v2_total_label" colspan="2"
                                    style="color:var(--color-primary,#16a34a);">$ 0</th>
                            </tr>
                            <tr style="background:var(--color-surface,#f3f4f6);">
                                <th colspan="4" class="text-end pe-3 pt-1 pb-2">
                                    <button type="button" id="v2_set_monto_desde_total"
                                            class="btn btn-sm btn-outline-success py-0 px-2"
                                            title="Copiar el total aplicado al campo Monto pagado">
                                        <i class="bi bi-arrow-up-right-circle me-1" aria-hidden="true"></i>Poner total aplicado en Monto pagado
                                    </button>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="small mt-2" id="v2_validacion_msg"></div>

                <!-- Observaciones colapsable -->
                <div class="mt-3">
                    <button type="button" class="btn btn-link btn-sm p-0 text-muted" id="v2_toggle_obs">
                        <i class="bi bi-chevron-right me-1" id="v2_obs_chevron"></i>Observaciones (opcional)
                    </button>
                    <div class="collapse mt-2" id="v2_obs_collapse">
                        <textarea class="form-control form-control-sm" id="v2_observaciones" name="observaciones"
                                  rows="2" maxlength="500" placeholder="Notas adicionales…"></textarea>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="modal-footer" style="background:var(--color-surface,#fff);border-top:1px solid var(--color-border,#e5e7eb);">
                <div class="me-auto small text-muted" id="v2_footer_info"></div>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success" id="v2_submit_btn">Guardar pago</button>
            </div>
        </form>
    </div>
</div>

<div
    class="modal fade"
    id="modalConfirmarComprobantePago"
    tabindex="-1"
    aria-hidden="true"
    data-demo-enabled="<?php echo $modoCorreoDemoActivo ? '1' : '0'; ?>"
    data-demo-default="<?php echo msp2Escape($correoDemoConfig); ?>">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Enviar comprobante</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">¿Quieres enviar el comprobante de pago por correo al guardar?</p>

                <div id="confirmar_comprobante_demo_wrap" class="<?php echo $modoCorreoDemoActivo ? '' : 'd-none'; ?>">
                    <label for="confirmar_comprobante_demo_email" class="form-label">Correo destino demo</label>
                    <input
                        type="email"
                        class="form-control"
                        id="confirmar_comprobante_demo_email"
                        value="<?php echo msp2Escape($correoDemoConfig); ?>"
                        placeholder="correo@demo.cl">
                    <div id="confirmar_comprobante_demo_error" class="small text-danger mt-2 d-none">Ingresa un correo válido para enviar el comprobante.</div>
                    <?php if ($modoCorreoDemoActivo): ?>
                        <div class="small text-muted mt-2">Modo demo activo. Correo por defecto: <strong><?php echo msp2Escape($correoDemoConfig); ?></strong></div>
                    <?php endif; ?>
                </div>

                <div id="confirmar_comprobante_real_info" class="small text-muted <?php echo $modoCorreoDemoActivo ? 'd-none' : ''; ?>">
                    <?php if ($envioArrendatariosHabilitado): ?>
                        Se intentará enviar al correo principal del arrendatario (si existe un correo válido).
                    <?php else: ?>
                        El envío real a arrendatarios está bloqueado desde Configuración Correos.
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="confirmar_comprobante_omitir_btn">Guardar sin enviar</button>
                <button
                    type="button"
                    class="btn btn-success"
                    id="confirmar_comprobante_enviar_btn"
                    <?php echo (!$modoCorreoDemoActivo && !$envioArrendatariosHabilitado) ? 'disabled' : ''; ?>>
                    Enviar y guardar
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const hiddenInput = document.getElementById('id_arrendatario');
    const hiddenPeriodoInput = document.getElementById('filtroPeriodo');
    const formArrendatario = document.getElementById('form_arrendatario');
    const formPeriodo = document.getElementById('form_periodo');
    const modalConfirmarComprobante = document.getElementById('modalConfirmarComprobantePago');
    const confirmarComprobanteEnviarBtn = document.getElementById('confirmar_comprobante_enviar_btn');
    const confirmarComprobanteOmitirBtn = document.getElementById('confirmar_comprobante_omitir_btn');
    const confirmarComprobanteDemoWrap = document.getElementById('confirmar_comprobante_demo_wrap');
    const confirmarComprobanteRealInfo = document.getElementById('confirmar_comprobante_real_info');
    const confirmarComprobanteDemoEmail = document.getElementById('confirmar_comprobante_demo_email');
    const confirmarComprobanteDemoError = document.getElementById('confirmar_comprobante_demo_error');
    const confirmarComprobanteDemoEnabled = !!(
        modalConfirmarComprobante
        && modalConfirmarComprobante.dataset.demoEnabled === '1'
        && window.bootstrap
    );
    let comprobanteFormPendiente = null;
    const SENDING_OVERLAY_ID = 'msp-mail-sending-overlay';

    const showMailSendingOverlay = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (form.dataset.mailSubmitting === '1') {
            return;
        }
        form.dataset.mailSubmitting = '1';
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((node) => {
            if ('disabled' in node) {
                node.disabled = true;
            }
        });

        let overlay = document.getElementById(SENDING_OVERLAY_ID);
        if (!(overlay instanceof HTMLDivElement)) {
            overlay = document.createElement('div');
            overlay.id = SENDING_OVERLAY_ID;
            overlay.className = 'msp-mail-sending-overlay';
            overlay.innerHTML = ''
                + '<div class="msp-mail-sending-box" role="status" aria-live="polite" aria-atomic="true">'
                + '<i class="bi bi-send-fill msp-mail-sending-plane" aria-hidden="true"></i>'
                + '<div class="msp-mail-sending-text">Enviando correo...</div>'
                + '</div>';
            document.body.appendChild(overlay);
        }
        overlay.classList.remove('d-none');
        document.body.classList.add('msp-mail-sending-open');
    };

    const limpiarFlagsComprobante = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const enviarInput = form.querySelector('input[name="enviar_comprobante"]');
        const demoConfirmadoInput = form.querySelector('input[name="demo_email_confirmado"]');
        const demoOverrideInput = form.querySelector('input[name="demo_email_override"]');
        if (enviarInput instanceof HTMLInputElement) {
            enviarInput.value = '1';
        }
        if (demoConfirmadoInput instanceof HTMLInputElement) {
            demoConfirmadoInput.value = '';
        }
        if (demoOverrideInput instanceof HTMLInputElement) {
            demoOverrideInput.value = '';
        }
        form.dataset.confirmacionComprobante = '0';
    };

    const decidirEnvioComprobante = (form, enviar, correoDemo = '') => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        const enviarInput = form.querySelector('input[name="enviar_comprobante"]');
        const demoConfirmadoInput = form.querySelector('input[name="demo_email_confirmado"]');
        const demoOverrideInput = form.querySelector('input[name="demo_email_override"]');
        if (enviarInput instanceof HTMLInputElement) {
            enviarInput.value = enviar ? '1' : '0';
        }
        if (demoConfirmadoInput instanceof HTMLInputElement) {
            demoConfirmadoInput.value = enviar && confirmarComprobanteDemoEnabled ? '1' : '';
        }
        if (demoOverrideInput instanceof HTMLInputElement) {
            demoOverrideInput.value = enviar && confirmarComprobanteDemoEnabled ? correoDemo : '';
        }
        form.dataset.confirmacionComprobante = '1';
    };

    const solicitarConfirmacionComprobante = (form) => {
        if (!(form instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
            return false;
        }
        comprobanteFormPendiente = form;
        if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
            confirmarComprobanteDemoError.classList.add('d-none');
        }
        if (confirmarComprobanteDemoWrap) {
            confirmarComprobanteDemoWrap.classList.toggle('d-none', !confirmarComprobanteDemoEnabled);
        }
        if (confirmarComprobanteRealInfo) {
            confirmarComprobanteRealInfo.classList.toggle('d-none', confirmarComprobanteDemoEnabled);
        }
        if (confirmarComprobanteDemoEnabled && confirmarComprobanteDemoEmail instanceof HTMLInputElement) {
            const overrideActual = form.querySelector('input[name="demo_email_override"]');
            const correoDefault = (modalConfirmarComprobante.dataset.demoDefault || '').trim();
            const correoInicial = overrideActual instanceof HTMLInputElement && overrideActual.value.trim() !== ''
                ? overrideActual.value.trim()
                : correoDefault;
            confirmarComprobanteDemoEmail.value = correoInicial;
            confirmarComprobanteDemoEmail.focus();
            confirmarComprobanteDemoEmail.select();
        }

        try {
            window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).show();
            return true;
        } catch (err) {
            console.error('No fue posible abrir el modal de confirmación de comprobante.', err);
            const msgEl = document.getElementById('v2_validacion_msg');
            if (msgEl) {
                msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>No fue posible abrir la confirmación de comprobante. Se continuará guardando el pago.</span>';
            }
            return false;
        }
    };

    if (hiddenInput instanceof HTMLInputElement && formArrendatario instanceof HTMLFormElement) {
        hiddenInput.addEventListener('change', () => {
            if (hiddenInput.value.trim() !== '') {
                formArrendatario.requestSubmit();
            }
        });
    }

    if (hiddenPeriodoInput instanceof HTMLInputElement && formPeriodo instanceof HTMLFormElement) {
        hiddenPeriodoInput.addEventListener('change', () => {
            if (hiddenPeriodoInput.value.trim() !== '') {
                formPeriodo.requestSubmit();
            }
        });
    }

    const formRegistrarPagoV2Global = document.getElementById('form_registrar_pago_v2');
    if (formRegistrarPagoV2Global instanceof HTMLFormElement) {
        limpiarFlagsComprobante(formRegistrarPagoV2Global);
    }

    /* ─── Modal v2 ─────────────────────────────────────────────────────────── */
    (() => {
        const FMT = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const fmtMoney = (v) => '$ ' + FMT.format(Number(v) || 0);
        const parseDot = (v) => {
            const n = parseFloat(String(v || '').replace(/,/g, '.'));
            return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
        };

        // Formato CLP con decimales opcionales: 1.234.567,89
        const formatCLP = (n) => {
            if (!Number.isFinite(n) || n < 0) return '';
            return n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        const parseCLP = (str) => {
            // Elimina puntos de miles; cambia coma por punto
            const s = String(str || '').trim().replace(/\./g, '').replace(',', '.');
            const n = parseFloat(s);
            return Number.isFinite(n) && n >= 0 ? Math.round(n * 100) / 100 : 0;
        };
        const roundPayableUp = (n) => {
            const num = Number(n);
            if (!Number.isFinite(num) || num <= 0) return 0;
            return Math.ceil(num);
        };

        let v2Conceptos = [];
        let v2SaldoDoc  = 0;
        let v2SaldoFavorDoc = 0;
        let v2DocumentoLabel = '-';
        let v2TiendaLabel = '-';

        const body       = document.getElementById('v2_conceptos_body');
        const totalLabel = document.getElementById('v2_total_label');
        const msgEl      = document.getElementById('v2_validacion_msg');
        const footerInfo = document.getElementById('v2_footer_info');
        const submitBtn  = document.getElementById('v2_submit_btn');
        const hiddenMonto   = document.getElementById('v2_monto_pagado');
        const montoView     = document.getElementById('v2_monto_pagado_view');
        const hiddenDetalle = document.getElementById('v2_detalle_conceptos_json');
        const btnSetMontoDesdeTotal = document.getElementById('v2_set_monto_desde_total');
        const referenciaWrap = document.getElementById('v2_referencia_wrap');
        const chequeWrap = document.getElementById('v2_cheque_wrap');
        const numeroChequeInp = document.getElementById('v2_numero_cheque');
        const bancoChequeInp = document.getElementById('v2_banco_cheque');
        const idBancoChequeInp = document.getElementById('v2_id_banco_cheque');
        const bancoDropdownBtn = document.getElementById('v2_banco_dropdown_btn');
        const bancoDropdownFilter = document.getElementById('v2_banco_dropdown_filter');
        const bancoDropdownList = document.getElementById('v2_banco_dropdown_list');
        const bancoPicker = document.getElementById('v2_banco_picker');
        const bancoPickerError = document.getElementById('v2_banco_picker_error');
        const saldoFavorWrap = document.getElementById('v2_saldo_favor_wrap');
        const saldoFavorCheck = document.getElementById('v2_usar_saldo_favor');
        const saldoFavorRow = document.getElementById('v2_saldo_favor_row');
        const saldoFavorInput = document.getElementById('v2_saldo_favor_monto');
        const saldoFavorLabel = document.getElementById('v2_saldo_favor_label');
        const saldoFavorMaxLabel = document.getElementById('v2_saldo_favor_max_label');
        const hiddenUsarSaldoFavor = document.getElementById('v2_usar_saldo_favor_hidden');
        const hiddenMontoSaldoFavor = document.getElementById('v2_monto_saldo_favor_hidden');
        const getMontoPagado = () => parseCLP(montoView ? montoView.value : '');
        const bancoPickerReady = idBancoChequeInp instanceof HTMLInputElement
            && bancoChequeInp instanceof HTMLInputElement
            && bancoDropdownBtn instanceof HTMLButtonElement
            && bancoDropdownFilter instanceof HTMLInputElement
            && bancoDropdownList instanceof HTMLDivElement
            && bancoPicker instanceof HTMLDivElement;

        const limpiarBancoCheque = () => {
            if (idBancoChequeInp instanceof HTMLInputElement) {
                idBancoChequeInp.value = '';
            }
            if (bancoChequeInp instanceof HTMLInputElement) {
                bancoChequeInp.value = '';
            }
            if (bancoDropdownBtn instanceof HTMLButtonElement) {
                bancoDropdownBtn.textContent = 'Selecciona banco...';
                bancoDropdownBtn.title = '';
                bancoDropdownBtn.classList.remove('is-invalid');
            }
            if (bancoPickerError instanceof HTMLDivElement) {
                bancoPickerError.classList.add('d-none');
            }
        };
        const syncChequeFields = () => {
            const medioSel = document.getElementById('v2_medio_pago');
            const esCheque = medioSel instanceof HTMLSelectElement && medioSel.value === 'Cheque';
            if (referenciaWrap) {
                referenciaWrap.classList.toggle('d-none', esCheque);
            }
            if (chequeWrap) {
                chequeWrap.classList.toggle('d-none', !esCheque);
            }
            if (numeroChequeInp instanceof HTMLInputElement) {
                numeroChequeInp.required = esCheque;
            }
            if (bancoPickerReady) {
                idBancoChequeInp.required = esCheque;
                bancoChequeInp.required = esCheque;
            } else if (bancoChequeInp instanceof HTMLInputElement) {
                bancoChequeInp.required = esCheque;
            }
            if (medioSel instanceof HTMLSelectElement && !esCheque) {
                if (numeroChequeInp instanceof HTMLInputElement) {
                    numeroChequeInp.value = '';
                }
                if (bancoPickerReady) {
                    limpiarBancoCheque();
                } else if (bancoChequeInp instanceof HTMLInputElement) {
                    bancoChequeInp.value = '';
                }
            }
        };

        if (bancoPickerReady) {
            const bancoDropdown = window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(bancoDropdownBtn) : null;
            const bancoOptions = Array.from(bancoDropdownList.querySelectorAll('.js-v2-banco-option'));

            const filterBancoOptions = () => {
                const term = bancoDropdownFilter.value.trim().toLowerCase();
                bancoOptions.forEach((option) => {
                    const searchable = option.dataset.search || '';
                    const visible = term === '' || searchable.includes(term);
                    option.classList.toggle('d-none', !visible);
                });
            };

            bancoOptions.forEach((option) => {
                option.addEventListener('click', () => {
                    idBancoChequeInp.value = option.dataset.value || '';
                    bancoChequeInp.value = option.dataset.bancoNombre || '';
                    bancoDropdownBtn.textContent = option.dataset.label || 'Selecciona banco...';
                    bancoDropdownBtn.title = option.dataset.label || '';
                    bancoDropdownBtn.classList.remove('is-invalid');
                    if (bancoPickerError instanceof HTMLDivElement) {
                        bancoPickerError.classList.add('d-none');
                    }
                    bancoOptions.forEach((item) => item.classList.remove('active'));
                    option.classList.add('active');
                    if (bancoDropdown) {
                        bancoDropdown.hide();
                    }
                });
            });

            bancoDropdownFilter.addEventListener('input', filterBancoOptions);
            bancoPicker.addEventListener('shown.bs.dropdown', () => {
                bancoDropdownFilter.focus();
            });
        }

        const recalcular = () => {
            if (!body) return;
            let total = 0;
            body.querySelectorAll('.v2-input-monto').forEach(inp => {
                total = Math.round((total + parseCLP(inp.value)) * 100) / 100;
            });

            if (totalLabel) totalLabel.textContent = fmtMoney(total);

            // Validación
            const rows    = Array.from(body.querySelectorAll('tr[data-v2-id]'));
            let excedeSaldo = false;
            let prelacionInvalida = false;
            let hayPendientePrevio = false;
            rows.forEach(row => {
                const saldo = parseDot(row.dataset.v2Saldo);
                const inp   = row.querySelector('.v2-input-monto');
                const monto = parseCLP(inp ? inp.value : '');
                if (hayPendientePrevio && monto > 0.01) {
                    prelacionInvalida = true;
                }
                const chk   = row.querySelector('.v2-check-pagar');
                if (chk) {
                    // Se marca automáticamente si el monto digitado es igual al saldo total
                    chk.checked = (monto > 0 && Math.abs(monto - saldo) < 0.01);
                }
                const badge = row.querySelector('.v2-saldo-badge');
                if (badge) {
                    const restante = Math.round((saldo - monto) * 100) / 100;
                    badge.textContent = restante > 0.005 ? fmtMoney(restante) + ' restante' : '✓ pagado';
                    badge.className   = 'badge ' + (restante > 0.005 ? 'text-bg-secondary' : 'text-bg-success') + ' v2-saldo-badge';
                }
                if (monto > saldo + 0.01) excedeSaldo = true;
                const pendiente = Math.round((saldo - monto) * 100) / 100;
                if (pendiente > 0.01) {
                    hayPendientePrevio = true;
                }
            });

            const montoPagado = getMontoPagado();
            const objetivo = Math.min(montoPagado, v2SaldoDoc > 0 ? v2SaldoDoc : montoPagado);
            const saldoRestanteDespuesPago = Math.max(0, Math.round((v2SaldoDoc - objetivo) * 100) / 100);
            const saldoFavorMax = Math.max(0, Math.round(Math.min(v2SaldoFavorDoc, saldoRestanteDespuesPago) * 100) / 100);
            const usarSaldoFavor = saldoFavorCheck instanceof HTMLInputElement && saldoFavorCheck.checked;
            const saldoFavorValorRaw = saldoFavorInput instanceof HTMLInputElement ? saldoFavorInput.value : '';
            let saldoFavorAplicado = usarSaldoFavor ? parseDot(saldoFavorValorRaw) : 0;
            if (!Number.isFinite(saldoFavorAplicado) || saldoFavorAplicado < 0) {
                saldoFavorAplicado = 0;
            }
            if (saldoFavorAplicado > saldoFavorMax) {
                saldoFavorAplicado = saldoFavorMax;
            }
            const saldoFavorInvalido = usarSaldoFavor && saldoFavorAplicado <= 0;
            const cuadra = Math.abs(total - objetivo) <= 0.01;
            const ok = montoPagado > 0 && total > 0 && !excedeSaldo && cuadra && !saldoFavorInvalido && !prelacionInvalida;
            if (msgEl) {
                if (excedeSaldo) {
                    msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Algún concepto supera su saldo disponible.</span>';
                } else if (saldoFavorInvalido) {
                    msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Indica un monto válido de saldo a favor.</span>';
                } else if (prelacionInvalida) {
                    msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Debes respetar el orden de aplicación: Arriendo → Servicios → Cobros extra.</span>';
                } else if (montoPagado <= 0) {
                    msgEl.innerHTML = '<span class="text-muted">Ingresa el monto pagado para continuar.</span>';
                } else if (total <= 0) {
                    msgEl.innerHTML = '<span class="text-muted">Ingresa al menos un monto para continuar.</span>';
                } else if (!cuadra) {
                    msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Debes distribuir '
                        + fmtMoney(objetivo) + ' entre los conceptos.</span>';
                } else {
                    const excedente = Math.round((montoPagado - objetivo) * 100) / 100;
                    msgEl.innerHTML = excedente > 0.01
                        ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Aplicado: ' + fmtMoney(total) + '. Excedente: ' + fmtMoney(excedente) + '.</span>'
                        : '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Total a guardar: ' + fmtMoney(total) + '</span>';
                }
            }
            if (submitBtn) submitBtn.disabled = !ok;
            if (hiddenMonto)  hiddenMonto.value  = ok ? montoPagado.toFixed(2) : '';
            if (hiddenUsarSaldoFavor) hiddenUsarSaldoFavor.value = usarSaldoFavor && saldoFavorAplicado > 0 ? '1' : '0';
            if (hiddenMontoSaldoFavor) hiddenMontoSaldoFavor.value = usarSaldoFavor && saldoFavorAplicado > 0 ? saldoFavorAplicado.toFixed(2) : '';
            if (saldoFavorInput instanceof HTMLInputElement) {
                saldoFavorInput.max = saldoFavorMax.toFixed(2);
                if (usarSaldoFavor && saldoFavorInput.value !== '' && parseDot(saldoFavorInput.value) > saldoFavorMax) {
                    saldoFavorInput.value = saldoFavorMax > 0 ? saldoFavorMax.toFixed(2) : '';
                }
            }
            if (saldoFavorMaxLabel) saldoFavorMaxLabel.textContent = fmtMoney(saldoFavorMax);
            if (footerInfo) {
                const aplicado = Math.min(total, v2SaldoDoc);
                const saldoRestante = Math.max(0, Math.round((v2SaldoDoc - aplicado - saldoFavorAplicado) * 100) / 100);
                const excedente = Math.max(0, Math.round((montoPagado - v2SaldoDoc) * 100) / 100);
                if (montoPagado <= 0 && total <= 0) {
                    footerInfo.textContent = '';
                } else if (excedente > 0.005) {
                    footerInfo.textContent = 'Saldo pendiente: ' + fmtMoney(saldoRestante) + ' | Excedente: ' + fmtMoney(excedente);
                } else if (saldoRestante > 0.005) {
                    footerInfo.textContent = 'Saldo pendiente tras pago: ' + fmtMoney(saldoRestante)
                        + (saldoFavorAplicado > 0 ? ' | Saldo a favor aplicado: ' + fmtMoney(saldoFavorAplicado) : '');
                } else {
                    footerInfo.textContent = saldoFavorAplicado > 0 ? 'Saldo a favor aplicado: ' + fmtMoney(saldoFavorAplicado) : '';
                }
            }
        };

        const pagarTodo = (row) => {
            const saldo = parseDot(row.dataset.v2Saldo);
            const inp   = row.querySelector('.v2-input-monto');
            if (inp) { inp.value = saldo > 0 ? formatCLP(saldo) : ''; }
            recalcular();
        };

        const limpiarTodo = (row) => {
            const inp = row.querySelector('.v2-input-monto');
            if (inp) { inp.value = ''; }
            recalcular();
        };

        const conceptoIcon = (codigo) => {
            switch (codigo) {
                case 'ARRIENDO':      return 'bi-house-door-fill';
                case 'SERVICIO_LUZ':  return 'bi-lightning-charge-fill';
                case 'SERVICIO_GAS':  return 'bi-fire';
                case 'SERVICIO_AGUA': return 'bi-droplet-fill';
                case 'MULTA':         return 'bi-exclamation-triangle-fill';
                case 'DANO':          return 'bi-tools';
                case 'AJUSTE':        return 'bi-sliders';
                default:              return 'bi-tag-fill';
            }
        };
        const conceptoColor = (codigo) => {
            switch (codigo) {
                case 'ARRIENDO':      return '#4f46e5';
                case 'SERVICIO_LUZ':  return '#d97706';
                case 'SERVICIO_GAS':  return '#dc2626';
                case 'SERVICIO_AGUA': return '#2563eb';
                case 'MULTA':         return '#ea580c';
                case 'DANO':          return '#7c3aed';
                default:              return '#6b7280';
            }
        };

        // Distribuye el monto ingresado en cascada según el orden de prioridad de los conceptos
        const autoDistribute = () => {
            if (!body) return;
            const montoPagado = getMontoPagado();
            if (montoPagado <= 0) {
                body.querySelectorAll('.v2-input-monto').forEach(inp => { inp.value = ''; });
                recalcular();
                return;
            }
            const objetivo = Math.min(montoPagado, v2SaldoDoc > 0 ? v2SaldoDoc : montoPagado);
            let restante = Math.round(objetivo * 100) / 100;
            body.querySelectorAll('tr[data-v2-id]').forEach(row => {
                const saldo = parseDot(row.dataset.v2Saldo);
                const inp   = row.querySelector('.v2-input-monto');
                if (!inp) return;
                const aAplicar = Math.round(Math.min(restante, saldo) * 100) / 100;
                inp.value  = aAplicar > 0 ? formatCLP(aAplicar) : '';
                restante   = Math.round((restante - aAplicar) * 100) / 100;
            });
            recalcular();
        };

        // ── Formateo automático CLP en campos de monto ────────────────────────
        const formatMontoInput = (e) => {
            const inp = e.target;
            const oldVal = inp.value;
            const sel    = inp.selectionStart ?? oldVal.length;

            // Chars significativos (dígitos + coma) antes del cursor
            const sigAntes = oldVal.slice(0, sel).replace(/\./g, '').length;

            // Limpiar: solo dígitos y UNA coma; máx 2 decimales
            let raw = oldVal.replace(/[^\d,]/g, '');
            const ci = raw.indexOf(',');
            if (ci !== -1) {
                const dec = raw.slice(ci + 1).replace(/,/g, '').slice(0, 2);
                raw = raw.slice(0, ci + 1) + dec;
            }

            const hasComma   = raw.includes(',');
            const partes     = raw.split(',');
            const intRaw     = partes[0] ?? '';
            const decRaw     = partes[1] ?? '';
            const intNum     = intRaw === '' ? '' : Number(intRaw);
            const intFmt     = intRaw === '' ? '' : (Number.isFinite(intNum) ? intNum.toLocaleString('es-CL') : intRaw);
            const newVal     = intFmt + (hasComma ? ',' + decRaw : '');

            if (newVal !== oldVal) {
                inp.value = newVal;
                // Restaurar posición del cursor contando chars significativos
                let count = 0, newPos = newVal.length;
                for (let i = 0; i < newVal.length; i++) {
                    if (newVal[i] !== '.') count++;
                    if (count === sigAntes) { newPos = i + 1; break; }
                }
                inp.setSelectionRange(newPos, newPos);
            }
            recalcular();
        };

        if (montoView instanceof HTMLInputElement) {
            montoView.addEventListener('input', (e) => {
                formatMontoInput(e);
                autoDistribute();
            });
        }

        const renderConceptosV2 = (conceptos) => {
            if (!body) return;
            v2Conceptos = conceptos;
            if (!Array.isArray(conceptos) || conceptos.length === 0) {
                body.innerHTML = '<tr><td colspan="4" class="text-muted small text-center py-3">Este documento no tiene conceptos con saldo disponible.</td></tr>';
                recalcular();
                return;
            }

            body.innerHTML = conceptos.map(c => {
                const id     = Number(c.id_tipo_item_documento || 0);
                const codigo = String(c.codigo_item || '');
                const nombre = String(c.nombre_item || 'Concepto');
                const saldo  = parseDot(c.saldo || 0);
                const icon   = conceptoIcon(codigo);
                const color  = conceptoColor(codigo);
                return `<tr data-v2-id="${id}" data-v2-saldo="${saldo.toFixed(2)}">
                    <td class="ps-3 py-2">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi ${icon}" style="color:${color};font-size:1.05em;flex-shrink:0;" aria-hidden="true"></i>
                            <span class="fw-semibold" style="font-size:.92rem;">${nombre.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</span>
                        </div>
                    </td>
                    <td class="text-end py-2 pe-3" style="font-size:.85rem;color:#6b7280;white-space:nowrap;">${fmtMoney(saldo)}</td>
                    <td class="py-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="text" inputmode="decimal" class="form-control v2-input-monto"
                                   placeholder="0,00" autocomplete="off">
                        </div>
                    </td>
                    <td class="text-center py-2 pe-2">
                        <span class="badge text-bg-secondary v2-saldo-badge" style="font-size:.72em;">${fmtMoney(saldo)} pend.</span>
                    </td>
                </tr>`;
            }).join('');

            // Eventos dentro de la tabla
            body.querySelectorAll('.v2-input-monto').forEach(inp => {
                inp.addEventListener('input', formatMontoInput);
            });

            recalcular();
        };

        // Botón "pagar todo / limpiar todo" en tfoot (estático en HTML)
        const btnPagarTodoDoc = document.getElementById('v2_pagar_todo_doc');
        if (btnPagarTodoDoc) {
            btnPagarTodoDoc.addEventListener('click', () => {
                document.querySelectorAll('#v2_conceptos_body tr[data-v2-id]').forEach(pagarTodo);
            });
        }
        const btnLimpiarTodoDoc = document.getElementById('v2_limpiar_todo_doc');
        if (btnLimpiarTodoDoc) {
            btnLimpiarTodoDoc.addEventListener('click', () => {
                document.querySelectorAll('#v2_conceptos_body tr[data-v2-id]').forEach(limpiarTodo);
            });
        }
        if (btnSetMontoDesdeTotal) {
            btnSetMontoDesdeTotal.addEventListener('click', () => {
                if (!(montoView instanceof HTMLInputElement) || !body) {
                    return;
                }
                let totalAplicado = 0;
                body.querySelectorAll('.v2-input-monto').forEach((inp) => {
                    totalAplicado = Math.round((totalAplicado + parseCLP(inp.value)) * 100) / 100;
                });
                montoView.value = totalAplicado > 0 ? formatCLP(totalAplicado) : '';
                recalcular();
                montoView.focus();
                montoView.select();
            });
        }


        // Toggle observaciones
        const toggleObs = document.getElementById('v2_toggle_obs');
        const obsChevron = document.getElementById('v2_obs_chevron');
        if (toggleObs) {
            toggleObs.addEventListener('click', () => {
                const col = document.getElementById('v2_obs_collapse');
                if (!col) return;
                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(col);
                bsCollapse.toggle();
                obsChevron && obsChevron.classList.toggle('bi-chevron-right');
                obsChevron && obsChevron.classList.toggle('bi-chevron-down');
            });
        }

        // Abrir v2
        document.querySelectorAll('.js-registrar-pago-v2').forEach(btn => {
            btn.addEventListener('click', () => {
                const idDoc   = btn.dataset.idDocumento || '';
                const label   = btn.dataset.documentoLabel || '-';
                const saldo   = parseDot(btn.dataset.saldo || '0');
                const saldoFavor = parseDot(btn.dataset.saldoFavor || '0');
                const tiendaLabel = btn.dataset.tiendaLabel || '-';
                const rawJson = btn.dataset.conceptos || '[]';

                const idInput   = document.getElementById('v2_id_documento_cobro');
                const docLabel  = document.getElementById('v2_doc_label');
                const fechaInp  = document.getElementById('v2_fecha_pago');
                const medioInp  = document.getElementById('v2_medio_pago');
                const refInp    = document.getElementById('v2_referencia');
                const obsInp    = document.getElementById('v2_observaciones');

                if (idInput)  idInput.value  = idDoc;
                if (docLabel) docLabel.textContent = label;
                if (fechaInp) fechaInp.value = '<?php echo date('Y-m-d'); ?>';
                if (medioInp) medioInp.value = '';
                if (refInp)   refInp.value   = '';
                if (numeroChequeInp instanceof HTMLInputElement) numeroChequeInp.value = '';
                if (bancoPickerReady) {
                    limpiarBancoCheque();
                } else if (bancoChequeInp instanceof HTMLInputElement) {
                    bancoChequeInp.value = '';
                }
                if (obsInp)   obsInp.value   = '';
                syncChequeFields();
                if (montoView) montoView.value = saldo > 0 ? formatCLP(roundPayableUp(saldo)) : '';
                v2SaldoDoc = saldo;
                v2SaldoFavorDoc = saldoFavor;
                v2DocumentoLabel = label;
                v2TiendaLabel = tiendaLabel;
                if (saldoFavorWrap) {
                    const mostrar = saldoFavor > 0.005;
                    saldoFavorWrap.classList.toggle('d-none', !mostrar);
                }
                if (saldoFavorLabel) saldoFavorLabel.textContent = fmtMoney(saldoFavor);
                if (saldoFavorCheck) saldoFavorCheck.checked = false;
                if (saldoFavorRow) saldoFavorRow.classList.add('d-none');
                if (saldoFavorInput) saldoFavorInput.value = '';
                limpiarFlagsComprobante(formV2);

                let conceptos = [];
                try { const p = JSON.parse(rawJson); if (Array.isArray(p)) conceptos = p; } catch (_) {}
                renderConceptosV2(conceptos);
                autoDistribute();

                const modalEl = document.getElementById('modalRegistrarPagoV2');
                const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);

                // Foco automático en el campo de monto al terminar la animación de apertura
                modalEl.addEventListener('shown.bs.modal', () => {
                    if (montoView instanceof HTMLInputElement) {
                        montoView.focus();
                        montoView.select();
                    }
                }, { once: true });

                bsModal.show();
            });
        });

        // Submit v2
        const formV2 = document.getElementById('form_registrar_pago_v2');
        if (formV2) {
            formV2.addEventListener('submit', (e) => {
                const medioInp = document.getElementById('v2_medio_pago');
                const refInp = document.getElementById('v2_referencia');
                const esCheque = medioInp instanceof HTMLSelectElement && medioInp.value === 'Cheque';
                const nroCheque = numeroChequeInp instanceof HTMLInputElement ? numeroChequeInp.value.trim() : '';
                const bancoCheque = bancoChequeInp instanceof HTMLInputElement ? bancoChequeInp.value.trim() : '';
                const idBancoCheque = idBancoChequeInp instanceof HTMLInputElement ? idBancoChequeInp.value.trim() : '';
                if (esCheque) {
                    const bancoInvalido = bancoPickerReady ? idBancoCheque === '' : bancoCheque === '';
                    if (nroCheque === '' || bancoInvalido) {
                        e.preventDefault();
                        if (msgEl) {
                            msgEl.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Para pagos con cheque debes ingresar N° Cheque y Banco.</span>';
                        }
                        if (nroCheque === '' && numeroChequeInp instanceof HTMLInputElement) {
                            numeroChequeInp.focus();
                        } else if (bancoPickerReady && bancoDropdownBtn instanceof HTMLButtonElement) {
                            bancoDropdownBtn.classList.add('is-invalid');
                            if (bancoPickerError instanceof HTMLDivElement) {
                                bancoPickerError.classList.remove('d-none');
                            }
                            bancoDropdownBtn.focus();
                        } else if (bancoChequeInp instanceof HTMLInputElement) {
                            bancoChequeInp.focus();
                        }
                        return;
                    }
                    if (refInp instanceof HTMLInputElement) {
                        refInp.value = nroCheque;
                    }
                }
                const rows = Array.from(
                    document.querySelectorAll('#v2_conceptos_body tr[data-v2-id]')
                );
                const detalle = [];
                let total = 0;
                let invalido = false;
                let prelacionInvalida = false;
                let hayPendientePrevio = false;
                const montoPagado = getMontoPagado();
                const montoObjetivo = Math.min(montoPagado, v2SaldoDoc > 0 ? v2SaldoDoc : montoPagado);
                const saldoRestanteDespuesPago = Math.max(0, Math.round((v2SaldoDoc - montoObjetivo) * 100) / 100);
                const saldoFavorMax = Math.max(0, Math.round(Math.min(v2SaldoFavorDoc, saldoRestanteDespuesPago) * 100) / 100);
                const usarSaldoFavor = saldoFavorCheck instanceof HTMLInputElement && saldoFavorCheck.checked;
                const saldoFavorValor = saldoFavorInput instanceof HTMLInputElement ? parseDot(saldoFavorInput.value) : 0;
                const saldoFavorInvalido = usarSaldoFavor && (saldoFavorValor <= 0 || saldoFavorValor > saldoFavorMax + 0.01);

                rows.forEach(row => {
                    const id    = Number(row.dataset.v2Id);
                    const saldo = parseDot(row.dataset.v2Saldo);
                    const inp   = row.querySelector('.v2-input-monto');
                    const monto = parseCLP(inp ? inp.value : 0);
                    if (hayPendientePrevio && monto > 0.01) {
                        prelacionInvalida = true;
                    }
                    const pendiente = Math.round((saldo - monto) * 100) / 100;
                    if (pendiente > 0.01) {
                        hayPendientePrevio = true;
                    }
                    if (monto <= 0) return;
                    if (monto > saldo + 0.01) { invalido = true; return; }
                    detalle.push({ id_tipo_item_documento: id, monto: Math.round(monto * 100) / 100 });
                    total = Math.round((total + monto) * 100) / 100;
                });

                const cuadra = Math.abs(total - montoObjetivo) <= 0.01;
                if (invalido || montoPagado <= 0 || total <= 0 || detalle.length === 0 || !cuadra || saldoFavorInvalido || prelacionInvalida) {
                    e.preventDefault();
                    recalcular();
                    return;
                }

                if (hiddenDetalle) hiddenDetalle.value = JSON.stringify(detalle);
                if (hiddenMonto)   hiddenMonto.value   = montoPagado.toFixed(2);

                if (formV2.dataset.confirmacionComprobante !== '1') {
                    const mostrado = solicitarConfirmacionComprobante(formV2);
                    if (mostrado) {
                        e.preventDefault();
                        return;
                    }
                }
                const enviarComprobanteInput = formV2.querySelector('input[name="enviar_comprobante"]');
                const enviaraCorreo = !(enviarComprobanteInput instanceof HTMLInputElement) || enviarComprobanteInput.value !== '0';
                if (enviaraCorreo) {
                    showMailSendingOverlay(formV2);
                }
                formV2.dataset.confirmacionComprobante = '0';
            });
        }

        const medioPagoSelect = document.getElementById('v2_medio_pago');
        if (medioPagoSelect instanceof HTMLSelectElement) {
            medioPagoSelect.addEventListener('change', syncChequeFields);
        }
        syncChequeFields();

        if (saldoFavorCheck) {
            saldoFavorCheck.addEventListener('change', () => {
                if (saldoFavorRow) {
                    saldoFavorRow.classList.toggle('d-none', !saldoFavorCheck.checked);
                }
                if (saldoFavorCheck.checked && saldoFavorInput instanceof HTMLInputElement) {
                    const montoPagado = getMontoPagado();
                    const objetivo = Math.min(montoPagado, v2SaldoDoc > 0 ? v2SaldoDoc : montoPagado);
                    const saldoRestante = Math.max(0, Math.round((v2SaldoDoc - objetivo) * 100) / 100);
                    const saldoFavorMax = Math.max(0, Math.round(Math.min(v2SaldoFavorDoc, saldoRestante) * 100) / 100);
                    saldoFavorInput.value = saldoFavorMax > 0 ? saldoFavorMax.toFixed(2) : '';
                }
                recalcular();
            });
        }
        if (saldoFavorInput) {
            saldoFavorInput.addEventListener('input', recalcular);
        }
    })();

    if (confirmarComprobanteOmitirBtn instanceof HTMLButtonElement) {
        confirmarComprobanteOmitirBtn.addEventListener('click', () => {
            if (!(comprobanteFormPendiente instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
                return;
            }
            decidirEnvioComprobante(comprobanteFormPendiente, false);
            window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).hide();
            comprobanteFormPendiente.requestSubmit();
        });
    }

    if (confirmarComprobanteEnviarBtn instanceof HTMLButtonElement) {
        confirmarComprobanteEnviarBtn.addEventListener('click', () => {
            if (!(comprobanteFormPendiente instanceof HTMLFormElement) || !modalConfirmarComprobante || !window.bootstrap) {
                return;
            }
            let correoDemo = '';
            if (confirmarComprobanteDemoEnabled) {
                if (!(confirmarComprobanteDemoEmail instanceof HTMLInputElement)) {
                    return;
                }
                correoDemo = confirmarComprobanteDemoEmail.value.trim();
                const correoValido = confirmarComprobanteDemoEmail.checkValidity() && correoDemo !== '';
                if (!correoValido) {
                    if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
                        confirmarComprobanteDemoError.classList.remove('d-none');
                    }
                    confirmarComprobanteDemoEmail.focus();
                    return;
                }
            }

            decidirEnvioComprobante(comprobanteFormPendiente, true, correoDemo);
            window.bootstrap.Modal.getOrCreateInstance(modalConfirmarComprobante).hide();
            comprobanteFormPendiente.requestSubmit();
        });
    }

    if (modalConfirmarComprobante) {
        modalConfirmarComprobante.addEventListener('hidden.bs.modal', () => {
            if (confirmarComprobanteDemoError instanceof HTMLDivElement) {
                confirmarComprobanteDemoError.classList.add('d-none');
            }
            comprobanteFormPendiente = null;
        });
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
