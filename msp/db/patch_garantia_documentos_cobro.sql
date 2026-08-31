SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_garantia_documento_aplicaciones', N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_garantia_documento_aplicaciones (
        id_aplicacion_garantia_documento INT IDENTITY(1,1) NOT NULL,
        id_garantia INT NOT NULL,
        id_documento_cobro INT NOT NULL,
        id_pago INT NOT NULL,
        id_movimiento_garantia INT NOT NULL,
        id_tipo_item_documento INT NULL,
        fecha_aplicacion DATE NOT NULL,
        monto_aplicado DECIMAL(18,2) NOT NULL,
        observaciones NVARCHAR(500) NULL,
        id_usuario INT NULL,
        fecha_registro DATETIME2(0) NOT NULL CONSTRAINT DF_msp_gda_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT PK_msp_garantia_documento_aplicaciones PRIMARY KEY(id_aplicacion_garantia_documento),
        CONSTRAINT FK_msp_gda_garantia FOREIGN KEY(id_garantia) REFERENCES dbo.msp_garantias(id_garantia),
        CONSTRAINT FK_msp_gda_documento FOREIGN KEY(id_documento_cobro) REFERENCES dbo.msp_documentos_cobro(id_documento_cobro),
        CONSTRAINT FK_msp_gda_pago FOREIGN KEY(id_pago) REFERENCES dbo.msp_pagos(id_pago),
        CONSTRAINT FK_msp_gda_movimiento FOREIGN KEY(id_movimiento_garantia) REFERENCES dbo.msp_movimientos_garantia(id_movimiento_garantia),
        CONSTRAINT FK_msp_gda_tipo_item FOREIGN KEY(id_tipo_item_documento) REFERENCES dbo.msp_tipo_item_documento(id_tipo_item_documento),
        CONSTRAINT UQ_msp_gda_pago UNIQUE(id_pago),
        CONSTRAINT UQ_msp_gda_movimiento UNIQUE(id_movimiento_garantia),
        CONSTRAINT CK_msp_gda_monto CHECK(monto_aplicado>0)
    );
    CREATE INDEX IX_msp_gda_garantia_fecha ON dbo.msp_garantia_documento_aplicaciones(id_garantia,fecha_aplicacion DESC);
    CREATE INDEX IX_msp_gda_documento ON dbo.msp_garantia_documento_aplicaciones(id_documento_cobro);
END;
GO

