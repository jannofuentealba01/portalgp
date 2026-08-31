/* ============================================================================
   MSP - Multas y cargos manuales con alcance de tienda

   - Permite que msp_cargos_salida.id_local sea NULL.
   - Convierte las multas existentes a alcance de tienda.
   - Conserva el local en cargos históricos de otros tipos.
   ============================================================================ */

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF OBJECT_ID(N'dbo.msp_cargos_salida', N'U') IS NULL
    THROW 51000, 'No existe dbo.msp_cargos_salida.', 1;

IF OBJECT_ID(N'dbo.msp_tipos_cargo_salida', N'U') IS NULL
    THROW 51001, 'No existe dbo.msp_tipos_cargo_salida.', 1;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_valida_local_contrato
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        LEFT JOIN dbo.msp_ocupacion_locales ol
            ON ol.id_tienda = c.id_tienda
           AND ol.id_local = i.id_local
        WHERE i.id_local IS NOT NULL
          AND ol.id_ocupacion_local IS NULL
    )
        THROW 50302, 'El local del cargo no pertenece a la tienda del contrato.', 1;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_tipos_cargo_salida tc
            ON tc.id_tipo_cargo_salida = i.id_tipo_cargo_salida
        WHERE tc.requiere_documento = 1
          AND i.id_documento_cobro IS NULL
    )
        THROW 50303, 'El tipo de cargo exige un documento asociado.', 1;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_documentos_cobro dc
            ON dc.id_documento_cobro = i.id_documento_cobro
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo = i.id_contrato_arriendo
        WHERE i.id_documento_cobro IS NOT NULL
          AND dc.id_tienda <> c.id_tienda
    )
        THROW 50304, 'El documento asociado al cargo no pertenece a la tienda del contrato.', 1;
END;
GO

BEGIN TRANSACTION;

IF EXISTS (
    SELECT 1
    FROM sys.columns
    WHERE object_id = OBJECT_ID(N'dbo.msp_cargos_salida')
      AND name = N'id_local'
      AND is_nullable = 0
)
BEGIN
    ALTER TABLE dbo.msp_cargos_salida
        ALTER COLUMN id_local INT NULL;
END;

UPDATE cs
SET cs.id_local = NULL
FROM dbo.msp_cargos_salida cs
INNER JOIN dbo.msp_tipos_cargo_salida tc
    ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
WHERE UPPER(LTRIM(RTRIM(tc.codigo_tipo_cargo))) = N'MULTA'
  AND cs.id_local IS NOT NULL;

COMMIT TRANSACTION;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_cargos_multa_alcance_tienda
ON dbo.msp_cargos_salida
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE cs
    SET cs.id_local = NULL
    FROM dbo.msp_cargos_salida cs
    INNER JOIN inserted i
        ON i.id_cargo_salida = cs.id_cargo_salida
    INNER JOIN dbo.msp_tipos_cargo_salida tc
        ON tc.id_tipo_cargo_salida = cs.id_tipo_cargo_salida
    WHERE UPPER(LTRIM(RTRIM(tc.codigo_tipo_cargo))) = N'MULTA'
      AND cs.id_local IS NOT NULL;
END;
GO
