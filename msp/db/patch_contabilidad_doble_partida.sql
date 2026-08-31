/*
===========================================================================
 MSP - PATCH CONTABILIDAD DOBLE PARTIDA
 - Capa contable simplificada desacoplada del modelo operativo.
 - Genera asientos automaticos desde documentos, pagos, garantias y saldo favor.
 - Idempotente para SQL Server / esquema dbo.
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. TABLAS BASE
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_acc_periodos_contables', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_periodos_contables (
        id_periodo_contable INT IDENTITY(1,1) NOT NULL,
        anio SMALLINT NOT NULL,
        mes TINYINT NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NOT NULL,
        estado_periodo TINYINT NOT NULL CONSTRAINT DF_msp_acc_periodos_estado DEFAULT (1),
        fecha_cierre DATETIME2(0) NULL,
        id_usuario_cierre INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_periodos_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_periodos PRIMARY KEY (id_periodo_contable),
        CONSTRAINT UQ_msp_acc_periodos_anio_mes UNIQUE (anio, mes),
        CONSTRAINT CK_msp_acc_periodos_mes CHECK (mes BETWEEN 1 AND 12),
        CONSTRAINT CK_msp_acc_periodos_estado CHECK (estado_periodo IN (1,2)),
        CONSTRAINT CK_msp_acc_periodos_fechas CHECK (fecha_fin >= fecha_inicio)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_plan_cuentas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_plan_cuentas (
        id_cuenta_contable INT IDENTITY(1,1) NOT NULL,
        codigo_cuenta NVARCHAR(30) NOT NULL,
        nombre_cuenta NVARCHAR(150) NOT NULL,
        tipo_cuenta NVARCHAR(20) NOT NULL,
        naturaleza CHAR(1) NOT NULL,
        acepta_movimiento BIT NOT NULL CONSTRAINT DF_msp_acc_plan_acepta DEFAULT (1),
        id_cuenta_padre INT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_acc_plan_activo DEFAULT (1),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_plan_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_plan_cuentas PRIMARY KEY (id_cuenta_contable),
        CONSTRAINT UQ_msp_acc_plan_codigo UNIQUE (codigo_cuenta),
        CONSTRAINT FK_msp_acc_plan_padre FOREIGN KEY (id_cuenta_padre) REFERENCES dbo.msp_acc_plan_cuentas (id_cuenta_contable),
        CONSTRAINT CK_msp_acc_plan_tipo CHECK (tipo_cuenta IN (N'ACTIVO', N'PASIVO', N'PATRIMONIO', N'INGRESO', N'GASTO', N'ORDEN')),
        CONSTRAINT CK_msp_acc_plan_naturaleza CHECK (naturaleza IN ('D','H'))
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_tipos_movimiento', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_tipos_movimiento (
        id_tipo_movimiento INT IDENTITY(1,1) NOT NULL,
        codigo_movimiento NVARCHAR(50) NOT NULL,
        nombre_movimiento NVARCHAR(150) NOT NULL,
        origen_negocio NVARCHAR(50) NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_acc_tipo_activo DEFAULT (1),
        CONSTRAINT PK_msp_acc_tipos_movimiento PRIMARY KEY (id_tipo_movimiento),
        CONSTRAINT UQ_msp_acc_tipos_codigo UNIQUE (codigo_movimiento)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_item_documento_cuenta', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_item_documento_cuenta (
        codigo_item NVARCHAR(30) NOT NULL,
        id_cuenta_haber INT NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_acc_item_cuenta_activo DEFAULT (1),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_item_cuenta_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_item_documento_cuenta PRIMARY KEY (codigo_item),
        CONSTRAINT FK_msp_acc_item_cuenta_haber FOREIGN KEY (id_cuenta_haber) REFERENCES dbo.msp_acc_plan_cuentas (id_cuenta_contable)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_tipo_cargo_cuenta', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_tipo_cargo_cuenta (
        codigo_tipo_cargo NVARCHAR(50) NOT NULL,
        id_cuenta_haber INT NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_acc_tipo_cargo_cuenta_activo DEFAULT (1),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_tipo_cargo_cuenta_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_tipo_cargo_cuenta PRIMARY KEY (codigo_tipo_cargo),
        CONSTRAINT FK_msp_acc_tipo_cargo_cuenta_haber FOREIGN KEY (id_cuenta_haber) REFERENCES dbo.msp_acc_plan_cuentas (id_cuenta_contable)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_asientos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_asientos (
        id_asiento_contable INT IDENTITY(1,1) NOT NULL,
        id_periodo_contable INT NOT NULL,
        id_tipo_movimiento INT NOT NULL,
        fecha_contable DATE NOT NULL,
        numero_asiento NVARCHAR(50) NULL,
        glosa NVARCHAR(500) NOT NULL,
        estado_asiento TINYINT NOT NULL CONSTRAINT DF_msp_acc_asientos_estado DEFAULT (1),
        tabla_origen NVARCHAR(128) NOT NULL,
        id_origen INT NOT NULL,
        id_origen_detalle INT NULL,
        hash_origen NVARCHAR(250) NOT NULL,
        id_asiento_reversado INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_asientos_registro DEFAULT (SYSDATETIME()),
        fecha_anulacion DATETIME2(0) NULL,
        CONSTRAINT PK_msp_acc_asientos PRIMARY KEY (id_asiento_contable),
        CONSTRAINT FK_msp_acc_asientos_periodo FOREIGN KEY (id_periodo_contable) REFERENCES dbo.msp_acc_periodos_contables (id_periodo_contable),
        CONSTRAINT FK_msp_acc_asientos_tipo FOREIGN KEY (id_tipo_movimiento) REFERENCES dbo.msp_acc_tipos_movimiento (id_tipo_movimiento),
        CONSTRAINT FK_msp_acc_asientos_reversado FOREIGN KEY (id_asiento_reversado) REFERENCES dbo.msp_acc_asientos (id_asiento_contable),
        CONSTRAINT UQ_msp_acc_asientos_hash UNIQUE (hash_origen),
        CONSTRAINT CK_msp_acc_asientos_estado CHECK (estado_asiento IN (1,2,3))
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_asientos_detalle', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_asientos_detalle (
        id_asiento_detalle INT IDENTITY(1,1) NOT NULL,
        id_asiento_contable INT NOT NULL,
        linea INT NOT NULL,
        id_cuenta_contable INT NOT NULL,
        debe DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_acc_det_debe DEFAULT (0),
        haber DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_acc_det_haber DEFAULT (0),
        glosa_detalle NVARCHAR(500) NULL,
        id_tienda INT NULL,
        id_arrendatario INT NULL,
        id_local INT NULL,
        id_documento_cobro INT NULL,
        id_pago INT NULL,
        id_garantia INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_det_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_asientos_detalle PRIMARY KEY (id_asiento_detalle),
        CONSTRAINT FK_msp_acc_det_asiento FOREIGN KEY (id_asiento_contable) REFERENCES dbo.msp_acc_asientos (id_asiento_contable),
        CONSTRAINT FK_msp_acc_det_cuenta FOREIGN KEY (id_cuenta_contable) REFERENCES dbo.msp_acc_plan_cuentas (id_cuenta_contable),
        CONSTRAINT CK_msp_acc_det_montos CHECK (
            debe >= 0
            AND haber >= 0
            AND ((debe > 0 AND haber = 0) OR (haber > 0 AND debe = 0))
        ),
        CONSTRAINT UQ_msp_acc_det_linea UNIQUE (id_asiento_contable, linea)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_acc_eventos_log', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_acc_eventos_log (
        id_log_contable INT IDENTITY(1,1) NOT NULL,
        id_asiento_contable INT NULL,
        tabla_origen NVARCHAR(128) NOT NULL,
        id_origen INT NOT NULL,
        accion_log NVARCHAR(30) NOT NULL,
        resultado NVARCHAR(30) NOT NULL,
        mensaje NVARCHAR(1000) NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_acc_log_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_acc_eventos_log PRIMARY KEY (id_log_contable),
        CONSTRAINT FK_msp_acc_log_asiento FOREIGN KEY (id_asiento_contable) REFERENCES dbo.msp_acc_asientos (id_asiento_contable)
    );
END;
GO

/* =========================================================================
   2. EXTENSIONES OPERATIVAS MINIMAS
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_garantias', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_garantias', 'medio_recepcion') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_garantias
            ADD medio_recepcion NVARCHAR(50) NULL;
    END;

    IF COL_LENGTH('dbo.msp_garantias', 'referencia_recepcion') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_garantias
            ADD referencia_recepcion NVARCHAR(100) NULL;
    END;
END;
GO

/* =========================================================================
   3. CATALOGOS INICIALES
   ========================================================================= */

