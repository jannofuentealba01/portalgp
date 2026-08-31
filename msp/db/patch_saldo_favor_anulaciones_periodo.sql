/*
===========================================================================
 PATCH: saldo a favor - anulaciones sincronizadas por periodo
 - Sincroniza anulación de pagos con items/aplicaciones de saldo por período.
 - Mantiene historial por reversas y cambios de estado (sin borrado físico).
===========================================================================
*/

SET NOCOUNT ON;
GO

CREATE OR ALTER PROCEDURE dbo.msp_anular_pago_documento
    @id_pago                INT,
    @fecha_anulacion        DATE,
    @motivo_anulacion       NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @id_documento_cobro INT;
    DECLARE @id_tienda INT;
    DECLARE @monto_pagado DECIMAL(18,2);
    DECLARE @monto_saldo_favor_generado DECIMAL(18,2);
    DECLARE @aplica_desde_saldo_favor BIT;
    DECLARE @saldo_favor_disponible DECIMAL(18,2);

    DECLARE @id_movimiento_excedente INT = NULL;
    DECLARE @id_item_periodo INT = NULL;
    DECLARE @id_movimiento_reversa INT = NULL;
    DECLARE @aplicaciones_activas_item INT = 0;

    IF @id_pago IS NULL OR @id_pago <= 0
    BEGIN
        ;THROW 50071, 'Debes indicar un pago valido.', 1;
    END;

    IF @fecha_anulacion IS NULL
    BEGIN
        ;THROW 50072, 'Debes indicar la fecha de anulacion.', 1;
    END;

    IF @motivo_anulacion IS NULL OR LTRIM(RTRIM(@motivo_anulacion)) = N''
    BEGIN
        ;THROW 50073, 'Debes indicar un motivo de anulacion.', 1;
    END;

    SELECT
        @id_documento_cobro = p.id_documento_cobro,
        @id_tienda = dc.id_tienda,
        @monto_pagado = p.monto_pagado,
        @monto_saldo_favor_generado = ISNULL(p.monto_saldo_favor_generado, 0),
        @aplica_desde_saldo_favor = ISNULL(p.aplica_desde_saldo_favor, 0)
    FROM dbo.msp_pagos p WITH (UPDLOCK, HOLDLOCK)
    INNER JOIN dbo.msp_documentos_cobro dc
        ON dc.id_documento_cobro = p.id_documento_cobro
    WHERE p.id_pago = @id_pago
      AND p.estado_pago = 1;

    IF @id_documento_cobro IS NULL
    BEGIN
        ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @aplica_desde_saldo_favor = 1
        BEGIN
            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                4,
                @monto_pagado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de aplicación de saldo a favor por anulación de pago #', @id_pago)
            );

            IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_aplicaciones
                SET estado_aplicacion = 5,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_pago = @id_pago
                  AND estado_aplicacion = 1;
            END;
        END
        ELSE IF @monto_saldo_favor_generado > 0
        BEGIN
            SELECT @saldo_favor_disponible = ISNULL(sf.saldo_disponible, 0)
            FROM dbo.msp_saldos_favor_tienda sf WITH (UPDLOCK, HOLDLOCK)
            WHERE sf.id_tienda = @id_tienda;

            SET @saldo_favor_disponible = ISNULL(@saldo_favor_disponible, 0);

            IF @saldo_favor_disponible < @monto_saldo_favor_generado
            BEGIN
                ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
            END;

            SELECT TOP 1
                @id_movimiento_excedente = msf.id_movimiento_saldo_favor
            FROM dbo.msp_movimientos_saldo_favor_tienda msf WITH (UPDLOCK, HOLDLOCK)
            WHERE msf.id_pago = @id_pago
              AND msf.id_documento_cobro = @id_documento_cobro
              AND msf.tipo_movimiento = 1
              AND msf.monto_movimiento > 0
            ORDER BY msf.id_movimiento_saldo_favor DESC;

            IF @id_movimiento_excedente IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                SELECT TOP 1
                    @id_item_periodo = sfpi.id_saldo_favor_periodo_item
                FROM dbo.msp_saldo_favor_periodo_items sfpi WITH (UPDLOCK, HOLDLOCK)
                WHERE sfpi.id_movimiento_saldo_favor = @id_movimiento_excedente
                  AND sfpi.estado_item = 1
                ORDER BY sfpi.id_saldo_favor_periodo_item DESC;

                IF @id_item_periodo IS NOT NULL
                   AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NOT NULL
                BEGIN
                    SELECT @aplicaciones_activas_item = COUNT(*)
                    FROM dbo.msp_saldo_favor_periodo_aplicaciones sfa WITH (UPDLOCK, HOLDLOCK)
                    WHERE sfa.id_saldo_favor_periodo_item = @id_item_periodo
                      AND sfa.estado_aplicacion = 1;

                    IF ISNULL(@aplicaciones_activas_item, 0) > 0
                    BEGIN
                        ;THROW 50075, 'El excedente generado por este pago ya fue utilizado total o parcialmente.', 1;
                    END;
                END;
            END;

            DECLARE @out_reversa TABLE (id_movimiento_saldo_favor INT);

            INSERT INTO dbo.msp_movimientos_saldo_favor_tienda (
                id_tienda,
                fecha_movimiento,
                tipo_movimiento,
                monto_movimiento,
                id_documento_cobro,
                id_pago,
                observaciones
            )
            OUTPUT INSERTED.id_movimiento_saldo_favor INTO @out_reversa(id_movimiento_saldo_favor)
            VALUES (
                @id_tienda,
                @fecha_anulacion,
                3,
                -@monto_saldo_favor_generado,
                @id_documento_cobro,
                @id_pago,
                CONCAT(N'Reversa de excedente por anulación de pago #', @id_pago)
            );

            SELECT TOP 1 @id_movimiento_reversa = id_movimiento_saldo_favor
            FROM @out_reversa;

            IF @id_item_periodo IS NOT NULL
               AND OBJECT_ID(N'dbo.msp_saldo_favor_periodo_items', N'U') IS NOT NULL
            BEGIN
                UPDATE dbo.msp_saldo_favor_periodo_items
                SET estado_item = 5,
                    id_movimiento_reversa = @id_movimiento_reversa,
                    fecha_actualizacion = SYSDATETIME()
                WHERE id_saldo_favor_periodo_item = @id_item_periodo
                  AND estado_item = 1;
            END;
        END;

        UPDATE dbo.msp_pagos
        SET estado_pago = 2,
            fecha_anulacion = @fecha_anulacion,
            motivo_anulacion = @motivo_anulacion
        WHERE id_pago = @id_pago
          AND estado_pago = 1;

        IF @@ROWCOUNT = 0
        BEGIN
            ;THROW 50074, 'El pago no existe o ya estaba anulado.', 1;
        END;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
            ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT 'Patch saldo a favor: anulaciones sincronizadas por periodo aplicado.';
GO
