/*
  Script: 30_ct_capa_tributaria.sql
  Capa: Tributaria
  Objetivo:
  - Crear entidades tributarias del modelo
  - Integrar referencias a terreno y usuarios corporativos

  Referencias:
  - Terreno(REF): dbo.ct_terreno
  - Usuario(REF): dbo.cr_usuarios

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
   Catalogos tributarios
   ========================= */

IF OBJECT_ID('dbo.ct_estado_sii', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_estado_sii (
        id_estado_sii   INT IDENTITY(1,1) NOT NULL,
        nombre          NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_estado_sii PRIMARY KEY CLUSTERED (id_estado_sii),
        CONSTRAINT UQ_ct_estado_sii_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_condicion_rol', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_condicion_rol (
        id_condicion_rol INT IDENTITY(1,1) NOT NULL,
        nombre           NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_condicion_rol PRIMARY KEY CLUSTERED (id_condicion_rol),
        CONSTRAINT UQ_ct_condicion_rol_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_destino_sii', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_destino_sii (
        id_destino_sii   INT IDENTITY(1,1) NOT NULL,
        nombre           NVARCHAR(100) NOT NULL,
        CONSTRAINT PK_ct_destino_sii PRIMARY KEY CLUSTERED (id_destino_sii),
        CONSTRAINT UQ_ct_destino_sii_nombre UNIQUE (nombre)
    );
END;
GO


/* =========================
   Transaccionales tributarias
   ========================= */

IF OBJECT_ID('dbo.ct_terreno_tributario', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_terreno_tributario (
        id_terreno        INT NOT NULL,
        rol_pagador       VARCHAR(30) NULL,
        id_estado_sii     INT NOT NULL,
        id_condicion_rol  INT NOT NULL,
        id_destino_sii    INT NOT NULL,
        detalle_tgr       NVARCHAR(400) NULL,
        CONSTRAINT PK_ct_terreno_tributario PRIMARY KEY CLUSTERED (id_terreno),
        CONSTRAINT FK_ct_terreno_tributario_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_terreno_tributario_estado_sii
            FOREIGN KEY (id_estado_sii) REFERENCES dbo.ct_estado_sii(id_estado_sii),
        CONSTRAINT FK_ct_terreno_tributario_condicion_rol
            FOREIGN KEY (id_condicion_rol) REFERENCES dbo.ct_condicion_rol(id_condicion_rol),
        CONSTRAINT FK_ct_terreno_tributario_destino_sii
            FOREIGN KEY (id_destino_sii) REFERENCES dbo.ct_destino_sii(id_destino_sii)
    );
END;
GO

IF OBJECT_ID('dbo.ct_avaluo_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_avaluo_terreno (
        id_avaluo                 INT IDENTITY(1,1) NOT NULL,
        id_terreno                INT NOT NULL,
        anio_avaluo               INT NOT NULL,
        fecha_referencia          DATE NOT NULL,
        avaluo_total              DECIMAL(18,2) NULL,
        avaluo_exento             DECIMAL(18,2) NULL,
        avaluo_afecto             DECIMAL(18,2) NULL,
        valor_libro_contable_uf   DECIMAL(18,2) NULL,
        id_usuario                INT NOT NULL,
        CONSTRAINT PK_ct_avaluo_terreno PRIMARY KEY CLUSTERED (id_avaluo),
        CONSTRAINT CK_ct_avaluo_terreno_anio CHECK (anio_avaluo >= 1900),
        CONSTRAINT CK_ct_avaluo_terreno_total_nonneg CHECK (avaluo_total IS NULL OR avaluo_total >= 0),
        CONSTRAINT CK_ct_avaluo_terreno_exento_nonneg CHECK (avaluo_exento IS NULL OR avaluo_exento >= 0),
        CONSTRAINT CK_ct_avaluo_terreno_afecto_nonneg CHECK (avaluo_afecto IS NULL OR avaluo_afecto >= 0),
        CONSTRAINT CK_ct_avaluo_terreno_libro_nonneg CHECK (valor_libro_contable_uf IS NULL OR valor_libro_contable_uf >= 0),
        CONSTRAINT FK_ct_avaluo_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno)
    );
END;
GO


/* =====================================
   FK a Usuario corporativo (dbo.cr_usuarios)
   ===================================== */

IF OBJECT_ID('dbo.ct_avaluo_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.cr_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_avaluo_terreno_cr_usuarios')
BEGIN
    IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id_usuario'
    )
    BEGIN
        ALTER TABLE dbo.ct_avaluo_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_avaluo_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id'
    )
    BEGIN
        ALTER TABLE dbo.ct_avaluo_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_avaluo_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'Id'
    )
    BEGIN
        ALTER TABLE dbo.ct_avaluo_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_avaluo_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);
    END
END;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_avaluo_terreno_cr_usuarios')
BEGIN
    ALTER TABLE dbo.ct_avaluo_terreno
    CHECK CONSTRAINT FK_ct_avaluo_terreno_cr_usuarios;
END;
GO

IF OBJECT_ID('dbo.cr_usuarios', 'U') IS NULL
BEGIN
    PRINT 'Aviso: no existe dbo.cr_usuarios; FK de ct_avaluo_terreno.id_usuario queda pendiente.';
END;
GO

/* =========================
   Indices tributarios
   ========================= */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_avaluo_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_avaluo_terreno'))
    CREATE INDEX IX_ct_avaluo_terreno_terreno ON dbo.ct_avaluo_terreno(id_terreno, anio_avaluo DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_avaluo_terreno_usuario' AND object_id = OBJECT_ID('dbo.ct_avaluo_terreno'))
    CREATE INDEX IX_ct_avaluo_terreno_usuario ON dbo.ct_avaluo_terreno(id_usuario);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_tributario_estado_sii' AND object_id = OBJECT_ID('dbo.ct_terreno_tributario'))
    CREATE INDEX IX_ct_terreno_tributario_estado_sii ON dbo.ct_terreno_tributario(id_estado_sii);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_tributario_condicion_rol' AND object_id = OBJECT_ID('dbo.ct_terreno_tributario'))
    CREATE INDEX IX_ct_terreno_tributario_condicion_rol ON dbo.ct_terreno_tributario(id_condicion_rol);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_terreno_tributario_destino_sii' AND object_id = OBJECT_ID('dbo.ct_terreno_tributario'))
    CREATE INDEX IX_ct_terreno_tributario_destino_sii ON dbo.ct_terreno_tributario(id_destino_sii);
GO

PRINT 'Capa tributaria creada/validada correctamente.';
GO
