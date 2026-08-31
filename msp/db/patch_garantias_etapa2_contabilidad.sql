SET XACT_ABORT ON;SET ANSI_NULLS ON;SET QUOTED_IDENTIFIER ON;
GO
MERGE dbo.msp_acc_tipos_movimiento t USING(SELECT N'GARANTIA_RECEPCION' codigo,N'Recepción efectiva de garantía' nombre,N'GARANTIA' origen)s ON t.codigo_movimiento=s.codigo
WHEN MATCHED THEN UPDATE SET nombre_movimiento=s.nombre,origen_negocio=s.origen,activo=1
WHEN NOT MATCHED THEN INSERT(codigo_movimiento,nombre_movimiento,origen_negocio,activo) VALUES(s.codigo,s.nombre,s.origen,1);
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_garantia_recepcion @id_recepcion_garantia INT AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;
 DECLARE @hash NVARCHAR(250)=CONCAT(N'GARANTIA_RECEPCION|msp_garantia_recepciones|',@id_recepcion_garantia),@id_asiento INT,@id_periodo INT,@id_tipo INT,@id_activo INT,@id_pasivo INT,@id_garantia INT,@id_tienda INT,@id_arrendatario INT,@id_local INT,@fecha DATE,@monto DECIMAL(18,2),@medio NVARCHAR(20),@tipo_cuenta NVARCHAR(20),@debe DECIMAL(18,2),@haber DECIMAL(18,2);
 IF EXISTS(SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen=@hash) RETURN;
 SELECT @id_garantia=r.id_garantia,@fecha=r.fecha_recepcion,@monto=r.monto_recibido,@medio=r.medio_recepcion,@tipo_cuenta=c.tipo_cuenta
 FROM dbo.msp_garantia_recepciones r JOIN dbo.msp_tesoreria_movimientos tm ON tm.id_recepcion_garantia=r.id_recepcion_garantia AND tm.estado_movimiento=N'VIGENTE' JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
 WHERE r.id_recepcion_garantia=@id_recepcion_garantia AND r.estado_recepcion=N'CONFIRMADA';
 IF @id_garantia IS NULL OR ISNULL(@monto,0)<=0 RETURN;
 SELECT @id_local=g.id_local,@id_tienda=ca.id_tienda,@id_arrendatario=ca.id_arrendatario FROM dbo.msp_garantias g JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=g.id_contrato_arriendo WHERE g.id_garantia=@id_garantia;
 EXEC dbo.msp_acc_asegurar_periodo @fecha,@id_periodo OUTPUT;
 SELECT @id_tipo=id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento=N'GARANTIA_RECEPCION' AND activo=1;
 SELECT @id_activo=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=CASE WHEN @tipo_cuenta=N'CAJA' THEN N'1.1.01' ELSE N'1.1.02' END AND activo=1;
 SELECT @id_pasivo=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'2.1.02' AND activo=1;
 IF @id_tipo IS NULL OR @id_activo IS NULL OR @id_pasivo IS NULL THROW 53501,N'Falta configuración contable para recepción de garantía.',1;
 BEGIN TRANSACTION;
 INSERT dbo.msp_acc_asientos(id_periodo_contable,id_tipo_movimiento,fecha_contable,glosa,tabla_origen,id_origen,hash_origen) VALUES(@id_periodo,@id_tipo,@fecha,CONCAT(N'Recepción efectiva garantía #',@id_garantia,N' / recepción #',@id_recepcion_garantia),N'msp_garantia_recepciones',@id_recepcion_garantia,@hash);SET @id_asiento=CAST(SCOPE_IDENTITY() AS INT);
 INSERT dbo.msp_acc_asientos_detalle(id_asiento_contable,linea,id_cuenta_contable,debe,haber,glosa_detalle,id_tienda,id_arrendatario,id_local,id_garantia) VALUES(@id_asiento,1,@id_activo,@monto,0,CONCAT(N'Ingreso real por ',@medio),@id_tienda,@id_arrendatario,@id_local,@id_garantia),(@id_asiento,2,@id_pasivo,0,@monto,N'Pasivo por garantía efectivamente recibida',@id_tienda,@id_arrendatario,@id_local,@id_garantia);
 SELECT @debe=SUM(debe),@haber=SUM(haber) FROM dbo.msp_acc_asientos_detalle WHERE id_asiento_contable=@id_asiento;IF ABS(ISNULL(@debe,0)-ISNULL(@haber,0))>0.01 THROW 53502,N'El asiento de recepción no cuadra.',1;
 INSERT dbo.msp_acc_eventos_log(id_asiento_contable,tabla_origen,id_origen,accion_log,resultado,mensaje) VALUES(@id_asiento,N'msp_garantia_recepciones',@id_recepcion_garantia,N'GENERAR',N'OK',N'Asiento por recepción efectiva generado.');COMMIT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_garantia_devolucion @id_movimiento_garantia INT AS
