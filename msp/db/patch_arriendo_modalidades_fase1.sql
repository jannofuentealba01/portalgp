/*
===========================================================================
 PATCH: arriendo modalidades fase 1
 - Crea catalogos y tablas base para arriendo por contrato-local.
 - Carga reglas default desde msp_locales / msp_contrato_locales.
 - Deja preparada regla de grupo OBRA+MODULAR = 280000 neto mensual.
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_contrato_locales', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_locales', N'U') IS NULL
BEGIN
    PRINT 'patch_arriendo_modalidades_fase1: faltan tablas base (msp_contrato_locales/msp_locales). Se omite.';
END
ELSE
BEGIN
    /* ================================================================
       1) CATALOGOS
       ================================================================ */
    IF OBJECT_ID(N'dbo.msp_tipo_modalidad_arriendo', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_tipo_modalidad_arriendo (
            id_modalidad_arriendo      TINYINT NOT NULL,
            codigo_modalidad           NVARCHAR(30) NOT NULL,
            nombre_modalidad           NVARCHAR(100) NOT NULL,
            requiere_valor_uf          BIT NOT NULL CONSTRAINT DF_msp_tipo_modalidad_req_uf DEFAULT (0),
            requiere_valor_clp         BIT NOT NULL CONSTRAINT DF_msp_tipo_modalidad_req_clp DEFAULT (0),
            requiere_valor_periodo     BIT NOT NULL CONSTRAINT DF_msp_tipo_modalidad_req_periodo DEFAULT (0),
            activo                     BIT NOT NULL CONSTRAINT DF_msp_tipo_modalidad_activo DEFAULT (1),
            CONSTRAINT PK_msp_tipo_modalidad_arriendo PRIMARY KEY (id_modalidad_arriendo),
            CONSTRAINT UQ_msp_tipo_modalidad_arriendo_codigo UNIQUE (codigo_modalidad)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_tipo_modalidad_arriendo WHERE id_modalidad_arriendo = 1)
    BEGIN
        INSERT INTO dbo.msp_tipo_modalidad_arriendo (
            id_modalidad_arriendo,
            codigo_modalidad,
            nombre_modalidad,
            requiere_valor_uf,
            requiere_valor_clp,
            requiere_valor_periodo
        )
        VALUES (1, N'UF_ESTATICO', N'UF estatico', 1, 0, 0);
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_tipo_modalidad_arriendo WHERE id_modalidad_arriendo = 2)
    BEGIN
        INSERT INTO dbo.msp_tipo_modalidad_arriendo (
            id_modalidad_arriendo,
            codigo_modalidad,
            nombre_modalidad,
            requiere_valor_uf,
            requiere_valor_clp,
            requiere_valor_periodo
        )
        VALUES (2, N'DINAMICO_MENSUAL', N'Dinamico mensual', 0, 0, 1);
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_tipo_modalidad_arriendo WHERE id_modalidad_arriendo = 3)
    BEGIN
        INSERT INTO dbo.msp_tipo_modalidad_arriendo (
            id_modalidad_arriendo,
            codigo_modalidad,
            nombre_modalidad,
            requiere_valor_uf,
            requiere_valor_clp,
            requiere_valor_periodo
        )
        VALUES (3, N'CLP_FIJO', N'CLP fijo', 0, 1, 0);
    END;

    IF OBJECT_ID(N'dbo.msp_tipo_descuento_arriendo', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_tipo_descuento_arriendo (
            id_tipo_descuento_arriendo TINYINT NOT NULL,
            codigo_descuento           NVARCHAR(30) NOT NULL,
            nombre_descuento           NVARCHAR(100) NOT NULL,
            activo                     BIT NOT NULL CONSTRAINT DF_msp_tipo_descuento_arriendo_activo DEFAULT (1),
            CONSTRAINT PK_msp_tipo_descuento_arriendo PRIMARY KEY (id_tipo_descuento_arriendo),
            CONSTRAINT UQ_msp_tipo_descuento_arriendo_codigo UNIQUE (codigo_descuento)
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_tipo_descuento_arriendo WHERE id_tipo_descuento_arriendo = 1)
    BEGIN
        INSERT INTO dbo.msp_tipo_descuento_arriendo (
            id_tipo_descuento_arriendo,
            codigo_descuento,
            nombre_descuento
        )
        VALUES (1, N'MONTO_CLP_MENSUAL', N'Descuento mensual por monto CLP');
    END;

    IF OBJECT_ID(N'dbo.msp_grupo_arriendo_especial', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_grupo_arriendo_especial (
            codigo_grupo               NVARCHAR(30) NOT NULL,
            descripcion_grupo          NVARCHAR(200) NOT NULL,
            monto_neto_mensual_clp     DECIMAL(18,2) NOT NULL,
            cod_local_requerido_1      NVARCHAR(20) NULL,
            cod_local_requerido_2      NVARCHAR(20) NULL,
            estado_grupo               TINYINT NOT NULL CONSTRAINT DF_msp_grupo_arriendo_especial_estado DEFAULT (1),
            fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_grupo_arriendo_especial_fecha DEFAULT (SYSDATETIME()),
            CONSTRAINT PK_msp_grupo_arriendo_especial PRIMARY KEY (codigo_grupo),
            CONSTRAINT CK_msp_grupo_arriendo_especial_monto CHECK (monto_neto_mensual_clp > 0),
            CONSTRAINT CK_msp_grupo_arriendo_especial_estado CHECK (estado_grupo IN (1, 2))
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_grupo_arriendo_especial WHERE codigo_grupo = N'OBRA_MODULAR')
    BEGIN
        INSERT INTO dbo.msp_grupo_arriendo_especial (
            codigo_grupo,
            descripcion_grupo,
            monto_neto_mensual_clp,
            cod_local_requerido_1,
            cod_local_requerido_2,
            estado_grupo
        )
        VALUES (
            N'OBRA_MODULAR',
            N'Grupo especial OBRA + MODULAR (monto neto mensual conjunto).',
            280000,
            N'OBRA',
            N'MODULAR',
            1
        );
    END;

    IF NOT EXISTS (SELECT 1 FROM dbo.msp_grupo_arriendo_especial WHERE codigo_grupo = N'CLP_FIJO_CONTRATO')
    BEGIN
        INSERT INTO dbo.msp_grupo_arriendo_especial (
            codigo_grupo,
            descripcion_grupo,
            monto_neto_mensual_clp,
            cod_local_requerido_1,
            cod_local_requerido_2,
            estado_grupo
        )
        VALUES (
            N'CLP_FIJO_CONTRATO',
            N'Modalidad CLP fijo a nivel contrato (monto unico, no prorrateado por local).',
            1,
            NULL,
            NULL,
            1
        );
    END;

    /* ================================================================
       2) REGLAS POR CONTRATO-LOCAL
       ================================================================ */
    IF OBJECT_ID(N'dbo.msp_contrato_local_arriendo_regla', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_contrato_local_arriendo_regla (
            id_regla_arriendo              INT IDENTITY(1,1) NOT NULL,
            id_contrato_local              INT NOT NULL,
            fecha_inicio                   DATE NOT NULL,
            fecha_termino                  DATE NULL,
            id_modalidad_arriendo          TINYINT NOT NULL,
            valor_base_uf                  DECIMAL(18,6) NULL,
            valor_base_clp                 DECIMAL(18,2) NULL,
            id_tipo_descuento_arriendo     TINYINT NULL,
            descuento_mensual_clp          DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_cl_arriendo_regla_desc DEFAULT (0),
            codigo_grupo_modalidad         NVARCHAR(30) NULL,
            prioridad                      INT NOT NULL CONSTRAINT DF_msp_cl_arriendo_regla_pri DEFAULT (100),
            estado_regla                   TINYINT NOT NULL CONSTRAINT DF_msp_cl_arriendo_regla_estado DEFAULT (1),
            es_default                     BIT NOT NULL CONSTRAINT DF_msp_cl_arriendo_regla_default DEFAULT (0),
            observaciones                  NVARCHAR(500) NULL,
            fecha_registro                 DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cl_arriendo_regla_fecha DEFAULT (SYSDATETIME()),
            fecha_actualizacion            DATETIME2(0) NULL,
            CONSTRAINT PK_msp_contrato_local_arriendo_regla PRIMARY KEY (id_regla_arriendo),
            CONSTRAINT FK_msp_cl_arriendo_regla_contrato_local
                FOREIGN KEY (id_contrato_local) REFERENCES dbo.msp_contrato_locales (id_contrato_local),
            CONSTRAINT FK_msp_cl_arriendo_regla_modalidad
                FOREIGN KEY (id_modalidad_arriendo) REFERENCES dbo.msp_tipo_modalidad_arriendo (id_modalidad_arriendo),
            CONSTRAINT FK_msp_cl_arriendo_regla_tipo_desc
                FOREIGN KEY (id_tipo_descuento_arriendo) REFERENCES dbo.msp_tipo_descuento_arriendo (id_tipo_descuento_arriendo),
            CONSTRAINT FK_msp_cl_arriendo_regla_grupo
                FOREIGN KEY (codigo_grupo_modalidad) REFERENCES dbo.msp_grupo_arriendo_especial (codigo_grupo),
            CONSTRAINT CK_msp_cl_arriendo_regla_fechas
                CHECK (fecha_termino IS NULL OR fecha_termino >= fecha_inicio),
            CONSTRAINT CK_msp_cl_arriendo_regla_modalidad_valores
                CHECK (
                    (id_modalidad_arriendo = 1 AND valor_base_uf IS NOT NULL AND valor_base_clp IS NULL)
                    OR
                    (id_modalidad_arriendo = 2 AND valor_base_uf IS NULL AND valor_base_clp IS NULL)
                    OR
                    (id_modalidad_arriendo = 3 AND valor_base_uf IS NULL AND valor_base_clp IS NOT NULL)
                ),
            CONSTRAINT CK_msp_cl_arriendo_regla_descuento
                CHECK (
                    descuento_mensual_clp >= 0
                    AND (
                        (id_tipo_descuento_arriendo IS NULL AND descuento_mensual_clp = 0)
                        OR (id_tipo_descuento_arriendo IS NOT NULL)
                    )
                ),
            CONSTRAINT CK_msp_cl_arriendo_regla_estado
                CHECK (estado_regla IN (1, 2, 3)),
            CONSTRAINT CK_msp_cl_arriendo_regla_grupo_modalidad
                CHECK (codigo_grupo_modalidad IS NULL OR id_modalidad_arriendo = 3)
        );
    END;

    IF COL_LENGTH('dbo.msp_contrato_local_arriendo_regla', 'fecha_actualizacion') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_contrato_local_arriendo_regla
        ADD fecha_actualizacion DATETIME2(0) NULL;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_contrato_local_arriendo_regla', N'U')
          AND name = N'IX_msp_cl_arriendo_regla_local_vigencia'
    )
    BEGIN
        CREATE INDEX IX_msp_cl_arriendo_regla_local_vigencia
            ON dbo.msp_contrato_local_arriendo_regla (id_contrato_local, estado_regla, fecha_inicio, fecha_termino, prioridad DESC, id_regla_arriendo DESC);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_contrato_local_arriendo_regla', N'U')
          AND name = N'UX_msp_cl_arriendo_regla_default'
    )
    BEGIN
        CREATE UNIQUE INDEX UX_msp_cl_arriendo_regla_default
            ON dbo.msp_contrato_local_arriendo_regla (id_contrato_local)
            WHERE es_default = 1 AND estado_regla = 1;
    END;

    /* ================================================================
       3) VALORES DINAMICOS POR PERIODO
       ================================================================ */
    IF OBJECT_ID(N'dbo.msp_contrato_local_arriendo_periodo', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_contrato_local_arriendo_periodo (
            id_arriendo_periodo            INT IDENTITY(1,1) NOT NULL,
            id_contrato_local              INT NOT NULL,
            periodo_facturacion            DATE NOT NULL,
            valor_periodo_uf               DECIMAL(18,6) NULL,
            valor_periodo_clp              DECIMAL(18,2) NULL,
            descuento_periodo_clp          DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_cl_arriendo_periodo_desc DEFAULT (0),
            origen_carga                   TINYINT NOT NULL CONSTRAINT DF_msp_cl_arriendo_periodo_origen DEFAULT (1),
            estado_periodo                 TINYINT NOT NULL CONSTRAINT DF_msp_cl_arriendo_periodo_estado DEFAULT (1),
            observaciones                  NVARCHAR(500) NULL,
            fecha_registro                 DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cl_arriendo_periodo_fecha DEFAULT (SYSDATETIME()),
            fecha_actualizacion            DATETIME2(0) NULL,
            CONSTRAINT PK_msp_contrato_local_arriendo_periodo PRIMARY KEY (id_arriendo_periodo),
            CONSTRAINT FK_msp_cl_arriendo_periodo_contrato_local
                FOREIGN KEY (id_contrato_local) REFERENCES dbo.msp_contrato_locales (id_contrato_local),
            CONSTRAINT UQ_msp_cl_arriendo_periodo UNIQUE (id_contrato_local, periodo_facturacion),
            CONSTRAINT CK_msp_cl_arriendo_periodo_mes CHECK (DAY(periodo_facturacion) = 1),
            CONSTRAINT CK_msp_cl_arriendo_periodo_valores CHECK (
                (valor_periodo_uf IS NULL OR valor_periodo_uf >= 0)
                AND (valor_periodo_clp IS NULL OR valor_periodo_clp >= 0)
                AND descuento_periodo_clp >= 0
            ),
            CONSTRAINT CK_msp_cl_arriendo_periodo_origen CHECK (origen_carga IN (1, 2)),
            CONSTRAINT CK_msp_cl_arriendo_periodo_estado CHECK (estado_periodo IN (1, 2))
        );
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_contrato_local_arriendo_periodo', N'U')
          AND name = N'IX_msp_cl_arriendo_periodo_periodo'
    )
    BEGIN
        CREATE INDEX IX_msp_cl_arriendo_periodo_periodo
            ON dbo.msp_contrato_local_arriendo_periodo (periodo_facturacion, estado_periodo, id_contrato_local);
    END;

    /* ================================================================
       4) SNAPSHOT DE CALCULO MENSUAL
       ================================================================ */
    IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_arriendo_local_snapshot_periodo (
            id_snapshot_arriendo           INT IDENTITY(1,1) NOT NULL,
            periodo_facturacion            DATE NOT NULL,
            id_tienda                      INT NOT NULL,
            id_contrato_arriendo           INT NOT NULL,
            id_contrato_local              INT NOT NULL,
            id_local                       INT NOT NULL,
            id_regla_arriendo              INT NULL,
            id_modalidad_aplicada          TINYINT NOT NULL,
            valor_base_uf                  DECIMAL(18,6) NULL,
            valor_base_clp                 DECIMAL(18,2) NULL,
            valor_uf_periodo               DECIMAL(18,6) NULL,
            descuento_aplicado_clp         DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_desc DEFAULT (0),
            monto_neto_clp                 DECIMAL(18,2) NOT NULL,
            monto_iva_clp                  DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_iva DEFAULT (0),
            monto_total_clp                DECIMAL(18,2) NOT NULL,
            codigo_grupo_modalidad         NVARCHAR(30) NULL,
            estado_snapshot                TINYINT NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_estado DEFAULT (1),
            es_congelado                   BIT NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_congelado DEFAULT (0),
            fuente_calculo                 NVARCHAR(50) NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_fuente DEFAULT (N'NA'),
            formula_json                   NVARCHAR(MAX) NULL,
            detalle_calculo                NVARCHAR(1000) NULL,
            fecha_registro                 DATETIME2(0) NOT NULL CONSTRAINT DF_msp_snapshot_arriendo_fecha DEFAULT (SYSDATETIME()),
            fecha_actualizacion            DATETIME2(0) NULL,
            CONSTRAINT PK_msp_arriendo_local_snapshot_periodo PRIMARY KEY (id_snapshot_arriendo),
            CONSTRAINT UQ_msp_snapshot_arriendo UNIQUE (periodo_facturacion, id_contrato_local),
            CONSTRAINT FK_msp_snapshot_arriendo_tienda
                FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
            CONSTRAINT FK_msp_snapshot_arriendo_contrato
                FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
            CONSTRAINT FK_msp_snapshot_arriendo_contrato_local
                FOREIGN KEY (id_contrato_local) REFERENCES dbo.msp_contrato_locales (id_contrato_local),
            CONSTRAINT FK_msp_snapshot_arriendo_local
                FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
            CONSTRAINT FK_msp_snapshot_arriendo_regla
                FOREIGN KEY (id_regla_arriendo) REFERENCES dbo.msp_contrato_local_arriendo_regla (id_regla_arriendo),
            CONSTRAINT FK_msp_snapshot_arriendo_modalidad
                FOREIGN KEY (id_modalidad_aplicada) REFERENCES dbo.msp_tipo_modalidad_arriendo (id_modalidad_arriendo),
            CONSTRAINT FK_msp_snapshot_arriendo_grupo
                FOREIGN KEY (codigo_grupo_modalidad) REFERENCES dbo.msp_grupo_arriendo_especial (codigo_grupo),
            CONSTRAINT CK_msp_snapshot_arriendo_mes CHECK (DAY(periodo_facturacion) = 1),
            CONSTRAINT CK_msp_snapshot_arriendo_montos CHECK (
                descuento_aplicado_clp >= 0
                AND monto_neto_clp >= 0
                AND monto_iva_clp >= 0
                AND monto_total_clp >= 0
            ),
            CONSTRAINT CK_msp_snapshot_arriendo_estado CHECK (estado_snapshot IN (1, 2, 3))
        );
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U')
          AND name = N'IX_msp_snapshot_arriendo_periodo_tienda'
    )
    BEGIN
        CREATE INDEX IX_msp_snapshot_arriendo_periodo_tienda
            ON dbo.msp_arriendo_local_snapshot_periodo (periodo_facturacion, id_tienda, estado_snapshot, es_congelado)
            INCLUDE (id_contrato_local, id_local, monto_neto_clp, monto_total_clp, codigo_grupo_modalidad);
    END;

    /* ================================================================
       5) BACKFILL REGLAS DEFAULT
       ================================================================ */
    DECLARE @reglas_insertadas INT = 0;
    DECLARE @reglas_grupo_actualizadas INT = 0;

    ;WITH base AS (
        SELECT
            cl.id_contrato_local,
            cl.fecha_inicio,
            cl.fecha_termino,
            UPPER(LTRIM(RTRIM(ISNULL(l.cdo_local, N'')))) AS cdo_local_key,
            CAST(ISNULL(l.valor_arriendo_uf, 0) AS DECIMAL(18,6)) AS valor_arriendo_uf
        FROM dbo.msp_contrato_locales cl
        INNER JOIN dbo.msp_locales l
            ON l.id_local = cl.id_local
    )
    INSERT INTO dbo.msp_contrato_local_arriendo_regla (
        id_contrato_local,
        fecha_inicio,
        fecha_termino,
        id_modalidad_arriendo,
        valor_base_uf,
        valor_base_clp,
        id_tipo_descuento_arriendo,
        descuento_mensual_clp,
        codigo_grupo_modalidad,
        prioridad,
        estado_regla,
        es_default,
        observaciones
    )
    SELECT
        b.id_contrato_local,
        b.fecha_inicio,
        b.fecha_termino,
        CASE WHEN b.cdo_local_key IN (N'OBRA', N'MODULAR') THEN 3 ELSE 1 END AS id_modalidad_arriendo,
        CASE WHEN b.cdo_local_key IN (N'OBRA', N'MODULAR') THEN NULL ELSE b.valor_arriendo_uf END AS valor_base_uf,
        CASE WHEN b.cdo_local_key IN (N'OBRA', N'MODULAR') THEN CAST(140000 AS DECIMAL(18,2)) ELSE NULL END AS valor_base_clp,
        NULL AS id_tipo_descuento_arriendo,
        CAST(0 AS DECIMAL(18,2)) AS descuento_mensual_clp,
        CASE WHEN b.cdo_local_key IN (N'OBRA', N'MODULAR') THEN N'OBRA_MODULAR' ELSE NULL END AS codigo_grupo_modalidad,
        100 AS prioridad,
        1 AS estado_regla,
        1 AS es_default,
        CASE
            WHEN b.cdo_local_key IN (N'OBRA', N'MODULAR')
                THEN N'Backfill Fase 1: regla default CLP (grupo OBRA_MODULAR).'
            ELSE N'Backfill Fase 1: regla default UF estatico desde msp_locales.'
        END AS observaciones
    FROM base b
    WHERE NOT EXISTS (
        SELECT 1
        FROM dbo.msp_contrato_local_arriendo_regla r
        WHERE r.id_contrato_local = b.id_contrato_local
          AND r.es_default = 1
    );

    SET @reglas_insertadas = @@ROWCOUNT;

    UPDATE r
    SET r.codigo_grupo_modalidad = N'OBRA_MODULAR',
        r.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_contrato_local_arriendo_regla r
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_local = r.id_contrato_local
    INNER JOIN dbo.msp_locales l
        ON l.id_local = cl.id_local
    WHERE r.es_default = 1
      AND r.id_modalidad_arriendo = 3
      AND r.codigo_grupo_modalidad IS NULL
      AND UPPER(LTRIM(RTRIM(ISNULL(l.cdo_local, N'')))) IN (N'OBRA', N'MODULAR');

    SET @reglas_grupo_actualizadas = @@ROWCOUNT;

    PRINT 'patch_arriendo_modalidades_fase1: reglas default insertadas = ' + CAST(@reglas_insertadas AS NVARCHAR(20));
    PRINT 'patch_arriendo_modalidades_fase1: reglas grupo OBRA_MODULAR ajustadas = ' + CAST(@reglas_grupo_actualizadas AS NVARCHAR(20));
END;
GO

PRINT 'patch_arriendo_modalidades_fase1 aplicado.';
GO
