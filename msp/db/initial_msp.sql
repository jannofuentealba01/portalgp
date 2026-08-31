/*
  MSP - INITIAL FULL SCRIPT
  Generado desde db/ para ejecutar en SSMS (modo normal, sin SQLCMD).
  Fecha de generacion: 2026-04-16 11:54:31 -0400
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;


/* ===================================================================== */
PRINT 'MSP initial: msp_agrupacion_locales.sql';
/* ===================================================================== */

/* =========================================================================
   INSTALACION A1 EN ESQUEMA DBO
   ========================================================================= */
/*
===========================================================================
 MSP - AREA GESTION AGRUPADA (A1)
 SQL Server
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS BASE
   ========================================================================= */

CREATE TABLE dbo.msp_rubros (
    id_rubro              INT IDENTITY(1,1) NOT NULL,
    nombre_rubro          NVARCHAR(150) NOT NULL,
    CONSTRAINT PK_msp_rubros PRIMARY KEY (id_rubro),
    CONSTRAINT UQ_msp_rubros_nombre UNIQUE (nombre_rubro)
);
GO

CREATE TABLE dbo.msp_estado_tiendas (
    id_estado_tienda      INT IDENTITY(1,1) NOT NULL,
    desc_estado           NVARCHAR(100) NOT NULL,
    CONSTRAINT PK_msp_estado_tiendas PRIMARY KEY (id_estado_tienda),
    CONSTRAINT UQ_msp_estado_tiendas_desc UNIQUE (desc_estado)
);
GO

CREATE TABLE dbo.msp_estado_locales (
    id_estado_local       INT IDENTITY(1,1) NOT NULL,
    desc_estado           NVARCHAR(100) NOT NULL,
    CONSTRAINT PK_msp_estado_locales PRIMARY KEY (id_estado_local),
    CONSTRAINT UQ_msp_estado_locales_desc UNIQUE (desc_estado)
);
GO

CREATE TABLE dbo.msp_estado_arrendatarios (
    id_estado_arrendatario INT IDENTITY(1,1) NOT NULL,
    desc_estado            NVARCHAR(100) NOT NULL,
    CONSTRAINT PK_msp_estado_arrendatarios PRIMARY KEY (id_estado_arrendatario),
    CONSTRAINT UQ_msp_estado_arrendatarios_desc UNIQUE (desc_estado)
);
GO

CREATE TABLE dbo.msp_comunas (
    id_comuna             INT IDENTITY(1,1) NOT NULL,
    desc_comuna           NVARCHAR(150) NOT NULL,
    CONSTRAINT PK_msp_comunas PRIMARY KEY (id_comuna),
    CONSTRAINT UQ_msp_comunas_desc UNIQUE (desc_comuna)
);
GO

INSERT INTO dbo.msp_rubros (nombre_rubro) VALUES
('Ropa y Accesorios'),
('Calzado'),
('Tecnología y Electrónica'),
('Hogar y Decoración'),
('Alimentos y Bebidas'),
('Salud y Belleza'),
('Deportes y Aire Libre'),
('Juguetes y Juegos'),
('Automotriz'),
('Servicios');
GO

INSERT INTO dbo.msp_estado_tiendas (desc_estado) VALUES
('Activo'),
('Inactivo'),
('En Renovación'),
('Cerrado');
GO

INSERT INTO dbo.msp_estado_locales (desc_estado) VALUES
('Disponible'),
('Ocupado'),
('En Mantenimiento'),
('Reservado');
GO

INSERT INTO dbo.msp_estado_arrendatarios (desc_estado) VALUES
('Activo'),
('Inactivo'),
('Sancionado'),
('En Revisión');
GO

INSERT INTO dbo.msp_comunas (desc_comuna) VALUES
('Concepción'),
('San Pedro de la Paz');
GO

/* =========================================================================
   2. MAESTRO ARRENDATARIOS
   ========================================================================= */

CREATE TABLE dbo.msp_arrendatarios (
    id_arrendatario       INT IDENTITY(1,1) NOT NULL,
    rut                   NVARCHAR(20) NOT NULL,
    es_empresa            BIT NOT NULL CONSTRAINT DF_msp_arrendatarios_es_empresa DEFAULT (0),
    nombre_locatario      NVARCHAR(200) NULL,
    nombre_representante  NVARCHAR(200) NULL,
    direccion             NVARCHAR(250) NULL,
    id_comuna             INT NULL,
    id_estado_arrendatario INT NOT NULL,
    CONSTRAINT PK_msp_arrendatarios PRIMARY KEY (id_arrendatario),
    CONSTRAINT FK_msp_arrendatarios_comuna
        FOREIGN KEY (id_comuna)
        REFERENCES dbo.msp_comunas (id_comuna),
    CONSTRAINT FK_msp_arrendatarios_estado
        FOREIGN KEY (id_estado_arrendatario)
        REFERENCES dbo.msp_estado_arrendatarios (id_estado_arrendatario),
    CONSTRAINT CK_msp_arrendatarios_rut_formato CHECK (
        rut LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9Kk]'
        OR rut LIKE '[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]-[0-9Kk]'
    )
);
GO

CREATE UNIQUE INDEX UX_msp_arrendatarios_rut
    ON dbo.msp_arrendatarios (rut);
GO

CREATE INDEX IX_msp_arrendatarios_comuna
    ON dbo.msp_arrendatarios (id_comuna);
GO

CREATE INDEX IX_msp_arrendatarios_estado
    ON dbo.msp_arrendatarios (id_estado_arrendatario);
GO

/* =========================================================================
   3. CORREOS DE ARRENDATARIOS
   ========================================================================= */

CREATE TABLE dbo.msp_arrendatarios_correos (
    id_arrendatario_correo INT IDENTITY(1,1) NOT NULL,
    id_arrendatario        INT NOT NULL,
    correo                 NVARCHAR(200) NOT NULL,
    es_principal           BIT NOT NULL CONSTRAINT DF_msp_arrendatarios_correos_es_principal DEFAULT (0),
    fecha_registro         DATETIME2(0) NOT NULL CONSTRAINT DF_msp_arrendatarios_correos_fecha_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_arrendatarios_correos PRIMARY KEY (id_arrendatario_correo),
    CONSTRAINT FK_msp_arrendatarios_correos_arrendatario
        FOREIGN KEY (id_arrendatario)
        REFERENCES dbo.msp_arrendatarios (id_arrendatario),
    CONSTRAINT CK_msp_arrendatarios_correos_formato CHECK (
        correo LIKE '%_@_%._%'
        AND correo NOT LIKE '% %'
    )
);
GO

CREATE UNIQUE INDEX UX_msp_arrendatarios_correos_arrendatario_correo
    ON dbo.msp_arrendatarios_correos (id_arrendatario, correo);
GO

CREATE UNIQUE INDEX UX_msp_arrendatarios_correos_principal
    ON dbo.msp_arrendatarios_correos (id_arrendatario)
    WHERE es_principal = 1;
GO

CREATE INDEX IX_msp_arrendatarios_correos_arrendatario
    ON dbo.msp_arrendatarios_correos (id_arrendatario);
GO

/* =========================================================================
   4. TELEFONOS DE ARRENDATARIOS
   ========================================================================= */

CREATE TABLE dbo.msp_arrendatarios_telefonos (
    id_arrendatario_telefono INT IDENTITY(1,1) NOT NULL,
    id_arrendatario          INT NOT NULL,
    nombre_contacto          NVARCHAR(200) NULL,
    telefono                 NVARCHAR(50) NOT NULL,
    es_principal             BIT NOT NULL CONSTRAINT DF_msp_arrendatarios_telefonos_es_principal DEFAULT (0),
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_arrendatarios_telefonos_fecha_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_arrendatarios_telefonos PRIMARY KEY (id_arrendatario_telefono),
    CONSTRAINT FK_msp_arrendatarios_telefonos_arrendatario
        FOREIGN KEY (id_arrendatario)
        REFERENCES dbo.msp_arrendatarios (id_arrendatario)
);
GO

CREATE UNIQUE INDEX UX_msp_arrendatarios_telefonos_arrendatario_telefono
    ON dbo.msp_arrendatarios_telefonos (id_arrendatario, telefono);
GO

CREATE UNIQUE INDEX UX_msp_arrendatarios_telefonos_principal
    ON dbo.msp_arrendatarios_telefonos (id_arrendatario)
    WHERE es_principal = 1;
GO

CREATE INDEX IX_msp_arrendatarios_telefonos_arrendatario
    ON dbo.msp_arrendatarios_telefonos (id_arrendatario);
GO

/* =========================================================================
   5. MAESTRO LOCALES
   ========================================================================= */

CREATE TABLE dbo.msp_locales (
    id_local              INT IDENTITY(1,1) NOT NULL,
    cdo_local             NVARCHAR(20) NOT NULL,
    desc_local            NVARCHAR(200) NOT NULL,
    id_estado_local       INT NOT NULL,
    metros_cuadrados      DECIMAL(12,2) NOT NULL,
    valor_arriendo_uf     DECIMAL(12,4) NOT NULL,
    CONSTRAINT PK_msp_locales PRIMARY KEY (id_local),
    CONSTRAINT FK_msp_locales_estado
        FOREIGN KEY (id_estado_local)
        REFERENCES dbo.msp_estado_locales (id_estado_local),
    CONSTRAINT CK_msp_locales_metros_cuadrados CHECK (metros_cuadrados >= 0),
    CONSTRAINT CK_msp_locales_valor_arriendo_uf CHECK (valor_arriendo_uf >= 0)
);
GO

CREATE UNIQUE INDEX UX_msp_locales_cdo_local
    ON dbo.msp_locales (cdo_local);
GO

CREATE INDEX IX_msp_locales_estado
    ON dbo.msp_locales (id_estado_local);
GO

/* =========================================================================
   6. MAESTRO TIENDAS
   ========================================================================= */

CREATE TABLE dbo.msp_tiendas (
    id_tienda             INT IDENTITY(1,1) NOT NULL,
    id_rubro              INT NOT NULL,
    id_arrendatario       INT NOT NULL,
    id_estado_tienda      INT NOT NULL,
    nombre_comercial      NVARCHAR(200) NOT NULL,
    fecha_inicio          DATE NULL,
    fecha_termino         DATE NULL,
    fecha_registro        DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tiendas_fecha_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_tiendas PRIMARY KEY (id_tienda),
    CONSTRAINT FK_msp_tiendas_rubro
        FOREIGN KEY (id_rubro)
        REFERENCES dbo.msp_rubros (id_rubro),
    CONSTRAINT FK_msp_tiendas_arrendatario
        FOREIGN KEY (id_arrendatario)
        REFERENCES dbo.msp_arrendatarios (id_arrendatario),
    CONSTRAINT FK_msp_tiendas_estado
        FOREIGN KEY (id_estado_tienda)
        REFERENCES dbo.msp_estado_tiendas (id_estado_tienda),
    CONSTRAINT CK_msp_tiendas_fechas CHECK (
        fecha_inicio IS NULL
        OR fecha_termino IS NULL
        OR fecha_termino >= fecha_inicio
    )
);
GO

CREATE INDEX IX_msp_tiendas_arrendatario
    ON dbo.msp_tiendas (id_arrendatario);
GO

CREATE INDEX IX_msp_tiendas_rubro
    ON dbo.msp_tiendas (id_rubro);
GO

CREATE INDEX IX_msp_tiendas_estado
    ON dbo.msp_tiendas (id_estado_tienda);
GO

/* =========================================================================
   7. OCUPACION DE LOCALES
   ========================================================================= */

CREATE TABLE dbo.msp_ocupacion_locales (
    id_ocupacion_local    INT IDENTITY(1,1) NOT NULL,
    id_tienda             INT NOT NULL,
    id_local              INT NOT NULL,
    fecha_inicio          DATE NOT NULL,
    fecha_termino         DATE NULL,
    fecha_registro        DATETIME2(0) NOT NULL CONSTRAINT DF_msp_ocupacion_locales_fecha_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_ocupacion_locales PRIMARY KEY (id_ocupacion_local),
    CONSTRAINT FK_msp_ocupacion_locales_tienda
        FOREIGN KEY (id_tienda)
        REFERENCES dbo.msp_tiendas (id_tienda),
    CONSTRAINT FK_msp_ocupacion_locales_local
        FOREIGN KEY (id_local)
        REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT CK_msp_ocupacion_locales_fechas CHECK (
        fecha_termino IS NULL OR fecha_termino >= fecha_inicio
    )
);
GO

CREATE UNIQUE INDEX UX_msp_ocupacion_locales_tienda_local_inicio
    ON dbo.msp_ocupacion_locales (id_tienda, id_local, fecha_inicio);
GO

CREATE INDEX IX_msp_ocupacion_locales_local
    ON dbo.msp_ocupacion_locales (id_local);
GO

CREATE INDEX IX_msp_ocupacion_locales_tienda
    ON dbo.msp_ocupacion_locales (id_tienda);
GO

/* =========================================================================
   8. TRIGGER DE INTEGRIDAD DE OCUPACION
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_ocupacion_locales_no_solapamiento
ON dbo.msp_ocupacion_locales
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_ocupacion_locales o
        INNER JOIN inserted i
            ON o.id_local = i.id_local
           AND o.id_ocupacion_local <> i.id_ocupacion_local
           AND i.fecha_inicio <= ISNULL(o.fecha_termino, CONVERT(date, '9999-12-31'))
           AND o.fecha_inicio <= ISNULL(i.fecha_termino, CONVERT(date, '9999-12-31'))
    )
    BEGIN
        ;THROW 50001, 'No se puede asignar un local a dos tiendas en periodos solapados.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i1
        INNER JOIN inserted i2
            ON i1.id_local = i2.id_local
           AND i1.id_ocupacion_local <> i2.id_ocupacion_local
           AND i1.fecha_inicio <= ISNULL(i2.fecha_termino, CONVERT(date, '9999-12-31'))
           AND i2.fecha_inicio <= ISNULL(i1.fecha_termino, CONVERT(date, '9999-12-31'))
    )
    BEGIN
        ;THROW 50002, 'El lote contiene ocupaciones solapadas para el mismo local.', 1;
    END;
END;
GO

PRINT 'P1';
GO


/* =========================================================================
   9. NOTAS
   =========================================================================

1. Se normaliza el esquema de nombres con prefijo `msp_` en tablas, indices
   y constraints para mantener trazabilidad del modulo.
2. Se usa `id_local` numerico como PK y `cdo_local` como identificador
   de negocio unico.
3. El indice unico de ocupacion evita duplicados exactos, pero el trigger
   sigue siendo necesario para bloquear solapamientos de rango de fechas.
4. Los datos de contacto se gestionan en tablas dedicadas:
   `msp_arrendatarios_correos` y `msp_arrendatarios_telefonos`.
*/

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_cobro_servicios.sql';
/* ===================================================================== */

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

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_documento_pago.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - DOCUMENTO Y PAGOS (A22)
 SQL Server / esquema dbo
 - Requiere A1 + A21 ya instalados
 - Esta capa cubre: documento mensual por tienda, detalle y pagos parciales
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS COMERCIALES
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_tipo_item_documento', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tipo_item_documento (
        id_tipo_item_documento   INT NOT NULL,
        codigo_item             NVARCHAR(30) NOT NULL,
        nombre_item             NVARCHAR(100) NOT NULL,
        CONSTRAINT PK_msp_tipo_item_documento PRIMARY KEY (id_tipo_item_documento),
        CONSTRAINT UQ_msp_tipo_item_documento_codigo UNIQUE (codigo_item),
        CONSTRAINT UQ_msp_tipo_item_documento_nombre UNIQUE (nombre_item)
    );
END;
GO

MERGE dbo.msp_tipo_item_documento AS t
USING (
    SELECT 1 AS id_tipo_item_documento, N'ARRIENDO' AS codigo_item, N'Arriendo' AS nombre_item
    UNION ALL
    SELECT 2, N'SERVICIO_AGUA', N'Servicio Agua'
    UNION ALL
    SELECT 3, N'SERVICIO_LUZ', N'Servicio Luz'
    UNION ALL
    SELECT 4, N'SERVICIO_GAS', N'Servicio Gas'
    UNION ALL
    SELECT 5, N'AJUSTE', N'Ajuste'
) AS s
ON t.id_tipo_item_documento = s.id_tipo_item_documento
WHEN MATCHED THEN
    UPDATE SET
        codigo_item = s.codigo_item,
        nombre_item = s.nombre_item
WHEN NOT MATCHED THEN
    INSERT (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (s.id_tipo_item_documento, s.codigo_item, s.nombre_item);
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_tipo_item_documento
    WHERE codigo_item = N'MULTA'
)
BEGIN
    DECLARE @id_tipo_multa INT;
    SELECT @id_tipo_multa = ISNULL(MAX(id_tipo_item_documento), 0) + 1
    FROM dbo.msp_tipo_item_documento;

    INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (@id_tipo_multa, N'MULTA', N'Multa');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_tipo_item_documento
    WHERE codigo_item = N'DANO'
)
BEGIN
    DECLARE @id_tipo_dano INT;
    SELECT @id_tipo_dano = ISNULL(MAX(id_tipo_item_documento), 0) + 1
    FROM dbo.msp_tipo_item_documento;

    INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (@id_tipo_dano, N'DANO', N'Dano');
END;
GO

/* =========================================================================
   2. DOCUMENTOS DE COBRO
   Estado:
     1 = Borrador
     2 = Emitido
     3 = Pagado Parcial
     4 = Pagado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_documentos_cobro (
    id_documento_cobro         INT IDENTITY(1,1) NOT NULL,
    id_tienda                  INT NOT NULL,
    periodo_facturacion        DATE NOT NULL,
    numero_documento           NVARCHAR(50) NULL,
    fecha_emision              DATE NOT NULL,
    fecha_vencimiento          DATE NOT NULL,

    rut_arrendatario_snapshot  NVARCHAR(20) NOT NULL,
    nombre_arrendatario_snapshot NVARCHAR(200) NOT NULL,
    nombre_tienda_snapshot     NVARCHAR(200) NOT NULL,

    subtotal_arriendo          DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_documentos_cobro_subtotal_arriendo DEFAULT (0),
    subtotal_servicios         DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_documentos_cobro_subtotal_servicios DEFAULT (0),
    monto_total                DECIMAL(18,2) NOT NULL,
    saldo_pendiente            DECIMAL(18,2) NOT NULL,

    estado_documento           TINYINT NOT NULL CONSTRAINT DF_msp_documentos_cobro_estado DEFAULT (1),
    observaciones              NVARCHAR(1000) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_documentos_cobro_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_documentos_cobro PRIMARY KEY (id_documento_cobro),
    CONSTRAINT FK_msp_documentos_cobro_tienda
        FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
    CONSTRAINT UQ_msp_documentos_cobro_tienda_periodo UNIQUE (id_tienda, periodo_facturacion),
    CONSTRAINT CK_msp_documentos_cobro_periodo CHECK (DAY(periodo_facturacion) = 1),
    CONSTRAINT CK_msp_documentos_cobro_fechas CHECK (fecha_vencimiento >= fecha_emision),
    CONSTRAINT CK_msp_documentos_cobro_estado CHECK (estado_documento IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_documentos_cobro_montos CHECK (
        subtotal_arriendo >= 0
        AND subtotal_servicios >= 0
        AND monto_total >= 0
        AND saldo_pendiente >= 0
    )
);
GO

CREATE INDEX IX_msp_documentos_cobro_periodo
    ON dbo.msp_documentos_cobro (periodo_facturacion, estado_documento);
GO

CREATE INDEX IX_msp_documentos_cobro_numero
    ON dbo.msp_documentos_cobro (numero_documento);
GO

/* =========================================================================
   3. DETALLE DEL DOCUMENTO
   ========================================================================= */

CREATE TABLE dbo.msp_documentos_cobro_detalle (
    id_detalle_documento       INT IDENTITY(1,1) NOT NULL,
    id_documento_cobro         INT NOT NULL,
    orden_item                 INT NOT NULL CONSTRAINT DF_msp_documentos_cobro_detalle_orden DEFAULT (1),
    id_tipo_item_documento     INT NOT NULL,
    descripcion_item           NVARCHAR(255) NOT NULL,
    cantidad                   DECIMAL(18,4) NOT NULL CONSTRAINT DF_msp_documentos_cobro_detalle_cantidad DEFAULT (1),
    valor_unitario             DECIMAL(18,2) NOT NULL,
    subtotal                   DECIMAL(18,2) NOT NULL,
    id_cobro_servicio          INT NULL,

    CONSTRAINT PK_msp_documentos_cobro_detalle PRIMARY KEY (id_detalle_documento),
    CONSTRAINT FK_msp_documentos_cobro_detalle_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_documentos_cobro_detalle_tipo
        FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
    CONSTRAINT FK_msp_documentos_cobro_detalle_cobro
        FOREIGN KEY (id_cobro_servicio) REFERENCES dbo.msp_cobros_servicios (id_cobro_servicio),
    CONSTRAINT CK_msp_documentos_cobro_detalle_montos CHECK (
        orden_item > 0
        AND cantidad > 0
        AND valor_unitario >= 0
        AND subtotal >= 0
    )
);
GO

CREATE INDEX IX_msp_documentos_cobro_detalle_documento
    ON dbo.msp_documentos_cobro_detalle (id_documento_cobro, orden_item);
GO

/* =========================================================================
   4. PAGOS
   Estado:
     1 = Aplicado
     2 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_pagos (
    id_pago                    INT IDENTITY(1,1) NOT NULL,
    id_documento_cobro         INT NOT NULL,
    fecha_pago                 DATE NOT NULL,
    monto_pagado               DECIMAL(18,2) NOT NULL,
    monto_saldo_favor_generado DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pagos_saldo_favor_generado DEFAULT (0),
    aplica_desde_saldo_favor   BIT NOT NULL CONSTRAINT DF_msp_pagos_aplica_saldo_favor DEFAULT (0),
    estado_pago                TINYINT NOT NULL CONSTRAINT DF_msp_pagos_estado DEFAULT (1),
    fecha_anulacion            DATE NULL,
    motivo_anulacion           NVARCHAR(500) NULL,
    medio_pago                 NVARCHAR(50) NULL,
    referencia_pago            NVARCHAR(100) NULL,
    observaciones              NVARCHAR(500) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_pagos PRIMARY KEY (id_pago),
    CONSTRAINT FK_msp_pagos_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT CK_msp_pagos_estado CHECK (estado_pago IN (1,2)),
    CONSTRAINT CK_msp_pagos_monto CHECK (
        monto_pagado > 0
        AND monto_saldo_favor_generado >= 0
        AND (aplica_desde_saldo_favor = 0 OR monto_saldo_favor_generado = 0)
    ),
    CONSTRAINT CK_msp_pagos_anulacion CHECK (
        (estado_pago = 1 AND fecha_anulacion IS NULL AND motivo_anulacion IS NULL)
        OR
        (
            estado_pago = 2
            AND fecha_anulacion IS NOT NULL
            AND motivo_anulacion IS NOT NULL
            AND LTRIM(RTRIM(motivo_anulacion)) <> ''
        )
    )
);
GO

CREATE INDEX IX_msp_pagos_documento_estado
    ON dbo.msp_pagos (id_documento_cobro, estado_pago, fecha_pago);
GO

/* =========================================================================
   5. DETALLE DE PAGO POR CONCEPTO
   ========================================================================= */

CREATE TABLE dbo.msp_pagos_detalle_concepto (
    id_detalle_pago_concepto      INT IDENTITY(1,1) NOT NULL,
    id_pago                       INT NOT NULL,
    id_documento_cobro            INT NOT NULL,
    id_tipo_item_documento        INT NOT NULL,
    monto_aplicado                DECIMAL(18,2) NOT NULL,
    fecha_registro                DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_detalle_concepto_fecha DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_pagos_detalle_concepto PRIMARY KEY (id_detalle_pago_concepto),
    CONSTRAINT FK_msp_pagos_detalle_concepto_pago
        FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
    CONSTRAINT FK_msp_pagos_detalle_concepto_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_pagos_detalle_concepto_tipo
        FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
    CONSTRAINT UQ_msp_pagos_detalle_concepto_pago_tipo UNIQUE (id_pago, id_tipo_item_documento),
    CONSTRAINT CK_msp_pagos_detalle_concepto_monto CHECK (monto_aplicado > 0)
);
GO

CREATE INDEX IX_msp_pagos_detalle_concepto_documento_tipo
    ON dbo.msp_pagos_detalle_concepto (id_documento_cobro, id_tipo_item_documento, id_pago);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_detalle_concepto_valida_documento
ON dbo.msp_pagos_detalle_concepto
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = i.id_pago
        WHERE p.id_documento_cobro <> i.id_documento_cobro
    )
    BEGIN
        ;THROW 50120, 'El documento del detalle de concepto no coincide con el documento del pago.', 1;
    END;
END;
GO

