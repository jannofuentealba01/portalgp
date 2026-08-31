/*
===========================================================================
 MSP - AREA COBRO SERVICIOS (A21)
 SQL Server / esquema dbo
 - Requiere A1 ya instalado
 - Esta capa cubre: cierre mensual, procesos por servicio, medidores,
   lecturas y cobro auditable por medidor
 - No incluye importacion Excel cruda ni documentos/pagos
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS BASE
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_tipos_servicio', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tipos_servicio (
        id_tipo_servicio      INT NOT NULL,
        codigo_servicio       NVARCHAR(20) NOT NULL,
        nombre_servicio       NVARCHAR(100) NOT NULL,
        unidad_medida         NVARCHAR(20) NOT NULL,
        CONSTRAINT PK_msp_tipos_servicio PRIMARY KEY (id_tipo_servicio),
        CONSTRAINT UQ_msp_tipos_servicio_codigo UNIQUE (codigo_servicio),
        CONSTRAINT UQ_msp_tipos_servicio_nombre UNIQUE (nombre_servicio)
    );
END;
GO

MERGE dbo.msp_tipos_servicio AS t
USING (
    SELECT 1 AS id_tipo_servicio, N'LUZ'  AS codigo_servicio, N'Luz'  AS nombre_servicio, N'kWh' AS unidad_medida
    UNION ALL
    SELECT 2, N'GAS',  N'Gas',  N'm3'
    UNION ALL
    SELECT 3, N'AGUA', N'Agua', N'm3'
) AS s
ON t.id_tipo_servicio = s.id_tipo_servicio
WHEN MATCHED THEN
    UPDATE SET
        codigo_servicio = s.codigo_servicio,
        nombre_servicio = s.nombre_servicio,
        unidad_medida   = s.unidad_medida
WHEN NOT MATCHED THEN
    INSERT (id_tipo_servicio, codigo_servicio, nombre_servicio, unidad_medida)
    VALUES (s.id_tipo_servicio, s.codigo_servicio, s.nombre_servicio, s.unidad_medida);
GO

IF OBJECT_ID(N'dbo.msp_origen_lecturas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_origen_lecturas (
        id_origen_lectura     INT NOT NULL,
        desc_origen           NVARCHAR(100) NOT NULL,
        CONSTRAINT PK_msp_origen_lecturas PRIMARY KEY (id_origen_lectura),
        CONSTRAINT UQ_msp_origen_lecturas_desc UNIQUE (desc_origen)
    );
END;
GO

MERGE dbo.msp_origen_lecturas AS t
USING (
    SELECT 1 AS id_origen_lectura, N'Manual' AS desc_origen
    UNION ALL
    SELECT 2, N'Excel'
    UNION ALL
    SELECT 3, N'Ajuste'
) AS s
ON t.id_origen_lectura = s.id_origen_lectura
WHEN MATCHED THEN
    UPDATE SET desc_origen = s.desc_origen
WHEN NOT MATCHED THEN
    INSERT (id_origen_lectura, desc_origen)
    VALUES (s.id_origen_lectura, s.desc_origen);
GO

/* =========================================================================
   2. CIERRE MENSUAL
   Estado:
     1 = Borrador
     2 = Calculado
     3 = Cerrado
     4 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_cierre_mensual (
    id_cierre_mensual       INT IDENTITY(1,1) NOT NULL,
    periodo_facturacion     DATE NOT NULL,
    fecha_valor_uf          DATE NOT NULL,
    valor_uf                DECIMAL(18,6) NOT NULL,
    estado_cierre           TINYINT NOT NULL CONSTRAINT DF_msp_cierre_mensual_estado DEFAULT (1),
    observaciones           NVARCHAR(1000) NULL,
    fecha_registro          DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cierre_mensual_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_cierre_mensual PRIMARY KEY (id_cierre_mensual),
    CONSTRAINT UQ_msp_cierre_mensual_periodo UNIQUE (periodo_facturacion),
    CONSTRAINT CK_msp_cierre_mensual_periodo CHECK (DAY(periodo_facturacion) = 1),
    CONSTRAINT CK_msp_cierre_mensual_valor_uf CHECK (valor_uf > 0),
    CONSTRAINT CK_msp_cierre_mensual_estado CHECK (estado_cierre IN (1,2,3,4))
);
GO

/* =========================================================================
   3. MEDIDORES
   Estado:
     1 = Activo
     2 = Retirado
     3 = Inactivo
   ========================================================================= */

