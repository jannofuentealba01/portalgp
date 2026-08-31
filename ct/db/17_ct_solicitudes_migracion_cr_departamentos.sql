SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID('dbo.cr_departamentos', 'U') IS NULL
        THROW 51000, 'No existe dbo.cr_departamentos. Ejecuta primero la capa de catalogos CR.', 1;

    IF OBJECT_ID('dbo.ct_solicitud_tipo_area', 'U') IS NULL
        THROW 51000, 'No existe dbo.ct_solicitud_tipo_area.', 1;

    IF OBJECT_ID('dbo.ct_solicitud_area_instancia', 'U') IS NULL
        THROW 51000, 'No existe dbo.ct_solicitud_area_instancia.', 1;

    DECLARE @alreadyMigrated BIT = 0;
    IF EXISTS (
        SELECT 1
        FROM sys.foreign_keys fk
        WHERE fk.parent_object_id = OBJECT_ID('dbo.ct_solicitud_tipo_area')
          AND fk.referenced_object_id = OBJECT_ID('dbo.cr_departamentos')
    )
    AND EXISTS (
        SELECT 1
        FROM sys.foreign_keys fk
        WHERE fk.parent_object_id = OBJECT_ID('dbo.ct_solicitud_area_instancia')
          AND fk.referenced_object_id = OBJECT_ID('dbo.cr_departamentos')
    )
    BEGIN
        SET @alreadyMigrated = 1;
    END;

    IF @alreadyMigrated = 0 AND OBJECT_ID('dbo.ct_area_solicitud', 'U') IS NOT NULL
    BEGIN
        IF OBJECT_ID('tempdb..#ct_area_map') IS NOT NULL DROP TABLE #ct_area_map;
        CREATE TABLE #ct_area_map (
            id_area_solicitud_old INT NOT NULL PRIMARY KEY,
            id_departamento_new INT NOT NULL
        );

        ;WITH used_area_ids AS (
            SELECT DISTINCT id_area_solicitud FROM dbo.ct_solicitud_tipo_area
            UNION
            SELECT DISTINCT id_area_solicitud FROM dbo.ct_solicitud_area_instancia
        )
        INSERT INTO #ct_area_map (id_area_solicitud_old, id_departamento_new)
        SELECT
            u.id_area_solicitud,
            mapped.id_departamento
        FROM used_area_ids u
        INNER JOIN dbo.ct_area_solicitud a
            ON a.id_area_solicitud = u.id_area_solicitud
        OUTER APPLY (
            SELECT TOP (1) d.id_departamento
            FROM dbo.cr_departamentos d
            WHERE
                (
                    NULLIF(LTRIM(RTRIM(d.codigo)), '') IS NOT NULL
                    AND UPPER(LTRIM(RTRIM(d.codigo))) = UPPER(LTRIM(RTRIM(a.codigo)))
                )
                OR UPPER(LTRIM(RTRIM(d.nombre))) = UPPER(LTRIM(RTRIM(a.nombre)))
            ORDER BY CASE
                WHEN UPPER(LTRIM(RTRIM(d.codigo))) = UPPER(LTRIM(RTRIM(a.codigo))) THEN 0
                ELSE 1
            END, d.id_departamento
        ) mapped
        WHERE mapped.id_departamento IS NOT NULL;

        DECLARE @unmappedCount INT = 0;
        SELECT @unmappedCount = COUNT(*)
        FROM (
            SELECT DISTINCT id_area_solicitud FROM dbo.ct_solicitud_tipo_area
            UNION
            SELECT DISTINCT id_area_solicitud FROM dbo.ct_solicitud_area_instancia
        ) u
        LEFT JOIN #ct_area_map m
            ON m.id_area_solicitud_old = u.id_area_solicitud
        WHERE m.id_area_solicitud_old IS NULL;

        /*
          Antes de actualizar/reconstruir con IDs de cr_departamentos,
          quitamos FKs legacy que aun apuntan a ct_area_solicitud.
        */
        DECLARE @dropSqlPre NVARCHAR(MAX) = N'';
        SELECT @dropSqlPre = @dropSqlPre +
            N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(fk.parent_object_id)) + N'.' + QUOTENAME(OBJECT_NAME(fk.parent_object_id)) +
            N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
        FROM sys.foreign_keys fk
        WHERE fk.referenced_object_id = OBJECT_ID('dbo.ct_area_solicitud')
          AND fk.parent_object_id IN (
              OBJECT_ID('dbo.ct_solicitud_tipo_area'),
              OBJECT_ID('dbo.ct_solicitud_area_instancia')
          );

        IF @dropSqlPre <> N''
            EXEC sp_executesql @dropSqlPre;

        IF @unmappedCount > 0
        BEGIN
            DECLARE @hasHistorial BIT = 0;
            IF EXISTS (SELECT 1 FROM dbo.ct_solicitud_area_instancia)
                OR EXISTS (SELECT 1 FROM dbo.ct_solicitud)
                OR EXISTS (SELECT 1 FROM dbo.ct_participante_solicitud)
            BEGIN
                SET @hasHistorial = 1;
            END;

            IF @hasHistorial = 1
            BEGIN
                THROW 51000, 'No se pudo mapear todas las areas CT hacia CR departamentos (revisa codigo/nombre).', 1;
            END;

            IF OBJECT_ID('dbo.ct_solicitud_tipo_area_participante_default', 'U') IS NOT NULL
            BEGIN
                DELETE pd
                FROM dbo.ct_solicitud_tipo_area_participante_default pd
                INNER JOIN dbo.ct_solicitud_tipo_area sta
                    ON sta.id_solicitud_tipo_area = pd.id_solicitud_tipo_area;
            END;

            DELETE FROM dbo.ct_solicitud_tipo_area;

            DECLARE @idTipoAdquisicion INT = NULL;
            DECLARE @idPlantillaGeneral INT = NULL;
            DECLARE @idPlantillaLegal INT = NULL;
            DECLARE @idPlantillaArquitectura INT = NULL;

            SELECT @idTipoAdquisicion = id_tipo_solicitud
            FROM dbo.ct_tipo_solicitud
            WHERE codigo = 'ADQUISICION';

            SELECT @idPlantillaGeneral = id_formulario_plantilla
            FROM dbo.ct_formulario_plantilla
            WHERE codigo = 'SOLICITUD_ADQUISICION_GENERAL';

            SELECT @idPlantillaLegal = id_formulario_plantilla
            FROM dbo.ct_formulario_plantilla
            WHERE codigo = 'SOLICITUD_ADQUISICION_LEGAL';

            SELECT @idPlantillaArquitectura = id_formulario_plantilla
            FROM dbo.ct_formulario_plantilla
            WHERE codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA';

            IF @idTipoAdquisicion IS NULL OR @idPlantillaGeneral IS NULL
                THROW 51000, 'No existe configuracion base ADQUISICION/plantilla general para reconstruir ct_solicitud_tipo_area.', 1;

            INSERT INTO dbo.ct_solicitud_tipo_area (
                id_tipo_solicitud,
                id_area_solicitud,
                id_formulario_plantilla,
                orden_flujo,
                es_requerida,
                habilita_automaticamente,
                requiere_formulario_tipado,
                activo
            )
            SELECT
                @idTipoAdquisicion,
                d.id_departamento,
                CASE
                    WHEN UPPER(LTRIM(RTRIM(d.codigo))) = 'LEGAL' AND @idPlantillaLegal IS NOT NULL THEN @idPlantillaLegal
                    WHEN UPPER(LTRIM(RTRIM(d.codigo))) = 'ARQUITECTURA' AND @idPlantillaArquitectura IS NOT NULL THEN @idPlantillaArquitectura
                    ELSE @idPlantillaGeneral
                END,
                ROW_NUMBER() OVER (ORDER BY d.orden_visual, d.nombre) * 10,
                1,
                1,
                CASE
                    WHEN UPPER(LTRIM(RTRIM(d.codigo))) IN ('LEGAL', 'ARQUITECTURA') THEN 1
                    ELSE 0
                END,
                1
            FROM dbo.cr_departamentos d
            WHERE d.activo = 1;
        END
        ELSE
        BEGIN
            UPDATE sta
            SET sta.id_area_solicitud = m.id_departamento_new
            FROM dbo.ct_solicitud_tipo_area sta
            INNER JOIN #ct_area_map m
                ON m.id_area_solicitud_old = sta.id_area_solicitud;

            UPDATE sai
            SET sai.id_area_solicitud = m.id_departamento_new
            FROM dbo.ct_solicitud_area_instancia sai
            INNER JOIN #ct_area_map m
                ON m.id_area_solicitud_old = sai.id_area_solicitud;

            IF EXISTS (
                SELECT 1
                FROM dbo.ct_solicitud_tipo_area
                GROUP BY id_tipo_solicitud, id_area_solicitud
                HAVING COUNT(*) > 1
            )
                THROW 51000, 'Se generaron duplicados en ct_solicitud_tipo_area tras la migracion.', 1;

            IF EXISTS (
                SELECT 1
                FROM dbo.ct_solicitud_area_instancia
                GROUP BY id_solicitud, id_area_solicitud
                HAVING COUNT(*) > 1
            )
                THROW 51000, 'Se generaron duplicados en ct_solicitud_area_instancia tras la migracion.', 1;
        END;
    END;

    DECLARE @dropSql NVARCHAR(MAX) = N'';
    SELECT @dropSql = @dropSql +
        N'ALTER TABLE ' + QUOTENAME(OBJECT_SCHEMA_NAME(fk.parent_object_id)) + N'.' + QUOTENAME(OBJECT_NAME(fk.parent_object_id)) +
        N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
    FROM sys.foreign_keys fk
    WHERE fk.referenced_object_id = OBJECT_ID('dbo.ct_area_solicitud')
      AND fk.parent_object_id IN (
          OBJECT_ID('dbo.ct_solicitud_tipo_area'),
          OBJECT_ID('dbo.ct_solicitud_area_instancia')
      );

    IF @dropSql <> N''
        EXEC sp_executesql @dropSql;

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_tipo_area_departamento')
    BEGIN
        ALTER TABLE dbo.ct_solicitud_tipo_area WITH CHECK
        ADD CONSTRAINT FK_ct_solicitud_tipo_area_departamento
            FOREIGN KEY (id_area_solicitud) REFERENCES dbo.cr_departamentos(id_departamento);
    END;

    IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_area_instancia_departamento')
    BEGIN
        ALTER TABLE dbo.ct_solicitud_area_instancia WITH CHECK
        ADD CONSTRAINT FK_ct_solicitud_area_instancia_departamento
            FOREIGN KEY (id_area_solicitud) REFERENCES dbo.cr_departamentos(id_departamento);
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    DECLARE @ErrorMessage NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @ErrorSeverity INT = ERROR_SEVERITY();
    DECLARE @ErrorState INT = ERROR_STATE();
    RAISERROR(@ErrorMessage, @ErrorSeverity, @ErrorState);
END CATCH;
GO