/* =========================================================================
   6. TRIGGER DE RECALCULO DE DOCUMENTO
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_recalcula_documento
ON dbo.msp_pagos
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @documentos_afectados TABLE (
        id_documento_cobro INT NOT NULL PRIMARY KEY
    );

    INSERT INTO @documentos_afectados (id_documento_cobro)
    SELECT DISTINCT i.id_documento_cobro
    FROM inserted i
    WHERE i.id_documento_cobro IS NOT NULL;

    INSERT INTO @documentos_afectados (id_documento_cobro)
    SELECT DISTINCT d.id_documento_cobro
    FROM deleted d
    WHERE d.id_documento_cobro IS NOT NULL
      AND NOT EXISTS (
            SELECT 1
            FROM @documentos_afectados x
            WHERE x.id_documento_cobro = d.id_documento_cobro
      );

    IF NOT EXISTS (SELECT 1 FROM @documentos_afectados)
        RETURN;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = i.id_documento_cobro
        WHERE i.estado_pago = 1
          AND dc.estado_documento = 5
    )
    BEGIN
        ;THROW 50041, 'No se pueden aplicar pagos sobre documentos anulados.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM @documentos_afectados da
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = da.id_documento_cobro
        CROSS APPLY (
            SELECT ISNULL(SUM(p.monto_pagado), 0) AS total_pagado
            FROM dbo.msp_pagos p
            WHERE p.id_documento_cobro = da.id_documento_cobro
              AND p.estado_pago = 1
        ) tp
        WHERE tp.total_pagado > dc.monto_total
    )
    BEGIN
        ;THROW 50042, 'El pago excede el monto total del documento.', 1;
    END;

    UPDATE dc
    SET dc.saldo_pendiente = ROUND(dc.monto_total - tp.total_pagado, 2),
        dc.estado_documento = CASE
            WHEN dc.estado_documento = 5 THEN 5
            WHEN tp.total_pagado <= 0 THEN 2
            WHEN tp.total_pagado < dc.monto_total THEN 3
            ELSE 4
        END
    FROM dbo.msp_documentos_cobro dc
    INNER JOIN @documentos_afectados da
        ON da.id_documento_cobro = dc.id_documento_cobro
    CROSS APPLY (
        SELECT ISNULL(SUM(p.monto_pagado), 0) AS total_pagado
        FROM dbo.msp_pagos p
        WHERE p.id_documento_cobro = dc.id_documento_cobro
          AND p.estado_pago = 1
    ) tp
    WHERE dc.estado_documento <> 5;
END;
GO

/* =========================================================================
   7. VISTA RESUMEN DE DOCUMENTOS
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_documentos_cobro_resumen
AS
SELECT
    dc.id_documento_cobro,
    dc.periodo_facturacion,
    dc.numero_documento,
    dc.fecha_emision,
    dc.fecha_vencimiento,
    dc.id_tienda,
    dc.nombre_tienda_snapshot,
    dc.rut_arrendatario_snapshot,
    dc.nombre_arrendatario_snapshot,
    dc.subtotal_arriendo,
    dc.subtotal_servicios,
    dc.monto_total,
    dc.saldo_pendiente,
    dc.estado_documento
FROM dbo.msp_documentos_cobro dc;
GO

/* =========================================================================
   8. PROCEDIMIENTO: GENERAR DOCUMENTOS DEL PERIODO
   - Arriendo: usa ocupacion que cruza cualquier dia del periodo_facturacion
   - Servicios: mapea al arrendatario segun ocupacion del periodo_facturacion
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_generar_documentos_cobro_periodo
    @id_cierre_mensual        INT,
    @fecha_emision            DATE = NULL,
    @dias_vencimiento         INT = 10,
    @reemplazar               BIT = 0,
    @documentos_generados     INT OUTPUT,
    @items_generados          INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @valor_uf DECIMAL(18,6);
    DECLARE @tasa_iva DECIMAL(9,6) = 0.19;

    DECLARE @id_item_arriendo INT;
    DECLARE @id_item_agua INT;
    DECLARE @id_item_luz INT;
    DECLARE @id_item_gas INT;

    SET @documentos_generados = 0;
    SET @items_generados = 0;

    IF @id_cierre_mensual IS NULL OR @id_cierre_mensual <= 0
    BEGIN
        ;THROW 50051, 'Debes indicar un cierre mensual valido.', 1;
    END;

    IF @dias_vencimiento < 0 OR @dias_vencimiento > 120
    BEGIN
        ;THROW 50052, 'Los dias de vencimiento deben estar entre 0 y 120.', 1;
    END;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre,
        @valor_uf = c.valor_uf
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50053, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @estado_cierre = 4
    BEGIN
        ;THROW 50033, 'No se pueden generar documentos sobre un cierre mensual anulado.', 1;
    END;

    IF @estado_cierre = 3
    BEGIN
        ;THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;
    END;

    IF @estado_cierre <> 1
    BEGIN
        ;THROW 50039, 'Solo se pueden generar documentos en período Borrador.', 1;
    END;

    SET @fecha_emision = ISNULL(@fecha_emision, CONVERT(date, SYSDATETIME()));

    SELECT @id_item_arriendo = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'ARRIENDO';

    SELECT @id_item_agua = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_AGUA';

    SELECT @id_item_luz = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_LUZ';

    SELECT @id_item_gas = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_GAS';

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_pagos p
                    ON p.id_documento_cobro = dc.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion
                  AND p.estado_pago = 1
            )
            BEGIN
                ;THROW 50054, 'No se puede regenerar el periodo porque existen pagos aplicados.', 1;
            END;

            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo_facturacion;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion;
        END
        ELSE
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.periodo_facturacion = @periodo_facturacion
            )
            BEGIN
                ;THROW 50055, 'Ya existen documentos para ese periodo_facturacion. Usa @reemplazar = 1 si quieres regenerarlos.', 1;
            END;
        END;

        CREATE TABLE #arriendo_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #arriendo_tienda (id_tienda, subtotal_arriendo)
        SELECT
            ca.id_tienda,
            SUM(ROUND(loc.valor_arriendo_uf * @valor_uf, 2)) AS subtotal_arriendo
        FROM dbo.msp_contrato_locales cl
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
        INNER JOIN dbo.msp_locales loc
            ON loc.id_local = cl.id_local
        WHERE cl.estado_relacion = 1
          AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
          AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
          AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
          AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
          AND ca.estado_contrato IN (1,2,3)
        GROUP BY ca.id_tienda;

        CREATE TABLE #servicios_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #servicios_tienda (id_tienda, subtotal_servicios)
        SELECT
            map.id_tienda,
            SUM(cs.monto_total) AS subtotal_servicios
        FROM dbo.msp_cobros_servicios cs
        INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL
        GROUP BY map.id_tienda;

        CREATE TABLE #documentos_base (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #documentos_base (id_tienda, subtotal_arriendo, subtotal_servicios)
        SELECT
            x.id_tienda,
            SUM(x.subtotal_arriendo) AS subtotal_arriendo,
            SUM(x.subtotal_servicios) AS subtotal_servicios
        FROM (
            SELECT at.id_tienda, at.subtotal_arriendo, CAST(0 AS DECIMAL(18,2)) AS subtotal_servicios
            FROM #arriendo_tienda at
            UNION ALL
            SELECT st.id_tienda, CAST(0 AS DECIMAL(18,2)), st.subtotal_servicios
            FROM #servicios_tienda st
        ) x
        GROUP BY x.id_tienda;

        INSERT INTO dbo.msp_documentos_cobro (
            id_tienda,
            periodo_facturacion,
            numero_documento,
            fecha_emision,
            fecha_vencimiento,
            rut_arrendatario_snapshot,
            nombre_arrendatario_snapshot,
            nombre_tienda_snapshot,
            subtotal_arriendo,
            subtotal_servicios,
            monto_total,
            saldo_pendiente,
            estado_documento,
            observaciones
        )
        SELECT
            t.id_tienda,
            @periodo_facturacion,
            CONCAT(CONVERT(CHAR(6), @periodo_facturacion, 112), N'-', t.id_tienda),
            @fecha_emision,
            DATEADD(DAY, @dias_vencimiento, @fecha_emision),
            a.rut,
            COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), a.rut),
            t.nombre_comercial,
            ROUND(db.subtotal_arriendo, 2),
            ROUND(db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            2,
            CONCAT(N'Documento generado desde cierre #', @id_cierre_mensual, N'.')
        FROM #documentos_base db
        INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = db.id_tienda
        INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
        WHERE db.subtotal_arriendo > 0
           OR db.subtotal_servicios > 0;

        SET @documentos_generados = @@ROWCOUNT;

        INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
        )
        SELECT
            dc.id_documento_cobro,
            ROW_NUMBER() OVER (
                PARTITION BY dc.id_documento_cobro
                ORDER BY
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key
            ),
            @id_item_arriendo,
            CONCAT(N'Arriendo local ', loc.cdo_local),
            1,
            ROUND(loc.valor_arriendo_uf * @valor_uf, 2),
            ROUND(loc.valor_arriendo_uf * @valor_uf, 2),
            NULL
        FROM dbo.msp_documentos_cobro dc
        INNER JOIN dbo.msp_contratos_arriendo ca
            ON ca.id_tienda = dc.id_tienda
           AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
           AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
           AND ca.estado_contrato IN (1,2,3)
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
           AND cl.estado_relacion = 1
           AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
           AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
        INNER JOIN dbo.msp_locales loc
            ON loc.id_local = cl.id_local
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(loc.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(loc.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        WHERE dc.periodo_facturacion = @periodo_facturacion;

        SET @items_generados = @items_generados + @@ROWCOUNT;

        INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
        )
        SELECT
            dc.id_documento_cobro,
            1000 + ROW_NUMBER() OVER (
                PARTITION BY dc.id_documento_cobro
                ORDER BY
                    ts.codigo_servicio,
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key,
                    m.codigo_medidor
            ),
            CASE ts.codigo_servicio
                WHEN N'AGUA' THEN @id_item_agua
                WHEN N'LUZ'  THEN @id_item_luz
                WHEN N'GAS'  THEN @id_item_gas
                ELSE @id_item_gas
            END,
            CONCAT(ts.nombre_servicio, N' local ', loc.cdo_local, N' medidor ', m.codigo_medidor),
            CASE WHEN cs.consumo_cobrado > 0 THEN cs.consumo_cobrado ELSE 1 END,
            CASE
                WHEN cs.consumo_cobrado > 0 THEN ROUND(cs.monto_total / cs.consumo_cobrado, 2)
                ELSE cs.monto_total
            END,
            cs.monto_total,
            cs.id_cobro_servicio
        FROM dbo.msp_cobros_servicios cs
        INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(loc.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(loc.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_tienda = map.id_tienda
           AND dc.periodo_facturacion = @periodo_facturacion
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL;

        SET @items_generados = @items_generados + @@ROWCOUNT;

        ;WITH resumen AS (
            SELECT
                dcd.id_documento_cobro,
                SUM(CASE WHEN tid.codigo_item = N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_arriendo,
                SUM(CASE WHEN tid.codigo_item <> N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_servicios
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo_facturacion
            GROUP BY dcd.id_documento_cobro
        )
        UPDATE dc
        SET dc.subtotal_arriendo = ROUND(r.subtotal_arriendo, 2),
            dc.subtotal_servicios = ROUND(r.subtotal_servicios, 2),
            dc.monto_total = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.saldo_pendiente = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.estado_documento = 2
        FROM dbo.msp_documentos_cobro dc
        INNER JOIN resumen r
            ON r.id_documento_cobro = dc.id_documento_cobro;

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
        @documentos_generados AS documentos_generados,
        @items_generados AS items_generados;
END;
GO

/* =========================================================================
   8. PROCEDIMIENTO: GUARDAR DETALLE DE PAGO POR CONCEPTO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos
    @id_pago                     INT,
    @id_documento_cobro          INT,
    @monto_aplicado              DECIMAL(18,2),
    @detalle_conceptos_json      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @subtotal_arriendo DECIMAL(18,2);
    DECLARE @subtotal_servicios DECIMAL(18,2);
    DECLARE @monto_total DECIMAL(18,2);
    DECLARE @iva_arriendo DECIMAL(18,2);
    DECLARE @id_tipo_arriendo INT;

    DECLARE @conceptos_base TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_total DECIMAL(18,2) NOT NULL
    );

    DECLARE @saldos_concepto TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_disponible DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_solicitado TABLE (
        id_tipo_item_documento INT NOT NULL,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_normalizado TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50111, 'Debes indicar un pago valido para guardar el detalle de conceptos.', 1;
    END;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    IF @monto_aplicado IS NULL OR @monto_aplicado <= 0
    BEGIN
        ;THROW 50113, 'El monto aplicado debe ser mayor a cero para distribuir conceptos.', 1;
    END;

    SELECT
        @subtotal_arriendo = dc.subtotal_arriendo,
        @subtotal_servicios = dc.subtotal_servicios,
        @monto_total = dc.monto_total
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @monto_total IS NULL
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    SELECT @id_tipo_arriendo = tid.id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento tid
    WHERE tid.codigo_item = N'ARRIENDO';

    INSERT INTO @conceptos_base (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_total
    )
    SELECT
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item,
        CASE tid.codigo_item
            WHEN N'ARRIENDO' THEN 10
            WHEN N'SERVICIO_LUZ' THEN 20
            WHEN N'SERVICIO_GAS' THEN 30
            WHEN N'SERVICIO_AGUA' THEN 40
            WHEN N'MULTA' THEN 50
            WHEN N'DANO' THEN 60
            WHEN N'AJUSTE' THEN 70
            ELSE 80
        END AS prioridad,
        ROUND(SUM(dcd.subtotal), 2) AS monto_total
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    WHERE dcd.id_documento_cobro = @id_documento_cobro
    GROUP BY
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item;

    SET @iva_arriendo = ROUND(ISNULL(@monto_total, 0) - ISNULL(@subtotal_arriendo, 0) - ISNULL(@subtotal_servicios, 0), 2);
    IF @iva_arriendo < 0
    BEGIN
        SET @iva_arriendo = 0;
    END;

    IF @id_tipo_arriendo IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo
        )
        BEGIN
            UPDATE cb
            SET cb.monto_total = ROUND(cb.monto_total + @iva_arriendo, 2)
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo;
        END
        ELSE IF ISNULL(@subtotal_arriendo, 0) > 0 OR @iva_arriendo > 0
        BEGIN
            INSERT INTO @conceptos_base (
                id_tipo_item_documento,
                codigo_item,
                nombre_item,
                prioridad,
                monto_total
            )
            SELECT
                tid.id_tipo_item_documento,
                tid.codigo_item,
                tid.nombre_item,
                10,
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)
            FROM dbo.msp_tipo_item_documento tid
            WHERE tid.id_tipo_item_documento = @id_tipo_arriendo;
        END;
    END;

    INSERT INTO @saldos_concepto (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_disponible
    )
    SELECT
        cb.id_tipo_item_documento,
        cb.codigo_item,
        cb.nombre_item,
        cb.prioridad,
        ROUND(
            CASE
                WHEN cb.monto_total - ISNULL(ap.aplicado, 0) < 0 THEN 0
                ELSE cb.monto_total - ISNULL(ap.aplicado, 0)
            END,
            2
        ) AS monto_disponible
    FROM @conceptos_base cb
    OUTER APPLY (
        SELECT SUM(pdc.monto_aplicado) AS aplicado
        FROM dbo.msp_pagos_detalle_concepto pdc
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = pdc.id_pago
        WHERE pdc.id_documento_cobro = @id_documento_cobro
          AND pdc.id_tipo_item_documento = cb.id_tipo_item_documento
          AND p.estado_pago = 1
          AND p.id_pago <> @id_pago
    ) ap
    WHERE cb.monto_total > 0;

    IF NOT EXISTS (SELECT 1 FROM @saldos_concepto)
    BEGIN
        ;THROW 50123, 'No fue posible distribuir conceptos: el documento no tiene conceptos pendientes.', 1;
    END;

    DELETE FROM dbo.msp_pagos_detalle_concepto
    WHERE id_pago = @id_pago;

    IF LTRIM(RTRIM(ISNULL(@detalle_conceptos_json, N''))) <> N''
    BEGIN
        INSERT INTO @detalle_solicitado (id_tipo_item_documento, monto_aplicado)
        SELECT
            TRY_CAST(JSON_VALUE(js.value, '$.id_tipo_item_documento') AS INT) AS id_tipo_item_documento,
            TRY_CAST(JSON_VALUE(js.value, '$.monto') AS DECIMAL(18,2)) AS monto_aplicado
        FROM OPENJSON(@detalle_conceptos_json) js;

        IF NOT EXISTS (SELECT 1 FROM @detalle_solicitado)
        BEGIN
            ;THROW 50125, 'Debes indicar al menos un concepto de pago.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_solicitado ds
            WHERE ds.id_tipo_item_documento IS NULL
               OR ds.id_tipo_item_documento <= 0
               OR ds.monto_aplicado IS NULL
               OR ds.monto_aplicado <= 0
        )
        BEGIN
            ;THROW 50127, 'El detalle de conceptos contiene valores invalidos.', 1;
        END;

        INSERT INTO @detalle_normalizado (id_tipo_item_documento, monto_aplicado)
        SELECT
            ds.id_tipo_item_documento,
            ROUND(SUM(ds.monto_aplicado), 2)
        FROM @detalle_solicitado ds
        GROUP BY ds.id_tipo_item_documento;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            LEFT JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE sc.id_tipo_item_documento IS NULL
        )
        BEGIN
            ;THROW 50124, 'Hay conceptos que no existen en el documento o no tienen saldo disponible.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            INNER JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE dn.monto_aplicado > sc.monto_disponible + 0.01
        )
        BEGIN
            ;THROW 50122, 'El monto de un concepto excede el saldo disponible del concepto.', 1;
        END;

        IF ABS(
            ROUND(
                ISNULL((SELECT SUM(dn.monto_aplicado) FROM @detalle_normalizado dn), 0)
                - @monto_aplicado,
                2
            )
        ) > 0.01
        BEGIN
            ;THROW 50121, 'La suma de conceptos no coincide con el monto aplicado al documento.', 1;
        END;

        INSERT INTO dbo.msp_pagos_detalle_concepto (
            id_pago,
            id_documento_cobro,
            id_tipo_item_documento,
            monto_aplicado
        )
        SELECT
            @id_pago,
            @id_documento_cobro,
            dn.id_tipo_item_documento,
            dn.monto_aplicado
        FROM @detalle_normalizado dn;
    END
    ELSE
    BEGIN
        DECLARE @id_tipo_actual INT;
        DECLARE @disponible_actual DECIMAL(18,2);
        DECLARE @monto_asignado DECIMAL(18,2);
        DECLARE @restante DECIMAL(18,2);

        SET @restante = ROUND(@monto_aplicado, 2);

        DECLARE cur_conceptos CURSOR LOCAL FAST_FORWARD FOR
            SELECT sc.id_tipo_item_documento, sc.monto_disponible
            FROM @saldos_concepto sc
            WHERE sc.monto_disponible > 0
            ORDER BY sc.prioridad ASC, sc.id_tipo_item_documento ASC;

        OPEN cur_conceptos;

        FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        WHILE @@FETCH_STATUS = 0 AND @restante > 0.01
        BEGIN
            SET @monto_asignado = CASE
                WHEN @restante < @disponible_actual THEN @restante
                ELSE @disponible_actual
            END;

            SET @monto_asignado = ROUND(@monto_asignado, 2);

            IF @monto_asignado > 0
            BEGIN
                INSERT INTO dbo.msp_pagos_detalle_concepto (
                    id_pago,
                    id_documento_cobro,
                    id_tipo_item_documento,
                    monto_aplicado
                )
                VALUES (
                    @id_pago,
                    @id_documento_cobro,
                    @id_tipo_actual,
                    @monto_asignado
                );

                SET @restante = ROUND(@restante - @monto_asignado, 2);
            END;

            FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        END;

        CLOSE cur_conceptos;
        DEALLOCATE cur_conceptos;

        IF @restante > 0.01
        BEGIN
            ;THROW 50123, 'No fue posible distribuir automaticamente el pago por concepto.', 1;
        END;
    END;
END;
GO

/* =========================================================================
   9. PROCEDIMIENTO: REGISTRAR PAGO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_registrar_pago_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_pagado           DECIMAL(18,2),
    @medio_pago             NVARCHAR(50) = NULL,
    @referencia_pago        NVARCHAR(100) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @monto_aplicado DECIMAL(18,2);
    DECLARE @monto_excedente DECIMAL(18,2);
    DECLARE @id_pago_generado INT;
    DECLARE @saldo_favor_tienda DECIMAL(18,2) = 0;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50061, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50062, 'Debes indicar la fecha del pago.', 1;
    END;

    IF @monto_pagado IS NULL OR @monto_pagado <= 0
    BEGIN
        ;THROW 50063, 'El monto_pagado debe ser mayor a cero.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50064, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50065, 'El documento no tiene saldo pendiente para recibir pagos.', 1;
    END;

    SET @monto_aplicado = CASE
        WHEN @monto_pagado > @saldo_pendiente THEN @saldo_pendiente
        ELSE @monto_pagado
    END;
    SET @monto_excedente = ROUND(@monto_pagado - @monto_aplicado, 2);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_aplicado,
            @monto_excedente,
            0,
            1,
            @medio_pago,
            @referencia_pago,
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_aplicado,
            @detalle_conceptos_json = @detalle_conceptos_json;

        IF @monto_excedente > 0
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_pago,
                1,
                @monto_excedente,
                @id_documento_cobro,
                @id_pago_generado,
                CONCAT(N'Excedente de pago registrado sobre documento #', @id_documento_cobro)
            );
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @saldo_favor_tienda = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_aplicado AS monto_aplicado_documento,
        @monto_excedente AS monto_saldo_favor_generado,
        @saldo_favor_tienda AS saldo_favor_tienda;
END;
GO

/* =========================================================================
   10. PROCEDIMIENTO: APLICAR SALDO A FAVOR
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_aplicar_saldo_favor_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_aplicar          DECIMAL(18,2) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);
    DECLARE @monto_real DECIMAL(18,2);
    DECLARE @id_pago_generado INT;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50081, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50082, 'Debes indicar la fecha de aplicación.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50083, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50084, 'El documento no tiene saldo pendiente para aplicar saldo a favor.', 1;
    END;

    SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
    WHERE sf.id_tienda = @id_tienda;

    SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

    IF @saldo_favor_disponible <= 0
    BEGIN
        ;THROW 50085, 'La tienda no tiene saldo a favor disponible.', 1;
    END;

    IF @monto_aplicar IS NULL
    BEGIN
        SET @monto_real = CASE
            WHEN @saldo_favor_disponible < @saldo_pendiente THEN @saldo_favor_disponible
            ELSE @saldo_pendiente
        END;
    END
    ELSE
    BEGIN
        IF @monto_aplicar <= 0
        BEGIN
            ;THROW 50086, 'El monto a aplicar debe ser mayor a cero.', 1;
        END;

        IF @monto_aplicar > @saldo_favor_disponible
        BEGIN
            ;THROW 50087, 'El monto a aplicar excede el saldo a favor disponible.', 1;
        END;

        IF @monto_aplicar > @saldo_pendiente
        BEGIN
            ;THROW 50088, 'El monto a aplicar excede el saldo pendiente del documento.', 1;
        END;

        SET @monto_real = @monto_aplicar;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_real,
            0,
            1,
            1,
            N'Saldo a favor',
            N'Aplicación de saldo a favor tienda',
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_real,
            @detalle_conceptos_json = @detalle_conceptos_json;

        INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
            id_tienda,
            fecha_movimiento,
            tipo_movimiento,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_tienda,
            @fecha_pago,
            2,
            -@monto_real,
            @id_documento_cobro,
            @id_pago_generado,
            CONCAT(N'Aplicación de saldo a favor sobre documento #', @id_documento_cobro)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_real AS monto_aplicado,
        ISNULL(sf.saldo_disponible, 0) AS saldo_favor_restante
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;
END;
GO

/* =========================================================================
   11. PROCEDIMIENTO: ANULAR PAGO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_anular_pago_documento
    @id_pago                INT,
    @fecha_anulacion        DATE,
    @motivo_anulacion       NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_documento_cobro INT;
    DECLARE @id_tienda INT;
    DECLARE @monto_pagado DECIMAL(18,2);
    DECLARE @monto_saldo_favor_generado DECIMAL(18,2);
    DECLARE @aplica_desde_saldo_favor BIT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);

    DECLARE @id_movimiento_excedente INT = NULL;
    DECLARE @id_item_periodo INT = NULL;
    DECLARE @id_movimiento_reversa INT = NULL;
    DECLARE @aplicaciones_activas_item INT = 0;

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50071, 'Debes indicar un pago valido.', 1;
    END;

    IF @fecha_anulacion IS NULL
    BEGIN
        ;THROW 50072, 'Debes indicar la fecha de anulacion.', 1;
    END;

    IF @motivo_anulacion IS NULL OR LTRIM(RTRIM(@motivo_anulacion)) = N''
    BEGIN
        ;THROW 50073, 'Debes indicar un motivo de anulacion.', 1;
    END;

    SELECT
        @id_documento_cobro = p.id_documento_cobro,
        @id_tienda = dc.id_tienda,
        @monto_pagado = p.monto_pagado,
        @monto_saldo_favor_generado = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_desde_saldo_favor = ISNULL(p.aplica_desde_saldo_favor, 0)
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = p.id_documento_cobro
    WHERE p.id_pago = @id_pago
      AND p.estado_pago = 1;

    IF @id_documento_cobro IS NULL
    BEGIN
        ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @aplica_desde_saldo_favor = 1
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                4,
                @monto_pagado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de aplicación de saldo a favor por anulación de pago #', @id_pago)
            );

            IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_aplicaciones
                SET estado_aplicacion = 5,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_pago = @id_pago
                  AND estado_aplicacion = 1;
            END;
        END
        ELSE IF @monto_saldo_favor_generado > 0
        BEGIN
            SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
            FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
            WHERE sf.id_tienda = @id_tienda;

            SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

            IF @saldo_favor_disponible < @monto_saldo_favor_generado
            BEGIN
                ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
            END;

            SELECT TOP 1
                @id_movimiento_excedente = msf.id_movimiento_saldo_favor
            FROM dbo.msp_movimientos_saldo_favor_tienda msf WITH (UPDLOCK, HOLDLOCK)
            WHERE msf.id_pago = @id_pago
              AND msf.id_documento_cobro = @id_documento_cobro
              AND msf.tipo_movimiento = 1
              AND msf.monto_movimiento > 0
            ORDER BY msf.id_movimiento_saldo_favor DESC;

            IF @id_movimiento_excedente IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                SELECT TOP 1
                    @id_item_periodo = sfpi.id_saldo_favor_periodo_item
                FROM dbo.msp_saldo_favor_periodo_items sfpi WITH (UPDLOCK, HOLDLOCK)
                WHERE sfpi.id_movimiento_saldo_favor = @id_movimiento_excedente
                  AND sfpi.estado_item = 1
                ORDER BY sfpi.id_saldo_favor_periodo_item DESC;

                IF @id_item_periodo IS NOT NULL
                   AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
                BEGIN
                    SELECT @aplicaciones_activas_item = COUNT(*)
                    FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa WITH (UPDLOCK, HOLDLOCK)
                    WHERE sfa.id_saldo_favor_periodo_item = @id_item_periodo
                      AND sfa.estado_aplicacion = 1;

                    IF ISNULL(@aplicaciones_activas_item, 0) > 0
                    BEGIN
                        ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
                    END;
                END;
            END;

            DECLARE @out_reversa TABLE (id_movimiento_saldo_favor INT);

            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out_reversa(id_movimiento_saldo_favor)
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                3,
                -@monto_saldo_favor_generado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de excedente por anulación de pago #', @id_pago)
            );

            SELECT TOP 1 @id_movimiento_reversa = id_movimiento_saldo_favor
            FROM @out_reversa;

            IF @id_item_periodo IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_items
                SET estado_item = 5,
                    id_movimiento_reversa = @id_movimiento_reversa,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_saldo_favor_periodo_item = @id_item_periodo
                  AND estado_item = 1;
            END;
        END;

        UPDATE dbo.msp_pagos
        SET estado_pago = 2,
            fecha_anulacion = @fecha_anulacion,
            motivo_anulacion = @motivo_anulacion
        WHERE id_pago = @id_pago
          AND estado_pago = 1;

        IF @@ROWCOUNT = 0
        BEGIN
            ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT 'MSP initial: patch_saldo_favor_anulaciones_periodo.sql';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_feriados.sql';
/* ===================================================================== */

