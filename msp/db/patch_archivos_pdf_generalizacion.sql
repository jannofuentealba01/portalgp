/*
===========================================================================
 PATCH: GENERALIZACION RESPALDO PDFs
 - Agrega modulo_origen
 - Habilita tipo_archivo = VALE_COBRO
===========================================================================
*/

SET NOCOUNT ON;
GO

IF OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NOT NULL
BEGIN
    IF COL_LENGTH('dbo.msp_pago_contrato_archivos', 'modulo_origen') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_pago_contrato_archivos
            ADD modulo_origen NVARCHAR(30) NOT NULL
                CONSTRAINT DF_msp_pago_contrato_archivos_modulo_upgrade DEFAULT (N'PAGO_CONTRATO');
    END;

    UPDATE dbo.msp_pago_contrato_archivos
       SET modulo_origen = N'PAGO_CONTRATO'
     WHERE LTRIM(RTRIM(ISNULL(modulo_origen, N''))) = N'';
END;
GO

IF EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_msp_pago_contrato_archivos_tipo'
      AND parent_object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U')
)
BEGIN
    ALTER TABLE dbo.msp_pago_contrato_archivos
        DROP CONSTRAINT CK_msp_pago_contrato_archivos_tipo;
END;
GO

IF OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U') IS NOT NULL
BEGIN
    ALTER TABLE dbo.msp_pago_contrato_archivos
        ADD CONSTRAINT CK_msp_pago_contrato_archivos_tipo
        CHECK (UPPER(tipo_archivo) IN (N'VALE_PAGO', N'COMPROBANTE_GASTOS', N'VALE_COBRO'));
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = N'IX_msp_pago_contrato_archivos_modulo_periodo'
      AND object_id = OBJECT_ID(N'dbo.msp_pago_contrato_archivos', N'U')
)
BEGIN
    CREATE INDEX IX_msp_pago_contrato_archivos_modulo_periodo
        ON dbo.msp_pago_contrato_archivos (modulo_origen, periodo_ym, id_pago_contrato_archivo DESC)
        INCLUDE (tipo_archivo, numero_documento, arrendatario_nombre, locales, estado_archivo, nombre_archivo);
END;
GO

PRINT 'Patch archivos_pdf_generalizacion aplicado.';
GO
