/*
===========================================================================
 MSP - SEGURIDAD FINANCIERA ETAPA 4: ELIMINACION, REGENERACION Y REAPERTURA

 - El borrado fisico de cierres y documentos queda reservado a procedimientos
   controlados.
 - Antes de reemplazar un documento se conserva una version inmutable y se
   revierte su asiento contable.
 - Un documento con pagos, aplicaciones, garantias, cargos, envios o respaldos
   no se puede reemplazar: debe corregirse mediante anulaciones/ajustes.
 - Cada retorno mensual a Borrador registra una fotografia de dependencias.
===========================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_documentos_cobro_versiones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_documentos_cobro_versiones (
        id_documento_cobro_version BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_msp_documentos_cobro_versiones PRIMARY KEY,
        id_documento_cobro_original INT NOT NULL,
        uuid_documento UNIQUEIDENTIFIER NULL,
        id_contrato_arriendo INT NULL,
        id_tienda INT NOT NULL,
        periodo_facturacion DATE NOT NULL,
        numero_documento NVARCHAR(50) NULL,
        estado_documento_original TINYINT NOT NULL,
        monto_total_original DECIMAL(18,2) NOT NULL,
        saldo_pendiente_original DECIMAL(18,2) NOT NULL,
        documento_json NVARCHAR(MAX) NOT NULL,
        detalle_json NVARCHAR(MAX) NULL,
        motivo_version NVARCHAR(500) NOT NULL,
        id_usuario INT NULL,
        fecha_version DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_documentos_versiones_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT UQ_msp_documentos_versiones_original UNIQUE(id_documento_cobro_original),
        CONSTRAINT CK_msp_documentos_versiones_documento_json CHECK(ISJSON(documento_json)=1),
        CONSTRAINT CK_msp_documentos_versiones_detalle_json CHECK(detalle_json IS NULL OR ISJSON(detalle_json)=1)
    );

    CREATE INDEX IX_msp_documentos_versiones_contrato_periodo
        ON dbo.msp_documentos_cobro_versiones(id_contrato_arriendo,periodo_facturacion,fecha_version DESC);
END;
GO

IF COL_LENGTH(N'dbo.msp_documentos_cobro_eventos', N'id_documento_cobro_historico') IS NULL
BEGIN
    ALTER TABLE dbo.msp_documentos_cobro_eventos
    ADD id_documento_cobro_historico INT NULL;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_documentos_cobro_delete_guard
ON dbo.msp_documentos_cobro
INSTEAD OF DELETE
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(TRY_CONVERT(INT, SESSION_CONTEXT(N'msp_documento_delete_controlado')),0) <> 1
        THROW 53501,N'Los documentos de cobro no admiten borrado directo. Usa la regeneracion o anulacion controlada.',1;

    DELETE dc
    FROM dbo.msp_documentos_cobro dc
    INNER JOIN deleted d ON d.id_documento_cobro=dc.id_documento_cobro;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_documento_preparar_regeneracion
    @id_documento_cobro INT,
    @motivo NVARCHAR(500),
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @motivo=NULLIF(LTRIM(RTRIM(@motivo)),N'');
    DECLARE @fecha_reversa DATE=CONVERT(date,SYSDATETIME());

    IF @id_documento_cobro IS NULL OR @id_documento_cobro<=0
        THROW 53502,N'Debes indicar un documento valido para regenerar.',1;
    IF @motivo IS NULL
        THROW 53503,N'Debes indicar el motivo de la regeneracion.',1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (
            SELECT 1 FROM dbo.msp_documentos_cobro WITH(UPDLOCK,HOLDLOCK)
            WHERE id_documento_cobro=@id_documento_cobro
        )
            THROW 53504,N'El documento que se intenta regenerar ya no existe.',1;

        IF EXISTS (SELECT 1 FROM dbo.msp_pagos WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53505,N'El documento tiene historial de pagos y no puede reemplazarse. Usa anulacion o ajuste financiero.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_saldo_favor_periodo_aplicaciones WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53506,N'El documento tiene aplicaciones de saldo a favor y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_garantia_documento_aplicaciones WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53507,N'El documento tiene aplicaciones de garantia y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_movimientos_garantia WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53508,N'El documento tiene movimientos de garantia y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_cargos_salida WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53509,N'El documento tiene cargos de salida asociados y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_cargos_contrato_local WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53510,N'El documento tiene cargos contractuales asociados y no puede reemplazarse.',1;
        IF EXISTS (
            SELECT 1 FROM dbo.msp_cargos_auto_generados
            WHERE id_documento_cobro=@id_documento_cobro OR id_documento_origen_deuda=@id_documento_cobro
        )
            THROW 53511,N'El documento participa en cargos automaticos y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_envio_lote_documentos WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53512,N'El documento tiene historial de envio y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53513,N'El documento pertenece a una operacion de pago por contrato y no puede reemplazarse.',1;
        IF EXISTS (SELECT 1 FROM dbo.msp_pago_contrato_archivos WHERE id_documento_cobro=@id_documento_cobro)
            THROW 53514,N'El documento tiene respaldos PDF y no puede reemplazarse.',1;
        IF EXISTS (
            SELECT 1 FROM dbo.msp_documentos_cobro_eventos
            WHERE id_documento_cobro=@id_documento_cobro
              AND UPPER(tipo_evento)<>N'EMISION'
        )
            THROW 53515,N'El documento tiene eventos operativos y no puede reemplazarse.',1;

        INSERT dbo.msp_documentos_cobro_versiones (
            id_documento_cobro_original,uuid_documento,id_contrato_arriendo,id_tienda,
            periodo_facturacion,numero_documento,estado_documento_original,
            monto_total_original,saldo_pendiente_original,documento_json,detalle_json,
            motivo_version,id_usuario
        )
        SELECT
            dc.id_documento_cobro,dc.uuid_documento,dc.id_contrato_arriendo,dc.id_tienda,
            dc.periodo_facturacion,dc.numero_documento,dc.estado_documento,
            dc.monto_total,dc.saldo_pendiente,
            (SELECT snapshot_dc.* FOR JSON PATH,WITHOUT_ARRAY_WRAPPER),
            NULLIF((
                SELECT dcd.*
                FROM dbo.msp_documentos_cobro_detalle dcd
                WHERE dcd.id_documento_cobro=dc.id_documento_cobro
                ORDER BY dcd.orden_item,dcd.id_detalle_documento
                FOR JSON PATH
            ),N'[]'),
            @motivo,@id_usuario
        FROM dbo.msp_documentos_cobro dc
        CROSS APPLY (
            SELECT dc.id_documento_cobro,dc.uuid_documento,dc.id_tienda,dc.id_contrato_arriendo,
                   dc.periodo_facturacion,dc.numero_documento,dc.fecha_emision,dc.fecha_vencimiento,
                   dc.rut_arrendatario_snapshot,dc.nombre_arrendatario_snapshot,dc.nombre_tienda_snapshot,
                   dc.subtotal_arriendo,dc.subtotal_servicios,dc.monto_total,dc.saldo_pendiente,
                   dc.estado_documento,dc.observaciones,dc.fecha_registro,dc.id_pool_documento
        ) snapshot_dc
        WHERE dc.id_documento_cobro=@id_documento_cobro;

        IF OBJECT_ID(N'dbo.msp_acc_revertir_origen',N'P') IS NOT NULL
            EXEC dbo.msp_acc_revertir_origen
                @tabla_origen=N'msp_documentos_cobro',
                @id_origen=@id_documento_cobro,
                @fecha_reversa=@fecha_reversa,
                @motivo=N'Regeneracion controlada de documento de cobro';

        UPDATE dbo.msp_documentos_cobro_eventos
        SET id_documento_cobro_historico=@id_documento_cobro,
            id_documento_cobro=NULL
        WHERE id_documento_cobro=@id_documento_cobro;

        UPDATE dbo.msp_pool_documentos_periodo
        SET id_documento_cobro=NULL,
            estado_pool=CASE WHEN estado_pool=4 THEN 4 WHEN ready_luz=1 THEN 2 ELSE 1 END,
            updated_at=SYSDATETIME()
        WHERE id_documento_cobro=@id_documento_cobro;

        DELETE FROM dbo.msp_documentos_cobro_detalle
        WHERE id_documento_cobro=@id_documento_cobro;

        EXEC sys.sp_set_session_context @key=N'msp_documento_delete_controlado',@value=1;
        DELETE FROM dbo.msp_documentos_cobro WHERE id_documento_cobro=@id_documento_cobro;
        EXEC sys.sp_set_session_context @key=N'msp_documento_delete_controlado',@value=NULL;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        EXEC sys.sp_set_session_context @key=N'msp_documento_delete_controlado',@value=NULL;
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

IF COL_LENGTH(N'dbo.msp_cierre_mensual_transiciones',N'es_reapertura') IS NULL
BEGIN
    ALTER TABLE dbo.msp_cierre_mensual_transiciones
    ADD es_reapertura BIT NOT NULL
        CONSTRAINT DF_msp_cierre_transicion_reapertura DEFAULT(0);
END;
GO

IF COL_LENGTH(N'dbo.msp_cierre_mensual_transiciones',N'dependencias_json') IS NULL
BEGIN
    ALTER TABLE dbo.msp_cierre_mensual_transiciones
    ADD dependencias_json NVARCHAR(MAX) NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE name=N'CK_msp_cierre_transicion_dependencias_json'
      AND parent_object_id=OBJECT_ID(N'dbo.msp_cierre_mensual_transiciones')
)
BEGIN
    ALTER TABLE dbo.msp_cierre_mensual_transiciones WITH CHECK
    ADD CONSTRAINT CK_msp_cierre_transicion_dependencias_json
        CHECK(dependencias_json IS NULL OR ISJSON(dependencias_json)=1);
END;
GO

IF OBJECT_ID(N'dbo.msp_cierre_mensual_eliminaciones',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_cierre_mensual_eliminaciones (
        id_eliminacion BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_msp_cierre_mensual_eliminaciones PRIMARY KEY,
        id_cierre_mensual_original INT NOT NULL,
        periodo_facturacion DATE NOT NULL,
        fecha_valor_uf DATE NOT NULL,
        valor_uf DECIMAL(18,4) NOT NULL,
        observaciones NVARCHAR(1000) NULL,
        motivo_eliminacion NVARCHAR(500) NOT NULL,
        id_usuario INT NULL,
        fecha_eliminacion DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_cierre_eliminacion_fecha DEFAULT(SYSDATETIME())
    );
    CREATE INDEX IX_msp_cierre_eliminaciones_periodo
        ON dbo.msp_cierre_mensual_eliminaciones(periodo_facturacion,fecha_eliminacion DESC);
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cierre_mensual_delete_guard
ON dbo.msp_cierre_mensual
INSTEAD OF DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF ISNULL(TRY_CONVERT(INT,SESSION_CONTEXT(N'msp_cierre_delete_controlado')),0)<>1
        THROW 53520,N'Los cierres mensuales no admiten borrado directo. Usa la eliminacion controlada de borradores vacios.',1;

    DELETE c
    FROM dbo.msp_cierre_mensual c
    INNER JOIN deleted d ON d.id_cierre_mensual=c.id_cierre_mensual;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_cierre_mensual_eliminar_borrador
    @id_cierre_mensual INT,
    @motivo NVARCHAR(500),
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @motivo=NULLIF(LTRIM(RTRIM(@motivo)),N'');
    IF @motivo IS NULL
        THROW 53521,N'Debes indicar el motivo de la eliminacion.',1;

    BEGIN TRY
        BEGIN TRANSACTION;
        DECLARE @estado TINYINT,@periodo DATE;
        SELECT @estado=estado_cierre,@periodo=periodo_facturacion
        FROM dbo.msp_cierre_mensual WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cierre_mensual=@id_cierre_mensual;

        IF @estado IS NULL THROW 53522,N'El cierre mensual ya no existe.',1;
        IF @estado<>1 THROW 53523,N'Solo se puede eliminar un cierre en Borrador.',1;
        IF EXISTS(SELECT 1 FROM dbo.msp_procesos_cobro_servicio WHERE id_cierre_mensual=@id_cierre_mensual)
            THROW 53524,N'No se puede eliminar: el cierre tiene procesos de cobro asociados.',1;
        IF EXISTS(SELECT 1 FROM dbo.msp_documentos_cobro WHERE periodo_facturacion=@periodo)
            THROW 53525,N'No se puede eliminar: el periodo tiene documentos, incluso historicos.',1;
        IF EXISTS(SELECT 1 FROM dbo.msp_cierre_mensual_transiciones WHERE id_cierre_mensual=@id_cierre_mensual)
            THROW 53526,N'No se puede eliminar: el cierre ya posee historial de estados. Debe conservarse para auditoria.',1;

        INSERT dbo.msp_cierre_mensual_eliminaciones(
            id_cierre_mensual_original,periodo_facturacion,fecha_valor_uf,valor_uf,
            observaciones,motivo_eliminacion,id_usuario
        )
        SELECT id_cierre_mensual,periodo_facturacion,fecha_valor_uf,valor_uf,
               observaciones,@motivo,@id_usuario
        FROM dbo.msp_cierre_mensual
        WHERE id_cierre_mensual=@id_cierre_mensual;

        EXEC sys.sp_set_session_context @key=N'msp_cierre_delete_controlado',@value=1;
        DELETE FROM dbo.msp_cierre_mensual WHERE id_cierre_mensual=@id_cierre_mensual;
        EXEC sys.sp_set_session_context @key=N'msp_cierre_delete_controlado',@value=NULL;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        EXEC sys.sp_set_session_context @key=N'msp_cierre_delete_controlado',@value=NULL;
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_cierre_mensual_transicionar
    @id_cierre_mensual INT,
    @estado_esperado TINYINT,
    @estado_destino TINYINT,
    @motivo NVARCHAR(500)=NULL,
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @motivo=NULLIF(LTRIM(RTRIM(@motivo)),N'');

    BEGIN TRY
        BEGIN TRANSACTION;
        DECLARE @estado_actual TINYINT,@periodo DATE,@docs INT=0,@cobros INT=0;
        DECLARE @dependencias NVARCHAR(MAX)=NULL,@es_reapertura BIT=0;

        SELECT @estado_actual=estado_cierre,@periodo=periodo_facturacion
        FROM dbo.msp_cierre_mensual WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cierre_mensual=@id_cierre_mensual;

        IF @estado_actual IS NULL THROW 53401,N'El periodo de cierre no existe.',1;
        IF @estado_actual<>@estado_esperado THROW 53402,N'El periodo cambio de estado mientras se procesaba la accion. Recarga la vista.',1;
        IF @estado_actual=@estado_destino THROW 53403,N'El periodo ya se encuentra en el estado solicitado.',1;
        IF NOT(
            (@estado_actual=1 AND @estado_destino=2) OR
            (@estado_actual=2 AND @estado_destino IN(1,5)) OR
            (@estado_actual=5 AND @estado_destino IN(1,3)) OR
            (@estado_actual=3 AND @estado_destino=1) OR
            (@estado_actual=4 AND @estado_destino=1)
        ) THROW 53404,N'La transicion solicitada no esta permitida para el estado actual.',1;

        IF @estado_destino=1 AND @estado_actual IN(2,3,4,5)
        BEGIN
            IF @motivo IS NULL THROW 53405,N'Debes indicar el motivo para devolver el periodo a Borrador.',1;
            SET @es_reapertura=1;
            SELECT @dependencias=(
                SELECT
                    (SELECT COUNT(*) FROM dbo.msp_documentos_cobro d WHERE d.periodo_facturacion=@periodo AND d.estado_documento<>5) documentos_activos,
                    (SELECT COUNT(*) FROM dbo.msp_pagos p INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=p.id_documento_cobro WHERE d.periodo_facturacion=@periodo AND p.estado_pago=1) pagos_activos,
                    (SELECT COUNT(*) FROM dbo.msp_pagos p INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=p.id_documento_cobro WHERE d.periodo_facturacion=@periodo) pagos_historicos,
                    (SELECT COUNT(*) FROM dbo.msp_garantia_documento_aplicaciones a INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=a.id_documento_cobro WHERE d.periodo_facturacion=@periodo) aplicaciones_garantia,
                    (SELECT COUNT(*) FROM dbo.msp_saldo_favor_periodo_aplicaciones a WHERE a.periodo_facturacion=@periodo) aplicaciones_saldo_favor,
                    (SELECT COUNT(*) FROM dbo.msp_envio_lote_documentos e INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=e.id_documento_cobro WHERE d.periodo_facturacion=@periodo) documentos_en_lotes,
                    (SELECT COUNT(*) FROM dbo.msp_acc_asientos a INNER JOIN dbo.msp_acc_periodos_contables pc ON pc.id_periodo_contable=a.id_periodo_contable WHERE pc.anio=YEAR(@periodo) AND pc.mes=MONTH(@periodo) AND a.estado_asiento=1) asientos_activos
                FOR JSON PATH,WITHOUT_ARRAY_WRAPPER
            );
        END;

        IF @estado_destino IN(3,5)
        BEGIN
            SELECT @docs=COUNT(*) FROM dbo.msp_documentos_cobro WHERE periodo_facturacion=@periodo AND estado_documento<>5;
            SELECT @cobros=COUNT(*)
            FROM dbo.msp_cobros_servicios cs
            INNER JOIN dbo.msp_lecturas_medidores lm ON lm.id_lectura=cs.id_lectura
            INNER JOIN dbo.msp_procesos_cobro_servicio p ON p.id_proceso_cobro=lm.id_proceso_cobro
            WHERE p.id_cierre_mensual=@id_cierre_mensual;
            IF @docs<=0 AND @cobros<=0
                THROW 53406,N'El periodo no tiene cobros ni documentos validos para revisar o cerrar.',1;
        END;

        UPDATE dbo.msp_cierre_mensual SET estado_cierre=@estado_destino
        WHERE id_cierre_mensual=@id_cierre_mensual AND estado_cierre=@estado_esperado;
        IF @@ROWCOUNT<>1 THROW 53407,N'No fue posible cambiar el estado del periodo.',1;

        INSERT dbo.msp_cierre_mensual_transiciones(
            id_cierre_mensual,estado_origen,estado_destino,motivo,id_usuario,
            es_reapertura,dependencias_json
        )
        VALUES(@id_cierre_mensual,@estado_actual,@estado_destino,@motivo,@id_usuario,@es_reapertura,@dependencias);

        COMMIT TRANSACTION;
        SELECT @id_cierre_mensual id_cierre_mensual,@estado_actual estado_origen,
               @estado_destino estado_destino,@es_reapertura es_reapertura,@dependencias dependencias_json;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH
END;
GO

PRINT N'Seguridad financiera de eliminacion, regeneracion y reapertura instalada.';
GO
