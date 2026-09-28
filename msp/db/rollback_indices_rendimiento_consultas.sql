USE [PORTALGP];
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET LOCK_TIMEOUT 15000;

BEGIN TRY
    BEGIN TRANSACTION;

    IF EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_saldo_favor_periodo_aplicaciones')
          AND name = N'IX_msp_sfpa_documento_estado'
    )
        DROP INDEX IX_msp_sfpa_documento_estado
            ON dbo.msp_saldo_favor_periodo_aplicaciones;

    IF EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda')
          AND name = N'IX_msp_msft_documento'
    )
        DROP INDEX IX_msp_msft_documento
            ON dbo.msp_movimientos_saldo_favor_tienda;

    IF EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos')
          AND name = N'IX_msp_pca_documento'
    )
        DROP INDEX IX_msp_pca_documento
            ON dbo.msp_pago_contrato_archivos;

    IF EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_acc_asientos')
          AND name = N'IX_msp_acc_asientos_origen_estado'
    )
        DROP INDEX IX_msp_acc_asientos_origen_estado
            ON dbo.msp_acc_asientos;

    IF EXISTS (
        SELECT 1 FROM sys.indexes
        WHERE object_id = OBJECT_ID(N'dbo.msp_documentos_cobro_detalle')
          AND name = N'IX_msp_dcd_cobro_servicio_documento'
    )
        DROP INDEX IX_msp_dcd_cobro_servicio_documento
            ON dbo.msp_documentos_cobro_detalle;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;
    THROW;
END CATCH;
