/*
  Script: 99_ct_terrenos_drop_all.sql
  Objetivo:
  - Eliminar estructura completa del modulo CT (objetos dbo.ct_*)
  - Primero elimina FKs donde participe ct_*
  - Luego elimina objetos programables ct_* y finalmente tablas ct_*
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRAN;

    DECLARE @sql NVARCHAR(MAX) = N'';

    /* 1) Eliminar FKs relacionadas a ct_* */
    SELECT @sql = @sql +
        N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(pt.schema_id)) + N'.' + QUOTENAME(pt.name) +
        N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
    FROM sys.foreign_keys fk
    INNER JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
    INNER JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
    WHERE SCHEMA_NAME(pt.schema_id) = N'dbo'
      AND SCHEMA_NAME(rt.schema_id) = N'dbo'
      AND (pt.name LIKE N'ct[_]%' OR rt.name LIKE N'ct[_]%');

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 2) Eliminar vistas ct_* */
    SET @sql = N'';
    SELECT @sql = @sql +
        N'DROP VIEW ' + QUOTENAME(SCHEMA_NAME(v.schema_id)) + N'.' + QUOTENAME(v.name) + N';' + CHAR(10)
    FROM sys.views v
    WHERE SCHEMA_NAME(v.schema_id) = N'dbo'
      AND v.name LIKE N'ct[_]%';

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 3) Eliminar procedimientos ct_* */
    SET @sql = N'';
    SELECT @sql = @sql +
        N'DROP PROCEDURE ' + QUOTENAME(SCHEMA_NAME(p.schema_id)) + N'.' + QUOTENAME(p.name) + N';' + CHAR(10)
    FROM sys.procedures p
    WHERE SCHEMA_NAME(p.schema_id) = N'dbo'
      AND (
          p.name LIKE N'ct[_]%'
          OR p.name LIKE N'sp[_]ct[_]%'
      );

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 4) Eliminar funciones ct_* */
    SET @sql = N'';
    SELECT @sql = @sql +
        N'DROP FUNCTION ' + QUOTENAME(SCHEMA_NAME(o.schema_id)) + N'.' + QUOTENAME(o.name) + N';' + CHAR(10)
    FROM sys.objects o
    WHERE SCHEMA_NAME(o.schema_id) = N'dbo'
      AND (
          o.name LIKE N'ct[_]%'
          OR o.name LIKE N'fn[_]ct[_]%'
      )
      AND o.type IN (N'FN', N'IF', N'TF', N'FS', N'FT');

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 5) Eliminar objetos que dependan de tipos ct_* por parametros */
    SET @sql = N'';
    ;WITH deps AS (
        SELECT DISTINCT
            o.object_id,
            o.type,
            SCHEMA_NAME(o.schema_id) AS schema_name,
            o.name
        FROM sys.parameter_type_usages ptu
        INNER JOIN sys.types t
            ON t.user_type_id = ptu.user_type_id
        INNER JOIN sys.objects o
            ON o.object_id = ptu.object_id
        WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
          AND t.is_user_defined = 1
          AND t.name LIKE N'ct[_]%'
          AND SCHEMA_NAME(o.schema_id) = N'dbo'
          AND o.type IN (N'P', N'PC', N'FN', N'IF', N'TF', N'FS', N'FT')
    )
    SELECT @sql = @sql +
        CASE
            WHEN d.type IN (N'P', N'PC') THEN N'DROP PROCEDURE '
            ELSE N'DROP FUNCTION '
        END +
        QUOTENAME(d.schema_name) + N'.' + QUOTENAME(d.name) + N';' + CHAR(10)
    FROM deps d;

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 6) Eliminar tipos de usuario ct_* (incluye table types) */
    SET @sql = N'';
    SELECT @sql = @sql +
        N'DROP TYPE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) + N';' + CHAR(10)
    FROM sys.types t
    WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
      AND t.is_user_defined = 1
      AND t.name LIKE N'ct[_]%';

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    /* 7) Eliminar tablas ct_* */
    SET @sql = N'';
    SELECT @sql = @sql +
        N'DROP TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) + N';' + CHAR(10)
    FROM sys.tables t
    WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
      AND t.name LIKE N'ct[_]%';

    IF LEN(@sql) > 0
        EXEC sp_executesql @sql;

    COMMIT TRAN;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRAN;
    THROW;
END CATCH;
GO
