/*
===========================================================================
 PATCH: pool operacional de documentos por periodo
 - Crea tabla base msp_pool_documentos_periodo.
 - Vincula msp_documentos_cobro con id_pool_documento (nullable, incremental).
===========================================================================
*/

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pool_documentos_periodo (
        id_pool_documento        INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion      DATE NOT NULL,
        id_tienda                INT NOT NULL,
        id_contrato_arriendo     INT NOT NULL,
        estado_pool              TINYINT NOT NULL CONSTRAINT DF_msp_pool_doc_estado DEFAULT (1),
        perfil_servicios         NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_pool_doc_perfil DEFAULT (N'LUZ'),

        requiere_luz             BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_luz DEFAULT (0),
        requiere_gas             BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_gas DEFAULT (0),
        requiere_agua            BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_agua DEFAULT (0),

        tiene_luz                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_luz DEFAULT (0),
        tiene_gas                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_gas DEFAULT (0),
        tiene_agua               BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_agua DEFAULT (0),

        ready_luz                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_luz DEFAULT (0),
        ready_gas                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_gas DEFAULT (0),
        ready_agua               BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_agua DEFAULT (0),

        id_documento_cobro       INT NULL,
        id_lote_envio_ultimo     INT NULL,
        saldo_aplicado_total     DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pool_doc_saldo DEFAULT (0),
        motivo_pendiente         NVARCHAR(500) NULL,

        created_at               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pool_doc_created DEFAULT (SYSDATETIME()),
        updated_at               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pool_doc_updated DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_pool_documentos_periodo PRIMARY KEY (id_pool_documento),
        CONSTRAINT UQ_msp_pool_documentos_periodo UNIQUE (periodo_facturacion, id_tienda, id_contrato_arriendo),
        CONSTRAINT CK_msp_pool_doc_periodo CHECK (DAY(periodo_facturacion) = 1),
        CONSTRAINT CK_msp_pool_doc_estado CHECK (estado_pool IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_pool_doc_perfil CHECK (perfil_servicios IN (N'LUZ', N'LUZ_GAS', N'LUZ_AGUA', N'LUZ_GAS_AGUA')),
        CONSTRAINT FK_msp_pool_doc_tienda FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_pool_doc_contrato FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT FK_msp_pool_doc_documento FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pool_doc_periodo_estado'
      AND object_id = OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pool_doc_periodo_estado
        ON dbo.msp_pool_documentos_periodo (periodo_facturacion, estado_pool)
        INCLUDE (id_tienda, id_documento_cobro, ready_luz, ready_gas, ready_agua, id_lote_envio_ultimo);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pool_doc_periodo_etapas'
      AND object_id = OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pool_doc_periodo_etapas
        ON dbo.msp_pool_documentos_periodo (periodo_facturacion, ready_luz, ready_gas, ready_agua)
        INCLUDE (id_tienda, id_documento_cobro, estado_pool, id_lote_envio_ultimo);
END;
GO

IF COL_LENGTH('dbo.msp_documentos_cobro', 'id_pool_documento') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD id_pool_documento INT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = N'FK_msp_documentos_cobro_pool_documento'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro WITH CHECK
    ADD CONSTRAINT FK_msp_documentos_cobro_pool_documento
        FOREIGN KEY (id_pool_documento)
        REFERENCES dbo.msp_pool_documentos_periodo (id_pool_documento);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_documentos_cobro_pool_documento'
      AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
)
BEGIN
    CREATE INDEX IX_msp_documentos_cobro_pool_documento
        ON dbo.msp_documentos_cobro (id_pool_documento)
        WHERE id_pool_documento IS NOT NULL;
END;
GO

PRINT 'Patch pool documentos por periodo aplicado.';
GO
