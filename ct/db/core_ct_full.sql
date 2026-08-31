/*
  Flujo CT - FULL
  Ejecuta el ciclo completo:
  1) DROP total de objetos ct_*
  2) INIT del modulo CT en estado actual

  Ejecucion:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_ct_full.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\ct"

PRINT '== CT: FULL inicio ==';
GO

:r $(ROOT)/db/core_ct_drop.sql
:r $(ROOT)/db/core_ct_init.sql

PRINT '== CT: FULL fin ==';
GO
