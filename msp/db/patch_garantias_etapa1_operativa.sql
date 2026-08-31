SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* Metadatos de autorización y clasificación. */
IF COL_LENGTH('dbo.msp_movimientos_garantia','categoria_aplicacion') IS NULL
    ALTER TABLE dbo.msp_movimientos_garantia ADD categoria_aplicacion NVARCHAR(30) NULL;
IF COL_LENGTH('dbo.msp_movimientos_garantia','motivo_autorizacion') IS NULL
    ALTER TABLE dbo.msp_movimientos_garantia ADD motivo_autorizacion NVARCHAR(500) NULL;
IF COL_LENGTH('dbo.msp_movimientos_garantia','id_usuario_solicita') IS NULL
    ALTER TABLE dbo.msp_movimientos_garantia ADD id_usuario_solicita INT NULL;
IF COL_LENGTH('dbo.msp_movimientos_garantia','id_usuario_autoriza') IS NULL
    ALTER TABLE dbo.msp_movimientos_garantia ADD id_usuario_autoriza INT NULL;
IF COL_LENGTH('dbo.msp_garantia_devoluciones','motivo_autorizacion') IS NULL
    ALTER TABLE dbo.msp_garantia_devoluciones ADD motivo_autorizacion NVARCHAR(500) NULL;
IF COL_LENGTH('dbo.msp_garantia_devoluciones','id_usuario_autoriza') IS NULL
    ALTER TABLE dbo.msp_garantia_devoluciones ADD id_usuario_autoriza INT NULL;
GO

IF OBJECT_ID(N'dbo.msp_garantia_reversas',N'U') IS NULL
BEGIN
 CREATE TABLE dbo.msp_garantia_reversas(
  id_reversa_garantia INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
  id_garantia INT NOT NULL,
  tipo_origen NVARCHAR(20) NOT NULL,
  id_origen INT NOT NULL,
  fecha_reversa DATE NOT NULL,
  monto_reversa DECIMAL(18,2) NOT NULL,
  motivo NVARCHAR(500) NOT NULL,
  id_usuario INT NULL,
  fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantia_reversas_fecha DEFAULT(SYSDATETIME()),
  CONSTRAINT FK_msp_garantia_reversas_garantia FOREIGN KEY(id_garantia) REFERENCES dbo.msp_garantias(id_garantia),
  CONSTRAINT CK_msp_garantia_reversas_tipo CHECK(tipo_origen IN(N'RECEPCION',N'DEVOLUCION',N'APLICACION')),
  CONSTRAINT UQ_msp_garantia_reversas_origen UNIQUE(tipo_origen,id_origen)
 );
END;
GO

/* Se recuperan ambos medios de devolución. */
IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.msp_garantia_devoluciones') AND name=N'CK_msp_garantia_devoluciones_datos')
 ALTER TABLE dbo.msp_garantia_devoluciones DROP CONSTRAINT CK_msp_garantia_devoluciones_datos;
IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.msp_garantia_devoluciones') AND name=N'CK_msp_garantia_devoluciones_medio')
 ALTER TABLE dbo.msp_garantia_devoluciones DROP CONSTRAINT CK_msp_garantia_devoluciones_medio;
ALTER TABLE dbo.msp_garantia_devoluciones ADD CONSTRAINT CK_msp_garantia_devoluciones_medio CHECK(medio_devolucion IN(N'EFECTIVO',N'TRANSFERENCIA'));
ALTER TABLE dbo.msp_garantia_devoluciones ADD CONSTRAINT CK_msp_garantia_devoluciones_datos CHECK(
 medio_devolucion=N'EFECTIVO' OR
 (medio_devolucion=N'TRANSFERENCIA' AND referencia_transferencia IS NOT NULL AND banco_destino IS NOT NULL AND cuenta_destino IS NOT NULL)
);
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_devolver_operativa
 @id_garantia INT,@id_cuenta_tesoreria INT,@fecha_devolucion DATE,@monto_devolucion DECIMAL(18,2),
 @medio_devolucion NVARCHAR(20),@beneficiario NVARCHAR(200),@rut_beneficiario NVARCHAR(20)=NULL,
 @banco_destino NVARCHAR(120)=NULL,@cuenta_destino NVARCHAR(100)=NULL,@referencia_transferencia NVARCHAR(200)=NULL,
 @numero_cheque NVARCHAR(80)=NULL,@fecha_cheque DATE=NULL,@observaciones NVARCHAR(500)=NULL,@id_usuario INT=NULL,
 @motivo_autorizacion NVARCHAR(500)=NULL,@id_usuario_autoriza INT=NULL
