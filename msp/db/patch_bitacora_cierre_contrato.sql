/* =========================================================================
   PATCH: BITACORA DE CIERRE DE CONTRATOS MSP
   Fecha: 2026-03-18
   Crea tabla dbo.msp_bitacora_cierre_contrato si no existe.
   ========================================================================= */

IF OBJECT_ID('dbo.msp_bitacora_cierre_contrato', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_bitacora_cierre_contrato (
        id_bitacora_cierre_contrato INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo        INT NOT NULL,
        id_usuario                  INT NOT NULL,
        estado_contrato_anterior    TINYINT NOT NULL,
        estado_contrato_nuevo       TINYINT NOT NULL,
        motivo_cierre               NVARCHAR(500) NOT NULL,
        fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bitacora_cierre_contrato_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_bitacora_cierre_contrato PRIMARY KEY (id_bitacora_cierre_contrato),
        CONSTRAINT FK_msp_bitacora_cierre_contrato_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_bitacora_cierre_contrato_estados
            CHECK (estado_contrato_anterior IN (1,2,3,4,5) AND estado_contrato_nuevo IN (1,2,3,4,5))
    );

    CREATE INDEX IX_msp_bitacora_cierre_contrato_contrato_fecha
        ON dbo.msp_bitacora_cierre_contrato (id_contrato_arriendo, fecha_registro DESC);
END;
GO
