/*
===========================================================================
 MSP - PATCH TRAZABILIDAD SALDO A FAVOR POR PERIODO
 - Crea tabla de items individuales de saldo a favor por tienda/periodo.
 - Permite regenerar documentos y reaplicar saldo de forma deterministica.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldo_favor_periodo_items (
        id_saldo_favor_periodo_item INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion          DATE NOT NULL,
        id_tienda                    INT NOT NULL,
        fecha_movimiento             DATE NOT NULL,
        monto_original               DECIMAL(18,2) NOT NULL,
        estado_item                  TINYINT NOT NULL
            CONSTRAINT DF_msp_sf_periodo_estado DEFAULT (1),
        id_movimiento_saldo_favor    INT NULL,
        id_movimiento_reversa        INT NULL,
        observaciones                NVARCHAR(500) NULL,
        fecha_registro               DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion          DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_actualizacion DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldo_favor_periodo_items
            PRIMARY KEY (id_saldo_favor_periodo_item),
        CONSTRAINT FK_msp_sf_periodo_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_sf_periodo_mov_ingreso
            FOREIGN KEY (id_movimiento_saldo_favor)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda (id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_sf_periodo_mov_reversa
            FOREIGN KEY (id_movimiento_reversa)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda (id_movimiento_saldo_favor),
        CONSTRAINT CK_msp_sf_periodo_monto_pos CHECK (monto_original > 0),
        CONSTRAINT CK_msp_sf_periodo_estado CHECK (estado_item IN (1,5))
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U')
      AND name = N'IX_msp_sf_periodo_periodo_tienda_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_periodo_tienda_estado
        ON dbo.msp_saldo_favor_periodo_items (periodo_facturacion, id_tienda, estado_item)
        INCLUDE (monto_original, id_movimiento_saldo_favor, fecha_movimiento);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U')
      AND name = N'UX_msp_sf_periodo_mov_ingreso'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_sf_periodo_mov_ingreso
        ON dbo.msp_saldo_favor_periodo_items (id_movimiento_saldo_favor)
        WHERE id_movimiento_saldo_favor IS NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldo_favor_periodo_aplicaciones (
        id_saldo_favor_periodo_aplicacion INT IDENTITY(1,1) NOT NULL,
        id_saldo_favor_periodo_item       INT NOT NULL,
        periodo_facturacion               DATE NOT NULL,
        id_tienda                         INT NOT NULL,
        id_documento_cobro                INT NOT NULL,
        id_pago                           INT NULL,
        fecha_aplicacion                  DATE NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
        monto_aplicado                    DECIMAL(18,2) NOT NULL,
        estado_aplicacion                 TINYINT NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_estado DEFAULT (1),
        observaciones                     NVARCHAR(500) NULL,
        fecha_registro                    DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion               DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_actualizacion DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldo_favor_periodo_aplicaciones
            PRIMARY KEY (id_saldo_favor_periodo_aplicacion),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_item
            FOREIGN KEY (id_saldo_favor_periodo_item)
            REFERENCES dbo.msp_saldo_favor_periodo_items (id_saldo_favor_periodo_item),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT CK_msp_sf_periodo_aplicacion_monto CHECK (monto_aplicado > 0),
        CONSTRAINT CK_msp_sf_periodo_aplicacion_estado CHECK (estado_aplicacion IN (1,5))
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
        ON dbo.msp_saldo_favor_periodo_aplicaciones (periodo_facturacion, id_tienda, estado_aplicacion)
        INCLUDE (monto_aplicado, id_documento_cobro, id_saldo_favor_periodo_item, id_pago);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'IX_msp_sf_periodo_aplicacion_item_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_item_estado
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_saldo_favor_periodo_item, estado_aplicacion)
        INCLUDE (monto_aplicado, periodo_facturacion, id_documento_cobro, id_pago);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'UX_msp_sf_periodo_aplicacion_pago'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_sf_periodo_aplicacion_pago
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_pago)
        WHERE id_pago IS NOT NULL;
END;
GO

PRINT 'Patch trazabilidad saldo a favor por periodo aplicado.';
GO