/* =========================================================================
   MSP - Tabla de feriados (Chile)
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_feriados', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_feriados (
        fecha DATE NOT NULL PRIMARY KEY,
        titulo NVARCHAR(200) NOT NULL,
        tipo NVARCHAR(80) NULL,
        inalienable BIT NOT NULL CONSTRAINT DF_msp_feriados_inalienable DEFAULT (0),
        fuente NVARCHAR(40) NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_feriados_activo DEFAULT (1),
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_feriados_created DEFAULT (SYSDATETIME()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_feriados_updated DEFAULT (SYSDATETIME())
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_feriados_anio'
      AND object_id = OBJECT_ID(N'dbo.msp_feriados')
)
BEGIN
    CREATE INDEX IX_msp_feriados_anio
        ON dbo.msp_feriados (fecha);
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_pagos_por_concepto.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH PAGOS POR CONCEPTO
 - Agrega conceptos comerciales: MULTA y DANO
 - Crea detalle de aplicacion de pagos por concepto
 - Permite registrar pago con distribucion manual (JSON) o automatica
 - Incluye backfill para pagos aplicados existentes sin detalle
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_tipo_item_documento', N'U') IS NOT NULL
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_tipo_item_documento
        WHERE codigo_item = N'MULTA'
    )
    BEGIN
        DECLARE @id_tipo_multa INT;
        SELECT @id_tipo_multa = ISNULL(MAX(id_tipo_item_documento), 0) + 1
        FROM dbo.msp_tipo_item_documento;

        INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
        VALUES (@id_tipo_multa, N'MULTA', N'Multa');
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_tipo_item_documento
        WHERE codigo_item = N'DANO'
    )
    BEGIN
        DECLARE @id_tipo_dano INT;
        SELECT @id_tipo_dano = ISNULL(MAX(id_tipo_item_documento), 0) + 1
        FROM dbo.msp_tipo_item_documento;

        INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
        VALUES (@id_tipo_dano, N'DANO', N'Dano');
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pagos_detalle_concepto (
        id_detalle_pago_concepto      INT IDENTITY(1,1) NOT NULL,
        id_pago                       INT NOT NULL,
        id_documento_cobro            INT NOT NULL,
        id_tipo_item_documento        INT NOT NULL,
        monto_aplicado                DECIMAL(18,2) NOT NULL,
        fecha_registro                DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_detalle_concepto_fecha DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_pagos_detalle_concepto PRIMARY KEY (id_detalle_pago_concepto),
        CONSTRAINT FK_msp_pagos_detalle_concepto_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT FK_msp_pagos_detalle_concepto_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_pagos_detalle_concepto_tipo
            FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
        CONSTRAINT UQ_msp_pagos_detalle_concepto_pago_tipo UNIQUE (id_pago, id_tipo_item_documento),
        CONSTRAINT CK_msp_pagos_detalle_concepto_monto CHECK (monto_aplicado > 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pagos_detalle_concepto_documento_tipo'
      AND object_id = OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pagos_detalle_concepto_documento_tipo
        ON dbo.msp_pagos_detalle_concepto (id_documento_cobro, id_tipo_item_documento, id_pago);
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_detalle_concepto_valida_documento
ON dbo.msp_pagos_detalle_concepto
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = i.id_pago
        WHERE p.id_documento_cobro <> i.id_documento_cobro
    )
    BEGIN
        ;THROW 50120, 'El documento del detalle de concepto no coincide con el documento del pago.', 1;
    END;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos
    @id_pago                     INT,
    @id_documento_cobro          INT,
    @monto_aplicado              DECIMAL(18,2),
    @detalle_conceptos_json      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @subtotal_arriendo DECIMAL(18,2);
    DECLARE @subtotal_servicios DECIMAL(18,2);
    DECLARE @monto_total DECIMAL(18,2);
    DECLARE @iva_arriendo DECIMAL(18,2);
    DECLARE @id_tipo_arriendo INT;

    DECLARE @conceptos_base TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_total DECIMAL(18,2) NOT NULL
    );

    DECLARE @saldos_concepto TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_disponible DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_solicitado TABLE (
        id_tipo_item_documento INT NOT NULL,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_normalizado TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50111, 'Debes indicar un pago valido para guardar el detalle de conceptos.', 1;
    END;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    IF @monto_aplicado IS NULL OR @monto_aplicado <= 0
    BEGIN
        ;THROW 50113, 'El monto aplicado debe ser mayor a cero para distribuir conceptos.', 1;
    END;

    SELECT
        @subtotal_arriendo = dc.subtotal_arriendo,
        @subtotal_servicios = dc.subtotal_servicios,
        @monto_total = dc.monto_total
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @monto_total IS NULL
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    SELECT @id_tipo_arriendo = tid.id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento tid
    WHERE tid.codigo_item = N'ARRIENDO';

    INSERT INTO @conceptos_base (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_total
    )
    SELECT
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item,
        CASE tid.codigo_item
            WHEN N'ARRIENDO' THEN 10
            WHEN N'SERVICIO_LUZ' THEN 20
            WHEN N'SERVICIO_GAS' THEN 30
            WHEN N'SERVICIO_AGUA' THEN 40
            WHEN N'MULTA' THEN 50
            WHEN N'DANO' THEN 60
            WHEN N'AJUSTE' THEN 70
            ELSE 80
        END AS prioridad,
        ROUND(SUM(dcd.subtotal), 2) AS monto_total
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    WHERE dcd.id_documento_cobro = @id_documento_cobro
    GROUP BY
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item;

    SET @iva_arriendo = ROUND(ISNULL(@monto_total, 0) - ISNULL(@subtotal_arriendo, 0) - ISNULL(@subtotal_servicios, 0), 2);
    IF @iva_arriendo < 0
    BEGIN
        SET @iva_arriendo = 0;
    END;

    IF @id_tipo_arriendo IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo
        )
        BEGIN
            UPDATE cb
            SET cb.monto_total = ROUND(cb.monto_total + @iva_arriendo, 2)
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo;
        END
        ELSE IF ISNULL(@subtotal_arriendo, 0) > 0 OR @iva_arriendo > 0
        BEGIN
            INSERT INTO @conceptos_base (
                id_tipo_item_documento,
                codigo_item,
                nombre_item,
                prioridad,
                monto_total
            )
            SELECT
                tid.id_tipo_item_documento,
                tid.codigo_item,
                tid.nombre_item,
                10,
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)
            FROM dbo.msp_tipo_item_documento tid
            WHERE tid.id_tipo_item_documento = @id_tipo_arriendo;
        END;
    END;

    INSERT INTO @saldos_concepto (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_disponible
    )
    SELECT
        cb.id_tipo_item_documento,
        cb.codigo_item,
        cb.nombre_item,
        cb.prioridad,
        ROUND(
            CASE
                WHEN cb.monto_total - ISNULL(ap.aplicado, 0) < 0 THEN 0
                ELSE cb.monto_total - ISNULL(ap.aplicado, 0)
            END,
            2
        ) AS monto_disponible
    FROM @conceptos_base cb
    OUTER APPLY (
        SELECT SUM(pdc.monto_aplicado) AS aplicado
        FROM dbo.msp_pagos_detalle_concepto pdc
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = pdc.id_pago
        WHERE pdc.id_documento_cobro = @id_documento_cobro
          AND pdc.id_tipo_item_documento = cb.id_tipo_item_documento
          AND p.estado_pago = 1
          AND p.id_pago <> @id_pago
    ) ap
    WHERE cb.monto_total > 0;

    IF NOT EXISTS (SELECT 1 FROM @saldos_concepto)
    BEGIN
        ;THROW 50123, 'No fue posible distribuir conceptos: el documento no tiene conceptos pendientes.', 1;
    END;

    DELETE FROM dbo.msp_pagos_detalle_concepto
    WHERE id_pago = @id_pago;

    IF LTRIM(RTRIM(ISNULL(@detalle_conceptos_json, N''))) <> N''
    BEGIN
        INSERT INTO @detalle_solicitado (id_tipo_item_documento, monto_aplicado)
        SELECT
            TRY_CAST(JSON_VALUE(js.value, '$.id_tipo_item_documento') AS INT) AS id_tipo_item_documento,
            TRY_CAST(JSON_VALUE(js.value, '$.monto') AS DECIMAL(18,2)) AS monto_aplicado
        FROM OPENJSON(@detalle_conceptos_json) js;

        IF NOT EXISTS (SELECT 1 FROM @detalle_solicitado)
        BEGIN
            ;THROW 50125, 'Debes indicar al menos un concepto de pago.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_solicitado ds
            WHERE ds.id_tipo_item_documento IS NULL
               OR ds.id_tipo_item_documento <= 0
               OR ds.monto_aplicado IS NULL
               OR ds.monto_aplicado <= 0
        )
        BEGIN
            ;THROW 50127, 'El detalle de conceptos contiene valores invalidos.', 1;
        END;

        INSERT INTO @detalle_normalizado (id_tipo_item_documento, monto_aplicado)
        SELECT
            ds.id_tipo_item_documento,
            ROUND(SUM(ds.monto_aplicado), 2)
        FROM @detalle_solicitado ds
        GROUP BY ds.id_tipo_item_documento;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            LEFT JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE sc.id_tipo_item_documento IS NULL
        )
        BEGIN
            ;THROW 50124, 'Hay conceptos que no existen en el documento o no tienen saldo disponible.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            INNER JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE dn.monto_aplicado > sc.monto_disponible + 0.01
        )
        BEGIN
            ;THROW 50122, 'El monto de un concepto excede el saldo disponible del concepto.', 1;
        END;

        IF ABS(
            ROUND(
                ISNULL((SELECT SUM(dn.monto_aplicado) FROM @detalle_normalizado dn), 0)
                - @monto_aplicado,
                2
            )
        ) > 0.01
        BEGIN
            ;THROW 50121, 'La suma de conceptos no coincide con el monto aplicado al documento.', 1;
        END;

        INSERT INTO dbo.msp_pagos_detalle_concepto (
            id_pago,
            id_documento_cobro,
            id_tipo_item_documento,
            monto_aplicado
        )
        SELECT
            @id_pago,
            @id_documento_cobro,
            dn.id_tipo_item_documento,
            dn.monto_aplicado
        FROM @detalle_normalizado dn;
    END
    ELSE
    BEGIN
        DECLARE @id_tipo_actual INT;
        DECLARE @disponible_actual DECIMAL(18,2);
        DECLARE @monto_asignado DECIMAL(18,2);
        DECLARE @restante DECIMAL(18,2);

        SET @restante = ROUND(@monto_aplicado, 2);

        DECLARE cur_conceptos CURSOR LOCAL FAST_FORWARD FOR
            SELECT sc.id_tipo_item_documento, sc.monto_disponible
            FROM @saldos_concepto sc
            WHERE sc.monto_disponible > 0
            ORDER BY sc.prioridad ASC, sc.id_tipo_item_documento ASC;

        OPEN cur_conceptos;

        FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        WHILE @@FETCH_STATUS = 0 AND @restante > 0.01
        BEGIN
            SET @monto_asignado = CASE
                WHEN @restante < @disponible_actual THEN @restante
                ELSE @disponible_actual
            END;

            SET @monto_asignado = ROUND(@monto_asignado, 2);

            IF @monto_asignado > 0
            BEGIN
                INSERT INTO dbo.msp_pagos_detalle_concepto (
                    id_pago,
                    id_documento_cobro,
                    id_tipo_item_documento,
                    monto_aplicado
                )
                VALUES (
                    @id_pago,
                    @id_documento_cobro,
                    @id_tipo_actual,
                    @monto_asignado
                );

                SET @restante = ROUND(@restante - @monto_asignado, 2);
            END;

            FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        END;

        CLOSE cur_conceptos;
        DEALLOCATE cur_conceptos;

        IF @restante > 0.01
        BEGIN
            ;THROW 50123, 'No fue posible distribuir automaticamente el pago por concepto.', 1;
        END;
    END;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_registrar_pago_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_pagado           DECIMAL(18,2),
    @medio_pago             NVARCHAR(50) = NULL,
    @referencia_pago        NVARCHAR(100) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @monto_aplicado DECIMAL(18,2);
    DECLARE @monto_excedente DECIMAL(18,2);
    DECLARE @id_pago_generado INT;
    DECLARE @saldo_favor_tienda DECIMAL(18,2) = 0;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50061, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50062, 'Debes indicar la fecha del pago.', 1;
    END;

    IF @monto_pagado IS NULL OR @monto_pagado <= 0
    BEGIN
        ;THROW 50063, 'El monto_pagado debe ser mayor a cero.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50064, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50065, 'El documento no tiene saldo pendiente para recibir pagos.', 1;
    END;

    SET @monto_aplicado = CASE
        WHEN @monto_pagado > @saldo_pendiente THEN @saldo_pendiente
        ELSE @monto_pagado
    END;
    SET @monto_excedente = ROUND(@monto_pagado - @monto_aplicado, 2);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_aplicado,
            @monto_excedente,
            0,
            1,
            @medio_pago,
            @referencia_pago,
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_aplicado,
            @detalle_conceptos_json = @detalle_conceptos_json;

        IF @monto_excedente > 0
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_pago,
                1,
                @monto_excedente,
                @id_documento_cobro,
                @id_pago_generado,
                CONCAT(N'Excedente de pago registrado sobre documento #', @id_documento_cobro)
            );
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @saldo_favor_tienda = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_aplicado AS monto_aplicado_documento,
        @monto_excedente AS monto_saldo_favor_generado,
        @saldo_favor_tienda AS saldo_favor_tienda;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_aplicar_saldo_favor_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_aplicar          DECIMAL(18,2) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);
    DECLARE @monto_real DECIMAL(18,2);
    DECLARE @id_pago_generado INT;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50081, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50082, 'Debes indicar la fecha de aplicación.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50083, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50084, 'El documento no tiene saldo pendiente para aplicar saldo a favor.', 1;
    END;

    SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
    WHERE sf.id_tienda = @id_tienda;

    SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

    IF @saldo_favor_disponible <= 0
    BEGIN
        ;THROW 50085, 'La tienda no tiene saldo a favor disponible.', 1;
    END;

    IF @monto_aplicar IS NULL
    BEGIN
        SET @monto_real = CASE
            WHEN @saldo_favor_disponible < @saldo_pendiente THEN @saldo_favor_disponible
            ELSE @saldo_pendiente
        END;
    END
    ELSE
    BEGIN
        IF @monto_aplicar <= 0
        BEGIN
            ;THROW 50086, 'El monto a aplicar debe ser mayor a cero.', 1;
        END;

        IF @monto_aplicar > @saldo_favor_disponible
        BEGIN
            ;THROW 50087, 'El monto a aplicar excede el saldo a favor disponible.', 1;
        END;

        IF @monto_aplicar > @saldo_pendiente
        BEGIN
            ;THROW 50088, 'El monto a aplicar excede el saldo pendiente del documento.', 1;
        END;

        SET @monto_real = @monto_aplicar;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_real,
            0,
            1,
            1,
            N'Saldo a favor',
            N'Aplicación de saldo a favor tienda',
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_real,
            @detalle_conceptos_json = @detalle_conceptos_json;

        INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
            id_tienda,
            fecha_movimiento,
            tipo_movimiento,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_tienda,
            @fecha_pago,
            2,
            -@monto_real,
            @id_documento_cobro,
            @id_pago_generado,
            CONCAT(N'Aplicación de saldo a favor sobre documento #', @id_documento_cobro)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_real AS monto_aplicado,
        ISNULL(sf.saldo_disponible, 0) AS saldo_favor_restante
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;
END;
GO

DECLARE @id_pago_backfill INT;
DECLARE @id_documento_backfill INT;
DECLARE @monto_backfill DECIMAL(18,2);

DECLARE cur_backfill CURSOR LOCAL FAST_FORWARD FOR
    SELECT
        p.id_pago,
        p.id_documento_cobro,
        p.monto_pagado
    FROM dbo.msp_pagos p
    WHERE p.estado_pago = 1
      AND p.monto_pagado > 0
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_pagos_detalle_concepto pdc
            WHERE pdc.id_pago = p.id_pago
      )
    ORDER BY p.fecha_pago ASC, p.id_pago ASC;

OPEN cur_backfill;

FETCH NEXT FROM cur_backfill INTO @id_pago_backfill, @id_documento_backfill, @monto_backfill;
WHILE @@FETCH_STATUS = 0
BEGIN
    EXEC dbo.msp_guardar_pago_detalle_conceptos
        @id_pago = @id_pago_backfill,
        @id_documento_cobro = @id_documento_backfill,
        @monto_aplicado = @monto_backfill,
        @detalle_conceptos_json = NULL;

    FETCH NEXT FROM cur_backfill INTO @id_pago_backfill, @id_documento_backfill, @monto_backfill;
END;

CLOSE cur_backfill;
DEALLOCATE cur_backfill;
GO

PRINT 'Patch pagos por concepto aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_documentos_cobro_uuid.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH UUID DOCUMENTOS COBRO
 - Agrega uuid_documento (UNIQUEIDENTIFIER) en msp_documentos_cobro
 - Backfill para filas existentes
 - Default NEWID() y restriccion unica
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NULL
BEGIN
    PRINT 'patch_documentos_cobro_uuid: tabla dbo.msp_documentos_cobro no existe, se omite.';
    RETURN;
END;
GO

IF COL_LENGTH(N'dbo.msp_documentos_cobro', N'uuid_documento') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD uuid_documento UNIQUEIDENTIFIER NULL;

    PRINT 'patch_documentos_cobro_uuid: columna uuid_documento creada.';
END
ELSE
BEGIN
    PRINT 'patch_documentos_cobro_uuid: columna uuid_documento ya existia.';
END;
GO

UPDATE dbo.msp_documentos_cobro
SET uuid_documento = NEWID()
WHERE uuid_documento IS NULL;
GO

;WITH filas_duplicadas AS (
    SELECT
        dc.id_documento_cobro,
        ROW_NUMBER() OVER (
            PARTITION BY dc.uuid_documento
            ORDER BY dc.id_documento_cobro
        ) AS rn
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.uuid_documento IS NOT NULL
)
UPDATE dc
SET dc.uuid_documento = NEWID()
FROM dbo.msp_documentos_cobro dc
INNER JOIN filas_duplicadas fd
    ON fd.id_documento_cobro = dc.id_documento_cobro
WHERE fd.rn > 1;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.default_constraints d
    INNER JOIN sys.columns c
        ON c.object_id = d.parent_object_id
       AND c.column_id = d.parent_column_id
    WHERE d.parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND c.name = N'uuid_documento'
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD CONSTRAINT DF_msp_documentos_cobro_uuid_documento DEFAULT (NEWID()) FOR uuid_documento;
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns c
    WHERE c.object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND c.name = N'uuid_documento'
      AND c.is_nullable = 1
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ALTER COLUMN uuid_documento UNIQUEIDENTIFIER NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes i
    WHERE i.object_id = OBJECT_ID(N'dbo.msp_documentos_cobro')
      AND i.name = N'UX_msp_documentos_cobro_uuid_documento'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UX_msp_documentos_cobro_uuid_documento
        ON dbo.msp_documentos_cobro (uuid_documento);
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_saldo_favor_tienda.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH SALDO A FAVOR POR TIENDA
 - Crea saldo a favor por tienda y libro de movimientos
 - Extiende msp_pagos para soportar excedentes y aplicaciones desde credito
 - Reemplaza procedimientos de pago / anulacion y agrega aplicacion manual
===========================================================================
*/

SET NOCOUNT ON;
GO

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

IF COL_LENGTH('dbo.msp_pagos', 'monto_saldo_favor_generado') IS NULL
BEGIN
    ALTER TABLE dbo.msp_pagos
    ADD monto_saldo_favor_generado DECIMAL(18,2) NOT NULL
        CONSTRAINT DF_msp_pagos_saldo_favor_generado DEFAULT (0);
END;
GO

IF COL_LENGTH('dbo.msp_pagos', 'aplica_desde_saldo_favor') IS NULL
BEGIN
    ALTER TABLE dbo.msp_pagos
    ADD aplica_desde_saldo_favor BIT NOT NULL
        CONSTRAINT DF_msp_pagos_aplica_saldo_favor DEFAULT (0);
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_msp_pagos_monto'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_pagos', N'U')
)
BEGIN
    ALTER TABLE dbo.msp_pagos DROP CONSTRAINT CK_msp_pagos_monto;
END;
GO

ALTER TABLE dbo.msp_pagos
ADD CONSTRAINT CK_msp_pagos_monto CHECK (
    monto_pagado > 0
    AND monto_saldo_favor_generado >= 0
    AND (aplica_desde_saldo_favor = 0 OR monto_saldo_favor_generado = 0)
);
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

CREATE OR ALTER PROCEDURE dbo.msp_registrar_pago_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_pagado           DECIMAL(18,2),
    @medio_pago             NVARCHAR(50) = NULL,
    @referencia_pago        NVARCHAR(100) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @monto_aplicado DECIMAL(18,2);
    DECLARE @monto_excedente DECIMAL(18,2);
    DECLARE @id_pago_generado INT;
    DECLARE @saldo_favor_tienda DECIMAL(18,2) = 0;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50061, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50062, 'Debes indicar la fecha del pago.', 1;
    END;

    IF @monto_pagado IS NULL OR @monto_pagado <= 0
    BEGIN
        ;THROW 50063, 'El monto_pagado debe ser mayor a cero.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50064, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50065, 'El documento no tiene saldo pendiente para recibir pagos.', 1;
    END;

    SET @monto_aplicado = CASE
        WHEN @monto_pagado > @saldo_pendiente THEN @saldo_pendiente
        ELSE @monto_pagado
    END;
    SET @monto_excedente = ROUND(@monto_pagado - @monto_aplicado, 2);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_aplicado,
            @monto_excedente,
            0,
            1,
            @medio_pago,
            @referencia_pago,
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        IF OBJECT_ID(N'dbo.msp_guardar_pago_detalle_conceptos', N'P') IS NOT NULL
        BEGIN
            EXEC dbo.msp_guardar_pago_detalle_conceptos
                @id_pago = @id_pago_generado,
                @id_documento_cobro = @id_documento_cobro,
                @monto_aplicado = @monto_aplicado,
                @detalle_conceptos_json = @detalle_conceptos_json;
        END;

        IF @monto_excedente > 0
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_pago,
                1,
                @monto_excedente,
                @id_documento_cobro,
                @id_pago_generado,
                CONCAT(N'Excedente de pago registrado sobre documento #', @id_documento_cobro)
            );
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @saldo_favor_tienda = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_aplicado AS monto_aplicado_documento,
        @monto_excedente AS monto_saldo_favor_generado,
        @saldo_favor_tienda AS saldo_favor_tienda;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_aplicar_saldo_favor_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_aplicar          DECIMAL(18,2) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);
    DECLARE @monto_real DECIMAL(18,2);
    DECLARE @id_pago_generado INT;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50081, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50082, 'Debes indicar la fecha de aplicación.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50083, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50084, 'El documento no tiene saldo pendiente para aplicar saldo a favor.', 1;
    END;

    SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
    WHERE sf.id_tienda = @id_tienda;

    SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

    IF @saldo_favor_disponible <= 0
    BEGIN
        ;THROW 50085, 'La tienda no tiene saldo a favor disponible.', 1;
    END;

    IF @monto_aplicar IS NULL
    BEGIN
        SET @monto_real = CASE
            WHEN @saldo_favor_disponible < @saldo_pendiente THEN @saldo_favor_disponible
            ELSE @saldo_pendiente
        END;
    END
    ELSE
    BEGIN
        IF @monto_aplicar <= 0
        BEGIN
            ;THROW 50086, 'El monto a aplicar debe ser mayor a cero.', 1;
        END;

        IF @monto_aplicar > @saldo_favor_disponible
        BEGIN
            ;THROW 50087, 'El monto a aplicar excede el saldo a favor disponible.', 1;
        END;

        IF @monto_aplicar > @saldo_pendiente
        BEGIN
            ;THROW 50088, 'El monto a aplicar excede el saldo pendiente del documento.', 1;
        END;

        SET @monto_real = @monto_aplicar;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_real,
            0,
            1,
            1,
            N'Saldo a favor',
            N'Aplicación de saldo a favor tienda',
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        IF OBJECT_ID(N'dbo.msp_guardar_pago_detalle_conceptos', N'P') IS NOT NULL
        BEGIN
            EXEC dbo.msp_guardar_pago_detalle_conceptos
                @id_pago = @id_pago_generado,
                @id_documento_cobro = @id_documento_cobro,
                @monto_aplicado = @monto_real,
                @detalle_conceptos_json = @detalle_conceptos_json;
        END;

        INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
            id_tienda,
            fecha_movimiento,
            tipo_movimiento,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_tienda,
            @fecha_pago,
            2,
            -@monto_real,
            @id_documento_cobro,
            @id_pago_generado,
            CONCAT(N'Aplicación de saldo a favor sobre documento #', @id_documento_cobro)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_real AS monto_aplicado,
        ISNULL(sf.saldo_disponible, 0) AS saldo_favor_restante
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_anular_pago_documento
    @id_pago                INT,
    @fecha_anulacion        DATE,
    @motivo_anulacion       NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_documento_cobro INT;
    DECLARE @id_tienda INT;
    DECLARE @monto_pagado DECIMAL(18,2);
    DECLARE @monto_saldo_favor_generado DECIMAL(18,2);
    DECLARE @aplica_desde_saldo_favor BIT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);

    DECLARE @id_movimiento_excedente INT = NULL;
    DECLARE @id_item_periodo INT = NULL;
    DECLARE @id_movimiento_reversa INT = NULL;
    DECLARE @aplicaciones_activas_item INT = 0;

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50071, 'Debes indicar un pago valido.', 1;
    END;

    IF @fecha_anulacion IS NULL
    BEGIN
        ;THROW 50072, 'Debes indicar la fecha de anulacion.', 1;
    END;

    IF @motivo_anulacion IS NULL OR LTRIM(RTRIM(@motivo_anulacion)) = N''
    BEGIN
        ;THROW 50073, 'Debes indicar un motivo de anulacion.', 1;
    END;

    SELECT
        @id_documento_cobro = p.id_documento_cobro,
        @id_tienda = dc.id_tienda,
        @monto_pagado = p.monto_pagado,
        @monto_saldo_favor_generado = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_desde_saldo_favor = ISNULL(p.aplica_desde_saldo_favor, 0)
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = p.id_documento_cobro
    WHERE p.id_pago = @id_pago
      AND p.estado_pago = 1;

    IF @id_documento_cobro IS NULL
    BEGIN
        ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @aplica_desde_saldo_favor = 1
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                4,
                @monto_pagado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de aplicación de saldo a favor por anulación de pago #', @id_pago)
            );

            IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_aplicaciones
                SET estado_aplicacion = 5,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_pago = @id_pago
                  AND estado_aplicacion = 1;
            END;
        END
        ELSE IF @monto_saldo_favor_generado > 0
        BEGIN
            SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
            FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
            WHERE sf.id_tienda = @id_tienda;

            SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

            IF @saldo_favor_disponible < @monto_saldo_favor_generado
            BEGIN
                ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
            END;

            SELECT TOP 1
                @id_movimiento_excedente = msf.id_movimiento_saldo_favor
            FROM dbo.msp_movimientos_saldo_favor_tienda msf WITH (UPDLOCK, HOLDLOCK)
            WHERE msf.id_pago = @id_pago
              AND msf.id_documento_cobro = @id_documento_cobro
              AND msf.tipo_movimiento = 1
              AND msf.monto_movimiento > 0
            ORDER BY msf.id_movimiento_saldo_favor DESC;

            IF @id_movimiento_excedente IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                SELECT TOP 1
                    @id_item_periodo = sfpi.id_saldo_favor_periodo_item
                FROM dbo.msp_saldo_favor_periodo_items sfpi WITH (UPDLOCK, HOLDLOCK)
                WHERE sfpi.id_movimiento_saldo_favor = @id_movimiento_excedente
                  AND sfpi.estado_item = 1
                ORDER BY sfpi.id_saldo_favor_periodo_item DESC;

                IF @id_item_periodo IS NOT NULL
                   AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
                BEGIN
                    SELECT @aplicaciones_activas_item = COUNT(*)
                    FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa WITH (UPDLOCK, HOLDLOCK)
                    WHERE sfa.id_saldo_favor_periodo_item = @id_item_periodo
                      AND sfa.estado_aplicacion = 1;

                    IF ISNULL(@aplicaciones_activas_item, 0) > 0
                    BEGIN
                        ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
                    END;
                END;
            END;

            DECLARE @out_reversa TABLE (id_movimiento_saldo_favor INT);

            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out_reversa(id_movimiento_saldo_favor)
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                3,
                -@monto_saldo_favor_generado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de excedente por anulación de pago #', @id_pago)
            );

            SELECT TOP 1 @id_movimiento_reversa = id_movimiento_saldo_favor
            FROM @out_reversa;

            IF @id_item_periodo IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_items
                SET estado_item = 5,
                    id_movimiento_reversa = @id_movimiento_reversa,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_saldo_favor_periodo_item = @id_item_periodo
                  AND estado_item = 1;
            END;
        END;

        UPDATE dbo.msp_pagos
        SET estado_pago = 2,
            fecha_anulacion = @fecha_anulacion,
            motivo_anulacion = @motivo_anulacion
        WHERE id_pago = @id_pago
          AND estado_pago = 1;

        IF @@ROWCOUNT = 0
        BEGIN
            ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT 'Patch saldo a favor por tienda aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_saldo_favor_periodo.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH TRAZABILIDAD SALDO A FAVOR POR PERIODO
 - Crea tabla de items individuales de saldo a favor por tienda/periodo.
 - Permite regenerar documentos y reaplicar saldo de forma deterministica.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldo_favor_periodo_items (
        id_saldo_favor_periodo_item INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion          DATE NOT NULL,
        id_tienda                    INT NOT NULL,
        fecha_movimiento             DATE NOT NULL,
        monto_original               DECIMAL(18,2) NOT NULL,
        estado_item                  TINYINT NOT NULL
            CONSTRAINT DF_msp_sf_periodo_estado DEFAULT (1),
        id_movimiento_saldo_favor    INT NULL,
        id_movimiento_reversa        INT NULL,
        observaciones                NVARCHAR(500) NULL,
        fecha_registro               DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion          DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_actualizacion DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldo_favor_periodo_items
            PRIMARY KEY (id_saldo_favor_periodo_item),
        CONSTRAINT FK_msp_sf_periodo_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_sf_periodo_mov_ingreso
            FOREIGN KEY (id_movimiento_saldo_favor)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda (id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_sf_periodo_mov_reversa
            FOREIGN KEY (id_movimiento_reversa)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda (id_movimiento_saldo_favor),
        CONSTRAINT CK_msp_sf_periodo_monto_pos CHECK (monto_original > 0),
        CONSTRAINT CK_msp_sf_periodo_estado CHECK (estado_item IN (1,5))
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U')
      AND name = N'IX_msp_sf_periodo_periodo_tienda_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_periodo_tienda_estado
        ON dbo.msp_saldo_favor_periodo_items (periodo_facturacion, id_tienda, estado_item)
        INCLUDE (monto_original, id_movimiento_saldo_favor, fecha_movimiento);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U')
      AND name = N'UX_msp_sf_periodo_mov_ingreso'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_sf_periodo_mov_ingreso
        ON dbo.msp_saldo_favor_periodo_items (id_movimiento_saldo_favor)
        WHERE id_movimiento_saldo_favor IS NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldo_favor_periodo_aplicaciones (
        id_saldo_favor_periodo_aplicacion INT IDENTITY(1,1) NOT NULL,
        id_saldo_favor_periodo_item       INT NOT NULL,
        periodo_facturacion               DATE NOT NULL,
        id_tienda                         INT NOT NULL,
        id_documento_cobro                INT NOT NULL,
        id_pago                           INT NULL,
        fecha_aplicacion                  DATE NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
        monto_aplicado                    DECIMAL(18,2) NOT NULL,
        estado_aplicacion                 TINYINT NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_estado DEFAULT (1),
        observaciones                     NVARCHAR(500) NULL,
        fecha_registro                    DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion               DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_periodo_aplicacion_actualizacion DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_saldo_favor_periodo_aplicaciones
            PRIMARY KEY (id_saldo_favor_periodo_aplicacion),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_item
            FOREIGN KEY (id_saldo_favor_periodo_item)
            REFERENCES dbo.msp_saldo_favor_periodo_items (id_saldo_favor_periodo_item),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_tienda
            FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_sf_periodo_aplicacion_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT CK_msp_sf_periodo_aplicacion_monto CHECK (monto_aplicado > 0),
        CONSTRAINT CK_msp_sf_periodo_aplicacion_estado CHECK (estado_aplicacion IN (1,5))
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
        ON dbo.msp_saldo_favor_periodo_aplicaciones (periodo_facturacion, id_tienda, estado_aplicacion)
        INCLUDE (monto_aplicado, id_documento_cobro, id_saldo_favor_periodo_item, id_pago);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'IX_msp_sf_periodo_aplicacion_item_estado'
)
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_item_estado
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_saldo_favor_periodo_item, estado_aplicacion)
        INCLUDE (monto_aplicado, periodo_facturacion, id_documento_cobro, id_pago);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
      AND name = N'UX_msp_sf_periodo_aplicacion_pago'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_sf_periodo_aplicacion_pago
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_pago)
        WHERE id_pago IS NOT NULL;