AS
BEGIN
 SET NOCOUNT ON; SET XACT_ABORT ON;
 SET @medio_devolucion=UPPER(LTRIM(RTRIM(ISNULL(@medio_devolucion,N''))));
 SET @motivo_autorizacion=NULLIF(LTRIM(RTRIM(ISNULL(@motivo_autorizacion,N''))),N'');
 IF @medio_devolucion NOT IN(N'EFECTIVO',N'TRANSFERENCIA') THROW 53201,N'La devolución debe ser en efectivo o transferencia.',1;
 IF NULLIF(LTRIM(RTRIM(@beneficiario)),N'') IS NULL THROW 53202,N'El beneficiario es obligatorio.',1;
 IF ISNULL(@monto_devolucion,0)<=0 THROW 53203,N'El monto debe ser mayor a cero.',1;
 IF @motivo_autorizacion IS NULL OR @id_usuario_autoriza IS NULL THROW 53209,N'El motivo y usuario autorizador son obligatorios.',1;
 IF @medio_devolucion=N'TRANSFERENCIA' AND (NULLIF(LTRIM(RTRIM(@referencia_transferencia)),N'') IS NULL OR NULLIF(LTRIM(RTRIM(@banco_destino)),N'') IS NULL OR NULLIF(LTRIM(RTRIM(@cuenta_destino)),N'') IS NULL) THROW 53204,N'La transferencia requiere banco, cuenta destino y referencia.',1;
 BEGIN TRANSACTION;
 BEGIN TRY
  DECLARE @tipo_cuenta NVARCHAR(20),@saldo DECIMAL(18,2),@recibido DECIMAL(18,2),@aplicado DECIMAL(18,2),@devuelto DECIMAL(18,2),@max_real DECIMAL(18,2),@id_mov INT,@id_dev INT;
  SELECT @tipo_cuenta=tipo_cuenta FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND activo=1;
  IF (@medio_devolucion=N'EFECTIVO' AND @tipo_cuenta<>N'CAJA') OR (@medio_devolucion=N'TRANSFERENCIA' AND @tipo_cuenta<>N'BANCO') OR @tipo_cuenta IS NULL THROW 53206,N'La cuenta de salida no corresponde al medio de devolución.',1;
  IF @tipo_cuenta=N'CAJA' AND EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre>=@fecha_devolucion) THROW 53210,N'La caja ya está cerrada para la fecha indicada.',1;
  IF @tipo_cuenta=N'BANCO' AND EXISTS(SELECT 1 FROM dbo.msp_tesoreria_conciliaciones WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_hasta>=@fecha_devolucion) THROW 53211,N'La cuenta bancaria ya fue conciliada para la fecha indicada.',1;
  SELECT @saldo=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto ELSE 0 END),0) AS DECIMAL(18,2)) FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria;
  IF @monto_devolucion>@saldo THROW 53207,N'La devolución supera el saldo disponible de la cuenta de origen.',1;
  SELECT @recibido=ISNULL(SUM(monto_recibido),0) FROM dbo.msp_garantia_recepciones WITH(UPDLOCK,HOLDLOCK) WHERE id_garantia=@id_garantia AND estado_recepcion=N'CONFIRMADA';
  SELECT @aplicado=ISNULL(SUM(CASE WHEN t.codigo_movimiento=N'APLICACION_CARGO' THEN m.monto_movimiento ELSE 0 END),0),@devuelto=ISNULL(SUM(CASE WHEN t.codigo_movimiento=N'DEVOLUCION' THEN m.monto_movimiento ELSE 0 END),0) FROM dbo.msp_movimientos_garantia m WITH(UPDLOCK,HOLDLOCK) JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE m.id_garantia=@id_garantia;
  SET @max_real=ISNULL(@recibido,0)-ISNULL(@aplicado,0)-ISNULL(@devuelto,0);
  IF @monto_devolucion>@max_real THROW 53208,N'La devolución supera la garantía efectivamente recibida y disponible.',1;
  EXEC dbo.msp_garantia_devolver @id_garantia=@id_garantia,@monto_movimiento=@monto_devolucion,@observaciones=@motivo_autorizacion,@id_pago=NULL,@id_movimiento_garantia=@id_mov OUTPUT;
  UPDATE dbo.msp_movimientos_garantia SET motivo_autorizacion=@motivo_autorizacion,id_usuario_solicita=@id_usuario,id_usuario_autoriza=@id_usuario_autoriza WHERE id_movimiento_garantia=@id_mov;
  INSERT dbo.msp_garantia_devoluciones(id_garantia,id_movimiento_garantia,id_cuenta_tesoreria,fecha_devolucion,monto_devolucion,medio_devolucion,beneficiario,rut_beneficiario,banco_destino,cuenta_destino,referencia_transferencia,numero_cheque,fecha_cheque,observaciones,id_usuario,motivo_autorizacion,id_usuario_autoriza)
  VALUES(@id_garantia,@id_mov,@id_cuenta_tesoreria,@fecha_devolucion,@monto_devolucion,@medio_devolucion,LTRIM(RTRIM(@beneficiario)),NULLIF(LTRIM(RTRIM(@rut_beneficiario)),N''),CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@banco_destino)),N'') END,CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@cuenta_destino)),N'') END,CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@referencia_transferencia)),N'') END,NULL,NULL,@observaciones,@id_usuario,@motivo_autorizacion,@id_usuario_autoriza);
  SET @id_dev=CAST(SCOPE_IDENTITY() AS INT);
  INSERT dbo.msp_tesoreria_movimientos(id_cuenta_tesoreria,fecha_movimiento,tipo_movimiento,naturaleza,monto,medio_pago,referencia,id_movimiento_garantia,id_devolucion_garantia,observaciones,id_usuario)
  VALUES(@id_cuenta_tesoreria,@fecha_devolucion,N'DEVOLUCION_GARANTIA','S',@monto_devolucion,@medio_devolucion,@referencia_transferencia,@id_mov,@id_dev,@motivo_autorizacion,@id_usuario);
  COMMIT; SELECT @id_dev id_devolucion_garantia,@id_mov id_movimiento_garantia,@saldo-@monto_devolucion saldo_origen_restante;
 END TRY BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK; THROW; END CATCH;
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantia_historial_integral AS
 SELECT r.id_garantia,r.fecha_recepcion fecha,N'RECEPCION' tipo,N'Garantía recibida' concepto,r.monto_recibido monto,N'+' signo,r.medio_recepcion medio,c.nombre_cuenta cuenta,r.referencia referencia,r.observaciones,NULL id_documento,NULL id_cargo,r.id_recepcion_garantia id_origen,r.estado_recepcion estado
 FROM dbo.msp_garantia_recepciones r LEFT JOIN dbo.msp_tesoreria_movimientos tm ON tm.id_recepcion_garantia=r.id_recepcion_garantia AND tm.estado_movimiento=N'VIGENTE' LEFT JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
 UNION ALL
 SELECT m.id_garantia,m.fecha_movimiento,N'APLICACION',COALESCE(m.categoria_aplicacion,t.nombre_movimiento),m.monto_movimiento,N'-',N'INTERNO',NULL,NULL,COALESCE(m.motivo_autorizacion,m.observaciones),m.id_documento_cobro,COALESCE(m.id_cargo_salida,m.id_cargo_contrato_local),m.id_movimiento_garantia,N'VIGENTE'
 FROM dbo.msp_movimientos_garantia m JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE t.codigo_movimiento IN(N'APLICACION_CARGO',N'RESERVA',N'LIBERACION_RESERVA')
 UNION ALL
 SELECT d.id_garantia,d.fecha_devolucion,N'DEVOLUCION',N'Devolución al arrendatario',d.monto_devolucion,N'-',d.medio_devolucion,c.nombre_cuenta,COALESCE(d.referencia_transferencia,d.numero_cheque),COALESCE(d.motivo_autorizacion,d.observaciones),NULL,NULL,d.id_devolucion_garantia,d.estado_devolucion
 FROM dbo.msp_garantia_devoluciones d JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=d.id_cuenta_tesoreria;
