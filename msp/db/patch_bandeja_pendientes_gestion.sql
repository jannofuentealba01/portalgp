/* MSP - Metadatos operacionales de la Bandeja Global de Pendientes */
SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_pendientes_meta', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pendientes_meta (
        pendiente_clave NVARCHAR(190) NOT NULL,
        estado_revision NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_pendientes_meta_estado DEFAULT(N'ABIERTO'),
        id_usuario_asignado INT NULL,
        id_usuario_toma INT NULL,
        pospuesto_hasta DATE NULL,
        comentario_interno NVARCHAR(1000) NULL,
        id_usuario_actualiza INT NOT NULL,
        fecha_creacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pendientes_meta_creacion DEFAULT(SYSDATETIME()),
        fecha_actualizacion DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pendientes_meta_actualizacion DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_pendientes_meta PRIMARY KEY(pendiente_clave),
        CONSTRAINT CK_msp_pendientes_meta_estado CHECK(estado_revision IN(N'ABIERTO',N'EN_REVISION',N'POSPUESTO')),
        CONSTRAINT CK_msp_pendientes_meta_pospuesto CHECK(estado_revision<>N'POSPUESTO' OR pospuesto_hasta IS NOT NULL),
        CONSTRAINT FK_msp_pendientes_meta_asignado FOREIGN KEY(id_usuario_asignado) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT FK_msp_pendientes_meta_toma FOREIGN KEY(id_usuario_toma) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT FK_msp_pendientes_meta_actualiza FOREIGN KEY(id_usuario_actualiza) REFERENCES dbo.cr_usuarios(id)
    );
    CREATE INDEX IX_msp_pendientes_meta_asignado ON dbo.msp_pendientes_meta(id_usuario_asignado,estado_revision,pospuesto_hasta);
    CREATE INDEX IX_msp_pendientes_meta_estado ON dbo.msp_pendientes_meta(estado_revision,pospuesto_hasta);
END;
GO

IF OBJECT_ID(N'dbo.msp_pendientes_bitacora', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_pendientes_bitacora (
        id_pendiente_bitacora BIGINT IDENTITY(1,1) NOT NULL,
        pendiente_clave NVARCHAR(190) NOT NULL,
        accion NVARCHAR(30) NOT NULL,
        estado_anterior NVARCHAR(20) NULL,
        estado_nuevo NVARCHAR(20) NULL,
        id_usuario_asignado_anterior INT NULL,
        id_usuario_asignado_nuevo INT NULL,
        pospuesto_hasta_anterior DATE NULL,
        pospuesto_hasta_nuevo DATE NULL,
        comentario NVARCHAR(1000) NULL,
        id_usuario_accion INT NOT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_pendientes_bitacora_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_pendientes_bitacora PRIMARY KEY(id_pendiente_bitacora),
        CONSTRAINT CK_msp_pendientes_bitacora_accion CHECK(accion IN(N'ASIGNAR',N'TOMAR_REVISION',N'POSPONER',N'REABRIR',N'COMENTAR',N'LIBERAR_ASIGNACION')),
        CONSTRAINT FK_msp_pendientes_bitacora_usuario FOREIGN KEY(id_usuario_accion) REFERENCES dbo.cr_usuarios(id)
    );
    CREATE INDEX IX_msp_pendientes_bitacora_clave ON dbo.msp_pendientes_bitacora(pendiente_clave,fecha_registro DESC);
END;
GO

IF OBJECT_ID(N'dbo.msp_configuracion', N'U') IS NOT NULL
BEGIN
    MERGE dbo.msp_configuracion AS target
    USING (VALUES
        (N'pendientes.contrato_vence_alta_dias', N'15', N'Días para prioridad alta en vencimiento de contrato'),
        (N'pendientes.contrato_vence_normal_dias', N'30', N'Días para mostrar contrato próximo a vencer'),
        (N'pendientes.cobranza_critica_dias', N'90', N'Días de mora para prioridad crítica'),
        (N'pendientes.cobranza_alta_dias', N'30', N'Días de mora para prioridad alta'),
        (N'pendientes.cobranza_critica_monto', N'1000000', N'Monto vencido para prioridad crítica'),
        (N'pendientes.movimiento_sin_conciliar_dias', N'3', N'Antigüedad para movimiento bancario sin conciliar'),
        (N'pendientes.cierre_mensual_atraso_dias', N'5', N'Días posteriores al fin de mes para alertar cierre pendiente'),
        (N'pendientes.hora_cierre_caja', N'18:00', N'Hora operacional esperada de cierre de caja')
    ) AS source(clave,valor,descripcion)
    ON target.clave=source.clave
    WHEN NOT MATCHED THEN INSERT(clave,valor,descripcion) VALUES(source.clave,source.valor,source.descripcion);
END;
GO

PRINT N'Gestión operacional de Bandeja de Pendientes instalada.';
GO
