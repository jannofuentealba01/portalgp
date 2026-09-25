SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.fte_dotacion_snapshots (
        id_fte_dotacion_snapshot BIGINT IDENTITY(1,1) NOT NULL,
        periodo DATE NOT NULL,
        version_snapshot INT NOT NULL,
        estado_snapshot NVARCHAR(20) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_estado DEFAULT (N'BORRADOR'),
        es_vigente BIT NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_vigente DEFAULT (1),
        fuente NVARCHAR(50) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_fuente DEFAULT (N'BUK_API'),
        regla_dotacion NVARCHAR(80) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_regla DEFAULT (N'monthly_unique_active_any_time_by_cost_center'),
        fecha_fuente DATETIME2(0) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_fecha_fuente DEFAULT (SYSDATETIME()),
        total_personas_unicas INT NOT NULL,
        total_asignaciones_ceco INT NOT NULL,
        dotacion_cierre INT NOT NULL,
        promedio_dia_calendario DECIMAL(18,8) NULL,
        promedio_dia_habil DECIMAL(18,8) NULL,
        persona_dias_calendario INT NOT NULL,
        persona_dias_habiles INT NOT NULL,
        dias_sin_ceco INT NOT NULL,
        hash_snapshot CHAR(64) NOT NULL,
        id_usuario_creacion INT NOT NULL,
        fecha_creacion DATETIME2(0) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_creacion DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_fte_dotacion_snapshots PRIMARY KEY (id_fte_dotacion_snapshot),
        CONSTRAINT FK_fte_dotacion_snapshot_usuario
            FOREIGN KEY (id_usuario_creacion) REFERENCES dbo.cr_usuarios (id),
        CONSTRAINT CK_fte_dotacion_snapshot_periodo CHECK (DAY(periodo) = 1),
        CONSTRAINT CK_fte_dotacion_snapshot_version CHECK (version_snapshot > 0),
        CONSTRAINT CK_fte_dotacion_snapshot_estado
            CHECK (estado_snapshot IN (N'BORRADOR', N'APROBADO', N'REEMPLAZADO', N'ANULADO')),
        CONSTRAINT CK_fte_dotacion_snapshot_totales
            CHECK (total_personas_unicas >= 0 AND total_asignaciones_ceco >= 0
               AND dotacion_cierre >= 0 AND persona_dias_calendario >= 0
               AND persona_dias_habiles >= 0 AND dias_sin_ceco >= 0),
        CONSTRAINT UQ_fte_dotacion_snapshot_periodo_version UNIQUE (periodo, version_snapshot)
    );
END;

IF COL_LENGTH(N'dbo.fte_dotacion_snapshots', N'id_usuario_aprobacion') IS NULL
BEGIN
    ALTER TABLE dbo.fte_dotacion_snapshots
        ADD id_usuario_aprobacion INT NULL;
END;

IF COL_LENGTH(N'dbo.fte_dotacion_snapshots', N'fecha_aprobacion') IS NULL
BEGIN
    ALTER TABLE dbo.fte_dotacion_snapshots
        ADD fecha_aprobacion DATETIME2(0) NULL;
END;

IF COL_LENGTH(N'dbo.fte_dotacion_snapshots', N'observacion_aprobacion') IS NULL
BEGIN
    ALTER TABLE dbo.fte_dotacion_snapshots
        ADD observacion_aprobacion NVARCHAR(500) NULL;
END;

GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE parent_object_id = OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U')
      AND name = N'FK_fte_dotacion_snapshot_usuario_aprobacion'
)
BEGIN
    ALTER TABLE dbo.fte_dotacion_snapshots WITH CHECK
        ADD CONSTRAINT FK_fte_dotacion_snapshot_usuario_aprobacion
            FOREIGN KEY (id_usuario_aprobacion) REFERENCES dbo.cr_usuarios (id);
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U')
      AND name = N'CK_fte_dotacion_snapshot_aprobacion'
)
BEGIN
    ALTER TABLE dbo.fte_dotacion_snapshots WITH CHECK
        ADD CONSTRAINT CK_fte_dotacion_snapshot_aprobacion CHECK (
            (estado_snapshot = N'APROBADO' AND id_usuario_aprobacion IS NOT NULL AND fecha_aprobacion IS NOT NULL)
            OR estado_snapshot <> N'APROBADO'
        );
END;
IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.fte_dotacion_snapshots', N'U')
      AND name = N'UX_fte_dotacion_snapshot_vigente'
)
BEGIN
    CREATE UNIQUE INDEX UX_fte_dotacion_snapshot_vigente
        ON dbo.fte_dotacion_snapshots (periodo)
        WHERE es_vigente = 1;
