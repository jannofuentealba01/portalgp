/*
  Prerrequisito del flujo de garantias y tesoreria.
  - msp_garantias.monto_inicial conserva el monto pactado.
  - msp_garantia_recepciones registra dinero/cheques efectivamente recibidos.
  - msp_tesoreria_movimientos deja la trazabilidad operativa de caja y banco.
  El patch es idempotente y no convierte automaticamente datos historicos ambiguos.
*/
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_cuentas', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tesoreria_cuentas (
        id_cuenta_tesoreria INT IDENTITY(1,1) NOT NULL,
        codigo_cuenta NVARCHAR(30) NOT NULL,
        nombre_cuenta NVARCHAR(150) NOT NULL,
        tipo_cuenta NVARCHAR(20) NOT NULL,
        banco NVARCHAR(120) NULL,
        numero_cuenta NVARCHAR(80) NULL,
        moneda CHAR(3) NOT NULL CONSTRAINT DF_msp_tesoreria_cuentas_moneda DEFAULT ('CLP'),
        activo BIT NOT NULL CONSTRAINT DF_msp_tesoreria_cuentas_activo DEFAULT (1),
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_cuentas_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_tesoreria_cuentas PRIMARY KEY (id_cuenta_tesoreria),
        CONSTRAINT UQ_msp_tesoreria_cuentas_codigo UNIQUE (codigo_cuenta),
        CONSTRAINT CK_msp_tesoreria_cuentas_tipo CHECK (tipo_cuenta IN (N'CAJA', N'BANCO'))
    );
END;
GO

MERGE dbo.msp_tesoreria_cuentas AS t
USING (VALUES (N'CAJA_GENERAL', N'Caja general', N'CAJA')) AS s(codigo_cuenta, nombre_cuenta, tipo_cuenta)
ON t.codigo_cuenta = s.codigo_cuenta
WHEN MATCHED THEN UPDATE SET nombre_cuenta=s.nombre_cuenta, tipo_cuenta=s.tipo_cuenta, activo=1
WHEN NOT MATCHED THEN INSERT (codigo_cuenta,nombre_cuenta,tipo_cuenta) VALUES (s.codigo_cuenta,s.nombre_cuenta,s.tipo_cuenta);
GO

IF OBJECT_ID(N'dbo.msp_garantia_recepciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_recepciones (
        id_recepcion_garantia INT IDENTITY(1,1) NOT NULL,
        id_garantia INT NOT NULL,
        fecha_recepcion DATE NOT NULL,
        monto_recibido DECIMAL(18,2) NOT NULL,
        medio_recepcion NVARCHAR(20) NOT NULL,
        referencia NVARCHAR(200) NULL,
        banco_emisor NVARCHAR(120) NULL,
        numero_cheque NVARCHAR(80) NULL,
        fecha_cheque DATE NULL,
        estado_recepcion NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_garantia_recepciones_estado DEFAULT (N'CONFIRMADA'),
        observaciones NVARCHAR(500) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantia_recepciones_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_garantia_recepciones PRIMARY KEY (id_recepcion_garantia),
        CONSTRAINT FK_msp_garantia_recepciones_garantia FOREIGN KEY (id_garantia) REFERENCES dbo.msp_garantias(id_garantia),
        CONSTRAINT CK_msp_garantia_recepciones_monto CHECK (monto_recibido > 0),
        CONSTRAINT CK_msp_garantia_recepciones_medio CHECK (medio_recepcion IN (N'EFECTIVO',N'TRANSFERENCIA',N'CHEQUE')),
        CONSTRAINT CK_msp_garantia_recepciones_estado CHECK (estado_recepcion IN (N'PENDIENTE',N'CONFIRMADA',N'ANULADA')),
        CONSTRAINT CK_msp_garantia_recepciones_cheque CHECK (medio_recepcion <> N'CHEQUE' OR numero_cheque IS NOT NULL)
    );
    CREATE INDEX IX_msp_garantia_recepciones_garantia_fecha
        ON dbo.msp_garantia_recepciones(id_garantia, fecha_recepcion, id_recepcion_garantia);
