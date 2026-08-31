<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/templates/components/searchable_select.php';

msp2RequireAccess();

/*
 * Punto de compatibilidad legacy.
 * La operación individual ya no debe recalcular documentos por tienda/período:
 * ese flujo fue absorbido por Correcciones Selectivas, cuyo ámbito es contractual
 * y conserva las validaciones de pagos, garantías y contabilidad.
 */
$legacyContrato = filter_input(INPUT_GET, 'id_contrato_arriendo', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$legacyTienda = filter_input(INPUT_GET, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (($legacyContrato === false || $legacyContrato === null) && $legacyTienda !== false && $legacyTienda !== null) {
    $legacyStmt = $conn->prepare(
        'SELECT TOP (1) id_contrato_arriendo
         FROM dbo.msp_contratos_arriendo
         WHERE id_tienda = :id_tienda
           AND estado_contrato IN (1,2,3)
         ORDER BY CASE WHEN estado_contrato = 2 THEN 0 WHEN estado_contrato = 3 THEN 1 ELSE 2 END,
                  fecha_inicio DESC, id_contrato_arriendo DESC'
    );
    $legacyStmt->execute([':id_tienda' => (int) $legacyTienda]);
    $legacyContrato = (int) ($legacyStmt->fetchColumn() ?: 0);
}
$legacyQuery = $legacyContrato !== false && $legacyContrato !== null && (int) $legacyContrato > 0
    ? '?id_contrato_arriendo=' . (int) $legacyContrato
    : '';
msp2SetFlash('info', 'La operación individual fue integrada a Correcciones Selectivas. Revisa el contrato antes de corregir.');
msp2Redirect('correcciones/index.php' . $legacyQuery);

$flash = msp2PullFlash();
$loadError = null;
$tablaExiste = false;

$serviceCodes = ['LUZ', 'GAS', 'AGUA'];
$estadoDocumento = [
    1 => 'Borrador',
    2 => 'Emitido',
    3 => 'Pagado parcial',
    4 => 'Pagado',
    5 => 'Anulado',
];

function oiParseMonthToFirstDay(string $periodoYm): ?string
{
    if (!preg_match('/^\d{4}-\d{2}$/', $periodoYm)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m', $periodoYm);
    if ($d === false || $d->format('Y-m') !== $periodoYm) {
        return null;
    }

    return $d->format('Y-m-01');
}

function oiRedirect(string $periodoYm = '', string $servicio = '', int $idTienda = 0): never
{
    $params = [];
    if ($periodoYm !== '') {
        $params['periodo'] = $periodoYm;
    }
    if ($servicio !== '') {
        $params['servicio'] = $servicio;
    }
    if ($idTienda > 0) {
        $params['id_tienda'] = (string) $idTienda;
    }

    $url = 'cobros/operacion_individual.php';
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }
    msp2Redirect($url);
}

function oiDecimal(string $value): ?string
{
    $raw = trim(str_replace(',', '.', $value));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return number_format((float) $raw, 4, '.', '');
}

function oiGenerarServicioTienda(
    PDO $conn,
    int $idCierre,
    string $periodoFacturacion,
    int $idTienda,
    string $servicio,
    int $dias
): array {
    $stmt = $conn->prepare(
        "DECLARE @id_cierre INT = :id_cierre;
         DECLARE @periodo DATE = :periodo;
         DECLARE @id_tienda INT = :id_tienda;
         DECLARE @servicio NVARCHAR(10) = :servicio;
         DECLARE @dias INT = :dias;
         DECLARE @tasa_iva DECIMAL(9,6) = 0.19;
         DECLARE @valor_uf DECIMAL(18,6);
         DECLARE @id_doc INT = NULL;
         DECLARE @subtotal_arriendo DECIMAL(18,2) = 0;
         DECLARE @subtotal_servicios DECIMAL(18,2) = 0;
         DECLARE @monto_total DECIMAL(18,2) = 0;
         DECLARE @cobros_afectados INT = 0;
         DECLARE @doc_generado INT = 0;
         DECLARE @items_generados INT = 0;
         DECLARE @id_item_arriendo INT;
         DECLARE @id_item_agua INT;
         DECLARE @id_item_luz INT;
         DECLARE @id_item_gas INT;

         SET XACT_ABORT ON;
         BEGIN TRY
            BEGIN TRANSACTION;

            SELECT @valor_uf = valor_uf
            FROM dbo.msp_cierre_mensual
            WHERE id_cierre_mensual = @id_cierre
              AND periodo_facturacion = @periodo;

            IF @valor_uf IS NULL
                ;THROW 50091, 'No existe cierre válido para el período indicado.', 1;

            IF OBJECT_ID(N'dbo.msp_generar_snapshot_arriendo_periodo', N'P') IS NOT NULL
            BEGIN
                DECLARE @snapshot_out TABLE (
                    periodo_facturacion DATE NULL,
                    snapshots_upsertados INT NULL,
                    snapshots_congelados INT NULL
                );
                INSERT INTO @snapshot_out (
                    periodo_facturacion,
                    snapshots_upsertados,
                    snapshots_congelados
                )
                EXEC dbo.msp_generar_snapshot_arriendo_periodo
                    @id_cierre_mensual = @id_cierre,
                    @reemplazar = 0,
                    @congelar = 1,
                    @target_tiendas_csv = CONVERT(NVARCHAR(20), @id_tienda);

                IF EXISTS (
                    SELECT 1
                    FROM dbo.msp_arriendo_local_snapshot_periodo s
                    WHERE s.periodo_facturacion = @periodo
                      AND s.id_tienda = @id_tienda
                      AND s.estado_snapshot IN (1,2,3)
                      AND s.id_modalidad_aplicada = 2
                      AND s.detalle_calculo LIKE N'Modalidad DINAMICO_MENSUAL sin valor_periodo cargado%'
                )
                BEGIN
                    ;THROW 50097, 'Hay locales con modalidad DINAMICO_MENSUAL sin valor cargado para el período. Completa el arriendo dinámico y reintenta.', 1;
                END;
            END;

            ;WITH base AS (
                SELECT
                    lm.id_lectura,
                    ts.codigo_servicio,
                    COALESCE(
                        lm.consumo_informado,
                        CASE
                            WHEN lm.lectura_actual >= ISNULL(lm.lectura_anterior, 0)
                                THEN lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
                            ELSE 0
                        END
                    ) AS consumo_cobrado,
                    pl.valor_kwh,
                    pg.factor,
                    pg.valor_litro,
                    pa.servicio_agua_potable,
                    pa.servicio_alcantarillado,
                    pa.tratamiento_aguas_servidas,
                    pa.sobreconsumo,
                    pa.interes_pf_plazo,
                    pa.divisor,
                    pa.cargo_fijo
                FROM dbo.msp_lecturas_medidores lm
                INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = p.id_tipo_servicio
                INNER JOIN dbo.msp_medidores m
                    ON m.id_medidor = lm.id_medidor
                INNER JOIN dbo.msp_contrato_locales cl
                    ON cl.id_local = m.id_local
                   AND cl.estado_relacion = 1
                   AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                   AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                   AND ca.id_tienda = @id_tienda
                   AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                   AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                   AND ca.estado_contrato IN (1,2,3)
                LEFT JOIN dbo.msp_proceso_cobro_luz pl
                    ON pl.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN dbo.msp_proceso_cobro_gas pg
                    ON pg.id_proceso_cobro = p.id_proceso_cobro
                LEFT JOIN dbo.msp_proceso_cobro_agua pa
                    ON pa.id_proceso_cobro = p.id_proceso_cobro
                WHERE p.id_cierre_mensual = @id_cierre
                  AND p.estado_proceso <> 4
                  AND UPPER(ts.codigo_servicio) = @servicio
            ),
            calculo AS (
                SELECT
                    b.id_lectura,
                    CAST(b.consumo_cobrado AS DECIMAL(18,4)) AS consumo_cobrado,
                    CAST(ROUND(CASE
                        WHEN b.codigo_servicio = N'LUZ' THEN b.consumo_cobrado * ISNULL(b.valor_kwh, 0)
                        WHEN b.codigo_servicio = N'GAS' THEN b.consumo_cobrado * ISNULL(b.factor, 0) * ISNULL(b.valor_litro, 0)
                        WHEN b.codigo_servicio = N'AGUA' THEN b.consumo_cobrado * (
                            (ISNULL(b.servicio_agua_potable, 0)
                            + ISNULL(b.servicio_alcantarillado, 0)
                            + ISNULL(b.tratamiento_aguas_servidas, 0)
                            + ISNULL(b.sobreconsumo, 0)
                            + ISNULL(b.interes_pf_plazo, 0)) / NULLIF(b.divisor, 0)
                        )
                        ELSE 0
                    END, 2) AS DECIMAL(18,2)) AS subtotal_variable,
                    CAST(ROUND(CASE
                        WHEN b.codigo_servicio = N'AGUA' THEN ISNULL(b.cargo_fijo, 0) / NULLIF(b.divisor, 0)
                        ELSE 0
                    END, 2) AS DECIMAL(18,2)) AS cargo_fijo,
                    CASE
                        WHEN b.codigo_servicio = N'LUZ' THEN N'LUZ individual'
                        WHEN b.codigo_servicio = N'GAS' THEN N'GAS individual'
                        WHEN b.codigo_servicio = N'AGUA' THEN N'AGUA individual'
                        ELSE N'-'
                    END AS detalle_calculo,
                    CASE
                        WHEN b.codigo_servicio = N'LUZ' THEN CONCAT(N'{\"servicio\":\"LUZ\",\"valor_kwh\":', CONVERT(NVARCHAR(50), ISNULL(b.valor_kwh, 0)), N'}')
                        WHEN b.codigo_servicio = N'GAS' THEN CONCAT(N'{\"servicio\":\"GAS\",\"factor\":', CONVERT(NVARCHAR(50), ISNULL(b.factor, 0)), N',\"valor_litro\":', CONVERT(NVARCHAR(50), ISNULL(b.valor_litro, 0)), N'}')
                        WHEN b.codigo_servicio = N'AGUA' THEN CONCAT(N'{\"servicio\":\"AGUA\",\"divisor\":', CONVERT(NVARCHAR(50), ISNULL(b.divisor, 0)), N'}')
                        ELSE N'{}'
                    END AS parametros_snapshot
                FROM base b
            )
            MERGE dbo.msp_cobros_servicios AS target
            USING calculo AS source
               ON target.id_lectura = source.id_lectura
            WHEN MATCHED THEN
                UPDATE SET
                    consumo_cobrado = source.consumo_cobrado,
                    subtotal_variable = source.subtotal_variable,
                    cargo_fijo = source.cargo_fijo,
                    monto_total = source.subtotal_variable + source.cargo_fijo,
                    formula_version = N'v1',
                    parametros_snapshot = source.parametros_snapshot,
                    detalle_calculo = source.detalle_calculo,
                    fecha_calculo = SYSDATETIME()
            WHEN NOT MATCHED BY TARGET THEN
                INSERT (
                    id_lectura, consumo_cobrado, subtotal_variable, cargo_fijo, monto_total,
                    formula_version, parametros_snapshot, detalle_calculo
                )
                VALUES (
                    source.id_lectura, source.consumo_cobrado, source.subtotal_variable, source.cargo_fijo, source.subtotal_variable + source.cargo_fijo,
                    N'v1', source.parametros_snapshot, source.detalle_calculo
                );

            SET @cobros_afectados = @@ROWCOUNT;

            SELECT @id_doc = dc.id_documento_cobro
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.id_tienda = @id_tienda
              AND dc.periodo_facturacion = @periodo;

            IF @id_doc IS NOT NULL
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM dbo.msp_pagos p
                    WHERE p.id_documento_cobro = @id_doc
                      AND p.estado_pago = 1
                )
                BEGIN
                    ;THROW 50092, 'El documento ya tiene pagos aplicados. No se puede regenerar desde operación individual.', 1;
                END;
            END;

            SELECT @subtotal_arriendo = ROUND(ISNULL(SUM(s.monto_neto_clp), 0), 2)
            FROM dbo.msp_arriendo_local_snapshot_periodo s
            WHERE s.periodo_facturacion = @periodo
              AND s.id_tienda = @id_tienda
              AND s.estado_snapshot IN (1,2,3);

            SELECT @subtotal_servicios = ROUND(ISNULL(SUM(cs.monto_total), 0), 2)
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
            INNER JOIN dbo.msp_medidores m
                ON m.id_medidor = lm.id_medidor
            INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_local = m.id_local
               AND cl.estado_relacion = 1
               AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
               AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
               AND ca.id_tienda = @id_tienda
               AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
               AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
               AND ca.estado_contrato IN (1,2,3)
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4;

            SET @monto_total = ROUND((@subtotal_arriendo * (1 + @tasa_iva)) + @subtotal_servicios, 2);

            IF @monto_total <= 0
            BEGIN
                IF @id_doc IS NOT NULL
                BEGIN
                    DELETE FROM dbo.msp_documentos_cobro_detalle WHERE id_documento_cobro = @id_doc;
                    DELETE FROM dbo.msp_documentos_cobro WHERE id_documento_cobro = @id_doc;
                END;
                COMMIT TRANSACTION;
                SELECT @cobros_afectados AS cobros_afectados, 0 AS documento_generado, 0 AS items_generados;
                RETURN;
            END;

            IF @id_doc IS NULL
            BEGIN
                INSERT INTO dbo.msp_documentos_cobro (
                    id_tienda, periodo_facturacion, numero_documento, fecha_emision, fecha_vencimiento,
                    rut_arrendatario_snapshot, nombre_arrendatario_snapshot, nombre_tienda_snapshot,
                    subtotal_arriendo, subtotal_servicios, monto_total, saldo_pendiente, estado_documento, observaciones
                )
                SELECT
                    t.id_tienda, @periodo, CONCAT(CONVERT(CHAR(6), @periodo, 112), N'-', t.id_tienda),
                    CONVERT(date, SYSDATETIME()), DATEADD(DAY, @dias, CONVERT(date, SYSDATETIME())),
                    a.rut, COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), a.rut),
                    t.nombre_comercial,
                    @subtotal_arriendo, @subtotal_servicios, @monto_total, @monto_total, 2,
                    N'Documento regenerado desde operación individual.'
                FROM dbo.msp_tiendas t
                INNER JOIN dbo.msp_arrendatarios a
                    ON a.id_arrendatario = t.id_arrendatario
                WHERE t.id_tienda = @id_tienda;
                SET @id_doc = SCOPE_IDENTITY();
                SET @doc_generado = 1;
            END
            ELSE
            BEGIN
                UPDATE dbo.msp_documentos_cobro
                SET
                    fecha_vencimiento = DATEADD(DAY, @dias, CONVERT(date, SYSDATETIME())),
                    subtotal_arriendo = @subtotal_arriendo,
                    subtotal_servicios = @subtotal_servicios,
                    monto_total = @monto_total,
                    saldo_pendiente = @monto_total,
                    estado_documento = 2
                WHERE id_documento_cobro = @id_doc;
                SET @doc_generado = 1;
            END;

            DELETE FROM dbo.msp_documentos_cobro_detalle WHERE id_documento_cobro = @id_doc;

            SELECT @id_item_arriendo = id_tipo_item_documento FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'ARRIENDO';
            SELECT @id_item_agua = id_tipo_item_documento FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_AGUA';
            SELECT @id_item_luz = id_tipo_item_documento FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_LUZ';
            SELECT @id_item_gas = id_tipo_item_documento FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_GAS';

            INSERT INTO dbo.msp_documentos_cobro_detalle (
                id_documento_cobro, orden_item, id_tipo_item_documento, descripcion_item, cantidad, valor_unitario, subtotal, id_cobro_servicio
            )
            SELECT
                @id_doc,
                ROW_NUMBER() OVER (ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", s.id_contrato_local),
                @id_item_arriendo,
                CONCAT(N'Arriendo local ', loc.cdo_local),
                1,
                CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2)),
                CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2)),
                NULL
            FROM dbo.msp_arriendo_local_snapshot_periodo s
            INNER JOIN dbo.msp_locales loc
                ON loc.id_local = s.id_local
            WHERE s.periodo_facturacion = @periodo
              AND s.id_tienda = @id_tienda
              AND s.estado_snapshot IN (1,2,3);

            SET @items_generados = @items_generados + @@ROWCOUNT;

            INSERT INTO dbo.msp_documentos_cobro_detalle (
                id_documento_cobro, orden_item, id_tipo_item_documento, descripcion_item, cantidad, valor_unitario, subtotal, id_cobro_servicio
            )
            SELECT
                @id_doc,
                1000 + ROW_NUMBER() OVER (ORDER BY ts.codigo_servicio, " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor),
                CASE ts.codigo_servicio
                    WHEN N'AGUA' THEN @id_item_agua
                    WHEN N'LUZ'  THEN @id_item_luz
                    WHEN N'GAS'  THEN @id_item_gas
                    ELSE @id_item_gas
                END,
                CONCAT(ts.nombre_servicio, N' local ', loc.cdo_local, N' medidor ', m.codigo_medidor),
                CASE WHEN cs.consumo_cobrado > 0 THEN cs.consumo_cobrado ELSE 1 END,
                CASE WHEN cs.consumo_cobrado > 0 THEN ROUND(cs.monto_total / cs.consumo_cobrado, 2) ELSE cs.monto_total END,
                cs.monto_total,
                cs.id_cobro_servicio
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
                ON cl.id_local = m.id_local
               AND cl.estado_relacion = 1
               AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
               AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
               AND ca.id_tienda = @id_tienda
               AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
               AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
               AND ca.estado_contrato IN (1,2,3)
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4;

            SET @items_generados = @items_generados + @@ROWCOUNT;

            COMMIT TRANSACTION;
            SELECT @cobros_afectados AS cobros_afectados, @doc_generado AS documento_generado, @items_generados AS items_generados;
         END TRY
         BEGIN CATCH
            IF XACT_STATE() <> 0
                ROLLBACK TRANSACTION;
            THROW;
         END CATCH;"
    );
    $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
    $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
    $stmt->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
    $stmt->bindValue(':servicio', $servicio, PDO::PARAM_STR);
    $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$periodoYm = trim((string) ($_GET['periodo'] ?? ''));
