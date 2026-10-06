/* Puntos 3 y 4: estructura administrativa, SIN activar el flujo de devolucion.
   Aplicar con un ejecutor que se detenga ante el primer error (sqlcmd -b).
   No modifica saldos, procedimientos financieros, cierres ni historicos.
   Una cabecera genera exactamente dos lineas E/S de igual monto en la vista.
   La integracion transaccional con la devolucion corresponde al punto 5. */
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_garantia_devoluciones',N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_tesoreria_movimientos',N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_tesoreria_cuentas',N'U') IS NULL
   OR COL_LENGTH(N'dbo.msp_garantia_devoluciones',N'id_garantia_tienda') IS NULL
   OR COL_LENGTH(N'dbo.msp_tesoreria_movimientos',N'id_devolucion_garantia') IS NULL
    THROW 53900,N'Faltan las migraciones de devoluciones operativas y garantia por tienda.',1;

IF NOT EXISTS(
    SELECT 1 FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.msp_tesoreria_movimientos')
      AND name=N'UX_msp_tesoreria_movimientos_devolucion_garantia'
      AND is_unique=1 AND has_filter=1 AND is_disabled=0
)
    THROW 53908,N'Falta la proteccion de un unico egreso financiero vigente por devolucion.',1;

BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.msp_garantia_devolucion_caja_admin',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_devolucion_caja_admin(
        id_registro_caja_admin BIGINT IDENTITY(1,1) NOT NULL,
        id_devolucion_garantia INT NOT NULL,
        id_movimiento_tesoreria_origen INT NOT NULL,
        id_cuenta_caja INT NOT NULL,
        fecha_movimiento DATE NOT NULL,
        monto DECIMAL(18,2) NOT NULL,
        id_usuario INT NOT NULL,
        fecha_registro DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_dev_caja_admin_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_dev_caja_admin PRIMARY KEY(id_registro_caja_admin),
        CONSTRAINT UQ_msp_dev_caja_admin_devolucion UNIQUE(id_devolucion_garantia),
        CONSTRAINT UQ_msp_dev_caja_admin_origen UNIQUE(id_movimiento_tesoreria_origen),
        CONSTRAINT FK_msp_dev_caja_admin_devolucion FOREIGN KEY(id_devolucion_garantia)
            REFERENCES dbo.msp_garantia_devoluciones(id_devolucion_garantia),
        CONSTRAINT FK_msp_dev_caja_admin_origen FOREIGN KEY(id_movimiento_tesoreria_origen)
            REFERENCES dbo.msp_tesoreria_movimientos(id_movimiento_tesoreria),
        CONSTRAINT FK_msp_dev_caja_admin_caja FOREIGN KEY(id_cuenta_caja)
            REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT CK_msp_dev_caja_admin_monto CHECK(monto>0),
        CONSTRAINT CK_msp_dev_caja_admin_usuario CHECK(id_usuario>0)
    );
    CREATE INDEX IX_msp_dev_caja_admin_caja_fecha
        ON dbo.msp_garantia_devolucion_caja_admin(id_cuenta_caja,fecha_movimiento,id_registro_caja_admin);
END;
GO

/* Validacion tambien para INSERT directo: no se permite simular una devolucion,
   usar una caja como banco de origen ni cambiar/borrar el registro de auditoria.
   No genera asientos ni movimientos financieros. */
CREATE OR ALTER TRIGGER dbo.TR_msp_dev_caja_admin_integridad
ON dbo.msp_garantia_devolucion_caja_admin
AFTER INSERT,UPDATE,DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted)
        THROW 53901,N'El registro administrativo es inmutable; conserve su trazabilidad.',1;

    IF EXISTS(
        SELECT 1 FROM inserted i
        LEFT JOIN dbo.msp_garantia_devoluciones d
            ON d.id_devolucion_garantia=i.id_devolucion_garantia
        LEFT JOIN dbo.msp_tesoreria_movimientos tm
            ON tm.id_movimiento_tesoreria=i.id_movimiento_tesoreria_origen
        LEFT JOIN dbo.msp_tesoreria_cuentas banco
            ON banco.id_cuenta_tesoreria=d.id_cuenta_tesoreria
        LEFT JOIN dbo.msp_tesoreria_cuentas caja
            ON caja.id_cuenta_tesoreria=i.id_cuenta_caja
        WHERE d.id_devolucion_garantia IS NULL OR tm.id_movimiento_tesoreria IS NULL
           OR d.estado_devolucion<>N'EMITIDA' OR d.medio_devolucion<>N'TRANSFERENCIA'
           OR banco.tipo_cuenta<>N'BANCO' OR banco.tipo_cuenta IS NULL
           OR caja.tipo_cuenta<>N'CAJA' OR caja.tipo_cuenta IS NULL OR caja.activo<>1
           OR banco.moneda<>caja.moneda
           OR d.id_garantia_tienda IS NULL
           OR tm.id_devolucion_garantia IS NULL OR tm.id_devolucion_garantia<>i.id_devolucion_garantia
           OR tm.id_movimiento_garantia IS NULL OR tm.id_movimiento_garantia<>d.id_movimiento_garantia
           OR tm.id_cuenta_tesoreria<>d.id_cuenta_tesoreria
           OR tm.tipo_movimiento<>N'DEVOLUCION_GARANTIA' OR tm.naturaleza<>'S'
           OR tm.medio_pago<>N'TRANSFERENCIA' OR tm.estado_movimiento<>N'VIGENTE'
           OR d.monto_devolucion<>i.monto OR tm.monto<>i.monto
           OR d.fecha_devolucion<>i.fecha_movimiento OR tm.fecha_movimiento<>i.fecha_movimiento
    ) THROW 53902,N'El registro administrativo requiere una devolucion bancaria real, vigente y coincidente.',1;
