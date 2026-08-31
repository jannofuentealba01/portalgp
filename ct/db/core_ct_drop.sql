/*
  Flujo CT - DROP
  Elimina completamente objetos dbo.ct_* (tablas, vistas, SPs, funciones, tipos).

  Ejecucion:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_ct_drop.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\ct"

PRINT '== CT: DROP inicio ==';
GO

:r $(ROOT)/db/99_ct_terrenos_drop_all.sql

PRINT '== CT: DROP fin ==';
GO
