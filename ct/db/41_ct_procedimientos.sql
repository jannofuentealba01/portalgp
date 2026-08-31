/*
  Script: 41_ct_procedimientos.sql
  Objetivo:
  - Procedimientos de flujos contables (tasacion y venta)
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ==================================
   Tipo de tabla para terceros en venta
   ================================== */

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_venta_tercero'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_venta_tercero AS TABLE (
            id_tercero INT NOT NULL,
            porcentaje DECIMAL(5,2) NOT NULL,
            rol_en_venta NVARCHAR(30) NULL
        );
    ');
END;
GO

/* ==================================
   Procedimiento: crear tasacion
   ================================== */

IF OBJECT_ID('dbo.sp_ct_tasacion_terreno_crear', 'P') IS NULL
BEGIN
    EXEC (N'
    CREATE PROCEDURE dbo.sp_ct_tasacion_terreno_crear
        @id_terreno            INT,
        @id_tipo_tasacion      INT,
        @fecha_tasacion        DATE,
        @valor_total_uf        DECIMAL(18,2),
        @valor_uf_m2           DECIMAL(18,4) = NULL,
        @id_entidad_financiera INT = NULL,
        @es_referencial        BIT = 0,
        @vigente_desde         DATE = NULL,
        @vigente_hasta         DATE = NULL,
        @id_usuario            INT,
        @id_tasacion           INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        BEGIN TRAN;

        IF @es_referencial = 1
        BEGIN
            UPDATE dbo.ct_tasacion_terreno
            SET es_referencial = 0
            WHERE id_terreno = @id_terreno
              AND es_referencial = 1;
        END;

        INSERT INTO dbo.ct_tasacion_terreno (
            id_terreno,
            id_tipo_tasacion,
            fecha_tasacion,
            valor_total_uf,
            valor_uf_m2,
            id_entidad_financiera,
            es_referencial,
            vigente_desde,
            vigente_hasta,
            id_usuario
        )
        VALUES (
            @id_terreno,
            @id_tipo_tasacion,
            @fecha_tasacion,
            @valor_total_uf,
            @valor_uf_m2,
            @id_entidad_financiera,
            @es_referencial,
            @vigente_desde,
            @vigente_hasta,
            @id_usuario
        );

        SET @id_tasacion = SCOPE_IDENTITY();

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: crear venta con terceros
   ================================== */

IF OBJECT_ID('dbo.sp_ct_venta_terreno_crear', 'P') IS NULL
BEGIN
    EXEC (N'
    CREATE PROCEDURE dbo.sp_ct_venta_terreno_crear
        @id_terreno              INT,
        @fecha_venta             DATE,
        @valor_total_uf          DECIMAL(18,2),
        @valor_venta_uf_m2       DECIMAL(18,4) = NULL,
        @id_tasacion_referencial INT = NULL,
        @terceros                dbo.ct_tt_venta_tercero READONLY,
        @validar_100             BIT = 1,
        @id_venta                INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF @validar_100 = 1
        BEGIN
            DECLARE @suma DECIMAL(10,2);
            SELECT @suma = ISNULL(SUM(porcentaje), 0.00) FROM @terceros;
            IF @suma <> 100.00
                THROW 50041, ''Venta invalida: porcentaje debe sumar 100.'', 1;
        END;

        BEGIN TRAN;

        INSERT INTO dbo.ct_venta_terreno (
            id_terreno,
            fecha_venta,
            valor_total_uf,
            valor_venta_uf_m2,
            id_tasacion_referencial
        )
        VALUES (
            @id_terreno,
            @fecha_venta,
            @valor_total_uf,
            @valor_venta_uf_m2,
            @id_tasacion_referencial
        );

        SET @id_venta = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_venta_terreno_tercero (
            id_venta,
            id_tercero,
            porcentaje,
            rol_en_venta
        )
        SELECT
            @id_venta,
            t.id_tercero,
            t.porcentaje,
            t.rol_en_venta
        FROM @terceros t;

        COMMIT TRAN;
    END;
    ');
END;
GO

PRINT 'Procedimientos contables aplicados correctamente.';
GO
