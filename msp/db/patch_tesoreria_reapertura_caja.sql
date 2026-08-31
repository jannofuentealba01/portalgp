SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_bitacora_reapertura_caja',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tesoreria_bitacora_reapertura_caja(
        id_reapertura_caja BIGINT IDENTITY(1,1) NOT NULL,
        id_cierre_caja INT NOT NULL,
        id_cuenta_tesoreria INT NOT NULL,
        fecha_cierre DATE NOT NULL,
        estado_anterior NVARCHAR(25) NOT NULL,
        estado_nuevo NVARCHAR(25) NOT NULL CONSTRAINT DF_msp_tes_reap_estado DEFAULT(N'REABIERTA'),
        motivo NVARCHAR(1000) NOT NULL,
        id_usuario_solicita INT NOT NULL,
        id_usuario_autoriza INT NOT NULL,
        fecha_reapertura DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tes_reap_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_tes_reapertura PRIMARY KEY(id_reapertura_caja),
        CONSTRAINT FK_msp_tes_reap_cierre FOREIGN KEY(id_cierre_caja) REFERENCES dbo.msp_tesoreria_cierres_caja(id_cierre_caja),
        CONSTRAINT FK_msp_tes_reap_cuenta FOREIGN KEY(id_cuenta_tesoreria) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT FK_msp_tes_reap_solicita FOREIGN KEY(id_usuario_solicita) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT FK_msp_tes_reap_autoriza FOREIGN KEY(id_usuario_autoriza) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_tes_reap_motivo CHECK(LEN(LTRIM(RTRIM(motivo)))>=10)
    );
    CREATE INDEX IX_msp_tes_reap_fecha ON dbo.msp_tesoreria_bitacora_reapertura_caja(fecha_reapertura DESC,id_cuenta_tesoreria);
END;
GO

IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE name=N'CK_msp_tesoreria_cierre_estado' AND parent_object_id=OBJECT_ID(N'dbo.msp_tesoreria_cierres_caja'))
    ALTER TABLE dbo.msp_tesoreria_cierres_caja DROP CONSTRAINT CK_msp_tesoreria_cierre_estado;
GO
ALTER TABLE dbo.msp_tesoreria_cierres_caja WITH NOCHECK
    ADD CONSTRAINT CK_msp_tesoreria_cierre_estado CHECK(estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA',N'REABIERTA'));
GO

IF EXISTS(SELECT 1 FROM sys.key_constraints WHERE name=N'UQ_msp_tesoreria_cierre_cuenta_fecha' AND parent_object_id=OBJECT_ID(N'dbo.msp_tesoreria_cierres_caja'))
    ALTER TABLE dbo.msp_tesoreria_cierres_caja DROP CONSTRAINT UQ_msp_tesoreria_cierre_cuenta_fecha;
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE name=N'UX_msp_tesoreria_cierre_activo_cuenta_fecha' AND object_id=OBJECT_ID(N'dbo.msp_tesoreria_cierres_caja'))
    CREATE UNIQUE INDEX UX_msp_tesoreria_cierre_activo_cuenta_fecha ON dbo.msp_tesoreria_cierres_caja(id_cuenta_tesoreria,fecha_cierre) WHERE estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA');
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
  IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre=@fecha_cierre AND estado_cierre IN(N'CUADRADO',N'CON_DIFERENCIA')) THROW 53313,N'La caja ya fue cerrada para esa fecha. Requiere reapertura autorizada.',1;
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

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_reabrir_caja
 @id_cierre_caja INT,@motivo NVARCHAR(1000),@id_usuario_solicita INT,@id_usuario_autoriza INT
AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;
 SET @motivo=NULLIF(LTRIM(RTRIM(ISNULL(@motivo,N''))),N'');
 IF ISNULL(@id_cierre_caja,0)<=0 OR @motivo IS NULL OR LEN(@motivo)<10 THROW 53321,N'La reapertura requiere un motivo de al menos 10 caracteres.',1;
 IF ISNULL(@id_usuario_solicita,0)<=0 OR ISNULL(@id_usuario_autoriza,0)<=0 OR @id_usuario_solicita=@id_usuario_autoriza THROW 53322,N'Debe indicar un solicitante y un autorizador distintos.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.cr_usuarios WHERE id=@id_usuario_solicita AND estado_id=1) THROW 53323,N'El usuario solicitante no está habilitado.',1;
 IF NOT EXISTS(SELECT 1 FROM dbo.cr_usuarios WHERE id=@id_usuario_autoriza AND estado_id=1) THROW 53324,N'El usuario autorizador no está habilitado.',1;
 BEGIN TRANSACTION;
 BEGIN TRY
  DECLARE @cuenta INT,@fecha DATE,@estado NVARCHAR(25);
  SELECT @cuenta=id_cuenta_tesoreria,@fecha=fecha_cierre,@estado=estado_cierre FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK,HOLDLOCK) WHERE id_cierre_caja=@id_cierre_caja;
  IF @cuenta IS NULL THROW 53325,N'El cierre de caja no existe.',1;
  IF @estado NOT IN(N'CUADRADO',N'CON_DIFERENCIA') THROW 53326,N'El cierre ya está reabierto o no admite reapertura.',1;
  UPDATE dbo.msp_tesoreria_cierres_caja SET estado_cierre=N'REABIERTA',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reapertura: ',@motivo) WHERE id_cierre_caja=@id_cierre_caja;
  INSERT dbo.msp_tesoreria_bitacora_reapertura_caja(id_cierre_caja,id_cuenta_tesoreria,fecha_cierre,estado_anterior,motivo,id_usuario_solicita,id_usuario_autoriza)
  VALUES(@id_cierre_caja,@cuenta,@fecha,@estado,@motivo,@id_usuario_solicita,@id_usuario_autoriza);
  COMMIT TRANSACTION;SELECT @id_cierre_caja id_cierre_caja,N'REABIERTA' estado_cierre;
 END TRY BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK TRANSACTION;THROW;END CATCH;
END;
GO
PRINT N'Reapertura autorizada de caja y bitácora instaladas.';
GO

