/*
===============================================================================
 MSP - GARANTIA POR TIENDA / BLOQUE 2

 Objetivo
   - Hacer operativa la garantia canonica por contrato-tienda.
   - Mantener id_garantia como ancla tecnica para compatibilidad historica.
   - Registrar en forma explicita id_garantia_tienda en toda operacion nueva.
   - Permitir aplicar la garantia a cualquier local del mismo contrato.
   - Conservar el local destino del cargo para trazabilidad y contabilidad.
===============================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;

IF OBJECT_ID(N'dbo.msp_garantias_tienda', N'U') IS NULL
    THROW 51601, 'Falta instalar garantia por tienda - bloque 1.', 1;
GO

/* -------------------------------------------------------------------------
   1. Claves canonicas y ancla tecnica de compatibilidad
   ------------------------------------------------------------------------- */
IF COL_LENGTH(N'dbo.msp_garantias_tienda', N'id_garantia_operativa') IS NULL
    ALTER TABLE dbo.msp_garantias_tienda ADD id_garantia_operativa INT NULL;

IF COL_LENGTH(N'dbo.msp_garantia_recepciones', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_garantia_recepciones ADD id_garantia_tienda INT NULL;

IF COL_LENGTH(N'dbo.msp_movimientos_garantia', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_movimientos_garantia ADD id_garantia_tienda INT NULL;

IF COL_LENGTH(N'dbo.msp_garantia_devoluciones', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_garantia_devoluciones ADD id_garantia_tienda INT NULL;

IF COL_LENGTH(N'dbo.msp_garantia_reversas', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_garantia_reversas ADD id_garantia_tienda INT NULL;

IF COL_LENGTH(N'dbo.msp_garantia_documento_aplicaciones', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_garantia_documento_aplicaciones ADD id_garantia_tienda INT NULL;

IF COL_LENGTH(N'dbo.msp_acc_asientos_detalle', N'id_garantia_tienda') IS NULL
    ALTER TABLE dbo.msp_acc_asientos_detalle ADD id_garantia_tienda INT NULL;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.TR_msp_garantia_reversas_integridad',N'TR') IS NOT NULL
        DISABLE TRIGGER dbo.TR_msp_garantia_reversas_integridad ON dbo.msp_garantia_reversas;

    UPDATE gt
    SET gt.id_garantia_operativa = x.id_garantia
    FROM dbo.msp_garantias_tienda gt
    CROSS APPLY (
        SELECT TOP (1) g.id_garantia
        FROM dbo.msp_garantias g
        WHERE g.id_garantia_tienda = gt.id_garantia_tienda
        ORDER BY CASE WHEN g.estado_garantia <> 6 THEN 0 ELSE 1 END,
                 g.id_garantia
    ) x
    WHERE gt.id_garantia_operativa IS NULL
       OR NOT EXISTS (
            SELECT 1
            FROM dbo.msp_garantias g
            WHERE g.id_garantia = gt.id_garantia_operativa
              AND g.id_garantia_tienda = gt.id_garantia_tienda
       );

    UPDATE r
    SET r.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_garantia_recepciones r
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = r.id_garantia
    WHERE r.id_garantia_tienda IS NULL;

    UPDATE m
    SET m.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_movimientos_garantia m
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = m.id_garantia
    WHERE m.id_garantia_tienda IS NULL;

    UPDATE d
    SET d.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_garantia_devoluciones d
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = d.id_garantia
    WHERE d.id_garantia_tienda IS NULL;

    UPDATE r
    SET r.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_garantia_reversas r
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = r.id_garantia
    WHERE r.id_garantia_tienda IS NULL;

    IF OBJECT_ID(N'dbo.TR_msp_garantia_reversas_integridad',N'TR') IS NOT NULL
        ENABLE TRIGGER dbo.TR_msp_garantia_reversas_integridad ON dbo.msp_garantia_reversas;

    UPDATE a
    SET a.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_garantia_documento_aplicaciones a
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = a.id_garantia
    WHERE a.id_garantia_tienda IS NULL;

    UPDATE ad
    SET ad.id_garantia_tienda = g.id_garantia_tienda
    FROM dbo.msp_acc_asientos_detalle ad
    INNER JOIN dbo.msp_garantias g ON g.id_garantia = ad.id_garantia
    WHERE ad.id_garantia_tienda IS NULL;

    UPDATE ad
    SET ad.id_local=NULL
    FROM dbo.msp_acc_asientos_detalle ad
    INNER JOIN dbo.msp_acc_asientos a ON a.id_asiento_contable=ad.id_asiento_contable
    INNER JOIN dbo.msp_acc_tipos_movimiento tm ON tm.id_tipo_movimiento=a.id_tipo_movimiento
    LEFT JOIN dbo.msp_movimientos_garantia mg
        ON a.tabla_origen=N'msp_movimientos_garantia' AND mg.id_movimiento_garantia=a.id_origen
    WHERE ad.id_garantia IS NOT NULL
      AND ad.id_local IS NOT NULL
      AND (
            tm.codigo_movimiento IN(N'GARANTIA_RECEPCION',N'GARANTIA_DEVOLUCION')
            OR (tm.codigo_movimiento=N'GARANTIA_APLICACION' AND mg.id_documento_cobro IS NOT NULL
                AND mg.id_cargo_contrato_local IS NULL AND mg.id_cargo_salida IS NULL)
      );

    IF EXISTS (SELECT 1 FROM dbo.msp_garantias_tienda WHERE id_garantia_operativa IS NULL)
        THROW 51602, 'Existe una garantia de tienda sin ancla tecnica.', 1;

    IF EXISTS (SELECT 1 FROM dbo.msp_garantia_recepciones WHERE id_garantia_tienda IS NULL)
       OR EXISTS (SELECT 1 FROM dbo.msp_movimientos_garantia WHERE id_garantia_tienda IS NULL)
       OR EXISTS (SELECT 1 FROM dbo.msp_garantia_devoluciones WHERE id_garantia_tienda IS NULL)
       OR EXISTS (SELECT 1 FROM dbo.msp_garantia_reversas WHERE id_garantia_tienda IS NULL)
       OR EXISTS (SELECT 1 FROM dbo.msp_garantia_documento_aplicaciones WHERE id_garantia_tienda IS NULL)
        THROW 51603, 'Existen operaciones historicas sin garantia de tienda.', 1;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    IF OBJECT_ID(N'dbo.TR_msp_garantia_reversas_integridad',N'TR') IS NOT NULL
        ENABLE TRIGGER dbo.TR_msp_garantia_reversas_integridad ON dbo.msp_garantia_reversas;
    THROW;
END CATCH;
GO

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_gt_garantia_operativa')
    ALTER TABLE dbo.msp_garantias_tienda ADD CONSTRAINT FK_msp_gt_garantia_operativa
        FOREIGN KEY(id_garantia_operativa) REFERENCES dbo.msp_garantias(id_garantia);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_gr_garantia_tienda')
    ALTER TABLE dbo.msp_garantia_recepciones ADD CONSTRAINT FK_msp_gr_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_mg_garantia_tienda')
    ALTER TABLE dbo.msp_movimientos_garantia ADD CONSTRAINT FK_msp_mg_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_gd_garantia_tienda')
    ALTER TABLE dbo.msp_garantia_devoluciones ADD CONSTRAINT FK_msp_gd_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_grev_garantia_tienda')
    ALTER TABLE dbo.msp_garantia_reversas ADD CONSTRAINT FK_msp_grev_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_gda_garantia_tienda')
    ALTER TABLE dbo.msp_garantia_documento_aplicaciones ADD CONSTRAINT FK_msp_gda_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);

IF NOT EXISTS (SELECT 1 FROM sys.foreign_keys WHERE name=N'FK_msp_aad_garantia_tienda')
    ALTER TABLE dbo.msp_acc_asientos_detalle ADD CONSTRAINT FK_msp_aad_garantia_tienda
        FOREIGN KEY(id_garantia_tienda) REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda);
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_recepciones') AND name=N'IX_msp_gr_garantia_tienda_fecha')
    CREATE INDEX IX_msp_gr_garantia_tienda_fecha ON dbo.msp_garantia_recepciones(id_garantia_tienda,fecha_recepcion DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_movimientos_garantia') AND name=N'IX_msp_mg_garantia_tienda_fecha')
    CREATE INDEX IX_msp_mg_garantia_tienda_fecha ON dbo.msp_movimientos_garantia(id_garantia_tienda,fecha_movimiento DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_devoluciones') AND name=N'IX_msp_gd_garantia_tienda_fecha')
    CREATE INDEX IX_msp_gd_garantia_tienda_fecha ON dbo.msp_garantia_devoluciones(id_garantia_tienda,fecha_devolucion DESC);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_documento_aplicaciones') AND name=N'IX_msp_gda_garantia_tienda_fecha')
    CREATE INDEX IX_msp_gda_garantia_tienda_fecha ON dbo.msp_garantia_documento_aplicaciones(id_garantia_tienda,fecha_aplicacion DESC);
GO

/* Mantiene el ancla tecnica cuando se agregan locales al contrato. */
CREATE OR ALTER TRIGGER dbo.TR_msp_garantias_tienda_ancla_operativa
ON dbo.msp_garantias
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE gt
    SET gt.id_garantia_operativa = x.id_garantia,
        gt.fecha_actualizacion = SYSDATETIME()
    FROM dbo.msp_garantias_tienda gt
    INNER JOIN (SELECT DISTINCT id_contrato_arriendo FROM inserted) i
        ON i.id_contrato_arriendo = gt.id_contrato_arriendo
    CROSS APPLY (
        SELECT TOP (1) g.id_garantia
        FROM dbo.msp_garantias g
        WHERE g.id_garantia_tienda = gt.id_garantia_tienda
        ORDER BY CASE WHEN g.estado_garantia <> 6 THEN 0 ELSE 1 END,
                 CASE WHEN g.id_garantia = gt.id_garantia_operativa THEN 0 ELSE 1 END,
                 g.id_garantia
    ) x
    WHERE gt.id_garantia_operativa IS NULL
       OR NOT EXISTS (
            SELECT 1 FROM dbo.msp_garantias actual
            WHERE actual.id_garantia = gt.id_garantia_operativa
              AND actual.id_garantia_tienda = gt.id_garantia_tienda
              AND actual.estado_garantia <> 6
       );
END;
GO

/* -------------------------------------------------------------------------
   2. Integridad por garantia de tienda
   ------------------------------------------------------------------------- */
CREATE OR ALTER TRIGGER dbo.TR_msp_garantia_recepciones_integridad
ON dbo.msp_garantia_recepciones
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        WHERE i.estado_recepcion=N'CONFIRMADA' AND g.estado_garantia=6
    )
        THROW 54001, 'No se puede confirmar una recepcion sobre una garantia anulada.', 1;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        WHERE i.id_garantia_tienda IS NOT NULL
          AND i.id_garantia_tienda<>g.id_garantia_tienda
    )
        THROW 51604, 'La recepcion no coincide con la garantia de tienda.', 1;

    UPDATE r
    SET r.id_garantia_tienda=g.id_garantia_tienda
    FROM dbo.msp_garantia_recepciones r
    INNER JOIN inserted i ON i.id_recepcion_garantia=r.id_recepcion_garantia
    INNER JOIN dbo.msp_garantias g ON g.id_garantia=r.id_garantia
    WHERE r.id_garantia_tienda IS NULL;

    IF EXISTS (
        SELECT 1
        FROM (
            SELECT DISTINCT COALESCE(i.id_garantia_tienda,g.id_garantia_tienda) id_garantia_tienda
            FROM inserted i
            INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        ) a
        INNER JOIN dbo.msp_garantias_tienda gt ON gt.id_garantia_tienda=a.id_garantia_tienda
        CROSS APPLY (
            SELECT ISNULL(SUM(r.monto_recibido),0) total_recibido
            FROM dbo.msp_garantia_recepciones r WITH(UPDLOCK,HOLDLOCK)
            WHERE r.id_garantia_tienda=a.id_garantia_tienda
              AND r.estado_recepcion=N'CONFIRMADA'
        ) x
        WHERE x.total_recibido>gt.monto_pactado+0.009
    )
        THROW 54002, 'La recepcion acumulada supera el monto pactado de la garantia de tienda.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_valida_garantia_cargo
ON dbo.msp_movimientos_garantia
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        WHERE i.id_garantia_tienda IS NOT NULL
          AND i.id_garantia_tienda<>g.id_garantia_tienda
    )
        THROW 51605, 'El movimiento no coincide con la garantia de tienda.', 1;

    UPDATE m
    SET m.id_garantia_tienda=g.id_garantia_tienda
    FROM dbo.msp_movimientos_garantia m
    INNER JOIN inserted i ON i.id_movimiento_garantia=m.id_movimiento_garantia
    INNER JOIN dbo.msp_garantias g ON g.id_garantia=m.id_garantia
    WHERE m.id_garantia_tienda IS NULL;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        INNER JOIN dbo.msp_garantias_tienda gt ON gt.id_garantia_tienda=COALESCE(i.id_garantia_tienda,g.id_garantia_tienda)
        INNER JOIN dbo.msp_cargos_salida cs ON cs.id_cargo_salida=i.id_cargo_salida
        WHERE i.id_cargo_salida IS NOT NULL
          AND cs.id_contrato_arriendo<>gt.id_contrato_arriendo
    )
        THROW 50305, 'La garantia solo puede cubrir cargos de su mismo contrato.', 1;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
        INNER JOIN dbo.msp_garantias_tienda gt ON gt.id_garantia_tienda=COALESCE(i.id_garantia_tienda,g.id_garantia_tienda)
        INNER JOIN dbo.msp_cargos_contrato_local ccl ON ccl.id_cargo_contrato_local=i.id_cargo_contrato_local
        INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
        WHERE i.id_cargo_contrato_local IS NOT NULL
          AND cl.id_contrato_arriendo<>gt.id_contrato_arriendo
    )
        THROW 50305, 'La garantia solo puede cubrir cargos de su mismo contrato.', 1;

    IF EXISTS (
        SELECT 1 FROM inserted i
        WHERE i.id_tipo_movimiento_garantia IN(2,3)
          AND i.id_cargo_salida IS NULL
          AND i.id_cargo_contrato_local IS NULL
    )
        THROW 50306, 'Reserva y liberacion deben referenciar un cargo.', 1;

    IF EXISTS (
        SELECT 1 FROM inserted i
        WHERE i.id_tipo_movimiento_garantia=4
          AND i.id_cargo_salida IS NULL
          AND i.id_cargo_contrato_local IS NULL
          AND i.id_documento_cobro IS NULL
    )
        THROW 50306, 'La aplicacion debe referenciar un cargo o documento.', 1;

    IF EXISTS (SELECT 1 FROM inserted WHERE id_tipo_movimiento_garantia=4 AND fondo_origen NOT IN('D','R'))
        THROW 50307, 'La aplicacion de garantia debe indicar si sale de disponible o reservado.', 1;

    IF EXISTS (SELECT 1 FROM inserted WHERE id_tipo_movimiento_garantia<>4 AND fondo_origen IS NOT NULL)
        THROW 50308, 'Solo la aplicacion de garantia usa fondo_origen.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_garantia_integridad_saldos
