/*
===========================================================================
 MSP - FASE 1: CONTRATO_LOCALES
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Requiere tablas base de A1 y de deudores/garantia ya instaladas
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. TABLA NUEVA: msp_contrato_locales
   Estado relacion:
     1 = Activa
     2 = Finalizada
     3 = Anulada
   ========================================================================= */

IF OBJECT_ID('dbo.msp_contrato_locales', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_contrato_locales (
        id_contrato_local      INT IDENTITY(1,1) NOT NULL,
        id_contrato_arriendo   INT NOT NULL,
        id_local               INT NOT NULL,
        fecha_inicio           DATE NOT NULL,
        fecha_termino          DATE NULL,
        orden_visual           INT NOT NULL CONSTRAINT DF_msp_contrato_locales_orden DEFAULT (1),
        estado_relacion        TINYINT NOT NULL CONSTRAINT DF_msp_contrato_locales_estado DEFAULT (1),
        monto_arriendo_local   DECIMAL(18,2) NULL,
        observaciones          NVARCHAR(500) NULL,
        fecha_registro         DATETIME2(0) NOT NULL CONSTRAINT DF_msp_contrato_locales_fecha_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_contrato_locales PRIMARY KEY (id_contrato_local),
        CONSTRAINT FK_msp_contrato_locales_contrato
            FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
        CONSTRAINT FK_msp_contrato_locales_local
            FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
        CONSTRAINT CK_msp_contrato_locales_estado
            CHECK (estado_relacion IN (1,2,3)),
        CONSTRAINT CK_msp_contrato_locales_fechas
            CHECK (fecha_termino IS NULL OR fecha_termino >= fecha_inicio),
        CONSTRAINT CK_msp_contrato_locales_monto
            CHECK (monto_arriendo_local IS NULL OR monto_arriendo_local >= 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'UX_msp_contrato_locales_contrato_local_inicio'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_contrato_locales_contrato_local_inicio
        ON dbo.msp_contrato_locales (id_contrato_arriendo, id_local, fecha_inicio);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'IX_msp_contrato_locales_local_fechas'
)
BEGIN
    CREATE INDEX IX_msp_contrato_locales_local_fechas
        ON dbo.msp_contrato_locales (id_local, fecha_inicio, fecha_termino, estado_relacion);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contrato_locales')
      AND name = 'IX_msp_contrato_locales_contrato_estado'
)
BEGIN
    CREATE INDEX IX_msp_contrato_locales_contrato_estado
        ON dbo.msp_contrato_locales (id_contrato_arriendo, estado_relacion, orden_visual, id_contrato_local);
END;
GO

/* =========================================================================
   2. TRIGGER: NO SOLAPAMIENTO DE LOCALES ENTRE CONTRATOS ACTIVOS

   Regla de Fase 1:
   - Bloquear solapamiento del mismo local cuando ambas filas estan activas
     (estado_relacion = 1) y ambos contratos estan en estado operativo
     (1 = Borrador, 2 = Vigente).
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_contrato_locales_no_solapamiento
ON dbo.msp_contrato_locales
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_contrato_locales cl
        INNER JOIN inserted i
            ON cl.id_local = i.id_local
           AND cl.id_contrato_local <> i.id_contrato_local
           AND i.fecha_inicio <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
           AND cl.fecha_inicio <= ISNULL(i.fecha_termino, CONVERT(date, '9999-12-31'))
        INNER JOIN dbo.msp_contratos_arriendo c1
            ON c1.id_contrato_arriendo = i.id_contrato_arriendo
        INNER JOIN dbo.msp_contratos_arriendo c2
            ON c2.id_contrato_arriendo = cl.id_contrato_arriendo
        WHERE i.estado_relacion = 1
          AND cl.estado_relacion = 1
          AND c1.estado_contrato IN (1,2)
          AND c2.estado_contrato IN (1,2)
    )
    BEGIN
        ;THROW 50401, 'No se puede solapar el mismo local en contratos activos.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i1
        INNER JOIN inserted i2
            ON i1.id_local = i2.id_local
           AND i1.id_contrato_local <> i2.id_contrato_local
           AND i1.fecha_inicio <= ISNULL(i2.fecha_termino, CONVERT(date, '9999-12-31'))
           AND i2.fecha_inicio <= ISNULL(i1.fecha_termino, CONVERT(date, '9999-12-31'))
        INNER JOIN dbo.msp_contratos_arriendo c1
            ON c1.id_contrato_arriendo = i1.id_contrato_arriendo
        INNER JOIN dbo.msp_contratos_arriendo c2
            ON c2.id_contrato_arriendo = i2.id_contrato_arriendo
        WHERE i1.estado_relacion = 1
          AND i2.estado_relacion = 1
          AND c1.estado_contrato IN (1,2)
          AND c2.estado_contrato IN (1,2)
    )
    BEGIN
        ;THROW 50402, 'El lote contiene locales solapados en contratos activos.', 1;
    END;
END;
GO

/* =========================================================================
   3. MIGRACION INICIAL
   Fuente:
   - msp_ocupacion_locales + contrato activo por tienda

   Regla:
   - Se inserta solo si no existe ya la misma combinacion
     (contrato, local, fecha_inicio).
   - orden_visual inicial basado en codigo local para cada contrato.
   ========================================================================= */

;WITH base AS (
    SELECT
        c.id_contrato_arriendo,
        ol.id_local,
        ol.fecha_inicio,
        ol.fecha_termino,
        ROW_NUMBER() OVER (
            PARTITION BY c.id_contrato_arriendo
            ORDER BY ml.cdo_local, ol.fecha_inicio, ol.id_ocupacion_local
        ) AS orden_visual
    FROM dbo.msp_contratos_arriendo c
    INNER JOIN dbo.msp_ocupacion_locales ol
        ON ol.id_tienda = c.id_tienda
    INNER JOIN dbo.msp_locales ml
        ON ml.id_local = ol.id_local
    WHERE c.estado_contrato IN (1,2)
)
INSERT INTO dbo.msp_contrato_locales (
    id_contrato_arriendo,
    id_local,
    fecha_inicio,
    fecha_termino,
    orden_visual,
    estado_relacion,
    monto_arriendo_local,
    observaciones
)
SELECT
    b.id_contrato_arriendo,
    b.id_local,
    b.fecha_inicio,
    b.fecha_termino,
    b.orden_visual,
    CASE WHEN b.fecha_termino IS NULL THEN 1 ELSE 2 END AS estado_relacion,
    NULL AS monto_arriendo_local,
    N'Migrado desde msp_ocupacion_locales (Fase 1)' AS observaciones
FROM base b
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.msp_contrato_locales cl
    WHERE cl.id_contrato_arriendo = b.id_contrato_arriendo
      AND cl.id_local = b.id_local
      AND cl.fecha_inicio = b.fecha_inicio
);
GO

