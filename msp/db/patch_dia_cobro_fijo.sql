/*
===========================================================================
 PATCH: dia_cobro fijo en 1 para contratos
 Idempotente / SQL Server
===========================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.msp_contratos_arriendo', 'U') IS NULL
BEGIN
    PRINT 'patch_dia_cobro_fijo: tabla dbo.msp_contratos_arriendo no existe. Se omite.';
    RETURN;
END;
GO

UPDATE dbo.msp_contratos_arriendo
SET dia_cobro = 1
WHERE ISNULL(dia_cobro, 0) <> 1;
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns c
    WHERE c.object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND c.name = 'dia_cobro'
      AND c.default_object_id = 0
)
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    ADD CONSTRAINT DF_msp_contratos_dia_cobro
        DEFAULT (1) FOR dia_cobro;

    PRINT 'patch_dia_cobro_fijo: default DF_msp_contratos_dia_cobro creado.';
END
ELSE
BEGIN
    PRINT 'patch_dia_cobro_fijo: default de dia_cobro ya existia.';
END;
GO

PRINT 'patch_dia_cobro_fijo aplicado.';
GO