ON dbo.msp_movimientos_garantia
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted) AND NOT EXISTS(SELECT 1 FROM inserted)
        THROW 54011, 'Los movimientos de garantia no se eliminan; deben revertirse.', 1;

    DECLARE @afectadas TABLE(id_garantia_tienda INT PRIMARY KEY);
    INSERT @afectadas
    SELECT DISTINCT COALESCE(i.id_garantia_tienda,g.id_garantia_tienda)
    FROM inserted i
    INNER JOIN dbo.msp_garantias g ON g.id_garantia=i.id_garantia
    WHERE COALESCE(i.id_garantia_tienda,g.id_garantia_tienda) IS NOT NULL;
    INSERT @afectadas
    SELECT DISTINCT COALESCE(d.id_garantia_tienda,g.id_garantia_tienda)
    FROM deleted d
    INNER JOIN dbo.msp_garantias g ON g.id_garantia=d.id_garantia
    WHERE COALESCE(d.id_garantia_tienda,g.id_garantia_tienda) IS NOT NULL
      AND NOT EXISTS(
            SELECT 1 FROM @afectadas a
            WHERE a.id_garantia_tienda=COALESCE(d.id_garantia_tienda,g.id_garantia_tienda)
      );

    IF EXISTS (
        SELECT 1
        FROM @afectadas a
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_garantia_tienda=a.id_garantia_tienda
        WHERE r.saldo_disponible < -0.009 OR r.monto_reservado < -0.009
    )
        THROW 54003, 'El movimiento dejaria la garantia de tienda con saldo negativo.', 1;
