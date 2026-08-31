/*
===========================================================================
 MSP - PATCH SALDO A FAVOR POR TIENDA
 - Crea saldo a favor por tienda y libro de movimientos
 - Extiende msp_pagos para soportar excedentes y aplicaciones desde credito
 - Reemplaza procedimientos de pago / anulacion y agrega aplicacion manual
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_saldos_favor_tienda', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldos_favor_tienda (
        id_tienda              INT NOT NULL,
        saldo_disponible       DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_saldos_favor_tienda_saldo DEFAULT (0),
        fecha_actualizacion    DATETIME2(0) NOT NULL CONSTRAINT DF_msp_saldos_favor_tienda_fecha DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldos_favor_tienda PRIMARY KEY (id_tienda),
        CONSTRAINT FK_msp_saldos_favor_tienda_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT CK_msp_saldos_favor_tienda_saldo CHECK (saldo_disponible >= 0)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_movimientos_saldo_favor_tienda (
        id_movimiento_saldo_favor   INT IDENTITY(1,1) NOT NULL,
        id_tienda                   INT NOT NULL,
        fecha_movimiento            DATE NOT NULL CONSTRAINT DF_msp_mov_saldo_favor_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
        tipo_movimiento             TINYINT NOT NULL,
        monto_movimiento            DECIMAL(18,2) NOT NULL,
        id_documento_cobro          INT NULL,
        id_pago                     INT NULL,
        observaciones               NVARCHAR(500) NULL,
        fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_mov_saldo_favor_registro DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_movimientos_saldo_favor_tienda PRIMARY KEY (id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_mov_saldo_favor_tienda_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT CK_msp_mov_saldo_favor_tipo CHECK (tipo_movimiento IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_mov_saldo_favor_monto CHECK (monto_movimiento <> 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_movimientos_saldo_favor_tienda_tienda_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U')
)
BEGIN
    CREATE INDEX IX_msp_movimientos_saldo_favor_tienda_tienda_fecha
        ON dbo.msp_movimientos_saldo_favor_tienda (id_tienda, fecha_movimiento DESC, id_movimiento_saldo_favor DESC);
END;
GO

IF COL_LENGTH('dbo.msp_pagos', 'monto_saldo_favor_generado') IS NULL
BEGIN
    ALTER TABLE dbo.msp_pagos
    ADD monto_saldo_favor_generado DECIMAL(18,2) NOT NULL
        CONSTRAINT DF_msp_pagos_saldo_favor_generado DEFAULT (0);
END;
GO

IF COL_LENGTH('dbo.msp_pagos', 'aplica_desde_saldo_favor') IS NULL
BEGIN
    ALTER TABLE dbo.msp_pagos
    ADD aplica_desde_saldo_favor BIT NOT NULL
        CONSTRAINT DF_msp_pagos_aplica_saldo_favor DEFAULT (0);
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_msp_pagos_monto'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_pagos', N'U')
)
BEGIN
    ALTER TABLE dbo.msp_pagos DROP CONSTRAINT CK_msp_pagos_monto;
END;
GO

ALTER TABLE dbo.msp_pagos
ADD CONSTRAINT CK_msp_pagos_monto CHECK (
    monto_pagado > 0
    AND monto_saldo_favor_generado >= 0
    AND (aplica_desde_saldo_favor = 0 OR monto_saldo_favor_generado = 0)
);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_saldo_favor_tienda_recalcula
ON dbo.msp_movimientos_saldo_favor_tienda
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @tiendas_afectadas TABLE (
        id_tienda INT NOT NULL PRIMARY KEY
    );

    INSERT INTO @tiendas_afectadas (id_tienda)
    SELECT DISTINCT i.id_tienda
    FROM inserted i
    WHERE i.id_tienda IS NOT NULL;

    INSERT INTO @tiendas_afectadas (id_tienda)
    SELECT DISTINCT d.id_tienda
    FROM deleted d
    WHERE d.id_tienda IS NOT NULL
      AND NOT EXISTS (
            SELECT 1
            FROM @tiendas_afectadas ta
            WHERE ta.id_tienda = d.id_tienda
      );

    IF NOT EXISTS (SELECT 1 FROM @tiendas_afectadas)
        RETURN;

    ;WITH saldo_calculado AS (
        SELECT
            ta.id_tienda,
            CAST(ISNULL(SUM(msf.monto_movimiento), 0) AS DECIMAL(18,2)) AS saldo_disponible
        FROM @tiendas_afectadas ta
        LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda msf
            ON msf.id_tienda = ta.id_tienda
        GROUP BY ta.id_tienda
    )
    MERGE dbo.msp_saldos_favor_tienda AS target
    USING saldo_calculado AS source
        ON target.id_tienda = source.id_tienda
    WHEN MATCHED THEN
        UPDATE SET
            saldo_disponible = source.saldo_disponible,
            fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (id_tienda, saldo_disponible, fecha_actualizacion)
        VALUES (source.id_tienda, source.saldo_disponible, SYSDATETIME());
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_saldos_favor_tienda
AS
SELECT
    t.id_tienda,
    t.nombre_comercial,
    ISNULL(sf.saldo_disponible, 0) AS saldo_disponible,
    sf.fecha_actualizacion
FROM dbo.msp_tiendas t
LEFT JOIN dbo.msp_saldos_favor_tienda sf
    ON sf.id_tienda = t.id_tienda;
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

        IF OBJECT_ID(N'dbo.msp_guardar_pago_detalle_conceptos', N'P') IS NOT NULL
        BEGIN
            EXEC dbo.msp_guardar_pago_detalle_conceptos
                @id_pago = @id_pago_generado,
                @id_documento_cobro = @id_documento_cobro,
                @monto_aplicado = @monto_aplicado,
                @detalle_conceptos_json = @detalle_conceptos_json;
        END;

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

        IF OBJECT_ID(N'dbo.msp_guardar_pago_detalle_conceptos', N'P') IS NOT NULL
        BEGIN
            EXEC dbo.msp_guardar_pago_detalle_conceptos
                @id_pago = @id_pago_generado,
                @id_documento_cobro = @id_documento_cobro,
                @monto_aplicado = @monto_real,
                @detalle_conceptos_json = @detalle_conceptos_json;
        END;

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

CREATE OR ALTER PROCEDURE dbo.msp_anular_pago_documento
    @id_pago                INT,
    @fecha_anulacion        DATE,
    @motivo_anulacion       NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_documento_cobro INT;
    DECLARE @id_tienda INT;
    DECLARE @monto_pagado DECIMAL(18,2);
    DECLARE @monto_saldo_favor_generado DECIMAL(18,2);
    DECLARE @aplica_desde_saldo_favor BIT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);

    DECLARE @id_movimiento_excedente INT = NULL;
    DECLARE @id_item_periodo INT = NULL;
    DECLARE @id_movimiento_reversa INT = NULL;
    DECLARE @aplicaciones_activas_item INT = 0;

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50071, 'Debes indicar un pago valido.', 1;
    END;

    IF @fecha_anulacion IS NULL
    BEGIN
        ;THROW 50072, 'Debes indicar la fecha de anulacion.', 1;
    END;

    IF @motivo_anulacion IS NULL OR LTRIM(RTRIM(@motivo_anulacion)) = N''
    BEGIN
        ;THROW 50073, 'Debes indicar un motivo de anulacion.', 1;
    END;

    SELECT
        @id_documento_cobro = p.id_documento_cobro,
        @id_tienda = dc.id_tienda,
        @monto_pagado = p.monto_pagado,
        @monto_saldo_favor_generado = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_desde_saldo_favor = ISNULL(p.aplica_desde_saldo_favor, 0)
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = p.id_documento_cobro
    WHERE p.id_pago = @id_pago
      AND p.estado_pago = 1;

    IF @id_documento_cobro IS NULL
    BEGIN
        ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @aplica_desde_saldo_favor = 1
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
                @fecha_anulacion,
                4,
                @monto_pagado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de aplicación de saldo a favor por anulación de pago #', @id_pago)
            );

            IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_aplicaciones
                SET estado_aplicacion = 5,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_pago = @id_pago
                  AND estado_aplicacion = 1;
            END;
        END
        ELSE IF @monto_saldo_favor_generado > 0
        BEGIN
            SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
            FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
            WHERE sf.id_tienda = @id_tienda;

            SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

            IF @saldo_favor_disponible < @monto_saldo_favor_generado
            BEGIN
                ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
            END;

            SELECT TOP 1
                @id_movimiento_excedente = msf.id_movimiento_saldo_favor
            FROM dbo.msp_movimientos_saldo_favor_tienda msf WITH (UPDLOCK, HOLDLOCK)
            WHERE msf.id_pago = @id_pago
              AND msf.id_documento_cobro = @id_documento_cobro
              AND msf.tipo_movimiento = 1
              AND msf.monto_movimiento > 0
            ORDER BY msf.id_movimiento_saldo_favor DESC;

            IF @id_movimiento_excedente IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                SELECT TOP 1
                    @id_item_periodo = sfpi.id_saldo_favor_periodo_item
                FROM dbo.msp_saldo_favor_periodo_items sfpi WITH (UPDLOCK, HOLDLOCK)
                WHERE sfpi.id_movimiento_saldo_favor = @id_movimiento_excedente
                  AND sfpi.estado_item = 1
                ORDER BY sfpi.id_saldo_favor_periodo_item DESC;

                IF @id_item_periodo IS NOT NULL
                   AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
                BEGIN
                    SELECT @aplicaciones_activas_item = COUNT(*)
                    FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa WITH (UPDLOCK, HOLDLOCK)
                    WHERE sfa.id_saldo_favor_periodo_item = @id_item_periodo
                      AND sfa.estado_aplicacion = 1;

                    IF ISNULL(@aplicaciones_activas_item, 0) > 0
                    BEGIN
                        ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
                    END;
                END;
            END;

            DECLARE @out_reversa TABLE (id_movimiento_saldo_favor INT);

            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out_reversa(id_movimiento_saldo_favor)
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                3,
                -@monto_saldo_favor_generado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de excedente por anulación de pago #', @id_pago)
            );

            SELECT TOP 1 @id_movimiento_reversa = id_movimiento_saldo_favor
            FROM @out_reversa;

            IF @id_item_periodo IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_items
                SET estado_item = 5,
                    id_movimiento_reversa = @id_movimiento_reversa,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_saldo_favor_periodo_item = @id_item_periodo
                  AND estado_item = 1;
            END;
        END;

        UPDATE dbo.msp_pagos
        SET estado_pago = 2,
            fecha_anulacion = @fecha_anulacion,
            motivo_anulacion = @motivo_anulacion
        WHERE id_pago = @id_pago
          AND estado_pago = 1;

        IF @@ROWCOUNT = 0
        BEGIN
            ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT 'Patch saldo a favor por tienda aplicado.';
GO
