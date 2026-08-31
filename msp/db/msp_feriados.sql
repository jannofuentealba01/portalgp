/* =========================================================================
   MSP - Tabla de feriados (Chile)
   ========================================================================= */

IF OBJECT_ID(N'dbo.msp_feriados', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_feriados (
        fecha DATE NOT NULL PRIMARY KEY,
        titulo NVARCHAR(200) NOT NULL,
        tipo NVARCHAR(80) NULL,
        inalienable BIT NOT NULL CONSTRAINT DF_msp_feriados_inalienable DEFAULT (0),
        fuente NVARCHAR(40) NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_feriados_activo DEFAULT (1),
        created_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_feriados_created DEFAULT (SYSDATETIME()),
        updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_msp_feriados_updated DEFAULT (SYSDATETIME())
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_feriados_anio'
      AND object_id = OBJECT_ID(N'dbo.msp_feriados')
)
BEGIN
    CREATE INDEX IX_msp_feriados_anio
        ON dbo.msp_feriados (fecha);
END;
GO
