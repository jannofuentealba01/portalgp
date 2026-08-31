/*
===========================================================================
 MSP - DOCUMENTO Y PAGOS (A22)
 SQL Server / esquema dbo
 - Requiere A1 + A21 ya instalados
 - Esta capa cubre: documento mensual por tienda, detalle y pagos parciales
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS COMERCIALES
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_tipo_item_documento', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tipo_item_documento (
        id_tipo_item_documento   INT NOT NULL,
        codigo_item             NVARCHAR(30) NOT NULL,
        nombre_item             NVARCHAR(100) NOT NULL,
        CONSTRAINT PK_msp_tipo_item_documento PRIMARY KEY (id_tipo_item_documento),
        CONSTRAINT UQ_msp_tipo_item_documento_codigo UNIQUE (codigo_item),
        CONSTRAINT UQ_msp_tipo_item_documento_nombre UNIQUE (nombre_item)
    );
END;
GO

MERGE dbo.msp_tipo_item_documento AS t
USING (
    SELECT 1 AS id_tipo_item_documento, N'ARRIENDO' AS codigo_item, N'Arriendo' AS nombre_item
    UNION ALL
    SELECT 2, N'SERVICIO_AGUA', N'Servicio Agua'
    UNION ALL
    SELECT 3, N'SERVICIO_LUZ', N'Servicio Luz'
    UNION ALL
    SELECT 4, N'SERVICIO_GAS', N'Servicio Gas'
    UNION ALL
    SELECT 5, N'AJUSTE', N'Ajuste'
) AS s
ON t.id_tipo_item_documento = s.id_tipo_item_documento
WHEN MATCHED THEN
    UPDATE SET
        codigo_item = s.codigo_item,
        nombre_item = s.nombre_item
WHEN NOT MATCHED THEN
    INSERT (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (s.id_tipo_item_documento, s.codigo_item, s.nombre_item);
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_tipo_item_documento
    WHERE codigo_item = N'MULTA'
)
BEGIN
    DECLARE @id_tipo_multa INT;
    SELECT @id_tipo_multa = ISNULL(MAX(id_tipo_item_documento), 0) + 1
    FROM dbo.msp_tipo_item_documento;

    INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (@id_tipo_multa, N'MULTA', N'Multa');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_tipo_item_documento
    WHERE codigo_item = N'DANO'
)
BEGIN
    DECLARE @id_tipo_dano INT;
    SELECT @id_tipo_dano = ISNULL(MAX(id_tipo_item_documento), 0) + 1
    FROM dbo.msp_tipo_item_documento;

    INSERT INTO dbo.msp_tipo_item_documento (id_tipo_item_documento, codigo_item, nombre_item)
    VALUES (@id_tipo_dano, N'DANO', N'Dano');
END;
GO

/* =========================================================================
   2. DOCUMENTOS DE COBRO
   Estado:
     1 = Borrador
     2 = Emitido
     3 = Pagado Parcial
     4 = Pagado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_documentos_cobro (
    id_documento_cobro         INT IDENTITY(1,1) NOT NULL,
    id_tienda                  INT NOT NULL,
    id_contrato_arriendo       INT NULL,
    periodo_facturacion        DATE NOT NULL,
    numero_documento           NVARCHAR(50) NULL,
    fecha_emision              DATE NOT NULL,
    fecha_vencimiento          DATE NOT NULL,

    rut_arrendatario_snapshot  NVARCHAR(20) NOT NULL,
    nombre_arrendatario_snapshot NVARCHAR(200) NOT NULL,
    nombre_tienda_snapshot     NVARCHAR(200) NOT NULL,

    subtotal_arriendo          DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_documentos_cobro_subtotal_arriendo DEFAULT (0),
    subtotal_servicios         DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_documentos_cobro_subtotal_servicios DEFAULT (0),
    monto_total                DECIMAL(18,2) NOT NULL,
    saldo_pendiente            DECIMAL(18,2) NOT NULL,

    estado_documento           TINYINT NOT NULL CONSTRAINT DF_msp_documentos_cobro_estado DEFAULT (1),
    observaciones              NVARCHAR(1000) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_documentos_cobro_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_documentos_cobro PRIMARY KEY (id_documento_cobro),
    CONSTRAINT FK_msp_documentos_cobro_tienda
        FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
    CONSTRAINT UQ_msp_documentos_cobro_tienda_periodo UNIQUE (id_tienda, periodo_facturacion),
    CONSTRAINT CK_msp_documentos_cobro_periodo CHECK (DAY(periodo_facturacion) = 1),
    CONSTRAINT CK_msp_documentos_cobro_fechas CHECK (fecha_vencimiento >= fecha_emision),
    CONSTRAINT CK_msp_documentos_cobro_estado CHECK (estado_documento IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_documentos_cobro_montos CHECK (
        subtotal_arriendo >= 0
        AND subtotal_servicios >= 0
        AND monto_total >= 0
        AND saldo_pendiente >= 0
    )
);
GO

CREATE INDEX IX_msp_documentos_cobro_periodo
    ON dbo.msp_documentos_cobro (periodo_facturacion, estado_documento);
GO

CREATE INDEX IX_msp_documentos_cobro_numero
    ON dbo.msp_documentos_cobro (numero_documento);
GO

/* =========================================================================
   3. DETALLE DEL DOCUMENTO
   ========================================================================= */

CREATE TABLE dbo.msp_documentos_cobro_detalle (
    id_detalle_documento       INT IDENTITY(1,1) NOT NULL,
    id_documento_cobro         INT NOT NULL,
    orden_item                 INT NOT NULL CONSTRAINT DF_msp_documentos_cobro_detalle_orden DEFAULT (1),
    id_tipo_item_documento     INT NOT NULL,
    descripcion_item           NVARCHAR(255) NOT NULL,
    cantidad                   DECIMAL(18,4) NOT NULL CONSTRAINT DF_msp_documentos_cobro_detalle_cantidad DEFAULT (1),
    valor_unitario             DECIMAL(18,2) NOT NULL,
    subtotal                   DECIMAL(18,2) NOT NULL,
    id_cobro_servicio          INT NULL,

    CONSTRAINT PK_msp_documentos_cobro_detalle PRIMARY KEY (id_detalle_documento),
    CONSTRAINT FK_msp_documentos_cobro_detalle_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_documentos_cobro_detalle_tipo
        FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
    CONSTRAINT FK_msp_documentos_cobro_detalle_cobro
        FOREIGN KEY (id_cobro_servicio) REFERENCES dbo.msp_cobros_servicios (id_cobro_servicio),
    CONSTRAINT CK_msp_documentos_cobro_detalle_montos CHECK (
        orden_item > 0
        AND cantidad > 0
        AND valor_unitario >= 0
        AND subtotal >= 0
    )
);
GO