CREATE TABLE dbo.msp_medidores (
    id_medidor              INT IDENTITY(1,1) NOT NULL,
    id_local                INT NOT NULL,
    id_tipo_servicio        INT NOT NULL,
    codigo_medidor          NVARCHAR(100) NOT NULL,
    alias_medidor           NVARCHAR(100) NOT NULL,
    numero_serie            NVARCHAR(100) NULL,
    valor_inicial           DECIMAL(18,6) NULL,
    fecha_instalacion       DATE NULL,
    fecha_retiro            DATE NULL,
    estado_medidor          TINYINT NOT NULL CONSTRAINT DF_msp_medidores_estado DEFAULT (1),

    CONSTRAINT PK_msp_medidores PRIMARY KEY (id_medidor),
    CONSTRAINT FK_msp_medidores_local
        FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT FK_msp_medidores_tipo_servicio
        FOREIGN KEY (id_tipo_servicio) REFERENCES dbo.msp_tipos_servicio (id_tipo_servicio),
    CONSTRAINT CK_msp_medidores_estado CHECK (estado_medidor IN (1,2,3)),
    CONSTRAINT CK_msp_medidores_valor_inicial CHECK (valor_inicial IS NULL OR valor_inicial >= 0),
    CONSTRAINT CK_msp_medidores_alias CHECK (LTRIM(RTRIM(alias_medidor)) <> N''),
    CONSTRAINT CK_msp_medidores_fechas CHECK (
        fecha_retiro IS NULL
        OR fecha_instalacion IS NULL
        OR fecha_retiro >= fecha_instalacion
    )
);
GO

CREATE UNIQUE INDEX UX_msp_medidores_codigo_medidor
    ON dbo.msp_medidores (codigo_medidor);
GO

CREATE UNIQUE INDEX UX_msp_medidores_local_servicio_alias
    ON dbo.msp_medidores (id_local, id_tipo_servicio, alias_medidor);
GO

/* =========================================================================
   4. PROCESOS DE COBRO POR SERVICIO
   Estado:
     1 = Borrador
     2 = Calculado
     3 = Cerrado
     4 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_procesos_cobro_servicio (
    id_proceso_cobro        INT IDENTITY(1,1) NOT NULL,
    id_cierre_mensual       INT NOT NULL,
    id_tipo_servicio        INT NOT NULL,
    numero_factura_origen   NVARCHAR(50) NULL,
    fecha_emision_origen    DATE NULL,
    fecha_vencimiento_origen DATE NULL,
    nombre_archivo_origen   NVARCHAR(255) NULL,
    estado_proceso          TINYINT NOT NULL CONSTRAINT DF_msp_procesos_cobro_servicio_estado DEFAULT (1),
    observaciones           NVARCHAR(1000) NULL,
    fecha_registro          DATETIME2(0) NOT NULL CONSTRAINT DF_msp_procesos_cobro_servicio_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_procesos_cobro_servicio PRIMARY KEY (id_proceso_cobro),
    CONSTRAINT FK_msp_procesos_cobro_servicio_cierre
        FOREIGN KEY (id_cierre_mensual) REFERENCES dbo.msp_cierre_mensual (id_cierre_mensual),
    CONSTRAINT FK_msp_procesos_cobro_servicio_tipo_servicio
        FOREIGN KEY (id_tipo_servicio) REFERENCES dbo.msp_tipos_servicio (id_tipo_servicio),
    CONSTRAINT UQ_msp_procesos_cobro_servicio UNIQUE (id_cierre_mensual, id_tipo_servicio),
    CONSTRAINT CK_msp_procesos_cobro_servicio_estado CHECK (estado_proceso IN (1,2,3,4)),
    CONSTRAINT CK_msp_procesos_cobro_servicio_fechas CHECK (
        fecha_vencimiento_origen IS NULL
        OR fecha_emision_origen IS NULL
        OR fecha_vencimiento_origen >= fecha_emision_origen
    )
);
GO

CREATE INDEX IX_msp_procesos_cobro_servicio_cierre
    ON dbo.msp_procesos_cobro_servicio (id_cierre_mensual, id_tipo_servicio);
GO

/* =========================================================================
   5. DETALLE DEL PROCESO SEGUN SERVICIO
   ========================================================================= */

CREATE TABLE dbo.msp_proceso_cobro_luz (
    id_proceso_cobro        INT NOT NULL,
    valor_kwh               DECIMAL(18,6) NOT NULL,

    CONSTRAINT PK_msp_proceso_cobro_luz PRIMARY KEY (id_proceso_cobro),
    CONSTRAINT FK_msp_proceso_cobro_luz_proceso
        FOREIGN KEY (id_proceso_cobro) REFERENCES dbo.msp_procesos_cobro_servicio (id_proceso_cobro),
    CONSTRAINT CK_msp_proceso_cobro_luz_valor_kwh CHECK (valor_kwh >= 0)
);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_proceso_cobro_luz_valida_servicio
ON dbo.msp_proceso_cobro_luz
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = i.id_proceso_cobro
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        WHERE ts.codigo_servicio <> N'LUZ'
    )
    BEGIN
        ;THROW 50011, 'El detalle de luz solo se puede asociar a procesos del servicio LUZ.', 1;
    END;
END;
GO

CREATE TABLE dbo.msp_proceso_cobro_gas (
    id_proceso_cobro        INT NOT NULL,
    factor                  DECIMAL(18,6) NOT NULL,
    valor_litro             DECIMAL(18,6) NOT NULL,

    CONSTRAINT PK_msp_proceso_cobro_gas PRIMARY KEY (id_proceso_cobro),
    CONSTRAINT FK_msp_proceso_cobro_gas_proceso
        FOREIGN KEY (id_proceso_cobro) REFERENCES dbo.msp_procesos_cobro_servicio (id_proceso_cobro),
    CONSTRAINT CK_msp_proceso_cobro_gas_factor CHECK (factor >= 0),
    CONSTRAINT CK_msp_proceso_cobro_gas_valor_litro CHECK (valor_litro >= 0)
);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_proceso_cobro_gas_valida_servicio
ON dbo.msp_proceso_cobro_gas
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = i.id_proceso_cobro
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        WHERE ts.codigo_servicio <> N'GAS'
    )
    BEGIN
        ;THROW 50012, 'El detalle de gas solo se puede asociar a procesos del servicio GAS.', 1;
    END;
