SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_depositos',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tesoreria_depositos (
        id_deposito_tesoreria INT IDENTITY(1,1) NOT NULL,
        id_cuenta_caja INT NOT NULL,
        id_cuenta_banco INT NOT NULL,
        fecha_deposito DATE NOT NULL,
        monto_deposito DECIMAL(18,2) NOT NULL,
        referencia_deposito NVARCHAR(200) NOT NULL,
        observaciones NVARCHAR(500) NULL,
        estado_deposito NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_tesoreria_depositos_estado DEFAULT(N'CONFIRMADO'),
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_depositos_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_tesoreria_depositos PRIMARY KEY(id_deposito_tesoreria),
        CONSTRAINT FK_msp_tesoreria_depositos_caja FOREIGN KEY(id_cuenta_caja) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT FK_msp_tesoreria_depositos_banco FOREIGN KEY(id_cuenta_banco) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT CK_msp_tesoreria_depositos_cuentas CHECK(id_cuenta_caja<>id_cuenta_banco),
        CONSTRAINT CK_msp_tesoreria_depositos_monto CHECK(monto_deposito>0),
        CONSTRAINT CK_msp_tesoreria_depositos_estado CHECK(estado_deposito IN(N'CONFIRMADO',N'ANULADO')),
        CONSTRAINT UQ_msp_tesoreria_depositos_referencia UNIQUE(id_cuenta_banco,referencia_deposito)
    );
    CREATE INDEX IX_msp_tesoreria_depositos_fecha ON dbo.msp_tesoreria_depositos(fecha_deposito DESC,id_deposito_tesoreria DESC);
END;
GO

IF COL_LENGTH('dbo.msp_tesoreria_movimientos','id_deposito_tesoreria') IS NULL
    ALTER TABLE dbo.msp_tesoreria_movimientos ADD id_deposito_tesoreria INT NULL;
GO

IF NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_tesoreria_movimientos_deposito')
    ALTER TABLE dbo.msp_tesoreria_movimientos ADD CONSTRAINT FK_msp_tesoreria_movimientos_deposito
        FOREIGN KEY(id_deposito_tesoreria) REFERENCES dbo.msp_tesoreria_depositos(id_deposito_tesoreria);
GO

IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_tesoreria_movimientos') AND name=N'UX_msp_tesoreria_movimientos_deposito_naturaleza')
    CREATE UNIQUE INDEX UX_msp_tesoreria_movimientos_deposito_naturaleza
        ON dbo.msp_tesoreria_movimientos(id_deposito_tesoreria,naturaleza)
        WHERE id_deposito_tesoreria IS NOT NULL;
GO

CREATE OR ALTER VIEW dbo.msp_vw_tesoreria_saldos
AS
SELECT c.id_cuenta_tesoreria,c.codigo_cuenta,c.nombre_cuenta,c.tipo_cuenta,c.banco,c.numero_cuenta,c.moneda,c.activo,
       CAST(ISNULL(SUM(CASE WHEN m.estado_movimiento=N'VIGENTE' AND m.naturaleza='E' THEN m.monto
                            WHEN m.estado_movimiento=N'VIGENTE' AND m.naturaleza='S' THEN -m.monto ELSE 0 END),0) AS DECIMAL(18,2)) saldo_actual,
       MAX(m.fecha_movimiento) ultima_fecha_movimiento
FROM dbo.msp_tesoreria_cuentas c
LEFT JOIN dbo.msp_tesoreria_movimientos m ON m.id_cuenta_tesoreria=c.id_cuenta_tesoreria
GROUP BY c.id_cuenta_tesoreria,c.codigo_cuenta,c.nombre_cuenta,c.tipo_cuenta,c.banco,c.numero_cuenta,c.moneda,c.activo;
GO

