/*
  Script: 40_ct_capa_contabilidad.sql
  Capa: Contabilidad
  Entidades consideradas de contabilidad:
  - ct_estado_terreno_comercial
  - ct_tipo_tasacion
  - ct_usufructuario_tipo
  - ct_entidad_financiera
  - ct_usufructo_terreno
  - ct_hipoteca_terreno
  - ct_tasacion_terreno
  - ct_venta_terreno
  - ct_venta_terreno_tercero

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
   Catalogos contables
   ========================= */

IF OBJECT_ID('dbo.ct_estado_terreno_comercial', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_estado_terreno_comercial (
        id_estado_comercial INT IDENTITY(1,1) NOT NULL,
        nombre              NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_estado_terreno_comercial PRIMARY KEY CLUSTERED (id_estado_comercial),
        CONSTRAINT UQ_ct_estado_terreno_comercial_nombre UNIQUE (nombre)
    );
END;
GO

-- Vendido, Arrendado, Disponible, Familia

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_comercial WHERE nombre = 'Vendido')
    INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES ('Vendido');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_comercial WHERE nombre = 'Arrendado')
    INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES ('Arrendado');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_comercial WHERE nombre = 'Disponible')
    INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES ('Disponible');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_terreno_comercial WHERE nombre = 'Familia')
    INSERT INTO dbo.ct_estado_terreno_comercial (nombre) VALUES ('Familia');
GO

IF OBJECT_ID('dbo.ct_tipo_tasacion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_tipo_tasacion (
        id_tipo_tasacion INT IDENTITY(1,1) NOT NULL,
        nombre           NVARCHAR(80) NOT NULL,
        CONSTRAINT PK_ct_tipo_tasacion PRIMARY KEY CLUSTERED (id_tipo_tasacion),
        CONSTRAINT UQ_ct_tipo_tasacion_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_usufructuario_tipo', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_usufructuario_tipo (
        id_usufructuario_tipo INT IDENTITY(1,1) NOT NULL,
        nombre                NVARCHAR(80) NOT NULL,
        activo                BIT NOT NULL CONSTRAINT DF_ct_usufructuario_tipo_activo DEFAULT (1),
        CONSTRAINT PK_ct_usufructuario_tipo PRIMARY KEY CLUSTERED (id_usufructuario_tipo),
        CONSTRAINT UQ_ct_usufructuario_tipo_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_usufructuario_tipo', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_usufructuario_tipo', 'activo') IS NULL
BEGIN
    ALTER TABLE dbo.ct_usufructuario_tipo
    ADD activo BIT NOT NULL CONSTRAINT DF_ct_usufructuario_tipo_activo DEFAULT (1);
END;
GO

IF OBJECT_ID('dbo.ct_entidad_financiera', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_entidad_financiera (
        id_entidad_financiera INT IDENTITY(1,1) NOT NULL,
        nombre                NVARCHAR(120) NOT NULL,
        activo                BIT NOT NULL CONSTRAINT DF_ct_entidad_financiera_activo DEFAULT (1),
        CONSTRAINT PK_ct_entidad_financiera PRIMARY KEY CLUSTERED (id_entidad_financiera),
        CONSTRAINT UQ_ct_entidad_financiera_nombre UNIQUE (nombre)
    );
END;
GO

IF OBJECT_ID('dbo.ct_entidad_financiera', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_entidad_financiera', 'activo') IS NULL
BEGIN
    ALTER TABLE dbo.ct_entidad_financiera
    ADD activo BIT NULL;

    UPDATE dbo.ct_entidad_financiera
    SET activo = 1
    WHERE activo IS NULL;

    ALTER TABLE dbo.ct_entidad_financiera
    ALTER COLUMN activo BIT NOT NULL;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.default_constraints dc
        INNER JOIN sys.columns c
            ON c.object_id = dc.parent_object_id
           AND c.column_id = dc.parent_column_id
        WHERE dc.parent_object_id = OBJECT_ID('dbo.ct_entidad_financiera')
          AND c.name = 'activo'
    )
    BEGIN
        ALTER TABLE dbo.ct_entidad_financiera
        ADD CONSTRAINT DF_ct_entidad_financiera_activo DEFAULT (1) FOR activo;
    END;
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_tasacion WHERE nombre = 'Comercial')
    INSERT INTO dbo.ct_tipo_tasacion (nombre) VALUES ('Comercial');