$servicio = strtoupper(trim((string) ($_GET['servicio'] ?? '')));
$idTienda = filter_input(INPUT_GET, 'id_tienda', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if ($idTienda === false || $idTienda === null) {
    $idTienda = 0;
}
$servicio = in_array($servicio, $serviceCodes, true) ? $servicio : '';

$periodoFacturacion = oiParseMonthToFirstDay($periodoYm);

$periodos = [];
$tiendas = [];
$serviciosTienda = [];
$lecturasByServicio = [];
$documentoActual = null;
$idCierre = 0;

try {
    $requiredTables = [
        'msp_cierre_mensual',
        'msp_procesos_cobro_servicio',
        'msp_tipos_servicio',
        'msp_lecturas_medidores',
        'msp_medidores',
        'msp_locales',
        'msp_contrato_locales',
        'msp_contratos_arriendo',
        'msp_tiendas',
        'msp_arrendatarios',
        'msp_cobros_servicios',
        'msp_documentos_cobro',
        'msp_documentos_cobro_detalle',
        'msp_tipo_item_documento',
        'msp_pagos',
        'msp_arriendo_local_snapshot_periodo',
    ];
    $missing = [];
    foreach ($requiredTables as $tableName) {
        if (!msp2TableExists($conn, $tableName)) {
            $missing[] = $tableName;
        }
    }
    $tablaExiste = $missing === [];
    if (!$tablaExiste) {
        $loadError = 'Faltan tablas requeridas: `' . implode('`, `', $missing) . '`.';
    }
} catch (Throwable) {
    $tablaExiste = false;
    $loadError = 'No fue posible validar estructura para operación individual.';
}

if ($tablaExiste) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $accion = trim((string) ($_POST['accion'] ?? ''));
        $periodoPost = trim((string) ($_POST['periodo'] ?? ''));
        $servicioPost = strtoupper(trim((string) ($_POST['servicio'] ?? '')));
        $idTiendaPost = filter_input(INPUT_POST, 'id_tienda', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $periodoPostDate = oiParseMonthToFirstDay($periodoPost);

        if (!in_array($servicioPost, $serviceCodes, true) || $periodoPostDate === null || $idTiendaPost === false || $idTiendaPost === null) {
            msp2SetFlash('warning', 'Datos inválidos para operación individual.');
            oiRedirect($periodoYm, $servicio, $idTienda);
        }

        try {
            $stmtCierre = $conn->prepare('SELECT id_cierre_mensual FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = :periodo');
            $stmtCierre->bindValue(':periodo', $periodoPostDate, PDO::PARAM_STR);
            $stmtCierre->execute();
            $idCierrePost = (int) $stmtCierre->fetchColumn();
            if ($idCierrePost <= 0) {
                throw new RuntimeException('El período seleccionado no tiene cierre mensual.');
            }

            if ($accion === 'guardar_lecturas') {
                $lecturasActuales = $_POST['lecturas_actuales'] ?? null;
                if (!is_array($lecturasActuales) || $lecturasActuales === []) {
                    throw new RuntimeException('No se recibieron lecturas para guardar.');
                }

                $stmtLecturasValidas = $conn->prepare(
                    "SELECT lm.id_lectura, lm.lectura_anterior
                     FROM dbo.msp_lecturas_medidores lm
                     INNER JOIN dbo.msp_procesos_cobro_servicio p
                        ON p.id_proceso_cobro = lm.id_proceso_cobro
                     INNER JOIN dbo.msp_tipos_servicio ts
                        ON ts.id_tipo_servicio = p.id_tipo_servicio
                     INNER JOIN dbo.msp_medidores m
                        ON m.id_medidor = lm.id_medidor
                     INNER JOIN dbo.msp_contrato_locales cl
                        ON cl.id_local = m.id_local
                       AND cl.estado_relacion = 1
                       AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                       AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                     INNER JOIN dbo.msp_contratos_arriendo ca
                        ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                       AND ca.id_tienda = :id_tienda
                       AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                       AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                       AND ca.estado_contrato IN (1,2,3)
                     WHERE p.id_cierre_mensual = :id_cierre
                       AND UPPER(ts.codigo_servicio) = :servicio"
                );
                $stmtLecturasValidas->bindValue(':id_cierre', $idCierrePost, PDO::PARAM_INT);
                $stmtLecturasValidas->bindValue(':servicio', $servicioPost, PDO::PARAM_STR);
                $stmtLecturasValidas->bindValue(':id_tienda', (int) $idTiendaPost, PDO::PARAM_INT);
                $stmtLecturasValidas->execute();

                $validRows = [];
                while (($row = $stmtLecturasValidas->fetch()) !== false) {
                    $validRows[(int) $row['id_lectura']] = (float) ($row['lectura_anterior'] ?? 0);
                }
                if ($validRows === []) {
                    throw new RuntimeException('No hay lecturas disponibles para esa tienda/servicio.');
                }

                $upd = $conn->prepare(
                    'UPDATE dbo.msp_lecturas_medidores
                     SET lectura_actual = :lectura_actual, fecha_actualizacion = SYSDATETIME()
                     WHERE id_lectura = :id_lectura'
                );

                $actualizadas = 0;
                foreach ($lecturasActuales as $idLecturaRaw => $lecturaActualRaw) {
                    $idLectura = (int) $idLecturaRaw;
                    if (!isset($validRows[$idLectura])) {
                        continue;
                    }
                    $lecturaActual = oiDecimal((string) $lecturaActualRaw);
                    if ($lecturaActual === null) {
                        continue;
                    }
                    if ((float) $lecturaActual < (float) $validRows[$idLectura]) {
                        throw new RuntimeException('La lectura actual no puede ser menor a la lectura anterior.');
                    }
                    $upd->bindValue(':lectura_actual', $lecturaActual, PDO::PARAM_STR);
                    $upd->bindValue(':id_lectura', $idLectura, PDO::PARAM_INT);
                    $upd->execute();
                    $actualizadas += $upd->rowCount();
                }
                msp2SetFlash('success', 'Lecturas actualizadas: ' . $actualizadas . '.');
                oiRedirect($periodoPost, $servicioPost, (int) $idTiendaPost);
            }

            if ($accion === 'generar_tienda' || $accion === 'generar_tienda_todo') {
                $dias = filter_input(INPUT_POST, 'dias_vencimiento', FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 0, 'max_range' => 120],
                ]);
                if ($dias === false || $dias === null) {
                    $dias = 10;
                }

                $serviciosAGenerar = [];
                if ($accion === 'generar_tienda_todo') {
                    $stmtSrv = $conn->prepare(
                        "SELECT DISTINCT UPPER(ts.codigo_servicio) AS codigo
                         FROM dbo.msp_procesos_cobro_servicio p
                         INNER JOIN dbo.msp_tipos_servicio ts
                            ON ts.id_tipo_servicio = p.id_tipo_servicio
                         INNER JOIN dbo.msp_lecturas_medidores lm
                            ON lm.id_proceso_cobro = p.id_proceso_cobro
                         INNER JOIN dbo.msp_medidores m
                            ON m.id_medidor = lm.id_medidor
                         INNER JOIN dbo.msp_contrato_locales cl
                            ON cl.id_local = m.id_local
                           AND cl.estado_relacion = 1
                           AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                         INNER JOIN dbo.msp_contratos_arriendo ca
                            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                           AND ca.id_tienda = :id_tienda
                           AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                           AND ca.estado_contrato IN (1,2,3)
                         WHERE p.id_cierre_mensual = :id_cierre
                           AND p.estado_proceso <> 4
                           AND UPPER(ts.codigo_servicio) IN ('LUZ','GAS','AGUA')"
                    );
                    $stmtSrv->bindValue(':id_cierre', $idCierrePost, PDO::PARAM_INT);
                    $stmtSrv->bindValue(':id_tienda', (int) $idTiendaPost, PDO::PARAM_INT);
                    $stmtSrv->execute();
                    while (($codeRow = $stmtSrv->fetch()) !== false) {
                        $code = strtoupper((string) ($codeRow['codigo'] ?? ''));
                        if (in_array($code, $serviceCodes, true)) {
                            $serviciosAGenerar[] = $code;
                        }
                    }
                    $serviciosAGenerar = array_values(array_unique($serviciosAGenerar));
                } else {
                    $serviciosAGenerar = [$servicioPost];
                }

                if ($serviciosAGenerar === []) {
                    throw new RuntimeException('No hay servicios activos con lecturas para generar en la tienda seleccionada.');
                }

                $cobrosAfectados = 0;
                $itemsGenerados = 0;
                $docGenerado = 0;
                foreach ($serviciosAGenerar as $srvCode) {
                    $result = oiGenerarServicioTienda(
                        $conn,
                        $idCierrePost,
                        $periodoPostDate,
                        (int) $idTiendaPost,
                        $srvCode,
                        $dias
                    );
                    $cobrosAfectados += (int) ($result['cobros_afectados'] ?? 0);
                    $itemsGenerados += (int) ($result['items_generados'] ?? 0);
                    $docGenerado = max($docGenerado, (int) ($result['documento_generado'] ?? 0));
                }

                msp2SetFlash(
                    'success',
                    'Servicios: ' . implode(', ', $serviciosAGenerar)
                    . ' | Cobros afectados: ' . $cobrosAfectados
                    . ' | Documento: ' . ($docGenerado > 0 ? 'sí' : 'no')
                    . ' | Items: ' . $itemsGenerados . '.'
                );
                oiRedirect($periodoPost, '', (int) $idTiendaPost);
            }
        } catch (Throwable $e) {
            msp2SetFlash('danger', $e->getMessage() !== '' ? $e->getMessage() : 'No se pudo completar la operación individual.');
            oiRedirect($periodoPost, $servicioPost, (int) $idTiendaPost);
        }
    }

    try {
        $stmtPeriodos = $conn->query(
            "SELECT CONVERT(CHAR(7), periodo_facturacion, 126) AS periodo_ym
             FROM dbo.msp_cierre_mensual
             ORDER BY periodo_facturacion DESC"
        );
        $periodos = $stmtPeriodos->fetchAll(PDO::FETCH_COLUMN);

        if ($periodoYm === '' && $periodos !== []) {
            $periodoYm = (string) ($periodos[0] ?? '');
            $periodoFacturacion = oiParseMonthToFirstDay($periodoYm);
        }

        if ($periodoFacturacion !== null) {
            $stmtCierreSel = $conn->prepare(
                'SELECT id_cierre_mensual FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = :periodo'
            );
            $stmtCierreSel->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
            $stmtCierreSel->execute();
            $idCierre = (int) $stmtCierreSel->fetchColumn();

            if ($idCierre > 0) {
                $stmtTiendas = $conn->prepare(
                    "SELECT DISTINCT
                        t.id_tienda,
                        t.nombre_comercial,
                        COALESCE(NULLIF(a.nombre_locatario, ''), NULLIF(a.nombre_representante, ''), a.rut) AS nombre_arrendatario
                     FROM dbo.msp_contratos_arriendo ca
                     INNER JOIN dbo.msp_tiendas t
                        ON t.id_tienda = ca.id_tienda
                     INNER JOIN dbo.msp_arrendatarios a
                        ON a.id_arrendatario = t.id_arrendatario
                     WHERE ca.fecha_inicio <= EOMONTH(:periodo_fin)
                       AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= :periodo_ini)
                       AND ca.estado_contrato IN (1,2,3)
                     ORDER BY t.nombre_comercial ASC"
                );
                $stmtTiendas->bindValue(':periodo_fin', $periodoFacturacion, PDO::PARAM_STR);
                $stmtTiendas->bindValue(':periodo_ini', $periodoFacturacion, PDO::PARAM_STR);
                $stmtTiendas->execute();
                $tiendas = $stmtTiendas->fetchAll();

                if ($idTienda <= 0 && $tiendas !== []) {
                    $idTienda = (int) ($tiendas[0]['id_tienda'] ?? 0);
                }

                if ($idTienda > 0) {
                    foreach ($serviceCodes as $code) {
                        $stmtProceso = $conn->prepare(
                            "SELECT TOP 1 p.id_proceso_cobro
                             FROM dbo.msp_procesos_cobro_servicio p
                             INNER JOIN dbo.msp_tipos_servicio ts
                                ON ts.id_tipo_servicio = p.id_tipo_servicio
                             WHERE p.id_cierre_mensual = :id_cierre
                               AND p.estado_proceso <> 4
                               AND UPPER(ts.codigo_servicio) = :servicio"
                        );
                        $stmtProceso->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
                        $stmtProceso->bindValue(':servicio', $code, PDO::PARAM_STR);
                        $stmtProceso->execute();
                        $idProcesoServicio = (int) $stmtProceso->fetchColumn();

                        if ($idProcesoServicio <= 0) {
                            $serviciosTienda[$code] = ['tiene_proceso' => false, 'lecturas' => 0];
                            $lecturasByServicio[$code] = [];
                            continue;
                        }

                        $stmtLecturas = $conn->prepare(
                            "SELECT
                                lm.id_lectura,
                                loc.cdo_local AS cod_local,
                                m.codigo_medidor,
                                lm.lectura_anterior,
                                lm.lectura_actual,
                                CASE
                                    WHEN lm.lectura_actual >= ISNULL(lm.lectura_anterior, 0)
                                        THEN lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
                                    ELSE 0
                                END AS consumo
                             FROM dbo.msp_lecturas_medidores lm
                             INNER JOIN dbo.msp_medidores m
                                ON m.id_medidor = lm.id_medidor
                             INNER JOIN dbo.msp_locales loc
                                ON loc.id_local = m.id_local
                             INNER JOIN dbo.msp_contrato_locales cl
                                ON cl.id_local = m.id_local
                               AND cl.estado_relacion = 1
                               AND cl.fecha_inicio <= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura)
                               AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                             INNER JOIN dbo.msp_contratos_arriendo ca
                                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                               AND ca.id_tienda = :id_tienda
                               AND ca.fecha_inicio <= EOMONTH(COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                               AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= COALESCE(lm.fecha_hasta_consumo, lm.fecha_lectura))
                               AND ca.estado_contrato IN (1,2,3)
                             WHERE lm.id_proceso_cobro = :id_proceso
                             ORDER BY " . msp2LocalCodeNaturalOrderSql('loc.cdo_local') . ", m.codigo_medidor ASC"
                        );
                        $stmtLecturas->bindValue(':id_proceso', $idProcesoServicio, PDO::PARAM_INT);
                        $stmtLecturas->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
                        $stmtLecturas->execute();
                        $rowsServicio = $stmtLecturas->fetchAll();
                        $lecturasCount = count($rowsServicio);

                        $serviciosTienda[$code] = [
                            'tiene_proceso' => $lecturasCount > 0,
                            'lecturas' => $lecturasCount,
                        ];
                        $lecturasByServicio[$code] = $lecturasCount > 0 ? $rowsServicio : [];
                    }

                    $stmtDoc = $conn->prepare(
                        "SELECT TOP 1 id_documento_cobro, numero_documento, monto_total, saldo_pendiente, estado_documento
                         FROM dbo.msp_documentos_cobro
                         WHERE id_tienda = :id_tienda
                           AND periodo_facturacion = :periodo"
                    );
                    $stmtDoc->bindValue(':id_tienda', $idTienda, PDO::PARAM_INT);
                    $stmtDoc->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
                    $stmtDoc->execute();
                    $documentoActual = $stmtDoc->fetch() ?: null;
                }
            }
        }
    } catch (Throwable $e) {
        $loadError = $e->getMessage() !== '' ? $e->getMessage() : 'No fue posible cargar datos de la operación individual.';
    }
}