END;
GO

/* -------------------------------------------------------------------------
   3. Operaciones de cargos usando el fondo comun de la tienda
   ------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE dbo.msp_garantia_tienda_operar_cargo
    @accion NVARCHAR(40),
    @id_cargo_contrato_local INT = NULL,
    @id_cargo_salida INT = NULL,
    @id_garantia_tienda INT = NULL,
    @monto_movimiento DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago INT = NULL,
    @id_movimiento_garantia INT OUTPUT,
    @estado_cargo_nuevo TINYINT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @epsilon DECIMAL(18,6)=0.00001;
    SET @accion=UPPER(LTRIM(RTRIM(ISNULL(@accion,N''))));
    SET @observaciones=NULLIF(LTRIM(RTRIM(ISNULL(@observaciones,N''))),N'');
    SET @id_movimiento_garantia=NULL;
    SET @estado_cargo_nuevo=NULL;

    IF @accion NOT IN(N'RESERVAR',N'LIBERAR_RESERVA',N'APLICAR_DESDE_DISPONIBLE',N'APLICAR_DESDE_RESERVADO')
        THROW 51701, 'La accion de garantia no es valida.', 1;
    IF ISNULL(@id_cargo_contrato_local,0)<=0 AND ISNULL(@id_cargo_salida,0)<=0
        THROW 51702, 'Debes indicar un cargo de referencia.', 1;
    IF ISNULL(@monto_movimiento,0)<=0
        THROW 51703, 'El monto del movimiento no es valido.', 1;
    IF @observaciones IS NOT NULL AND LEN(@observaciones)>500
        THROW 51704, 'Las observaciones no pueden superar 500 caracteres.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE
            @id_contrato_arriendo INT,
            @id_local_destino INT,
            @id_garantia_operativa INT,
            @id_cargo_contrato_local_final INT,
            @id_cargo_salida_final INT,
            @monto_cargo_total DECIMAL(18,2),
            @estado_cargo_actual TINYINT,
            @saldo_disponible DECIMAL(18,2),
            @saldo_reservado DECIMAL(18,2),
            @total_reserva DECIMAL(18,2),
            @total_liberacion DECIMAL(18,2),
            @total_aplicado_disponible DECIMAL(18,2),
            @total_aplicado_reservado DECIMAL(18,2),
            @reserva_neta_cargo DECIMAL(18,2),
            @aplicado_total_cargo DECIMAL(18,2),
            @pendiente_aplicar_cargo DECIMAL(18,2),
            @maximo_permitido DECIMAL(18,2),
            @id_tipo_movimiento INT,
            @fondo_origen CHAR(1),
            @reserva_neta_nueva DECIMAL(18,2),
            @aplicado_total_nuevo DECIMAL(18,2);

        IF ISNULL(@id_cargo_contrato_local,0)>0
        BEGIN
            SELECT
                @id_cargo_contrato_local_final=ccl.id_cargo_contrato_local,
                @id_cargo_salida_final=ccl.id_cargo_salida_legacy,
                @id_contrato_arriendo=cl.id_contrato_arriendo,
                @id_local_destino=cl.id_local,
                @monto_cargo_total=ccl.monto_cargo,
                @estado_cargo_actual=ccl.estado_cargo
            FROM dbo.msp_cargos_contrato_local ccl WITH(UPDLOCK,HOLDLOCK)
            INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
            WHERE ccl.id_cargo_contrato_local=@id_cargo_contrato_local;
        END
        ELSE
        BEGIN
            SELECT
                @id_cargo_contrato_local_final=ccl.id_cargo_contrato_local,
                @id_cargo_salida_final=ccl.id_cargo_salida_legacy,
                @id_contrato_arriendo=cl.id_contrato_arriendo,
                @id_local_destino=cl.id_local,
                @monto_cargo_total=ccl.monto_cargo,
                @estado_cargo_actual=ccl.estado_cargo
            FROM dbo.msp_cargos_contrato_local ccl WITH(UPDLOCK,HOLDLOCK)
            INNER JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=ccl.id_contrato_local
            WHERE ccl.id_cargo_salida_legacy=@id_cargo_salida;

            IF @id_cargo_contrato_local_final IS NULL
            BEGIN
                SELECT
                    @id_cargo_salida_final=cs.id_cargo_salida,
                    @id_contrato_arriendo=cs.id_contrato_arriendo,
                    @id_local_destino=cs.id_local,
                    @monto_cargo_total=cs.monto_cargo,
                    @estado_cargo_actual=cs.estado_cargo
                FROM dbo.msp_cargos_salida cs WITH(UPDLOCK,HOLDLOCK)
                WHERE cs.id_cargo_salida=@id_cargo_salida;
            END;
        END;

        IF ISNULL(@monto_cargo_total,0)<=0 OR ISNULL(@id_contrato_arriendo,0)<=0 OR ISNULL(@id_local_destino,0)<=0
            THROW 51705, 'No fue posible validar el cargo para operar garantia.', 1;
        IF ISNULL(@estado_cargo_actual,0) NOT IN(1,2,3)
            THROW 51706, 'El estado del cargo no permite movimientos de garantia.', 1;

        IF ISNULL(@id_garantia_tienda,0)<=0
        BEGIN
            SELECT @id_garantia_tienda=gt.id_garantia_tienda
            FROM dbo.msp_garantias_tienda gt WITH(UPDLOCK,HOLDLOCK)
            WHERE gt.id_contrato_arriendo=@id_contrato_arriendo AND gt.estado_garantia<>6;
        END;

        SELECT @id_garantia_operativa=gt.id_garantia_operativa
        FROM dbo.msp_garantias_tienda gt WITH(UPDLOCK,HOLDLOCK)
        WHERE gt.id_garantia_tienda=@id_garantia_tienda
          AND gt.id_contrato_arriendo=@id_contrato_arriendo
          AND gt.estado_garantia<>6;

        IF @id_garantia_operativa IS NULL
            THROW 51707, 'No existe una garantia de tienda activa para el contrato del cargo.', 1;

        SELECT @saldo_disponible=r.saldo_disponible,@saldo_reservado=r.monto_reservado
        FROM dbo.msp_vw_garantias_tienda_resumen r
        WHERE r.id_garantia_tienda=@id_garantia_tienda;

        IF @saldo_disponible IS NULL OR @saldo_reservado IS NULL
            THROW 51708, 'No fue posible leer el saldo de la garantia de tienda.', 1;

        SELECT
            @total_reserva=ISNULL(SUM(CASE WHEN tm.codigo_movimiento=N'RESERVA' THEN mg.monto_movimiento ELSE 0 END),0),
            @total_liberacion=ISNULL(SUM(CASE WHEN tm.codigo_movimiento=N'LIBERACION_RESERVA' THEN mg.monto_movimiento ELSE 0 END),0),
            @total_aplicado_disponible=ISNULL(SUM(CASE WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='D' THEN mg.monto_movimiento ELSE 0 END),0),
            @total_aplicado_reservado=ISNULL(SUM(CASE WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='R' THEN mg.monto_movimiento ELSE 0 END),0)
        FROM dbo.msp_movimientos_garantia mg WITH(UPDLOCK,HOLDLOCK)
        INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
        WHERE mg.id_garantia_tienda=@id_garantia_tienda
          AND (
                (@id_cargo_contrato_local_final IS NOT NULL AND mg.id_cargo_contrato_local=@id_cargo_contrato_local_final)
                OR (@id_cargo_salida_final IS NOT NULL AND mg.id_cargo_salida=@id_cargo_salida_final)
              );

        SET @reserva_neta_cargo=@total_reserva-@total_liberacion-@total_aplicado_reservado;
        SET @aplicado_total_cargo=@total_aplicado_disponible+@total_aplicado_reservado;
        SET @pendiente_aplicar_cargo=CASE WHEN @monto_cargo_total-@aplicado_total_cargo>0 THEN @monto_cargo_total-@aplicado_total_cargo ELSE 0 END;
        SET @fondo_origen=NULL;

        IF @accion=N'RESERVAR'
        BEGIN
            SET @maximo_permitido=(SELECT MIN(v) FROM (VALUES
                (@saldo_disponible),
                (CASE WHEN @pendiente_aplicar_cargo-CASE WHEN @reserva_neta_cargo>0 THEN @reserva_neta_cargo ELSE 0 END>0
                      THEN @pendiente_aplicar_cargo-CASE WHEN @reserva_neta_cargo>0 THEN @reserva_neta_cargo ELSE 0 END ELSE 0 END)
            ) x(v));
            SELECT @id_tipo_movimiento=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'RESERVA' AND activo=1;
        END
        ELSE IF @accion=N'LIBERAR_RESERVA'
        BEGIN
            SET @maximo_permitido=CASE WHEN @reserva_neta_cargo>0 THEN @reserva_neta_cargo ELSE 0 END;
            SELECT @id_tipo_movimiento=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'LIBERACION_RESERVA' AND activo=1;
        END
        ELSE IF @accion=N'APLICAR_DESDE_DISPONIBLE'
        BEGIN
            SET @maximo_permitido=(SELECT MIN(v) FROM (VALUES(@saldo_disponible),(@pendiente_aplicar_cargo)) x(v));
            SELECT @id_tipo_movimiento=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'APLICACION_CARGO' AND activo=1;
            SET @fondo_origen='D';
        END
        ELSE
        BEGIN
            SET @maximo_permitido=(SELECT MIN(v) FROM (VALUES(@saldo_reservado),(@reserva_neta_cargo),(@pendiente_aplicar_cargo)) x(v));
            SELECT @id_tipo_movimiento=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'APLICACION_CARGO' AND activo=1;
            SET @fondo_origen='R';
        END;

        IF @id_tipo_movimiento IS NULL
            THROW 51709, 'El catalogo de movimientos de garantia esta incompleto.', 1;
        IF ISNULL(@maximo_permitido,0)<=@epsilon
            THROW 51710, 'No existe saldo utilizable para esta operacion.', 1;
        IF @monto_movimiento-@maximo_permitido>@epsilon
            THROW 51711, 'El monto supera el maximo permitido para esta operacion.', 1;

        INSERT dbo.msp_movimientos_garantia(
            id_garantia,id_garantia_tienda,fecha_movimiento,id_tipo_movimiento_garantia,
            fondo_origen,monto_movimiento,id_cargo_salida,id_cargo_contrato_local,id_pago,observaciones
        ) VALUES(
            @id_garantia_operativa,@id_garantia_tienda,CONVERT(DATE,GETDATE()),@id_tipo_movimiento,
            @fondo_origen,@monto_movimiento,@id_cargo_salida_final,@id_cargo_contrato_local_final,@id_pago,@observaciones
        );
        SET @id_movimiento_garantia=CONVERT(INT,SCOPE_IDENTITY());

        SET @reserva_neta_nueva=@reserva_neta_cargo;
        SET @aplicado_total_nuevo=@aplicado_total_cargo;
        IF @accion=N'RESERVAR' SET @reserva_neta_nueva+=@monto_movimiento;
        ELSE IF @accion=N'LIBERAR_RESERVA' SET @reserva_neta_nueva-=@monto_movimiento;
        ELSE IF @accion=N'APLICAR_DESDE_DISPONIBLE' SET @aplicado_total_nuevo+=@monto_movimiento;
        ELSE BEGIN SET @reserva_neta_nueva-=@monto_movimiento; SET @aplicado_total_nuevo+=@monto_movimiento; END;

        SET @estado_cargo_nuevo=CASE
            WHEN @aplicado_total_nuevo+@epsilon>=@monto_cargo_total THEN 3
            WHEN @reserva_neta_nueva>@epsilon THEN 2
            ELSE 1 END;

        IF @id_cargo_contrato_local_final IS NOT NULL
            UPDATE dbo.msp_cargos_contrato_local
            SET estado_cargo=@estado_cargo_nuevo,
                monto_aplicado_garantia=CASE WHEN @aplicado_total_nuevo>monto_cargo THEN monto_cargo ELSE @aplicado_total_nuevo END
            WHERE id_cargo_contrato_local=@id_cargo_contrato_local_final;

        IF @id_cargo_salida_final IS NOT NULL AND OBJECT_ID(N'dbo.msp_cargos_salida',N'U') IS NOT NULL
            UPDATE dbo.msp_cargos_salida SET estado_cargo=@estado_cargo_nuevo WHERE id_cargo_salida=@id_cargo_salida_final;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

/* -------------------------------------------------------------------------
   4. Aplicacion a documentos, incluidos contratos multilocal
   ------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE dbo.msp_garantia_tienda_aplicar_documento
    @id_documento_cobro INT,
    @id_garantia_tienda INT = NULL,
    @fecha_pago DATE,
    @monto_aplicar DECIMAL(18,2),
    @observaciones NVARCHAR(500) = NULL,
    @id_pago_generado INT OUTPUT,
    @id_movimiento_garantia INT OUTPUT,
    @id_tipo_item_documento INT = NULL,
    @id_usuario INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @id_pago_generado=NULL;
    SET @id_movimiento_garantia=NULL;
    SET @observaciones=NULLIF(LTRIM(RTRIM(ISNULL(@observaciones,N''))),N'');

    IF ISNULL(@id_documento_cobro,0)<=0 OR ISNULL(@monto_aplicar,0)<=0 OR @fecha_pago IS NULL
        THROW 51801, 'Los datos de aplicacion de garantia no son validos.', 1;
    IF @observaciones IS NOT NULL AND LEN(@observaciones)>500
        THROW 51802, 'Las observaciones no pueden superar 500 caracteres.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE
            @id_contrato_documento INT,
            @estado_documento TINYINT,
            @saldo_documento DECIMAL(18,2),
            @id_garantia_operativa INT,
            @saldo_disponible DECIMAL(18,2),
            @saldo_concepto DECIMAL(18,2),
            @detalle_json NVARCHAR(MAX),
            @referencia NVARCHAR(100),
            @id_tipo_aplicacion INT;

        SELECT
            @id_contrato_documento=dc.id_contrato_arriendo,
            @estado_documento=dc.estado_documento,
            @saldo_documento=dc.saldo_pendiente
        FROM dbo.msp_documentos_cobro dc WITH(UPDLOCK,HOLDLOCK)
        WHERE dc.id_documento_cobro=@id_documento_cobro;

        IF @id_contrato_documento IS NULL
            THROW 51803, 'El documento de cobro no existe.', 1;
        IF @estado_documento NOT IN(2,3) OR ISNULL(@saldo_documento,0)<=0
            THROW 51804, 'El documento no tiene deuda disponible para aplicar.', 1;

        IF ISNULL(@id_garantia_tienda,0)<=0
            SELECT @id_garantia_tienda=gt.id_garantia_tienda
            FROM dbo.msp_garantias_tienda gt WITH(UPDLOCK,HOLDLOCK)
            WHERE gt.id_contrato_arriendo=@id_contrato_documento AND gt.estado_garantia<>6;

        SELECT @id_garantia_operativa=gt.id_garantia_operativa
        FROM dbo.msp_garantias_tienda gt WITH(UPDLOCK,HOLDLOCK)
        WHERE gt.id_garantia_tienda=@id_garantia_tienda
          AND gt.id_contrato_arriendo=@id_contrato_documento
          AND gt.estado_garantia<>6;

        IF @id_garantia_operativa IS NULL
            THROW 51805, 'La garantia y el documento deben pertenecer al mismo contrato.', 1;

        SELECT @saldo_disponible=r.saldo_disponible
        FROM dbo.msp_vw_garantias_tienda_resumen r
        WHERE r.id_garantia_tienda=@id_garantia_tienda;

        IF @monto_aplicar>ISNULL(@saldo_disponible,0)+0.009
            THROW 51806, 'El monto excede la garantia efectivamente recibida y disponible.', 1;
        IF @monto_aplicar>@saldo_documento+0.009
            THROW 51807, 'El monto excede el saldo pendiente del documento.', 1;

        SET @detalle_json=NULL;
        IF ISNULL(@id_tipo_item_documento,0)>0
        BEGIN
            SELECT @saldo_concepto=ROUND(CASE WHEN base.total-ISNULL(pag.aplicado,0)>0 THEN base.total-ISNULL(pag.aplicado,0) ELSE 0 END,2)
            FROM (
                SELECT d.id_tipo_item_documento,
                       SUM(d.subtotal)+CASE WHEN t.codigo_item=N'ARRIENDO'
                            THEN CASE WHEN dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios>0
                                      THEN dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios ELSE 0 END
                            ELSE 0 END total
                FROM dbo.msp_documentos_cobro_detalle d
                INNER JOIN dbo.msp_tipo_item_documento t ON t.id_tipo_item_documento=d.id_tipo_item_documento
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=d.id_documento_cobro
                WHERE d.id_documento_cobro=@id_documento_cobro
                  AND d.id_tipo_item_documento=@id_tipo_item_documento
                GROUP BY d.id_tipo_item_documento,t.codigo_item,dc.monto_total,dc.subtotal_arriendo,dc.subtotal_servicios
            ) base
            OUTER APPLY(
                SELECT SUM(pdc.monto_aplicado) aplicado
                FROM dbo.msp_pagos_detalle_concepto pdc
                INNER JOIN dbo.msp_pagos p ON p.id_pago=pdc.id_pago
                WHERE pdc.id_documento_cobro=@id_documento_cobro
                  AND pdc.id_tipo_item_documento=base.id_tipo_item_documento
                  AND p.estado_pago=1
            ) pag;

            IF ISNULL(@saldo_concepto,0)<=0 OR @monto_aplicar>@saldo_concepto+0.009
                THROW 51808, 'El monto excede el saldo pendiente del concepto seleccionado.', 1;
            SET @detalle_json=(SELECT @id_tipo_item_documento id_tipo_item_documento,@monto_aplicar monto FOR JSON PATH);
        END;

        DECLARE @resultado TABLE(
            id_pago_generado INT,
            monto_aplicado_documento DECIMAL(18,2),
            monto_saldo_favor_generado DECIMAL(18,2),
            saldo_favor_tienda DECIMAL(18,2)
        );
        SET @referencia=CONCAT(N'GT-',@id_garantia_tienda,N'-DOC-',@id_documento_cobro);
        INSERT @resultado
        EXEC dbo.msp_registrar_pago_documento
             @id_documento_cobro=@id_documento_cobro,
             @fecha_pago=@fecha_pago,
             @monto_pagado=@monto_aplicar,
             @medio_pago=N'GARANTIA',
             @referencia_pago=@referencia,
             @observaciones=@observaciones,
             @detalle_conceptos_json=@detalle_json;
        SELECT @id_pago_generado=id_pago_generado FROM @resultado;

        SELECT @id_tipo_aplicacion=id_tipo_movimiento_garantia
        FROM dbo.msp_tipos_movimiento_garantia
        WHERE codigo_movimiento=N'APLICACION_CARGO' AND activo=1;
        IF @id_tipo_aplicacion IS NULL
            THROW 51809, 'El catalogo de movimientos de garantia esta incompleto.', 1;

        INSERT dbo.msp_movimientos_garantia(
            id_garantia,id_garantia_tienda,fecha_movimiento,id_tipo_movimiento_garantia,
            monto_movimiento,id_documento_cobro,id_pago,fondo_origen,observaciones
        ) VALUES(
            @id_garantia_operativa,@id_garantia_tienda,@fecha_pago,@id_tipo_aplicacion,
            @monto_aplicar,@id_documento_cobro,@id_pago_generado,'D',@observaciones
        );
        SET @id_movimiento_garantia=CONVERT(INT,SCOPE_IDENTITY());

        INSERT dbo.msp_garantia_documento_aplicaciones(
            id_garantia,id_garantia_tienda,id_documento_cobro,id_pago,id_movimiento_garantia,
            id_tipo_item_documento,fecha_aplicacion,monto_aplicado,observaciones,id_usuario
        ) VALUES(
            @id_garantia_operativa,@id_garantia_tienda,@id_documento_cobro,@id_pago_generado,@id_movimiento_garantia,
            @id_tipo_item_documento,@fecha_pago,@monto_aplicar,@observaciones,@id_usuario
        );

        COMMIT TRANSACTION;
        SELECT @id_pago_generado id_pago_generado,
               @id_movimiento_garantia id_movimiento_garantia,
               @id_documento_cobro id_documento_cobro,
               @id_garantia_tienda id_garantia_tienda,
               @monto_aplicar monto_aplicado;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

/* -------------------------------------------------------------------------
   5. Devolucion y reversa sobre el saldo consolidado
   ------------------------------------------------------------------------- */
