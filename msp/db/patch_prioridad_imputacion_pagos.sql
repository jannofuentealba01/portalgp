/*
===========================================================================
 MSP - PRIORIDAD ÚNICA DE IMPUTACIÓN DE PAGOS
 Regla: documento más antiguo primero; dentro del documento:
 Arriendo -> Luz -> Gas -> Agua -> Multa/Daño/Ajuste/Otros.
===========================================================================
*/
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_prioridades_imputacion_pago', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_prioridades_imputacion_pago (
        codigo_item NVARCHAR(30) NOT NULL CONSTRAINT PK_msp_prioridades_imputacion_pago PRIMARY KEY,
        prioridad INT NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_prioridades_imputacion_pago_activo DEFAULT (1),
        CONSTRAINT CK_msp_prioridades_imputacion_pago_prioridad CHECK (prioridad > 0)
    );
END;
GO

MERGE dbo.msp_prioridades_imputacion_pago AS target
USING (VALUES
    (N'ARRIENDO', 10), (N'SERVICIO_LUZ', 20), (N'SERVICIO_GAS', 30),
    (N'SERVICIO_AGUA', 40), (N'MULTA', 50), (N'DANO', 60), (N'AJUSTE', 70)
) AS source(codigo_item, prioridad)
ON target.codigo_item=source.codigo_item
WHEN MATCHED THEN UPDATE SET prioridad=source.prioridad,activo=1
WHEN NOT MATCHED THEN INSERT(codigo_item,prioridad,activo) VALUES(source.codigo_item,source.prioridad,1);
GO

/* Actualiza solo la definición del SP. No recalcula ni modifica pagos históricos. */
DECLARE @def NVARCHAR(MAX) = OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_guardar_pago_detalle_conceptos', N'P'));
DECLARE @bloque_antiguo NVARCHAR(MAX) = N'CASE tid.codigo_item
            WHEN N''ARRIENDO'' THEN 10
            WHEN N''MULTA'' THEN 20
            WHEN N''DANO'' THEN 30
            WHEN N''SERVICIO_AGUA'' THEN 40
            WHEN N''SERVICIO_LUZ'' THEN 50
            WHEN N''SERVICIO_GAS'' THEN 60
            WHEN N''AJUSTE'' THEN 90
            ELSE 80
        END AS prioridad,';
DECLARE @bloque_nuevo NVARCHAR(MAX) = N'ISNULL((SELECT p.prioridad
                FROM dbo.msp_prioridades_imputacion_pago p
                WHERE p.codigo_item=tid.codigo_item AND p.activo=1),80) AS prioridad,';

IF @def IS NULL
BEGIN
    ;THROW 51210, 'No existe dbo.msp_guardar_pago_detalle_conceptos. Ejecuta antes patch_pagos_por_concepto.sql.', 1;
END;

IF CHARINDEX(N'msp_prioridades_imputacion_pago', @def) = 0
BEGIN
    IF CHARINDEX(@bloque_antiguo, @def) = 0
    BEGIN
        ;THROW 51211, 'La definición esperada de msp_guardar_pago_detalle_conceptos no coincide; no se aplicó una modificación insegura.', 1;
    END;

    SET @def = REPLACE(@def, @bloque_antiguo, @bloque_nuevo);
    SET @def = REPLACE(
        @def,
        N'                10,
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)',
        N'                ISNULL((SELECT prioridad FROM dbo.msp_prioridades_imputacion_pago WHERE codigo_item=N''ARRIENDO'' AND activo=1),80),
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)'
    );
    /* OBJECT_DEFINITION conserva CREATE PROCEDURE; al recompilar una definición
       existente debe transformarse a ALTER PROCEDURE. */
    SET @def = REPLACE(@def, N'CREATE PROCEDURE dbo.msp_guardar_pago_detalle_conceptos', N'ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos');
    SET @def = REPLACE(@def, N'CREATE   PROCEDURE dbo.msp_guardar_pago_detalle_conceptos', N'ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos');
    SET @def = REPLACE(@def, N'CREATE OR ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos', N'ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos');
    EXEC sys.sp_executesql @def;
END;
GO
