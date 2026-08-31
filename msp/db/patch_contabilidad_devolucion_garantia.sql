SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

MERGE dbo.msp_acc_tipos_movimiento AS t
USING (SELECT N'GARANTIA_DEVOLUCION' codigo_movimiento,N'Devolución de garantía' nombre_movimiento,N'GARANTIA' origen_negocio) s
ON t.codigo_movimiento=s.codigo_movimiento
WHEN MATCHED THEN UPDATE SET nombre_movimiento=s.nombre_movimiento,origen_negocio=s.origen_negocio,activo=1
WHEN NOT MATCHED THEN INSERT(codigo_movimiento,nombre_movimiento,origen_negocio,activo) VALUES(s.codigo_movimiento,s.nombre_movimiento,s.origen_negocio,1);
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_garantia_devolucion
    @id_movimiento_garantia INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    DECLARE @hash NVARCHAR(250)=CONCAT(N'GARANTIA_DEVOLUCION|msp_movimientos_garantia|',@id_movimiento_garantia),
            @id_asiento INT,@id_periodo INT,@id_tipo INT,@id_garantias INT,@id_banco INT,
            @id_garantia INT,@id_tienda INT,@id_arrendatario INT,@id_local INT,@fecha DATE,@monto DECIMAL(18,2),
            @codigo_mov NVARCHAR(50),@debe DECIMAL(18,2),@haber DECIMAL(18,2);
    IF EXISTS(SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen=@hash) RETURN;
    SELECT @id_garantia=mg.id_garantia,@fecha=CONVERT(DATE,ISNULL(mg.fecha_movimiento,mg.fecha_registro)),
           @monto=mg.monto_movimiento,@codigo_mov=t.codigo_movimiento
    FROM dbo.msp_movimientos_garantia mg WITH(UPDLOCK,HOLDLOCK)
    JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
    WHERE mg.id_movimiento_garantia=@id_movimiento_garantia;
    IF @codigo_mov<>N'DEVOLUCION' OR ISNULL(@monto,0)<=0 RETURN;
    SELECT @id_local=g.id_local,@id_tienda=c.id_tienda,@id_arrendatario=c.id_arrendatario
    FROM dbo.msp_garantias g JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=g.id_contrato_arriendo
    WHERE g.id_garantia=@id_garantia;
    EXEC dbo.msp_acc_asegurar_periodo @fecha,@id_periodo OUTPUT;
    SELECT @id_tipo=id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento=N'GARANTIA_DEVOLUCION' AND activo=1;
    SELECT @id_garantias=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'2.1.02' AND activo=1;
    SELECT @id_banco=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'1.1.02' AND activo=1;
    IF @id_tipo IS NULL OR @id_garantias IS NULL OR @id_banco IS NULL THROW 52041,N'Falta configuración contable para devolución de garantía.',1;

    BEGIN TRANSACTION;
    INSERT dbo.msp_acc_asientos(id_periodo_contable,id_tipo_movimiento,fecha_contable,glosa,tabla_origen,id_origen,hash_origen)
    VALUES(@id_periodo,@id_tipo,@fecha,CONCAT(N'Devolución garantía movimiento #',@id_movimiento_garantia),N'msp_movimientos_garantia',@id_movimiento_garantia,@hash);
    SET @id_asiento=CAST(SCOPE_IDENTITY() AS INT);
    INSERT dbo.msp_acc_asientos_detalle(id_asiento_contable,linea,id_cuenta_contable,debe,haber,glosa_detalle,id_tienda,id_arrendatario,id_local,id_garantia)
    VALUES
      (@id_asiento,1,@id_garantias,@monto,0,N'Disminución del pasivo por garantía',@id_tienda,@id_arrendatario,@id_local,@id_garantia),
      (@id_asiento,2,@id_banco,0,@monto,N'Salida bancaria por devolución de garantía',@id_tienda,@id_arrendatario,@id_local,@id_garantia);
    SELECT @debe=SUM(debe),@haber=SUM(haber) FROM dbo.msp_acc_asientos_detalle WHERE id_asiento_contable=@id_asiento;
    IF ABS(ISNULL(@debe,0)-ISNULL(@haber,0))>0.01 THROW 52042,N'El asiento de devolución de garantía no cuadra.',1;
    INSERT dbo.msp_acc_eventos_log(id_asiento_contable,tabla_origen,id_origen,accion_log,resultado,mensaje)
    VALUES(@id_asiento,N'msp_movimientos_garantia',@id_movimiento_garantia,N'GENERAR',N'OK',N'Asiento de devolución de garantía generado.');
    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_acc_movimientos_garantia
ON dbo.msp_movimientos_garantia
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @id INT;
    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR SELECT id_movimiento_garantia FROM inserted;
    OPEN cur;FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS=0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_garantia_aplicacion @id_movimiento_garantia=@id;
        EXEC dbo.msp_acc_generar_asiento_garantia_devolucion @id_movimiento_garantia=@id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;DEALLOCATE cur;
END;
GO

DECLARE @id_mov INT;
DECLARE cur_backfill CURSOR LOCAL FAST_FORWARD FOR
SELECT mg.id_movimiento_garantia
FROM dbo.msp_movimientos_garantia mg
JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
WHERE t.codigo_movimiento=N'DEVOLUCION'
  AND NOT EXISTS(SELECT 1 FROM dbo.msp_acc_asientos a WHERE a.hash_origen=CONCAT(N'GARANTIA_DEVOLUCION|msp_movimientos_garantia|',mg.id_movimiento_garantia));
OPEN cur_backfill;FETCH NEXT FROM cur_backfill INTO @id_mov;
WHILE @@FETCH_STATUS=0
BEGIN
    EXEC dbo.msp_acc_generar_asiento_garantia_devolucion @id_movimiento_garantia=@id_mov;
    FETCH NEXT FROM cur_backfill INTO @id_mov;
END;
CLOSE cur_backfill;DEALLOCATE cur_backfill;
GO

PRINT N'Contabilidad de devolución de garantías aplicada.';
GO
