/*
===========================================================================
 MSP - DEUDA Y GARANTIA
 SQL Server / esquema dbo
 - Requiere A1 ya instalado: msp_agrupacion_locales.sql
 - Requiere A22 ya instalado: msp_documento_pago.sql
 - Alcance intencionalmente acotado:
   contrato por tienda, garantia por local, cargos por local, movimientos,
   bitacora de cierre e historial contractual
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. CATALOGOS BASE
   ========================================================================= */

CREATE TABLE dbo.msp_tipos_movimiento_garantia (
    id_tipo_movimiento_garantia INT NOT NULL,
    codigo_movimiento           NVARCHAR(50) NOT NULL,
    nombre_movimiento           NVARCHAR(120) NOT NULL,
    activo                      BIT NOT NULL CONSTRAINT DF_msp_tipos_mov_garantia_activo DEFAULT (1),
    CONSTRAINT PK_msp_tipos_movimiento_garantia PRIMARY KEY (id_tipo_movimiento_garantia),
    CONSTRAINT UQ_msp_tipos_movimiento_garantia_codigo UNIQUE (codigo_movimiento),
    CONSTRAINT UQ_msp_tipos_movimiento_garantia_nombre UNIQUE (nombre_movimiento)
);
GO

INSERT INTO dbo.msp_tipos_movimiento_garantia (
    id_tipo_movimiento_garantia,
    codigo_movimiento,
    nombre_movimiento
)
VALUES
    (1, N'CONSTITUCION',       N'Constitucion de garantia'),
    (2, N'RESERVA',            N'Reserva de garantia'),
    (3, N'LIBERACION_RESERVA', N'Liberacion de reserva'),
    (4, N'APLICACION_CARGO',   N'Aplicacion de garantia a cargo'),
    (5, N'DEVOLUCION',         N'Devolucion de garantia'),
    (6, N'AJUSTE_POSITIVO',    N'Ajuste positivo'),
    (7, N'AJUSTE_NEGATIVO',    N'Ajuste negativo');
GO

CREATE TABLE dbo.msp_tipos_cargo_salida (
    id_tipo_cargo_salida      INT NOT NULL,
    codigo_tipo_cargo         NVARCHAR(50) NOT NULL,
    nombre_tipo_cargo         NVARCHAR(120) NOT NULL,
    requiere_documento        BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_requiere_doc DEFAULT (0),
    permite_estimacion        BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_perm_est DEFAULT (0),
    activo                    BIT NOT NULL CONSTRAINT DF_msp_tipos_cargo_activo DEFAULT (1),
    CONSTRAINT PK_msp_tipos_cargo_salida PRIMARY KEY (id_tipo_cargo_salida),
    CONSTRAINT UQ_msp_tipos_cargo_salida_codigo UNIQUE (codigo_tipo_cargo),
    CONSTRAINT UQ_msp_tipos_cargo_salida_nombre UNIQUE (nombre_tipo_cargo)
);
GO

INSERT INTO dbo.msp_tipos_cargo_salida (
    id_tipo_cargo_salida,
    codigo_tipo_cargo,
    nombre_tipo_cargo,
    requiere_documento,
    permite_estimacion
)
VALUES
    (4, N'MULTA',              N'Multa',                       0, 0),
    (5, N'DANOS',              N'Daños o reparaciones',        0, 0),
    (6, N'OTRO',               N'Otro cargo',                  0, 1);
GO

