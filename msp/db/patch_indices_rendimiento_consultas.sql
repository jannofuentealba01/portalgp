USE [PORTALGP];
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET LOCK_TIMEOUT 15000;

/*
  Índices de lectura validados en laboratorio con copias temporales escaladas.
  El parche es idempotente y no modifica datos funcionales.
*/

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.msp_documentos_cobro_detalle', N'U') IS NULL
        THROW 51000, 'Falta dbo.msp_documentos_cobro_detalle.', 1;
    IF COL_LENGTH(N'dbo.msp_documentos_cobro_detalle', N'id_cobro_servicio') IS NULL
       OR COL_LENGTH(N'dbo.msp_documentos_cobro_detalle', N'id_documento_cobro') IS NULL
        THROW 51000, 'Faltan columnas requeridas en dbo.msp_documentos_cobro_detalle.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_detalle')
          AND name = N'IX_msp_dcd_cobro_servicio_documento'
    )
        CREATE INDEX IX_msp_dcd_cobro_servicio_documento
            ON dbo.msp_documentos_cobro_detalle(id_cobro_servicio)
            INCLUDE(id_documento_cobro);

    IF OBJECT_ID(N'dbo.msp_acc_asientos', N'U') IS NULL
        THROW 51000, 'Falta dbo.msp_acc_asientos.', 1;
    IF COL_LENGTH(N'dbo.msp_acc_asientos', N'tabla_origen') IS NULL
       OR COL_LENGTH(N'dbo.msp_acc_asientos', N'id_origen') IS NULL
       OR COL_LENGTH(N'dbo.msp_acc_asientos', N'estado_asiento') IS NULL
        THROW 51000, 'Faltan columnas requeridas en dbo.msp_acc_asientos.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_acc_asientos')
          AND name = N'IX_msp_acc_asientos_origen_estado'
    )
        CREATE INDEX IX_msp_acc_asientos_origen_estado
            ON dbo.msp_acc_asientos(tabla_origen, id_origen, estado_asiento);

    IF OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NULL
        THROW 51000, 'Falta dbo.msp_pago_contrato_archivos.', 1;
    IF COL_LENGTH(N'dbo.msp_pago_contrato_archivos', N'id_documento_cobro') IS NULL
       OR COL_LENGTH(N'dbo.msp_pago_contrato_archivos', N'id_pago') IS NULL
       OR COL_LENGTH(N'dbo.msp_pago_contrato_archivos', N'tipo_archivo') IS NULL
        THROW 51000, 'Faltan columnas requeridas en dbo.msp_pago_contrato_archivos.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos')
          AND name = N'IX_msp_pca_documento'
    )
        CREATE INDEX IX_msp_pca_documento
            ON dbo.msp_pago_contrato_archivos(id_documento_cobro)
            INCLUDE(id_pago, tipo_archivo);

    IF OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda', N'U') IS NULL
        THROW 51000, 'Falta dbo.msp_movimientos_saldo_favor_tienda.', 1;
    IF COL_LENGTH(N'dbo.msp_movimientos_saldo_favor_tienda', N'id_documento_cobro') IS NULL
       OR COL_LENGTH(N'dbo.msp_movimientos_saldo_favor_tienda', N'id_tienda') IS NULL
       OR COL_LENGTH(N'dbo.msp_movimientos_saldo_favor_tienda', N'id_pago') IS NULL
       OR COL_LENGTH(N'dbo.msp_movimientos_saldo_favor_tienda', N'monto_movimiento') IS NULL
        THROW 51000, 'Faltan columnas requeridas en dbo.msp_movimientos_saldo_favor_tienda.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda')
          AND name = N'IX_msp_msft_documento'
    )
        CREATE INDEX IX_msp_msft_documento
            ON dbo.msp_movimientos_saldo_favor_tienda(id_documento_cobro)
            INCLUDE(id_tienda, id_pago, monto_movimiento)
            WHERE id_documento_cobro IS NOT NULL;

    IF OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'U') IS NULL
        THROW 51000, 'Falta dbo.msp_saldo_favor_periodo_aplicaciones.', 1;
    IF COL_LENGTH(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'id_documento_cobro') IS NULL
       OR COL_LENGTH(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'estado_aplicacion') IS NULL
       OR COL_LENGTH(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'id_saldo_favor_periodo_item') IS NULL
       OR COL_LENGTH(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'id_pago') IS NULL
       OR COL_LENGTH(N'dbo.msp_saldo_favor_periodo_aplicaciones', N'monto_aplicado') IS NULL
        THROW 51000, 'Faltan columnas requeridas en dbo.msp_saldo_favor_periodo_aplicaciones.', 1;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones')
          AND name = N'IX_msp_sfpa_documento_estado'
    )
        CREATE INDEX IX_msp_sfpa_documento_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones(id_documento_cobro, estado_aplicacion)
            INCLUDE(id_saldo_favor_periodo_item, id_pago, monto_aplicado);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;

