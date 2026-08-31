/*
  Script: 21_ct_procedimientos.sql
  Objetivo:
  - Triggers y procedimientos clave del flujo construccion
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ==================================
   Tipos de tabla para alta masiva
   ================================== */

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_proyecto_terreno'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_proyecto_terreno AS TABLE (
            id_terreno INT NOT NULL,
            id_rol_en_proyecto INT NOT NULL,
            vigente_desde DATE NULL,
            vigente_hasta DATE NULL
        );
    ');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_construccion'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_construccion AS TABLE (
            tipo_construccion NVARCHAR(80) NULL,
            nombre NVARCHAR(150) NOT NULL,
            superficie_m2 DECIMAL(18,2) NULL
        );
    ');
END;
GO

/* ==================================
   Trigger: evitar solapes por terreno/rol
   ================================== */

IF OBJECT_ID('dbo.ct_proyecto_construccion_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER TRIGGER dbo.TR_ct_proyecto_construccion_terreno_validacion
    ON dbo.ct_proyecto_construccion_terreno
    AFTER INSERT, UPDATE, DELETE
    AS
    BEGIN
        SET NOCOUNT ON;

        IF EXISTS (
            SELECT 1
            FROM dbo.ct_proyecto_construccion_terreno t1
            JOIN dbo.ct_proyecto_construccion_terreno t2
              ON t1.id_terreno = t2.id_terreno
             AND t1.id_rol_en_proyecto = t2.id_rol_en_proyecto
             AND t1.id_proyecto_construccion_terreno <> t2.id_proyecto_construccion_terreno
            WHERE (t1.vigente_hasta IS NULL OR t2.vigente_desde IS NULL OR t2.vigente_desde <= t1.vigente_hasta)
              AND (t2.vigente_hasta IS NULL OR t1.vigente_desde IS NULL OR t1.vigente_desde <= t2.vigente_hasta)
              AND EXISTS (
                  SELECT 1
                  FROM (
                      SELECT DISTINCT id_terreno, id_rol_en_proyecto
                      FROM inserted
                      UNION
                      SELECT DISTINCT id_terreno, id_rol_en_proyecto
                      FROM deleted
                  ) a
                  WHERE a.id_terreno = t1.id_terreno
                    AND a.id_rol_en_proyecto = t1.id_rol_en_proyecto
              )
        )
        BEGIN
            THROW 50041, ''Relacion proyecto/terreno invalida: existen periodos solapados para el mismo terreno y rol.'', 1;
        END;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: crear proyecto con detalles
   ================================== */
/*
  Descripcion:
  - Crea un proyecto de construccion.
  - Inserta terrenos asociados y construcciones en una sola transaccion.
*/

IF OBJECT_ID('dbo.sp_ct_proyecto_construccion_crear', 'P') IS NULL
BEGIN
    EXEC (N'
    CREATE PROCEDURE dbo.sp_ct_proyecto_construccion_crear
        @nombre         NVARCHAR(150),
        @id_tercero     INT,
        @estado         NVARCHAR(50) = NULL,
        @fecha_inicio   DATE = NULL,
        @fecha_termino  DATE = NULL,
        @terrenos       dbo.ct_tt_proyecto_terreno READONLY,
        @construcciones dbo.ct_tt_construccion READONLY,
        @id_proyecto    INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        BEGIN TRAN;

        INSERT INTO dbo.ct_proyecto_construccion (nombre, id_tercero, estado, fecha_inicio, fecha_termino)
        VALUES (@nombre, @id_tercero, @estado, @fecha_inicio, @fecha_termino);

        SET @id_proyecto = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_proyecto_construccion_terreno (
            id_proyecto, id_terreno, id_rol_en_proyecto, vigente_desde, vigente_hasta
        )
        SELECT @id_proyecto, t.id_terreno, t.id_rol_en_proyecto, t.vigente_desde, t.vigente_hasta
        FROM @terrenos t;

        INSERT INTO dbo.ct_construccion (id_proyecto, tipo_construccion, nombre, superficie_m2)
        SELECT @id_proyecto, c.tipo_construccion, c.nombre, c.superficie_m2
        FROM @construcciones c;

        COMMIT TRAN;
    END;
    ');
END;
GO

PRINT 'Procedimientos CT construccion aplicados correctamente.';
GO
