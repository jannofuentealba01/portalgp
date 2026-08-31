/*
===========================================================================
 MSP - PATCH UUID DOCUMENTOS COBRO
 - Agrega uuid_documento (UNIQUEIDENTIFIER) en msp_documentos_cobro
 - Backfill para filas existentes
 - Default NEWID() y restriccion unica
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NULL
BEGIN
    PRINT 'patch_documentos_cobro_uuid: tabla dbo.msp_documentos_cobro no existe, se omite.';
    RETURN;
END;
GO

IF COL_LENGTH(N'dbo.msp_documentos_cobro', N'uuid_documento') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD uuid_documento UNIQUEIDENTIFIER NULL;

    PRINT 'patch_documentos_cobro_uuid: columna uuid_documento creada.';
END
ELSE
BEGIN
    PRINT 'patch_documentos_cobro_uuid: columna uuid_documento ya existia.';
END;
GO

UPDATE dbo.msp_documentos_cobro
SET uuid_documento = NEWID()
WHERE uuid_documento IS NULL;
GO

;WITH filas_duplicadas AS (
    SELECT
        dc.id_documento_cobro,
        ROW_NUMBER() OVER (
            PARTITION BY dc.uuid_documento
            ORDER BY dc.id_documento_cobro
        ) AS rn
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.uuid_documento IS NOT NULL
)
UPDATE dc
SET dc.uuid_documento = NEWID()
FROM dbo.msp_documentos_cobro dc
INNER JOIN filas_duplicadas fd
    ON fd.id_documento_cobro = dc.id_documento_cobro
WHERE fd.rn > 1;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.default_constraints d
    INNER JOIN sys.columns c
        ON c.object_id = d.parent_object_id
       AND c.column_id = d.parent_column_id
    WHERE d.parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND c.name = N'uuid_documento'
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD CONSTRAINT DF_msp_documentos_cobro_uuid_documento DEFAULT (NEWID()) FOR uuid_documento;
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns c
    WHERE c.object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND c.name = N'uuid_documento'
      AND c.is_nullable = 1
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ALTER COLUMN uuid_documento UNIQUEIDENTIFIER NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes i
    WHERE i.object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND i.name = N'UX_msp_documentos_cobro_uuid_documento'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_msp_documentos_cobro_uuid_documento
        ON dbo.msp_documentos_cobro (uuid_documento);
END;
GO
