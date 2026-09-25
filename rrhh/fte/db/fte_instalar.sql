:setvar FTE_DB_DIR "."

SET NOCOUNT ON;
SET XACT_ABORT ON;

PRINT N'==== FTE: estructura de dotación mensual ====';
:r $(FTE_DB_DIR)\patch_fte_dotacion_snapshot.sql

PRINT N'==== FTE: fuentes mensuales congeladas ====';
:r $(FTE_DB_DIR)\patch_fte_fuentes_mensuales_snapshot.sql

PRINT N'==== FTE: instalación completada ====';
GO
