/*
===========================================================================
 PORTALGP / MSP - Seguridad financiera etapa 4, puntos 1 a 3
 - Pagos y aplicaciones
 - Garantias, recepciones, aplicaciones, devoluciones y reversas
 - Saldos a favor y asignacion por periodo
 Incremental, idempotente y sin correccion destructiva de datos historicos.
===========================================================================
*/
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

/* Corrige el algoritmo instalado sin borrar ni sobrescribir historia. */
DECLARE @proc NVARCHAR(MAX)=OBJECT_DEFINITION(OBJECT_ID(N'dbo.msp_garantia_revertir_operacion'));
IF @proc IS NULL THROW 54020, 'No existe msp_garantia_revertir_operacion.', 1;
IF @proc NOT LIKE N'%Reversa financiera compensatoria%'
BEGIN
    DECLARE @oldDev NVARCHAR(MAX)=N'UPDATE dbo.msp_movimientos_garantia SET id_tipo_movimiento_garantia=@id_ajuste,observaciones=CONCAT(ISNULL(observaciones,N''''),N'' | Reversa de devolución: '',@motivo) WHERE id_movimiento_garantia=@id_mov;';
    DECLARE @newDev NVARCHAR(MAX)=N'INSERT dbo.msp_movimientos_garantia(id_garantia,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,observaciones,id_usuario_solicita,id_usuario_autoriza) VALUES(@id_garantia,@fecha_reversa,@id_ajuste,@monto,CONCAT(N''Reversa financiera compensatoria de devolución #'',@id_origen,N'': '',@motivo),@id_usuario,@id_usuario);';
    DECLARE @oldApp NVARCHAR(MAX)=N'UPDATE dbo.msp_movimientos_garantia SET id_tipo_movimiento_garantia=@id_ajuste,observaciones=CONCAT(ISNULL(observaciones,N''''),N'' | Reversa de aplicación: '',@motivo) WHERE id_movimiento_garantia=@id_mov;';
    DECLARE @newApp NVARCHAR(MAX)=N'INSERT dbo.msp_movimientos_garantia(id_garantia,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,id_documento_cobro,id_pago,observaciones,id_usuario_solicita,id_usuario_autoriza) VALUES(@id_garantia,@fecha_reversa,@id_ajuste,@monto,NULL,@id_pago,CONCAT(N''Reversa financiera compensatoria de aplicación #'',@id_origen,N'': '',@motivo),@id_usuario,@id_usuario);';
    IF CHARINDEX(@oldDev,@proc)=0 OR CHARINDEX(@oldApp,@proc)=0
        THROW 54021, 'La version instalada de la reversa no coincide con la version auditable.', 1;
    SET @proc=REPLACE(@proc,@oldDev,@newDev);
    SET @proc=REPLACE(@proc,@oldApp,@newApp);
    SET @proc=REPLACE(@proc,N'WHERE m.id_cargo_contrato_local=c.id_cargo_contrato_local)x WHERE c.id_cargo_contrato_local=@id_ccl;',N'WHERE m.id_cargo_contrato_local=c.id_cargo_contrato_local AND m.id_movimiento_garantia<>@id_mov)x WHERE c.id_cargo_contrato_local=@id_ccl;');
    SET @proc=STUFF(@proc,CHARINDEX(N'CREATE OR ALTER PROCEDURE',@proc),LEN(N'CREATE OR ALTER PROCEDURE'),N'ALTER PROCEDURE');
    EXEC sys.sp_executesql @proc;