/* =========================================================================
   4. REORDENAR orden_visual EN CONTRATOS CON DATOS YA EXISTENTES
   ========================================================================= */

;WITH orden AS (
    SELECT
        cl.id_contrato_local,
        ROW_NUMBER() OVER (
            PARTITION BY cl.id_contrato_arriendo
            ORDER BY ml.cdo_local, cl.fecha_inicio, cl.id_contrato_local
        ) AS nuevo_orden
    FROM dbo.msp_contrato_locales cl
    INNER JOIN dbo.msp_locales ml
        ON ml.id_local = cl.id_local
)
UPDATE cl
SET cl.orden_visual = o.nuevo_orden
FROM dbo.msp_contrato_locales cl
INNER JOIN orden o
    ON o.id_contrato_local = cl.id_contrato_local
WHERE cl.orden_visual <> o.nuevo_orden;
GO

/* =========================================================================
   5. VISTA DE APOYO (FASE 1)
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_contrato_locales_activos
AS
SELECT
    cl.id_contrato_local,
    cl.id_contrato_arriendo,
    cl.id_local,
    cl.fecha_inicio,
    cl.fecha_termino,
    cl.orden_visual,
    cl.estado_relacion,
    cl.monto_arriendo_local,
    c.id_tienda,
    c.id_arrendatario,
    c.estado_contrato
FROM dbo.msp_contrato_locales cl
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = cl.id_contrato_arriendo
WHERE cl.estado_relacion = 1
  AND c.estado_contrato IN (1,2);
GO

/* =========================================================================
   6. VALIDACIONES DE CONTROL POST-MIGRACION
   ========================================================================= */

PRINT 'Fase 1 aplicada: msp_contrato_locales creada y poblada.';
GO
