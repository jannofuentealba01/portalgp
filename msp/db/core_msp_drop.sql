/*
  Flujo MSP - DROP
  Limpia objetos MSP en la base actual.

  Ejecución:
  sqlcmd -S <SERVER> -d <DATABASE> -E -i core_msp_drop.sql
*/

:on error exit
:setvar ROOT "C:\wamp64\www\portalgp\msp"

PRINT '== MSP: DROP inicio ==';
GO

:r $(ROOT)\db\msp_limpiar.sql

PRINT '== MSP: DROP fin ==';
GO
