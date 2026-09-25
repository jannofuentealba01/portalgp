/*
===========================================================================
 MSP - Servicios tardios durante liquidacion contractual
 - Registra los servicios que aun deben conciliarse despues del termino.
 - Conserva la asignacion al contrato-local original.
 - Esta etapa solo captura y controla; la emision se integra en la etapa 2.
===========================================================================
*/

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
GO

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_liquidacion_servicios', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_liquidacion_servicios (
        id_liquidacion_servicio INT IDENTITY(1,1) NOT NULL,
        id_contrato_local INT NOT NULL,
        id_tipo_servicio INT NOT NULL,
        id_medidor INT NULL,
        fecha_termino_operativo DATE NOT NULL,
        fecha_lectura_corte DATE NULL,
        lectura_corte DECIMAL(18,4) NULL,
        estado_liquidacion TINYINT NOT NULL
            CONSTRAINT DF_msp_liq_serv_estado DEFAULT (1),
        observaciones NVARCHAR(500) NULL,
        id_usuario_creacion INT NULL,
        id_usuario_actualizacion INT NULL,
        fecha_registro DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_liq_serv_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_liq_serv_actualizacion DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_liquidacion_servicios
            PRIMARY KEY (id_liquidacion_servicio),
        CONSTRAINT FK_msp_liq_serv_contrato_local
            FOREIGN KEY (id_contrato_local)
            REFERENCES dbo.msp_contrato_locales (id_contrato_local),
        CONSTRAINT FK_msp_liq_serv_tipo_servicio
            FOREIGN KEY (id_tipo_servicio)
            REFERENCES dbo.msp_tipos_servicio (id_tipo_servicio),
        CONSTRAINT FK_msp_liq_serv_medidor
            FOREIGN KEY (id_medidor)
            REFERENCES dbo.msp_medidores (id_medidor),
        CONSTRAINT CK_msp_liq_serv_estado
            CHECK (estado_liquidacion IN (1,2,3,4)),
        CONSTRAINT CK_msp_liq_serv_lectura_corte
            CHECK (lectura_corte IS NULL OR lectura_corte >= 0)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicios')
      AND name = N'UX_msp_liq_serv_contrato_medidor'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_liq_serv_contrato_medidor
        ON dbo.msp_liquidacion_servicios (id_contrato_local, id_medidor)
        WHERE id_medidor IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicios')
      AND name = N'IX_msp_liq_serv_estado'
)
BEGIN
    CREATE INDEX IX_msp_liq_serv_estado
        ON dbo.msp_liquidacion_servicios
            (estado_liquidacion, id_contrato_local, id_tipo_servicio);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_msp_liq_serv_usuario_creacion'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicios')
)
BEGIN
    ALTER TABLE dbo.msp_liquidacion_servicios
        ADD CONSTRAINT FK_msp_liq_serv_usuario_creacion
        FOREIGN KEY (id_usuario_creacion) REFERENCES dbo.cr_usuarios (id);
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_msp_liq_serv_usuario_actualizacion'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicios')
)
BEGIN
    ALTER TABLE dbo.msp_liquidacion_servicios
        ADD CONSTRAINT FK_msp_liq_serv_usuario_actualizacion
        FOREIGN KEY (id_usuario_actualizacion) REFERENCES dbo.cr_usuarios (id);
END;
GO

