SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
IF OBJECT_ID(N'dbo.msp_vacancias_locales',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_vacancias_locales(
  id_vacancia INT IDENTITY(1,1) CONSTRAINT PK_msp_vacancias PRIMARY KEY,
  id_local INT NOT NULL, fecha_inicio DATE NOT NULL, fecha_fin DATE NULL,
  motivo NVARCHAR(500) NULL, estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_vac_estado DEFAULT N'ABIERTA',
  observaciones NVARCHAR(1000) NULL, id_usuario INT NULL, fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_vac_reg DEFAULT SYSDATETIME(),
  CONSTRAINT CK_msp_vac_estado CHECK(estado IN(N'ABIERTA',N'RESERVADA',N'ASIGNADA',N'CERRADA')),
  CONSTRAINT CK_msp_vac_fechas CHECK(fecha_fin IS NULL OR fecha_fin>=fecha_inicio)
 );
 CREATE INDEX IX_msp_vac_local_estado ON dbo.msp_vacancias_locales(id_local,estado,fecha_inicio);
END;
IF OBJECT_ID(N'dbo.msp_centro_documental',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_centro_documental(
  id_documento INT IDENTITY(1,1) CONSTRAINT PK_msp_centro_doc PRIMARY KEY,
  id_contrato_arriendo INT NULL, id_arrendatario INT NULL, id_local INT NULL,
  nombre_archivo NVARCHAR(255) NOT NULL, ruta_archivo NVARCHAR(1000) NOT NULL,
  tipo_documento NVARCHAR(50) NOT NULL, descripcion NVARCHAR(500) NULL,
  estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cdoc_estado DEFAULT N'ACTIVO',
  id_usuario INT NULL, fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cdoc_reg DEFAULT SYSDATETIME(),
  CONSTRAINT CK_msp_cdoc_estado CHECK(estado IN(N'ACTIVO',N'ANULADO'))
 );
 CREATE INDEX IX_msp_cdoc_contexto ON dbo.msp_centro_documental(id_contrato_arriendo,id_arrendatario,id_local,estado);
END;
GO
CREATE OR ALTER VIEW dbo.msp_vw_vacancia_locales AS
SELECT v.id_vacancia,v.id_local,l.cdo_local,l.desc_local,v.fecha_inicio,v.fecha_fin,v.motivo,v.estado,v.observaciones
FROM dbo.msp_vacancias_locales v JOIN dbo.msp_locales l ON l.id_local=v.id_local;
GO
CREATE OR ALTER PROCEDURE dbo.msp_vacancia_abrir
 @id_local INT,@fecha_inicio DATE,@motivo NVARCHAR(500)=NULL,@id_usuario INT=NULL
AS
BEGIN
 SET NOCOUNT ON;
 IF NOT EXISTS(SELECT 1 FROM dbo.msp_locales WHERE id_local=@id_local) THROW 52001,'Local no encontrado.',1;
 IF EXISTS(SELECT 1 FROM dbo.msp_vacancias_locales WHERE id_local=@id_local AND estado IN(N'ABIERTA',N'RESERVADA') AND (fecha_fin IS NULL OR fecha_fin>=@fecha_inicio)) THROW 52002,'El local ya tiene una vacancia activa.',1;
 INSERT dbo.msp_vacancias_locales(id_local,fecha_inicio,motivo,id_usuario) VALUES(@id_local,@fecha_inicio,@motivo,@id_usuario);
 SELECT CAST(SCOPE_IDENTITY() AS INT) id_vacancia;
END;
GO
