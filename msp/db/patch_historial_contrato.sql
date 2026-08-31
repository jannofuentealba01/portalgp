/* =========================================================================
   PATCH: HISTORIAL DE CAMBIOS DE CONTRATO MSP
   Fecha: 2026-03-23
   Crea tabla dbo.msp_historial_contrato si no existe.
   ========================================================================= */

IF OBJECT_ID('dbo.msp_historial_contrato', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_historial_contrato (
        id_historial_contrato      INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo       INT NOT NULL,
        tipo_evento                NVARCHAR(30) NOT NULL,
        id_usuario                 INT NOT NULL,
        detalle_evento             NVARCHAR(MAX) NULL,
        motivo_evento              NVARCHAR(500) NULL,
        fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_historial_contrato_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_historial_contrato PRIMARY KEY (id_historial_contrato),
        CONSTRAINT FK_msp_historial_contrato_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_historial_contrato_tipo
            CHECK (tipo_evento IN (N'CREACION', N'ACTUALIZACION', N'CIERRE'))
    );

    CREATE INDEX IX_msp_historial_contrato_contrato_fecha
        ON dbo.msp_historial_contrato (id_contrato_arriendo, fecha_registro DESC);
END;
GO