END;

IF OBJECT_ID(N'dbo.fte_dotacion_snapshot_trabajadores', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.fte_dotacion_snapshot_trabajadores (
        id_fte_dotacion_snapshot_trabajador BIGINT IDENTITY(1,1) NOT NULL,
        id_fte_dotacion_snapshot BIGINT NOT NULL,
        identificador_normalizado NVARCHAR(30) NOT NULL,
        identificador_snapshot NVARCHAR(30) NULL,
        nombre_snapshot NVARCHAR(200) NOT NULL,
        buk_employee_id NVARCHAR(100) NULL,
        codigo_ceco NVARCHAR(100) NOT NULL,
        nombre_ceco NVARCHAR(200) NULL,
        vigente_algun_dia BIT NOT NULL,
        vigente_cierre BIT NOT NULL,
        primer_dia_vigente DATE NOT NULL,
        ultimo_dia_vigente DATE NOT NULL,
        dias_calendario INT NOT NULL,
        dias_habiles INT NOT NULL,
        fechas_vigentes_json NVARCHAR(MAX) NOT NULL,
        job_ids_json NVARCHAR(MAX) NULL,
        fecha_registro DATETIME2(0) NOT NULL
            CONSTRAINT DF_fte_dotacion_snapshot_trabajador_registro DEFAULT (SYSDATETIME()),
        CONSTRAINT PK_fte_dotacion_snapshot_trabajadores
            PRIMARY KEY (id_fte_dotacion_snapshot_trabajador),
        CONSTRAINT FK_fte_dotacion_snapshot_trabajador_snapshot
            FOREIGN KEY (id_fte_dotacion_snapshot)
            REFERENCES dbo.fte_dotacion_snapshots (id_fte_dotacion_snapshot),
        CONSTRAINT CK_fte_dotacion_snapshot_trabajador_dias
            CHECK (dias_calendario > 0 AND dias_habiles >= 0 AND primer_dia_vigente <= ultimo_dia_vigente),
        CONSTRAINT CK_fte_dotacion_snapshot_trabajador_fechas_json
            CHECK (ISJSON(fechas_vigentes_json) = 1),
        CONSTRAINT CK_fte_dotacion_snapshot_trabajador_jobs_json
            CHECK (job_ids_json IS NULL OR ISJSON(job_ids_json) = 1),
        CONSTRAINT UQ_fte_dotacion_snapshot_trabajador_ceco
            UNIQUE (id_fte_dotacion_snapshot, identificador_normalizado, codigo_ceco)
    );
END;

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.fte_dotacion_snapshot_trabajadores', N'U')
      AND name = N'IX_fte_dotacion_snapshot_trabajador_identificador'
)
BEGIN
    CREATE INDEX IX_fte_dotacion_snapshot_trabajador_identificador
        ON dbo.fte_dotacion_snapshot_trabajadores (identificador_normalizado, id_fte_dotacion_snapshot)
        INCLUDE (codigo_ceco, primer_dia_vigente, ultimo_dia_vigente, vigente_cierre);
END;

GO
CREATE OR ALTER TRIGGER dbo.trg_fte_dotacion_snapshot_inmutable
ON dbo.fte_dotacion_snapshots
AFTER UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (SELECT 1 FROM deleted WHERE estado_snapshot = N'APROBADO')
    BEGIN
        THROW 51040, N'La fotografia FTE aprobada es inmutable.', 1;
    END;
END;
GO

CREATE OR ALTER TRIGGER dbo.trg_fte_dotacion_snapshot_detalle_inmutable
ON dbo.fte_dotacion_snapshot_trabajadores
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT id_fte_dotacion_snapshot FROM inserted
            UNION
            SELECT id_fte_dotacion_snapshot FROM deleted
        ) x
        INNER JOIN dbo.fte_dotacion_snapshots s
            ON s.id_fte_dotacion_snapshot = x.id_fte_dotacion_snapshot
        WHERE s.estado_snapshot = N'APROBADO'
    )
    BEGIN
        THROW 51041, N'El detalle de una fotografia FTE aprobada es inmutable.', 1;
    END;
END;
GO
IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON OBJECT::dbo.fte_dotacion_snapshots TO portalgp_runtime_role;
    GRANT SELECT, INSERT ON OBJECT::dbo.fte_dotacion_snapshot_trabajadores TO portalgp_runtime_role;
END;

PRINT N'Fotografías mensuales de dotación FTE instaladas.';