END;
GO

PRINT 'Patch trazabilidad saldo a favor por periodo aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_reglas_cobro_auto.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH REGLAS DE COBRO AUTOMATICO
 SQL Server / esquema dbo
 - Crea reglas para cargos automaticos (ej: mora diaria fija)
 - Crea trazabilidad de cargos auto-generados por documento
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_reglas_cobro_auto', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_reglas_cobro_auto (
        id_regla_cobro_auto      INT IDENTITY(1,1) NOT NULL,
        codigo_regla             NVARCHAR(60) NOT NULL,
        nombre_regla             NVARCHAR(120) NOT NULL,
        descripcion_regla        NVARCHAR(200) NULL,
        id_tipo_item_documento   INT NOT NULL,
        modo_calculo             NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_modo DEFAULT (N'DIARIO_FIJO'),
        monto_unitario           DECIMAL(18,2) NOT NULL,
        fecha_inicio_vigencia    DATE NOT NULL,
        fecha_fin_vigencia       DATE NULL,
        dias_gracia              INT NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_dias_gracia DEFAULT (0),
        orden_aplicacion         INT NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_orden DEFAULT (100),
        activo                   BIT NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_activo DEFAULT (1),
        fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_fecha_reg DEFAULT (SYSDATETIME()),
        fecha_actualizacion      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_reglas_cobro_auto_fecha_act DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_reglas_cobro_auto PRIMARY KEY (id_regla_cobro_auto),
        CONSTRAINT FK_msp_reglas_cobro_auto_tipo_item
            FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
        CONSTRAINT UQ_msp_reglas_cobro_auto_codigo_inicio UNIQUE (codigo_regla, fecha_inicio_vigencia),
        CONSTRAINT CK_msp_reglas_cobro_auto_montos CHECK (monto_unitario >= 0),
        CONSTRAINT CK_msp_reglas_cobro_auto_dias_gracia CHECK (dias_gracia >= 0),
        CONSTRAINT CK_msp_reglas_cobro_auto_modo CHECK (modo_calculo IN (N'DIARIO_FIJO')),
        CONSTRAINT CK_msp_reglas_cobro_auto_vigencia CHECK (
            fecha_fin_vigencia IS NULL OR fecha_fin_vigencia >= fecha_inicio_vigencia
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_reglas_cobro_auto')
      AND name = N'IX_msp_reglas_cobro_auto_activo_vigencia'
)
BEGIN
    CREATE INDEX IX_msp_reglas_cobro_auto_activo_vigencia
        ON dbo.msp_reglas_cobro_auto (activo, fecha_inicio_vigencia, fecha_fin_vigencia, orden_aplicacion);
END;
GO