MERGE dbo.msp_acc_plan_cuentas AS t
USING (
    SELECT N'1.1.01' AS codigo_cuenta, N'Caja' AS nombre_cuenta, N'ACTIVO' AS tipo_cuenta, 'D' AS naturaleza
    UNION ALL SELECT N'1.1.02', N'Banco', N'ACTIVO', 'D'
    UNION ALL SELECT N'1.1.10', N'Cuentas por cobrar arrendatarios', N'ACTIVO', 'D'
    UNION ALL SELECT N'2.1.01', N'Saldos a favor de arrendatarios', N'PASIVO', 'H'
    UNION ALL SELECT N'2.1.02', N'Garantias recibidas', N'PASIVO', 'H'
    UNION ALL SELECT N'2.1.03', N'IVA debito fiscal', N'PASIVO', 'H'
    UNION ALL SELECT N'2.1.20', N'Recuperacion de servicios por liquidar', N'PASIVO', 'H'
    UNION ALL SELECT N'4.1.01', N'Ingreso por arriendo', N'INGRESO', 'H'
    UNION ALL SELECT N'4.1.03', N'Ingreso por multas', N'INGRESO', 'H'
    UNION ALL SELECT N'4.1.04', N'Ingreso por danos', N'INGRESO', 'H'
    UNION ALL SELECT N'4.1.09', N'Ingreso por cargos extra', N'INGRESO', 'H'
) AS s
ON t.codigo_cuenta = s.codigo_cuenta
WHEN MATCHED THEN
    UPDATE SET nombre_cuenta = s.nombre_cuenta, tipo_cuenta = s.tipo_cuenta, naturaleza = s.naturaleza, activo = 1
WHEN NOT MATCHED THEN
    INSERT (codigo_cuenta, nombre_cuenta, tipo_cuenta, naturaleza, acepta_movimiento, activo)
    VALUES (s.codigo_cuenta, s.nombre_cuenta, s.tipo_cuenta, s.naturaleza, 1, 1);
GO

MERGE dbo.msp_acc_tipos_movimiento AS t
USING (
    SELECT N'DOCUMENTO_EMISION' AS codigo_movimiento, N'Emision de documento de cobro' AS nombre_movimiento, N'DOCUMENTO' AS origen_negocio
    UNION ALL SELECT N'PAGO_REAL', N'Pago real recibido', N'PAGO'
    UNION ALL SELECT N'GARANTIA_CONSTITUCION', N'Constitucion de garantia', N'GARANTIA'
    UNION ALL SELECT N'GARANTIA_APLICACION', N'Aplicacion de garantia a deuda', N'GARANTIA'
    UNION ALL SELECT N'SALDO_FAVOR_APLICACION', N'Aplicacion de saldo a favor', N'SALDO_FAVOR'
    UNION ALL SELECT N'REVERSA', N'Reversa contable', N'REVERSA'
) AS s
ON t.codigo_movimiento = s.codigo_movimiento
WHEN MATCHED THEN
    UPDATE SET nombre_movimiento = s.nombre_movimiento, origen_negocio = s.origen_negocio, activo = 1
WHEN NOT MATCHED THEN
    INSERT (codigo_movimiento, nombre_movimiento, origen_negocio, activo)
    VALUES (s.codigo_movimiento, s.nombre_movimiento, s.origen_negocio, 1);
GO

MERGE dbo.msp_acc_item_documento_cuenta AS t
USING (
    SELECT seed.codigo_item, c.id_cuenta_contable
    FROM (
        SELECT N'SERVICIO_AGUA' AS codigo_item, N'2.1.20' AS codigo_cuenta
        UNION ALL SELECT N'SERVICIO_LUZ', N'2.1.20'
        UNION ALL SELECT N'SERVICIO_GAS', N'2.1.20'
        UNION ALL SELECT N'MULTA', N'4.1.03'
        UNION ALL SELECT N'DANO', N'4.1.04'
        UNION ALL SELECT N'AJUSTE', N'4.1.09'
    ) seed
    INNER JOIN dbo.msp_acc_plan_cuentas c
        ON c.codigo_cuenta = seed.codigo_cuenta
) AS s
ON t.codigo_item = s.codigo_item
WHEN MATCHED THEN
    UPDATE SET id_cuenta_haber = s.id_cuenta_contable, activo = 1
WHEN NOT MATCHED THEN
    INSERT (codigo_item, id_cuenta_haber, activo)
    VALUES (s.codigo_item, s.id_cuenta_contable, 1);
GO

