/* Punto 5: clave persistente de la solicitud completa. No cambia el SP financiero.
   Aplicar despues de patch_garantias_caja_administrativa_base.sql, con sqlcmd -b.
   El servicio PHP confirma en una sola transaccion devolucion + egreso + pareja + solicitud. */
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF OBJECT_ID(N'dbo.msp_garantia_devolucion_caja_admin',N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_garantia_devolucion_registrar_caja_admin',N'P') IS NULL
    THROW 53920,N'Falta instalar la estructura administrativa de los puntos 3 y 4.',1;
BEGIN TRANSACTION;
IF OBJECT_ID(N'dbo.msp_garantia_devolucion_solicitudes',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_devolucion_solicitudes(
        id_solicitud CHAR(32) COLLATE Latin1_General_100_BIN2 NOT NULL,
        hash_datos CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
        id_usuario INT NOT NULL,
        id_devolucion_garantia INT NOT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_dev_solicitud_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_dev_solicitud PRIMARY KEY(id_solicitud),
        CONSTRAINT UQ_msp_dev_solicitud_devolucion UNIQUE(id_devolucion_garantia),
        CONSTRAINT FK_msp_dev_solicitud_devolucion FOREIGN KEY(id_devolucion_garantia)
            REFERENCES dbo.msp_garantia_devoluciones(id_devolucion_garantia),
        CONSTRAINT CK_msp_dev_solicitud_clave CHECK(id_solicitud NOT LIKE '%[^0-9a-f]%' AND LEN(id_solicitud)=32),
        CONSTRAINT CK_msp_dev_solicitud_hash CHECK(hash_datos NOT LIKE '%[^0-9a-f]%' AND LEN(hash_datos)=64),
        CONSTRAINT CK_msp_dev_solicitud_usuario CHECK(id_usuario>0)
    );
END;
GO
CREATE OR ALTER TRIGGER dbo.TR_msp_dev_solicitud_integridad
ON dbo.msp_garantia_devolucion_solicitudes
AFTER INSERT,UPDATE,DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted)
        THROW 53921,N'La solicitud de devolucion procesada es inmutable.',1;
    IF EXISTS(
        SELECT 1 FROM inserted i
        JOIN dbo.msp_garantia_devoluciones d ON d.id_devolucion_garantia=i.id_devolucion_garantia
        WHERE d.estado_devolucion<>N'EMITIDA' OR d.id_usuario IS NULL OR d.id_usuario<>i.id_usuario
           OR (d.medio_devolucion=N'TRANSFERENCIA' AND NOT EXISTS(
               SELECT 1 FROM dbo.msp_garantia_devolucion_caja_admin a
               WHERE a.id_devolucion_garantia=d.id_devolucion_garantia
           ))
    ) THROW 53922,N'La solicitud no coincide con una devolucion completa y trazable.',1;
END;
GO
COMMIT TRANSACTION;
GO
