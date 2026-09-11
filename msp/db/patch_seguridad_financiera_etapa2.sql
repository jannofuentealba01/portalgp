SET NOCOUNT ON;
SET XACT_ABORT ON;
SET QUOTED_IDENTIFIER ON;
GO

DECLARE @id_permiso_tesoreria INT;

SELECT @id_permiso_tesoreria = id
FROM dbo.cr_permisos
WHERE nombre_permiso = N'MSP Tesoreria';

IF @id_permiso_tesoreria IS NULL
BEGIN
    INSERT dbo.cr_permisos(nombre_permiso, descripcion)
    VALUES(N'MSP Tesoreria', N'Consulta y operación de caja, bancos, conciliaciones y reaperturas de MSP.');
    SET @id_permiso_tesoreria = CONVERT(INT, SCOPE_IDENTITY());
END;

;WITH permisos_origen AS (
    SELECT rp.rol_id,
           MAX(CONVERT(INT, rp.lectura)) AS lectura,
           MAX(CONVERT(INT, rp.escritura)) AS escritura,
           MAX(CONVERT(INT, rp.eliminacion)) AS eliminacion
    FROM dbo.cr_rol_permisos rp
    INNER JOIN dbo.cr_permisos p ON p.id = rp.permiso_id
    WHERE p.nombre_permiso = N'MSP Operacion'
    GROUP BY rp.rol_id
)
INSERT dbo.cr_rol_permisos(rol_id, permiso_id, lectura, escritura, eliminacion)
SELECT po.rol_id, @id_permiso_tesoreria, po.lectura, po.escritura, po.eliminacion
FROM permisos_origen po
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.cr_rol_permisos actual
    WHERE actual.rol_id = po.rol_id
      AND actual.permiso_id = @id_permiso_tesoreria
);
GO

