/*
  Script: 00_ct_terrenos_limpiar_bd.sql
  Objetivo:
  - Limpiar datos del modulo CT (tablas dbo.ct_*)
  - Mantener estructura (no elimina tablas)
  - Reiniciar identidades en tablas con IDENTITY

  Nota:
  - Borra en orden de dependencia (hijas -> padres) en base a FKs ct_*.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRY
    BEGIN TRAN;

    DECLARE @targets TABLE (
        object_id   INT PRIMARY KEY,
        full_name   NVARCHAR(260) NOT NULL,
        lvl         INT NOT NULL DEFAULT (0)
    );

    INSERT INTO @targets (object_id, full_name)
    SELECT
        t.object_id,
        QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name)
    FROM sys.tables t
    WHERE SCHEMA_NAME(t.schema_id) = N'dbo'
      AND t.name LIKE N'ct[_]%';

    ;WITH fk_edges AS (
        SELECT
            fk.referenced_object_id AS parent_id,
            fk.parent_object_id     AS child_id
        FROM sys.foreign_keys fk
        INNER JOIN @targets p ON p.object_id = fk.referenced_object_id
        INNER JOIN @targets c ON c.object_id = fk.parent_object_id
    ),
    dep AS (
        SELECT t.object_id, 0 AS lvl
        FROM @targets t
        UNION ALL
        SELECT e.child_id, d.lvl + 1
        FROM dep d
        INNER JOIN fk_edges e
            ON e.parent_id = d.object_id
    ),
    max_dep AS (
        SELECT object_id, MAX(lvl) AS max_lvl
        FROM dep
        GROUP BY object_id
    )
    UPDATE t
    SET lvl = ISNULL(m.max_lvl, 0)
    FROM @targets t
    LEFT JOIN max_dep m
        ON m.object_id = t.object_id;

    DECLARE @full_name NVARCHAR(260);
    DECLARE @sql NVARCHAR(MAX);

    DECLARE cur_delete CURSOR LOCAL FAST_FORWARD FOR
        SELECT full_name
        FROM @targets
        ORDER BY lvl DESC, full_name ASC;

    OPEN cur_delete;
    FETCH NEXT FROM cur_delete INTO @full_name;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @sql = N'DELETE FROM ' + @full_name + N';';
        EXEC sp_executesql @sql;
        FETCH NEXT FROM cur_delete INTO @full_name;
    END;

    CLOSE cur_delete;
    DEALLOCATE cur_delete;

    DECLARE cur_reseed CURSOR LOCAL FAST_FORWARD FOR
        SELECT DISTINCT t.full_name
        FROM @targets t
        INNER JOIN sys.identity_columns ic
            ON ic.object_id = t.object_id
        ORDER BY t.full_name;

    OPEN cur_reseed;
    FETCH NEXT FROM cur_reseed INTO @full_name;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @sql = N'DBCC CHECKIDENT (' + N'''' + @full_name + N'''' + N', RESEED, 0) WITH NO_INFOMSGS;';
        EXEC sp_executesql @sql;
        FETCH NEXT FROM cur_reseed INTO @full_name;
    END;

    CLOSE cur_reseed;
    DEALLOCATE cur_reseed;

    COMMIT TRAN;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRAN;
    THROW;
END CATCH;
GO