END;
GO

/* Cada cabecera es una pareja indivisible, no dos filas editables por separado.
   La anulacion del origen se refleja sin alterar ni borrar la auditoria.
   Las discrepancias posteriores se muestran como INCONSISTENTE, no se ocultan. */
CREATE OR ALTER VIEW dbo.msp_vw_garantia_devolucion_caja_admin
AS
SELECT a.id_registro_caja_admin,p.orden_movimiento,p.naturaleza,
       a.id_devolucion_garantia,a.id_movimiento_tesoreria_origen,
       d.id_garantia_tienda,d.id_garantia,d.id_movimiento_garantia,
       a.id_cuenta_caja AS id_cuenta_tesoreria,
       d.id_cuenta_tesoreria AS id_cuenta_banco_origen,
       a.fecha_movimiento,a.monto,a.id_usuario,a.fecha_registro,
       CAST(N'DEVOLUCION_GARANTIA_ADMIN' AS NVARCHAR(30)) AS tipo_movimiento,
       CAST(N'TRANSFERENCIA' AS NVARCHAR(20)) AS medio_pago,
       d.referencia_transferencia AS referencia,
       CAST(N'Sin movimiento de efectivo' AS NVARCHAR(80)) AS etiqueta,
       CAST(1 AS BIT) AS es_administrativo,
       CAST(0 AS BIT) AS afecta_saldo_caja,
       CAST(0 AS DECIMAL(18,2)) AS impacto_efectivo,
       CAST(CASE WHEN d.estado_devolucion=N'ANULADA' OR tm.estado_movimiento=N'ANULADO'
                 THEN N'ANULADO'
                 WHEN d.estado_devolucion=N'EMITIDA' AND d.medio_devolucion=N'TRANSFERENCIA'
                  AND banco.tipo_cuenta=N'BANCO' AND caja.tipo_cuenta=N'CAJA'
                  AND banco.moneda=caja.moneda AND d.id_garantia_tienda IS NOT NULL
                  AND tm.id_devolucion_garantia=a.id_devolucion_garantia
                  AND tm.id_movimiento_garantia=d.id_movimiento_garantia
                  AND tm.id_cuenta_tesoreria=d.id_cuenta_tesoreria
                  AND tm.tipo_movimiento=N'DEVOLUCION_GARANTIA' AND tm.naturaleza='S'
                  AND tm.medio_pago=N'TRANSFERENCIA' AND tm.estado_movimiento=N'VIGENTE'
                  AND d.monto_devolucion=a.monto AND tm.monto=a.monto
                  AND d.fecha_devolucion=a.fecha_movimiento AND tm.fecha_movimiento=a.fecha_movimiento
                 THEN N'VIGENTE' ELSE N'INCONSISTENTE' END AS NVARCHAR(20)) AS estado_movimiento
FROM dbo.msp_garantia_devolucion_caja_admin a
JOIN dbo.msp_garantia_devoluciones d ON d.id_devolucion_garantia=a.id_devolucion_garantia
JOIN dbo.msp_tesoreria_movimientos tm ON tm.id_movimiento_tesoreria=a.id_movimiento_tesoreria_origen
JOIN dbo.msp_tesoreria_cuentas banco ON banco.id_cuenta_tesoreria=d.id_cuenta_tesoreria
JOIN dbo.msp_tesoreria_cuentas caja ON caja.id_cuenta_tesoreria=a.id_cuenta_caja
CROSS JOIN (VALUES(1,CAST('E' AS CHAR(1))),(2,CAST('S' AS CHAR(1)))) p(orden_movimiento,naturaleza);
GO

/* Servicio preparado, sin llamadas desde el flujo web actual.
   Acepta una devolucion ya registrada; no paga, no deposita, no devuelve otra vez.
   Si se llama dentro de una transaccion, no la confirma ni revierte por completo. */
