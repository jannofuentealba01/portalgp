/*
  Flujo CT - MIGRATE
  Aplica migraciones e integridad sobre una base CT ya existente.
  No elimina datos.

  Ejecucion:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_ct_migrate.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\ct"

PRINT '== CT: MIGRATE inicio ==';
GO

IF OBJECT_ID('dbo.ct_tercero', 'U') IS NULL
BEGIN
    THROW 50030, 'No existe esquema base CT. Ejecuta primero core_ct_init.sql o core_ct_full.sql.', 1;
END;
GO

/* Capa solicitudes base */
:r $(ROOT)/db/15_ct_capa_solicitudes.sql

/* Migraciones incrementales */
:r $(ROOT)/db/migrate/00_ct_migrate_index.sql
:r $(ROOT)/db/17_ct_solicitudes_migracion_cr_departamentos.sql

/* Migraciones de integridad */
:r $(ROOT)/db/50_ct_integridad.sql

/* Refresco de objetos programables */
:r $(ROOT)/db/11_ct_procedimientos.sql
:r $(ROOT)/db/16_ct_procedimientos_solicitudes.sql
:r $(ROOT)/db/21_ct_procedimientos.sql
:r $(ROOT)/db/31_ct_procedimientos.sql
:r $(ROOT)/db/41_ct_procedimientos.sql

PRINT '== CT: MIGRATE fin ==';
GO