END;
GO

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @tipoAjuste INT=(SELECT id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'AJUSTE_POSITIVO');
    DECLARE @tipoDevolucion INT=(SELECT id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'DEVOLUCION');
    IF @tipoAjuste IS NULL OR @tipoDevolucion IS NULL THROW 54022, 'Catalogo de movimientos de garantia incompleto.', 1;

    INSERT dbo.msp_movimientos_garantia(id_garantia,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,observaciones,id_usuario_solicita,id_usuario_autoriza)
    SELECT r.id_garantia,r.fecha_reversa,@tipoAjuste,r.monto_reversa,
           CONCAT(N'[REVERSA_GARANTIA:',r.id_reversa_garantia,N'] Compensacion historica de devolucion.'),r.id_usuario,r.id_usuario
    FROM dbo.msp_garantia_reversas r
    INNER JOIN dbo.msp_garantia_devoluciones d ON d.id_devolucion_garantia=r.id_origen AND r.tipo_origen=N'DEVOLUCION'
    INNER JOIN dbo.msp_movimientos_garantia m ON m.id_movimiento_garantia=d.id_movimiento_garantia
    WHERE m.id_tipo_movimiento_garantia=@tipoAjuste
      AND NOT EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia x WHERE x.observaciones LIKE CONCAT(N'%[[]REVERSA_GARANTIA:',r.id_reversa_garantia,N']%'));

    UPDATE m SET id_tipo_movimiento_garantia=@tipoDevolucion
    FROM dbo.msp_movimientos_garantia m
    INNER JOIN dbo.msp_garantia_devoluciones d ON d.id_movimiento_garantia=m.id_movimiento_garantia AND d.estado_devolucion=N'ANULADA'
    INNER JOIN dbo.msp_garantia_reversas r ON r.tipo_origen=N'DEVOLUCION' AND r.id_origen=d.id_devolucion_garantia
    WHERE m.id_tipo_movimiento_garantia=@tipoAjuste;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

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
        FROM (SELECT DISTINCT id_garantia FROM inserted) a
        INNER JOIN dbo.msp_garantias g ON g.id_garantia=a.id_garantia
        CROSS APPLY (
            SELECT ISNULL(SUM(r.monto_recibido),0) total_recibido
            FROM dbo.msp_garantia_recepciones r WITH (UPDLOCK,HOLDLOCK)
            WHERE r.id_garantia=a.id_garantia AND r.estado_recepcion=N'CONFIRMADA'
        ) x
        WHERE x.total_recibido>g.monto_inicial+0.009
    )
        THROW 54002, 'La recepcion acumulada supera el monto pactado de la garantia.', 1;
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
    DECLARE @afectadas TABLE(id_garantia INT PRIMARY KEY);
    INSERT @afectadas SELECT DISTINCT id_garantia FROM inserted WHERE id_garantia IS NOT NULL;
    INSERT @afectadas
    SELECT DISTINCT d.id_garantia FROM deleted d
    WHERE d.id_garantia IS NOT NULL AND NOT EXISTS(SELECT 1 FROM @afectadas a WHERE a.id_garantia=d.id_garantia);

    IF EXISTS (
        SELECT 1
        FROM @afectadas a
        INNER JOIN dbo.msp_vw_garantias_resumen r ON r.id_garantia=a.id_garantia
        WHERE r.saldo_disponible < -0.009 OR r.saldo_reservado < -0.009
    )
        THROW 54003, 'El movimiento dejaria la garantia con saldo disponible o reservado negativo.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_garantia_reversas_integridad