CREATE OR ALTER PROCEDURE dbo.msp_garantia_tienda_devolver_operativa
    @id_garantia_tienda INT,
    @id_cuenta_tesoreria INT,
    @fecha_devolucion DATE,
    @monto_devolucion DECIMAL(18,2),
    @medio_devolucion NVARCHAR(20),
    @beneficiario NVARCHAR(200),
    @rut_beneficiario NVARCHAR(20)=NULL,
    @banco_destino NVARCHAR(120)=NULL,
    @cuenta_destino NVARCHAR(100)=NULL,
    @referencia_transferencia NVARCHAR(200)=NULL,
    @numero_cheque NVARCHAR(80)=NULL,
    @fecha_cheque DATE=NULL,
    @observaciones NVARCHAR(500)=NULL,
    @id_usuario INT=NULL,
    @motivo_autorizacion NVARCHAR(500)=NULL,
    @id_usuario_autoriza INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @medio_devolucion=UPPER(LTRIM(RTRIM(ISNULL(@medio_devolucion,N''))));
    SET @motivo_autorizacion=NULLIF(LTRIM(RTRIM(ISNULL(@motivo_autorizacion,N''))),N'');

    IF @medio_devolucion NOT IN(N'EFECTIVO',N'TRANSFERENCIA')
        THROW 51901, 'La devolucion debe ser en efectivo o transferencia.', 1;
    IF NULLIF(LTRIM(RTRIM(@beneficiario)),N'') IS NULL
        THROW 51902, 'El beneficiario es obligatorio.', 1;
    IF ISNULL(@monto_devolucion,0)<=0
        THROW 51903, 'El monto debe ser mayor a cero.', 1;
    IF @motivo_autorizacion IS NULL OR @id_usuario_autoriza IS NULL
        THROW 51904, 'El motivo y usuario autorizador son obligatorios.', 1;
    IF @medio_devolucion=N'TRANSFERENCIA' AND (
        NULLIF(LTRIM(RTRIM(@referencia_transferencia)),N'') IS NULL
        OR NULLIF(LTRIM(RTRIM(@banco_destino)),N'') IS NULL
        OR NULLIF(LTRIM(RTRIM(@cuenta_destino)),N'') IS NULL
    )
        THROW 51905, 'La transferencia requiere banco, cuenta destino y referencia.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE
            @id_garantia_operativa INT,
            @tipo_cuenta NVARCHAR(20),
            @saldo_tesoreria DECIMAL(18,2),
            @saldo_disponible DECIMAL(18,2),
            @monto_reservado DECIMAL(18,2),
            @id_tipo_devolucion INT,
            @id_movimiento INT,
            @id_devolucion INT;

        SELECT @id_garantia_operativa=gt.id_garantia_operativa
        FROM dbo.msp_garantias_tienda gt WITH(UPDLOCK,HOLDLOCK)
        WHERE gt.id_garantia_tienda=@id_garantia_tienda AND gt.estado_garantia<>6;
        IF @id_garantia_operativa IS NULL
            THROW 51906, 'La garantia de tienda no existe o esta anulada.', 1;

        SELECT @tipo_cuenta=tipo_cuenta
        FROM dbo.msp_tesoreria_cuentas WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND activo=1;
        IF (@medio_devolucion=N'EFECTIVO' AND @tipo_cuenta<>N'CAJA')
           OR (@medio_devolucion=N'TRANSFERENCIA' AND @tipo_cuenta<>N'BANCO')
           OR @tipo_cuenta IS NULL
            THROW 51907, 'La cuenta de salida no corresponde al medio de devolucion.', 1;

        IF @tipo_cuenta=N'CAJA' AND EXISTS(
            SELECT 1 FROM dbo.msp_tesoreria_cierres_caja
            WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_cierre>=@fecha_devolucion
        ) THROW 51908, 'La caja ya esta cerrada para la fecha indicada.', 1;
        IF @tipo_cuenta=N'BANCO' AND EXISTS(
            SELECT 1 FROM dbo.msp_tesoreria_conciliaciones
            WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria AND fecha_hasta>=@fecha_devolucion
        ) THROW 51909, 'La cuenta bancaria ya fue conciliada para la fecha indicada.', 1;

        SELECT @saldo_tesoreria=CAST(ISNULL(SUM(CASE
            WHEN estado_movimiento=N'VIGENTE' AND naturaleza='E' THEN monto
            WHEN estado_movimiento=N'VIGENTE' AND naturaleza='S' THEN -monto
            ELSE 0 END),0) AS DECIMAL(18,2))
        FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK)
        WHERE id_cuenta_tesoreria=@id_cuenta_tesoreria;
        IF @monto_devolucion>@saldo_tesoreria
            THROW 51910, 'La devolucion supera el saldo disponible de la cuenta de origen.', 1;

        SELECT @saldo_disponible=saldo_disponible,@monto_reservado=monto_reservado
        FROM dbo.msp_vw_garantias_tienda_resumen
        WHERE id_garantia_tienda=@id_garantia_tienda;
        IF ISNULL(@monto_reservado,0)>0.009
            THROW 51911, 'La garantia mantiene fondos reservados; liberelos o apliquelos antes de devolver.', 1;
        IF @monto_devolucion>ISNULL(@saldo_disponible,0)+0.009
            THROW 51912, 'La devolucion supera la garantia efectivamente recibida y disponible.', 1;

        SELECT @id_tipo_devolucion=id_tipo_movimiento_garantia
        FROM dbo.msp_tipos_movimiento_garantia
        WHERE codigo_movimiento=N'DEVOLUCION' AND activo=1;
        IF @id_tipo_devolucion IS NULL
            THROW 51913, 'El catalogo de movimientos de garantia esta incompleto.', 1;

        INSERT dbo.msp_movimientos_garantia(
            id_garantia,id_garantia_tienda,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,
            observaciones,motivo_autorizacion,id_usuario_solicita,id_usuario_autoriza
        ) VALUES(
            @id_garantia_operativa,@id_garantia_tienda,@fecha_devolucion,@id_tipo_devolucion,@monto_devolucion,
            @observaciones,@motivo_autorizacion,@id_usuario,@id_usuario_autoriza
        );
        SET @id_movimiento=CONVERT(INT,SCOPE_IDENTITY());

        INSERT dbo.msp_garantia_devoluciones(
            id_garantia,id_garantia_tienda,id_movimiento_garantia,id_cuenta_tesoreria,fecha_devolucion,
            monto_devolucion,medio_devolucion,beneficiario,rut_beneficiario,banco_destino,cuenta_destino,
            referencia_transferencia,numero_cheque,fecha_cheque,observaciones,id_usuario,
            motivo_autorizacion,id_usuario_autoriza
        ) VALUES(
            @id_garantia_operativa,@id_garantia_tienda,@id_movimiento,@id_cuenta_tesoreria,@fecha_devolucion,
            @monto_devolucion,@medio_devolucion,LTRIM(RTRIM(@beneficiario)),NULLIF(LTRIM(RTRIM(@rut_beneficiario)),N''),
            CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@banco_destino)),N'') END,
            CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@cuenta_destino)),N'') END,
            CASE WHEN @medio_devolucion=N'TRANSFERENCIA' THEN NULLIF(LTRIM(RTRIM(@referencia_transferencia)),N'') END,
            NULL,NULL,@observaciones,@id_usuario,@motivo_autorizacion,@id_usuario_autoriza
        );
        SET @id_devolucion=CONVERT(INT,SCOPE_IDENTITY());

        INSERT dbo.msp_tesoreria_movimientos(
            id_cuenta_tesoreria,fecha_movimiento,tipo_movimiento,naturaleza,monto,medio_pago,
            referencia,id_movimiento_garantia,id_devolucion_garantia,observaciones,id_usuario
        ) VALUES(
            @id_cuenta_tesoreria,@fecha_devolucion,N'DEVOLUCION_GARANTIA','S',@monto_devolucion,@medio_devolucion,
            @referencia_transferencia,@id_movimiento,@id_devolucion,@motivo_autorizacion,@id_usuario
        );

        COMMIT TRANSACTION;
        SELECT @id_devolucion id_devolucion_garantia,
               @id_movimiento id_movimiento_garantia,
               @saldo_tesoreria-@monto_devolucion saldo_origen_restante;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_tienda_revertir_operacion
    @tipo_origen NVARCHAR(20),
    @id_origen INT,
    @fecha_reversa DATE,
    @motivo NVARCHAR(500),
    @id_usuario INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @tipo_origen=UPPER(LTRIM(RTRIM(ISNULL(@tipo_origen,N''))));
    SET @motivo=NULLIF(LTRIM(RTRIM(ISNULL(@motivo,N''))),N'');
    IF @tipo_origen NOT IN(N'RECEPCION',N'DEVOLUCION',N'APLICACION')
       OR ISNULL(@id_origen,0)<=0 OR @fecha_reversa IS NULL OR @motivo IS NULL
        THROW 52001, 'Datos de reversa incompletos.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;
        DECLARE
            @id_garantia INT,@id_garantia_tienda INT,@monto DECIMAL(18,2),@id_tm INT,@id_mov INT,
            @id_pago INT,@id_ccl INT,@id_cs INT,@fecha DATE,@cuenta INT,@tipo_cuenta NVARCHAR(20),
            @consumido DECIMAL(18,2),@otros_recibidos DECIMAL(18,2),@id_ajuste INT,@id_reversa INT;

        IF @tipo_origen=N'RECEPCION'
        BEGIN
            SELECT @id_garantia=id_garantia,@id_garantia_tienda=id_garantia_tienda,
                   @monto=monto_recibido,@fecha=fecha_recepcion
            FROM dbo.msp_garantia_recepciones WITH(UPDLOCK,HOLDLOCK)
            WHERE id_recepcion_garantia=@id_origen AND estado_recepcion=N'CONFIRMADA';
            SELECT @id_tm=id_movimiento_tesoreria,@cuenta=id_cuenta_tesoreria
            FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK)
            WHERE id_recepcion_garantia=@id_origen AND estado_movimiento=N'VIGENTE';
            IF @id_garantia_tienda IS NULL
                THROW 52002, 'La recepcion no existe o ya fue anulada.', 1;

            SELECT @tipo_cuenta=tipo_cuenta FROM dbo.msp_tesoreria_cuentas WHERE id_cuenta_tesoreria=@cuenta;
            IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_movimientos WHERE id_movimiento_tesoreria=@id_tm AND conciliado=1)
               OR (@tipo_cuenta=N'CAJA' AND EXISTS(
                    SELECT 1 FROM dbo.msp_tesoreria_cierres_caja
                    WHERE id_cuenta_tesoreria=@cuenta AND fecha_cierre>=@fecha
               ))
                THROW 52003, 'No se puede revertir un movimiento conciliado o perteneciente a una caja cerrada.', 1;

            SELECT @otros_recibidos=ISNULL(SUM(monto_recibido),0)
            FROM dbo.msp_garantia_recepciones
            WHERE id_garantia_tienda=@id_garantia_tienda
              AND estado_recepcion=N'CONFIRMADA'
              AND id_recepcion_garantia<>@id_origen;
            SELECT @consumido=ISNULL(SUM(CASE WHEN t.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION') THEN m.monto_movimiento ELSE 0 END),0)
            FROM dbo.msp_movimientos_garantia m
            INNER JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
            WHERE m.id_garantia_tienda=@id_garantia_tienda;
            IF @otros_recibidos<@consumido-0.009
                THROW 52004, 'No se puede anular: parte de esta recepcion ya fue aplicada o devuelta.', 1;

            UPDATE dbo.msp_garantia_recepciones
            SET estado_recepcion=N'ANULADA',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo)
            WHERE id_recepcion_garantia=@id_origen;
            UPDATE dbo.msp_tesoreria_movimientos
            SET estado_movimiento=N'ANULADO',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo)
            WHERE id_movimiento_tesoreria=@id_tm;
        END
        ELSE IF @tipo_origen=N'DEVOLUCION'
        BEGIN
            SELECT @id_garantia=id_garantia,@id_garantia_tienda=id_garantia_tienda,@monto=monto_devolucion,
                   @fecha=fecha_devolucion,@id_mov=id_movimiento_garantia,@cuenta=id_cuenta_tesoreria
            FROM dbo.msp_garantia_devoluciones WITH(UPDLOCK,HOLDLOCK)
            WHERE id_devolucion_garantia=@id_origen AND estado_devolucion=N'EMITIDA';
            SELECT @id_tm=id_movimiento_tesoreria
            FROM dbo.msp_tesoreria_movimientos WITH(UPDLOCK,HOLDLOCK)
            WHERE id_devolucion_garantia=@id_origen AND estado_movimiento=N'VIGENTE';
            IF @id_garantia_tienda IS NULL
                THROW 52005, 'La devolucion no existe o ya fue anulada.', 1;
            IF EXISTS(SELECT 1 FROM dbo.msp_tesoreria_movimientos WHERE id_movimiento_tesoreria=@id_tm AND conciliado=1)
                THROW 52003, 'No se puede revertir un movimiento conciliado.', 1;

            SELECT @id_ajuste=id_tipo_movimiento_garantia
            FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'AJUSTE_POSITIVO';
            UPDATE dbo.msp_garantia_devoluciones
            SET estado_devolucion=N'ANULADA',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo)
            WHERE id_devolucion_garantia=@id_origen;
            UPDATE dbo.msp_tesoreria_movimientos
            SET estado_movimiento=N'ANULADO',observaciones=CONCAT(ISNULL(observaciones,N''),N' | Reversa: ',@motivo)
            WHERE id_movimiento_tesoreria=@id_tm;
            INSERT dbo.msp_movimientos_garantia(
                id_garantia,id_garantia_tienda,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,
                observaciones,id_usuario_solicita,id_usuario_autoriza
            ) VALUES(
                @id_garantia,@id_garantia_tienda,@fecha_reversa,@id_ajuste,@monto,
                CONCAT(N'Reversa financiera compensatoria de devolucion #',@id_origen,N': ',@motivo),@id_usuario,@id_usuario
            );
        END
        ELSE
        BEGIN
            SELECT @id_garantia=id_garantia,@id_garantia_tienda=id_garantia_tienda,@monto=monto_movimiento,
                   @fecha=fecha_movimiento,@id_mov=id_movimiento_garantia,@id_pago=id_pago,
                   @id_ccl=id_cargo_contrato_local,@id_cs=id_cargo_salida
            FROM dbo.msp_movimientos_garantia WITH(UPDLOCK,HOLDLOCK)
            WHERE id_movimiento_garantia=@id_origen
              AND id_tipo_movimiento_garantia=(
                    SELECT id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia
                    WHERE codigo_movimiento=N'APLICACION_CARGO'
              );
            IF @id_garantia_tienda IS NULL
                THROW 52006, 'La aplicacion no existe o ya fue revertida.', 1;
            IF @id_pago IS NOT NULL
                EXEC dbo.msp_anular_pago_documento @id_pago=@id_pago,@fecha_anulacion=@fecha_reversa,@motivo_anulacion=@motivo;

            SELECT @id_ajuste=id_tipo_movimiento_garantia
            FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'AJUSTE_POSITIVO';
            INSERT dbo.msp_movimientos_garantia(
                id_garantia,id_garantia_tienda,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,
                id_documento_cobro,id_pago,observaciones,id_usuario_solicita,id_usuario_autoriza
            ) VALUES(
                @id_garantia,@id_garantia_tienda,@fecha_reversa,@id_ajuste,@monto,NULL,@id_pago,
                CONCAT(N'Reversa financiera compensatoria de aplicacion #',@id_origen,N': ',@motivo),@id_usuario,@id_usuario
            );

            IF @id_ccl IS NOT NULL
                UPDATE c
                SET monto_aplicado_garantia=ISNULL(x.aplicado,0),
                    estado_cargo=CASE WHEN ISNULL(x.aplicado,0)>=c.monto_cargo THEN 3 WHEN ISNULL(x.reservado,0)>0 THEN 2 ELSE 1 END
                FROM dbo.msp_cargos_contrato_local c
                OUTER APPLY(
                    SELECT
                        SUM(CASE WHEN t.codigo_movimiento=N'APLICACION_CARGO' THEN m.monto_movimiento ELSE 0 END) aplicado,
                        SUM(CASE WHEN t.codigo_movimiento=N'RESERVA' THEN m.monto_movimiento
                                 WHEN t.codigo_movimiento=N'LIBERACION_RESERVA' THEN -m.monto_movimiento ELSE 0 END) reservado
                    FROM dbo.msp_movimientos_garantia m
                    INNER JOIN dbo.msp_tipos_movimiento_garantia t ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
                    WHERE m.id_cargo_contrato_local=c.id_cargo_contrato_local
                      AND m.id_movimiento_garantia<>@id_mov
                ) x
                WHERE c.id_cargo_contrato_local=@id_ccl;
        END;

        INSERT dbo.msp_garantia_reversas(
            id_garantia,id_garantia_tienda,tipo_origen,id_origen,fecha_reversa,monto_reversa,motivo,id_usuario
        ) VALUES(
            @id_garantia,@id_garantia_tienda,@tipo_origen,@id_origen,@fecha_reversa,@monto,@motivo,@id_usuario
        );
        SET @id_reversa=CONVERT(INT,SCOPE_IDENTITY());
        COMMIT TRANSACTION;
        SELECT @id_reversa id_reversa_garantia;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

