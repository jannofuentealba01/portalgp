<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/mail_helper.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

$flash = msp2PullFlash();
$tablaExiste = false;
$loadError = null;

$estadoDocumento = [
    1 => ['label' => 'Borrador', 'badge' => 'text-bg-secondary'],
    2 => ['label' => 'Emitido', 'badge' => 'text-bg-primary'],
    3 => ['label' => 'Pagado Parcial', 'badge' => 'text-bg-warning'],
    4 => ['label' => 'Pagado', 'badge' => 'text-bg-success'],
    5 => ['label' => 'Anulado', 'badge' => 'text-bg-danger'],
];

$estadoPago = [
    1 => ['label' => 'Aplicado', 'badge' => 'text-bg-success'],
    2 => ['label' => 'Anulado', 'badge' => 'text-bg-secondary'],
];

$estadoLoteEnvio = [
    1 => 'Programado',
    2 => 'Procesando',
    3 => 'Completado',
    4 => 'Con error',
    5 => 'Cancelado',
];

$estadoDestinatarioEnvio = [
    1 => 'Pendiente',
    2 => 'Enviado',
    3 => 'Error',
    4 => 'Omitido',
];

$idArrendatario = filter_input(INPUT_GET, 'id_arrendatario', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$filtroPeriodo = trim((string) ($_GET['filtroPeriodo'] ?? ''));

$arrendatariosDisponibles = [];
$arrendatarioIdsDisponibles = [];
$contratosLocalesPorArrendatario = [];
$periodosDisponibles = [];
$arrendatarioSeleccionado = null;
$mostrarDatosFinancieros = false;

$cierrePeriodo = null;
$detalleLocales = [];
$detalleServicioLuz = [];
$documentosPeriodo = [];
$pagosPorDocumento = [];
$detalleLocalesPorDocumento = [];
$detalleLuzPorDocumento = [];
$detalleGasPorDocumento = [];
$detalleAguaPorDocumento = [];
$saldoFavorPorTienda = [];
$conceptosPagoPorDocumento = [];
$enviosPorDocumento = [];
$cargosExtraCondonablesPorDocumento = [];
$cargosExtraOpcionesPorDocumento = [];
$cargosExtraHistorialPorDocumento = [];

$tablaSaldoFavorExiste = false;
$pagosTieneSaldoFavorGenerado = false;
$pagosTieneAplicaSaldoFavor = false;
$tablaPagoDetalleConceptoExiste = false;
$documentosCobroTieneUuid = false;
$tablaBancosExiste = false;
$bancosDisponibles = [];
$correoDemoConfigRaw = trim((string) (mspMailConfig()['demo']['to'] ?? ''));
$correoDemoConfig = filter_var($correoDemoConfigRaw, FILTER_VALIDATE_EMAIL) !== false ? $correoDemoConfigRaw : '';
$modoCorreoDemoActivo = $correoDemoConfig !== '';
$envioArrendatariosHabilitado = msp2MailTenantDeliveryEnabled($conn);

$resumenFinanciero = [
    'total_uf' => 0.0,
    'neto' => 0.0,
    'iva' => 0.0,
    'total' => 0.0,
];

$resumenServicioLuz = [
    'consumo_total' => 0.0,
    'calculo_total' => 0.0,
    'monto_total' => 0.0,
];

$controlComposicion = [
    'arriendo_total' => 0.0,
    'servicios_luz' => 0.0,
    'servicios_gas' => 0.0,
    'servicios_agua' => 0.0,
    'servicios_total' => 0.0,
    'servicios_label' => 'Sin servicios',
    'esperado' => 0.0,
    'documentado' => 0.0,
    'diferencia' => 0.0,
];

$resumenDeuda = [
    'monto_total' => 0.0,
    'saldo_total' => 0.0,
    'pagado_total' => 0.0,
    'documentos' => 0,
];

$filtroPeriodoFactura = null;
$filtroPeriodoFinFactura = null;
if ($filtroPeriodo !== '' && preg_match('/^\d{4}-\d{2}$/', $filtroPeriodo) === 1) {
    $periodoParsed = DateTimeImmutable::createFromFormat('!Y-m', $filtroPeriodo);
    if ($periodoParsed instanceof DateTimeImmutable && $periodoParsed->format('Y-m') === $filtroPeriodo) {
        $filtroPeriodoFactura = $periodoParsed->format('Y-m-01');
        $filtroPeriodoFinFactura = $periodoParsed->modify('last day of this month')->format('Y-m-d');
    }
}

try {
    $requiredTables = [
        'msp_documentos_cobro',
        'msp_tiendas',
        'msp_pagos',
        'msp_arrendatarios',
        'msp_locales',
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_cierre_mensual',
    ];

    $missingTables = [];

    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missingTables[] = $tableName;
        }
    }

    $tablaExiste = $missingTables === [];
    $tablaSaldoFavorExiste = msp2TableExists($conn, 'msp_saldos_favor_tienda') && msp2TableExists($conn, 'msp_movimientos_saldo_favor_tienda');
    $pagosTieneSaldoFavorGenerado = msp2ColumnExists($conn, 'msp_pagos', 'monto_saldo_favor_generado');
    $pagosTieneAplicaSaldoFavor = msp2ColumnExists($conn, 'msp_pagos', 'aplica_desde_saldo_favor');
    $tablaPagoDetalleConceptoExiste = msp2TableExists($conn, 'msp_pagos_detalle_concepto') && msp2TableExists($conn, 'msp_tipo_item_documento');
    $documentosCobroTieneUuid = msp2ColumnExists($conn, 'msp_documentos_cobro', 'uuid_documento');
    $tablaBancosExiste = msp2TableExists($conn, 'msp_bancos');

    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas para esta vista: `' . implode('`, `', $missingTables) . '`. Ejecuta los scripts base de MSP.';
    }
} catch (PDOException $exception) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar la estructura base de cobranza.';
}

