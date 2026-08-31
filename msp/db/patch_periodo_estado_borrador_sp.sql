/*
===========================================================================
 PATCH: estado de período (Borrador/Calculado/Cerrado/Anulado)
 - Refuerza bloqueo de generación fuera de Borrador en SP de servicios y documentos.
 - Requiere esquema MSP ya instalado.
===========================================================================
*/

SET NOCOUNT ON;
GO

CREATE OR ALTER PROCEDURE dbo.msp_generar_cobros_servicios_periodo
    @id_cierre_mensual       INT,
    @reemplazar              BIT = 0,
    @cobros_generados        INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;

    SET @cobros_generados = 0;

    IF @id_cierre_mensual IS NULL OR @id_cierre_mensual <= 0
    BEGIN
        ;THROW 50031, 'Debes indicar un cierre mensual valido.', 1;
    END;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50032, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @estado_cierre = 4
    BEGIN
        ;THROW 50033, 'No se pueden generar cobros sobre un cierre mensual anulado.', 1;
    END;

    IF @estado_cierre = 3
    BEGIN
        ;THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;
    END;

    IF @estado_cierre <> 1
    BEGIN
        ;THROW 50039, 'Solo se pueden generar cobros en período Borrador.', 1;
    END;

    IF OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NOT NULL
       AND EXISTS (
            SELECT 1
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion
       )
    BEGIN
        ;THROW 50034, 'Ya existen documentos emitidos para este periodo_facturacion. Regenera primero la capa documental.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre_mensual;
        END
        ELSE
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_cobros_servicios cs
                INNER JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_lectura = cs.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                WHERE p.id_cierre_mensual = @id_cierre_mensual
            )
            BEGIN
                ;THROW 50035, 'Ya existen cobros auditables para este cierre mensual. Usa @reemplazar = 1 para regenerarlos.', 1;
            END;
        END;

        ;WITH base AS (
            SELECT
                lm.id_lectura,
                ts.codigo_servicio,
                COALESCE(
                    lm.consumo_informado,
                    lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
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
            LEFT JOIN dbo.msp_proceso_cobro_luz pl
                ON pl.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_gas pg
                ON pg.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_agua pa
                ON pa.id_proceso_cobro = p.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre_mensual
              AND p.estado_proceso <> 4
        )
        INSERT INTO dbo.msp_cobros_servicios (
            id_lectura,
            consumo_cobrado,
            subtotal_variable,
            cargo_fijo,
            monto_total,
            formula_version,
            parametros_snapshot,
            detalle_calculo
        )
        SELECT
            b.id_lectura,
            CAST(b.consumo_cobrado AS DECIMAL(18,4)),
            CAST(ROUND(calc.subtotal_variable, 2) AS DECIMAL(18,2)),
            CAST(ROUND(calc.cargo_fijo, 2) AS DECIMAL(18,2)),
            CAST(ROUND(calc.subtotal_variable + calc.cargo_fijo, 2) AS DECIMAL(18,2)),
            N'v1',
            calc.parametros_snapshot,
            calc.detalle_calculo
        FROM base b
        CROSS APPLY (
            SELECT
                subtotal_variable = CASE
                    WHEN b.codigo_servicio = N'LUZ'
                        THEN b.consumo_cobrado * ISNULL(b.valor_kwh, 0)
                    WHEN b.codigo_servicio = N'GAS'
                        THEN b.consumo_cobrado * ISNULL(b.factor, 0) * ISNULL(b.valor_litro, 0)
                    WHEN b.codigo_servicio = N'AGUA'
                        THEN b.consumo_cobrado * (
                            (
                                ISNULL(b.servicio_agua_potable, 0)
                                + ISNULL(b.servicio_alcantarillado, 0)
                                + ISNULL(b.tratamiento_aguas_servidas, 0)
                            ) / NULLIF(b.divisor, 0)
                        )
                    ELSE 0
                END,
                cargo_fijo = CASE
                    WHEN b.codigo_servicio = N'AGUA'
                        THEN ISNULL(b.cargo_fijo, 0)
                    ELSE 0
                END,
                parametros_snapshot = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(
                            N'{"servicio":"LUZ","valor_kwh":', CONVERT(NVARCHAR(50), ISNULL(b.valor_kwh, 0)), N'}'
                        )
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'{"servicio":"GAS","factor":', CONVERT(NVARCHAR(50), ISNULL(b.factor, 0)),
                            N',"valor_litro":', CONVERT(NVARCHAR(50), ISNULL(b.valor_litro, 0)), N'}'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(
                            N'{"servicio":"AGUA","servicio_agua_potable":', CONVERT(NVARCHAR(50), ISNULL(b.servicio_agua_potable, 0)),
                            N',"servicio_alcantarillado":', CONVERT(NVARCHAR(50), ISNULL(b.servicio_alcantarillado, 0)),
                            N',"tratamiento_aguas_servidas":', CONVERT(NVARCHAR(50), ISNULL(b.tratamiento_aguas_servidas, 0)),
                            N',"sobreconsumo":', CONVERT(NVARCHAR(50), ISNULL(b.sobreconsumo, 0)),
                            N',"interes_pf_plazo":', CONVERT(NVARCHAR(50), ISNULL(b.interes_pf_plazo, 0)),
                            N',"divisor":', CONVERT(NVARCHAR(50), ISNULL(b.divisor, 0)),
                            N',"cargo_fijo":', CONVERT(NVARCHAR(50), ISNULL(b.cargo_fijo, 0)),
                            N'}'
                        )
                    ELSE N'{}'
                END,
                detalle_calculo = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(
                            N'LUZ: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * valor_kwh(', FORMAT(ISNULL(b.valor_kwh, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'GAS: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * factor(', FORMAT(ISNULL(b.factor, 0), 'N6'),
                            N') * valor_litro(', FORMAT(ISNULL(b.valor_litro, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(
                            N'AGUA: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * ((SAP + SAL + TAS)/divisor) + cargo_fijo'
                        )
                    ELSE N'-'
                END
        ) calc;

        SET @cobros_generados = @@ROWCOUNT;

        UPDATE p
        SET p.estado_proceso = CASE
            WHEN p.estado_proceso = 4 THEN 4
            ELSE 2
        END
        FROM dbo.msp_procesos_cobro_servicio p
        WHERE p.id_cierre_mensual = @id_cierre_mensual;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_cierre_mensual AS id_cierre_mensual,
        @periodo_facturacion AS periodo_facturacion,
        @cobros_generados AS cobros_generados;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_generar_documentos_cobro_periodo
    @id_cierre_mensual        INT,
    @fecha_emision            DATE = NULL,
    @dias_vencimiento         INT = 10,
    @reemplazar               BIT = 0,
    @documentos_generados     INT OUTPUT,
    @items_generados          INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @valor_uf DECIMAL(18,6);
    DECLARE @tasa_iva DECIMAL(9,6) = 0.19;

    DECLARE @id_item_arriendo INT;
    DECLARE @id_item_agua INT;
    DECLARE @id_item_luz INT;
    DECLARE @id_item_gas INT;

    SET @documentos_generados = 0;
    SET @items_generados = 0;

    IF @id_cierre_mensual IS NULL OR @id_cierre_mensual <= 0
    BEGIN
        ;THROW 50051, 'Debes indicar un cierre mensual valido.', 1;
    END;

    IF @dias_vencimiento < 0 OR @dias_vencimiento > 120
    BEGIN
        ;THROW 50052, 'Los dias de vencimiento deben estar entre 0 y 120.', 1;
    END;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre,
        @valor_uf = c.valor_uf
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50053, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @estado_cierre = 4
    BEGIN
        ;THROW 50033, 'No se pueden generar documentos sobre un cierre mensual anulado.', 1;
    END;

    IF @estado_cierre = 3
    BEGIN
        ;THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;
    END;

    IF @estado_cierre <> 1
    BEGIN
        ;THROW 50039, 'Solo se pueden generar documentos en período Borrador.', 1;
    END;

    IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
    BEGIN
        ;THROW 50056, 'No existe msp_arriendo_local_snapshot_periodo. Debes aplicar Fase 1/2 de arriendo antes de generar documentos.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
    )
    BEGIN
        ;THROW 50057, 'No existen snapshots de arriendo para el período. Ejecuta primero la generación/congelamiento de snapshot.', 1;
    END;

    SET @fecha_emision = ISNULL(@fecha_emision, CONVERT(date, SYSDATETIME()));

    SELECT @id_item_arriendo = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'ARRIENDO';

    SELECT @id_item_agua = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_AGUA';

    SELECT @id_item_luz = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_LUZ';

    SELECT @id_item_gas = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_GAS';

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_pagos p
                    ON p.id_documento_cobro = dc.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion
                  AND p.estado_pago = 1
            )
            BEGIN
                ;THROW 50054, 'No se puede regenerar el periodo porque existen pagos aplicados.', 1;
            END;

            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo_facturacion;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion;
        END
        ELSE
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.periodo_facturacion = @periodo_facturacion
            )
            BEGIN
                ;THROW 50055, 'Ya existen documentos para ese periodo_facturacion. Usa @reemplazar = 1 si quieres regenerarlos.', 1;
            END;
        END;

        CREATE TABLE #arriendo_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #arriendo_tienda (id_tienda, subtotal_arriendo)
        SELECT
            s.id_tienda,
            SUM(CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2))) AS subtotal_arriendo
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
        GROUP BY s.id_tienda;

        CREATE TABLE #servicios_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #servicios_tienda (id_tienda, subtotal_servicios)
        SELECT
            map.id_tienda,
            SUM(cs.monto_total) AS subtotal_servicios
        FROM dbo.msp_cobros_servicios cs
        INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL
        GROUP BY map.id_tienda;

        CREATE TABLE #documentos_base (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #documentos_base (id_tienda, subtotal_arriendo, subtotal_servicios)
        SELECT
            x.id_tienda,
            SUM(x.subtotal_arriendo) AS subtotal_arriendo,
            SUM(x.subtotal_servicios) AS subtotal_servicios
        FROM (
            SELECT at.id_tienda, at.subtotal_arriendo, CAST(0 AS DECIMAL(18,2)) AS subtotal_servicios
            FROM #arriendo_tienda at
            UNION ALL
            SELECT st.id_tienda, CAST(0 AS DECIMAL(18,2)), st.subtotal_servicios
            FROM #servicios_tienda st
        ) x
        GROUP BY x.id_tienda;

        INSERT INTO dbo.msp_documentos_cobro (
            id_tienda,
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
            @periodo_facturacion,
            CONCAT(CONVERT(CHAR(6), @periodo_facturacion, 112), N'-', t.id_tienda),
            @fecha_emision,
            DATEADD(DAY, @dias_vencimiento, @fecha_emision),
            a.rut,
            COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), a.rut),
            t.nombre_comercial,
            ROUND(db.subtotal_arriendo, 2),
            ROUND(db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            2,
            CONCAT(N'Documento generado desde cierre #', @id_cierre_mensual, N'.')
        FROM #documentos_base db
        INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = db.id_tienda
        INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
        WHERE db.subtotal_arriendo > 0
           OR db.subtotal_servicios > 0;

        SET @documentos_generados = @@ROWCOUNT;

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
               AND s.periodo_facturacion = @periodo_facturacion
               AND s.estado_snapshot IN (1,2,3)
            INNER JOIN dbo.msp_locales loc
                ON loc.id_local = s.id_local
            WHERE dc.periodo_facturacion = @periodo_facturacion
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
            adr.id_documento_cobro,
            ROW_NUMBER() OVER (
                PARTITION BY adr.id_documento_cobro
                ORDER BY
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key,
                    adr.id_contrato_local
            ),
            @id_item_arriendo,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CONCAT(N'Arriendo fijo contrato #', adr.id_contrato_arriendo)
                ELSE CONCAT(N'Arriendo local ', adr.cdo_local)
            END,
            1,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CAST(ROUND(ISNULL(adr.total_clp_fijo_contrato, 0), 2) AS DECIMAL(18,2))
                ELSE adr.valor_arriendo_neto
            END,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CAST(ROUND(ISNULL(adr.total_clp_fijo_contrato, 0), 2) AS DECIMAL(18,2))
                ELSE adr.valor_arriendo_neto
            END,
            NULL
        FROM arriendo_detalle_raw adr
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(adr.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(adr.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        WHERE adr.es_clp_fijo_contrato = 0
           OR (adr.es_clp_fijo_contrato = 1 AND adr.rn_clp_fijo_contrato = 1);

        SET @items_generados = @items_generados + @@ROWCOUNT;

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
                    ts.codigo_servicio,
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key,
                    m.codigo_medidor
            ),
            CASE ts.codigo_servicio
                WHEN N'AGUA' THEN @id_item_agua
                WHEN N'LUZ'  THEN @id_item_luz
                WHEN N'GAS'  THEN @id_item_gas
                ELSE @id_item_gas
            END,
            CONCAT(ts.nombre_servicio, N' local ', loc.cdo_local, N' medidor ', m.codigo_medidor),
            CASE WHEN cs.consumo_cobrado > 0 THEN cs.consumo_cobrado ELSE 1 END,
            CASE
                WHEN cs.consumo_cobrado > 0 THEN ROUND(cs.monto_total / cs.consumo_cobrado, 2)
                ELSE cs.monto_total
            END,
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
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(loc.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(loc.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_tienda = map.id_tienda
           AND dc.periodo_facturacion = @periodo_facturacion
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL;

        SET @items_generados = @items_generados + @@ROWCOUNT;

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
            WHERE dc.periodo_facturacion = @periodo_facturacion
            GROUP BY dcd.id_documento_cobro
        )
        UPDATE dc
        SET dc.subtotal_arriendo = ROUND(r.subtotal_arriendo, 2),
            dc.subtotal_servicios = ROUND(r.subtotal_servicios, 2),
            dc.monto_total = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.saldo_pendiente = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.estado_documento = 2
        FROM dbo.msp_documentos_cobro dc
        INNER JOIN resumen r
            ON r.id_documento_cobro = dc.id_documento_cobro;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_cierre_mensual AS id_cierre_mensual,
        @periodo_facturacion AS periodo_facturacion,
        @documentos_generados AS documentos_generados,
        @items_generados AS items_generados;
END;
GO
