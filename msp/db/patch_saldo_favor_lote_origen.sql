/*
===========================================================================
 PATCH: trazabilidad saldo a favor por lote origen
 - Agrega id_lote_envio_origen en msp_saldo_favor_periodo_aplicaciones.
 - Índices/FK para auditoría por lote.
===========================================================================
*/

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_saldo_favor_periodo_aplicaciones', 'id_lote_envio_origen') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_saldo_favor_periodo_aplicaciones
        ADD id_lote_envio_origen INT NULL;
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.msp_envio_lotes_programados', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE name = N'FK_msp_sf_periodo_aplicacion_lote_origen'
          AND parent_object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
   )
BEGIN
    ALTER TABLE dbo.msp_saldo_favor_periodo_aplicaciones WITH CHECK
    ADD CONSTRAINT FK_msp_sf_periodo_aplicacion_lote_origen
        FOREIGN KEY (id_lote_envio_origen)
        REFERENCES dbo.msp_envio_lotes_programados (id_lote_envio);
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND name = N'IX_msp_sf_periodo_aplicacion_lote_origen'
   )
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_lote_origen
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_lote_envio_origen, estado_aplicacion)
        INCLUDE (periodo_facturacion, id_tienda, id_documento_cobro, monto_aplicado, id_pago)
        WHERE id_lote_envio_origen IS NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
   )
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.index_columns ic
        INNER JOIN sys.columns c
            ON c.object_id = ic.object_id
           AND c.column_id = ic.column_id
        INNER JOIN sys.indexes i
            ON i.object_id = ic.object_id
           AND i.index_id = ic.index_id
        WHERE i.object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND i.name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
          AND c.name = N'id_lote_envio_origen'
    )
    BEGIN
        DROP INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones;

        CREATE INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones (periodo_facturacion, id_tienda, estado_aplicacion)
            INCLUDE (monto_aplicado, id_documento_cobro, id_saldo_favor_periodo_item, id_pago, id_lote_envio_origen);
    END;
END;
GO

PRINT 'Patch trazabilidad saldo a favor por lote origen aplicado.';
GO
