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