END;
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_movimientos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tesoreria_movimientos (
        id_movimiento_tesoreria INT IDENTITY(1,1) NOT NULL,
        id_cuenta_tesoreria INT NOT NULL,
        fecha_movimiento DATE NOT NULL,
        tipo_movimiento NVARCHAR(30) NOT NULL,
        naturaleza CHAR(1) NOT NULL,
        monto DECIMAL(18,2) NOT NULL,
        medio_pago NVARCHAR(20) NOT NULL,
        referencia NVARCHAR(200) NULL,
        id_recepcion_garantia INT NULL,
        id_movimiento_garantia INT NULL,
        estado_movimiento NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_tesoreria_movimientos_estado DEFAULT (N'VIGENTE'),
        conciliado BIT NOT NULL CONSTRAINT DF_msp_tesoreria_movimientos_conciliado DEFAULT (0),
        fecha_conciliacion DATE NULL,
        observaciones NVARCHAR(500) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tesoreria_movimientos_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_tesoreria_movimientos PRIMARY KEY (id_movimiento_tesoreria),
        CONSTRAINT FK_msp_tesoreria_movimientos_cuenta FOREIGN KEY (id_cuenta_tesoreria) REFERENCES dbo.msp_tesoreria_cuentas(id_cuenta_tesoreria),
        CONSTRAINT FK_msp_tesoreria_movimientos_recepcion FOREIGN KEY (id_recepcion_garantia) REFERENCES dbo.msp_garantia_recepciones(id_recepcion_garantia),
        CONSTRAINT FK_msp_tesoreria_movimientos_mov_garantia FOREIGN KEY (id_movimiento_garantia) REFERENCES dbo.msp_movimientos_garantia(id_movimiento_garantia),
        CONSTRAINT CK_msp_tesoreria_movimientos_naturaleza CHECK (naturaleza IN ('E','S')),
        CONSTRAINT CK_msp_tesoreria_movimientos_monto CHECK (monto > 0),
        CONSTRAINT CK_msp_tesoreria_movimientos_medio CHECK (medio_pago IN (N'EFECTIVO',N'TRANSFERENCIA',N'CHEQUE')),
        CONSTRAINT CK_msp_tesoreria_movimientos_estado CHECK (estado_movimiento IN (N'VIGENTE',N'ANULADO')),
        CONSTRAINT CK_msp_tesoreria_movimientos_conciliacion CHECK (conciliado=0 OR fecha_conciliacion IS NOT NULL)
    );
    CREATE INDEX IX_msp_tesoreria_movimientos_cuenta_fecha
        ON dbo.msp_tesoreria_movimientos(id_cuenta_tesoreria, fecha_movimiento, id_movimiento_tesoreria);
    CREATE UNIQUE INDEX UX_msp_tesoreria_movimientos_recepcion
        ON dbo.msp_tesoreria_movimientos(id_recepcion_garantia)
        WHERE id_recepcion_garantia IS NOT NULL AND estado_movimiento=N'VIGENTE';
END;
GO

IF OBJECT_ID(N'dbo.msp_garantia_archivos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_archivos (
        id_garantia_archivo INT IDENTITY(1,1) NOT NULL,
        id_garantia INT NOT NULL,
        id_recepcion_garantia INT NULL,
        id_movimiento_garantia INT NULL,
        tipo_documento NVARCHAR(30) NOT NULL,
        nombre_archivo NVARCHAR(260) NOT NULL,
        ruta_relativa NVARCHAR(500) NOT NULL,
        mime_type NVARCHAR(100) NOT NULL,
        hash_sha256 CHAR(64) NOT NULL,
        bytes_archivo BIGINT NOT NULL,
        estado_archivo NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_garantia_archivos_estado DEFAULT (N'ACTIVO'),
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_garantia_archivos_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_garantia_archivos PRIMARY KEY (id_garantia_archivo),
        CONSTRAINT FK_msp_garantia_archivos_garantia FOREIGN KEY (id_garantia) REFERENCES dbo.msp_garantias(id_garantia),
        CONSTRAINT FK_msp_garantia_archivos_recepcion FOREIGN KEY (id_recepcion_garantia) REFERENCES dbo.msp_garantia_recepciones(id_recepcion_garantia),
        CONSTRAINT FK_msp_garantia_archivos_movimiento FOREIGN KEY (id_movimiento_garantia) REFERENCES dbo.msp_movimientos_garantia(id_movimiento_garantia),
        CONSTRAINT CK_msp_garantia_archivos_tipo CHECK (tipo_documento IN (N'COMPROBANTE_RECEPCION',N'COMPROBANTE_DEVOLUCION',N'CHEQUE',N'OTRO')),
        CONSTRAINT CK_msp_garantia_archivos_bytes CHECK (bytes_archivo >= 0),
        CONSTRAINT CK_msp_garantia_archivos_estado CHECK (estado_archivo IN (N'ACTIVO',N'ANULADO'))
    );
    CREATE INDEX IX_msp_garantia_archivos_garantia
        ON dbo.msp_garantia_archivos(id_garantia, id_garantia_archivo);
