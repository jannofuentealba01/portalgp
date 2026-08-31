/*
  Script: 10_ct_capa_predial.sql
  Capa: Predial
  Objetivo:
  - Crear entidades base de la capa predial
  - Dejar llaves preparadas para integracion con capa contabilidad

  Orden recomendado:
  1) Ejecutar 10_ct_capa_predial.sql
  2) Ejecutar 20_ct_capa_construccion.sql
  3) Ejecutar 30_ct_capa_tributaria.sql
  4) Ejecutar 40_ct_capa_contabilidad.sql
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* =========================
   Catalogos / Referencias
   ========================= */

IF OBJECT_ID('dbo.ct_comuna', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_comuna (
        id_comuna        INT IDENTITY(1,1) NOT NULL,
        nombre           NVARCHAR(120) NOT NULL,
        CONSTRAINT PK_ct_comuna PRIMARY KEY CLUSTERED (id_comuna),
        CONSTRAINT UQ_ct_comuna_nombre UNIQUE (nombre)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Puerto Montt')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Puerto Montt');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'San Pedro de la Paz')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('San Pedro de la Paz');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Hualqui')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Hualqui');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Vitacura')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Vitacura');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Coronel')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Coronel');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Concepcion')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Concepcion');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Temuco')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Temuco');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Talcahuano')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Talcahuano');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Talca')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Talca');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Valdivia')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Valdivia');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'San Fabián de Alico')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('San Fabián de Alico');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Colina')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Colina');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Arauco')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Arauco');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_comuna WHERE nombre = 'Natales')
    INSERT INTO dbo.ct_comuna (nombre) VALUES ('Natales');
GO


IF OBJECT_ID('dbo.ct_estado_terreno_predial', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_estado_terreno_predial (
        id_estado_predial INT IDENTITY(1,1) NOT NULL,
        nombre            NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_estado_terreno_predial PRIMARY KEY CLUSTERED (id_estado_predial),
        CONSTRAINT UQ_ct_estado_terreno_predial_nombre UNIQUE (nombre)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'No disponible')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('No disponible');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Disponible')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Disponible');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Cedido')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Cedido');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Subdividido')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Subdividido');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Fusionado')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Fusionado');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Construido')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Construido');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_predial WHERE nombre = 'Porcionado')
    INSERT INTO dbo.ct_estado_terreno_predial (nombre) VALUES ('Porcionado');
GO

IF OBJECT_ID('dbo.ct_tipo_inmueble', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_tipo_inmueble (
        id_tipo_inmueble INT IDENTITY(1,1) NOT NULL,
        nombre           NVARCHAR(80) NOT NULL,
        activo           BIT NOT NULL CONSTRAINT DF_ct_tipo_inmueble_activo DEFAULT (1),
        CONSTRAINT PK_ct_tipo_inmueble PRIMARY KEY CLUSTERED (id_tipo_inmueble),
        CONSTRAINT UQ_ct_tipo_inmueble_nombre UNIQUE (nombre)
    );
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Terreno')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Terreno');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Habitacional')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Habitacional');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Estacionamiento')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Estacionamiento');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Bodega')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Bodega');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Oficina')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Oficina');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Comercial')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Comercial');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_inmueble WHERE nombre = 'Industrial')
    INSERT INTO dbo.ct_tipo_inmueble (nombre) VALUES ('Industrial');
GO