IF OBJECT_ID(N'dbo.msp_cargos_auto_generados', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cargos_auto_generados (
        id_cargo_auto_generado      INT IDENTITY(1,1) NOT NULL,
        id_regla_cobro_auto         INT NOT NULL,
        id_documento_cobro          INT NOT NULL,
        id_documento_origen_deuda   INT NOT NULL,
        id_detalle_documento        INT NULL,
        periodo_calculo             DATE NOT NULL,
        fecha_vencimiento_origen    DATE NOT NULL,
        dias_mora_calculados        INT NOT NULL,
        monto_unitario_aplicado     DECIMAL(18,2) NOT NULL,
        monto_generado              DECIMAL(18,2) NOT NULL,
        fecha_calculo               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cargos_auto_generados_fecha_calc DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_cargos_auto_generados PRIMARY KEY (id_cargo_auto_generado),
        CONSTRAINT FK_msp_cargos_auto_generados_regla
            FOREIGN KEY (id_regla_cobro_auto) REFERENCES dbo.msp_reglas_cobro_auto (id_regla_cobro_auto),
        CONSTRAINT FK_msp_cargos_auto_generados_doc
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_cargos_auto_generados_doc_origen
            FOREIGN KEY (id_documento_origen_deuda) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_cargos_auto_generados_detalle
            FOREIGN KEY (id_detalle_documento) REFERENCES dbo.msp_documentos_cobro_detalle (id_detalle_documento),
        CONSTRAINT UQ_msp_cargos_auto_generados_unq UNIQUE (
            id_regla_cobro_auto,
            id_documento_cobro,
            id_documento_origen_deuda,
            periodo_calculo
        ),
        CONSTRAINT CK_msp_cargos_auto_generados_periodo CHECK (DAY(periodo_calculo) = 1),
        CONSTRAINT CK_msp_cargos_auto_generados_dias CHECK (dias_mora_calculados >= 0),
        CONSTRAINT CK_msp_cargos_auto_generados_montos CHECK (
            monto_unitario_aplicado >= 0
            AND monto_generado >= 0
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_cargos_auto_generados')
      AND name = N'IX_msp_cargos_auto_generados_doc'
)
BEGIN
    CREATE INDEX IX_msp_cargos_auto_generados_doc
        ON dbo.msp_cargos_auto_generados (id_documento_cobro, id_regla_cobro_auto, periodo_calculo);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_tipo_item_documento
    WHERE codigo_item = N'MULTA'
)
BEGIN
    DECLARE @id_tipo_multa_patch INT;
    SELECT @id_tipo_multa_patch = ISNULL(MAX(id_tipo_item_documento), 0) + 1
    FROM dbo.msp_tipo_item_documento;

    INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (@id_tipo_multa_patch, N'MULTA', N'Multa');
END;
GO

DECLARE @id_tipo_multa INT;
SELECT @id_tipo_multa = id_tipo_item_documento
FROM dbo.msp_tipo_item_documento
WHERE codigo_item = N'MULTA';

IF @id_tipo_multa IS NOT NULL
BEGIN
    MERGE dbo.msp_reglas_cobro_auto AS tgt
    USING (
        SELECT
            N'MORA_DIARIA_FIJA' AS codigo_regla,
            CAST(N'Multa mora diaria fija' AS NVARCHAR(120)) AS nombre_regla,
            CAST(N'Multa diaria por deuda vencida. Inicia el dia siguiente al vencimiento.' AS NVARCHAR(200)) AS descripcion_regla,
            @id_tipo_multa AS id_tipo_item_documento,
            CAST(N'DIARIO_FIJO' AS NVARCHAR(30)) AS modo_calculo,
            CAST(1000.00 AS DECIMAL(18,2)) AS monto_unitario,
            CAST('2026-04-01' AS DATE) AS fecha_inicio_vigencia,
            CAST(NULL AS DATE) AS fecha_fin_vigencia,
            CAST(0 AS INT) AS dias_gracia,
            CAST(100 AS INT) AS orden_aplicacion,
            CAST(1 AS BIT) AS activo
    ) AS src
    ON tgt.codigo_regla = src.codigo_regla
   AND tgt.fecha_inicio_vigencia = src.fecha_inicio_vigencia
    WHEN MATCHED THEN
        UPDATE SET
            nombre_regla = src.nombre_regla,
            descripcion_regla = src.descripcion_regla,
            id_tipo_item_documento = src.id_tipo_item_documento,
            modo_calculo = src.modo_calculo,
            monto_unitario = src.monto_unitario,
            fecha_fin_vigencia = src.fecha_fin_vigencia,
            dias_gracia = src.dias_gracia,
            orden_aplicacion = src.orden_aplicacion,
            activo = src.activo,
            fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED THEN
        INSERT (
            codigo_regla,
            nombre_regla,
            descripcion_regla,
            id_tipo_item_documento,
            modo_calculo,
            monto_unitario,
            fecha_inicio_vigencia,
            fecha_fin_vigencia,
            dias_gracia,
            orden_aplicacion,
            activo
        )
        VALUES (
            src.codigo_regla,
            src.nombre_regla,
            src.descripcion_regla,
            src.id_tipo_item_documento,
            src.modo_calculo,
            src.monto_unitario,
            src.fecha_inicio_vigencia,
            src.fecha_fin_vigencia,
            src.dias_gracia,
            src.orden_aplicacion,
            src.activo
        );
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_catalogo_bancos.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH CATALOGO BANCOS
 - Crea tabla de bancos para medios de pago por cheque
 - Incluye semillas base para bancos frecuentes en Chile
 - Idempotente
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_bancos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_bancos (
        id_banco        INT IDENTITY(1,1) NOT NULL,
        nombre_banco    NVARCHAR(120) NOT NULL,
        codigo_banco    NVARCHAR(20) NULL,
        activo          BIT NOT NULL CONSTRAINT DF_msp_bancos_activo DEFAULT (1),
        created_at      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bancos_created_at DEFAULT (SYSDATETIME()),
        updated_at      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bancos_updated_at DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_bancos PRIMARY KEY (id_banco),
        CONSTRAINT CK_msp_bancos_nombre_no_vacio CHECK (LEN(LTRIM(RTRIM(nombre_banco))) > 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'UX_msp_bancos_nombre'
      AND object_id = OBJECT_ID(N'dbo.msp_bancos', N'U')
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_bancos_nombre
        ON dbo.msp_bancos (nombre_banco);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_bancos_activo_nombre'
      AND object_id = OBJECT_ID(N'dbo.msp_bancos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_bancos_activo_nombre
        ON dbo.msp_bancos (activo, nombre_banco);
END;
GO

DECLARE @seed TABLE (
    nombre_banco NVARCHAR(120) NOT NULL,
    codigo_banco NVARCHAR(20) NULL
);

INSERT INTO @seed (nombre_banco, codigo_banco)
VALUES
    (N'Banco de Chile', N'001'),
    (N'Banco Internacional', N'009'),
    (N'Scotiabank Chile', N'014'),
    (N'Banco de Crédito e Inversiones (BCI)', N'016'),
    (N'Banco Estado', N'012'),
    (N'Banco BICE', N'028'),
    (N'Banco Santander Chile', N'037'),
    (N'Banco Itaú Chile', N'039'),
    (N'Banco Security', N'049'),
    (N'Banco Falabella', N'051'),
    (N'Banco Ripley', N'053'),
    (N'Banco Consorcio', N'055'),
    (N'Banco BTG Pactual Chile', N'031');

MERGE dbo.msp_bancos AS target
USING @seed AS source
    ON target.nombre_banco = source.nombre_banco
WHEN MATCHED THEN
    UPDATE SET
        codigo_banco = COALESCE(source.codigo_banco, target.codigo_banco),
        updated_at = SYSDATETIME()
WHEN NOT MATCHED BY TARGET THEN
    INSERT (nombre_banco, codigo_banco, activo, created_at, updated_at)
    VALUES (source.nombre_banco, source.codigo_banco, 1, SYSDATETIME(), SYSDATETIME());
GO


GO


/* ===================================================================== */
PRINT 'MSP initial: msp_deudores_garantia.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - DEUDA Y GARANTIA
 SQL Server / esquema dbo
 - Requiere A1 ya instalado: msp_agrupacion_locales.sql
 - Requiere A22 ya instalado: msp_documento_pago.sql
 - Alcance intencionalmente acotado:
   contrato por tienda, garantia por local, cargos por local, movimientos,
   bitacora de cierre e historial contractual
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS BASE
   ========================================================================= */

CREATE TABLE dbo.msp_tipos_movimiento_garantia (
    id_tipo_movimiento_garantia INT NOT NULL,
    codigo_movimiento           NVARCHAR(50) NOT NULL,
    nombre_movimiento           NVARCHAR(120) NOT NULL,
    activo                      BIT NOT NULL CONSTRAINT DF_msp_tipos_mov_garantia_activo DEFAULT (1),
    CONSTRAINT PK_msp_tipos_movimiento_garantia PRIMARY KEY (id_tipo_movimiento_garantia),
    CONSTRAINT UQ_msp_tipos_movimiento_garantia_codigo UNIQUE (codigo_movimiento),
    CONSTRAINT UQ_msp_tipos_movimiento_garantia_nombre UNIQUE (nombre_movimiento)
);
GO

INSERT INTO dbo.msp_tipos_movimiento_garantia (
    id_tipo_movimiento_garantia,
    codigo_movimiento,
    nombre_movimiento
)
VALUES
    (1, N'CONSTITUCION',       N'Constitucion de garantia'),
    (2, N'RESERVA',            N'Reserva de garantia'),
    (3, N'LIBERACION_RESERVA', N'Liberacion de reserva'),
    (4, N'APLICACION_CARGO',   N'Aplicacion de garantia a cargo'),
    (5, N'DEVOLUCION',         N'Devolucion de garantia'),
    (6, N'AJUSTE_POSITIVO',    N'Ajuste positivo'),
    (7, N'AJUSTE_NEGATIVO',    N'Ajuste negativo');
GO

CREATE TABLE dbo.msp_tipos_cargo_salida (
    id_tipo_cargo_salida      INT NOT NULL,
    codigo_tipo_cargo         NVARCHAR(50) NOT NULL,
    nombre_tipo_cargo         NVARCHAR(120) NOT NULL,
    requiere_documento        BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_requiere_doc DEFAULT (0),
    permite_estimacion        BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_perm_est DEFAULT (0),
    activo                    BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_activo DEFAULT (1),
    CONSTRAINT PK_msp_tipos_cargo_salida PRIMARY KEY (id_tipo_cargo_salida),
    CONSTRAINT UQ_msp_tipos_cargo_salida_codigo UNIQUE (codigo_tipo_cargo),
    CONSTRAINT UQ_msp_tipos_cargo_salida_nombre UNIQUE (nombre_tipo_cargo)
);
GO

INSERT INTO dbo.msp_tipos_cargo_salida (
    id_tipo_cargo_salida,
    codigo_tipo_cargo,
    nombre_tipo_cargo,
    requiere_documento,
    permite_estimacion
)
VALUES
    (4, N'MULTA',              N'Multa',                       0, 0),
    (5, N'DANOS',              N'Daños o reparaciones',        0, 0),
    (6, N'OTRO',               N'Otro cargo',                  0, 1);
GO

/* =========================================================================
   2. CONTRATOS DE ARRIENDO
   Estado:
     1 = Borrador
     2 = Vigente
     3 = En cierre financiero
     4 = Cerrado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_contratos_arriendo (
    id_contrato_arriendo     INT IDENTITY(1,1) NOT NULL,
    id_tienda                INT NOT NULL,
    id_arrendatario          INT NOT NULL,
    fecha_inicio             DATE NOT NULL,
    fecha_termino_pactada    DATE NULL,
    fecha_termino_efectiva   DATE NULL,
    dia_cobro                TINYINT NULL CONSTRAINT DF_msp_contratos_dia_cobro DEFAULT (1),
    monto_arriendo_pactado   DECIMAL(18,2) NULL,
    rubro_contrato           NVARCHAR(150) NULL,
    estado_contrato          TINYINT NOT NULL CONSTRAINT DF_msp_contratos_estado DEFAULT (2),
    observaciones            NVARCHAR(1000) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_contratos_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_contratos_arriendo PRIMARY KEY (id_contrato_arriendo),
    CONSTRAINT FK_msp_contratos_tienda
        FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
    CONSTRAINT FK_msp_contratos_arrendatario
        FOREIGN KEY (id_arrendatario) REFERENCES dbo.msp_arrendatarios (id_arrendatario),
    CONSTRAINT CK_msp_contratos_estado CHECK (estado_contrato IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_contratos_dia_cobro CHECK (
        dia_cobro IS NULL OR dia_cobro BETWEEN 1 AND 31
    ),
    CONSTRAINT CK_msp_contratos_monto_arriendo CHECK (
        monto_arriendo_pactado IS NULL OR monto_arriendo_pactado >= 0
    ),
    CONSTRAINT CK_msp_contratos_fechas CHECK (
        (fecha_termino_pactada IS NULL OR fecha_termino_pactada >= fecha_inicio)
        AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva >= fecha_inicio)
    )
);
GO

CREATE UNIQUE INDEX UX_msp_contratos_tienda_activo
    ON dbo.msp_contratos_arriendo (id_tienda)
    WHERE estado_contrato IN (1,2);
GO

CREATE INDEX IX_msp_contratos_arrendatario
    ON dbo.msp_contratos_arriendo (id_arrendatario, estado_contrato, fecha_inicio DESC);
GO

/* =========================================================================
   3. BITACORA DE CIERRE DE CONTRATO
   ========================================================================= */

CREATE TABLE dbo.msp_bitacora_cierre_contrato (
    id_bitacora_cierre_contrato INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo        INT NOT NULL,
    id_usuario                  INT NOT NULL,
    estado_contrato_anterior    TINYINT NOT NULL,
    estado_contrato_nuevo       TINYINT NOT NULL,
    motivo_cierre               NVARCHAR(500) NOT NULL,
    fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bitacora_cierre_contrato_fecha DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_bitacora_cierre_contrato PRIMARY KEY (id_bitacora_cierre_contrato),
    CONSTRAINT FK_msp_bitacora_cierre_contrato_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT CK_msp_bitacora_cierre_contrato_estados
        CHECK (estado_contrato_anterior IN (1,2,3,4,5) AND estado_contrato_nuevo IN (1,2,3,4,5))
);
GO

CREATE INDEX IX_msp_bitacora_cierre_contrato_contrato_fecha
    ON dbo.msp_bitacora_cierre_contrato (id_contrato_arriendo, fecha_registro DESC);
GO

/* =========================================================================
   4. HISTORIAL DE CAMBIOS DE CONTRATO
   ========================================================================= */

CREATE TABLE dbo.msp_historial_contrato (
    id_historial_contrato      INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo       INT NOT NULL,
    tipo_evento                NVARCHAR(30) NOT NULL,
    id_usuario                 INT NOT NULL,
    detalle_evento             NVARCHAR(MAX) NULL,
    motivo_evento              NVARCHAR(500) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_historial_contrato_fecha DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_historial_contrato PRIMARY KEY (id_historial_contrato),
    CONSTRAINT FK_msp_historial_contrato_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT CK_msp_historial_contrato_tipo
        CHECK (tipo_evento IN (N'CREACION', N'ACTUALIZACION', N'CIERRE'))
);
GO

CREATE INDEX IX_msp_historial_contrato_contrato_fecha
    ON dbo.msp_historial_contrato (id_contrato_arriendo, fecha_registro DESC);
GO

/* =========================================================================
   5. GARANTIAS POR LOCAL
   Estado:
     1 = Vigente
     2 = Con reserva
     3 = Aplicada parcial
     4 = Devuelta parcial
     5 = Cerrada
     6 = Anulada
   ========================================================================= */

CREATE TABLE dbo.msp_garantias (
    id_garantia              INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo     INT NOT NULL,
    id_local                 INT NOT NULL,
    fecha_constitucion       DATE NOT NULL,
    monto_inicial            DECIMAL(18,2) NOT NULL,
    estado_garantia          TINYINT NOT NULL CONSTRAINT DF_msp_garantias_estado DEFAULT (1),
    observaciones            NVARCHAR(500) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantias_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_garantias PRIMARY KEY (id_garantia),
    CONSTRAINT FK_msp_garantias_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT FK_msp_garantias_local
        FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT UQ_msp_garantias_contrato_local UNIQUE (id_contrato_arriendo, id_local),
    CONSTRAINT CK_msp_garantias_monto CHECK (monto_inicial >= 0),
    CONSTRAINT CK_msp_garantias_estado CHECK (estado_garantia IN (1,2,3,4,5,6))
);
GO

CREATE INDEX IX_msp_garantias_contrato
    ON dbo.msp_garantias (id_contrato_arriendo, estado_garantia, id_local);
GO

/* =========================================================================
   6. CARGOS POR LOCAL
   Origen:
     1 = Documento
     2 = Estimacion manual
     3 = Multa
     4 = Manual

   Estado:
     1 = Pendiente
     2 = Reservado
     3 = Aplicado
     4 = Pagado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_cargos_salida (
    id_cargo_salida          INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo     INT NOT NULL,
    id_local                 INT NULL,
    id_tipo_cargo_salida     INT NOT NULL,
    fecha_cargo              DATE NOT NULL CONSTRAINT DF_msp_cargos_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
    origen_cargo             TINYINT NOT NULL,
    id_documento_cobro       INT NULL,
    periodo_referencia       DATE NULL,
    servicio_referencia      NVARCHAR(30) NULL,
    descripcion_cargo        NVARCHAR(500) NOT NULL,
    monto_cargo              DECIMAL(18,2) NOT NULL,
    es_estimado              BIT NOT NULL CONSTRAINT DF_msp_cargos_estimado DEFAULT (0),
    estado_cargo             TINYINT NOT NULL CONSTRAINT DF_msp_cargos_estado DEFAULT (1),
    observaciones            NVARCHAR(500) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cargos_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_cargos_salida PRIMARY KEY (id_cargo_salida),
    CONSTRAINT FK_msp_cargos_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT FK_msp_cargos_local
        FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT FK_msp_cargos_tipo
        FOREIGN KEY (id_tipo_cargo_salida) REFERENCES dbo.msp_tipos_cargo_salida (id_tipo_cargo_salida),
    CONSTRAINT FK_msp_cargos_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT CK_msp_cargos_origen CHECK (origen_cargo IN (1,2,3,4)),
    CONSTRAINT CK_msp_cargos_estado CHECK (estado_cargo IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_cargos_monto CHECK (monto_cargo > 0),
    CONSTRAINT CK_msp_cargos_periodo CHECK (
        periodo_referencia IS NULL OR DAY(periodo_referencia) = 1
    )
);
GO

CREATE INDEX IX_msp_cargos_contrato_local
    ON dbo.msp_cargos_salida (id_contrato_arriendo, id_local, estado_cargo, fecha_cargo DESC);
GO

CREATE INDEX IX_msp_cargos_documento
    ON dbo.msp_cargos_salida (id_documento_cobro, id_cargo_salida DESC);
GO

/* =========================================================================
   7. MOVIMIENTOS DE GARANTIA
   fondo_origen:
     D = Disponible
     R = Reservado
     NULL = No aplica
   ========================================================================= */

CREATE TABLE dbo.msp_movimientos_garantia (
    id_movimiento_garantia       INT IDENTITY(1,1) NOT NULL,
    id_garantia                  INT NOT NULL,
    fecha_movimiento             DATE NOT NULL CONSTRAINT DF_msp_mov_garantia_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
    id_tipo_movimiento_garantia  INT NOT NULL,
    fondo_origen                 CHAR(1) NULL,
    monto_movimiento             DECIMAL(18,2) NOT NULL,
    id_cargo_salida              INT NULL,
    id_documento_cobro           INT NULL,
    id_pago                      INT NULL,
    observaciones                NVARCHAR(500) NULL,
    fecha_registro               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_mov_garantia_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_movimientos_garantia PRIMARY KEY (id_movimiento_garantia),
    CONSTRAINT FK_msp_mov_garantia_garantia
        FOREIGN KEY (id_garantia) REFERENCES dbo.msp_garantias (id_garantia),
    CONSTRAINT FK_msp_mov_garantia_tipo
        FOREIGN KEY (id_tipo_movimiento_garantia) REFERENCES dbo.msp_tipos_movimiento_garantia (id_tipo_movimiento_garantia),
    CONSTRAINT FK_msp_mov_garantia_cargo
        FOREIGN KEY (id_cargo_salida) REFERENCES dbo.msp_cargos_salida (id_cargo_salida),
    CONSTRAINT FK_msp_mov_garantia_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_mov_garantia_pago
        FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
    CONSTRAINT CK_msp_mov_garantia_monto CHECK (monto_movimiento > 0),
    CONSTRAINT CK_msp_mov_garantia_fondo CHECK (
        fondo_origen IS NULL OR fondo_origen IN ('D', 'R')
    )
);
GO

CREATE INDEX IX_msp_mov_garantia_garantia
    ON dbo.msp_movimientos_garantia (id_garantia, fecha_movimiento DESC, id_movimiento_garantia DESC);
GO

CREATE INDEX IX_msp_mov_garantia_cargo
    ON dbo.msp_movimientos_garantia (id_cargo_salida, id_movimiento_garantia DESC);
GO

/* =========================================================================
   8. TRIGGERS DE CONSISTENCIA
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_valida_local_contrato
ON dbo.msp_garantias
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        LEFT JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_tienda = c.id_tienda
           AND ol.id_local = i.id_local
        WHERE ol.id_ocupacion_local IS NULL
    )
    BEGIN
        ;THROW 50301, 'La garantia debe asociarse a un local que pertenezca a la tienda del contrato.', 1;
    END;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_valida_local_contrato
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        LEFT JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_tienda = c.id_tienda
           AND ol.id_local = i.id_local
        WHERE i.id_local IS NOT NULL
          AND ol.id_ocupacion_local IS NULL
    )
    BEGIN
        ;THROW 50302, 'El cargo debe asociarse a un local que pertenezca a la tienda del contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = i.id_tipo_cargo_salida
        WHERE tc.requiere_documento = 1
          AND i.id_documento_cobro IS NULL
    )
    BEGIN
        ;THROW 50303, 'El tipo de cargo exige un documento asociado.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = i.id_documento_cobro
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        WHERE i.id_documento_cobro IS NOT NULL
          AND dc.id_tienda <> c.id_tienda
    )
    BEGIN
        ;THROW 50304, 'El documento asociado al cargo no pertenece a la tienda del contrato.', 1;
    END;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_multa_alcance_tienda
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE cs
    SET cs.id_local = NULL
    FROM dbo.msp_cargos_salida cs
    INNER JOIN inserted i
        ON i.id_cargo_salida = cs.id_cargo_salida
    INNER JOIN dbo.msp_tipos_cargo_salida tc
        ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
    WHERE UPPER(LTRIM(RTRIM(tc.codigo_tipo_cargo))) = N'MULTA'
      AND cs.id_local IS NOT NULL;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_valida_garantia_cargo
ON dbo.msp_movimientos_garantia
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g
            ON g.id_garantia = i.id_garantia
        INNER JOIN dbo.msp_cargos_salida cs
            ON cs.id_cargo_salida = i.id_cargo_salida
        WHERE i.id_cargo_salida IS NOT NULL
          AND (
                cs.id_contrato_arriendo <> g.id_contrato_arriendo
                OR cs.id_local <> g.id_local
              )
    )
    BEGIN
        ;THROW 50305, 'La garantia solo puede cubrir cargos de su mismo local y contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia IN (2,3,4)
          AND i.id_cargo_salida IS NULL
    )
    BEGIN
        ;THROW 50306, 'Reserva, liberacion y aplicacion deben referenciar un cargo.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia = 4
          AND i.fondo_origen NOT IN ('D', 'R')
    )
    BEGIN
        ;THROW 50307, 'La aplicacion de garantia debe indicar si sale de disponible o reservado.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia <> 4
          AND i.fondo_origen IS NOT NULL
    )
    BEGIN
        ;THROW 50308, 'Solo la aplicacion de garantia usa fondo_origen.', 1;
    END;
END;
GO

/* =========================================================================
   9. VISTAS DE APOYO
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_garantias_resumen
AS
SELECT
    g.id_garantia,
    g.id_contrato_arriendo,
    g.id_local,
    c.id_tienda,
    c.id_arrendatario,
    g.fecha_constitucion,
    g.estado_garantia,
    g.monto_inicial,
    CAST(ISNULL(mov.total_reserva, 0) AS DECIMAL(18,2)) AS total_reserva,
    CAST(ISNULL(mov.total_liberacion, 0) AS DECIMAL(18,2)) AS total_liberacion,
    CAST(ISNULL(mov.total_aplicado_disponible, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_disponible,
    CAST(ISNULL(mov.total_aplicado_reservado, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_reservado,
    CAST(ISNULL(mov.total_devuelto, 0) AS DECIMAL(18,2)) AS total_devuelto,
    CAST(ISNULL(mov.total_ajuste_positivo, 0) AS DECIMAL(18,2)) AS total_ajuste_positivo,
    CAST(ISNULL(mov.total_ajuste_negativo, 0) AS DECIMAL(18,2)) AS total_ajuste_negativo,
    CAST(
        g.monto_inicial
        - ISNULL(mov.total_reserva, 0)
        + ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_disponible, 0)
        - ISNULL(mov.total_devuelto, 0)
        + ISNULL(mov.total_ajuste_positivo, 0)
        - ISNULL(mov.total_ajuste_negativo, 0)
        AS DECIMAL(18,2)
    ) AS saldo_disponible,
    CAST(
        ISNULL(mov.total_reserva, 0)
        - ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_reservado,
    CAST(
        ISNULL(mov.total_aplicado_disponible, 0)
        + ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_aplicado
FROM dbo.msp_garantias g
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = g.id_contrato_arriendo
OUTER APPLY (
    SELECT
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END) AS total_reserva,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END) AS total_liberacion,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_disponible,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_reservado,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END) AS total_devuelto,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 6 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_positivo,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 7 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_negativo
    FROM dbo.msp_movimientos_garantia mg
    WHERE mg.id_garantia = g.id_garantia
) mov;
GO

CREATE OR ALTER VIEW dbo.msp_vw_deuda_garantia_local
AS
SELECT
    c.id_contrato_arriendo,
    c.id_tienda,
    c.id_arrendatario,
    g.id_local,
    g.id_garantia,
    gr.monto_inicial,
    gr.saldo_disponible,
    gr.saldo_reservado,
    gr.saldo_aplicado,
    CAST(ISNULL(cg.total_cargos, 0) AS DECIMAL(18,2)) AS total_cargos,
    CAST(ISNULL(cg.total_pendiente, 0) AS DECIMAL(18,2)) AS total_cargos_pendientes,
    CAST(ISNULL(cg.total_reservado, 0) AS DECIMAL(18,2)) AS total_cargos_reservados,
    CAST(ISNULL(cg.total_aplicado, 0) AS DECIMAL(18,2)) AS total_cargos_aplicados
FROM dbo.msp_garantias g
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = g.id_contrato_arriendo
INNER JOIN dbo.msp_vw_garantias_resumen gr
    ON gr.id_garantia = g.id_garantia
OUTER APPLY (
    SELECT
        SUM(cs.monto_cargo) AS total_cargos,
        SUM(CASE WHEN cs.estado_cargo = 1 THEN cs.monto_cargo ELSE 0 END) AS total_pendiente,
        SUM(CASE WHEN cs.estado_cargo = 2 THEN cs.monto_cargo ELSE 0 END) AS total_reservado,
        SUM(CASE WHEN cs.estado_cargo = 3 THEN cs.monto_cargo ELSE 0 END) AS total_aplicado
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = g.id_contrato_arriendo
      AND cs.id_local = g.id_local
      AND cs.estado_cargo <> 5
) cg;
GO

PRINT 'P4';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_dia_cobro_fijo.sql';
/* ===================================================================== */

/*
===========================================================================
 PATCH: dia_cobro fijo en 1 para contratos
 Idempotente / SQL Server
===========================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.msp_contratos_arriendo', 'U') IS NULL
BEGIN
    PRINT 'patch_dia_cobro_fijo: tabla dbo.msp_contratos_arriendo no existe. Se omite.';
    RETURN;
END;
GO

UPDATE dbo.msp_contratos_arriendo
SET dia_cobro = 1
WHERE ISNULL(dia_cobro, 0) <> 1;
GO

IF EXISTS (
    SELECT 1
    FROM sys.columns c
    WHERE c.object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND c.name = 'dia_cobro'
      AND c.default_object_id = 0
)
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    ADD CONSTRAINT DF_msp_contratos_dia_cobro
        DEFAULT (1) FOR dia_cobro;

    PRINT 'patch_dia_cobro_fijo: default DF_msp_contratos_dia_cobro creado.';
END
ELSE
BEGIN
    PRINT 'patch_dia_cobro_fijo: default de dia_cobro ya existia.';
END;
GO

PRINT 'patch_dia_cobro_fijo aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_contrato_termino_efectivo.sql';
/* ===================================================================== */

/*
===========================================================================
 PATCH: fecha_termino_efectiva en contratos + regla de fechas
 Idempotente / SQL Server
===========================================================================
*/

SET NOCOUNT ON;

IF OBJECT_ID('dbo.msp_contratos_arriendo', 'U') IS NULL
BEGIN
    PRINT 'patch_contrato_termino_efectivo: tabla dbo.msp_contratos_arriendo no existe. Se omite.';
    RETURN;
END;
GO

IF COL_LENGTH('dbo.msp_contratos_arriendo', 'fecha_termino_efectiva') IS NULL
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    ADD fecha_termino_efectiva DATE NULL;

    PRINT 'patch_contrato_termino_efectivo: columna fecha_termino_efectiva creada.';
END
ELSE
BEGIN
    PRINT 'patch_contrato_termino_efectivo: columna fecha_termino_efectiva ya existia.';
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'CK_msp_contratos_fechas'
)
BEGIN
    ALTER TABLE dbo.msp_contratos_arriendo
    DROP CONSTRAINT CK_msp_contratos_fechas;
END;
GO

ALTER TABLE dbo.msp_contratos_arriendo WITH NOCHECK
ADD CONSTRAINT CK_msp_contratos_fechas CHECK (
    (fecha_termino_pactada IS NULL OR fecha_termino_pactada >= fecha_inicio)
    AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva >= fecha_inicio)
);
GO

ALTER TABLE dbo.msp_contratos_arriendo
CHECK CONSTRAINT CK_msp_contratos_fechas;
GO

PRINT 'patch_contrato_termino_efectivo aplicado.';
GO


GO


/* ===================================================================== */
PRINT 'MSP initial: patch_contrato_indices_operativos.sql';
/* ===================================================================== */

/*
===========================================================================
 PATCH: contratos - indices operativos y cierre financiero
 Objetivo:
 - Estado operativo de contrato = (1,2).
 - Estado 3 queda reservado para cierre financiero.
 - Agregar soporte de consulta para estado 3 y fecha_termino_efectiva.
===========================================================================
*/

SET NOCOUNT ON;
GO

/* -----------------------------------------------------------
   1) Unico por tienda solo en estado operativo (1,2)
   ----------------------------------------------------------- */
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'UX_msp_contratos_tienda_activo'
)
BEGIN
    DROP INDEX UX_msp_contratos_tienda_activo ON dbo.msp_contratos_arriendo;
    PRINT 'patch_contrato_indices_operativos: UX_msp_contratos_tienda_activo eliminada.';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'UX_msp_contratos_tienda_activo'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_contratos_tienda_activo
        ON dbo.msp_contratos_arriendo (id_tienda)
        WHERE estado_contrato IN (1,2);

    PRINT 'patch_contrato_indices_operativos: UX_msp_contratos_tienda_activo creada (estado 1,2).';
END;
GO

/* -----------------------------------------------------------
   2) Indice para bandeja de cierre financiero (estado 3)
   ----------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'IX_msp_contratos_cierre_financiero'
)
BEGIN
    CREATE INDEX IX_msp_contratos_cierre_financiero
        ON dbo.msp_contratos_arriendo (estado_contrato, fecha_termino_efectiva, id_tienda, id_arrendatario)
        WHERE estado_contrato = 3;

    PRINT 'patch_contrato_indices_operativos: IX_msp_contratos_cierre_financiero creada.';
END;
GO

/* -----------------------------------------------------------
   3) Indice por fecha termino efectiva (cierres/reportes)
   ----------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'IX_msp_contratos_fecha_termino_efectiva'
)
BEGIN
    CREATE INDEX IX_msp_contratos_fecha_termino_efectiva
        ON dbo.msp_contratos_arriendo (fecha_termino_efectiva, estado_contrato, id_contrato_arriendo)
        WHERE fecha_termino_efectiva IS NOT NULL;

    PRINT 'patch_contrato_indices_operativos: IX_msp_contratos_fecha_termino_efectiva creada.';
END;
GO

PRINT 'patch_contrato_indices_operativos: OK';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_tiendas_fecha_termino.sql';
/* ===================================================================== */

/*
 PATCH: fecha_termino en msp_tiendas + regla de fechas
 Idempotente para SQL Server
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID('dbo.msp_tiendas', 'U') IS NULL
BEGIN
    PRINT 'patch_tiendas_fecha_termino: tabla dbo.msp_tiendas no existe, se omite.';
    RETURN;
END
GO

IF COL_LENGTH('dbo.msp_tiendas', 'fecha_termino') IS NULL
BEGIN
    ALTER TABLE dbo.msp_tiendas
    ADD fecha_termino DATE NULL;

    PRINT 'patch_tiendas_fecha_termino: columna fecha_termino creada.';
END
ELSE
BEGIN
    PRINT 'patch_tiendas_fecha_termino: columna fecha_termino ya existia.';
END
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID('dbo.msp_tiendas')
      AND name = 'CK_msp_tiendas_fechas'
)
BEGIN
    ALTER TABLE dbo.msp_tiendas
    DROP CONSTRAINT CK_msp_tiendas_fechas;
END
GO

ALTER TABLE dbo.msp_tiendas
ADD CONSTRAINT CK_msp_tiendas_fechas CHECK (
    fecha_inicio IS NULL
    OR fecha_termino IS NULL
    OR fecha_termino >= fecha_inicio
);
GO

PRINT 'patch_tiendas_fecha_termino: constraint CK_msp_tiendas_fechas aplicada.';
GO


GO


/* ===================================================================== */
PRINT 'MSP initial: patch_bitacora_cierre_contrato.sql';
/* ===================================================================== */

/* =========================================================================
   PATCH: BITACORA DE CIERRE DE CONTRATOS MSP
   Fecha: 2026-03-18
   Crea tabla dbo.msp_bitacora_cierre_contrato si no existe.
   ========================================================================= */

IF OBJECT_ID('dbo.msp_bitacora_cierre_contrato', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_bitacora_cierre_contrato (
        id_bitacora_cierre_contrato INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo        INT NOT NULL,
        id_usuario                  INT NOT NULL,
        estado_contrato_anterior    TINYINT NOT NULL,
        estado_contrato_nuevo       TINYINT NOT NULL,
        motivo_cierre               NVARCHAR(500) NOT NULL,
        fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bitacora_cierre_contrato_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_bitacora_cierre_contrato PRIMARY KEY (id_bitacora_cierre_contrato),
        CONSTRAINT FK_msp_bitacora_cierre_contrato_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_bitacora_cierre_contrato_estados
            CHECK (estado_contrato_anterior IN (1,2,3,4,5) AND estado_contrato_nuevo IN (1,2,3,4,5))
    );

    CREATE INDEX IX_msp_bitacora_cierre_contrato_contrato_fecha
        ON dbo.msp_bitacora_cierre_contrato (id_contrato_arriendo, fecha_registro DESC);
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_historial_contrato.sql';
/* ===================================================================== */

/* =========================================================================
   PATCH: HISTORIAL DE CAMBIOS DE CONTRATO MSP
   Fecha: 2026-03-23
   Crea tabla dbo.msp_historial_contrato si no existe.
   ========================================================================= */

IF OBJECT_ID('dbo.msp_historial_contrato', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_historial_contrato (
        id_historial_contrato      INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo       INT NOT NULL,
        tipo_evento                NVARCHAR(30) NOT NULL,
        id_usuario                 INT NOT NULL,
        detalle_evento             NVARCHAR(MAX) NULL,
        motivo_evento              NVARCHAR(500) NULL,
        fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_historial_contrato_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_historial_contrato PRIMARY KEY (id_historial_contrato),
        CONSTRAINT FK_msp_historial_contrato_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT CK_msp_historial_contrato_tipo
            CHECK (tipo_evento IN (N'CREACION', N'ACTUALIZACION', N'CIERRE'))
    );

    CREATE INDEX IX_msp_historial_contrato_contrato_fecha
        ON dbo.msp_historial_contrato (id_contrato_arriendo, fecha_registro DESC);
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_fase1_contrato_locales.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - FASE 1: CONTRATO_LOCALES
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Requiere tablas base de A1 y de deudores/garantia ya instaladas
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. TABLA NUEVA: msp_contrato_locales
   Estado relacion:
     1 = Activa
     2 = Finalizada
     3 = Anulada
   ========================================================================= */

IF OBJECT_ID('dbo.msp_contrato_locales', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_contrato_locales (
        id_contrato_local      INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo   INT NOT NULL,
        id_local               INT NOT NULL,
        fecha_inicio           DATE NOT NULL,
        fecha_termino          DATE NULL,
        orden_visual           INT NOT NULL CONSTRAINT DF_msp_contrato_locales_orden DEFAULT (1),
        estado_relacion        TINYINT NOT NULL CONSTRAINT DF_msp_contrato_locales_estado DEFAULT (1),
        monto_arriendo_local   DECIMAL(18,2) NULL,
        observaciones          NVARCHAR(500) NULL,
        fecha_registro         DATETIME2(0) NOT NULL CONSTRAINT DF_msp_contrato_locales_fecha_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_contrato_locales PRIMARY KEY (id_contrato_local),
        CONSTRAINT FK_msp_contrato_locales_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT FK_msp_contrato_locales_local
            FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
        CONSTRAINT CK_msp_contrato_locales_estado
            CHECK (estado_relacion IN (1,2,3)),
        CONSTRAINT CK_msp_contrato_locales_fechas
            CHECK (fecha_termino IS NULL OR fecha_termino >= fecha_inicio),
        CONSTRAINT CK_msp_contrato_locales_monto
            CHECK (monto_arriendo_local IS NULL OR monto_arriendo_local >= 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'UX_msp_contrato_locales_contrato_local_inicio'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_contrato_locales_contrato_local_inicio
        ON dbo.msp_contrato_locales (id_contrato_arriendo, id_local, fecha_inicio);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'IX_msp_contrato_locales_local_fechas'
)
BEGIN
    CREATE INDEX IX_msp_contrato_locales_local_fechas
        ON dbo.msp_contrato_locales (id_local, fecha_inicio, fecha_termino, estado_relacion);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'IX_msp_contrato_locales_contrato_estado'
)
BEGIN
    CREATE INDEX IX_msp_contrato_locales_contrato_estado
        ON dbo.msp_contrato_locales (id_contrato_arriendo, estado_relacion, orden_visual, id_contrato_local);
END;
GO

/* =========================================================================
   2. TRIGGER: NO SOLAPAMIENTO DE LOCALES ENTRE CONTRATOS ACTIVOS

   Regla de Fase 1:
   - Bloquear solapamiento del mismo local cuando ambas filas estan activas
     (estado_relacion = 1) y ambos contratos estan en estado operativo
     (1 = Borrador, 2 = Vigente).
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_contrato_locales_no_solapamiento
ON dbo.msp_contrato_locales
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_contrato_locales cl
        INNER JOIN inserted i
            ON cl.id_local = i.id_local
           AND cl.id_contrato_local <> i.id_contrato_local
           AND i.fecha_inicio <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
           AND cl.fecha_inicio <= ISNULL(i.fecha_termino, CONVERT(date, '9999-12-31'))
        INNER JOIN dbo.msp_contratos_arriendo c1
            ON c1.id_contrato_arriendo = i.id_contrato_arriendo
        INNER JOIN dbo.msp_contratos_arriendo c2
            ON c2.id_contrato_arriendo = cl.id_contrato_arriendo
        WHERE i.estado_relacion = 1
          AND cl.estado_relacion = 1
          AND c1.estado_contrato IN (1,2)
          AND c2.estado_contrato IN (1,2)
    )
    BEGIN
        ;THROW 50401, 'No se puede solapar el mismo local en contratos activos.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i1
        INNER JOIN inserted i2
            ON i1.id_local = i2.id_local
           AND i1.id_contrato_local <> i2.id_contrato_local
           AND i1.fecha_inicio <= ISNULL(i2.fecha_termino, CONVERT(date, '9999-12-31'))
           AND i2.fecha_inicio <= ISNULL(i1.fecha_termino, CONVERT(date, '9999-12-31'))
        INNER JOIN dbo.msp_contratos_arriendo c1
            ON c1.id_contrato_arriendo = i1.id_contrato_arriendo
        INNER JOIN dbo.msp_contratos_arriendo c2
            ON c2.id_contrato_arriendo = i2.id_contrato_arriendo
        WHERE i1.estado_relacion = 1
          AND i2.estado_relacion = 1
          AND c1.estado_contrato IN (1,2)
          AND c2.estado_contrato IN (1,2)
    )
    BEGIN
        ;THROW 50402, 'El lote contiene locales solapados en contratos activos.', 1;
    END;
END;
GO

/* =========================================================================
   3. MIGRACION INICIAL
   Fuente:
   - msp_ocupacion_locales + contrato activo por tienda

   Regla:
   - Se inserta solo si no existe ya la misma combinacion
     (contrato, local, fecha_inicio).
   - orden_visual inicial basado en codigo local para cada contrato.
   ========================================================================= */

;WITH base AS (
    SELECT
        c.id_contrato_arriendo,
        ol.id_local,
        ol.fecha_inicio,
        ol.fecha_termino,
        ROW_NUMBER() OVER (
            PARTITION BY c.id_contrato_arriendo
            ORDER BY ml.cdo_local, ol.fecha_inicio, ol.id_ocupacion_local
        ) AS orden_visual
    FROM dbo.msp_contratos_arriendo c
    INNER JOIN dbo.msp_ocupacion_locales ol
        ON ol.id_tienda = c.id_tienda
    INNER JOIN dbo.msp_locales ml
        ON ml.id_local = ol.id_local
    WHERE c.estado_contrato IN (1,2)
)
INSERT INTO dbo.msp_contrato_locales (
    id_contrato_arriendo,
    id_local,
    fecha_inicio,
    fecha_termino,
    orden_visual,
    estado_relacion,
    monto_arriendo_local,
    observaciones
)
SELECT
    b.id_contrato_arriendo,
    b.id_local,
    b.fecha_inicio,
    b.fecha_termino,
    b.orden_visual,
    CASE WHEN b.fecha_termino IS NULL THEN 1 ELSE 2 END AS estado_relacion,
    NULL AS monto_arriendo_local,
    N'Migrado desde msp_ocupacion_locales (Fase 1)' AS observaciones
FROM base b
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.msp_contrato_locales cl
    WHERE cl.id_contrato_arriendo = b.id_contrato_arriendo
      AND cl.id_local = b.id_local
      AND cl.fecha_inicio = b.fecha_inicio
);
GO

/* =========================================================================
   4. REORDENAR orden_visual EN CONTRATOS CON DATOS YA EXISTENTES
   ========================================================================= */

;WITH orden AS (
    SELECT
        cl.id_contrato_local,
        ROW_NUMBER() OVER (
            PARTITION BY cl.id_contrato_arriendo
            ORDER BY ml.cdo_local, cl.fecha_inicio, cl.id_contrato_local
        ) AS nuevo_orden
    FROM dbo.msp_contrato_locales cl
    INNER JOIN dbo.msp_locales ml
        ON ml.id_local = cl.id_local
)
UPDATE cl
SET cl.orden_visual = o.nuevo_orden
FROM dbo.msp_contrato_locales cl
INNER JOIN orden o
    ON o.id_contrato_local = cl.id_contrato_local
WHERE cl.orden_visual <> o.nuevo_orden;
GO

/* =========================================================================
   5. VISTA DE APOYO (FASE 1)
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_contrato_locales_activos
AS
SELECT
    cl.id_contrato_local,
    cl.id_contrato_arriendo,
    cl.id_local,
    cl.fecha_inicio,
    cl.fecha_termino,
    cl.orden_visual,
    cl.estado_relacion,
    cl.monto_arriendo_local,
    c.id_tienda,
    c.id_arrendatario,
    c.estado_contrato
FROM dbo.msp_contrato_locales cl
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = cl.id_contrato_arriendo
WHERE cl.estado_relacion = 1
  AND c.estado_contrato IN (1,2);
GO

/* =========================================================================
   6. VALIDACIONES DE CONTROL POST-MIGRACION
   ========================================================================= */

PRINT 'Fase 1 aplicada: msp_contrato_locales creada y poblada.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_fase2_garantia_contrato_local.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - FASE 2: RELIGAR GARANTIA A CONTRATO-LOCAL
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Requiere Fase 1 aplicada (msp_contrato_locales)
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. AGREGAR id_contrato_local EN GARANTIAS (COMPATIBILIDAD TRANSITORIA)
   ========================================================================= */

IF COL_LENGTH('dbo.msp_garantias', 'id_contrato_local') IS NULL
BEGIN
    ALTER TABLE dbo.msp_garantias
        ADD id_contrato_local INT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'FK_msp_garantias_contrato_local'
      AND parent_object_id = OBJECT_ID('dbo.msp_garantias')
)
BEGIN
    ALTER TABLE dbo.msp_garantias
        ADD CONSTRAINT FK_msp_garantias_contrato_local
            FOREIGN KEY (id_contrato_local) REFERENCES dbo.msp_contrato_locales (id_contrato_local);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_garantias')
      AND name = 'IX_msp_garantias_contrato_local'
)
BEGIN
    CREATE INDEX IX_msp_garantias_contrato_local
        ON dbo.msp_garantias (id_contrato_local)
        WHERE id_contrato_local IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_garantias')
      AND name = 'UX_msp_garantias_id_contrato_local'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_garantias_id_contrato_local
        ON dbo.msp_garantias (id_contrato_local)
        WHERE id_contrato_local IS NOT NULL;
END;
GO

/* =========================================================================
   2. POBLAR id_contrato_local PARA DATOS EXISTENTES

   Estrategia:
   - Paso A: match por contrato/local + fecha_constitucion dentro del rango.
   - Paso B: fallback por contrato/local priorizando fila activa y mas reciente.
   ========================================================================= */

;WITH match_rango AS (
    SELECT
        g.id_garantia,
        cl.id_contrato_local,
        ROW_NUMBER() OVER (
            PARTITION BY g.id_garantia
            ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
        ) AS rn
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_arriendo = g.id_contrato_arriendo
       AND cl.id_local = g.id_local
       AND g.fecha_constitucion >= cl.fecha_inicio
       AND g.fecha_constitucion <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
    WHERE g.id_contrato_local IS NULL
)
UPDATE g
SET g.id_contrato_local = mr.id_contrato_local
FROM dbo.msp_garantias g
INNER JOIN match_rango mr
    ON mr.id_garantia = g.id_garantia
   AND mr.rn = 1;
GO

;WITH match_fallback AS (
    SELECT
        g.id_garantia,
        cl.id_contrato_local,
        ROW_NUMBER() OVER (
            PARTITION BY g.id_garantia
            ORDER BY
                CASE WHEN cl.estado_relacion = 1 THEN 0 ELSE 1 END,
                cl.fecha_inicio DESC,
                cl.id_contrato_local DESC
        ) AS rn
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_arriendo = g.id_contrato_arriendo
       AND cl.id_local = g.id_local
    WHERE g.id_contrato_local IS NULL
)
UPDATE g
SET g.id_contrato_local = mf.id_contrato_local
FROM dbo.msp_garantias g
INNER JOIN match_fallback mf
    ON mf.id_garantia = g.id_garantia
   AND mf.rn = 1;
GO

/* =========================================================================
   3. VALIDACION DE COHERENCIA EN GARANTIAS
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_valida_local_contrato
ON dbo.msp_garantias
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    /* Si viene id_contrato_local, debe coincidir con contrato/local legacy */
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = i.id_contrato_local
        WHERE i.id_contrato_local IS NOT NULL
          AND (
                cl.id_contrato_arriendo <> i.id_contrato_arriendo
                OR cl.id_local <> i.id_local
              )
    )
    BEGIN
        ;THROW 50311, 'id_contrato_local no coincide con id_contrato_arriendo e id_local de la garantia.', 1;
    END;

    /* Mantener regla legacy mientras exista compatibilidad */
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        LEFT JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_tienda = c.id_tienda
           AND ol.id_local = i.id_local
        WHERE ol.id_ocupacion_local IS NULL
    )
    BEGIN
        ;THROW 50301, 'La garantia debe asociarse a un local que pertenezca a la tienda del contrato.', 1;
    END;

    /* Si viene id_contrato_local, validar ventana temporal con fecha_constitucion */
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = i.id_contrato_local
        WHERE i.id_contrato_local IS NOT NULL
          AND (
                i.fecha_constitucion < cl.fecha_inicio
                OR i.fecha_constitucion > ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
              )
    )
    BEGIN
        ;THROW 50312, 'fecha_constitucion debe caer dentro de la vigencia de contrato-local.', 1;
    END;
END;
GO