CREATE INDEX IX_msp_documentos_cobro_detalle_documento
    ON dbo.msp_documentos_cobro_detalle (id_documento_cobro, orden_item);
GO

/* =========================================================================
   4. PAGOS
   Estado:
     1 = Aplicado
     2 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_pagos (
    id_pago                    INT IDENTITY(1,1) NOT NULL,
    id_documento_cobro         INT NOT NULL,
    fecha_pago                 DATE NOT NULL,
    monto_pagado               DECIMAL(18,2) NOT NULL,
    monto_saldo_favor_generado DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_pagos_saldo_favor_generado DEFAULT (0),
    aplica_desde_saldo_favor   BIT NOT NULL CONSTRAINT DF_msp_pagos_aplica_saldo_favor DEFAULT (0),
    estado_pago                TINYINT NOT NULL CONSTRAINT DF_msp_pagos_estado DEFAULT (1),
    fecha_anulacion            DATE NULL,
    motivo_anulacion           NVARCHAR(500) NULL,
    medio_pago                 NVARCHAR(50) NULL,
    referencia_pago            NVARCHAR(100) NULL,
    observaciones              NVARCHAR(500) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_fecha_registro DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_pagos PRIMARY KEY (id_pago),
    CONSTRAINT FK_msp_pagos_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT CK_msp_pagos_estado CHECK (estado_pago IN (1,2)),
    CONSTRAINT CK_msp_pagos_monto CHECK (
        monto_pagado > 0
        AND monto_saldo_favor_generado >= 0
        AND (aplica_desde_saldo_favor = 0 OR monto_saldo_favor_generado = 0)
    ),
    CONSTRAINT CK_msp_pagos_anulacion CHECK (
        (estado_pago = 1 AND fecha_anulacion IS NULL AND motivo_anulacion IS NULL)
        OR
        (
            estado_pago = 2
            AND fecha_anulacion IS NOT NULL
            AND motivo_anulacion IS NOT NULL
            AND LTRIM(RTRIM(motivo_anulacion)) <> ''
        )
    )
);
GO

CREATE INDEX IX_msp_pagos_documento_estado
    ON dbo.msp_pagos (id_documento_cobro, estado_pago, fecha_pago);
GO

/* =========================================================================
   5. DETALLE DE PAGO POR CONCEPTO
   ========================================================================= */

CREATE TABLE dbo.msp_pagos_detalle_concepto (
    id_detalle_pago_concepto      INT IDENTITY(1,1) NOT NULL,
    id_pago                       INT NOT NULL,
    id_documento_cobro            INT NOT NULL,
    id_tipo_item_documento        INT NOT NULL,
    monto_aplicado                DECIMAL(18,2) NOT NULL,
    fecha_registro                DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pagos_detalle_concepto_fecha DEFAULT (SYSDATETIME()),

    CONSTRAINT PK_msp_pagos_detalle_concepto PRIMARY KEY (id_detalle_pago_concepto),
    CONSTRAINT FK_msp_pagos_detalle_concepto_pago
        FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
    CONSTRAINT FK_msp_pagos_detalle_concepto_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_pagos_detalle_concepto_tipo
        FOREIGN KEY (id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento (id_tipo_item_documento),
    CONSTRAINT UQ_msp_pagos_detalle_concepto_pago_tipo UNIQUE (id_pago, id_tipo_item_documento),
    CONSTRAINT CK_msp_pagos_detalle_concepto_monto CHECK (monto_aplicado > 0)
);
GO

CREATE INDEX IX_msp_pagos_detalle_concepto_documento_tipo
    ON dbo.msp_pagos_detalle_concepto (id_documento_cobro, id_tipo_item_documento, id_pago);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_detalle_concepto_valida_documento
ON dbo.msp_pagos_detalle_concepto
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = i.id_pago
        WHERE p.id_documento_cobro <> i.id_documento_cobro
    )
    BEGIN
        ;THROW 50120, 'El documento del detalle de concepto no coincide con el documento del pago.', 1;
    END;
END;
GO

