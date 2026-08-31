/*
  Flujo MSP - INIT
  Instala esquema MSP sobre una base limpia.

  Ejecución:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_msp_init.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\msp"

PRINT '== MSP: INIT inicio ==';
GO

:r $(ROOT)\db\msp_instalar_core.sql

PRINT '== MSP: INIT fin ==';
GO
