/*
  Script: 50_ct_integridad.sql
  Objetivo:
  - Reglas de integridad para el modelo CT por capas (10/20/30/40)
  - Script idempotente
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ==================================
   Unicidad operativa (indices)
   ================================== */

IF OBJECT_ID('dbo.ct_titularidad_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'UX_ct_titularidad_terreno_vigencia'
         AND object_id = OBJECT_ID('dbo.ct_titularidad_terreno')
   )
BEGIN
    CREATE UNIQUE INDEX UX_ct_titularidad_terreno_vigencia
        ON dbo.ct_titularidad_terreno (id_terreno, id_tercero, vigente_desde);
END;
GO

IF OBJECT_ID('dbo.ct_tercero', 'U') IS NOT NULL
BEGIN
    IF EXISTS (
        SELECT 1
        FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.ct_tercero')
          AND name = 'rut'
          AND is_nullable = 0
    )
    BEGIN
        ALTER TABLE dbo.ct_tercero ALTER COLUMN rut VARCHAR(20) NULL;
    END;

    IF EXISTS (
        SELECT 1
        FROM sys.key_constraints
        WHERE name = 'UQ_ct_tercero_rut'
          AND parent_object_id = OBJECT_ID('dbo.ct_tercero')
    )
    BEGIN
        ALTER TABLE dbo.ct_tercero DROP CONSTRAINT UQ_ct_tercero_rut;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.ct_tercero
        WHERE rut IS NOT NULL
          AND LTRIM(RTRIM(rut)) <> ''
        GROUP BY rut
        HAVING COUNT(*) > 1
    )
    BEGIN
        THROW 50021, 'Existen RUT duplicados en ct_tercero. Depuralos antes de aplicar UX_ct_tercero_rut_informado.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE name = 'UX_ct_tercero_rut_informado'
          AND object_id = OBJECT_ID('dbo.ct_tercero')
    )
    BEGIN
        CREATE UNIQUE INDEX UX_ct_tercero_rut_informado
            ON dbo.ct_tercero (rut)
            WHERE rut IS NOT NULL AND rut <> '';
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.ct_tercero')
          AND name = 'nombre_razon_social_norm'
    )
    BEGIN
        ALTER TABLE dbo.ct_tercero
        ADD nombre_razon_social_norm AS UPPER(LTRIM(RTRIM(nombre_razon_social))) PERSISTED;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.ct_tercero')
          AND name = 'nombre_razon_social_juridica_key'
    )
    BEGIN
        ALTER TABLE dbo.ct_tercero
        ADD nombre_razon_social_juridica_key AS (
            CASE
                WHEN tipo_persona = 'J' AND LTRIM(RTRIM(nombre_razon_social)) <> ''
                    THEN UPPER(LTRIM(RTRIM(nombre_razon_social)))
                ELSE N'ID:' + CONVERT(NVARCHAR(20), id_tercero)
            END
        ) PERSISTED;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.ct_tercero
        WHERE tipo_persona = 'J'
          AND LTRIM(RTRIM(nombre_razon_social)) <> ''
        GROUP BY UPPER(LTRIM(RTRIM(nombre_razon_social)))
        HAVING COUNT(*) > 1
    )
    BEGIN
        THROW 50022, 'Existen razones sociales duplicadas para persona juridica en ct_tercero. Depuralas antes de aplicar UX_ct_tercero_razon_social_juridica.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE name = 'UX_ct_tercero_razon_social_juridica'
          AND object_id = OBJECT_ID('dbo.ct_tercero')
    )
    BEGIN
        EXEC (N'
            CREATE UNIQUE INDEX UX_ct_tercero_razon_social_juridica
                ON dbo.ct_tercero (nombre_razon_social_juridica_key);
        ');
    END;
END;
GO

IF OBJECT_ID('dbo.ct_proyecto_construccion_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'UX_ct_proyecto_construccion_terreno_vigencia'
         AND object_id = OBJECT_ID('dbo.ct_proyecto_construccion_terreno')
   )
BEGIN
    CREATE UNIQUE INDEX UX_ct_proyecto_construccion_terreno_vigencia
        ON dbo.ct_proyecto_construccion_terreno (id_proyecto, id_terreno, id_rol_en_proyecto, vigente_desde);
END;
GO

IF OBJECT_ID('dbo.ct_avaluo_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'UX_ct_avaluo_terreno_anio'
         AND object_id = OBJECT_ID('dbo.ct_avaluo_terreno')
   )
BEGIN
    CREATE UNIQUE INDEX UX_ct_avaluo_terreno_anio
        ON dbo.ct_avaluo_terreno (id_terreno, anio_avaluo);
END;
GO

IF OBJECT_ID('dbo.ct_venta_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'UX_ct_venta_terreno_unica'
         AND object_id = OBJECT_ID('dbo.ct_venta_terreno')
   )
BEGIN
    CREATE UNIQUE INDEX UX_ct_venta_terreno_unica
        ON dbo.ct_venta_terreno (id_terreno);
END;
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.indexes
       WHERE name = 'UX_ct_tasacion_terreno_referencial'
         AND object_id = OBJECT_ID('dbo.ct_tasacion_terreno')
   )
BEGIN
    CREATE UNIQUE INDEX UX_ct_tasacion_terreno_referencial
        ON dbo.ct_tasacion_terreno (id_terreno)
        WHERE es_referencial = 1;
END;
GO

/* ==================================
   Checks de consistencia adicional
   ================================== */

IF OBJECT_ID('dbo.ct_terreno_arquitectura_legal', 'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1
       FROM sys.check_constraints
       WHERE name = 'CK_ct_terreno_arquitectura_legal_garantia_vencimiento'
         AND parent_object_id = OBJECT_ID('dbo.ct_terreno_arquitectura_legal')
   )
BEGIN
    ALTER TABLE dbo.ct_terreno_arquitectura_legal
    ADD CONSTRAINT CK_ct_terreno_arquitectura_legal_garantia_vencimiento
        CHECK (
            garantia IS NULL
            OR garantia = 0
            OR (garantia = 1 AND vencimiento_garantia IS NOT NULL)
        );
END;
GO

/* ==================================
   Trigger: porcentaje por venta <= 100
   ================================== */

IF OBJECT_ID('dbo.ct_venta_terreno_tercero', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER TRIGGER dbo.TR_ct_venta_terreno_tercero_pct_max100
    ON dbo.ct_venta_terreno_tercero
    AFTER INSERT, UPDATE, DELETE
    AS
    BEGIN
        SET NOCOUNT ON;

        IF EXISTS (
            SELECT 1
            FROM (
                SELECT
                    va.id_venta,
                    CAST(ISNULL(SUM(vt.porcentaje), 0.00) AS decimal(10,2)) AS total_pct
                FROM (
                    SELECT DISTINCT id_venta
                    FROM inserted
                    WHERE id_venta IS NOT NULL
                    UNION
                    SELECT DISTINCT id_venta
                    FROM deleted
                    WHERE id_venta IS NOT NULL
                ) va
                LEFT JOIN dbo.ct_venta_terreno_tercero vt
                    ON vt.id_venta = va.id_venta
                GROUP BY va.id_venta
            ) sumas
            WHERE total_pct <> 100.00
        )
        BEGIN
            THROW 50011, ''Porcentaje invalido: suma de terceros por venta debe ser 100.00.'', 1;
        END;
    END;
    ');
END;
GO

PRINT 'Integridad CT aplicada correctamente.';
GO