CREATE OR ALTER PROCEDURE dbo.msp_garantia_aplicar_documento
    @id_documento_cobro INT,
    @id_garantia INT = NULL,
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

    IF ISNULL(@id_documento_cobro,0)<=0 OR ISNULL(@monto_aplicar,0)<=0 OR @fecha_pago IS NULL
        THROW 52401, 'Los datos de aplicación de garantía no son válidos.', 1;

    SET @observaciones=NULLIF(LTRIM(RTRIM(ISNULL(@observaciones,N''))),N'');
    IF @observaciones IS NOT NULL AND LEN(@observaciones)>500
        THROW 52402, 'Las observaciones no pueden superar 500 caracteres.', 1;

    DECLARE @contrato_garantia INT,@local_garantia INT,@locales_contrato INT,@contrato_documento INT,@estado_documento TINYINT,@saldo_documento DECIMAL(18,2),
            @recibido DECIMAL(18,2),@aplicado_devuelto DECIMAL(18,2),@reserva_neta DECIMAL(18,2),@disponible_real DECIMAL(18,2),
            @saldo_concepto DECIMAL(18,2),@detalle_json NVARCHAR(MAX),@referencia NVARCHAR(100),@id_tipo_aplicacion INT;

    SET @id_pago_generado=NULL;
    SET @id_movimiento_garantia=NULL;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @contrato_documento=dc.id_contrato_arriendo,@estado_documento=dc.estado_documento,@saldo_documento=dc.saldo_pendiente
        FROM dbo.msp_documentos_cobro dc WITH(UPDLOCK,HOLDLOCK)
        WHERE dc.id_documento_cobro=@id_documento_cobro;

        IF ISNULL(@id_garantia,0)<=0
        BEGIN
            SELECT TOP(1) @id_garantia=g.id_garantia
            FROM dbo.msp_garantias g WITH(UPDLOCK,HOLDLOCK)
            WHERE g.id_contrato_arriendo=@contrato_documento AND g.estado_garantia<>6
              AND EXISTS(SELECT 1 FROM dbo.msp_garantia_recepciones r WHERE r.id_garantia=g.id_garantia AND r.estado_recepcion=N'CONFIRMADA')
            ORDER BY g.id_garantia DESC;
        END;

        SELECT @contrato_garantia=g.id_contrato_arriendo,@local_garantia=g.id_local
        FROM dbo.msp_garantias g WITH(UPDLOCK,HOLDLOCK)
        WHERE g.id_garantia=@id_garantia AND g.estado_garantia<>6;

        IF @contrato_garantia IS NULL OR @contrato_documento IS NULL OR @contrato_garantia<>@contrato_documento
            THROW 52403, 'La garantía y el documento deben pertenecer al mismo contrato.', 1;

        /* Los documentos de cobro son de contrato (no de local). Por tanto,
           una aplicación contra documento solo es inequívoca cuando el
           contrato tiene un único local. En contratos multi-local se debe
           aplicar primero contra un cargo de salida que lleve id_local; así
           evitamos usar la garantía de A-1 para cubrir deuda de A-2. */
        SELECT @locales_contrato=COUNT(*)
        FROM dbo.msp_contrato_locales cl
        WHERE cl.id_contrato_arriendo=@contrato_garantia
          AND cl.estado_relacion IN (1,2)
          AND cl.fecha_inicio<=EOMONTH(@fecha_pago)
          AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=@fecha_pago);
        IF ISNULL(@locales_contrato,0)<>1
            THROW 52408, 'La aplicación contra documento requiere un contrato con un solo local. En contratos con varios locales aplica la garantía contra el cargo específico del local.', 1;
        IF NOT EXISTS (
            SELECT 1 FROM dbo.msp_contrato_locales cl
            WHERE cl.id_contrato_arriendo=@contrato_garantia AND cl.id_local=@local_garantia
              AND cl.estado_relacion IN (1,2)
              AND cl.fecha_inicio<=EOMONTH(@fecha_pago)
              AND (cl.fecha_termino IS NULL OR cl.fecha_termino>=@fecha_pago)
        )
            THROW 52409, 'La garantía no está asociada al local vigente del documento.', 1;
        IF @estado_documento NOT IN(2,3) OR ISNULL(@saldo_documento,0)<=0
            THROW 52404, 'El documento no tiene deuda disponible para aplicar.', 1;

        SELECT @recibido=ISNULL(SUM(r.monto_recibido),0)
        FROM dbo.msp_garantia_recepciones r WITH(UPDLOCK,HOLDLOCK)
        WHERE r.id_garantia=@id_garantia AND r.estado_recepcion=N'CONFIRMADA';

        SELECT
            @aplicado_devuelto=ISNULL(SUM(CASE WHEN tm.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION') THEN mg.monto_movimiento ELSE 0 END),0),
            @reserva_neta=ISNULL(SUM(CASE WHEN tm.codigo_movimiento=N'RESERVA' THEN mg.monto_movimiento WHEN tm.codigo_movimiento=N'LIBERACION_RESERVA' THEN -mg.monto_movimiento WHEN tm.codigo_movimiento=N'APLICACION_CARGO' AND mg.fondo_origen='R' THEN -mg.monto_movimiento ELSE 0 END),0)
        FROM dbo.msp_movimientos_garantia mg WITH(UPDLOCK,HOLDLOCK)
        INNER JOIN dbo.msp_tipos_movimiento_garantia tm ON tm.id_tipo_movimiento_garantia=mg.id_tipo_movimiento_garantia
        WHERE mg.id_garantia=@id_garantia;

        SET @disponible_real=ROUND(ISNULL(@recibido,0)-ISNULL(@aplicado_devuelto,0)-ISNULL(@reserva_neta,0),2);
        IF @monto_aplicar>@disponible_real+0.009
            THROW 52405, 'El monto excede la garantía efectivamente recibida y disponible.', 1;
        IF @monto_aplicar>@saldo_documento+0.009
            THROW 52406, 'El monto excede el saldo pendiente del documento.', 1;

        SET @detalle_json=NULL;
        IF ISNULL(@id_tipo_item_documento,0)>0
        BEGIN
            SELECT @saldo_concepto=ROUND(CASE WHEN base.total-ISNULL(pag.aplicado,0)>0 THEN base.total-ISNULL(pag.aplicado,0) ELSE 0 END,2)
            FROM (
                SELECT d.id_tipo_item_documento,
                       SUM(d.subtotal)+CASE WHEN t.codigo_item=N'ARRIENDO' THEN CASE WHEN dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios>0 THEN dc.monto_total-dc.subtotal_arriendo-dc.subtotal_servicios ELSE 0 END ELSE 0 END total
                FROM dbo.msp_documentos_cobro_detalle d
                INNER JOIN dbo.msp_tipo_item_documento t ON t.id_tipo_item_documento=d.id_tipo_item_documento
                INNER JOIN dbo.msp_documentos_cobro dc ON dc.id_documento_cobro=d.id_documento_cobro
                WHERE d.id_documento_cobro=@id_documento_cobro AND d.id_tipo_item_documento=@id_tipo_item_documento
                GROUP BY d.id_tipo_item_documento,t.codigo_item,dc.monto_total,dc.subtotal_arriendo,dc.subtotal_servicios
            ) base
            OUTER APPLY(SELECT SUM(pdc.monto_aplicado) aplicado FROM dbo.msp_pagos_detalle_concepto pdc INNER JOIN dbo.msp_pagos p ON p.id_pago=pdc.id_pago WHERE pdc.id_documento_cobro=@id_documento_cobro AND pdc.id_tipo_item_documento=base.id_tipo_item_documento AND p.estado_pago=1) pag;
            IF ISNULL(@saldo_concepto,0)<=0 OR @monto_aplicar>@saldo_concepto+0.009
                THROW 52407, 'El monto excede el saldo pendiente del concepto seleccionado.', 1;
            SET @detalle_json=(SELECT @id_tipo_item_documento id_tipo_item_documento,@monto_aplicar monto FOR JSON PATH);
        END;

        DECLARE @resultado TABLE(id_pago_generado INT,monto_aplicado_documento DECIMAL(18,2),monto_saldo_favor_generado DECIMAL(18,2),saldo_favor_tienda DECIMAL(18,2));
        SET @referencia=CONCAT(N'GAR-',@id_garantia,N'-DOC-',@id_documento_cobro);
        INSERT INTO @resultado
        EXEC dbo.msp_registrar_pago_documento @id_documento_cobro=@id_documento_cobro,@fecha_pago=@fecha_pago,
             @monto_pagado=@monto_aplicar,@medio_pago=N'GARANTIA',@referencia_pago=@referencia,
             @observaciones=@observaciones,@detalle_conceptos_json=@detalle_json;
        SELECT @id_pago_generado=id_pago_generado FROM @resultado;

        SELECT @id_tipo_aplicacion=id_tipo_movimiento_garantia FROM dbo.msp_tipos_movimiento_garantia WHERE codigo_movimiento=N'APLICACION_CARGO' AND activo=1;
        INSERT INTO dbo.msp_movimientos_garantia(id_garantia,fecha_movimiento,id_tipo_movimiento_garantia,monto_movimiento,id_documento_cobro,id_pago,fondo_origen,observaciones)
        VALUES(@id_garantia,@fecha_pago,@id_tipo_aplicacion,@monto_aplicar,@id_documento_cobro,@id_pago_generado,'D',@observaciones);
        SET @id_movimiento_garantia=CONVERT(INT,SCOPE_IDENTITY());

        INSERT INTO dbo.msp_garantia_documento_aplicaciones(id_garantia,id_documento_cobro,id_pago,id_movimiento_garantia,id_tipo_item_documento,fecha_aplicacion,monto_aplicado,observaciones,id_usuario)
        VALUES(@id_garantia,@id_documento_cobro,@id_pago_generado,@id_movimiento_garantia,@id_tipo_item_documento,@fecha_pago,@monto_aplicar,@observaciones,@id_usuario);

        COMMIT TRANSACTION;
        SELECT @id_pago_generado id_pago_generado,@id_movimiento_garantia id_movimiento_garantia,@id_documento_cobro id_documento_cobro,@id_garantia id_garantia,@monto_aplicar monto_aplicado;
    END TRY
    BEGIN CATCH
        IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

PRINT N'Aplicación de garantía contra documentos instalada.';
GO
