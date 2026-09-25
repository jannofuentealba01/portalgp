SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_liquidacion_servicio_consumos', N'U') IS NULL
    THROW 51000, 'Falta ejecutar patch_servicios_tardios_liquidacion.sql antes de la integración.', 1;
GO

IF COL_LENGTH(N'dbo.msp_documentos_cobro_detalle', N'id_consumo_liquidacion') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro_detalle
        ADD id_consumo_liquidacion INT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_detalle')
      AND name = N'FK_msp_doc_detalle_consumo_liquidacion'
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro_detalle WITH CHECK
        ADD CONSTRAINT FK_msp_doc_detalle_consumo_liquidacion
        FOREIGN KEY (id_consumo_liquidacion)
        REFERENCES dbo.msp_liquidacion_servicio_consumos (id_consumo_liquidacion);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_detalle')
      AND name = N'UX_msp_doc_detalle_consumo_liquidacion'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_doc_detalle_consumo_liquidacion
        ON dbo.msp_documentos_cobro_detalle (id_consumo_liquidacion)
        WHERE id_consumo_liquidacion IS NOT NULL;
END;
GO

PRINT N'Integración de servicios tardíos con documentos de cobro habilitada.';
GO
