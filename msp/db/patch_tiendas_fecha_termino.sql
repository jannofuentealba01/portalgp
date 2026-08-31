/*
 PATCH: fecha_termino en msp_tiendas + regla de fechas
 Idempotente para SQL Server
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.msp_tiendas', 'U') IS NULL
BEGIN
    PRINT 'patch_tiendas_fecha_termino: tabla dbo.msp_tiendas no existe, se omite.';
    RETURN;
END
GO

IF COL_LENGTH('dbo.msp_tiendas', 'fecha_termino') IS NULL
BEGIN
    ALTER TABLE dbo.msp_tiendas
    ADD fecha_termino DATE NULL;

    PRINT 'patch_tiendas_fecha_termino: columna fecha_termino creada.';
END
ELSE
BEGIN
    PRINT 'patch_tiendas_fecha_termino: columna fecha_termino ya existia.';
END
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.msp_tiendas')
      AND name = 'CK_msp_tiendas_fechas'
)
BEGIN
    ALTER TABLE dbo.msp_tiendas
    DROP CONSTRAINT CK_msp_tiendas_fechas;
END
GO

ALTER TABLE dbo.msp_tiendas
ADD CONSTRAINT CK_msp_tiendas_fechas CHECK (
    fecha_inicio IS NULL
    OR fecha_termino IS NULL
    OR fecha_termino >= fecha_inicio
);
GO

PRINT 'patch_tiendas_fecha_termino: constraint CK_msp_tiendas_fechas aplicada.';
GO