/* =========================================================================
   4. VISTAS MIGRADAS A CONTRATO-LOCAL (CON COMPATIBILIDAD)
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_garantias_resumen
AS
SELECT
    g.id_garantia,
    g.id_contrato_local,
    COALESCE(cl.id_contrato_arriendo, g.id_contrato_arriendo) AS id_contrato_arriendo,
    COALESCE(cl.id_local, g.id_local) AS id_local,
    c.id_tienda,
    c.id_arrendatario,
    g.fecha_constitucion,
    g.estado_garantia,
    g.monto_inicial,
    CAST(ISNULL(mov.total_reserva, 0) AS DECIMAL(18,2)) AS total_reserva,
    CAST(ISNULL(mov.total_liberacion, 0) AS DECIMAL(18,2)) AS total_liberacion,
    CAST(ISNULL(mov.total_aplicado_disponible, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_disponible,
    CAST(ISNULL(mov.total_aplicado_reservado, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_reservado,
    CAST(ISNULL(mov.total_devuelto, 0) AS DECIMAL(18,2)) AS total_devuelto,
    CAST(ISNULL(mov.total_ajuste_positivo, 0) AS DECIMAL(18,2)) AS total_ajuste_positivo,
    CAST(ISNULL(mov.total_ajuste_negativo, 0) AS DECIMAL(18,2)) AS total_ajuste_negativo,
    CAST(
        g.monto_inicial
        - ISNULL(mov.total_reserva, 0)
        + ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_disponible, 0)
        - ISNULL(mov.total_devuelto, 0)
        + ISNULL(mov.total_ajuste_positivo, 0)
        - ISNULL(mov.total_ajuste_negativo, 0)
        AS DECIMAL(18,2)
    ) AS saldo_disponible,
    CAST(
        ISNULL(mov.total_reserva, 0)
        - ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_reservado,
    CAST(
        ISNULL(mov.total_aplicado_disponible, 0)
        + ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_aplicado
FROM dbo.msp_garantias g
LEFT JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local = g.id_contrato_local
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = COALESCE(cl.id_contrato_arriendo, g.id_contrato_arriendo)
OUTER APPLY (
    SELECT
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END) AS total_reserva,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END) AS total_liberacion,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_disponible,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_reservado,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END) AS total_devuelto,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 6 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_positivo,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 7 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_negativo
    FROM dbo.msp_movimientos_garantia mg
    WHERE mg.id_garantia = g.id_garantia
) mov;
GO

CREATE OR ALTER VIEW dbo.msp_vw_deuda_garantia_local
AS
SELECT
    gr.id_contrato_local,
    gr.id_contrato_arriendo,
    gr.id_tienda,
    gr.id_arrendatario,
    gr.id_local,
    gr.id_garantia,
    gr.monto_inicial,
    gr.saldo_disponible,
    gr.saldo_reservado,
    gr.saldo_aplicado,
    CAST(ISNULL(cg.total_cargos, 0) AS DECIMAL(18,2)) AS total_cargos,
    CAST(ISNULL(cg.total_pendiente, 0) AS DECIMAL(18,2)) AS total_cargos_pendientes,
    CAST(ISNULL(cg.total_reservado, 0) AS DECIMAL(18,2)) AS total_cargos_reservados,
    CAST(ISNULL(cg.total_aplicado, 0) AS DECIMAL(18,2)) AS total_cargos_aplicados
FROM dbo.msp_vw_garantias_resumen gr
OUTER APPLY (
    SELECT
        SUM(cs.monto_cargo) AS total_cargos,
        SUM(CASE WHEN cs.estado_cargo = 1 THEN cs.monto_cargo ELSE 0 END) AS total_pendiente,
        SUM(CASE WHEN cs.estado_cargo = 2 THEN cs.monto_cargo ELSE 0 END) AS total_reservado,
        SUM(CASE WHEN cs.estado_cargo = 3 THEN cs.monto_cargo ELSE 0 END) AS total_aplicado
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = gr.id_contrato_arriendo
      AND cs.id_local = gr.id_local
      AND cs.estado_cargo <> 5
) cg;
GO

/* =========================================================================
   5. CONTROL DE PENDIENTES DE MIGRACION
   ========================================================================= */

DECLARE @pendientes INT = (
    SELECT COUNT(1)
    FROM dbo.msp_garantias
    WHERE id_contrato_local IS NULL
);

PRINT CONCAT('Fase 2 aplicada. Garantias sin id_contrato_local: ', @pendientes);
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_fase3_cargos_contrato_local.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - FASE 3: REEMPLAZAR msp_cargos_salida
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Mantiene compatibilidad legacy durante transicion
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. NUEVA TABLA: msp_cargos_contrato_local

   Estado (compatibilidad con legacy):
     1 = Pendiente
     2 = Reservado
     3 = Aplicado garantia
     4 = Pagado
     5 = Anulado
   ========================================================================= */

IF OBJECT_ID('dbo.msp_cargos_contrato_local', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cargos_contrato_local (
        id_cargo_contrato_local   INT IDENTITY(1,1) NOT NULL,
        id_contrato_local         INT NOT NULL,
        id_tipo_cargo_salida      INT NOT NULL,
        fecha_cargo               DATE NOT NULL CONSTRAINT DF_msp_ccl_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
        periodo_referencia        DATE NULL,
        origen_cargo              TINYINT NOT NULL,
        id_documento_cobro        INT NULL,
        id_pago                   INT NULL,
        descripcion_cargo         NVARCHAR(500) NOT NULL,
        monto_cargo               DECIMAL(18,2) NOT NULL,
        monto_aplicado_garantia   DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_ccl_monto_aplicado DEFAULT (0),
        monto_pagado_directo      DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_ccl_monto_pagado DEFAULT (0),
        estado_cargo              TINYINT NOT NULL CONSTRAINT DF_msp_ccl_estado DEFAULT (1),
        es_estimado               BIT NOT NULL CONSTRAINT DF_msp_ccl_estimado DEFAULT (0),
        requiere_regularizacion   BIT NOT NULL CONSTRAINT DF_msp_ccl_regularizacion DEFAULT (0),
        servicio_referencia       NVARCHAR(30) NULL,
        observaciones             NVARCHAR(500) NULL,
        id_cargo_salida_legacy    INT NULL,
        fecha_registro            DATETIME2(0) NOT NULL CONSTRAINT DF_msp_ccl_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_cargos_contrato_local PRIMARY KEY (id_cargo_contrato_local),
        CONSTRAINT FK_msp_ccl_contrato_local
            FOREIGN KEY (id_contrato_local) REFERENCES dbo.msp_contrato_locales (id_contrato_local),
        CONSTRAINT FK_msp_ccl_tipo
            FOREIGN KEY (id_tipo_cargo_salida) REFERENCES dbo.msp_tipos_cargo_salida (id_tipo_cargo_salida),
        CONSTRAINT FK_msp_ccl_documento
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT FK_msp_ccl_pago
            FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
        CONSTRAINT CK_msp_ccl_origen CHECK (origen_cargo IN (1,2,3,4)),
        CONSTRAINT CK_msp_ccl_estado CHECK (estado_cargo IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_ccl_monto CHECK (monto_cargo > 0),
        CONSTRAINT CK_msp_ccl_periodo CHECK (
            periodo_referencia IS NULL OR DAY(periodo_referencia) = 1
        ),
        CONSTRAINT CK_msp_ccl_monto_aplicado CHECK (monto_aplicado_garantia >= 0),
        CONSTRAINT CK_msp_ccl_monto_pagado CHECK (monto_pagado_directo >= 0),
        CONSTRAINT CK_msp_ccl_montos_total CHECK (
            monto_aplicado_garantia + monto_pagado_directo <= monto_cargo
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_cargos_contrato_local')
      AND name = 'UX_msp_ccl_legacy'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_ccl_legacy
        ON dbo.msp_cargos_contrato_local (id_cargo_salida_legacy)
        WHERE id_cargo_salida_legacy IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_cargos_contrato_local')
      AND name = 'IX_msp_ccl_contrato_local_estado'
)
BEGIN
    CREATE INDEX IX_msp_ccl_contrato_local_estado
        ON dbo.msp_cargos_contrato_local (id_contrato_local, estado_cargo, fecha_cargo DESC);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_cargos_contrato_local')
      AND name = 'IX_msp_ccl_documento'
)
BEGIN
    CREATE INDEX IX_msp_ccl_documento
        ON dbo.msp_cargos_contrato_local (id_documento_cobro, id_cargo_contrato_local DESC);
END;
GO

/* =========================================================================
   2. MIGRAR CARGOS DESDE msp_cargos_salida

   Mapeo id_contrato_local:
   - Primero por rango de fecha_cargo dentro de contrato-local.
   - Fallback al registro activo/mas reciente del mismo contrato+local.
   ========================================================================= */

;WITH base AS (
    SELECT
        cs.id_cargo_salida,
        cs.id_contrato_arriendo,
        cs.id_local,
        cs.id_tipo_cargo_salida,
        cs.fecha_cargo,
        cs.periodo_referencia,
        cs.origen_cargo,
        cs.id_documento_cobro,
        cs.servicio_referencia,
        cs.descripcion_cargo,
        cs.monto_cargo,
        cs.es_estimado,
        cs.estado_cargo,
        cs.observaciones,
        ISNULL(mg.total_aplicado, 0) AS monto_aplicado_garantia,
        cclr.id_contrato_local
    FROM dbo.msp_cargos_salida cs
    OUTER APPLY (
        SELECT SUM(mov.monto_movimiento) AS total_aplicado
        FROM dbo.msp_movimientos_garantia mov
        WHERE mov.id_cargo_salida = cs.id_cargo_salida
          AND mov.id_tipo_movimiento_garantia = 4
    ) mg
    OUTER APPLY (
        SELECT TOP (1) cl.id_contrato_local
        FROM dbo.msp_contrato_locales cl
        WHERE cl.id_contrato_arriendo = cs.id_contrato_arriendo
          AND cl.id_local = cs.id_local
          AND cs.fecha_cargo >= cl.fecha_inicio
          AND cs.fecha_cargo <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
        ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
    ) cclr
), base_fallback AS (
    SELECT
        b.*,
        COALESCE(
            b.id_contrato_local,
            (
                SELECT TOP (1) cl2.id_contrato_local
                FROM dbo.msp_contrato_locales cl2
                WHERE cl2.id_contrato_arriendo = b.id_contrato_arriendo
                  AND cl2.id_local = b.id_local
                ORDER BY
                    CASE WHEN cl2.estado_relacion = 1 THEN 0 ELSE 1 END,
                    cl2.fecha_inicio DESC,
                    cl2.id_contrato_local DESC
            )
        ) AS id_contrato_local_final
    FROM base b
)
INSERT INTO dbo.msp_cargos_contrato_local (
    id_contrato_local,
    id_tipo_cargo_salida,
    fecha_cargo,
    periodo_referencia,
    origen_cargo,
    id_documento_cobro,
    id_pago,
    descripcion_cargo,
    monto_cargo,
    monto_aplicado_garantia,
    monto_pagado_directo,
    estado_cargo,
    es_estimado,
    requiere_regularizacion,
    servicio_referencia,
    observaciones,
    id_cargo_salida_legacy
)
SELECT
    bf.id_contrato_local_final,
    bf.id_tipo_cargo_salida,
    bf.fecha_cargo,
    bf.periodo_referencia,
    bf.origen_cargo,
    bf.id_documento_cobro,
    NULL AS id_pago,
    bf.descripcion_cargo,
    bf.monto_cargo,
    CAST(CASE WHEN bf.monto_aplicado_garantia > bf.monto_cargo THEN bf.monto_cargo ELSE bf.monto_aplicado_garantia END AS DECIMAL(18,2)) AS monto_aplicado_garantia,
    CAST(0 AS DECIMAL(18,2)) AS monto_pagado_directo,
    bf.estado_cargo,
    bf.es_estimado,
    CAST(0 AS BIT) AS requiere_regularizacion,
    bf.servicio_referencia,
    bf.observaciones,
    bf.id_cargo_salida
FROM base_fallback bf
WHERE bf.id_contrato_local_final IS NOT NULL
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.msp_cargos_contrato_local t
      WHERE t.id_cargo_salida_legacy = bf.id_cargo_salida
);
GO

/* =========================================================================
   3. REENLACE DE MOVIMIENTOS DE GARANTIA AL NUEVO CARGO (COMPATIBLE)
   ========================================================================= */

IF COL_LENGTH('dbo.msp_movimientos_garantia', 'id_cargo_contrato_local') IS NULL
BEGIN
    ALTER TABLE dbo.msp_movimientos_garantia
        ADD id_cargo_contrato_local INT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'FK_msp_mov_garantia_cargo_contrato_local'
      AND parent_object_id = OBJECT_ID('dbo.msp_movimientos_garantia')
)
BEGIN
    ALTER TABLE dbo.msp_movimientos_garantia
        ADD CONSTRAINT FK_msp_mov_garantia_cargo_contrato_local
            FOREIGN KEY (id_cargo_contrato_local) REFERENCES dbo.msp_cargos_contrato_local (id_cargo_contrato_local);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_movimientos_garantia')
      AND name = 'IX_msp_mov_garantia_cargo_contrato_local'
)
BEGIN
    CREATE INDEX IX_msp_mov_garantia_cargo_contrato_local
        ON dbo.msp_movimientos_garantia (id_cargo_contrato_local, id_movimiento_garantia DESC)
        WHERE id_cargo_contrato_local IS NOT NULL;
END;
GO

UPDATE mg
SET mg.id_cargo_contrato_local = ccl.id_cargo_contrato_local
FROM dbo.msp_movimientos_garantia mg
INNER JOIN dbo.msp_cargos_contrato_local ccl
    ON ccl.id_cargo_salida_legacy = mg.id_cargo_salida
WHERE mg.id_cargo_salida IS NOT NULL
  AND mg.id_cargo_contrato_local IS NULL;
GO

/* =========================================================================
   4. AJUSTE TRIGGER DE MOVIMIENTOS (NUEVO + LEGACY)
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_valida_garantia_cargo
ON dbo.msp_movimientos_garantia
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    /* Si vienen ambos ids, deben apuntar al mismo cargo legacy */
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_cargos_contrato_local ccl
            ON ccl.id_cargo_contrato_local = i.id_cargo_contrato_local
        WHERE i.id_cargo_salida IS NOT NULL
          AND i.id_cargo_contrato_local IS NOT NULL
          AND ccl.id_cargo_salida_legacy <> i.id_cargo_salida
    )
    BEGIN
        ;THROW 50309, 'id_cargo_salida e id_cargo_contrato_local no apuntan al mismo cargo.', 1;
    END;

    /* Garantia vs cargo: deben pertenecer al mismo contrato/local */
    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g
            ON g.id_garantia = i.id_garantia
        LEFT JOIN dbo.msp_cargos_salida cs
            ON cs.id_cargo_salida = i.id_cargo_salida
        LEFT JOIN dbo.msp_cargos_contrato_local ccl
            ON ccl.id_cargo_contrato_local = i.id_cargo_contrato_local
        LEFT JOIN dbo.msp_contrato_locales clc
            ON clc.id_contrato_local = ccl.id_contrato_local
        WHERE (i.id_cargo_salida IS NOT NULL OR i.id_cargo_contrato_local IS NOT NULL)
          AND (
                COALESCE(clc.id_contrato_arriendo, cs.id_contrato_arriendo) <> g.id_contrato_arriendo
                OR COALESCE(clc.id_local, cs.id_local) <> g.id_local
              )
    )
    BEGIN
        ;THROW 50305, 'La garantia solo puede cubrir cargos de su mismo local y contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE ((i.id_tipo_movimiento_garantia IN (2,3) AND i.id_cargo_salida IS NULL AND i.id_cargo_contrato_local IS NULL)
           OR (i.id_tipo_movimiento_garantia = 4 AND i.id_cargo_salida IS NULL AND i.id_cargo_contrato_local IS NULL AND i.id_documento_cobro IS NULL))
    )
    BEGIN
        ;THROW 50306, 'Reserva, liberacion y aplicacion deben referenciar un cargo.', 1;
    END;

    IF EXISTS (
        SELECT 1 FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=i.id_documento_cobro
        WHERE i.id_documento_cobro IS NOT NULL AND dc.id_contrato_arriendo<>g.id_contrato_arriendo
    )
    BEGIN
        ;THROW 50310, 'La garantía solo puede cubrir documentos de su mismo contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia = 4
          AND i.fondo_origen NOT IN ('D', 'R')
    )
    BEGIN
        ;THROW 50307, 'La aplicacion de garantia debe indicar si sale de disponible o reservado.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia <> 4
          AND i.fondo_origen IS NOT NULL
    )
    BEGIN
        ;THROW 50308, 'Solo la aplicacion de garantia usa fondo_origen.', 1;
    END;
END;
GO

/* =========================================================================
   5. TRIGGER DE SINCRONIZACION LEGACY -> NUEVO

   Mientras PHP siga grabando en msp_cargos_salida, este trigger mantiene
   msp_cargos_contrato_local actualizado.
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_salida_sync_contrato_local
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF TRY_CONVERT(bit, SESSION_CONTEXT(N'msp_skip_cargo_legacy_sync')) = 1
        RETURN;

    ;WITH src AS (
        SELECT
            i.id_cargo_salida,
            i.id_contrato_arriendo,
            i.id_local,
            i.id_tipo_cargo_salida,
            i.fecha_cargo,
            i.periodo_referencia,
            i.origen_cargo,
            i.id_documento_cobro,
            i.servicio_referencia,
            i.descripcion_cargo,
            i.monto_cargo,
            i.es_estimado,
            i.estado_cargo,
            i.observaciones,
            ISNULL(mg.total_aplicado, 0) AS monto_aplicado_garantia,
            COALESCE(
                ccr.id_contrato_local,
                (
                    SELECT TOP (1) cl2.id_contrato_local
                    FROM dbo.msp_contrato_locales cl2
                    WHERE cl2.id_contrato_arriendo = i.id_contrato_arriendo
                      AND cl2.id_local = i.id_local
                    ORDER BY
                        CASE WHEN cl2.estado_relacion = 1 THEN 0 ELSE 1 END,
                        cl2.fecha_inicio DESC,
                        cl2.id_contrato_local DESC
                )
            ) AS id_contrato_local_final
        FROM inserted i
        OUTER APPLY (
            SELECT SUM(mov.monto_movimiento) AS total_aplicado
            FROM dbo.msp_movimientos_garantia mov
            WHERE mov.id_cargo_salida = i.id_cargo_salida
              AND mov.id_tipo_movimiento_garantia = 4
        ) mg
        OUTER APPLY (
            SELECT TOP (1) cl.id_contrato_local
            FROM dbo.msp_contrato_locales cl
            WHERE cl.id_contrato_arriendo = i.id_contrato_arriendo
              AND cl.id_local = i.id_local
              AND i.fecha_cargo >= cl.fecha_inicio
              AND i.fecha_cargo <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
            ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
        ) ccr
    )
    UPDATE tgt
       SET tgt.id_contrato_local = src.id_contrato_local_final,
           tgt.id_tipo_cargo_salida = src.id_tipo_cargo_salida,
           tgt.fecha_cargo = src.fecha_cargo,
           tgt.periodo_referencia = src.periodo_referencia,
           tgt.origen_cargo = src.origen_cargo,
           tgt.id_documento_cobro = src.id_documento_cobro,
           tgt.descripcion_cargo = src.descripcion_cargo,
           tgt.monto_cargo = src.monto_cargo,
           tgt.monto_aplicado_garantia = CASE WHEN src.monto_aplicado_garantia > src.monto_cargo THEN src.monto_cargo ELSE src.monto_aplicado_garantia END,
           tgt.estado_cargo = src.estado_cargo,
           tgt.es_estimado = src.es_estimado,
           tgt.servicio_referencia = src.servicio_referencia,
           tgt.observaciones = src.observaciones
    FROM dbo.msp_cargos_contrato_local tgt
    INNER JOIN src
        ON src.id_cargo_salida = tgt.id_cargo_salida_legacy
    WHERE src.id_contrato_local_final IS NOT NULL;

    ;WITH src AS (
        SELECT
            i.id_cargo_salida,
            i.id_tipo_cargo_salida,
            i.fecha_cargo,
            i.periodo_referencia,
            i.origen_cargo,
            i.id_documento_cobro,
            i.servicio_referencia,
            i.descripcion_cargo,
            i.monto_cargo,
            i.es_estimado,
            i.estado_cargo,
            i.observaciones,
            ISNULL(mg.total_aplicado, 0) AS monto_aplicado_garantia,
            COALESCE(
                ccr.id_contrato_local,
                (
                    SELECT TOP (1) cl2.id_contrato_local
                    FROM dbo.msp_contrato_locales cl2
                    WHERE cl2.id_contrato_arriendo = i.id_contrato_arriendo
                      AND cl2.id_local = i.id_local
                    ORDER BY
                        CASE WHEN cl2.estado_relacion = 1 THEN 0 ELSE 1 END,
                        cl2.fecha_inicio DESC,
                        cl2.id_contrato_local DESC
                )
            ) AS id_contrato_local_final
        FROM inserted i
        OUTER APPLY (
            SELECT SUM(mov.monto_movimiento) AS total_aplicado
            FROM dbo.msp_movimientos_garantia mov
            WHERE mov.id_cargo_salida = i.id_cargo_salida
              AND mov.id_tipo_movimiento_garantia = 4
        ) mg
        OUTER APPLY (
            SELECT TOP (1) cl.id_contrato_local
            FROM dbo.msp_contrato_locales cl
            WHERE cl.id_contrato_arriendo = i.id_contrato_arriendo
              AND cl.id_local = i.id_local
              AND i.fecha_cargo >= cl.fecha_inicio
              AND i.fecha_cargo <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
            ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC
        ) ccr
    )
    INSERT INTO dbo.msp_cargos_contrato_local (
        id_contrato_local,
        id_tipo_cargo_salida,
        fecha_cargo,
        periodo_referencia,
        origen_cargo,
        id_documento_cobro,
        id_pago,
        descripcion_cargo,
        monto_cargo,
        monto_aplicado_garantia,
        monto_pagado_directo,
        estado_cargo,
        es_estimado,
        requiere_regularizacion,
        servicio_referencia,
        observaciones,
        id_cargo_salida_legacy
    )
    SELECT
        src.id_contrato_local_final,
        src.id_tipo_cargo_salida,
        src.fecha_cargo,
        src.periodo_referencia,
        src.origen_cargo,
        src.id_documento_cobro,
        NULL AS id_pago,
        src.descripcion_cargo,
        src.monto_cargo,
        CAST(CASE WHEN src.monto_aplicado_garantia > src.monto_cargo THEN src.monto_cargo ELSE src.monto_aplicado_garantia END AS DECIMAL(18,2)) AS monto_aplicado_garantia,
        CAST(0 AS DECIMAL(18,2)) AS monto_pagado_directo,
        src.estado_cargo,
        src.es_estimado,
        CAST(0 AS BIT) AS requiere_regularizacion,
        src.servicio_referencia,
        src.observaciones,
        src.id_cargo_salida
    FROM src
    WHERE src.id_contrato_local_final IS NOT NULL
      AND NOT EXISTS (
          SELECT 1
          FROM dbo.msp_cargos_contrato_local t WITH (UPDLOCK, HOLDLOCK)
          WHERE t.id_cargo_salida_legacy = src.id_cargo_salida
      );
END;
GO

/* =========================================================================
   6. VISTA NUEVA DE CARGOS (CANONICA DE FASE 3)
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_cargos_contrato_local
AS
SELECT
    ccl.id_cargo_contrato_local,
    ccl.id_contrato_local,
    cl.id_contrato_arriendo,
    cl.id_local,
    ccl.id_tipo_cargo_salida,
    ccl.fecha_cargo,
    ccl.periodo_referencia,
    ccl.origen_cargo,
    ccl.id_documento_cobro,
    ccl.id_pago,
    ccl.descripcion_cargo,
    ccl.monto_cargo,
    ccl.monto_aplicado_garantia,
    ccl.monto_pagado_directo,
    ccl.estado_cargo,
    ccl.es_estimado,
    ccl.requiere_regularizacion,
    ccl.servicio_referencia,
    ccl.observaciones,
    ccl.id_cargo_salida_legacy,
    ccl.fecha_registro
FROM dbo.msp_cargos_contrato_local ccl
INNER JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local = ccl.id_contrato_local;
GO

/* =========================================================================
   7. AJUSTE DE VISTA DEUDA/GARANTIA
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_deuda_garantia_local
AS
SELECT
    gr.id_contrato_local,
    gr.id_contrato_arriendo,
    gr.id_tienda,
    gr.id_arrendatario,
    gr.id_local,
    gr.id_garantia,
    gr.monto_inicial,
    gr.saldo_disponible,
    gr.saldo_reservado,
    gr.saldo_aplicado,
    CAST(ISNULL(cg.total_cargos, 0) AS DECIMAL(18,2)) AS total_cargos,
    CAST(ISNULL(cg.total_pendiente, 0) AS DECIMAL(18,2)) AS total_cargos_pendientes,
    CAST(ISNULL(cg.total_reservado, 0) AS DECIMAL(18,2)) AS total_cargos_reservados,
    CAST(ISNULL(cg.total_aplicado, 0) AS DECIMAL(18,2)) AS total_cargos_aplicados
FROM dbo.msp_vw_garantias_resumen gr
OUTER APPLY (
    SELECT
        SUM(x.monto_cargo) AS total_cargos,
        SUM(CASE WHEN x.estado_cargo = 1 THEN x.monto_cargo ELSE 0 END) AS total_pendiente,
        SUM(CASE WHEN x.estado_cargo = 2 THEN x.monto_cargo ELSE 0 END) AS total_reservado,
        SUM(CASE WHEN x.estado_cargo = 3 THEN x.monto_cargo ELSE 0 END) AS total_aplicado
    FROM (
        SELECT ccl.monto_cargo, ccl.estado_cargo
        FROM dbo.msp_cargos_contrato_local ccl
        WHERE gr.id_contrato_local IS NOT NULL
          AND ccl.id_contrato_local = gr.id_contrato_local
          AND ccl.estado_cargo <> 5

        UNION ALL

        SELECT cs.monto_cargo, cs.estado_cargo
        FROM dbo.msp_cargos_salida cs
        WHERE gr.id_contrato_local IS NULL
          AND cs.id_contrato_arriendo = gr.id_contrato_arriendo
          AND cs.id_local = gr.id_local
          AND cs.estado_cargo <> 5
    ) x
) cg;
GO

/* =========================================================================
   8. CONTROL DE COBERTURA DE MIGRACION
   ========================================================================= */

DECLARE @total_legacy INT = (
    SELECT COUNT(1) FROM dbo.msp_cargos_salida
);

DECLARE @migrados INT = (
    SELECT COUNT(1)
    FROM dbo.msp_cargos_contrato_local
    WHERE id_cargo_salida_legacy IS NOT NULL
);

