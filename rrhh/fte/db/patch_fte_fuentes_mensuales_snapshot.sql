SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U') IS NULL
BEGIN
    THROW 51050, N'Primero debe instalarse patch_fte_dotacion_snapshot.sql.', 1;
END;

IF OBJECT_ID(N'dbo.fte_dotacion_snapshot_fuentes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.fte_dotacion_snapshot_fuentes (
        id_fte_dotacion_snapshot_fuente BIGINT IDENTITY(1,1) NOT NULL,
        id_fte_dotacion_snapshot BIGINT NOT NULL,
        clave_fuente NVARCHAR(50) NOT NULL,
        proveedor NVARCHAR(30) NOT NULL,
        estado_captura NVARCHAR(20) NOT NULL,
        version_esquema INT NOT NULL,
        cantidad_registros INT NOT NULL,
        payload_json NVARCHAR(MAX) NOT NULL,
        hash_fuente CHAR(64) NOT NULL,
        archivo_relativo NVARCHAR(500) NOT NULL,
        fecha_captura DATETIME2(0) NOT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_fte_snapshot_fuente_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_fte_dotacion_snapshot_fuentes PRIMARY KEY (id_fte_dotacion_snapshot_fuente),
        CONSTRAINT FK_fte_snapshot_fuente_snapshot FOREIGN KEY (id_fte_dotacion_snapshot) REFERENCES dbo.fte_dotacion_snapshots (id_fte_dotacion_snapshot),
        CONSTRAINT UQ_fte_snapshot_fuente_clave UNIQUE (id_fte_dotacion_snapshot, clave_fuente),
        CONSTRAINT CK_fte_snapshot_fuente_clave CHECK (clave_fuente IN (N'BUK_PERSONAS',N'BUK_VACACIONES',N'BUK_LICENCIAS',N'GEOVICTORIA_ASISTENCIA',N'GEOVICTORIA_DIAGNOSTICOS')),
        CONSTRAINT CK_fte_snapshot_fuente_proveedor CHECK (proveedor IN (N'BUK',N'GEOVICTORIA')),
        CONSTRAINT CK_fte_snapshot_fuente_estado CHECK (estado_captura IN (N'COMPLETA',N'PARCIAL')),
        CONSTRAINT CK_fte_snapshot_fuente_version CHECK (version_esquema > 0),
        CONSTRAINT CK_fte_snapshot_fuente_cantidad CHECK (cantidad_registros >= 0),
        CONSTRAINT CK_fte_snapshot_fuente_json CHECK (ISJSON(payload_json) = 1),
        CONSTRAINT CK_fte_snapshot_fuente_hash CHECK (hash_fuente NOT LIKE '%[^0-9a-f]%' AND LEN(hash_fuente) = 64),
        CONSTRAINT CK_fte_snapshot_fuente_archivo CHECK (LEN(LTRIM(RTRIM(archivo_relativo))) > 0)
    );
END;

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.fte_dotacion_snapshot_fuentes',N'U') AND name=N'IX_fte_snapshot_fuente_clave')
BEGIN
    CREATE INDEX IX_fte_snapshot_fuente_clave ON dbo.fte_dotacion_snapshot_fuentes (clave_fuente,id_fte_dotacion_snapshot)
        INCLUDE (estado_captura,cantidad_registros,hash_fuente,fecha_captura);
END;
GO

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT ON OBJECT::dbo.fte_dotacion_snapshot_fuentes TO portalgp_runtime_role;
END;

PRINT N'Fotografias integrales de fuentes mensuales FTE instaladas.';
GO

CREATE OR ALTER TRIGGER dbo.trg_fte_snapshot_fuente_inmutable
ON dbo.fte_dotacion_snapshot_fuentes
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (
        SELECT 1 FROM (
            SELECT id_fte_dotacion_snapshot FROM inserted
            UNION
            SELECT id_fte_dotacion_snapshot FROM deleted
        ) x INNER JOIN dbo.fte_dotacion_snapshots s ON s.id_fte_dotacion_snapshot=x.id_fte_dotacion_snapshot
        WHERE s.estado_snapshot=N'APROBADO'
    ) THROW 51051, N'Las fuentes de una fotografia FTE aprobada son inmutables.', 1;
END;
GO