IF OBJECT_ID('dbo.ct_tercero', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_tercero (
        id_tercero           INT IDENTITY(1,1) NOT NULL,
        tipo_persona         CHAR(1) NOT NULL,
        rut                  VARCHAR(20) NULL,
        nombre_razon_social  NVARCHAR(200) NOT NULL,
        CONSTRAINT PK_ct_tercero PRIMARY KEY CLUSTERED (id_tercero),
        CONSTRAINT CK_ct_tercero_tipo_persona CHECK (tipo_persona IN ('N', 'J'))
    );
END;
GO

/* =========================
   Nucleo Predial
   ========================= */

IF OBJECT_ID('dbo.ct_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_terreno (
        id_terreno                  INT IDENTITY(1,1) NOT NULL,
        rol_asignado                VARCHAR(30) NOT NULL,
        rol_matriz                  VARCHAR(30) NULL,
        identificacion_propiedad    NVARCHAR(120) NULL,
        superficie_m2               DECIMAL(18,2) NOT NULL,
        id_comuna                   INT NOT NULL,
        id_estado_predial           INT NOT NULL,
        id_estado_comercial         INT NOT NULL,  -- FK se agrega en capa contabilidad
        id_tipo_inmueble            INT NOT NULL,
        CONSTRAINT PK_ct_terreno PRIMARY KEY CLUSTERED (id_terreno),
        CONSTRAINT CK_ct_terreno_superficie_m2_positiva CHECK (superficie_m2 > 0),
        CONSTRAINT FK_ct_terreno_comuna
            FOREIGN KEY (id_comuna) REFERENCES dbo.ct_comuna(id_comuna),
        CONSTRAINT FK_ct_terreno_estado_predial
            FOREIGN KEY (id_estado_predial) REFERENCES dbo.ct_estado_terreno_predial(id_estado_predial),
        CONSTRAINT FK_ct_terreno_tipo_inmueble
            FOREIGN KEY (id_tipo_inmueble) REFERENCES dbo.ct_tipo_inmueble(id_tipo_inmueble)
    );
END;
GO

IF OBJECT_ID('dbo.ct_titularidad_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_titularidad_terreno (
        id_titularidad         INT IDENTITY(1,1) NOT NULL,
        id_terreno             INT NOT NULL,
        id_tercero             INT NOT NULL,
        vigente_desde          DATE NOT NULL,
        vigente_hasta          DATE NULL,
        porcentaje_derecho     DECIMAL(5,2) NOT NULL,
        CONSTRAINT PK_ct_titularidad_terreno PRIMARY KEY CLUSTERED (id_titularidad),
        CONSTRAINT CK_ct_titularidad_terreno_porcentaje CHECK (porcentaje_derecho > 0 AND porcentaje_derecho <= 100),
        CONSTRAINT CK_ct_titularidad_terreno_vigencia_fechas CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde),
        CONSTRAINT FK_ct_titularidad_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_titularidad_terreno_tercero
            FOREIGN KEY (id_tercero) REFERENCES dbo.ct_tercero(id_tercero)
    );
END;
GO

IF OBJECT_ID('dbo.ct_operacion_predial', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_operacion_predial (
        id_operacion       INT IDENTITY(1,1) NOT NULL,
        tipo_operacion     NVARCHAR(50) NOT NULL,
        fecha_operacion    DATE NOT NULL,
        fecha_registro     DATETIME2(0) NOT NULL CONSTRAINT DF_ct_operacion_predial_fecha_registro DEFAULT (SYSUTCDATETIME()),
        documento_fuente   NVARCHAR(255) NULL,
        CONSTRAINT PK_ct_operacion_predial PRIMARY KEY CLUSTERED (id_operacion)
    );
END;
GO

IF OBJECT_ID('dbo.ct_operacion_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_operacion_terreno (
        id_operacion_terreno   INT IDENTITY(1,1) NOT NULL,
        id_operacion           INT NOT NULL,
        id_terreno             INT NOT NULL,
        rol_en_operacion       NVARCHAR(30) NULL,
        CONSTRAINT PK_ct_operacion_terreno PRIMARY KEY CLUSTERED (id_operacion_terreno),
        CONSTRAINT FK_ct_operacion_terreno_operacion
            FOREIGN KEY (id_operacion) REFERENCES dbo.ct_operacion_predial(id_operacion),
        CONSTRAINT FK_ct_operacion_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno)
    );
END;
GO