/* =========================================================================
   6. TRIGGER DE RECALCULO DE DOCUMENTO
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_recalcula_documento
ON dbo.msp_pagos
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @documentos_afectados TABLE (
        id_documento_cobro INT NOT NULL PRIMARY KEY
    );

    INSERT INTO @documentos_afectados (id_documento_cobro)
    SELECT DISTINCT i.id_documento_cobro
    FROM inserted i
    WHERE i.id_documento_cobro IS NOT NULL;

    INSERT INTO @documentos_afectados (id_documento_cobro)
    SELECT DISTINCT d.id_documento_cobro
    FROM deleted d
    WHERE d.id_documento_cobro IS NOT NULL
      AND NOT EXISTS (
            SELECT 1
            FROM @documentos_afectados x
            WHERE x.id_documento_cobro = d.id_documento_cobro
      );

    IF NOT EXISTS (SELECT 1 FROM @documentos_afectados)
        RETURN;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = i.id_documento_cobro
        WHERE i.estado_pago = 1
          AND dc.estado_documento = 5
    )
    BEGIN
        ;THROW 50041, 'No se pueden aplicar pagos sobre documentos anulados.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM @documentos_afectados da
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = da.id_documento_cobro
        CROSS APPLY (
            SELECT ISNULL(SUM(p.monto_pagado), 0) AS total_pagado
            FROM dbo.msp_pagos p
            WHERE p.id_documento_cobro = da.id_documento_cobro
              AND p.estado_pago = 1
        ) tp
        WHERE tp.total_pagado > dc.monto_total
    )
    BEGIN
        ;THROW 50042, 'El pago excede el monto total del documento.', 1;
    END;

    UPDATE dc
    SET dc.saldo_pendiente = ROUND(dc.monto_total - tp.total_pagado, 2),
        dc.estado_documento = CASE
            WHEN dc.estado_documento = 5 THEN 5
            WHEN tp.total_pagado <= 0 THEN 2
            WHEN tp.total_pagado < dc.monto_total THEN 3
            ELSE 4
        END
    FROM dbo.msp_documentos_cobro dc
    INNER JOIN @documentos_afectados da
        ON da.id_documento_cobro = dc.id_documento_cobro
    CROSS APPLY (
        SELECT ISNULL(SUM(p.monto_pagado), 0) AS total_pagado
        FROM dbo.msp_pagos p
        WHERE p.id_documento_cobro = dc.id_documento_cobro
          AND p.estado_pago = 1
    ) tp
    WHERE dc.estado_documento <> 5;
END;
GO

/* =========================================================================
   7. VISTA RESUMEN DE DOCUMENTOS
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_documentos_cobro_resumen
AS
SELECT
    dc.id_documento_cobro,
    dc.periodo_facturacion,
    dc.numero_documento,
    dc.fecha_emision,
    dc.fecha_vencimiento,
    dc.id_tienda,
    dc.nombre_tienda_snapshot,
    dc.rut_arrendatario_snapshot,
    dc.nombre_arrendatario_snapshot,
    dc.subtotal_arriendo,
    dc.subtotal_servicios,
    dc.monto_total,
    dc.saldo_pendiente,
    dc.estado_documento
FROM dbo.msp_documentos_cobro dc;
GO

/* =========================================================================
   8. PROCEDIMIENTO: GENERAR DOCUMENTOS DEL PERIODO
   - Arriendo: usa ocupacion que cruza cualquier dia del periodo_facturacion
   - Servicios: mapea al arrendatario segun ocupacion del periodo_facturacion
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_generar_documentos_cobro_periodo
    @id_cierre_mensual        INT,
    @fecha_emision            DATE = NULL,
    @dias_vencimiento         INT = 10,
    @reemplazar               BIT = 0,
    @documentos_generados     INT OUTPUT,
    @items_generados          INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @periodo_facturacion DATE;
    DECLARE @estado_cierre TINYINT;
    DECLARE @valor_uf DECIMAL(18,6);
    DECLARE @tasa_iva DECIMAL(9,6) = 0.19;

    DECLARE @id_item_arriendo INT;
    DECLARE @id_item_agua INT;
    DECLARE @id_item_luz INT;
    DECLARE @id_item_gas INT;

    SET @documentos_generados = 0;
    SET @items_generados = 0;

    IF @id_cierre_mensual IS NULL OR @id_cierre_mensual <= 0
    BEGIN
        ;THROW 50051, 'Debes indicar un cierre mensual valido.', 1;
    END;

    IF @dias_vencimiento < 0 OR @dias_vencimiento > 120
    BEGIN
        ;THROW 50052, 'Los dias de vencimiento deben estar entre 0 y 120.', 1;
    END;

    SELECT
        @periodo_facturacion = c.periodo_facturacion,
        @estado_cierre = c.estado_cierre,
        @valor_uf = c.valor_uf
    FROM dbo.msp_cierre_mensual c
    WHERE c.id_cierre_mensual = @id_cierre_mensual;

    IF @periodo_facturacion IS NULL
    BEGIN
        ;THROW 50053, 'El cierre mensual indicado no existe.', 1;
    END;

    IF @estado_cierre = 4
    BEGIN
        ;THROW 50033, 'No se pueden generar documentos sobre un cierre mensual anulado.', 1;
    END;

    IF @estado_cierre = 3
    BEGIN
        ;THROW 50038, 'El período está cerrado. Reábrelo a Borrador para recalcular.', 1;
    END;

    IF @estado_cierre <> 1
    BEGIN
        ;THROW 50039, 'Solo se pueden generar documentos en período Borrador.', 1;
    END;

    IF OBJECT_ID(N'dbo.msp_arriendo_local_snapshot_periodo', N'U') IS NULL
    BEGIN
        ;THROW 50056, 'No existe msp_arriendo_local_snapshot_periodo. Debes aplicar Fase 1/2 de arriendo antes de generar documentos.', 1;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
    )
    BEGIN
        ;THROW 50057, 'No existen snapshots de arriendo para el período. Ejecuta primero la generación/congelamiento de snapshot.', 1;
    END;

    SET @fecha_emision = ISNULL(@fecha_emision, CONVERT(date, SYSDATETIME()));

    SELECT @id_item_arriendo = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'ARRIENDO';

    SELECT @id_item_agua = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_AGUA';

    SELECT @id_item_luz = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_LUZ';

    SELECT @id_item_gas = id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento WHERE codigo_item = N'SERVICIO_GAS';

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @reemplazar = 1
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                INNER JOIN dbo.msp_pagos p
                    ON p.id_documento_cobro = dc.id_documento_cobro
                WHERE dc.periodo_facturacion = @periodo_facturacion
                  AND p.estado_pago = 1
            )
            BEGIN
                ;THROW 50054, 'No se puede regenerar el periodo porque existen pagos aplicados.', 1;
            END;

            DELETE dcd
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo_facturacion;

            DELETE dc
            FROM dbo.msp_documentos_cobro dc
            WHERE dc.periodo_facturacion = @periodo_facturacion;
        END
        ELSE
        BEGIN
            IF EXISTS (
                SELECT 1
                FROM dbo.msp_documentos_cobro dc
                WHERE dc.periodo_facturacion = @periodo_facturacion
            )
            BEGIN
                ;THROW 50055, 'Ya existen documentos para ese periodo_facturacion. Usa @reemplazar = 1 si quieres regenerarlos.', 1;
            END;
        END;

        CREATE TABLE #arriendo_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #arriendo_tienda (id_tienda, subtotal_arriendo)
        SELECT
            s.id_tienda,
            SUM(CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2))) AS subtotal_arriendo
        FROM dbo.msp_arriendo_local_snapshot_periodo s
        WHERE s.periodo_facturacion = @periodo_facturacion
          AND s.estado_snapshot IN (1,2,3)
        GROUP BY s.id_tienda;

        CREATE TABLE #servicios_tienda (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #servicios_tienda (id_tienda, subtotal_servicios)
        SELECT
            map.id_tienda,
            SUM(cs.monto_total) AS subtotal_servicios
        FROM dbo.msp_cobros_servicios cs
        INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL
        GROUP BY map.id_tienda;

        CREATE TABLE #documentos_base (
            id_tienda INT NOT NULL PRIMARY KEY,
            subtotal_arriendo DECIMAL(18,2) NOT NULL,
            subtotal_servicios DECIMAL(18,2) NOT NULL
        );

        INSERT INTO #documentos_base (id_tienda, subtotal_arriendo, subtotal_servicios)
        SELECT
            x.id_tienda,
            SUM(x.subtotal_arriendo) AS subtotal_arriendo,
            SUM(x.subtotal_servicios) AS subtotal_servicios
        FROM (
            SELECT at.id_tienda, at.subtotal_arriendo, CAST(0 AS DECIMAL(18,2)) AS subtotal_servicios
            FROM #arriendo_tienda at
            UNION ALL
            SELECT st.id_tienda, CAST(0 AS DECIMAL(18,2)), st.subtotal_servicios
            FROM #servicios_tienda st
        ) x
        GROUP BY x.id_tienda;

        INSERT INTO dbo.msp_documentos_cobro (
            id_tienda,
            periodo_facturacion,
            numero_documento,
            fecha_emision,
            fecha_vencimiento,
            rut_arrendatario_snapshot,
            nombre_arrendatario_snapshot,
            nombre_tienda_snapshot,
            subtotal_arriendo,
            subtotal_servicios,
            monto_total,
            saldo_pendiente,
            estado_documento,
            observaciones
        )
        SELECT
            t.id_tienda,
            @periodo_facturacion,
            CONCAT(CONVERT(CHAR(6), @periodo_facturacion, 112), N'-', t.id_tienda),
            @fecha_emision,
            DATEADD(DAY, @dias_vencimiento, @fecha_emision),
            a.rut,
            COALESCE(NULLIF(a.nombre_locatario, N''), NULLIF(a.nombre_representante, N''), a.rut),
            t.nombre_comercial,
            ROUND(db.subtotal_arriendo, 2),
            ROUND(db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            ROUND((db.subtotal_arriendo * (1 + @tasa_iva)) + db.subtotal_servicios, 2),
            2,
            CONCAT(N'Documento generado desde cierre #', @id_cierre_mensual, N'.')
        FROM #documentos_base db
        INNER JOIN dbo.msp_tiendas t
            ON t.id_tienda = db.id_tienda
        INNER JOIN dbo.msp_arrendatarios a
            ON a.id_arrendatario = t.id_arrendatario
        WHERE db.subtotal_arriendo > 0
           OR db.subtotal_servicios > 0;

        SET @documentos_generados = @@ROWCOUNT;

        ;WITH arriendo_detalle_raw AS (
            SELECT
                dc.id_documento_cobro,
                s.id_contrato_arriendo,
                s.id_contrato_local,
                s.id_modalidad_aplicada,
                loc.cdo_local,
                CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2)) AS valor_arriendo_neto,
                CASE WHEN s.id_modalidad_aplicada = 3 THEN 1 ELSE 0 END AS es_clp_fijo_contrato,
                ROW_NUMBER() OVER (
                    PARTITION BY dc.id_documento_cobro, CASE WHEN s.id_modalidad_aplicada = 3 THEN s.id_contrato_arriendo ELSE s.id_contrato_local END
                    ORDER BY s.id_contrato_local
                ) AS rn_clp_fijo_contrato,
                SUM(
                    CASE
                        WHEN s.id_modalidad_aplicada = 3 THEN CAST(ROUND(ISNULL(s.monto_neto_clp, 0), 2) AS DECIMAL(18,2))
                        ELSE CAST(0 AS DECIMAL(18,2))
                    END
                ) OVER (PARTITION BY dc.id_documento_cobro, s.id_contrato_arriendo) AS total_clp_fijo_contrato
            FROM dbo.msp_documentos_cobro dc
            INNER JOIN dbo.msp_arriendo_local_snapshot_periodo s
                ON s.id_tienda = dc.id_tienda
               AND s.periodo_facturacion = @periodo_facturacion
               AND s.estado_snapshot IN (1,2,3)
            INNER JOIN dbo.msp_locales loc
                ON loc.id_local = s.id_local
            WHERE dc.periodo_facturacion = @periodo_facturacion
        )
        INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
        )
        SELECT
            adr.id_documento_cobro,
            ROW_NUMBER() OVER (
                PARTITION BY adr.id_documento_cobro
                ORDER BY
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key,
                    adr.id_contrato_local
            ),
            @id_item_arriendo,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CONCAT(N'Arriendo fijo contrato #', adr.id_contrato_arriendo)
                ELSE CONCAT(N'Arriendo local ', adr.cdo_local)
            END,
            1,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CAST(ROUND(ISNULL(adr.total_clp_fijo_contrato, 0), 2) AS DECIMAL(18,2))
                ELSE adr.valor_arriendo_neto
            END,
            CASE
                WHEN adr.es_clp_fijo_contrato = 1 THEN CAST(ROUND(ISNULL(adr.total_clp_fijo_contrato, 0), 2) AS DECIMAL(18,2))
                ELSE adr.valor_arriendo_neto
            END,
            NULL
        FROM arriendo_detalle_raw adr
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(adr.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(adr.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        WHERE adr.es_clp_fijo_contrato = 0
           OR (adr.es_clp_fijo_contrato = 1 AND adr.rn_clp_fijo_contrato = 1);

        SET @items_generados = @items_generados + @@ROWCOUNT;

        INSERT INTO dbo.msp_documentos_cobro_detalle (
            id_documento_cobro,
            orden_item,
            id_tipo_item_documento,
            descripcion_item,
            cantidad,
            valor_unitario,
            subtotal,
            id_cobro_servicio
        )
        SELECT
            dc.id_documento_cobro,
            1000 + ROW_NUMBER() OVER (
                PARTITION BY dc.id_documento_cobro
                ORDER BY
                    ts.codigo_servicio,
                    CASE
                        WHEN cls.is_alpha_number = 1 THEN 0
                        WHEN cls.is_single_letter = 1 THEN 1
                        WHEN cls.is_numeric = 1 THEN 2
                        WHEN ranker.named_rank IS NOT NULL THEN 3
                        ELSE 4
                    END,
                    CASE WHEN ranker.named_rank IS NOT NULL THEN ranker.named_rank END,
                    CASE WHEN cls.is_alpha_number = 1 THEN LEFT(loc_sort.code_key, 1) END,
                    CASE WHEN cls.is_alpha_number = 1 THEN ranker.numeric_value END,
                    CASE WHEN cls.is_alpha_number = 1 THEN token.suffix_token END,
                    CASE WHEN cls.is_single_letter = 1 THEN loc_sort.code_key END,
                    CASE WHEN cls.is_numeric = 1 THEN TRY_CONVERT(INT, loc_sort.code_key) END,
                    loc_sort.code_key,
                    m.codigo_medidor
            ),
            CASE ts.codigo_servicio
                WHEN N'AGUA' THEN @id_item_agua
                WHEN N'LUZ'  THEN @id_item_luz
                WHEN N'GAS'  THEN @id_item_gas
                ELSE @id_item_gas
            END,
            CONCAT(ts.nombre_servicio, N' local ', loc.cdo_local, N' medidor ', m.codigo_medidor),
            CASE WHEN cs.consumo_cobrado > 0 THEN cs.consumo_cobrado ELSE 1 END,
            CASE
                WHEN cs.consumo_cobrado > 0 THEN ROUND(cs.monto_total / cs.consumo_cobrado, 2)
                ELSE cs.monto_total
            END,
            cs.monto_total,
            cs.id_cobro_servicio
        FROM dbo.msp_cobros_servicios cs
        INNER JOIN dbo.msp_lecturas_medidores lm
            ON lm.id_lectura = cs.id_lectura
        INNER JOIN dbo.msp_procesos_cobro_servicio p
            ON p.id_proceso_cobro = lm.id_proceso_cobro
        INNER JOIN dbo.msp_tipos_servicio ts
            ON ts.id_tipo_servicio = p.id_tipo_servicio
        INNER JOIN dbo.msp_medidores m
            ON m.id_medidor = lm.id_medidor
        INNER JOIN dbo.msp_locales loc
            ON loc.id_local = m.id_local
        CROSS APPLY (
            SELECT
                UPPER(LTRIM(RTRIM(loc.cdo_local))) AS code_key,
                SUBSTRING(UPPER(LTRIM(RTRIM(loc.cdo_local))), 3, 100) AS after_dash
        ) loc_sort
        CROSS APPLY (
            SELECT
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN LEFT(loc_sort.after_dash, LEN(loc_sort.after_dash) - 1)
                    ELSE loc_sort.after_dash
                END AS numeric_token,
                CASE
                    WHEN RIGHT(loc_sort.after_dash, 1) LIKE '[A-Z]' AND LEN(loc_sort.after_dash) > 1
                        THEN RIGHT(loc_sort.after_dash, 1)
                    ELSE ''
                END AS suffix_token
        ) token
        CROSS APPLY (
            SELECT
                TRY_CONVERT(INT, token.numeric_token) AS numeric_value,
                CASE
                    WHEN loc_sort.code_key = 'PELUQUERIA' THEN 0
                    WHEN loc_sort.code_key = 'GYM' THEN 1
                    WHEN loc_sort.code_key = 'OBRA' THEN 2
                    WHEN loc_sort.code_key = 'MODULAR' THEN 3
                    WHEN loc_sort.code_key LIKE 'ESPACIO%' THEN 4
                    ELSE NULL
                END AS named_rank
        ) ranker
        CROSS APPLY (
            SELECT
                CASE
                    WHEN SUBSTRING(loc_sort.code_key, 2, 1) = '-'
                     AND LEFT(loc_sort.code_key, 1) LIKE '[A-Z]'
                     AND ranker.numeric_value IS NOT NULL
                        THEN 1
                    ELSE 0
                END AS is_alpha_number,
                CASE
                    WHEN LEN(loc_sort.code_key) = 1 AND loc_sort.code_key LIKE '[A-Z]'
                        THEN 1
                    ELSE 0
                END AS is_single_letter,
                CASE
                    WHEN loc_sort.code_key <> '' AND loc_sort.code_key NOT LIKE '%[^0-9]%'
                        THEN 1
                    ELSE 0
                END AS is_numeric
        ) cls
        OUTER APPLY (
            SELECT TOP 1
                ca.id_tienda
            FROM dbo.msp_contrato_locales cl
            INNER JOIN dbo.msp_contratos_arriendo ca
                ON ca.id_contrato_arriendo = cl.id_contrato_arriendo
            WHERE cl.id_local = m.id_local
              AND cl.estado_relacion = 1
              AND cl.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino >= @periodo_facturacion)
              AND ca.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= @periodo_facturacion)
              AND ca.estado_contrato IN (1,2,3)
            ORDER BY
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN 0 ELSE 1 END,
                CASE WHEN cl.fecha_inicio <= @periodo_facturacion THEN cl.fecha_inicio END DESC,
                CASE WHEN cl.fecha_inicio > @periodo_facturacion THEN cl.fecha_inicio END ASC,
                cl.id_contrato_local DESC
        ) map
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_tienda = map.id_tienda
           AND dc.periodo_facturacion = @periodo_facturacion
        WHERE p.id_cierre_mensual = @id_cierre_mensual
          AND p.estado_proceso <> 4
          AND map.id_tienda IS NOT NULL;

        SET @items_generados = @items_generados + @@ROWCOUNT;

        ;WITH resumen AS (
            SELECT
                dcd.id_documento_cobro,
                SUM(CASE WHEN tid.codigo_item = N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_arriendo,
                SUM(CASE WHEN tid.codigo_item <> N'ARRIENDO' THEN dcd.subtotal ELSE 0 END) AS subtotal_servicios
            FROM dbo.msp_documentos_cobro_detalle dcd
            INNER JOIN dbo.msp_tipo_item_documento tid
                ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
            INNER JOIN dbo.msp_documentos_cobro dc
                ON dc.id_documento_cobro = dcd.id_documento_cobro
            WHERE dc.periodo_facturacion = @periodo_facturacion
            GROUP BY dcd.id_documento_cobro
        )
        UPDATE dc
        SET dc.subtotal_arriendo = ROUND(r.subtotal_arriendo, 2),
            dc.subtotal_servicios = ROUND(r.subtotal_servicios, 2),
            dc.monto_total = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.saldo_pendiente = ROUND((r.subtotal_arriendo * (1 + @tasa_iva)) + r.subtotal_servicios, 2),
            dc.estado_documento = 2
        FROM dbo.msp_documentos_cobro dc
        INNER JOIN resumen r
            ON r.id_documento_cobro = dc.id_documento_cobro;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_cierre_mensual AS id_cierre_mensual,
        @periodo_facturacion AS periodo_facturacion,
        @documentos_generados AS documentos_generados,
        @items_generados AS items_generados;
END;
GO

/* =========================================================================
   8. PROCEDIMIENTO: GUARDAR DETALLE DE PAGO POR CONCEPTO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_guardar_pago_detalle_conceptos
    @id_pago                     INT,
    @id_documento_cobro          INT,
    @monto_aplicado              DECIMAL(18,2),
    @detalle_conceptos_json      NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @subtotal_arriendo DECIMAL(18,2);
    DECLARE @subtotal_servicios DECIMAL(18,2);
    DECLARE @monto_total DECIMAL(18,2);
    DECLARE @iva_arriendo DECIMAL(18,2);
    DECLARE @id_tipo_arriendo INT;

    DECLARE @conceptos_base TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_total DECIMAL(18,2) NOT NULL
    );

    DECLARE @saldos_concepto TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        codigo_item NVARCHAR(30) NOT NULL,
        nombre_item NVARCHAR(100) NOT NULL,
        prioridad INT NOT NULL,
        monto_disponible DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_solicitado TABLE (
        id_tipo_item_documento INT NOT NULL,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    DECLARE @detalle_normalizado TABLE (
        id_tipo_item_documento INT NOT NULL PRIMARY KEY,
        monto_aplicado DECIMAL(18,2) NOT NULL
    );

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50111, 'Debes indicar un pago valido para guardar el detalle de conceptos.', 1;
    END;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    IF @monto_aplicado IS NULL OR @monto_aplicado <= 0
    BEGIN
        ;THROW 50113, 'El monto aplicado debe ser mayor a cero para distribuir conceptos.', 1;
    END;

    SELECT
        @subtotal_arriendo = dc.subtotal_arriendo,
        @subtotal_servicios = dc.subtotal_servicios,
        @monto_total = dc.monto_total
    FROM dbo.msp_documentos_cobro dc
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @monto_total IS NULL
    BEGIN
        ;THROW 50112, 'Debes indicar un documento valido para guardar el detalle de conceptos.', 1;
    END;

    SELECT @id_tipo_arriendo = tid.id_tipo_item_documento
    FROM dbo.msp_tipo_item_documento tid
    WHERE tid.codigo_item = N'ARRIENDO';

    INSERT INTO @conceptos_base (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_total
    )
    SELECT
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item,
        CASE tid.codigo_item
            WHEN N'ARRIENDO' THEN 10
            WHEN N'SERVICIO_LUZ' THEN 20
            WHEN N'SERVICIO_GAS' THEN 30
            WHEN N'SERVICIO_AGUA' THEN 40
            WHEN N'MULTA' THEN 50
            WHEN N'DANO' THEN 60
            WHEN N'AJUSTE' THEN 70
            ELSE 80
        END AS prioridad,
        ROUND(SUM(dcd.subtotal), 2) AS monto_total
    FROM dbo.msp_documentos_cobro_detalle dcd
    INNER JOIN dbo.msp_tipo_item_documento tid
        ON tid.id_tipo_item_documento = dcd.id_tipo_item_documento
    WHERE dcd.id_documento_cobro = @id_documento_cobro
    GROUP BY
        tid.id_tipo_item_documento,
        tid.codigo_item,
        tid.nombre_item;

    SET @iva_arriendo = ROUND(ISNULL(@monto_total, 0) - ISNULL(@subtotal_arriendo, 0) - ISNULL(@subtotal_servicios, 0), 2);
    IF @iva_arriendo < 0
    BEGIN
        SET @iva_arriendo = 0;
    END;

    IF @id_tipo_arriendo IS NOT NULL
    BEGIN
        IF EXISTS (
            SELECT 1
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo
        )
        BEGIN
            UPDATE cb
            SET cb.monto_total = ROUND(cb.monto_total + @iva_arriendo, 2)
            FROM @conceptos_base cb
            WHERE cb.id_tipo_item_documento = @id_tipo_arriendo;
        END
        ELSE IF ISNULL(@subtotal_arriendo, 0) > 0 OR @iva_arriendo > 0
        BEGIN
            INSERT INTO @conceptos_base (
                id_tipo_item_documento,
                codigo_item,
                nombre_item,
                prioridad,
                monto_total
            )
            SELECT
                tid.id_tipo_item_documento,
                tid.codigo_item,
                tid.nombre_item,
                10,
                ROUND(ISNULL(@subtotal_arriendo, 0) + @iva_arriendo, 2)
            FROM dbo.msp_tipo_item_documento tid
            WHERE tid.id_tipo_item_documento = @id_tipo_arriendo;
        END;
    END;

    INSERT INTO @saldos_concepto (
        id_tipo_item_documento,
        codigo_item,
        nombre_item,
        prioridad,
        monto_disponible
    )
    SELECT
        cb.id_tipo_item_documento,
        cb.codigo_item,
        cb.nombre_item,
        cb.prioridad,
        ROUND(
            CASE
                WHEN cb.monto_total - ISNULL(ap.aplicado, 0) < 0 THEN 0
                ELSE cb.monto_total - ISNULL(ap.aplicado, 0)
            END,
            2
        ) AS monto_disponible
    FROM @conceptos_base cb
    OUTER APPLY (
        SELECT SUM(pdc.monto_aplicado) AS aplicado
        FROM dbo.msp_pagos_detalle_concepto pdc
        INNER JOIN dbo.msp_pagos p
            ON p.id_pago = pdc.id_pago
        WHERE pdc.id_documento_cobro = @id_documento_cobro
          AND pdc.id_tipo_item_documento = cb.id_tipo_item_documento
          AND p.estado_pago = 1
          AND p.id_pago <> @id_pago
    ) ap
    WHERE cb.monto_total > 0;

    IF NOT EXISTS (SELECT 1 FROM @saldos_concepto)
    BEGIN
        ;THROW 50123, 'No fue posible distribuir conceptos: el documento no tiene conceptos pendientes.', 1;
    END;

    DELETE FROM dbo.msp_pagos_detalle_concepto
    WHERE id_pago = @id_pago;

    IF LTRIM(RTRIM(ISNULL(@detalle_conceptos_json, N''))) <> N''
    BEGIN
        INSERT INTO @detalle_solicitado (id_tipo_item_documento, monto_aplicado)
        SELECT
            TRY_CAST(JSON_VALUE(js.value, '$.id_tipo_item_documento') AS INT) AS id_tipo_item_documento,
            TRY_CAST(JSON_VALUE(js.value, '$.monto') AS DECIMAL(18,2)) AS monto_aplicado
        FROM OPENJSON(@detalle_conceptos_json) js;

        IF NOT EXISTS (SELECT 1 FROM @detalle_solicitado)
        BEGIN
            ;THROW 50125, 'Debes indicar al menos un concepto de pago.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_solicitado ds
            WHERE ds.id_tipo_item_documento IS NULL
               OR ds.id_tipo_item_documento <= 0
               OR ds.monto_aplicado IS NULL
               OR ds.monto_aplicado <= 0
        )
        BEGIN
            ;THROW 50127, 'El detalle de conceptos contiene valores invalidos.', 1;
        END;

        INSERT INTO @detalle_normalizado (id_tipo_item_documento, monto_aplicado)
        SELECT
            ds.id_tipo_item_documento,
            ROUND(SUM(ds.monto_aplicado), 2)
        FROM @detalle_solicitado ds
        GROUP BY ds.id_tipo_item_documento;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            LEFT JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE sc.id_tipo_item_documento IS NULL
        )
        BEGIN
            ;THROW 50124, 'Hay conceptos que no existen en el documento o no tienen saldo disponible.', 1;
        END;

        IF EXISTS (
            SELECT 1
            FROM @detalle_normalizado dn
            INNER JOIN @saldos_concepto sc
                ON sc.id_tipo_item_documento = dn.id_tipo_item_documento
            WHERE dn.monto_aplicado > sc.monto_disponible + 0.01
        )
        BEGIN
            ;THROW 50122, 'El monto de un concepto excede el saldo disponible del concepto.', 1;
        END;

        IF ABS(
            ROUND(
                ISNULL((SELECT SUM(dn.monto_aplicado) FROM @detalle_normalizado dn), 0)
                - @monto_aplicado,
                2
            )
        ) > 0.01
        BEGIN
            ;THROW 50121, 'La suma de conceptos no coincide con el monto aplicado al documento.', 1;
        END;

        INSERT INTO dbo.msp_pagos_detalle_concepto (
            id_pago,
            id_documento_cobro,
            id_tipo_item_documento,
            monto_aplicado
        )
        SELECT
            @id_pago,
            @id_documento_cobro,
            dn.id_tipo_item_documento,
            dn.monto_aplicado
        FROM @detalle_normalizado dn;
    END
    ELSE
    BEGIN
        DECLARE @id_tipo_actual INT;
        DECLARE @disponible_actual DECIMAL(18,2);
        DECLARE @monto_asignado DECIMAL(18,2);
        DECLARE @restante DECIMAL(18,2);

        SET @restante = ROUND(@monto_aplicado, 2);

        DECLARE cur_conceptos CURSOR LOCAL FAST_FORWARD FOR
            SELECT sc.id_tipo_item_documento, sc.monto_disponible
            FROM @saldos_concepto sc
            WHERE sc.monto_disponible > 0
            ORDER BY sc.prioridad ASC, sc.id_tipo_item_documento ASC;

        OPEN cur_conceptos;

        FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        WHILE @@FETCH_STATUS = 0 AND @restante > 0.01
        BEGIN
            SET @monto_asignado = CASE
                WHEN @restante < @disponible_actual THEN @restante
                ELSE @disponible_actual
            END;

            SET @monto_asignado = ROUND(@monto_asignado, 2);

            IF @monto_asignado > 0
            BEGIN
                INSERT INTO dbo.msp_pagos_detalle_concepto (
                    id_pago,
                    id_documento_cobro,
                    id_tipo_item_documento,
                    monto_aplicado
                )
                VALUES (
                    @id_pago,
                    @id_documento_cobro,
                    @id_tipo_actual,
                    @monto_asignado
                );

                SET @restante = ROUND(@restante - @monto_asignado, 2);
            END;

            FETCH NEXT FROM cur_conceptos INTO @id_tipo_actual, @disponible_actual;
        END;

        CLOSE cur_conceptos;
        DEALLOCATE cur_conceptos;

        IF @restante > 0.01
        BEGIN
            ;THROW 50123, 'No fue posible distribuir automaticamente el pago por concepto.', 1;
        END;
    END;
END;
GO

/* =========================================================================
   9. PROCEDIMIENTO: REGISTRAR PAGO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_registrar_pago_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_pagado           DECIMAL(18,2),
    @medio_pago             NVARCHAR(50) = NULL,
    @referencia_pago        NVARCHAR(100) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @monto_aplicado DECIMAL(18,2);
    DECLARE @monto_excedente DECIMAL(18,2);
    DECLARE @id_pago_generado INT;
    DECLARE @saldo_favor_tienda DECIMAL(18,2) = 0;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50061, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50062, 'Debes indicar la fecha del pago.', 1;
    END;

    IF @monto_pagado IS NULL OR @monto_pagado <= 0
    BEGIN
        ;THROW 50063, 'El monto_pagado debe ser mayor a cero.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50064, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50065, 'El documento no tiene saldo pendiente para recibir pagos.', 1;
    END;

    SET @monto_aplicado = CASE
        WHEN @monto_pagado > @saldo_pendiente THEN @saldo_pendiente
        ELSE @monto_pagado
    END;
    SET @monto_excedente = ROUND(@monto_pagado - @monto_aplicado, 2);

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_aplicado,
            @monto_excedente,
            0,
            1,
            @medio_pago,
            @referencia_pago,
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_aplicado,
            @detalle_conceptos_json = @detalle_conceptos_json;

        IF @monto_excedente > 0
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_pago,
                1,
                @monto_excedente,
                @id_documento_cobro,
                @id_pago_generado,
                CONCAT(N'Excedente de pago registrado sobre documento #', @id_documento_cobro)
            );
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT @saldo_favor_tienda = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_aplicado AS monto_aplicado_documento,
        @monto_excedente AS monto_saldo_favor_generado,
        @saldo_favor_tienda AS saldo_favor_tienda;
END;
GO

/* =========================================================================
   10. PROCEDIMIENTO: APLICAR SALDO A FAVOR
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_aplicar_saldo_favor_documento
    @id_documento_cobro     INT,
    @fecha_pago             DATE,
    @monto_aplicar          DECIMAL(18,2) = NULL,
    @observaciones          NVARCHAR(500) = NULL,
    @detalle_conceptos_json NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_tienda INT;
    DECLARE @saldo_pendiente DECIMAL(18,2);
    DECLARE @estado_documento TINYINT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);
    DECLARE @monto_real DECIMAL(18,2);
    DECLARE @id_pago_generado INT;

    IF @id_documento_cobro IS NULL OR @id_documento_cobro <= 0
    BEGIN
        ;THROW 50081, 'Debes indicar un documento de cobro valido.', 1;
    END;

    IF @fecha_pago IS NULL
    BEGIN
        ;THROW 50082, 'Debes indicar la fecha de aplicación.', 1;
    END;

    SELECT
        @id_tienda = dc.id_tienda,
        @saldo_pendiente = dc.saldo_pendiente,
        @estado_documento = dc.estado_documento
    FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
    WHERE dc.id_documento_cobro = @id_documento_cobro;

    IF @id_tienda IS NULL
    BEGIN
        ;THROW 50083, 'El documento de cobro indicado no existe.', 1;
    END;

    IF @estado_documento = 5
    BEGIN
        ;THROW 50041, 'No se pueden registrar pagos sobre documentos anulados.', 1;
    END;

    IF ISNULL(@saldo_pendiente, 0) <= 0
    BEGIN
        ;THROW 50084, 'El documento no tiene saldo pendiente para aplicar saldo a favor.', 1;
    END;

    SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
    FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
    WHERE sf.id_tienda = @id_tienda;

    SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

    IF @saldo_favor_disponible <= 0
    BEGIN
        ;THROW 50085, 'La tienda no tiene saldo a favor disponible.', 1;
    END;

    IF @monto_aplicar IS NULL
    BEGIN
        SET @monto_real = CASE
            WHEN @saldo_favor_disponible < @saldo_pendiente THEN @saldo_favor_disponible
            ELSE @saldo_pendiente
        END;
    END
    ELSE
    BEGIN
        IF @monto_aplicar <= 0
        BEGIN
            ;THROW 50086, 'El monto a aplicar debe ser mayor a cero.', 1;
        END;

        IF @monto_aplicar > @saldo_favor_disponible
        BEGIN
            ;THROW 50087, 'El monto a aplicar excede el saldo a favor disponible.', 1;
        END;

        IF @monto_aplicar > @saldo_pendiente
        BEGIN
            ;THROW 50088, 'El monto a aplicar excede el saldo pendiente del documento.', 1;
        END;

        SET @monto_real = @monto_aplicar;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.msp_pagos (
            id_documento_cobro,
            fecha_pago,
            monto_pagado,
            monto_saldo_favor_generado,
            aplica_desde_saldo_favor,
            estado_pago,
            medio_pago,
            referencia_pago,
            observaciones
        )
        VALUES (
            @id_documento_cobro,
            @fecha_pago,
            @monto_real,
            0,
            1,
            1,
            N'Saldo a favor',
            N'Aplicación de saldo a favor tienda',
            @observaciones
        );

        SET @id_pago_generado = CAST(SCOPE_IDENTITY() AS INT);

        EXEC dbo.msp_guardar_pago_detalle_conceptos
            @id_pago = @id_pago_generado,
            @id_documento_cobro = @id_documento_cobro,
            @monto_aplicado = @monto_real,
            @detalle_conceptos_json = @detalle_conceptos_json;

        INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
            id_tienda,
            fecha_movimiento,
            tipo_movimiento,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_tienda,
            @fecha_pago,
            2,
            -@monto_real,
            @id_documento_cobro,
            @id_pago_generado,
            CONCAT(N'Aplicación de saldo a favor sobre documento #', @id_documento_cobro)
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @monto_real AS monto_aplicado,
        ISNULL(sf.saldo_disponible, 0) AS saldo_favor_restante
    FROM dbo.msp_saldos_favor_tienda sf
    WHERE sf.id_tienda = @id_tienda;
END;
GO

/* =========================================================================
   11. PROCEDIMIENTO: ANULAR PAGO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_anular_pago_documento
    @id_pago                INT,
    @fecha_anulacion        DATE,
    @motivo_anulacion       NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_documento_cobro INT;
    DECLARE @id_tienda INT;
    DECLARE @monto_pagado DECIMAL(18,2);
    DECLARE @monto_saldo_favor_generado DECIMAL(18,2);
    DECLARE @aplica_desde_saldo_favor BIT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50071, 'Debes indicar un pago valido.', 1;
    END;

    IF @fecha_anulacion IS NULL
    BEGIN
        ;THROW 50072, 'Debes indicar la fecha de anulacion.', 1;
    END;

    IF @motivo_anulacion IS NULL OR LTRIM(RTRIM(@motivo_anulacion)) = N''
    BEGIN
        ;THROW 50073, 'Debes indicar un motivo de anulacion.', 1;
    END;

    SELECT
        @id_documento_cobro = p.id_documento_cobro,
        @id_tienda = dc.id_tienda,
        @monto_pagado = p.monto_pagado,
        @monto_saldo_favor_generado = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_desde_saldo_favor = ISNULL(p.aplica_desde_saldo_favor, 0)
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = p.id_documento_cobro
    WHERE p.id_pago = @id_pago
      AND p.estado_pago = 1;

    IF @id_documento_cobro IS NULL
    BEGIN
        ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @aplica_desde_saldo_favor = 1
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                4,
                @monto_pagado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de aplicación de saldo a favor por anulación de pago #', @id_pago)
            );
        END
        ELSE IF @monto_saldo_favor_generado > 0
        BEGIN
            SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
            FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
            WHERE sf.id_tienda = @id_tienda;

            SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

            IF @saldo_favor_disponible < @monto_saldo_favor_generado
            BEGIN
                ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
            END;

            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                3,
                -@monto_saldo_favor_generado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de excedente por anulación de pago #', @id_pago)
            );
        END;

        UPDATE dbo.msp_pagos
        SET estado_pago = 2,
            fecha_anulacion = @fecha_anulacion,
            motivo_anulacion = @motivo_anulacion
        WHERE id_pago = @id_pago
          AND estado_pago = 1;

        IF @@ROWCOUNT = 0
        BEGIN
            ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT 'P3';
GO
