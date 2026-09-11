/* Migracion unica: conserva permisos efectivos, no modifica credenciales. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.cr_security_migrations', N'U') IS NULL
    CREATE TABLE dbo.cr_security_migrations (
        migration_name NVARCHAR(120) NOT NULL PRIMARY KEY,
        applied_at DATETIME2 NOT NULL DEFAULT SYSDATETIME()
    );
IF NOT EXISTS (SELECT 1 FROM dbo.cr_security_migrations WITH(UPDLOCK,HOLDLOCK)
               WHERE migration_name=N'seguridad_acceso_etapa1')
BEGIN
    IF OBJECT_ID(N'dbo.cr_security_permisos_backup_etapa1', N'U') IS NOT NULL
        THROW 54001, N'Existe respaldo previo sin marcador; revisar antes de migrar.', 1;
    SELECT rol_id, permiso_id, lectura, escritura, eliminacion
        INTO dbo.cr_security_permisos_backup_etapa1 FROM dbo.cr_rol_permisos;
    /* Antes la existencia de la fila concedia todas las acciones, incluso con 0/NULL. */
    UPDATE dbo.cr_rol_permisos SET lectura=1, escritura=1, eliminacion=1;
    INSERT dbo.cr_security_migrations(migration_name) VALUES(N'seguridad_acceso_etapa1');
END;
IF COL_LENGTH(N'dbo.cr_usuarios', N'security_version') IS NULL
    ALTER TABLE dbo.cr_usuarios ADD security_version INT NOT NULL
        CONSTRAINT DF_cr_usuarios_security_version DEFAULT(0) WITH VALUES;
COMMIT TRANSACTION;
GO
/* Revoca tambien si se deshabilita y rehabilita entre solicitudes. */
CREATE OR ALTER TRIGGER dbo.tr_cr_usuarios_security_version
ON dbo.cr_usuarios AFTER UPDATE AS
BEGIN
    SET NOCOUNT ON;
    IF NOT (UPDATE(estado_id) OR UPDATE(rol_id) OR UPDATE(password_hash)) RETURN;
    UPDATE u SET security_version=u.security_version+1
    FROM dbo.cr_usuarios u JOIN inserted i ON i.id=u.id JOIN deleted d ON d.id=i.id
    WHERE ISNULL(i.estado_id,-1)<>ISNULL(d.estado_id,-1)
       OR ISNULL(i.rol_id,-1)<>ISNULL(d.rol_id,-1)
       OR ISNULL(CONVERT(VARBINARY(MAX),i.password_hash),0x)
          <>ISNULL(CONVERT(VARBINARY(MAX),d.password_hash),0x);
END;
GO