PRINT CONCAT('Fase 3 aplicada. Cargos legacy: ', @total_legacy, ' | Migrados al nuevo modelo: ', @migrados);
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: msp_fase4_sp_negocio.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - FASE 4: STORED PROCEDURES DE NEGOCIO
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Mueve reglas criticas desde PHP a SQL
 - Compatible con modelo nuevo (contrato_local) y legado transitorio
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. SP: CREAR CARGO MANUAL
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_cargo_crear_manual
    @id_tienda INT,
    @cdo_local NVARCHAR(20),
    @id_tipo_cargo_salida INT,
    @fecha_cargo DATE = NULL,
    @periodo_referencia DATE = NULL,
    @servicio_referencia NVARCHAR(30) = NULL,
    @descripcion_cargo NVARCHAR(500),
    @monto_cargo DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @crear_legacy BIT = 1,
    @id_cargo_contrato_local INT OUTPUT,
    @id_cargo_salida_legacy INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @id_tienda IS NULL OR @id_tienda <= 0
        THROW 50601, 'La tienda indicada no es valida.', 1;

    IF @id_tipo_cargo_salida IS NULL OR @id_tipo_cargo_salida <= 0
        THROW 50602, 'El tipo de cargo no es valido.', 1;

    SET @cdo_local = UPPER(LTRIM(RTRIM(ISNULL(@cdo_local, N''))));
    IF @cdo_local = N'' OR LEN(@cdo_local) > 20
        THROW 50603, 'El codigo local no es valido.', 1;

    SET @descripcion_cargo = LTRIM(RTRIM(ISNULL(@descripcion_cargo, N'')));
    IF @descripcion_cargo = N'' OR LEN(@descripcion_cargo) > 500
        THROW 50604, 'La descripcion del cargo es obligatoria y no puede superar 500 caracteres.', 1;

    SET @servicio_referencia = NULLIF(LTRIM(RTRIM(ISNULL(@servicio_referencia, N''))), N'');
    IF @servicio_referencia IS NOT NULL AND LEN(@servicio_referencia) > 30
        THROW 50605, 'El servicio de referencia no puede superar 30 caracteres.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50606, 'Las observaciones no pueden superar 500 caracteres.', 1;

    IF @monto_cargo IS NULL OR @monto_cargo <= 0
        THROW 50607, 'El monto del cargo no es valido.', 1;

    IF @fecha_cargo IS NULL
        SET @fecha_cargo = CONVERT(date, SYSDATETIME());

    IF @periodo_referencia IS NOT NULL AND DAY(@periodo_referencia) <> 1
        THROW 50608, 'El periodo de referencia debe ser el primer dia del mes.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @id_contrato_local INT,
        @requiere_documento BIT,
        @codigo_tipo_cargo NVARCHAR(50),
        @origen_cargo TINYINT,
        @es_estimado BIT;

    BEGIN TRANSACTION;

    SELECT TOP (1)
        @id_contrato_arriendo = c.id_contrato_arriendo
    FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.id_tienda = @id_tienda
      AND c.estado_contrato IN (1,2,3)
    ORDER BY c.id_contrato_arriendo DESC;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 50609, 'La tienda no tiene contrato activo para registrar cargos.', 1;

    SELECT TOP (1)
        @id_local = l.id_local
    FROM dbo.msp_locales l
    WHERE UPPER(LTRIM(RTRIM(l.cdo_local))) = @cdo_local;

    IF ISNULL(@id_local, 0) <= 0
        THROW 50610, 'El local seleccionado no existe.', 1;

    SELECT TOP (1)
        @id_contrato_local = cl.id_contrato_local
    FROM dbo.msp_contrato_locales cl WITH (UPDLOCK, HOLDLOCK)
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND cl.id_local = @id_local
      AND @fecha_cargo >= cl.fecha_inicio
      AND @fecha_cargo <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
    ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC;

    IF ISNULL(@id_contrato_local, 0) <= 0
    BEGIN
        SELECT TOP (1)
            @id_contrato_local = cl.id_contrato_local
        FROM dbo.msp_contrato_locales cl WITH (UPDLOCK, HOLDLOCK)
        WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
          AND cl.id_local = @id_local
        ORDER BY
            CASE WHEN cl.estado_relacion = 1 THEN 0 ELSE 1 END,
            cl.fecha_inicio DESC,
            cl.id_contrato_local DESC;
    END;

    IF ISNULL(@id_contrato_local, 0) <= 0
        THROW 50611, 'No existe una relacion contrato-local valida para registrar el cargo.', 1;

    SELECT
        @codigo_tipo_cargo = t.codigo_tipo_cargo,
        @requiere_documento = t.requiere_documento
    FROM dbo.msp_tipos_cargo_salida t
    WHERE t.id_tipo_cargo_salida = @id_tipo_cargo_salida
      AND t.activo = 1;

    IF @codigo_tipo_cargo IS NULL
        THROW 50612, 'El tipo de cargo seleccionado no esta disponible.', 1;

    IF ISNULL(@requiere_documento, 0) = 1
        THROW 50613, 'Este tipo de cargo requiere documento asociado.', 1;

    SET @es_estimado = 0;

    SET @origen_cargo = CASE
        WHEN @codigo_tipo_cargo IN (N'MULTA', N'DANOS') THEN 3
        ELSE 4
    END;

    INSERT INTO dbo.msp_cargos_contrato_local (
        id_contrato_local,
        id_tipo_cargo_salida,
        fecha_cargo,
        periodo_referencia,
        origen_cargo,
        id_documento_cobro,
        id_pago,
        descripcion_cargo,
        monto_cargo,
        monto_aplicado_garantia,
        monto_pagado_directo,
        estado_cargo,
        es_estimado,
        requiere_regularizacion,
        servicio_referencia,
        observaciones,
        id_cargo_salida_legacy
    )
    VALUES (
        @id_contrato_local,
        @id_tipo_cargo_salida,
        @fecha_cargo,
        @periodo_referencia,
        @origen_cargo,
        NULL,
        NULL,
        @descripcion_cargo,
        @monto_cargo,
        0,
        0,
        1,
        @es_estimado,
        0,
        @servicio_referencia,
        @observaciones,
        NULL
    );

    SET @id_cargo_contrato_local = CONVERT(INT, SCOPE_IDENTITY());
    SET @id_cargo_salida_legacy = NULL;

    IF @crear_legacy = 1 AND OBJECT_ID('dbo.msp_cargos_salida', 'U') IS NOT NULL
    BEGIN
        BEGIN TRY
            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=1;

            INSERT INTO dbo.msp_cargos_salida (
            id_contrato_arriendo,
            id_local,
            id_tipo_cargo_salida,
            fecha_cargo,
            origen_cargo,
            id_documento_cobro,
            periodo_referencia,
            servicio_referencia,
            descripcion_cargo,
            monto_cargo,
            es_estimado,
            estado_cargo,
            observaciones
        )
        VALUES (
            @id_contrato_arriendo,
            @id_local,
            @id_tipo_cargo_salida,
            @fecha_cargo,
            @origen_cargo,
            NULL,
            @periodo_referencia,
            @servicio_referencia,
            @descripcion_cargo,
            @monto_cargo,
            @es_estimado,
            1,
            @observaciones
            );

            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=NULL;
        END TRY
        BEGIN CATCH
            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=NULL;
            THROW;
        END CATCH;

        SET @id_cargo_salida_legacy = CONVERT(INT, SCOPE_IDENTITY());

        UPDATE dbo.msp_cargos_contrato_local
        SET id_cargo_salida_legacy = @id_cargo_salida_legacy
        WHERE id_cargo_contrato_local = @id_cargo_contrato_local;
    END;

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   2. SP: OPERAR GARANTIA SOBRE CARGO
   Acciones:
   - RESERVAR
   - LIBERAR_RESERVA
   - APLICAR_DESDE_DISPONIBLE
   - APLICAR_DESDE_RESERVADO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_operar_cargo
    @accion NVARCHAR(40),
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @epsilon DECIMAL(18,6) = 0.00001;

    SET @accion = UPPER(LTRIM(RTRIM(ISNULL(@accion, N''))));
    IF @accion NOT IN (N'RESERVAR', N'LIBERAR_RESERVA', N'APLICAR_DESDE_DISPONIBLE', N'APLICAR_DESDE_RESERVADO')
        THROW 50701, 'La accion de garantia no es valida.', 1;

    IF ISNULL(@id_cargo_contrato_local, 0) <= 0 AND ISNULL(@id_cargo_salida, 0) <= 0
        THROW 50702, 'Debes indicar un cargo de referencia.', 1;

    IF @monto_movimiento IS NULL OR @monto_movimiento <= 0
        THROW 50703, 'El monto del movimiento no es valido.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50704, 'Las observaciones no pueden superar 500 caracteres.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @monto_cargo_total DECIMAL(18,2),
        @estado_cargo_actual TINYINT,
        @id_cargo_contrato_local_final INT,
        @id_cargo_salida_final INT,
        @saldo_disponible_garantia DECIMAL(18,2),
        @saldo_reservado_garantia DECIMAL(18,2),
        @total_reserva DECIMAL(18,2),
        @total_liberacion DECIMAL(18,2),
        @total_aplicado_disponible DECIMAL(18,2),
        @total_aplicado_reservado DECIMAL(18,2),
        @reserva_neta_cargo DECIMAL(18,2),
        @aplicado_total_cargo DECIMAL(18,2),
        @pendiente_aplicar_cargo DECIMAL(18,2),
        @maximo_permitido DECIMAL(18,2),
        @id_tipo_reserva INT,
        @id_tipo_liberacion INT,
        @id_tipo_aplicacion INT,
        @id_tipo_movimiento INT,
        @fondo_origen CHAR(1),
        @reserva_neta_nueva DECIMAL(18,2),
        @aplicado_total_nuevo DECIMAL(18,2);

    SET @id_movimiento_garantia = NULL;
    SET @estado_cargo_nuevo = NULL;

    BEGIN TRANSACTION;

    IF ISNULL(@id_cargo_contrato_local, 0) > 0
    BEGIN
        SELECT
            @id_cargo_contrato_local_final = ccl.id_cargo_contrato_local,
            @id_cargo_salida_final = ccl.id_cargo_salida_legacy,
            @id_contrato_arriendo = cl.id_contrato_arriendo,
            @id_local = cl.id_local,
            @monto_cargo_total = ccl.monto_cargo,
            @estado_cargo_actual = ccl.estado_cargo
        FROM dbo.msp_cargos_contrato_local ccl WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = ccl.id_contrato_local
        WHERE ccl.id_cargo_contrato_local = @id_cargo_contrato_local;
    END
    ELSE
    BEGIN
        SELECT
            @id_cargo_contrato_local_final = ccl.id_cargo_contrato_local,
            @id_cargo_salida_final = ccl.id_cargo_salida_legacy,
            @id_contrato_arriendo = cl.id_contrato_arriendo,
            @id_local = cl.id_local,
            @monto_cargo_total = ccl.monto_cargo,
            @estado_cargo_actual = ccl.estado_cargo
        FROM dbo.msp_cargos_contrato_local ccl WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = ccl.id_contrato_local
        WHERE ccl.id_cargo_salida_legacy = @id_cargo_salida;

        IF ISNULL(@id_cargo_contrato_local_final, 0) <= 0
        BEGIN
            SELECT
                @id_cargo_salida_final = cs.id_cargo_salida,
                @id_contrato_arriendo = cs.id_contrato_arriendo,
                @id_local = cs.id_local,
                @monto_cargo_total = cs.monto_cargo,
                @estado_cargo_actual = cs.estado_cargo
            FROM dbo.msp_cargos_salida cs WITH (UPDLOCK, HOLDLOCK)
            WHERE cs.id_cargo_salida = @id_cargo_salida;
        END
    END;

    IF ISNULL(@monto_cargo_total, 0) <= 0 OR ISNULL(@id_contrato_arriendo, 0) <= 0 OR ISNULL(@id_local, 0) <= 0
        THROW 50705, 'No fue posible validar el cargo para operar garantia.', 1;

    IF ISNULL(@estado_cargo_actual, 0) NOT IN (1,2,3)
        THROW 50706, 'El estado del cargo no permite movimientos de garantia.', 1;

    IF ISNULL(@id_garantia, 0) <= 0
    BEGIN
        SELECT TOP (1)
            @id_garantia = g.id_garantia
        FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
        WHERE (
            (ISNULL(@id_cargo_contrato_local_final, 0) > 0 AND g.id_contrato_local = (
                SELECT ccl2.id_contrato_local
                FROM dbo.msp_cargos_contrato_local ccl2
                WHERE ccl2.id_cargo_contrato_local = @id_cargo_contrato_local_final
            ))
            OR
            (ISNULL(@id_cargo_contrato_local_final, 0) <= 0 AND g.id_contrato_arriendo = @id_contrato_arriendo AND g.id_local = @id_local)
        )
          AND g.estado_garantia <> 6
        ORDER BY g.id_garantia DESC;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
            WHERE g.id_garantia = @id_garantia
              AND g.estado_garantia <> 6
              AND g.id_contrato_arriendo = @id_contrato_arriendo
              AND g.id_local = @id_local
        )
            THROW 50707, 'La garantia indicada no coincide con el cargo seleccionado.', 1;
    END;

    IF ISNULL(@id_garantia, 0) <= 0
        THROW 50708, 'No existe garantia activa para el local del cargo.', 1;

    SELECT
        @saldo_disponible_garantia = gr.saldo_disponible,
        @saldo_reservado_garantia = gr.saldo_reservado
    FROM dbo.msp_vw_garantias_resumen gr
    WHERE gr.id_garantia = @id_garantia;

    IF @saldo_disponible_garantia IS NULL OR @saldo_reservado_garantia IS NULL
        THROW 50709, 'No fue posible leer el saldo de la garantia.', 1;

    SELECT
        @total_reserva = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END),
        @total_liberacion = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END),
        @total_aplicado_disponible = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END),
        @total_aplicado_reservado = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END)
    FROM dbo.msp_movimientos_garantia mg WITH (UPDLOCK, HOLDLOCK)
    WHERE mg.id_garantia = @id_garantia
      AND (
            (ISNULL(@id_cargo_contrato_local_final, 0) > 0 AND mg.id_cargo_contrato_local = @id_cargo_contrato_local_final)
            OR
            (ISNULL(@id_cargo_salida_final, 0) > 0 AND mg.id_cargo_salida = @id_cargo_salida_final)
          );

    SET @total_reserva = ISNULL(@total_reserva, 0);
    SET @total_liberacion = ISNULL(@total_liberacion, 0);
    SET @total_aplicado_disponible = ISNULL(@total_aplicado_disponible, 0);
    SET @total_aplicado_reservado = ISNULL(@total_aplicado_reservado, 0);

    SET @reserva_neta_cargo = @total_reserva - @total_liberacion - @total_aplicado_reservado;
    SET @aplicado_total_cargo = @total_aplicado_disponible + @total_aplicado_reservado;
    SET @pendiente_aplicar_cargo = CASE WHEN @monto_cargo_total - @aplicado_total_cargo > 0 THEN @monto_cargo_total - @aplicado_total_cargo ELSE 0 END;

    SELECT
        @id_tipo_reserva = MAX(CASE WHEN codigo_movimiento = N'RESERVA' THEN id_tipo_movimiento_garantia END),
        @id_tipo_liberacion = MAX(CASE WHEN codigo_movimiento = N'LIBERACION_RESERVA' THEN id_tipo_movimiento_garantia END),
        @id_tipo_aplicacion = MAX(CASE WHEN codigo_movimiento = N'APLICACION_CARGO' THEN id_tipo_movimiento_garantia END)
    FROM dbo.msp_tipos_movimiento_garantia
    WHERE activo = 1
      AND codigo_movimiento IN (N'RESERVA', N'LIBERACION_RESERVA', N'APLICACION_CARGO');

    IF @id_tipo_reserva IS NULL OR @id_tipo_liberacion IS NULL OR @id_tipo_aplicacion IS NULL
        THROW 50710, 'Catalogo incompleto de tipos de movimiento de garantia.', 1;

    SET @id_tipo_movimiento = NULL;
    SET @fondo_origen = NULL;

    IF @accion = N'RESERVAR'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_disponible_garantia),
                (CASE WHEN @pendiente_aplicar_cargo - CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END > 0
                      THEN @pendiente_aplicar_cargo - CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END
                      ELSE 0 END)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50711, 'No hay saldo disponible para reservar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50712, 'El monto supera el maximo reservable para este cargo.', 1;

        SET @id_tipo_movimiento = @id_tipo_reserva;
    END
    ELSE IF @accion = N'LIBERAR_RESERVA'
    BEGIN
        SET @maximo_permitido = CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END;

        IF @maximo_permitido <= @epsilon
            THROW 50713, 'No hay reserva neta para liberar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50714, 'El monto supera la reserva neta del cargo.', 1;

        SET @id_tipo_movimiento = @id_tipo_liberacion;
    END
    ELSE IF @accion = N'APLICAR_DESDE_DISPONIBLE'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_disponible_garantia),
                (@pendiente_aplicar_cargo)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50715, 'No hay saldo disponible para aplicar a este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50716, 'El monto supera el maximo aplicable desde saldo disponible.', 1;

        SET @id_tipo_movimiento = @id_tipo_aplicacion;
        SET @fondo_origen = 'D';
    END
    ELSE IF @accion = N'APLICAR_DESDE_RESERVADO'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_reservado_garantia),
                (CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END),
                (@pendiente_aplicar_cargo)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50717, 'No hay reserva disponible para aplicar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50718, 'El monto supera el maximo aplicable desde reserva.', 1;

        SET @id_tipo_movimiento = @id_tipo_aplicacion;
        SET @fondo_origen = 'R';
    END;

    INSERT INTO dbo.msp_movimientos_garantia (
        id_garantia,
        id_tipo_movimiento_garantia,
        fondo_origen,
        monto_movimiento,
        id_cargo_salida,
        id_cargo_contrato_local,
        id_pago,
        observaciones
    )
    VALUES (
        @id_garantia,
        @id_tipo_movimiento,
        @fondo_origen,
        @monto_movimiento,
        @id_cargo_salida_final,
        @id_cargo_contrato_local_final,
        @id_pago,
        @observaciones
    );

    SET @id_movimiento_garantia = CONVERT(INT, SCOPE_IDENTITY());

    SET @reserva_neta_nueva = @reserva_neta_cargo;
    SET @aplicado_total_nuevo = @aplicado_total_cargo;

    IF @accion = N'RESERVAR'
        SET @reserva_neta_nueva = @reserva_neta_nueva + @monto_movimiento;
    ELSE IF @accion = N'LIBERAR_RESERVA'
        SET @reserva_neta_nueva = @reserva_neta_nueva - @monto_movimiento;
    ELSE IF @accion = N'APLICAR_DESDE_DISPONIBLE'
        SET @aplicado_total_nuevo = @aplicado_total_nuevo + @monto_movimiento;
    ELSE IF @accion = N'APLICAR_DESDE_RESERVADO'
    BEGIN
        SET @aplicado_total_nuevo = @aplicado_total_nuevo + @monto_movimiento;
        SET @reserva_neta_nueva = @reserva_neta_nueva - @monto_movimiento;
    END;

    SET @estado_cargo_nuevo = 1;
    IF @aplicado_total_nuevo + @epsilon >= @monto_cargo_total
        SET @estado_cargo_nuevo = 3;
    ELSE IF @reserva_neta_nueva > @epsilon
        SET @estado_cargo_nuevo = 2;

    IF ISNULL(@id_cargo_contrato_local_final, 0) > 0
    BEGIN
        UPDATE dbo.msp_cargos_contrato_local
        SET estado_cargo = @estado_cargo_nuevo,
            monto_aplicado_garantia = CASE WHEN @aplicado_total_nuevo > monto_cargo THEN monto_cargo ELSE @aplicado_total_nuevo END
        WHERE id_cargo_contrato_local = @id_cargo_contrato_local_final;
    END;

    IF ISNULL(@id_cargo_salida_final, 0) > 0 AND OBJECT_ID('dbo.msp_cargos_salida', 'U') IS NOT NULL
    BEGIN
        UPDATE dbo.msp_cargos_salida
        SET estado_cargo = @estado_cargo_nuevo
        WHERE id_cargo_salida = @id_cargo_salida_final;
    END;

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   3. WRAPPERS DE GARANTIA
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_reservar
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    EXEC dbo.msp_garantia_operar_cargo
        @accion = N'RESERVAR',
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = NULL,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_liberar_reserva
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    EXEC dbo.msp_garantia_operar_cargo
        @accion = N'LIBERAR_RESERVA',
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = NULL,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_aplicar
    @origen_fondo CHAR(1),
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    DECLARE @accion NVARCHAR(40);

    SET @origen_fondo = UPPER(ISNULL(@origen_fondo, ''));
    IF @origen_fondo = 'D'
        SET @accion = N'APLICAR_DESDE_DISPONIBLE';
    ELSE IF @origen_fondo = 'R'
        SET @accion = N'APLICAR_DESDE_RESERVADO';
    ELSE
        THROW 50719, 'El origen de fondo para aplicar debe ser D o R.', 1;

    EXEC dbo.msp_garantia_operar_cargo
        @accion = @accion,
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = @id_pago,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

/* =========================================================================
   4. SP: DEVOLVER GARANTIA
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_devolver
    @id_garantia INT,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @epsilon DECIMAL(18,6) = 0.00001;

    IF ISNULL(@id_garantia, 0) <= 0
        THROW 50801, 'La garantia indicada no es valida.', 1;

    IF @monto_movimiento IS NULL OR @monto_movimiento <= 0
        THROW 50802, 'El monto de devolucion no es valido.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50803, 'Las observaciones no pueden superar 500 caracteres.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @id_contrato_local INT,
        @estado_garantia TINYINT,
        @saldo_disponible DECIMAL(18,2),
        @saldo_reservado DECIMAL(18,2),
        @id_tipo_devolucion INT,
        @pendientes_nuevo INT,
        @pendientes_legacy INT;

    BEGIN TRANSACTION;

    SELECT
        @id_contrato_arriendo = g.id_contrato_arriendo,
        @id_local = g.id_local,
        @id_contrato_local = g.id_contrato_local,
        @estado_garantia = g.estado_garantia
    FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
    WHERE g.id_garantia = @id_garantia;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0 OR ISNULL(@id_local, 0) <= 0
        THROW 50804, 'La garantia ya no existe.', 1;

    IF @estado_garantia = 6
        THROW 50805, 'La garantia esta anulada y no permite devoluciones.', 1;

    SELECT
        @saldo_disponible = gr.saldo_disponible,
        @saldo_reservado = gr.saldo_reservado
    FROM dbo.msp_vw_garantias_resumen gr
    WHERE gr.id_garantia = @id_garantia;

    IF @saldo_disponible IS NULL OR @saldo_reservado IS NULL
        THROW 50806, 'No fue posible leer el saldo de la garantia.', 1;

    IF @saldo_reservado > @epsilon
        THROW 50807, 'No se puede devolver mientras exista saldo reservado en la garantia.', 1;

    IF @saldo_disponible <= @epsilon
        THROW 50808, 'La garantia no tiene saldo disponible para devolver.', 1;

    IF @monto_movimiento - @saldo_disponible > @epsilon
        THROW 50809, 'El monto de devolucion supera el saldo disponible de la garantia.', 1;

    SELECT
        @pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    WHERE (
            (@id_contrato_local IS NOT NULL AND ccl.id_contrato_local = @id_contrato_local)
            OR
            (@id_contrato_local IS NULL AND ccl.id_contrato_local IN (
                SELECT cl.id_contrato_local
                FROM dbo.msp_contrato_locales cl
                WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
                  AND cl.id_local = @id_local
            ))
          )
      AND ccl.estado_cargo IN (1,2);

    SET @pendientes_nuevo = ISNULL(@pendientes_nuevo, 0);

    SELECT
        @pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.id_local = @id_local
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SET @pendientes_legacy = ISNULL(@pendientes_legacy, 0);

    IF @pendientes_nuevo + @pendientes_legacy > 0
        THROW 50810, 'No se puede devolver garantia: el local tiene cargos pendientes o reservados.', 1;

    SELECT TOP (1)
        @id_tipo_devolucion = t.id_tipo_movimiento_garantia
    FROM dbo.msp_tipos_movimiento_garantia t
    WHERE t.activo = 1
      AND t.codigo_movimiento = N'DEVOLUCION';

    IF ISNULL(@id_tipo_devolucion, 0) <= 0
        THROW 50811, 'No existe el tipo de movimiento DEVOLUCION en catalogo.', 1;

    INSERT INTO dbo.msp_movimientos_garantia (
        id_garantia,
        id_tipo_movimiento_garantia,
        fondo_origen,
        monto_movimiento,
        id_cargo_salida,
        id_cargo_contrato_local,
        id_documento_cobro,
        id_pago,
        observaciones
    )
    VALUES (
        @id_garantia,
        @id_tipo_devolucion,
        NULL,
        @monto_movimiento,
        NULL,
        NULL,
        NULL,
        @id_pago,
        @observaciones
    );

    SET @id_movimiento_garantia = CONVERT(INT, SCOPE_IDENTITY());

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   5. SP: PREPARAR CIERRE FINANCIERO (CHECKS)
   Regla:
   - Solo aplica para contratos en estado 3 (En cierre financiero).
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_contrato_preparar_cierre
    @id_contrato_arriendo INT
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 50901, 'El contrato indicado no es valido.', 1;

    DECLARE
        @existe_contrato BIT,
        @estado_contrato TINYINT,
        @cargos_pendientes_nuevo INT,
        @cargos_pendientes_legacy INT,
        @garantias_reservadas INT,
        @puede_cerrar BIT;

    SELECT
        @existe_contrato = 1,
        @estado_contrato = c.estado_contrato
    FROM dbo.msp_contratos_arriendo c
    WHERE c.id_contrato_arriendo = @id_contrato_arriendo;

    IF ISNULL(@existe_contrato, 0) = 0
        THROW 50902, 'El contrato ya no existe.', 1;

    SELECT
        @cargos_pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_local = ccl.id_contrato_local
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND ccl.estado_cargo IN (1,2);

    SELECT
        @cargos_pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SELECT
        @garantias_reservadas = COUNT(1)
    FROM dbo.msp_vw_garantias_resumen gr
    INNER JOIN dbo.msp_garantias g
        ON g.id_garantia = gr.id_garantia
    WHERE g.id_contrato_arriendo = @id_contrato_arriendo
      AND g.estado_garantia <> 6
      AND gr.saldo_reservado > 0;

    SET @cargos_pendientes_nuevo = ISNULL(@cargos_pendientes_nuevo, 0);
    SET @cargos_pendientes_legacy = ISNULL(@cargos_pendientes_legacy, 0);
    SET @garantias_reservadas = ISNULL(@garantias_reservadas, 0);

    SET @puede_cerrar = CASE
        WHEN @estado_contrato <> 3 THEN 0
        WHEN @cargos_pendientes_nuevo + @cargos_pendientes_legacy > 0 THEN 0
        WHEN @garantias_reservadas > 0 THEN 0
        ELSE 1
    END;

    SELECT
        @id_contrato_arriendo AS id_contrato_arriendo,
        @estado_contrato AS estado_contrato,
        @cargos_pendientes_nuevo AS cargos_pendientes_nuevo,
        @cargos_pendientes_legacy AS cargos_pendientes_legacy,
        @garantias_reservadas AS garantias_reservadas,
        @puede_cerrar AS puede_cerrar;
END;
GO

/* =========================================================================
   6. SP: CERRAR CONTRATO (CIERRE FINANCIERO)
   Regla:
   - Cierra a estado 4 solo desde estado 3.
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_contrato_cerrar
    @id_contrato_arriendo INT,
    @id_usuario INT,
    @motivo_cierre NVARCHAR(500),
    @forzar_cierre BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 51001, 'El contrato indicado no es valido.', 1;

    IF ISNULL(@id_usuario, 0) <= 0
        THROW 51002, 'No fue posible identificar al usuario para registrar bitacora.', 1;

    SET @motivo_cierre = LTRIM(RTRIM(ISNULL(@motivo_cierre, N'')));
    IF @motivo_cierre = N''
        THROW 51003, 'Debes indicar un motivo para cerrar el contrato.', 1;

    IF LEN(@motivo_cierre) > 500
        THROW 51004, 'El motivo de cierre no puede superar 500 caracteres.', 1;

    DECLARE
        @estado_contrato_anterior TINYINT,
        @cargos_pendientes_nuevo INT,
        @cargos_pendientes_legacy INT,
        @garantias_reservadas INT,
        @puede_cerrar BIT,
        @detalle_evento NVARCHAR(MAX);

    BEGIN TRANSACTION;

    SELECT
        @estado_contrato_anterior = c.estado_contrato
    FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.id_contrato_arriendo = @id_contrato_arriendo;

    IF @estado_contrato_anterior IS NULL
        THROW 51005, 'El contrato ya no existe.', 1;

    IF @estado_contrato_anterior = 4
        THROW 51006, 'El contrato ya esta cerrado.', 1;

    IF @estado_contrato_anterior = 5
        THROW 51007, 'El contrato esta anulado y no se puede cerrar.', 1;

    IF @estado_contrato_anterior <> 3
        THROW 51008, 'El contrato debe estar en estado 3 (En cierre financiero) para cerrar.', 1;

    SELECT
        @cargos_pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_local = ccl.id_contrato_local
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND ccl.estado_cargo IN (1,2);

    SELECT
        @cargos_pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SELECT
        @garantias_reservadas = COUNT(1)
    FROM dbo.msp_vw_garantias_resumen gr
    INNER JOIN dbo.msp_garantias g
        ON g.id_garantia = gr.id_garantia
    WHERE g.id_contrato_arriendo = @id_contrato_arriendo
      AND g.estado_garantia <> 6
      AND gr.saldo_reservado > 0;

    SET @cargos_pendientes_nuevo = ISNULL(@cargos_pendientes_nuevo, 0);
    SET @cargos_pendientes_legacy = ISNULL(@cargos_pendientes_legacy, 0);
    SET @garantias_reservadas = ISNULL(@garantias_reservadas, 0);

    SET @puede_cerrar = CASE
        WHEN @cargos_pendientes_nuevo + @cargos_pendientes_legacy > 0 THEN 0
        WHEN @garantias_reservadas > 0 THEN 0
        ELSE 1
    END;

    IF @forzar_cierre = 0 AND @puede_cerrar = 0
        THROW 51009, 'No se puede cerrar el contrato: existen cargos pendientes/reservados o garantia reservada.', 1;

    UPDATE dbo.msp_contratos_arriendo
    SET estado_contrato = 4
    WHERE id_contrato_arriendo = @id_contrato_arriendo
      AND estado_contrato = 3;

    IF @@ROWCOUNT <= 0
        THROW 51010, 'No fue posible cerrar el contrato. Intenta nuevamente.', 1;

    INSERT INTO dbo.msp_bitacora_cierre_contrato (
        id_contrato_arriendo,
        id_usuario,
        estado_contrato_anterior,
        estado_contrato_nuevo,
        motivo_cierre
    )
    VALUES (
        @id_contrato_arriendo,
        @id_usuario,
        @estado_contrato_anterior,
        4,
        @motivo_cierre
    );

    IF OBJECT_ID('dbo.msp_historial_contrato', 'U') IS NOT NULL
    BEGIN
        SET @detalle_evento = (
            SELECT
                N'sp' AS origen,
                @estado_contrato_anterior AS estado_anterior,
                4 AS estado_nuevo,
                @forzar_cierre AS forzado,
                @cargos_pendientes_nuevo AS cargos_pendientes_nuevo,
                @cargos_pendientes_legacy AS cargos_pendientes_legacy,
                @garantias_reservadas AS garantias_reservadas
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
        );

        INSERT INTO dbo.msp_historial_contrato (
            id_contrato_arriendo,
            tipo_evento,
            id_usuario,
            detalle_evento,
            motivo_evento
        )
        VALUES (
            @id_contrato_arriendo,
            N'CIERRE',
            @id_usuario,
            @detalle_evento,
            @motivo_cierre
        );
    END;

    COMMIT TRANSACTION;
END;
GO

PRINT 'Fase 4 aplicada: SPs de negocio creados/actualizados.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_operacion_mensual_sp.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH OPERACION MENSUAL A SPs
 - Extrae lógica crítica de operacion_mensual.php a procedimientos SQL.
 - Incluye:
   * dbo.msp_generar_cobros_periodo
   * dbo.msp_borrar_generacion_periodo
===========================================================================
*/

