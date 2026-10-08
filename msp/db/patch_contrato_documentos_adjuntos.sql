SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_centro_documental', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_centro_documental (
        id_documento                 INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo         INT NULL,
        id_arrendatario              INT NULL,
        id_local                     INT NULL,
        nombre_archivo               NVARCHAR(255) NOT NULL,
        ruta_archivo                 NVARCHAR(1000) NOT NULL,
        ruta_relativa                NVARCHAR(1000) NULL,
        tipo_documento               NVARCHAR(50) NOT NULL,
        descripcion                  NVARCHAR(500) NULL,
        fecha_documento              DATE NULL,
        mime_type                    NVARCHAR(100) NULL,
        hash_sha256                  CHAR(64) NULL,
        bytes_archivo                BIGINT NULL,
        id_documento_reemplazado     INT NULL,
        estado                       NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cdoc_estado DEFAULT N'ACTIVO',
        id_usuario                   INT NULL,
        fecha_registro               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cdoc_reg DEFAULT SYSDATETIME(),
        fecha_actualizacion          DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cdoc_actualizacion DEFAULT SYSDATETIME(),
        fecha_anulacion              DATETIME2(0) NULL,
        id_usuario_anulacion         INT NULL,
        motivo_anulacion             NVARCHAR(500) NULL,
        CONSTRAINT PK_msp_centro_doc PRIMARY KEY (id_documento),
        CONSTRAINT CK_msp_cdoc_estado CHECK (estado IN (N'ACTIVO', N'ANULADO')),
        CONSTRAINT CK_msp_cdoc_bytes CHECK (bytes_archivo IS NULL OR bytes_archivo BETWEEN 1 AND 15728640),
        CONSTRAINT CK_msp_cdoc_hash CHECK (hash_sha256 IS NULL OR LEN(hash_sha256) = 64),
        CONSTRAINT FK_msp_cdoc_reemplazado FOREIGN KEY (id_documento_reemplazado)
            REFERENCES dbo.msp_centro_documental (id_documento)
    );
END;
GO

IF COL_LENGTH(N'dbo.msp_centro_documental', N'ruta_relativa') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD ruta_relativa NVARCHAR(1000) NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'fecha_documento') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD fecha_documento DATE NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'mime_type') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD mime_type NVARCHAR(100) NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'hash_sha256') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD hash_sha256 CHAR(64) NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'bytes_archivo') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD bytes_archivo BIGINT NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'id_documento_reemplazado') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD id_documento_reemplazado INT NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'fecha_actualizacion') IS NULL
BEGIN
    ALTER TABLE dbo.msp_centro_documental
        ADD fecha_actualizacion DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_cdoc_actualizacion DEFAULT SYSDATETIME() WITH VALUES;
END;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'fecha_anulacion') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD fecha_anulacion DATETIME2(0) NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'id_usuario_anulacion') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD id_usuario_anulacion INT NULL;
GO
IF COL_LENGTH(N'dbo.msp_centro_documental', N'motivo_anulacion') IS NULL
    ALTER TABLE dbo.msp_centro_documental ADD motivo_anulacion NVARCHAR(500) NULL;
GO

IF OBJECT_ID(N'dbo.CK_msp_cdoc_bytes', N'C') IS NULL
BEGIN
    ALTER TABLE dbo.msp_centro_documental WITH CHECK
        ADD CONSTRAINT CK_msp_cdoc_bytes
        CHECK (bytes_archivo IS NULL OR bytes_archivo BETWEEN 1 AND 15728640);
END;
GO
IF OBJECT_ID(N'dbo.CK_msp_cdoc_hash', N'C') IS NULL
BEGIN
    ALTER TABLE dbo.msp_centro_documental WITH CHECK
        ADD CONSTRAINT CK_msp_cdoc_hash
        CHECK (hash_sha256 IS NULL OR LEN(hash_sha256) = 64);
END;
GO
IF OBJECT_ID(N'dbo.FK_msp_cdoc_reemplazado', N'F') IS NULL
BEGIN
    ALTER TABLE dbo.msp_centro_documental WITH CHECK
        ADD CONSTRAINT FK_msp_cdoc_reemplazado
        FOREIGN KEY (id_documento_reemplazado)
        REFERENCES dbo.msp_centro_documental (id_documento);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_centro_documental')
      AND name = N'IX_msp_cdoc_contrato_estado_fecha'
)
BEGIN
    CREATE INDEX IX_msp_cdoc_contrato_estado_fecha
        ON dbo.msp_centro_documental
            (id_contrato_arriendo, estado, fecha_registro DESC, id_documento DESC);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_centro_documental')
      AND name = N'UX_msp_cdoc_contrato_hash_activo'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_cdoc_contrato_hash_activo
        ON dbo.msp_centro_documental (id_contrato_arriendo, hash_sha256)
        WHERE id_contrato_arriendo IS NOT NULL
          AND hash_sha256 IS NOT NULL
          AND estado = N'ACTIVO';
END;
GO

PRINT 'patch_contrato_documentos_adjuntos aplicado correctamente.';
GO