CREATE OR ALTER PROCEDURE dbo.msp_garantia_devolucion_registrar_caja_admin
    @id_devolucion_garantia INT,
    @id_cuenta_caja INT,
    @id_usuario INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF ISNULL(@id_devolucion_garantia,0)<=0 OR ISNULL(@id_cuenta_caja,0)<=0 OR ISNULL(@id_usuario,0)<=0
        THROW 53903,N'Devolucion, caja y usuario son obligatorios.',1;

    DECLARE @transaccion_propia BIT=CASE WHEN @@TRANCOUNT=0 THEN 1 ELSE 0 END;
    BEGIN TRY
        IF @transaccion_propia=1 BEGIN TRANSACTION;
        ELSE SAVE TRANSACTION msp_dev_caja_admin;

        DECLARE @monto DECIMAL(18,2),@fecha DATE,@id_banco INT,@id_movimiento INT,
                @id_registro BIGINT,@caja_existente INT;
        SELECT @monto=d.monto_devolucion,@fecha=d.fecha_devolucion,@id_banco=d.id_cuenta_tesoreria
        FROM dbo.msp_garantia_devoluciones d WITH(UPDLOCK,HOLDLOCK)
        WHERE d.id_devolucion_garantia=@id_devolucion_garantia
          AND d.estado_devolucion=N'EMITIDA' AND d.medio_devolucion=N'TRANSFERENCIA'
          AND d.id_garantia_tienda IS NOT NULL;
        IF @monto IS NULL
            THROW 53904,N'La devolucion no existe, esta anulada o no es una transferencia de garantia de tienda.',1;

        SELECT @id_movimiento=tm.id_movimiento_tesoreria
        FROM dbo.msp_tesoreria_movimientos tm WITH(UPDLOCK,HOLDLOCK)
        JOIN dbo.msp_garantia_devoluciones d ON d.id_devolucion_garantia=tm.id_devolucion_garantia
        WHERE tm.id_devolucion_garantia=@id_devolucion_garantia
          AND tm.tipo_movimiento=N'DEVOLUCION_GARANTIA' AND tm.naturaleza='S'
          AND tm.medio_pago=N'TRANSFERENCIA' AND tm.estado_movimiento=N'VIGENTE'
          AND tm.id_cuenta_tesoreria=@id_banco AND tm.monto=@monto AND tm.fecha_movimiento=@fecha
          AND tm.id_movimiento_garantia=d.id_movimiento_garantia;
        IF @id_movimiento IS NULL
            THROW 53905,N'Falta el egreso bancario real y coincidente de esta devolucion.',1;

        SELECT @id_registro=id_registro_caja_admin,@caja_existente=id_cuenta_caja
        FROM dbo.msp_garantia_devolucion_caja_admin WITH(UPDLOCK,HOLDLOCK)
        WHERE id_devolucion_garantia=@id_devolucion_garantia;
        IF @id_registro IS NOT NULL
        BEGIN
            IF @caja_existente<>@id_cuenta_caja OR NOT EXISTS(
                SELECT 1 FROM dbo.msp_vw_garantia_devolucion_caja_admin
                WHERE id_registro_caja_admin=@id_registro AND estado_movimiento=N'VIGENTE'
            ) THROW 53906,N'Esta devolucion ya tiene una pareja administrativa diferente o inconsistente.',1;
        END
        ELSE
        BEGIN
            IF NOT EXISTS(
                SELECT 1 FROM dbo.msp_tesoreria_cuentas caja WITH(UPDLOCK,HOLDLOCK)
                JOIN dbo.msp_tesoreria_cuentas banco WITH(UPDLOCK,HOLDLOCK) ON banco.id_cuenta_tesoreria=@id_banco
                WHERE caja.id_cuenta_tesoreria=@id_cuenta_caja AND caja.tipo_cuenta=N'CAJA' AND caja.activo=1
                  AND banco.tipo_cuenta=N'BANCO' AND banco.moneda=caja.moneda
            ) THROW 53907,N'Se requiere una caja activa y un banco de la misma moneda.',1;

            INSERT dbo.msp_garantia_devolucion_caja_admin(
                id_devolucion_garantia,id_movimiento_tesoreria_origen,id_cuenta_caja,fecha_movimiento,monto,id_usuario
            ) VALUES(@id_devolucion_garantia,@id_movimiento,@id_cuenta_caja,@fecha,@monto,@id_usuario);
            SET @id_registro=CONVERT(BIGINT,SCOPE_IDENTITY());
        END;

        IF @transaccion_propia=1 COMMIT TRANSACTION;
        SELECT @id_registro AS id_registro_caja_admin,@id_devolucion_garantia AS id_devolucion_garantia,
               CAST(0 AS DECIMAL(18,2)) AS impacto_efectivo;
    END TRY
    BEGIN CATCH
        IF @transaccion_propia=1 AND XACT_STATE()<>0 ROLLBACK TRANSACTION;
        ELSE IF @transaccion_propia=0 AND XACT_STATE()=1 ROLLBACK TRANSACTION msp_dev_caja_admin;
        THROW;
    END CATCH;
END;
GO
COMMIT TRANSACTION;
GO
