SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
IF COL_LENGTH('dbo.msp_correcciones','estrategia_ejecucion') IS NULL ALTER TABLE dbo.msp_correcciones ADD estrategia_ejecucion NVARCHAR(50) NULL;
IF COL_LENGTH('dbo.msp_correcciones','payload_ejecucion') IS NULL ALTER TABLE dbo.msp_correcciones ADD payload_ejecucion NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.msp_correcciones','resultado_ejecucion') IS NULL ALTER TABLE dbo.msp_correcciones ADD resultado_ejecucion NVARCHAR(MAX) NULL;
IF COL_LENGTH('dbo.msp_correcciones','fecha_analisis') IS NULL ALTER TABLE dbo.msp_correcciones ADD fecha_analisis DATETIME2(0) NULL;
IF COL_LENGTH('dbo.msp_correcciones','hash_precondicion') IS NULL ALTER TABLE dbo.msp_correcciones ADD hash_precondicion NVARCHAR(128) NULL;
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE name=N'CK_msp_correcciones_estado_v2')
 ALTER TABLE dbo.msp_correcciones WITH NOCHECK ADD CONSTRAINT CK_msp_correcciones_estado_v2 CHECK(estado_correccion IN(N'BORRADOR',N'ANALIZADA',N'PENDIENTE_APROBACION',N'APROBADA',N'EJECUTANDO',N'EJECUTADA',N'ERROR',N'RECHAZADA',N'CANCELADA'));
IF OBJECT_ID(N'dbo.msp_correcciones_impactos',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_correcciones_impactos(id_impacto BIGINT IDENTITY(1,1) PRIMARY KEY,id_correccion INT NOT NULL,tipo_entidad NVARCHAR(50) NOT NULL,id_registro BIGINT NULL,accion_prevista NVARCHAR(50) NOT NULL,valor_anterior NVARCHAR(MAX) NULL,valor_nuevo NVARCHAR(MAX) NULL,es_financiero BIT NOT NULL DEFAULT 0,fecha_registro DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),CONSTRAINT FK_msp_corr_impacto_corr FOREIGN KEY(id_correccion) REFERENCES dbo.msp_correcciones(id_correccion));
 CREATE INDEX IX_msp_corr_impacto_corr ON dbo.msp_correcciones_impactos(id_correccion,fecha_registro);
END;
IF OBJECT_ID(N'dbo.msp_arriendo_ajustes_periodo',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_arriendo_ajustes_periodo(id_ajuste_arriendo BIGINT IDENTITY(1,1) PRIMARY KEY,id_contrato_local INT NOT NULL,periodo_facturacion DATE NOT NULL,monto_correcto_clp DECIMAL(18,2) NOT NULL,motivo NVARCHAR(500) NOT NULL,id_correccion INT NOT NULL,estado_ajuste BIT NOT NULL DEFAULT 1,usuario_registro INT NOT NULL,fecha_registro DATETIME2(0) NOT NULL DEFAULT SYSDATETIME(),CONSTRAINT FK_msp_arriendo_aj_corr FOREIGN KEY(id_correccion) REFERENCES dbo.msp_correcciones(id_correccion));
 CREATE UNIQUE INDEX UX_msp_arriendo_ajuste_activo ON dbo.msp_arriendo_ajustes_periodo(id_contrato_local,periodo_facturacion) WHERE estado_ajuste=1;
END;
GO
