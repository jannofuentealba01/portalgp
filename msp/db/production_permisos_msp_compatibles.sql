/*
  Compatibilidad de permisos para despliegue productivo MSP.

  El PortalGP historico consideraba que la sola existencia de una fila en
  cr_rol_permisos otorgaba acceso. La capa de seguridad actual exige ademas
  los indicadores lectura/escritura/eliminacion.

  Este parche habilita EXCLUSIVAMENTE los permisos funcionales nuevos de MSP
  para los roles que ya tenian asignado el permiso historico "MSP Arriendos".
  No modifica credenciales, usuarios, roles ni permisos de otros modulos.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.cr_permisos', N'U') IS NULL
   OR OBJECT_ID(N'dbo.cr_rol_permisos', N'U') IS NULL
BEGIN
    THROW 51000, 'No existen las tablas base de permisos requeridas.', 1;
END;

BEGIN TRY
    BEGIN TRANSACTION;

    ;WITH roles_msp_existentes AS (
        SELECT DISTINCT rp.rol_id
        FROM dbo.cr_rol_permisos AS rp
        INNER JOIN dbo.cr_permisos AS p
            ON p.id = rp.permiso_id
        WHERE p.nombre_permiso = N'MSP Arriendos'
    ),
    permisos_msp_funcionales AS (
        SELECT p.id AS permiso_id
        FROM dbo.cr_permisos AS p
        WHERE p.nombre_permiso IN (
            N'MSP Documentos Tienda Carga',
            N'MSP Documentos Tienda Revision',
            N'MSP Documentos Tienda Aprobacion',
            N'MSP Operacion',
            N'MSP Cobranza',
            N'MSP Cierre Mensual',
            N'MSP Reportes',
            N'MSP Configuracion',
            N'MSP Tesoreria'
        )
    )
    INSERT INTO dbo.cr_rol_permisos
        (rol_id, permiso_id, lectura, escritura, eliminacion)
    SELECT r.rol_id, p.permiso_id, 1, 1, 1
    FROM roles_msp_existentes AS r
    CROSS JOIN permisos_msp_funcionales AS p
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.cr_rol_permisos AS existente
        WHERE existente.rol_id = r.rol_id
          AND existente.permiso_id = p.permiso_id
    );

    ;WITH roles_msp_existentes AS (
        SELECT DISTINCT rp.rol_id
        FROM dbo.cr_rol_permisos AS rp
        INNER JOIN dbo.cr_permisos AS p
            ON p.id = rp.permiso_id
        WHERE p.nombre_permiso = N'MSP Arriendos'
    )
    UPDATE rp
       SET rp.lectura = 1,
           rp.escritura = 1,
           rp.eliminacion = 1
    FROM dbo.cr_rol_permisos AS rp
    INNER JOIN dbo.cr_permisos AS p
        ON p.id = rp.permiso_id
    INNER JOIN roles_msp_existentes AS r
        ON r.rol_id = rp.rol_id
    WHERE p.nombre_permiso IN (
        N'MSP Documentos Tienda Carga',
        N'MSP Documentos Tienda Revision',
        N'MSP Documentos Tienda Aprobacion',
        N'MSP Operacion',
        N'MSP Cobranza',
        N'MSP Cierre Mensual',
        N'MSP Reportes',
        N'MSP Configuracion',
        N'MSP Tesoreria'
    )
      AND (
          ISNULL(rp.lectura, 0) <> 1
          OR ISNULL(rp.escritura, 0) <> 1
          OR ISNULL(rp.eliminacion, 0) <> 1
      );

    DECLARE @roles_habilitados int = (
        SELECT COUNT(DISTINCT rp.rol_id)
        FROM dbo.cr_rol_permisos AS rp
        INNER JOIN dbo.cr_permisos AS p
            ON p.id = rp.permiso_id
        WHERE p.nombre_permiso IN (
            N'MSP Documentos Tienda Carga',
            N'MSP Documentos Tienda Revision',
            N'MSP Documentos Tienda Aprobacion',
            N'MSP Operacion',
            N'MSP Cobranza',
            N'MSP Cierre Mensual',
            N'MSP Reportes',
            N'MSP Configuracion',
            N'MSP Tesoreria'
        )
          AND rp.lectura = 1
          AND rp.escritura = 1
          AND rp.eliminacion = 1
    );

    COMMIT TRANSACTION;

    PRINT CONCAT(
        'Compatibilidad MSP aplicada. Roles con permisos MSP funcionales: ',
        @roles_habilitados,
        '.'
    );
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
