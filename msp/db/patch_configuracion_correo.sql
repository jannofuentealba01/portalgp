IF OBJECT_ID(N'dbo.msp_configuracion', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_configuracion (
        clave NVARCHAR(120) NOT NULL CONSTRAINT PK_msp_configuracion PRIMARY KEY,
        valor NVARCHAR(4000) NULL,
        descripcion NVARCHAR(500) NULL,
        fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_configuracion_fecha_actualizacion DEFAULT (SYSDATETIME()),
        id_usuario_actualizacion INT NULL
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.msp_configuracion
    WHERE clave = N'mail_arrendatarios_habilitado'
)
BEGIN
    INSERT INTO dbo.msp_configuracion (clave, valor, descripcion)
    VALUES (
        N'mail_arrendatarios_habilitado',
        N'0',
        N'Control global para permitir o bloquear correos reales enviados a arrendatarios.'
    );
END;
GO