END;
GO

CREATE TABLE dbo.msp_proceso_cobro_agua (
    id_proceso_cobro            INT NOT NULL,
    lectura_general_anterior    DECIMAL(18,4) NULL,
    lectura_general_actual      DECIMAL(18,4) NULL,
    servicio_agua_potable       DECIMAL(18,6) NOT NULL,
    servicio_alcantarillado     DECIMAL(18,6) NOT NULL,
    tratamiento_aguas_servidas  DECIMAL(18,6) NOT NULL,
    sobreconsumo                DECIMAL(18,6) NOT NULL CONSTRAINT DF_msp_proceso_cobro_agua_sobreconsumo DEFAULT (0),
    interes_pf_plazo            DECIMAL(18,6) NOT NULL CONSTRAINT DF_msp_proceso_cobro_agua_interes DEFAULT (0),
    divisor                     DECIMAL(18,6) NOT NULL,
    cargo_fijo                  DECIMAL(18,6) NOT NULL,
    monto_total_factura         DECIMAL(18,2) NULL,

    CONSTRAINT PK_msp_proceso_cobro_agua PRIMARY KEY (id_proceso_cobro),
    CONSTRAINT FK_msp_proceso_cobro_agua_proceso
        FOREIGN KEY (id_proceso_cobro) REFERENCES dbo.msp_procesos_cobro_servicio (id_proceso_cobro),
    CONSTRAINT CK_msp_proceso_cobro_agua_lecturas CHECK (
        lectura_general_anterior IS NULL
        OR lectura_general_actual IS NULL
        OR lectura_general_actual >= lectura_general_anterior
    ),
    CONSTRAINT CK_msp_proceso_cobro_agua_componentes CHECK (
        servicio_agua_potable >= 0
        AND servicio_alcantarillado >= 0
        AND tratamiento_aguas_servidas >= 0
        AND sobreconsumo >= 0
        AND interes_pf_plazo >= 0
        AND divisor > 0
        AND cargo_fijo >= 0
        AND (monto_total_factura IS NULL OR monto_total_factura >= 0)
    )
);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_proceso_cobro_agua_valida_servicio
ON dbo.msp_proceso_cobro_agua
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = i.id_proceso_cobro
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        WHERE ts.codigo_servicio <> N'AGUA'
    )
    BEGIN
        ;THROW 50013, 'El detalle de agua solo se puede asociar a procesos del servicio AGUA.', 1;
    END;
END;
GO

/* =========================================================================
   6. IMPORTACION EXCEL (STAGING)
   ========================================================================= */

CREATE TABLE dbo.msp_import_lotes (
    id_lote                 INT IDENTITY(1,1) NOT NULL,
    periodo_facturacion     DATE NOT NULL,
    id_tipo_servicio        INT NOT NULL,
    nombre_archivo          NVARCHAR(255) NULL,
    usuario_carga           NVARCHAR(100) NULL,
    fecha_carga             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_import_lotes_fecha DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_import_lotes PRIMARY KEY (id_lote),
    CONSTRAINT FK_msp_import_lotes_servicio
        FOREIGN KEY (id_tipo_servicio) REFERENCES dbo.msp_tipos_servicio (id_tipo_servicio),
    CONSTRAINT CK_msp_import_lotes_periodo CHECK (DAY(periodo_facturacion) = 1)
);
GO

CREATE TABLE dbo.msp_import_lecturas (
    id_import_lectura        INT IDENTITY(1,1) NOT NULL,
    id_lote                  INT NOT NULL,
    fila_origen              INT NULL,
    cod_local                NVARCHAR(20) NOT NULL,
    codigo_medidor           NVARCHAR(100) NOT NULL,
    lectura_anterior         DECIMAL(18,4) NULL,
    lectura_actual           DECIMAL(18,4) NOT NULL,
    fecha_hasta_consumo      DATE NOT NULL,
    fecha_lectura            DATE NOT NULL,
    observaciones            NVARCHAR(500) NULL,

    CONSTRAINT PK_msp_import_lecturas PRIMARY KEY (id_import_lectura),
    CONSTRAINT FK_msp_import_lecturas_lote
        FOREIGN KEY (id_lote) REFERENCES dbo.msp_import_lotes (id_lote),
    CONSTRAINT CK_msp_import_lecturas_valores CHECK (
        (lectura_anterior IS NULL OR lectura_anterior >= 0)
        AND lectura_actual >= 0
    ),
    CONSTRAINT CK_msp_import_lecturas_fechas CHECK (fecha_lectura >= fecha_hasta_consumo)
);
GO

CREATE INDEX IX_msp_import_lecturas_lote
    ON dbo.msp_import_lecturas (id_lote, cod_local, codigo_medidor);
