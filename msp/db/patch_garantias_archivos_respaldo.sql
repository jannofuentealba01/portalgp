SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF COL_LENGTH('dbo.msp_garantia_archivos','id_devolucion_garantia') IS NULL
    ALTER TABLE dbo.msp_garantia_archivos ADD id_devolucion_garantia INT NULL;
GO
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_garantia_archivos_devolucion')
    ALTER TABLE dbo.msp_garantia_archivos ADD CONSTRAINT FK_msp_garantia_archivos_devolucion
    FOREIGN KEY(id_devolucion_garantia) REFERENCES dbo.msp_garantia_devoluciones(id_devolucion_garantia);
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_archivos') AND name=N'IX_msp_garantia_archivos_devolucion')
    CREATE INDEX IX_msp_garantia_archivos_devolucion ON dbo.msp_garantia_archivos(id_devolucion_garantia,id_garantia_archivo) WHERE id_devolucion_garantia IS NOT NULL;
GO
PRINT N'Archivos de respaldo de garantías habilitados.';
GO
