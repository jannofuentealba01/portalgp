/*
===========================================================================
 MSP - REGISTRO DE MIGRACIONES DE ESQUEMA
 Mantiene la huella de los parches aplicados por el verificador de instalación.
===========================================================================
*/

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_schema_migrations', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_schema_migrations (
        migration_file NVARCHAR(260) NOT NULL,
        sha256 CHAR(64) NOT NULL,
        applied_at DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_schema_migrations_applied_at DEFAULT (SYSDATETIME()),
        applied_by NVARCHAR(128) NULL,
        source NVARCHAR(30) NOT NULL
            CONSTRAINT DF_msp_schema_migrations_source DEFAULT (N'VERIFICADOR'),
        CONSTRAINT PK_msp_schema_migrations PRIMARY KEY (migration_file),
        CONSTRAINT CK_msp_schema_migrations_sha256 CHECK (LEN(sha256) = 64)
    );
END;
GO

PRINT N'Registro de migraciones MSP disponible.';
GO
