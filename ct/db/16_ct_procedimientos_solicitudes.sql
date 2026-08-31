/*
  Script: 16_ct_procedimientos_solicitudes.sql
  Objetivo:
  - Objetos programables del nucleo de Solicitudes CT.
  - Recalculo de estado general e historial de cambios.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

EXEC (N'
CREATE OR ALTER PROCEDURE dbo.sp_ct_solicitud_recalcular_estado
    @id_solicitud INT
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT EXISTS (SELECT 1 FROM dbo.ct_solicitud WHERE id_solicitud = @id_solicitud)
        RETURN;

    DECLARE
        @id_estado_actual INT,
        @tipo_codigo NVARCHAR(50),
        @id_estado_borrador INT,
        @id_estado_revision INT,
        @id_estado_observaciones INT,
        @id_estado_lista INT,
        @id_estado_aprobada INT,
        @id_estado_anulada INT,
        @id_estado_nuevo INT,
        @general_completo BIT = 0,
        @titulares_completos BIT = 0,
        @hay_actividad BIT = 0,
        @areas_observadas INT = 0,
        @areas_requeridas INT = 0,
        @areas_cumplidas INT = 0;

    SELECT
        @id_estado_actual = s.id_estado_solicitud,
        @tipo_codigo = ts.codigo
    FROM dbo.ct_solicitud s
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON ts.id_tipo_solicitud = s.id_tipo_solicitud
    WHERE s.id_solicitud = @id_solicitud;

    SELECT @id_estado_borrador = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''BORRADOR'';
    SELECT @id_estado_revision = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''EN_REVISION'';
    SELECT @id_estado_observaciones = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''CON_OBSERVACIONES'';
    SELECT @id_estado_lista = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''LISTA_PARA_APROBAR'';
    SELECT @id_estado_aprobada = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''APROBADA'';
    SELECT @id_estado_anulada = id_estado_solicitud FROM dbo.ct_estado_solicitud WHERE codigo = ''ANULADA'';

    IF @id_estado_actual IN (@id_estado_aprobada, @id_estado_anulada)
        RETURN;

    ;WITH areas_requeridas AS (
        SELECT
            sai.id_area_instancia,
            eas.codigo AS estado_area_codigo,
            area.codigo AS area_codigo,
            CASE
                WHEN area.codigo = ''LEGAL''
                     AND EXISTS (
                         SELECT 1
                         FROM dbo.ct_solicitud_adquisicion_legal al
                         WHERE al.id_area_instancia = sai.id_area_instancia
                           AND al.id_solicitud = @id_solicitud
                           AND al.estudio_titulos_ok IS NOT NULL
                     )
                    THEN 1
                WHEN area.codigo = ''ARQUITECTURA''
                     AND EXISTS (
                         SELECT 1
                         FROM dbo.ct_solicitud_adquisicion_arquitectura aa
                         WHERE aa.id_area_instancia = sai.id_area_instancia
                           AND aa.id_solicitud = @id_solicitud
                           AND aa.informe_tecnico_ok IS NOT NULL
                     )
                    THEN 1
                WHEN area.codigo NOT IN (''LEGAL'', ''ARQUITECTURA'')
                    THEN 1
                ELSE 0
            END AS tiene_formulario_completo
        FROM dbo.ct_solicitud_area_instancia sai
        INNER JOIN dbo.ct_estado_area_solicitud eas
            ON eas.id_estado_area_solicitud = sai.id_estado_area_solicitud
        INNER JOIN dbo.ct_area_solicitud area
            ON area.id_area_solicitud = sai.id_area_solicitud
        WHERE sai.id_solicitud = @id_solicitud
          AND sai.es_requerida = 1
    )
    SELECT
        @areas_requeridas = COUNT(*),
        @areas_observadas = COALESCE(SUM(CASE WHEN estado_area_codigo = ''CON_OBSERVACIONES'' THEN 1 ELSE 0 END), 0),
        @areas_cumplidas = COALESCE(SUM(
            CASE
                WHEN estado_area_codigo IN (''COMPLETA'', ''CERRADA'')
                     AND tiene_formulario_completo = 1
                    THEN 1
                ELSE 0
            END
        ), 0)
    FROM areas_requeridas;

    IF @tipo_codigo = ''ADQUISICION''
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_adquisicion sa
            WHERE sa.id_solicitud = @id_solicitud
              AND NULLIF(LTRIM(RTRIM(ISNULL(sa.rol_propuesto, ''''))), '''') IS NOT NULL
              AND sa.superficie_m2 IS NOT NULL
              AND sa.id_comuna IS NOT NULL
              AND sa.id_tipo_inmueble IS NOT NULL
              AND sa.fecha_adquisicion IS NOT NULL
        )
        BEGIN
            SET @general_completo = 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_adquisicion_titular sat
            WHERE sat.id_solicitud = @id_solicitud
            GROUP BY sat.id_solicitud
            HAVING ABS(SUM(sat.porcentaje_derecho) - 100.00) < 0.01
        )
        BEGIN
            SET @titulares_completos = 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_adquisicion sa
            WHERE sa.id_solicitud = @id_solicitud
        )
           OR EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_adquisicion_titular sat
            WHERE sat.id_solicitud = @id_solicitud
        )
           OR EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_area_instancia sai
            WHERE sai.id_solicitud = @id_solicitud
        )
        BEGIN
            SET @hay_actividad = 1;
        END;
    END
    ELSE
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM dbo.ct_solicitud_area_instancia sai
            WHERE sai.id_solicitud = @id_solicitud
        )
        BEGIN
            SET @hay_actividad = 1;
        END;
    END;

    SET @id_estado_nuevo = @id_estado_borrador;

    IF @areas_observadas > 0
    BEGIN
        SET @id_estado_nuevo = @id_estado_observaciones;
    END
    ELSE IF @tipo_codigo = ''ADQUISICION''
         AND @general_completo = 1
         AND @titulares_completos = 1
         AND @areas_requeridas > 0
         AND @areas_requeridas = @areas_cumplidas
    BEGIN
        SET @id_estado_nuevo = @id_estado_lista;
    END
    ELSE IF @hay_actividad = 1
    BEGIN
        SET @id_estado_nuevo = @id_estado_revision;
    END;

    IF @id_estado_nuevo IS NOT NULL
       AND @id_estado_actual <> @id_estado_nuevo
    BEGIN
        UPDATE dbo.ct_solicitud
        SET id_estado_solicitud = @id_estado_nuevo,
            fecha_actualizacion = SYSUTCDATETIME(),
            fecha_cierre = CASE
                WHEN @id_estado_nuevo IN (@id_estado_aprobada, @id_estado_anulada)
                    THEN COALESCE(fecha_cierre, SYSUTCDATETIME())
                ELSE NULL
            END
        WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_historial_estado
ON dbo.ct_solicitud
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.ct_solicitud_historial_estado (
        id_solicitud,
        id_area_instancia,
        tipo_entidad,
        id_estado_solicitud_anterior,
        id_estado_solicitud_nuevo,
        fecha_cambio
    )
    SELECT
        i.id_solicitud,
        NULL,
        ''SOLICITUD'',
        d.id_estado_solicitud,
        i.id_estado_solicitud,
        SYSUTCDATETIME()
    FROM inserted i
    LEFT JOIN deleted d
        ON d.id_solicitud = i.id_solicitud
    WHERE d.id_solicitud IS NULL
       OR ISNULL(d.id_estado_solicitud, -1) <> ISNULL(i.id_estado_solicitud, -1);
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_area_instancia_historial_estado
ON dbo.ct_solicitud_area_instancia
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    INSERT INTO dbo.ct_solicitud_historial_estado (
        id_solicitud,
        id_area_instancia,
        tipo_entidad,
        id_estado_area_anterior,
        id_estado_area_nuevo,
        fecha_cambio
    )
    SELECT
        i.id_solicitud,
        i.id_area_instancia,
        ''AREA'',
        d.id_estado_area_solicitud,
        i.id_estado_area_solicitud,
        SYSUTCDATETIME()
    FROM inserted i
    LEFT JOIN deleted d
        ON d.id_area_instancia = i.id_area_instancia
    WHERE d.id_area_instancia IS NULL
       OR ISNULL(d.id_estado_area_solicitud, -1) <> ISNULL(i.id_estado_area_solicitud, -1);

    DECLARE @ids TABLE (id_solicitud INT PRIMARY KEY);

    INSERT INTO @ids (id_solicitud)
    SELECT DISTINCT id_solicitud
    FROM inserted;

    DECLARE @id_solicitud INT;

    WHILE EXISTS (SELECT 1 FROM @ids)
    BEGIN
        SELECT TOP (1) @id_solicitud = id_solicitud FROM @ids ORDER BY id_solicitud;
        EXEC dbo.sp_ct_solicitud_recalcular_estado @id_solicitud = @id_solicitud;
        DELETE FROM @ids WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_adquisicion_recalcular_estado
ON dbo.ct_solicitud_adquisicion
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ids TABLE (id_solicitud INT PRIMARY KEY);

    INSERT INTO @ids (id_solicitud)
    SELECT DISTINCT id_solicitud FROM inserted
    UNION
    SELECT DISTINCT id_solicitud FROM deleted;

    DECLARE @id_solicitud INT;

    WHILE EXISTS (SELECT 1 FROM @ids)
    BEGIN
        SELECT TOP (1) @id_solicitud = id_solicitud FROM @ids ORDER BY id_solicitud;
        EXEC dbo.sp_ct_solicitud_recalcular_estado @id_solicitud = @id_solicitud;
        DELETE FROM @ids WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_adquisicion_titular_recalcular_estado
ON dbo.ct_solicitud_adquisicion_titular
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ids TABLE (id_solicitud INT PRIMARY KEY);

    INSERT INTO @ids (id_solicitud)
    SELECT DISTINCT id_solicitud FROM inserted
    UNION
    SELECT DISTINCT id_solicitud FROM deleted;

    DECLARE @id_solicitud INT;

    WHILE EXISTS (SELECT 1 FROM @ids)
    BEGIN
        SELECT TOP (1) @id_solicitud = id_solicitud FROM @ids ORDER BY id_solicitud;
        EXEC dbo.sp_ct_solicitud_recalcular_estado @id_solicitud = @id_solicitud;
        DELETE FROM @ids WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_adquisicion_legal_recalcular_estado
ON dbo.ct_solicitud_adquisicion_legal
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ids TABLE (id_solicitud INT PRIMARY KEY);

    INSERT INTO @ids (id_solicitud)
    SELECT DISTINCT id_solicitud FROM inserted
    UNION
    SELECT DISTINCT id_solicitud FROM deleted;

    DECLARE @id_solicitud INT;

    WHILE EXISTS (SELECT 1 FROM @ids)
    BEGIN
        SELECT TOP (1) @id_solicitud = id_solicitud FROM @ids ORDER BY id_solicitud;
        EXEC dbo.sp_ct_solicitud_recalcular_estado @id_solicitud = @id_solicitud;
        DELETE FROM @ids WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

EXEC (N'
CREATE OR ALTER TRIGGER dbo.TR_ct_solicitud_adquisicion_arquitectura_recalcular_estado
ON dbo.ct_solicitud_adquisicion_arquitectura
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @ids TABLE (id_solicitud INT PRIMARY KEY);

    INSERT INTO @ids (id_solicitud)
    SELECT DISTINCT id_solicitud FROM inserted
    UNION
    SELECT DISTINCT id_solicitud FROM deleted;

    DECLARE @id_solicitud INT;

    WHILE EXISTS (SELECT 1 FROM @ids)
    BEGIN
        SELECT TOP (1) @id_solicitud = id_solicitud FROM @ids ORDER BY id_solicitud;
        EXEC dbo.sp_ct_solicitud_recalcular_estado @id_solicitud = @id_solicitud;
        DELETE FROM @ids WHERE id_solicitud = @id_solicitud;
    END;
END;
');
GO

PRINT 'Procedimientos de Solicitudes CT aplicados correctamente.';
GO
