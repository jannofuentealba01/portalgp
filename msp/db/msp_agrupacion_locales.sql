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