/* -------------------------------------------------------------------------
   6. Trazabilidad contable: tienda/arrendatario como eje; local solo destino
   ------------------------------------------------------------------------- */
CREATE OR ALTER TRIGGER dbo.TR_msp_acc_detalle_garantia_tienda
ON dbo.msp_acc_asientos_detalle
AFTER INSERT, UPDATE
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE d
    SET d.id_garantia_tienda=g.id_garantia_tienda,
        d.id_local=CASE
            WHEN tm.codigo_movimiento IN(N'GARANTIA_RECEPCION',N'GARANTIA_DEVOLUCION') THEN NULL
            WHEN tm.codigo_movimiento=N'GARANTIA_APLICACION'
             AND mg.id_documento_cobro IS NOT NULL
             AND mg.id_cargo_contrato_local IS NULL
             AND mg.id_cargo_salida IS NULL THEN NULL
            ELSE d.id_local
        END
    FROM dbo.msp_acc_asientos_detalle d
    INNER JOIN inserted i ON i.id_asiento_detalle=d.id_asiento_detalle
    INNER JOIN dbo.msp_garantias g ON g.id_garantia=d.id_garantia
    INNER JOIN dbo.msp_acc_asientos a ON a.id_asiento_contable=d.id_asiento_contable
    INNER JOIN dbo.msp_acc_tipos_movimiento tm ON tm.id_tipo_movimiento=a.id_tipo_movimiento
    LEFT JOIN dbo.msp_movimientos_garantia mg
        ON a.tabla_origen=N'msp_movimientos_garantia'
       AND mg.id_movimiento_garantia=a.id_origen
    WHERE d.id_garantia IS NOT NULL
      AND (d.id_garantia_tienda IS NULL OR d.id_garantia_tienda<>g.id_garantia_tienda
           OR (tm.codigo_movimiento IN(N'GARANTIA_RECEPCION',N'GARANTIA_DEVOLUCION') AND d.id_local IS NOT NULL)
           OR (tm.codigo_movimiento=N'GARANTIA_APLICACION' AND mg.id_documento_cobro IS NOT NULL
               AND mg.id_cargo_contrato_local IS NULL AND mg.id_cargo_salida IS NULL AND d.id_local IS NOT NULL));
