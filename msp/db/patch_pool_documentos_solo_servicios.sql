/*
 * Permite clasificar documentos con gas, agua o solo cargos, sin exigir
 * un medidor de electricidad. No modifica contratos ni documentos.
 */
SET XACT_ABORT ON;
BEGIN TRANSACTION;

IF OBJECT_ID(N'dbo.msp_pool_documentos_periodo', N'U') IS NOT NULL
BEGIN
    IF EXISTS (
        SELECT 1 FROM sys.check_constraints
        WHERE parent_object_id = OBJECT_ID(N'dbo.msp_pool_documentos_periodo')
          AND name = N'CK_msp_pool_doc_perfil'
    )
        ALTER TABLE dbo.msp_pool_documentos_periodo DROP CONSTRAINT CK_msp_pool_doc_perfil;

    ALTER TABLE dbo.msp_pool_documentos_periodo WITH CHECK
    ADD CONSTRAINT CK_msp_pool_doc_perfil CHECK (
        perfil_servicios IN (
            N'LUZ', N'LUZ_GAS', N'LUZ_AGUA', N'LUZ_GAS_AGUA',
            N'GAS', N'AGUA', N'GAS_AGUA', N'SIN_SERVICIO'
        )
    );
END;

COMMIT TRANSACTION;
