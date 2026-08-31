/*
===========================================================================
 PATCH: EVENTOS CANONICOS DOCUMENTOS DE COBRO
 - Tabla de ledger de eventos para trazabilidad operacional y contable.
 - Idempotente para SQL Server / esquema dbo.
===========================================================================
*/

SET NOCOUNT ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_documentos_cobro_eventos (
        id_documento_cobro_evento INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo INT NOT NULL,
        id_documento_cobro INT NULL,
        id_asiento_contable INT NULL,
        id_usuario INT NULL,
        tipo_evento NVARCHAR(40) NOT NULL,
        origen_evento NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_doc_eventos_origen DEFAULT (N'DOCUMENTO'),
        titulo_evento NVARCHAR(200) NULL,
        detalle_evento NVARCHAR(MAX) NULL,
        payload_json NVARCHAR(MAX) NULL,
        es_evento_derivado BIT NOT NULL CONSTRAINT DF_msp_doc_eventos_derivado DEFAULT (0),
        fecha_evento DATETIME2(0) NOT NULL CONSTRAINT DF_msp_doc_eventos_fecha DEFAULT (SYSDATETIME()),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_doc_eventos_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_documentos_cobro_eventos PRIMARY KEY (id_documento_cobro_evento),
        CONSTRAINT FK_msp_doc_eventos_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_doc_eventos_tipo CHECK (
            UPPER(tipo_evento) IN (
                N'EMISION',
                N'RECALCULO',
                N'REGENERACION',
                N'CONDONACION',
                N'CONDONACION_CARGO',
                N'ENVIO_PROGRAMADO',
                N'ENVIO_RESULTADO',
                N'PAGO_APLICADO',
                N'PAGO_ANULADO',
                N'AJUSTE',
                N'ASIENTO_CONTABLE'
            )
        ),
        CONSTRAINT CK_msp_doc_eventos_origen CHECK (
            UPPER(origen_evento) IN (
                N'CONTRATO',
                N'DOCUMENTO',
                N'PAGO',
                N'CARGO',
                N'ENVIO',
                N'CONTABLE',
                N'SISTEMA'
            )
        )
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'id_contrato_arriendo') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD id_contrato_arriendo INT NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'id_documento_cobro') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD id_documento_cobro INT NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'id_asiento_contable') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD id_asiento_contable INT NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'id_usuario') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD id_usuario INT NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'tipo_evento') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD tipo_evento NVARCHAR(40) NOT NULL CONSTRAINT DF_msp_doc_eventos_tipo DEFAULT (N'EMISION');
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'origen_evento') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD origen_evento NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_doc_eventos_origen_legacy DEFAULT (N'DOCUMENTO');
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'titulo_evento') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD titulo_evento NVARCHAR(200) NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'detalle_evento') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD detalle_evento NVARCHAR(MAX) NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'payload_json') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD payload_json NVARCHAR(MAX) NULL;
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'es_evento_derivado') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD es_evento_derivado BIT NOT NULL CONSTRAINT DF_msp_doc_eventos_derivado_legacy DEFAULT (0);
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'fecha_evento') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD fecha_evento DATETIME2(0) NOT NULL CONSTRAINT DF_msp_doc_eventos_fecha_legacy DEFAULT (SYSDATETIME());
    END;

    IF COL_LENGTH('dbo.msp_documentos_cobro_eventos', 'fecha_registro') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos
        ADD fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_doc_eventos_registro_legacy DEFAULT (SYSDATETIME());
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE name = N'FK_msp_doc_eventos_documento'
          AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U')
    )
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos WITH CHECK
        ADD CONSTRAINT FK_msp_doc_eventos_documento
            FOREIGN KEY (id_documento_cobro)
            REFERENCES dbo.msp_documentos_cobro (id_documento_cobro);
    END;

    IF OBJECT_ID(N'dbo.msp_acc_asientos', N'U') IS NOT NULL
       AND NOT EXISTS (
            SELECT 1
            FROM sys.foreign_keys
            WHERE name = N'FK_msp_doc_eventos_asiento'
              AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U')
       )
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro_eventos WITH CHECK
        ADD CONSTRAINT FK_msp_doc_eventos_asiento
            FOREIGN KEY (id_asiento_contable)
            REFERENCES dbo.msp_acc_asientos (id_asiento_contable);
    END;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_doc_eventos_contrato_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_doc_eventos_contrato_fecha
        ON dbo.msp_documentos_cobro_eventos (id_contrato_arriendo, fecha_evento DESC, id_documento_cobro_evento DESC)
        INCLUDE (tipo_evento, origen_evento, id_documento_cobro, id_asiento_contable, id_usuario);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_doc_eventos_documento_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_doc_eventos_documento_fecha
        ON dbo.msp_documentos_cobro_eventos (id_documento_cobro, fecha_evento DESC, id_documento_cobro_evento DESC)
        INCLUDE (tipo_evento, origen_evento, id_contrato_arriendo, id_asiento_contable);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_doc_eventos_asiento'
      AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_doc_eventos_asiento
        ON dbo.msp_documentos_cobro_eventos (id_asiento_contable)
        WHERE id_asiento_contable IS NOT NULL;
END;
GO

PRINT 'Patch documentos_cobro_eventos aplicado.';
GO