MERGE dbo.msp_acc_tipo_cargo_cuenta AS t
USING (
    SELECT seed.codigo_tipo_cargo, c.id_cuenta_contable
    FROM (
        SELECT N'MULTA' AS codigo_tipo_cargo, N'4.1.03' AS codigo_cuenta
        UNION ALL SELECT N'DANOS', N'4.1.04'
        UNION ALL SELECT N'OTRO', N'4.1.09'
    ) seed
    INNER JOIN dbo.msp_acc_plan_cuentas c
        ON c.codigo_cuenta = seed.codigo_cuenta
) AS s
ON t.codigo_tipo_cargo = s.codigo_tipo_cargo
WHEN MATCHED THEN
    UPDATE SET id_cuenta_haber = s.id_cuenta_contable, activo = 1
WHEN NOT MATCHED THEN
    INSERT (codigo_tipo_cargo, id_cuenta_haber, activo)
    VALUES (s.codigo_tipo_cargo, s.id_cuenta_contable, 1);
GO

/* =========================================================================
   4. HELPERS CONTABLES
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_acc_asegurar_periodo
    @fecha_periodo DATE,
    @id_periodo_contable INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    IF @fecha_periodo IS NULL
        THROW 52001, 'La fecha contable es obligatoria.', 1;

    DECLARE @inicio DATE = DATEFROMPARTS(YEAR(@fecha_periodo), MONTH(@fecha_periodo), 1);
    DECLARE @fin DATE = EOMONTH(@inicio);

    SELECT @id_periodo_contable = id_periodo_contable
    FROM dbo.msp_acc_periodos_contables WITH (UPDLOCK, HOLDLOCK)
    WHERE fecha_inicio = @inicio;

    IF ISNULL(@id_periodo_contable, 0) <= 0
    BEGIN
        INSERT INTO dbo.msp_acc_periodos_contables (anio, mes, fecha_inicio, fecha_fin, estado_periodo)
        VALUES (YEAR(@inicio), MONTH(@inicio), @inicio, @fin, 1);

        SET @id_periodo_contable = CONVERT(INT, SCOPE_IDENTITY());
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_acc_periodos_contables
        WHERE id_periodo_contable = @id_periodo_contable
          AND estado_periodo = 2
    )
        THROW 52002, 'El periodo contable esta cerrado.', 1;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_revertir_asiento
    @id_asiento_contable INT,
    @fecha_reversa DATE,
    @motivo NVARCHAR(500) = NULL,
    @id_asiento_reversa INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @id_periodo INT,
        @id_tipo_reversa INT,
        @tabla_origen NVARCHAR(128),
        @id_origen INT,
        @hash NVARCHAR(250),
        @glosa NVARCHAR(500);

    SET @id_asiento_reversa = NULL;
    SET @fecha_reversa = ISNULL(@fecha_reversa, CONVERT(date, SYSDATETIME()));

    SELECT
        @tabla_origen = a.tabla_origen,
        @id_origen = a.id_origen,
        @glosa = CONCAT(N'Reversa de asiento #', a.id_asiento_contable, CASE WHEN NULLIF(@motivo, N'') IS NULL THEN N'' ELSE CONCAT(N': ', @motivo) END)
    FROM dbo.msp_acc_asientos a WITH (UPDLOCK, HOLDLOCK)
    WHERE a.id_asiento_contable = @id_asiento_contable
      AND a.estado_asiento = 1;

    IF @id_origen IS NULL
        RETURN;

    SET @hash = CONCAT(N'REVERSA|', @id_asiento_contable);
    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    EXEC dbo.msp_acc_asegurar_periodo @fecha_reversa, @id_periodo OUTPUT;

    SELECT @id_tipo_reversa = id_tipo_movimiento
    FROM dbo.msp_acc_tipos_movimiento
    WHERE codigo_movimiento = N'REVERSA';

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable,
        id_tipo_movimiento,
        fecha_contable,
        glosa,
        estado_asiento,
        tabla_origen,
        id_origen,
        hash_origen,
        id_asiento_reversado
    )
    VALUES (
        @id_periodo,
        @id_tipo_reversa,
        @fecha_reversa,
        @glosa,
        3,
        @tabla_origen,
        @id_origen,
        @hash,
        @id_asiento_contable
    );

    SET @id_asiento_reversa = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable,
        linea,
        id_cuenta_contable,
        debe,
        haber,
        glosa_detalle,
        id_tienda,
        id_arrendatario,
        id_local,
        id_documento_cobro,
        id_pago,
        id_garantia
    )
    SELECT
        @id_asiento_reversa,
        d.linea,
        d.id_cuenta_contable,
        d.haber,
        d.debe,
        CONCAT(N'Reversa - ', ISNULL(d.glosa_detalle, N'')),
        d.id_tienda,
        d.id_arrendatario,
        d.id_local,
        d.id_documento_cobro,
        d.id_pago,
        d.id_garantia
    FROM dbo.msp_acc_asientos_detalle d
    WHERE d.id_asiento_contable = @id_asiento_contable;

    UPDATE dbo.msp_acc_asientos
    SET estado_asiento = 2,
        fecha_anulacion = SYSDATETIME()
    WHERE id_asiento_contable = @id_asiento_contable;

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_revertir_origen
    @tabla_origen NVARCHAR(128),
    @id_origen INT,
    @fecha_reversa DATE,
    @motivo NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id_asiento INT;
    DECLARE @id_reversa INT;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT id_asiento_contable
        FROM dbo.msp_acc_asientos
        WHERE tabla_origen = @tabla_origen
          AND id_origen = @id_origen
          AND estado_asiento = 1;

    OPEN cur;
    FETCH NEXT FROM cur INTO @id_asiento;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_revertir_asiento
            @id_asiento_contable = @id_asiento,
            @fecha_reversa = @fecha_reversa,
            @motivo = @motivo,
            @id_asiento_reversa = @id_reversa OUTPUT;
        FETCH NEXT FROM cur INTO @id_asiento;
    END;

    CLOSE cur;
    DEALLOCATE cur;
END;
GO

/* =========================================================================
   5. GENERADORES DE ASIENTO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_documento
    @id_documento_cobro INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @hash NVARCHAR(250) = CONCAT(N'DOCUMENTO_EMISION|msp_documentos_cobro|', @id_documento_cobro),
        @id_asiento INT,
        @id_periodo INT,
        @id_tipo INT,
        @id_cxc INT,
        @id_ing_arriendo INT,
        @id_iva INT,
        @id_rec_serv INT,
        @id_ing_multa INT,
        @id_ing_dano INT,
        @id_ing_extra INT,
        @id_tienda INT,
        @id_arrendatario INT,
        @fecha_contable DATE,
        @periodo_facturacion DATE,
        @numero_documento NVARCHAR(50),
        @monto_total DECIMAL(18,2),
        @estado_documento TINYINT,
        @total_detalle DECIMAL(18,2),
        @monto_arriendo DECIMAL(18,2),
        @monto_iva DECIMAL(18,2),
        @monto_servicios DECIMAL(18,2),
        @monto_multa DECIMAL(18,2),
        @monto_dano DECIMAL(18,2),
        @monto_extra DECIMAL(18,2),
        @id_cuenta_item INT,
        @monto_item DECIMAL(18,2),
        @codigo_item NVARCHAR(30),
        @linea INT = 1,
        @debe_total DECIMAL(18,2),
        @haber_total DECIMAL(18,2);

    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    SELECT
        @id_tienda = dc.id_tienda,
        @periodo_facturacion = dc.periodo_facturacion,
        @fecha_contable = dc.fecha_emision,
        @numero_documento = COALESCE(NULLIF(dc.numero_documento, N''), CONCAT(N'DOC-', dc.id_documento_cobro)),
        @monto_total = dc.monto_total,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL OR @estado_documento NOT IN (2,3,4) OR ISNULL(@monto_total, 0) <= 0
        RETURN;

    SELECT @id_arrendatario = t.id_arrendatario
    FROM dbo.msp_tiendas t
    WHERE t.id_tienda = @id_tienda;

    SELECT
        @total_detalle = ROUND(ISNULL(SUM(dcd.subtotal), 0), 2),
        @monto_arriendo = ROUND(ISNULL(SUM(CASE WHEN tid.codigo_item = N'ARRIENDO' THEN dcd.subtotal ELSE 0 END), 0), 2),
        @monto_servicios = ROUND(ISNULL(SUM(CASE WHEN tid.codigo_item IN (N'SERVICIO_AGUA', N'SERVICIO_LUZ', N'SERVICIO_GAS') THEN dcd.subtotal ELSE 0 END), 0), 2),
        @monto_multa = ROUND(ISNULL(SUM(CASE WHEN tid.codigo_item = N'MULTA' THEN dcd.subtotal ELSE 0 END), 0), 2),
        @monto_dano = ROUND(ISNULL(SUM(CASE WHEN tid.codigo_item = N'DANO' THEN dcd.subtotal ELSE 0 END), 0), 2),
        @monto_extra = ROUND(ISNULL(SUM(CASE WHEN tid.codigo_item NOT IN (N'ARRIENDO', N'SERVICIO_AGUA', N'SERVICIO_LUZ', N'SERVICIO_GAS', N'MULTA', N'DANO') THEN dcd.subtotal ELSE 0 END), 0), 2)
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    WHERE dcd.id_documento_cobro = @id_documento_cobro;

    IF ISNULL(@total_detalle, 0) <= 0
    BEGIN
        SELECT
            @monto_arriendo = ROUND(ISNULL(dc.subtotal_arriendo, 0), 2),
            @monto_servicios = ROUND(ISNULL(dc.subtotal_servicios, 0), 2),
            @monto_multa = 0,
            @monto_dano = 0,
            @monto_extra = 0,
            @total_detalle = ROUND(ISNULL(dc.subtotal_arriendo, 0) + ISNULL(dc.subtotal_servicios, 0), 2)
        FROM dbo.msp_documentos_cobro dc
        WHERE dc.id_documento_cobro = @id_documento_cobro;
    END;

    SET @monto_iva = ROUND(ISNULL(@monto_total, 0) - ISNULL(@total_detalle, 0), 2);
    IF @monto_iva < 0 SET @monto_iva = 0;

    EXEC dbo.msp_acc_asegurar_periodo @periodo_facturacion, @id_periodo OUTPUT;

    SELECT @id_tipo = id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento = N'DOCUMENTO_EMISION';
    SELECT @id_cxc = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'1.1.10';
    SELECT @id_ing_arriendo = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'4.1.01';
    SELECT @id_iva = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.03';
    SELECT @id_rec_serv = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.20';
    SELECT @id_ing_multa = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'4.1.03';
    SELECT @id_ing_dano = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'4.1.04';
    SELECT @id_ing_extra = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'4.1.09';

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable,
        id_tipo_movimiento,
        fecha_contable,
        glosa,
        tabla_origen,
        id_origen,
        hash_origen
    )
    VALUES (
        @id_periodo,
        @id_tipo,
        ISNULL(@fecha_contable, @periodo_facturacion),
        CONCAT(N'Emision documento ', @numero_documento),
        N'msp_documentos_cobro',
        @id_documento_cobro,
        @hash
    );

    SET @id_asiento = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_documento_cobro
    )
    VALUES (
        @id_asiento, @linea, @id_cxc, @monto_total, 0, CONCAT(N'CxC documento ', @numero_documento), @id_tienda, @id_arrendatario, @id_documento_cobro
    );
    SET @linea += 1;

    IF ISNULL(@monto_arriendo, 0) > 0
    BEGIN
        INSERT INTO dbo.msp_acc_asientos_detalle (
            id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_documento_cobro, id_pago, id_garantia
        )
        VALUES (@id_asiento, @linea, @id_ing_arriendo, 0, @monto_arriendo, N'Ingreso arriendo neto', @id_tienda, @id_arrendatario, NULL, @id_documento_cobro, NULL, NULL);
        SET @linea += 1;
    END;
    IF ISNULL(@monto_iva, 0) > 0
    BEGIN
        INSERT INTO dbo.msp_acc_asientos_detalle (
            id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_documento_cobro, id_pago, id_garantia
        )
        VALUES (@id_asiento, @linea, @id_iva, 0, @monto_iva, N'IVA debito arriendo', @id_tienda, @id_arrendatario, NULL, @id_documento_cobro, NULL, NULL);
        SET @linea += 1;
    END;
    DECLARE cur_items CURSOR LOCAL FAST_FORWARD FOR
        SELECT
            COALESCE(map.id_cuenta_haber, @id_ing_extra) AS id_cuenta_haber,
            ROUND(SUM(dcd.subtotal), 2) AS monto_item,
            tid.codigo_item
        FROM dbo.msp_documentos_cobro_detalle dcd
        INNER JOIN dbo.msp_tipo_item_documento tid
            ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
        LEFT JOIN dbo.msp_acc_item_documento_cuenta map
            ON map.codigo_item = tid.codigo_item
           AND map.activo = 1
        WHERE dcd.id_documento_cobro = @id_documento_cobro
          AND tid.codigo_item <> N'ARRIENDO'
        GROUP BY
            COALESCE(map.id_cuenta_haber, @id_ing_extra),
            tid.codigo_item
        HAVING ROUND(SUM(dcd.subtotal), 2) > 0;

    OPEN cur_items;
    FETCH NEXT FROM cur_items INTO @id_cuenta_item, @monto_item, @codigo_item;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        INSERT INTO dbo.msp_acc_asientos_detalle (
            id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_documento_cobro, id_pago, id_garantia
        )
        VALUES (@id_asiento, @linea, @id_cuenta_item, 0, @monto_item, CONCAT(N'Item documento ', @codigo_item), @id_tienda, @id_arrendatario, NULL, @id_documento_cobro, NULL, NULL);

        SET @linea += 1;
        FETCH NEXT FROM cur_items INTO @id_cuenta_item, @monto_item, @codigo_item;
    END;
    CLOSE cur_items;
    DEALLOCATE cur_items;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_documentos_cobro_detalle
        WHERE id_documento_cobro = @id_documento_cobro
    ) AND ISNULL(@monto_servicios, 0) > 0
    BEGIN
        INSERT INTO dbo.msp_acc_asientos_detalle (
            id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_documento_cobro, id_pago, id_garantia
        )
        VALUES (@id_asiento, @linea, @id_rec_serv, 0, @monto_servicios, N'Recuperacion de servicios', @id_tienda, @id_arrendatario, NULL, @id_documento_cobro, NULL, NULL);

        SET @linea += 1;
    END;

    SELECT @debe_total = SUM(debe), @haber_total = SUM(haber)
    FROM dbo.msp_acc_asientos_detalle
    WHERE id_asiento_contable = @id_asiento;

    IF ABS(ISNULL(@debe_total, 0) - ISNULL(@haber_total, 0)) > 0.01
        THROW 52010, 'El asiento de documento no cuadra.', 1;

    INSERT INTO dbo.msp_acc_eventos_log (id_asiento_contable, tabla_origen, id_origen, accion_log, resultado, mensaje)
    VALUES (@id_asiento, N'msp_documentos_cobro', @id_documento_cobro, N'GENERAR', N'OK', N'Asiento de documento generado.');

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_pago
    @id_pago INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @hash NVARCHAR(250) = CONCAT(N'PAGO_REAL|msp_pagos|', @id_pago),
        @id_asiento INT,
        @id_periodo INT,
        @id_tipo INT,
        @id_caja_banco INT,
        @id_cxc INT,
        @id_saldo_favor INT,
        @id_documento INT,
        @id_tienda INT,
        @id_arrendatario INT,
        @fecha_pago DATE,
        @monto_pagado DECIMAL(18,2),
        @monto_excedente DECIMAL(18,2),
        @monto_tesoreria DECIMAL(18,2),
        @aplica_saldo BIT,
        @estado_pago TINYINT,
        @medio_pago NVARCHAR(50),
        @linea INT = 1,
        @debe_total DECIMAL(18,2),
        @haber_total DECIMAL(18,2);

    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    SELECT
        @id_documento = p.id_documento_cobro,
        @fecha_pago = p.fecha_pago,
        @monto_pagado = p.monto_pagado,
        @monto_excedente = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_saldo = ISNULL(p.aplica_desde_saldo_favor, 0),
        @estado_pago = p.estado_pago,
        @medio_pago = ISNULL(p.medio_pago, N'')
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    WHERE p.id_pago = @id_pago;

    IF @estado_pago <> 1 OR ISNULL(@monto_pagado, 0) <= 0
        RETURN;

    IF @aplica_saldo = 1 OR UPPER(LTRIM(RTRIM(@medio_pago))) IN (N'GARANTIA', N'GARANTÍA')
        RETURN;

    SELECT @id_tienda = dc.id_tienda
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.id_documento_cobro = @id_documento;

    SELECT @id_arrendatario = t.id_arrendatario
    FROM dbo.msp_tiendas t
    WHERE t.id_tienda = @id_tienda;

    SET @monto_tesoreria = ROUND(ISNULL(@monto_pagado, 0) + ISNULL(@monto_excedente, 0), 2);

    EXEC dbo.msp_acc_asegurar_periodo @fecha_pago, @id_periodo OUTPUT;

    SELECT @id_tipo = id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento = N'PAGO_REAL';
    SELECT @id_caja_banco = id_cuenta_contable
    FROM dbo.msp_acc_plan_cuentas
    WHERE codigo_cuenta = CASE WHEN UPPER(LTRIM(RTRIM(@medio_pago))) = N'EFECTIVO' THEN N'1.1.01' ELSE N'1.1.02' END;
    SELECT @id_cxc = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'1.1.10';
    SELECT @id_saldo_favor = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.01';

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable, id_tipo_movimiento, fecha_contable, glosa, tabla_origen, id_origen, hash_origen
    )
    VALUES (
        @id_periodo, @id_tipo, @fecha_pago, CONCAT(N'Pago real #', @id_pago), N'msp_pagos', @id_pago, @hash
    );

    SET @id_asiento = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_documento_cobro, id_pago
    )
    VALUES (
        @id_asiento, @linea, @id_caja_banco, @monto_tesoreria, 0, CONCAT(N'Ingreso por ', NULLIF(@medio_pago, N'')), @id_tienda, @id_arrendatario, @id_documento, @id_pago
    );
    SET @linea += 1;

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_documento_cobro, id_pago
    )
    VALUES (
        @id_asiento, @linea, @id_cxc, 0, @monto_pagado, N'Cancelacion de cuenta por cobrar', @id_tienda, @id_arrendatario, @id_documento, @id_pago
    );
    SET @linea += 1;

    IF ISNULL(@monto_excedente, 0) > 0
    BEGIN
        INSERT INTO dbo.msp_acc_asientos_detalle (
            id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_documento_cobro, id_pago
        )
        VALUES (
            @id_asiento, @linea, @id_saldo_favor, 0, @monto_excedente, N'Excedente registrado como saldo a favor', @id_tienda, @id_arrendatario, @id_documento, @id_pago
        );
    END;

    SELECT @debe_total = SUM(debe), @haber_total = SUM(haber)
    FROM dbo.msp_acc_asientos_detalle
    WHERE id_asiento_contable = @id_asiento;

    IF ABS(ISNULL(@debe_total, 0) - ISNULL(@haber_total, 0)) > 0.01
        THROW 52020, 'El asiento de pago no cuadra.', 1;

    INSERT INTO dbo.msp_acc_eventos_log (id_asiento_contable, tabla_origen, id_origen, accion_log, resultado, mensaje)
    VALUES (@id_asiento, N'msp_pagos', @id_pago, N'GENERAR', N'OK', N'Asiento de pago generado.');

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_garantia_constitucion
    @id_garantia INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @hash NVARCHAR(250) = CONCAT(N'GARANTIA_CONSTITUCION|msp_garantias|', @id_garantia),
        @id_asiento INT,
        @id_periodo INT,
        @id_tipo INT,
        @id_caja_banco INT,
        @id_garantias INT,
        @id_tienda INT,
        @id_arrendatario INT,
        @id_local INT,
        @id_contrato INT,
        @fecha DATE,
        @monto DECIMAL(18,2),
        @medio NVARCHAR(50),
        @debe_total DECIMAL(18,2),
        @haber_total DECIMAL(18,2);

    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    SELECT
        @id_contrato = g.id_contrato_arriendo,
        @id_local = g.id_local,
        @fecha = g.fecha_constitucion,
        @monto = g.monto_inicial,
        @medio = ISNULL(g.medio_recepcion, N'Efectivo')
    FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
    WHERE g.id_garantia = @id_garantia
      AND g.estado_garantia <> 6;

    IF ISNULL(@monto, 0) <= 0
        RETURN;

    SELECT @id_tienda = c.id_tienda, @id_arrendatario = c.id_arrendatario
    FROM dbo.msp_contratos_arriendo c
    WHERE c.id_contrato_arriendo = @id_contrato;

    EXEC dbo.msp_acc_asegurar_periodo @fecha, @id_periodo OUTPUT;

    SELECT @id_tipo = id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento = N'GARANTIA_CONSTITUCION';
    SELECT @id_caja_banco = id_cuenta_contable
    FROM dbo.msp_acc_plan_cuentas
    WHERE codigo_cuenta = CASE WHEN UPPER(LTRIM(RTRIM(@medio))) = N'EFECTIVO' THEN N'1.1.01' ELSE N'1.1.02' END;
    SELECT @id_garantias = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.02';

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable, id_tipo_movimiento, fecha_contable, glosa, tabla_origen, id_origen, hash_origen
    )
    VALUES (
        @id_periodo, @id_tipo, @fecha, CONCAT(N'Constitucion garantia #', @id_garantia), N'msp_garantias', @id_garantia, @hash
    );

    SET @id_asiento = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_garantia
    )
    VALUES
        (@id_asiento, 1, @id_caja_banco, @monto, 0, CONCAT(N'Recepcion garantia por ', @medio), @id_tienda, @id_arrendatario, @id_local, @id_garantia),
        (@id_asiento, 2, @id_garantias, 0, @monto, N'Pasivo por garantia recibida', @id_tienda, @id_arrendatario, @id_local, @id_garantia);

    SELECT @debe_total = SUM(debe), @haber_total = SUM(haber)
    FROM dbo.msp_acc_asientos_detalle
    WHERE id_asiento_contable = @id_asiento;

    IF ABS(ISNULL(@debe_total, 0) - ISNULL(@haber_total, 0)) > 0.01
        THROW 52030, 'El asiento de garantia no cuadra.', 1;

    INSERT INTO dbo.msp_acc_eventos_log (id_asiento_contable, tabla_origen, id_origen, accion_log, resultado, mensaje)
    VALUES (@id_asiento, N'msp_garantias', @id_garantia, N'GENERAR', N'OK', N'Asiento de constitucion de garantia generado.');

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_garantia_aplicacion
    @id_movimiento_garantia INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @hash NVARCHAR(250) = CONCAT(N'GARANTIA_APLICACION|msp_movimientos_garantia|', @id_movimiento_garantia),
        @id_asiento INT,
        @id_periodo INT,
        @id_tipo INT,
        @id_garantias INT,
        @id_cxc INT,
        @id_cuenta_credito INT,
        @id_garantia INT,
        @id_documento INT,
        @id_pago INT,
        @id_cargo_contrato_local INT,
        @id_cargo_salida INT,
        @id_tienda INT,
        @id_arrendatario INT,
        @id_local INT,
        @fecha DATE,
        @monto DECIMAL(18,2),
        @codigo_mov NVARCHAR(50),
        @glosa_credito NVARCHAR(500),
        @debe_total DECIMAL(18,2),
        @haber_total DECIMAL(18,2);

    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    SELECT
        @id_garantia = mg.id_garantia,
        @id_documento = mg.id_documento_cobro,
        @id_pago = mg.id_pago,
        @fecha = CONVERT(date, ISNULL(mg.fecha_movimiento, mg.fecha_registro)),
        @monto = mg.monto_movimiento,
        @codigo_mov = t.codigo_movimiento,
        @id_cargo_salida = mg.id_cargo_salida,
        @id_cargo_contrato_local = mg.id_cargo_contrato_local
    FROM dbo.msp_movimientos_garantia mg WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_tipos_movimiento_garantia t
        ON t.id_tipo_movimiento_garantia = mg.id_tipo_movimiento_garantia
    WHERE mg.id_movimiento_garantia = @id_movimiento_garantia;

    IF @codigo_mov <> N'APLICACION_CARGO' OR ISNULL(@monto, 0) <= 0
        RETURN;

    SELECT @id_local = g.id_local
    FROM dbo.msp_garantias g
    WHERE g.id_garantia = @id_garantia;

    IF ISNULL(@id_documento, 0) > 0
    BEGIN
        SELECT @id_tienda = dc.id_tienda
        FROM dbo.msp_documentos_cobro dc
        WHERE dc.id_documento_cobro = @id_documento;
    END;

    IF ISNULL(@id_tienda, 0) <= 0 AND ISNULL(@id_cargo_contrato_local, 0) > 0
    BEGIN
        SELECT
            @id_tienda = ca.id_tienda,
            @id_arrendatario = ca.id_arrendatario,
            @id_local = cl.id_local,
            @id_cuenta_credito = map.id_cuenta_haber,
            @glosa_credito = CONCAT(N'Aplicacion garantia a cargo ', tc.codigo_tipo_cargo)
        FROM dbo.msp_cargos_contrato_local ccl
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = ccl.id_contrato_local
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
        INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = ccl.id_tipo_cargo_salida
        LEFT JOIN dbo.msp_acc_tipo_cargo_cuenta map
            ON map.codigo_tipo_cargo = tc.codigo_tipo_cargo
           AND map.activo = 1
        WHERE ccl.id_cargo_contrato_local = @id_cargo_contrato_local;
    END;

    IF ISNULL(@id_tienda, 0) <= 0 AND ISNULL(@id_cargo_salida, 0) > 0
    BEGIN
        SELECT
            @id_tienda = ca.id_tienda,
            @id_arrendatario = ca.id_arrendatario,
            @id_local = cs.id_local,
            @id_cuenta_credito = map.id_cuenta_haber,
            @glosa_credito = CONCAT(N'Aplicacion garantia a cargo ', tc.codigo_tipo_cargo)
        FROM dbo.msp_cargos_salida cs
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cs.id_contrato_arriendo
        INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
        LEFT JOIN dbo.msp_acc_tipo_cargo_cuenta map
            ON map.codigo_tipo_cargo = tc.codigo_tipo_cargo
           AND map.activo = 1
        WHERE cs.id_cargo_salida = @id_cargo_salida;
    END;

    IF ISNULL(@id_arrendatario, 0) <= 0
    BEGIN
        SELECT @id_arrendatario = t.id_arrendatario
        FROM dbo.msp_tiendas t
        WHERE t.id_tienda = @id_tienda;
    END;

    EXEC dbo.msp_acc_asegurar_periodo @fecha, @id_periodo OUTPUT;

    SELECT @id_tipo = id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento = N'GARANTIA_APLICACION';
    SELECT @id_garantias = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.02';
    SELECT @id_cxc = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'1.1.10';
    IF ISNULL(@id_documento, 0) > 0
    BEGIN
        SET @id_cuenta_credito = @id_cxc;
        SET @glosa_credito = N'Cancelacion de cuenta por cobrar con garantia';
    END;
    IF ISNULL(@id_cuenta_credito, 0) <= 0
    BEGIN
        SELECT @id_cuenta_credito = id_cuenta_contable
        FROM dbo.msp_acc_plan_cuentas
        WHERE codigo_cuenta = N'4.1.09';
        SET @glosa_credito = N'Aplicacion garantia a cargo';
    END;

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable, id_tipo_movimiento, fecha_contable, glosa, tabla_origen, id_origen, hash_origen
    )
    VALUES (
        @id_periodo, @id_tipo, @fecha, CONCAT(N'Aplicacion garantia movimiento #', @id_movimiento_garantia), N'msp_movimientos_garantia', @id_movimiento_garantia, @hash
    );

    SET @id_asiento = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_local, id_documento_cobro, id_pago, id_garantia
    )
    VALUES
        (@id_asiento, 1, @id_garantias, @monto, 0, N'Uso de garantia recibida', @id_tienda, @id_arrendatario, @id_local, @id_documento, @id_pago, @id_garantia),
        (@id_asiento, 2, @id_cuenta_credito, 0, @monto, @glosa_credito, @id_tienda, @id_arrendatario, @id_local, @id_documento, @id_pago, @id_garantia);

    SELECT @debe_total = SUM(debe), @haber_total = SUM(haber)
    FROM dbo.msp_acc_asientos_detalle
    WHERE id_asiento_contable = @id_asiento;

    IF ABS(ISNULL(@debe_total, 0) - ISNULL(@haber_total, 0)) > 0.01
        THROW 52040, 'El asiento de aplicacion de garantia no cuadra.', 1;

    INSERT INTO dbo.msp_acc_eventos_log (id_asiento_contable, tabla_origen, id_origen, accion_log, resultado, mensaje)
    VALUES (@id_asiento, N'msp_movimientos_garantia', @id_movimiento_garantia, N'GENERAR', N'OK', N'Asiento de aplicacion de garantia generado.');

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_acc_generar_asiento_saldo_favor_aplicacion
    @id_movimiento_saldo_favor INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @hash NVARCHAR(250) = CONCAT(N'SALDO_FAVOR_APLICACION|msp_movimientos_saldo_favor_tienda|', @id_movimiento_saldo_favor),
        @id_asiento INT,
        @id_periodo INT,
        @id_tipo INT,
        @id_saldo_favor INT,
        @id_cxc INT,
        @id_tienda INT,
        @id_arrendatario INT,
        @id_documento INT,
        @id_pago INT,
        @tipo_movimiento TINYINT,
        @fecha DATE,
        @monto DECIMAL(18,2),
        @debe_total DECIMAL(18,2),
        @haber_total DECIMAL(18,2);

    IF EXISTS (SELECT 1 FROM dbo.msp_acc_asientos WHERE hash_origen = @hash)
        RETURN;

    SELECT
        @id_tienda = msf.id_tienda,
        @fecha = msf.fecha_movimiento,
        @tipo_movimiento = msf.tipo_movimiento,
        @monto = ABS(msf.monto_movimiento),
        @id_documento = msf.id_documento_cobro,
        @id_pago = msf.id_pago
    FROM dbo.msp_movimientos_saldo_favor_tienda msf WITH (UPDLOCK, HOLDLOCK)
    WHERE msf.id_movimiento_saldo_favor = @id_movimiento_saldo_favor;

    IF @tipo_movimiento <> 2 OR ISNULL(@monto, 0) <= 0
        RETURN;

    SELECT @id_arrendatario = t.id_arrendatario
    FROM dbo.msp_tiendas t
    WHERE t.id_tienda = @id_tienda;

    EXEC dbo.msp_acc_asegurar_periodo @fecha, @id_periodo OUTPUT;

    SELECT @id_tipo = id_tipo_movimiento FROM dbo.msp_acc_tipos_movimiento WHERE codigo_movimiento = N'SALDO_FAVOR_APLICACION';
    SELECT @id_saldo_favor = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'2.1.01';
    SELECT @id_cxc = id_cuenta_contable FROM dbo.msp_acc_plan_cuentas WHERE codigo_cuenta = N'1.1.10';

    BEGIN TRANSACTION;

    INSERT INTO dbo.msp_acc_asientos (
        id_periodo_contable, id_tipo_movimiento, fecha_contable, glosa, tabla_origen, id_origen, hash_origen
    )
    VALUES (
        @id_periodo, @id_tipo, @fecha, CONCAT(N'Aplicacion saldo a favor #', @id_movimiento_saldo_favor), N'msp_movimientos_saldo_favor_tienda', @id_movimiento_saldo_favor, @hash
    );

    SET @id_asiento = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_acc_asientos_detalle (
        id_asiento_contable, linea, id_cuenta_contable, debe, haber, glosa_detalle, id_tienda, id_arrendatario, id_documento_cobro, id_pago
    )
    VALUES
        (@id_asiento, 1, @id_saldo_favor, @monto, 0, N'Uso de saldo a favor', @id_tienda, @id_arrendatario, @id_documento, @id_pago),
        (@id_asiento, 2, @id_cxc, 0, @monto, N'Cancelacion de cuenta por cobrar con saldo a favor', @id_tienda, @id_arrendatario, @id_documento, @id_pago);

    SELECT @debe_total = SUM(debe), @haber_total = SUM(haber)
    FROM dbo.msp_acc_asientos_detalle
    WHERE id_asiento_contable = @id_asiento;

    IF ABS(ISNULL(@debe_total, 0) - ISNULL(@haber_total, 0)) > 0.01
        THROW 52050, 'El asiento de saldo a favor no cuadra.', 1;

    INSERT INTO dbo.msp_acc_eventos_log (id_asiento_contable, tabla_origen, id_origen, accion_log, resultado, mensaje)
    VALUES (@id_asiento, N'msp_movimientos_saldo_favor_tienda', @id_movimiento_saldo_favor, N'GENERAR', N'OK', N'Asiento de aplicacion de saldo a favor generado.');

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   6. TRIGGERS DE INTEGRACION
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_acc_documentos_cobro
ON dbo.msp_documentos_cobro
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id INT;
    DECLARE @fecha_reversa DATE;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT i.id_documento_cobro
        FROM inserted i
        LEFT JOIN deleted d
            ON d.id_documento_cobro = i.id_documento_cobro
        WHERE i.estado_documento IN (2,3,4)
          AND (
                d.id_documento_cobro IS NOT NULL
                OR EXISTS (
                    SELECT 1
                    FROM dbo.msp_documentos_cobro_detalle det
                    WHERE det.id_documento_cobro = i.id_documento_cobro
                )
              );

    OPEN cur;
    FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_documento @id_documento_cobro = @id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;
    DEALLOCATE cur;

    DECLARE cur_rev CURSOR LOCAL FAST_FORWARD FOR
        SELECT i.id_documento_cobro, CONVERT(date, SYSDATETIME())
        FROM inserted i
        INNER JOIN deleted d
            ON d.id_documento_cobro = i.id_documento_cobro
        WHERE i.estado_documento = 5
          AND d.estado_documento <> 5;

    OPEN cur_rev;
    FETCH NEXT FROM cur_rev INTO @id, @fecha_reversa;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_revertir_origen N'msp_documentos_cobro', @id, @fecha_reversa, N'Anulacion de documento';
        FETCH NEXT FROM cur_rev INTO @id, @fecha_reversa;
    END;
    CLOSE cur_rev;
    DEALLOCATE cur_rev;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_acc_pagos
ON dbo.msp_pagos
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id INT;
    DECLARE @fecha_reversa DATE;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT id_pago
        FROM inserted
        WHERE estado_pago = 1;

    OPEN cur;
    FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_pago @id_pago = @id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;
    DEALLOCATE cur;

    DECLARE cur_rev CURSOR LOCAL FAST_FORWARD FOR
        SELECT i.id_pago, ISNULL(i.fecha_anulacion, CONVERT(date, SYSDATETIME()))
        FROM inserted i
        INNER JOIN deleted d
            ON d.id_pago = i.id_pago
        WHERE i.estado_pago = 2
          AND d.estado_pago = 1;

    OPEN cur_rev;
    FETCH NEXT FROM cur_rev INTO @id, @fecha_reversa;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_revertir_origen N'msp_pagos', @id, @fecha_reversa, N'Anulacion de pago';
        FETCH NEXT FROM cur_rev INTO @id, @fecha_reversa;
    END;
    CLOSE cur_rev;
    DEALLOCATE cur_rev;
END;
GO

IF OBJECT_ID(N'dbo.msp_garantias', N'U') IS NOT NULL
EXEC(N'
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_garantias
ON dbo.msp_garantias
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id INT;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT id_garantia
        FROM inserted
        WHERE estado_garantia <> 6;

    OPEN cur;
    FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_garantia_constitucion @id_garantia = @id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;
    DEALLOCATE cur;
END;
');
GO

IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NOT NULL
EXEC(N'
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_movimientos_garantia
ON dbo.msp_movimientos_garantia
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id INT;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT id_movimiento_garantia
        FROM inserted;

    OPEN cur;
    FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_garantia_aplicacion @id_movimiento_garantia = @id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;
    DEALLOCATE cur;
END;
');
GO

IF OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U') IS NOT NULL
EXEC(N'
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_movimientos_saldo_favor
ON dbo.msp_movimientos_saldo_favor_tienda
AFTER INSERT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @id INT;

    DECLARE cur CURSOR LOCAL FAST_FORWARD FOR
        SELECT id_movimiento_saldo_favor
        FROM inserted;

    OPEN cur;
    FETCH NEXT FROM cur INTO @id;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        EXEC dbo.msp_acc_generar_asiento_saldo_favor_aplicacion @id_movimiento_saldo_favor = @id;
        FETCH NEXT FROM cur INTO @id;
    END;
    CLOSE cur;
    DEALLOCATE cur;
END;
');
GO

/* =========================================================================
   7. VISTAS
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_acc_vw_libro_diario
AS
SELECT
    a.id_asiento_contable,
    pc.anio,
    pc.mes,
    pc.fecha_inicio AS periodo_inicio,
    a.fecha_contable,
    tm.codigo_movimiento,
    tm.nombre_movimiento,
    a.glosa,
    a.estado_asiento,
    a.tabla_origen,
    a.id_origen,
    d.linea,
    c.codigo_cuenta,
    c.nombre_cuenta,
    c.tipo_cuenta,
    d.debe,
    d.haber,
    d.glosa_detalle,
    d.id_tienda,
    d.id_arrendatario,
    d.id_local,
    d.id_documento_cobro,
    d.id_pago,
    d.id_garantia
FROM dbo.msp_acc_asientos a
INNER JOIN dbo.msp_acc_periodos_contables pc
    ON pc.id_periodo_contable = a.id_periodo_contable
INNER JOIN dbo.msp_acc_tipos_movimiento tm
    ON tm.id_tipo_movimiento = a.id_tipo_movimiento
INNER JOIN dbo.msp_acc_asientos_detalle d
    ON d.id_asiento_contable = a.id_asiento_contable
INNER JOIN dbo.msp_acc_plan_cuentas c
    ON c.id_cuenta_contable = d.id_cuenta_contable;
GO

CREATE OR ALTER VIEW dbo.msp_acc_vw_saldos_cuentas
AS
SELECT
    pc.anio,
    pc.mes,
    pc.fecha_inicio AS periodo_inicio,
    c.id_cuenta_contable,
    c.codigo_cuenta,
    c.nombre_cuenta,
    c.tipo_cuenta,
    SUM(CASE WHEN a.estado_asiento IN (1,3) THEN d.debe ELSE 0 END) AS total_debe,
    SUM(CASE WHEN a.estado_asiento IN (1,3) THEN d.haber ELSE 0 END) AS total_haber,
    SUM(CASE WHEN a.estado_asiento IN (1,3) THEN d.debe - d.haber ELSE 0 END) AS saldo_deudor
FROM dbo.msp_acc_asientos a
INNER JOIN dbo.msp_acc_periodos_contables pc
    ON pc.id_periodo_contable = a.id_periodo_contable
INNER JOIN dbo.msp_acc_asientos_detalle d
    ON d.id_asiento_contable = a.id_asiento_contable
INNER JOIN dbo.msp_acc_plan_cuentas c
    ON c.id_cuenta_contable = d.id_cuenta_contable
GROUP BY
    pc.anio,
    pc.mes,
    pc.fecha_inicio,
    c.id_cuenta_contable,
    c.codigo_cuenta,
    c.nombre_cuenta,
    c.tipo_cuenta;
GO

PRINT 'Patch contabilidad doble partida aplicado.';
GO
