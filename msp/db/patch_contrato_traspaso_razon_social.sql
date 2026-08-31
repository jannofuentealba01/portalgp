/* =========================================================================
   PATCH: TRASPASO DE CONTRATO POR CAMBIO DE RAZON SOCIAL
   - Crea contrato destino en misma tienda
   - Transfiere solo saldo disponible de garantia por local
   - Deja contrato origen en estado 3 (cierre financiero)
   - Bloquea si existen reservas de garantia en origen
   ========================================================================= */

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_contrato_traspasar_razon_social', N'P') IS NOT NULL
    DROP PROCEDURE dbo.msp_contrato_traspasar_razon_social;
GO

CREATE PROCEDURE dbo.msp_contrato_traspasar_razon_social
    @id_contrato_origen INT,
    @id_arrendatario_destino INT,
    @fecha_traspaso DATE,
    @motivo NVARCHAR(500),
    @id_usuario INT,
    @id_contrato_destino INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@id_contrato_origen, 0) <= 0
        THROW 51201, 'El contrato origen no es valido.', 1;

    IF ISNULL(@id_arrendatario_destino, 0) <= 0
        THROW 51202, 'El arrendatario destino no es valido.', 1;

    IF @fecha_traspaso IS NULL
        THROW 51203, 'La fecha de traspaso es obligatoria.', 1;
    IF @fecha_traspaso <> EOMONTH(@fecha_traspaso)
        THROW 51220, 'La fecha de traspaso debe corresponder al fin de mes.', 1;

    SET @motivo = LTRIM(RTRIM(ISNULL(@motivo, N'')));
    IF @motivo = N''
        THROW 51204, 'Debes indicar un motivo de traspaso.', 1;
    IF LEN(@motivo) > 500
        THROW 51205, 'El motivo no puede superar 500 caracteres.', 1;

    IF ISNULL(@id_usuario, 0) <= 0
        THROW 51206, 'No fue posible identificar al usuario del traspaso.', 1;

    IF OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
        THROW 51207, 'No existe tabla de contratos.', 1;
    IF OBJECT_ID(N'dbo.msp_contrato_locales', N'U') IS NULL
        THROW 51208, 'No existe tabla contrato-local.', 1;
    IF OBJECT_ID(N'dbo.msp_garantias', N'U') IS NULL
        THROW 51209, 'No existe tabla de garantias.', 1;
    IF OBJECT_ID(N'dbo.msp_vw_garantias_resumen', N'V') IS NULL
        THROW 51210, 'No existe vista de resumen de garantias.', 1;

    DECLARE
        @id_tienda INT,
        @id_arrendatario_origen INT,
        @fecha_inicio_origen DATE,
        @estado_origen TINYINT,
        @fecha_termino_pactada_origen DATE,
        @dia_cobro TINYINT,
        @monto_arriendo_pactado DECIMAL(18,2),
        @rubro_contrato NVARCHAR(150),
        @observaciones NVARCHAR(1000),
        @estado_nuevo TINYINT = 2,
        @detalle_origen NVARCHAR(MAX),
        @detalle_destino NVARCHAR(MAX);

    SET @id_contrato_destino = NULL;

    BEGIN TRANSACTION;

    SELECT
        @id_tienda = c.id_tienda,
        @id_arrendatario_origen = c.id_arrendatario,
        @fecha_inicio_origen = c.fecha_inicio,
        @estado_origen = c.estado_contrato,
        @fecha_termino_pactada_origen = c.fecha_termino_pactada,
        @dia_cobro = c.dia_cobro,
        @monto_arriendo_pactado = c.monto_arriendo_pactado,
        @rubro_contrato = c.rubro_contrato,
        @observaciones = c.observaciones
    FROM dbo.msp_contratos_arriendo c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.id_contrato_arriendo = @id_contrato_origen;

    IF ISNULL(@id_tienda, 0) <= 0
        THROW 51211, 'El contrato origen no existe.', 1;

    IF @estado_origen NOT IN (1,2)
        THROW 51212, 'Solo se puede traspasar un contrato en estado borrador o vigente.', 1;

    IF @fecha_inicio_origen IS NOT NULL AND @fecha_traspaso < @fecha_inicio_origen
        THROW 51213, 'La fecha de traspaso no puede ser anterior al inicio del contrato origen.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_arrendatarios a
        WHERE a.id_arrendatario = @id_arrendatario_destino
    )
        THROW 51214, 'El arrendatario destino no existe.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.msp_contrato_locales cl
        WHERE cl.id_contrato_arriendo = @id_contrato_origen
          AND cl.estado_relacion = 1
    )
        THROW 51215, 'El contrato origen no tiene locales activos para traspasar.', 1;

    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias g
        INNER JOIN dbo.msp_vw_garantias_resumen gr
            ON gr.id_garantia = g.id_garantia
        WHERE g.id_contrato_arriendo = @id_contrato_origen
          AND g.estado_garantia <> 6
          AND ISNULL(gr.saldo_reservado, 0) > 0
    )
        THROW 51216, 'No se puede traspasar: existen garantias con saldo reservado.', 1;

    UPDATE dbo.msp_contratos_arriendo
    SET estado_contrato = 3,
        fecha_termino_efectiva = @fecha_traspaso
    WHERE id_contrato_arriendo = @id_contrato_origen
      AND estado_contrato IN (1,2);

    IF @@ROWCOUNT <= 0
        THROW 51217, 'No fue posible actualizar el contrato origen a cierre financiero.', 1;

    UPDATE dbo.msp_contrato_locales
    SET estado_relacion = 2,
        fecha_termino = CASE
            WHEN fecha_inicio > @fecha_traspaso THEN fecha_inicio
            ELSE @fecha_traspaso
        END
    WHERE id_contrato_arriendo = @id_contrato_origen
      AND estado_relacion = 1;

    DECLARE @fecha_inicio_destino DATE = DATEADD(DAY, 1, @fecha_traspaso);
    DECLARE @fecha_termino_pactada_destino DATE = NULL;
    IF @fecha_termino_pactada_origen IS NOT NULL AND @fecha_termino_pactada_origen >= @fecha_inicio_destino
        SET @fecha_termino_pactada_destino = @fecha_termino_pactada_origen;

    INSERT INTO dbo.msp_contratos_arriendo (
        id_tienda,
        id_arrendatario,
        fecha_inicio,
        fecha_termino_pactada,
        fecha_termino_efectiva,
        dia_cobro,
        monto_arriendo_pactado,
        rubro_contrato,
        estado_contrato,
        observaciones
    )
    VALUES (
        @id_tienda,
        @id_arrendatario_destino,
        @fecha_inicio_destino,
        @fecha_termino_pactada_destino,
        NULL,
        @dia_cobro,
        @monto_arriendo_pactado,
        @rubro_contrato,
        @estado_nuevo,
        CONCAT(N'Traspasado desde contrato #', @id_contrato_origen, N'. ', ISNULL(@observaciones, N''))
    );

    SET @id_contrato_destino = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.msp_contrato_locales (
        id_contrato_arriendo,
        id_local,
        fecha_inicio,
        fecha_termino,
        orden_visual,
        estado_relacion,
        monto_arriendo_local,
        observaciones
    )
    SELECT
        @id_contrato_destino,
        cl.id_local,
        @fecha_inicio_destino,
        NULL,
        cl.orden_visual,
        1,
        cl.monto_arriendo_local,
        CONCAT(N'Traspasado desde contrato #', @id_contrato_origen)
    FROM dbo.msp_contrato_locales cl
    WHERE cl.id_contrato_arriendo = @id_contrato_origen;

    DECLARE
        @id_garantia_origen INT,
        @id_local INT,
        @saldo_disponible DECIMAL(18,2),
        @id_contrato_local_destino INT,
        @id_garantia_destino INT,
        @id_tipo_ajuste_negativo INT,
        @id_tipo_ajuste_positivo INT;

    SELECT @id_tipo_ajuste_positivo = id_tipo_movimiento_garantia
    FROM dbo.msp_tipos_movimiento_garantia
    WHERE codigo_movimiento = N'AJUSTE_POSITIVO';

    SELECT @id_tipo_ajuste_negativo = id_tipo_movimiento_garantia
    FROM dbo.msp_tipos_movimiento_garantia
    WHERE codigo_movimiento = N'AJUSTE_NEGATIVO';

    IF ISNULL(@id_tipo_ajuste_positivo, 0) <= 0 OR ISNULL(@id_tipo_ajuste_negativo, 0) <= 0
        THROW 51218, 'No existen tipos de movimiento de ajuste para traspaso de garantia.', 1;

    DECLARE cur_gar CURSOR LOCAL FAST_FORWARD FOR
        SELECT
            g.id_garantia,
            g.id_local,
            CAST(ROUND(ISNULL(gr.saldo_disponible, 0), 2) AS DECIMAL(18,2)) AS saldo_disponible
        FROM dbo.msp_garantias g
        INNER JOIN dbo.msp_vw_garantias_resumen gr
            ON gr.id_garantia = g.id_garantia
        WHERE g.id_contrato_arriendo = @id_contrato_origen
          AND g.estado_garantia <> 6
          AND ISNULL(gr.saldo_disponible, 0) > 0;

    OPEN cur_gar;
    FETCH NEXT FROM cur_gar INTO @id_garantia_origen, @id_local, @saldo_disponible;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SELECT TOP (1)
            @id_contrato_local_destino = cld.id_contrato_local
        FROM dbo.msp_contrato_locales cld
        WHERE cld.id_contrato_arriendo = @id_contrato_destino
          AND cld.id_local = @id_local
        ORDER BY cld.id_contrato_local DESC;

        IF ISNULL(@id_contrato_local_destino, 0) <= 0
            THROW 51219, 'No fue posible resolver contrato-local destino para traspaso de garantia.', 1;

        INSERT INTO dbo.msp_garantias (
            id_contrato_arriendo,
            id_local,
            id_contrato_local,
            fecha_constitucion,
            monto_inicial,
            estado_garantia,
            observaciones
        )
        VALUES (
            @id_contrato_destino,
            @id_local,
            @id_contrato_local_destino,
            @fecha_traspaso,
            0,
            1,
            CONCAT(N'Receptor de saldo disponible traspasado desde garantia #', @id_garantia_origen)
        );

        SET @id_garantia_destino = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.msp_movimientos_garantia (
            id_garantia,
            fecha_movimiento,
            id_tipo_movimiento_garantia,
            fondo_origen,
            monto_movimiento,
            observaciones
        )
        VALUES (
            @id_garantia_origen,
            @fecha_traspaso,
            @id_tipo_ajuste_negativo,
            NULL,
            @saldo_disponible,
            CONCAT(N'Traspaso de saldo disponible a contrato #', @id_contrato_destino, N' (garantia destino #', @id_garantia_destino, N')')
        );

        INSERT INTO dbo.msp_movimientos_garantia (
            id_garantia,
            fecha_movimiento,
            id_tipo_movimiento_garantia,
            fondo_origen,
            monto_movimiento,
            observaciones
        )
        VALUES (
            @id_garantia_destino,
            @fecha_traspaso,
            @id_tipo_ajuste_positivo,
            NULL,
            @saldo_disponible,
            CONCAT(N'Traspaso de saldo disponible desde garantia #', @id_garantia_origen, N' (contrato #', @id_contrato_origen, N')')
        );

        FETCH NEXT FROM cur_gar INTO @id_garantia_origen, @id_local, @saldo_disponible;
    END;

    CLOSE cur_gar;
    DEALLOCATE cur_gar;

    IF OBJECT_ID(N'dbo.msp_bitacora_cierre_contrato', N'U') IS NOT NULL
    BEGIN
        INSERT INTO dbo.msp_bitacora_cierre_contrato (
            id_contrato_arriendo,
            id_usuario,
            estado_contrato_anterior,
            estado_contrato_nuevo,
            motivo_cierre
        )
        VALUES (
            @id_contrato_origen,
            @id_usuario,
            @estado_origen,
            3,
            CONCAT(N'Traspaso a contrato #', @id_contrato_destino, N'. Motivo: ', @motivo)
        );
    END;

    IF OBJECT_ID(N'dbo.msp_historial_contrato', N'U') IS NOT NULL
    BEGIN
        SET @detalle_origen = CONCAT(
            N'{"origen":"msp_contrato_traspasar_razon_social","tipo":"traspaso_salida","id_contrato_destino":',
            CONVERT(NVARCHAR(20), @id_contrato_destino),
            N',"id_arrendatario_destino":',
            CONVERT(NVARCHAR(20), @id_arrendatario_destino),
            N',"fecha_traspaso":"',
            CONVERT(NVARCHAR(10), @fecha_traspaso, 23),
            N'"}'
        );

        SET @detalle_destino = CONCAT(
            N'{"origen":"msp_contrato_traspasar_razon_social","tipo":"traspaso_entrada","id_contrato_origen":',
            CONVERT(NVARCHAR(20), @id_contrato_origen),
            N',"id_arrendatario_origen":',
            CONVERT(NVARCHAR(20), @id_arrendatario_origen),
            N',"fecha_traspaso":"',
            CONVERT(NVARCHAR(10), @fecha_traspaso, 23),
            N'"}'
        );

        INSERT INTO dbo.msp_historial_contrato (
            id_contrato_arriendo,
            tipo_evento,
            id_usuario,
            detalle_evento,
            motivo_evento
        )
        VALUES
            (@id_contrato_origen, N'ACTUALIZACION', @id_usuario, @detalle_origen, @motivo),
            (@id_contrato_destino, N'ACTUALIZACION', @id_usuario, @detalle_destino, @motivo);
    END;

    COMMIT TRANSACTION;

    SELECT
        @id_contrato_origen AS id_contrato_origen,
        @id_contrato_destino AS id_contrato_destino,
        @id_tienda AS id_tienda,
        @id_arrendatario_origen AS id_arrendatario_origen,
        @id_arrendatario_destino AS id_arrendatario_destino,
        @fecha_traspaso AS fecha_traspaso;
END;
GO

PRINT 'patch_contrato_traspaso_razon_social aplicado.';
GO
