SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_conciliaciones',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_tesoreria_conciliaciones(
  id_conciliacion_tesoreria INT IDENTITY(1,1) NOT NULL,id_cuenta_tesoreria INT NOT NULL,
  fecha_desde DATE NOT NULL,fecha_hasta DATE NOT NULL,saldo_sistema DECIMAL(18,2) NOT NULL,saldo_cartola DECIMAL(18,2) NOT NULL,
  diferencia DECIMAL(18,2) NOT NULL,estado_conciliacion NVARCHAR(20) NOT NULL,observaciones NVARCHAR(500) NULL,
  id_usuario INT NULL,fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_conc_fecha DEFAULT(SYSDATETIME()),
  CONSTRAINT PK_msp_tesoreria_conciliaciones PRIMARY KEY(id_conciliacion_tesoreria),
  CONSTRAINT FK_msp_tesoreria_conc_cuenta FOREIGN KEY(id_cuenta_tesoreria) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
  CONSTRAINT CK_msp_tesoreria_conc_fechas CHECK(fecha_desde<=fecha_hasta),
  CONSTRAINT CK_msp_tesoreria_conc_estado CHECK(estado_conciliacion IN(N'CUADRADA',N'PENDIENTE'))
 );
 CREATE INDEX IX_msp_tesoreria_conc_cuenta_fecha ON dbo.msp_tesoreria_conciliaciones(id_cuenta_tesoreria,fecha_hasta DESC,id_conciliacion_tesoreria DESC);
END;
GO
IF OBJECT_ID(N'dbo.msp_tesoreria_conciliacion_items',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_tesoreria_conciliacion_items(
  id_conciliacion_item INT IDENTITY(1,1) NOT NULL,id_conciliacion_tesoreria INT NOT NULL,id_movimiento_tesoreria INT NOT NULL,
  monto_firmado DECIMAL(18,2) NOT NULL,fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_conc_item_fecha DEFAULT(SYSDATETIME()),
  CONSTRAINT PK_msp_tesoreria_conciliacion_items PRIMARY KEY(id_conciliacion_item),
  CONSTRAINT FK_msp_tesoreria_conc_item_cab FOREIGN KEY(id_conciliacion_tesoreria) REFERENCES dbo.msp_tesoreria_conciliaciones(id_conciliacion_tesoreria),
  CONSTRAINT FK_msp_tesoreria_conc_item_mov FOREIGN KEY(id_movimiento_tesoreria) REFERENCES dbo.msp_tesoreria_movimientos(id_movimiento_tesoreria),
  CONSTRAINT UQ_msp_tesoreria_conc_item UNIQUE(id_conciliacion_tesoreria,id_movimiento_tesoreria)
 );
END;
GO
IF COL_LENGTH('dbo.msp_tesoreria_movimientos','id_conciliacion_tesoreria') IS NULL
 ALTER TABLE dbo.msp_tesoreria_movimientos ADD id_conciliacion_tesoreria INT NULL;
GO
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_tesoreria_mov_conciliacion')
 ALTER TABLE dbo.msp_tesoreria_movimientos ADD CONSTRAINT FK_msp_tesoreria_mov_conciliacion FOREIGN KEY(id_conciliacion_tesoreria) REFERENCES dbo.msp_tesoreria_conciliaciones(id_conciliacion_tesoreria);
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_cierres_caja',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_tesoreria_cierres_caja(
  id_cierre_caja INT IDENTITY(1,1) NOT NULL,id_cuenta_tesoreria INT NOT NULL,fecha_cierre DATE NOT NULL,
  saldo_apertura DECIMAL(18,2) NOT NULL,total_entradas DECIMAL(18,2) NOT NULL,total_salidas DECIMAL(18,2) NOT NULL,
  saldo_sistema DECIMAL(18,2) NOT NULL,efectivo_contado DECIMAL(18,2) NOT NULL,diferencia DECIMAL(18,2) NOT NULL,
  estado_cierre NVARCHAR(25) NOT NULL,observaciones NVARCHAR(500) NULL,id_usuario INT NULL,
  fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_cierre_fecha DEFAULT(SYSDATETIME()),
  CONSTRAINT PK_msp_tesoreria_cierres_caja PRIMARY KEY(id_cierre_caja),
  CONSTRAINT FK_msp_tesoreria_cierre_cuenta FOREIGN KEY(id_cuenta_tesoreria) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
  CONSTRAINT UQ_msp_tesoreria_cierre_cuenta_fecha UNIQUE(id_cuenta_tesoreria,fecha_cierre),
  CONSTRAINT CK_msp_tesoreria_cierre_contado CHECK(efectivo_contado>=0),
  CONSTRAINT CK_msp_tesoreria_cierre_estado CHECK(estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA'))
 );
 CREATE INDEX IX_msp_tesoreria_cierre_fecha ON dbo.msp_tesoreria_cierres_caja(fecha_cierre DESC,id_cierre_caja DESC);
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_conciliar_banco
 @id_cuenta_tesoreria INT,@fecha_desde DATE,@fecha_hasta DATE,@saldo_cartola DECIMAL(18,2),
 @movimientos_json NVARCHAR(MAX),@observaciones NVARCHAR(500)=NULL,@id_usuario INT=NULL
AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;
 IF @fecha_desde IS NULL OR @fecha_hasta IS NULL OR @fecha_desde>@fecha_hasta THROW 53301,N'El rango de conciliación no es válido.',1;
 IF ISJSON(@movimientos_json)<>1 THROW 53302,N'La selección de movimientos no es válida.',1;
 BEGIN TRANSACTION;
 BEGIN TRY
  IF NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND tipo_cuenta=N'BANCO' AND activo=1) THROW 53303,N'La cuenta bancaria no existe o está inactiva.',1;
  DECLARE @ids TABLE(id INT PRIMARY KEY);INSERT @ids SELECT DISTINCT TRY_CONVERT(INT,[value]) FROM OPENJSON(@movimientos_json) WHERE TRY_CONVERT(INT,[value])>0;
  IF EXISTS(SELECT 1 FROM @ids i LEFT JOIN dbo.msp_tesoreria_movimientos m ON m.id_movimiento_tesoreria=i.id WHERE m.id_movimiento_tesoreria IS NULL OR m.id_cuenta_tesoreria<>@id_cuenta_tesoreria OR m.fecha_movimiento<@fecha_desde OR m.fecha_movimiento>@fecha_hasta OR m.estado_movimiento<>N'VIGENTE' OR m.conciliado=1) THROW 53304,N'Uno o más movimientos no pueden conciliarse.',1;
  DECLARE @saldo_sistema DECIMAL(18,2),@diferencia DECIMAL(18,2),@estado NVARCHAR(20),@id_conc INT;
  SELECT @saldo_sistema=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto ELSE 0 END),0) AS DECIMAL(18,2)) FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_movimiento<=@fecha_hasta;
  SET @diferencia=ROUND(@saldo_cartola-@saldo_sistema,2);SET @estado=CASE WHEN ABS(@diferencia)<=0.01 THEN N'CUADRADA' ELSE N'PENDIENTE' END;
  INSERT dbo.msp_tesoreria_conciliaciones(id_cuenta_tesoreria,fecha_desde,fecha_hasta,saldo_sistema,saldo_cartola,diferencia,estado_conciliacion,observaciones,id_usuario)
  VALUES(@id_cuenta_tesoreria,@fecha_desde,@fecha_hasta,@saldo_sistema,@saldo_cartola,@diferencia,@estado,@observaciones,@id_usuario);SET @id_conc=CAST(SCOPE_IDENTITY() AS INT);
  INSERT dbo.msp_tesoreria_conciliacion_items(id_conciliacion_tesoreria,id_movimiento_tesoreria,monto_firmado)
  SELECT @id_conc,m.id_movimiento_tesoreria,CASE WHEN m.naturaleza='E' THEN m.monto ELSE -m.monto END FROM dbo.msp_tesoreria_movimientos m JOIN @ids i ON i.id=m.id_movimiento_tesoreria;
  IF @estado=N'CUADRADA' UPDATE m SET conciliado=1,fecha_conciliacion=@fecha_hasta,id_conciliacion_tesoreria=@id_conc FROM dbo.msp_tesoreria_movimientos m JOIN @ids i ON i.id=m.id_movimiento_tesoreria;
  COMMIT TRANSACTION;SELECT @id_conc id_conciliacion_tesoreria,@saldo_sistema saldo_sistema,@diferencia diferencia,@estado estado_conciliacion;
 END TRY BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK TRANSACTION;THROW;END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_cerrar_caja
 @id_cuenta_tesoreria INT,@fecha_cierre DATE,@efectivo_contado DECIMAL(18,2),@observaciones NVARCHAR(500)=NULL,@id_usuario INT=NULL
AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;
 IF @fecha_cierre IS NULL OR ISNULL(@efectivo_contado,-1)<0 THROW 53311,N'La fecha o el efectivo contado no son válidos.',1;
 BEGIN TRANSACTION;
 BEGIN TRY
  IF NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND tipo_cuenta=N'CAJA' AND activo=1) THROW 53312,N'La caja no existe o está inactiva.',1;
  IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre=@fecha_cierre) THROW 53313,N'La caja ya fue cerrada para esa fecha.',1;
  DECLARE @apertura DECIMAL(18,2),@entradas DECIMAL(18,2),@salidas DECIMAL(18,2),@sistema DECIMAL(18,2),@dif DECIMAL(18,2),@estado NVARCHAR(25),@id INT;
  SELECT @apertura=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto ELSE 0 END),0) AS DECIMAL(18,2)) FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_movimiento<@fecha_cierre;
  SELECT @entradas=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto ELSE 0 END),0) AS DECIMAL(18,2)),@salidas=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN monto ELSE 0 END),0) AS DECIMAL(18,2)) FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_movimiento=@fecha_cierre;
  SET @sistema=@apertura+@entradas-@salidas;SET @dif=ROUND(@efectivo_contado-@sistema,2);SET @estado=CASE WHEN ABS(@dif)<=0.01 THEN N'CUADRADO' ELSE N'CON_DIFERENCIA' END;
  INSERT dbo.msp_tesoreria_cierres_caja(id_cuenta_tesoreria,fecha_cierre,saldo_apertura,total_entradas,total_salidas,saldo_sistema,efectivo_contado,diferencia,estado_cierre,observaciones,id_usuario)
  VALUES(@id_cuenta_tesoreria,@fecha_cierre,@apertura,@entradas,@salidas,@sistema,@efectivo_contado,@dif,@estado,@observaciones,@id_usuario);SET @id=CAST(SCOPE_IDENTITY() AS INT);
  COMMIT TRANSACTION;SELECT @id id_cierre_caja,@sistema saldo_sistema,@dif diferencia,@estado estado_cierre;
 END TRY BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK TRANSACTION;THROW;END CATCH;
END;
GO
PRINT N'Conciliación bancaria y cierre diario de caja instalados.';
GO
