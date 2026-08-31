/*
===========================================================================
 PATCH: CLP_FIJO como monto fijo de contrato
 - Crea grupo semántico CLP_FIJO_CONTRATO.
 - Migra reglas CLP_FIJO activas para usar el nuevo grupo.
 - Reaplica procedimientos de snapshot/prorrata sin hardcode OBRA/MODULAR.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_grupo_arriendo_especial', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (SELECT 1 FROM dbo.msp_grupo_arriendo_especial WHERE codigo_grupo = N'CLP_FIJO_CONTRATO')
    BEGIN
        INSERT INTO dbo.msp_grupo_arriendo_especial (
            codigo_grupo,
            descripcion_grupo,
            monto_neto_mensual_clp,
            cod_local_requerido_1,
            cod_local_requerido_2,
            estado_grupo
        )
        VALUES (
            N'CLP_FIJO_CONTRATO',
            N'Modalidad CLP fijo a nivel contrato (monto unico, no prorrateado por local).',
            1,
            NULL,
            NULL,
            1
        );
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_contrato_local_arriendo_regla', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.msp_tipo_modalidad_arriendo', N'U') IS NOT NULL
BEGIN
    UPDATE rr
    SET
        rr.codigo_grupo_modalidad = N'CLP_FIJO_CONTRATO',
        rr.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_contrato_local_arriendo_regla rr
    INNER JOIN dbo.msp_tipo_modalidad_arriendo tm
        ON tm.id_modalidad_arriendo = rr.id_modalidad_arriendo
    WHERE rr.estado_regla = 1
      AND UPPER(LTRIM(RTRIM(ISNULL(tm.codigo_modalidad, N'')))) = N'CLP_FIJO'
      AND ISNULL(rr.codigo_grupo_modalidad, N'') <> N'CLP_FIJO_CONTRATO';

    PRINT 'patch_clp_fijo_contrato: reglas CLP_FIJO migradas a grupo CLP_FIJO_CONTRATO = ' + CAST(@@ROWCOUNT AS NVARCHAR(20));
END;
GO
CREATE OR ALTER PROCEDURE dbo.msp_generar_snapshot_arriendo_periodo
    @id_cierre_mensual INT,
    @reemplazar BIT = 0,
    @congelar BIT = 1,
    @target_tiendas_csv NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo DATE;
    DECLARE @valor_uf DECIMAL(18,6);
    DECLARE @rows_afectadas INT = 0;
    DECLARE @snapshots_congelados INT = 0;
    DECLARE @has_target BIT = 0;

    IF ISNULL(@id_cierre_mensual, 0) <= 0
    BEGIN
        ;THROW 50801, 'Debes indicar un cierre mensual valido para snapshot de arriendo.', 1;
    END;

    SELECT
        @periodo = c.periodo_facturacion,
        @valor_uf = c.valor_uf
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo IS NULL
    BEGIN
        ;THROW 50802, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @valor_uf IS NULL OR @valor_uf <= 0
    BEGIN
        ;THROW 50803, 'El cierre mensual no tiene valor UF valido.', 1;
    END;

    DECLARE @target_tiendas TABLE (id_tienda INT NOT NULL PRIMARY KEY);

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

    /* Bloqueo funcional: no más de un descuento entidad activo para mismo contrato-local y periodo */
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT
                dcl.id_contrato_local,
                COUNT(*) AS cnt
            FROM dbo.msp_descuento_arriendo_contrato_local dcl
            INNER JOIN dbo.msp_descuento_arriendo d
                ON d.id_descuento_arriendo = dcl.id_descuento_arriendo
            INNER JOIN dbo.msp_contrato_locales cl
                ON cl.id_contrato_local = dcl.id_contrato_local
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE dcl.estado_asignacion = 1
              AND d.estado_descuento = 1
              AND d.periodo_desde <= @periodo
              AND (d.periodo_hasta IS NULL OR d.periodo_hasta >= @periodo)
              AND cl.estado_relacion IN (1,2)
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
            GROUP BY dcl.id_contrato_local
            HAVING COUNT(*) > 1
        ) q
    )
    BEGIN
        ;THROW 50811, 'Existe más de un descuento entidad activo para el mismo contrato-local en el período.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            DELETE s
            FROM dbo.msp_arriendo_local_snapshot_periodo s
            WHERE s.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = s.id_tienda
              ));
        END;

        ;WITH base_locales AS (
            SELECT
                ca.id_tienda,
                ca.id_contrato_arriendo,
                cl.id_contrato_local,
                cl.id_local,
                UPPER(LTRIM(RTRIM(ISNULL(loc.cdo_local, N'')))) AS cdo_local_key,
                CAST(ISNULL(loc.valor_arriendo_uf, 0) AS DECIMAL(18,6)) AS valor_arriendo_uf_local
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            INNER JOIN dbo.msp_locales loc
                ON loc.id_local = cl.id_local
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
                b.cdo_local_key,
                b.valor_arriendo_uf_local,
                r.id_regla_arriendo,
                ISNULL(r.id_modalidad_arriendo, CAST(1 AS TINYINT)) AS id_modalidad_arriendo,
                ISNULL(tm.codigo_modalidad, N'UF_ESTATICO') AS codigo_modalidad,
                CASE
                    WHEN r.id_regla_arriendo IS NULL THEN b.valor_arriendo_uf_local
                    ELSE r.valor_base_uf
                END AS valor_base_uf,
                r.valor_base_clp,
                ISNULL(r.descuento_mensual_clp, 0) AS descuento_regla_clp,
                r.codigo_grupo_modalidad
            FROM base_locales b
            OUTER APPLY (
                SELECT TOP (1)
                    rr.id_regla_arriendo,
                    rr.id_modalidad_arriendo,
                    rr.valor_base_uf,
                    rr.valor_base_clp,
                    rr.descuento_mensual_clp,
                    rr.codigo_grupo_modalidad,
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
                ON tm.id_modalidad_arriendo = ISNULL(r.id_modalidad_arriendo, CAST(1 AS TINYINT))
        ),
        base_with_period AS (
            SELECT
                br.id_tienda,
                br.id_contrato_arriendo,
                br.id_contrato_local,
                br.id_local,
                br.cdo_local_key,
                br.id_regla_arriendo,
                br.id_modalidad_arriendo,
                br.codigo_modalidad,
                br.valor_base_uf,
                br.valor_base_clp,
                br.descuento_regla_clp,
                br.codigo_grupo_modalidad,
                ap.valor_periodo_uf,
                ap.valor_periodo_clp,
                ap.descuento_periodo_clp
            FROM base_with_rule br
            LEFT JOIN dbo.msp_contrato_local_arriendo_periodo ap
                ON ap.id_contrato_local = br.id_contrato_local
               AND ap.periodo_facturacion = @periodo
               AND ap.estado_periodo = 1
        ),
        base_with_discount_entity AS (
            SELECT
                bp.id_tienda,
                bp.id_contrato_arriendo,
                bp.id_contrato_local,
                bp.id_local,
                bp.cdo_local_key,
                bp.id_regla_arriendo,
                bp.id_modalidad_arriendo,
                bp.codigo_modalidad,
                bp.valor_base_uf,
                bp.valor_base_clp,
                bp.descuento_regla_clp,
                bp.codigo_grupo_modalidad,
                bp.valor_periodo_uf,
                bp.valor_periodo_clp,
                bp.descuento_periodo_clp,
                de.id_descuento_arriendo,
                de.tipo_monto AS tipo_descuento_entidad,
                de.valor_descuento AS valor_descuento_entidad
            FROM base_with_period bp
            OUTER APPLY (
                SELECT TOP (1)
                    d.id_descuento_arriendo,
                    d.tipo_monto,
                    d.valor_descuento,
                    d.periodo_desde,
                    d.periodo_hasta
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
        calc_raw AS (
            SELECT
                bd.id_tienda,
                bd.id_contrato_arriendo,
                bd.id_contrato_local,
                bd.id_local,
                bd.cdo_local_key,
                bd.id_regla_arriendo,
                bd.id_modalidad_arriendo,
                bd.codigo_modalidad,
                bd.valor_base_uf,
                bd.valor_base_clp,
                bd.codigo_grupo_modalidad,
                bd.valor_periodo_uf,
                bd.valor_periodo_clp,
                bd.id_descuento_arriendo,
                bd.tipo_descuento_entidad,
                bd.valor_descuento_entidad,
                CASE
                    WHEN bd.id_descuento_arriendo IS NOT NULL THEN
                        CASE
                            WHEN bd.tipo_descuento_entidad = N'UF_FIJO' THEN ROUND(ISNULL(bd.valor_descuento_entidad, 0) * @valor_uf, 2)
                            ELSE ROUND(ISNULL(bd.valor_descuento_entidad, 0), 2)
                        END
                    ELSE ROUND(COALESCE(bd.descuento_periodo_clp, bd.descuento_regla_clp, 0), 2)
                END AS monto_descuento_clp_snapshot,
                CASE
                    WHEN bd.id_descuento_arriendo IS NOT NULL THEN N'ENTIDAD'
                    WHEN ROUND(COALESCE(bd.descuento_periodo_clp, bd.descuento_regla_clp, 0), 2) > 0 THEN N'LEGACY'
                    ELSE N'NINGUNO'
                END AS fuente_descuento,
                CASE
                    WHEN bd.id_modalidad_arriendo = 1
                        THEN ROUND(ISNULL(bd.valor_base_uf, 0) * @valor_uf, 2)
                    WHEN bd.id_modalidad_arriendo = 2
                        THEN ROUND(CASE
                                        WHEN bd.valor_periodo_clp IS NOT NULL THEN bd.valor_periodo_clp
                                        WHEN bd.valor_periodo_uf IS NOT NULL THEN bd.valor_periodo_uf * @valor_uf
                                        ELSE 0
                                   END, 2)
                    WHEN bd.id_modalidad_arriendo = 3
                        THEN ROUND(ISNULL(bd.valor_base_clp, 0), 2)
                    ELSE ROUND(ISNULL(bd.valor_base_uf, 0) * @valor_uf, 2)
                END AS base_neto_clp,
                ROW_NUMBER() OVER (
                    PARTITION BY bd.id_contrato_arriendo, CASE WHEN bd.id_modalidad_arriendo = 3 THEN 1 ELSE bd.id_contrato_local END
                    ORDER BY bd.id_contrato_local
                ) AS rn_modalidad_clp_fijo,
                MAX(CASE WHEN bd.id_modalidad_arriendo = 3 AND bd.codigo_grupo_modalidad = N'OBRA_MODULAR' THEN 1 ELSE 0 END)
                    OVER (PARTITION BY bd.id_contrato_arriendo) AS clp_fijo_legacy_obra_modular,
                CASE
                    WHEN MAX(CASE WHEN bd.id_modalidad_arriendo = 3 AND bd.codigo_grupo_modalidad = N'OBRA_MODULAR' THEN 1 ELSE 0 END)
                        OVER (PARTITION BY bd.id_contrato_arriendo) = 1
                        THEN SUM(
                            CASE
                                WHEN bd.id_modalidad_arriendo = 3 AND bd.codigo_grupo_modalidad = N'OBRA_MODULAR'
                                    THEN ROUND(ISNULL(bd.valor_base_clp, 0), 2)
                                ELSE 0
                            END
                        ) OVER (PARTITION BY bd.id_contrato_arriendo)
                    ELSE MAX(
                        CASE
                            WHEN bd.id_modalidad_arriendo = 3
                                THEN ROUND(ISNULL(bd.valor_base_clp, 0), 2)
                            ELSE NULL
                        END
                    ) OVER (PARTITION BY bd.id_contrato_arriendo)
                END AS clp_fijo_base_contrato,
                CASE
                    WHEN MAX(CASE WHEN bd.id_modalidad_arriendo = 3 AND bd.codigo_grupo_modalidad = N'OBRA_MODULAR' THEN 1 ELSE 0 END)
                        OVER (PARTITION BY bd.id_contrato_arriendo) = 1
                        THEN SUM(
                            CASE
                                WHEN bd.id_modalidad_arriendo = 3 AND bd.codigo_grupo_modalidad = N'OBRA_MODULAR'
                                    THEN ROUND(COALESCE(bd.descuento_periodo_clp, bd.descuento_regla_clp, 0), 2)
                                ELSE 0
                            END
                        ) OVER (PARTITION BY bd.id_contrato_arriendo)
                    ELSE MAX(
                        CASE
                            WHEN bd.id_modalidad_arriendo = 3
                                THEN ROUND(COALESCE(bd.descuento_periodo_clp, bd.descuento_regla_clp, 0), 2)
                            ELSE NULL
                        END
                    ) OVER (PARTITION BY bd.id_contrato_arriendo)
                END AS clp_fijo_descuento_contrato
            FROM base_with_discount_entity bd
        ),
        calc_final AS (
            SELECT
                cr.id_tienda,
                cr.id_contrato_arriendo,
                cr.id_contrato_local,
                cr.id_local,
                cr.id_regla_arriendo,
                cr.id_modalidad_arriendo,
                cr.valor_base_uf,
                cr.valor_base_clp,
                @valor_uf AS valor_uf_periodo,
                cr.id_descuento_arriendo,
                cr.tipo_descuento_entidad AS tipo_descuento_snapshot,
                cr.valor_descuento_entidad AS valor_descuento_snapshot,
                CASE
                    WHEN cr.id_modalidad_arriendo = 3 AND cr.rn_modalidad_clp_fijo > 1
                        THEN CAST(0 AS DECIMAL(18,2))
                    WHEN cr.id_modalidad_arriendo = 3
                        THEN CAST(ISNULL(cr.clp_fijo_descuento_contrato, 0) AS DECIMAL(18,2))
                    ELSE CAST(ISNULL(cr.monto_descuento_clp_snapshot, 0) AS DECIMAL(18,2))
                END AS descuento_aplicado_clp,
                CASE
                    WHEN cr.id_modalidad_arriendo = 3 AND cr.rn_modalidad_clp_fijo > 1
                        THEN CAST(0 AS DECIMAL(18,2))
                    WHEN cr.id_modalidad_arriendo = 3
                        THEN CAST(
                            CASE
                                WHEN (ISNULL(cr.clp_fijo_base_contrato, 0) - ISNULL(cr.clp_fijo_descuento_contrato, 0)) < 0
                                    THEN 0
                                ELSE ROUND(ISNULL(cr.clp_fijo_base_contrato, 0) - ISNULL(cr.clp_fijo_descuento_contrato, 0), 2)
                            END
                        AS DECIMAL(18,2))
                    WHEN (ISNULL(cr.base_neto_clp, 0) - ISNULL(cr.monto_descuento_clp_snapshot, 0)) < 0
                        THEN CAST(0 AS DECIMAL(18,2))
                    ELSE CAST(ROUND(ISNULL(cr.base_neto_clp, 0) - ISNULL(cr.monto_descuento_clp_snapshot, 0), 2) AS DECIMAL(18,2))
                END AS monto_neto_clp,
                CASE
                    WHEN cr.id_modalidad_arriendo = 3
                        THEN N'CLP_FIJO_CONTRATO'
                    ELSE cr.codigo_grupo_modalidad
                END AS codigo_grupo_modalidad,
                CASE
                    WHEN cr.id_modalidad_arriendo = 3 AND cr.rn_modalidad_clp_fijo > 1
                        THEN N'NINGUNO'
                    ELSE cr.fuente_descuento
                END AS fuente_descuento,
                CASE
                    WHEN cr.id_modalidad_arriendo = 3 AND cr.rn_modalidad_clp_fijo > 1
                        THEN CAST(0 AS DECIMAL(18,2))
                    WHEN cr.id_modalidad_arriendo = 3
                        THEN CAST(ISNULL(cr.clp_fijo_descuento_contrato, 0) AS DECIMAL(18,2))
                    ELSE CAST(ISNULL(cr.monto_descuento_clp_snapshot, 0) AS DECIMAL(18,2))
                END AS monto_descuento_clp_snapshot,
                cr.codigo_modalidad,
                cr.base_neto_clp
            FROM calc_raw cr
        )
        MERGE dbo.msp_arriendo_local_snapshot_periodo AS tgt
        USING (
            SELECT
                cf.id_tienda,
                cf.id_contrato_arriendo,
                cf.id_contrato_local,
                cf.id_local,
                cf.id_regla_arriendo,
                cf.id_modalidad_arriendo,
                cf.valor_base_uf,
                cf.valor_base_clp,
                cf.valor_uf_periodo,
                cf.id_descuento_arriendo,
                cf.tipo_descuento_snapshot,
                cf.valor_descuento_snapshot,
                cf.monto_descuento_clp_snapshot,
                cf.fuente_descuento,
                cf.descuento_aplicado_clp,
                cf.monto_neto_clp,
                CAST(ROUND(cf.monto_neto_clp * 0.19, 2) AS DECIMAL(18,2)) AS monto_iva_clp,
                CAST(ROUND(cf.monto_neto_clp * 1.19, 2) AS DECIMAL(18,2)) AS monto_total_clp,
                cf.codigo_grupo_modalidad,
                CAST(CASE WHEN @congelar = 1 THEN 2 ELSE 1 END AS TINYINT) AS estado_snapshot,
                CAST(CASE WHEN @congelar = 1 THEN 1 ELSE 0 END AS BIT) AS es_congelado,
                N'SP_DESCUENTO_ENTIDAD' AS fuente_calculo,
                CONCAT(
                    N'{"modalidad":"', cf.codigo_modalidad,
                    N'","base_neto_clp":', CONVERT(NVARCHAR(50), ISNULL(cf.base_neto_clp, 0)),
                    N',"fuente_descuento":"', cf.fuente_descuento,
                    N'","monto_descuento_clp":', CONVERT(NVARCHAR(50), ISNULL(cf.monto_descuento_clp_snapshot, 0)),
                    N',"id_descuento":', CONVERT(NVARCHAR(50), ISNULL(cf.id_descuento_arriendo, 0)),
                    N',"valor_uf_periodo":', CONVERT(NVARCHAR(50), ISNULL(cf.valor_uf_periodo, 0)),
                    N',"grupo":"', ISNULL(cf.codigo_grupo_modalidad, N''),
                    N'"}'
                ) AS formula_json,
                CASE
                    WHEN cf.id_modalidad_arriendo = 3
                        THEN N'Modalidad CLP_FIJO: monto fijo de contrato aplicado en linea consolidada.'
                    WHEN cf.fuente_descuento = N'ENTIDAD'
                        THEN N'Descuento aplicado desde entidad reusable.'
                    WHEN cf.fuente_descuento = N'LEGACY'
                        THEN N'Descuento aplicado desde configuración legacy de regla/período.'
                    WHEN cf.id_modalidad_arriendo = 2
                        THEN N'Modalidad DINAMICO_MENSUAL con valor mensual cargado por UI.'
                    ELSE N'Cálculo base de arriendo aplicado.'
                END AS detalle_calculo
            FROM calc_final cf
        ) AS src
        ON tgt.periodo_facturacion = @periodo
       AND tgt.id_contrato_local = src.id_contrato_local
        WHEN MATCHED AND (@reemplazar = 1 OR tgt.es_congelado = 0) THEN
            UPDATE SET
                tgt.id_tienda = src.id_tienda,
                tgt.id_contrato_arriendo = src.id_contrato_arriendo,
                tgt.id_local = src.id_local,
                tgt.id_regla_arriendo = src.id_regla_arriendo,
                tgt.id_modalidad_aplicada = src.id_modalidad_arriendo,
                tgt.valor_base_uf = src.valor_base_uf,
                tgt.valor_base_clp = src.valor_base_clp,
                tgt.valor_uf_periodo = src.valor_uf_periodo,
                tgt.id_descuento_arriendo = src.id_descuento_arriendo,
                tgt.tipo_descuento_snapshot = src.tipo_descuento_snapshot,
                tgt.valor_descuento_snapshot = src.valor_descuento_snapshot,
                tgt.monto_descuento_clp_snapshot = src.monto_descuento_clp_snapshot,
                tgt.fuente_descuento = src.fuente_descuento,
                tgt.descuento_aplicado_clp = src.descuento_aplicado_clp,
                tgt.monto_neto_clp = src.monto_neto_clp,
                tgt.monto_iva_clp = src.monto_iva_clp,
                tgt.monto_total_clp = src.monto_total_clp,
                tgt.codigo_grupo_modalidad = src.codigo_grupo_modalidad,
                tgt.estado_snapshot = src.estado_snapshot,
                tgt.es_congelado = src.es_congelado,
                tgt.fuente_calculo = src.fuente_calculo,
                tgt.formula_json = src.formula_json,
                tgt.detalle_calculo = src.detalle_calculo,
                tgt.fecha_actualizacion = SYSDATETIME()
        WHEN NOT MATCHED BY TARGET THEN
            INSERT (
                periodo_facturacion,
                id_tienda,
                id_contrato_arriendo,
                id_contrato_local,
                id_local,
                id_regla_arriendo,
                id_modalidad_aplicada,
                valor_base_uf,
                valor_base_clp,
                valor_uf_periodo,
                id_descuento_arriendo,
                tipo_descuento_snapshot,
                valor_descuento_snapshot,
                monto_descuento_clp_snapshot,
                fuente_descuento,
                descuento_aplicado_clp,
                monto_neto_clp,
                monto_iva_clp,
                monto_total_clp,
                codigo_grupo_modalidad,
                estado_snapshot,
                es_congelado,
                fuente_calculo,
                formula_json,
                detalle_calculo
            )
            VALUES (
                @periodo,
                src.id_tienda,
                src.id_contrato_arriendo,
                src.id_contrato_local,
                src.id_local,
                src.id_regla_arriendo,
                src.id_modalidad_arriendo,
                src.valor_base_uf,
                src.valor_base_clp,
                src.valor_uf_periodo,
                src.id_descuento_arriendo,
                src.tipo_descuento_snapshot,
                src.valor_descuento_snapshot,
                src.monto_descuento_clp_snapshot,
                src.fuente_descuento,
                src.descuento_aplicado_clp,
                src.monto_neto_clp,
                src.monto_iva_clp,
                src.monto_total_clp,
                src.codigo_grupo_modalidad,
                src.estado_snapshot,
                src.es_congelado,
                src.fuente_calculo,
                src.formula_json,
                src.detalle_calculo
            );

        SET @rows_afectadas = @@ROWCOUNT;

        IF @congelar = 1
        BEGIN
            UPDATE s
            SET
                s.es_congelado = 1,
                s.estado_snapshot = 2,
                s.fecha_actualizacion = SYSDATETIME()
            FROM dbo.msp_arriendo_local_snapshot_periodo s
            WHERE s.periodo_facturacion = @periodo
              AND (@has_target = 0 OR EXISTS (
                    SELECT 1
                    FROM @target_tiendas tt
                    WHERE tt.id_tienda = s.id_tienda
              ));

            SET @snapshots_congelados = @@ROWCOUNT;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @periodo AS periodo_facturacion,
        @rows_afectadas AS snapshots_upsertados,
        @snapshots_congelados AS snapshots_congelados;
END;
GO
IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contrato_locales', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
BEGIN
    PRINT 'patch_arriendo_termino_prorrata: faltan tablas base. Se omite.';
END
ELSE
BEGIN
    EXEC(' 
CREATE OR ALTER PROCEDURE dbo.msp_ajustar_snapshot_arriendo_termino_prorrata
    @periodo_facturacion DATE,
    @target_tiendas_csv NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @periodo_facturacion IS NULL OR DAY(@periodo_facturacion) <> 1
    BEGIN
        ;THROW 50901, ''El periodo de facturacion debe ser el primer dia del mes.'', 1;
    END;

    DECLARE @mes_fin DATE = EOMONTH(@periodo_facturacion);
    DECLARE @dias_mes DECIMAL(18,6) = CAST(DAY(@mes_fin) AS DECIMAL(18,6));
    DECLARE @has_target BIT = 0;

    DECLARE @target_tiendas TABLE (
        id_tienda INT NOT NULL PRIMARY KEY
    );

    IF NULLIF(LTRIM(RTRIM(ISNULL(@target_tiendas_csv, N''''))), N'''') IS NOT NULL
    BEGIN
        INSERT INTO @target_tiendas (id_tienda)
        SELECT DISTINCT TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT)
        FROM STRING_SPLIT(@target_tiendas_csv, N'','') ss
        WHERE TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) IS NOT NULL
          AND TRY_CAST(LTRIM(RTRIM(ss.value)) AS INT) > 0;
    END;

    IF EXISTS (SELECT 1 FROM @target_tiendas)
    BEGIN
        SET @has_target = 1;
    END;

    ;WITH base AS (
        SELECT
            s.id_snapshot_arriendo,
            s.id_tienda,
            s.codigo_grupo_modalidad,
            ca.fecha_termino_efectiva,
            CASE
                WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio
                ELSE @periodo_facturacion
            END AS fecha_inicio_ocupacion,
            CASE
                WHEN ca.fecha_termino_efectiva < @mes_fin THEN ca.fecha_termino_efectiva
                ELSE @mes_fin
            END AS fecha_fin_ocupacion,
            CAST(ISNULL(s.monto_neto_clp, 0) AS DECIMAL(18,6)) AS neto_base_clp
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = s.id_contrato_local
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = s.id_contrato_arriendo
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
          AND ca.fecha_termino_efectiva IS NOT NULL
          AND ca.fecha_termino_efectiva >= @periodo_facturacion
          AND ca.fecha_termino_efectiva <= @mes_fin
          AND (@has_target = 0 OR EXISTS (
                SELECT 1
                FROM @target_tiendas tt
                WHERE tt.id_tienda = s.id_tienda
          ))
          AND (s.detalle_calculo IS NULL OR s.detalle_calculo NOT LIKE N''%Prorrateo termino aplicado%'')
    ),
    calc AS (
        SELECT
            b.id_snapshot_arriendo,
            b.id_tienda,
            b.neto_base_clp,
            CASE
                WHEN b.fecha_fin_ocupacion < b.fecha_inicio_ocupacion THEN 0
                ELSE DATEDIFF(DAY, b.fecha_inicio_ocupacion, b.fecha_fin_ocupacion) + 1
            END AS dias_ocupados
        FROM base b
    ),
    final_calc AS (
        SELECT
            c.id_snapshot_arriendo,
            c.id_tienda,
            c.dias_ocupados,
            CAST(
                CASE
                    WHEN @dias_mes <= 0 THEN 0
                    WHEN c.dias_ocupados <= 0 THEN 0
                    WHEN CAST(c.dias_ocupados AS DECIMAL(18,6)) >= @dias_mes THEN 1
                    ELSE CAST(c.dias_ocupados AS DECIMAL(18,6)) / @dias_mes
                END
                AS DECIMAL(18,6)
            ) AS factor_prorrata,
            CAST(ROUND(
                CASE
                    WHEN c.neto_base_clp <= 0 THEN 0
                    WHEN c.dias_ocupados <= 0 THEN 0
                    WHEN CAST(c.dias_ocupados AS DECIMAL(18,6)) >= @dias_mes THEN c.neto_base_clp
                    ELSE c.neto_base_clp * (CAST(c.dias_ocupados AS DECIMAL(18,6)) / @dias_mes)
                END,
                2
            ) AS DECIMAL(18,2)) AS neto_prorrateado_clp
        FROM calc c
    )
    UPDATE s
    SET
        s.monto_neto_clp = fc.neto_prorrateado_clp,
        s.monto_iva_clp = CAST(ROUND(fc.neto_prorrateado_clp * 0.19, 2) AS DECIMAL(18,2)),
        s.monto_total_clp = CAST(ROUND(fc.neto_prorrateado_clp * 1.19, 2) AS DECIMAL(18,2)),
        s.formula_json = CASE
            WHEN NULLIF(LTRIM(RTRIM(ISNULL(s.formula_json, N''''))), N'''') IS NULL THEN
                CONCAT(
                    N''{"prorrata_termino":{"dias_ocupados":'', CONVERT(NVARCHAR(10), fc.dias_ocupados),
                    N'',"dias_mes":'', CONVERT(NVARCHAR(10), DAY(@mes_fin)),
                    N'',"factor":'', CONVERT(NVARCHAR(40), fc.factor_prorrata),
                    N'',"neto_prorrateado_clp":'', CONVERT(NVARCHAR(40), fc.neto_prorrateado_clp),
                    N''}}''
                )
            ELSE s.formula_json
        END,
        s.detalle_calculo = LEFT(
            CONCAT(
                COALESCE(NULLIF(LTRIM(RTRIM(ISNULL(s.detalle_calculo, N''''))), N''''), N''Calculo base de arriendo aplicado.''),
                N'' | Prorrateo termino aplicado (dias_ocupados='', CONVERT(NVARCHAR(10), fc.dias_ocupados),
                N'' de '', CONVERT(NVARCHAR(10), DAY(@mes_fin)), N'').''
            ),
            1000
        ),
        s.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_arriendo_local_snapshot_periodo s
    INNER JOIN final_calc fc
        ON fc.id_snapshot_arriendo = s.id_snapshot_arriendo;

    SELECT @@ROWCOUNT AS snapshots_prorrateados;
END
');
END;
GO

PRINT 'patch_clp_fijo_contrato aplicado.';
GO
