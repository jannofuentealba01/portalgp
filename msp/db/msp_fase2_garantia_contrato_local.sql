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
