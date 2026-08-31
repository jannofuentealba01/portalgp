/*
  Migracion: tema_fecha_tasacion
  Objetivo:
  - Agregar fecha_registro (datetime2) a ct_tasacion_terreno para distinguir
    fecha de tasacion (negocio) vs fecha real de registro (sistema).
*/

PRINT '>> Migracion tema_fecha_tasacion: inicio';
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_tasacion_terreno', 'fecha_registro') IS NULL
BEGIN
    ALTER TABLE dbo.ct_tasacion_terreno
    ADD fecha_registro DATETIME2(0) NULL;

    EXEC sp_executesql N'
        UPDATE dbo.ct_tasacion_terreno
        SET fecha_registro = SYSUTCDATETIME()
        WHERE fecha_registro IS NULL;
    ';

    EXEC sp_executesql N'
        ALTER TABLE dbo.ct_tasacion_terreno
        ALTER COLUMN fecha_registro DATETIME2(0) NOT NULL;
    ';

    IF NOT EXISTS (
        SELECT 1
        FROM sys.default_constraints dc
        INNER JOIN sys.columns c
            ON c.object_id = dc.parent_object_id
           AND c.column_id = dc.parent_column_id
        WHERE dc.parent_object_id = OBJECT_ID('dbo.ct_tasacion_terreno')
          AND c.name = 'fecha_registro'
    )
    BEGIN
        ALTER TABLE dbo.ct_tasacion_terreno
        ADD CONSTRAINT DF_ct_tasacion_terreno_fecha_registro
            DEFAULT (SYSUTCDATETIME()) FOR fecha_registro;
    END;
END;
GO

PRINT '>> Migracion tema_fecha_tasacion: fin';
GO