/* =========================================================================
   2. CONTRATOS DE ARRIENDO
   Estado:
     1 = Borrador
     2 = Vigente
     3 = En cierre financiero
     4 = Cerrado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_contratos_arriendo (
    id_contrato_arriendo     INT IDENTITY(1,1) NOT NULL,
    id_tienda                INT NOT NULL,
    id_arrendatario          INT NOT NULL,
    fecha_inicio             DATE NOT NULL,
    fecha_termino_pactada    DATE NULL,
    fecha_termino_efectiva   DATE NULL,
    dia_cobro                TINYINT NULL CONSTRAINT DF_msp_contratos_dia_cobro DEFAULT (1),
    monto_arriendo_pactado   DECIMAL(18,2) NULL,
    rubro_contrato           NVARCHAR(150) NULL,
    estado_contrato          TINYINT NOT NULL CONSTRAINT DF_msp_contratos_estado DEFAULT (2),
    observaciones            NVARCHAR(1000) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_contratos_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_contratos_arriendo PRIMARY KEY (id_contrato_arriendo),
    CONSTRAINT FK_msp_contratos_tienda
        FOREIGN KEY (id_tienda) REFERENCES dbo.msp_tiendas (id_tienda),
    CONSTRAINT FK_msp_contratos_arrendatario
        FOREIGN KEY (id_arrendatario) REFERENCES dbo.msp_arrendatarios (id_arrendatario),
    CONSTRAINT CK_msp_contratos_estado CHECK (estado_contrato IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_contratos_dia_cobro CHECK (
        dia_cobro IS NULL OR dia_cobro BETWEEN 1 AND 31
    ),
    CONSTRAINT CK_msp_contratos_monto_arriendo CHECK (
        monto_arriendo_pactado IS NULL OR monto_arriendo_pactado >= 0
    ),
    CONSTRAINT CK_msp_contratos_fechas CHECK (
        (fecha_termino_pactada IS NULL OR fecha_termino_pactada >= fecha_inicio)
        AND (fecha_termino_efectiva IS NULL OR fecha_termino_efectiva >= fecha_inicio)
    )
);
GO

CREATE UNIQUE INDEX UX_msp_contratos_tienda_activo
    ON dbo.msp_contratos_arriendo (id_tienda)
    WHERE estado_contrato IN (1,2);
GO

CREATE INDEX IX_msp_contratos_arrendatario
    ON dbo.msp_contratos_arriendo (id_arrendatario, estado_contrato, fecha_inicio DESC);
GO

/* =========================================================================
   3. BITACORA DE CIERRE DE CONTRATO
   ========================================================================= */

CREATE TABLE dbo.msp_bitacora_cierre_contrato (
    id_bitacora_cierre_contrato INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo        INT NOT NULL,
    id_usuario                  INT NOT NULL,
    estado_contrato_anterior    TINYINT NOT NULL,
    estado_contrato_nuevo       TINYINT NOT NULL,
    motivo_cierre               NVARCHAR(500) NOT NULL,
    fecha_registro              DATETIME2(0) NOT NULL CONSTRAINT DF_msp_bitacora_cierre_contrato_fecha DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_bitacora_cierre_contrato PRIMARY KEY (id_bitacora_cierre_contrato),
    CONSTRAINT FK_msp_bitacora_cierre_contrato_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT CK_msp_bitacora_cierre_contrato_estados
        CHECK (estado_contrato_anterior IN (1,2,3,4,5) AND estado_contrato_nuevo IN (1,2,3,4,5))
);
GO

CREATE INDEX IX_msp_bitacora_cierre_contrato_contrato_fecha
    ON dbo.msp_bitacora_cierre_contrato (id_contrato_arriendo, fecha_registro DESC);
GO

/* =========================================================================
   4. HISTORIAL DE CAMBIOS DE CONTRATO
   ========================================================================= */

CREATE TABLE dbo.msp_historial_contrato (
    id_historial_contrato      INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo       INT NOT NULL,
    tipo_evento                NVARCHAR(30) NOT NULL,
    id_usuario                 INT NOT NULL,
    detalle_evento             NVARCHAR(MAX) NULL,
    motivo_evento              NVARCHAR(500) NULL,
    fecha_registro             DATETIME2(0) NOT NULL CONSTRAINT DF_msp_historial_contrato_fecha DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_historial_contrato PRIMARY KEY (id_historial_contrato),
    CONSTRAINT FK_msp_historial_contrato_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT CK_msp_historial_contrato_tipo
        CHECK (tipo_evento IN (N'CREACION', N'ACTUALIZACION', N'CIERRE'))
);
GO

