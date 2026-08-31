<?php
declare(strict_types=1);

final class DocumentosCobroService
{
    private const SERVICE_PROFILES = ['ALL', 'LUZ_ONLY', 'LUZ_GAS', 'LUZ_AGUA', 'LUZ_GAS_AGUA', 'LUZ_CON_AGUA'];

    public static function generateDocumentsForCierre(
        PDO $conn,
        int $idCierre,
        int $dias,
        int $rep,
        bool $aplicarCargosExtra = true,
        string $serviceProfile = 'ALL',
        ?array $targetTiendaIds = null
    ): array
{
    $serviceProfile = strtoupper(trim($serviceProfile));
    if (!in_array($serviceProfile, self::SERVICE_PROFILES, true)) {
        $serviceProfile = 'ALL';
    }

    if ($serviceProfile !== 'ALL' && $rep !== 1) {
        throw new RuntimeException('Para generar documentos por perfil de servicios debes usar `Reemplazar documentos`.');
    }

    $targetTiendaIdsNorm = [];
    foreach ((array) $targetTiendaIds as $targetId) {
        $idTienda = (int) $targetId;
        if ($idTienda > 0) {
            $targetTiendaIdsNorm[$idTienda] = true;
        }
    }
    $targetTiendaCsv = implode(',', array_map('strval', array_keys($targetTiendaIdsNorm)));
    $hasTargetTiendaFilter = $targetTiendaCsv !== '';

    $periodoFacturacion = null;
    $periodoStmt = $conn->prepare(
        'SELECT c.periodo_facturacion
         FROM dbo.msp_cierre_mensual c
         WHERE c.id_cierre_mensual = :id_cierre'
    );
    $periodoStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
    $periodoStmt->execute();
    $periodoFacturacion = $periodoStmt->fetchColumn();
    if ($periodoFacturacion === false) {
        throw new RuntimeException('No existe cierre mensual para generar documentos.');
    }

    // Fuente única de arriendo para documentos:
    // snapshot mensual calculado desde reglas por contrato-local.
    self::generateArriendoSnapshotForCierre($conn, $idCierre, $rep, $targetTiendaCsv);

    $hasDocsPeriodo = false;
    if ($rep === 1 && $periodoFacturacion !== false && msp2TableExists($conn, 'msp_documentos_cobro')) {
        $docsPeriodoStmt = $conn->prepare(
            'SELECT COUNT(*)
             FROM dbo.msp_documentos_cobro
             WHERE periodo_facturacion = :periodo'
        );
        $docsPeriodoStmt->bindValue(':periodo', (string) $periodoFacturacion, PDO::PARAM_STR);
        $docsPeriodoStmt->execute();
        $hasDocsPeriodo = ((int) ($docsPeriodoStmt->fetchColumn() ?: 0)) > 0;
    }

    // En flujo por etapas/pool, materializar solo tiendas objetivo.
    // El SP base no recibe target y construiria todo el periodo antes de recomponer.
    $runFullGeneration = false;

    if ($runFullGeneration && $rep === 1 && msp2TableExists($conn, 'msp_cargos_salida')) {
        $releaseStmt = $conn->prepare(
            'DECLARE @periodo DATE;
             SELECT @periodo = c.periodo_facturacion
             FROM dbo.msp_cierre_mensual c
             WHERE c.id_cierre_mensual = :id_cierre;

             IF @periodo IS NOT NULL
             BEGIN
                UPDATE cs
                SET
                    cs.id_documento_cobro = NULL,
                    cs.estado_cargo = CASE
                        WHEN cs.estado_cargo = 3 THEN 1
                        ELSE cs.estado_cargo
                    END
                FROM dbo.msp_cargos_salida cs
                INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_documento_cobro = cs.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo;
             END;'
        );
        $releaseStmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $releaseStmt->execute();
    }

    $docsGenerados = 0;
    $itemsGenerados = 0;
    if ($runFullGeneration) {
        $stmt = $conn->prepare(
            'DECLARE @out_docs INT, @out_items INT;
             DECLARE @fecha_emision DATE;
             SELECT @fecha_emision = CONVERT(DATE, SYSDATETIME());
             EXEC dbo.msp_generar_documentos_cobro_periodo
                @id_cierre_mensual = :id_exec,
                @fecha_emision = @fecha_emision,
                @dias_vencimiento = :dias,
                @reemplazar = :rep,
                @documentos_generados = @out_docs OUTPUT,
                @items_generados = @out_items OUTPUT;
             SELECT @out_docs AS documentos_generados, @out_items AS items_generados;'
        );
        $stmt->bindValue(':id_exec', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':dias', $dias, PDO::PARAM_INT);
        $stmt->bindValue(':rep', $rep, PDO::PARAM_INT);
        $stmt->execute();
        $resDocs = omFetchFirstRowsetRow($stmt);
        $docsGenerados = (int) ($resDocs['documentos_generados'] ?? 0);
        $itemsGenerados = (int) ($resDocs['items_generados'] ?? 0);
    }

    if ($serviceProfile !== 'ALL') {
        self::pruneDocsOutsideServiceProfile($conn, $idCierre, $serviceProfile);
    }

    // Reconciliacion post-generacion:
    // Recompone arriendo + servicios usando ocupacion del periodo (no fecha de carga),
    // crea documentos faltantes y recalcula subtotales, total, saldo y estado.
    $reconStmt = $conn->prepare(
        "DECLARE @id_cierre INT = :id;
         DECLARE @aplicar_cargos_extra BIT = :aplicar_cargos_extra;
         DECLARE @periodo DATE;
         DECLARE @valor_uf DECIMAL(18,6);
         DECLARE @tasa_iva DECIMAL(9,6) = 0.19;
         DECLARE @id_item_arriendo INT;
         DECLARE @id_item_agua INT;
         DECLARE @id_item_luz INT;
         DECLARE @id_item_gas INT;
         DECLARE @id_item_multa INT;
         DECLARE @id_item_dano INT;
         DECLARE @id_item_ajuste INT;
         DECLARE @service_profile NVARCHAR(30) = :service_profile;
         DECLARE @target_tiendas_csv NVARCHAR(MAX) = :target_tiendas_csv;
         DECLARE @dias_vencimiento INT = :dias_vencimiento;
         DECLARE @target_tiendas TABLE (
            id_tienda INT NOT NULL PRIMARY KEY
         );
         DECLARE @has_target BIT = 0;
         DECLARE @docs_creados INT = 0;
         DECLARE @items_recompuestos INT = 0;
         DECLARE @items_servicios_recompuestos INT = 0;
         DECLARE @items_multa_recompuestos INT = 0;
         DECLARE @items_cargos_extra_recompuestos INT = 0;

         SELECT
            @periodo = c.periodo_facturacion,
            @valor_uf = c.valor_uf
         FROM dbo.msp_cierre_mensual c
         WHERE c.id_cierre_mensual = @id_cierre;

         SELECT @id_item_arriendo = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'ARRIENDO';

         SELECT @id_item_agua = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'SERVICIO_AGUA';

         SELECT @id_item_luz = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'SERVICIO_LUZ';

         SELECT @id_item_gas = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'SERVICIO_GAS';

         SELECT @id_item_multa = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'MULTA';

         SELECT @id_item_dano = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'DANO';

         SELECT @id_item_ajuste = tid.id_tipo_item_documento
         FROM dbo.msp_tipo_item_documento tid
         WHERE tid.codigo_item = N'AJUSTE';

         IF NULLIF(LTRIM(RTRIM(ISNULL(@target_tiendas_csv, N''))), N'') IS NOT NULL
         BEGIN
            INSERT INTO @target_tiendas (id_tienda)
            SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT)
            FROM STRING_SPLIT(@target_tiendas_csv, N',') ss
            WHERE TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) IS NOT NULL
              AND TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) > 0;
         END;
         IF EXISTS (SELECT 1 FROM @target_tiendas)
         BEGIN
            SET @has_target = 1;
         END;

         IF @periodo IS NULL
            OR @valor_uf IS NULL
            OR @id_item_arriendo IS NULL
            OR @id_item_agua IS NULL
            OR @id_item_luz IS NULL
            OR @id_item_gas IS NULL
         BEGIN
            SELECT 0 AS items_recompuestos;
            RETURN;
         END;

         ;WITH servicios_por_contrato AS (
            SELECT
                ca.id_tienda,
                ca.id_contrato_arriendo,
                MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS has_luz,
                MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS has_gas,
                MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS has_agua
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            INNER JOIN dbo.msp_medidores m
                ON m.id_local = cl.id_local
               AND m.estado_medidor IN (1,2)
               AND (m.fecha_instalacion IS NULL OR m.fecha_instalacion <= EOMONTH(@periodo))
               AND (m.fecha_retiro IS NULL OR m.fecha_retiro >= DATEADD(MONTH, -1, @periodo))
            INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = m.id_tipo_servicio
            WHERE cl.estado_relacion IN (1,2)
              AND cl.fecha_inicio <= EOMONTH(@periodo)
              AND (
                    cl.fecha_termino IS NULL
                    OR cl.fecha_termino >= @periodo
                    OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), MONTH(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), 1)) = @periodo
              )
              AND ca.fecha_inicio <= EOMONTH(@periodo)
              AND (
                    ca.fecha_termino_efectiva IS NULL
                    OR ca.fecha_termino_efectiva >= @periodo
                    OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
              )
              AND ca.estado_contrato IN (1,2,3,4)
            GROUP BY ca.id_tienda, ca.id_contrato_arriendo
         ),
         contratos_perfil AS (
            SELECT spc.id_tienda, spc.id_contrato_arriendo
            FROM servicios_por_contrato spc
            WHERE (
                   @service_profile = N'ALL'
                OR (@service_profile = N'LUZ_ONLY' AND spc.has_luz = 1 AND spc.has_gas = 0 AND spc.has_agua = 0)
                OR (@service_profile = N'LUZ_GAS' AND spc.has_luz = 1 AND spc.has_gas = 1 AND spc.has_agua = 0)
                OR (@service_profile = N'LUZ_AGUA' AND spc.has_luz = 1 AND spc.has_gas = 0 AND spc.has_agua = 1)
                OR (@service_profile = N'LUZ_GAS_AGUA' AND spc.has_luz = 1 AND spc.has_gas = 1 AND spc.has_agua = 1)
                OR (@service_profile = N'LUZ_CON_AGUA' AND spc.has_luz = 1 AND spc.has_agua = 1)
            )
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = spc.id_tienda
              ))
         ),
         contratos_arriendo AS (
            SELECT DISTINCT
                s.id_tienda,
                s.id_contrato_arriendo,
                CAST(0 AS BIT) AS es_liquidacion
            FROM dbo.msp_arriendo_local_snapshot_periodo s
            LEFT JOIN contratos_perfil cp
                ON cp.id_tienda = s.id_tienda
               AND cp.id_contrato_arriendo = s.id_contrato_arriendo
            WHERE s.periodo_facturacion = @periodo
              AND s.estado_snapshot IN (1,2,3)
              AND (@service_profile = N'ALL' OR cp.id_tienda IS NOT NULL)
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = s.id_tienda
              ))
         ),
         contratos_servicios AS (
            SELECT DISTINCT
                map.id_tienda,
                map.id_contrato_arriendo,
                CAST(map.es_liquidacion AS BIT) AS es_liquidacion
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
            INNER JOIN dbo.msp_medidores m
                ON m.id_medidor = lm.id_medidor
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_tienda,
                    ca.id_contrato_arriendo,
                    CASE
                        WHEN ca.fecha_termino_efectiva IS NOT NULL
                         AND ca.fecha_termino_efectiva < @periodo
                         AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                        THEN 1
                        ELSE 0
                    END AS es_liquidacion
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                WHERE cl.id_local = m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        cl.fecha_termino IS NULL
                        OR cl.fecha_termino >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), MONTH(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), 1)) = @periodo
                  )
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        ca.fecha_termino_efectiva IS NULL
                        OR ca.fecha_termino_efectiva >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                  )
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY
                    CASE
                        WHEN ca.fecha_termino_efectiva IS NOT NULL
                         AND ca.fecha_termino_efectiva < @periodo
                         AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                        THEN 0
                        ELSE 1
                    END,
                    CASE WHEN cl.fecha_inicio <= @periodo THEN 0 ELSE 1 END,
                    CASE WHEN cl.fecha_inicio <= @periodo THEN cl.fecha_inicio END DESC,
                    CASE WHEN cl.fecha_inicio > @periodo THEN cl.fecha_inicio END ASC,
                    cl.id_contrato_local DESC
            ) map
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4
              AND map.id_tienda IS NOT NULL
              AND EXISTS (
                    SELECT 1
                    FROM contratos_perfil cp
                    WHERE cp.id_tienda = map.id_tienda
                      AND cp.id_contrato_arriendo = map.id_contrato_arriendo
              )
         ),
         contratos_cargos AS (
            SELECT DISTINCT
                ca.id_tienda,
                ca.id_contrato_arriendo,
                CAST(CASE
                    WHEN ca.fecha_termino_efectiva IS NOT NULL
                     AND ca.fecha_termino_efectiva < @periodo
                     AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                    THEN 1 ELSE 0 END AS BIT) AS es_liquidacion
            FROM dbo.msp_cargos_salida cs
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
            WHERE cs.estado_cargo IN (1,2)
              AND cs.id_documento_cobro IS NULL
              AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
              AND cs.monto_cargo > 0
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = ca.id_tienda
              ))
         ),
         contratos_objetivo AS (
            SELECT id_tienda, id_contrato_arriendo, es_liquidacion FROM contratos_arriendo
            UNION
            SELECT id_tienda, id_contrato_arriendo, es_liquidacion FROM contratos_servicios
            UNION
            SELECT id_tienda, id_contrato_arriendo, es_liquidacion FROM contratos_cargos
         )
         INSERT INTO dbo.msp_documentos_cobro (
            id_tienda,
            id_contrato_arriendo,
            periodo_facturacion,
            numero_documento,
            fecha_emision,
            fecha_vencimiento,
            rut_arrendatario_snapshot,
            nombre_arrendatario_snapshot,
            nombre_tienda_snapshot,
            subtotal_arriendo,
            subtotal_servicios,
            monto_total,
            saldo_pendiente,
            estado_documento,
            observaciones
         )
         SELECT
            t.id_tienda,
            ca.id_contrato_arriendo,
            @periodo,
            CONCAT(CONVERT(CHAR(6), @periodo, 112), N'-C', ca.id_contrato_arriendo),
            @periodo,
            DATEADD(DAY, @dias_vencimiento, @periodo),
            a.rut,
            COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), a.rut),
            t.nombre_comercial,
            0,
            0,
            0,
            0,
            2,
            CASE
                WHEN tobj.es_liquidacion = 1 THEN CONCAT(N'Liquidacion post-termino recompuesta desde cierre #', @id_cierre, N'.')
                ELSE CONCAT(N'Documento recompuesto desde cierre #', @id_cierre, N'.')
            END
         FROM contratos_objetivo tobj
         INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = tobj.id_contrato_arriendo
         INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = tobj.id_tienda
         INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = ca.id_arrendatario
         LEFT JOIN dbo.msp_documentos_cobro dc_exist
            ON dc_exist.id_contrato_arriendo = ca.id_contrato_arriendo
           AND dc_exist.periodo_facturacion = @periodo
         WHERE dc_exist.id_documento_cobro IS NULL;

         SET @docs_creados = @@ROWCOUNT;

         DELETE dcd
         FROM dbo.msp_documentos_cobro_detalle dcd
         INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = dcd.id_documento_cobro
         INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
         WHERE dc.periodo_facturacion = @periodo
           AND (@has_target = 0 OR EXISTS (
                SELECT 1
                FROM @target_tiendas tt
                WHERE tt.id_tienda = dc.id_tienda
           ))
           AND tid.codigo_item IN (N'ARRIENDO', N'SERVICIO_AGUA', N'SERVICIO_LUZ', N'SERVICIO_GAS');

         IF OBJECT_ID(N'dbo.msp_reglas_cobro_auto', N'U') IS NOT NULL
            AND OBJECT_ID(N'dbo.msp_cargos_auto_generados', N'U') IS NOT NULL
         BEGIN
            DECLARE @detalles_auto_a_borrar TABLE (
                id_detalle_documento INT NOT NULL PRIMARY KEY
            );

            INSERT INTO @detalles_auto_a_borrar (id_detalle_documento)
            SELECT DISTINCT cag.id_detalle_documento
            FROM dbo.msp_cargos_auto_generados cag
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = cag.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
              ))
              AND cag.id_detalle_documento IS NOT NULL;

            DELETE cag
            FROM dbo.msp_cargos_auto_generados cag
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = cag.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
              ));

            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN @detalles_auto_a_borrar dab
                ON dab.id_detalle_documento = dcd.id_detalle_documento;
         END
         ELSE IF @id_item_multa IS NOT NULL
         BEGIN
            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_documentos_cobro dc
               ON dc.id_documento_cobro = dcd.id_documento_cobro
            INNER JOIN dbo.msp_tipo_item_documento tid
               ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            WHERE dc.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
              ))
              AND tid.codigo_item = N'MULTA'
              AND dcd.descripcion_item LIKE N'Multa mora diaria automatica%';
         END;

         ;WITH arriendo_detalle_raw AS (
            SELECT
                dc.id_documento_cobro,
                s.id_contrato_arriendo,
                s.id_contrato_local,
                s.id_modalidad_aplicada,
                loc.cdo_local,
                CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2)) AS valor_arriendo_neto,
                CASE WHEN s.id_modalidad_aplicada = 3 THEN 1 ELSE 0 END AS es_clp_fijo_contrato,
                ROW_NUMBER() OVER (
                    PARTITION BY dc.id_documento_cobro, CASE WHEN s.id_modalidad_aplicada = 3 THEN s.id_contrato_arriendo ELSE s.id_contrato_local END
                    ORDER BY s.id_contrato_local
                ) AS rn_clp_fijo_contrato,
                SUM(
                    CASE
                        WHEN s.id_modalidad_aplicada = 3 THEN CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2))
                        ELSE CAST(0 AS DECIMAL(18,2))
                    END
                ) OVER (PARTITION BY dc.id_documento_cobro, s.id_contrato_arriendo) AS total_clp_fijo_contrato
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_arriendo_local_snapshot_periodo s
                ON s.id_tienda = dc.id_tienda
               AND s.id_contrato_arriendo = dc.id_contrato_arriendo
               AND s.periodo_facturacion = @periodo
               AND s.estado_snapshot IN (1,2,3)
            INNER JOIN dbo.msp_contratos_arriendo ca_arr
                ON ca_arr.id_contrato_arriendo = s.id_contrato_arriendo
            INNER JOIN dbo.msp_locales loc
                ON loc.id_local = s.id_local
            WHERE dc.periodo_facturacion = @periodo
              AND dc.estado_documento <> 5
              AND (ca_arr.fecha_termino_efectiva IS NULL OR ca_arr.fecha_termino_efectiva >= @periodo)
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
              ))
         ),
         arriendo_local AS (
            SELECT
                adr.id_documento_cobro,
                adr.id_contrato_local,
                adr.cdo_local,
                adr.es_clp_fijo_contrato,
                adr.id_contrato_arriendo,
                CASE
                    WHEN adr.es_clp_fijo_contrato = 1
                        THEN CAST(ROUND(ISNULL(adr.total_clp_fijo_contrato, 0), 2) AS DECIMAL(18,2))
                    ELSE adr.valor_arriendo_neto
                END AS valor_arriendo_neto
            FROM arriendo_detalle_raw adr
            WHERE adr.es_clp_fijo_contrato = 0
               OR (adr.es_clp_fijo_contrato = 1 AND adr.rn_clp_fijo_contrato = 1)
         )
         INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
         )
         SELECT
            al.id_documento_cobro,
            ROW_NUMBER() OVER (
                PARTITION BY al.id_documento_cobro
                ORDER BY " . msp2LocalCodeNaturalOrderSql('al.cdo_local') . ", al.id_contrato_local
            ),
            @id_item_arriendo,
            CASE
                WHEN al.es_clp_fijo_contrato = 1 THEN CONCAT(N'Arriendo fijo contrato #', al.id_contrato_arriendo)
                ELSE CONCAT(N'Arriendo local ', al.cdo_local)
            END,
            1,
            al.valor_arriendo_neto,
            al.valor_arriendo_neto,
            NULL
         FROM arriendo_local al;

         SET @items_recompuestos = @@ROWCOUNT;

         ;WITH servicios_mapeados AS (
            SELECT
                cs.id_cobro_servicio,
                ts.codigo_servicio,
                ts.nombre_servicio,
                loc.cdo_local,
                m.codigo_medidor,
                cs.consumo_cobrado,
                cs.monto_total,
                map.id_tienda,
                map.id_contrato_arriendo
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
            OUTER APPLY (
                SELECT TOP 1
                    ca.id_tienda,
                    ca.id_contrato_arriendo
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                WHERE cl.id_local = m.id_local
                  AND cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        cl.fecha_termino IS NULL
                        OR cl.fecha_termino >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), MONTH(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), 1)) = @periodo
                  )
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        ca.fecha_termino_efectiva IS NULL
                        OR ca.fecha_termino_efectiva >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                  )
                  AND ca.estado_contrato IN (1,2,3,4)
                ORDER BY
                    CASE
                        WHEN ca.fecha_termino_efectiva IS NOT NULL
                         AND ca.fecha_termino_efectiva < @periodo
                         AND DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                        THEN 0
                        ELSE 1
                    END,
                    CASE WHEN cl.fecha_inicio <= @periodo THEN 0 ELSE 1 END,
                    CASE WHEN cl.fecha_inicio <= @periodo THEN cl.fecha_inicio END DESC,
                    CASE WHEN cl.fecha_inicio > @periodo THEN cl.fecha_inicio END ASC,
                    cl.id_contrato_local DESC
            ) map
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4
              AND map.id_tienda IS NOT NULL
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = map.id_tienda
              ))
         )
         INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
         )
         SELECT
            dc.id_documento_cobro,
            1000 + ROW_NUMBER() OVER (
                PARTITION BY dc.id_documento_cobro
                ORDER BY
                    sm.codigo_servicio,
                    " . msp2LocalCodeNaturalOrderSql('sm.cdo_local') . ",
                    sm.codigo_medidor,
                    sm.id_cobro_servicio
            ),
            CASE sm.codigo_servicio
                WHEN N'AGUA' THEN @id_item_agua
                WHEN N'LUZ'  THEN @id_item_luz
                WHEN N'GAS'  THEN @id_item_gas
                ELSE @id_item_gas
            END,
            CONCAT(sm.nombre_servicio, N' local ', sm.cdo_local, N' medidor ', sm.codigo_medidor),
            CASE WHEN sm.consumo_cobrado > 0 THEN sm.consumo_cobrado ELSE 1 END,
            CASE
                WHEN sm.consumo_cobrado > 0 THEN ROUND(sm.monto_total / sm.consumo_cobrado, 2)
                ELSE sm.monto_total
            END,
            sm.monto_total,
            sm.id_cobro_servicio
         FROM servicios_mapeados sm
            INNER JOIN dbo.msp_documentos_cobro dc
               ON dc.id_tienda = sm.id_tienda
              AND dc.id_contrato_arriendo = sm.id_contrato_arriendo
              AND dc.periodo_facturacion = @periodo
           AND dc.estado_documento <> 5;

         SET @items_servicios_recompuestos = @@ROWCOUNT;

         IF @aplicar_cargos_extra = 1
            AND OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NOT NULL
            AND OBJECT_ID(N'dbo.msp_tipos_cargo_salida', N'U') IS NOT NULL
         BEGIN
            DECLARE @cargos_extra_insertados TABLE (
                id_documento_cobro INT NOT NULL,
                id_cargo_salida INT NOT NULL
            );

            ;WITH cargos_extra_fuente AS (
                SELECT
                    dc.id_documento_cobro,
                    cs.id_cargo_salida,
                    CASE UPPER(LTRIM(RTRIM(ISNULL(tc.codigo_tipo_cargo, N''))))
                        WHEN N'MULTA' THEN ISNULL(@id_item_multa, ISNULL(@id_item_ajuste, @id_item_dano))
                        WHEN N'DANOS' THEN ISNULL(@id_item_dano, ISNULL(@id_item_ajuste, @id_item_multa))
                        ELSE ISNULL(@id_item_ajuste, ISNULL(@id_item_multa, @id_item_dano))
                    END AS id_tipo_item_documento,
                    CONCAT(
                        CASE UPPER(LTRIM(RTRIM(ISNULL(tc.codigo_tipo_cargo, N''))))
                            WHEN N'DANOS' THEN N'Daños/Reparaciones'
                            WHEN N'MULTA' THEN N'Multa'
                            WHEN N'OTRO' THEN N'Otro cargo'
                            ELSE COALESCE(NULLIF(tc.nombre_tipo_cargo, N''), N'Otro cargo')
                        END,
                        CASE
                            WHEN NULLIF(LTRIM(RTRIM(ISNULL(loc_extra.cdo_local, N''))), N'') IS NULL THEN N''
                            ELSE CONCAT(N' local ', LTRIM(RTRIM(loc_extra.cdo_local)))
                        END,
                        N': ',
                        LEFT(LTRIM(RTRIM(ISNULL(cs.descripcion_cargo, N''))), 240)
                    ) AS descripcion_item,
                    cs.monto_cargo
                FROM dbo.msp_cargos_salida cs
                INNER JOIN dbo.msp_tipos_cargo_salida tc
                    ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
                LEFT JOIN dbo.msp_locales loc_extra
                    ON loc_extra.id_local = cs.id_local
                INNER JOIN dbo.msp_documentos_cobro dc
                    ON dc.id_tienda = ca.id_tienda
                   AND dc.id_contrato_arriendo = ca.id_contrato_arriendo
                   AND dc.periodo_facturacion = @periodo
                   AND dc.estado_documento <> 5
                WHERE (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
                ))
                  AND cs.estado_cargo IN (1, 2)
                  AND cs.id_documento_cobro IS NULL
                  AND ISNULL(cs.periodo_referencia, @periodo) = @periodo
                  AND cs.monto_cargo > 0
            )
            MERGE dbo.msp_documentos_cobro_detalle AS tgt
            USING (
                SELECT
                    src.id_documento_cobro,
                    src.id_cargo_salida,
                    src.id_tipo_item_documento,
                    src.descripcion_item,
                    src.monto_cargo,
                    3000 + ROW_NUMBER() OVER (
                        PARTITION BY src.id_documento_cobro
                        ORDER BY src.id_cargo_salida
                    ) AS orden_item
                FROM cargos_extra_fuente src
                WHERE src.id_tipo_item_documento IS NOT NULL
            ) AS src
            ON 1 = 0
            WHEN NOT MATCHED THEN
                INSERT (
                    id_documento_cobro,
                    orden_item,
                    id_tipo_item_documento,
                    descripcion_item,
                    cantidad,
                    valor_unitario,
                    subtotal,
                    id_cobro_servicio
                )
                VALUES (
                    src.id_documento_cobro,
                    src.orden_item,
                    src.id_tipo_item_documento,
                    src.descripcion_item,
                    1,
                    ROUND(src.monto_cargo, 2),
                    ROUND(src.monto_cargo, 2),
                    NULL
                )
            OUTPUT inserted.id_documento_cobro, src.id_cargo_salida
            INTO @cargos_extra_insertados (id_documento_cobro, id_cargo_salida);

            SET @items_cargos_extra_recompuestos = @@ROWCOUNT;

            UPDATE cs
            SET
                cs.id_documento_cobro = cei.id_documento_cobro,
                cs.estado_cargo = 3,
                cs.periodo_referencia = ISNULL(cs.periodo_referencia, @periodo)
            FROM dbo.msp_cargos_salida cs
            INNER JOIN @cargos_extra_insertados cei
                ON cei.id_cargo_salida = cs.id_cargo_salida;
         END;

         IF OBJECT_ID(N'dbo.msp_reglas_cobro_auto', N'U') IS NOT NULL
            AND OBJECT_ID(N'dbo.msp_cargos_auto_generados', N'U') IS NOT NULL
         BEGIN
            DECLARE @reglas_mora TABLE (
                id_regla_cobro_auto INT NOT NULL,
                id_tipo_item_documento INT NOT NULL,
                monto_unitario DECIMAL(18,2) NOT NULL,
                fecha_inicio_vigencia DATE NOT NULL,
                descripcion_regla NVARCHAR(120) NULL
            );

            INSERT INTO @reglas_mora (
                id_regla_cobro_auto,
                id_tipo_item_documento,
                monto_unitario,
                fecha_inicio_vigencia,
                descripcion_regla
            )
            SELECT
                r.id_regla_cobro_auto,
                r.id_tipo_item_documento,
                r.monto_unitario,
                r.fecha_inicio_vigencia,
                r.descripcion_regla
            FROM dbo.msp_reglas_cobro_auto r
            WHERE r.activo = 1
              AND r.codigo_regla = N'MORA_DIARIA_FIJA'
              AND r.monto_unitario > 0
              AND @periodo >= r.fecha_inicio_vigencia;

            IF EXISTS (SELECT 1 FROM @reglas_mora)
            BEGIN
                DECLARE @multa_insertada TABLE (
                    id_detalle_documento INT NOT NULL,
                    id_documento_cobro INT NOT NULL,
                    id_documento_origen_deuda INT NOT NULL,
                    id_regla_cobro_auto INT NOT NULL,
                    dias_mora_calculados INT NOT NULL,
                    fecha_vencimiento_origen DATE NOT NULL,
                    monto_generado DECIMAL(18,2) NOT NULL
                );

                ;WITH deudas_mora AS (
                    SELECT
                        dc_actual.id_documento_cobro,
                        dc_prev.id_documento_cobro AS id_documento_origen_deuda,
                        dc_prev.numero_documento AS numero_documento_origen,
                        dc_prev.fecha_vencimiento,
                        DATEDIFF(DAY, dc_prev.fecha_vencimiento, @periodo) AS dias_mora,
                        r.id_regla_cobro_auto,
                        r.id_tipo_item_documento,
                        r.monto_unitario,
                        r.descripcion_regla
                    FROM dbo.msp_documentos_cobro dc_actual
                    INNER JOIN dbo.msp_documentos_cobro dc_prev
                        ON dc_prev.id_tienda = dc_actual.id_tienda
                       AND dc_prev.id_contrato_arriendo = dc_actual.id_contrato_arriendo
                       AND dc_prev.periodo_facturacion < @periodo
                       AND dc_prev.estado_documento <> 5
                       AND dc_prev.saldo_pendiente > 0
                       AND dc_prev.fecha_vencimiento < @periodo
                    INNER JOIN @reglas_mora r
                        ON 1 = 1
                    WHERE dc_actual.periodo_facturacion = @periodo
                      AND dc_actual.estado_documento <> 5
                      AND (@has_target = 0 OR EXISTS (
                            SELECT 1
                            FROM @target_tiendas tt
                            WHERE tt.id_tienda = dc_actual.id_tienda
                      ))
                      AND dc_prev.fecha_vencimiento >= r.fecha_inicio_vigencia
                ),
                deudas_mora_validas AS (
                    SELECT
                        dm.id_documento_cobro,
                        dm.id_documento_origen_deuda,
                        dm.numero_documento_origen,
                        dm.fecha_vencimiento,
                        dm.dias_mora,
                        dm.id_regla_cobro_auto,
                        dm.id_tipo_item_documento,
                        dm.monto_unitario,
                        dm.descripcion_regla
                    FROM deudas_mora dm
                    WHERE dm.dias_mora > 0
                )
                MERGE dbo.msp_documentos_cobro_detalle AS tgt
                USING (
                    SELECT
                        src.id_documento_cobro,
                        src.id_documento_origen_deuda,
                        src.numero_documento_origen,
                        src.fecha_vencimiento,
                        src.dias_mora,
                        src.id_regla_cobro_auto,
                        src.id_tipo_item_documento,
                        src.monto_unitario,
                        src.descripcion_regla,
                        2000 + ROW_NUMBER() OVER (
                            PARTITION BY src.id_documento_cobro
                            ORDER BY src.id_regla_cobro_auto, src.fecha_vencimiento, src.id_documento_origen_deuda
                        ) AS orden_item
                    FROM deudas_mora_validas src
                ) AS src
                ON 1 = 0
                WHEN NOT MATCHED THEN
                    INSERT (
                        id_documento_cobro,
                        orden_item,
                        id_tipo_item_documento,
                        descripcion_item,
                        cantidad,
                        valor_unitario,
                        subtotal,
                        id_cobro_servicio
                    )
                    VALUES (
                        src.id_documento_cobro,
                        src.orden_item,
                        src.id_tipo_item_documento,
                        CONCAT(
                            N'Multa mora diaria automatica',
                            CASE
                                WHEN NULLIF(LTRIM(RTRIM(ISNULL(src.descripcion_regla, N''))), N'') IS NULL THEN N''
                                ELSE CONCAT(N' [', src.descripcion_regla, N']')
                            END,
                            N' por deuda ',
                            COALESCE(NULLIF(src.numero_documento_origen, N''), CONCAT(N'#', CONVERT(NVARCHAR(20), src.id_documento_origen_deuda))),
                            N' (venc. ',
                            CONVERT(NVARCHAR(10), src.fecha_vencimiento, 23),
                            N', ',
                            CONVERT(NVARCHAR(20), src.dias_mora),
                            N' dia(s))'
                        ),
                        CAST(src.dias_mora AS DECIMAL(18,4)),
                        src.monto_unitario,
                        ROUND(CAST(src.dias_mora AS DECIMAL(18,4)) * src.monto_unitario, 2),
                        NULL
                    )
                OUTPUT
                    inserted.id_detalle_documento,
                    inserted.id_documento_cobro,
                    src.id_documento_origen_deuda,
                    src.id_regla_cobro_auto,
                    src.dias_mora,
                    src.fecha_vencimiento,
                    inserted.subtotal
                INTO @multa_insertada (
                    id_detalle_documento,
                    id_documento_cobro,
                    id_documento_origen_deuda,
                    id_regla_cobro_auto,
                    dias_mora_calculados,
                    fecha_vencimiento_origen,
                    monto_generado
                );

                INSERT INTO dbo.msp_cargos_auto_generados (
                    id_regla_cobro_auto,
                    id_documento_cobro,
                    id_documento_origen_deuda,
                    id_detalle_documento,
                    periodo_calculo,
                    fecha_vencimiento_origen,
                    dias_mora_calculados,
                    monto_unitario_aplicado,
                    monto_generado
                )
                SELECT
                    mi.id_regla_cobro_auto,
                    mi.id_documento_cobro,
                    mi.id_documento_origen_deuda,
                    mi.id_detalle_documento,
                    @periodo,
                    mi.fecha_vencimiento_origen,
                    mi.dias_mora_calculados,
                    r.monto_unitario,
                    mi.monto_generado
                FROM @multa_insertada mi
                INNER JOIN @reglas_mora r
                    ON r.id_regla_cobro_auto = mi.id_regla_cobro_auto;

                SET @items_multa_recompuestos = @@ROWCOUNT;
            END;
         END
         ELSE IF @id_item_multa IS NOT NULL
         BEGIN
            -- Fallback de compatibilidad en ambientes donde aun no existe la tabla de reglas.
            DECLARE @multa_mora_diaria_fallback DECIMAL(18,2) = 1000;
            DECLARE @fecha_activacion_mora_fallback DATE = CONVERT(DATE, '2026-04-01');

            IF @multa_mora_diaria_fallback > 0 AND @periodo >= @fecha_activacion_mora_fallback
            BEGIN
                ;WITH deudas_mora AS (
                    SELECT
                        dc_actual.id_documento_cobro,
                        dc_prev.id_documento_cobro AS id_documento_origen,
                        dc_prev.numero_documento AS numero_documento_origen,
                        dc_prev.fecha_vencimiento,
                        DATEDIFF(DAY, dc_prev.fecha_vencimiento, @periodo) AS dias_mora
                    FROM dbo.msp_documentos_cobro dc_actual
                    INNER JOIN dbo.msp_documentos_cobro dc_prev
                        ON dc_prev.id_tienda = dc_actual.id_tienda
                       AND dc_prev.id_contrato_arriendo = dc_actual.id_contrato_arriendo
                       AND dc_prev.periodo_facturacion < @periodo
                       AND dc_prev.estado_documento <> 5
                       AND dc_prev.saldo_pendiente > 0
                       AND dc_prev.fecha_vencimiento >= @fecha_activacion_mora_fallback
                       AND dc_prev.fecha_vencimiento < @periodo
                    WHERE dc_actual.periodo_facturacion = @periodo
                      AND dc_actual.estado_documento <> 5
                      AND (@has_target = 0 OR EXISTS (
                            SELECT 1
                            FROM @target_tiendas tt
                            WHERE tt.id_tienda = dc_actual.id_tienda
                      ))
                ),
                deudas_mora_validas AS (
                    SELECT
                        dm.id_documento_cobro,
                        dm.id_documento_origen,
                        dm.numero_documento_origen,
                        dm.fecha_vencimiento,
                        dm.dias_mora
                    FROM deudas_mora dm
                    WHERE dm.dias_mora > 0
                )
                INSERT INTO dbo.msp_documentos_cobro_detalle (
                    id_documento_cobro,
                    orden_item,
                    id_tipo_item_documento,
                    descripcion_item,
                    cantidad,
                    valor_unitario,
                    subtotal,
                    id_cobro_servicio
                )
                SELECT
                    dmv.id_documento_cobro,
                    2000 + ROW_NUMBER() OVER (
                        PARTITION BY dmv.id_documento_cobro
                        ORDER BY dmv.fecha_vencimiento, dmv.id_documento_origen
                    ),
                    @id_item_multa,
                    CONCAT(
                        N'Multa mora diaria automatica por deuda ',
                        COALESCE(NULLIF(dmv.numero_documento_origen, N''), CONCAT(N'#', CONVERT(NVARCHAR(20), dmv.id_documento_origen))),
                        N' (venc. ',
                        CONVERT(NVARCHAR(10), dmv.fecha_vencimiento, 23),
                        N', ',
                        CONVERT(NVARCHAR(20), dmv.dias_mora),
                        N' dia(s))'
                    ),
                    CAST(dmv.dias_mora AS DECIMAL(18,4)),
                    @multa_mora_diaria_fallback,
                    ROUND(CAST(dmv.dias_mora AS DECIMAL(18,4)) * @multa_mora_diaria_fallback, 2),
                    NULL
                FROM deudas_mora_validas dmv;

                SET @items_multa_recompuestos = @@ROWCOUNT;
            END;
         END;

         ;WITH resumen AS (
            SELECT
                dcd.id_documento_cobro,
                SUM(CASE WHEN tid.codigo_item = N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_arriendo,
                SUM(CASE WHEN tid.codigo_item <> N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_servicios
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = dc.id_tienda
              ))
            GROUP BY dcd.id_documento_cobro
         ),
         pagos AS (
            SELECT
                p.id_documento_cobro,
                SUM(CASE WHEN p.estado_pago = 1 THEN p.monto_pagado ELSE 0 END) AS total_pagado
            FROM dbo.msp_pagos p
            GROUP BY p.id_documento_cobro
         )
         UPDATE dc
         SET
            dc.subtotal_arriendo = ROUND(ISNULL(r.subtotal_arriendo, 0), 2),
            dc.subtotal_servicios = ROUND(ISNULL(r.subtotal_servicios, 0), 2),
            dc.monto_total = ROUND((ISNULL(r.subtotal_arriendo, 0) * (1 + @tasa_iva)) + ISNULL(r.subtotal_servicios, 0), 2),
            dc.saldo_pendiente = CASE
                WHEN dc.estado_documento = 5 THEN dc.saldo_pendiente
                ELSE ROUND(
                    CASE
                        WHEN (((ISNULL(r.subtotal_arriendo, 0) * (1 + @tasa_iva)) + ISNULL(r.subtotal_servicios, 0)) - ISNULL(pg.total_pagado, 0)) < 0
                            THEN 0
                        ELSE (((ISNULL(r.subtotal_arriendo, 0) * (1 + @tasa_iva)) + ISNULL(r.subtotal_servicios, 0)) - ISNULL(pg.total_pagado, 0))
                    END,
                    2
                )
            END,
            dc.estado_documento = CASE
                WHEN dc.estado_documento = 5 THEN 5
                WHEN ISNULL(pg.total_pagado, 0) <= 0 THEN 2
                WHEN ISNULL(pg.total_pagado, 0) < ((ISNULL(r.subtotal_arriendo, 0) * (1 + @tasa_iva)) + ISNULL(r.subtotal_servicios, 0)) THEN 3
                ELSE 4
            END
         FROM dbo.msp_documentos_cobro dc
         LEFT JOIN resumen r
            ON r.id_documento_cobro = dc.id_documento_cobro
         LEFT JOIN pagos pg
            ON pg.id_documento_cobro = dc.id_documento_cobro
         WHERE dc.periodo_facturacion = @periodo
           AND (@has_target = 0 OR EXISTS (
                SELECT 1
                FROM @target_tiendas tt
                WHERE tt.id_tienda = dc.id_tienda
           ));

         SELECT
            @docs_creados AS docs_creados,
            (@items_recompuestos + @items_servicios_recompuestos + @items_multa_recompuestos + @items_cargos_extra_recompuestos) AS items_recompuestos;"
    );
    $reconStmt->bindValue(':id', $idCierre, PDO::PARAM_INT);
    $reconStmt->bindValue(':aplicar_cargos_extra', $aplicarCargosExtra ? 1 : 0, PDO::PARAM_INT);
    $reconStmt->bindValue(':service_profile', $serviceProfile, PDO::PARAM_STR);
    $reconStmt->bindValue(':target_tiendas_csv', $targetTiendaCsv, PDO::PARAM_STR);
    $reconStmt->bindValue(':dias_vencimiento', max(0, min(120, $dias)), PDO::PARAM_INT);
    $reconStmt->execute();
    $reconRes = omFetchFirstRowsetRow($reconStmt);
    $itemsRecompuestos = (int) ($reconRes['items_recompuestos'] ?? 0);
    if (!$runFullGeneration) {
        $docsGenerados = (int) ($reconRes['docs_creados'] ?? 0);
    }

    return [
        'documentos_generados' => $docsGenerados,
        'items_generados' => $itemsGenerados,
        'items_recompuestos' => $itemsRecompuestos,
    ];
}

    public static function pruneIncompleteDocumentsByCompletionStage(PDO $conn, string $periodoFacturacion, string $etapa): int
    {
        $etapa = strtoupper(trim($etapa));
        $filtroEtapa = match ($etapa) {
            'LUZ' => "spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 0",
            'GAS' => "spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 0",
            'AGUA' => "(spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 1) OR (spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 1)",
            default => null,
        };
        if ($filtroEtapa === null) {
            throw new RuntimeException('Etapa inválida para depurar documentos incompletos.');
        }

        $excluirLoteSql = '';
        if (msp2TableExists($conn, 'msp_envio_lote_documentos')) {
            $excluirLoteSql = 'AND NOT EXISTS (
                    SELECT 1
                    FROM dbo.msp_envio_lote_documentos eld
                    WHERE eld.id_documento_cobro = dc.id_documento_cobro
                )';
        }

        $excluirPagosSql = '';
        if (msp2TableExists($conn, 'msp_pagos')) {
            $excluirPagosSql = 'AND NOT EXISTS (
                    SELECT 1
                    FROM dbo.msp_pagos p
                    WHERE p.id_documento_cobro = dc.id_documento_cobro
                )';
        }

        $sql = "DECLARE @periodo DATE = :periodo;
            DECLARE @docs_incompletos TABLE (
                id_documento_cobro INT NOT NULL PRIMARY KEY
            );

            ;WITH servicios_por_contrato AS (
                SELECT
                    ca.id_tienda,
                    ca.id_contrato_arriendo,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS has_luz,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS has_gas,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS has_agua
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_medidores m
                    ON m.id_local = cl.id_local
                   AND m.estado_medidor IN (1,2)
                   AND (m.fecha_instalacion IS NULL OR m.fecha_instalacion <= EOMONTH(@periodo))
                   AND (m.fecha_retiro IS NULL OR m.fecha_retiro >= DATEADD(MONTH, -1, @periodo))
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = m.id_tipo_servicio
                WHERE cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        cl.fecha_termino IS NULL
                        OR cl.fecha_termino >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), MONTH(ISNULL(cl.fecha_termino, ca.fecha_termino_efectiva)), 1)) = @periodo
                  )
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (
                        ca.fecha_termino_efectiva IS NULL
                        OR ca.fecha_termino_efectiva >= @periodo
                        OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = @periodo
                  )
                  AND ca.estado_contrato IN (1,2,3,4)
                GROUP BY ca.id_tienda, ca.id_contrato_arriendo
            ),
            docs_etapa AS (
                SELECT
                    dc.id_documento_cobro,
                    spt.has_luz,
                    spt.has_gas,
                    spt.has_agua
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN servicios_por_contrato spt
                    ON spt.id_tienda = dc.id_tienda
                   AND spt.id_contrato_arriendo = dc.id_contrato_arriendo
                WHERE dc.periodo_facturacion = @periodo
                  AND dc.estado_documento <> 5
                  AND ($filtroEtapa)
                  $excluirLoteSql
                  $excluirPagosSql
            ),
            doc_flags AS (
                SELECT
                    dcd.id_documento_cobro,
                    MAX(CASE WHEN tid.codigo_item = N'SERVICIO_LUZ' THEN 1 ELSE 0 END) AS has_luz_item,
                    MAX(CASE WHEN tid.codigo_item = N'SERVICIO_GAS' THEN 1 ELSE 0 END) AS has_gas_item,
                    MAX(CASE WHEN tid.codigo_item = N'SERVICIO_AGUA' THEN 1 ELSE 0 END) AS has_agua_item
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_tipo_item_documento tid
                    ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
                WHERE EXISTS (
                    SELECT 1
                    FROM docs_etapa de
                    WHERE de.id_documento_cobro = dcd.id_documento_cobro
                )
                GROUP BY dcd.id_documento_cobro
            )
            INSERT INTO @docs_incompletos (id_documento_cobro)
            SELECT DISTINCT de.id_documento_cobro
            FROM docs_etapa de
            LEFT JOIN doc_flags df
                ON df.id_documento_cobro = de.id_documento_cobro
            WHERE ISNULL(df.has_luz_item, 0) = 0
               OR (de.has_gas = 1 AND ISNULL(df.has_gas_item, 0) = 0)
               OR (de.has_agua = 1 AND ISNULL(df.has_agua_item, 0) = 0);

            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN @docs_incompletos di
                ON di.id_documento_cobro = dcd.id_documento_cobro;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN @docs_incompletos di
                ON di.id_documento_cobro = dc.id_documento_cobro;

            SELECT @@ROWCOUNT AS docs_pruned;";

        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':periodo', $periodoFacturacion, PDO::PARAM_STR);
        $stmt->execute();
        $row = omFetchFirstRowsetRow($stmt);

        return (int) ($row['docs_pruned'] ?? 0);
    }

    private static function generateArriendoSnapshotForCierre(
        PDO $conn,
        int $idCierre,
        int $reemplazar,
        string $targetTiendaCsv
    ): void {
        if (!msp2TableExists($conn, 'msp_arriendo_local_snapshot_periodo')) {
            throw new RuntimeException('No existe `msp_arriendo_local_snapshot_periodo`. Debes aplicar Fase 1/2 de arriendo para generar documentos.');
        }

        $procExistsStmt = $conn->query(
            "SELECT 1
             WHERE OBJECT_ID(N'dbo.msp_generar_snapshot_arriendo_periodo', N'P') IS NOT NULL"
        );
        $procExists = $procExistsStmt !== false && $procExistsStmt->fetchColumn() !== false;
        if (!$procExists) {
            throw new RuntimeException('No existe `dbo.msp_generar_snapshot_arriendo_periodo`. Debes aplicar Fase 2 de arriendo para generar documentos.');
        }

        self::validateArriendoInputsForCierre($conn, $idCierre, $targetTiendaCsv);

        $stmt = $conn->prepare(
            'EXEC dbo.msp_generar_snapshot_arriendo_periodo
                @id_cierre_mensual = :id_cierre,
                @reemplazar = :reemplazar,
                @congelar = 1,
                @target_tiendas_csv = :target_tiendas_csv'
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':reemplazar', $reemplazar === 1 ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':target_tiendas_csv', $targetTiendaCsv, PDO::PARAM_STR);
        $stmt->execute();
        try {
            $stmt->closeCursor();
        } catch (Throwable) {
            // El SP puede dejar rowsets intermedios sin campos; no se consume su salida.
        }

        $prorrataExistsStmt = $conn->query(
            "SELECT 1 WHERE OBJECT_ID(N'dbo.msp_ajustar_snapshot_arriendo_inicio_prorrata', N'P') IS NOT NULL"
        );
        if ($prorrataExistsStmt === false || $prorrataExistsStmt->fetchColumn() === false) {
            throw new RuntimeException('No existe el prorrateo de inicio. Aplica `msp/db/patch_arriendo_inicio_prorrata.sql`.');
        }

        $periodoStmt = $conn->prepare(
            'SELECT periodo_facturacion FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual = :id_cierre'
        );
        $periodoStmt->execute([':id_cierre' => $idCierre]);
        $periodoFacturacion = $periodoStmt->fetchColumn();
        if ($periodoFacturacion === false) {
            throw new RuntimeException('No fue posible determinar el período para prorratear el arriendo.');
        }

        $prorrataStmt = $conn->prepare(
            'EXEC dbo.msp_ajustar_snapshot_arriendo_inicio_prorrata
                @periodo_facturacion = :periodo,
                @target_tiendas_csv = :target_tiendas_csv'
        );
        $prorrataStmt->execute([
            ':periodo' => (string) $periodoFacturacion,
            ':target_tiendas_csv' => $targetTiendaCsv,
        ]);
        try {
            $prorrataStmt->closeCursor();
        } catch (Throwable) {
            // El resultado solo informa cuántos snapshots fueron ajustados.
        }

        // El primer mes se cobra proporcionalmente desde la fecha de inicio inclusiva.
        // Regla operativa vigente: el mes de termino cobra arriendo completo.
        // Desde el mes siguiente, el snapshot ya excluye el contrato/local por fecha de termino.
    }

    private static function validateArriendoInputsForCierre(PDO $conn, int $idCierre, string $targetTiendaCsv): void
    {
        $requiredTables = [
            'msp_cierre_mensual',
            'msp_contratos_arriendo',
            'msp_contrato_locales',
            'msp_locales',
            'msp_tiendas',
            'msp_contrato_local_arriendo_regla',
            'msp_tipo_modalidad_arriendo',
            'msp_contrato_local_arriendo_periodo',
            'msp_descuento_arriendo',
            'msp_descuento_arriendo_contrato_local',
        ];
        $missingTables = [];
        foreach ($requiredTables as $tableName) {
            if (!msp2TableExists($conn, $tableName)) {
                $missingTables[] = $tableName;
            }
        }
        if ($missingTables !== []) {
            throw new RuntimeException('Faltan tablas para validar arriendo antes de generar documentos: `' . implode('`, `', $missingTables) . '`.');
        }

        $stmt = $conn->prepare(
            "DECLARE @id_cierre INT = :id_cierre;
             DECLARE @target_tiendas_csv NVARCHAR(MAX) = :target_tiendas_csv;
             DECLARE @periodo DATE;
             DECLARE @valor_uf DECIMAL(18,6);
             DECLARE @target_tiendas TABLE (
                id_tienda INT NOT NULL PRIMARY KEY
             );
             DECLARE @has_target BIT = 0;

             SELECT
                @periodo = c.periodo_facturacion,
                @valor_uf = c.valor_uf
             FROM dbo.msp_cierre_mensual c
             WHERE c.id_cierre_mensual = @id_cierre;

             IF @periodo IS NULL
             BEGIN
                ;THROW 50821, 'No existe cierre mensual para validar arriendo.', 1;
             END;

             IF @valor_uf IS NULL OR @valor_uf <= 0
             BEGIN
                ;THROW 50822, 'El cierre mensual no tiene valor UF valido para validar arriendo.', 1;
             END;

             IF NULLIF(LTRIM(RTRIM(ISNULL(@target_tiendas_csv, N''))), N'') IS NOT NULL
             BEGIN
                INSERT INTO @target_tiendas (id_tienda)
                SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT)
                FROM STRING_SPLIT(@target_tiendas_csv, N',') ss
                WHERE TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) IS NOT NULL
                  AND TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) > 0;
             END;

             IF EXISTS (SELECT 1 FROM @target_tiendas)
             BEGIN
                SET @has_target = 1;
             END;

             ;WITH base_locales AS (
                SELECT
                    ca.id_tienda,
                    ca.id_contrato_arriendo,
                    cl.id_contrato_local,
                    cl.id_local,
                    COALESCE(NULLIF(LTRIM(RTRIM(t.nombre_comercial)), N''), CONCAT(N'Tienda #', ca.id_tienda)) AS nombre_tienda,
                    COALESCE(NULLIF(LTRIM(RTRIM(loc.cdo_local)), N''), CONCAT(N'Local #', cl.id_local)) AS codigo_local
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_locales loc
                    ON loc.id_local = cl.id_local
                INNER JOIN dbo.msp_tiendas t
                    ON t.id_tienda = ca.id_tienda
                WHERE cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
                  AND (@has_target = 0 OR EXISTS (
                        SELECT 1
                        FROM @target_tiendas tt
                        WHERE tt.id_tienda = ca.id_tienda
                  ))
             ),
             base_with_rule AS (
                SELECT
                    b.id_tienda,
                    b.id_contrato_arriendo,
                    b.id_contrato_local,
                    b.id_local,
                    b.nombre_tienda,
                    b.codigo_local,
                    r.id_regla_arriendo,
                    r.valor_base_uf,
                    r.valor_base_clp,
                    UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'')))) AS codigo_modalidad
                FROM base_locales b
                OUTER APPLY (
                    SELECT TOP (1)
                        rr.id_regla_arriendo,
                        rr.id_modalidad_arriendo,
                        rr.valor_base_uf,
                        rr.valor_base_clp,
                        rr.prioridad,
                        rr.es_default
                    FROM dbo.msp_contrato_local_arriendo_regla rr
                    WHERE rr.id_contrato_local = b.id_contrato_local
                      AND rr.estado_regla = 1
                      AND rr.fecha_inicio <= EOMONTH(@periodo)
                      AND (rr.fecha_termino IS NULL OR rr.fecha_termino >= @periodo)
                    ORDER BY
                        CASE WHEN rr.es_default = 1 THEN 1 ELSE 0 END DESC,
                        rr.prioridad DESC,
                        rr.id_regla_arriendo DESC
                ) r
                LEFT JOIN dbo.msp_tipo_modalidad_arriendo tm
                    ON tm.id_modalidad_arriendo = r.id_modalidad_arriendo
             ),
             base_with_period AS (
                SELECT
                    br.id_tienda,
                    br.id_contrato_arriendo,
                    br.id_contrato_local,
                    br.id_local,
                    br.nombre_tienda,
                    br.codigo_local,
                    br.id_regla_arriendo,
                    br.valor_base_uf,
                    br.valor_base_clp,
                    br.codigo_modalidad,
                    ap.valor_periodo_uf,
                    ap.valor_periodo_clp
                FROM base_with_rule br
                LEFT JOIN dbo.msp_contrato_local_arriendo_periodo ap
                    ON ap.id_contrato_local = br.id_contrato_local
                   AND ap.periodo_facturacion = @periodo
                   AND ap.estado_periodo = 1
             ),
             base_with_discount AS (
                SELECT
                    bp.id_tienda,
                    bp.id_contrato_arriendo,
                    bp.id_contrato_local,
                    bp.id_local,
                    bp.nombre_tienda,
                    bp.codigo_local,
                    bp.id_regla_arriendo,
                    bp.valor_base_uf,
                    bp.valor_base_clp,
                    bp.codigo_modalidad,
                    bp.valor_periodo_uf,
                    bp.valor_periodo_clp,
                    ISNULL(dc.descuentos_activos, 0) AS descuentos_activos,
                    de.tipo_monto AS tipo_descuento,
                    de.valor_descuento
                FROM base_with_period bp
                OUTER APPLY (
                    SELECT COUNT_BIG(1) AS descuentos_activos
                    FROM dbo.msp_descuento_arriendo_contrato_local dcl
                    INNER JOIN dbo.msp_descuento_arriendo d
                        ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
                    WHERE dcl.id_contrato_local = bp.id_contrato_local
                      AND dcl.estado_asignacion = 1
                      AND d.estado_descuento = 1
                      AND d.periodo_desde <= @periodo
                      AND (d.periodo_hasta IS NULL OR d.periodo_hasta >= @periodo)
                ) dc
                OUTER APPLY (
                    SELECT TOP (1)
                        UPPER(LTRIM(RTRIM(ISNULL(d.tipo_monto, N'')))) AS tipo_monto,
                        d.valor_descuento
                    FROM dbo.msp_descuento_arriendo_contrato_local dcl
                    INNER JOIN dbo.msp_descuento_arriendo d
                        ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
                    WHERE dcl.id_contrato_local = bp.id_contrato_local
                      AND dcl.estado_asignacion = 1
                      AND d.estado_descuento = 1
                      AND d.periodo_desde <= @periodo
                      AND (d.periodo_hasta IS NULL OR d.periodo_hasta >= @periodo)
                    ORDER BY d.periodo_desde DESC, d.id_descuento_arriendo DESC
                ) de
             ),
             issues AS (
                SELECT
                    bwd.nombre_tienda,
                    bwd.codigo_local,
                    bwd.id_contrato_arriendo,
                    CASE
                        WHEN bwd.id_regla_arriendo IS NULL THEN N'SIN_REGLA'
                        WHEN bwd.codigo_modalidad = N'' THEN N'SIN_MODALIDAD'
                        ELSE bwd.codigo_modalidad
                    END AS modalidad,
                    CASE
                        WHEN bwd.id_regla_arriendo IS NULL THEN N'sin regla de arriendo vigente'
                        WHEN bwd.codigo_modalidad = N'' THEN N'sin modalidad de arriendo vigente'
                        WHEN bwd.codigo_modalidad = N'UF_ESTATICO'
                         AND (bwd.valor_base_uf IS NULL OR bwd.valor_base_uf <= 0) THEN N'sin valor UF'
                        WHEN bwd.codigo_modalidad = N'CLP_FIJO'
                         AND (bwd.valor_base_clp IS NULL OR bwd.valor_base_clp <= 0) THEN N'sin valor CLP'
                        WHEN bwd.codigo_modalidad = N'DINAMICO_MENSUAL'
                         AND (bwd.valor_periodo_clp IS NULL OR bwd.valor_periodo_clp <= 0)
                         AND (bwd.valor_periodo_uf IS NULL OR bwd.valor_periodo_uf <= 0) THEN N'dinámico sin valor para el período'
                        WHEN bwd.codigo_modalidad NOT IN (N'UF_ESTATICO', N'CLP_FIJO', N'DINAMICO_MENSUAL') THEN N'modalidad inválida'
                        WHEN bwd.descuentos_activos > 1 THEN N'descuentos duplicados'
                        WHEN bwd.descuentos_activos = 1
                         AND bwd.tipo_descuento = N'UF_FIJO'
                         AND (bwd.valor_descuento IS NULL OR bwd.valor_descuento <= 0) THEN N'descuento UF sin valor'
                        WHEN bwd.descuentos_activos = 1
                         AND bwd.tipo_descuento = N'CLP_FIJO'
                         AND (bwd.valor_descuento IS NULL OR bwd.valor_descuento <= 0) THEN N'descuento CLP sin valor'
                        WHEN bwd.descuentos_activos = 1
                         AND bwd.tipo_descuento NOT IN (N'UF_FIJO', N'CLP_FIJO') THEN N'descuento sin tipo válido'
                        ELSE NULL
                    END AS causa
                FROM base_with_discount bwd
             ),
             invalid AS (
                SELECT
                    i.nombre_tienda,
                    i.codigo_local,
                    i.id_contrato_arriendo,
                    i.modalidad,
                    i.causa,
                    COUNT_BIG(1) OVER () AS total_inconsistencias
                FROM issues i
                WHERE i.causa IS NOT NULL
             )
             SELECT TOP (20)
                CONVERT(CHAR(10), @periodo, 126) AS periodo_facturacion,
                i.total_inconsistencias,
                i.nombre_tienda,
                i.codigo_local,
                i.id_contrato_arriendo,
                i.modalidad,
                i.causa
             FROM invalid i
             ORDER BY
                i.nombre_tienda ASC,
                i.codigo_local ASC,
                i.id_contrato_arriendo ASC;"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':target_tiendas_csv', $targetTiendaCsv, PDO::PARAM_STR);
        $stmt->execute();

        $rows = omFetchFirstRowsetRows($stmt);
        if ($rows === []) {
            return;
        }

        $total = (int) ($rows[0]['total_inconsistencias'] ?? count($rows));
        $periodo = trim((string) ($rows[0]['periodo_facturacion'] ?? ''));
        $examples = [];
        foreach ($rows as $row) {
            $examples[] = trim(
                (string) ($row['nombre_tienda'] ?? 'Tienda')
                . ' | Local ' . (string) ($row['codigo_local'] ?? '-')
                . ' | Contrato #' . (string) ($row['id_contrato_arriendo'] ?? '-')
                . ' | Modalidad ' . (string) ($row['modalidad'] ?? '-')
                . ' | ' . (string) ($row['causa'] ?? 'sin detalle')
            );
        }

        $message = 'No se pueden generar documentos: hay '
            . $total
            . ' contrato-local activo(s) con arriendo no calculable'
            . ($periodo !== '' ? ' para el período ' . $periodo : '')
            . '. Corrige reglas, valores dinámicos o descuentos vigentes y vuelve a generar. '
            . 'Detalle: '
            . implode('; ', $examples);

        if ($total > count($examples)) {
            $message .= '; y ' . ($total - count($examples)) . ' más.';
        }

        throw new RuntimeException($message);
    }

    private static function pruneDocsOutsideServiceProfile(PDO $conn, int $idCierre, string $serviceProfile): void
    {
        $stmt = $conn->prepare(
            "DECLARE @id_cierre INT = :id_cierre;
             DECLARE @profile NVARCHAR(30) = :service_profile;
             DECLARE @periodo DATE;
             DECLARE @tiendas_objetivo TABLE (
                id_tienda INT NOT NULL PRIMARY KEY
             );

             SELECT @periodo = c.periodo_facturacion
             FROM dbo.msp_cierre_mensual c
             WHERE c.id_cierre_mensual = @id_cierre;

             IF @periodo IS NULL
             BEGIN
                SELECT 0 AS docs_pruned;
                RETURN;
             END;

             ;WITH servicios_por_tienda AS (
                SELECT
                    ca.id_tienda,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'LUZ' THEN 1 ELSE 0 END) AS has_luz,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'GAS' THEN 1 ELSE 0 END) AS has_gas,
                    MAX(CASE WHEN UPPER(ts.codigo_servicio) = N'AGUA' THEN 1 ELSE 0 END) AS has_agua
                FROM dbo.msp_contrato_locales cl
                INNER JOIN dbo.msp_contratos_arriendo ca
                    ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
                INNER JOIN dbo.msp_medidores m
                    ON m.id_local = cl.id_local
                   AND m.estado_medidor IN (1,2)
                   AND (m.fecha_instalacion IS NULL OR m.fecha_instalacion <= EOMONTH(@periodo))
                   AND (m.fecha_retiro IS NULL OR m.fecha_retiro >= @periodo)
                INNER JOIN dbo.msp_tipos_servicio ts
                    ON ts.id_tipo_servicio = m.id_tipo_servicio
                WHERE cl.estado_relacion IN (1,2)
                  AND cl.fecha_inicio <= EOMONTH(@periodo)
                  AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo)
                  AND ca.fecha_inicio <= EOMONTH(@periodo)
                  AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo)
                  AND ca.estado_contrato IN (1,2,3)
                GROUP BY ca.id_tienda
             )
             INSERT INTO @tiendas_objetivo (id_tienda)
             SELECT spt.id_tienda
             FROM servicios_por_tienda spt
             WHERE @profile = N'ALL'
                OR (@profile = N'LUZ_ONLY' AND spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 0)
                OR (@profile = N'LUZ_GAS' AND spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 0)
                OR (@profile = N'LUZ_AGUA' AND spt.has_luz = 1 AND spt.has_gas = 0 AND spt.has_agua = 1)
                OR (@profile = N'LUZ_GAS_AGUA' AND spt.has_luz = 1 AND spt.has_gas = 1 AND spt.has_agua = 1)
                OR (@profile = N'LUZ_CON_AGUA' AND spt.has_luz = 1 AND spt.has_agua = 1);

             DELETE dcd
             FROM dbo.msp_documentos_cobro_detalle dcd
             INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
             LEFT JOIN @tiendas_objetivo tobj
                ON tobj.id_tienda = dc.id_tienda
             WHERE dc.periodo_facturacion = @periodo
               AND tobj.id_tienda IS NULL;

             DELETE dc
             FROM dbo.msp_documentos_cobro dc
             LEFT JOIN @tiendas_objetivo tobj
                ON tobj.id_tienda = dc.id_tienda
             WHERE dc.periodo_facturacion = @periodo
               AND tobj.id_tienda IS NULL;

             SELECT @@ROWCOUNT AS docs_pruned;"
        );
        $stmt->bindValue(':id_cierre', $idCierre, PDO::PARAM_INT);
        $stmt->bindValue(':service_profile', $serviceProfile, PDO::PARAM_STR);
        $stmt->execute();
        omFetchFirstRowsetRow($stmt);
    }
}
