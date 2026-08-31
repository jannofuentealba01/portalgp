SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_garantia_devoluciones',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_devoluciones(
        id_devolucion_garantia INT IDENTITY(1,1) NOT NULL,
        id_garantia INT NOT NULL,
        id_movimiento_garantia INT NOT NULL,
        id_cuenta_tesoreria INT NOT NULL,
        fecha_devolucion DATE NOT NULL,
        monto_devolucion DECIMAL(18,2) NOT NULL,
        medio_devolucion NVARCHAR(20) NOT NULL,
        beneficiario NVARCHAR(200) NOT NULL,
        rut_beneficiario NVARCHAR(20) NULL,
        banco_destino NVARCHAR(120) NULL,
        cuenta_destino NVARCHAR(100) NULL,
        referencia_transferencia NVARCHAR(200) NULL,
        numero_cheque NVARCHAR(80) NULL,
        fecha_cheque DATE NULL,
        observaciones NVARCHAR(500) NULL,
        estado_devolucion NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_garantia_devoluciones_estado DEFAULT(N'EMITIDA'),
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantia_devoluciones_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_garantia_devoluciones PRIMARY KEY(id_devolucion_garantia),
        CONSTRAINT FK_msp_garantia_devoluciones_garantia FOREIGN KEY(id_garantia) REFERENCES dbo.msp_garantias(id_garantia),
        CONSTRAINT FK_msp_garantia_devoluciones_movimiento FOREIGN KEY(id_movimiento_garantia) REFERENCES dbo.msp_movimientos_garantia(id_movimiento_garantia),
        CONSTRAINT FK_msp_garantia_devoluciones_cuenta FOREIGN KEY(id_cuenta_tesoreria) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT UQ_msp_garantia_devoluciones_movimiento UNIQUE(id_movimiento_garantia),
        CONSTRAINT CK_msp_garantia_devoluciones_monto CHECK(monto_devolucion>0),
        CONSTRAINT CK_msp_garantia_devoluciones_medio CHECK(medio_devolucion=N'EFECTIVO'),
        CONSTRAINT CK_msp_garantia_devoluciones_estado CHECK(estado_devolucion IN(N'EMITIDA',N'ANULADA')),
        CONSTRAINT CK_msp_garantia_devoluciones_datos CHECK(medio_devolucion=N'EFECTIVO')
    );
    CREATE INDEX IX_msp_garantia_devoluciones_fecha ON dbo.msp_garantia_devoluciones(fecha_devolucion DESC,id_devolucion_garantia DESC);
END;
GO

IF OBJECT_ID(N'dbo.msp_garantia_devoluciones',N'U') IS NOT NULL
BEGIN
    IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.msp_garantia_devoluciones') AND name=N'CK_msp_garantia_devoluciones_datos')
        ALTER TABLE dbo.msp_garantia_devoluciones DROP CONSTRAINT CK_msp_garantia_devoluciones_datos;
    IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.msp_garantia_devoluciones') AND name=N'CK_msp_garantia_devoluciones_medio')
        ALTER TABLE dbo.msp_garantia_devoluciones DROP CONSTRAINT CK_msp_garantia_devoluciones_medio;
    ALTER TABLE dbo.msp_garantia_devoluciones ADD CONSTRAINT CK_msp_garantia_devoluciones_medio CHECK(medio_devolucion=N'EFECTIVO');
    ALTER TABLE dbo.msp_garantia_devoluciones ADD CONSTRAINT CK_msp_garantia_devoluciones_datos CHECK(medio_devolucion=N'EFECTIVO');
END;
GO

IF COL_LENGTH('dbo.msp_tesoreria_movimientos','id_devolucion_garantia') IS NULL
    ALTER TABLE dbo.msp_tesoreria_movimientos ADD id_devolucion_garantia INT NULL;