IF OBJECT_ID('dbo.ct_historial_estado_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_historial_estado_terreno (
        id_historial_estado   INT IDENTITY(1,1) NOT NULL,
        id_terreno            INT NOT NULL,
        id_estado_anterior    INT NULL,
        id_estado_nuevo       INT NOT NULL,
        fecha_cambio          DATETIME2(0) NOT NULL CONSTRAINT DF_ct_historial_estado_terreno_fecha_cambio DEFAULT (SYSUTCDATETIME()),
        id_venta              INT NULL, -- FK se agrega en capa contabilidad
        id_operacion          INT NULL,
        id_usuario            INT NOT NULL,
        tipo_estado           CHAR(1) NOT NULL,
        CONSTRAINT PK_ct_historial_estado_terreno PRIMARY KEY CLUSTERED (id_historial_estado),
        CONSTRAINT CK_ct_historial_estado_terreno_tipo_estado CHECK (tipo_estado IN ('P', 'C')),
        CONSTRAINT FK_ct_historial_estado_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_historial_estado_terreno_operacion
            FOREIGN KEY (id_operacion) REFERENCES dbo.ct_operacion_predial(id_operacion)
    );
END;
GO

/* Usuario(REF) corporativo: dbo.cr_usuarios */
IF OBJECT_ID('dbo.ct_historial_estado_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.cr_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_historial_estado_terreno_cr_usuarios')
BEGIN
    IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id_usuario'
    )
    BEGIN
        ALTER TABLE dbo.ct_historial_estado_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_historial_estado_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id'
    )
    BEGIN
        ALTER TABLE dbo.ct_historial_estado_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_historial_estado_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'Id'
    )
    BEGIN
        ALTER TABLE dbo.ct_historial_estado_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_historial_estado_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);
    END
END;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_historial_estado_terreno_cr_usuarios')
BEGIN
    ALTER TABLE dbo.ct_historial_estado_terreno
    CHECK CONSTRAINT FK_ct_historial_estado_terreno_cr_usuarios;
END;
GO

IF OBJECT_ID('dbo.cr_usuarios', 'U') IS NULL
BEGIN
    PRINT 'Aviso: no existe dbo.cr_usuarios; FK de ct_historial_estado_terreno.id_usuario queda pendiente.';
END;
GO

/* =========================
   Indices predial
   ========================= */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_comuna' AND object_id = OBJECT_ID('dbo.ct_terreno'))
    CREATE INDEX IX_ct_terreno_comuna ON dbo.ct_terreno(id_comuna);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_estado_predial' AND object_id = OBJECT_ID('dbo.ct_terreno'))
    CREATE INDEX IX_ct_terreno_estado_predial ON dbo.ct_terreno(id_estado_predial);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_estado_comercial' AND object_id = OBJECT_ID('dbo.ct_terreno'))
    CREATE INDEX IX_ct_terreno_estado_comercial ON dbo.ct_terreno(id_estado_comercial);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UQ_ct_terreno_rol_asignado' AND object_id = OBJECT_ID('dbo.ct_terreno'))
    CREATE UNIQUE INDEX UQ_ct_terreno_rol_asignado ON dbo.ct_terreno(rol_asignado);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_titularidad_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_titularidad_terreno'))
    CREATE INDEX IX_ct_titularidad_terreno_terreno ON dbo.ct_titularidad_terreno(id_terreno, vigente_desde DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_operacion_terreno_operacion' AND object_id = OBJECT_ID('dbo.ct_operacion_terreno'))
    CREATE INDEX IX_ct_operacion_terreno_operacion ON dbo.ct_operacion_terreno(id_operacion);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_operacion_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_operacion_terreno'))
    CREATE INDEX IX_ct_operacion_terreno_terreno ON dbo.ct_operacion_terreno(id_terreno);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_historial_estado_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_historial_estado_terreno'))
    CREATE INDEX IX_ct_historial_estado_terreno_terreno ON dbo.ct_historial_estado_terreno(id_terreno, fecha_cambio DESC);
GO

PRINT 'Capa predial creada/validada correctamente.';
GO
