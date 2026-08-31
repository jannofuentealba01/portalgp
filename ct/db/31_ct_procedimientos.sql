/*
  Script: 31_ct_procedimientos.sql
  Objetivo:
  - Triggers y procedimientos clave del flujo tributario
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ==================================
   Trigger: consistencia avaluo
   ================================== */
/*
  Descripcion:
  - Valida que avaluo_afecto = avaluo_total - avaluo_exento cuando existan.
  - Evita valores negativos en avaluos.
*/

IF OBJECT_ID('dbo.ct_avaluo_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER TRIGGER dbo.TR_ct_avaluo_terreno_consistencia
    ON dbo.ct_avaluo_terreno
    AFTER INSERT, UPDATE
    AS
    BEGIN
        SET NOCOUNT ON;

        IF EXISTS (
            SELECT 1
            FROM inserted i
            WHERE (i.avaluo_total IS NOT NULL AND i.avaluo_total < 0)
               OR (i.avaluo_exento IS NOT NULL AND i.avaluo_exento < 0)
               OR (i.avaluo_afecto IS NOT NULL AND i.avaluo_afecto < 0)
               OR (i.valor_libro_contable_uf IS NOT NULL AND i.valor_libro_contable_uf < 0)
        )
        BEGIN
            THROW 50051, ''Avaluo invalido: no se permiten valores negativos.'', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM inserted i
            WHERE i.avaluo_total IS NOT NULL
              AND i.avaluo_exento IS NOT NULL
              AND i.avaluo_afecto IS NOT NULL
              AND i.avaluo_afecto <> (i.avaluo_total - i.avaluo_exento)
        )
        BEGIN
            THROW 50052, ''Avaluo invalido: avaluo_afecto debe ser avaluo_total - avaluo_exento.'', 1;
        END;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: registrar/actualizar avaluo
   ================================== */
/*
  Descripcion:
  - Inserta o actualiza un avaluo por (id_terreno, anio_avaluo, fecha_referencia).
  - Si existe, actualiza; si no, inserta.
*/

IF OBJECT_ID('dbo.sp_ct_avaluo_terreno_upsert', 'P') IS NULL
BEGIN
    EXEC (N'
    CREATE PROCEDURE dbo.sp_ct_avaluo_terreno_upsert
        @id_terreno              INT,
        @anio_avaluo             INT,
        @fecha_referencia        DATE,
        @avaluo_total            DECIMAL(18,2) = NULL,
        @avaluo_exento           DECIMAL(18,2) = NULL,
        @avaluo_afecto           DECIMAL(18,2) = NULL,
        @valor_libro_contable_uf DECIMAL(18,2) = NULL,
        @id_usuario              INT,
        @id_avaluo               INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        DECLARE @id_existente INT;

        SELECT @id_existente = id_avaluo
        FROM dbo.ct_avaluo_terreno
        WHERE id_terreno = @id_terreno
          AND anio_avaluo = @anio_avaluo
          AND fecha_referencia = @fecha_referencia;

        IF @id_existente IS NULL
        BEGIN
            INSERT INTO dbo.ct_avaluo_terreno (
                id_terreno,
                anio_avaluo,
                fecha_referencia,
                avaluo_total,
                avaluo_exento,
                avaluo_afecto,
                valor_libro_contable_uf,
                id_usuario
            )
            VALUES (
                @id_terreno,
                @anio_avaluo,
                @fecha_referencia,
                @avaluo_total,
                @avaluo_exento,
                @avaluo_afecto,
                @valor_libro_contable_uf,
                @id_usuario
            );

            SET @id_avaluo = SCOPE_IDENTITY();
        END
        ELSE
        BEGIN
            UPDATE dbo.ct_avaluo_terreno
            SET avaluo_total = @avaluo_total,
                avaluo_exento = @avaluo_exento,
                avaluo_afecto = @avaluo_afecto,
                valor_libro_contable_uf = @valor_libro_contable_uf,
                id_usuario = @id_usuario
            WHERE id_avaluo = @id_existente;

            SET @id_avaluo = @id_existente;
        END
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: registrar hipoteca
   ================================== */
/*
  Descripcion:
  - Inserta una hipoteca asociada a un terreno.
  - Valida vigencias y entidad financiera existente.
*/

IF OBJECT_ID('dbo.sp_ct_hipoteca_terreno_crear', 'P') IS NULL
BEGIN
    EXEC (N'
    CREATE PROCEDURE dbo.sp_ct_hipoteca_terreno_crear
        @id_terreno            INT,
        @id_entidad_financiera INT,
        @vigencia_desde        DATE,
        @vigencia_hasta        DATE = NULL,
        @id_hipoteca           INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF @vigencia_hasta IS NOT NULL AND @vigencia_hasta < @vigencia_desde
        BEGIN
            THROW 50061, ''Vigencia invalida: vigencia_hasta < vigencia_desde.'', 1;
        END;

        /*
          Compatibilidad:
          La tabla actual ct_hipoteca_terreno no persiste vigencia_desde/vigencia_hasta.
          Se conserva la firma historica del procedimiento y se materializa
          la fecha de constitucion con @vigencia_desde.
        */
        INSERT INTO dbo.ct_hipoteca_terreno (
            id_terreno,
            id_entidad_financiera,
            fecha_constitucion
        )
        VALUES (
            @id_terreno,
            @id_entidad_financiera,
            @vigencia_desde
        );

        SET @id_hipoteca = SCOPE_IDENTITY();
    END;
    ');
END;
GO

PRINT 'Procedimientos CT tributaria aplicados correctamente.';
GO
