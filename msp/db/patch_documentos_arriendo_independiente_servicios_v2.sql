/*
    PortalGP / MSP
    El arriendo no debe quedar bloqueado por servicios sin lectura o cobro.

    La lista de tiendas recibida por el procedimiento se construye desde el
    pool de servicios. Antes esa lista también restringía el snapshot de
    arriendo: un servicio pendiente terminaba dejando fuera incluso el
    arriendo ya calculable.

    Desde este parche el snapshot se genera para todos los contratos activos.
    Los servicios se agregan únicamente cuando existe un cobro calculado.
    El pool conserva ready_* y motivo_pendiente para mantener la trazabilidad.
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;

DECLARE @definicion NVARCHAR(MAX);
DECLARE @inicio_procedimiento INT;

SELECT @definicion = sm.definition
FROM sys.sql_modules sm
WHERE sm.object_id = OBJECT_ID(N'dbo.msp_generar_snapshot_arriendo_periodo', N'P');

IF @definicion IS NULL
    THROW 50901, 'No existe dbo.msp_generar_snapshot_arriendo_periodo.', 1;

IF @definicion LIKE N'%IF 1 = 0 AND EXISTS (SELECT 1 FROM @target_tiendas)%'
BEGIN
    PRINT N'OK: el arriendo ya era independiente de los servicios.';
    RETURN;
END;

SET @definicion = REPLACE(
    @definicion,
    N'IF EXISTS (SELECT 1 FROM @target_tiendas)',
    N'IF 1 = 0 AND EXISTS (SELECT 1 FROM @target_tiendas)'
);

IF @definicion NOT LIKE N'%IF 1 = 0 AND EXISTS (SELECT 1 FROM @target_tiendas)%'
    THROW 50902, 'No se encontro el bloque de seleccion de tiendas esperado.', 1;

SET @inicio_procedimiento = CHARINDEX(
    N'PROCEDURE dbo.msp_generar_snapshot_arriendo_periodo',
    @definicion
);

IF @inicio_procedimiento = 0
    THROW 50903, 'No fue posible localizar la cabecera del procedimiento.', 1;

SET @definicion =
    N'CREATE OR ALTER ' +
    SUBSTRING(
        @definicion,
        @inicio_procedimiento,
        LEN(@definicion) - @inicio_procedimiento + 1
    );

EXEC sys.sp_executesql @definicion;

IF OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_generar_snapshot_arriendo_periodo'))
       NOT LIKE N'%IF 1 = 0 AND EXISTS (SELECT 1 FROM @target_tiendas)%'
    THROW 50904, 'La verificacion posterior del procedimiento fallo.', 1;

PRINT N'OK: el arriendo ya no queda bloqueado por servicios sin cobro.';