BEGIN
 SET NOCOUNT ON;SET XACT_ABORT ON;
 DECLARE @hash NVARCHAR(250)=CONCAT(N'GARANTIA_DEVOLUCION|msp_movimientos_garantia|',@id_movimiento_garantia),@id_asiento INT,@id_periodo INT,@id_tipo INT,@id_pasivo INT,@id_activo INT,@id_garantia INT,@id_tienda INT,@id_arrendatario INT,@id_local INT,@fecha DATE,@monto DECIMAL(18,2),@codigo NVARCHAR(50),@tipo_cuenta NVARCHAR(20),@debe DECIMAL(18,2),@haber DECIMAL(18,2);
 IF EXISTS(SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen=@hash) RETURN;
 SELECT @id_garantia=m.id_garantia,@fecha=d.fecha_devolucion,@monto=m.monto_movimiento,@codigo=t.codigo_movimiento,@tipo_cuenta=c.tipo_cuenta
 FROM dbo.msp_movimientos_garantia m JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia JOIN dbo.msp_garantia_devoluciones d ON d.id_movimiento_garantia=m.id_movimiento_garantia AND d.estado_devolucion=N'EMITIDA' JOIN dbo.msp_tesoreria_cuentas c ON c.id_cuenta_tesoreria=d.id_cuenta_tesoreria WHERE m.id_movimiento_garantia=@id_movimiento_garantia;
 IF @codigo<>N'DEVOLUCION' OR ISNULL(@monto,0)<=0 RETURN;
 SELECT @id_local=g.id_local,@id_tienda=ca.id_tienda,@id_arrendatario=ca.id_arrendatario FROM dbo.msp_garantias g JOIN dbo.msp_contratos_arriendo ca ON ca.id_contrato_arriendo=g.id_contrato_arriendo WHERE g.id_garantia=@id_garantia;
 EXEC dbo.msp_acc_asegurar_periodo @fecha,@id_periodo OUTPUT;SELECT @id_tipo=id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento=N'GARANTIA_DEVOLUCION' AND activo=1;SELECT @id_pasivo=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'2.1.02' AND activo=1;SELECT @id_activo=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=CASE WHEN @tipo_cuenta=N'CAJA' THEN N'1.1.01' ELSE N'1.1.02' END AND activo=1;
 IF @id_tipo IS NULL OR @id_pasivo IS NULL OR @id_activo IS NULL THROW 53503,N'Falta configuración contable para devolución.',1;
 BEGIN TRANSACTION;INSERT dbo.msp_acc_asientos(id_periodo_contable,id_tipo_movimiento,fecha_contable,glosa,tabla_origen,id_origen,hash_origen) VALUES(@id_periodo,@id_tipo,@fecha,CONCAT(N'Devolución garantía movimiento #',@id_movimiento_garantia,N' desde ',LOWER(@tipo_cuenta)),N'msp_movimientos_garantia',@id_movimiento_garantia,@hash);SET @id_asiento=CAST(SCOPE_IDENTITY() AS INT);
 INSERT dbo.msp_acc_asientos_detalle(id_asiento_contable,linea,id_cuenta_contable,debe,haber,glosa_detalle,id_tienda,id_arrendatario,id_local,id_garantia) VALUES(@id_asiento,1,@id_pasivo,@monto,0,N'Disminución del pasivo por garantía',@id_tienda,@id_arrendatario,@id_local,@id_garantia),(@id_asiento,2,@id_activo,0,@monto,CONCAT(N'Salida desde ',LOWER(@tipo_cuenta)),@id_tienda,@id_arrendatario,@id_local,@id_garantia);
 SELECT @debe=SUM(debe),@haber=SUM(haber) FROM dbo.msp_acc_asientos_detalle WHERE id_asiento_contable=@id_asiento;IF ABS(ISNULL(@debe,0)-ISNULL(@haber,0))>0.01 THROW 53504,N'El asiento de devolución no cuadra.',1;INSERT dbo.msp_acc_eventos_log(id_asiento_contable,tabla_origen,id_origen,accion_log,resultado,mensaje) VALUES(@id_asiento,N'msp_movimientos_garantia',@id_movimiento_garantia,N'GENERAR',N'OK',N'Asiento de devolución según origen real generado.');COMMIT;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_acc_movimientos_garantia ON dbo.msp_movimientos_garantia AFTER INSERT AS
