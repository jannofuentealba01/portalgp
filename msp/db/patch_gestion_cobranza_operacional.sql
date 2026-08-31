SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.msp_cobranza_tipos_gestion', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_tipos_gestion(
        id_tipo_gestion INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_tipos_gestion PRIMARY KEY,
        codigo NVARCHAR(30) NOT NULL CONSTRAINT UQ_msp_cobranza_tipos_gestion_codigo UNIQUE,
        nombre NVARCHAR(80) NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_cobranza_tipos_gestion_activo DEFAULT(1),
        orden INT NOT NULL CONSTRAINT DF_msp_cobranza_tipos_gestion_orden DEFAULT(100)
    );
END;

IF OBJECT_ID(N'dbo.msp_cobranza_resultados_gestion', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_resultados_gestion(
        id_resultado_gestion INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_resultados_gestion PRIMARY KEY,
        codigo NVARCHAR(40) NOT NULL CONSTRAINT UQ_msp_cobranza_resultados_gestion_codigo UNIQUE,
        nombre NVARCHAR(100) NOT NULL,
        estado_operacional_sugerido NVARCHAR(30) NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_cobranza_resultados_gestion_activo DEFAULT(1),
        orden INT NOT NULL CONSTRAINT DF_msp_cobranza_resultados_gestion_orden DEFAULT(100)
    );
END;

MERGE dbo.msp_cobranza_tipos_gestion AS t
USING (VALUES
 (N'LLAMADA',N'Llamada',10),(N'CORREO',N'Correo',20),(N'WHATSAPP',N'WhatsApp',30),
 (N'REUNION',N'Reunión',40),(N'CARTA',N'Carta',50),(N'VISITA',N'Visita',60),(N'OTRO',N'Otro',100)
) s(codigo,nombre,orden) ON t.codigo=s.codigo
WHEN MATCHED THEN UPDATE SET nombre=s.nombre,orden=s.orden
WHEN NOT MATCHED THEN INSERT(codigo,nombre,orden) VALUES(s.codigo,s.nombre,s.orden);

MERGE dbo.msp_cobranza_resultados_gestion AS t
USING (VALUES
 (N'SIN_RESPUESTA',N'Sin respuesta',N'EN_GESTION',10),(N'CONTACTADO',N'Contactado',N'CONTACTADO',20),
 (N'RECONOCE_DEUDA',N'Reconoce deuda',N'CONTACTADO',30),(N'DESCONOCE_DEUDA',N'Desconoce deuda',N'ESCALADO',40),
 (N'SOLICITA_DETALLE',N'Solicita detalle',N'CONTACTADO',50),(N'PROMETE_PAGO',N'Promete pago',N'COMPROMISO_PAGO',60),
 (N'SOLICITA_CONVENIO',N'Solicita convenio',N'CONVENIO',70),(N'PAGO_INFORMADO',N'Pago informado',N'EN_GESTION',80),
 (N'DERIVADO',N'Derivado',N'ESCALADO',90),(N'OTRO',N'Otro',N'EN_GESTION',100)
) s(codigo,nombre,estado,orden) ON t.codigo=s.codigo
WHEN MATCHED THEN UPDATE SET nombre=s.nombre,estado_operacional_sugerido=s.estado,orden=s.orden
WHEN NOT MATCHED THEN INSERT(codigo,nombre,estado_operacional_sugerido,orden) VALUES(s.codigo,s.nombre,s.estado,s.orden);

IF OBJECT_ID(N'dbo.msp_cobranza_casos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_casos(
        id_caso_cobranza INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_casos PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL CONSTRAINT UQ_msp_cobranza_casos_contrato UNIQUE,
        estado_operacional NVARCHAR(30) NOT NULL CONSTRAINT DF_msp_cobranza_casos_estado DEFAULT(N'SIN_GESTION'),
        fecha_activacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobranza_casos_activacion DEFAULT(SYSDATETIME()),
        fecha_resolucion DATETIME2(0) NULL,
        fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobranza_casos_actualizacion DEFAULT(SYSDATETIME()),
        CONSTRAINT FK_msp_cobranza_casos_contrato FOREIGN KEY(id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT CK_msp_cobranza_casos_estado CHECK(estado_operacional IN(N'SIN_GESTION',N'EN_GESTION',N'CONTACTADO',N'COMPROMISO_PAGO',N'CONVENIO',N'ESCALADO',N'SUSPENDIDO',N'RESUELTO'))
    );
END;

IF OBJECT_ID(N'dbo.msp_cobranza_gestiones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_gestiones(
        id_gestion_cobranza INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_gestiones PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,
        id_arrendatario INT NOT NULL,
        fecha_gestion DATETIME2(0) NOT NULL,
        id_tipo_gestion INT NOT NULL,
        id_resultado_gestion INT NOT NULL,
        persona_contactada NVARCHAR(150) NULL,
        observacion NVARCHAR(1500) NOT NULL,
        proxima_fecha_seguimiento DATE NULL,
        id_usuario INT NOT NULL,
        origen NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cobranza_gestiones_origen DEFAULT(N'MANUAL'),
        id_aviso_cobranza INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobranza_gestiones_registro DEFAULT(SYSDATETIME()),
        CONSTRAINT FK_msp_cobranza_gestiones_contrato FOREIGN KEY(id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT FK_msp_cobranza_gestiones_arrendatario FOREIGN KEY(id_arrendatario) REFERENCES dbo.msp_arrendatarios(id_arrendatario),
        CONSTRAINT FK_msp_cobranza_gestiones_tipo FOREIGN KEY(id_tipo_gestion) REFERENCES dbo.msp_cobranza_tipos_gestion(id_tipo_gestion),
        CONSTRAINT FK_msp_cobranza_gestiones_resultado FOREIGN KEY(id_resultado_gestion) REFERENCES dbo.msp_cobranza_resultados_gestion(id_resultado_gestion),
        CONSTRAINT FK_msp_cobranza_gestiones_usuario FOREIGN KEY(id_usuario) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_cobranza_gestiones_origen CHECK(origen IN(N'MANUAL',N'AVISO'))
    );
    CREATE INDEX IX_msp_cobranza_gestiones_contrato_fecha ON dbo.msp_cobranza_gestiones(id_contrato_arriendo,fecha_gestion DESC);
END;

IF OBJECT_ID(N'dbo.msp_cobranza_compromisos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_compromisos(
        id_compromiso_pago INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_compromisos PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,
        id_arrendatario INT NOT NULL,
        fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobranza_compromisos_creacion DEFAULT(SYSDATETIME()),
        monto_comprometido DECIMAL(18,2) NOT NULL,
        fecha_comprometida DATE NOT NULL,
        observacion NVARCHAR(1000) NULL,
        id_usuario_creador INT NOT NULL,
        estado NVARCHAR(25) NOT NULL CONSTRAINT DF_msp_cobranza_compromisos_estado DEFAULT(N'PENDIENTE'),
        monto_pagado_evaluado DECIMAL(18,2) NOT NULL CONSTRAINT DF_msp_cobranza_compromisos_pagado DEFAULT(0),
        fecha_ultima_evaluacion DATETIME2(0) NULL,
        fecha_cancelacion DATETIME2(0) NULL,
        motivo_cancelacion NVARCHAR(500) NULL,
        CONSTRAINT FK_msp_cobranza_compromisos_contrato FOREIGN KEY(id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT FK_msp_cobranza_compromisos_arrendatario FOREIGN KEY(id_arrendatario) REFERENCES dbo.msp_arrendatarios(id_arrendatario),
        CONSTRAINT FK_msp_cobranza_compromisos_usuario FOREIGN KEY(id_usuario_creador) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_cobranza_compromisos_monto CHECK(monto_comprometido>0),
        CONSTRAINT CK_msp_cobranza_compromisos_estado CHECK(estado IN(N'PENDIENTE',N'CUMPLIDO',N'CUMPLIDO_PARCIAL',N'INCUMPLIDO',N'CANCELADO'))
    );
    CREATE INDEX IX_msp_cobranza_compromisos_estado_fecha ON dbo.msp_cobranza_compromisos(estado,fecha_comprometida,id_contrato_arriendo);
END;

IF OBJECT_ID(N'dbo.msp_cobranza_plantillas_aviso', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_plantillas_aviso(
        id_plantilla_aviso INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_plantillas_aviso PRIMARY KEY,
        codigo NVARCHAR(40) NOT NULL CONSTRAINT UQ_msp_cobranza_plantillas_aviso_codigo UNIQUE,
        nombre NVARCHAR(100) NOT NULL,asunto NVARCHAR(200) NOT NULL,cuerpo NVARCHAR(MAX) NOT NULL,
        activo BIT NOT NULL CONSTRAINT DF_msp_cobranza_plantillas_aviso_activo DEFAULT(1),orden INT NOT NULL DEFAULT(100)
    );
END;
MERGE dbo.msp_cobranza_plantillas_aviso t USING(VALUES
(N'AVISO_AMISTOSO',N'Aviso amistoso',N'Recordatorio de pago pendiente',N'Informamos que el contrato mantiene documentos pendientes. Agradecemos regularizar o contactar a Administración.',10),
(N'MORA_30_DIAS',N'Mora de 30 días',N'Aviso de documentos vencidos',N'El contrato registra documentos vencidos. Solicitamos regularizar el saldo indicado en este aviso.',20),
(N'MORA_60_DIAS',N'Mora de 60 días',N'Aviso prioritario de cobranza',N'El contrato registra mora prolongada. Solicitamos contactar a Administración para regularizar.',30),
(N'COMPROMISO_INCUMPLIDO',N'Compromiso incumplido',N'Aviso por compromiso de pago incumplido',N'No se ha verificado el cumplimiento completo del compromiso de pago registrado.',40)
)s(codigo,nombre,asunto,cuerpo,orden) ON t.codigo=s.codigo
WHEN MATCHED THEN UPDATE SET nombre=s.nombre,asunto=s.asunto,cuerpo=s.cuerpo,orden=s.orden
WHEN NOT MATCHED THEN INSERT(codigo,nombre,asunto,cuerpo,orden)VALUES(s.codigo,s.nombre,s.asunto,s.cuerpo,s.orden);

IF OBJECT_ID(N'dbo.msp_cobranza_avisos', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cobranza_avisos(
        id_aviso_cobranza INT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_cobranza_avisos PRIMARY KEY,
        id_contrato_arriendo INT NOT NULL,id_arrendatario INT NOT NULL,id_plantilla_aviso INT NOT NULL,
        fecha_generacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_cobranza_avisos_generacion DEFAULT(SYSDATETIME()),
        asunto_snapshot NVARCHAR(200) NOT NULL,cuerpo_snapshot NVARCHAR(MAX) NOT NULL,
        deuda_vencida_snapshot DECIMAL(18,2) NOT NULL,mora_maxima_snapshot INT NOT NULL,
        estado NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_cobranza_avisos_estado DEFAULT(N'GENERADO'),
        fecha_envio DATETIME2(0) NULL,medio_envio NVARCHAR(30) NULL,observacion_envio NVARCHAR(1000) NULL,
        id_usuario_generador INT NOT NULL,id_usuario_envio INT NULL,
        CONSTRAINT FK_msp_cobranza_avisos_contrato FOREIGN KEY(id_contrato_arriendo) REFERENCES dbo.msp_contratos_arriendo(id_contrato_arriendo),
        CONSTRAINT FK_msp_cobranza_avisos_arrendatario FOREIGN KEY(id_arrendatario) REFERENCES dbo.msp_arrendatarios(id_arrendatario),
        CONSTRAINT FK_msp_cobranza_avisos_plantilla FOREIGN KEY(id_plantilla_aviso) REFERENCES dbo.msp_cobranza_plantillas_aviso(id_plantilla_aviso),
        CONSTRAINT FK_msp_cobranza_avisos_usuario_gen FOREIGN KEY(id_usuario_generador) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT FK_msp_cobranza_avisos_usuario_env FOREIGN KEY(id_usuario_envio) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_cobranza_avisos_estado CHECK(estado IN(N'GENERADO',N'ENVIADO',N'ANULADO'))
    );
    CREATE INDEX IX_msp_cobranza_avisos_contrato ON dbo.msp_cobranza_avisos(id_contrato_arriendo,fecha_generacion DESC);
END;

IF COL_LENGTH('dbo.msp_cobranza_gestiones','id_aviso_cobranza') IS NOT NULL
AND NOT EXISTS(SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_cobranza_gestiones_aviso')
    ALTER TABLE dbo.msp_cobranza_gestiones ADD CONSTRAINT FK_msp_cobranza_gestiones_aviso FOREIGN KEY(id_aviso_cobranza) REFERENCES dbo.msp_cobranza_avisos(id_aviso_cobranza);

PRINT 'Gestión operacional de cobranza instalada.';
