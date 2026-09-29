/*
===============================================================================
 MSP - GARANTIA POR TIENDA / BLOQUE 1

 Objetivo
   - Crear una garantia canonica por contrato-tienda.
   - Conservar sin modificaciones las garantias y operaciones historicas por
     local.
   - Vincular cada garantia legacy con su garantia canonica.
   - Consolidar saldos y dejar controles de conciliacion para la revision final.

 Estrategia de compatibilidad
   La aplicacion vigente continua operando sobre dbo.msp_garantias. La entidad
   dbo.msp_garantias_tienda se incorpora mediante una migracion expansiva y no
   recibe movimientos nuevos hasta que se implemente el bloque operativo.
===============================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;

IF OBJECT_ID(N'dbo.msp_garantias', N'U') IS NULL
    THROW 51501, 'No existe dbo.msp_garantias. Ejecute primero la base de garantias.', 1;

IF OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
    THROW 51502, 'No existe dbo.msp_contratos_arriendo.', 1;

IF OBJECT_ID(N'dbo.msp_tiendas', N'U') IS NULL
    THROW 51503, 'No existe dbo.msp_tiendas.', 1;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.msp_garantias_tienda', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_garantias_tienda (
            id_garantia_tienda          INT IDENTITY(1,1) NOT NULL,
            id_contrato_arriendo        INT NOT NULL,
            id_tienda                   INT NOT NULL,
            fecha_constitucion          DATE NOT NULL,
            monto_pactado               DECIMAL(18,2) NOT NULL,
            estado_garantia             TINYINT NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_estado DEFAULT (1),
            origen_registro             NVARCHAR(30) NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_origen DEFAULT (N'MIGRACION_LOCAL'),
            requiere_revision_manual    BIT NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_revision DEFAULT (0),
            estado_revision             NVARCHAR(20) NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_estado_revision DEFAULT (N'NO_REQUERIDA'),
            motivo_revision             NVARCHAR(500) NULL,
            observaciones               NVARCHAR(500) NULL,
            fecha_registro              DATETIME2(0) NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_registro DEFAULT (SYSDATETIME()),
            fecha_actualizacion         DATETIME2(0) NOT NULL
                CONSTRAINT DF_msp_garantias_tienda_actualizacion DEFAULT (SYSDATETIME()),
            CONSTRAINT PK_msp_garantias_tienda
                PRIMARY KEY (id_garantia_tienda),
            CONSTRAINT UQ_msp_garantias_tienda_contrato
                UNIQUE (id_contrato_arriendo),
            CONSTRAINT FK_msp_garantias_tienda_contrato
                FOREIGN KEY (id_contrato_arriendo)
                REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
            CONSTRAINT FK_msp_garantias_tienda_tienda
                FOREIGN KEY (id_tienda)
                REFERENCES dbo.msp_tiendas (id_tienda),
            CONSTRAINT CK_msp_garantias_tienda_monto
                CHECK (monto_pactado >= 0),
            CONSTRAINT CK_msp_garantias_tienda_estado
                CHECK (estado_garantia IN (1,2,3,4,5,6)),
            CONSTRAINT CK_msp_garantias_tienda_revision
                CHECK (estado_revision IN (N'NO_REQUERIDA', N'PENDIENTE', N'APROBADA', N'OBSERVADA'))
        );
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_garantias_tienda')
          AND name = N'IX_msp_garantias_tienda_tienda_estado'
    )
    BEGIN
        CREATE INDEX IX_msp_garantias_tienda_tienda_estado
            ON dbo.msp_garantias_tienda (id_tienda, estado_garantia, id_contrato_arriendo);
    END;

    IF COL_LENGTH(N'dbo.msp_garantias', N'id_garantia_tienda') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_garantias
            ADD id_garantia_tienda INT NULL;
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

/*
   El cambio de columna se confirma en un lote separado para que SQL Server
   pueda compilar de forma idempotente los índices y consultas siguientes.
*/
BEGIN TRY
    BEGIN TRANSACTION;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE parent_object_id = OBJECT_ID(N'dbo.msp_garantias')
          AND name = N'FK_msp_garantias_garantia_tienda'
    )
    BEGIN
        ALTER TABLE dbo.msp_garantias
            ADD CONSTRAINT FK_msp_garantias_garantia_tienda
                FOREIGN KEY (id_garantia_tienda)
                REFERENCES dbo.msp_garantias_tienda (id_garantia_tienda);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_garantias')
          AND name = N'IX_msp_garantias_garantia_tienda'
    )
    BEGIN
        CREATE INDEX IX_msp_garantias_garantia_tienda
            ON dbo.msp_garantias (id_garantia_tienda, id_local)
            WHERE id_garantia_tienda IS NOT NULL;
    END;

    IF OBJECT_ID(N'dbo.msp_garantias_tienda_migracion_auditoria', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_garantias_tienda_migracion_auditoria (
            id_auditoria               BIGINT IDENTITY(1,1) NOT NULL,
            id_ejecucion               UNIQUEIDENTIFIER NOT NULL,
            fase                       NVARCHAR(10) NOT NULL,
            fecha_auditoria            DATETIME2(0) NOT NULL
                CONSTRAINT DF_msp_gt_migracion_auditoria_fecha DEFAULT (SYSDATETIME()),
            filas_garantia_local       INT NOT NULL,
            filas_local_vinculadas     INT NOT NULL,
            garantias_tienda           INT NOT NULL,
            contratos_con_garantia     INT NOT NULL,
            revisiones_pendientes      INT NOT NULL,
            monto_pactado_local        DECIMAL(18,2) NOT NULL,
            monto_pactado_tienda       DECIMAL(18,2) NOT NULL,
            monto_recibido             DECIMAL(18,2) NOT NULL,
            monto_reservado            DECIMAL(18,2) NOT NULL,
            monto_aplicado             DECIMAL(18,2) NOT NULL,
            monto_devuelto             DECIMAL(18,2) NOT NULL,
            CONSTRAINT PK_msp_gt_migracion_auditoria PRIMARY KEY (id_auditoria),
            CONSTRAINT CK_msp_gt_migracion_auditoria_fase CHECK (fase IN (N'PRE', N'POST'))
        );
    END;

    DECLARE @id_ejecucion UNIQUEIDENTIFIER = NEWID();

    INSERT INTO dbo.msp_garantias_tienda_migracion_auditoria (
        id_ejecucion,
        fase,
        filas_garantia_local,
        filas_local_vinculadas,
        garantias_tienda,
        contratos_con_garantia,
        revisiones_pendientes,
        monto_pactado_local,
        monto_pactado_tienda,
        monto_recibido,
        monto_reservado,
        monto_aplicado,
        monto_devuelto
    )
    SELECT
        @id_ejecucion,
        N'PRE',
        (SELECT COUNT(*) FROM dbo.msp_garantias),
        (SELECT COUNT(*) FROM dbo.msp_garantias WHERE id_garantia_tienda IS NOT NULL),
        (SELECT COUNT(*) FROM dbo.msp_garantias_tienda),
        (SELECT COUNT(DISTINCT id_contrato_arriendo) FROM dbo.msp_garantias),
        (SELECT COUNT(*) FROM dbo.msp_garantias_tienda WHERE estado_revision = N'PENDIENTE'),
        ISNULL((SELECT SUM(monto_inicial) FROM dbo.msp_garantias), 0),
        ISNULL((SELECT SUM(monto_pactado) FROM dbo.msp_garantias_tienda), 0),
        ISNULL((
            SELECT SUM(r.monto_recibido)
            FROM dbo.msp_garantia_recepciones r
            WHERE r.estado_recepcion = N'CONFIRMADA'
        ), 0),
        ISNULL((
            SELECT SUM(CASE
                WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento
                WHEN mg.id_tipo_movimiento_garantia = 3 THEN -mg.monto_movimiento
                WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN -mg.monto_movimiento
                ELSE 0
            END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0),
        ISNULL((
            SELECT SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 THEN mg.monto_movimiento ELSE 0 END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0),
        ISNULL((
            SELECT SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0);

    ;WITH fuente AS (
        SELECT
            g.id_contrato_arriendo,
            c.id_tienda,
            MIN(g.fecha_constitucion) AS fecha_constitucion,
            CAST(SUM(g.monto_inicial) AS DECIMAL(18,2)) AS monto_pactado,
            CAST(
                CASE
                    WHEN SUM(CASE WHEN g.estado_garantia NOT IN (5,6) THEN 1 ELSE 0 END) > 0 THEN 1
                    WHEN SUM(CASE WHEN g.estado_garantia = 5 THEN 1 ELSE 0 END) > 0 THEN 5
                    ELSE 6
                END
                AS TINYINT
            ) AS estado_garantia
        FROM dbo.msp_garantias g
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = g.id_contrato_arriendo
        GROUP BY g.id_contrato_arriendo, c.id_tienda
    )
    MERGE dbo.msp_garantias_tienda WITH (HOLDLOCK) AS destino
    USING fuente
        ON destino.id_contrato_arriendo = fuente.id_contrato_arriendo
    WHEN MATCHED
         AND destino.origen_registro = N'MIGRACION_LOCAL'
         AND destino.estado_revision IN (N'NO_REQUERIDA', N'PENDIENTE')
    THEN UPDATE SET
        destino.id_tienda = fuente.id_tienda,
        destino.fecha_constitucion = fuente.fecha_constitucion,
        destino.monto_pactado = fuente.monto_pactado,
        destino.estado_garantia = fuente.estado_garantia,
        destino.fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED BY TARGET
    THEN INSERT (
        id_contrato_arriendo,
        id_tienda,
        fecha_constitucion,
        monto_pactado,
        estado_garantia,
        origen_registro,
        observaciones
    )
    VALUES (
        fuente.id_contrato_arriendo,
        fuente.id_tienda,
        fuente.fecha_constitucion,
        fuente.monto_pactado,
        fuente.estado_garantia,
        N'MIGRACION_LOCAL',
        N'Garantía canónica creada desde la suma de garantías históricas por local.'
    );

    UPDATE g
    SET g.id_garantia_tienda = gt.id_garantia_tienda
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_garantias_tienda gt
        ON gt.id_contrato_arriendo = g.id_contrato_arriendo
    WHERE g.id_garantia_tienda IS NULL
       OR g.id_garantia_tienda <> gt.id_garantia_tienda;

    ;WITH actividad_financiera AS (
        SELECT r.id_garantia
        FROM dbo.msp_garantia_recepciones r
        UNION
        SELECT mg.id_garantia
        FROM dbo.msp_movimientos_garantia mg
        UNION
        SELECT rv.id_garantia
        FROM dbo.msp_garantia_reversas rv
    ), casos_revision AS (
        SELECT
            gt.id_garantia_tienda,
            COUNT(*) AS cantidad_garantias_local,
            MAX(CASE WHEN af.id_garantia IS NOT NULL THEN 1 ELSE 0 END) AS tiene_actividad_financiera
        FROM dbo.msp_garantias_tienda gt
        INNER JOIN dbo.msp_garantias g
            ON g.id_garantia_tienda = gt.id_garantia_tienda
        LEFT JOIN actividad_financiera af
            ON af.id_garantia = g.id_garantia
        GROUP BY gt.id_garantia_tienda
    )
    UPDATE gt
    SET
        gt.requiere_revision_manual = CASE
            WHEN cr.cantidad_garantias_local > 1 AND cr.tiene_actividad_financiera = 1 THEN 1
            ELSE 0
        END,
        gt.estado_revision = CASE
            WHEN cr.cantidad_garantias_local > 1 AND cr.tiene_actividad_financiera = 1 THEN N'PENDIENTE'
            ELSE N'NO_REQUERIDA'
        END,
        gt.motivo_revision = CASE
            WHEN cr.cantidad_garantias_local > 1 AND cr.tiene_actividad_financiera = 1
                THEN N'Contrato multilocal con actividad financiera histórica. Revisar al finalizar la migración.'
            ELSE NULL
        END,
        gt.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_garantias_tienda gt
    INNER JOIN casos_revision cr
        ON cr.id_garantia_tienda = gt.id_garantia_tienda
    WHERE gt.origen_registro = N'MIGRACION_LOCAL'
      AND gt.estado_revision <> N'APROBADA';

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias g
        WHERE g.id_garantia_tienda IS NULL
    )
        THROW 51504, 'Existen garantías históricas sin vínculo a una garantía de tienda.', 1;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias g
        INNER JOIN dbo.msp_garantias_tienda gt
            ON gt.id_garantia_tienda = g.id_garantia_tienda
        WHERE g.id_contrato_arriendo <> gt.id_contrato_arriendo
    )
        THROW 51505, 'Una garantía histórica quedó vinculada a otro contrato.', 1;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias_tienda gt
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = gt.id_contrato_arriendo
        WHERE gt.id_tienda <> c.id_tienda
    )
        THROW 51506, 'Una garantía canónica no coincide con la tienda de su contrato.', 1;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias_tienda gt
        OUTER APPLY (
            SELECT CAST(ISNULL(SUM(g.monto_inicial), 0) AS DECIMAL(18,2)) AS monto_local
            FROM dbo.msp_garantias g
            WHERE g.id_garantia_tienda = gt.id_garantia_tienda
        ) src
        WHERE gt.origen_registro = N'MIGRACION_LOCAL'
          AND gt.estado_revision IN (N'NO_REQUERIDA', N'PENDIENTE')
          AND ABS(gt.monto_pactado - src.monto_local) > 0.009
    )
        THROW 51507, 'El monto canónico no coincide con la suma de garantías históricas.', 1;

    INSERT INTO dbo.msp_garantias_tienda_migracion_auditoria (
        id_ejecucion,
        fase,
        filas_garantia_local,
        filas_local_vinculadas,
        garantias_tienda,
        contratos_con_garantia,
        revisiones_pendientes,
        monto_pactado_local,
        monto_pactado_tienda,
        monto_recibido,
        monto_reservado,
        monto_aplicado,
        monto_devuelto
    )
    SELECT
        @id_ejecucion,
        N'POST',
        (SELECT COUNT(*) FROM dbo.msp_garantias),
        (SELECT COUNT(*) FROM dbo.msp_garantias WHERE id_garantia_tienda IS NOT NULL),
        (SELECT COUNT(*) FROM dbo.msp_garantias_tienda),
        (SELECT COUNT(DISTINCT id_contrato_arriendo) FROM dbo.msp_garantias),
        (SELECT COUNT(*) FROM dbo.msp_garantias_tienda WHERE estado_revision = N'PENDIENTE'),
        ISNULL((SELECT SUM(monto_inicial) FROM dbo.msp_garantias), 0),
        ISNULL((SELECT SUM(monto_pactado) FROM dbo.msp_garantias_tienda), 0),
        ISNULL((
            SELECT SUM(r.monto_recibido)
            FROM dbo.msp_garantia_recepciones r
            WHERE r.estado_recepcion = N'CONFIRMADA'
        ), 0),
        ISNULL((
            SELECT SUM(CASE
                WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento
                WHEN mg.id_tipo_movimiento_garantia = 3 THEN -mg.monto_movimiento
                WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN -mg.monto_movimiento
                ELSE 0
            END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0),
        ISNULL((
            SELECT SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 THEN mg.monto_movimiento ELSE 0 END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0),
        ISNULL((
            SELECT SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END)
            FROM dbo.msp_movimientos_garantia mg
        ), 0);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_tienda_valida_contrato
ON dbo.msp_garantias_tienda
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        WHERE c.id_tienda <> i.id_tienda
    )
        THROW 51508, 'La tienda de la garantía no coincide con la tienda del contrato.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_valida_garantia_tienda
ON dbo.msp_garantias
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias_tienda gt
            ON gt.id_garantia_tienda = i.id_garantia_tienda
        WHERE i.id_garantia_tienda IS NOT NULL
          AND i.id_contrato_arriendo <> gt.id_contrato_arriendo
    )
        THROW 51509, 'La garantía histórica no pertenece al contrato de la garantía de tienda.', 1;

    /*
       Compatibilidad durante la transición:
       mientras los formularios sigan creando/actualizando garantías por local,
       la garantía canónica se crea o recalcula automáticamente.
    */
    ;WITH contratos_afectados AS (
        SELECT DISTINCT i.id_contrato_arriendo
        FROM inserted i
    ), fuente AS (
        SELECT
            g.id_contrato_arriendo,
            c.id_tienda,
            MIN(g.fecha_constitucion) AS fecha_constitucion,
            CAST(SUM(g.monto_inicial) AS DECIMAL(18,2)) AS monto_pactado,
            CAST(
                CASE
                    WHEN SUM(CASE WHEN g.estado_garantia NOT IN (5,6) THEN 1 ELSE 0 END) > 0 THEN 1
                    WHEN SUM(CASE WHEN g.estado_garantia = 5 THEN 1 ELSE 0 END) > 0 THEN 5
                    ELSE 6
                END
                AS TINYINT
            ) AS estado_garantia
        FROM contratos_afectados ca
        INNER JOIN dbo.msp_garantias g
            ON g.id_contrato_arriendo = ca.id_contrato_arriendo
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = g.id_contrato_arriendo
        GROUP BY g.id_contrato_arriendo, c.id_tienda
    )
    MERGE dbo.msp_garantias_tienda WITH (HOLDLOCK) AS destino
    USING fuente
        ON destino.id_contrato_arriendo = fuente.id_contrato_arriendo
    WHEN MATCHED
         AND destino.origen_registro = N'MIGRACION_LOCAL'
         AND destino.estado_revision IN (N'NO_REQUERIDA', N'PENDIENTE')
    THEN UPDATE SET
        destino.id_tienda = fuente.id_tienda,
        destino.fecha_constitucion = fuente.fecha_constitucion,
        destino.monto_pactado = fuente.monto_pactado,
        destino.estado_garantia = fuente.estado_garantia,
        destino.fecha_actualizacion = SYSDATETIME()
    WHEN NOT MATCHED BY TARGET
    THEN INSERT (
        id_contrato_arriendo,
        id_tienda,
        fecha_constitucion,
        monto_pactado,
        estado_garantia,
        origen_registro,
        observaciones
    )
    VALUES (
        fuente.id_contrato_arriendo,
        fuente.id_tienda,
        fuente.fecha_constitucion,
        fuente.monto_pactado,
        fuente.estado_garantia,
        N'MIGRACION_LOCAL',
        N'Garantía canónica creada automáticamente durante la transición.'
    );

    UPDATE g
    SET g.id_garantia_tienda = gt.id_garantia_tienda
    FROM dbo.msp_garantias g
    INNER JOIN inserted i
        ON i.id_contrato_arriendo = g.id_contrato_arriendo
    INNER JOIN dbo.msp_garantias_tienda gt
        ON gt.id_contrato_arriendo = g.id_contrato_arriendo
    WHERE g.id_garantia_tienda IS NULL;
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_tienda_fuentes
AS
SELECT
    gt.id_garantia_tienda,
    gt.id_contrato_arriendo,
    gt.id_tienda,
    g.id_garantia,
    g.id_contrato_local,
    g.id_local,
    l.cdo_local,
    l.desc_local,
    g.fecha_constitucion,
    g.monto_inicial AS monto_pactado_local,
    g.estado_garantia AS estado_garantia_local,
    gr.monto_recibido,
    gr.total_reserva,
    gr.total_liberacion,
    gr.total_aplicado_desde_disponible,
    gr.total_aplicado_desde_reservado,
    gr.total_devuelto,
    gr.total_ajuste_positivo,
    gr.total_ajuste_negativo,
    gr.saldo_disponible,
    gr.saldo_reservado,
    gr.saldo_aplicado
FROM dbo.msp_garantias_tienda gt
INNER JOIN dbo.msp_garantias g
    ON g.id_garantia_tienda = gt.id_garantia_tienda
INNER JOIN dbo.msp_locales l
    ON l.id_local = g.id_local
LEFT JOIN dbo.msp_vw_garantias_resumen gr
    ON gr.id_garantia = g.id_garantia;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_tienda_resumen
AS
WITH fuentes AS (
    SELECT
        g.id_garantia_tienda,
        COUNT(*) AS cantidad_garantias_local,
        COUNT(DISTINCT g.id_local) AS cantidad_locales,
        STRING_AGG(CONVERT(NVARCHAR(MAX), l.cdo_local), N' / ')
            WITHIN GROUP (ORDER BY l.cdo_local) AS locales,
        CAST(SUM(g.monto_inicial) AS DECIMAL(18,2)) AS monto_pactado_fuentes
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_locales l
        ON l.id_local = g.id_local
    WHERE g.id_garantia_tienda IS NOT NULL
    GROUP BY g.id_garantia_tienda
), recepciones AS (
    SELECT
        g.id_garantia_tienda,
        CAST(SUM(CASE
            WHEN r.estado_recepcion = N'CONFIRMADA' THEN r.monto_recibido
            ELSE 0
        END) AS DECIMAL(18,2)) AS monto_recibido
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_garantia_recepciones r
        ON r.id_garantia = g.id_garantia
    WHERE g.id_garantia_tienda IS NOT NULL
    GROUP BY g.id_garantia_tienda
), movimientos AS (
    SELECT
        g.id_garantia_tienda,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS total_reserva,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS total_liberacion,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS aplicado_disponible,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS aplicado_reservado,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS total_devuelto,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 6 THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS ajuste_positivo,
        CAST(SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 7 THEN mg.monto_movimiento ELSE 0 END) AS DECIMAL(18,2)) AS ajuste_negativo
    FROM dbo.msp_garantias g
    INNER JOIN dbo.msp_movimientos_garantia mg
        ON mg.id_garantia = g.id_garantia
    WHERE g.id_garantia_tienda IS NOT NULL
    GROUP BY g.id_garantia_tienda
), calculo AS (
    SELECT
        gt.id_garantia_tienda,
        gt.id_contrato_arriendo,
        gt.id_tienda,
        c.id_arrendatario,
        a.nombre_locatario,
        a.rut,
        t.nombre_comercial,
        gt.fecha_constitucion,
        gt.estado_garantia,
        gt.origen_registro,
        gt.requiere_revision_manual,
        gt.estado_revision,
        gt.motivo_revision,
        gt.monto_pactado,
        ISNULL(f.monto_pactado_fuentes, 0) AS monto_pactado_fuentes,
        ISNULL(f.cantidad_garantias_local, 0) AS cantidad_garantias_local,
        ISNULL(f.cantidad_locales, 0) AS cantidad_locales,
        f.locales,
        ISNULL(r.monto_recibido, 0) AS monto_recibido,
        ISNULL(m.total_reserva, 0) AS total_reserva,
        ISNULL(m.total_liberacion, 0) AS total_liberacion,
        ISNULL(m.aplicado_disponible, 0) AS aplicado_disponible,
        ISNULL(m.aplicado_reservado, 0) AS aplicado_reservado,
        ISNULL(m.total_devuelto, 0) AS total_devuelto,
        ISNULL(m.ajuste_positivo, 0) AS ajuste_positivo,
        ISNULL(m.ajuste_negativo, 0) AS ajuste_negativo
    FROM dbo.msp_garantias_tienda gt
    INNER JOIN dbo.msp_contratos_arriendo c
        ON c.id_contrato_arriendo = gt.id_contrato_arriendo
    INNER JOIN dbo.msp_arrendatarios a
        ON a.id_arrendatario = c.id_arrendatario
    INNER JOIN dbo.msp_tiendas t
        ON t.id_tienda = gt.id_tienda
    LEFT JOIN fuentes f
        ON f.id_garantia_tienda = gt.id_garantia_tienda
    LEFT JOIN recepciones r
        ON r.id_garantia_tienda = gt.id_garantia_tienda
    LEFT JOIN movimientos m
        ON m.id_garantia_tienda = gt.id_garantia_tienda
), saldos AS (
    SELECT
        c.*,
        CAST(CASE
            WHEN c.monto_pactado > c.monto_recibido THEN c.monto_pactado - c.monto_recibido
            ELSE 0
        END AS DECIMAL(18,2)) AS monto_pendiente_recepcion,
        CAST(
            c.total_reserva - c.total_liberacion - c.aplicado_reservado
            AS DECIMAL(18,2)
        ) AS monto_reservado,
        CAST(
            c.aplicado_disponible + c.aplicado_reservado
            AS DECIMAL(18,2)
        ) AS monto_aplicado,
        CAST(
            c.monto_recibido
            - c.total_reserva
            + c.total_liberacion
            - c.aplicado_disponible
            - c.total_devuelto
            + c.ajuste_positivo
            - c.ajuste_negativo
            AS DECIMAL(18,2)
        ) AS saldo_disponible
    FROM calculo c
)
SELECT
    s.*,
    CAST(CASE WHEN s.saldo_disponible > 0 THEN s.saldo_disponible ELSE 0 END AS DECIMAL(18,2)) AS monto_disponible,
    CASE
        WHEN s.monto_recibido = 0 THEN N'NO_RECIBIDA'
        WHEN s.monto_recibido < s.monto_pactado THEN N'PARCIAL'
        WHEN s.monto_recibido = s.monto_pactado THEN N'COMPLETA'
        ELSE N'EXCEDIDA'
    END AS estado_recepcion,
    CASE
        WHEN ABS(s.monto_pactado - s.monto_pactado_fuentes) > 0.009 THEN N'DIFERENCIA_PACTADO'
        WHEN s.saldo_disponible < 0 OR s.monto_reservado < 0 THEN N'INCONSISTENCIA_SALDO'
        WHEN s.requiere_revision_manual = 1 AND s.estado_revision = N'PENDIENTE' THEN N'REVISION_PENDIENTE'
        ELSE N'OK'
    END AS estado_conciliacion
FROM saldos s;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_tienda_control_migracion
AS
SELECT
    r.id_garantia_tienda,
    r.id_contrato_arriendo,
    r.id_tienda,
    r.nombre_comercial,
    r.nombre_locatario,
    r.rut,
    r.locales,
    r.cantidad_garantias_local,
    r.cantidad_locales,
    r.monto_pactado,
    r.monto_pactado_fuentes,
    CAST(r.monto_pactado - r.monto_pactado_fuentes AS DECIMAL(18,2)) AS diferencia_pactado,
    r.monto_recibido,
    r.monto_reservado,
    r.monto_aplicado,
    r.total_devuelto AS monto_devuelto,
    r.monto_disponible,
    r.requiere_revision_manual,
    r.estado_revision,
    r.motivo_revision,
    r.estado_conciliacion
FROM dbo.msp_vw_garantias_tienda_resumen r;
GO

PRINT N'Garantía por tienda - bloque 1 instalado correctamente.';
PRINT N'Las garantías por local y todos sus movimientos históricos permanecen intactos.';
GO
