/*
  Script: modulo_terrenos_sqlserver_integridad.sql
  Objetivo:
  - Materializar reglas de integridad de negocio en SQL Server
  - Endurecer trazabilidad en ventas, avaluos y movimientos prediales
  - Mantener idempotencia para ejecucion repetible

  Nota:
  - Asume que las tablas del modelo base ya existen en schema dbo.
  - Asume que los valores de "enum" se almacenan como texto (nvarchar/varchar):
      estado venta: FORMALIZADA
      estado movimiento: CONFIRMADO
      tipo movimiento: SUBDIVISION, FUSION, VENTA
      rol en movimiento: ORIGEN, RESULTADO, AFECTADO
      estado ficha: VIGENTE
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

/* ================================
   CHECK constraints basicos
   ================================ */

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_terreno_avaluo_semestre_1_2'
      AND parent_object_id = OBJECT_ID('dbo.ct_terreno_avaluo')
)
BEGIN
    ALTER TABLE dbo.ct_terreno_avaluo
    ADD CONSTRAINT CK_terreno_avaluo_semestre_1_2
    CHECK (semestre IN (1, 2));
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_terreno_contribucion_cuota_1_4'
      AND parent_object_id = OBJECT_ID('dbo.ct_terreno_contribucion')
)
BEGIN
    ALTER TABLE dbo.ct_terreno_contribucion
    ADD CONSTRAINT CK_terreno_contribucion_cuota_1_4
    CHECK (cuota BETWEEN 1 AND 4);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_terreno_venta_cliente_porcentaje_rango'
      AND parent_object_id = OBJECT_ID('dbo.ct_terreno_venta_cliente')
)
BEGIN
    ALTER TABLE dbo.ct_terreno_venta_cliente
    ADD CONSTRAINT CK_terreno_venta_cliente_porcentaje_rango
    CHECK (porcentaje_derecho > 0 AND porcentaje_derecho <= 100);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_terreno_avaluo_total_consistente'
      AND parent_object_id = OBJECT_ID('dbo.ct_terreno_avaluo')
)
BEGIN
    ALTER TABLE dbo.ct_terreno_avaluo
    ADD CONSTRAINT CK_terreno_avaluo_total_consistente
    CHECK (
        avaluo_total IS NULL
        OR (
            avaluo_terreno IS NOT NULL
            AND avaluo_construcciones IS NOT NULL
            AND avaluo_total = (avaluo_terreno + avaluo_construcciones)
        )
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = 'CK_terreno_avaluo_afecto_consistente'
      AND parent_object_id = OBJECT_ID('dbo.ct_terreno_avaluo')
)
BEGIN
    ALTER TABLE dbo.ct_terreno_avaluo
    ADD CONSTRAINT CK_terreno_avaluo_afecto_consistente
    CHECK (
        avaluo_afecto IS NULL
        OR (
            avaluo_total IS NOT NULL
            AND avaluo_exento IS NOT NULL
            AND avaluo_afecto = (avaluo_total - avaluo_exento)
        )
    );
END;
GO

/* ================================
   Unicidad "vigente"/"oficial"
   via filtered indexes
   ================================ */

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_terreno_valor_comercial_vigente'
      AND object_id = OBJECT_ID('dbo.ct_terreno_valor_comercial')
)
BEGIN
    CREATE UNIQUE INDEX UX_terreno_valor_comercial_vigente
        ON dbo.ct_terreno_valor_comercial (id_terreno)
        WHERE vigente = 1;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_terreno_tasacion_mejor'
      AND object_id = OBJECT_ID('dbo.ct_terreno_tasacion')
)
BEGIN
    CREATE UNIQUE INDEX UX_terreno_tasacion_mejor
        ON dbo.ct_terreno_tasacion (id_terreno)
        WHERE mejor_tasacion = 1;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_terreno_ficha_vigente'
      AND object_id = OBJECT_ID('dbo.ct_terreno_ficha')
)
BEGIN
    CREATE UNIQUE INDEX UX_terreno_ficha_vigente
        ON dbo.ct_terreno_ficha (id_terreno)
        WHERE estado = 'VIGENTE';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_terreno_avaluo_oficial_periodo'
      AND object_id = OBJECT_ID('dbo.ct_terreno_avaluo')
)
BEGIN
    CREATE UNIQUE INDEX UX_terreno_avaluo_oficial_periodo
        ON dbo.ct_terreno_avaluo (id_terreno, anio, semestre)
        WHERE es_oficial = 1;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_terreno_avaluo_exencion_aplicada'
      AND object_id = OBJECT_ID('dbo.ct_terreno_avaluo_exencion')
)
BEGIN
    CREATE UNIQUE INDEX UX_terreno_avaluo_exencion_aplicada
        ON dbo.ct_terreno_avaluo_exencion (id_avaluo)
        WHERE es_aplicada = 1;
END;
GO

/* ================================
   Trigger: venta formalizada debe
   sumar 100 en porcentaje_derecho
   ================================ */

