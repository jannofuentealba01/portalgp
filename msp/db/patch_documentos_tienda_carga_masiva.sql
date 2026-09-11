SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;

IF OBJECT_ID(N'dbo.msp_documentos_tienda_lotes', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_documentos_tienda_lotes (
        id_lote_documentos_tienda INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_documentos_tienda_lotes PRIMARY KEY,
        nombre_lote NVARCHAR(180) NOT NULL,
        estado_lote NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_dtl_estado DEFAULT (N'BORRADOR'),
        id_usuario_creador INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dtl_fecha DEFAULT (SYSDATETIME()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dtl_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT CK_msp_dtl_estado CHECK (estado_lote IN (N'BORRADOR', N'EN_REVISION', N'LISTO', N'PARCIAL', N'COMPLETADO')),
        CONSTRAINT FK_msp_dtl_usuario FOREIGN KEY (id_usuario_creador) REFERENCES dbo.cr_usuarios(id)
    );
END;

IF OBJECT_ID(N'dbo.msp_documentos_tienda_archivos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_documentos_tienda_archivos (
        id_documento_tienda_archivo INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_documentos_tienda_archivos PRIMARY KEY,
        id_lote_documentos_tienda INT NOT NULL,
        nombre_original NVARCHAR(260) NOT NULL,
        nombre_almacenado NVARCHAR(120) NOT NULL,
        ruta_relativa NVARCHAR(500) NOT NULL,
        mime_type NVARCHAR(100) NOT NULL,
        hash_sha256 CHAR(64) NOT NULL,
        bytes_archivo BIGINT NOT NULL,
        id_tienda INT NULL,
        id_contrato_arriendo INT NULL,
        id_arrendatario INT NULL,
        correo_destino_snapshot NVARCHAR(254) NULL,
        estado_archivo NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_dta_estado DEFAULT (N'PENDIENTE'),
        observaciones NVARCHAR(1000) NULL,
        ultimo_error NVARCHAR(1000) NULL,
        intentos_envio INT NOT NULL CONSTRAINT DF_msp_dta_intentos DEFAULT (0),
        enviado_at DATETIME2(0) NULL,
        id_usuario_carga INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dta_fecha DEFAULT (SYSDATETIME()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dta_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT FK_msp_dta_lote FOREIGN KEY (id_lote_documentos_tienda) REFERENCES dbo.msp_documentos_tienda_lotes(id_lote_documentos_tienda),
        CONSTRAINT FK_msp_dta_tienda FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas(id_tienda),
        CONSTRAINT FK_msp_dta_contrato FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT FK_msp_dta_arrendatario FOREIGN KEY (id_arrendatario) REFERENCES dbo.msp_arrendatarios(id_arrendatario),
        CONSTRAINT FK_msp_dta_usuario FOREIGN KEY (id_usuario_carga) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_dta_estado CHECK (estado_archivo IN (N'PENDIENTE', N'ASOCIADO', N'ENVIADO', N'ERROR', N'OMITIDO')),
        CONSTRAINT CK_msp_dta_bytes CHECK (bytes_archivo > 0)
    );

    CREATE UNIQUE INDEX UX_msp_dta_lote_hash
        ON dbo.msp_documentos_tienda_archivos (id_lote_documentos_tienda, hash_sha256);
    CREATE UNIQUE INDEX UX_msp_dta_ruta
        ON dbo.msp_documentos_tienda_archivos (ruta_relativa);
    CREATE INDEX IX_msp_dta_revision
        ON dbo.msp_documentos_tienda_archivos (id_lote_documentos_tienda, estado_archivo, id_documento_tienda_archivo);
END;

IF OBJECT_ID(N'dbo.msp_documentos_tienda_archivos', N'U') IS NOT NULL
   AND NOT EXISTS (
       SELECT 1 FROM sys.indexes
       WHERE object_id=OBJECT_ID(N'dbo.msp_documentos_tienda_archivos')
         AND name=N'IX_msp_dta_hash_global'
   )
BEGIN
    CREATE INDEX IX_msp_dta_hash_global
        ON dbo.msp_documentos_tienda_archivos (hash_sha256)
        INCLUDE (id_lote_documentos_tienda, nombre_original);
END;

IF OBJECT_ID(N'dbo.msp_documentos_tienda_eventos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_documentos_tienda_eventos (
        id_documento_tienda_evento BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_documentos_tienda_eventos PRIMARY KEY,
        id_lote_documentos_tienda INT NOT NULL,
        id_documento_tienda_archivo INT NULL,
        tipo_evento NVARCHAR(40) NOT NULL,
        detalle_evento NVARCHAR(1000) NULL,
        id_usuario INT NULL,
        fecha_evento DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dte_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT FK_msp_dte_lote FOREIGN KEY (id_lote_documentos_tienda) REFERENCES dbo.msp_documentos_tienda_lotes(id_lote_documentos_tienda),
        CONSTRAINT FK_msp_dte_archivo FOREIGN KEY (id_documento_tienda_archivo) REFERENCES dbo.msp_documentos_tienda_archivos(id_documento_tienda_archivo),
        CONSTRAINT FK_msp_dte_usuario FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_dte_tipo CHECK (tipo_evento IN (N'LOTE_CREADO', N'ARCHIVO_CARGADO', N'ASOCIACION', N'OMISION', N'ENVIO_DEMO', N'ERROR_ENVIO'))
    );

    CREATE INDEX IX_msp_dte_lote_fecha
        ON dbo.msp_documentos_tienda_eventos (id_lote_documentos_tienda, fecha_evento DESC);
END;

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.msp_documentos_tienda_lotes TO portalgp_runtime_role;
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.msp_documentos_tienda_archivos TO portalgp_runtime_role;
    GRANT SELECT, INSERT, UPDATE, DELETE ON dbo.msp_documentos_tienda_eventos TO portalgp_runtime_role;
END;