ON dbo.msp_garantia_reversas
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted)
        THROW 54004, 'Las reversas de garantia son inmutables.', 1;

    IF EXISTS (
        SELECT 1 FROM inserted i
        LEFT JOIN dbo.msp_garantia_recepciones r
          ON i.tipo_origen=N'RECEPCION' AND r.id_recepcion_garantia=i.id_origen
        LEFT JOIN dbo.msp_garantia_devoluciones d
          ON i.tipo_origen=N'DEVOLUCION' AND d.id_devolucion_garantia=i.id_origen
        LEFT JOIN dbo.msp_movimientos_garantia dm ON dm.id_movimiento_garantia=d.id_movimiento_garantia
        LEFT JOIN dbo.msp_tipos_movimiento_garantia dt ON dt.id_tipo_movimiento_garantia=dm.id_tipo_movimiento_garantia
        LEFT JOIN dbo.msp_movimientos_garantia m
          ON i.tipo_origen=N'APLICACION' AND m.id_movimiento_garantia=i.id_origen
        LEFT JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
        WHERE (i.tipo_origen=N'RECEPCION' AND (r.id_recepcion_garantia IS NULL OR r.id_garantia<>i.id_garantia OR r.estado_recepcion<>N'ANULADA' OR ABS(r.monto_recibido-i.monto_reversa)>0.009))
           OR (i.tipo_origen=N'DEVOLUCION' AND (d.id_devolucion_garantia IS NULL OR d.id_garantia<>i.id_garantia OR d.estado_devolucion<>N'ANULADA' OR ABS(d.monto_devolucion-i.monto_reversa)>0.009 OR dt.codigo_movimiento<>N'DEVOLUCION' OR NOT EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia x JOIN dbo.msp_tipos_movimiento_garantia xt ON xt.id_tipo_movimiento_garantia=x.id_tipo_movimiento_garantia WHERE x.id_garantia=i.id_garantia AND xt.codigo_movimiento=N'AJUSTE_POSITIVO' AND x.monto_movimiento=i.monto_reversa AND (x.observaciones LIKE CONCAT(N'%reversa financiera compensatoria de devolución #',i.id_origen,N':%') OR x.observaciones LIKE CONCAT(N'%[[]REVERSA_GARANTIA:',i.id_reversa_garantia,N']%')))))
           OR (i.tipo_origen=N'APLICACION' AND (m.id_movimiento_garantia IS NULL OR m.id_garantia<>i.id_garantia OR tm.codigo_movimiento<>N'APLICACION_CARGO' OR ABS(m.monto_movimiento-i.monto_reversa)>0.009 OR NOT EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia x JOIN dbo.msp_tipos_movimiento_garantia xt ON xt.id_tipo_movimiento_garantia=x.id_tipo_movimiento_garantia WHERE x.id_garantia=i.id_garantia AND xt.codigo_movimiento=N'AJUSTE_POSITIVO' AND x.monto_movimiento=i.monto_reversa AND x.observaciones LIKE CONCAT(N'%reversa financiera compensatoria de aplicación #',i.id_origen,N':%'))))
    )
        THROW 54005, 'La reversa no coincide con una operacion de garantia anulada.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_movimientos_saldo_favor_integridad
ON dbo.msp_movimientos_saldo_favor_tienda
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM deleted)
        THROW 54012, 'El libro de saldo a favor es inmutable; los cambios se registran con una reversa.', 1;
    IF EXISTS (
        SELECT 1 FROM inserted
        WHERE (tipo_movimiento IN(1,4,5) AND monto_movimiento<=0)
           OR (tipo_movimiento IN(2,3) AND monto_movimiento>=0)
    )
        THROW 54006, 'El signo no corresponde al tipo de movimiento de saldo a favor.', 1;

    IF EXISTS (
        SELECT 1
        FROM inserted i
        LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=i.id_documento_cobro
        LEFT JOIN dbo.msp_pagos p ON p.id_pago=i.id_pago
        WHERE (i.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL)
           OR (i.id_pago IS NOT NULL AND p.id_pago IS NULL)
           OR (d.id_documento_cobro IS NOT NULL AND d.id_tienda<>i.id_tienda)
           OR (p.id_pago IS NOT NULL AND i.id_documento_cobro IS NOT NULL AND p.id_documento_cobro<>i.id_documento_cobro)
           OR (i.tipo_movimiento=1 AND p.id_pago IS NOT NULL AND (p.aplica_desde_saldo_favor<>0 OR ABS(p.monto_saldo_favor_generado-i.monto_movimiento)>0.009))
           OR (i.tipo_movimiento=2 AND p.id_pago IS NOT NULL AND (p.aplica_desde_saldo_favor<>1 OR ABS(p.monto_pagado+i.monto_movimiento)>0.009))
    )
        THROW 54007, 'El movimiento de saldo a favor no coincide con su tienda, documento o pago.', 1;

    IF EXISTS (
        SELECT 1 FROM (SELECT DISTINCT id_tienda FROM inserted) a
        CROSS APPLY (
            SELECT ISNULL(SUM(m.monto_movimiento),0) saldo
            FROM dbo.msp_movimientos_saldo_favor_tienda m WITH(UPDLOCK,HOLDLOCK)
            WHERE m.id_tienda=a.id_tienda
        ) x WHERE x.saldo < -0.009
    )
        THROW 54008, 'El movimiento dejaria un saldo a favor negativo.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_pagos_protege_saldo_favor
