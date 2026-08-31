/*
  Script: 11_ct_procedimientos.sql
  Objetivo:
  - Triggers y procedimientos clave del flujo predial
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ==================================
   Tipos de tabla para predial
   ================================== */

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_operacion_terreno'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_operacion_terreno AS TABLE (
            id_terreno INT NOT NULL,
            rol_en_operacion NVARCHAR(30) NULL
        );
    ');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_titularidad'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_titularidad AS TABLE (
            id_tercero INT NOT NULL,
            porcentaje_derecho DECIMAL(5,2) NOT NULL,
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
      AND name = 'ct_tt_subdivision_resultado'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_subdivision_resultado AS TABLE (
            id_terreno INT NOT NULL
        );
    ');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.types
    WHERE is_table_type = 1
      AND name = 'ct_tt_fusion_origen'
)
BEGIN
    EXEC (N'
        CREATE TYPE dbo.ct_tt_fusion_origen AS TABLE (
            id_terreno INT NOT NULL
        );
    ');
END;
GO

/* ==================================
   Trigger: validar titularidad
   - no solapamiento por tercero
   - suma vigente <= 100
   ================================== */

IF OBJECT_ID('dbo.ct_titularidad_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER TRIGGER dbo.TR_ct_titularidad_terreno_validacion
    ON dbo.ct_titularidad_terreno
    AFTER INSERT, UPDATE, DELETE
    AS
    BEGIN
        SET NOCOUNT ON;

        -- solapamiento por mismo tercero
        IF EXISTS (
            SELECT 1
            FROM dbo.ct_titularidad_terreno t1
            JOIN dbo.ct_titularidad_terreno t2
              ON t1.id_terreno = t2.id_terreno
             AND t1.id_tercero = t2.id_tercero
             AND t1.id_titularidad <> t2.id_titularidad
            WHERE t1.id_terreno IN (
                SELECT DISTINCT id_terreno FROM inserted
                UNION
                SELECT DISTINCT id_terreno FROM deleted
            )
              AND (t1.vigente_hasta IS NULL OR t2.vigente_desde <= t1.vigente_hasta)
              AND (t2.vigente_hasta IS NULL OR t1.vigente_desde <= t2.vigente_hasta)
        )
        BEGIN
            THROW 50021, ''Titularidad invalida: existen periodos solapados para el mismo tercero.'', 1;
        END;

        -- suma vigente <= 100
        IF EXISTS (
            SELECT 1
            FROM (
                SELECT id_terreno, SUM(porcentaje_derecho) AS total_pct
                FROM dbo.ct_titularidad_terreno
                WHERE id_terreno IN (
                    SELECT DISTINCT id_terreno FROM inserted
                    UNION
                    SELECT DISTINCT id_terreno FROM deleted
                )
                  AND vigente_hasta IS NULL
                GROUP BY id_terreno
            ) s
            WHERE s.total_pct > 100.00
        )
        BEGIN
            THROW 50022, ''Titularidad invalida: porcentaje vigente supera 100.'', 1;
        END;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: crear operacion predial
   ================================== */
/*
  Descripcion:
  - Crea una operacion predial y su detalle en ct_operacion_terreno.
  - Uso tipico: subdivisiones, fusiones, ventas u otras operaciones sobre terrenos.
*/

IF OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_operacion_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_operacion_predial_crear
        @tipo_operacion   NVARCHAR(50),
        @fecha_operacion  DATE,
        @documento_fuente NVARCHAR(255) = NULL,
        @detalles         dbo.ct_tt_operacion_terreno READONLY,
        @id_operacion     INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF NOT EXISTS (SELECT 1 FROM @detalles)
        BEGIN
            THROW 50023, ''Operacion invalida: no se entregaron detalles.'', 1;
        END;

        BEGIN TRAN;

        INSERT INTO dbo.ct_operacion_predial (tipo_operacion, fecha_operacion, documento_fuente)
        VALUES (@tipo_operacion, @fecha_operacion, @documento_fuente);

        SET @id_operacion = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        SELECT @id_operacion, d.id_terreno, d.rol_en_operacion
        FROM @detalles d;

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: cambiar estado de terreno
   ================================== */
/*
  Descripcion:
  - Cambia el estado predial o comercial del terreno.
  - Registra el cambio en ct_historial_estado_terreno.
*/

IF OBJECT_ID('dbo.ct_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_historial_estado_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_terreno_cambiar_estado
        @id_terreno      INT,
        @tipo_estado     CHAR(1), -- P o C
        @id_estado_nuevo INT,
        @id_usuario      INT,
        @id_operacion    INT = NULL,
        @id_venta        INT = NULL,
        @fecha_cambio    DATETIME2(0) = NULL
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF @tipo_estado NOT IN (''P'', ''C'')
        BEGIN
            THROW 50031, ''tipo_estado invalido. Use P o C.'', 1;
        END;

        IF @fecha_cambio IS NULL
            SET @fecha_cambio = SYSUTCDATETIME();

        DECLARE @estado_anterior INT;

        BEGIN TRAN;

        IF @tipo_estado = ''P''
        BEGIN
            SELECT @estado_anterior = id_estado_predial
            FROM dbo.ct_terreno WITH (UPDLOCK, ROWLOCK)
            WHERE id_terreno = @id_terreno;

            IF @estado_anterior IS NULL
            BEGIN
                THROW 50032, ''Terreno no existe.'', 1;
            END;

            IF @estado_anterior = @id_estado_nuevo
            BEGIN
                COMMIT TRAN;
                RETURN;
            END;

            UPDATE dbo.ct_terreno
            SET id_estado_predial = @id_estado_nuevo
            WHERE id_terreno = @id_terreno;
        END
        ELSE
        BEGIN
            SELECT @estado_anterior = id_estado_comercial
            FROM dbo.ct_terreno WITH (UPDLOCK, ROWLOCK)
            WHERE id_terreno = @id_terreno;

            IF @estado_anterior IS NULL
            BEGIN
                THROW 50032, ''Terreno no existe.'', 1;
            END;

            IF @estado_anterior = @id_estado_nuevo
            BEGIN
                COMMIT TRAN;
                RETURN;
            END;

            UPDATE dbo.ct_terreno
            SET id_estado_comercial = @id_estado_nuevo
            WHERE id_terreno = @id_terreno;
        END

        INSERT INTO dbo.ct_historial_estado_terreno (
            id_terreno,
            id_estado_anterior,
            id_estado_nuevo,
            fecha_cambio,
            id_venta,
            id_operacion,
            id_usuario,
            tipo_estado
        )
        VALUES (
            @id_terreno,
            @estado_anterior,
            @id_estado_nuevo,
            @fecha_cambio,
            @id_venta,
            @id_operacion,
            @id_usuario,
            @tipo_estado
        );

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: registrar titularidad
   ================================== */
/*
  Descripcion:
  - Registra una nueva titularidad para un terreno.
  - Opcionalmente cierra la titularidad vigente del mismo tercero.
*/

IF OBJECT_ID('dbo.ct_titularidad_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_titularidad_terreno_registrar
        @id_terreno            INT,
        @id_tercero            INT,
        @vigente_desde         DATE,
        @porcentaje_derecho    DECIMAL(5,2),
        @vigente_hasta         DATE = NULL,
        @cerrar_vigente_actual BIT = 0,
        @id_titularidad        INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF @vigente_hasta IS NOT NULL AND @vigente_hasta < @vigente_desde
        BEGIN
            THROW 50033, ''Titularidad invalida: vigente_hasta no puede ser menor a vigente_desde.'', 1;
        END;

        BEGIN TRAN;

        IF @cerrar_vigente_actual = 1
        BEGIN
            UPDATE dbo.ct_titularidad_terreno
            SET vigente_hasta = DATEADD(DAY, -1, @vigente_desde)
            WHERE id_terreno = @id_terreno
              AND id_tercero = @id_tercero
              AND vigente_hasta IS NULL
              AND vigente_desde <= @vigente_desde;
        END;

        INSERT INTO dbo.ct_titularidad_terreno (
            id_terreno,
            id_tercero,
            vigente_desde,
            vigente_hasta,
            porcentaje_derecho
        )
        VALUES (
            @id_terreno,
            @id_tercero,
            @vigente_desde,
            @vigente_hasta,
            @porcentaje_derecho
        );

        SET @id_titularidad = SCOPE_IDENTITY();

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: adquisicion de terreno
   ================================== */
/*
  Descripcion:
  - Registra adquisicion formal de un terreno en una transaccion:
    1) crea terreno
    2) crea titularidades iniciales
    3) registra operacion predial ADQUISICION
    4) registra historial inicial predial y comercial
*/

IF OBJECT_ID('dbo.ct_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_titularidad_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_operacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_historial_estado_terreno', 'U') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_terreno_adquisicion_registrar
        @rol_asignado             VARCHAR(30),
        @rol_matriz               VARCHAR(30) = NULL,
        @identificacion_propiedad NVARCHAR(120) = NULL,
        @superficie_m2            DECIMAL(18,2),
        @id_comuna                INT,
        @id_estado_predial        INT = NULL,
        @id_estado_comercial      INT = NULL,
        @id_tipo_inmueble         INT,
        @fecha_adquisicion        DATE,
        @documento_fuente         NVARCHAR(255) = NULL,
        @id_usuario               INT,
        @titulares                dbo.ct_tt_titularidad READONLY,
        @id_terreno               INT OUTPUT,
        @id_operacion             INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;

        IF NOT EXISTS (SELECT 1 FROM @titulares)
        BEGIN
            THROW 50034, ''Adquisicion invalida: debes informar al menos un titular.'', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @titulares t
            WHERE t.vigente_hasta IS NOT NULL
              AND COALESCE(t.vigente_desde, @fecha_adquisicion) > t.vigente_hasta
        )
        BEGIN
            THROW 50035, ''Adquisicion invalida: existe titularidad con vigencia_hasta menor que vigente_desde.'', 1;
        END;

        DECLARE @suma_titulares DECIMAL(10,2);
        SELECT @suma_titulares = CAST(ISNULL(SUM(t.porcentaje_derecho), 0.00) AS DECIMAL(10,2))
        FROM @titulares t;

        IF @suma_titulares <> 100.00
        BEGIN
            THROW 50036, ''Adquisicion invalida: la suma de porcentajes de titularidad debe ser 100.00.'', 1;
        END;

        DECLARE @id_estado_predial_resuelto INT = ISNULL(@id_estado_predial, 0);
        DECLARE @id_estado_comercial_resuelto INT = ISNULL(@id_estado_comercial, 0);

        IF @id_estado_predial_resuelto <= 0
        BEGIN
            SELECT TOP (1) @id_estado_predial_resuelto = id_estado_predial
            FROM dbo.ct_estado_terreno_predial
            WHERE UPPER(LTRIM(RTRIM(nombre))) = ''DISPONIBLE'';

            IF @id_estado_predial_resuelto IS NULL OR @id_estado_predial_resuelto <= 0
            BEGIN
                INSERT INTO dbo.ct_estado_terreno_predial (nombre)
                VALUES (N''DISPONIBLE'');

                SELECT TOP (1) @id_estado_predial_resuelto = id_estado_predial
                FROM dbo.ct_estado_terreno_predial
                WHERE UPPER(LTRIM(RTRIM(nombre))) = ''DISPONIBLE'';
            END;
        END;

        IF @id_estado_comercial_resuelto <= 0
        BEGIN
            SELECT TOP (1) @id_estado_comercial_resuelto = id_estado_comercial
            FROM dbo.ct_estado_terreno_comercial
            WHERE UPPER(LTRIM(RTRIM(nombre))) = ''SIN DEFINIR'';

            IF @id_estado_comercial_resuelto IS NULL OR @id_estado_comercial_resuelto <= 0
            BEGIN
                INSERT INTO dbo.ct_estado_terreno_comercial (nombre)
                VALUES (N''SIN DEFINIR'');

                SELECT TOP (1) @id_estado_comercial_resuelto = id_estado_comercial
                FROM dbo.ct_estado_terreno_comercial
                WHERE UPPER(LTRIM(RTRIM(nombre))) = ''SIN DEFINIR'';
            END;
        END;

        IF @id_estado_predial_resuelto <= 0
        BEGIN
            THROW 50037, ''Adquisicion invalida: no fue posible resolver estado predial DISPONIBLE.'', 1;
        END;

        IF @id_estado_comercial_resuelto <= 0
        BEGIN
            THROW 50038, ''Adquisicion invalida: no fue posible resolver estado comercial SIN DEFINIR.'', 1;
        END;

        BEGIN TRAN;

        INSERT INTO dbo.ct_terreno (
            rol_asignado,
            rol_matriz,
            identificacion_propiedad,
            superficie_m2,
            id_comuna,
            id_estado_predial,
            id_estado_comercial,
            id_tipo_inmueble
        )
        VALUES (
            @rol_asignado,
            @rol_matriz,
            @identificacion_propiedad,
            @superficie_m2,
            @id_comuna,
            @id_estado_predial_resuelto,
            @id_estado_comercial_resuelto,
            @id_tipo_inmueble
        );

        SET @id_terreno = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_titularidad_terreno (
            id_terreno,
            id_tercero,
            vigente_desde,
            vigente_hasta,
            porcentaje_derecho
        )
        SELECT
            @id_terreno,
            t.id_tercero,
            COALESCE(t.vigente_desde, @fecha_adquisicion),
            t.vigente_hasta,
            t.porcentaje_derecho
        FROM @titulares t;

        INSERT INTO dbo.ct_operacion_predial (tipo_operacion, fecha_operacion, documento_fuente)
        VALUES (N''ADQUISICION'', @fecha_adquisicion, @documento_fuente);

        SET @id_operacion = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        VALUES (@id_operacion, @id_terreno, N''ADQUIRIDO'');

        INSERT INTO dbo.ct_historial_estado_terreno (
            id_terreno,
            id_estado_anterior,
            id_estado_nuevo,
            fecha_cambio,
            id_venta,
            id_operacion,
            id_usuario,
            tipo_estado
        )
        VALUES (
            @id_terreno,
            NULL,
            @id_estado_predial_resuelto,
            CAST(@fecha_adquisicion AS DATETIME2(0)),
            NULL,
            @id_operacion,
            @id_usuario,
            ''P''
        );

        INSERT INTO dbo.ct_historial_estado_terreno (
            id_terreno,
            id_estado_anterior,
            id_estado_nuevo,
            fecha_cambio,
            id_venta,
            id_operacion,
            id_usuario,
            tipo_estado
        )
        VALUES (
            @id_terreno,
            NULL,
            @id_estado_comercial_resuelto,
            CAST(@fecha_adquisicion AS DATETIME2(0)),
            NULL,
            @id_operacion,
            @id_usuario,
            ''C''
        );

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: registrar subdivision
   ================================== */
/*
  Descripcion:
  - Registra operacion SUBDIVISION.
  - Asocia terreno origen y terrenos resultado.
  - Opcionalmente actualiza estado predial de origen y resultados (con historial).
*/

IF OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_operacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.sp_ct_terreno_cambiar_estado', 'P') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_terreno_subdivision_registrar
        @id_terreno_origen        INT,
        @fecha_operacion          DATE,
        @documento_fuente         NVARCHAR(255) = NULL,
        @id_usuario               INT,
        @id_estado_origen_nuevo   INT = NULL,
        @id_estado_resultado_nuevo INT = NULL,
        @terrenos_resultado       dbo.ct_tt_subdivision_resultado READONLY,
        @id_operacion             INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;
        DECLARE @fecha_cambio_dt DATETIME2(0) = CAST(@fecha_operacion AS DATETIME2(0));

        DECLARE @resultados TABLE (
            id_terreno INT PRIMARY KEY
        );

        INSERT INTO @resultados (id_terreno)
        SELECT DISTINCT tr.id_terreno
        FROM @terrenos_resultado tr
        WHERE tr.id_terreno IS NOT NULL;

        IF (SELECT COUNT(*) FROM @resultados) < 2
        BEGIN
            THROW 50037, ''Subdivision invalida: debes informar al menos 2 terrenos resultado.'', 1;
        END;

        IF EXISTS (SELECT 1 FROM @resultados WHERE id_terreno = @id_terreno_origen)
        BEGIN
            THROW 50038, ''Subdivision invalida: el terreno origen no puede quedar como resultado.'', 1;
        END;

        BEGIN TRAN;

        INSERT INTO dbo.ct_operacion_predial (tipo_operacion, fecha_operacion, documento_fuente)
        VALUES (N''SUBDIVISION'', @fecha_operacion, @documento_fuente);

        SET @id_operacion = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        VALUES (@id_operacion, @id_terreno_origen, N''ORIGEN'');

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        SELECT @id_operacion, r.id_terreno, N''RESULTADO''
        FROM @resultados r;

        IF @id_estado_origen_nuevo IS NOT NULL
        BEGIN
            EXEC dbo.sp_ct_terreno_cambiar_estado
                @id_terreno = @id_terreno_origen,
                @tipo_estado = ''P'',
                @id_estado_nuevo = @id_estado_origen_nuevo,
                @id_usuario = @id_usuario,
                @id_operacion = @id_operacion,
                @id_venta = NULL,
                @fecha_cambio = @fecha_cambio_dt;
        END;

        IF @id_estado_resultado_nuevo IS NOT NULL
        BEGIN
            DECLARE @id_terreno_resultado INT;
            DECLARE cur_resultados CURSOR LOCAL FAST_FORWARD FOR
                SELECT r.id_terreno FROM @resultados r;

            OPEN cur_resultados;
            FETCH NEXT FROM cur_resultados INTO @id_terreno_resultado;

            WHILE @@FETCH_STATUS = 0
            BEGIN
                EXEC dbo.sp_ct_terreno_cambiar_estado
                    @id_terreno = @id_terreno_resultado,
                    @tipo_estado = ''P'',
                    @id_estado_nuevo = @id_estado_resultado_nuevo,
                    @id_usuario = @id_usuario,
                    @id_operacion = @id_operacion,
                    @id_venta = NULL,
                    @fecha_cambio = @fecha_cambio_dt;

                FETCH NEXT FROM cur_resultados INTO @id_terreno_resultado;
            END;

            CLOSE cur_resultados;
            DEALLOCATE cur_resultados;
        END;

        COMMIT TRAN;
    END;
    ');
END;
GO

/* ==================================
   Procedimiento: registrar fusion
   ================================== */
/*
  Descripcion:
  - Registra operacion FUSION.
  - Asocia terrenos origen y terreno resultado.
  - Opcionalmente actualiza estado predial de origenes y resultado (con historial).
*/

IF OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_operacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.sp_ct_terreno_cambiar_estado', 'P') IS NOT NULL
BEGIN
    EXEC (N'
    CREATE OR ALTER PROCEDURE dbo.sp_ct_terreno_fusion_registrar
        @id_terreno_resultado      INT,
        @fecha_operacion           DATE,
        @documento_fuente          NVARCHAR(255) = NULL,
        @id_usuario                INT,
        @id_estado_origen_nuevo    INT = NULL,
        @id_estado_resultado_nuevo INT = NULL,
        @terrenos_origen           dbo.ct_tt_fusion_origen READONLY,
        @id_operacion              INT OUTPUT
    AS
    BEGIN
        SET NOCOUNT ON;
        SET XACT_ABORT ON;
        DECLARE @fecha_cambio_dt DATETIME2(0) = CAST(@fecha_operacion AS DATETIME2(0));

        DECLARE @origenes TABLE (
            id_terreno INT PRIMARY KEY
        );

        INSERT INTO @origenes (id_terreno)
        SELECT DISTINCT to1.id_terreno
        FROM @terrenos_origen to1
        WHERE to1.id_terreno IS NOT NULL;

        IF (SELECT COUNT(*) FROM @origenes) < 2
        BEGIN
            THROW 50039, ''Fusion invalida: debes informar al menos 2 terrenos origen.'', 1;
        END;

        IF EXISTS (SELECT 1 FROM @origenes WHERE id_terreno = @id_terreno_resultado)
        BEGIN
            THROW 50040, ''Fusion invalida: el terreno resultado no puede ser uno de los origenes.'', 1;
        END;

        BEGIN TRAN;

        INSERT INTO dbo.ct_operacion_predial (tipo_operacion, fecha_operacion, documento_fuente)
        VALUES (N''FUSION'', @fecha_operacion, @documento_fuente);

        SET @id_operacion = SCOPE_IDENTITY();

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        SELECT @id_operacion, o.id_terreno, N''ORIGEN''
        FROM @origenes o;

        INSERT INTO dbo.ct_operacion_terreno (id_operacion, id_terreno, rol_en_operacion)
        VALUES (@id_operacion, @id_terreno_resultado, N''RESULTADO'');

        IF @id_estado_origen_nuevo IS NOT NULL
        BEGIN
            DECLARE @id_terreno_origen INT;
            DECLARE cur_origenes CURSOR LOCAL FAST_FORWARD FOR
                SELECT o.id_terreno FROM @origenes o;

            OPEN cur_origenes;
            FETCH NEXT FROM cur_origenes INTO @id_terreno_origen;

            WHILE @@FETCH_STATUS = 0
            BEGIN
                EXEC dbo.sp_ct_terreno_cambiar_estado
                    @id_terreno = @id_terreno_origen,
                    @tipo_estado = ''P'',
                    @id_estado_nuevo = @id_estado_origen_nuevo,
                    @id_usuario = @id_usuario,
                    @id_operacion = @id_operacion,
                    @id_venta = NULL,
                    @fecha_cambio = @fecha_cambio_dt;

                FETCH NEXT FROM cur_origenes INTO @id_terreno_origen;
            END;

            CLOSE cur_origenes;
            DEALLOCATE cur_origenes;
        END;

        IF @id_estado_resultado_nuevo IS NOT NULL
        BEGIN
            EXEC dbo.sp_ct_terreno_cambiar_estado
                @id_terreno = @id_terreno_resultado,
                @tipo_estado = ''P'',
                @id_estado_nuevo = @id_estado_resultado_nuevo,
                @id_usuario = @id_usuario,
                @id_operacion = @id_operacion,
                @id_venta = NULL,
                @fecha_cambio = @fecha_cambio_dt;
        END;

        COMMIT TRAN;
    END;
    ');
END;
GO

PRINT 'Procedimientos CT predial aplicados correctamente.';
GO