GO
IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_tasacion WHERE nombre = 'Bancaria')
    INSERT INTO dbo.ct_tipo_tasacion (nombre) VALUES ('Bancaria');
GO

/* =========================
   Transaccionales contables
   ========================= */

IF OBJECT_ID('dbo.ct_usufructo_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_usufructo_terreno (
        id_usufructo            INT IDENTITY(1,1) NOT NULL,
        id_terreno              INT NOT NULL,
        id_usufructuario_tipo   INT NOT NULL,
        fecha_inicio            DATE NULL,
        fecha_fin               DATE NULL,
        porcentaje              DECIMAL(5,2) NULL,
        monto_referencia_uf     DECIMAL(18,2) NULL,
        observacion             NVARCHAR(300) NULL,
        CONSTRAINT PK_ct_usufructo_terreno PRIMARY KEY CLUSTERED (id_usufructo),
        CONSTRAINT CK_ct_usufructo_terreno_fechas CHECK (fecha_fin IS NULL OR fecha_inicio IS NULL OR fecha_fin >= fecha_inicio),
        CONSTRAINT CK_ct_usufructo_terreno_porcentaje CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100)),
        CONSTRAINT CK_ct_usufructo_terreno_monto_nonneg CHECK (monto_referencia_uf IS NULL OR monto_referencia_uf >= 0),
        CONSTRAINT FK_ct_usufructo_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_usufructo_terreno_tipo
            FOREIGN KEY (id_usufructuario_tipo) REFERENCES dbo.ct_usufructuario_tipo(id_usufructuario_tipo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_hipoteca_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_hipoteca_terreno (
        id_hipoteca              INT IDENTITY(1,1) NOT NULL,
        id_terreno               INT NOT NULL,
        id_entidad_financiera    INT NOT NULL,
        fecha_constitucion       DATE NOT NULL,
        monto_uf                 DECIMAL(18,2) NULL,
        estado                   NVARCHAR(40) NULL,
        observacion              NVARCHAR(300) NULL,
        CONSTRAINT PK_ct_hipoteca_terreno PRIMARY KEY CLUSTERED (id_hipoteca),
        CONSTRAINT CK_ct_hipoteca_terreno_monto_nonneg CHECK (monto_uf IS NULL OR monto_uf >= 0),
        CONSTRAINT FK_ct_hipoteca_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_hipoteca_terreno_entidad
            FOREIGN KEY (id_entidad_financiera) REFERENCES dbo.ct_entidad_financiera(id_entidad_financiera)
    );
END;
GO

IF OBJECT_ID('dbo.ct_hipoteca_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_entidad_financiera', 'U') IS NULL
BEGIN
    PRINT 'Aviso: no existe dbo.ct_entidad_financiera; FK de ct_hipoteca_terreno.id_entidad_financiera queda pendiente.';
END;
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_tasacion_terreno (
        id_tasacion              INT IDENTITY(1,1) NOT NULL,
        id_terreno               INT NOT NULL,
        id_tipo_tasacion         INT NOT NULL,
        fecha_tasacion           DATE NOT NULL,
        fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_ct_tasacion_terreno_fecha_registro DEFAULT (SYSUTCDATETIME()),
        valor_total_uf           DECIMAL(18,2) NOT NULL,
        valor_uf_m2              DECIMAL(18,4) NULL,
        id_entidad_financiera    INT NULL,
        es_referencial           BIT NOT NULL CONSTRAINT DF_ct_tasacion_terreno_es_referencial DEFAULT (0),
        vigente_desde            DATE NULL,
        vigente_hasta            DATE NULL,
        id_usuario               INT NOT NULL,
        CONSTRAINT PK_ct_tasacion_terreno PRIMARY KEY CLUSTERED (id_tasacion),
        CONSTRAINT CK_ct_tasacion_terreno_valor_total_uf_positivo CHECK (valor_total_uf > 0),
        CONSTRAINT CK_ct_tasacion_terreno_valor_uf_m2_positivo CHECK (valor_uf_m2 IS NULL OR valor_uf_m2 > 0),
        CONSTRAINT CK_ct_tasacion_terreno_vigencia_fechas CHECK (vigente_hasta IS NULL OR vigente_desde IS NULL OR vigente_hasta >= vigente_desde),
        CONSTRAINT FK_ct_tasacion_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_tasacion_terreno_tipo_tasacion
            FOREIGN KEY (id_tipo_tasacion) REFERENCES dbo.ct_tipo_tasacion(id_tipo_tasacion)
    );
END;
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_tasacion_terreno', 'fecha_registro') IS NULL
BEGIN
    ALTER TABLE dbo.ct_tasacion_terreno
    ADD fecha_registro DATETIME2(0) NULL;

    EXEC sp_executesql N'
        UPDATE dbo.ct_tasacion_terreno
        SET fecha_registro = SYSUTCDATETIME()
        WHERE fecha_registro IS NULL;
    ';

    EXEC sp_executesql N'
        ALTER TABLE dbo.ct_tasacion_terreno
        ALTER COLUMN fecha_registro DATETIME2(0) NOT NULL;
    ';
END;
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (
        SELECT 1
        FROM sys.default_constraints dc
        INNER JOIN sys.columns c
            ON c.object_id = dc.parent_object_id
           AND c.column_id = dc.parent_column_id
        WHERE dc.parent_object_id = OBJECT_ID('dbo.ct_tasacion_terreno')
          AND c.name = 'fecha_registro'
   )
BEGIN
    ALTER TABLE dbo.ct_tasacion_terreno
    ADD CONSTRAINT DF_ct_tasacion_terreno_fecha_registro
        DEFAULT (SYSUTCDATETIME()) FOR fecha_registro;
END;
GO

IF OBJECT_ID('dbo.ct_venta_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_venta_terreno (
        id_venta                   INT IDENTITY(1,1) NOT NULL,
        id_terreno                 INT NOT NULL,
        fecha_venta                DATE NOT NULL,
        valor_total_uf             DECIMAL(18,2) NOT NULL,
        valor_venta_uf_m2          DECIMAL(18,4) NULL,
        id_tasacion_referencial    INT NULL,
        CONSTRAINT PK_ct_venta_terreno PRIMARY KEY CLUSTERED (id_venta),
        CONSTRAINT CK_ct_venta_terreno_valor_total_uf_positivo CHECK (valor_total_uf > 0),
        CONSTRAINT CK_ct_venta_terreno_valor_uf_m2_positivo CHECK (valor_venta_uf_m2 IS NULL OR valor_venta_uf_m2 > 0),
        CONSTRAINT FK_ct_venta_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_venta_terreno_tasacion
            FOREIGN KEY (id_tasacion_referencial) REFERENCES dbo.ct_tasacion_terreno(id_tasacion)
    );
END;
GO

IF OBJECT_ID('dbo.ct_venta_terreno_tercero', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_venta_terreno_tercero (
        id_venta_tercero      INT IDENTITY(1,1) NOT NULL,
        id_venta              INT NOT NULL,
        id_tercero            INT NOT NULL,
        porcentaje            DECIMAL(5,2) NOT NULL,
        rol_en_venta          NVARCHAR(30) NULL,
        CONSTRAINT PK_ct_venta_terreno_tercero PRIMARY KEY CLUSTERED (id_venta_tercero),
        CONSTRAINT CK_ct_venta_terreno_tercero_porcentaje CHECK (porcentaje > 0 AND porcentaje <= 100),
        CONSTRAINT FK_ct_venta_terreno_tercero_venta
            FOREIGN KEY (id_venta) REFERENCES dbo.ct_venta_terreno(id_venta),
        CONSTRAINT FK_ct_venta_terreno_tercero_tercero
            FOREIGN KEY (id_tercero) REFERENCES dbo.ct_tercero(id_tercero)
    );
END;
GO

/* =========================
   FKs cruzadas con predial
   ========================= */

IF OBJECT_ID('dbo.ct_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_estado_terreno_comercial', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_terreno_estado_comercial')
BEGIN
    ALTER TABLE dbo.ct_terreno
    WITH CHECK ADD CONSTRAINT FK_ct_terreno_estado_comercial
        FOREIGN KEY (id_estado_comercial) REFERENCES dbo.ct_estado_terreno_comercial(id_estado_comercial);

    ALTER TABLE dbo.ct_terreno
    CHECK CONSTRAINT FK_ct_terreno_estado_comercial;
END;
GO

IF OBJECT_ID('dbo.ct_historial_estado_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_venta_terreno', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_historial_estado_terreno_venta')
BEGIN
    ALTER TABLE dbo.ct_historial_estado_terreno
    WITH CHECK ADD CONSTRAINT FK_ct_historial_estado_terreno_venta
        FOREIGN KEY (id_venta) REFERENCES dbo.ct_venta_terreno(id_venta);

    ALTER TABLE dbo.ct_historial_estado_terreno
    CHECK CONSTRAINT FK_ct_historial_estado_terreno_venta;
END;
GO

/* Usuario(REF) corporativo: dbo.cr_usuarios */
IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.cr_usuarios', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_tasacion_terreno_cr_usuarios')
BEGIN
    IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id_usuario'
    )
    BEGIN
        ALTER TABLE dbo.ct_tasacion_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_tasacion_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'id'
    )
    BEGIN
        ALTER TABLE dbo.ct_tasacion_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_tasacion_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);
    END
    ELSE IF EXISTS (
        SELECT 1 FROM sys.columns
        WHERE object_id = OBJECT_ID('dbo.cr_usuarios') AND name = 'Id'
    )
    BEGIN
        ALTER TABLE dbo.ct_tasacion_terreno
        WITH CHECK ADD CONSTRAINT FK_ct_tasacion_terreno_cr_usuarios
            FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);
    END
