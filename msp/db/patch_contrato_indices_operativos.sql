/*
===========================================================================
 PATCH: contratos - indices operativos y cierre financiero
 Objetivo:
 - Estado operativo de contrato = (1,2).
 - Estado 3 queda reservado para cierre financiero.
 - Agregar soporte de consulta para estado 3 y fecha_termino_efectiva.
===========================================================================
*/

SET NOCOUNT ON;
GO

/* -----------------------------------------------------------
   1) Unico por tienda solo en estado operativo (1,2)
   ----------------------------------------------------------- */
IF EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'UX_msp_contratos_tienda_activo'
)
BEGIN
    DROP INDEX UX_msp_contratos_tienda_activo ON dbo.msp_contratos_arriendo;
    PRINT 'patch_contrato_indices_operativos: UX_msp_contratos_tienda_activo eliminada.';
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'UX_msp_contratos_tienda_activo'
)
BEGIN
    CREATE UNIQUE INDEX UX_msp_contratos_tienda_activo
        ON dbo.msp_contratos_arriendo (id_tienda)
        WHERE estado_contrato IN (1,2);

    PRINT 'patch_contrato_indices_operativos: UX_msp_contratos_tienda_activo creada (estado 1,2).';
END;
GO

/* -----------------------------------------------------------
   2) Indice para bandeja de cierre financiero (estado 3)
   ----------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'IX_msp_contratos_cierre_financiero'
)
BEGIN
    CREATE INDEX IX_msp_contratos_cierre_financiero
        ON dbo.msp_contratos_arriendo (estado_contrato, fecha_termino_efectiva, id_tienda, id_arrendatario)
        WHERE estado_contrato = 3;

    PRINT 'patch_contrato_indices_operativos: IX_msp_contratos_cierre_financiero creada.';
END;
GO

/* -----------------------------------------------------------
   3) Indice por fecha termino efectiva (cierres/reportes)
   ----------------------------------------------------------- */
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.msp_contratos_arriendo')
      AND name = 'IX_msp_contratos_fecha_termino_efectiva'
)
BEGIN
    CREATE INDEX IX_msp_contratos_fecha_termino_efectiva
        ON dbo.msp_contratos_arriendo (fecha_termino_efectiva, estado_contrato, id_contrato_arriendo)
        WHERE fecha_termino_efectiva IS NOT NULL;

    PRINT 'patch_contrato_indices_operativos: IX_msp_contratos_fecha_termino_efectiva creada.';
END;
GO

PRINT 'patch_contrato_indices_operativos: OK';
GO
