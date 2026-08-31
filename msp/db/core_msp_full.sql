/*
  Flujo MSP - FULL
  Reinicia MSP en la base actual (destructivo para objetos MSP).

  Ejecución:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_msp_full.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\msp"

PRINT '== MSP: FULL inicio ==';
GO

:r $(ROOT)\db\core_msp_drop.sql
:r $(ROOT)\db\core_msp_init.sql

PRINT '== MSP: FULL fin ==';
GO