BEGIN SET NOCOUNT ON;DECLARE @id INT;DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT id_movimiento_garantia FROM inserted;OPEN c;FETCH NEXT FROM c INTO @id;WHILE @@FETCH_STATUS=0 BEGIN EXEC dbo.msp_acc_generar_asiento_garantia_aplicacion @id_movimiento_garantia=@id;FETCH NEXT FROM c INTO @id;END;CLOSE c;DEALLOCATE c;END;
GO
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_tesoreria_garantias ON dbo.msp_tesoreria_movimientos AFTER INSERT AS
BEGIN SET NOCOUNT ON;DECLARE @r INT,@m INT;DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT id_recepcion_garantia,id_movimiento_garantia FROM inserted WHERE tipo_movimiento IN(N'RECEPCION_GARANTIA',N'DEVOLUCION_GARANTIA');OPEN c;FETCH NEXT FROM c INTO @r,@m;WHILE @@FETCH_STATUS=0 BEGIN IF @r IS NOT NULL EXEC dbo.msp_acc_generar_asiento_garantia_recepcion @id_recepcion_garantia=@r;IF @m IS NOT NULL EXEC dbo.msp_acc_generar_asiento_garantia_devolucion @id_movimiento_garantia=@m;FETCH NEXT FROM c INTO @r,@m;END;CLOSE c;DEALLOCATE c;END;
GO
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_garantia_reversas ON dbo.msp_garantia_reversas AFTER INSERT AS
BEGIN SET NOCOUNT ON;DECLARE @tipo NVARCHAR(20),@id INT,@fecha DATE,@motivo NVARCHAR(500),@tabla NVARCHAR(128),@origen INT;DECLARE c CURSOR LOCAL FAST_FORWARD FOR SELECT tipo_origen,id_origen,fecha_reversa,motivo FROM inserted;OPEN c;FETCH NEXT FROM c INTO @tipo,@id,@fecha,@motivo;WHILE @@FETCH_STATUS=0 BEGIN SET @tabla=CASE WHEN @tipo=N'RECEPCION' THEN N'msp_garantia_recepciones' ELSE N'msp_movimientos_garantia' END;SET @origen=@id;IF @tipo=N'DEVOLUCION' SELECT @origen=id_movimiento_garantia FROM dbo.msp_garantia_devoluciones WHERE id_devolucion_garantia=@id;EXEC dbo.msp_acc_revertir_origen @tabla_origen=@tabla,@id_origen=@origen,@fecha_reversa=@fecha,@motivo=@motivo;FETCH NEXT FROM c INTO @tipo,@id,@fecha,@motivo;END;CLOSE c;DEALLOCATE c;END;
GO

/* Convierte el único asiento histórico válido de constitución en recepción efectiva. */
UPDATE a SET tabla_origen=N'msp_garantia_recepciones',id_origen=r.id_recepcion_garantia,hash_origen=CONCAT(N'GARANTIA_RECEPCION|msp_garantia_recepciones|',r.id_recepcion_garantia),glosa=CONCAT(N'Recepción efectiva garantía #',r.id_garantia,N' / recepción #',r.id_recepcion_garantia)
FROM dbo.msp_acc_asientos a JOIN dbo.msp_garantia_recepciones r ON r.id_garantia=a.id_origen AND r.estado_recepcion=N'CONFIRMADA' WHERE a.hash_origen=CONCAT(N'GARANTIA_CONSTITUCION|msp_garantias|',a.id_origen) AND r.monto_recibido=(SELECT SUM(d.debe) FROM dbo.msp_acc_asientos_detalle d JOIN dbo.msp_acc_plan_cuentas p ON p.id_cuenta_contable=d.id_cuenta_contable WHERE d.id_asiento_contable=a.id_asiento_contable AND p.codigo_cuenta IN(N'1.1.01',N'1.1.02'));
GO
DECLARE @r INT;DECLARE cr CURSOR LOCAL FAST_FORWARD FOR SELECT id_recepcion_garantia FROM dbo.msp_garantia_recepciones WHERE estado_recepcion=N'CONFIRMADA';OPEN cr;FETCH NEXT FROM cr INTO @r;WHILE @@FETCH_STATUS=0 BEGIN EXEC dbo.msp_acc_generar_asiento_garantia_recepcion @id_recepcion_garantia=@r;FETCH NEXT FROM cr INTO @r;END;CLOSE cr;DEALLOCATE cr;
GO

