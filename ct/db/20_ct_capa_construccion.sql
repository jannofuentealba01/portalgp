/*
  Script: 20_ct_capa_construccion.sql
  Capa: Construccion
  Objetivo:
  - Crear entidades de proyectos y arquitectura/legal de terreno
  - Integrar referencias a terreno y tercero existentes

  Referencias:
  - Terreno(REF): dbo.ct_terreno
  - Tercero(REF): dbo.ct_tercero

  Orden recomendado:
  1) Ejecutar 10_ct_capa_predial.sql
  2) Ejecutar 20_ct_capa_construccion.sql
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* =========================
   Catalogos construccion
   ========================= */

IF OBJECT_ID('dbo.ct_rol_en_proyecto', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_rol_en_proyecto (
        id_rol_en_proyecto INT IDENTITY(1,1) NOT NULL,
        nombre             NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_rol_en_proyecto PRIMARY KEY CLUSTERED (id_rol_en_proyecto),
        CONSTRAINT UQ_ct_rol_en_proyecto_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_empresa_sanitaria', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_empresa_sanitaria (
        id_empresa_sanitaria INT IDENTITY(1,1) NOT NULL,
        nombre               NVARCHAR(120) NOT NULL,
        activo               BIT NULL,
        CONSTRAINT PK_ct_empresa_sanitaria PRIMARY KEY CLUSTERED (id_empresa_sanitaria),
        CONSTRAINT UQ_ct_empresa_sanitaria_nombre UNIQUE (nombre)
    );
END;
GO

/* =========================
   Transaccionales construccion
   ========================= */

IF OBJECT_ID('dbo.ct_proyecto_construccion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_proyecto_construccion (
        id_proyecto     INT IDENTITY(1,1) NOT NULL,
        nombre          NVARCHAR(150) NOT NULL,
        id_tercero      INT NOT NULL,
        estado          NVARCHAR(50) NULL,
        fecha_inicio    DATE NULL,
        fecha_termino   DATE NULL,
        CONSTRAINT PK_ct_proyecto_construccion PRIMARY KEY CLUSTERED (id_proyecto),
        CONSTRAINT CK_ct_proyecto_construccion_fechas CHECK (fecha_termino IS NULL OR fecha_inicio IS NULL OR fecha_termino >= fecha_inicio),
        CONSTRAINT FK_ct_proyecto_construccion_tercero
            FOREIGN KEY (id_tercero) REFERENCES dbo.ct_tercero(id_tercero)
    );
END;
GO

IF OBJECT_ID('dbo.ct_construccion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_construccion (
        id_construccion     INT IDENTITY(1,1) NOT NULL,
        id_proyecto         INT NOT NULL,
        tipo_construccion   NVARCHAR(80) NULL,
        nombre              NVARCHAR(150) NOT NULL,
        superficie_m2       DECIMAL(18,2) NULL,
        CONSTRAINT PK_ct_construccion PRIMARY KEY CLUSTERED (id_construccion),
        CONSTRAINT CK_ct_construccion_superficie CHECK (superficie_m2 IS NULL OR superficie_m2 > 0),
        CONSTRAINT FK_ct_construccion_proyecto
            FOREIGN KEY (id_proyecto) REFERENCES dbo.ct_proyecto_construccion(id_proyecto)
    );
END;
GO

IF OBJECT_ID('dbo.ct_proyecto_construccion_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_proyecto_construccion_terreno (
        id_proyecto_construccion_terreno INT IDENTITY(1,1) NOT NULL,
        id_proyecto                      INT NOT NULL,
        id_terreno                       INT NOT NULL,
        id_rol_en_proyecto               INT NOT NULL,
        vigente_desde                    DATE NULL,
        vigente_hasta                    DATE NULL,
        CONSTRAINT PK_ct_proyecto_construccion_terreno PRIMARY KEY CLUSTERED (id_proyecto_construccion_terreno),
        CONSTRAINT CK_ct_proyecto_construccion_terreno_vigencia CHECK (vigente_hasta IS NULL OR vigente_desde IS NULL OR vigente_hasta >= vigente_desde),
        CONSTRAINT FK_ct_proyecto_construccion_terreno_proyecto
            FOREIGN KEY (id_proyecto) REFERENCES dbo.ct_proyecto_construccion(id_proyecto),
        CONSTRAINT FK_ct_proyecto_construccion_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_proyecto_construccion_terreno_rol
            FOREIGN KEY (id_rol_en_proyecto) REFERENCES dbo.ct_rol_en_proyecto(id_rol_en_proyecto)
    );
END;
GO

IF OBJECT_ID('dbo.ct_terreno_arquitectura_legal', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_terreno_arquitectura_legal (
        id_terreno                     INT NOT NULL,
        superficie_neta_arquitectura_m2 DECIMAL(18,2) NULL,
        resolucion                     NVARCHAR(120) NULL,
        fecha_resolucion               DATE NULL,
        direccion_arquitectura         NVARCHAR(200) NULL,
        factibilidad_electrica         BIT NULL,
        factibilidad_sanitaria         BIT NULL,
        id_empresa_sanitaria           INT NULL,
        garantia                       BIT NULL,
        resolucion_garantia            NVARCHAR(120) NULL,
        vencimiento_garantia           DATE NULL,
        modificacion_deslindes         BIT NULL,
        nueva_superficie_m2            DECIMAL(18,2) NULL,
        nueva_resolucion               NVARCHAR(120) NULL,
        fecha_nueva_resolucion         DATE NULL,
        CONSTRAINT PK_ct_terreno_arquitectura_legal PRIMARY KEY CLUSTERED (id_terreno),
        CONSTRAINT CK_ct_terreno_arquitectura_legal_superficie CHECK (
            (superficie_neta_arquitectura_m2 IS NULL OR superficie_neta_arquitectura_m2 > 0)
            AND (nueva_superficie_m2 IS NULL OR nueva_superficie_m2 > 0)
        ),
        CONSTRAINT FK_ct_terreno_arquitectura_legal_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_terreno_arquitectura_legal_empresa_sanitaria
            FOREIGN KEY (id_empresa_sanitaria) REFERENCES dbo.ct_empresa_sanitaria(id_empresa_sanitaria)
    );
END;
GO

/* =========================
   Indices construccion
   ========================= */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_proyecto_construccion_tercero' AND object_id = OBJECT_ID('dbo.ct_proyecto_construccion'))
    CREATE INDEX IX_ct_proyecto_construccion_tercero ON dbo.ct_proyecto_construccion(id_tercero);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_construccion_proyecto' AND object_id = OBJECT_ID('dbo.ct_construccion'))
    CREATE INDEX IX_ct_construccion_proyecto ON dbo.ct_construccion(id_proyecto);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_proyecto_construccion_terreno_proyecto' AND object_id = OBJECT_ID('dbo.ct_proyecto_construccion_terreno'))
    CREATE INDEX IX_ct_proyecto_construccion_terreno_proyecto ON dbo.ct_proyecto_construccion_terreno(id_proyecto);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_proyecto_construccion_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_proyecto_construccion_terreno'))
    CREATE INDEX IX_ct_proyecto_construccion_terreno_terreno ON dbo.ct_proyecto_construccion_terreno(id_terreno);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_proyecto_construccion_terreno_rol' AND object_id = OBJECT_ID('dbo.ct_proyecto_construccion_terreno'))
    CREATE INDEX IX_ct_proyecto_construccion_terreno_rol ON dbo.ct_proyecto_construccion_terreno(id_rol_en_proyecto);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_arquitectura_legal_empresa' AND object_id = OBJECT_ID('dbo.ct_terreno_arquitectura_legal'))
    CREATE INDEX IX_ct_terreno_arquitectura_legal_empresa ON dbo.ct_terreno_arquitectura_legal(id_empresa_sanitaria);
GO

PRINT 'Capa construccion creada/validada correctamente.';
GO
