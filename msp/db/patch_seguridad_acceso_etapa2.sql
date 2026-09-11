/* Seguridad etapa 2: limitación temporal del login local.
   No modifica usuarios, contraseñas, roles ni permisos existentes. */
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.cr_login_intentos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.cr_login_intentos (
        clave_hash CHAR(64) NOT NULL CONSTRAINT PK_cr_login_intentos PRIMARY KEY,
        tipo_clave VARCHAR(12) NOT NULL,
        intentos_fallidos INT NOT NULL CONSTRAINT DF_cr_login_intentos_fallos DEFAULT(0),
        ventana_inicio DATETIME2(0) NOT NULL CONSTRAINT DF_cr_login_intentos_inicio DEFAULT(SYSDATETIME()),
        ultimo_intento DATETIME2(0) NOT NULL CONSTRAINT DF_cr_login_intentos_ultimo DEFAULT(SYSDATETIME()),
        bloqueado_hasta DATETIME2(0) NULL,
        CONSTRAINT CK_cr_login_intentos_tipo CHECK(tipo_clave IN('CUENTA','ORIGEN')),
        CONSTRAINT CK_cr_login_intentos_fallos CHECK(intentos_fallidos>=0)
    );
    CREATE INDEX IX_cr_login_intentos_limpieza ON dbo.cr_login_intentos(ultimo_intento);
END;
IF OBJECT_ID(N'dbo.cr_security_migrations', N'U') IS NULL
    CREATE TABLE dbo.cr_security_migrations (
        migration_name NVARCHAR(120) NOT NULL PRIMARY KEY,
        applied_at DATETIME2 NOT NULL DEFAULT SYSDATETIME()
    );
IF NOT EXISTS(SELECT 1 FROM dbo.cr_security_migrations WHERE migration_name=N'seguridad_acceso_etapa2')
    INSERT dbo.cr_security_migrations(migration_name) VALUES(N'seguridad_acceso_etapa2');
COMMIT TRANSACTION;
GO