CREATE INDEX IX_msp_historial_contrato_contrato_fecha
    ON dbo.msp_historial_contrato (id_contrato_arriendo, fecha_registro DESC);
GO

/* =========================================================================
   5. GARANTIAS POR LOCAL
   Estado:
     1 = Vigente
     2 = Con reserva
     3 = Aplicada parcial
     4 = Devuelta parcial
     5 = Cerrada
     6 = Anulada
   ========================================================================= */

CREATE TABLE dbo.msp_garantias (
    id_garantia              INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo     INT NOT NULL,
    id_local                 INT NOT NULL,
    fecha_constitucion       DATE NOT NULL,
    monto_inicial            DECIMAL(18,2) NOT NULL,
    estado_garantia          TINYINT NOT NULL CONSTRAINT DF_msp_garantias_estado DEFAULT (1),
    observaciones            NVARCHAR(500) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantias_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_garantias PRIMARY KEY (id_garantia),
    CONSTRAINT FK_msp_garantias_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT FK_msp_garantias_local
        FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT UQ_msp_garantias_contrato_local UNIQUE (id_contrato_arriendo, id_local),
    CONSTRAINT CK_msp_garantias_monto CHECK (monto_inicial >= 0),
    CONSTRAINT CK_msp_garantias_estado CHECK (estado_garantia IN (1,2,3,4,5,6))
);
GO

CREATE INDEX IX_msp_garantias_contrato
    ON dbo.msp_garantias (id_contrato_arriendo, estado_garantia, id_local);
GO

/* =========================================================================
   6. CARGOS POR CONTRATO / TIENDA
   id_local es opcional: NULL representa un cargo aplicado a toda la tienda.
   Origen:
     1 = Documento
     2 = Estimacion manual
     3 = Multa
     4 = Manual

   Estado:
     1 = Pendiente
     2 = Reservado
     3 = Aplicado
     4 = Pagado
     5 = Anulado
   ========================================================================= */

CREATE TABLE dbo.msp_cargos_salida (
    id_cargo_salida          INT IDENTITY(1,1) NOT NULL,
    id_contrato_arriendo     INT NOT NULL,
    id_local                 INT NULL,
    id_tipo_cargo_salida     INT NOT NULL,
    fecha_cargo              DATE NOT NULL CONSTRAINT DF_msp_cargos_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
    origen_cargo             TINYINT NOT NULL,
    id_documento_cobro       INT NULL,
    periodo_referencia       DATE NULL,
    servicio_referencia      NVARCHAR(30) NULL,
    descripcion_cargo        NVARCHAR(500) NOT NULL,
    monto_cargo              DECIMAL(18,2) NOT NULL,
    es_estimado              BIT NOT NULL CONSTRAINT DF_msp_cargos_estimado DEFAULT (0),
    estado_cargo             TINYINT NOT NULL CONSTRAINT DF_msp_cargos_estado DEFAULT (1),
    observaciones            NVARCHAR(500) NULL,
    fecha_registro           DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cargos_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_cargos_salida PRIMARY KEY (id_cargo_salida),
    CONSTRAINT FK_msp_cargos_contrato
        FOREIGN KEY (id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo),
    CONSTRAINT FK_msp_cargos_local
        FOREIGN KEY (id_local) REFERENCES dbo.msp_locales (id_local),
    CONSTRAINT FK_msp_cargos_tipo
        FOREIGN KEY (id_tipo_cargo_salida) REFERENCES dbo.msp_tipos_cargo_salida (id_tipo_cargo_salida),
    CONSTRAINT FK_msp_cargos_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT CK_msp_cargos_origen CHECK (origen_cargo IN (1,2,3,4)),
    CONSTRAINT CK_msp_cargos_estado CHECK (estado_cargo IN (1,2,3,4,5)),
    CONSTRAINT CK_msp_cargos_monto CHECK (monto_cargo > 0),
    CONSTRAINT CK_msp_cargos_periodo CHECK (
        periodo_referencia IS NULL OR DAY(periodo_referencia) = 1
    )
);
GO