END;
GO

IF EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_tasacion_terreno_cr_usuarios')
BEGIN
    ALTER TABLE dbo.ct_tasacion_terreno
    CHECK CONSTRAINT FK_ct_tasacion_terreno_cr_usuarios;
END;
GO

IF OBJECT_ID('dbo.cr_usuarios', 'U') IS NULL
BEGIN
    PRINT 'Aviso: no existe dbo.cr_usuarios; FK de ct_tasacion_terreno.id_usuario queda pendiente.';
END;
GO

/* Entidad_Financiera(REF) definida en esta capa */
IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_entidad_financiera', 'U') IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_tasacion_terreno_entidad_financiera')
BEGIN
    ALTER TABLE dbo.ct_tasacion_terreno
    WITH CHECK ADD CONSTRAINT FK_ct_tasacion_terreno_entidad_financiera
        FOREIGN KEY (id_entidad_financiera) REFERENCES dbo.ct_entidad_financiera(id_entidad_financiera);

    ALTER TABLE dbo.ct_tasacion_terreno
    CHECK CONSTRAINT FK_ct_tasacion_terreno_entidad_financiera;
END;
GO

IF OBJECT_ID('dbo.ct_tasacion_terreno', 'U') IS NOT NULL
   AND OBJECT_ID('dbo.ct_entidad_financiera', 'U') IS NULL
