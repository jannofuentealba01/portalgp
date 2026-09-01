SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;

-- Normaliza instalaciones donde estos nombres se guardaron con tilde o con
-- una codificación incorrecta. Los identificadores canónicos son ASCII para
-- que el parche produzca el mismo resultado desde SSMS, sqlcmd o PHP.
IF NOT EXISTS (SELECT 1 FROM dbo.cr_permisos WHERE nombre_permiso = N'MSP Operacion')
BEGIN
    UPDATE TOP (1) dbo.cr_permisos
       SET nombre_permiso = N'MSP Operacion'
     WHERE nombre_permiso LIKE N'MSP Operaci%';
END;

IF NOT EXISTS (SELECT 1 FROM dbo.cr_permisos WHERE nombre_permiso = N'MSP Configuracion')
BEGIN
    UPDATE TOP (1) dbo.cr_permisos
       SET nombre_permiso = N'MSP Configuracion'
     WHERE nombre_permiso LIKE N'MSP Configuraci%';
END;

DECLARE @permisos TABLE(nombre NVARCHAR(150), descripcion NVARCHAR(255));
INSERT INTO @permisos(nombre, descripcion) VALUES
    (N'MSP Operacion', N'Gestiona arrendatarios, contratos, tiendas, locales, medidores y operación diaria MSP.'),
    (N'MSP Cobranza', N'Gestiona documentos, pagos, saldos, cargos, garantías y cobranza MSP.'),
    (N'MSP Cierre Mensual', N'Revisa, reabre y cierra períodos mensuales MSP.'),
    (N'MSP Reportes', N'Consulta dashboard, contabilidad e informes MSP.'),
    (N'MSP Configuracion', N'Administra catálogos maestros, parámetros y configuración MSP.');

INSERT INTO dbo.cr_permisos(nombre_permiso, descripcion)
SELECT p.nombre, p.descripcion
FROM @permisos p
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.cr_permisos actual WHERE actual.nombre_permiso = p.nombre
);

-- Compatibilidad: los roles que ya accedían a MSP reciben inicialmente todos
-- los permisos específicos. Luego pueden ajustarse desde Gestión de Roles.
INSERT INTO dbo.cr_rol_permisos(rol_id, permiso_id)
SELECT DISTINCT legado.rol_id, nuevo.id
FROM dbo.cr_rol_permisos legado
INNER JOIN dbo.cr_permisos permiso_legado
    ON permiso_legado.id = legado.permiso_id
   AND permiso_legado.nombre_permiso = N'MSP Arriendos'
CROSS JOIN dbo.cr_permisos nuevo
WHERE nuevo.nombre_permiso IN (
    N'MSP Operacion', N'MSP Cobranza', N'MSP Cierre Mensual',
    N'MSP Reportes', N'MSP Configuracion'
)
AND NOT EXISTS (
    SELECT 1
    FROM dbo.cr_rol_permisos existente
    WHERE existente.rol_id = legado.rol_id
      AND existente.permiso_id = nuevo.id
);

COMMIT TRANSACTION;
GO
