/*
===============================================================================
 PATCH: Pago contrato operacion general (cabecera + detalle)
 - Permite guardar el pago "real" ingresado en pago por contrato.
 - Mantiene desglose por documento sin reemplazar msp_pagos.
===============================================================================
*/

IF OBJECT_ID(N'dbo.msp_pago_contrato_operaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pago_contrato_operaciones (
        id_pago_contrato_operacion INT IDENTITY(1,1) NOT NULL,
        id_arrendatario            INT NOT NULL,
        id_contrato_arriendo       INT NOT NULL,
        fecha_pago                 DATE NOT NULL,
        monto_total_pagado         DECIMAL(18,2) NOT NULL,
        monto_total_aplicado       DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pco_total_aplicado DEFAULT (0),
        monto_total_excedente      DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pco_total_excedente DEFAULT (0),
        monto_total_no_imputado    DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pco_total_no_imputado DEFAULT (0),
        total_documentos           INT NOT NULL CONSTRAINT DF_msp_pco_total_documentos DEFAULT (0),
        medio_pago                 NVARCHAR(50) NULL,
        referencia_pago            NVARCHAR(100) NULL,
        referencia_operacion       NVARCHAR(100) NULL,
        observaciones              NVARCHAR(500) NULL,
        estado_operacion           TINYINT NOT NULL CONSTRAINT DF_msp_pco_estado DEFAULT (1),
        id_usuario                 INT NULL,
        fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pco_fecha_registro DEFAULT (SYSDATETIME()),
        updated_at                 DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pco_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_pago_contrato_operaciones PRIMARY KEY (id_pago_contrato_operacion),
        CONSTRAINT FK_msp_pco_arrendatario
            FOREIGN KEY (id_arrendatario) REFERENCES dbo.msp_arrendatarios (id_arrendatario),
        CONSTRAINT FK_msp_pco_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_pco_montos CHECK (
            monto_total_pagado > 0
            AND monto_total_aplicado >= 0
            AND monto_total_excedente >= 0
            AND monto_total_no_imputado >= 0
            AND monto_total_aplicado + monto_total_excedente + monto_total_no_imputado <= monto_total_pagado + 0.01
        ),
        CONSTRAINT CK_msp_pco_estado CHECK (estado_operacion IN (1,2)),
        CONSTRAINT CK_msp_pco_total_documentos CHECK (total_documentos >= 0)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_pago_contrato_operacion_detalle', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pago_contrato_operacion_detalle (
        id_pago_contrato_operacion_detalle INT IDENTITY(1,1) NOT NULL,
        id_pago_contrato_operacion         INT NOT NULL,
        orden_aplicacion                   INT NOT NULL,
        id_pago                            INT NOT NULL,
        id_documento_cobro                 INT NOT NULL,
        saldo_pendiente_original           DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pcod_saldo_original DEFAULT (0),
        monto_intentado                    DECIMAL(18,2) NOT NULL,
        monto_aplicado                     DECIMAL(18,2) NOT NULL,
        monto_excedente                    DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pcod_excedente DEFAULT (0),
        monto_consumido                    DECIMAL(18,2) NOT NULL,
        monto_restante_luego               DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pcod_restante DEFAULT (0),
        fecha_registro                     DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pcod_fecha_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_pago_contrato_operacion_detalle PRIMARY KEY (id_pago_contrato_operacion_detalle),
        CONSTRAINT FK_msp_pcod_operacion
            FOREIGN KEY (id_pago_contrato_operacion) REFERENCES dbo.msp_pago_contrato_operaciones (id_pago_contrato_operacion),
        CONSTRAINT FK_msp_pcod_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT FK_msp_pcod_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT CK_msp_pcod_orden CHECK (orden_aplicacion > 0),
        CONSTRAINT CK_msp_pcod_montos CHECK (
            saldo_pendiente_original >= 0
            AND monto_intentado > 0
            AND monto_aplicado >= 0
            AND monto_excedente >= 0
            AND monto_consumido >= 0
            AND monto_restante_luego >= 0
            AND monto_aplicado + monto_excedente <= monto_intentado + 0.01
            AND monto_consumido <= monto_intentado + 0.01
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pco_contrato_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_operaciones', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pco_contrato_fecha
        ON dbo.msp_pago_contrato_operaciones (id_contrato_arriendo, fecha_pago DESC, id_pago_contrato_operacion DESC)
        INCLUDE (monto_total_pagado, monto_total_aplicado, monto_total_excedente, monto_total_no_imputado, referencia_operacion);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pco_referencia_operacion'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_operaciones', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pco_referencia_operacion
        ON dbo.msp_pago_contrato_operaciones (referencia_operacion, id_pago_contrato_operacion DESC);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UX_msp_pcod_operacion_orden'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_operacion_detalle', N'U')
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_pcod_operacion_orden
        ON dbo.msp_pago_contrato_operacion_detalle (id_pago_contrato_operacion, orden_aplicacion);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pcod_pago'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_operacion_detalle', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pcod_pago
        ON dbo.msp_pago_contrato_operacion_detalle (id_pago, id_documento_cobro);
END;
GO

PRINT 'Patch pago contrato operacion general aplicado.';
