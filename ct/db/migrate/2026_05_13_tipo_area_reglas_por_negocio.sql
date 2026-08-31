/*
  CT MIGRATE: reglas de areas por tipo de solicitud.

  Reglas aplicadas:
  - ADQUISICION -> LEGAL + ARQUITECTURA
  - FUSION -> LEGAL + ARQUITECTURA
  - SUBDIVISION -> LEGAL + ARQUITECTURA
  - VENTA -> COMERCIAL

  Notas:
  - Idempotente.
  - Mantiene el nombre de columna legacy id_area_solicitud, pero referenciando cr_departamentos.
*/

IF OBJECT_ID('dbo.ct_solicitud_tipo_area', 'U') IS NULL
    OR OBJECT_ID('dbo.ct_tipo_solicitud', 'U') IS NULL
    OR OBJECT_ID('dbo.ct_formulario_plantilla', 'U') IS NULL
    OR OBJECT_ID('dbo.cr_departamentos', 'U') IS NULL
BEGIN
    PRINT 'Tablas base no disponibles para reglas tipo-area; se omite migracion.';
    RETURN;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.cr_departamentos
    WHERE UPPER(LTRIM(RTRIM(codigo))) = 'COMERCIAL'
)
BEGIN
    INSERT INTO dbo.cr_departamentos (codigo, nombre, descripcion, orden_visual, activo)
    VALUES ('COMERCIAL', 'Comercial', 'Gestion comercial y coordinacion de ventas.', 45, 1);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_tipo_solicitud
    WHERE UPPER(LTRIM(RTRIM(codigo))) = 'VENTA'
)
BEGIN
    INSERT INTO dbo.ct_tipo_solicitud (codigo, nombre, descripcion, activo)
    VALUES ('VENTA', 'Venta', 'Operacion de venta y traspaso.', 1);