/* Regulariza devoluciones históricas cuya cuenta contable no coincide con tesorería. */
DECLARE @mov INT,@asiento INT,@rev INT,@fecha_regularizacion DATE=CONVERT(date,SYSDATETIME());DECLARE cd CURSOR LOCAL FAST_FORWARD FOR SELECT d.id_movimiento_garantia,a.id_asiento_contable FROM dbo.msp_garantia_devoluciones d JOIN dbo.msp_tesoreria_cuentas tc ON tc.id_cuenta_tesoreria=d.id_cuenta_tesoreria JOIN dbo.msp_acc_asientos a ON a.tabla_origen=N'msp_movimientos_garantia' AND a.id_origen=d.id_movimiento_garantia AND a.estado_asiento=1 JOIN dbo.msp_acc_asientos_detalle ad ON ad.id_asiento_contable=a.id_asiento_contable AND ad.haber>0 JOIN dbo.msp_acc_plan_cuentas pc ON pc.id_cuenta_contable=ad.id_cuenta_contable WHERE d.estado_devolucion=N'EMITIDA' AND pc.codigo_cuenta<>CASE WHEN tc.tipo_cuenta=N'CAJA' THEN N'1.1.01' ELSE N'1.1.02' END;OPEN cd;FETCH NEXT FROM cd INTO @mov,@asiento;WHILE @@FETCH_STATUS=0 BEGIN UPDATE dbo.msp_acc_asientos SET hash_origen=CONCAT(hash_origen,N'|LEGACY_INCORRECTO|',id_asiento_contable) WHERE id_asiento_contable=@asiento;EXEC dbo.msp_acc_revertir_asiento @id_asiento_contable=@asiento,@fecha_reversa=@fecha_regularizacion,@motivo=N'Regularización Etapa 2: origen de tesorería incorrecto',@id_asiento_reversa=@rev OUTPUT;EXEC dbo.msp_acc_generar_asiento_garantia_devolucion @id_movimiento_garantia=@mov;FETCH NEXT FROM cd INTO @mov,@asiento;END;CLOSE cd;DEALLOCATE cd;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_submayor_contable AS
WITH negocio AS(SELECT v.id_garantia,v.id_contrato_arriendo,v.nombre_locatario,v.cdo_local,v.monto_recibido,v.monto_aplicado,v.monto_devuelto,v.monto_disponible FROM dbo.msp_vw_garantias_control_integral v),contable AS(SELECT d.id_garantia,SUM(CASE WHEN pc.codigo_cuenta=N'2.1.02' THEN d.haber-d.debe ELSE 0 END) saldo_contable FROM dbo.msp_acc_asientos a JOIN dbo.msp_acc_asientos_detalle d ON d.id_asiento_contable=a.id_asiento_contable JOIN dbo.msp_acc_plan_cuentas pc ON pc.id_cuenta_contable=d.id_cuenta_contable WHERE a.estado_asiento IN(1,2,3) GROUP BY d.id_garantia)
SELECT n.*,CAST(ISNULL(c.saldo_contable,0) AS DECIMAL(18,2)) saldo_pasivo_contable,CAST(ISNULL(c.saldo_contable,0)-n.monto_disponible AS DECIMAL(18,2)) diferencia,CASE WHEN ABS(ISNULL(c.saldo_contable,0)-n.monto_disponible)<=0.01 THEN N'CUADRADO' ELSE N'REVISAR' END estado_cuadre FROM negocio n LEFT JOIN contable c ON c.id_garantia=n.id_garantia;
GO
PRINT N'Etapa 2 contable de garantías instalada y regularizada.';
GO