IF OBJECT_ID(N'dbo.msp_liquidacion_servicio_consumos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_liquidacion_servicio_consumos (
        id_consumo_liquidacion INT IDENTITY(1,1) NOT NULL,
        id_liquidacion_servicio INT NOT NULL,
        id_lectura INT NULL,
        periodo_emision DATE NOT NULL,
        fecha_desde_consumo DATE NULL,
        fecha_hasta_consumo DATE NOT NULL,
        referencia_origen NVARCHAR(100) NOT NULL,
        lectura_anterior DECIMAL(18,4) NULL,
        lectura_actual DECIMAL(18,4) NULL,
        consumo_asignado DECIMAL(18,4) NULL,
        monto_asignado DECIMAL(18,2) NOT NULL,
        tipo_asignacion TINYINT NOT NULL
            CONSTRAINT DF_msp_liq_consumo_tipo DEFAULT (2),
        estado_consumo TINYINT NOT NULL
            CONSTRAINT DF_msp_liq_consumo_estado DEFAULT (1),
        id_documento_cobro INT NULL,
        observaciones NVARCHAR(500) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_liq_consumo_registro DEFAULT (SYSDATETIME()),
        fecha_actualizacion DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_liq_consumo_actualizacion DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_msp_liquidacion_servicio_consumos
            PRIMARY KEY (id_consumo_liquidacion),
        CONSTRAINT FK_msp_liq_consumo_servicio
            FOREIGN KEY (id_liquidacion_servicio)
            REFERENCES dbo.msp_liquidacion_servicios (id_liquidacion_servicio),
        CONSTRAINT FK_msp_liq_consumo_lectura
            FOREIGN KEY (id_lectura)
            REFERENCES dbo.msp_lecturas_medidores (id_lectura),
        CONSTRAINT FK_msp_liq_consumo_documento
            FOREIGN KEY (id_documento_cobro)
            REFERENCES dbo.msp_documentos_cobro (id_documento_cobro),
        CONSTRAINT CK_msp_liq_consumo_periodo
            CHECK (DAY(periodo_emision) = 1),
        CONSTRAINT CK_msp_liq_consumo_fechas
            CHECK (fecha_desde_consumo IS NULL OR fecha_desde_consumo <= fecha_hasta_consumo),
        CONSTRAINT CK_msp_liq_consumo_lecturas
            CHECK (
                (lectura_anterior IS NULL OR lectura_anterior >= 0)
                AND (lectura_actual IS NULL OR lectura_actual >= 0)
                AND (lectura_anterior IS NULL OR lectura_actual IS NULL OR lectura_actual >= lectura_anterior)
            ),
        CONSTRAINT CK_msp_liq_consumo_asignado
            CHECK (consumo_asignado IS NULL OR consumo_asignado >= 0),
        CONSTRAINT CK_msp_liq_consumo_monto
            CHECK (monto_asignado > 0),
        CONSTRAINT CK_msp_liq_consumo_tipo
            CHECK (tipo_asignacion IN (1,2)),
        CONSTRAINT CK_msp_liq_consumo_estado
            CHECK (estado_consumo IN (1,2,3)),
        CONSTRAINT UQ_msp_liq_consumo_referencia
            UNIQUE (id_liquidacion_servicio, referencia_origen)
    );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.foreign_keys
    WHERE name = N'FK_msp_liq_consumo_usuario'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicio_consumos')
)
BEGIN
    ALTER TABLE dbo.msp_liquidacion_servicio_consumos
        ADD CONSTRAINT FK_msp_liq_consumo_usuario
        FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios (id);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicio_consumos')
      AND name = N'UX_msp_liq_consumo_lectura'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_liq_consumo_lectura
        ON dbo.msp_liquidacion_servicio_consumos
            (id_liquidacion_servicio, id_lectura)
        WHERE id_lectura IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.msp_liquidacion_servicio_consumos')
      AND name = N'IX_msp_liq_consumo_periodo_estado'
)
BEGIN
CREATE INDEX IX_msp_liq_consumo_periodo_estado
        ON dbo.msp_liquidacion_servicio_consumos
            (periodo_emision, estado_consumo, id_liquidacion_servicio);
END;
GO

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE
        ON OBJECT::dbo.msp_liquidacion_servicios
        TO portalgp_runtime_role;
    GRANT SELECT, INSERT, UPDATE
        ON OBJECT::dbo.msp_liquidacion_servicio_consumos
        TO portalgp_runtime_role;
    DENY DELETE
        ON OBJECT::dbo.msp_liquidacion_servicios
        TO portalgp_runtime_role;
    DENY DELETE
        ON OBJECT::dbo.msp_liquidacion_servicio_consumos
        TO portalgp_runtime_role;
END;
GO

/* Backfill seguro: solo contratos que actualmente siguen en proceso de cierre. */
INSERT INTO dbo.msp_liquidacion_servicios (
    id_contrato_local,
    id_tipo_servicio,
    id_medidor,
    fecha_termino_operativo,
    estado_liquidacion,
    observaciones
)
SELECT DISTINCT
    cl.id_contrato_local,
    m.id_tipo_servicio,
    m.id_medidor,
    ca.fecha_termino_efectiva,
    1,
    N'Pendiente creado al instalar el control de servicios tardios.'
FROM dbo.msp_contratos_arriendo ca
INNER JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_arriendo = ca.id_contrato_arriendo
INNER JOIN dbo.msp_medidores m
    ON m.id_local = cl.id_local
WHERE ca.estado_contrato = 3
  AND ca.fecha_termino_efectiva IS NOT NULL
  AND (m.fecha_instalacion IS NULL OR m.fecha_instalacion <= ca.fecha_termino_efectiva)
  AND (m.fecha_retiro IS NULL OR m.fecha_retiro >= ca.fecha_termino_efectiva)
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.msp_liquidacion_servicios ls
      WHERE ls.id_contrato_local = cl.id_contrato_local
        AND ls.id_medidor = m.id_medidor
  );
GO

PRINT 'patch_servicios_tardios_liquidacion aplicado.';
GO
