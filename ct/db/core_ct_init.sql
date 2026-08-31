/*
  Flujo CT - INIT
  Crea/valida estructura base y deja el modulo CT listo en estado actual.
  No elimina datos existentes.

  Ejecucion:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_ct_init.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\ct"

PRINT '== CT: INIT inicio ==';
GO

/* 1) Capas base */
:r $(ROOT)/db/10_ct_capa_predial.sql
:r $(ROOT)/db/15_ct_capa_solicitudes.sql
:r $(ROOT)/db/20_ct_capa_construccion.sql
:r $(ROOT)/db/30_ct_capa_tributaria.sql
:r $(ROOT)/db/40_ct_capa_contabilidad.sql

/* 2) Integridad y unicidad */
:r $(ROOT)/db/17_ct_solicitudes_migracion_cr_departamentos.sql
:r $(ROOT)/db/migrate/2026_05_13_tipo_area_reglas_por_negocio.sql
:r $(ROOT)/db/50_ct_integridad.sql

/* 3) Triggers/procedimientos */
:r $(ROOT)/db/11_ct_procedimientos.sql
:r $(ROOT)/db/16_ct_procedimientos_solicitudes.sql
:r $(ROOT)/db/21_ct_procedimientos.sql
:r $(ROOT)/db/31_ct_procedimientos.sql
:r $(ROOT)/db/41_ct_procedimientos.sql

PRINT '== CT: INIT fin ==';
GO