if ($tablaExiste) {
    try {
        if ($filtroPeriodoFactura !== null) {
            $stmtArr = $conn->prepare(
                "DECLARE @periodo DATE = :periodo;
                 SELECT
                    a.id_arrendatario,
                    a.rut,
                    COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
                 FROM dbo.msp_arrendatarios a
                 WHERE EXISTS (
                    SELECT 1
                    FROM dbo.msp_documentos_cobro dc
                    WHERE dc.periodo_facturacion = @periodo
                      AND (
                        EXISTS (
                            SELECT 1
                            FROM dbo.msp_tiendas t
                            WHERE t.id_tienda = dc.id_tienda
                              AND t.id_arrendatario = a.id_arrendatario
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM dbo.msp_contratos_arriendo ca_doc
                            WHERE ca_doc.id_tienda = dc.id_tienda
                              AND ca_doc.id_arrendatario = a.id_arrendatario
                              AND ca_doc.fecha_inicio <= EOMONTH(@periodo)
                              AND (ca_doc.fecha_termino_efectiva IS NULL OR ca_doc.fecha_termino_efectiva >= @periodo)
                        )
                      )
                 )
                 OR EXISTS (
                    SELECT 1
                    FROM dbo.msp_contratos_arriendo ca
                    INNER JOIN dbo.msp_contrato_locales cl
                        ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                    WHERE ca.id_arrendatario = a.id_arrendatario
                      AND cl.estado_relacion IN (1,2)
                      AND cl.fecha_inicio <= EOMONTH(@periodo)
                      AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                      AND ca.fecha_inicio <= EOMONTH(@periodo)
                      AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                      AND ca.estado_contrato IN (1,2,3)
                 )
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

        $contratosArrRows = [];
        if ($filtroPeriodoFactura !== null) {
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
        }

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
            $mostrarDatosFinancieros = true;

            $stmtCierre = $conn->prepare(
                'SELECT TOP 1 id_cierre_mensual, periodo_facturacion, fecha_valor_uf, valor_uf
                 FROM dbo.msp_cierre_mensual
                 WHERE periodo_facturacion = :periodo'
            );
            $stmtCierre->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtCierre->execute();
            $cierrePeriodo = $stmtCierre->fetch() ?: null;

            if ($cierrePeriodo !== null) {
                $valorUf = (float) ($cierrePeriodo['valor_uf'] ?? 0);

                $stmtLocales = $conn->prepare(
                    "WITH detalle_arriendo AS (
                        SELECT
                            CASE
                                WHEN dcd.descripcion_item LIKE N'Arriendo local %'
                                    THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                                ELSE NULL
                            END AS cdo_local,
                            CASE
                                WHEN dcd.descripcion_item LIKE N'Arriendo fijo contrato #%'
                                    THEN TRY_CONVERT(INT, LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo fijo contrato #') + 1, 50)))
                                ELSE NULL
                            END AS id_contrato_ref,
                            dcd.descripcion_item,
                            dcd.subtotal
                        FROM dbo.msp_documentos_cobro dc
                        INNER JOIN dbo.msp_tiendas t
                            ON t.id_tienda = dc.id_tienda
                        INNER JOIN dbo.msp_documentos_cobro_detalle dcd
                            ON dcd.id_documento_cobro = dc.id_documento_cobro
                        INNER JOIN dbo.msp_tipo_item_documento tid
                            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                        WHERE t.id_arrendatario = :id_arrendatario
                          AND dc.periodo_facturacion = :periodo
                          AND tid.codigo_item = N'ARRIENDO'
                    )
                    SELECT
                        ISNULL(locales_ref.locales_label, ISNULL(da.cdo_local, N'-')) AS cdo_local,
                        CASE
                            WHEN da.id_contrato_ref IS NOT NULL THEN da.descripcion_item
                            ELSE ISNULL(l.desc_local, da.descripcion_item)
                        END AS desc_local,
                        ROUND(
                            SUM(
                                CASE
                                    WHEN CAST(:valor_uf AS DECIMAL(18, 4)) > 0
                                        THEN da.subtotal / NULLIF(CAST(:valor_uf_calc AS DECIMAL(18, 4)), 0)
                                    ELSE 0
                                END
                            ),
                            2
                        ) AS valor_arriendo_uf,
                        ROUND(SUM(da.subtotal), 2) AS monto_neto
                    FROM detalle_arriendo da
                    LEFT JOIN dbo.msp_locales l
                        ON l.cdo_local = da.cdo_local
                    OUTER APPLY (
                        SELECT
                            STUFF((
                                SELECT N' / ' + LTRIM(RTRIM(ISNULL(l2.cdo_local, N'')))
                                FROM dbo.msp_contrato_locales cl2
                                INNER JOIN dbo.msp_locales l2
                                    ON l2.id_local = cl2.id_local
                                WHERE cl2.id_contrato_arriendo = da.id_contrato_ref
                                  AND cl2.estado_relacion IN (1,2)
                                  AND cl2.fecha_inicio <= EOMONTH(:periodo_locales_ref_fin)
                                  AND (cl2.fecha_termino IS NULL OR cl2.fecha_termino >= :periodo_locales_ref_ini)
                                ORDER BY l2.cdo_local
                                FOR XML PATH(N''), TYPE
                            ).value(N'.', N'nvarchar(max)'), 1, 3, N'') AS locales_label
                    ) locales_ref
                    GROUP BY
                        ISNULL(locales_ref.locales_label, ISNULL(da.cdo_local, N'-')),
                        CASE
                            WHEN da.id_contrato_ref IS NOT NULL THEN da.descripcion_item
                            ELSE ISNULL(l.desc_local, da.descripcion_item)
                        END
                    ORDER BY
                        CASE WHEN ISNULL(locales_ref.locales_label, ISNULL(da.cdo_local, N'-')) = N'-' THEN 1 ELSE 0 END,
                        " . msp2LocalCodeNaturalOrderSql('ISNULL(locales_ref.locales_label, ISNULL(da.cdo_local, N\'-\'))')
                );
                $stmtLocales->bindValue(':id_arrendatario', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
                $stmtLocales->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
                $stmtLocales->bindValue(':valor_uf', $valorUf, PDO::PARAM_STR);
                $stmtLocales->bindValue(':valor_uf_calc', $valorUf, PDO::PARAM_STR);
                $stmtLocales->bindValue(':periodo_locales_ref_ini', $filtroPeriodoFactura, PDO::PARAM_STR);
                $stmtLocales->bindValue(':periodo_locales_ref_fin', $filtroPeriodoFactura, PDO::PARAM_STR);
                $stmtLocales->execute();
                $detalleLocales = $stmtLocales->fetchAll();

                if (empty($detalleLocales) && $filtroPeriodoFinFactura !== null) {
                    // Fallback: si no hay detalle documental, usar contratos activos que crucen el mes.
                    $stmtLocalesFallback = $conn->prepare(
                        "SELECT
                            l.cdo_local,
                            l.desc_local,
                            CAST(MAX(l.valor_arriendo_uf) AS DECIMAL(18, 6)) AS valor_arriendo_uf,
                            ROUND(MAX(l.valor_arriendo_uf) * :valor_uf, 2) AS monto_neto
                         FROM dbo.msp_contrato_locales cl
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                           AND ca.fecha_inicio <= :periodo_fin_ca
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= :periodo_inicio_ca)
                           AND ca.estado_contrato IN (1,2,3)
                         INNER JOIN dbo.msp_tiendas t
                            ON t.id_tienda = ca.id_tienda
                         INNER JOIN dbo.msp_locales l
                            ON l.id_local = cl.id_local
                         WHERE t.id_arrendatario = :id_arrendatario
                           AND cl.estado_relacion = 1
                           AND cl.fecha_inicio <= :periodo_fin_cl
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= :periodo_inicio_cl)
                         GROUP BY
                            l.cdo_local,
                            l.desc_local
                         ORDER BY " . msp2LocalCodeNaturalOrderSql('l.cdo_local')
                    );
                    $stmtLocalesFallback->bindValue(':valor_uf', $valorUf, PDO::PARAM_STR);
                    $stmtLocalesFallback->bindValue(':periodo_inicio_ca', $filtroPeriodoFactura, PDO::PARAM_STR);
                    $stmtLocalesFallback->bindValue(':periodo_fin_ca', $filtroPeriodoFinFactura, PDO::PARAM_STR);
                    $stmtLocalesFallback->bindValue(':periodo_inicio_cl', $filtroPeriodoFactura, PDO::PARAM_STR);
                    $stmtLocalesFallback->bindValue(':periodo_fin_cl', $filtroPeriodoFinFactura, PDO::PARAM_STR);
                    $stmtLocalesFallback->bindValue(':id_arrendatario', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
                    $stmtLocalesFallback->execute();
                    $detalleLocales = $stmtLocalesFallback->fetchAll();
                }

                foreach ($detalleLocales as $local) {
                    $resumenFinanciero['total_uf'] += (float) ($local['valor_arriendo_uf'] ?? 0);
                    $resumenFinanciero['neto'] += (float) ($local['monto_neto'] ?? 0);
                }

                $resumenFinanciero['total_uf'] = round($resumenFinanciero['total_uf'], 2);
                $resumenFinanciero['neto'] = round($resumenFinanciero['neto'], 2);
                $resumenFinanciero['iva'] = round($resumenFinanciero['neto'] * 0.19, 2);
                $resumenFinanciero['total'] = round($resumenFinanciero['neto'] + $resumenFinanciero['iva'], 2);
            }

            $selectUuidDocumentoSql = $documentosCobroTieneUuid
                ? 'CONVERT(CHAR(36), dc.uuid_documento) AS uuid_documento,'
                : 'CAST(NULL AS NVARCHAR(36)) AS uuid_documento,';

            $stmtDocs = $conn->prepare(
                "DECLARE @periodo_contrato DATE = :periodo_contrato;
                 SELECT
                    dc.id_documento_cobro,
                    {$selectUuidDocumentoSql}
                    dc.id_tienda,
                    dc.numero_documento,
                    dc.nombre_tienda_snapshot,
                    dc.periodo_facturacion,
                    dc.fecha_emision,
                    dc.fecha_vencimiento,
                    dc.fecha_registro,
                    dc.subtotal_arriendo,
                    dc.subtotal_servicios,
                    dc.monto_total,
                    dc.saldo_pendiente,
                    dc.estado_documento,
                    (
                        SELECT COUNT(*)
                        FROM dbo.msp_pagos p
                        WHERE p.id_documento_cobro = dc.id_documento_cobro
                    ) AS cantidad_pagos
                 FROM dbo.msp_documentos_cobro dc
                 WHERE dc.periodo_facturacion = :periodo
                   AND (
                        EXISTS (
                            SELECT 1
                            FROM dbo.msp_tiendas t
                            WHERE t.id_tienda = dc.id_tienda
                              AND t.id_arrendatario = :id_arrendatario
                        )
                        OR EXISTS (
                            SELECT 1
                            FROM dbo.msp_contratos_arriendo ca_doc
                            WHERE ca_doc.id_tienda = dc.id_tienda
                              AND ca_doc.id_arrendatario = :id_arrendatario_contrato
                              AND ca_doc.fecha_inicio <= EOMONTH(@periodo_contrato)
                              AND (ca_doc.fecha_termino_efectiva IS NULL OR ca_doc.fecha_termino_efectiva >= @periodo_contrato)
                        )
                   )
                 ORDER BY dc.id_documento_cobro DESC"
            );
            $stmtDocs->bindValue(':id_arrendatario', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
            $stmtDocs->bindValue(':id_arrendatario_contrato', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
            $stmtDocs->bindValue(':periodo_contrato', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtDocs->bindValue(':periodo', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtDocs->execute();
            $documentosPeriodo = $stmtDocs->fetchAll();

            if ($documentosPeriodo !== []) {
                if ($tablaSaldoFavorExiste) {
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

                $documentoIds = array_map(
                    static fn(array $doc): int => (int) ($doc['id_documento_cobro'] ?? 0),
                    $documentosPeriodo
                );
                $documentoIds = array_values(array_filter($documentoIds, static fn(int $id): bool => $id > 0));

                if ($documentoIds !== []) {
                    $placeholders = [];
                    foreach ($documentoIds as $index => $docId) {
                        $placeholders[] = ':doc_' . $index;
                    }

                    if (msp2TableExists($conn, 'msp_cargos_salida') && msp2TableExists($conn, 'msp_tipos_cargo_salida')) {
                        $stmtCargosDocumento = $conn->prepare(
                            "SELECT
                                cs.id_documento_cobro,
                                cs.id_cargo_salida,
                                cs.estado_cargo,
                                cs.monto_cargo,
                                cs.descripcion_cargo,
                                cs.observaciones,
                                cs.fecha_registro,
                                UPPER(LTRIM(RTRIM(ISNULL(tc.codigo_tipo_cargo, N'')))) AS codigo_tipo_cargo,
                                tc.nombre_tipo_cargo,
                                loc.cdo_local
                             FROM dbo.msp_cargos_salida cs
                             INNER JOIN dbo.msp_tipos_cargo_salida tc
                                ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
                             LEFT JOIN dbo.msp_locales loc
                                ON loc.id_local = cs.id_local
                             WHERE cs.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                               AND cs.estado_cargo IN (3, 5)
                               AND UPPER(LTRIM(RTRIM(ISNULL(tc.codigo_tipo_cargo, N'')))) IN (N'MULTA', N'DANOS', N'OTRO')
                               AND cs.monto_cargo > 0
                             ORDER BY cs.id_documento_cobro ASC, cs.id_cargo_salida ASC"
                        );
                        foreach ($documentoIds as $index => $docId) {
                            $stmtCargosDocumento->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                        }
                        $stmtCargosDocumento->execute();
                        while (($cargoRow = $stmtCargosDocumento->fetch()) !== false) {
                            $docCargoId = (int) ($cargoRow['id_documento_cobro'] ?? 0);
                            if ($docCargoId <= 0) {
                                continue;
                            }

                            $estadoCargo = (int) ($cargoRow['estado_cargo'] ?? 0);
                            $codigoTipoCargo = strtoupper(trim((string) ($cargoRow['codigo_tipo_cargo'] ?? '')));
                            $tipoCargoLabel = trim((string) ($cargoRow['nombre_tipo_cargo'] ?? ''));
                            if ($tipoCargoLabel === '') {
                                $tipoCargoLabel = match ($codigoTipoCargo) {
                                    'MULTA' => 'Multa',
                                    'DANOS' => 'Daños/Reparaciones',
                                    default => 'Otro cargo',
                                };
                            }
                            $localCode = trim((string) ($cargoRow['cdo_local'] ?? ''));
                            $descripcionCargo = trim((string) ($cargoRow['descripcion_cargo'] ?? ''));
                            $montoCargo = round((float) ($cargoRow['monto_cargo'] ?? 0), 2);
                            $obsCargo = trim((string) ($cargoRow['observaciones'] ?? ''));

                            if (!isset($cargosExtraHistorialPorDocumento[$docCargoId])) {
                                $cargosExtraHistorialPorDocumento[$docCargoId] = [];
                            }
                            $cargosExtraHistorialPorDocumento[$docCargoId][] = [
                                'id_cargo_salida' => (int) ($cargoRow['id_cargo_salida'] ?? 0),
                                'estado_cargo' => $estadoCargo,
                                'codigo_tipo_cargo' => $codigoTipoCargo,
                                'tipo_label' => $tipoCargoLabel,
                                'local_code' => $localCode,
                                'descripcion' => $descripcionCargo,
                                'monto' => $montoCargo,
                                'observaciones' => $obsCargo,
                                'fecha_registro' => trim((string) ($cargoRow['fecha_registro'] ?? '')),
                            ];

                            if ($estadoCargo !== 3) {
                                continue;
                            }

                            if (!isset($cargosExtraCondonablesPorDocumento[$docCargoId])) {
                                $cargosExtraCondonablesPorDocumento[$docCargoId] = [
                                    'cantidad' => 0,
                                    'monto_total' => 0.0,
                                ];
                            }
                            $cargosExtraCondonablesPorDocumento[$docCargoId]['cantidad']++;
                            $cargosExtraCondonablesPorDocumento[$docCargoId]['monto_total'] += $montoCargo;

                            if (!isset($cargosExtraOpcionesPorDocumento[$docCargoId])) {
                                $cargosExtraOpcionesPorDocumento[$docCargoId] = [];
                            }
                            $resumenCargo = $tipoCargoLabel;
                            if ($localCode !== '') {
                                $resumenCargo .= ' local ' . $localCode;
                            }
                            if ($descripcionCargo !== '') {
                                $resumenCargo .= ': ' . $descripcionCargo;
                            }
                            $cargosExtraOpcionesPorDocumento[$docCargoId][] = [
                                'id_cargo_salida' => (int) ($cargoRow['id_cargo_salida'] ?? 0),
                                'resumen' => $resumenCargo,
                                'monto' => $montoCargo,
                            ];
                        }

                        foreach ($cargosExtraCondonablesPorDocumento as $docCargoId => $infoCargo) {
                            $cargosExtraCondonablesPorDocumento[$docCargoId]['monto_total'] = round((float) ($infoCargo['monto_total'] ?? 0), 2);
                        }
                    }

                    $pagoSelectSaldoFavor = $pagosTieneSaldoFavorGenerado
                        ? 'p.monto_saldo_favor_generado'
                        : 'CAST(0 AS DECIMAL(18,2)) AS monto_saldo_favor_generado';
                    $pagoSelectAplicaSaldoFavor = $pagosTieneAplicaSaldoFavor
                        ? 'p.aplica_desde_saldo_favor'
                        : 'CAST(0 AS BIT) AS aplica_desde_saldo_favor';

                    $stmtPagos = $conn->prepare(
                        "SELECT
                            p.id_pago,
                            p.id_documento_cobro,
                            p.fecha_pago,
                            p.monto_pagado,
                            p.estado_pago,
                            p.fecha_anulacion,
                            p.motivo_anulacion,
                            p.medio_pago,
                            p.referencia_pago,
                            p.observaciones,
                            $pagoSelectSaldoFavor,
                            $pagoSelectAplicaSaldoFavor
                         FROM dbo.msp_pagos p
                         WHERE p.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                         ORDER BY p.fecha_pago DESC, p.id_pago DESC"
                    );

                    foreach ($documentoIds as $index => $docId) {
                        $stmtPagos->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }

                    $stmtPagos->execute();
                    while (($pago = $stmtPagos->fetch()) !== false) {
                        $docId = (int) ($pago['id_documento_cobro'] ?? 0);
                        if ($docId <= 0) {
                            continue;
                        }
                        if (!isset($pagosPorDocumento[$docId])) {
                            $pagosPorDocumento[$docId] = [];
                        }
                        $pagosPorDocumento[$docId][] = $pago;
                    }

                    $hasLoteTables = msp2TableExists($conn, 'msp_envio_lotes_programados')
                        && msp2TableExists($conn, 'msp_envio_lote_destinatarios')
                        && msp2TableExists($conn, 'msp_envio_lote_documentos');
                    if ($hasLoteTables) {
                        $stmtEnvios = $conn->prepare(
                            "SELECT
                                eld.id_documento_cobro,
                                l.id_lote_envio,
                                l.codigo_servicio,
                                l.modo_destino,
                                l.programado_para,
                                l.estado_lote,
                                d.correo_destino,
                                d.estado_destinatario,
                                d.intentos,
                                d.ultimo_error,
                                d.enviado_at,
                                d.updated_at
                             FROM dbo.msp_envio_lote_documentos eld
                             INNER JOIN dbo.msp_envio_lote_destinatarios d
                                ON d.id_lote_destinatario = eld.id_lote_destinatario
                             INNER JOIN dbo.msp_envio_lotes_programados l
                                ON l.id_lote_envio = d.id_lote_envio
                             WHERE eld.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                             ORDER BY
                                eld.id_documento_cobro ASC,
                                l.id_lote_envio DESC,
                                d.id_lote_destinatario DESC"
                        );
                        foreach ($documentoIds as $index => $docId) {
                            $stmtEnvios->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                        }
                        $stmtEnvios->execute();
                        while (($envioRow = $stmtEnvios->fetch()) !== false) {
                            $docId = (int) ($envioRow['id_documento_cobro'] ?? 0);
                            if ($docId <= 0) {
                                continue;
                            }
                            if (!isset($enviosPorDocumento[$docId])) {
                                $enviosPorDocumento[$docId] = [];
                            }
                            $enviosPorDocumento[$docId][] = $envioRow;
                        }
                    }

                    if ($tablaPagoDetalleConceptoExiste) {
                        $prioridadConcepto = static fn(string $codigoItem): int => msp2PagoPrioridadImputacion($codigoItem);

                        $documentosPorId = [];
                        foreach ($documentosPeriodo as $docData) {
                            $docDataId = (int) ($docData['id_documento_cobro'] ?? 0);
                            if ($docDataId <= 0) {
                                continue;
                            }
                            $documentosPorId[$docDataId] = $docData;
                        }

                        $tipoArriendo = null;
                        $stmtTipoArriendo = $conn->query(
                            "SELECT TOP 1
                                tid.id_tipo_item_documento,
                                tid.codigo_item,
                                tid.nombre_item
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

                            if ($tipoArriendo === null) {
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
                            if ($docId <= 0 || $tipoId <= 0) {
                                continue;
                            }
                            if (!isset($conceptosTotales[$docId][$tipoId])) {
                                continue;
                            }
                            $conceptosTotales[$docId][$tipoId]['monto_aplicado'] = round((float) ($conceptoAplicado['monto_aplicado'] ?? 0), 2);
                        }

                        foreach ($conceptosTotales as $docId => $conceptosDoc) {
                            $rows = [];
                            foreach ($conceptosDoc as $tipoId => $row) {
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

                    $stmtDetalleLocales = $conn->prepare(
                        "WITH detalle_arriendo AS (
                            SELECT
                                dcd.id_documento_cobro,
                                dcd.orden_item,
                                dcd.descripcion_item,
                                dcd.subtotal,
                                dc.periodo_facturacion,
                                CASE
                                    WHEN dcd.descripcion_item LIKE N'Arriendo local %'
                                        THEN LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo local ') + 1, 200))
                                    ELSE NULL
                                END AS cdo_local,
                                CASE
                                    WHEN dcd.descripcion_item LIKE N'Arriendo fijo contrato #%'
                                        THEN TRY_CONVERT(INT, LTRIM(SUBSTRING(dcd.descripcion_item, LEN(N'Arriendo fijo contrato #') + 1, 50)))
                                    ELSE NULL
                                END AS id_contrato_ref
                            FROM dbo.msp_documentos_cobro_detalle dcd
                            INNER JOIN dbo.msp_documentos_cobro dc
                                ON dc.id_documento_cobro = dcd.id_documento_cobro
                            INNER JOIN dbo.msp_tipo_item_documento tid
                                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                            WHERE dcd.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                              AND tid.codigo_item = N'ARRIENDO'
                        )
                        SELECT
                            da.id_documento_cobro,
                            da.orden_item,
                            ISNULL(locales_ref.locales_label, ISNULL(loc.cdo_local, ISNULL(da.cdo_local, N'-'))) AS cdo_local,
                            CASE
                                WHEN da.id_contrato_ref IS NOT NULL THEN da.descripcion_item
                                ELSE ISNULL(loc.desc_local, da.descripcion_item)
                            END AS desc_local,
                            loc.metros_cuadrados,
                            da.subtotal AS monto_neto
                        FROM detalle_arriendo da
                        LEFT JOIN dbo.msp_locales loc
                            ON loc.cdo_local = da.cdo_local
                        OUTER APPLY (
                            SELECT
                                STUFF((
                                    SELECT N' / ' + LTRIM(RTRIM(ISNULL(l2.cdo_local, N'')))
                                    FROM dbo.msp_contrato_locales cl2
                                    INNER JOIN dbo.msp_locales l2
                                        ON l2.id_local = cl2.id_local
                                    WHERE cl2.id_contrato_arriendo = da.id_contrato_ref
                                      AND cl2.estado_relacion IN (1,2)
                                      AND cl2.fecha_inicio <= EOMONTH(da.periodo_facturacion)
                                      AND (cl2.fecha_termino IS NULL OR cl2.fecha_termino >= da.periodo_facturacion)
                                    ORDER BY l2.cdo_local
                                    FOR XML PATH(N''), TYPE
                                ).value(N'.', N'nvarchar(max)'), 1, 3, N'') AS locales_label
                        ) locales_ref
                        ORDER BY da.id_documento_cobro ASC, da.orden_item ASC"
                    );

                    foreach ($documentoIds as $index => $docId) {
                        $stmtDetalleLocales->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }

                    $stmtDetalleLocales->execute();
                    while (($detalleLocal = $stmtDetalleLocales->fetch()) !== false) {
                        $docId = (int) ($detalleLocal['id_documento_cobro'] ?? 0);
                        if ($docId <= 0) {
                            continue;
                        }
                        if (!isset($detalleLocalesPorDocumento[$docId])) {
                            $detalleLocalesPorDocumento[$docId] = [];
                        }
                        $detalleLocalesPorDocumento[$docId][] = $detalleLocal;
                    }

                    $stmtDetalleLuz = $conn->prepare(
                        "SELECT
                            dc.id_documento_cobro,
                            loc.cdo_local,
                            loc.desc_local,
                            m.codigo_medidor,
                            lm.lectura_anterior,
                            lm.lectura_actual,
                            cs.consumo_cobrado,
                            ISNULL(pl.valor_kwh, 0) AS valor_kwh,
                            ROUND(cs.consumo_cobrado * ISNULL(pl.valor_kwh, 0), 2) AS calculo_luz,
                            cs.monto_total
                         FROM dbo.msp_documentos_cobro dc
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_tienda = dc.id_tienda
                           AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)
                           AND ca.estado_contrato IN (1,2,3)
                         INNER JOIN dbo.msp_contrato_locales cl
                            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                           AND cl.estado_relacion = 1
                           AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)
                         INNER JOIN dbo.msp_locales loc
                            ON loc.id_local = cl.id_local
                         INNER JOIN dbo.msp_medidores m
                            ON m.id_local = loc.id_local
                         INNER JOIN dbo.msp_lecturas_medidores lm
                            ON lm.id_medidor = m.id_medidor
                           AND lm.periodo_facturacion = dc.periodo_facturacion
                           AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                         INNER JOIN dbo.msp_cobros_servicios cs
                            ON cs.id_lectura = lm.id_lectura
                         INNER JOIN dbo.msp_procesos_cobro_servicio p
                            ON p.id_proceso_cobro = lm.id_proceso_cobro
                         INNER JOIN dbo.msp_tipos_servicio ts
                            ON ts.id_tipo_servicio = p.id_tipo_servicio
                         LEFT JOIN dbo.msp_proceso_cobro_luz pl
                            ON pl.id_proceso_cobro = p.id_proceso_cobro
                         WHERE dc.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                           AND ts.codigo_servicio = N'LUZ'
                           AND ISNULL(cs.consumo_cobrado, 0) > 0
                         ORDER BY dc.id_documento_cobro ASC, " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
                    );

                    foreach ($documentoIds as $index => $docId) {
                        $stmtDetalleLuz->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }

                    $stmtDetalleLuz->execute();
                    while (($detalleLuz = $stmtDetalleLuz->fetch()) !== false) {
                        $docId = (int) ($detalleLuz['id_documento_cobro'] ?? 0);
                        if ($docId <= 0) {
                            continue;
                        }
                        if (!isset($detalleLuzPorDocumento[$docId])) {
                            $detalleLuzPorDocumento[$docId] = [];
                        }
                        $detalleLuzPorDocumento[$docId][] = $detalleLuz;
                    }

                    $stmtDetalleGas = $conn->prepare(
                        "SELECT
                            dc.id_documento_cobro,
                            loc.cdo_local,
                            loc.desc_local,
                            m.codigo_medidor,
                            lm.lectura_anterior,
                            lm.lectura_actual,
                            cs.consumo_cobrado,
                            ISNULL(pg.factor, 0) AS factor,
                            ISNULL(pg.valor_litro, 0) AS valor_litro,
                            cs.monto_total
                         FROM dbo.msp_documentos_cobro dc
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_tienda = dc.id_tienda
                           AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)
                           AND ca.estado_contrato IN (1,2,3)
                         INNER JOIN dbo.msp_contrato_locales cl
                            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                           AND cl.estado_relacion = 1
                           AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)
                         INNER JOIN dbo.msp_locales loc
                            ON loc.id_local = cl.id_local
                         INNER JOIN dbo.msp_medidores m
                            ON m.id_local = loc.id_local
                         INNER JOIN dbo.msp_lecturas_medidores lm
                            ON lm.id_medidor = m.id_medidor
                           AND lm.periodo_facturacion = dc.periodo_facturacion
                           AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                         INNER JOIN dbo.msp_cobros_servicios cs
                            ON cs.id_lectura = lm.id_lectura
                         INNER JOIN dbo.msp_procesos_cobro_servicio p
                            ON p.id_proceso_cobro = lm.id_proceso_cobro
                         INNER JOIN dbo.msp_tipos_servicio ts
                            ON ts.id_tipo_servicio = p.id_tipo_servicio
                         LEFT JOIN dbo.msp_proceso_cobro_gas pg
                            ON pg.id_proceso_cobro = p.id_proceso_cobro
                         WHERE dc.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                           AND ts.codigo_servicio = N'GAS'
                           AND ISNULL(cs.consumo_cobrado, 0) > 0
                         ORDER BY dc.id_documento_cobro ASC, " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
                    );

                    foreach ($documentoIds as $index => $docId) {
                        $stmtDetalleGas->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }

                    $stmtDetalleGas->execute();
                    while (($detalleGas = $stmtDetalleGas->fetch()) !== false) {
                        $docId = (int) ($detalleGas['id_documento_cobro'] ?? 0);
                        if ($docId <= 0) {
                            continue;
                        }
                        if (!isset($detalleGasPorDocumento[$docId])) {
                            $detalleGasPorDocumento[$docId] = [];
                        }
                        $detalleGasPorDocumento[$docId][] = $detalleGas;
                    }

                    $stmtDetalleAgua = $conn->prepare(
                        "SELECT
                            dc.id_documento_cobro,
                            loc.cdo_local,
                            loc.desc_local,
                            m.codigo_medidor,
                            lm.lectura_anterior,
                            lm.lectura_actual,
                            cs.consumo_cobrado,
                            cs.monto_total
                         FROM dbo.msp_documentos_cobro dc
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_tienda = dc.id_tienda
                           AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= dc.periodo_facturacion)
                           AND ca.estado_contrato IN (1,2,3)
                         INNER JOIN dbo.msp_contrato_locales cl
                            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
                           AND cl.estado_relacion = 1
                           AND cl.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= dc.periodo_facturacion)
                         INNER JOIN dbo.msp_locales loc
                            ON loc.id_local = cl.id_local
                         INNER JOIN dbo.msp_medidores m
                            ON m.id_local = loc.id_local
                         INNER JOIN dbo.msp_lecturas_medidores lm
                            ON lm.id_medidor = m.id_medidor
                           AND lm.periodo_facturacion = dc.periodo_facturacion
                           AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                         INNER JOIN dbo.msp_cobros_servicios cs
                            ON cs.id_lectura = lm.id_lectura
                         INNER JOIN dbo.msp_procesos_cobro_servicio p
                            ON p.id_proceso_cobro = lm.id_proceso_cobro
                         INNER JOIN dbo.msp_tipos_servicio ts
                            ON ts.id_tipo_servicio = p.id_tipo_servicio
                         WHERE dc.id_documento_cobro IN (" . implode(', ', $placeholders) . ")
                           AND ts.codigo_servicio = N'AGUA'
                           AND ISNULL(cs.consumo_cobrado, 0) > 0
                         ORDER BY dc.id_documento_cobro ASC, " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
                    );

                    foreach ($documentoIds as $index => $docId) {
                        $stmtDetalleAgua->bindValue(':doc_' . $index, $docId, PDO::PARAM_INT);
                    }

                    $stmtDetalleAgua->execute();
                    while (($detalleAgua = $stmtDetalleAgua->fetch()) !== false) {
                        $docId = (int) ($detalleAgua['id_documento_cobro'] ?? 0);
                        if ($docId <= 0) {
                            continue;
                        }
                        if (!isset($detalleAguaPorDocumento[$docId])) {
                            $detalleAguaPorDocumento[$docId] = [];
                        }
                        $detalleAguaPorDocumento[$docId][] = $detalleAgua;
                    }
                }
            }

            $stmtLuz = $conn->prepare(
                "SELECT
                    loc.cdo_local,
                    loc.desc_local,
                    m.codigo_medidor,
                    lm.lectura_anterior,
                    lm.lectura_actual,
                    cs.consumo_cobrado,
                    ISNULL(pl.valor_kwh, 0) AS valor_kwh,
                    ROUND(cs.consumo_cobrado * ISNULL(pl.valor_kwh, 0), 2) AS calculo_luz,
                    cs.monto_total
                 FROM dbo.msp_cobros_servicios cs
                 INNER JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_lectura = cs.id_lectura
                 INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                 INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                 INNER JOIN dbo.msp_medidores m
                    ON m.id_medidor = lm.id_medidor
                 INNER JOIN dbo.msp_locales loc
                    ON loc.id_local = m.id_local
                 INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_local = loc.id_local
                   AND cl.estado_relacion = 1
                   AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                 INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                   AND ca.fecha_inicio <= EOMONTH(:periodo_ca_ini)
                   AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= :periodo_ca_fin)
                   AND ca.estado_contrato IN (1,2,3)
                 INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = ca.id_tienda
                 LEFT JOIN dbo.msp_proceso_cobro_luz pl
                    ON pl.id_proceso_cobro = p.id_proceso_cobro
                 WHERE t.id_arrendatario = :id_arrendatario
                   AND lm.periodo_facturacion = :periodo_lm
                   AND ts.codigo_servicio = N'LUZ'
                 ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
            );
            $stmtLuz->bindValue(':id_arrendatario', (int) $arrendatarioSeleccionado['id_arrendatario'], PDO::PARAM_INT);
            $stmtLuz->bindValue(':periodo_ca_ini', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtLuz->bindValue(':periodo_ca_fin', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtLuz->bindValue(':periodo_lm', $filtroPeriodoFactura, PDO::PARAM_STR);
            $stmtLuz->execute();
            $detalleServicioLuz = $stmtLuz->fetchAll();

            foreach ($detalleServicioLuz as $luzRow) {
                $resumenServicioLuz['consumo_total'] += (float) ($luzRow['consumo_cobrado'] ?? 0);
                $resumenServicioLuz['calculo_total'] += (float) ($luzRow['calculo_luz'] ?? 0);
                $resumenServicioLuz['monto_total'] += (float) ($luzRow['monto_total'] ?? 0);
            }
            $resumenServicioLuz['consumo_total'] = round($resumenServicioLuz['consumo_total'], 0);
            $resumenServicioLuz['calculo_total'] = round($resumenServicioLuz['calculo_total'], 2);
            $resumenServicioLuz['monto_total'] = round($resumenServicioLuz['monto_total'], 2);

            foreach ($documentosPeriodo as $doc) {
                $montoTotal = (float) ($doc['monto_total'] ?? 0);
                $saldo = (float) ($doc['saldo_pendiente'] ?? 0);

                $resumenDeuda['monto_total'] += $montoTotal;
                $resumenDeuda['saldo_total'] += $saldo;
                $resumenDeuda['documentos']++;
            }

            $resumenDeuda['monto_total'] = round($resumenDeuda['monto_total'], 2);
            $resumenDeuda['saldo_total'] = round($resumenDeuda['saldo_total'], 2);
            $resumenDeuda['pagado_total'] = round(max(0, $resumenDeuda['monto_total'] - $resumenDeuda['saldo_total']), 2);

            $sumarMontoPorDocumento = static function (array $detallesPorDocumento): float {
                $total = 0.0;
                foreach ($detallesPorDocumento as $rows) {
                    if (!is_array($rows)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $total += (float) ($row['monto_total'] ?? 0);
                    }
                }

                return round($total, 2);
            };

            $controlComposicion['arriendo_total'] = $resumenFinanciero['total'];
            $controlComposicion['servicios_luz'] = $sumarMontoPorDocumento($detalleLuzPorDocumento);
            $controlComposicion['servicios_gas'] = $sumarMontoPorDocumento($detalleGasPorDocumento);
            $controlComposicion['servicios_agua'] = $sumarMontoPorDocumento($detalleAguaPorDocumento);
            $controlComposicion['servicios_total'] = round(
                $controlComposicion['servicios_luz']
                + $controlComposicion['servicios_gas']
                + $controlComposicion['servicios_agua'],
                2
            );

            $serviciosComposicion = [];
            if ($controlComposicion['servicios_luz'] > 0) {
                $serviciosComposicion[] = 'LUZ';
            }
            if ($controlComposicion['servicios_gas'] > 0) {
                $serviciosComposicion[] = 'GAS';
            }
            if ($controlComposicion['servicios_agua'] > 0) {
                $serviciosComposicion[] = 'AGUA';
            }
            $controlComposicion['servicios_label'] = $serviciosComposicion !== [] ? implode(' + ', $serviciosComposicion) : 'Sin servicios';
            $controlComposicion['esperado'] = round($controlComposicion['arriendo_total'] + $controlComposicion['servicios_total'], 2);
            $controlComposicion['documentado'] = $resumenDeuda['monto_total'];
            $controlComposicion['diferencia'] = round($controlComposicion['documentado'] - $controlComposicion['esperado'], 2);
        }
    } catch (PDOException $exception) {
        $loadError = 'No fue posible cargar la vista de cobranza. Detalle tecnico: ' . $exception->getMessage();
    }
}

$queryBase = $_GET;

function formatoMonto(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return '$ ' . number_format((float) $value, 2, ',', '.');
}

function formatoDecimal(mixed $value, int $decimales = 2): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimales, ',', '.');
}

function formatoFecha(?string $value): string
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

function formatoFechaHora(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    $normalized = str_replace('T', ' ', trim($value));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized) === 1) {
        return formatoFecha($normalized);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+00:00(?::00(?:\.0+)?)?$/', $normalized) === 1) {
        return formatoFecha(substr($normalized, 0, 10));
    }
    $timestamp = strtotime($normalized);
    if ($timestamp === false) {
        return formatoFecha($value);
    }

    return date('d-m-Y H:i', $timestamp);
}

