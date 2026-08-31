/*
 PATCH: documentos de cobro por contrato
 - Permite documento normal y liquidacion post-termino para el mismo local/periodo.
 - Mantiene compatibilidad con documentos historicos existentes.
*/

IF OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NULL
BEGIN
    PRINT 'patch_documentos_por_contrato: tabla dbo.msp_documentos_cobro no existe, se omite.';
END
ELSE
BEGIN
    IF COL_LENGTH(N'dbo.msp_documentos_cobro', N'id_contrato_arriendo') IS NULL
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro
        ADD id_contrato_arriendo INT NULL;
    END;

    UPDATE dc
    SET id_contrato_arriendo = contrato_map.id_contrato_arriendo
    FROM dbo.msp_documentos_cobro dc
    OUTER APPLY (
        SELECT TOP (1)
            ca.id_contrato_arriendo
        FROM dbo.msp_contratos_arriendo ca
        WHERE ca.id_tienda = dc.id_tienda
          AND ca.fecha_inicio <= EOMONTH(dc.periodo_facturacion)
          AND (
                ca.fecha_termino_efectiva IS NULL
                OR ca.fecha_termino_efectiva >= dc.periodo_facturacion
                OR DATEADD(MONTH, 1, DATEFROMPARTS(YEAR(ca.fecha_termino_efectiva), MONTH(ca.fecha_termino_efectiva), 1)) = dc.periodo_facturacion
              )
          AND ca.estado_contrato IN (1,2,3,4)
        ORDER BY
            CASE
                WHEN ca.fecha_termino_efectiva IS NOT NULL
                 AND ca.fecha_termino_efectiva < dc.periodo_facturacion THEN 1
                ELSE 0
            END,
            ca.fecha_inicio DESC,
            ca.id_contrato_arriendo DESC
    ) contrato_map
    WHERE dc.id_contrato_arriendo IS NULL
      AND contrato_map.id_contrato_arriendo IS NOT NULL;

    IF EXISTS (
        SELECT 1
        FROM sys.key_constraints
        WHERE name = N'UQ_msp_documentos_cobro_tienda_periodo'
          AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
    )
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro
        DROP CONSTRAINT UQ_msp_documentos_cobro_tienda_periodo;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE name = N'UX_msp_documentos_cobro_contrato_periodo'
          AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
    )
    BEGIN
        CREATE UNIQUE INDEX UX_msp_documentos_cobro_contrato_periodo
            ON dbo.msp_documentos_cobro (id_contrato_arriendo, periodo_facturacion)
            WHERE id_contrato_arriendo IS NOT NULL;
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.indexes
        WHERE name = N'IX_msp_documentos_cobro_tienda_periodo'
          AND object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
    )
    BEGIN
        CREATE INDEX IX_msp_documentos_cobro_tienda_periodo
            ON dbo.msp_documentos_cobro (id_tienda, periodo_facturacion)
            INCLUDE (id_contrato_arriendo, estado_documento, saldo_pendiente);
    END;

    IF NOT EXISTS (
        SELECT 1
        FROM sys.foreign_keys
        WHERE name = N'FK_msp_documentos_cobro_contrato'
          AND parent_object_id = OBJECT_ID(N'dbo.msp_documentos_cobro', N'U')
    )
    BEGIN
        ALTER TABLE dbo.msp_documentos_cobro WITH CHECK
        ADD CONSTRAINT FK_msp_documentos_cobro_contrato
            FOREIGN KEY (id_contrato_arriendo)
            REFERENCES dbo.msp_contratos_arriendo (id_contrato_arriendo);
    END;
END;
GO

PRINT 'Patch documentos por contrato aplicado.';
GO