END;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    UPDATE dbo.cr_departamentos
    SET activo = 1
    WHERE UPPER(LTRIM(RTRIM(codigo))) IN ('LEGAL', 'ARQUITECTURA', 'COMERCIAL');

    UPDATE dbo.ct_tipo_solicitud
    SET activo = 1
    WHERE UPPER(LTRIM(RTRIM(codigo))) IN ('ADQUISICION', 'FUSION', 'SUBDIVISION', 'VENTA');

    DECLARE @idPlantillaGeneral INT = NULL;
    DECLARE @idPlantillaLegal INT = NULL;
    DECLARE @idPlantillaArquitectura INT = NULL;

    SELECT @idPlantillaGeneral = id_formulario_plantilla
    FROM dbo.ct_formulario_plantilla
    WHERE codigo = 'SOLICITUD_ADQUISICION_GENERAL';

    SELECT @idPlantillaLegal = id_formulario_plantilla
    FROM dbo.ct_formulario_plantilla
    WHERE codigo = 'SOLICITUD_ADQUISICION_LEGAL';

    SELECT @idPlantillaArquitectura = id_formulario_plantilla
    FROM dbo.ct_formulario_plantilla
    WHERE codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA';

    IF @idPlantillaGeneral IS NULL
        THROW 51000, 'No existe SOLICITUD_ADQUISICION_GENERAL para configurar ct_solicitud_tipo_area.', 1;

    IF OBJECT_ID('tempdb..#ct_reglas_tipo_area') IS NOT NULL DROP TABLE #ct_reglas_tipo_area;
    CREATE TABLE #ct_reglas_tipo_area (
        tipo_codigo NVARCHAR(50) NOT NULL,
        area_codigo NVARCHAR(50) NOT NULL,
        orden_flujo INT NOT NULL,
        requiere_formulario_tipado BIT NOT NULL
    );

    INSERT INTO #ct_reglas_tipo_area (tipo_codigo, area_codigo, orden_flujo, requiere_formulario_tipado)
    VALUES
        ('ADQUISICION', 'LEGAL', 10, 1),
        ('ADQUISICION', 'ARQUITECTURA', 20, 1),
        ('FUSION', 'LEGAL', 10, 1),
        ('FUSION', 'ARQUITECTURA', 20, 1),
        ('SUBDIVISION', 'LEGAL', 10, 1),
        ('SUBDIVISION', 'ARQUITECTURA', 20, 1),
        ('VENTA', 'COMERCIAL', 10, 0);

    IF OBJECT_ID('tempdb..#ct_objetivo_tipo_area') IS NOT NULL DROP TABLE #ct_objetivo_tipo_area;
    CREATE TABLE #ct_objetivo_tipo_area (
        id_tipo_solicitud INT NOT NULL,
        id_area_solicitud INT NOT NULL,
        id_formulario_plantilla INT NOT NULL,
        orden_flujo INT NOT NULL,
        requiere_formulario_tipado BIT NOT NULL
    );

    INSERT INTO #ct_objetivo_tipo_area (
        id_tipo_solicitud,
        id_area_solicitud,
        id_formulario_plantilla,
        orden_flujo,
        requiere_formulario_tipado
    )
    SELECT
        ts.id_tipo_solicitud,
        d.id_departamento,
        CASE
            WHEN r.area_codigo = 'LEGAL' THEN COALESCE(@idPlantillaLegal, @idPlantillaGeneral)
            WHEN r.area_codigo = 'ARQUITECTURA' THEN COALESCE(@idPlantillaArquitectura, @idPlantillaGeneral)
            ELSE @idPlantillaGeneral
        END AS id_formulario_plantilla,
        r.orden_flujo,
        r.requiere_formulario_tipado
    FROM #ct_reglas_tipo_area r
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON UPPER(LTRIM(RTRIM(ts.codigo))) = r.tipo_codigo
       AND ts.activo = 1
    INNER JOIN dbo.cr_departamentos d
        ON UPPER(LTRIM(RTRIM(d.codigo))) = r.area_codigo
       AND d.activo = 1;

    DECLARE @expectedRows INT = 0;
    DECLARE @actualRows INT = 0;

    SELECT @expectedRows = COUNT(*)
    FROM #ct_reglas_tipo_area r
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON UPPER(LTRIM(RTRIM(ts.codigo))) = r.tipo_codigo
       AND ts.activo = 1;

    SELECT @actualRows = COUNT(*) FROM #ct_objetivo_tipo_area;

    IF @expectedRows <> @actualRows
        THROW 51000, 'No se pudo resolver todas las reglas tipo-area (verifica codigos de departamentos/tipos).', 1;

    MERGE dbo.ct_solicitud_tipo_area AS tgt
    USING #ct_objetivo_tipo_area AS src
        ON tgt.id_tipo_solicitud = src.id_tipo_solicitud
       AND tgt.id_area_solicitud = src.id_area_solicitud
    WHEN MATCHED THEN
        UPDATE SET
            id_formulario_plantilla = src.id_formulario_plantilla,
            orden_flujo = src.orden_flujo,
            es_requerida = 1,
            habilita_automaticamente = 1,
            requiere_formulario_tipado = src.requiere_formulario_tipado,
            activo = 1
    WHEN NOT MATCHED BY TARGET THEN
        INSERT (
            id_tipo_solicitud,
            id_area_solicitud,
            id_formulario_plantilla,
            orden_flujo,
            es_requerida,
            habilita_automaticamente,
            requiere_formulario_tipado,
            activo
        )
        VALUES (
            src.id_tipo_solicitud,
            src.id_area_solicitud,
            src.id_formulario_plantilla,
            src.orden_flujo,
            1,
            1,
            src.requiere_formulario_tipado,
            1
        );

    UPDATE sta
    SET sta.activo = 0
    FROM dbo.ct_solicitud_tipo_area sta
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
    WHERE UPPER(LTRIM(RTRIM(ts.codigo))) IN ('ADQUISICION', 'FUSION', 'SUBDIVISION', 'VENTA')
      AND NOT EXISTS (
          SELECT 1
          FROM #ct_objetivo_tipo_area o
          WHERE o.id_tipo_solicitud = sta.id_tipo_solicitud
            AND o.id_area_solicitud = sta.id_area_solicitud
      );

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