ON dbo.msp_pagos
AFTER DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(
        SELECT 1 FROM deleted d
        INNER JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_pago=d.id_pago
    )
        THROW 54013, 'No se puede eliminar un pago con movimientos de saldo a favor; debe anularse.', 1;
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_documentos_protege_saldo_favor
ON dbo.msp_documentos_cobro
AFTER DELETE
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(
        SELECT 1 FROM deleted d
        INNER JOIN dbo.msp_movimientos_saldo_favor_tienda m ON m.id_documento_cobro=d.id_documento_cobro
    )
        THROW 54014, 'No se puede eliminar un documento con movimientos de saldo a favor.', 1;
END;
GO

IF EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dbo.msp_pago_contrato_operaciones') AND name=N'CK_msp_pco_montos')
    ALTER TABLE dbo.msp_pago_contrato_operaciones DROP CONSTRAINT CK_msp_pco_montos;
ALTER TABLE dbo.msp_pago_contrato_operaciones WITH CHECK ADD CONSTRAINT CK_msp_pco_montos CHECK(
    monto_total_pagado>0 AND monto_total_aplicado>=0 AND monto_total_excedente>=0 AND monto_total_no_imputado>=0
    AND ABS(monto_total_pagado-monto_total_aplicado-monto_total_excedente-monto_total_no_imputado)<=0.01
);
ALTER TABLE dbo.msp_pago_contrato_operaciones CHECK CONSTRAINT CK_msp_pco_montos;
GO

IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.msp_movimientos_saldo_favor_tienda') AND name=N'UX_msp_msf_pago_tipo_operativo')
    CREATE UNIQUE INDEX UX_msp_msf_pago_tipo_operativo
    ON dbo.msp_movimientos_saldo_favor_tienda(id_pago,tipo_movimiento)
    WHERE id_pago IS NOT NULL AND tipo_movimiento IN(1,2,3,4);
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_saldo_favor_periodo_aplicaciones_integridad
ON dbo.msp_saldo_favor_periodo_aplicaciones
AFTER INSERT, UPDATE, DELETE
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @items TABLE(id_item INT PRIMARY KEY);
    INSERT @items SELECT DISTINCT id_saldo_favor_periodo_item FROM inserted;
    INSERT @items SELECT DISTINCT d.id_saldo_favor_periodo_item FROM deleted d
      WHERE NOT EXISTS(SELECT 1 FROM @items i WHERE i.id_item=d.id_saldo_favor_periodo_item);

    IF EXISTS (
        SELECT 1 FROM inserted a
        INNER JOIN dbo.msp_saldo_favor_periodo_items i ON i.id_saldo_favor_periodo_item=a.id_saldo_favor_periodo_item
        INNER JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=a.id_documento_cobro
        LEFT JOIN dbo.msp_pagos p ON p.id_pago=a.id_pago
        WHERE a.id_tienda<>i.id_tienda OR a.periodo_facturacion<>i.periodo_facturacion
           OR d.id_tienda<>a.id_tienda
           OR (a.id_pago IS NOT NULL AND (p.id_pago IS NULL OR p.id_documento_cobro<>a.id_documento_cobro OR p.aplica_desde_saldo_favor<>1 OR ABS(p.monto_pagado-a.monto_aplicado)>0.009))
    )
        THROW 54009, 'La aplicacion por periodo no coincide con el item, tienda, documento o pago.', 1;

    IF EXISTS (
        SELECT 1 FROM @items x
        INNER JOIN dbo.msp_saldo_favor_periodo_items i ON i.id_saldo_favor_periodo_item=x.id_item
        CROSS APPLY (
            SELECT ISNULL(SUM(a.monto_aplicado),0) aplicado
            FROM dbo.msp_saldo_favor_periodo_aplicaciones a WITH(UPDLOCK,HOLDLOCK)
            WHERE a.id_saldo_favor_periodo_item=x.id_item AND a.estado_aplicacion=1
        ) t WHERE t.aplicado>i.monto_original+0.009
    )
        THROW 54010, 'Las aplicaciones activas superan el saldo original del item de periodo.', 1;
END;
GO

PRINT N'Seguridad financiera etapa 4 puntos 1 a 3 aplicada.';
GO