GO

/* =========================================================================
   7. LECTURAS DE MEDIDORES
   - periodo_facturacion: a que mes comercial/contable pertenece la lectura
   - fecha_desde_consumo / fecha_hasta_consumo: rango real auditado
   ========================================================================= */

CREATE TABLE dbo.msp_lecturas_medidores (
    id_lectura               INT IDENTITY(1,1) NOT NULL,
    id_proceso_cobro         INT NOT NULL,
    id_medidor               INT NOT NULL,
    id_origen_lectura        INT NOT NULL CONSTRAINT DF_msp_lecturas_medidores_origen DEFAULT (1),
    periodo_facturacion      DATE NOT NULL,
    fecha_desde_consumo      DATE NULL,
    fecha_hasta_consumo      DATE NOT NULL,
    fecha_lectura            DATE NOT NULL,
    lectura_anterior         DECIMAL(18,4) NULL,
    lectura_actual           DECIMAL(18,4) NOT NULL,
    consumo_informado        DECIMAL(18,4) NULL,
    observaciones            NVARCHAR(500) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_lecturas_medidores_fecha_registro DEFAULT (SYSDATETIME()),
    fecha_actualizacion      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_lecturas_medidores_fecha_actualizacion DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_lecturas_medidores PRIMARY KEY (id_lectura),
    CONSTRAINT FK_msp_lecturas_medidores_proceso
        FOREIGN KEY (id_proceso_cobro) REFERENCES dbo.msp_procesos_cobro_servicio (id_proceso_cobro),
    CONSTRAINT FK_msp_lecturas_medidores_medidor
        FOREIGN KEY (id_medidor) REFERENCES dbo.msp_medidores (id_medidor),
    CONSTRAINT FK_msp_lecturas_medidores_origen
        FOREIGN KEY (id_origen_lectura) REFERENCES dbo.msp_origen_lecturas (id_origen_lectura),
    CONSTRAINT CK_msp_lecturas_medidores_periodo CHECK (DAY(periodo_facturacion) = 1),
    CONSTRAINT CK_msp_lecturas_medidores_lecturas CHECK (
        (lectura_anterior IS NULL OR lectura_anterior >= 0)
        AND lectura_actual >= 0
        AND (lectura_anterior IS NULL OR lectura_actual >= lectura_anterior)
        AND (consumo_informado IS NULL OR consumo_informado >= 0)
    ),
    CONSTRAINT CK_msp_lecturas_medidores_fechas CHECK (
        fecha_hasta_consumo >= ISNULL(fecha_desde_consumo, fecha_hasta_consumo)
        AND fecha_lectura >= fecha_hasta_consumo
    )
);
GO

CREATE UNIQUE INDEX UX_msp_lecturas_medidores_proceso_medidor
    ON dbo.msp_lecturas_medidores (id_proceso_cobro, id_medidor);
GO

CREATE INDEX IX_msp_lecturas_medidores_periodo
    ON dbo.msp_lecturas_medidores (periodo_facturacion, fecha_hasta_consumo);
GO

CREATE INDEX IX_msp_lecturas_medidores_medidor
    ON dbo.msp_lecturas_medidores (id_medidor, periodo_facturacion);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_lecturas_medidores_consistencia
ON dbo.msp_lecturas_medidores
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = i.id_proceso_cobro
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = i.id_medidor
        WHERE p.id_tipo_servicio <> m.id_tipo_servicio
    )
    BEGIN
        ;THROW 50021, 'La lectura referencia un medidor cuyo servicio no coincide con el proceso de cobro.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = i.id_proceso_cobro
        INNER JOIN dbo.msp_cierre_mensual c
            ON c.id_cierre_mensual = p.id_cierre_mensual
        WHERE i.periodo_facturacion <> c.periodo_facturacion
    )
    BEGIN
        ;THROW 50022, 'La lectura fue registrada con un periodo_facturacion distinto al cierre mensual del proceso.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = i.id_medidor
        WHERE m.fecha_instalacion IS NOT NULL
          AND i.fecha_hasta_consumo < m.fecha_instalacion
    )
    BEGIN
        ;THROW 50023, 'La lectura usa una fecha_hasta_consumo anterior a la instalacion del medidor.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = i.id_medidor
        WHERE m.fecha_retiro IS NOT NULL
          AND i.fecha_hasta_consumo > m.fecha_retiro
    )
    BEGIN
        ;THROW 50024, 'La lectura usa una fecha_hasta_consumo posterior al retiro del medidor.', 1;
    END;
END;
GO

/* =========================================================================
   8. COBROS AUDITABLES POR MEDIDOR
   - Se persiste un cobro por lectura
   - Se guarda snapshot de parametros para auditoria historica
   ========================================================================= */

