/*
===========================================================================
 MSP - AUDITORIA DE MOVIMIENTOS HISTORICOS DE SALDO A FAVOR

 Clasifica los 60 movimientos legacy con pago/documento eliminado, conserva
 sus identificadores como evidencia y compensa los asientos de las aplicaciones
 que ya poseen una reversa financiera exacta.
===========================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

IF OBJECT_ID(N'dbo.msp_saldo_favor_auditoria_historica',N'U') IS NULL
BEGIN
    CREATE TABLE dbo.msp_saldo_favor_auditoria_historica (
        id_auditoria BIGINT IDENTITY(1,1) NOT NULL
            CONSTRAINT PK_msp_saldo_favor_auditoria_historica PRIMARY KEY,
        id_movimiento_saldo_favor INT NOT NULL,
        id_movimiento_contrapartida INT NOT NULL,
        id_tienda INT NOT NULL,
        id_pago_historico INT NOT NULL,
        id_documento_historico INT NOT NULL,
        tipo_movimiento TINYINT NOT NULL,
        monto_movimiento DECIMAL(18,2) NOT NULL,
        efecto_neto_par DECIMAL(18,2) NOT NULL,
        clasificacion NVARCHAR(50) NOT NULL,
        accion_contable NVARCHAR(50) NOT NULL,
        evidencia NVARCHAR(1000) NOT NULL,
        fecha_revision DATETIME2(0) NOT NULL
            CONSTRAINT DF_msp_sf_auditoria_fecha DEFAULT(SYSDATETIME()),
        CONSTRAINT UQ_msp_sf_auditoria_movimiento UNIQUE(id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_sf_auditoria_movimiento FOREIGN KEY(id_movimiento_saldo_favor)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda(id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_sf_auditoria_contrapartida FOREIGN KEY(id_movimiento_contrapartida)
            REFERENCES dbo.msp_movimientos_saldo_favor_tienda(id_movimiento_saldo_favor),
        CONSTRAINT FK_msp_sf_auditoria_tienda FOREIGN KEY(id_tienda)
            REFERENCES dbo.msp_tiendas(id_tienda),
        CONSTRAINT CK_msp_sf_auditoria_tipo CHECK(tipo_movimiento IN(1,2,3,4)),
        CONSTRAINT CK_msp_sf_auditoria_neto CHECK(ABS(efecto_neto_par)<=0.01),
        CONSTRAINT CK_msp_sf_auditoria_clasificacion CHECK(
            clasificacion IN(N'EXCEDENTE_COMPENSADO',N'APLICACION_COMPENSADA')
        ),
        CONSTRAINT CK_msp_sf_auditoria_accion CHECK(
            accion_contable IN(N'NO_REQUIERE_ASIENTO',N'ASIENTO_COMPENSADO')
        )
    );

    CREATE INDEX IX_msp_sf_auditoria_tienda
        ON dbo.msp_saldo_favor_auditoria_historica(id_tienda,fecha_revision DESC);
END;
GO

CREATE OR ALTER TRIGGER dbo.TR_msp_saldo_favor_auditoria_historica_inmutable
ON dbo.msp_saldo_favor_auditoria_historica
INSTEAD OF UPDATE,DELETE
AS
BEGIN
    SET NOCOUNT ON;
    THROW 53540,N'La auditoria historica de saldo a favor es inmutable.',1;
END;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'tempdb..#huerfanos',N'U') IS NOT NULL DROP TABLE #huerfanos;
    SELECT m.*
    INTO #huerfanos
    FROM dbo.msp_movimientos_saldo_favor_tienda m
    LEFT JOIN dbo.msp_pagos p ON p.id_pago=m.id_pago
    LEFT JOIN dbo.msp_documentos_cobro d ON d.id_documento_cobro=m.id_documento_cobro
    WHERE (m.id_pago IS NOT NULL AND p.id_pago IS NULL)
       OR (m.id_documento_cobro IS NOT NULL AND d.id_documento_cobro IS NULL);

    IF EXISTS(
        SELECT 1 FROM #huerfanos
        WHERE id_pago IS NULL OR id_documento_cobro IS NULL OR tipo_movimiento NOT IN(1,2,3,4)
    )
        THROW 53541,N'Existen movimientos historicos sin identificadores suficientes para una clasificacion automatica.',1;

    IF OBJECT_ID(N'tempdb..#pares',N'U') IS NOT NULL DROP TABLE #pares;
    SELECT
        o.id_movimiento_saldo_favor AS id_origen,
        r.id_movimiento_saldo_favor AS id_reversa,
        o.id_tienda,o.id_pago,o.id_documento_cobro,
        o.tipo_movimiento AS tipo_origen,r.tipo_movimiento AS tipo_reversa,
        o.monto_movimiento AS monto_origen,r.monto_movimiento AS monto_reversa,
        r.fecha_movimiento AS fecha_reversa
    INTO #pares
    FROM #huerfanos o
    INNER JOIN #huerfanos r
        ON r.id_tienda=o.id_tienda
       AND r.id_pago=o.id_pago
       AND r.id_documento_cobro=o.id_documento_cobro
       AND r.monto_movimiento=-o.monto_movimiento
       AND r.tipo_movimiento=CASE o.tipo_movimiento WHEN 1 THEN 3 WHEN 2 THEN 4 END
    WHERE o.tipo_movimiento IN(1,2);

    IF EXISTS(
        SELECT id_movimiento_saldo_favor
        FROM #huerfanos h
        LEFT JOIN (
            SELECT id_origen id FROM #pares
            UNION ALL SELECT id_reversa FROM #pares
        ) p ON p.id=h.id_movimiento_saldo_favor
        GROUP BY id_movimiento_saldo_favor
        HAVING COUNT(p.id)<>1
    )
        THROW 53542,N'No todos los movimientos historicos poseen una contrapartida unica y exacta.',1;

    DECLARE @id_movimiento INT,@fecha_reversa DATE;
    DECLARE cur_compensar CURSOR LOCAL FAST_FORWARD FOR
        SELECT p.id_origen,p.fecha_reversa
        FROM #pares p
        WHERE p.tipo_origen=2
          AND EXISTS(
              SELECT 1 FROM dbo.msp_acc_asientos a
              WHERE a.tabla_origen=N'msp_movimientos_saldo_favor_tienda'
                AND a.id_origen=p.id_origen
                AND a.estado_asiento=1
          );

    OPEN cur_compensar;
    FETCH NEXT FROM cur_compensar INTO @id_movimiento,@fecha_reversa;
    WHILE @@FETCH_STATUS=0
    BEGIN
        EXEC dbo.msp_acc_revertir_origen
            @tabla_origen=N'msp_movimientos_saldo_favor_tienda',
            @id_origen=@id_movimiento,
            @fecha_reversa=@fecha_reversa,
            @motivo=N'Compensacion por reversa historica auditada de saldo a favor';
        FETCH NEXT FROM cur_compensar INTO @id_movimiento,@fecha_reversa;
    END;
    CLOSE cur_compensar;
    DEALLOCATE cur_compensar;

    INSERT dbo.msp_saldo_favor_auditoria_historica(
        id_movimiento_saldo_favor,id_movimiento_contrapartida,id_tienda,
        id_pago_historico,id_documento_historico,tipo_movimiento,monto_movimiento,
        efecto_neto_par,clasificacion,accion_contable,evidencia
    )
    SELECT
        x.id_movimiento,x.id_contrapartida,p.id_tienda,p.id_pago,p.id_documento_cobro,
        x.tipo_movimiento,x.monto_movimiento,p.monto_origen+p.monto_reversa,
        CASE WHEN p.tipo_origen=1 THEN N'EXCEDENTE_COMPENSADO' ELSE N'APLICACION_COMPENSADA' END,
        CASE WHEN p.tipo_origen=2 THEN N'ASIENTO_COMPENSADO' ELSE N'NO_REQUIERE_ASIENTO' END,
        CONCAT(
            N'Pago historico #',p.id_pago,N' y documento historico #',p.id_documento_cobro,
            N' eliminados. Movimiento #',x.id_movimiento,N' compensado exactamente por #',
            x.id_contrapartida,N'; tienda #',p.id_tienda,N'; monto par ',
            CONVERT(NVARCHAR(50),ABS(p.monto_origen)),N'; efecto neto 0.'
        )
    FROM #pares p
    CROSS APPLY(VALUES
        (p.id_origen,p.id_reversa,p.tipo_origen,p.monto_origen),
        (p.id_reversa,p.id_origen,p.tipo_reversa,p.monto_reversa)
    ) x(id_movimiento,id_contrapartida,tipo_movimiento,monto_movimiento)
    WHERE NOT EXISTS(
        SELECT 1 FROM dbo.msp_saldo_favor_auditoria_historica a
        WHERE a.id_movimiento_saldo_favor=x.id_movimiento
    );

    UPDATE rh
    SET referencia=LEFT(CONCAT(
        CASE WHEN a.clasificacion=N'EXCEDENTE_COMPENSADO'
             THEN N'Excedente historico compensado. '
             ELSE N'Aplicacion historica compensada. ' END,
        N'Pago #',a.id_pago_historico,N' y documento #',a.id_documento_historico,
        N' eliminados; contrapartida movimiento #',a.id_movimiento_contrapartida,
        N'; efecto neto $0; ',
        CASE WHEN a.accion_contable=N'ASIENTO_COMPENSADO'
             THEN N'asiento contable compensado.'
             ELSE N'sin asiento adicional requerido.' END
    ),500)
    FROM dbo.msp_referencias_financieras_historicas rh
    INNER JOIN dbo.msp_saldo_favor_auditoria_historica a
        ON a.id_movimiento_saldo_favor=rh.id_origen
       AND rh.tipo_origen=N'SALDO_FAVOR_MOVIMIENTO';

    IF EXISTS(
        SELECT 1
        FROM #pares p
        INNER JOIN dbo.msp_acc_asientos a
            ON a.tabla_origen=N'msp_movimientos_saldo_favor_tienda'
           AND a.id_origen=p.id_origen
           AND a.estado_asiento=1
        WHERE p.tipo_origen=2
    )
        THROW 53543,N'Quedaron asientos activos de aplicaciones historicas que ya fueron revertidas.',1;

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF CURSOR_STATUS('local','cur_compensar')>-1 CLOSE cur_compensar;
    IF CURSOR_STATUS('local','cur_compensar')>=-1 DEALLOCATE cur_compensar;
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT ON OBJECT::dbo.msp_saldo_favor_auditoria_historica TO portalgp_runtime_role;
    DENY INSERT,UPDATE,DELETE ON OBJECT::dbo.msp_saldo_favor_auditoria_historica TO portalgp_runtime_role;
END;
GO

PRINT N'Auditoria historica de 60 movimientos de saldo a favor aplicada.';
GO
