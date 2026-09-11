SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.msp_cierre_mensual', N'U') IS NOT NULL
       AND NOT EXISTS (
            SELECT 1 FROM dbo.msp_cierre_mensual WHERE periodo_facturacion = CONVERT(DATE, '2026-05-01')
       )
    BEGIN
        INSERT INTO dbo.msp_cierre_mensual
            (periodo_facturacion, fecha_valor_uf, valor_uf, estado_cierre, observaciones)
        VALUES
            (CONVERT(DATE, '2026-05-01'), CONVERT(DATE, '2026-05-01'), 40133.50, 1,
             N'Período habilitado en Borrador. UF del 01-05-2026 según tabla oficial SII; sin lecturas ni documentos fabricados.');
    END;

    IF OBJECT_ID(N'dbo.msp_cierre_mensual_transiciones', N'U') IS NOT NULL
    BEGIN
        DECLARE @corregidos TABLE (id_cierre_mensual INT, estado_origen TINYINT, estado_destino TINYINT);

        UPDATE c
        SET estado_cierre = 2
        OUTPUT inserted.id_cierre_mensual, deleted.estado_cierre, inserted.estado_cierre
            INTO @corregidos (id_cierre_mensual, estado_origen, estado_destino)
        FROM dbo.msp_cierre_mensual c
        WHERE c.periodo_facturacion IN (CONVERT(DATE, '2026-01-01'), CONVERT(DATE, '2026-02-01'))
          AND c.estado_cierre = 1
          AND c.observaciones LIKE N'%Estado Calculado%'
          AND EXISTS (
                SELECT 1 FROM dbo.msp_documentos_cobro dc
                WHERE dc.periodo_facturacion = c.periodo_facturacion
          );

        INSERT INTO dbo.msp_cierre_mensual_transiciones
            (id_cierre_mensual, estado_origen, estado_destino, motivo, id_usuario, fecha_transicion)
        SELECT r.id_cierre_mensual, r.estado_origen, r.estado_destino,
               N'Corrección de consistencia: el historial del período indicaba Estado Calculado.', NULL, SYSDATETIME()
        FROM @corregidos r;
    END;

    IF OBJECT_ID(N'dbo.msp_documentos_cobro_eventos', N'U') IS NOT NULL
    BEGIN
        INSERT INTO dbo.msp_documentos_cobro_eventos
            (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento,
             titulo_evento, detalle_evento, payload_json, es_evento_derivado, fecha_evento)
        SELECT dc.id_contrato_arriendo, dc.id_documento_cobro, N'EMISION', N'DOCUMENTO',
               N'Emisión histórica', N'Evento reconstruido desde el documento existente.',
               CONCAT(N'{"numero_documento":"', STRING_ESCAPE(ISNULL(dc.numero_documento, N''), 'json'), N'"}'),
               1, COALESCE(dc.fecha_registro, CAST(dc.fecha_emision AS DATETIME2(0)), SYSDATETIME())
        FROM dbo.msp_documentos_cobro dc
        WHERE dc.id_contrato_arriendo IS NOT NULL
          AND NOT EXISTS (
                SELECT 1 FROM dbo.msp_documentos_cobro_eventos ev
                WHERE ev.id_documento_cobro = dc.id_documento_cobro
                  AND ev.tipo_evento = N'EMISION'
          );

        INSERT INTO dbo.msp_documentos_cobro_eventos
            (id_contrato_arriendo, id_documento_cobro, tipo_evento, origen_evento,
             titulo_evento, detalle_evento, payload_json, es_evento_derivado, fecha_evento)
        SELECT dc.id_contrato_arriendo, p.id_documento_cobro,
               CASE WHEN p.estado_pago = 1 THEN N'PAGO_APLICADO' ELSE N'PAGO_ANULADO' END,
               N'PAGO',
               CASE WHEN p.estado_pago = 1 THEN N'Pago histórico aplicado' ELSE N'Pago histórico anulado' END,
               N'Evento reconstruido desde el registro de pago existente.',
               CONCAT(N'{"id_pago":', p.id_pago, N',"monto":', CONVERT(NVARCHAR(50), p.monto_pagado), N'}'),
               1, COALESCE(p.fecha_registro, CAST(p.fecha_pago AS DATETIME2(0)), SYSDATETIME())
        FROM dbo.msp_pagos p
        INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = p.id_documento_cobro
        WHERE NOT EXISTS (
            SELECT 1 FROM dbo.msp_documentos_cobro_eventos ev
            WHERE ev.id_documento_cobro = p.id_documento_cobro
              AND ev.tipo_evento = CASE WHEN p.estado_pago = 1 THEN N'PAGO_APLICADO' ELSE N'PAGO_ANULADO' END
              AND JSON_VALUE(ev.payload_json, '$.id_pago') = CONVERT(NVARCHAR(30), p.id_pago)
        );
    END;

    IF OBJECT_ID(N'dbo.msp_referencias_financieras_historicas', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_referencias_financieras_historicas (
            id_referencia_historica BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_msp_referencias_financieras_historicas PRIMARY KEY,
            tipo_origen NVARCHAR(80) NOT NULL,
            id_origen INT NOT NULL,
            referencia NVARCHAR(500) NOT NULL,
            fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_rfh_fecha DEFAULT (SYSDATETIME()),
            CONSTRAINT UX_msp_rfh_origen UNIQUE (tipo_origen, id_origen)
        );
    END;

    INSERT INTO dbo.msp_referencias_financieras_historicas (tipo_origen, id_origen, referencia)
    SELECT N'SALDO_FAVOR_MOVIMIENTO', msf.id_movimiento_saldo_favor,
           CONCAT(
                CASE WHEN msf.id_pago IS NOT NULL AND p.id_pago IS NULL THEN CONCAT(N'Pago eliminado #', msf.id_pago, N'. ') ELSE N'' END,
                CASE WHEN msf.id_documento_cobro IS NOT NULL AND dc.id_documento_cobro IS NULL THEN CONCAT(N'Documento eliminado #', msf.id_documento_cobro, N'.') ELSE N'' END
           )
    FROM dbo.msp_movimientos_saldo_favor_tienda msf
    LEFT JOIN dbo.msp_pagos p ON p.id_pago = msf.id_pago
    LEFT JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro = msf.id_documento_cobro
    WHERE ((msf.id_pago IS NOT NULL AND p.id_pago IS NULL)
        OR (msf.id_documento_cobro IS NOT NULL AND dc.id_documento_cobro IS NULL))
      AND NOT EXISTS (
        SELECT 1 FROM dbo.msp_referencias_financieras_historicas rh
        WHERE rh.tipo_origen = N'SALDO_FAVOR_MOVIMIENTO'
          AND rh.id_origen = msf.id_movimiento_saldo_favor
      );

    IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
        GRANT SELECT ON dbo.msp_referencias_financieras_historicas TO portalgp_runtime_role;

    IF OBJECT_ID(N'dbo.msp_pago_contrato_operaciones', N'U') IS NOT NULL
       AND COL_LENGTH(N'dbo.msp_pago_contrato_operaciones', N'detalle_historico') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_pago_contrato_operaciones ADD detalle_historico NVARCHAR(MAX) NULL;
        ALTER TABLE dbo.msp_pago_contrato_operaciones ADD trazabilidad_incompleta BIT NOT NULL
            CONSTRAINT DF_msp_pco_trazabilidad_incompleta DEFAULT (0);
    END;

    IF COL_LENGTH(N'dbo.msp_pago_contrato_operaciones', N'detalle_historico') IS NOT NULL
    BEGIN
        EXEC sys.sp_executesql N'
            UPDATE op
            SET trazabilidad_incompleta = 1,
                detalle_historico = CONCAT(
                    N''Registro legado sin detalle recuperable. Totales conservados: pagado='', op.monto_total_pagado,
                    N''; aplicado='', op.monto_total_aplicado, N''; excedente='', op.monto_total_excedente,
                    N''; no imputado='', op.monto_total_no_imputado, N''; documentos='', op.total_documentos, N''.''
                )
            FROM dbo.msp_pago_contrato_operaciones op
            WHERE NOT EXISTS (
                SELECT 1 FROM dbo.msp_pago_contrato_operacion_detalle od
                WHERE od.id_pago_contrato_operacion = op.id_pago_contrato_operacion
            )
              AND NULLIF(LTRIM(RTRIM(ISNULL(op.detalle_historico, N''''))), N'''') IS NULL;';
    END;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