CREATE TABLE dbo.msp_cobros_servicios (
    id_cobro_servicio        INT IDENTITY(1,1) NOT NULL,
    id_lectura               INT NOT NULL,
    consumo_cobrado          DECIMAL(18,4) NOT NULL,
    subtotal_variable        DECIMAL(18,2) NOT NULL,
    cargo_fijo               DECIMAL(18,2) NOT NULL,
    monto_total              DECIMAL(18,2) NOT NULL,
    formula_version          NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cobros_servicios_formula_version DEFAULT (N'v1'),
    parametros_snapshot      NVARCHAR(MAX) NULL,
    detalle_calculo          NVARCHAR(MAX) NULL,
    fecha_calculo            DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobros_servicios_fecha_calculo DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_cobros_servicios PRIMARY KEY (id_cobro_servicio),
    CONSTRAINT FK_msp_cobros_servicios_lectura
        FOREIGN KEY (id_lectura) REFERENCES dbo.msp_lecturas_medidores (id_lectura),
    CONSTRAINT UQ_msp_cobros_servicios_lectura UNIQUE (id_lectura),
    CONSTRAINT CK_msp_cobros_servicios_valores CHECK (
        consumo_cobrado >= 0
        AND subtotal_variable >= 0
        AND cargo_fijo >= 0
        AND monto_total >= 0
    )
);
GO

CREATE INDEX IX_msp_cobros_servicios_fecha_calculo
    ON dbo.msp_cobros_servicios (fecha_calculo DESC);
GO

