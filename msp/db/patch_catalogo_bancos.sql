/*
===========================================================================
 MSP - PATCH CATALOGO BANCOS
 - Crea tabla de bancos para medios de pago por cheque
 - Incluye semillas base para bancos frecuentes en Chile
 - Idempotente
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_bancos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_bancos (
        id_banco        INT IDENTITY(1,1) NOT NULL,
        nombre_banco    NVARCHAR(120) NOT NULL,
        codigo_banco    NVARCHAR(20) NULL,
        activo          BIT NOT NULL CONSTRAINT DF_msp_bancos_activo DEFAULT (1),
        created_at      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bancos_created_at DEFAULT (SYSDATETIME()),
        updated_at      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bancos_updated_at DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_bancos PRIMARY KEY (id_banco),
        CONSTRAINT CK_msp_bancos_nombre_no_vacio CHECK (LEN(LTRIM(RTRIM(nombre_banco))) > 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UX_msp_bancos_nombre'
      AND object_id = OBJECT_ID(N'dbo.msp_bancos', N'U')
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_bancos_nombre
        ON dbo.msp_bancos (nombre_banco);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_bancos_activo_nombre'
      AND object_id = OBJECT_ID(N'dbo.msp_bancos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_bancos_activo_nombre
        ON dbo.msp_bancos (activo, nombre_banco);
END;
GO

DECLARE @seed TABLE (
    nombre_banco NVARCHAR(120) NOT NULL,
    codigo_banco NVARCHAR(20) NULL
);

INSERT INTO @seed (nombre_banco, codigo_banco)
VALUES
    (N'Banco de Chile', N'001'),
    (N'Banco Internacional', N'009'),
    (N'Scotiabank Chile', N'014'),
    (N'Banco de Crédito e Inversiones (BCI)', N'016'),
    (N'Banco Estado', N'012'),
    (N'Banco BICE', N'028'),
    (N'Banco Santander Chile', N'037'),
    (N'Banco Itaú Chile', N'039'),
    (N'Banco Security', N'049'),
    (N'Banco Falabella', N'051'),
    (N'Banco Ripley', N'053'),
    (N'Banco Consorcio', N'055'),
    (N'Banco BTG Pactual Chile', N'031');

MERGE dbo.msp_bancos AS target
USING @seed AS source
    ON target.nombre_banco = source.nombre_banco
WHEN MATCHED THEN
    UPDATE SET
        codigo_banco = COALESCE(source.codigo_banco, target.codigo_banco),
        updated_at = SYSDATETIME()
WHEN NOT MATCHED BY TARGET THEN
    INSERT (nombre_banco, codigo_banco, activo, created_at, updated_at)
    VALUES (source.nombre_banco, source.codigo_banco, 1, SYSDATETIME(), SYSDATETIME());
GO

