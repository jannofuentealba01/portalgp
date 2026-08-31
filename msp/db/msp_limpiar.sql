/*
===========================================================================
 MSP LIMPIEZA
 - Limpia SOLO objetos MSP previos en dbo/msp
   (tablas msp_*, fks, indices, procs, views, funciones, sinonimos, triggers TR_msp_*)
 - No crea esquema msp
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   LIMPIEZA MSP (OBJETOS PREVIOS)
   ========================================================================= */

DECLARE @sql NVARCHAR(MAX) = N'';

-- 1) Drop FKs ligadas a tablas msp_% en dbo o msp.
SELECT @sql += N'ALTER TABLE ' + QUOTENAME(SCHEMA_NAME(pt.schema_id)) + N'.' + QUOTENAME(pt.name)
            + N' DROP CONSTRAINT ' + QUOTENAME(fk.name) + N';' + CHAR(10)
FROM sys.foreign_keys fk
INNER JOIN sys.tables pt ON pt.object_id = fk.parent_object_id
INNER JOIN sys.tables rt ON rt.object_id = fk.referenced_object_id
WHERE (
        SCHEMA_NAME(pt.schema_id) IN (N'dbo', N'msp')
        AND pt.name LIKE N'msp[_]%'
      )
   OR (
        SCHEMA_NAME(rt.schema_id) IN (N'dbo', N'msp')
        AND rt.name LIKE N'msp[_]%'
      );

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 2) Drop triggers de usuario TR_msp_* en dbo o msp.
SELECT @sql += N'DROP TRIGGER ' + QUOTENAME(OBJECT_SCHEMA_NAME(tr.object_id)) + N'.' + QUOTENAME(tr.name) + N';' + CHAR(10)
FROM sys.triggers tr
WHERE tr.parent_class = 1
  AND OBJECT_SCHEMA_NAME(tr.object_id) IN (N'dbo', N'msp')
  AND tr.name LIKE N'TR[_]msp[_]%';

SELECT @sql += N'DROP TRIGGER ' + QUOTENAME(tr.name) + N' ON DATABASE;' + CHAR(10)
FROM sys.triggers tr
WHERE tr.parent_class = 0
  AND tr.name LIKE N'TR[_]msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 3) Drop procedimientos msp_* en dbo o msp.
SELECT @sql += N'DROP PROCEDURE ' + QUOTENAME(SCHEMA_NAME(p.schema_id)) + N'.' + QUOTENAME(p.name) + N';' + CHAR(10)
FROM sys.procedures p
WHERE SCHEMA_NAME(p.schema_id) IN (N'dbo', N'msp')
  AND p.name LIKE N'msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 4) Drop funciones msp_* en dbo o msp.
SELECT @sql += N'DROP FUNCTION ' + QUOTENAME(SCHEMA_NAME(o.schema_id)) + N'.' + QUOTENAME(o.name) + N';' + CHAR(10)
FROM sys.objects o
WHERE o.type IN (N'FN', N'IF', N'TF', N'FS', N'FT')
  AND SCHEMA_NAME(o.schema_id) IN (N'dbo', N'msp')
  AND o.name LIKE N'msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 5) Drop vistas msp_* en dbo o msp.
SELECT @sql += N'DROP VIEW ' + QUOTENAME(SCHEMA_NAME(v.schema_id)) + N'.' + QUOTENAME(v.name) + N';' + CHAR(10)
FROM sys.views v
WHERE SCHEMA_NAME(v.schema_id) IN (N'dbo', N'msp')
  AND v.name LIKE N'msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 6) Drop sinonimos msp_* en dbo o msp.
SELECT @sql += N'DROP SYNONYM ' + QUOTENAME(SCHEMA_NAME(sn.schema_id)) + N'.' + QUOTENAME(sn.name) + N';' + CHAR(10)
FROM sys.synonyms sn
WHERE SCHEMA_NAME(sn.schema_id) IN (N'dbo', N'msp')
  AND sn.name LIKE N'msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

DECLARE @sql NVARCHAR(MAX) = N'';

-- 7) Drop tablas msp_* en dbo o msp.
SELECT @sql += N'DROP TABLE ' + QUOTENAME(SCHEMA_NAME(t.schema_id)) + N'.' + QUOTENAME(t.name) + N';' + CHAR(10)
FROM sys.tables t
WHERE SCHEMA_NAME(t.schema_id) IN (N'dbo', N'msp')
  AND t.name LIKE N'msp[_]%';

IF @sql <> N'' EXEC sp_executesql @sql;
GO

PRINT 'Limpieza MSP completada.';
GO
