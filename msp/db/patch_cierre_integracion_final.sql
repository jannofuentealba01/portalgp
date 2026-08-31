SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
IF OBJECT_ID(N'dbo.msp_reversiones_controladas',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_reversiones_controladas(
  id_reversion BIGINT IDENTITY(1,1) PRIMARY KEY,id_correccion INT NULL,id_contrato_arriendo INT NOT NULL,
  entidad NVARCHAR(40) NOT NULL,id_registro_origen BIGINT NOT NULL,motivo NVARCHAR(1000) NOT NULL,
  estado NVARCHAR(20) NOT NULL DEFAULT N'REGISTRADA',id_usuario INT NOT NULL,fecha_registro DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),
  CONSTRAINT CK_msp_rev_estado CHECK(estado IN(N'REGISTRADA',N'EJECUTADA',N'ANULADA'))
 );
 CREATE INDEX IX_msp_rev_contrato ON dbo.msp_reversiones_controladas(id_contrato_arriendo,fecha_registro DESC);
END;
IF OBJECT_ID(N'dbo.msp_liquidacion_documentos',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_liquidacion_documentos(
  id_documento_liquidacion BIGINT IDENTITY(1,1) PRIMARY KEY,id_contrato_arriendo INT NOT NULL,id_liquidacion_final INT NULL,
  numero_documento NVARCHAR(60) NOT NULL, deuda DECIMAL(18,2) NOT NULL, garantia_aplicada DECIMAL(18,2) NOT NULL,
  garantia_devuelta DECIMAL(18,2) NOT NULL,saldo_final DECIMAL(18,2) NOT NULL,ruta_archivo NVARCHAR(1000) NULL,
  fecha_emision DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),id_usuario INT NULL
 );
END;
IF OBJECT_ID(N'dbo.msp_vacancias_locales','U') IS NOT NULL AND COL_LENGTH('dbo.msp_vacancias_locales','id_contrato_reserva') IS NULL
 ALTER TABLE dbo.msp_vacancias_locales ADD id_contrato_reserva INT NULL;
GO
CREATE OR ALTER PROCEDURE dbo.msp_vacancia_reservar
 @id_vacancia INT,@id_contrato_arriendo INT,@id_usuario INT
AS
BEGIN
 SET NOCOUNT ON; SET XACT_ABORT ON; BEGIN TRAN;
 IF NOT EXISTS(SELECT 1 FROM dbo.msp_contratos_arriendo WHERE id_contrato_arriendo=@id_contrato_arriendo) THROW 52101,N'Contrato no encontrado.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.msp_vacancias_locales WITH(UPDLOCK,HOLDLOCK) WHERE id_vacancia=@id_vacancia AND estado=N'ABIERTA') THROW 52102,N'La vacancia no está disponible.',1;
 UPDATE dbo.msp_vacancias_locales SET estado=N'RESERVADA',id_contrato_reserva=@id_contrato_arriendo,id_usuario=@id_usuario WHERE id_vacancia=@id_vacancia;
 COMMIT; SELECT @id_vacancia id_vacancia,N'RESERVADA' estado;
END;
GO
CREATE OR ALTER PROCEDURE dbo.msp_vacancia_asignar
 @id_vacancia INT,@id_usuario INT
AS
BEGIN
 SET NOCOUNT ON;
 UPDATE dbo.msp_vacancias_locales SET estado=N'ASIGNADA',id_usuario=@id_usuario WHERE id_vacancia=@id_vacancia AND estado=N'RESERVADA';
 IF @@ROWCOUNT=0 THROW 52103,N'La vacancia no está reservada.',1;
 SELECT @id_vacancia id_vacancia,N'ASIGNADA' estado;
END;
GO