CREATE OR ALTER TRIGGER dbo.TR_terreno_venta_cliente_porcentaje_100
ON dbo.ct_terreno_venta_cliente
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    ;WITH ventas_afectadas AS (
        SELECT DISTINCT id_venta
        FROM inserted
        WHERE id_venta IS NOT NULL
        UNION
        SELECT DISTINCT id_venta
        FROM deleted
        WHERE id_venta IS NOT NULL
    ),
    ventas_formalizadas AS (
        SELECT v.id_venta
        FROM ventas_afectadas va
        INNER JOIN dbo.ct_terreno_venta v
            ON v.id_venta = va.id_venta
        WHERE v.estado = 'FORMALIZADA'
    ),
    sumas AS (
        SELECT
            vf.id_venta,
            CAST(ISNULL(SUM(tvc.porcentaje_derecho), 0.00) AS decimal(10,2)) AS total_porcentaje
        FROM ventas_formalizadas vf
        LEFT JOIN dbo.ct_terreno_venta_cliente tvc
            ON tvc.id_venta = vf.id_venta
        GROUP BY vf.id_venta
    )
    IF EXISTS (
        SELECT 1
        FROM sumas
        WHERE total_porcentaje <> 100.00
    )
    BEGIN
        THROW 50001, 'Venta FORMALIZADA invalida: porcentaje_derecho debe sumar 100.00.', 1;
    END;
END;
GO

/* ==========================================
   Trigger: al formalizar venta, validar 100
   aunque no se haya tocado venta_cliente
   ========================================== */

CREATE OR ALTER TRIGGER dbo.TR_terreno_venta_formalizada_porcentaje_100
ON dbo.ct_terreno_venta
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    ;WITH ventas_formalizadas AS (
        SELECT DISTINCT i.id_venta
        FROM inserted i
        WHERE i.estado = 'FORMALIZADA'
    ),
    sumas AS (
        SELECT
            vf.id_venta,
            CAST(ISNULL(SUM(tvc.porcentaje_derecho), 0.00) AS decimal(10,2)) AS total_porcentaje
        FROM ventas_formalizadas vf
        LEFT JOIN dbo.ct_terreno_venta_cliente tvc
            ON tvc.id_venta = vf.id_venta
        GROUP BY vf.id_venta
    )
    IF EXISTS (
        SELECT 1
        FROM sumas
        WHERE total_porcentaje <> 100.00
    )
    BEGIN
        THROW 50004, 'No se puede FORMALIZAR la venta: porcentaje_derecho debe sumar 100.00.', 1;
    END;
END;
GO

/* ================================
   Trigger: validar estructura minima
   al confirmar movimientos prediales
   ================================ */

CREATE OR ALTER TRIGGER dbo.TR_movimiento_terreno_confirmado_valida_estructura
ON dbo.ct_movimiento_terreno
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    ;WITH movimientos_confirmados AS (
        SELECT DISTINCT i.id_movimiento, i.tipo
        FROM inserted i
        WHERE i.estado = 'CONFIRMADO'
    ),
    resumen AS (
        SELECT
            mc.id_movimiento,
            mc.tipo,
            COALESCE(SUM(CASE WHEN d.rol_en_movimiento = 'ORIGEN' THEN 1 ELSE 0 END), 0)    AS cnt_origen,
            COALESCE(SUM(CASE WHEN d.rol_en_movimiento = 'RESULTADO' THEN 1 ELSE 0 END), 0) AS cnt_resultado,
            COALESCE(SUM(CASE WHEN d.rol_en_movimiento = 'AFECTADO' THEN 1 ELSE 0 END), 0)  AS cnt_afectado
        FROM movimientos_confirmados mc
        LEFT JOIN dbo.ct_movimiento_terreno_detalle d
            ON d.id_movimiento = mc.id_movimiento
        GROUP BY mc.id_movimiento, mc.tipo
    )
    IF EXISTS (
        SELECT 1
        FROM resumen r
        WHERE
            (r.tipo = 'SUBDIVISION' AND (r.cnt_origen <> 1 OR r.cnt_resultado < 2))
            OR
            (r.tipo = 'FUSION' AND (r.cnt_origen < 2 OR r.cnt_resultado <> 1))
            OR
            (r.tipo = 'VENTA' AND (r.cnt_afectado <> 1))
    )
    BEGIN
        THROW 50002, 'Movimiento CONFIRMADO invalido: estructura de ORIGEN/RESULTADO/AFECTADO no cumple reglas.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM movimientos_confirmados mc
        WHERE mc.tipo IN ('SUBDIVISION', 'FUSION')
          AND NOT EXISTS (
              SELECT 1
              FROM dbo.ct_movimiento_terreno_historial h
              WHERE h.id_movimiento = mc.id_movimiento
          )
    )
    BEGIN
        THROW 50003, 'Movimiento CONFIRMADO invalido: SUBDIVISION/FUSION requiere trazabilidad en movimiento_terreno_historial.', 1;
    END;
END;
GO