CREATE INDEX IX_msp_cargos_contrato_local
    ON dbo.msp_cargos_salida (id_contrato_arriendo, id_local, estado_cargo, fecha_cargo DESC);
GO

CREATE INDEX IX_msp_cargos_documento
    ON dbo.msp_cargos_salida (id_documento_cobro, id_cargo_salida DESC);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_multa_alcance_tienda
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE cs
    SET cs.id_local = NULL
    FROM dbo.msp_cargos_salida cs
    INNER JOIN inserted i
        ON i.id_cargo_salida = cs.id_cargo_salida
    INNER JOIN dbo.msp_tipos_cargo_salida tc
        ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
    WHERE UPPER(LTRIM(RTRIM(tc.codigo_tipo_cargo))) = N'MULTA'
      AND cs.id_local IS NOT NULL;
END;
GO

/* =========================================================================
   7. MOVIMIENTOS DE GARANTIA
   fondo_origen:
     D = Disponible
     R = Reservado
     NULL = No aplica
   ========================================================================= */

CREATE TABLE dbo.msp_movimientos_garantia (
    id_movimiento_garantia       INT IDENTITY(1,1) NOT NULL,
    id_garantia                  INT NOT NULL,
    fecha_movimiento             DATE NOT NULL CONSTRAINT DF_msp_mov_garantia_fecha DEFAULT (CONVERT(DATE, SYSDATETIME())),
    id_tipo_movimiento_garantia  INT NOT NULL,
    fondo_origen                 CHAR(1) NULL,
    monto_movimiento             DECIMAL(18,2) NOT NULL,
    id_cargo_salida              INT NULL,
    id_documento_cobro           INT NULL,
    id_pago                      INT NULL,
    observaciones                NVARCHAR(500) NULL,
    fecha_registro               DATETIME2(0) NOT NULL CONSTRAINT DF_msp_mov_garantia_registro DEFAULT (SYSDATETIME()),
    CONSTRAINT PK_msp_movimientos_garantia PRIMARY KEY (id_movimiento_garantia),
    CONSTRAINT FK_msp_mov_garantia_garantia
        FOREIGN KEY (id_garantia) REFERENCES dbo.msp_garantias (id_garantia),
    CONSTRAINT FK_msp_mov_garantia_tipo
        FOREIGN KEY (id_tipo_movimiento_garantia) REFERENCES dbo.msp_tipos_movimiento_garantia (id_tipo_movimiento_garantia),
    CONSTRAINT FK_msp_mov_garantia_cargo
        FOREIGN KEY (id_cargo_salida) REFERENCES dbo.msp_cargos_salida (id_cargo_salida),
    CONSTRAINT FK_msp_mov_garantia_documento
        FOREIGN KEY (id_documento_cobro) REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
    CONSTRAINT FK_msp_mov_garantia_pago
        FOREIGN KEY (id_pago) REFERENCES dbo.msp_pagos (id_pago),
    CONSTRAINT CK_msp_mov_garantia_monto CHECK (monto_movimiento > 0),
    CONSTRAINT CK_msp_mov_garantia_fondo CHECK (
        fondo_origen IS NULL OR fondo_origen IN ('D', 'R')
    )
);
GO

CREATE INDEX IX_msp_mov_garantia_garantia
    ON dbo.msp_movimientos_garantia (id_garantia, fecha_movimiento DESC, id_movimiento_garantia DESC);
GO

CREATE INDEX IX_msp_mov_garantia_cargo
    ON dbo.msp_movimientos_garantia (id_cargo_salida, id_movimiento_garantia DESC);
