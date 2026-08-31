/*
===========================================================================
 MSP - PATCH PAGOS POR CONCEPTO
 - Agrega conceptos comerciales: MULTA y DANO
 - Crea detalle de aplicacion de pagos por concepto
 - Permite registrar pago con distribucion manual (JSON) o automatica
 - Incluye backfill para pagos aplicados existentes sin detalle
===========================================================================
*/

SET NOCOUNT ON;
GO

/* Fuente de verdad de la prioridad de imputación: contrato/documento más antiguo
   primero; dentro de él Arriendo -> Luz -> Gas -> Agua -> otros cargos. */
IF OBJECT_ID(N'dbo.msp_prioridades_imputacion_pago', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_prioridades_imputacion_pago (
        codigo_item NVARCHAR(30) NOT NULL CONSTRAINT PK_msp_prioridades_imputacion_pago PRIMARY KEY,
        prioridad INT NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_prioridades_imputacion_pago_activo DEFAULT (1),
        CONSTRAINT CK_msp_prioridades_imputacion_pago_prioridad CHECK (prioridad > 0)
    );
END;
GO

MERGE dbo.msp_prioridades_imputacion_pago AS target
USING (VALUES
    (N'ARRIENDO', 10), (N'SERVICIO_LUZ', 20), (N'SERVICIO_GAS', 30),
    (N'SERVICIO_AGUA', 40), (N'MULTA', 50), (N'DANO', 60), (N'AJUSTE', 70)
) AS source(codigo_item, prioridad)
ON target.codigo_item=source.codigo_item
WHEN MATCHED THEN UPDATE SET prioridad=source.prioridad,activo=1
WHEN NOT MATCHED THEN INSERT(codigo_item,prioridad,activo) VALUES(source.codigo_item,source.prioridad,1);
GO

IF OBJECT_ID(N'dbo.msp_tipo_item_documento', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_tipo_item_documento
        WHERE codigo_item = N'MULTA'
    )
    BEGIN
        DECLARE @id_tipo_multa INT;
        SELECT @id_tipo_multa = ISNULL(MAX(id_tipo_item_documento), 0) + 1
        FROM dbo.msp_tipo_item_documento;

        INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
        VALUES (@id_tipo_multa, N'MULTA', N'Multa');
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_tipo_item_documento
        WHERE codigo_item = N'DANO'
    )
    BEGIN
        DECLARE @id_tipo_dano INT;
        SELECT @id_tipo_dano = ISNULL(MAX(id_tipo_item_documento), 0) + 1
        FROM dbo.msp_tipo_item_documento;

        INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
        VALUES (@id_tipo_dano, N'DANO', N'Dano');
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pagos_detalle_concepto (
        id_detalle_pago_concepto      INT IDENTITY(1,1) NOT NULL,
        id_pago                       INT NOT NULL,
        id_documento_cobro            INT NOT NULL,
        id_tipo_item_documento        INT NOT NULL,
        monto_aplicado                DECIMAL(18,2) NOT NULL,
        fecha_registro                DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_detalle_concepto_fecha DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_pagos_detalle_concepto PRIMARY KEY (id_detalle_pago_concepto),
        CONSTRAINT FK_msp_pagos_detalle_concepto_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT FK_msp_pagos_detalle_concepto_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_pagos_detalle_concepto_tipo
            FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
        CONSTRAINT UQ_msp_pagos_detalle_concepto_pago_tipo UNIQUE (id_pago, id_tipo_item_documento),
        CONSTRAINT CK_msp_pagos_detalle_concepto_monto CHECK (monto_aplicado > 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pagos_detalle_concepto_documento_tipo'
      AND object_id = OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pagos_detalle_concepto_documento_tipo
        ON dbo.msp_pagos_detalle_concepto (id_documento_cobro, id_tipo_item_documento, id_pago);
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_detalle_concepto_valida_documento
ON dbo.msp_pagos_detalle_concepto
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = i.id_pago
        WHERE p.id_documento_cobro <> i.id_documento_cobro
    )
    BEGIN
        ;THROW 50120, 'El documento del detalle de concepto no coincide con el documento del pago.', 1;
    END;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos
    @id_pago                     INT,
    @id_documento_cobro          INT,
    @monto_aplicado              DECIMAL(18,2),
    @detalle_conceptos_json      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @subtotal_arriendo DECIMAL(18,2);
    DECLARE @subtotal_servicios DECIMAL(18,2);
    DECLARE @monto_total DECIMAL(18,2);
    DECLARE @iva_arriendo DECIMAL(18,2);
    DECLARE @id_tipo_arriendo INT;

    DECLARE @conceptos_base TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_total DECIMAL(18,2) NOT NULL
    );

    DECLARE @saldos_concepto TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_disponible DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_solicitado TABLE (
        id_tipo_item_documento INT NOT NULL,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_normalizado TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50111, 'Debes indicar un pago valido para guardar el detalle de conceptos.', 1;
    END;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    IF @monto_aplicado IS NULL OR @monto_aplicado <= 0
    BEGIN
        ;THROW 50113, 'El monto aplicado debe ser mayor a cero para distribuir conceptos.', 1;
    END;

    SELECT
        @subtotal_arriendo = dc.subtotal_arriendo,
        @subtotal_servicios = dc.subtotal_servicios,
        @monto_total = dc.monto_total
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @monto_total IS NULL
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    SELECT @id_tipo_arriendo = tid.id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento tid
    WHERE tid.codigo_item = N'ARRIENDO';

    INSERT INTO @conceptos_base (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_total
    )
    SELECT
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item,
        ISNULL(prioridad.prioridad,80) AS prioridad,
        ROUND(SUM(dcd.subtotal), 2) AS monto_total
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    LEFT JOIN dbo.msp_prioridades_imputacion_pago prioridad
        ON prioridad.codigo_item=tid.codigo_item AND prioridad.activo=1
    WHERE dcd.id_documento_cobro = @id_documento_cobro
    GROUP BY
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item,
        prioridad.prioridad;

    SET @iva_arriendo = ROUND(ISNULL(@monto_total, 0) - ISNULL(@subtotal_arriendo, 0) - ISNULL(@subtotal_servicios, 0), 2);
    IF @iva_arriendo < 0
    BEGIN
        SET @iva_arriendo = 0;
    END;

    IF @id_tipo_arriendo IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo
        )
        BEGIN
            UPDATE cb
            SET cb.monto_total = ROUND(cb.monto_total + @iva_arriendo, 2)
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo;
        END
        ELSE IF ISNULL(@subtotal_arriendo, 0) > 0 OR @iva_arriendo > 0
        BEGIN
            INSERT INTO @conceptos_base (
                id_tipo_item_documento,
                codigo_item,
                nombre_item,
                prioridad,
                monto_total
            )
            SELECT
                tid.id_tipo_item_documento,
                tid.codigo_item,
                tid.nombre_item,
                ISNULL((SELECT prioridad FROM dbo.msp_prioridades_imputacion_pago WHERE codigo_item=N'ARRIENDO' AND activo=1),80),
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)
            FROM dbo.msp_tipo_item_documento tid
            WHERE tid.id_tipo_item_documento = @id_tipo_arriendo;
        END;
    END;

    INSERT INTO @saldos_concepto (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_disponible
    )
    SELECT
        cb.id_tipo_item_documento,
        cb.codigo_item,
        cb.nombre_item,
        cb.prioridad,
        ROUND(
            CASE
                WHEN cb.monto_total - ISNULL(ap.aplicado, 0) < 0 THEN 0
                ELSE cb.monto_total - ISNULL(ap.aplicado, 0)
            END,
            2
        ) AS monto_disponible
    FROM @conceptos_base cb
    OUTER APPLY (
        SELECT SUM(pdc.monto_aplicado) AS aplicado
        FROM dbo.msp_pagos_detalle_concepto pdc
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = pdc.id_pago
        WHERE pdc.id_documento_cobro = @id_documento_cobro
          AND pdc.id_tipo_item_documento = cb.id_tipo_item_documento
          AND p.estado_pago = 1
          AND p.id_pago <> @id_pago
    ) ap
    WHERE cb.monto_total > 0;

    IF NOT EXISTS (SELECT 1 FROM @saldos_concepto)
    BEGIN
        ;THROW 50123, 'No fue posible distribuir conceptos: el documento no tiene conceptos pendientes.', 1;
    END;

    DELETE FROM dbo.msp_pagos_detalle_concepto
    WHERE id_pago = @id_pago;

    IF LTRIM(RTRIM(ISNULL(@detalle_conceptos_json, N''))) <> N''
    BEGIN
        INSERT INTO @detalle_solicitado (id_tipo_item_documento, monto_aplicado)
        SELECT
            TRY_CAST(JSON_VALUE(js.value, '$.id_tipo_item_documento') AS INT) AS id_tipo_item_documento,
            TRY_CAST(JSON_VALUE(js.value, '$.monto') AS DECIMAL(18,2)) AS monto_aplicado
        FROM OPENJSON(@detalle_conceptos_json) js;

        IF NOT EXISTS (SELECT 1 FROM @detalle_solicitado)
        BEGIN
            ;THROW 50125, 'Debes indicar al menos un concepto de pago.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_solicitado ds
            WHERE ds.id_tipo_item_documento IS NULL
               OR ds.id_tipo_item_documento <= 0
               OR ds.monto_aplicado IS NULL
               OR ds.monto_aplicado <= 0
        )
        BEGIN
            ;THROW 50127, 'El detalle de conceptos contiene valores invalidos.', 1;
        END;

        INSERT INTO @detalle_normalizado (id_tipo_item_documento, monto_aplicado)
        SELECT
            ds.id_tipo_item_documento,
            ROUND(SUM(ds.monto_aplicado), 2)
        FROM @detalle_solicitado ds
        GROUP BY ds.id_tipo_item_documento;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            LEFT JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE sc.id_tipo_item_documento IS NULL
        )
        BEGIN
            ;THROW 50124, 'Hay conceptos que no existen en el documento o no tienen saldo disponible.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            INNER JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE dn.monto_aplicado > sc.monto_disponible + 0.01
        )
        BEGIN
            ;THROW 50122, 'El monto de un concepto excede el saldo disponible del concepto.', 1;
        END;

        IF ABS(
            ROUND(
                ISNULL((SELECT SUM(dn.monto_aplicado) FROM @detalle_normalizado dn), 0)
                - @monto_aplicado,
                2
            )
        ) > 0.01
        BEGIN
            ;THROW 50121, 'La suma de conceptos no coincide con el monto aplicado al documento.', 1;
        END;

        INSERT INTO dbo.msp_pagos_detalle_concepto (
            id_pago,
            id_documento_cobro,
            id_tipo_item_documento,
            monto_aplicado
        )
        SELECT
            @id_pago,
            @id_documento_cobro,
            dn.id_tipo_item_documento,
            dn.monto_aplicado
        FROM @detalle_normalizado dn;
    END
    ELSE
    BEGIN
        DECLARE @id_tipo_actual INT;
        DECLARE @disponible_actual DECIMAL(18,2);
        DECLARE @monto_asignado DECIMAL(18,2);
        DECLARE @restante DECIMAL(18,2);

        SET @restante = ROUND(@monto_aplicado, 2);

        DECLARE cur_conceptos CURSOR LOCAL FAST_FORWARD FOR
            SELECT sc.id_tipo_item_documento, sc.monto_disponible
            FROM @saldos_concepto sc
            WHERE sc.monto_disponible > 0
            ORDER BY sc.prioridad ASC, sc.id_tipo_item_documento ASC;

        OPEN cur_conceptos;

        FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        WHILE @@FETCH_STATUS = 0 AND @restante > 0.01
        BEGIN
            SET @monto_asignado = CASE
                WHEN @restante < @disponible_actual THEN @restante
                ELSE @disponible_actual
            END;

            SET @monto_asignado = ROUND(@monto_asignado, 2);

            IF @monto_asignado > 0
            BEGIN
                INSERT INTO dbo.msp_pagos_detalle_concepto (
                    id_pago,
                    id_documento_cobro,
                    id_tipo_item_documento,
                    monto_aplicado
                )
                VALUES (
                    @id_pago,
                    @id_documento_cobro,
                    @id_tipo_actual,
                    @monto_asignado
                );

                SET @restante = ROUND(@restante - @monto_asignado, 2);
            END;

            FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        END;

        CLOSE cur_conceptos;
        DEALLOCATE cur_conceptos;

        IF @restante > 0.01
        BEGIN
            ;THROW 50123, 'No fue posible distribuir automaticamente el pago por concepto.', 1;
        END;
    END;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_registrar_pago_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_pagado           DECIMAL(18,2),
    @medio_pago             NVARCHAR(50) = NULL,
    @referencia_pago        NVARCHAR(100) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @monto_aplicado DECIMAL(18,2);
    DECLARE @monto_excedente DECIMAL(18,2);
    DECLARE @id_pago_generado INT;
    DECLARE @saldo_favor_tienda DECIMAL(18,2) = 0;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50061, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50062, 'Debes indicar la fecha del pago.', 1;
    END;

    IF @monto_pagado IS NULL OR @monto_pagado <= 0
    BEGIN
        ;THROW 50063, 'El monto_pagado debe ser mayor a cero.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50064, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50065, 'El documento no tiene saldo pendiente para recibir pagos.', 1;
    END;

    SET @monto_aplicado = CASE
        WHEN @monto_pagado > @saldo_pendiente THEN @saldo_pendiente
        ELSE @monto_pagado
    END;
    SET @monto_excedente = ROUND(@monto_pagado - @monto_aplicado, 2);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_aplicado,
            @monto_excedente,
            0,
            1,
            @medio_pago,
            @referencia_pago,
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_aplicado,
            @detalle_conceptos_json = @detalle_conceptos_json;

        IF @monto_excedente > 0
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_pago,
                1,
                @monto_excedente,
                @id_documento_cobro,
                @id_pago_generado,
                CONCAT(N'Excedente de pago registrado sobre documento #', @id_documento_cobro)
            );
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @saldo_favor_tienda = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_aplicado AS monto_aplicado_documento,
        @monto_excedente AS monto_saldo_favor_generado,
        @saldo_favor_tienda AS saldo_favor_tienda;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_aplicar_saldo_favor_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_aplicar          DECIMAL(18,2) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);
    DECLARE @monto_real DECIMAL(18,2);
    DECLARE @id_pago_generado INT;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50081, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50082, 'Debes indicar la fecha de aplicación.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50083, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50084, 'El documento no tiene saldo pendiente para aplicar saldo a favor.', 1;
    END;

    SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
    WHERE sf.id_tienda = @id_tienda;

    SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

    IF @saldo_favor_disponible <= 0
    BEGIN
        ;THROW 50085, 'La tienda no tiene saldo a favor disponible.', 1;
    END;

    IF @monto_aplicar IS NULL
    BEGIN
        SET @monto_real = CASE
            WHEN @saldo_favor_disponible < @saldo_pendiente THEN @saldo_favor_disponible
            ELSE @saldo_pendiente
        END;
    END
    ELSE
    BEGIN
        IF @monto_aplicar <= 0
        BEGIN
            ;THROW 50086, 'El monto a aplicar debe ser mayor a cero.', 1;
        END;

        IF @monto_aplicar > @saldo_favor_disponible
        BEGIN
            ;THROW 50087, 'El monto a aplicar excede el saldo a favor disponible.', 1;
        END;

        IF @monto_aplicar > @saldo_pendiente
        BEGIN
            ;THROW 50088, 'El monto a aplicar excede el saldo pendiente del documento.', 1;
        END;

        SET @monto_real = @monto_aplicar;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_real,
            0,
            1,
            1,
            N'Saldo a favor',
            N'Aplicación de saldo a favor tienda',
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_real,
            @detalle_conceptos_json = @detalle_conceptos_json;

        INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
            id_tienda,
            fecha_movimiento,
            tipo_movimiento,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_tienda,
            @fecha_pago,
            2,
            -@monto_real,
            @id_documento_cobro,
            @id_pago_generado,
            CONCAT(N'Aplicación de saldo a favor sobre documento #', @id_documento_cobro)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_real AS monto_aplicado,
        ISNULL(sf.saldo_disponible, 0) AS saldo_favor_restante
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;
END;
GO