END;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantia_tienda_historial_integral
AS
SELECT
    r.id_garantia_tienda,
    r.id_garantia,
    r.id_recepcion_garantia AS id_origen,
    r.fecha_recepcion AS fecha,
    N'RECEPCION' AS tipo,
    N'Garantia recibida' AS concepto,
    r.monto_recibido AS monto,
    N'+' AS signo,
    r.estado_recepcion AS estado,
    CAST(NULL AS INT) AS id_local_destino,
    CAST(NULL AS INT) AS id_documento,
    CAST(NULL AS INT) AS id_cargo,
    r.medio_recepcion AS medio,
    tc.nombre_cuenta AS cuenta,
    COALESCE(r.referencia,r.numero_cheque) AS referencia,
    r.observaciones
FROM dbo.msp_garantia_recepciones r
LEFT JOIN dbo.msp_tesoreria_movimientos tes
    ON tes.id_recepcion_garantia=r.id_recepcion_garantia AND tes.estado_movimiento=N'VIGENTE'
LEFT JOIN dbo.msp_tesoreria_cuentas tc
    ON tc.id_cuenta_tesoreria=tes.id_cuenta_tesoreria
UNION ALL
SELECT
    m.id_garantia_tienda,
    m.id_garantia,
    m.id_movimiento_garantia,
    m.fecha_movimiento,
    t.codigo_movimiento,
    t.nombre_movimiento,
    m.monto_movimiento,
    CASE WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN N'+' ELSE N'-' END,
    N'VIGENTE',
    COALESCE(cl.id_local,cs.id_local),
    m.id_documento_cobro,
    COALESCE(m.id_cargo_contrato_local,m.id_cargo_salida),
    CAST(NULL AS NVARCHAR(20)),
    CAST(NULL AS NVARCHAR(150)),
    CASE WHEN m.id_pago IS NOT NULL THEN CONCAT(N'Pago #',m.id_pago) END,
    m.observaciones