BEGIN
    PRINT 'Aviso: no existe dbo.ct_entidad_financiera; FK de ct_tasacion_terreno.id_entidad_financiera queda pendiente.';
END;
GO

/* =========================
   Indices contabilidad
   ========================= */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_tasacion_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_tasacion_terreno'))
    CREATE INDEX IX_ct_tasacion_terreno_terreno ON dbo.ct_tasacion_terreno(id_terreno, fecha_tasacion DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_usufructo_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_usufructo_terreno'))
    CREATE INDEX IX_ct_usufructo_terreno_terreno ON dbo.ct_usufructo_terreno(id_terreno);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_hipoteca_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_hipoteca_terreno'))
    CREATE INDEX IX_ct_hipoteca_terreno_terreno ON dbo.ct_hipoteca_terreno(id_terreno, fecha_constitucion DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_venta_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_venta_terreno'))
    CREATE INDEX IX_ct_venta_terreno_terreno ON dbo.ct_venta_terreno(id_terreno, fecha_venta DESC);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_venta_terreno_tercero_venta' AND object_id = OBJECT_ID('dbo.ct_venta_terreno_tercero'))
    CREATE INDEX IX_ct_venta_terreno_tercero_venta ON dbo.ct_venta_terreno_tercero(id_venta);
GO

PRINT 'Capa contabilidad creada/validada correctamente.';
GO