DECLARE @id_pago_backfill INT;
DECLARE @id_documento_backfill INT;
DECLARE @monto_backfill DECIMAL(18,2);

DECLARE cur_backfill CURSOR LOCAL FAST_FORWARD FOR
    SELECT
        p.id_pago,
        p.id_documento_cobro,
        p.monto_pagado
    FROM dbo.msp_pagos p
    WHERE p.estado_pago = 1
      AND p.monto_pagado > 0
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_pagos_detalle_concepto pdc
            WHERE pdc.id_pago = p.id_pago
      )
    ORDER BY p.fecha_pago ASC, p.id_pago ASC;

OPEN cur_backfill;

FETCH NEXT FROM cur_backfill INTO @id_pago_backfill, @id_documento_backfill, @monto_backfill;
WHILE @@FETCH_STATUS = 0
BEGIN
    EXEC dbo.msp_guardar_pago_detalle_conceptos
        @id_pago = @id_pago_backfill,
        @id_documento_cobro = @id_documento_backfill,
        @monto_aplicado = @monto_backfill,
        @detalle_conceptos_json = NULL;

    FETCH NEXT FROM cur_backfill INTO @id_pago_backfill, @id_documento_backfill, @monto_backfill;
END;

CLOSE cur_backfill;
DEALLOCATE cur_backfill;
GO

PRINT 'Patch pagos por concepto aplicado.';
GO