END;
GO

/* Migracion conservadora: solo se reconoce como recibida una garantia historica
   que ya tenga medio explicito y asiento contable vigente de constitucion. */
INSERT INTO dbo.msp_garantia_recepciones (
    id_garantia, fecha_recepcion, monto_recibido, medio_recepcion, referencia,
    estado_recepcion, observaciones
)
SELECT
    g.id_garantia,
    g.fecha_constitucion,
    g.monto_inicial,
    CASE UPPER(LTRIM(RTRIM(g.medio_recepcion)))
        WHEN N'EFECTIVO' THEN N'EFECTIVO'
        WHEN N'TRANSFERENCIA' THEN N'TRANSFERENCIA'
        ELSE N'CHEQUE'
    END,
    g.referencia_recepcion,
    N'CONFIRMADA',
    N'Migrada desde garantía histórica con asiento contable vigente.'
FROM dbo.msp_garantias g
WHERE g.monto_inicial > 0
  AND UPPER(LTRIM(RTRIM(ISNULL(g.medio_recepcion,N'')))) IN (N'EFECTIVO',N'TRANSFERENCIA')
  AND EXISTS (
      SELECT 1 FROM dbo.msp_acc_asientos a
      WHERE a.tabla_origen=N'msp_garantias' AND a.id_origen=g.id_garantia AND a.estado_asiento=1
  )
  AND NOT EXISTS (
      SELECT 1 FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion<>N'ANULADA'
  );
GO

INSERT INTO dbo.msp_tesoreria_movimientos (
    id_cuenta_tesoreria, fecha_movimiento, tipo_movimiento, naturaleza, monto,
    medio_pago, referencia, id_recepcion_garantia, estado_movimiento, observaciones
)
SELECT
    tc.id_cuenta_tesoreria,
    r.fecha_recepcion,
    N'RECEPCION_GARANTIA',
    'E',
    r.monto_recibido,
    r.medio_recepcion,
    r.referencia,
    r.id_recepcion_garantia,
    N'VIGENTE',
    N'Migrado desde recepción histórica validada.'
FROM dbo.msp_garantia_recepciones r
INNER JOIN dbo.msp_tesoreria_cuentas tc
    ON tc.codigo_cuenta=N'CAJA_GENERAL'
WHERE r.estado_recepcion=N'CONFIRMADA'
  AND r.medio_recepcion=N'EFECTIVO'
  AND NOT EXISTS (
      SELECT 1 FROM dbo.msp_tesoreria_movimientos tm
      WHERE tm.id_recepcion_garantia=r.id_recepcion_garantia AND tm.estado_movimiento=N'VIGENTE'
  );
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_control_recepcion
AS
SELECT
    g.id_garantia,
    g.id_contrato_arriendo,
    g.id_contrato_local,
    g.id_local,
    g.fecha_constitucion,
    g.monto_inicial AS monto_pactado,
    CAST(ISNULL(r.monto_recibido,0) AS DECIMAL(18,2)) AS monto_recibido,
    CAST(g.monto_inicial-ISNULL(r.monto_recibido,0) AS DECIMAL(18,2)) AS monto_por_recibir,
    CASE
        WHEN ISNULL(r.monto_recibido,0)=0 THEN N'NO_RECIBIDA'
        WHEN ISNULL(r.monto_recibido,0)<g.monto_inicial THEN N'PARCIAL'
        WHEN ISNULL(r.monto_recibido,0)=g.monto_inicial THEN N'COMPLETA'
        ELSE N'EXCEDIDA'
    END AS estado_recepcion
FROM dbo.msp_garantias g
OUTER APPLY (
    SELECT SUM(gr.monto_recibido) monto_recibido
    FROM dbo.msp_garantia_recepciones gr
    WHERE gr.id_garantia=g.id_garantia AND gr.estado_recepcion=N'CONFIRMADA'
) r;
GO

PRINT N'Base de garantias y tesoreria instalada correctamente.';
GO