GO
IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_tesoreria_movimientos_devolucion_garantia')
    ALTER TABLE dbo.msp_tesoreria_movimientos ADD CONSTRAINT FK_msp_tesoreria_movimientos_devolucion_garantia
    FOREIGN KEY(id_devolucion_garantia) REFERENCES dbo.msp_garantia_devoluciones(id_devolucion_garantia);
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_tesoreria_movimientos') AND name=N'UX_msp_tesoreria_movimientos_devolucion_garantia')
    CREATE UNIQUE INDEX UX_msp_tesoreria_movimientos_devolucion_garantia ON dbo.msp_tesoreria_movimientos(id_devolucion_garantia)
    WHERE id_devolucion_garantia IS NOT NULL AND estado_movimiento=N'VIGENTE';
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_devolver_operativa
    @id_garantia INT,@id_cuenta_tesoreria INT,@fecha_devolucion DATE,@monto_devolucion DECIMAL(18,2),
    @medio_devolucion NVARCHAR(20),@beneficiario NVARCHAR(200),@rut_beneficiario NVARCHAR(20)=NULL,
    @banco_destino NVARCHAR(120)=NULL,@cuenta_destino NVARCHAR(100)=NULL,@referencia_transferencia NVARCHAR(200)=NULL,
    @numero_cheque NVARCHAR(80)=NULL,@fecha_cheque DATE=NULL,@observaciones NVARCHAR(500)=NULL,@id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;SET XACT_ABORT ON;
    SET @medio_devolucion=UPPER(LTRIM(RTRIM(ISNULL(@medio_devolucion,N''))));
    IF @medio_devolucion<>N'EFECTIVO' THROW 53201,N'La devolución de garantía debe realizarse en efectivo desde caja.',1;
    IF NULLIF(LTRIM(RTRIM(@beneficiario)),N'') IS NULL THROW 53202,N'El beneficiario es obligatorio.',1;
    IF ISNULL(@monto_devolucion,0)<=0 THROW 53203,N'El monto de devolución debe ser mayor a cero.',1;

    BEGIN TRANSACTION;
    BEGIN TRY
        IF NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND tipo_cuenta=N'CAJA' AND activo=1) THROW 53206,N'La caja de salida no existe o está inactiva.',1;
        DECLARE @saldo_caja DECIMAL(18,2),@recibido DECIMAL(18,2),@aplicado DECIMAL(18,2),@devuelto DECIMAL(18,2),@max_real DECIMAL(18,2),@id_mov INT,@id_dev INT;
        SELECT @saldo_caja=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto ELSE 0 END),0) AS DECIMAL(18,2)) FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria;
        IF @monto_devolucion>@saldo_caja THROW 53207,N'La devolución supera el saldo disponible de la caja.',1;
        SELECT @recibido=ISNULL(SUM(monto_recibido),0) FROM dbo.msp_garantia_recepciones WITH(UPDLOCK,HOLDLOCK) WHERE id_garantia=@id_garantia AND estado_recepcion=N'CONFIRMADA';
        SELECT @aplicado=ISNULL(SUM(CASE WHEN t.codigo_movimiento=N'APLICACION_CARGO' THEN m.monto_movimiento ELSE 0 END),0),@devuelto=ISNULL(SUM(CASE WHEN t.codigo_movimiento=N'DEVOLUCION' THEN m.monto_movimiento ELSE 0 END),0)
        FROM dbo.msp_movimientos_garantia m WITH(UPDLOCK,HOLDLOCK) JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia WHERE m.id_garantia=@id_garantia;
        SET @max_real=ISNULL(@recibido,0)-ISNULL(@aplicado,0)-ISNULL(@devuelto,0);
        IF @monto_devolucion>@max_real THROW 53208,N'La devolución supera el dinero efectivamente recibido y aún disponible.',1;

        EXEC dbo.msp_garantia_devolver @id_garantia=@id_garantia,@monto_movimiento=@monto_devolucion,@observaciones=@observaciones,@id_pago=NULL,@id_movimiento_garantia=@id_mov OUTPUT;
        INSERT dbo.msp_garantia_devoluciones(id_garantia,id_movimiento_garantia,id_cuenta_tesoreria,fecha_devolucion,monto_devolucion,medio_devolucion,beneficiario,rut_beneficiario,banco_destino,cuenta_destino,referencia_transferencia,numero_cheque,fecha_cheque,observaciones,id_usuario)
        VALUES(@id_garantia,@id_mov,@id_cuenta_tesoreria,@fecha_devolucion,@monto_devolucion,@medio_devolucion,LTRIM(RTRIM(@beneficiario)),NULLIF(LTRIM(RTRIM(@rut_beneficiario)),N''),NULLIF(LTRIM(RTRIM(@banco_destino)),N''),NULLIF(LTRIM(RTRIM(@cuenta_destino)),N''),NULLIF(LTRIM(RTRIM(@referencia_transferencia)),N''),NULLIF(LTRIM(RTRIM(@numero_cheque)),N''),@fecha_cheque,@observaciones,@id_usuario);
        SET @id_dev=CAST(SCOPE_IDENTITY() AS INT);
        INSERT dbo.msp_tesoreria_movimientos(id_cuenta_tesoreria,fecha_movimiento,tipo_movimiento,naturaleza,monto,medio_pago,referencia,id_movimiento_garantia,id_devolucion_garantia,observaciones,id_usuario)
        VALUES(@id_cuenta_tesoreria,@fecha_devolucion,N'DEVOLUCION_GARANTIA','S',@monto_devolucion,@medio_devolucion,CASE WHEN @medio_devolucion=N'CHEQUE' THEN @numero_cheque ELSE @referencia_transferencia END,@id_mov,@id_dev,@observaciones,@id_usuario);
        COMMIT TRANSACTION;
        SELECT @id_dev id_devolucion_garantia,@id_mov id_movimiento_garantia,@saldo_caja-@monto_devolucion saldo_caja_restante;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;THROW;
    END CATCH;
END;
GO
PRINT N'Patch devolución operativa de garantías aplicado.';
GO
