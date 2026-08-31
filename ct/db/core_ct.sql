/*
  Core legacy CT (compatible con comando actual).
  Este alias ejecuta INIT (no destructivo).

  Ejecucion:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_ct.sql

  Flujos dedicados:
  - core_ct_drop.sql    (destructivo)
  - core_ct_init.sql    (no destructivo)
  - core_ct_migrate.sql (no destructivo)
  - core_ct_full.sql    (destructivo)
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\ct"

PRINT '== CT: inicio core (alias INIT) ==';
GO

:r $(ROOT)/db/core_ct_init.sql

PRINT '== CT: fin core ==';
GO