SET NOCOUNT ON;
GO

CREATE OR ALTER PROCEDURE dbo.msp_generar_cobros_periodo
    @id_cierre INT,
    @reemplazar BIT = 0,
    @servicios_csv NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @out INT = 0;

    IF ISNULL(@id_cierre, 0) <= 0
        THROW 50032, 'El cierre mensual indicado no existe.', 1;

    DECLARE @servicios TABLE (
        codigo_servicio NVARCHAR(20) NOT NULL PRIMARY KEY
    );

    INSERT INTO @servicios (codigo_servicio)
    SELECT DISTINCT UPPER(LTRIM(RTRIM(value)))
    FROM STRING_SPLIT(ISNULL(@servicios_csv, N''), N',')
    WHERE UPPER(LTRIM(RTRIM(value))) IN (N'AGUA', N'LUZ', N'GAS');

    IF NOT EXISTS (SELECT 1 FROM @servicios)
        THROW 50037, 'Debes seleccionar al menos un servicio para generar cobros.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT
            @periodo_facturacion = c.periodo_facturacion,
            @estado_cierre = c.estado_cierre
        FROM dbo.msp_cierre_mensual c
        WHERE c.id_cierre_mensual = @id_cierre;

        IF @periodo_facturacion IS NULL
            THROW 50032, 'El cierre mensual indicado no existe.', 1;

        IF @estado_cierre = 4
            THROW 50033, 'No se pueden generar cobros sobre un cierre mensual anulado.', 1;

        IF @estado_cierre = 3
            THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;

        IF @estado_cierre <> 1
            THROW 50039, 'Solo se pueden generar cobros en período Borrador.', 1;

        -- Flujo incremental por servicios:
        -- no bloquear generación de cobros por existencia de documentos del período.
        -- el recálculo selectivo lo controla @reemplazar y @servicios_csv.

        IF EXISTS (
            SELECT 1
            FROM dbo.msp_tipos_servicio ts
            INNER JOIN @servicios s
                ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            LEFT JOIN dbo.msp_procesos_cobro_servicio p
                ON p.id_cierre_mensual = @id_cierre
               AND p.id_tipo_servicio = ts.id_tipo_servicio
            WHERE p.id_proceso_cobro IS NULL OR p.estado_proceso = 4
        )
        BEGIN
            THROW 50036, 'Uno o mas servicios seleccionados no tienen proceso activo para el periodo.', 1;
        END;

        IF @reemplazar = 1
        BEGIN
            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
            INNER JOIN dbo.msp_tipos_servicio ts ON ts.id_tipo_servicio = p.id_tipo_servicio
            INNER JOIN @servicios s ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            WHERE p.id_cierre_mensual = @id_cierre;
        END;

        ;WITH base AS (
            SELECT
                lm.id_lectura,
                UPPER(ts.codigo_servicio) AS codigo_servicio,
                COALESCE(
                    lm.consumo_informado,
                    CASE WHEN lm.lectura_actual >= ISNULL(lm.lectura_anterior, 0)
                         THEN lm.lectura_actual - ISNULL(lm.lectura_anterior, 0)
                         ELSE 0 END
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
            INNER JOIN @servicios s
                ON s.codigo_servicio = UPPER(ts.codigo_servicio)
            LEFT JOIN dbo.msp_proceso_cobro_luz pl
                ON pl.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_gas pg
                ON pg.id_proceso_cobro = p.id_proceso_cobro
            LEFT JOIN dbo.msp_proceso_cobro_agua pa
                ON pa.id_proceso_cobro = p.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre
              AND p.estado_proceso <> 4
              AND NOT EXISTS (SELECT 1 FROM dbo.msp_cobros_servicios ex WHERE ex.id_lectura = lm.id_lectura)
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
                    WHEN b.codigo_servicio = N'LUZ' THEN b.consumo_cobrado * ISNULL(b.valor_kwh, 0)
                    WHEN b.codigo_servicio = N'GAS' THEN b.consumo_cobrado * ISNULL(b.factor, 0) * ISNULL(b.valor_litro, 0)
                    WHEN b.codigo_servicio = N'AGUA' THEN b.consumo_cobrado * (
                        (ISNULL(b.servicio_agua_potable, 0) + ISNULL(b.servicio_alcantarillado, 0) + ISNULL(b.tratamiento_aguas_servidas, 0))
                        / NULLIF(b.divisor, 0)
                    )
                    ELSE 0
                END,
                cargo_fijo = CASE WHEN b.codigo_servicio = N'AGUA' THEN ISNULL(b.cargo_fijo, 0) ELSE 0 END,
                parametros_snapshot = CASE
                    WHEN b.codigo_servicio = N'LUZ' THEN
                        CONCAT(N'{"servicio":"LUZ","valor_kwh":', CONVERT(NVARCHAR(50), ISNULL(b.valor_kwh, 0)), N'}')
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
                        CONCAT(N'LUZ: consumo(', FORMAT(b.consumo_cobrado, 'N4'), N') * valor_kwh(', FORMAT(ISNULL(b.valor_kwh, 0), 'N6'), N')')
                    WHEN b.codigo_servicio = N'GAS' THEN
                        CONCAT(
                            N'GAS: consumo(', FORMAT(b.consumo_cobrado, 'N4'),
                            N') * factor(', FORMAT(ISNULL(b.factor, 0), 'N6'),
                            N') * valor_litro(', FORMAT(ISNULL(b.valor_litro, 0), 'N6'), N')'
                        )
                    WHEN b.codigo_servicio = N'AGUA' THEN
                        CONCAT(N'AGUA: consumo(', FORMAT(b.consumo_cobrado, 'N4'), N') * ((SAP + SAL + TAS)/divisor) + cargo_fijo')
                    ELSE N'-'
                END
        ) calc;

        SET @out = @@ROWCOUNT;

        UPDATE p
        SET p.estado_proceso = CASE WHEN p.estado_proceso = 4 THEN 4 ELSE 2 END
        FROM dbo.msp_procesos_cobro_servicio p
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        INNER JOIN @servicios s
            ON s.codigo_servicio = UPPER(ts.codigo_servicio)
        WHERE p.id_cierre_mensual = @id_cierre;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @out AS cobros_generados;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_borrar_generacion_periodo
    @id_cierre INT,
    @del_docs BIT = 0,
    @del_cobros BIT = 0,
    @del_pagos BIT = 0,
    @del_cargos_salida_asociados BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @docs_borrados INT = 0;
    DECLARE @items_borrados INT = 0;
    DECLARE @cobros_borrados INT = 0;
    DECLARE @pagos_borrados INT = 0;
    DECLARE @cargos_salida_desvinculados INT = 0;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre;

    IF @periodo_facturacion IS NULL
        THROW 50091, 'El cierre mensual indicado no existe.', 1;

    IF @estado_cierre = 4
        THROW 50033, 'No se puede corregir un período anulado.', 1;

    IF @estado_cierre = 3
        THROW 50038, 'El período está cerrado. Reábrelo a Borrador para corregir.', 1;

    IF @estado_cierre <> 1
        THROW 50039, 'Solo se puede corregir un período en Borrador.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @del_pagos = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_pagos', N'U') IS NOT NULL
            BEGIN
                IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NOT NULL
                   AND EXISTS (
                        SELECT 1
                        FROM dbo.msp_movimientos_garantia mg
                        INNER JOIN dbo.msp_pagos p ON p.id_pago = mg.id_pago
                        INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                        WHERE dc.periodo_facturacion = @periodo_facturacion
                   )
                BEGIN
                    THROW 50096, 'No se pueden borrar pagos del periodo porque existen movimientos de garantia asociados.', 1;
                END;

                IF OBJECT_ID(N'dbo.msp_pagos_detalle_concepto', N'U') IS NOT NULL
                BEGIN
                    DELETE pdc
                    FROM dbo.msp_pagos_detalle_concepto pdc
                    INNER JOIN dbo.msp_pagos p ON p.id_pago = pdc.id_pago
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion;
                END;

                IF OBJECT_ID(N'dbo.msp_anular_pago_documento', N'P') IS NOT NULL
                BEGIN
                    DECLARE @id_pago_anular INT;
                    DECLARE @fecha_anulacion_pago DATE = CAST(SYSDATETIME() AS DATE);
                    DECLARE @motivo_anulacion NVARCHAR(500) =
                        N'Correccion operativa periodo '
                        + CAST(YEAR(@periodo_facturacion) AS NVARCHAR(4))
                        + N'-'
                        + RIGHT(N'0' + CAST(MONTH(@periodo_facturacion) AS NVARCHAR(2)), 2);

                    DECLARE @pagos_activos TABLE (id_pago INT NOT NULL PRIMARY KEY);
                    INSERT INTO @pagos_activos (id_pago)
                    SELECT p.id_pago
                    FROM dbo.msp_pagos p
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
                      AND p.estado_pago = 1;

                    WHILE EXISTS (SELECT 1 FROM @pagos_activos)
                    BEGIN
                        SELECT TOP (1) @id_pago_anular = pa.id_pago
                        FROM @pagos_activos pa
                        ORDER BY pa.id_pago DESC;

                        EXEC dbo.msp_anular_pago_documento
                            @id_pago = @id_pago_anular,
                            @fecha_anulacion = @fecha_anulacion_pago,
                            @motivo_anulacion = @motivo_anulacion;

                        DELETE FROM @pagos_activos WHERE id_pago = @id_pago_anular;
                    END;
                END;

                DELETE p
                FROM dbo.msp_pagos p
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @pagos_borrados = @@ROWCOUNT;
            END;
        END;

        IF @del_cargos_salida_asociados = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NOT NULL
            BEGIN
                UPDATE cs
                SET
                    cs.id_documento_cobro = NULL,
                    cs.estado_cargo = CASE
                        WHEN cs.estado_cargo = 3 THEN 1
                        ELSE cs.estado_cargo
                    END
                FROM dbo.msp_cargos_salida cs
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = cs.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @cargos_salida_desvinculados = @@ROWCOUNT;
            END;
        END;

        IF @del_docs = 1
        BEGIN
            IF @del_pagos = 0
               AND OBJECT_ID(N'dbo.msp_pagos', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_pagos p
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50092, 'No se pueden borrar documentos del periodo porque existen pagos asociados.', 1;
            END;

            IF @del_cargos_salida_asociados = 0
               AND OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_cargos_salida cs
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = cs.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50093, 'No se pueden borrar documentos del periodo porque existen cargos de salida asociados.', 1;
            END;

            IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_movimientos_garantia mg
                    INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = mg.id_documento_cobro
                    WHERE dc.periodo_facturacion = @periodo_facturacion
               )
            BEGIN
                THROW 50094, 'No se pueden borrar documentos del periodo porque existen movimientos de garantia asociados.', 1;
            END;

            IF OBJECT_ID(N'dbo.msp_documentos_cobro_detalle', N'U') IS NOT NULL
            BEGIN
                DELETE dcd
                FROM dbo.msp_documentos_cobro_detalle dcd
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = dcd.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion;

                SET @items_borrados = @@ROWCOUNT;
            END;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion;

            SET @docs_borrados = @@ROWCOUNT;
        END;

        IF @del_cobros = 1
        BEGIN
            IF OBJECT_ID(N'dbo.msp_documentos_cobro_detalle', N'U') IS NOT NULL
               AND EXISTS (
                    SELECT 1
                    FROM dbo.msp_documentos_cobro_detalle dcd
                    WHERE dcd.id_cobro_servicio IN (
                        SELECT cs.id_cobro_servicio
                        FROM dbo.msp_cobros_servicios cs
                        INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
                        INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
                        WHERE p.id_cierre_mensual = @id_cierre
                    )
               )
            BEGIN
                THROW 50095, 'No se pueden borrar cobros porque hay documentos que aun los referencian.', 1;
            END;

            DELETE cs
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura = cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro = lm.id_proceso_cobro
            WHERE p.id_cierre_mensual = @id_cierre;

            SET @cobros_borrados = @@ROWCOUNT;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @docs_borrados AS docs_borrados,
        @items_borrados AS items_borrados,
        @cobros_borrados AS cobros_borrados,
        @pagos_borrados AS pagos_borrados,
        @cargos_salida_desvinculados AS cargos_salida_desvinculados;
END;
GO

PRINT 'Patch operacion mensual SP aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_envio_lotes_programados.sql';
/* ===================================================================== */

/*
===========================================================================
 MSP - PATCH ENVIO DE LOTES PROGRAMADOS
 - Cola persistente para envios automáticos de documentos de cobro
 - Fuente de elegibilidad: contrato/local (no ocupación)
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_envio_lotes_programados', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lotes_programados (
        id_lote_envio        INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion  DATE NOT NULL,
        codigo_servicio      NVARCHAR(20) NOT NULL,
        modo_destino         NVARCHAR(10) NOT NULL CONSTRAINT DF_msp_envio_lotes_modo DEFAULT (N'real'),
        demo_destino         NVARCHAR(200) NULL,
        programado_para      DATETIME2(0) NOT NULL,
        estado_lote          TINYINT NOT NULL CONSTRAINT DF_msp_envio_lotes_estado DEFAULT (1),
        batch_size           INT NOT NULL CONSTRAINT DF_msp_envio_lotes_batch DEFAULT (10),
        total_destinatarios  INT NOT NULL CONSTRAINT DF_msp_envio_lotes_total DEFAULT (0),
        procesados           INT NOT NULL CONSTRAINT DF_msp_envio_lotes_procesados DEFAULT (0),
        enviados             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_enviados DEFAULT (0),
        fallidos             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_fallidos DEFAULT (0),
        omitidos             INT NOT NULL CONSTRAINT DF_msp_envio_lotes_omitidos DEFAULT (0),
        worker_token         NVARCHAR(120) NULL,
        last_error           NVARCHAR(1000) NULL,
        created_by_user_id   INT NULL,
        created_at           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lotes_created_at DEFAULT (SYSDATETIME()),
        updated_at           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lotes_updated_at DEFAULT (SYSDATETIME()),
        started_at           DATETIME2(0) NULL,
        finished_at          DATETIME2(0) NULL,
        CONSTRAINT PK_msp_envio_lotes_programados PRIMARY KEY (id_lote_envio),
        CONSTRAINT CK_msp_envio_lotes_periodo CHECK (DAY(periodo_facturacion) = 1),
        CONSTRAINT CK_msp_envio_lotes_servicio CHECK (UPPER(codigo_servicio) IN (N'AGUA', N'LUZ', N'GAS')),
        CONSTRAINT CK_msp_envio_lotes_modo CHECK (LOWER(modo_destino) IN (N'real', N'demo')),
        CONSTRAINT CK_msp_envio_lotes_estado CHECK (estado_lote IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_envio_lotes_batch CHECK (batch_size BETWEEN 1 AND 100),
        CONSTRAINT CK_msp_envio_lotes_contadores CHECK (
            total_destinatarios >= 0
            AND procesados >= 0
            AND enviados >= 0
            AND fallidos >= 0
            AND omitidos >= 0
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lotes_programados_estado_programado'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lotes_programados')
)
BEGIN
    CREATE INDEX IX_msp_envio_lotes_programados_estado_programado
        ON dbo.msp_envio_lotes_programados (estado_lote, programado_para, id_lote_envio)
        INCLUDE (batch_size, periodo_facturacion, codigo_servicio, modo_destino, demo_destino);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lotes_programados_periodo'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lotes_programados')
)
BEGIN
    CREATE INDEX IX_msp_envio_lotes_programados_periodo
        ON dbo.msp_envio_lotes_programados (periodo_facturacion, id_lote_envio DESC)
        INCLUDE (codigo_servicio, estado_lote, procesados, total_destinatarios, enviados, fallidos, omitidos, programado_para);
END;
GO

IF OBJECT_ID(N'dbo.msp_envio_lote_destinatarios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lote_destinatarios (
        id_lote_destinatario            INT IDENTITY(1,1) NOT NULL,
        id_lote_envio                   INT NOT NULL,
        id_arrendatario                 INT NOT NULL,
        nombre_arrendatario_snapshot    NVARCHAR(200) NOT NULL,
        rut_snapshot                    NVARCHAR(30) NULL,
        correo_principal_snapshot       NVARCHAR(200) NULL,
        correo_destino                  NVARCHAR(200) NOT NULL,
        estado_destinatario             TINYINT NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_estado DEFAULT (1),
        intentos                        INT NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_intentos DEFAULT (0),
        ultimo_error                    NVARCHAR(1000) NULL,
        enviado_at                      DATETIME2(0) NULL,
        created_at                      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_created DEFAULT (SYSDATETIME()),
        updated_at                      DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_destinatarios_updated DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_envio_lote_destinatarios PRIMARY KEY (id_lote_destinatario),
        CONSTRAINT FK_msp_envio_lote_destinatarios_lote
            FOREIGN KEY (id_lote_envio) REFERENCES dbo.msp_envio_lotes_programados (id_lote_envio),
        CONSTRAINT CK_msp_envio_lote_destinatarios_estado CHECK (estado_destinatario IN (1,2,3,4)),
        CONSTRAINT CK_msp_envio_lote_destinatarios_intentos CHECK (intentos >= 0),
        CONSTRAINT UQ_msp_envio_lote_destinatarios_lote_arr UNIQUE (id_lote_envio, id_arrendatario)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lote_destinatarios_pendientes'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lote_destinatarios')
)
BEGIN
    CREATE INDEX IX_msp_envio_lote_destinatarios_pendientes
        ON dbo.msp_envio_lote_destinatarios (id_lote_envio, estado_destinatario, id_lote_destinatario)
        INCLUDE (correo_destino, intentos);
END;
GO

IF OBJECT_ID(N'dbo.msp_envio_lote_documentos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_envio_lote_documentos (
        id_lote_destinatario  INT NOT NULL,
        id_documento_cobro    INT NOT NULL,
        created_at            DATETIME2(0) NOT NULL CONSTRAINT DF_msp_envio_lote_documentos_created DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_envio_lote_documentos PRIMARY KEY (id_lote_destinatario, id_documento_cobro),
        CONSTRAINT FK_msp_envio_lote_documentos_dest
            FOREIGN KEY (id_lote_destinatario) REFERENCES dbo.msp_envio_lote_destinatarios (id_lote_destinatario),
        CONSTRAINT FK_msp_envio_lote_documentos_doc
            FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE name = N'IX_msp_envio_lote_documentos_doc'
      AND object_id = OBJECT_ID(N'dbo.msp_envio_lote_documentos')
)
BEGIN
    CREATE INDEX IX_msp_envio_lote_documentos_doc
        ON dbo.msp_envio_lote_documentos (id_documento_cobro, id_lote_destinatario);
END;
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_pool_documentos_periodo.sql';
/* ===================================================================== */

/*
===========================================================================
 PATCH: pool operacional de documentos por periodo
 - Crea tabla base msp_pool_documentos_periodo.
 - Vincula msp_documentos_cobro con id_pool_documento (nullable, incremental).
===========================================================================
*/

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pool_documentos_periodo (
        id_pool_documento        INT IDENTITY(1,1) NOT NULL,
        periodo_facturacion      DATE NOT NULL,
        id_tienda                INT NOT NULL,
        id_contrato_arriendo     INT NOT NULL,
        estado_pool              TINYINT NOT NULL CONSTRAINT DF_msp_pool_doc_estado DEFAULT (1),
        perfil_servicios         NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_pool_doc_perfil DEFAULT (N'LUZ'),

        requiere_luz             BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_luz DEFAULT (0),
        requiere_gas             BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_gas DEFAULT (0),
        requiere_agua            BIT NOT NULL CONSTRAINT DF_msp_pool_doc_req_agua DEFAULT (0),

        tiene_luz                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_luz DEFAULT (0),
        tiene_gas                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_gas DEFAULT (0),
        tiene_agua               BIT NOT NULL CONSTRAINT DF_msp_pool_doc_tiene_agua DEFAULT (0),

        ready_luz                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_luz DEFAULT (0),
        ready_gas                BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_gas DEFAULT (0),
        ready_agua               BIT NOT NULL CONSTRAINT DF_msp_pool_doc_ready_agua DEFAULT (0),

        id_documento_cobro       INT NULL,
        id_lote_envio_ultimo     INT NULL,
        saldo_aplicado_total     DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pool_doc_saldo DEFAULT (0),
        motivo_pendiente         NVARCHAR(500) NULL,

        created_at               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pool_doc_created DEFAULT (SYSDATETIME()),
        updated_at               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pool_doc_updated DEFAULT (SYSDATETIME()),

        CONSTRAINT PK_msp_pool_documentos_periodo PRIMARY KEY (id_pool_documento),
        CONSTRAINT UQ_msp_pool_documentos_periodo UNIQUE (periodo_facturacion, id_tienda, id_contrato_arriendo),
        CONSTRAINT CK_msp_pool_doc_periodo CHECK (DAY(periodo_facturacion) = 1),
        CONSTRAINT CK_msp_pool_doc_estado CHECK (estado_pool IN (1,2,3,4,5)),
        CONSTRAINT CK_msp_pool_doc_perfil CHECK (perfil_servicios IN (N'LUZ', N'LUZ_GAS', N'LUZ_AGUA', N'LUZ_GAS_AGUA')),
        CONSTRAINT FK_msp_pool_doc_tienda FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
        CONSTRAINT FK_msp_pool_doc_contrato FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT FK_msp_pool_doc_documento FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pool_doc_periodo_estado'
      AND object_id = OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pool_doc_periodo_estado
        ON dbo.msp_pool_documentos_periodo (periodo_facturacion, estado_pool)
        INCLUDE (id_tienda, id_documento_cobro, ready_luz, ready_gas, ready_agua, id_lote_envio_ultimo);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pool_doc_periodo_etapas'
      AND object_id = OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pool_doc_periodo_etapas
        ON dbo.msp_pool_documentos_periodo (periodo_facturacion, ready_luz, ready_gas, ready_agua)
        INCLUDE (id_tienda, id_documento_cobro, estado_pool, id_lote_envio_ultimo);
END;
GO

IF COL_LENGTH('dbo.msp_documentos_cobro', 'id_pool_documento') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro
    ADD id_pool_documento INT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = N'FK_msp_documentos_cobro_pool_documento'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
)
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro WITH CHECK
    ADD CONSTRAINT FK_msp_documentos_cobro_pool_documento
        FOREIGN KEY (id_pool_documento)
        REFERENCES dbo.msp_pool_documentos_periodo (id_pool_documento);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_documentos_cobro_pool_documento'
      AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
)
BEGIN
    CREATE INDEX IX_msp_documentos_cobro_pool_documento
        ON dbo.msp_documentos_cobro (id_pool_documento)
        WHERE id_pool_documento IS NOT NULL;
END;
GO

PRINT 'Patch pool documentos por periodo aplicado.';
GO

GO


/* ===================================================================== */
PRINT 'MSP initial: patch_saldo_favor_lote_origen.sql';
/* ===================================================================== */

/*
===========================================================================
 PATCH: trazabilidad saldo a favor por lote origen
 - Agrega id_lote_envio_origen en msp_saldo_favor_periodo_aplicaciones.
 - Índices/FK para auditoría por lote.
===========================================================================
*/

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_saldo_favor_periodo_aplicaciones', 'id_lote_envio_origen') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_saldo_favor_periodo_aplicaciones
        ADD id_lote_envio_origen INT NULL;
    END;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.msp_envio_lotes_programados', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE name = N'FK_msp_sf_periodo_aplicacion_lote_origen'
          AND parent_object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
   )
BEGIN
    ALTER TABLE dbo.msp_saldo_favor_periodo_aplicaciones WITH CHECK
    ADD CONSTRAINT FK_msp_sf_periodo_aplicacion_lote_origen
        FOREIGN KEY (id_lote_envio_origen)
        REFERENCES dbo.msp_envio_lotes_programados (id_lote_envio);
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND name = N'IX_msp_sf_periodo_aplicacion_lote_origen'
   )
BEGIN
    CREATE INDEX IX_msp_sf_periodo_aplicacion_lote_origen
        ON dbo.msp_saldo_favor_periodo_aplicaciones (id_lote_envio_origen, estado_aplicacion)
        INCLUDE (periodo_facturacion, id_tienda, id_documento_cobro, monto_aplicado, id_pago)
        WHERE id_lote_envio_origen IS NOT NULL;
END;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
   AND EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
   )
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM sys.index_columns ic
        INNER JOIN sys.columns c
            ON c.object_id = ic.object_id
           AND c.column_id = ic.column_id
        INNER JOIN sys.indexes i
            ON i.object_id = ic.object_id
           AND i.index_id = ic.index_id
        WHERE i.object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U')
          AND i.name = N'IX_msp_sf_periodo_aplicacion_periodo_tienda_estado'
          AND c.name = N'id_lote_envio_origen'
    )
    BEGIN
        DROP INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones;

        CREATE INDEX IX_msp_sf_periodo_aplicacion_periodo_tienda_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones (periodo_facturacion, id_tienda, estado_aplicacion)
            INCLUDE (monto_aplicado, id_documento_cobro, id_saldo_favor_periodo_item, id_pago, id_lote_envio_origen);
    END;
END;
GO

PRINT 'Patch trazabilidad saldo a favor por lote origen aplicado.';
GO

GO

PRINT 'NOTA: Job SQL Agent de envio lotes NO incluido en initial_msp.sql.';
PRINT 'Si necesitas ejecucion automatica, ejecutar msp/db/patch_sql_agent_envio_lotes_job.sql (ajustando rutas y con permisos sysadmin).';
GO

PRINT 'MSP initial completado';
