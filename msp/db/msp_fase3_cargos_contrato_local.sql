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
