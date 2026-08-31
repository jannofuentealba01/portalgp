/*
==========================================================================
 PATCH: TIPOS DE CARGO OBSOLETOS
 - ARRIENDO_VENCIDO
 - SERVICIO_PENDIENTE
 - SERVICIO_ESTIMADO

 Regla:
 - Si no tienen uso en cargos de salida, se eliminan del catalogo.
 - Si tienen uso historico, se dejan inactivos para no romper FK/historial.
==========================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID(N'dbo.msp_tipos_cargo_salida', N'U') IS NULL
BEGIN
    PRINT 'msp_tipos_cargo_salida no existe. Patch omitido.';
    RETURN;
END;

DECLARE @TiposObjetivo TABLE (codigo_tipo_cargo NVARCHAR(50) PRIMARY KEY);
INSERT INTO @TiposObjetivo (codigo_tipo_cargo)
VALUES
    (N'ARRIENDO_VENCIDO'),
    (N'SERVICIO_PENDIENTE'),
    (N'SERVICIO_ESTIMADO');

DECLARE @TiposEnUso TABLE (id_tipo_cargo_salida INT PRIMARY KEY);

IF OBJECT_ID(N'dbo.msp_cargos_salida_contrato_local', N'U') IS NOT NULL
BEGIN
    INSERT INTO @TiposEnUso (id_tipo_cargo_salida)
    SELECT DISTINCT cs.id_tipo_cargo_salida
    FROM dbo.msp_cargos_salida_contrato_local cs
    INNER JOIN dbo.msp_tipos_cargo_salida tc
        ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
    INNER JOIN @TiposObjetivo t
        ON t.codigo_tipo_cargo = tc.codigo_tipo_cargo;
END;

UPDATE tc
SET tc.activo = 0
FROM dbo.msp_tipos_cargo_salida tc
INNER JOIN @TiposObjetivo t
    ON t.codigo_tipo_cargo = tc.codigo_tipo_cargo
INNER JOIN @TiposEnUso tu
    ON tu.id_tipo_cargo_salida = tc.id_tipo_cargo_salida;

DELETE tc
FROM dbo.msp_tipos_cargo_salida tc
INNER JOIN @TiposObjetivo t
    ON t.codigo_tipo_cargo = tc.codigo_tipo_cargo
LEFT JOIN @TiposEnUso tu
    ON tu.id_tipo_cargo_salida = tc.id_tipo_cargo_salida
WHERE tu.id_tipo_cargo_salida IS NULL;

IF OBJECT_ID(N'dbo.msp_acc_tipo_cargo_cuenta', N'U') IS NOT NULL
BEGIN
    DELETE map
    FROM dbo.msp_acc_tipo_cargo_cuenta map
    INNER JOIN @TiposObjetivo t
        ON t.codigo_tipo_cargo = map.codigo_tipo_cargo;
END;
GO
