/*
  Migracion: tema_fecha
  Objetivo:
  - Agregar fecha_registro (datetime2) a ct_operacion_predial para distinguir
    fecha de operacion (negocio) vs fecha real de registro (sistema).
*/

PRINT '>> Migracion tema_fecha: inicio';
GO

IF OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_operacion_predial', 'fecha_registro') IS NULL
BEGIN
    ALTER TABLE dbo.ct_operacion_predial
    ADD fecha_registro DATETIME2(0) NULL;

    EXEC sp_executesql N'
        UPDATE dbo.ct_operacion_predial
        SET fecha_registro = SYSUTCDATETIME()
        WHERE fecha_registro IS NULL;
    ';

    EXEC sp_executesql N'
        ALTER TABLE dbo.ct_operacion_predial
        ALTER COLUMN fecha_registro DATETIME2(0) NOT NULL;
    ';

    IF NOT EXISTS (
        SELECT 1
        FROM sys.default_constraints dc
        INNER JOIN sys.columns c
            ON c.object_id = dc.parent_object_id
           AND c.column_id = dc.parent_column_id
        WHERE dc.parent_object_id = OBJECT_ID('dbo.ct_operacion_predial')
          AND c.name = 'fecha_registro'
    )
    BEGIN
        ALTER TABLE dbo.ct_operacion_predial
        ADD CONSTRAINT DF_ct_operacion_predial_fecha_registro
            DEFAULT (SYSUTCDATETIME()) FOR fecha_registro;
    END;
END;
GO

PRINT '>> Migracion tema_fecha: fin';
GO
