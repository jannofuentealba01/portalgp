/*
 MSP - Correcciones selectivas
 Crea tablas de solicitudes y eventos para el motor de correcciones.
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_correcciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_correcciones (
        id_correccion INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_correcciones PRIMARY KEY,
        codigo_operacion UNIQUEIDENTIFIER NOT NULL CONSTRAINT DF_msp_correcciones_codigo DEFAULT (NEWSEQUENTIALID()),
        tipo_correccion NVARCHAR(40) NOT NULL,
        modulo_origen NVARCHAR(80) NOT NULL,
        periodo_facturacion DATE NULL,
        id_contrato_arriendo INT NULL,
        id_tienda INT NULL,
        id_local INT NULL,
        entidad_afectada NVARCHAR(80) NOT NULL,
        id_registro_origen INT NULL,
        estado_correccion NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_correcciones_estado DEFAULT (N'BORRADOR'),
        nivel_correcion NVARCHAR(40) NOT NULL CONSTRAINT DF_msp_correcciones_nivel DEFAULT (N'REVISION'),
        valor_anterior NVARCHAR(MAX) NULL,
        valor_nuevo NVARCHAR(MAX) NULL,
        motivo NVARCHAR(500) NOT NULL,
        resultado_analisis NVARCHAR(MAX) NULL,
        usuario_solicitante INT NOT NULL,
        usuario_aprobador INT NULL,
        usuario_ejecutor INT NULL,
        fecha_solicitud DATETIME2(0) NOT NULL CONSTRAINT DF_msp_correcciones_fecha_solicitud DEFAULT (SYSDATETIME()),
        fecha_aprobacion DATETIME2(0) NULL,
        fecha_ejecucion DATETIME2(0) NULL,
        fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_correcciones_fecha_actualizacion DEFAULT (SYSDATETIME()),
        error_ejecucion NVARCHAR(MAX) NULL
    );

    CREATE INDEX IX_msp_correcciones_estado_fecha
        ON dbo.msp_correcciones (estado_correccion, fecha_solicitud DESC, id_correccion DESC);
    CREATE INDEX IX_msp_correcciones_contrato
        ON dbo.msp_correcciones (id_contrato_arriendo, fecha_solicitud DESC, id_correccion DESC);
    CREATE INDEX IX_msp_correcciones_operacion
        ON dbo.msp_correcciones (codigo_operacion);
END

IF OBJECT_ID(N'dbo.msp_correcciones_eventos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_correcciones_eventos (
        id_evento_correcion INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_correcciones_eventos PRIMARY KEY,
        id_correccion INT NOT NULL,
        tipo_evento NVARCHAR(40) NOT NULL,
        detalle NVARCHAR(MAX) NULL,
        estado_anterior NVARCHAR(30) NULL,
        estado_nuevo NVARCHAR(30) NULL,
        payload_json NVARCHAR(MAX) NULL,
        usuario_evento INT NOT NULL,
        fecha_evento DATETIME2(0) NOT NULL CONSTRAINT DF_msp_correcciones_eventos_fecha DEFAULT (SYSDATETIME()),
        CONSTRAINT FK_msp_correcciones_eventos_correccion FOREIGN KEY (id_correccion) REFERENCES dbo.msp_correcciones (id_correccion)
    );

    CREATE INDEX IX_msp_correcciones_eventos_correccion_fecha
        ON dbo.msp_correcciones_eventos (id_correccion, fecha_evento DESC, id_evento_correcion DESC);
END
GO