function formatoRutFrontend(?string $rut): string
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
?>
<!DOCTYPE html>
<html lang="es" class="h-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MSP | Documentos de Cobro</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <?php msp2RenderSearchableSelectAssets(); ?>
    <style>
        .doc-card {
            border-color: #d8e2f0;
        }
        .doc-card .doc-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.15rem;
        }
        .doc-card .doc-subtitle {
            color: #61738b;
            font-size: 0.84rem;
        }
        .doc-kpi {
            border: 1px solid #e7edf6;
            border-radius: 0.55rem;
            background: #f8fbff;
            padding: 0.7rem 0.8rem;
            height: 100%;
        }
        .doc-kpi .label {
            color: #6f8098;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 0.2rem;
        }
        .doc-kpi .value {
            font-size: 1.03rem;
            font-weight: 600;
            color: #1f2e44;
            margin: 0;
            line-height: 1.2;
        }
        .doc-detail-box {
            border: 1px solid #e5ebf5;
            border-radius: 0.55rem;
            background: #ffffff;
            padding: 0.9rem;
            height: 100%;
        }
        .timeline-scroll {
            max-height: none;
            overflow: visible;
            padding-right: 0;
        }
        .timeline-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }
        .timeline-item {
            position: relative;
            padding-left: 2.45rem;
            padding-bottom: 1rem;
        }
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        .timeline-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 0.95rem;
            top: 1.65rem;
            bottom: 0.15rem;
            width: 2px;
            background: #d7e1ee;
        }
        .timeline-marker {
            position: absolute;
            left: 0.35rem;
            top: 0.15rem;
            width: 1.2rem;
            height: 1.2rem;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            color: #fff;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.06);
        }
        .timeline-item-operativa .timeline-marker {
            background: #1f8a53;
        }
        .timeline-item-sistema .timeline-marker {
            background: #6b7785;
        }
        .timeline-content {
            border: 1px solid #e8edf5;
            border-radius: 0.5rem;
            padding: 0.65rem 0.75rem;
            background: #fff;
        }
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
        .msp-documents-index {
            width: 100%;
            max-width: none;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            box-shadow: none;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
        }
        .msp-documents-page-header {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
            align-items: center;
            gap: 1rem;
            margin-bottom: .8rem;
        }
        .msp-documents-page-header .msp-documents-back {
            grid-column: 1;
            justify-self: start;
        }
        .msp-documents-page-header h1 {
            grid-column: 2;
            margin: 0;
            color: #003da5;
            font-size: 1.75rem;
            line-height: 1.2;
        }
        .msp-documents-section {
            margin-bottom: .75rem;
            border: 0;
            border-radius: .65rem;
            background: #fff;
            box-shadow: 0 1px 5px rgba(30, 50, 75, .09);
        }
        .msp-documents-section > .card-body {
            padding: .8rem .9rem;
        }
        .msp-documents-section h2 {
            color: #24364b;
            font-size: 1rem;
            font-weight: 700;
        }
        .msp-documents-index .form-label {
            margin-bottom: .22rem;
            font-size: .84rem;
            font-weight: 600;
        }
        .msp-documents-index .form-control,
        .msp-documents-index .form-select,
        .msp-documents-index .msp-searchable-select-toggle {
            min-height: 36px;
            padding-top: .35rem;
            padding-bottom: .35rem;
            font-size: .88rem;
        }
        .msp-documents-index .btn {
            min-height: 34px;
            padding: .32rem .65rem;
            font-size: .84rem;
        }
        .msp-documents-summary .border.rounded {
            padding: .65rem .75rem !important;
            border-color: #e4eaf1 !important;
            background: #f7f9fc !important;
        }
        .msp-documents-summary .h5 {
            font-size: 1rem;
        }
        .msp-documents-detail > .card-body {
            padding: .8rem;
        }
        .msp-documents-detail .doc-card {
            border: 1px solid #d8e0ea;
            border-radius: .55rem;
            box-shadow: none !important;
        }
        .msp-documents-detail .doc-card > .card-body {
            padding: .75rem .8rem;
        }
        .msp-documents-detail .doc-detail-box {
            padding: .7rem;
        }
        @media (max-width: 767.98px) {
            .msp-documents-page-header {
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
            }
            .msp-documents-page-header h1 {
                order: -1;
                width: 100%;
                text-align: center;
                font-size: 1.55rem;
            }
            .msp-documents-section > .card-body,
            .msp-documents-detail > .card-body {
                padding: .7rem;
            }
        }
    </style>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main p-3 p-xl-4">
    <div class="msp-documents-index">
        <header class="msp-documents-page-header">
            <a href="<?php echo msp2Escape(msp2Url('msp_menu.php')); ?>" class="btn btn-outline-secondary btn-sm msp-documents-back">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a MSP
            </a>
            <h1>Documentos de cobro</h1>
        </header>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert <?php echo $tablaExiste ? 'alert-danger' : 'alert-warning'; ?>" role="alert">
                <?php echo msp2Escape($loadError); ?>
            </div>
        <?php else: ?>
            <div class="card msp-documents-section msp-documents-selection">
                <div class="card-body">
                    <h2 class="h6 mb-2">Selección</h2>
                    <div class="row g-2">
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
                                        $arrRut = formatoRutFrontend((string) ($arr['rut'] ?? ''));
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
                                        'empty_message' => 'No hay arrendatarios disponibles.',
                                        'required' => true,
                                        'value' => $arrendatarioSeleccionado !== null ? (string) (int) $arrendatarioSeleccionado['id_arrendatario'] : '',
                                        'options' => $arrendatarioOptions,
                                    ]);
                                    ?>
                                </div>
                            </form>
                                <?php if (empty($arrendatarioOptions)): ?>
                                    <div class="alert alert-warning mt-2 mb-0">No hay arrendatarios con contratos o documentos para este período.</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($arrendatarioSeleccionado !== null && $filtroPeriodo !== '' && !$mostrarDatosFinancieros): ?>
                <div class="alert alert-warning">El período seleccionado no tiene documentos para este arrendatario.</div>
            <?php endif; ?>

            <?php if ($mostrarDatosFinancieros): ?>
                <div class="card msp-documents-section msp-documents-summary">
                    <div class="card-body">
                        <h2 class="h6 mb-2">Resumen de deuda del período</h2>
                        <div class="row g-2">
                            <div class="col-12 col-md-3">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="small text-muted">Documentos</div>
                                    <div class="h5 mb-0"><?php echo number_format((int) $resumenDeuda['documentos'], 0, ',', '.'); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="small text-muted">Monto total</div>
                                    <div class="h5 mb-0"><?php echo msp2Escape(formatoMonto($resumenDeuda['monto_total'])); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="small text-muted">Pagado</div>
                                    <div class="h5 mb-0 text-success"><?php echo msp2Escape(formatoMonto($resumenDeuda['pagado_total'])); ?></div>
                                </div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="border rounded p-3 bg-white h-100">
                                    <div class="small text-muted">Debe (saldo)</div>
                                    <div class="h5 mb-0 text-danger"><?php echo msp2Escape(formatoMonto($resumenDeuda['saldo_total'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card msp-documents-section msp-documents-detail">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                            <h2 class="h6 mb-0">Detalle por documento</h2>
                            <div class="small text-muted">
                                <?php echo number_format((int) $resumenDeuda['documentos'], 0, ',', '.'); ?> documento(s) en <?php echo msp2Escape((string) $filtroPeriodo); ?>
                            </div>
                        </div>

                        <?php if (empty($documentosPeriodo)): ?>
                            <div class="alert alert-warning mb-0">No hay documentos para este arrendatario/período.</div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-2">
                                <?php foreach ($documentosPeriodo as $doc): ?>
                                    <?php
                                    $estadoId = (int) ($doc['estado_documento'] ?? 0);
                                    $estado = $estadoDocumento[$estadoId] ?? ['label' => 'Desconocido', 'badge' => 'text-bg-light text-dark'];
                                    $docId = (int) ($doc['id_documento_cobro'] ?? 0);
                                    $docUuid = trim((string) ($doc['uuid_documento'] ?? ''));
                                    $docNumero = trim((string) ($doc['numero_documento'] ?? ''));
                                    $docTienda = trim((string) ($doc['nombre_tienda_snapshot'] ?? ''));
                                    $tiendaId = (int) ($doc['id_tienda'] ?? 0);
                                    $saldo = round((float) ($doc['saldo_pendiente'] ?? 0), 2);
                                    $montoTotalDocumento = round((float) ($doc['monto_total'] ?? 0), 2);
                                    $montoPagadoDocumento = round(max(0, $montoTotalDocumento - $saldo), 2);
                                    $porcentajePagado = $montoTotalDocumento > 0
                                        ? min(100, max(0, round(($montoPagadoDocumento / $montoTotalDocumento) * 100, 1)))
                                        : 0.0;
                                    $puedePagar = $saldo > 0 && $estadoId !== 5;
                                    $saldoFavorTienda = (float) ($saldoFavorPorTienda[$tiendaId] ?? 0);
                                    $puedeAplicarSaldoFavor = $saldoFavorTienda > 0 && $saldo > 0 && $estadoId !== 5;
                                    $pagosDocumento = $pagosPorDocumento[$docId] ?? [];
                                    $enviosDocumento = $enviosPorDocumento[$docId] ?? [];
                                    $localesDocumento = $detalleLocalesPorDocumento[$docId] ?? [];
                                    $luzDocumento = $detalleLuzPorDocumento[$docId] ?? [];
                                    $gasDocumento = $detalleGasPorDocumento[$docId] ?? [];
                                    $aguaDocumento = $detalleAguaPorDocumento[$docId] ?? [];
                                    $conceptosDocumentoPago = $conceptosPagoPorDocumento[$docId] ?? [];
                                    $cargosCondonablesDoc = $cargosExtraCondonablesPorDocumento[$docId] ?? null;
                                    $cantidadCargosCondonables = (int) ($cargosCondonablesDoc['cantidad'] ?? 0);
                                    $montoCargosCondonables = round((float) ($cargosCondonablesDoc['monto_total'] ?? 0), 2);
                                    $cargosOpcionesDoc = $cargosExtraOpcionesPorDocumento[$docId] ?? [];
                                    $cargosOpcionesDocJson = '[]';
                                    if ($cargosOpcionesDoc !== []) {
                                        $encodedCargos = json_encode(
                                            $cargosOpcionesDoc,
                                            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                                        );
                                        if (is_string($encodedCargos) && $encodedCargos !== '') {
                                            $cargosOpcionesDocJson = $encodedCargos;
                                        }
                                    }
                                    $conceptosDocumentoPagoJson = '[]';
                                    if ($conceptosDocumentoPago !== []) {
                                        $encoded = json_encode(
                                            $conceptosDocumentoPago,
                                            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                                        );
                                        if (is_string($encoded) && $encoded !== '') {
                                            $conceptosDocumentoPagoJson = $encoded;
                                        }
                                    }
                                    $historialDocumento = [];
                                    $pushEventoHistorial = static function (
                                        array &$bucket,
                                        string $momentoRaw,
                                        string $titulo,
                                        string $detalle,
                                        string $badge,
                                        string $contextoFecha,
                                        string $categoria
                                    ): void {
                                        $momentoNorm = trim($momentoRaw);
                                        if ($momentoNorm === '') {
                                            return;
                                        }
                                        $sortTs = strtotime(str_replace('T', ' ', $momentoNorm));
                                        if ($sortTs === false) {
                                            $sortTs = 0;
                                        }
                                        $bucket[] = [
                                            'momento' => $momentoNorm,
                                            'sort_ts' => $sortTs,
                                            'titulo' => $titulo,
                                            'detalle' => $detalle,
                                            'badge' => $badge,
                                            'contexto_fecha' => $contextoFecha,
                                            'categoria' => $categoria,
                                        ];
                                    };
                                    $periodoDocumentoYm = substr((string) ($doc['periodo_facturacion'] ?? ''), 0, 7);
                                    $fechaRegistroDocumentoYm = substr((string) ($doc['fecha_registro'] ?? ''), 0, 7);
                                    $esRegeneracionFueraPeriodo = $periodoDocumentoYm !== ''
                                        && $fechaRegistroDocumentoYm !== ''
                                        && $fechaRegistroDocumentoYm !== $periodoDocumentoYm;
                                    $tituloDocumentoSistema = $esRegeneracionFueraPeriodo
                                        ? 'Documento regenerado en sistema'
                                        : 'Documento creado';
                                    $detalleDocumentoSistema = $esRegeneracionFueraPeriodo
                                        ? 'Documento recalculado para el período ' . $periodoDocumentoYm . '.'
                                        : 'Documento generado en el sistema.';
                                    $pushEventoHistorial(
                                        $historialDocumento,
                                        (string) ($doc['fecha_registro'] ?? ''),
                                        $tituloDocumentoSistema,
                                        $detalleDocumentoSistema,
                                        'text-bg-secondary',
                                        'Fecha de sistema',
                                        'sistema'
                                    );
                                    $fechaEmisionDocumentoBase = trim((string) ($doc['fecha_emision'] ?? ''));
                                    $fechaEmisionDesdeLote = '';
                                    $idLoteEmision = 0;
                                    $tituloEmisionOperativa = 'Documento emitido';
                                    $detalleEmisionOperativa = 'Emisión formal del documento de cobro.';
                                    $contextoEmisionOperativa = 'Fecha operativa';
                                    $primerEnvioExitoso = null;
                                    foreach ($enviosDocumento as $envioRef) {
                                        $idLoteRef = (int) ($envioRef['id_lote_envio'] ?? 0);
                                        $estadoDestRef = (int) ($envioRef['estado_destinatario'] ?? 0);
                                        if ($idLoteRef <= 0 || $estadoDestRef !== 2) {
                                            continue;
                                        }
                                        $enviadoAtRef = trim((string) ($envioRef['enviado_at'] ?? ''));
                                        $programadoRef = trim((string) ($envioRef['programado_para'] ?? ''));
                                        $updatedRef = trim((string) ($envioRef['updated_at'] ?? ''));
                                        // Priorizamos `updated_at` (SYSDATETIME del SQL Server) para
                                        // mantener una semántica temporal consistente en el historial.
                                        $momentoRef = $updatedRef !== ''
                                            ? $updatedRef
                                            : ($enviadoAtRef !== '' ? $enviadoAtRef : $programadoRef);
                                        if ($momentoRef === '') {
                                            continue;
                                        }

                                        $sortRef = strtotime(str_replace('T', ' ', $momentoRef));
                                        if ($sortRef === false) {
                                            $sortRef = PHP_INT_MAX;
                                        }

                                        if ($primerEnvioExitoso === null) {
                                            $primerEnvioExitoso = [
                                                'id_lote_envio' => $idLoteRef,
                                                'momento' => $momentoRef,
                                                'sort_ts' => $sortRef,
                                            ];
                                            continue;
                                        }

                                        $sortActual = (int) ($primerEnvioExitoso['sort_ts'] ?? PHP_INT_MAX);
                                        $idLoteActual = (int) ($primerEnvioExitoso['id_lote_envio'] ?? PHP_INT_MAX);
                                        if ($sortRef < $sortActual || ($sortRef === $sortActual && $idLoteRef < $idLoteActual)) {
                                            $primerEnvioExitoso = [
                                                'id_lote_envio' => $idLoteRef,
                                                'momento' => $momentoRef,
                                                'sort_ts' => $sortRef,
                                            ];
                                        }
                                    }
                                    if (is_array($primerEnvioExitoso)) {
                                        $idLoteEmision = (int) ($primerEnvioExitoso['id_lote_envio'] ?? 0);
                                        $fechaEmisionDesdeLote = trim((string) ($primerEnvioExitoso['momento'] ?? ''));
                                        if ($fechaEmisionDesdeLote !== '') {
                                            $tituloEmisionOperativa = 'Documento enviado (emisión operativa)';
                                            $detalleEmisionOperativa = 'Primer envío exitoso en lote #' . $idLoteEmision . '.';
                                            $contextoEmisionOperativa = 'Fecha operativa (primer envío exitoso)';
                                        }
                                    }

                                    $fechaEmisionOperativa = $fechaEmisionDesdeLote !== ''
                                        ? $fechaEmisionDesdeLote
                                        : $fechaEmisionDocumentoBase;
                                    $pushEventoHistorial(
                                        $historialDocumento,
                                        $fechaEmisionOperativa,
                                        $tituloEmisionOperativa,
                                        $detalleEmisionOperativa,
                                        'text-bg-primary',
                                        $contextoEmisionOperativa,
                                        'operativa'
                                    );
                                    $pushEventoHistorial(
                                        $historialDocumento,
                                        (string) ($doc['fecha_vencimiento'] ?? ''),
                                        'Vencimiento programado',
                                        'Fecha límite de pago del documento.',
                                        'text-bg-light text-dark',
                                        'Fecha operativa',
                                        'operativa'
                                    );
                                    foreach ($enviosDocumento as $envioDoc) {
                                        $estadoDest = (int) ($envioDoc['estado_destinatario'] ?? 0);
                                        $estadoLote = (int) ($envioDoc['estado_lote'] ?? 0);
                                        $estadoDestLabel = $estadoDestinatarioEnvio[$estadoDest] ?? 'Desconocido';
                                        $estadoLoteLabel = $estadoLoteEnvio[$estadoLote] ?? 'Desconocido';
                                        $correoDestino = trim((string) ($envioDoc['correo_destino'] ?? ''));
                                        $codigoServicioEnvio = strtoupper(trim((string) ($envioDoc['codigo_servicio'] ?? '')));
                                        $modoDestinoEnvio = strtolower(trim((string) ($envioDoc['modo_destino'] ?? 'real')));
                                        $idLoteEnvio = (int) ($envioDoc['id_lote_envio'] ?? 0);
                                        $intentosEnvio = (int) ($envioDoc['intentos'] ?? 0);
                                        $ultimoErrorEnvio = trim((string) ($envioDoc['ultimo_error'] ?? ''));

                                        // Evita duplicar el mismo hito de "primer envío exitoso":
                                        // ya se muestra como evento operativo de emisión.
                                        if ($estadoDest === 2 && $idLoteEmision > 0 && $idLoteEnvio === $idLoteEmision) {
                                            continue;
                                        }

                                        // `updated_at` es consistente con el huso SQL; `enviado_at`
                                        // puede venir con semántica distinta en registros legacy.
                                        $momentoEnvio = (string) ($envioDoc['updated_at'] ?? '');
                                        if ($momentoEnvio === '') {
                                            $momentoEnvio = (string) ($envioDoc['enviado_at'] ?? '');
                                        }
                                        if ($momentoEnvio === '') {
                                            $momentoEnvio = (string) ($envioDoc['programado_para'] ?? '');
                                        }

                                        $tituloEnvio = 'Gestión de envío por correo';
                                        $badgeEnvio = 'text-bg-info';
                                        if ($estadoDest === 2) {
                                            $tituloEnvio = 'Documento enviado por correo';
                                            $badgeEnvio = 'text-bg-success';
                                        } elseif ($estadoDest === 3) {
                                            $tituloEnvio = 'Error al enviar documento';
                                            $badgeEnvio = 'text-bg-danger';
                                        } elseif ($estadoDest === 4) {
                                            $tituloEnvio = 'Envío omitido';
                                            $badgeEnvio = 'text-bg-secondary';
                                        }

                                        $detalleEnvio = 'Lote #' . $idLoteEnvio
                                            . ' | Servicio: ' . ($codigoServicioEnvio !== '' ? $codigoServicioEnvio : '-')
                                            . ' | Estado destinatario: ' . $estadoDestLabel
                                            . ' | Estado lote: ' . $estadoLoteLabel
                                            . ' | Destino: ' . ($correoDestino !== '' ? $correoDestino : '-')
                                            . ' | Modo: ' . ($modoDestinoEnvio === 'demo' ? 'Demo' : 'Real');
                                        if ($intentosEnvio > 0) {
                                            $detalleEnvio .= ' | Intentos: ' . $intentosEnvio;
                                        }
                                        if ($ultimoErrorEnvio !== '') {
                                            $detalleEnvio .= ' | Error: ' . $ultimoErrorEnvio;
                                        }

                                        $pushEventoHistorial(
                                            $historialDocumento,
                                            $momentoEnvio,
                                            $tituloEnvio,
                                            $detalleEnvio,
                                            $badgeEnvio,
                                            'Fecha de sistema',
                                            'sistema'
                                        );
                                    }
                                    foreach ($pagosDocumento as $pagoHist) {
                                        $estadoPagoHist = (int) ($pagoHist['estado_pago'] ?? 0);
                                        $montoPagoHist = round((float) ($pagoHist['monto_pagado'] ?? 0), 2);
                                        $medioPagoHist = trim((string) ($pagoHist['medio_pago'] ?? ''));
                                        $referenciaPagoHist = trim((string) ($pagoHist['referencia_pago'] ?? ''));
                                        $obsPagoHist = trim((string) ($pagoHist['observaciones'] ?? ''));
                                        $aplicaSaldoFavorHist = (int) ($pagoHist['aplica_desde_saldo_favor'] ?? 0) === 1;
                                        $saldoFavorGeneradoHist = round((float) ($pagoHist['monto_saldo_favor_generado'] ?? 0), 2);
                                        $detallePagoHist = 'Monto: ' . formatoMonto($montoPagoHist);
                                        if ($medioPagoHist !== '') {
                                            $detallePagoHist .= ' | Medio: ' . $medioPagoHist;
                                        }
                                        if ($referenciaPagoHist !== '') {
                                            $detallePagoHist .= ' | Ref: ' . $referenciaPagoHist;
                                        }
                                        if ($aplicaSaldoFavorHist) {
                                            $detallePagoHist .= ' | Aplicado desde saldo a favor';
                                        }
                                        if ($saldoFavorGeneradoHist > 0.005) {
                                            $detallePagoHist .= ' | Generó saldo a favor: ' . formatoMonto($saldoFavorGeneradoHist);
                                        }
                                        if ($obsPagoHist !== '') {
                                            $detallePagoHist .= ' | Obs: ' . $obsPagoHist;
                                        }

                                        if ($estadoPagoHist === 1) {
                                            $pushEventoHistorial(
                                                $historialDocumento,
                                                (string) ($pagoHist['fecha_pago'] ?? ''),
                                                'Pago registrado',
                                                $detallePagoHist,
                                                'text-bg-success',
                                                'Fecha operativa',
                                                'operativa'
                                            );
                                        } elseif ($estadoPagoHist === 2) {
                                            $motivoAnulacionHist = trim((string) ($pagoHist['motivo_anulacion'] ?? ''));
                                            if ($motivoAnulacionHist !== '') {
                                                $detallePagoHist .= ' | Motivo anulación: ' . $motivoAnulacionHist;
                                            }
                                            $pushEventoHistorial(
                                                $historialDocumento,
                                                (string) ($pagoHist['fecha_anulacion'] ?? $pagoHist['fecha_pago'] ?? ''),
                                                'Pago anulado',
                                                $detallePagoHist,
                                                'text-bg-danger',
                                                'Fecha operativa',
                                                'operativa'
                                            );
                                        }
                                    }
                                    $cargosHistorialDocumento = $cargosExtraHistorialPorDocumento[$docId] ?? [];
                                    foreach ($cargosHistorialDocumento as $cargoHist) {
                                        if (!is_array($cargoHist)) {
                                            continue;
                                        }
                                        $estadoCargoHist = (int) ($cargoHist['estado_cargo'] ?? 0);
                                        if ($estadoCargoHist !== 5) {
                                            continue;
                                        }

                                        $obsCargoHist = trim((string) ($cargoHist['observaciones'] ?? ''));
                                        $momentoCargoHist = trim((string) ($cargoHist['fecha_registro'] ?? ''));
                                        if (
                                            $obsCargoHist !== ''
                                            && preg_match('/Condonado\s*\[([0-9]{4}-[0-9]{2}-[0-9]{2}\s+[0-9]{2}:[0-9]{2}:[0-9]{2})\]/u', $obsCargoHist, $matchMomento) === 1
                                        ) {
                                            $momentoCargoHist = trim((string) ($matchMomento[1] ?? ''));
                                        }

                                        $tipoCargoHist = trim((string) ($cargoHist['tipo_label'] ?? 'Cargo extra'));
                                        $localCargoHist = trim((string) ($cargoHist['local_code'] ?? ''));
                                        $detalleCargoHist = $tipoCargoHist;
                                        if ($localCargoHist !== '') {
                                            $detalleCargoHist .= ' local ' . $localCargoHist;
                                        }
                                        $detalleCargoHist .= ' | Monto condonado: ' . formatoMonto((float) ($cargoHist['monto'] ?? 0));

                                        $descripcionCargoHist = trim((string) ($cargoHist['descripcion'] ?? ''));
                                        if ($descripcionCargoHist !== '') {
                                            $detalleCargoHist .= ' | ' . $descripcionCargoHist;
                                        }
                                        if ($obsCargoHist !== '') {
                                            $detalleCargoHist .= ' | ' . $obsCargoHist;
                                        }

                                        $pushEventoHistorial(
                                            $historialDocumento,
                                            $momentoCargoHist,
                                            'Deuda condonada',
                                            $detalleCargoHist,
                                            'text-bg-warning text-dark',
                                            'Fecha de sistema',
                                            'sistema'
                                        );
                                    }
                                    usort(
                                        $historialDocumento,
                                        static function (array $a, array $b): int {
                                            $prio = [
                                                'operativa' => 1,
                                                'sistema' => 2,
                                            ];
                                            $pa = $prio[(string) ($a['categoria'] ?? '')] ?? 99;
                                            $pb = $prio[(string) ($b['categoria'] ?? '')] ?? 99;
                                            if ($pa !== $pb) {
                                                return $pa <=> $pb;
                                            }
                                            $cmp = ((int) ($b['sort_ts'] ?? 0)) <=> ((int) ($a['sort_ts'] ?? 0));
                                            if ($cmp !== 0) {
                                                return $cmp;
                                            }
                                            return strcmp((string) ($b['momento'] ?? ''), (string) ($a['momento'] ?? ''));
                                        }
                                    );
                                    if (count($historialDocumento) > 25) {
                                        $historialDocumento = array_slice($historialDocumento, 0, 25);
                                    }
                                    $pdfParams = ['id' => $docId];
                                    if (
                                        $docUuid !== ''
                                        && preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89ABab][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $docUuid) === 1
                                    ) {
                                        $pdfParams = ['uuid' => strtolower($docUuid)];
                                    }
                                    $pdfUrl = msp2BuildSignedUrl(
                                        'documentos_cobro/pdf.php',
                                        $pdfParams,
                                        'documento_cobro_pdf',
                                        600
                                    );
                                    ?>
                                    <article class="card doc-card">
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                                                <div>
                                                    <div class="doc-title">
                                                        Documento <?php echo msp2Escape($docNumero !== '' ? $docNumero : ('#' . $docId)); ?>
                                                    </div>
                                                    <div class="doc-subtitle">
                                                        ID #<?php echo $docId; ?> | Tienda: <?php echo msp2Escape($docTienda !== '' ? $docTienda : ('Tienda #' . $tiendaId)); ?>
                                                    </div>
                                                </div>
                                                <div class="d-flex flex-wrap align-items-center gap-2">
                                                    <span class="badge <?php echo msp2Escape((string) $estado['badge']); ?>">
                                                        <?php echo msp2Escape((string) $estado['label']); ?>
                                                    </span>
                                                    <a
                                                        href="<?php echo msp2Escape($pdfUrl); ?>"
                                                        class="btn btn-outline-secondary btn-sm"
                                                        target="_blank"
                                                        rel="noopener">
                                                        <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
                                                    </a>
                                                    <?php if ($estadoId !== 5): ?>
                                                        <form method="post" action="<?php echo msp2Escape(msp2Url('documentos_cobro/reenviar_individual.php')); ?>" class="d-inline js-reenviar-cobro-form">
                                                            <?php msp2CsrfField(); ?>
                                                            <input type="hidden" name="id_documento_cobro" value="<?php echo $docId; ?>">
                                                            <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
                                                            <button
                                                                type="submit"
                                                                class="btn btn-outline-primary btn-sm"
                                                                title="<?php echo (!$modoCorreoDemoActivo && !$envioArrendatariosHabilitado) ? 'Envío real bloqueado desde Configuración Correos' : 'Reenviar cobro de este documento'; ?>"
                                                                <?php echo (!$modoCorreoDemoActivo && !$envioArrendatariosHabilitado) ? 'disabled' : ''; ?>>
                                                                <i class="bi bi-send me-1" aria-hidden="true"></i>Reenviar cobro
                                                            </button>
                                                        </form>
                                                        <?php if ($cantidadCargosCondonables > 0): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-warning btn-sm js-condonar-cargos-extra"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modalCondonarCargoExtra"
                                                                data-id-documento="<?php echo $docId; ?>"
                                                                data-documento-label="<?php echo msp2Escape('#' . $docId . ' | ' . ($docNumero !== '' ? $docNumero : ('DOC-' . $docId))); ?>"
                                                                data-cantidad-cargos="<?php echo (int) $cantidadCargosCondonables; ?>"
                                                                data-monto-total="<?php echo msp2Escape((string) number_format($montoCargosCondonables, 2, '.', '')); ?>"
                                                                data-cargos="<?php echo msp2Escape($cargosOpcionesDocJson); ?>"
                                                                title="Seleccionar y condonar cargos extra de este documento">
                                                                <i class="bi bi-scissors me-1" aria-hidden="true"></i>Condonar cargos
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($puedePagar): ?>
                                                        <button
                                                            type="button"
                                                            class="btn btn-success btn-sm js-registrar-pago-v2"
                                                            data-id-documento="<?php echo $docId; ?>"
                                                            data-documento-label="<?php echo msp2Escape('#' . $docId . ' | ' . ($docNumero !== '' ? $docNumero : ('DOC-' . $docId))); ?>"
                                                            data-saldo="<?php echo msp2Escape((string) number_format($saldo, 2, '.', '')); ?>"
                                                            data-saldo-favor="<?php echo msp2Escape((string) number_format($saldoFavorTienda, 2, '.', '')); ?>"
                                                            data-tienda-label="<?php echo msp2Escape($docTienda !== '' ? $docTienda : ('Tienda #' . $tiendaId)); ?>"
                                                            data-conceptos="<?php echo msp2Escape($conceptosDocumentoPagoJson); ?>">
                                                            <i class="bi bi-cash-coin me-1" aria-hidden="true"></i>Registrar pago
                                                        </button>
                                                        <?php if ($puedeAplicarSaldoFavor): ?>
                                                            <button
                                                                type="button"
                                                                class="btn btn-outline-success btn-sm js-aplicar-saldo-favor"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#modalAplicarSaldoFavor"
                                                                data-id-documento="<?php echo $docId; ?>"
                                                                data-documento-label="<?php echo msp2Escape('#' . $docId . ' | ' . ($docNumero !== '' ? $docNumero : ('DOC-' . $docId))); ?>"
                                                                data-saldo="<?php echo msp2Escape((string) number_format($saldo, 2, '.', '')); ?>"
                                                                data-saldo-favor="<?php echo msp2Escape((string) number_format($saldoFavorTienda, 2, '.', '')); ?>"
                                                                data-tienda-label="<?php echo msp2Escape($docTienda !== '' ? $docTienda : ('Tienda #' . $tiendaId)); ?>">
                                                                <i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Aplicar saldo
                                                            </button>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <div class="row g-2 mb-3">
                                                <div class="col-12 col-md-3">
                                                    <div class="doc-kpi">
                                                        <div class="label">Monto documento</div>
                                                        <p class="value"><?php echo msp2Escape(formatoMonto($montoTotalDocumento)); ?></p>
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-3">
                                                    <div class="doc-kpi">
                                                        <div class="label">Monto pagado</div>
                                                        <p class="value text-success"><?php echo msp2Escape(formatoMonto($montoPagadoDocumento)); ?></p>
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-3">
                                                    <div class="doc-kpi">
                                                        <div class="label">Saldo pendiente</div>
                                                        <p class="value <?php echo $saldo > 0 ? 'text-danger' : 'text-success'; ?>">
                                                            <?php echo msp2Escape(formatoMonto($saldo)); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="col-12 col-md-3">
                                                    <div class="doc-kpi">
                                                        <div class="label">Abonos registrados</div>
                                                        <p class="value"><?php echo number_format((int) ($doc['cantidad_pagos'] ?? 0), 0, ',', '.'); ?></p>
                                                        <?php if ($saldoFavorTienda > 0): ?>
                                                            <div class="small text-success mt-1">Saldo a favor tienda: <?php echo msp2Escape(formatoMonto($saldoFavorTienda)); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="mb-3">
                                                <div class="d-flex justify-content-between align-items-center small text-muted mb-1">
                                                    <span>Avance de pago</span>
                                                    <span><?php echo msp2Escape(formatoDecimal($porcentajePagado, 1)); ?>%</span>
                                                </div>
                                                <div class="progress" role="progressbar" aria-label="Avance de pago documento" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo msp2Escape((string) $porcentajePagado); ?>">
                                                    <div class="progress-bar <?php echo $saldo > 0 ? 'bg-warning text-dark' : 'bg-success'; ?>" style="width: <?php echo msp2Escape((string) $porcentajePagado); ?>%"></div>
                                                </div>
                                            </div>

                                            <div class="doc-detail-box mb-3">
                                                <h3 class="h6 mb-1">Historial de acciones</h3>
                                                <div class="small text-muted mb-2">Primero se muestran fechas operativas del cobro y luego eventos técnicos de sistema.</div>
                                                <?php if ($historialDocumento === []): ?>
                                                    <div class="text-muted small">No hay acciones registradas para este documento.</div>
                                                <?php else: ?>
                                                    <div class="timeline-scroll">
                                                        <ul class="timeline-list">
                                                        <?php foreach ($historialDocumento as $evento): ?>
                                                            <?php
                                                            $categoriaEvento = (string) ($evento['categoria'] ?? 'sistema');
                                                            $timelineItemClass = $categoriaEvento === 'operativa' ? 'timeline-item-operativa' : 'timeline-item-sistema';
                                                            $timelineIcon = $categoriaEvento === 'operativa' ? 'bi-cash-stack' : 'bi-gear';
                                                            ?>
                                                            <li class="timeline-item <?php echo msp2Escape($timelineItemClass); ?>">
                                                                <span class="timeline-marker">
                                                                    <i class="bi <?php echo msp2Escape($timelineIcon); ?>" aria-hidden="true"></i>
                                                                </span>
                                                                <div class="timeline-content">
                                                                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                                                        <div class="pe-2">
                                                                        <div class="fw-semibold"><?php echo msp2Escape((string) ($evento['titulo'] ?? 'Acción')); ?></div>
                                                                        <div class="small text-muted"><?php echo msp2Escape((string) ($evento['detalle'] ?? '')); ?></div>
                                                                        </div>
                                                                        <div class="text-end">
                                                                            <?php
                                                                            $momentoEventoRaw = trim((string) ($evento['momento'] ?? ''));
                                                                            $momentoEventoLabel = formatoFechaHora($momentoEventoRaw);
                                                                            $momentoEventoHasTime = preg_match('/\d{2}:\d{2}/', str_replace('T', ' ', $momentoEventoRaw)) === 1;
                                                                            ?>
                                                                            <span class="badge <?php echo msp2Escape((string) ($evento['badge'] ?? 'text-bg-secondary')); ?>">
                                                                                <span
                                                                                    class="<?php echo $momentoEventoHasTime ? 'js-evento-fecha-utc' : ''; ?>"
                                                                                    <?php if ($momentoEventoHasTime): ?>
                                                                                    data-utc="<?php echo msp2Escape($momentoEventoRaw); ?>"
                                                                                    data-fallback="<?php echo msp2Escape($momentoEventoLabel); ?>"
                                                                                    <?php endif; ?>
                                                                                >
                                                                                    <?php echo msp2Escape($momentoEventoLabel); ?>
                                                                                </span>
                                                                            </span>
                                                                            <div class="small text-muted mt-1"><?php echo msp2Escape((string) ($evento['contexto_fecha'] ?? '')); ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </li>
                                                        <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <div class="row g-3">
                                                <div class="col-12">
                                                    <div class="doc-detail-box">
                                                        <h3 class="h6 mb-2">Arriendo por local</h3>
                                                        <?php if ($localesDocumento === []): ?>
                                                            <div class="text-muted small">No hay detalle de arriendo para este documento.</div>
                                                        <?php else: ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-striped align-middle mb-0">
                                                                    <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 90px;">Local</th>
                                                                        <th>Descripción</th>
                                                                        <th style="width: 120px;" class="text-end">m2</th>
                                                                        <th style="width: 140px;" class="text-end">Neto</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($localesDocumento as $localDoc): ?>
                                                                        <tr>
                                                                            <td><?php echo msp2Escape((string) ($localDoc['cdo_local'] ?? '')); ?></td>
                                                                            <td><?php echo msp2Escape((string) ($localDoc['desc_local'] ?? '')); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($localDoc['metros_cuadrados'] ?? null, 2)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoMonto($localDoc['monto_neto'] ?? null)); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>

                                                <?php if ($luzDocumento !== []): ?>
                                                    <div class="col-12">
                                                        <div class="doc-detail-box">
                                                            <h3 class="h6 mb-2">Servicios LUZ</h3>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-striped align-middle mb-0">
                                                                    <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 90px;">Local</th>
                                                                        <th style="width: 110px;" class="text-end">Anterior</th>
                                                                        <th style="width: 110px;" class="text-end">Actual</th>
                                                                        <th style="width: 90px;" class="text-end">Consumo</th>
                                                                        <th style="width: 110px;" class="text-end">Valor kWh</th>
                                                                        <th style="width: 130px;" class="text-end">Monto</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($luzDocumento as $luzDoc): ?>
                                                                        <tr>
                                                                            <td><?php echo msp2Escape((string) ($luzDoc['cdo_local'] ?? '')); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($luzDoc['lectura_anterior'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($luzDoc['lectura_actual'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($luzDoc['consumo_cobrado'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($luzDoc['valor_kwh'] ?? null, 2)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoMonto($luzDoc['monto_total'] ?? null)); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($gasDocumento !== []): ?>
                                                    <div class="col-12">
                                                        <div class="doc-detail-box">
                                                            <h3 class="h6 mb-2">Servicios GAS</h3>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-striped align-middle mb-0">
                                                                    <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 90px;">Local</th>
                                                                        <th style="width: 110px;" class="text-end">Anterior</th>
                                                                        <th style="width: 110px;" class="text-end">Actual</th>
                                                                        <th style="width: 90px;" class="text-end">Consumo</th>
                                                                        <th style="width: 110px;" class="text-end">Factor</th>
                                                                        <th style="width: 110px;" class="text-end">Valor litro</th>
                                                                        <th style="width: 130px;" class="text-end">Monto</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($gasDocumento as $gasDoc): ?>
                                                                        <tr>
                                                                            <td><?php echo msp2Escape((string) ($gasDoc['cdo_local'] ?? '')); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($gasDoc['lectura_anterior'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($gasDoc['lectura_actual'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($gasDoc['consumo_cobrado'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($gasDoc['factor'] ?? null, 6)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($gasDoc['valor_litro'] ?? null, 6)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoMonto($gasDoc['monto_total'] ?? null)); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($aguaDocumento !== []): ?>
                                                    <div class="col-12">
                                                        <div class="doc-detail-box">
                                                            <h3 class="h6 mb-2">Servicios AGUA</h3>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-striped align-middle mb-0">
                                                                    <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 90px;">Local</th>
                                                                        <th style="width: 110px;" class="text-end">Anterior</th>
                                                                        <th style="width: 110px;" class="text-end">Actual</th>
                                                                        <th style="width: 90px;" class="text-end">Consumo</th>
                                                                        <th style="width: 130px;" class="text-end">Monto</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($aguaDocumento as $aguaDoc): ?>
                                                                        <tr>
                                                                            <td><?php echo msp2Escape((string) ($aguaDoc['cdo_local'] ?? '')); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($aguaDoc['lectura_anterior'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($aguaDoc['lectura_actual'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoDecimal($aguaDoc['consumo_cobrado'] ?? null, 0)); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoMonto($aguaDoc['monto_total'] ?? null)); ?></td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <div class="col-12">
                                                    <div class="doc-detail-box">
                                                        <h3 class="h6 mb-2">Abonos del documento</h3>
                                                        <?php if ($pagosDocumento === []): ?>
                                                            <div class="text-muted small">No hay abonos registrados para este documento.</div>
                                                        <?php else: ?>
                                                            <div class="table-responsive">
                                                                <table class="table table-sm table-striped align-middle mb-0">
                                                                    <thead class="table-light">
                                                                    <tr>
                                                                        <th style="width: 90px;">Fecha</th>
                                                                        <th style="width: 120px;" class="text-end">Monto</th>
                                                                        <th style="width: 120px;">Situación</th>
                                                                        <th style="width: 140px;">Medio</th>
                                                                        <th>Referencia / Observación</th>
                                                                        <th style="width: 110px;">Acción</th>
                                                                    </tr>
                                                                    </thead>
                                                                    <tbody>
                                                                    <?php foreach ($pagosDocumento as $pago): ?>
                                                                        <?php
                                                                        $estadoPagoRow = $estadoPago[(int) ($pago['estado_pago'] ?? 0)] ?? ['label' => 'Desconocido', 'badge' => 'text-bg-light text-dark'];
                                                                        $pagoId = (int) ($pago['id_pago'] ?? 0);
                                                                        $pagoAplicado = (int) ($pago['estado_pago'] ?? 0) === 1;
                                                                        $detallePago = [];
                                                                        $medioPago = msp2NormalizeText((string) ($pago['medio_pago'] ?? ''));
                                                                        $referenciaPago = msp2NormalizeText((string) ($pago['referencia_pago'] ?? ''));
                                                                        $observacionPago = msp2NormalizeText((string) ($pago['observaciones'] ?? ''));
                                                                        $motivoAnulacion = msp2NormalizeText((string) ($pago['motivo_anulacion'] ?? ''));
                                                                        $saldoFavorGenerado = (float) ($pago['monto_saldo_favor_generado'] ?? 0);
                                                                        $aplicaDesdeSaldoFavor = (int) ($pago['aplica_desde_saldo_favor'] ?? 0) === 1;
                                                                        if ($referenciaPago !== '') {
                                                                            $detallePago[] = 'Ref: ' . $referenciaPago;
                                                                        }
                                                                        if ($observacionPago !== '') {
                                                                            $detallePago[] = $observacionPago;
                                                                        }
                                                                        if ($aplicaDesdeSaldoFavor) {
                                                                            $detallePago[] = 'Aplicado desde saldo a favor tienda';
                                                                        }
                                                                        if ($saldoFavorGenerado > 0) {
                                                                            $detallePago[] = 'Generó saldo a favor: ' . formatoMonto($saldoFavorGenerado);
                                                                        }
                                                                        if (!$pagoAplicado && $motivoAnulacion !== '') {
                                                                            $detallePago[] = 'Motivo anulación: ' . $motivoAnulacion;
                                                                        }
                                                                        ?>
                                                                        <tr>
                                                                            <td><?php echo msp2Escape(formatoFecha((string) ($pago['fecha_pago'] ?? ''))); ?></td>
                                                                            <td class="text-end"><?php echo msp2Escape(formatoMonto($pago['monto_pagado'] ?? null)); ?></td>
                                                                            <td>
                                                                                <span class="badge <?php echo msp2Escape((string) $estadoPagoRow['badge']); ?>">
                                                                                    <?php echo msp2Escape((string) $estadoPagoRow['label']); ?>
                                                                                </span>
                                                                                <?php if (!$pagoAplicado && !empty($pago['fecha_anulacion'])): ?>
                                                                                    <div class="small text-muted mt-1">Anulado: <?php echo msp2Escape(formatoFecha((string) $pago['fecha_anulacion'])); ?></div>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                            <td><?php echo msp2Escape($medioPago !== '' ? $medioPago : '-'); ?></td>
                                                                            <td><?php echo msp2Escape($detallePago !== [] ? implode(' | ', $detallePago) : '-'); ?></td>
                                                                            <td>
                                                                                <?php if ($pagoAplicado): ?>
                                                                                    <button
                                                                                        type="button"
                                                                                        class="btn btn-outline-danger btn-sm js-anular-pago"
                                                                                        data-bs-toggle="modal"
                                                                                        data-bs-target="#modalAnularPago"
                                                                                        data-id="<?php echo $pagoId; ?>"
                                                                                        data-pago-label="<?php echo msp2Escape('Abono del ' . formatoFecha((string) ($pago['fecha_pago'] ?? '')) . ' / ' . formatoMonto($pago['monto_pagado'] ?? null)); ?>">
                                                                                        Anular
                                                                                    </button>
                                                                                <?php else: ?>
                                                                                    <span class="text-muted small">Sin acción</span>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                    <?php endforeach; ?>
                                                                    </tbody>
                                                                </table>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<div class="modal fade" id="modalRegistrarPagoV2" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/guardar.php')); ?>" id="form_registrar_pago_v2"
              style="border-radius:var(--gp-radius-lg,12px);overflow:hidden;">
            <input type="hidden" name="id_documento_cobro" id="v2_id_documento_cobro">
            <input type="hidden" name="detalle_conceptos_json" id="v2_detalle_conceptos_json">
            <input type="hidden" name="monto_pagado" id="v2_monto_pagado">
            <input type="hidden" name="usar_saldo_favor" id="v2_usar_saldo_favor_hidden" value="0">
            <input type="hidden" name="monto_saldo_favor" id="v2_monto_saldo_favor_hidden" value="">
            <input type="hidden" name="enviar_comprobante" id="v2_enviar_comprobante" value="1">
            <input type="hidden" name="demo_email_confirmado" id="v2_demo_email_confirmado" value="">
            <input type="hidden" name="demo_email_override" id="v2_demo_email_override" value="">
            <input type="hidden" name="volver_a" value="documentos_cobro">
            <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">

            <div class="modal-header" style="background:var(--color-surface,#fff);border-bottom:1px solid var(--color-border,#e5e7eb);">
                <div>
                    <h2 class="modal-title fs-5 mb-0 d-flex align-items-center gap-2">Registrar pago</h2>
                    <div class="small text-muted" id="v2_doc_label" style="margin-top:2px;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <div class="modal-body" style="background:var(--color-bg,#f9fafb);">
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
                            <?php foreach (['Transferencia', 'Efectivo', 'Cheque'] as $medioPago): ?>
                                <option value="<?php echo msp2Escape($medioPago); ?>"><?php echo msp2Escape($medioPago); ?></option>
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
                        <tbody id="v2_conceptos_body"></tbody>
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

<div class="modal fade" id="modalAplicarSaldoFavor" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/aplicar_saldo_favor.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Aplicar saldo a favor</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_documento_cobro" id="saldo_favor_id_documento_cobro">
                <input type="hidden" name="volver_a" value="documentos_cobro">
                <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
                <div class="mb-3">
                    <div class="small text-muted">Documento</div>
                    <div class="fw-semibold" id="saldo_favor_documento_label">-</div>
                    <div class="small text-muted" id="saldo_favor_tienda_label">-</div>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label for="saldo_favor_fecha_pago" class="form-label">Fecha aplicación</label>
                        <input type="date" class="form-control" id="saldo_favor_fecha_pago" name="fecha_pago" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="saldo_favor_monto_aplicar" class="form-label">Monto a aplicar</label>
                        <input type="number" class="form-control" id="saldo_favor_monto_aplicar" name="monto_aplicar" min="0.01" step="0.01" required>
                        <div class="form-text">Disponible: <span id="saldo_favor_disponible_label">-</span> | Saldo documento: <span id="saldo_favor_documento_saldo_label">-</span></div>
                    </div>
                </div>
                <div class="mt-3">
                    <label for="saldo_favor_observaciones" class="form-label">Observaciones</label>
                    <textarea class="form-control" id="saldo_favor_observaciones" name="observaciones" rows="2" maxlength="500"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success">Aplicar saldo</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalAnularPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('pagos/anular.php')); ?>">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Anular abono</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id_pago" id="anular_id_pago">
                <input type="hidden" name="volver_a" value="documentos_cobro">
                <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">
                <p class="mb-2">Vas a anular <strong id="anular_pago_label"></strong>.</p>
                <div>
                    <label for="anular_motivo" class="form-label">Motivo de anulación</label>
                    <textarea class="form-control" id="anular_motivo" name="motivo_anulacion" rows="3" maxlength="500" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger">Confirmar anulación</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalCondonarCargoExtra" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content" method="post" action="<?php echo msp2Escape(msp2Url('documentos_cobro/condonar_cargo_extra.php')); ?>" id="formCondonarCargoExtra">
            <div class="modal-header">
                <h2 class="modal-title fs-5">Condonar cargos extra</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <?php msp2CsrfField(); ?>
                <input type="hidden" name="id_documento_cobro" id="condonar_id_documento_cobro">
                <input type="hidden" name="volver_query" value="<?php echo msp2Escape(http_build_query($queryBase)); ?>">

                <div class="mb-2">
                    <div class="small text-muted">Documento</div>
                    <div class="fw-semibold" id="condonar_documento_label">-</div>
                    <div class="small text-muted">Selecciona los cargos a condonar y deja trazabilidad del motivo.</div>
                </div>

                <div class="table-responsive border rounded">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;"></th>
                                <th>Cargo</th>
                                <th class="text-end" style="width: 140px;">Monto</th>
                            </tr>
                        </thead>
                        <tbody id="condonar_cargos_body">
                            <tr>
                                <td colspan="3" class="text-muted small">No hay cargos seleccionables.</td>
                            </tr>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Total a condonar</th>
                                <th class="text-end" id="condonar_total_label">$ 0</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="mt-3">
                    <label for="condonar_motivo" class="form-label">Motivo condonación</label>
                    <textarea class="form-control" id="condonar_motivo" name="motivo_condonacion" rows="2" maxlength="500" required></textarea>
                </div>
                <div class="small text-danger mt-2 d-none" id="condonar_error_label">Debes seleccionar al menos un cargo para condonar.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-warning" id="condonar_submit_btn">Condonar seleccionados</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const hiddenInput = document.getElementById('id_arrendatario');
    const formArrendatario = document.getElementById('form_arrendatario');
    const formPeriodo = document.getElementById('form_periodo');
    const selectPeriodo = document.getElementById('filtroPeriodo');
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
        } catch (error) {
            return false;
        }
    };

    const parseUtcRawDate = (raw) => {
        const value = String(raw || '').trim();
        if (value === '') {
            return null;
        }

        const match = value.match(
            /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,7}))?)?)?(?:\s*(Z|[+\-]\d{2}:\d{2}))?$/i,
        );
        if (!match) {
            const fallback = new Date(value);
            return Number.isNaN(fallback.getTime()) ? null : fallback;
        }

        const [, year, month, day, hourRaw, minuteRaw, secondRaw, fractionalRaw, offsetRaw] = match;
        if (!hourRaw || !minuteRaw) {
            return null;
        }

        const hour = hourRaw.padStart(2, '0');
        const minute = minuteRaw.padStart(2, '0');
        const second = (secondRaw || '00').padStart(2, '0');
        const ms = (fractionalRaw || '').slice(0, 3).padEnd(3, '0');
        const hasExplicitOffset = typeof offsetRaw === 'string' && offsetRaw !== '';

        // Si no hay zona explícita en BD, asumimos hora local (evita doble conversión).
        if (!hasExplicitOffset) {
            const parsedLocal = new Date(
                Number(year),
                Number(month) - 1,
                Number(day),
                Number(hour),
                Number(minute),
                Number(second),
                Number(ms),
            );
            return Number.isNaN(parsedLocal.getTime()) ? null : parsedLocal;
        }

        const offset = offsetRaw.toUpperCase();
        const iso = `${year}-${month}-${day}T${hour}:${minute}:${second}.${ms}${offset}`;
        const parsed = new Date(iso);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    };

    const formatBrowserDateTime = (date) => {
        const formatter = new Intl.DateTimeFormat('es-CL', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });
        const parts = formatter.formatToParts(date);
        const value = {
            day: parts.find((p) => p.type === 'day')?.value || '00',
            month: parts.find((p) => p.type === 'month')?.value || '00',
            year: parts.find((p) => p.type === 'year')?.value || '0000',
            hour: parts.find((p) => p.type === 'hour')?.value || '00',
            minute: parts.find((p) => p.type === 'minute')?.value || '00',
        };
        return `${value.day}-${value.month}-${value.year} ${value.hour}:${value.minute}`;
    };

    document.querySelectorAll('.js-evento-fecha-utc').forEach((node) => {
        const rawUtc = node.getAttribute('data-utc') || '';
        const fallback = node.getAttribute('data-fallback') || node.textContent || '-';
        const parsed = parseUtcRawDate(rawUtc);
        if (!(parsed instanceof Date)) {
            node.textContent = fallback;
            return;
        }
        node.textContent = formatBrowserDateTime(parsed);
    });

    if (hiddenInput instanceof HTMLInputElement && formArrendatario instanceof HTMLFormElement) {
        hiddenInput.addEventListener('change', () => {
            if (hiddenInput.value.trim() !== '') {
                formArrendatario.requestSubmit();
            }
        });
    }

    if (formPeriodo && selectPeriodo) {
        selectPeriodo.addEventListener('change', () => {
            if (selectPeriodo.value !== '') {
                formPeriodo.requestSubmit();
            }
        });
    }

    const formRegistrarPagoV2Global = document.getElementById('form_registrar_pago_v2');
    if (formRegistrarPagoV2Global instanceof HTMLFormElement) {
        limpiarFlagsComprobante(formRegistrarPagoV2Global);
    }
    document.querySelectorAll('.js-reenviar-cobro-form').forEach((form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        form.addEventListener('submit', (event) => {
            if (form.dataset.mailSubmitting === '1') {
                event.preventDefault();
                return;
            }
            showMailSendingOverlay(form);
        });
    });

    (() => {
        const FMT = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const fmtMoney = (v) => '$ ' + FMT.format(Number(v) || 0);
        const parseDot = (v) => {
            const n = parseFloat(String(v || '').replace(/,/g, '.'));
            return Number.isFinite(n) ? Math.round(n * 100) / 100 : 0;
        };

        const formatCLP = (n) => {
            if (!Number.isFinite(n) || n < 0) return '';
            return n.toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        const parseCLP = (str) => {
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
        let v2SaldoDoc = 0;
        let v2SaldoFavorDoc = 0;

        const body = document.getElementById('v2_conceptos_body');
        const totalLabel = document.getElementById('v2_total_label');
        const msgEl = document.getElementById('v2_validacion_msg');
        const footerInfo = document.getElementById('v2_footer_info');
        const submitBtn = document.getElementById('v2_submit_btn');
        const hiddenMonto = document.getElementById('v2_monto_pagado');
        const montoView = document.getElementById('v2_monto_pagado_view');
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
            body.querySelectorAll('.v2-input-monto').forEach((inp) => {
                total = Math.round((total + parseCLP(inp.value)) * 100) / 100;
            });

            if (totalLabel) totalLabel.textContent = fmtMoney(total);

            const rows = Array.from(body.querySelectorAll('tr[data-v2-id]'));
            let excedeSaldo = false;
            let prelacionInvalida = false;
            let hayPendientePrevio = false;
            rows.forEach((row) => {
                const saldo = parseDot(row.dataset.v2Saldo);
                const inp = row.querySelector('.v2-input-monto');
                const monto = parseCLP(inp ? inp.value : '');
                if (hayPendientePrevio && monto > 0.01) {
                    prelacionInvalida = true;
                }
                const chk = row.querySelector('.v2-check-pagar');
                if (chk) {
                    chk.checked = (monto > 0 && Math.abs(monto - saldo) < 0.01);
                }
                const badge = row.querySelector('.v2-saldo-badge');
                if (badge) {
                    const restante = Math.round((saldo - monto) * 100) / 100;
                    badge.textContent = restante > 0.005 ? fmtMoney(restante) + ' restante' : '✓ pagado';
                    badge.className = 'badge ' + (restante > 0.005 ? 'text-bg-secondary' : 'text-bg-success') + ' v2-saldo-badge';
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
            if (hiddenMonto) hiddenMonto.value = ok ? montoPagado.toFixed(2) : '';
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
            const inp = row.querySelector('.v2-input-monto');
            if (inp) {
                inp.value = saldo > 0 ? formatCLP(saldo) : '';
            }
            recalcular();
        };

        const limpiarTodo = (row) => {
            const inp = row.querySelector('.v2-input-monto');
            if (inp) {
                inp.value = '';
            }
            recalcular();
        };

        const conceptoIcon = (codigo) => {
            switch (codigo) {
                case 'ARRIENDO': return 'bi-house-door-fill';
                case 'SERVICIO_LUZ': return 'bi-lightning-charge-fill';
                case 'SERVICIO_GAS': return 'bi-fire';
                case 'SERVICIO_AGUA': return 'bi-droplet-fill';
                case 'MULTA': return 'bi-exclamation-triangle-fill';
                case 'DANO': return 'bi-tools';
                case 'AJUSTE': return 'bi-sliders';
                default: return 'bi-tag-fill';
            }
        };

        const conceptoColor = (codigo) => {
            switch (codigo) {
                case 'ARRIENDO': return '#4f46e5';
                case 'SERVICIO_LUZ': return '#d97706';
                case 'SERVICIO_GAS': return '#dc2626';
                case 'SERVICIO_AGUA': return '#2563eb';
                case 'MULTA': return '#ea580c';
                case 'DANO': return '#7c3aed';
                default: return '#6b7280';
            }
        };

        const autoDistribute = () => {
            if (!body) return;
            const montoPagado = getMontoPagado();
            if (montoPagado <= 0) {
                body.querySelectorAll('.v2-input-monto').forEach((inp) => { inp.value = ''; });
                recalcular();
                return;
            }
            const objetivo = Math.min(montoPagado, v2SaldoDoc > 0 ? v2SaldoDoc : montoPagado);
            let restante = Math.round(objetivo * 100) / 100;
            body.querySelectorAll('tr[data-v2-id]').forEach((row) => {
                const saldo = parseDot(row.dataset.v2Saldo);
                const inp = row.querySelector('.v2-input-monto');
                if (!inp) return;
                const aAplicar = Math.round(Math.min(restante, saldo) * 100) / 100;
                inp.value = aAplicar > 0 ? formatCLP(aAplicar) : '';
                restante = Math.round((restante - aAplicar) * 100) / 100;
            });
            recalcular();
        };

        const formatMontoInput = (e) => {
            const inp = e.target;
            const oldVal = inp.value;
            const sel = inp.selectionStart ?? oldVal.length;
            const sigAntes = oldVal.slice(0, sel).replace(/\./g, '').length;

            let raw = oldVal.replace(/[^\d,]/g, '');
            const ci = raw.indexOf(',');
            if (ci !== -1) {
                const dec = raw.slice(ci + 1).replace(/,/g, '').slice(0, 2);
                raw = raw.slice(0, ci + 1) + dec;
            }

            const hasComma = raw.includes(',');
            const partes = raw.split(',');
            const intRaw = partes[0] ?? '';
            const decRaw = partes[1] ?? '';
            const intNum = intRaw === '' ? '' : Number(intRaw);
            const intFmt = intRaw === '' ? '' : (Number.isFinite(intNum) ? intNum.toLocaleString('es-CL') : intRaw);
            const newVal = intFmt + (hasComma ? ',' + decRaw : '');

            if (newVal !== oldVal) {
                inp.value = newVal;
                let count = 0;
                let newPos = newVal.length;
                for (let i = 0; i < newVal.length; i++) {
                    if (newVal[i] !== '.') count++;
                    if (count === sigAntes) {
                        newPos = i + 1;
                        break;
                    }
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

            body.innerHTML = conceptos.map((c) => {
                const id = Number(c.id_tipo_item_documento || 0);
                const codigo = String(c.codigo_item || '');
                const nombre = String(c.nombre_item || 'Concepto');
                const saldo = parseDot(c.saldo || 0);
                const icon = conceptoIcon(codigo);
                const color = conceptoColor(codigo);
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

            body.querySelectorAll('.v2-input-monto').forEach((inp) => {
                inp.addEventListener('input', formatMontoInput);
            });

            recalcular();
        };

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

        const toggleObs = document.getElementById('v2_toggle_obs');
        const obsChevron = document.getElementById('v2_obs_chevron');
        if (toggleObs) {
            toggleObs.addEventListener('click', () => {
                const col = document.getElementById('v2_obs_collapse');
                if (!col) return;
                const bsCollapse = bootstrap.Collapse.getOrCreateInstance(col);
                bsCollapse.toggle();
                if (obsChevron) {
                    obsChevron.classList.toggle('bi-chevron-right');
                    obsChevron.classList.toggle('bi-chevron-down');
                }
            });
        }

        document.querySelectorAll('.js-registrar-pago-v2').forEach((btn) => {
            btn.addEventListener('click', () => {
                const idDoc = btn.dataset.idDocumento || '';
                const label = btn.dataset.documentoLabel || '-';
                const saldo = parseDot(btn.dataset.saldo || '0');
                const saldoFavor = parseDot(btn.dataset.saldoFavor || '0');
                const rawJson = btn.dataset.conceptos || '[]';

                const idInput = document.getElementById('v2_id_documento_cobro');
                const docLabel = document.getElementById('v2_doc_label');
                const fechaInp = document.getElementById('v2_fecha_pago');
                const medioInp = document.getElementById('v2_medio_pago');
                const refInp = document.getElementById('v2_referencia');
                const obsInp = document.getElementById('v2_observaciones');

                if (idInput) idInput.value = idDoc;
                if (docLabel) docLabel.textContent = label;
                if (fechaInp) fechaInp.value = '<?php echo date('Y-m-d'); ?>';
                if (medioInp) medioInp.value = '';
                if (refInp) refInp.value = '';
                if (numeroChequeInp instanceof HTMLInputElement) numeroChequeInp.value = '';
                if (bancoPickerReady) {
                    limpiarBancoCheque();
                } else if (bancoChequeInp instanceof HTMLInputElement) {
                    bancoChequeInp.value = '';
                }
                if (obsInp) obsInp.value = '';
                syncChequeFields();
                if (montoView) montoView.value = saldo > 0 ? formatCLP(roundPayableUp(saldo)) : '';
                v2SaldoDoc = saldo;
                v2SaldoFavorDoc = saldoFavor;
                if (saldoFavorWrap) {
                    const mostrar = saldoFavor > 0.005;
                    saldoFavorWrap.classList.toggle('d-none', !mostrar);
                }
                if (saldoFavorLabel) saldoFavorLabel.textContent = fmtMoney(saldoFavor);
                if (saldoFavorCheck) saldoFavorCheck.checked = false;
                if (saldoFavorRow) saldoFavorRow.classList.add('d-none');
                if (saldoFavorInput) saldoFavorInput.value = '';
                const formV2Local = document.getElementById('form_registrar_pago_v2');
                if (formV2Local instanceof HTMLFormElement) {
                    limpiarFlagsComprobante(formV2Local);
                }

                let conceptos = [];
                try {
                    const parsed = JSON.parse(rawJson);
                    if (Array.isArray(parsed)) {
                        conceptos = parsed;
                    }
                } catch (_) {
                }
                renderConceptosV2(conceptos);
                autoDistribute();

                const modalEl = document.getElementById('modalRegistrarPagoV2');
                const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
                modalEl.addEventListener('shown.bs.modal', () => {
                    if (montoView instanceof HTMLInputElement) {
                        montoView.focus();
                        montoView.select();
                    }
                }, { once: true });
                bsModal.show();
            });
        });

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

                const rows = Array.from(document.querySelectorAll('#v2_conceptos_body tr[data-v2-id]'));
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

                rows.forEach((row) => {
                    const id = Number(row.dataset.v2Id);
                    const saldo = parseDot(row.dataset.v2Saldo);
                    const inp = row.querySelector('.v2-input-monto');
                    const monto = parseCLP(inp ? inp.value : 0);
                    if (hayPendientePrevio && monto > 0.01) {
                        prelacionInvalida = true;
                    }
                    const pendiente = Math.round((saldo - monto) * 100) / 100;
                    if (pendiente > 0.01) {
                        hayPendientePrevio = true;
                    }
                    if (monto <= 0) return;
                    if (monto > saldo + 0.01) {
                        invalido = true;
                        return;
                    }
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
                if (hiddenMonto) hiddenMonto.value = montoPagado.toFixed(2);

                if (formV2.dataset.confirmacionComprobante !== '1') {
                    const modalMostrado = solicitarConfirmacionComprobante(formV2);
                    if (modalMostrado) {
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

    document.querySelectorAll('.js-aplicar-saldo-favor').forEach((button) => {
        button.addEventListener('click', () => {
            const idDocumento = button.dataset.idDocumento || '';
            const documentoLabel = button.dataset.documentoLabel || '-';
            const saldo = button.dataset.saldo || '';
            const saldoFavor = button.dataset.saldoFavor || '';
            const tiendaLabel = button.dataset.tiendaLabel || '-';

            const idInput = document.getElementById('saldo_favor_id_documento_cobro');
            const documento = document.getElementById('saldo_favor_documento_label');
            const tienda = document.getElementById('saldo_favor_tienda_label');
            const disponible = document.getElementById('saldo_favor_disponible_label');
            const saldoDocumento = document.getElementById('saldo_favor_documento_saldo_label');
            const montoInput = document.getElementById('saldo_favor_monto_aplicar');
            const observaciones = document.getElementById('saldo_favor_observaciones');
            const fechaInput = document.getElementById('saldo_favor_fecha_pago');

            const saldoValue = saldo === '' ? 0 : Number(saldo);
            const saldoFavorValue = saldoFavor === '' ? 0 : Number(saldoFavor);
            const montoSugerido = Math.min(saldoValue, saldoFavorValue);
            const montoFormateado = (value) => `$ ${Number(value).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

            if (idInput) idInput.value = idDocumento;
            if (documento) documento.textContent = documentoLabel;
            if (tienda) tienda.textContent = `Tienda: ${tiendaLabel}`;
            if (disponible) disponible.textContent = montoFormateado(saldoFavorValue);
            if (saldoDocumento) saldoDocumento.textContent = montoFormateado(saldoValue);
            if (montoInput) montoInput.value = montoSugerido > 0 ? montoSugerido.toFixed(2) : '';
            if (observaciones) observaciones.value = '';
            if (fechaInput) fechaInput.value = '<?php echo date('Y-m-d'); ?>';
        });
    });

    document.querySelectorAll('.js-anular-pago').forEach((button) => {
        button.addEventListener('click', () => {
            const idInput = document.getElementById('anular_id_pago');
            const label = document.getElementById('anular_pago_label');
            const motivo = document.getElementById('anular_motivo');

            if (idInput) idInput.value = button.dataset.id || '';
            if (label) label.textContent = button.dataset.pagoLabel || '';
            if (motivo) motivo.value = '';
        });
    });

    (() => {
        const modalCondonar = document.getElementById('modalCondonarCargoExtra');
        const formCondonar = document.getElementById('formCondonarCargoExtra');
        const idDocumentoInput = document.getElementById('condonar_id_documento_cobro');
        const documentoLabel = document.getElementById('condonar_documento_label');
        const cargosBody = document.getElementById('condonar_cargos_body');
        const totalLabel = document.getElementById('condonar_total_label');
        const motivoInput = document.getElementById('condonar_motivo');
        const errorLabel = document.getElementById('condonar_error_label');

        const fmtMoney = (value) => `$ ${Number(value || 0).toLocaleString('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        const updateTotal = () => {
            if (!(cargosBody instanceof HTMLElement) || !(totalLabel instanceof HTMLElement)) {
                return;
            }
            let total = 0;
            cargosBody.querySelectorAll('input.js-condonar-cargo-check').forEach((input) => {
                if (!(input instanceof HTMLInputElement) || !input.checked) {
                    return;
                }
                total += Number(input.dataset.monto || 0);
            });
            totalLabel.textContent = fmtMoney(total);
        };

        document.querySelectorAll('.js-condonar-cargos-extra').forEach((button) => {
            button.addEventListener('click', () => {
                if (!(cargosBody instanceof HTMLElement) || !(idDocumentoInput instanceof HTMLInputElement)) {
                    return;
                }
                const docId = String(button.dataset.idDocumento || '').trim();
                const docLabel = String(button.dataset.documentoLabel || '-').trim();
                const cargosRaw = String(button.dataset.cargos || '[]');
                let cargos = [];
                try {
                    const parsed = JSON.parse(cargosRaw);
                    if (Array.isArray(parsed)) {
                        cargos = parsed;
                    }
                } catch (error) {
                    cargos = [];
                }

                idDocumentoInput.value = docId;
                if (documentoLabel instanceof HTMLElement) {
                    documentoLabel.textContent = docLabel !== '' ? docLabel : '-';
                }
                if (motivoInput instanceof HTMLTextAreaElement) {
                    motivoInput.value = '';
                }
                if (errorLabel instanceof HTMLElement) {
                    errorLabel.classList.add('d-none');
                }

                const rows = [];
                cargos.forEach((cargo, idx) => {
                    const idCargo = Number(cargo?.id_cargo_salida || 0);
                    const monto = Number(cargo?.monto || 0);
                    const resumen = String(cargo?.resumen || '').trim();
                    if (idCargo <= 0 || monto <= 0) {
                        return;
                    }
                    const inputId = `condonar_cargo_${idCargo}_${idx}`;
                    rows.push(
                        `<tr>
                            <td class="text-center">
                                <input class="form-check-input js-condonar-cargo-check" type="checkbox" id="${inputId}" name="ids_cargo_salida[]" value="${idCargo}" data-monto="${monto.toFixed(2)}" checked>
                            </td>
                            <td><label class="mb-0" for="${inputId}">${resumen !== '' ? resumen : ('Cargo #' + idCargo)}</label></td>
                            <td class="text-end">${fmtMoney(monto)}</td>
                        </tr>`
                    );
                });

                cargosBody.innerHTML = rows.length > 0
                    ? rows.join('')
                    : '<tr><td colspan="3" class="text-muted small">No hay cargos seleccionables.</td></tr>';
                cargosBody.querySelectorAll('input.js-condonar-cargo-check').forEach((input) => {
                    input.addEventListener('change', updateTotal);
                });
                updateTotal();
            });
        });

        if (formCondonar instanceof HTMLFormElement) {
            formCondonar.addEventListener('submit', (event) => {
                if (!(cargosBody instanceof HTMLElement)) {
                    return;
                }
                const selected = cargosBody.querySelectorAll('input.js-condonar-cargo-check:checked').length;
                if (selected > 0) {
                    return;
                }
                event.preventDefault();
                if (errorLabel instanceof HTMLElement) {
                    errorLabel.classList.remove('d-none');
                }
            });
        }

        if (modalCondonar instanceof HTMLElement) {
            modalCondonar.addEventListener('hidden.bs.modal', () => {
                if (errorLabel instanceof HTMLElement) {
                    errorLabel.classList.add('d-none');
                }
            });
        }
    })();
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
