/*
===========================================================================
 MSP - FASE 4: STORED PROCEDURES DE NEGOCIO
 SQL Server / esquema dbo
 - Script incremental e idempotente
 - Mueve reglas criticas desde PHP a SQL
 - Compatible con modelo nuevo (contrato_local) y legado transitorio
===========================================================================
*/

SET NOCOUNT ON;
GO

/* =========================================================================
   1. SP: CREAR CARGO MANUAL
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_cargo_crear_manual
    @id_tienda INT,
    @cdo_local NVARCHAR(20),
    @id_tipo_cargo_salida INT,
    @fecha_cargo DATE = NULL,
    @periodo_referencia DATE = NULL,
    @servicio_referencia NVARCHAR(30) = NULL,
    @descripcion_cargo NVARCHAR(500),
    @monto_cargo DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @crear_legacy BIT = 1,
    @id_cargo_contrato_local INT OUTPUT,
    @id_cargo_salida_legacy INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @id_tienda IS NULL OR @id_tienda <= 0
        THROW 50601, 'La tienda indicada no es valida.', 1;

    IF @id_tipo_cargo_salida IS NULL OR @id_tipo_cargo_salida <= 0
        THROW 50602, 'El tipo de cargo no es valido.', 1;

    SET @cdo_local = UPPER(LTRIM(RTRIM(ISNULL(@cdo_local, N''))));
    IF @cdo_local = N'' OR LEN(@cdo_local) > 20
        THROW 50603, 'El codigo local no es valido.', 1;

    SET @descripcion_cargo = LTRIM(RTRIM(ISNULL(@descripcion_cargo, N'')));
    IF @descripcion_cargo = N'' OR LEN(@descripcion_cargo) > 500
        THROW 50604, 'La descripcion del cargo es obligatoria y no puede superar 500 caracteres.', 1;

    SET @servicio_referencia = NULLIF(LTRIM(RTRIM(ISNULL(@servicio_referencia, N''))), N'');
    IF @servicio_referencia IS NOT NULL AND LEN(@servicio_referencia) > 30
        THROW 50605, 'El servicio de referencia no puede superar 30 caracteres.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50606, 'Las observaciones no pueden superar 500 caracteres.', 1;

    IF @monto_cargo IS NULL OR @monto_cargo <= 0
        THROW 50607, 'El monto del cargo no es valido.', 1;

    IF @fecha_cargo IS NULL
        SET @fecha_cargo = CONVERT(date, SYSDATETIME());

    IF @periodo_referencia IS NOT NULL AND DAY(@periodo_referencia) <> 1
        THROW 50608, 'El periodo de referencia debe ser el primer dia del mes.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @id_contrato_local INT,
        @requiere_documento BIT,
        @codigo_tipo_cargo NVARCHAR(50),
        @origen_cargo TINYINT,
        @es_estimado BIT;

    BEGIN TRANSACTION;

    SELECT TOP (1)
        @id_contrato_arriendo = c.id_contrato_arriendo
    FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.id_tienda = @id_tienda
      AND c.estado_contrato IN (1,2,3)
    ORDER BY c.id_contrato_arriendo DESC;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 50609, 'La tienda no tiene contrato activo para registrar cargos.', 1;

    SELECT TOP (1)
        @id_local = l.id_local
    FROM dbo.msp_locales l
    WHERE UPPER(LTRIM(RTRIM(l.cdo_local))) = @cdo_local;

    IF ISNULL(@id_local, 0) <= 0
        THROW 50610, 'El local seleccionado no existe.', 1;

    SELECT TOP (1)
        @id_contrato_local = cl.id_contrato_local
    FROM dbo.msp_contrato_locales cl WITH (UPDLOCK, HOLDLOCK)
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND cl.id_local = @id_local
      AND @fecha_cargo >= cl.fecha_inicio
      AND @fecha_cargo <= ISNULL(cl.fecha_termino, CONVERT(date, '9999-12-31'))
    ORDER BY cl.fecha_inicio DESC, cl.id_contrato_local DESC;

    IF ISNULL(@id_contrato_local, 0) <= 0
    BEGIN
        SELECT TOP (1)
            @id_contrato_local = cl.id_contrato_local
        FROM dbo.msp_contrato_locales cl WITH (UPDLOCK, HOLDLOCK)
        WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
          AND cl.id_local = @id_local
        ORDER BY
            CASE WHEN cl.estado_relacion = 1 THEN 0 ELSE 1 END,
            cl.fecha_inicio DESC,
            cl.id_contrato_local DESC;
    END;

    IF ISNULL(@id_contrato_local, 0) <= 0
        THROW 50611, 'No existe una relacion contrato-local valida para registrar el cargo.', 1;

    SELECT
        @codigo_tipo_cargo = t.codigo_tipo_cargo,
        @requiere_documento = t.requiere_documento
    FROM dbo.msp_tipos_cargo_salida t
    WHERE t.id_tipo_cargo_salida = @id_tipo_cargo_salida
      AND t.activo = 1;

    IF @codigo_tipo_cargo IS NULL
        THROW 50612, 'El tipo de cargo seleccionado no esta disponible.', 1;

    IF ISNULL(@requiere_documento, 0) = 1
        THROW 50613, 'Este tipo de cargo requiere documento asociado.', 1;

    SET @es_estimado = 0;

    SET @origen_cargo = CASE
        WHEN @codigo_tipo_cargo IN (N'MULTA', N'DANOS') THEN 3
        ELSE 4
    END;

    INSERT INTO dbo.msp_cargos_contrato_local (
        id_contrato_local,
        id_tipo_cargo_salida,
        fecha_cargo,
        periodo_referencia,
        origen_cargo,
        id_documento_cobro,
        id_pago,
        descripcion_cargo,
        monto_cargo,
        monto_aplicado_garantia,
        monto_pagado_directo,
        estado_cargo,
        es_estimado,
        requiere_regularizacion,
        servicio_referencia,
        observaciones,
        id_cargo_salida_legacy
    )
    VALUES (
        @id_contrato_local,
        @id_tipo_cargo_salida,
        @fecha_cargo,
        @periodo_referencia,
        @origen_cargo,
        NULL,
        NULL,
        @descripcion_cargo,
        @monto_cargo,
        0,
        0,
        1,
        @es_estimado,
        0,
        @servicio_referencia,
        @observaciones,
        NULL
    );

    SET @id_cargo_contrato_local = CONVERT(INT, SCOPE_IDENTITY());
    SET @id_cargo_salida_legacy = NULL;

    IF @crear_legacy = 1 AND OBJECT_ID('dbo.msp_cargos_salida', 'U') IS NOT NULL
    BEGIN
        BEGIN TRY
            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=1;

            INSERT INTO dbo.msp_cargos_salida (
            id_contrato_arriendo,
            id_local,
            id_tipo_cargo_salida,
            fecha_cargo,
            origen_cargo,
            id_documento_cobro,
            periodo_referencia,
            servicio_referencia,
            descripcion_cargo,
            monto_cargo,
            es_estimado,
            estado_cargo,
            observaciones
        )
        VALUES (
            @id_contrato_arriendo,
            @id_local,
            @id_tipo_cargo_salida,
            @fecha_cargo,
            @origen_cargo,
            NULL,
            @periodo_referencia,
            @servicio_referencia,
            @descripcion_cargo,
            @monto_cargo,
            @es_estimado,
            1,
            @observaciones
            );

            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=NULL;
        END TRY
        BEGIN CATCH
            EXEC sys.sp_set_session_context @key=N'msp_skip_cargo_legacy_sync', @value=NULL;
            THROW;
        END CATCH;

        SET @id_cargo_salida_legacy = CONVERT(INT, SCOPE_IDENTITY());

        UPDATE dbo.msp_cargos_contrato_local
        SET id_cargo_salida_legacy = @id_cargo_salida_legacy
        WHERE id_cargo_contrato_local = @id_cargo_contrato_local;
    END;

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   2. SP: OPERAR GARANTIA SOBRE CARGO
   Acciones:
   - RESERVAR
   - LIBERAR_RESERVA
   - APLICAR_DESDE_DISPONIBLE
   - APLICAR_DESDE_RESERVADO
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_operar_cargo
    @accion NVARCHAR(40),
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @epsilon DECIMAL(18,6) = 0.00001;

    SET @accion = UPPER(LTRIM(RTRIM(ISNULL(@accion, N''))));
    IF @accion NOT IN (N'RESERVAR', N'LIBERAR_RESERVA', N'APLICAR_DESDE_DISPONIBLE', N'APLICAR_DESDE_RESERVADO')
        THROW 50701, 'La accion de garantia no es valida.', 1;

    IF ISNULL(@id_cargo_contrato_local, 0) <= 0 AND ISNULL(@id_cargo_salida, 0) <= 0
        THROW 50702, 'Debes indicar un cargo de referencia.', 1;

    IF @monto_movimiento IS NULL OR @monto_movimiento <= 0
        THROW 50703, 'El monto del movimiento no es valido.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50704, 'Las observaciones no pueden superar 500 caracteres.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @monto_cargo_total DECIMAL(18,2),
        @estado_cargo_actual TINYINT,
        @id_cargo_contrato_local_final INT,
        @id_cargo_salida_final INT,
        @saldo_disponible_garantia DECIMAL(18,2),
        @saldo_reservado_garantia DECIMAL(18,2),
        @total_reserva DECIMAL(18,2),
        @total_liberacion DECIMAL(18,2),
        @total_aplicado_disponible DECIMAL(18,2),
        @total_aplicado_reservado DECIMAL(18,2),
        @reserva_neta_cargo DECIMAL(18,2),
        @aplicado_total_cargo DECIMAL(18,2),
        @pendiente_aplicar_cargo DECIMAL(18,2),
        @maximo_permitido DECIMAL(18,2),
        @id_tipo_reserva INT,
        @id_tipo_liberacion INT,
        @id_tipo_aplicacion INT,
        @id_tipo_movimiento INT,
        @fondo_origen CHAR(1),
        @reserva_neta_nueva DECIMAL(18,2),
        @aplicado_total_nuevo DECIMAL(18,2);

    SET @id_movimiento_garantia = NULL;
    SET @estado_cargo_nuevo = NULL;

    BEGIN TRANSACTION;

    IF ISNULL(@id_cargo_contrato_local, 0) > 0
    BEGIN
        SELECT
            @id_cargo_contrato_local_final = ccl.id_cargo_contrato_local,
            @id_cargo_salida_final = ccl.id_cargo_salida_legacy,
            @id_contrato_arriendo = cl.id_contrato_arriendo,
            @id_local = cl.id_local,
            @monto_cargo_total = ccl.monto_cargo,
            @estado_cargo_actual = ccl.estado_cargo
        FROM dbo.msp_cargos_contrato_local ccl WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = ccl.id_contrato_local
        WHERE ccl.id_cargo_contrato_local = @id_cargo_contrato_local;
    END
    ELSE
    BEGIN
        SELECT
            @id_cargo_contrato_local_final = ccl.id_cargo_contrato_local,
            @id_cargo_salida_final = ccl.id_cargo_salida_legacy,
            @id_contrato_arriendo = cl.id_contrato_arriendo,
            @id_local = cl.id_local,
            @monto_cargo_total = ccl.monto_cargo,
            @estado_cargo_actual = ccl.estado_cargo
        FROM dbo.msp_cargos_contrato_local ccl WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN dbo.msp_contrato_locales cl
            ON cl.id_contrato_local = ccl.id_contrato_local
        WHERE ccl.id_cargo_salida_legacy = @id_cargo_salida;

        IF ISNULL(@id_cargo_contrato_local_final, 0) <= 0
        BEGIN
            SELECT
                @id_cargo_salida_final = cs.id_cargo_salida,
                @id_contrato_arriendo = cs.id_contrato_arriendo,
                @id_local = cs.id_local,
                @monto_cargo_total = cs.monto_cargo,
                @estado_cargo_actual = cs.estado_cargo
            FROM dbo.msp_cargos_salida cs WITH (UPDLOCK, HOLDLOCK)
            WHERE cs.id_cargo_salida = @id_cargo_salida;
        END
    END;

    IF ISNULL(@monto_cargo_total, 0) <= 0 OR ISNULL(@id_contrato_arriendo, 0) <= 0 OR ISNULL(@id_local, 0) <= 0
        THROW 50705, 'No fue posible validar el cargo para operar garantia.', 1;

    IF ISNULL(@estado_cargo_actual, 0) NOT IN (1,2,3)
        THROW 50706, 'El estado del cargo no permite movimientos de garantia.', 1;

    IF ISNULL(@id_garantia, 0) <= 0
    BEGIN
        SELECT TOP (1)
            @id_garantia = g.id_garantia
        FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
        WHERE (
            (ISNULL(@id_cargo_contrato_local_final, 0) > 0 AND g.id_contrato_local = (
                SELECT ccl2.id_contrato_local
                FROM dbo.msp_cargos_contrato_local ccl2
                WHERE ccl2.id_cargo_contrato_local = @id_cargo_contrato_local_final
            ))
            OR
            (ISNULL(@id_cargo_contrato_local_final, 0) <= 0 AND g.id_contrato_arriendo = @id_contrato_arriendo AND g.id_local = @id_local)
        )
          AND g.estado_garantia <> 6
        ORDER BY g.id_garantia DESC;
    END
    ELSE
    BEGIN
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
            WHERE g.id_garantia = @id_garantia
              AND g.estado_garantia <> 6
              AND g.id_contrato_arriendo = @id_contrato_arriendo
              AND g.id_local = @id_local
        )
            THROW 50707, 'La garantia indicada no coincide con el cargo seleccionado.', 1;
    END;

    IF ISNULL(@id_garantia, 0) <= 0
        THROW 50708, 'No existe garantia activa para el local del cargo.', 1;

    SELECT
        @saldo_disponible_garantia = gr.saldo_disponible,
        @saldo_reservado_garantia = gr.saldo_reservado
    FROM dbo.msp_vw_garantias_resumen gr
    WHERE gr.id_garantia = @id_garantia;

    IF @saldo_disponible_garantia IS NULL OR @saldo_reservado_garantia IS NULL
        THROW 50709, 'No fue posible leer el saldo de la garantia.', 1;

    SELECT
        @total_reserva = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END),
        @total_liberacion = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END),
        @total_aplicado_disponible = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END),
        @total_aplicado_reservado = SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END)
    FROM dbo.msp_movimientos_garantia mg WITH (UPDLOCK, HOLDLOCK)
    WHERE mg.id_garantia = @id_garantia
      AND (
            (ISNULL(@id_cargo_contrato_local_final, 0) > 0 AND mg.id_cargo_contrato_local = @id_cargo_contrato_local_final)
            OR
            (ISNULL(@id_cargo_salida_final, 0) > 0 AND mg.id_cargo_salida = @id_cargo_salida_final)
          );

    SET @total_reserva = ISNULL(@total_reserva, 0);
    SET @total_liberacion = ISNULL(@total_liberacion, 0);
    SET @total_aplicado_disponible = ISNULL(@total_aplicado_disponible, 0);
    SET @total_aplicado_reservado = ISNULL(@total_aplicado_reservado, 0);

    SET @reserva_neta_cargo = @total_reserva - @total_liberacion - @total_aplicado_reservado;
    SET @aplicado_total_cargo = @total_aplicado_disponible + @total_aplicado_reservado;
    SET @pendiente_aplicar_cargo = CASE WHEN @monto_cargo_total - @aplicado_total_cargo > 0 THEN @monto_cargo_total - @aplicado_total_cargo ELSE 0 END;

    SELECT
        @id_tipo_reserva = MAX(CASE WHEN codigo_movimiento = N'RESERVA' THEN id_tipo_movimiento_garantia END),
        @id_tipo_liberacion = MAX(CASE WHEN codigo_movimiento = N'LIBERACION_RESERVA' THEN id_tipo_movimiento_garantia END),
        @id_tipo_aplicacion = MAX(CASE WHEN codigo_movimiento = N'APLICACION_CARGO' THEN id_tipo_movimiento_garantia END)
    FROM dbo.msp_tipos_movimiento_garantia
    WHERE activo = 1
      AND codigo_movimiento IN (N'RESERVA', N'LIBERACION_RESERVA', N'APLICACION_CARGO');

    IF @id_tipo_reserva IS NULL OR @id_tipo_liberacion IS NULL OR @id_tipo_aplicacion IS NULL
        THROW 50710, 'Catalogo incompleto de tipos de movimiento de garantia.', 1;

    SET @id_tipo_movimiento = NULL;
    SET @fondo_origen = NULL;

    IF @accion = N'RESERVAR'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_disponible_garantia),
                (CASE WHEN @pendiente_aplicar_cargo - CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END > 0
                      THEN @pendiente_aplicar_cargo - CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END
                      ELSE 0 END)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50711, 'No hay saldo disponible para reservar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50712, 'El monto supera el maximo reservable para este cargo.', 1;

        SET @id_tipo_movimiento = @id_tipo_reserva;
    END
    ELSE IF @accion = N'LIBERAR_RESERVA'
    BEGIN
        SET @maximo_permitido = CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END;

        IF @maximo_permitido <= @epsilon
            THROW 50713, 'No hay reserva neta para liberar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50714, 'El monto supera la reserva neta del cargo.', 1;

        SET @id_tipo_movimiento = @id_tipo_liberacion;
    END
    ELSE IF @accion = N'APLICAR_DESDE_DISPONIBLE'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_disponible_garantia),
                (@pendiente_aplicar_cargo)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50715, 'No hay saldo disponible para aplicar a este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50716, 'El monto supera el maximo aplicable desde saldo disponible.', 1;

        SET @id_tipo_movimiento = @id_tipo_aplicacion;
        SET @fondo_origen = 'D';
    END
    ELSE IF @accion = N'APLICAR_DESDE_RESERVADO'
    BEGIN
        SET @maximo_permitido = (
            SELECT MIN(v)
            FROM (VALUES
                (@saldo_reservado_garantia),
                (CASE WHEN @reserva_neta_cargo > 0 THEN @reserva_neta_cargo ELSE 0 END),
                (@pendiente_aplicar_cargo)
            ) AS t(v)
        );

        IF @maximo_permitido <= @epsilon
            THROW 50717, 'No hay reserva disponible para aplicar en este cargo.', 1;

        IF @monto_movimiento - @maximo_permitido > @epsilon
            THROW 50718, 'El monto supera el maximo aplicable desde reserva.', 1;

        SET @id_tipo_movimiento = @id_tipo_aplicacion;
        SET @fondo_origen = 'R';
    END;

    INSERT INTO dbo.msp_movimientos_garantia (
        id_garantia,
        id_tipo_movimiento_garantia,
        fondo_origen,
        monto_movimiento,
        id_cargo_salida,
        id_cargo_contrato_local,
        id_pago,
        observaciones
    )
    VALUES (
        @id_garantia,
        @id_tipo_movimiento,
        @fondo_origen,
        @monto_movimiento,
        @id_cargo_salida_final,
        @id_cargo_contrato_local_final,
        @id_pago,
        @observaciones
    );

    SET @id_movimiento_garantia = CONVERT(INT, SCOPE_IDENTITY());

    SET @reserva_neta_nueva = @reserva_neta_cargo;
    SET @aplicado_total_nuevo = @aplicado_total_cargo;

    IF @accion = N'RESERVAR'
        SET @reserva_neta_nueva = @reserva_neta_nueva + @monto_movimiento;
    ELSE IF @accion = N'LIBERAR_RESERVA'
        SET @reserva_neta_nueva = @reserva_neta_nueva - @monto_movimiento;
    ELSE IF @accion = N'APLICAR_DESDE_DISPONIBLE'
        SET @aplicado_total_nuevo = @aplicado_total_nuevo + @monto_movimiento;
    ELSE IF @accion = N'APLICAR_DESDE_RESERVADO'
    BEGIN
        SET @aplicado_total_nuevo = @aplicado_total_nuevo + @monto_movimiento;
        SET @reserva_neta_nueva = @reserva_neta_nueva - @monto_movimiento;
    END;

    SET @estado_cargo_nuevo = 1;
    IF @aplicado_total_nuevo + @epsilon >= @monto_cargo_total
        SET @estado_cargo_nuevo = 3;
    ELSE IF @reserva_neta_nueva > @epsilon
        SET @estado_cargo_nuevo = 2;

    IF ISNULL(@id_cargo_contrato_local_final, 0) > 0
    BEGIN
        UPDATE dbo.msp_cargos_contrato_local
        SET estado_cargo = @estado_cargo_nuevo,
            monto_aplicado_garantia = CASE WHEN @aplicado_total_nuevo > monto_cargo THEN monto_cargo ELSE @aplicado_total_nuevo END
        WHERE id_cargo_contrato_local = @id_cargo_contrato_local_final;
    END;

    IF ISNULL(@id_cargo_salida_final, 0) > 0 AND OBJECT_ID('dbo.msp_cargos_salida', 'U') IS NOT NULL
    BEGIN
        UPDATE dbo.msp_cargos_salida
        SET estado_cargo = @estado_cargo_nuevo
        WHERE id_cargo_salida = @id_cargo_salida_final;
    END;

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   3. WRAPPERS DE GARANTIA
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_reservar
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    EXEC dbo.msp_garantia_operar_cargo
        @accion = N'RESERVAR',
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = NULL,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_liberar_reserva
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    EXEC dbo.msp_garantia_operar_cargo
        @accion = N'LIBERAR_RESERVA',
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = NULL,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_aplicar
    @origen_fondo CHAR(1),
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    DECLARE @accion NVARCHAR(40);

    SET @origen_fondo = UPPER(ISNULL(@origen_fondo, ''));
    IF @origen_fondo = 'D'
        SET @accion = N'APLICAR_DESDE_DISPONIBLE';
    ELSE IF @origen_fondo = 'R'
        SET @accion = N'APLICAR_DESDE_RESERVADO';
    ELSE
        THROW 50719, 'El origen de fondo para aplicar debe ser D o R.', 1;

    EXEC dbo.msp_garantia_operar_cargo
        @accion = @accion,
        @id_cargo_contrato_local = @id_cargo_contrato_local,
        @id_cargo_salida = @id_cargo_salida,
        @id_garantia = @id_garantia,
        @monto_movimiento = @monto_movimiento,
        @observaciones = @observaciones,
        @id_pago = @id_pago,
        @id_movimiento_garantia = @id_movimiento_garantia OUTPUT,
        @estado_cargo_nuevo = @estado_cargo_nuevo OUTPUT;
END;
GO

/* =========================================================================
   4. SP: DEVOLVER GARANTIA
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_garantia_devolver
    @id_garantia INT,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @epsilon DECIMAL(18,6) = 0.00001;

    IF ISNULL(@id_garantia, 0) <= 0
        THROW 50801, 'La garantia indicada no es valida.', 1;

    IF @monto_movimiento IS NULL OR @monto_movimiento <= 0
        THROW 50802, 'El monto de devolucion no es valido.', 1;

    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones) > 500
        THROW 50803, 'Las observaciones no pueden superar 500 caracteres.', 1;

    DECLARE
        @id_contrato_arriendo INT,
        @id_local INT,
        @id_contrato_local INT,
        @estado_garantia TINYINT,
        @saldo_disponible DECIMAL(18,2),
        @saldo_reservado DECIMAL(18,2),
        @id_tipo_devolucion INT,
        @pendientes_nuevo INT,
        @pendientes_legacy INT;

    BEGIN TRANSACTION;

    SELECT
        @id_contrato_arriendo = g.id_contrato_arriendo,
        @id_local = g.id_local,
        @id_contrato_local = g.id_contrato_local,
        @estado_garantia = g.estado_garantia
    FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
    WHERE g.id_garantia = @id_garantia;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0 OR ISNULL(@id_local, 0) <= 0
        THROW 50804, 'La garantia ya no existe.', 1;

    IF @estado_garantia = 6
        THROW 50805, 'La garantia esta anulada y no permite devoluciones.', 1;

    SELECT
        @saldo_disponible = gr.saldo_disponible,
        @saldo_reservado = gr.saldo_reservado
    FROM dbo.msp_vw_garantias_resumen gr
    WHERE gr.id_garantia = @id_garantia;

    IF @saldo_disponible IS NULL OR @saldo_reservado IS NULL
        THROW 50806, 'No fue posible leer el saldo de la garantia.', 1;

    IF @saldo_reservado > @epsilon
        THROW 50807, 'No se puede devolver mientras exista saldo reservado en la garantia.', 1;

    IF @saldo_disponible <= @epsilon
        THROW 50808, 'La garantia no tiene saldo disponible para devolver.', 1;

    IF @monto_movimiento - @saldo_disponible > @epsilon
        THROW 50809, 'El monto de devolucion supera el saldo disponible de la garantia.', 1;

    SELECT
        @pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    WHERE (
            (@id_contrato_local IS NOT NULL AND ccl.id_contrato_local = @id_contrato_local)
            OR
            (@id_contrato_local IS NULL AND ccl.id_contrato_local IN (
                SELECT cl.id_contrato_local
                FROM dbo.msp_contrato_locales cl
                WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
                  AND cl.id_local = @id_local
            ))
          )
      AND ccl.estado_cargo IN (1,2);

    SET @pendientes_nuevo = ISNULL(@pendientes_nuevo, 0);

    SELECT
        @pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.id_local = @id_local
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SET @pendientes_legacy = ISNULL(@pendientes_legacy, 0);

    IF @pendientes_nuevo + @pendientes_legacy > 0
        THROW 50810, 'No se puede devolver garantia: el local tiene cargos pendientes o reservados.', 1;

    SELECT TOP (1)
        @id_tipo_devolucion = t.id_tipo_movimiento_garantia
    FROM dbo.msp_tipos_movimiento_garantia t
    WHERE t.activo = 1
      AND t.codigo_movimiento = N'DEVOLUCION';

    IF ISNULL(@id_tipo_devolucion, 0) <= 0
        THROW 50811, 'No existe el tipo de movimiento DEVOLUCION en catalogo.', 1;

    INSERT INTO dbo.msp_movimientos_garantia (
        id_garantia,
        id_tipo_movimiento_garantia,
        fondo_origen,
        monto_movimiento,
        id_cargo_salida,
        id_cargo_contrato_local,
        id_documento_cobro,
        id_pago,
        observaciones
    )
    VALUES (
        @id_garantia,
        @id_tipo_devolucion,
        NULL,
        @monto_movimiento,
        NULL,
        NULL,
        NULL,
        @id_pago,
        @observaciones
    );

    SET @id_movimiento_garantia = CONVERT(INT, SCOPE_IDENTITY());

    COMMIT TRANSACTION;
END;
GO

/* =========================================================================
   5. SP: PREPARAR CIERRE FINANCIERO (CHECKS)
   Regla:
   - Solo aplica para contratos en estado 3 (En cierre financiero).
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_contrato_preparar_cierre
    @id_contrato_arriendo INT
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 50901, 'El contrato indicado no es valido.', 1;

    DECLARE
        @existe_contrato BIT,
        @estado_contrato TINYINT,
        @cargos_pendientes_nuevo INT,
        @cargos_pendientes_legacy INT,
        @garantias_reservadas INT,
        @puede_cerrar BIT;

    SELECT
        @existe_contrato = 1,
        @estado_contrato = c.estado_contrato
    FROM dbo.msp_contratos_arriendo c
    WHERE c.id_contrato_arriendo = @id_contrato_arriendo;

    IF ISNULL(@existe_contrato, 0) = 0
        THROW 50902, 'El contrato ya no existe.', 1;

    SELECT
        @cargos_pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_local = ccl.id_contrato_local
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND ccl.estado_cargo IN (1,2);

    SELECT
        @cargos_pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SELECT
        @garantias_reservadas = COUNT(1)
    FROM dbo.msp_vw_garantias_resumen gr
    INNER JOIN dbo.msp_garantias g
        ON g.id_garantia = gr.id_garantia
    WHERE g.id_contrato_arriendo = @id_contrato_arriendo
      AND g.estado_garantia <> 6
      AND gr.saldo_reservado > 0;

    SET @cargos_pendientes_nuevo = ISNULL(@cargos_pendientes_nuevo, 0);
    SET @cargos_pendientes_legacy = ISNULL(@cargos_pendientes_legacy, 0);
    SET @garantias_reservadas = ISNULL(@garantias_reservadas, 0);

    SET @puede_cerrar = CASE
        WHEN @estado_contrato <> 3 THEN 0
        WHEN @cargos_pendientes_nuevo + @cargos_pendientes_legacy > 0 THEN 0
        WHEN @garantias_reservadas > 0 THEN 0
        ELSE 1
    END;

    SELECT
        @id_contrato_arriendo AS id_contrato_arriendo,
        @estado_contrato AS estado_contrato,
        @cargos_pendientes_nuevo AS cargos_pendientes_nuevo,
        @cargos_pendientes_legacy AS cargos_pendientes_legacy,
        @garantias_reservadas AS garantias_reservadas,
        @puede_cerrar AS puede_cerrar;
END;
GO

/* =========================================================================
   6. SP: CERRAR CONTRATO (CIERRE FINANCIERO)
   Regla:
   - Cierra a estado 4 solo desde estado 3.
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.msp_contrato_cerrar
    @id_contrato_arriendo INT,
    @id_usuario INT,
    @motivo_cierre NVARCHAR(500),
    @forzar_cierre BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@id_contrato_arriendo, 0) <= 0
        THROW 51001, 'El contrato indicado no es valido.', 1;

    IF ISNULL(@id_usuario, 0) <= 0
        THROW 51002, 'No fue posible identificar al usuario para registrar bitacora.', 1;

    SET @motivo_cierre = LTRIM(RTRIM(ISNULL(@motivo_cierre, N'')));
    IF @motivo_cierre = N''
        THROW 51003, 'Debes indicar un motivo para cerrar el contrato.', 1;

    IF LEN(@motivo_cierre) > 500
        THROW 51004, 'El motivo de cierre no puede superar 500 caracteres.', 1;

    DECLARE
        @estado_contrato_anterior TINYINT,
        @cargos_pendientes_nuevo INT,
        @cargos_pendientes_legacy INT,
        @garantias_reservadas INT,
        @puede_cerrar BIT,
        @detalle_evento NVARCHAR(MAX);

    BEGIN TRANSACTION;

    SELECT
        @estado_contrato_anterior = c.estado_contrato
    FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.id_contrato_arriendo = @id_contrato_arriendo;

    IF @estado_contrato_anterior IS NULL
        THROW 51005, 'El contrato ya no existe.', 1;

    IF @estado_contrato_anterior = 4
        THROW 51006, 'El contrato ya esta cerrado.', 1;

    IF @estado_contrato_anterior = 5
        THROW 51007, 'El contrato esta anulado y no se puede cerrar.', 1;

    IF @estado_contrato_anterior <> 3
        THROW 51008, 'El contrato debe estar en estado 3 (En cierre financiero) para cerrar.', 1;

    SELECT
        @cargos_pendientes_nuevo = COUNT(1)
    FROM dbo.msp_cargos_contrato_local ccl
    INNER JOIN dbo.msp_contrato_locales cl
        ON cl.id_contrato_local = ccl.id_contrato_local
    WHERE cl.id_contrato_arriendo = @id_contrato_arriendo
      AND ccl.estado_cargo IN (1,2);

    SELECT
        @cargos_pendientes_legacy = COUNT(1)
    FROM dbo.msp_cargos_salida cs
    WHERE cs.id_contrato_arriendo = @id_contrato_arriendo
      AND cs.estado_cargo IN (1,2)
      AND NOT EXISTS (
            SELECT 1
            FROM dbo.msp_cargos_contrato_local cclx
            WHERE cclx.id_cargo_salida_legacy = cs.id_cargo_salida
      );

    SELECT
        @garantias_reservadas = COUNT(1)
    FROM dbo.msp_vw_garantias_resumen gr
    INNER JOIN dbo.msp_garantias g
        ON g.id_garantia = gr.id_garantia
    WHERE g.id_contrato_arriendo = @id_contrato_arriendo
      AND g.estado_garantia <> 6
      AND gr.saldo_reservado > 0;

    SET @cargos_pendientes_nuevo = ISNULL(@cargos_pendientes_nuevo, 0);
    SET @cargos_pendientes_legacy = ISNULL(@cargos_pendientes_legacy, 0);
    SET @garantias_reservadas = ISNULL(@garantias_reservadas, 0);

    SET @puede_cerrar = CASE
        WHEN @cargos_pendientes_nuevo + @cargos_pendientes_legacy > 0 THEN 0
        WHEN @garantias_reservadas > 0 THEN 0
        ELSE 1
    END;

    IF @forzar_cierre = 0 AND @puede_cerrar = 0
        THROW 51009, 'No se puede cerrar el contrato: existen cargos pendientes/reservados o garantia reservada.', 1;

    UPDATE dbo.msp_contratos_arriendo
    SET estado_contrato = 4
    WHERE id_contrato_arriendo = @id_contrato_arriendo
      AND estado_contrato = 3;

    IF @@ROWCOUNT <= 0
        THROW 51010, 'No fue posible cerrar el contrato. Intenta nuevamente.', 1;

    INSERT INTO dbo.msp_bitacora_cierre_contrato (
        id_contrato_arriendo,
        id_usuario,
        estado_contrato_anterior,
        estado_contrato_nuevo,
        motivo_cierre
    )
    VALUES (
        @id_contrato_arriendo,
        @id_usuario,
        @estado_contrato_anterior,
        4,
        @motivo_cierre
    );

    IF OBJECT_ID('dbo.msp_historial_contrato', 'U') IS NOT NULL
    BEGIN
        SET @detalle_evento = (
            SELECT
                N'sp' AS origen,
                @estado_contrato_anterior AS estado_anterior,
                4 AS estado_nuevo,
                @forzar_cierre AS forzado,
                @cargos_pendientes_nuevo AS cargos_pendientes_nuevo,
                @cargos_pendientes_legacy AS cargos_pendientes_legacy,
                @garantias_reservadas AS garantias_reservadas
            FOR JSON PATH, WITHOUT_ARRAY_WRAPPER
        );

        INSERT INTO dbo.msp_historial_contrato (
            id_contrato_arriendo,
            tipo_evento,
            id_usuario,
            detalle_evento,
            motivo_evento
        )
        VALUES (
            @id_contrato_arriendo,
            N'CIERRE',
            @id_usuario,
            @detalle_evento,
            @motivo_cierre
        );
    END;

    COMMIT TRANSACTION;
END;
GO

PRINT 'Fase 4 aplicada: SPs de negocio creados/actualizados.';
GO