IF OBJECT_ID(N'dbo.msp_tesoreria_solicitudes_reapertura_caja', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_tesoreria_solicitudes_reapertura_caja(
        id_solicitud_reapertura BIGINT IDENTITY(1,1) NOT NULL,
        id_cierre_caja INT NOT NULL,
        motivo NVARCHAR(1000) NOT NULL,
        estado_solicitud NVARCHAR(20) NOT NULL CONSTRAINT DF_msp_tes_sol_reap_estado DEFAULT(N'PENDIENTE'),
        id_usuario_solicita INT NOT NULL,
        fecha_solicitud DATETIME2(0) NOT NULL CONSTRAINT DF_msp_tes_sol_reap_fecha DEFAULT(SYSDATETIME()),
        id_usuario_resuelve INT NULL,
        fecha_resolucion DATETIME2(0) NULL,
        observacion_resolucion NVARCHAR(1000) NULL,
        CONSTRAINT PK_msp_tes_sol_reap PRIMARY KEY(id_solicitud_reapertura),
        CONSTRAINT FK_msp_tes_sol_reap_cierre FOREIGN KEY(id_cierre_caja) REFERENCES dbo.msp_tesoreria_cierres_caja(id_cierre_caja),
        CONSTRAINT FK_msp_tes_sol_reap_solicita FOREIGN KEY(id_usuario_solicita) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT FK_msp_tes_sol_reap_resuelve FOREIGN KEY(id_usuario_resuelve) REFERENCES dbo.cr_usuarios(id),
        CONSTRAINT CK_msp_tes_sol_reap_motivo CHECK(LEN(LTRIM(RTRIM(motivo))) >= 10),
        CONSTRAINT CK_msp_tes_sol_reap_estado CHECK(estado_solicitud IN(N'PENDIENTE', N'APROBADA', N'RECHAZADA')),
        CONSTRAINT CK_msp_tes_sol_reap_resolucion CHECK(
            (estado_solicitud = N'PENDIENTE' AND id_usuario_resuelve IS NULL AND fecha_resolucion IS NULL)
            OR
            (estado_solicitud IN(N'APROBADA', N'RECHAZADA') AND id_usuario_resuelve IS NOT NULL AND fecha_resolucion IS NOT NULL)
        )
    );

    CREATE UNIQUE INDEX UX_msp_tes_sol_reap_pendiente
        ON dbo.msp_tesoreria_solicitudes_reapertura_caja(id_cierre_caja)
        WHERE estado_solicitud = N'PENDIENTE';

    CREATE INDEX IX_msp_tes_sol_reap_estado_fecha
        ON dbo.msp_tesoreria_solicitudes_reapertura_caja(estado_solicitud, fecha_solicitud DESC);
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_solicitar_reapertura_caja
    @id_cierre_caja INT,
    @motivo NVARCHAR(1000),
    @id_usuario_solicita INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @motivo = NULLIF(LTRIM(RTRIM(ISNULL(@motivo, N''))), N'');
    IF ISNULL(@id_cierre_caja, 0) <= 0 OR @motivo IS NULL OR LEN(@motivo) < 10
        THROW 53401, N'La solicitud requiere un motivo de al menos 10 caracteres.', 1;

    IF NOT EXISTS(
        SELECT 1
        FROM dbo.cr_usuarios u
        INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id = u.rol_id
        INNER JOIN dbo.cr_permisos p ON p.id = rp.permiso_id
        WHERE u.id = @id_usuario_solicita
          AND u.estado_id = 1
          AND p.nombre_permiso = N'MSP Tesoreria'
          AND rp.lectura = 1
          AND rp.escritura = 1
    )
        THROW 53402, N'El usuario no está habilitado para solicitar reaperturas de caja.', 1;

    BEGIN TRANSACTION;
    BEGIN TRY
        DECLARE @estado NVARCHAR(25), @id_solicitud BIGINT;

        SELECT @estado = estado_cierre
        FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK, HOLDLOCK)
        WHERE id_cierre_caja = @id_cierre_caja;

        IF @estado IS NULL
            THROW 53403, N'El cierre de caja no existe.', 1;
        IF @estado NOT IN(N'CUADRADO', N'CON_DIFERENCIA')
            THROW 53404, N'El cierre ya está reabierto o no admite reapertura.', 1;
        IF EXISTS(
            SELECT 1
            FROM dbo.msp_tesoreria_solicitudes_reapertura_caja WITH(UPDLOCK, HOLDLOCK)
            WHERE id_cierre_caja = @id_cierre_caja
              AND estado_solicitud = N'PENDIENTE'
        )
            THROW 53405, N'Ya existe una solicitud pendiente para este cierre.', 1;

        INSERT dbo.msp_tesoreria_solicitudes_reapertura_caja(id_cierre_caja, motivo, id_usuario_solicita)
        VALUES(@id_cierre_caja, @motivo, @id_usuario_solicita);

        SET @id_solicitud = CONVERT(BIGINT, SCOPE_IDENTITY());
        COMMIT TRANSACTION;

        SELECT @id_solicitud AS id_solicitud_reapertura, N'PENDIENTE' AS estado_solicitud;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_resolver_reapertura_caja
    @id_solicitud_reapertura BIGINT,
    @decision NVARCHAR(20),
    @observacion NVARCHAR(1000) = NULL,
    @id_usuario_resuelve INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @decision = UPPER(LTRIM(RTRIM(ISNULL(@decision, N''))));
    SET @observacion = NULLIF(LTRIM(RTRIM(ISNULL(@observacion, N''))), N'');

    IF ISNULL(@id_solicitud_reapertura, 0) <= 0 OR @decision NOT IN(N'APROBAR', N'RECHAZAR')
        THROW 53411, N'La solicitud o decisión no es válida.', 1;
    IF @decision = N'RECHAZAR' AND LEN(ISNULL(@observacion, N'')) < 5
        THROW 53412, N'Indica el motivo del rechazo con al menos 5 caracteres.', 1;

    IF NOT EXISTS(
        SELECT 1
        FROM dbo.cr_usuarios u
        INNER JOIN dbo.cr_rol_permisos rp ON rp.rol_id = u.rol_id
        INNER JOIN dbo.cr_permisos p ON p.id = rp.permiso_id
        WHERE u.id = @id_usuario_resuelve
          AND u.estado_id = 1
          AND p.nombre_permiso = N'MSP Tesoreria'
          AND rp.lectura = 1
          AND rp.escritura = 1
          AND rp.eliminacion = 1
    )
        THROW 53413, N'El usuario no está habilitado para resolver reaperturas de caja.', 1;

    BEGIN TRANSACTION;
    BEGIN TRY
        DECLARE @id_cierre INT,
                @id_solicitante INT,
                @estado_solicitud NVARCHAR(20),
                @motivo NVARCHAR(1000),
                @cuenta INT,
                @fecha DATE,
                @estado_cierre NVARCHAR(25);

        SELECT @id_cierre = id_cierre_caja,
               @id_solicitante = id_usuario_solicita,
               @estado_solicitud = estado_solicitud,
               @motivo = motivo
        FROM dbo.msp_tesoreria_solicitudes_reapertura_caja WITH(UPDLOCK, HOLDLOCK)
        WHERE id_solicitud_reapertura = @id_solicitud_reapertura;

        IF @id_cierre IS NULL
            THROW 53414, N'La solicitud de reapertura no existe.', 1;
        IF @estado_solicitud <> N'PENDIENTE'
            THROW 53415, N'La solicitud ya fue resuelta.', 1;
        IF @id_solicitante = @id_usuario_resuelve
            THROW 53416, N'El solicitante no puede aprobar ni rechazar su propia solicitud.', 1;

        IF @decision = N'RECHAZAR'
        BEGIN
            UPDATE dbo.msp_tesoreria_solicitudes_reapertura_caja
            SET estado_solicitud = N'RECHAZADA',
                id_usuario_resuelve = @id_usuario_resuelve,
                fecha_resolucion = SYSDATETIME(),
                observacion_resolucion = @observacion
            WHERE id_solicitud_reapertura = @id_solicitud_reapertura;

            COMMIT TRANSACTION;
            SELECT @id_solicitud_reapertura AS id_solicitud_reapertura, N'RECHAZADA' AS estado_solicitud;
            RETURN;
        END;

        SELECT @cuenta = id_cuenta_tesoreria,
               @fecha = fecha_cierre,
               @estado_cierre = estado_cierre
        FROM dbo.msp_tesoreria_cierres_caja WITH(UPDLOCK, HOLDLOCK)
        WHERE id_cierre_caja = @id_cierre;

        IF @estado_cierre NOT IN(N'CUADRADO', N'CON_DIFERENCIA')
            THROW 53417, N'El cierre ya está reabierto o no admite reapertura.', 1;

        UPDATE dbo.msp_tesoreria_cierres_caja
        SET estado_cierre = N'REABIERTA',
            observaciones = CONCAT(ISNULL(observaciones, N''), N' | Reapertura autorizada: ', @motivo)
        WHERE id_cierre_caja = @id_cierre;

        INSERT dbo.msp_tesoreria_bitacora_reapertura_caja(
            id_cierre_caja, id_cuenta_tesoreria, fecha_cierre, estado_anterior,
            motivo, id_usuario_solicita, id_usuario_autoriza
        )
        VALUES(
            @id_cierre, @cuenta, @fecha, @estado_cierre,
            @motivo, @id_solicitante, @id_usuario_resuelve
        );

        UPDATE dbo.msp_tesoreria_solicitudes_reapertura_caja
        SET estado_solicitud = N'APROBADA',
            id_usuario_resuelve = @id_usuario_resuelve,
            fecha_resolucion = SYSDATETIME(),
            observacion_resolucion = @observacion
        WHERE id_solicitud_reapertura = @id_solicitud_reapertura;

        COMMIT TRANSACTION;
        SELECT @id_solicitud_reapertura AS id_solicitud_reapertura,
               @id_cierre AS id_cierre_caja,
               N'APROBADA' AS estado_solicitud,
               N'REABIERTA' AS estado_cierre;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_tesoreria_reabrir_caja
    @id_cierre_caja INT,
    @motivo NVARCHAR(1000),
    @id_usuario_solicita INT,
    @id_usuario_autoriza INT
AS
BEGIN
    SET NOCOUNT ON;
    THROW 53420, N'La reapertura directa está deshabilitada. Debe usar el flujo de solicitud y resolución con sesiones separadas.', 1;
END;
GO

PRINT N'Seguridad financiera etapa 2 instalada: permiso de Tesorería y reapertura con doble control.';
GO
