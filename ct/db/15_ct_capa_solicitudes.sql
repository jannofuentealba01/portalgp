/*
  Script: 15_ct_capa_solicitudes.sql
  Capa: Solicitudes
  Objetivo:
  - Crear el nucleo limpio de Solicitudes CT sin compatibilidad hacia atras.
  - Dejar el modelo base de Adquisicion listo y la estructura preparada para Fusion/Subdivision.
  - Materializar workflow, formularios tipados, asignaciones por area y trazabilidad.

  Nota:
  - Si detecta tablas legacy del MVP anterior, este script falla y obliga a ejecutar
    core_ct_full.sql o core_ct_drop.sql + core_ct_init.sql.
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID('dbo.ct_solicitud_adquisicion_draft', 'U') IS NOT NULL
   OR OBJECT_ID('dbo.ct_solicitud_area_respuesta', 'U') IS NOT NULL
   OR OBJECT_ID('dbo.ct_solicitud_participante', 'U') IS NOT NULL
BEGIN
    THROW 50060, 'Se detecto esquema legacy de Solicitudes. Ejecuta core_ct_full.sql para reconstruir CT sin compatibilidad hacia atras.', 1;
END;
GO

IF OBJECT_ID('dbo.ct_solicitud', 'U') IS NOT NULL
   AND COL_LENGTH('dbo.ct_solicitud', 'id_gerente_usuario') IS NULL
BEGIN
    THROW 50061, 'La tabla dbo.ct_solicitud no corresponde al modelo nuevo. Ejecuta core_ct_full.sql.', 1;
END;
GO

/* =========================
   Catalogos base
   ========================= */

