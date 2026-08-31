/*
   MSP - Cierre financiero con deuda residual

   Permite cerrar un contrato después de aplicar/resolver la garantía y deja
   una constancia formal de la deuda que continúa en cobranza histórica.
   Los documentos y cargos originales no se eliminan ni se marcan como pagados.
*/
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
GO

/* El instalador histórico no siempre incluye este objeto; se deja idempotente
   para que el cierre pueda registrar la liquidación incluso en instalaciones
   antiguas. */
IF OBJECT_ID(N'dbo.msp_liquidaciones_finales', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_liquidaciones_finales (
        id_liquidacion_final INT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_msp_liquidaciones_finales_cierre PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,
        fecha_corte DATE NOT NULL,
        deuda DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_liq_cierre_deuda DEFAULT (0),
        garantia_disponible DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_liq_cierre_gar_disp DEFAULT (0),
        garantia_aplicada DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_liq_cierre_gar_apl DEFAULT (0),
        garantia_devuelta DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_liq_cierre_gar_dev DEFAULT (0),
        saldo_final DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_liq_cierre_saldo DEFAULT (0),
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_liq_cierre_estado DEFAULT (N'APROBADA'),
        observaciones NVARCHAR(1000) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_liq_cierre_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT CK_msp_liq_cierre_estado CHECK (estado IN (N'BORRADOR', N'APROBADA', N'ANULADA')),
        CONSTRAINT FK_msp_liq_cierre_contrato FOREIGN KEY (id_contrato_arriendo)
            REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo)
    );
    CREATE UNIQUE INDEX UX_msp_liq_cierre_contrato
        ON dbo.msp_liquidaciones_finales(id_contrato_arriendo);
END;
GO

IF OBJECT_ID(N'dbo.msp_deudas_historicas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_deudas_historicas (
        id_deuda_historica BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_msp_deudas_historicas PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,
        id_liquidacion_final INT NULL,
        periodo_corte DATE NOT NULL,
        fecha_termino_operativo DATE NULL,
        deuda_documental DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_doc DEFAULT (0),
        deuda_cargos DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_cargos DEFAULT (0),
        deuda_total DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_total DEFAULT (0),
        garantia_aplicada DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_gar_apl DEFAULT (0),
        garantia_disponible DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_gar_disp DEFAULT (0),
        garantia_devuelta DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_gar_dev DEFAULT (0),
        saldo_residual DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_deuda_hist_saldo DEFAULT (0),
        estado_deuda NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_deuda_hist_estado DEFAULT (N'ACTIVA'),
        motivo NVARCHAR(1000) NULL,
        id_usuario INT NULL,
        fecha_derivacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_deuda_hist_fecha DEFAULT (SYSDATETIME()),
        fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_deuda_hist_actualiza DEFAULT (SYSDATETIME()),
        CONSTRAINT FK_msp_deuda_hist_contrato FOREIGN KEY (id_contrato_arriendo)
            REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT CK_msp_deuda_hist_estado CHECK (estado_deuda IN (N'ACTIVA', N'SALDADA', N'ANULADA')),
        CONSTRAINT CK_msp_deuda_hist_montos CHECK (
            deuda_documental >= 0 AND deuda_cargos >= 0 AND deuda_total >= 0
            AND garantia_aplicada >= 0 AND garantia_disponible >= 0
            AND garantia_devuelta >= 0 AND saldo_residual >= 0
        )
    );
    CREATE INDEX IX_msp_deudas_historicas_contrato
        ON dbo.msp_deudas_historicas(id_contrato_arriendo, fecha_derivacion DESC);
    CREATE INDEX IX_msp_deudas_historicas_estado
        ON dbo.msp_deudas_historicas(estado_deuda, saldo_residual DESC);
    CREATE UNIQUE INDEX UX_msp_deudas_historicas_contrato_activa
        ON dbo.msp_deudas_historicas(id_contrato_arriendo)
        WHERE estado_deuda = N'ACTIVA';
END;
GO

PRINT N'Patch de cierre con deuda histórica instalado.';
GO
