/*
===========================================================================
 MSP - PATCH ENVIO DE LOTES PROGRAMADOS
 - Cola persistente para envios automáticos de documentos de cobro
 - Fuente de elegibilidad: contrato/local (no ocupación)
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_envio_lotes_programados', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lotes_programados (
        id_lote_envio        INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion  DATE NOT NULL,
        codigo_servicio      NVARCHAR(20) NOT NULL,
        modo_destino         NVARCHAR(10) NOT NULL CONSTRAINT DF_msp_envio_lotes_modo DEFAULT (N'real'),
        demo_destino         NVARCHAR(200) NULL,
        programado_para      DATETIME2(0) NOT NULL,
        estado_lote          TINYINT NOT NULL CONSTRAINT DF_msp_envio_lotes_estado DEFAULT (1),
        batch_size           INT NOT NULL CONSTRAINT DF_msp_envio_lotes_batch DEFAULT (10),
        total_destinatarios  INT NOT NULL CONSTRAINT DF_msp_envio_lotes_total DEFAULT (0),
        procesados           INT NOT NULL CONSTRAINT DF_msp_envio_lotes_procesados DEFAULT (0),
        enviados             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_enviados DEFAULT (0),
        fallidos             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_fallidos DEFAULT (0),
        omitidos             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_omitidos DEFAULT (0),
        worker_token         NVARCHAR(120) NULL,
        last_error           NVARCHAR(1000) NULL,
        created_by_user_id   INT NULL,
        created_at           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lotes_created_at DEFAULT (SYSDATETIME()),
        updated_at           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lotes_updated_at DEFAULT (SYSDATETIME()),
        started_at           DATETIME2(0) NULL,
        finished_at          DATETIME2(0) NULL,
        CONSTRAINT PK_msp_envio_lotes_programados PRIMARY KEY (id_lote_envio),
        CONSTRAINT CK_msp_envio_lotes_periodo CHECK (DAY(periodo_facturacion) = 1),
        CONSTRAINT CK_msp_envio_lotes_servicio CHECK (UPPER(codigo_servicio) IN (N'AGUA', N'LUZ', N'GAS')),
        CONSTRAINT CK_msp_envio_lotes_modo CHECK (LOWER(modo_destino) IN (N'real', N'demo')),
        CONSTRAINT CK_msp_envio_lotes_estado CHECK (estado_lote IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_envio_lotes_batch CHECK (batch_size BETWEEN 1 AND 100),
        CONSTRAINT CK_msp_envio_lotes_contadores CHECK (
            total_destinatarios >= 0
            AND procesados >= 0
            AND enviados >= 0
            AND fallidos >= 0
            AND omitidos >= 0
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lotes_programados_estado_programado'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lotes_programados')
)
BEGIN
    CREATE INDEX IX_msp_envio_lotes_programados_estado_programado
        ON dbo.msp_envio_lotes_programados (estado_lote, programado_para, id_lote_envio)
        INCLUDE (batch_size, periodo_facturacion, codigo_servicio, modo_destino, demo_destino);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lotes_programados_periodo'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lotes_programados')
)
BEGIN
    CREATE INDEX IX_msp_envio_lotes_programados_periodo
        ON dbo.msp_envio_lotes_programados (periodo_facturacion, id_lote_envio DESC)
        INCLUDE (codigo_servicio, estado_lote, procesados, total_destinatarios, enviados, fallidos, omitidos, programado_para);
END;
GO

IF OBJECT_ID(N'dbo.msp_envio_lote_destinatarios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lote_destinatarios (
        id_lote_destinatario            INT IDENTITY(1,1) NOT NULL,
        id_lote_envio                   INT NOT NULL,
        id_arrendatario                 INT NOT NULL,
        nombre_arrendatario_snapshot    NVARCHAR(200) NOT NULL,
        rut_snapshot                    NVARCHAR(30) NULL,
        correo_principal_snapshot       NVARCHAR(200) NULL,
        correo_destino                  NVARCHAR(200) NOT NULL,
        estado_destinatario             TINYINT NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_estado DEFAULT (1),
        intentos                        INT NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_intentos DEFAULT (0),
        ultimo_error                    NVARCHAR(1000) NULL,
        enviado_at                      DATETIME2(0) NULL,
        created_at                      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_created DEFAULT (SYSDATETIME()),
        updated_at                      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_envio_lote_destinatarios PRIMARY KEY (id_lote_destinatario),
        CONSTRAINT FK_msp_envio_lote_destinatarios_lote
            FOREIGN KEY (id_lote_envio) REFERENCES dbo.msp_envio_lotes_programados (id_lote_envio),
        CONSTRAINT CK_msp_envio_lote_destinatarios_estado CHECK (estado_destinatario IN (1,2,3,4)),
        CONSTRAINT CK_msp_envio_lote_destinatarios_intentos CHECK (intentos >= 0),
        CONSTRAINT UQ_msp_envio_lote_destinatarios_lote_arr UNIQUE (id_lote_envio, id_arrendatario)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lote_destinatarios_pendientes'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lote_destinatarios')
)
BEGIN
    CREATE INDEX IX_msp_envio_lote_destinatarios_pendientes
        ON dbo.msp_envio_lote_destinatarios (id_lote_envio, estado_destinatario, id_lote_destinatario)
        INCLUDE (correo_destino, intentos);
END;
GO

IF OBJECT_ID(N'dbo.msp_envio_lote_documentos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lote_documentos (
        id_lote_destinatario  INT NOT NULL,
        id_documento_cobro    INT NOT NULL,
        created_at            DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_documentos_created DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_envio_lote_documentos PRIMARY KEY (id_lote_destinatario, id_documento_cobro),
        CONSTRAINT FK_msp_envio_lote_documentos_dest
            FOREIGN KEY (id_lote_destinatario) REFERENCES dbo.msp_envio_lote_destinatarios (id_lote_destinatario),
        CONSTRAINT FK_msp_envio_lote_documentos_doc
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lote_documentos_doc'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lote_documentos')
)
BEGIN
    CREATE INDEX IX_msp_envio_lote_documentos_doc
        ON dbo.msp_envio_lote_documentos (id_documento_cobro, id_lote_destinatario);
END;
GO