IF OBJECT_ID('dbo.ct_tipo_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_tipo_solicitud (
        id_tipo_solicitud      INT IDENTITY(1,1) NOT NULL,
        codigo                 NVARCHAR(50) NOT NULL,
        nombre                 NVARCHAR(120) NOT NULL,
        descripcion            NVARCHAR(500) NULL,
        activo                 BIT NOT NULL CONSTRAINT DF_ct_tipo_solicitud_activo DEFAULT (1),
        fecha_creacion         DATETIME2(0) NOT NULL CONSTRAINT DF_ct_tipo_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion    DATETIME2(0) NOT NULL CONSTRAINT DF_ct_tipo_solicitud_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_tipo_solicitud PRIMARY KEY CLUSTERED (id_tipo_solicitud),
        CONSTRAINT UQ_ct_tipo_solicitud_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_estado_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_estado_solicitud (
        id_estado_solicitud    INT IDENTITY(1,1) NOT NULL,
        codigo                 NVARCHAR(50) NOT NULL,
        nombre                 NVARCHAR(120) NOT NULL,
        orden_visual           INT NOT NULL CONSTRAINT DF_ct_estado_solicitud_orden_visual DEFAULT (0),
        es_terminal            BIT NOT NULL CONSTRAINT DF_ct_estado_solicitud_es_terminal DEFAULT (0),
        activo                 BIT NOT NULL CONSTRAINT DF_ct_estado_solicitud_activo DEFAULT (1),
        fecha_creacion         DATETIME2(0) NOT NULL CONSTRAINT DF_ct_estado_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_estado_solicitud PRIMARY KEY CLUSTERED (id_estado_solicitud),
        CONSTRAINT UQ_ct_estado_solicitud_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_area_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_area_solicitud (
        id_area_solicitud      INT IDENTITY(1,1) NOT NULL,
        codigo                 NVARCHAR(50) NOT NULL,
        nombre                 NVARCHAR(120) NOT NULL,
        descripcion            NVARCHAR(500) NULL,
        orden_visual           INT NOT NULL CONSTRAINT DF_ct_area_solicitud_orden_visual DEFAULT (0),
        activo                 BIT NOT NULL CONSTRAINT DF_ct_area_solicitud_activo DEFAULT (1),
        fecha_creacion         DATETIME2(0) NOT NULL CONSTRAINT DF_ct_area_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_area_solicitud PRIMARY KEY CLUSTERED (id_area_solicitud),
        CONSTRAINT UQ_ct_area_solicitud_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_estado_area_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_estado_area_solicitud (
        id_estado_area_solicitud INT IDENTITY(1,1) NOT NULL,
        codigo                   NVARCHAR(50) NOT NULL,
        nombre                   NVARCHAR(120) NOT NULL,
        orden_visual             INT NOT NULL CONSTRAINT DF_ct_estado_area_solicitud_orden_visual DEFAULT (0),
        es_terminal              BIT NOT NULL CONSTRAINT DF_ct_estado_area_solicitud_es_terminal DEFAULT (0),
        activo                   BIT NOT NULL CONSTRAINT DF_ct_estado_area_solicitud_activo DEFAULT (1),
        fecha_creacion           DATETIME2(0) NOT NULL CONSTRAINT DF_ct_estado_area_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_estado_area_solicitud PRIMARY KEY CLUSTERED (id_estado_area_solicitud),
        CONSTRAINT UQ_ct_estado_area_solicitud_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_rol_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_rol_solicitud (
        id_rol_solicitud       INT IDENTITY(1,1) NOT NULL,
        codigo                 NVARCHAR(50) NOT NULL,
        nombre                 NVARCHAR(120) NOT NULL,
        descripcion            NVARCHAR(500) NULL,
        activo                 BIT NOT NULL CONSTRAINT DF_ct_rol_solicitud_activo DEFAULT (1),
        fecha_creacion         DATETIME2(0) NOT NULL CONSTRAINT DF_ct_rol_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_rol_solicitud PRIMARY KEY CLUSTERED (id_rol_solicitud),
        CONSTRAINT UQ_ct_rol_solicitud_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_usuario_rol_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_usuario_rol_solicitud (
        id_usuario_rol_solicitud INT IDENTITY(1,1) NOT NULL,
        id_rol_solicitud         INT NOT NULL,
        id_usuario               INT NOT NULL,
        activo                   BIT NOT NULL CONSTRAINT DF_ct_usuario_rol_solicitud_activo DEFAULT (1),
        fecha_desde              DATETIME2(0) NOT NULL CONSTRAINT DF_ct_usuario_rol_solicitud_fecha_desde DEFAULT (SYSUTCDATETIME()),
        fecha_hasta              DATETIME2(0) NULL,
        creado_en                DATETIME2(0) NOT NULL CONSTRAINT DF_ct_usuario_rol_solicitud_creado_en DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_usuario_rol_solicitud PRIMARY KEY CLUSTERED (id_usuario_rol_solicitud),
        CONSTRAINT FK_ct_usuario_rol_solicitud_rol
            FOREIGN KEY (id_rol_solicitud) REFERENCES dbo.ct_rol_solicitud(id_rol_solicitud),
        CONSTRAINT UQ_ct_usuario_rol_solicitud UNIQUE (id_rol_solicitud, id_usuario),
        CONSTRAINT CK_ct_usuario_rol_solicitud_fechas CHECK (fecha_hasta IS NULL OR fecha_hasta >= fecha_desde)
    );
END;
GO

IF OBJECT_ID('dbo.ct_formulario_plantilla', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_formulario_plantilla (
        id_formulario_plantilla INT IDENTITY(1,1) NOT NULL,
        codigo                  NVARCHAR(80) NOT NULL,
        nombre                  NVARCHAR(150) NOT NULL,
        dominio                 NVARCHAR(50) NOT NULL,
        descripcion             NVARCHAR(500) NULL,
        activo                  BIT NOT NULL CONSTRAINT DF_ct_formulario_plantilla_activo DEFAULT (1),
        fecha_creacion          DATETIME2(0) NOT NULL CONSTRAINT DF_ct_formulario_plantilla_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion     DATETIME2(0) NOT NULL CONSTRAINT DF_ct_formulario_plantilla_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_formulario_plantilla PRIMARY KEY CLUSTERED (id_formulario_plantilla),
        CONSTRAINT UQ_ct_formulario_plantilla_codigo UNIQUE (codigo)
    );
END;
GO

IF OBJECT_ID('dbo.ct_formulario_plantilla_version', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_formulario_plantilla_version (
        id_formulario_plantilla_version INT IDENTITY(1,1) NOT NULL,
        id_formulario_plantilla         INT NOT NULL,
        version_numero                  INT NOT NULL,
        version_codigo                  NVARCHAR(50) NOT NULL,
        definicion_resumen              NVARCHAR(MAX) NULL,
        publicado                       BIT NOT NULL CONSTRAINT DF_ct_formulario_plantilla_version_publicado DEFAULT (1),
        vigente_desde                   DATETIME2(0) NOT NULL CONSTRAINT DF_ct_formulario_plantilla_version_vigente_desde DEFAULT (SYSUTCDATETIME()),
        vigente_hasta                   DATETIME2(0) NULL,
        fecha_creacion                  DATETIME2(0) NOT NULL CONSTRAINT DF_ct_formulario_plantilla_version_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_formulario_plantilla_version PRIMARY KEY CLUSTERED (id_formulario_plantilla_version),
        CONSTRAINT FK_ct_formulario_plantilla_version_plantilla
            FOREIGN KEY (id_formulario_plantilla) REFERENCES dbo.ct_formulario_plantilla(id_formulario_plantilla),
        CONSTRAINT UQ_ct_formulario_plantilla_version UNIQUE (id_formulario_plantilla, version_numero),
        CONSTRAINT CK_ct_formulario_plantilla_version_fechas CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_tipo_area', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_tipo_area (
        id_solicitud_tipo_area         INT IDENTITY(1,1) NOT NULL,
        id_tipo_solicitud              INT NOT NULL,
        id_area_solicitud              INT NOT NULL,
        id_formulario_plantilla        INT NOT NULL,
        orden_flujo                    INT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_orden_flujo DEFAULT (0),
        es_requerida                   BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_es_requerida DEFAULT (1),
        habilita_automaticamente       BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_habilita_automaticamente DEFAULT (1),
        requiere_formulario_tipado     BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_requiere_formulario_tipado DEFAULT (1),
        activo                         BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_activo DEFAULT (1),
        fecha_creacion                 DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_tipo_area PRIMARY KEY CLUSTERED (id_solicitud_tipo_area),
        CONSTRAINT FK_ct_solicitud_tipo_area_tipo
            FOREIGN KEY (id_tipo_solicitud) REFERENCES dbo.ct_tipo_solicitud(id_tipo_solicitud),
        CONSTRAINT FK_ct_solicitud_tipo_area_area
            FOREIGN KEY (id_area_solicitud) REFERENCES dbo.ct_area_solicitud(id_area_solicitud),
        CONSTRAINT FK_ct_solicitud_tipo_area_plantilla
            FOREIGN KEY (id_formulario_plantilla) REFERENCES dbo.ct_formulario_plantilla(id_formulario_plantilla),
        CONSTRAINT UQ_ct_solicitud_tipo_area UNIQUE (id_tipo_solicitud, id_area_solicitud)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_tipo_area_participante_default', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_tipo_area_participante_default (
        id_solicitud_tipo_area_participante_default INT IDENTITY(1,1) NOT NULL,
        id_solicitud_tipo_area                      INT NOT NULL,
        id_rol_solicitud                            INT NULL,
        id_usuario_default                          INT NULL,
        es_responsable                              BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_participante_default_es_responsable DEFAULT (0),
        orden_asignacion                            INT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_participante_default_orden DEFAULT (0),
        activo                                      BIT NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_participante_default_activo DEFAULT (1),
        fecha_creacion                              DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_tipo_area_participante_default_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_tipo_area_participante_default PRIMARY KEY CLUSTERED (id_solicitud_tipo_area_participante_default),
        CONSTRAINT FK_ct_solicitud_tipo_area_participante_default_tipo_area
            FOREIGN KEY (id_solicitud_tipo_area) REFERENCES dbo.ct_solicitud_tipo_area(id_solicitud_tipo_area),
        CONSTRAINT FK_ct_solicitud_tipo_area_participante_default_rol
            FOREIGN KEY (id_rol_solicitud) REFERENCES dbo.ct_rol_solicitud(id_rol_solicitud),
        CONSTRAINT CK_ct_solicitud_tipo_area_participante_default_origen
            CHECK (id_rol_solicitud IS NOT NULL OR id_usuario_default IS NOT NULL)
    );
END;
GO

/* =========================
   Solicitudes y workflow
   ========================= */

IF OBJECT_ID('dbo.ct_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud (
        id_solicitud              INT IDENTITY(1,1) NOT NULL,
        codigo_solicitud          NVARCHAR(50) NULL,
        id_tipo_solicitud         INT NOT NULL,
        id_estado_solicitud       INT NOT NULL,
        id_gerente_usuario        INT NOT NULL,
        resumen                   NVARCHAR(500) NULL,
        prioridad                 TINYINT NOT NULL CONSTRAINT DF_ct_solicitud_prioridad DEFAULT (2),
        fecha_creacion            DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion       DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        fecha_cierre              DATETIME2(0) NULL,
        id_terreno_generado       INT NULL,
        id_operacion_generada     INT NULL,
        payload_extra_json        NVARCHAR(MAX) NULL,
        CONSTRAINT PK_ct_solicitud PRIMARY KEY CLUSTERED (id_solicitud),
        CONSTRAINT FK_ct_solicitud_tipo
            FOREIGN KEY (id_tipo_solicitud) REFERENCES dbo.ct_tipo_solicitud(id_tipo_solicitud),
        CONSTRAINT FK_ct_solicitud_estado
            FOREIGN KEY (id_estado_solicitud) REFERENCES dbo.ct_estado_solicitud(id_estado_solicitud),
        CONSTRAINT FK_ct_solicitud_terreno_generado
            FOREIGN KEY (id_terreno_generado) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT FK_ct_solicitud_operacion_generada
            FOREIGN KEY (id_operacion_generada) REFERENCES dbo.ct_operacion_predial(id_operacion),
        CONSTRAINT CK_ct_solicitud_prioridad CHECK (prioridad BETWEEN 1 AND 5),
        CONSTRAINT CK_ct_solicitud_codigo_solicitud CHECK (codigo_solicitud IS NULL OR LTRIM(RTRIM(codigo_solicitud)) <> '')
    );
END;
GO

IF OBJECT_ID('dbo.ct_participante_solicitud', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_participante_solicitud (
        id_participante_solicitud INT IDENTITY(1,1) NOT NULL,
        id_solicitud              INT NOT NULL,
        id_usuario                INT NOT NULL,
        id_rol_solicitud          INT NULL,
        tipo_participacion        NVARCHAR(50) NOT NULL CONSTRAINT DF_ct_participante_solicitud_tipo DEFAULT ('COLABORADOR'),
        activo                    BIT NOT NULL CONSTRAINT DF_ct_participante_solicitud_activo DEFAULT (1),
        fecha_creacion            DATETIME2(0) NOT NULL CONSTRAINT DF_ct_participante_solicitud_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion       DATETIME2(0) NOT NULL CONSTRAINT DF_ct_participante_solicitud_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_participante_solicitud PRIMARY KEY CLUSTERED (id_participante_solicitud),
        CONSTRAINT FK_ct_participante_solicitud_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_participante_solicitud_rol
            FOREIGN KEY (id_rol_solicitud) REFERENCES dbo.ct_rol_solicitud(id_rol_solicitud),
        CONSTRAINT UQ_ct_participante_solicitud UNIQUE (id_solicitud, id_usuario, tipo_participacion),
        CONSTRAINT CK_ct_participante_solicitud_tipo CHECK (tipo_participacion IN ('GERENTE', 'COLABORADOR', 'OBSERVADOR', 'APROBADOR'))
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_terreno', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_terreno (
        id_solicitud_terreno      INT IDENTITY(1,1) NOT NULL,
        id_solicitud              INT NOT NULL,
        id_terreno                INT NOT NULL,
        rol_relacion              NVARCHAR(30) NOT NULL,
        es_principal              BIT NOT NULL CONSTRAINT DF_ct_solicitud_terreno_es_principal DEFAULT (0),
        observacion               NVARCHAR(500) NULL,
        fecha_creacion            DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_terreno_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_terreno PRIMARY KEY CLUSTERED (id_solicitud_terreno),
        CONSTRAINT FK_ct_solicitud_terreno_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_terreno_terreno
            FOREIGN KEY (id_terreno) REFERENCES dbo.ct_terreno(id_terreno),
        CONSTRAINT UQ_ct_solicitud_terreno UNIQUE (id_solicitud, id_terreno, rol_relacion),
        CONSTRAINT CK_ct_solicitud_terreno_rol CHECK (rol_relacion IN ('ORIGEN', 'RESULTADO', 'AFECTADO', 'GENERADO'))
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_area_instancia', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_area_instancia (
        id_area_instancia                 INT IDENTITY(1,1) NOT NULL,
        id_solicitud                      INT NOT NULL,
        id_area_solicitud                 INT NOT NULL,
        id_solicitud_tipo_area            INT NULL,
        id_estado_area_solicitud          INT NOT NULL,
        id_formulario_plantilla_version   INT NOT NULL,
        es_requerida                      BIT NOT NULL CONSTRAINT DF_ct_solicitud_area_instancia_es_requerida DEFAULT (1),
        orden_flujo                       INT NOT NULL CONSTRAINT DF_ct_solicitud_area_instancia_orden_flujo DEFAULT (0),
        habilitada_en                     DATETIME2(0) NULL,
        completada_en                     DATETIME2(0) NULL,
        cerrada_en                        DATETIME2(0) NULL,
        observacion_abierta               NVARCHAR(1000) NULL,
        fecha_creacion                    DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_area_instancia_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion               DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_area_instancia_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_area_instancia PRIMARY KEY CLUSTERED (id_area_instancia),
        CONSTRAINT FK_ct_solicitud_area_instancia_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_area_instancia_area
            FOREIGN KEY (id_area_solicitud) REFERENCES dbo.ct_area_solicitud(id_area_solicitud),
        CONSTRAINT FK_ct_solicitud_area_instancia_tipo_area
            FOREIGN KEY (id_solicitud_tipo_area) REFERENCES dbo.ct_solicitud_tipo_area(id_solicitud_tipo_area),
        CONSTRAINT FK_ct_solicitud_area_instancia_estado
            FOREIGN KEY (id_estado_area_solicitud) REFERENCES dbo.ct_estado_area_solicitud(id_estado_area_solicitud),
        CONSTRAINT FK_ct_solicitud_area_instancia_plantilla_version
            FOREIGN KEY (id_formulario_plantilla_version) REFERENCES dbo.ct_formulario_plantilla_version(id_formulario_plantilla_version),
        CONSTRAINT UQ_ct_solicitud_area_instancia UNIQUE (id_solicitud, id_area_solicitud)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_area_asignacion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_area_asignacion (
        id_area_asignacion          INT IDENTITY(1,1) NOT NULL,
        id_area_instancia           INT NOT NULL,
        id_usuario_asignado         INT NOT NULL,
        id_participante_solicitud   INT NULL,
        es_responsable              BIT NOT NULL CONSTRAINT DF_ct_solicitud_area_asignacion_es_responsable DEFAULT (0),
        activo                      BIT NOT NULL CONSTRAINT DF_ct_solicitud_area_asignacion_activo DEFAULT (1),
        fecha_asignacion            DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_area_asignacion_fecha_asignacion DEFAULT (SYSUTCDATETIME()),
        fecha_liberacion            DATETIME2(0) NULL,
        observacion                 NVARCHAR(500) NULL,
        CONSTRAINT PK_ct_solicitud_area_asignacion PRIMARY KEY CLUSTERED (id_area_asignacion),
        CONSTRAINT FK_ct_solicitud_area_asignacion_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT FK_ct_solicitud_area_asignacion_participante
            FOREIGN KEY (id_participante_solicitud) REFERENCES dbo.ct_participante_solicitud(id_participante_solicitud),
        CONSTRAINT UQ_ct_solicitud_area_asignacion UNIQUE (id_area_instancia, id_usuario_asignado),
        CONSTRAINT CK_ct_solicitud_area_asignacion_fechas CHECK (fecha_liberacion IS NULL OR fecha_liberacion >= fecha_asignacion)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_historial_estado', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_historial_estado (
        id_historial_estado         INT IDENTITY(1,1) NOT NULL,
        id_solicitud                INT NOT NULL,
        id_area_instancia           INT NULL,
        tipo_entidad                NVARCHAR(20) NOT NULL,
        id_estado_solicitud_anterior INT NULL,
        id_estado_solicitud_nuevo   INT NULL,
        id_estado_area_anterior     INT NULL,
        id_estado_area_nuevo        INT NULL,
        id_usuario_accion           INT NULL,
        comentario                  NVARCHAR(1000) NULL,
        fecha_cambio                DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_historial_estado_fecha_cambio DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_historial_estado PRIMARY KEY CLUSTERED (id_historial_estado),
        CONSTRAINT FK_ct_solicitud_historial_estado_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_historial_estado_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT FK_ct_solicitud_historial_estado_estado_solicitud_anterior
            FOREIGN KEY (id_estado_solicitud_anterior) REFERENCES dbo.ct_estado_solicitud(id_estado_solicitud),
        CONSTRAINT FK_ct_solicitud_historial_estado_estado_solicitud_nuevo
            FOREIGN KEY (id_estado_solicitud_nuevo) REFERENCES dbo.ct_estado_solicitud(id_estado_solicitud),
        CONSTRAINT FK_ct_solicitud_historial_estado_estado_area_anterior
            FOREIGN KEY (id_estado_area_anterior) REFERENCES dbo.ct_estado_area_solicitud(id_estado_area_solicitud),
        CONSTRAINT FK_ct_solicitud_historial_estado_estado_area_nuevo
            FOREIGN KEY (id_estado_area_nuevo) REFERENCES dbo.ct_estado_area_solicitud(id_estado_area_solicitud),
        CONSTRAINT CK_ct_solicitud_historial_estado_tipo CHECK (tipo_entidad IN ('SOLICITUD', 'AREA'))
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_comentario', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_comentario (
        id_solicitud_comentario    INT IDENTITY(1,1) NOT NULL,
        id_solicitud               INT NOT NULL,
        id_area_instancia          INT NULL,
        id_usuario                 INT NOT NULL,
        tipo_comentario            NVARCHAR(30) NOT NULL CONSTRAINT DF_ct_solicitud_comentario_tipo DEFAULT ('COMENTARIO'),
        estado_revision            NVARCHAR(20) NOT NULL CONSTRAINT DF_ct_solicitud_comentario_estado_revision DEFAULT ('PENDIENTE'),
        resuelto_en                DATETIME2(0) NULL,
        id_usuario_resolucion      INT NULL,
        comentario                 NVARCHAR(MAX) NOT NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_comentario_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_comentario PRIMARY KEY CLUSTERED (id_solicitud_comentario),
        CONSTRAINT FK_ct_solicitud_comentario_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_comentario_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT CK_ct_solicitud_comentario_tipo CHECK (tipo_comentario IN ('COMENTARIO', 'OBSERVACION', 'RESPUESTA')),
        CONSTRAINT CK_ct_solicitud_comentario_estado_revision CHECK (estado_revision IN ('PENDIENTE', 'RESUELTO'))
    );
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'estado_revision') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD estado_revision NVARCHAR(20) NOT NULL
        CONSTRAINT DF_ct_solicitud_comentario_estado_revision DEFAULT ('PENDIENTE');
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'resuelto_en') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD resuelto_en DATETIME2(0) NULL;
END;
GO

IF COL_LENGTH('dbo.ct_solicitud_comentario', 'id_usuario_resolucion') IS NULL
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario
    ADD id_usuario_resolucion INT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_ct_solicitud_comentario_estado_revision')
BEGIN
    ALTER TABLE dbo.ct_solicitud_comentario WITH CHECK
    ADD CONSTRAINT CK_ct_solicitud_comentario_estado_revision
        CHECK (estado_revision IN ('PENDIENTE', 'RESUELTO'));
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_adjunto', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_adjunto (
        id_solicitud_adjunto       INT IDENTITY(1,1) NOT NULL,
        id_solicitud               INT NOT NULL,
        id_area_instancia          INT NULL,
        nombre                     NVARCHAR(255) NOT NULL,
        tipo                       NVARCHAR(120) NULL,
        referencia                 NVARCHAR(500) NOT NULL,
        nota                       NVARCHAR(500) NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adjunto_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_adjunto PRIMARY KEY CLUSTERED (id_solicitud_adjunto),
        CONSTRAINT FK_ct_solicitud_adjunto_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_adjunto_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_notificacion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_notificacion (
        id_solicitud_notificacion  INT IDENTITY(1,1) NOT NULL,
        id_solicitud               INT NOT NULL,
        id_area_instancia          INT NULL,
        tipo_evento                NVARCHAR(50) NOT NULL,
        id_usuario_destinatario    INT NULL,
        destinatario               NVARCHAR(180) NOT NULL,
        asunto                     NVARCHAR(255) NOT NULL,
        payload                    NVARCHAR(MAX) NULL,
        estado                     NVARCHAR(30) NOT NULL CONSTRAINT DF_ct_solicitud_notificacion_estado DEFAULT ('PENDIENTE'),
        intentos                   INT NOT NULL CONSTRAINT DF_ct_solicitud_notificacion_intentos DEFAULT (0),
        fecha_ultimo_intento       DATETIME2(0) NULL,
        fecha_envio                DATETIME2(0) NULL,
        error_ultimo               NVARCHAR(2000) NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_notificacion_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_notificacion PRIMARY KEY CLUSTERED (id_solicitud_notificacion),
        CONSTRAINT FK_ct_solicitud_notificacion_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_notificacion_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT CK_ct_solicitud_notificacion_estado CHECK (estado IN ('PENDIENTE', 'ENVIADA', 'ERROR', 'CANCELADA')),
        CONSTRAINT CK_ct_solicitud_notificacion_intentos CHECK (intentos >= 0)
    );
END;
GO

/* =========================
   Formularios tipados MVP
   ========================= */

IF OBJECT_ID('dbo.ct_solicitud_adquisicion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_adquisicion (
        id_solicitud               INT NOT NULL,
        rol_propuesto              VARCHAR(30) NULL,
        rol_matriz                 VARCHAR(30) NULL,
        identificacion_propiedad   NVARCHAR(120) NULL,
        superficie_m2              DECIMAL(18,2) NULL,
        id_comuna                  INT NULL,
        id_tipo_inmueble           INT NULL,
        fecha_adquisicion          DATE NULL,
        documento_fuente           NVARCHAR(255) NULL,
        destino_uso                NVARCHAR(200) NULL,
        observaciones              NVARCHAR(1000) NULL,
        payload_extra_json         NVARCHAR(MAX) NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion        DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_adquisicion PRIMARY KEY CLUSTERED (id_solicitud),
        CONSTRAINT FK_ct_solicitud_adquisicion_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_adquisicion_comuna
            FOREIGN KEY (id_comuna) REFERENCES dbo.ct_comuna(id_comuna),
        CONSTRAINT FK_ct_solicitud_adquisicion_tipo_inmueble
            FOREIGN KEY (id_tipo_inmueble) REFERENCES dbo.ct_tipo_inmueble(id_tipo_inmueble),
        CONSTRAINT CK_ct_solicitud_adquisicion_superficie CHECK (superficie_m2 IS NULL OR superficie_m2 > 0)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_adquisicion_titular', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_adquisicion_titular (
        id_solicitud_titular       INT IDENTITY(1,1) NOT NULL,
        id_solicitud               INT NOT NULL,
        id_tercero                 INT NOT NULL,
        porcentaje_derecho         DECIMAL(9,2) NOT NULL,
        vigente_desde              DATE NOT NULL,
        vigente_hasta              DATE NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_titular_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion        DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_titular_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_adquisicion_titular PRIMARY KEY CLUSTERED (id_solicitud_titular),
        CONSTRAINT FK_ct_solicitud_adquisicion_titular_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT FK_ct_solicitud_adquisicion_titular_tercero
            FOREIGN KEY (id_tercero) REFERENCES dbo.ct_tercero(id_tercero),
        CONSTRAINT CK_ct_solicitud_adquisicion_titular_porcentaje CHECK (porcentaje_derecho > 0 AND porcentaje_derecho <= 100),
        CONSTRAINT CK_ct_solicitud_adquisicion_titular_fechas CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_adquisicion_legal', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_adquisicion_legal (
        id_area_instancia          INT NOT NULL,
        id_solicitud               INT NOT NULL,
        estudio_titulos_ok         BIT NULL,
        prohibiciones_hipotecas    BIT NULL,
        litigios_vigentes          BIT NULL,
        observaciones_legal        NVARCHAR(2000) NULL,
        payload_extra_json         NVARCHAR(MAX) NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_legal_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion        DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_legal_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_adquisicion_legal PRIMARY KEY CLUSTERED (id_area_instancia),
        CONSTRAINT FK_ct_solicitud_adquisicion_legal_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT FK_ct_solicitud_adquisicion_legal_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud)
    );
END;
GO

IF OBJECT_ID('dbo.ct_solicitud_adquisicion_arquitectura', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.ct_solicitud_adquisicion_arquitectura (
        id_area_instancia          INT NOT NULL,
        id_solicitud               INT NOT NULL,
        informe_tecnico_ok         BIT NULL,
        superficie_validada_m2     DECIMAL(18,2) NULL,
        requiere_regularizacion    BIT NULL,
        observaciones_arquitectura NVARCHAR(2000) NULL,
        payload_extra_json         NVARCHAR(MAX) NULL,
        fecha_creacion             DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_arquitectura_fecha_creacion DEFAULT (SYSUTCDATETIME()),
        fecha_actualizacion        DATETIME2(0) NOT NULL CONSTRAINT DF_ct_solicitud_adquisicion_arquitectura_fecha_actualizacion DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT PK_ct_solicitud_adquisicion_arquitectura PRIMARY KEY CLUSTERED (id_area_instancia),
        CONSTRAINT FK_ct_solicitud_adquisicion_arquitectura_area_instancia
            FOREIGN KEY (id_area_instancia) REFERENCES dbo.ct_solicitud_area_instancia(id_area_instancia),
        CONSTRAINT FK_ct_solicitud_adquisicion_arquitectura_solicitud
            FOREIGN KEY (id_solicitud) REFERENCES dbo.ct_solicitud(id_solicitud),
        CONSTRAINT CK_ct_solicitud_adquisicion_arquitectura_superficie CHECK (superficie_validada_m2 IS NULL OR superficie_validada_m2 > 0)
    );
END;
GO

/* =========================
   Indices operativos
   ========================= */

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_estado' AND object_id = OBJECT_ID('dbo.ct_solicitud'))
BEGIN
    CREATE INDEX IX_ct_solicitud_estado
        ON dbo.ct_solicitud (id_estado_solicitud, fecha_actualizacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'UX_ct_solicitud_codigo_informado' AND object_id = OBJECT_ID('dbo.ct_solicitud'))
BEGIN
    CREATE UNIQUE INDEX UX_ct_solicitud_codigo_informado
        ON dbo.ct_solicitud (codigo_solicitud)
        WHERE codigo_solicitud IS NOT NULL;
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_gerente' AND object_id = OBJECT_ID('dbo.ct_solicitud'))
BEGIN
    CREATE INDEX IX_ct_solicitud_gerente
        ON dbo.ct_solicitud (id_gerente_usuario, fecha_creacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_terreno_terreno' AND object_id = OBJECT_ID('dbo.ct_solicitud_terreno'))
BEGIN
    CREATE INDEX IX_ct_solicitud_terreno_terreno
        ON dbo.ct_solicitud_terreno (id_terreno, rol_relacion);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_area_instancia_solicitud' AND object_id = OBJECT_ID('dbo.ct_solicitud_area_instancia'))
BEGIN
    CREATE INDEX IX_ct_solicitud_area_instancia_solicitud
        ON dbo.ct_solicitud_area_instancia (id_solicitud, id_estado_area_solicitud, orden_flujo);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_area_asignacion_usuario' AND object_id = OBJECT_ID('dbo.ct_solicitud_area_asignacion'))
BEGIN
    CREATE INDEX IX_ct_solicitud_area_asignacion_usuario
        ON dbo.ct_solicitud_area_asignacion (id_usuario_asignado, activo, fecha_asignacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_historial_estado_solicitud' AND object_id = OBJECT_ID('dbo.ct_solicitud_historial_estado'))
BEGIN
    CREATE INDEX IX_ct_solicitud_historial_estado_solicitud
        ON dbo.ct_solicitud_historial_estado (id_solicitud, fecha_cambio DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_comentario_solicitud' AND object_id = OBJECT_ID('dbo.ct_solicitud_comentario'))
BEGIN
    CREATE INDEX IX_ct_solicitud_comentario_solicitud
        ON dbo.ct_solicitud_comentario (id_solicitud, fecha_creacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_comentario_estado' AND object_id = OBJECT_ID('dbo.ct_solicitud_comentario'))
BEGIN
    CREATE INDEX IX_ct_solicitud_comentario_estado
        ON dbo.ct_solicitud_comentario (id_solicitud, estado_revision, fecha_creacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_adjunto_solicitud' AND object_id = OBJECT_ID('dbo.ct_solicitud_adjunto'))
BEGIN
    CREATE INDEX IX_ct_solicitud_adjunto_solicitud
        ON dbo.ct_solicitud_adjunto (id_solicitud, fecha_creacion DESC);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_notificacion_outbox' AND object_id = OBJECT_ID('dbo.ct_solicitud_notificacion'))
BEGIN
    CREATE INDEX IX_ct_solicitud_notificacion_outbox
        ON dbo.ct_solicitud_notificacion (estado, fecha_creacion, intentos);
END;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name = 'IX_ct_solicitud_adquisicion_titular_solicitud' AND object_id = OBJECT_ID('dbo.ct_solicitud_adquisicion_titular'))
BEGIN
    CREATE INDEX IX_ct_solicitud_adquisicion_titular_solicitud
        ON dbo.ct_solicitud_adquisicion_titular (id_solicitud, vigente_desde);
END;
GO

/* =========================
   Seeds
   ========================= */

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_solicitud WHERE codigo = 'ADQUISICION')
BEGIN
    INSERT INTO dbo.ct_tipo_solicitud (codigo, nombre, descripcion)
    VALUES ('ADQUISICION', 'Adquisicion', 'Ingreso de un nuevo terreno al sistema materializado final.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_solicitud WHERE codigo = 'FUSION')
BEGIN
    INSERT INTO dbo.ct_tipo_solicitud (codigo, nombre, descripcion)
    VALUES ('FUSION', 'Fusion', 'Operacion predial que consolida terrenos origen en un resultado.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_solicitud WHERE codigo = 'SUBDIVISION')
BEGIN
    INSERT INTO dbo.ct_tipo_solicitud (codigo, nombre, descripcion)
    VALUES ('SUBDIVISION', 'Subdivision', 'Operacion predial que divide un terreno origen en multiples resultados.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_tipo_solicitud WHERE codigo = 'MODIFICACION')
BEGIN
    INSERT INTO dbo.ct_tipo_solicitud (codigo, nombre, descripcion)
    VALUES ('MODIFICACION', 'Modificacion', 'Ajuste posterior sobre informacion predial ya materializada.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'BORRADOR')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('BORRADOR', 'Borrador', 10, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'EN_REVISION')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('EN_REVISION', 'En revision', 20, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'CON_OBSERVACIONES')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('CON_OBSERVACIONES', 'Con observaciones', 30, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'LISTA_PARA_APROBAR')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('LISTA_PARA_APROBAR', 'Lista para aprobar', 40, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'APROBADA')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('APROBADA', 'Aprobada', 50, 1);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_solicitud WHERE codigo = 'ANULADA')
BEGIN
    INSERT INTO dbo.ct_estado_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('ANULADA', 'Anulada', 60, 1);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'PENDIENTE')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('PENDIENTE', 'Pendiente', 10, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'HABILITADA')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('HABILITADA', 'Habilitada', 20, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'EN_PROCESO')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('EN_PROCESO', 'En proceso', 30, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'CON_OBSERVACIONES')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('CON_OBSERVACIONES', 'Con observaciones', 40, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'COMPLETA')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('COMPLETA', 'Completa', 50, 0);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_estado_area_solicitud WHERE codigo = 'CERRADA')
BEGIN
    INSERT INTO dbo.ct_estado_area_solicitud (codigo, nombre, orden_visual, es_terminal)
    VALUES ('CERRADA', 'Cerrada', 60, 1);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_area_solicitud WHERE codigo = 'LEGAL')
BEGIN
    INSERT INTO dbo.ct_area_solicitud (codigo, nombre, descripcion, orden_visual)
    VALUES ('LEGAL', 'Legal', 'Revision legal y documental de la solicitud.', 10);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_area_solicitud WHERE codigo = 'ARQUITECTURA')
BEGIN
    INSERT INTO dbo.ct_area_solicitud (codigo, nombre, descripcion, orden_visual)
    VALUES ('ARQUITECTURA', 'Arquitectura', 'Revision tecnica y de soporte arquitectonico.', 20);
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_rol_solicitud WHERE codigo = 'GERENTE_SOLICITUD')
BEGIN
    INSERT INTO dbo.ct_rol_solicitud (codigo, nombre, descripcion)
    VALUES ('GERENTE_SOLICITUD', 'Gerente de solicitud', 'Usuario funcional autorizado para crear, observar, aprobar y anular solicitudes.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_formulario_plantilla WHERE codigo = 'SOLICITUD_ADQUISICION_GENERAL')
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla (codigo, nombre, dominio, descripcion)
    VALUES ('SOLICITUD_ADQUISICION_GENERAL', 'Solicitud Adquisicion General', 'SOLICITUD', 'Formulario general completado por el gerente para adquisiciones.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_formulario_plantilla WHERE codigo = 'SOLICITUD_ADQUISICION_LEGAL')
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla (codigo, nombre, dominio, descripcion)
    VALUES ('SOLICITUD_ADQUISICION_LEGAL', 'Solicitud Adquisicion Legal', 'LEGAL', 'Formulario tipado del area Legal para adquisiciones.');
END;
GO

IF NOT EXISTS (SELECT 1 FROM dbo.ct_formulario_plantilla WHERE codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA')
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla (codigo, nombre, dominio, descripcion)
    VALUES ('SOLICITUD_ADQUISICION_ARQUITECTURA', 'Solicitud Adquisicion Arquitectura', 'ARQUITECTURA', 'Formulario tipado del area Arquitectura para adquisiciones.');
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_formulario_plantilla_version v
    INNER JOIN dbo.ct_formulario_plantilla p
        ON p.id_formulario_plantilla = v.id_formulario_plantilla
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_GENERAL'
      AND v.version_numero = 1
)
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla_version (
        id_formulario_plantilla,
        version_numero,
        version_codigo,
        definicion_resumen
    )
    SELECT
        p.id_formulario_plantilla,
        1,
        'v1',
        N'Campos base: rol propuesto, rol matriz, identificacion, superficie, comuna, tipo de inmueble, fecha y documento fuente.'
    FROM dbo.ct_formulario_plantilla p
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_GENERAL';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_formulario_plantilla_version v
    INNER JOIN dbo.ct_formulario_plantilla p
        ON p.id_formulario_plantilla = v.id_formulario_plantilla
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_LEGAL'
      AND v.version_numero = 1
)
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla_version (
        id_formulario_plantilla,
        version_numero,
        version_codigo,
        definicion_resumen
    )
    SELECT
        p.id_formulario_plantilla,
        1,
        'v1',
        N'Campos base: estudio de titulos, prohibiciones/hipotecas, litigios y observaciones legales.'
    FROM dbo.ct_formulario_plantilla p
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_LEGAL';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_formulario_plantilla_version v
    INNER JOIN dbo.ct_formulario_plantilla p
        ON p.id_formulario_plantilla = v.id_formulario_plantilla
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA'
      AND v.version_numero = 1
)
BEGIN
    INSERT INTO dbo.ct_formulario_plantilla_version (
        id_formulario_plantilla,
        version_numero,
        version_codigo,
        definicion_resumen
    )
    SELECT
        p.id_formulario_plantilla,
        1,
        'v1',
        N'Campos base: informe tecnico, superficie validada, regularizacion y observaciones de arquitectura.'
    FROM dbo.ct_formulario_plantilla p
    WHERE p.codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_solicitud_tipo_area sta
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
    INNER JOIN dbo.ct_area_solicitud a
        ON a.id_area_solicitud = sta.id_area_solicitud
    WHERE ts.codigo = 'ADQUISICION'
      AND a.codigo = 'LEGAL'
)
BEGIN
    INSERT INTO dbo.ct_solicitud_tipo_area (
        id_tipo_solicitud,
        id_area_solicitud,
        id_formulario_plantilla,
        orden_flujo,
        es_requerida,
        habilita_automaticamente,
        requiere_formulario_tipado,
        activo
    )
    SELECT
        ts.id_tipo_solicitud,
        a.id_area_solicitud,
        p.id_formulario_plantilla,
        10,
        1,
        1,
        1,
        1
    FROM dbo.ct_tipo_solicitud ts
    CROSS JOIN dbo.ct_area_solicitud a
    CROSS JOIN dbo.ct_formulario_plantilla p
    WHERE ts.codigo = 'ADQUISICION'
      AND a.codigo = 'LEGAL'
      AND p.codigo = 'SOLICITUD_ADQUISICION_LEGAL';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.ct_solicitud_tipo_area sta
    INNER JOIN dbo.ct_tipo_solicitud ts
        ON ts.id_tipo_solicitud = sta.id_tipo_solicitud
    INNER JOIN dbo.ct_area_solicitud a
        ON a.id_area_solicitud = sta.id_area_solicitud
    WHERE ts.codigo = 'ADQUISICION'
      AND a.codigo = 'ARQUITECTURA'
)
BEGIN
    INSERT INTO dbo.ct_solicitud_tipo_area (
        id_tipo_solicitud,
        id_area_solicitud,
        id_formulario_plantilla,
        orden_flujo,
        es_requerida,
        habilita_automaticamente,
        requiere_formulario_tipado,
        activo
    )
    SELECT
        ts.id_tipo_solicitud,
        a.id_area_solicitud,
        p.id_formulario_plantilla,
        20,
        1,
        1,
        1,
        1
    FROM dbo.ct_tipo_solicitud ts
    CROSS JOIN dbo.ct_area_solicitud a
    CROSS JOIN dbo.ct_formulario_plantilla p
    WHERE ts.codigo = 'ADQUISICION'
      AND a.codigo = 'ARQUITECTURA'
      AND p.codigo = 'SOLICITUD_ADQUISICION_ARQUITECTURA';
END;
GO

/* =========================
   FKs opcionales a dbo.cr_usuarios
   ========================= */

IF OBJECT_ID('dbo.cr_usuarios', 'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.cr_usuarios', 'id_usuario') IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_usuario_rol_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_usuario_rol_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_usuario_rol_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_cr_usuarios_gerente')
            ALTER TABLE dbo.ct_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_cr_usuarios_gerente FOREIGN KEY (id_gerente_usuario) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_participante_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_participante_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_participante_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_area_asignacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_area_asignacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_area_asignacion_cr_usuarios FOREIGN KEY (id_usuario_asignado) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_historial_estado_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_historial_estado WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_historial_estado_cr_usuarios FOREIGN KEY (id_usuario_accion) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_comentario_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_comentario WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_comentario_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_notificacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_notificacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_notificacion_cr_usuarios FOREIGN KEY (id_usuario_destinatario) REFERENCES dbo.cr_usuarios(id_usuario);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_tipo_area_part_default_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_tipo_area_participante_default WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_tipo_area_part_default_cr_usuarios FOREIGN KEY (id_usuario_default) REFERENCES dbo.cr_usuarios(id_usuario);
    END
    ELSE IF COL_LENGTH('dbo.cr_usuarios', 'id') IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_usuario_rol_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_usuario_rol_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_usuario_rol_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_cr_usuarios_gerente')
            ALTER TABLE dbo.ct_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_cr_usuarios_gerente FOREIGN KEY (id_gerente_usuario) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_participante_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_participante_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_participante_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_area_asignacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_area_asignacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_area_asignacion_cr_usuarios FOREIGN KEY (id_usuario_asignado) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_historial_estado_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_historial_estado WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_historial_estado_cr_usuarios FOREIGN KEY (id_usuario_accion) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_comentario_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_comentario WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_comentario_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_notificacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_notificacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_notificacion_cr_usuarios FOREIGN KEY (id_usuario_destinatario) REFERENCES dbo.cr_usuarios(id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_tipo_area_part_default_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_tipo_area_participante_default WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_tipo_area_part_default_cr_usuarios FOREIGN KEY (id_usuario_default) REFERENCES dbo.cr_usuarios(id);
    END
    ELSE IF COL_LENGTH('dbo.cr_usuarios', 'Id') IS NOT NULL
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_usuario_rol_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_usuario_rol_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_usuario_rol_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_cr_usuarios_gerente')
            ALTER TABLE dbo.ct_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_cr_usuarios_gerente FOREIGN KEY (id_gerente_usuario) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_participante_solicitud_cr_usuarios')
            ALTER TABLE dbo.ct_participante_solicitud WITH CHECK
            ADD CONSTRAINT FK_ct_participante_solicitud_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_area_asignacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_area_asignacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_area_asignacion_cr_usuarios FOREIGN KEY (id_usuario_asignado) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_historial_estado_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_historial_estado WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_historial_estado_cr_usuarios FOREIGN KEY (id_usuario_accion) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_comentario_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_comentario WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_comentario_cr_usuarios FOREIGN KEY (id_usuario) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_notificacion_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_notificacion WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_notificacion_cr_usuarios FOREIGN KEY (id_usuario_destinatario) REFERENCES dbo.cr_usuarios(Id);

        IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name = 'FK_ct_solicitud_tipo_area_part_default_cr_usuarios')
            ALTER TABLE dbo.ct_solicitud_tipo_area_participante_default WITH CHECK
            ADD CONSTRAINT FK_ct_solicitud_tipo_area_part_default_cr_usuarios FOREIGN KEY (id_usuario_default) REFERENCES dbo.cr_usuarios(Id);
    END;
END
ELSE
BEGIN
    PRINT 'Aviso: no existe dbo.cr_usuarios; FKs de usuarios para Solicitudes quedan pendientes.';
END;
GO

PRINT 'Capa Solicitudes CT aplicada correctamente.';
GO
