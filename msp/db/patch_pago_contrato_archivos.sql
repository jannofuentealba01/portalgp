/*
===========================================================================
 PATCH: RESPALDO DE ARCHIVOS PDF DE PAGO POR CONTRATO
 - Crea indice y metadata para vales/comprobantes respaldados en disco.
 - Idempotente para SQL Server / esquema dbo.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pago_contrato_archivos (
        id_pago_contrato_archivo INT IDENTITY(1,1) NOT NULL,
        id_pago INT NOT NULL,
        id_documento_cobro INT NOT NULL,
        id_contrato_arriendo INT NOT NULL,
        id_arrendatario INT NOT NULL,
        modulo_origen NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_modulo DEFAULT (N'PAGO_CONTRATO'),
        tipo_archivo NVARCHAR(30) NOT NULL,
        periodo_ym CHAR(7) NULL,
        fecha_pago DATE NULL,
        numero_documento NVARCHAR(100) NULL,
        arrendatario_nombre NVARCHAR(200) NULL,
        locales NVARCHAR(200) NULL,
        nombre_archivo NVARCHAR(260) NOT NULL,
        ruta_relativa NVARCHAR(500) NOT NULL,
        mime_type NVARCHAR(100) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_mime DEFAULT (N'application/pdf'),
        hash_sha256 CHAR(64) NOT NULL,
        bytes_archivo BIGINT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_bytes DEFAULT (0),
        payload_json NVARCHAR(MAX) NULL,
        estado_archivo NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_estado DEFAULT (N'ACTIVO'),
        id_usuario INT NULL,
        fecha_generacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_generacion DEFAULT (SYSDATETIME()),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_registro DEFAULT (SYSDATETIME()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_pago_contrato_archivos PRIMARY KEY (id_pago_contrato_archivo),
        CONSTRAINT CK_msp_pago_contrato_archivos_tipo CHECK (UPPER(tipo_archivo) IN (N'VALE_PAGO', N'COMPROBANTE_GASTOS', N'VALE_COBRO')),
        CONSTRAINT CK_msp_pago_contrato_archivos_estado CHECK (UPPER(estado_archivo) IN (N'ACTIVO', N'REGENERADO', N'FALTANTE', N'ERROR'))
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'id_pago') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD id_pago INT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_id_pago DEFAULT (0);
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'id_documento_cobro') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD id_documento_cobro INT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_doc DEFAULT (0);
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'id_contrato_arriendo') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD id_contrato_arriendo INT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_contrato DEFAULT (0);
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'id_arrendatario') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD id_arrendatario INT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_arr DEFAULT (0);
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'modulo_origen') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD modulo_origen NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_modulo_legacy DEFAULT (N'PAGO_CONTRATO');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'tipo_archivo') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD tipo_archivo NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_tipo DEFAULT (N'VALE_PAGO');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'periodo_ym') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD periodo_ym CHAR(7) NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'fecha_pago') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD fecha_pago DATE NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'numero_documento') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD numero_documento NVARCHAR(100) NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'arrendatario_nombre') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD arrendatario_nombre NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'locales') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD locales NVARCHAR(200) NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'nombre_archivo') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD nombre_archivo NVARCHAR(260) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_nombre DEFAULT (N'documento.pdf');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'ruta_relativa') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD ruta_relativa NVARCHAR(500) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_ruta DEFAULT (N'pendiente.pdf');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'mime_type') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD mime_type NVARCHAR(100) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_mime_legacy DEFAULT (N'application/pdf');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'hash_sha256') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD hash_sha256 CHAR(64) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_hash DEFAULT (REPLICATE('0', 64));
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'bytes_archivo') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD bytes_archivo BIGINT NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_bytes_legacy DEFAULT (0);
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'payload_json') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD payload_json NVARCHAR(MAX) NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'estado_archivo') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD estado_archivo NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_estado_legacy DEFAULT (N'ACTIVO');
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'id_usuario') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD id_usuario INT NULL;
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'fecha_generacion') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD fecha_generacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_generacion_legacy DEFAULT (SYSDATETIME());
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'fecha_registro') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_registro_legacy DEFAULT (SYSDATETIME());
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'updated_at') IS NULL
        ALTER TABLE dbo.msp_pago_contrato_archivos ADD updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pago_contrato_archivos_updated_legacy DEFAULT (SYSDATETIME());
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UX_msp_pago_contrato_archivos_pago_doc_tipo'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U')
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_pago_contrato_archivos_pago_doc_tipo
        ON dbo.msp_pago_contrato_archivos (id_pago, id_documento_cobro, tipo_archivo);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pago_contrato_archivos_periodo_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pago_contrato_archivos_periodo_fecha
        ON dbo.msp_pago_contrato_archivos (periodo_ym, fecha_pago DESC, id_pago_contrato_archivo DESC)
        INCLUDE (tipo_archivo, arrendatario_nombre, locales, numero_documento, estado_archivo, nombre_archivo);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pago_contrato_archivos_contrato'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pago_contrato_archivos_contrato
        ON dbo.msp_pago_contrato_archivos (id_contrato_arriendo, id_arrendatario, id_pago_contrato_archivo DESC)
        INCLUDE (periodo_ym, tipo_archivo, numero_documento, nombre_archivo, estado_archivo);
END;
GO

PRINT 'Patch pago_contrato_archivos aplicado.';
GO