IF OBJECT_ID(N'dbo.msp_acc_tipos_movimiento',N'U') IS NOT NULL
BEGIN
    MERGE dbo.msp_acc_tipos_movimiento AS t
    USING (SELECT N'DEPOSITO_BANCARIO' codigo_movimiento,N'Depósito de efectivo en banco' nombre_movimiento,N'TESORERIA' origen_negocio) s
       ON t.codigo_movimiento=s.codigo_movimiento
    WHEN MATCHED THEN UPDATE SET nombre_movimiento=s.nombre_movimiento,origen_negocio=s.origen_negocio,activo=1
    WHEN NOT MATCHED THEN INSERT(codigo_movimiento,nombre_movimiento,origen_negocio,activo) VALUES(s.codigo_movimiento,s.nombre_movimiento,s.origen_negocio,1);
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_registrar_deposito
    @id_cuenta_caja INT,
    @id_cuenta_banco INT,
    @fecha_deposito DATE,
    @monto_deposito DECIMAL(18,2),
    @referencia_deposito NVARCHAR(200),
    @observaciones NVARCHAR(500)=NULL,
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @fecha_deposito IS NULL THROW 53101,N'La fecha de depósito es obligatoria.',1;
    IF ISNULL(@monto_deposito,0)<=0 THROW 53102,N'El monto del depósito debe ser mayor a cero.',1;
    IF NULLIF(LTRIM(RTRIM(@referencia_deposito)),N'') IS NULL THROW 53103,N'La referencia del depósito es obligatoria.',1;

    BEGIN TRANSACTION;
    BEGIN TRY
        IF NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_caja AND tipo_cuenta=N'CAJA' AND activo=1)
            THROW 53104,N'La cuenta de caja no existe o está inactiva.',1;
        IF NOT EXISTS(SELECT 1 FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_banco AND tipo_cuenta=N'BANCO' AND activo=1)
            THROW 53105,N'La cuenta bancaria no existe o está inactiva.',1;

        DECLARE @saldo_caja DECIMAL(18,2),@id_deposito INT;
        SELECT @saldo_caja=CAST(ISNULL(SUM(CASE WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto ELSE 0 END),0) AS DECIMAL(18,2))
        FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK) WHERE id_cuenta_tesoreria=@id_cuenta_caja;
        IF @monto_deposito>@saldo_caja THROW 53106,N'El depósito supera el saldo disponible en caja.',1;

        INSERT INTO dbo.msp_tesoreria_depositos(id_cuenta_caja,id_cuenta_banco,fecha_deposito,monto_deposito,referencia_deposito,observaciones,id_usuario)
        VALUES(@id_cuenta_caja,@id_cuenta_banco,@fecha_deposito,@monto_deposito,LTRIM(RTRIM(@referencia_deposito)),@observaciones,@id_usuario);
        SET @id_deposito=CAST(SCOPE_IDENTITY() AS INT);

        INSERT INTO dbo.msp_tesoreria_movimientos(id_cuenta_tesoreria,fecha_movimiento,tipo_movimiento,naturaleza,monto,medio_pago,referencia,id_deposito_tesoreria,observaciones,id_usuario)
        VALUES
          (@id_cuenta_caja,@fecha_deposito,N'DEPOSITO_BANCARIO','S',@monto_deposito,N'EFECTIVO',@referencia_deposito,@id_deposito,N'Salida de caja por depósito bancario.',@id_usuario),
          (@id_cuenta_banco,@fecha_deposito,N'DEPOSITO_BANCARIO','E',@monto_deposito,N'EFECTIVO',@referencia_deposito,@id_deposito,N'Entrada bancaria por depósito desde caja.',@id_usuario);

        IF OBJECT_ID(N'dbo.msp_acc_asientos',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.msp_acc_asegurar_periodo',N'P') IS NOT NULL
        BEGIN
            DECLARE @id_periodo INT,@id_tipo INT,@id_cta_caja INT,@id_cta_banco INT,@id_asiento INT;
            EXEC dbo.msp_acc_asegurar_periodo @fecha_deposito,@id_periodo OUTPUT;
            SELECT @id_tipo=id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento=N'DEPOSITO_BANCARIO';
            SELECT @id_cta_caja=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'1.1.01';
            SELECT @id_cta_banco=id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta=N'1.1.02';
            IF @id_tipo IS NULL OR @id_cta_caja IS NULL OR @id_cta_banco IS NULL
                THROW 53107,N'Falta configuración contable para caja, banco o depósito.',1;
            INSERT dbo.msp_acc_asientos(id_periodo_contable,id_tipo_movimiento,fecha_contable,glosa,tabla_origen,id_origen,hash_origen)
            VALUES(@id_periodo,@id_tipo,@fecha_deposito,CONCAT(N'Depósito bancario ',@referencia_deposito),N'msp_tesoreria_depositos',@id_deposito,CONCAT(N'DEPOSITO_BANCARIO|msp_tesoreria_depositos|',@id_deposito));
            SET @id_asiento=CAST(SCOPE_IDENTITY() AS INT);
            INSERT dbo.msp_acc_asientos_detalle(id_asiento_contable,linea,id_cuenta_contable,debe,haber,glosa_detalle)
            VALUES(@id_asiento,1,@id_cta_banco,@monto_deposito,0,N'Ingreso a banco por depósito'),(@id_asiento,2,@id_cta_caja,0,@monto_deposito,N'Salida de caja por depósito');
        END;
        COMMIT TRANSACTION;
        SELECT @id_deposito id_deposito_tesoreria,@saldo_caja-@monto_deposito saldo_caja_restante;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT N'Patch de depósitos de tesorería aplicado.';
GO
