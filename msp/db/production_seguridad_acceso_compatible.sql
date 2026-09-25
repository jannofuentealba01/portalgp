/*
===========================================================================
 PortalGP / MSP - Compatibilidad de acceso para despliegue productivo

 Este parche contiene únicamente los requisitos de esquema que necesita el
 código actual de autenticación. No normaliza ni amplía los permisos de los
 roles existentes y no modifica credenciales.
===========================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.cr_security_migrations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.cr_security_migrations (
        migration_name NVARCHAR(120) NOT NULL
            CONSTRAINT PK_cr_security_migrations PRIMARY KEY,
        applied_at DATETIME2(0) NOT NULL
            CONSTRAINT DF_cr_security_migrations_applied_at DEFAULT(SYSDATETIME())
    );
END;

IF COL_LENGTH(N'dbo.cr_usuarios', N'security_version') IS NULL
BEGIN
    ALTER TABLE dbo.cr_usuarios
    ADD security_version INT NOT NULL
        CONSTRAINT DF_cr_usuarios_security_version DEFAULT(0) WITH VALUES;
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.cr_security_migrations WITH (UPDLOCK, HOLDLOCK)
    WHERE migration_name = N'seguridad_acceso_compatible_msp'
)
BEGIN
    INSERT dbo.cr_security_migrations(migration_name)
    VALUES(N'seguridad_acceso_compatible_msp');
END;

COMMIT TRANSACTION;
GO

/*
 Invalida sesiones solo cuando cambia el estado, rol o hash de contraseña del
 usuario. Crear el trigger no altera filas ni incrementa versiones existentes.
*/
CREATE OR ALTER TRIGGER dbo.tr_cr_usuarios_security_version
ON dbo.cr_usuarios
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF NOT (UPDATE(estado_id) OR UPDATE(rol_id) OR UPDATE(password_hash))
        RETURN;

    UPDATE usuario
       SET security_version = usuario.security_version + 1
    FROM dbo.cr_usuarios usuario
    INNER JOIN inserted nuevo ON nuevo.id = usuario.id
    INNER JOIN deleted anterior ON anterior.id = nuevo.id
    WHERE ISNULL(nuevo.estado_id, -1) <> ISNULL(anterior.estado_id, -1)
       OR ISNULL(nuevo.rol_id, -1) <> ISNULL(anterior.rol_id, -1)
       OR ISNULL(CONVERT(VARBINARY(MAX), nuevo.password_hash), 0x)
          <> ISNULL(CONVERT(VARBINARY(MAX), anterior.password_hash), 0x);
END;
GO

PRINT N'Compatibilidad de acceso MSP instalada sin ampliar permisos globales.';
GO