GO

/* =========================================================================
   8. TRIGGERS DE CONSISTENCIA
   ========================================================================= */

CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_valida_local_contrato
ON dbo.msp_garantias
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

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
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_valida_local_contrato
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        LEFT JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_tienda = c.id_tienda
           AND ol.id_local = i.id_local
        WHERE i.id_local IS NOT NULL
          AND ol.id_ocupacion_local IS NULL
    )
    BEGIN
        ;THROW 50302, 'El cargo debe asociarse a un local que pertenezca a la tienda del contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = i.id_tipo_cargo_salida
        WHERE tc.requiere_documento = 1
          AND i.id_documento_cobro IS NULL
    )
    BEGIN
        ;THROW 50303, 'El tipo de cargo exige un documento asociado.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = i.id_documento_cobro
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        WHERE i.id_documento_cobro IS NOT NULL
          AND dc.id_tienda <> c.id_tienda
    )
    BEGIN
        ;THROW 50304, 'El documento asociado al cargo no pertenece a la tienda del contrato.', 1;
    END;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_valida_garantia_cargo
ON dbo.msp_movimientos_garantia
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g
            ON g.id_garantia = i.id_garantia
        INNER JOIN dbo.msp_cargos_salida cs
            ON cs.id_cargo_salida = i.id_cargo_salida
        WHERE i.id_cargo_salida IS NOT NULL
          AND (
                cs.id_contrato_arriendo <> g.id_contrato_arriendo
                OR cs.id_local <> g.id_local
              )
    )
    BEGIN
        ;THROW 50305, 'La garantia solo puede cubrir cargos de su mismo local y contrato.', 1;
    END;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        WHERE i.id_tipo_movimiento_garantia IN (2,3,4)
          AND i.id_cargo_salida IS NULL
    )
    BEGIN
        ;THROW 50306, 'Reserva, liberacion y aplicacion deben referenciar un cargo.', 1;
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
   9. VISTAS DE APOYO
   ========================================================================= */

CREATE OR ALTER VIEW dbo.msp_vw_garantias_resumen
AS
SELECT
    g.id_garantia,
    g.id_contrato_arriendo,
    g.id_local,
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
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = g.id_contrato_arriendo
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
    c.id_contrato_arriendo,
    c.id_tienda,
    c.id_arrendatario,
    g.id_local,
    g.id_garantia,
    gr.monto_inicial,
    gr.saldo_disponible,
    gr.saldo_reservado,
    gr.saldo_aplicado,
    CAST(ISNULL(cg.total_cargos, 0) AS DECIMAL(18,2)) AS total_cargos,
    CAST(ISNULL(cg.total_pendiente, 0) AS DECIMAL(18,2)) AS total_cargos_pendientes,
    CAST(ISNULL(cg.total_reservado, 0) AS DECIMAL(18,2)) AS total_cargos_reservados,
    CAST(ISNULL(cg.total_aplicado, 0) AS DECIMAL(18,2)) AS total_cargos_aplicados
FROM dbo.msp_garantias g
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = g.id_contrato_arriendo
INNER JOIN dbo.msp_vw_garantias_resumen gr
    ON gr.id_garantia = g.id_garantia
OUTER APPLY (
    SELECT
        SUM(cs.monto_cargo) AS total_cargos,
        SUM(CASE WHEN cs.estado_cargo = 1 THEN cs.monto_cargo ELSE 0 END) AS total_pendiente,
        SUM(CASE WHEN cs.estado_cargo = 2 THEN cs.monto_cargo ELSE 0 END) AS total_reservado,
        SUM(CASE WHEN cs.estado_cargo = 3 THEN cs.monto_cargo ELSE 0 END) AS total_aplicado
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = g.id_contrato_arriendo
      AND cs.id_local = g.id_local
      AND cs.estado_cargo <> 5
) cg;
GO

PRINT 'P4';
GO