GO

UPDATE m SET categoria_aplicacion=N'CARGO_ADICIONAL'
FROM dbo.msp_movimientos_garantia m JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
WHERE t.codigo_movimiento=N'APLICACION_CARGO' AND m.categoria_aplicacion IS NULL AND m.id_documento_cobro IS NULL;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_revertir_operacion
 @tipo_origen NVARCHAR(20),@id_origen INT,@fecha_reversa DATE,@motivo NVARCHAR(500),@id_usuario INT=NULL
AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;SET @tipo_origen=UPPER(LTRIM(RTRIM(ISNULL(@tipo_origen,N''))));SET @motivo=NULLIF(LTRIM(RTRIM(ISNULL(@motivo,N''))),N'');
 IF @tipo_origen NOT IN(N'RECEPCION',N'DEVOLUCION',N'APLICACION') OR ISNULL(@id_origen,0)<=0 OR @fecha_reversa IS NULL OR @motivo IS NULL THROW 53401,N'Datos de reversa incompletos.',1;
 BEGIN TRANSACTION;
 BEGIN TRY
  DECLARE @id_garantia INT,@monto DECIMAL(18,2),@id_tm INT,@id_mov INT,@id_pago INT,@id_ccl INT,@id_cs INT,@fecha DATE,@cuenta INT,@tipo_cuenta NVARCHAR(20),@consumido DECIMAL(18,2),@otros_recibidos DECIMAL(18,2),@id_ajuste INT;
  IF @tipo_origen=N'RECEPCION'
  BEGIN
   SELECT @id_garantia=id_garantia,@monto=monto_recibido,@fecha=fecha_recepcion FROM dbo.msp_garantia_recepciones WITH(UPDLOCK,HOLDLOCK) WHERE id_recepcion_garantia=@id_origen AND estado_recepcion=N'CONFIRMADA';
   SELECT @id_tm=id_movimiento_tesoreria,@cuenta=id_cuenta_tesoreria FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_recepcion_garantia=@id_origen AND estado_movimiento=N'VIGENTE';
   IF @id_garantia IS NULL THROW 53402,N'La recepción no existe o ya fue anulada.',1;
   SELECT @tipo_cuenta=tipo_cuenta FROM dbo.msp_tesoreria_cuentas WHERE id_cuenta_tesoreria=@cuenta;
   IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_movimientos WHERE id_movimiento_tesoreria=@id_tm AND conciliado=1) OR (@tipo_cuenta=N'CAJA' AND EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cierres_caja WHERE id_cuenta_tesoreria=@cuenta AND fecha_cierre>=@fecha)) THROW 53403,N'No se puede revertir un movimiento conciliado o perteneciente a una caja cerrada.',1;
   SELECT @otros_recibidos=ISNULL(SUM(monto_recibido),0) FROM dbo.msp_garantia_recepciones WHERE id_garantia=@id_garantia AND estado_recepcion=N'CONFIRMADA' AND id_recepcion_garantia<>@id_origen;
   SELECT @consumido=ISNULL(SUM(CASE WHEN t.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION') THEN m.monto_movimiento ELSE 0 END),0) FROM dbo.msp_movimientos_garantia m JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE m.id_garantia=@id_garantia;
   IF @otros_recibidos<@consumido THROW 53404,N'No se puede anular: parte de esta recepción ya fue aplicada o devuelta.',1;
   UPDATE dbo.msp_garantia_recepciones SET estado_recepcion=N'ANULADA',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo) WHERE id_recepcion_garantia=@id_origen;
   UPDATE dbo.msp_tesoreria_movimientos SET estado_movimiento=N'ANULADO',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo) WHERE id_movimiento_tesoreria=@id_tm;
  END
  ELSE IF @tipo_origen=N'DEVOLUCION'
  BEGIN
   SELECT @id_garantia=id_garantia,@monto=monto_devolucion,@fecha=fecha_devolucion,@id_mov=id_movimiento_garantia,@cuenta=id_cuenta_tesoreria FROM dbo.msp_garantia_devoluciones WITH(UPDLOCK,HOLDLOCK) WHERE id_devolucion_garantia=@id_origen AND estado_devolucion=N'EMITIDA';
   SELECT @id_tm=id_movimiento_tesoreria FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_devolucion_garantia=@id_origen AND estado_movimiento=N'VIGENTE';
   IF @id_garantia IS NULL THROW 53405,N'La devolución no existe o ya fue anulada.',1;
   IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_movimientos WHERE id_movimiento_tesoreria=@id_tm AND conciliado=1) THROW 53403,N'No se puede revertir un movimiento conciliado.',1;
   SELECT @id_ajuste=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'AJUSTE_POSITIVO';
   UPDATE dbo.msp_garantia_devoluciones SET estado_devolucion=N'ANULADA',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo) WHERE id_devolucion_garantia=@id_origen;
   UPDATE dbo.msp_tesoreria_movimientos SET estado_movimiento=N'ANULADO',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo) WHERE id_movimiento_tesoreria=@id_tm;
   UPDATE dbo.msp_movimientos_garantia SET id_tipo_movimiento_garantia=@id_ajuste,observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa de devolución: ',@motivo) WHERE id_movimiento_garantia=@id_mov;
  END
  ELSE
  BEGIN
   SELECT @id_garantia=id_garantia,@monto=monto_movimiento,@fecha=fecha_movimiento,@id_mov=id_movimiento_garantia,@id_pago=id_pago,@id_ccl=id_cargo_contrato_local,@id_cs=id_cargo_salida FROM dbo.msp_movimientos_garantia WITH(UPDLOCK,HOLDLOCK) WHERE id_movimiento_garantia=@id_origen AND id_tipo_movimiento_garantia=(SELECT id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'APLICACION_CARGO');
   IF @id_garantia IS NULL THROW 53406,N'La aplicación no existe o ya fue revertida.',1;
   IF @id_pago IS NOT NULL EXEC dbo.msp_anular_pago_documento @id_pago=@id_pago,@fecha_anulacion=@fecha_reversa,@motivo_anulacion=@motivo;
   SELECT @id_ajuste=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'AJUSTE_POSITIVO';
   UPDATE dbo.msp_movimientos_garantia SET id_tipo_movimiento_garantia=@id_ajuste,observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa de aplicación: ',@motivo) WHERE id_movimiento_garantia=@id_mov;
   IF @id_ccl IS NOT NULL UPDATE c SET monto_aplicado_garantia=ISNULL(x.aplicado,0),estado_cargo=CASE WHEN ISNULL(x.aplicado,0)>=c.monto_cargo THEN 3 WHEN ISNULL(x.reservado,0)>0 THEN 2 ELSE 1 END FROM dbo.msp_cargos_contrato_local c OUTER APPLY(SELECT SUM(CASE WHEN t.codigo_movimiento=N'APLICACION_CARGO' THEN m.monto_movimiento ELSE 0 END) aplicado,SUM(CASE WHEN t.codigo_movimiento=N'RESERVA' THEN m.monto_movimiento WHEN t.codigo_movimiento=N'LIBERACION_RESERVA' THEN -m.monto_movimiento ELSE 0 END) reservado FROM dbo.msp_movimientos_garantia m JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE m.id_cargo_contrato_local=c.id_cargo_contrato_local)x WHERE c.id_cargo_contrato_local=@id_ccl;
  END;
  INSERT dbo.msp_garantia_reversas(id_garantia,tipo_origen,id_origen,fecha_reversa,monto_reversa,motivo,id_usuario) VALUES(@id_garantia,@tipo_origen,@id_origen,@fecha_reversa,@monto,@motivo,@id_usuario);
  COMMIT;SELECT CAST(SCOPE_IDENTITY() AS INT) id_reversa_garantia;
 END TRY BEGIN CATCH IF XACT_STATE()<>0 ROLLBACK;THROW;END CATCH;
END;
GO

PRINT N'Etapa 1 operativa de garantías instalada.';
GO