/* =========================================================================
   9. VISTA DE AUDITORIA
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_cobros_servicios_auditoria
AS
SELECT
    cs.id_cobro_servicio,
    c.periodo_facturacion,
    ts.codigo_servicio,
    ts.nombre_servicio,
    l.id_lectura,
    m.id_medidor,
    m.codigo_medidor,
    loc.id_local,
    loc.cdo_local,
    l.fecha_desde_consumo,
    l.fecha_hasta_consumo,
    l.fecha_lectura,
    cs.consumo_cobrado,
    cs.subtotal_variable,
    cs.cargo_fijo,
    cs.monto_total,
    cs.formula_version,
    cs.parametros_snapshot,
    cs.detalle_calculo,
    cs.fecha_calculo
FROM dbo.msp_cobros_servicios cs
INNER JOIN dbo.msp_lecturas_medidores l
    ON l.id_lectura = cs.id_lectura
INNER JOIN dbo.msp_procesos_cobro_servicio p
    ON p.id_proceso_cobro = l.id_proceso_cobro
INNER JOIN dbo.msp_cierre_mensual c
    ON c.id_cierre_mensual = p.id_cierre_mensual
INNER JOIN dbo.msp_tipos_servicio ts
    ON ts.id_tipo_servicio = p.id_tipo_servicio
INNER JOIN dbo.msp_medidores m
    ON m.id_medidor = l.id_medidor
INNER JOIN dbo.msp_locales loc
    ON loc.id_local = m.id_local;
GO

/* =========================================================================
   10. PROCEDIMIENTO: GENERAR COBROS AUDITABLES
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_generar_cobros_servicios_periodo
    @id_cierre_mensual       INT,
    @reemplazar              BIT = 0,
    @cobros_generados        INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;

    SET @cobros_generados = 0;

    IF @id_cierre_mensual IS NULL OR @id_cierre_mensual <= 0
    BEGIN
        ;THROW 50031, 'Debes indicar un cierre mensual valido.', 1;
    END;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50032, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @estado_cierre = 4
    BEGIN
        ;THROW 50033, 'No se pueden generar cobros sobre un cierre mensual anulado.', 1;
    END;

    IF @estado_cierre = 3
    BEGIN
        ;THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;
    END;

    IF @estado_cierre <> 1
    BEGIN
        ;THROW 50039, 'Solo se pueden generar cobros en período Borrador.', 1;
    END;

    IF OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NOT NULL
       AND EXISTS (
            SELECT 1
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion
       )
    BEGIN
        ;THROW 50034, 'Ya existen documentos emitidos para este periodo_facturacion. Regenera primero la capa documental.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm
                ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre_mensual;
        END
        ELSE
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_cobros_servicios cs
                INNER JOIN dbo.msp_lecturas_medidores lm
                    ON lm.id_lectura = cs.id_lectura
                INNER JOIN dbo.msp_procesos_cobro_servicio p
                    ON p.id_proceso_cobro = lm.id_proceso_cobro
                WHERE p.id_cierre_mensual = @id_cierre_mensual
            )
            BEGIN
                ;THROW 50035, 'Ya existen cobros auditables para este cierre mensual. Usa @reemplazar = 1 para regenerarlos.', 1;
            END;
        END;

        ;WITH base AS (
            SELECT
                lm.id_lectura,
                ts.codigo_servicio,
                COALESCE(
                    lm.consumo_informado,
                    lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
                ) AS consumo_cobrado,
                pl.valor_kwh,
                pg.factor,
                pg.valor_litro,
                pa.servicio_agua_potable,
                pa.servicio_alcantarillado,
                pa.tratamiento_aguas_servidas,
                pa.sobreconsumo,
                pa.interes_pf_plazo,
                pa.divisor,
                pa.cargo_fijo
            FROM dbo.msp_lecturas_medidores lm
            INNER JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_proceso_cobro = lm.id_proceso_cobro
            INNER JOIN dbo.msp_tipos_servicio ts
                ON ts.id_tipo_servicio = p.id_tipo_servicio
            LEFT JOIN dbo.msp_proceso_cobro_luz pl
                ON pl.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_gas pg
                ON pg.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_agua pa
                ON pa.id_proceso_cobro = p.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre_mensual
              AND p.estado_proceso <> 4
        )
        INSERT INTO dbo.msp_cobros_servicios (
            id_lectura,
            consumo_cobrado,
            subtotal_variable,
            cargo_fijo,
            monto_total,
            formula_version,
            parametros_snapshot,
            detalle_calculo
        )
        SELECT
            b.id_lectura,
            CAST(b.consumo_cobrado AS DECIMAL(18,4)),
            CAST(ROUND(calc.subtotal_variable, 2) AS DECIMAL(18,2)),
            CAST(ROUND(calc.cargo_fijo, 2) AS DECIMAL(18,2)),
            CAST(ROUND(calc.subtotal_variable + calc.cargo_fijo, 2) AS DECIMAL(18,2)),
            N'v1',
            calc.parametros_snapshot,
            calc.detalle_calculo
        FROM base b
        CROSS APPLY (
            SELECT
                subtotal_variable = CASE
                    WHEN b.codigo_servicio = N'LUZ'
                        THEN b.consumo_cobrado * ISNULL(b.valor_kwh, 0)
                    WHEN b.codigo_servicio = N'GAS'
                        THEN b.consumo_cobrado * ISNULL(b.factor, 0) * ISNULL(b.valor_litro, 0)
                    WHEN b.codigo_servicio = N'AGUA'
                        THEN b.consumo_cobrado * (
                            (
                                ISNULL(b.servicio_agua_potable, 0)
                                + ISNULL(b.servicio_alcantarillado, 0)
                                + ISNULL(b.tratamiento_aguas_servidas, 0)
                            ) / NULLIF(b.divisor, 0)
                        )
                    ELSE 0
                END,
                cargo_fijo = CASE
                    WHEN b.codigo_servicio = N'AGUA'
                        THEN ISNULL(b.cargo_fijo, 0)
                    ELSE 0
                END,
                parametros_snapshot = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(
                            N'{"servicio":"LUZ","valor_kwh":', CONVERT(NVARCHAR(50), ISNULL(b.valor_kwh, 0)), N'}'
                        )
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'{"servicio":"GAS","factor":', CONVERT(NVARCHAR(50), ISNULL(b.factor, 0)),
                            N',"valor_litro":', CONVERT(NVARCHAR(50), ISNULL(b.valor_litro, 0)), N'}'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(
                            N'{"servicio":"AGUA","servicio_agua_potable":', CONVERT(NVARCHAR(50), ISNULL(b.servicio_agua_potable, 0)),
                            N',"servicio_alcantarillado":', CONVERT(NVARCHAR(50), ISNULL(b.servicio_alcantarillado, 0)),
                            N',"tratamiento_aguas_servidas":', CONVERT(NVARCHAR(50), ISNULL(b.tratamiento_aguas_servidas, 0)),
                            N',"sobreconsumo":', CONVERT(NVARCHAR(50), ISNULL(b.sobreconsumo, 0)),
                            N',"interes_pf_plazo":', CONVERT(NVARCHAR(50), ISNULL(b.interes_pf_plazo, 0)),
                            N',"divisor":', CONVERT(NVARCHAR(50), ISNULL(b.divisor, 0)),
                            N',"cargo_fijo":', CONVERT(NVARCHAR(50), ISNULL(b.cargo_fijo, 0)),
                            N'}'
                        )
                    ELSE N'{}'
                END,
                detalle_calculo = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(
                            N'LUZ: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * valor_kwh(', FORMAT(ISNULL(b.valor_kwh, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'GAS: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * factor(', FORMAT(ISNULL(b.factor, 0), 'N6'),
                            N') * valor_litro(', FORMAT(ISNULL(b.valor_litro, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(
                            N'AGUA: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * ((SAP + SAL + TAS)/divisor) + cargo_fijo'
                        )
                    ELSE N'-'
                END
        ) calc;

        SET @cobros_generados = @@ROWCOUNT;

        UPDATE p
        SET p.estado_proceso = CASE
            WHEN p.estado_proceso = 4 THEN 4
            ELSE 2
        END
        FROM dbo.msp_procesos_cobro_servicio p
        WHERE p.id_cierre_mensual = @id_cierre_mensual;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_cierre_mensual AS id_cierre_mensual,
        @periodo_facturacion AS periodo_facturacion,
        @cobros_generados AS cobros_generados;
END;
GO

/* =========================================================================
   11. VISTA: PLANTILLA DE LECTURAS (EXPORT)
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_plantilla_lecturas
AS
SELECT
    loc.cdo_local AS cod_local,
    ts.codigo_servicio,
    ts.nombre_servicio,
    m.codigo_medidor,
    m.alias_medidor,
    ult.lectura_actual AS lectura_anterior,
    ult.fecha_hasta_consumo AS fecha_hasta_consumo_anterior
FROM dbo.msp_medidores m
INNER JOIN dbo.msp_locales loc
    ON loc.id_local = m.id_local
INNER JOIN dbo.msp_tipos_servicio ts
    ON ts.id_tipo_servicio = m.id_tipo_servicio
OUTER APPLY (
    SELECT TOP (1)
        lm.lectura_actual,
        lm.fecha_hasta_consumo
    FROM dbo.msp_lecturas_medidores lm
    WHERE lm.id_medidor = m.id_medidor
    ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
) ult
WHERE m.estado_medidor = 1
  AND m.fecha_retiro IS NULL;
GO

/* =========================================================================
   12. PROCEDIMIENTO: IMPORTAR LECTURAS DESDE LOTE
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_importar_lecturas_lote
    @id_lote            INT,
    @reemplazar         BIT = 0,
    @lecturas_insertadas INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @id_tipo_servicio INT;
    DECLARE @id_cierre_mensual INT;
    DECLARE @id_proceso_cobro INT;

    SET @lecturas_insertadas = 0;

    IF @id_lote IS NULL OR @id_lote <= 0
    BEGIN
        ;THROW 50101, 'Debes indicar un lote valido.', 1;
    END;

    SELECT
        @periodo_facturacion = l.periodo_facturacion,
        @id_tipo_servicio = l.id_tipo_servicio
    FROM dbo.msp_import_lotes l
    WHERE l.id_lote = @id_lote;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50102, 'El lote indicado no existe.', 1;
    END;

    SELECT @id_cierre_mensual = c.id_cierre_mensual
    FROM dbo.msp_cierre_mensual c
    WHERE c.periodo_facturacion = @periodo_facturacion;

    IF @id_cierre_mensual IS NULL
    BEGIN
        ;THROW 50103, 'No existe cierre mensual para el periodo del lote.', 1;
    END;

    SELECT @id_proceso_cobro = p.id_proceso_cobro
    FROM dbo.msp_procesos_cobro_servicio p
    WHERE p.id_cierre_mensual = @id_cierre_mensual
      AND p.id_tipo_servicio = @id_tipo_servicio;

    IF @id_proceso_cobro IS NULL
    BEGIN
        ;THROW 50104, 'No existe proceso de cobro para el servicio del lote.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_import_lecturas il
        LEFT JOIN dbo.msp_locales loc
            ON loc.cdo_local = il.cod_local
        WHERE il.id_lote = @id_lote
          AND loc.id_local IS NULL
    )
    BEGIN
        ;THROW 50105, 'Hay cod_local en el lote que no existen en msp_locales.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_import_lecturas il
        LEFT JOIN dbo.msp_medidores m
            ON m.codigo_medidor = il.codigo_medidor
        WHERE il.id_lote = @id_lote
          AND m.id_medidor IS NULL
    )
    BEGIN
        ;THROW 50106, 'Hay codigo_medidor en el lote que no existen en msp_medidores.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_import_lecturas il
        INNER JOIN dbo.msp_locales loc
            ON loc.cdo_local = il.cod_local
        INNER JOIN dbo.msp_medidores m
            ON m.codigo_medidor = il.codigo_medidor
        WHERE il.id_lote = @id_lote
          AND (m.id_local <> loc.id_local OR m.id_tipo_servicio <> @id_tipo_servicio)
    )
    BEGIN
        ;THROW 50107, 'Hay medidores que no corresponden al local o servicio del lote.', 1;
    END;

    IF @reemplazar = 1
    BEGIN
        DELETE lm
        FROM dbo.msp_lecturas_medidores lm
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        INNER JOIN dbo.msp_import_lecturas il
            ON il.codigo_medidor = m.codigo_medidor
           AND il.id_lote = @id_lote
        WHERE lm.id_proceso_cobro = @id_proceso_cobro;
    END
    ELSE
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM dbo.msp_lecturas_medidores lm
            INNER JOIN dbo.msp_medidores m
                ON m.id_medidor = lm.id_medidor
            INNER JOIN dbo.msp_import_lecturas il
                ON il.codigo_medidor = m.codigo_medidor
               AND il.id_lote = @id_lote
            WHERE lm.id_proceso_cobro = @id_proceso_cobro
        )
        BEGIN
            ;THROW 50108, 'Ya existen lecturas para medidores del lote en este proceso. Usa @reemplazar = 1.', 1;
        END;
    END;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_import_lecturas il
        INNER JOIN dbo.msp_medidores m
            ON m.codigo_medidor = il.codigo_medidor
        OUTER APPLY (
            SELECT TOP (1) lm.lectura_actual
            FROM dbo.msp_lecturas_medidores lm
            WHERE lm.id_medidor = m.id_medidor
            ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
        ) ult
        WHERE il.id_lote = @id_lote
          AND COALESCE(il.lectura_anterior, ult.lectura_actual, 0) > il.lectura_actual
    )
    BEGIN
        ;THROW 50109, 'Hay lecturas donde la lectura_actual es menor que la lectura_anterior.', 1;
    END;

    INSERT INTO dbo.msp_lecturas_medidores (
        id_proceso_cobro,
        id_medidor,
        id_origen_lectura,
        periodo_facturacion,
        fecha_desde_consumo,
        fecha_hasta_consumo,
        fecha_lectura,
        lectura_anterior,
        lectura_actual,
        consumo_informado,
        observaciones
    )
    SELECT
        @id_proceso_cobro,
        m.id_medidor,
        2,
        @periodo_facturacion,
        NULL,
        il.fecha_hasta_consumo,
        il.fecha_lectura,
        COALESCE(il.lectura_anterior, ult.lectura_actual),
        il.lectura_actual,
        NULL,
        il.observaciones
    FROM dbo.msp_import_lecturas il
    INNER JOIN dbo.msp_medidores m
        ON m.codigo_medidor = il.codigo_medidor
    OUTER APPLY (
        SELECT TOP (1) lm.lectura_actual
        FROM dbo.msp_lecturas_medidores lm
        WHERE lm.id_medidor = m.id_medidor
        ORDER BY lm.fecha_hasta_consumo DESC, lm.id_lectura DESC
    ) ult
    WHERE il.id_lote = @id_lote;

    SET @lecturas_insertadas = @@ROWCOUNT;
END;
GO

/* =========================================================================
   13. SALDOS A FAVOR POR TIENDA
   - saldo_disponible: credito utilizable para futuros documentos de la tienda
   - msp_movimientos_saldo_favor_tienda: libro auditable de movimientos
   Tipos:
     1 = Excedente de pago
     2 = Aplicacion a documento
     3 = Reversa excedente por anulacion
     4 = Reversa aplicacion por anulacion
     5 = Ajuste manual
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_saldos_favor_tienda', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldos_favor_tienda (
        id_tienda              INT NOT NULL,
        saldo_disponible       DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_saldos_favor_tienda_saldo DEFAULT (0),
        fecha_actualizacion    DATETIME2(0) NOT NULL CONSTRAINT DF_msp_saldos_favor_tienda_fecha DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldos_favor_tienda PRIMARY KEY (id_tienda),
        CONSTRAINT FK_msp_saldos_favor_tienda_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT CK_msp_saldos_favor_tienda_saldo CHECK (saldo_disponible >= 0)
    );
END;
GO

IF OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_movimientos_saldo_favor_tienda (
        id_movimiento_saldo_favor   INT IDENTITY(1,1) NOT NULL,
        id_tienda                   INT NOT NULL,
        fecha_movimiento            DATE NOT NULL CONSTRAINT DF_msp_mov_saldo_favor_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
        tipo_movimiento             TINYINT NOT NULL,
        monto_movimiento            DECIMAL(18,2) NOT NULL,
        id_documento_cobro          INT NULL,
        id_pago                     INT NULL,
        observaciones               NVARCHAR(500) NULL,
        fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_mov_saldo_favor_registro DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_movimientos_saldo_favor_tienda PRIMARY KEY (id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_mov_saldo_favor_tienda_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT CK_msp_mov_saldo_favor_tipo CHECK (tipo_movimiento IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_mov_saldo_favor_monto CHECK (monto_movimiento <> 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_movimientos_saldo_favor_tienda_tienda_fecha'
      AND object_id = OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U')
)
BEGIN
    CREATE INDEX IX_msp_movimientos_saldo_favor_tienda_tienda_fecha
        ON dbo.msp_movimientos_saldo_favor_tienda (id_tienda, fecha_movimiento DESC, id_movimiento_saldo_favor DESC);
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_saldo_favor_tienda_recalcula
ON dbo.msp_movimientos_saldo_favor_tienda
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @tiendas_afectadas TABLE (
        id_tienda INT NOT NULL PRIMARY KEY
    );

    INSERT INTO @tiendas_afectadas (id_tienda)
    SELECT DISTINCT i.id_tienda
    FROM inserted i
    WHERE i.id_tienda IS NOT NULL;

    INSERT INTO @tiendas_afectadas (id_tienda)
    SELECT DISTINCT d.id_tienda
    FROM deleted d
    WHERE d.id_tienda IS NOT NULL
      AND NOT EXISTS (
            SELECT 1
            FROM @tiendas_afectadas ta
            WHERE ta.id_tienda = d.id_tienda
      );

    IF NOT EXISTS (SELECT 1 FROM @tiendas_afectadas)
        RETURN;

    ;WITH saldo_calculado AS (
        SELECT
            ta.id_tienda,
            CAST(ISNULL(SUM(msf.monto_movimiento), 0) AS DECIMAL(18,2)) AS saldo_disponible
        FROM @tiendas_afectadas ta
        LEFT JOIN dbo.msp_movimientos_saldo_favor_tienda msf
            ON msf.id_tienda = ta.id_tienda
        GROUP BY ta.id_tienda
    )
    MERGE dbo.msp_saldos_favor_tienda AS target
    USING saldo_calculado AS source
        ON target.id_tienda = source.id_tienda
    WHEN MATCHED THEN
        UPDATE SET
            saldo_disponible = source.saldo_disponible,
            fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (id_tienda, saldo_disponible, fecha_actualizacion)
        VALUES (source.id_tienda, source.saldo_disponible, SYSDATETIME());
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_saldos_favor_tienda
AS
SELECT
    t.id_tienda,
    t.nombre_comercial,
    ISNULL(sf.saldo_disponible, 0) AS saldo_disponible,
    sf.fecha_actualizacion
FROM dbo.msp_tiendas t
LEFT JOIN dbo.msp_saldos_favor_tienda sf
    ON sf.id_tienda = t.id_tienda;
GO

PRINT 'P2';
GO
