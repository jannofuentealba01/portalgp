/*
===========================================================================
 PATCH: fecha_termino_efectiva en contratos + regla de fechas
 Idempotente / SQL Server
===========================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.msp_contratos_arriendo', 'U') IS NULL
BEGIN
    PRINT 'patch_contrato_termino_efectivo: tabla dbo.msp_contratos_arriendo no existe. Se omite.';
    RETURN;
END;
GO

IF COL_LENGTH('dbo.msp_contratos_arriendo', 'fecha_termino_efectiva') IS NULL
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    ADD fecha_termino_efectiva DATE NULL;

    PRINT 'patch_contrato_termino_efectivo: columna fecha_termino_efectiva creada.';
END
ELSE
BEGIN
    PRINT 'patch_contrato_termino_efectivo: columna fecha_termino_efectiva ya existia.';
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'CK_msp_contratos_fechas'
)
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    DROP CONSTRAINT CK_msp_contratos_fechas;
END;
GO

ALTER TABLE dbo.msp_contratos_arriendo WITH NOCHECK
ADD CONSTRAINT CK_msp_contratos_fechas CHECK (
    (fecha_termino_pactada IS NULL OR fecha_termino_pactada >= fecha_inicio)
    AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva >= fecha_inicio)
);
GO

ALTER TABLE dbo.msp_contratos_arriendo
CHECK CONSTRAINT CK_msp_contratos_fechas;
GO

PRINT 'patch_contrato_termino_efectivo aplicado.';
GO

