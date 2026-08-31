/*
===========================================================================
 MSP - PATCH OPERACION MENSUAL A SPs
 - Extrae lógica crítica de operacion_mensual.php a procedimientos SQL.
 - Incluye:
   * dbo.msp_generar_cobros_periodo
   * dbo.msp_borrar_generacion_periodo
===========================================================================
*/

SET NOCOUNT ON;
GO

CREATE OR ALTER PROCEDURE dbo.msp_generar_cobros_periodo
    @id_cierre INT,
    @reemplazar BIT = 0,
    @servicios_csv NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @out INT = 0;

    IF ISNULL(@id_cierre, 0) <= 0
        THROW 50032, 'El cierre mensual indicado no existe.', 1;

    DECLARE @servicios TABLE (
        codigo_servicio NVARCHAR(20) NOT NULL PRIMARY KEY
    );

    INSERT INTO @servicios (codigo_servicio)
    SELECT DISTINCT UPPER(LTRIM(RTRIM(value)))
    FROM STRING_SPLIT(ISNULL(@servicios_csv, N''), N',')
    WHERE UPPER(LTRIM(RTRIM(value))) IN (N'AGUA', N'LUZ', N'GAS');

    IF NOT EXISTS (SELECT 1 FROM @servicios)
        THROW 50037, 'Debes seleccionar al menos un servicio para generar cobros.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT
            @periodo_facturacion = c.periodo_facturacion,
            @estado_cierre = c.estado_cierre
        FROM dbo.msp_cierre_mensual c
        WHERE c.id_cierre_mensual = @id_cierre;

        IF @periodo_facturacion IS NULL
            THROW 50032, 'El cierre mensual indicado no existe.', 1;

        IF @estado_cierre = 4
            THROW 50033, 'No se pueden generar cobros sobre un cierre mensual anulado.', 1;

        IF @estado_cierre = 3
            THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;

        IF @estado_cierre <> 1
            THROW 50039, 'Solo se pueden generar cobros en período Borrador.', 1;

        -- Flujo incremental por servicios:
        -- no bloquear generación de cobros por existencia de documentos del período.
        -- el recálculo selectivo lo controla @reemplazar y @servicios_csv.

        IF EXISTS (
            SELECT 1
            FROM dbo.msp_tipos_servicio ts
            INNER JOIN @servicios s
                ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            LEFT JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_cierre_mensual = @id_cierre
               AND p.id_tipo_servicio = ts.id_tipo_servicio
            WHERE p.id_proceso_cobro IS NULL OR p.estado_proceso = 4
        )
        BEGIN
            THROW 50036, 'Uno o mas servicios seleccionados no tienen proceso activo para el periodo.', 1;
        END;

        IF @reemplazar = 1
        BEGIN
            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
            INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = p.id_tipo_servicio
            INNER JOIN @servicios s ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            WHERE p.id_cierre_mensual = @id_cierre;
        END;

        ;WITH base AS (
            SELECT
                lm.id_lectura,
                UPPER(ts.codigo_servicio) AS codigo_servicio,
                COALESCE(
                    lm.consumo_informado,
                    CASE WHEN lm.lectura_actual >= ISNULL(lm.lectura_anterior, 0)
                         THEN lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
                         ELSE 0 END
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
            INNER JOIN @servicios s
                ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            LEFT JOIN dbo.msp_proceso_cobro_luz pl
                ON pl.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_gas pg
                ON pg.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_agua pa
                ON pa.id_proceso_cobro = p.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4
              AND NOT EXISTS (SELECT 1 FROM dbo.msp_cobros_servicios ex WHERE ex.id_lectura = lm.id_lectura)
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
                    WHEN b.codigo_servicio = N'LUZ' THEN b.consumo_cobrado * ISNULL(b.valor_kwh, 0)
                    WHEN b.codigo_servicio = N'GAS' THEN b.consumo_cobrado * ISNULL(b.factor, 0) * ISNULL(b.valor_litro, 0)
                    WHEN b.codigo_servicio = N'AGUA' THEN b.consumo_cobrado * (
                        (ISNULL(b.servicio_agua_potable, 0) + ISNULL(b.servicio_alcantarillado, 0) + ISNULL(b.tratamiento_aguas_servidas, 0))
                        / NULLIF(b.divisor, 0)
                    )
                    ELSE 0
                END,
                cargo_fijo = CASE WHEN b.codigo_servicio = N'AGUA' THEN ISNULL(b.cargo_fijo, 0) ELSE 0 END,
                parametros_snapshot = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(N'{"servicio":"LUZ","valor_kwh":', CONVERT(NVARCHAR(50), ISNULL(b.valor_kwh, 0)), N'}')
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
                        CONCAT(N'LUZ: consumo(', FORMAT(b.consumo_cobrado, 'N4'), N') * valor_kwh(', FORMAT(ISNULL(b.valor_kwh, 0), 'N6'), N')')
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'GAS: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * factor(', FORMAT(ISNULL(b.factor, 0), 'N6'),
                            N') * valor_litro(', FORMAT(ISNULL(b.valor_litro, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(N'AGUA: consumo(', FORMAT(b.consumo_cobrado, 'N4'), N') * ((SAP + SAL + TAS)/divisor) + cargo_fijo')
                    ELSE N'-'
                END
        ) calc;

        SET @out = @@ROWCOUNT;

        UPDATE p
        SET p.estado_proceso = CASE WHEN p.estado_proceso = 4 THEN 4 ELSE 2 END
        FROM dbo.msp_procesos_cobro_servicio p
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        INNER JOIN @servicios s
            ON s.codigo_servicio = UPPER(ts.codigo_servicio)
        WHERE p.id_cierre_mensual = @id_cierre;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @out AS cobros_generados;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_borrar_generacion_periodo
    @id_cierre INT,
    @del_docs BIT = 0,
    @del_cobros BIT = 0,
    @del_pagos BIT = 0,
    @del_cargos_salida_asociados BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @docs_borrados INT = 0;
    DECLARE @items_borrados INT = 0;
    DECLARE @cobros_borrados INT = 0;
    DECLARE @pagos_borrados INT = 0;
    DECLARE @cargos_salida_desvinculados INT = 0;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre;

    IF @periodo_facturacion IS NULL
        THROW 50091, 'El cierre mensual indicado no existe.', 1;

    IF @estado_cierre = 4
        THROW 50033, 'No se puede corregir un período anulado.', 1;

    IF @estado_cierre = 3
        THROW 50038, 'El período está cerrado. Reábrelo a Borrador para corregir.', 1;

    IF @estado_cierre <> 1
        THROW 50039, 'Solo se puede corregir un período en Borrador.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @del_pagos = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_pagos', N'U') IS NOT NULL
            BEGIN
                IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NOT NULL
                   AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_movimientos_garantia mg
                        INNER JOIN dbo.msp_pagos p ON p.id_pago = mg.id_pago
                        INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                        WHERE dc.periodo_facturacion = @periodo_facturacion
                   )
                BEGIN
                    THROW 50096, 'No se pueden borrar pagos del periodo porque existen movimientos de garantia asociados.', 1;
                END;

                IF OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U') IS NOT NULL
                BEGIN
                    DELETE pdc
                    FROM dbo.msp_pagos_detalle_concepto pdc
                    INNER JOIN dbo.msp_pagos p ON p.id_pago = pdc.id_pago
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion;
                END;

                IF OBJECT_ID(N'dbo.msp_anular_pago_documento', N'P') IS NOT NULL
                BEGIN
                    DECLARE @id_pago_anular INT;
                    DECLARE @fecha_anulacion_pago DATE = CAST(SYSDATETIME() AS DATE);
                    DECLARE @motivo_anulacion NVARCHAR(500) =
                        N'Correccion operativa periodo '
                        + CAST(YEAR(@periodo_facturacion) AS NVARCHAR(4))
                        + N'-'
                        + RIGHT(N'0' + CAST(MONTH(@periodo_facturacion) AS NVARCHAR(2)), 2);

                    DECLARE @pagos_activos TABLE (id_pago INT NOT NULL PRIMARY KEY);
                    INSERT INTO @pagos_activos (id_pago)
                    SELECT p.id_pago
                    FROM dbo.msp_pagos p
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
                      AND p.estado_pago = 1;

                    WHILE EXISTS (SELECT 1 FROM @pagos_activos)
                    BEGIN
                        SELECT TOP (1) @id_pago_anular = pa.id_pago
                        FROM @pagos_activos pa
                        ORDER BY pa.id_pago DESC;

                        EXEC dbo.msp_anular_pago_documento
                            @id_pago = @id_pago_anular,
                            @fecha_anulacion = @fecha_anulacion_pago,
                            @motivo_anulacion = @motivo_anulacion;

                        DELETE FROM @pagos_activos WHERE id_pago = @id_pago_anular;
                    END;
                END;

                DELETE p
                FROM dbo.msp_pagos p
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @pagos_borrados = @@ROWCOUNT;
            END;
        END;

        IF @del_cargos_salida_asociados = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NOT NULL
            BEGIN
                UPDATE cs
                SET
                    cs.id_documento_cobro = NULL,
                    cs.estado_cargo = CASE
                        WHEN cs.estado_cargo = 3 THEN 1
                        ELSE cs.estado_cargo
                    END
                FROM dbo.msp_cargos_salida cs
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = cs.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @cargos_salida_desvinculados = @@ROWCOUNT;
            END;
        END;

        IF @del_docs = 1
        BEGIN
            IF @del_pagos = 0
               AND OBJECT_ID(N'dbo.msp_pagos', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_pagos p
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50092, 'No se pueden borrar documentos del periodo porque existen pagos asociados.', 1;
            END;

            IF @del_cargos_salida_asociados = 0
               AND OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_cargos_salida cs
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = cs.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50093, 'No se pueden borrar documentos del periodo porque existen cargos de salida asociados.', 1;
            END;

            IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_movimientos_garantia mg
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = mg.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50094, 'No se pueden borrar documentos del periodo porque existen movimientos de garantia asociados.', 1;
            END;

            IF OBJECT_ID(N'dbo.msp_documentos_cobro_detalle', N'U') IS NOT NULL
            BEGIN
                DELETE dcd
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = dcd.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @items_borrados = @@ROWCOUNT;
            END;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion;

            SET @docs_borrados = @@ROWCOUNT;
        END;

        IF @del_cobros = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_documentos_cobro_detalle', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_documentos_cobro_detalle dcd
                    WHERE dcd.id_cobro_servicio IN (
                        SELECT cs.id_cobro_servicio
                        FROM dbo.msp_cobros_servicios cs
                        INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
                        INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
                        WHERE p.id_cierre_mensual = @id_cierre
                    )
               )
            BEGIN
                THROW 50095, 'No se pueden borrar cobros porque hay documentos que aun los referencian.', 1;
            END;

            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre;

            SET @cobros_borrados = @@ROWCOUNT;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @docs_borrados AS docs_borrados,
        @items_borrados AS items_borrados,
        @cobros_borrados AS cobros_borrados,
        @pagos_borrados AS pagos_borrados,
        @cargos_salida_desvinculados AS cargos_salida_desvinculados;
END;
GO

PRINT 'Patch operacion mensual SP aplicado.';
GO