FROM dbo.msp_movimientos_garantia m
INNER JOIN dbo.msp_tipos_movimiento_garantia t
    ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
LEFT JOIN dbo.msp_cargos_contrato_local ccl
    ON ccl.id_cargo_contrato_local=m.id_cargo_contrato_local
LEFT JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local=ccl.id_contrato_local
LEFT JOIN dbo.msp_cargos_salida cs
    ON cs.id_cargo_salida=m.id_cargo_salida;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_tienda_control_integral
AS
SELECT
    r.id_garantia_tienda,
    gt.id_garantia_operativa AS id_garantia,
    r.id_contrato_arriendo,
    r.id_tienda,
    r.id_arrendatario,
    r.nombre_locatario,
    r.rut,
    r.nombre_comercial,
    r.locales AS cdo_local,
    r.locales,
    r.fecha_constitucion,
    r.estado_garantia,
    r.monto_pactado,
    r.monto_recibido,
    r.monto_pendiente_recepcion,
    r.monto_reservado,
    r.monto_aplicado,
    r.total_devuelto AS monto_devuelto,
    r.monto_disponible,
    r.estado_recepcion,
    CASE
        WHEN r.saldo_disponible<0 OR r.monto_reservado<0 THEN N'INCONSISTENCIA_SALDO'
        WHEN r.monto_recibido>r.monto_pactado THEN N'RECEPCION_EXCEDIDA'
        WHEN r.monto_pactado=0 THEN N'SIN_MONTO'
        WHEN r.monto_recibido=0 THEN N'NO_RECIBIDA'
        WHEN r.monto_recibido<r.monto_pactado THEN N'RECEPCION_PARCIAL'
        WHEN r.estado_conciliacion=N'DIFERENCIA_PACTADO' THEN N'DIFERENCIA_PACTADO'
        WHEN r.estado_conciliacion=N'REVISION_PENDIENTE' THEN N'REVISION_PENDIENTE'
        WHEN r.monto_reservado>0 THEN N'SALDO_RESERVADO'
        ELSE N'OK'
    END AS alerta_codigo,
    CASE
        WHEN r.saldo_disponible<0 OR r.monto_reservado<0 THEN N'El saldo neto de la garantia por tienda es negativo.'
        WHEN r.monto_recibido>r.monto_pactado THEN N'El monto recibido supera lo pactado para la tienda.'
        WHEN r.monto_pactado=0 THEN N'Garantia por tienda configurada sin monto pactado.'
        WHEN r.monto_recibido=0 THEN N'Existe monto pactado sin recepcion confirmada.'
        WHEN r.monto_recibido<r.monto_pactado THEN N'La recepcion de la garantia por tienda esta incompleta.'
        WHEN r.estado_conciliacion=N'DIFERENCIA_PACTADO' THEN N'El monto pactado consolidado difiere de sus registros de origen.'
        WHEN r.estado_conciliacion=N'REVISION_PENDIENTE' THEN COALESCE(r.motivo_revision,N'La garantia requiere revision manual.')
        WHEN r.monto_reservado>0 THEN N'Existe saldo reservado por cargos.'
        ELSE N'Sin alertas.'
    END AS alerta_descripcion,
    CASE
        WHEN r.saldo_disponible<0 OR r.monto_reservado<0 OR r.monto_recibido>r.monto_pactado THEN 3
        WHEN r.monto_recibido<r.monto_pactado AND r.monto_pactado>0 THEN 2
        WHEN r.estado_conciliacion IN(N'DIFERENCIA_PACTADO',N'REVISION_PENDIENTE') THEN 2
        WHEN r.monto_pactado=0 OR r.monto_reservado>0 THEN 1
        ELSE 0
    END AS alerta_nivel
FROM dbo.msp_vw_garantias_tienda_resumen r
INNER JOIN dbo.msp_garantias_tienda gt
    ON gt.id_garantia_tienda=r.id_garantia_tienda;
GO

PRINT N'Garantia por tienda - bloque 2 instalado correctamente.';
PRINT N'Las operaciones nuevas usan saldo consolidado y conservan el local destino del cargo.';
GO
