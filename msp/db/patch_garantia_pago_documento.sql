/*
 PATCH: aplicar garantia a documento de cobro
 - Registra un pago real sobre msp_documentos_cobro.
 - Registra el movimiento de garantia asociado al pago/documento.
 - Permite pagar consumos de contrato terminado usando saldo disponible de garantia.
*/

IF OBJECT_ID(N'dbo.msp_garantia_aplicar_documento', N'P') IS NOT NULL
    DROP PROCEDURE dbo.msp_garantia_aplicar_documento;
GO

CREATE PROCEDURE dbo.msp_garantia_aplicar_documento
    @id_documento_cobro INT,
    @id_garantia INT = NULL,
    @fecha_pago DATE,
    @monto_aplicar DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago_generado INT OUTPUT,
    @id_movimiento_garantia INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE
        @id_tienda INT,
        @id_contrato_documento INT,
        @periodo_facturacion DATE,
        @saldo_pendiente DECIMAL(18,2),
        @estado_documento TINYINT,
        @id_tipo_aplicacion INT,
        @saldo_disponible DECIMAL(18,2),
        @monto_aplicado_documento DECIMAL(18,2),
        @monto_saldo_favor_generado DECIMAL(18,2);

    SET @id_pago_generado = NULL;
    SET @id_movimiento_garantia = NULL;
    SET @observaciones = NULLIF(LTRIM(RTRIM(ISNULL(@observaciones, N''))), N'');

    IF ISNULL(@id_documento_cobro, 0) <= 0
        THROW 51101, 'Debes indicar un documento de cobro valido.', 1;

    IF @fecha_pago IS NULL
        THROW 51102, 'Debes indicar fecha de aplicacion.', 1;

    IF @monto_aplicar IS NULL OR @monto_aplicar <= 0
        THROW 51103, 'El monto a aplicar debe ser mayor a cero.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT
            @id_tienda = dc.id_tienda,
            @id_contrato_documento = dc.id_contrato_arriendo,
            @periodo_facturacion = dc.periodo_facturacion,
            @saldo_pendiente = dc.saldo_pendiente,
            @estado_documento = dc.estado_documento
        FROM dbo.msp_documentos_cobro dc WITH (UPDLOCK, HOLDLOCK)
        WHERE dc.id_documento_cobro = @id_documento_cobro;

        IF ISNULL(@id_tienda, 0) <= 0
            THROW 51104, 'El documento indicado no existe.', 1;

        IF @estado_documento = 5
            THROW 51105, 'No se puede aplicar garantia sobre documentos anulados.', 1;

        IF ISNULL(@saldo_pendiente, 0) <= 0
            THROW 51106, 'El documento no tiene saldo pendiente.', 1;

        IF @monto_aplicar > @saldo_pendiente
            THROW 51107, 'El monto supera el saldo pendiente del documento.', 1;

        IF ISNULL(@id_garantia, 0) <= 0
        BEGIN
            SELECT TOP (1)
                @id_garantia = g.id_garantia
            FROM dbo.msp_garantias g WITH (UPDLOCK, HOLDLOCK)
            INNER JOIN dbo.msp_contratos_arriendo c
                ON c.id_contrato_arriendo = g.id_contrato_arriendo
            WHERE c.id_tienda = @id_tienda
              AND (ISNULL(@id_contrato_documento, 0) <= 0 OR c.id_contrato_arriendo = @id_contrato_documento)
              AND g.estado_garantia <> 6
              AND c.fecha_inicio <= EOMONTH(@periodo_facturacion)
              AND (
                    c.fecha_termino_efectiva IS NULL
                    OR DATEADD(MONTH, 2, c.fecha_termino_efectiva) >= @periodo_facturacion
                  )
            ORDER BY
                CASE WHEN c.estado_contrato IN (3,4) THEN 0 ELSE 1 END,
                c.fecha_inicio DESC,
                g.id_garantia DESC;
        END;

        IF ISNULL(@id_garantia, 0) <= 0
            THROW 51108, 'No existe garantia activa para aplicar al documento.', 1;

        IF NOT EXISTS (
            SELECT 1
            FROM dbo.msp_garantias g
            INNER JOIN dbo.msp_contratos_arriendo c
                ON c.id_contrato_arriendo = g.id_contrato_arriendo
            WHERE g.id_garantia = @id_garantia
              AND g.estado_garantia <> 6
              AND c.id_tienda = @id_tienda
              AND (ISNULL(@id_contrato_documento, 0) <= 0 OR c.id_contrato_arriendo = @id_contrato_documento)
        )
            THROW 51109, 'La garantia seleccionada no corresponde al documento.', 1;

        SELECT @saldo_disponible = gr.saldo_disponible
        FROM dbo.msp_vw_garantias_resumen gr
        WHERE gr.id_garantia = @id_garantia;

        IF @saldo_disponible IS NULL
            THROW 51110, 'No fue posible leer el saldo de garantia.', 1;

        IF @monto_aplicar > @saldo_disponible
            THROW 51111, 'El monto supera el saldo disponible de la garantia.', 1;

        SELECT @id_tipo_aplicacion = t.id_tipo_movimiento_garantia
        FROM dbo.msp_tipos_movimiento_garantia t
        WHERE t.codigo_movimiento = N'APLICACION_CARGO'
          AND t.activo = 1;

        IF ISNULL(@id_tipo_aplicacion, 0) <= 0
            THROW 51112, 'No existe tipo de movimiento APLICACION_CARGO.', 1;

        DECLARE @resultado_pago TABLE (
            id_pago_generado INT NULL,
            monto_aplicado_documento DECIMAL(18,2) NULL,
            monto_saldo_favor_generado DECIMAL(18,2) NULL
        );

        INSERT INTO @resultado_pago (
            id_pago_generado,
            monto_aplicado_documento,
            monto_saldo_favor_generado
        )
        EXEC dbo.msp_registrar_pago_documento
            @id_documento_cobro = @id_documento_cobro,
            @fecha_pago = @fecha_pago,
            @monto_pagado = @monto_aplicar,
            @medio_pago = N'Garantia',
            @referencia_pago = N'Aplicacion de garantia',
            @observaciones = @observaciones,
            @detalle_conceptos_json = NULL;

        SELECT TOP (1)
            @id_pago_generado = id_pago_generado,
            @monto_aplicado_documento = monto_aplicado_documento,
            @monto_saldo_favor_generado = monto_saldo_favor_generado
        FROM @resultado_pago;

        IF ISNULL(@id_pago_generado, 0) <= 0
            THROW 51113, 'No fue posible registrar el pago con garantia.', 1;

        IF ABS(ISNULL(@monto_aplicado_documento, 0) - @monto_aplicar) > 0.01
            THROW 51114, 'El pago registrado no coincide con el monto de garantia.', 1;

        IF ISNULL(@monto_saldo_favor_generado, 0) > 0
            THROW 51115, 'La aplicacion de garantia no puede generar saldo a favor.', 1;

        INSERT INTO dbo.msp_movimientos_garantia (
            id_garantia,
            fecha_movimiento,
            id_tipo_movimiento_garantia,
            fondo_origen,
            monto_movimiento,
            id_documento_cobro,
            id_pago,
            observaciones
        )
        VALUES (
            @id_garantia,
            @fecha_pago,
            @id_tipo_aplicacion,
            'D',
            @monto_aplicar,
            @id_documento_cobro,
            @id_pago_generado,
            @observaciones
        );

        SET @id_movimiento_garantia = CONVERT(INT, SCOPE_IDENTITY());

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT
        @id_pago_generado AS id_pago_generado,
        @id_movimiento_garantia AS id_movimiento_garantia,
        @id_documento_cobro AS id_documento_cobro,
        @id_garantia AS id_garantia,
        @monto_aplicar AS monto_aplicado;
END;
GO

PRINT 'Patch garantia pago documento aplicado.';