?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>MSP - Operación individual</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/portalgp/styles.css">
    <?php msp2RenderSearchableSelectAssets(); ?>
</head>
<body class="gp-layout bg-light">
<?php include dirname(__DIR__, 2) . '/templates/header.php'; ?>
<main class="gp-main d-flex align-items-center justify-content-center p-4">
    <div class="box-container-wide">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <a href="<?php echo msp2Escape(msp2Url('cobros/operacion_mensual.php')); ?>" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Volver a generar cobros
            </a>
        </div>

        <h1 class="h4 mb-1">Operación individual</h1>
        <p class="text-muted mb-3">Editar lecturas por tienda y regenerar cobros/documento sin reprocesar todo el período.</p>

        <?php msp2RenderFlash($flash); ?>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning"><?php echo msp2Escape($loadError); ?></div>
        <?php else: ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end" id="form_carga_individual">
                        <?php
                        $periodoOptions = [];
                        foreach ($periodos as $pYm) {
                            $periodoValue = (string) $pYm;
                            if ($periodoValue === '') {
                                continue;
                            }
                            $periodoOptions[] = [
                                'value' => $periodoValue,
                                'label' => $periodoValue,
                                'search' => mb_strtolower($periodoValue, 'UTF-8'),
                            ];
                        }
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-4',
                            'label' => 'Período',
                            'input_name' => 'periodo',
                            'input_id' => 'periodo_picker_value',
                            'picker_id' => 'periodo_picker',
                            'button_id' => 'periodo_picker_btn',
                            'filter_id' => 'periodo_picker_filter',
                            'list_id' => 'periodo_picker_list',
                            'error_id' => 'periodo_picker_error',
                            'error_message' => 'Debes seleccionar un período.',
                            'button_placeholder' => 'Seleccionar...',
                            'filter_placeholder' => 'Buscar período',
                            'empty_message' => 'No hay períodos disponibles.',
                            'required' => true,
                            'value' => $periodoYm,
                            'options' => $periodoOptions,
                        ]);

                        $tiendaOptions = [];
                        foreach ($tiendas as $t) {
                            $tid = (int) ($t['id_tienda'] ?? 0);
                            if ($tid <= 0) {
                                continue;
                            }
                            $tiendaNombre = (string) ($t['nombre_comercial'] ?? '');
                            $arrendatarioNombre = (string) ($t['nombre_arrendatario'] ?? '');
                            $tiendaOptionLabel = $tiendaNombre . ' · ' . $arrendatarioNombre;
                            $tiendaOptions[] = [
                                'value' => (string) $tid,
                                'label' => $tiendaOptionLabel,
                                'search' => mb_strtolower($tiendaOptionLabel . ' ' . $tid, 'UTF-8'),
                            ];
                        }
                        msp2RenderSearchableSelectField([
                            'wrapper_class' => 'col-12 col-md-8',
                            'label' => 'Tienda',
                            'input_name' => 'id_tienda',
                            'input_id' => 'tienda_picker_value',
                            'picker_id' => 'tienda_picker',
                            'button_id' => 'tienda_picker_btn',
                            'filter_id' => 'tienda_picker_filter',
                            'list_id' => 'tienda_picker_list',
                            'error_id' => 'tienda_picker_error',
                            'error_message' => 'Debes seleccionar una tienda.',
                            'button_placeholder' => 'Seleccionar...',
                            'filter_placeholder' => 'Buscar por tienda o arrendatario',
                            'empty_message' => 'No hay tiendas disponibles.',
                            'required' => true,
                            'value' => $idTienda > 0 ? (string) $idTienda : '',
                            'options' => $tiendaOptions,
                        ]);
                        ?>
                    </form>
                </div>
            </div>

            <?php if ($idTienda > 0): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h2 class="h6 mb-0">Procesos del período</h2>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($serviceCodes as $code): ?>
                                    <?php
                                    $srvInfo = $serviciosTienda[$code] ?? ['tiene_proceso' => false, 'lecturas' => 0];
                                    $badgeClass = $srvInfo['tiene_proceso'] ? 'text-bg-success' : 'text-bg-secondary';
                                    ?>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo msp2Escape($code); ?>: <?php echo $srvInfo['tiene_proceso'] ? ((int) $srvInfo['lecturas'] . ' lecturas') : 'sin proceso'; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($documentoActual !== null): ?>
                            <?php $estadoDoc = (int) ($documentoActual['estado_documento'] ?? 0); ?>
                            <div class="small text-muted mt-2">
                                Documento actual: <strong><?php echo msp2Escape((string) ($documentoActual['numero_documento'] ?? '')); ?></strong>
                                · <?php echo msp2Escape($estadoDocumento[$estadoDoc] ?? ('Estado ' . $estadoDoc)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php foreach ($serviceCodes as $code): ?>
                    <?php
                    $srvInfo = $serviciosTienda[$code] ?? ['tiene_proceso' => false, 'lecturas' => 0];
                    if (!$srvInfo['tiene_proceso']) {
                        continue;
                    }
                    $lecturasSrv = $lecturasByServicio[$code] ?? [];
                    ?>
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h2 class="h6 mb-0">Lecturas registradas (<?php echo msp2Escape($code); ?>)</h2>
                                <span class="badge text-bg-secondary"><?php echo count($lecturasSrv); ?> medidores</span>
                            </div>
                            <?php if ($lecturasSrv === []): ?>
                                <div class="alert alert-warning mb-0">Proceso activo sin lecturas para esta tienda.</div>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="accion" value="guardar_lecturas">
                                    <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                    <input type="hidden" name="servicio" value="<?php echo msp2Escape($code); ?>">
                                    <input type="hidden" name="id_tienda" value="<?php echo (int) $idTienda; ?>">
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle">
                                            <thead>
                                                <tr>
                                                    <th>Local</th>
                                                    <th>Medidor</th>
                                                    <th>Anterior</th>
                                                    <th style="width:180px;">Actual</th>
                                                    <th>Consumo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($lecturasSrv as $row): ?>
                                                    <?php
                                                    $idLectura = (int) ($row['id_lectura'] ?? 0);
                                                    $anterior = (float) ($row['lectura_anterior'] ?? 0);
                                                    $actual = (float) ($row['lectura_actual'] ?? 0);
                                                    $consumo = (float) ($row['consumo'] ?? 0);
                                                    ?>
                                                    <tr>
                                                        <td><?php echo msp2Escape((string) ($row['cod_local'] ?? '')); ?></td>
                                                        <td><?php echo msp2Escape((string) ($row['codigo_medidor'] ?? '')); ?></td>
                                                        <td><?php echo msp2Escape(number_format($anterior, 4, ',', '.')); ?></td>
                                                        <td>
                                                            <input
                                                                type="text"
                                                                class="form-control form-control-sm"
                                                                name="lecturas_actuales[<?php echo $idLectura; ?>]"
                                                                value="<?php echo msp2Escape(number_format($actual, 4, ',', '')); ?>">
                                                        </td>
                                                        <td><?php echo msp2Escape(number_format($consumo, 4, ',', '.')); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Guardar lecturas <?php echo msp2Escape($code); ?></button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php
                $hasAnyProceso = false;
                foreach ($serviceCodes as $codeCheck) {
                    if (($serviciosTienda[$codeCheck]['tiene_proceso'] ?? false) === true) {
                        $hasAnyProceso = true;
                        break;
                    }
                }
                ?>
                <?php if (!$hasAnyProceso): ?>
                    <div class="alert alert-warning">La tienda no tiene procesos activos de LUZ/GAS/AGUA en este período.</div>
                <?php else: ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h2 class="h6 mb-2">Confirmación</h2>
                            <p class="small text-muted mb-2">Genera todos los servicios activos de la tienda y recompone el documento del período en una sola acción.</p>
                            <form method="post" class="row g-2 align-items-end">
                                <input type="hidden" name="accion" value="generar_tienda_todo">
                                <input type="hidden" name="periodo" value="<?php echo msp2Escape($periodoYm); ?>">
                                <input type="hidden" name="id_tienda" value="<?php echo (int) $idTienda; ?>">
                                <input type="hidden" name="servicio" value="LUZ">
                                <div class="col-12 col-md-3">
                                    <label class="form-label">Días vencimiento</label>
                                    <input type="number" class="form-control" name="dias_vencimiento" min="0" max="120" value="10">
                                </div>
                                <div class="col-12 col-md-4 d-grid">
                                    <button type="submit" class="btn btn-success">Confirmar y generar documento</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const formCarga = document.getElementById('form_carga_individual');
    const periodoValueEl = document.getElementById('periodo_picker_value');
    const tiendaValueEl = document.getElementById('tienda_picker_value');
    if (formCarga instanceof HTMLFormElement
        && periodoValueEl instanceof HTMLInputElement
        && tiendaValueEl instanceof HTMLInputElement
    ) {
        tiendaValueEl.addEventListener('change', () => {
            if (String(periodoValueEl.value || '').trim() === '' || String(tiendaValueEl.value || '').trim() === '') {
                return;
            }
            formCarga.requestSubmit();
        });
    }
})();
</script>
<?php include dirname(__DIR__, 2) . '/templates/footer.php'; ?>
</body>
</html>
