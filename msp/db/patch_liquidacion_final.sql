SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
IF OBJECT_ID(N'dbo.msp_liquidaciones_finales',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_liquidaciones_finales(
  id_liquidacion_final INT IDENTITY(1,1) CONSTRAINT PK_msp_liquidacion_final PRIMARY KEY,
  id_contrato_arriendo INT NOT NULL,
  fecha_corte DATE NOT NULL,
  deuda DECIMAL(18,2) NOT NULL DEFAULT 0,
  garantia_disponible DECIMAL(18,2) NOT NULL DEFAULT 0,
  garantia_aplicada DECIMAL(18,2) NOT NULL DEFAULT 0,
  garantia_devuelta DECIMAL(18,2) NOT NULL DEFAULT 0,
  saldo_final DECIMAL(18,2) NOT NULL DEFAULT 0,
  estado NVARCHAR(20) NOT NULL DEFAULT N'BORRADOR',
  observaciones NVARCHAR(1000) NULL,
  id_usuario INT NULL,
  fecha_registro DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),
  CONSTRAINT CK_msp_liq_estado CHECK(estado IN(N'BORRADOR',N'APROBADA',N'ANULADA'))
 );
 CREATE UNIQUE INDEX UX_msp_liq_contrato ON dbo.msp_liquidaciones_finales(id_contrato_arriendo);
END;
GO
