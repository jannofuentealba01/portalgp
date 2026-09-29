/*
===============================================================================
 MSP - GARANTIA POR TIENDA / BLOQUE 3

 Objetivo
   - Cerrar la revision manual de los contratos multilocal identificados en la
     migracion inicial.
   - Conservar una evidencia persistente de la decision y de los importes
     revisados.
   - Certificar que no existen operaciones sin garantia canonica ni diferencias
     entre el monto pactado por tienda y sus fuentes historicas.
   - Dejar observados, sin bloquear la instalacion, los casos cuyo estado real
     no coincide con la fotografia que fue revisada manualmente.

 Este parche no modifica montos, movimientos, recepciones, devoluciones ni
 asientos. Solo aprueba conciliaciones que cumplen exactamente las condiciones
 auditadas; las diferencias permanecen pendientes y quedan registradas.
===============================================================================
*/

SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

IF OBJECT_ID(N'dbo.msp_garantias_tienda', N'U') IS NULL
    THROW 51801, 'Falta instalar garantia por tienda - bloque 1.', 1;
IF OBJECT_ID(N'dbo.msp_vw_garantias_tienda_resumen', N'V') IS NULL
    THROW 51802, 'Falta instalar el resumen de garantia por tienda.', 1;
IF OBJECT_ID(N'dbo.msp_vw_garantia_tienda_historial_integral', N'V') IS NULL
    THROW 51803, 'Falta instalar garantia por tienda - bloque 2.', 1;
GO

BEGIN TRY
    BEGIN TRANSACTION;

    IF OBJECT_ID(N'dbo.msp_garantias_tienda_revision_historial', N'U') IS NULL
    BEGIN
        CREATE TABLE dbo.msp_garantias_tienda_revision_historial (
            id_revision                 BIGINT IDENTITY(1,1) NOT NULL,
            id_garantia_tienda          INT NOT NULL,
            id_contrato_arriendo        INT NOT NULL,
            decision                    NVARCHAR(20) NOT NULL,
            origen_revision             NVARCHAR(50) NOT NULL,
            resumen_revision            NVARCHAR(1000) NOT NULL,
            cantidad_locales            INT NOT NULL,
            monto_pactado               DECIMAL(18,2) NOT NULL,
            monto_recibido              DECIMAL(18,2) NOT NULL,
            monto_reservado             DECIMAL(18,2) NOT NULL,
            monto_aplicado              DECIMAL(18,2) NOT NULL,
            monto_devuelto_historico    DECIMAL(18,2) NOT NULL,
            monto_disponible            DECIMAL(18,2) NOT NULL,
            fecha_revision              DATETIME2(0) NOT NULL
                CONSTRAINT DF_msp_gt_revision_historial_fecha DEFAULT(SYSDATETIME()),
            CONSTRAINT PK_msp_gt_revision_historial PRIMARY KEY(id_revision),
            CONSTRAINT FK_msp_gt_revision_historial_garantia
                FOREIGN KEY(id_garantia_tienda)
                REFERENCES dbo.msp_garantias_tienda(id_garantia_tienda),
            CONSTRAINT CK_msp_gt_revision_historial_decision
                CHECK(decision IN(N'APROBADA',N'OBSERVADA')),
            CONSTRAINT UQ_msp_gt_revision_historial_origen
                UNIQUE(id_garantia_tienda,origen_revision)
        );
    END;

    DECLARE @casos TABLE (
        id_contrato_arriendo INT NOT NULL PRIMARY KEY,
        cantidad_locales INT NOT NULL,
        monto_pactado DECIMAL(18,2) NOT NULL,
        monto_recibido DECIMAL(18,2) NOT NULL,
        monto_devuelto_historico DECIMAL(18,2) NOT NULL,
        monto_disponible DECIMAL(18,2) NOT NULL,
        resumen_revision NVARCHAR(1000) NOT NULL
    );

    INSERT INTO @casos VALUES
        (1,3,876000,876000,0,876000,
         N'Conciliacion aprobada: A-1, A-2 y A-3a suman $876.000 pactados y las tres recepciones confirmadas suman $876.000.'),
        (49,2,500000,150000,0,150000,
         N'Conciliacion aprobada: D-23 y D-24 suman $500.000 pactados; existen $150.000 recibidos y $350.000 pendientes de recepcion.'),
        (73,2,100000,100000,200000,100000,
         N'Conciliacion aprobada: locales 95 y 96 suman $100.000 pactados. Dos devoluciones historicas por $100.000 fueron anuladas y compensadas, conservando $100.000 disponibles.');

    DECLARE @casos_conciliados TABLE (
        id_contrato_arriendo INT NOT NULL PRIMARY KEY,
        id_garantia_tienda INT NOT NULL
    );

    INSERT INTO @casos_conciliados(id_contrato_arriendo,id_garantia_tienda)
    SELECT c.id_contrato_arriendo,r.id_garantia_tienda
    FROM @casos c
    INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
        ON r.id_contrato_arriendo=c.id_contrato_arriendo
    WHERE r.cantidad_locales=c.cantidad_locales
      AND ABS(r.monto_pactado-c.monto_pactado)<=0.009
      AND ABS(r.monto_pactado_fuentes-c.monto_pactado)<=0.009
      AND ABS(r.monto_recibido-c.monto_recibido)<=0.009
      AND ABS(r.monto_reservado)<=0.009
      AND ABS(r.monto_aplicado)<=0.009
      AND ABS(r.total_devuelto-c.monto_devuelto_historico)<=0.009
      AND ABS(r.monto_disponible-c.monto_disponible)<=0.009;

    /* El caso 73 exige, ademas de los totales, conservar dos devoluciones
       anuladas y sus compensaciones. Se usa la garantia canonica resuelta por
       contrato; nunca se supone que su ID coincide con el contrato. */
    DELETE cc
    FROM @casos_conciliados cc
    WHERE cc.id_contrato_arriendo=73
      AND (
            (SELECT COUNT(*) FROM dbo.msp_garantia_reversas WHERE id_garantia_tienda=cc.id_garantia_tienda AND tipo_origen=N'DEVOLUCION')<>2
         OR ABS(ISNULL((SELECT SUM(monto_reversa) FROM dbo.msp_garantia_reversas WHERE id_garantia_tienda=cc.id_garantia_tienda AND tipo_origen=N'DEVOLUCION'),0)-200000)>0.009
         OR (SELECT COUNT(*) FROM dbo.msp_garantia_devoluciones WHERE id_garantia_tienda=cc.id_garantia_tienda AND estado_devolucion=N'ANULADA')<>2
         OR ABS(ISNULL((SELECT SUM(monto_devolucion) FROM dbo.msp_garantia_devoluciones WHERE id_garantia_tienda=cc.id_garantia_tienda AND estado_devolucion=N'ANULADA'),0)-200000)>0.009
         OR EXISTS (
                SELECT 1
                FROM dbo.msp_garantia_devoluciones d
                INNER JOIN dbo.msp_tesoreria_movimientos tm
                    ON tm.id_devolucion_garantia=d.id_devolucion_garantia
                WHERE d.id_garantia_tienda=cc.id_garantia_tienda
                  AND (tm.estado_movimiento<>N'ANULADO' OR tm.conciliado<>0)
            )
         OR (SELECT COUNT(*)
             FROM dbo.msp_movimientos_garantia m
             INNER JOIN dbo.msp_tipos_movimiento_garantia t
                 ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
             WHERE m.id_garantia_tienda=cc.id_garantia_tienda
               AND t.codigo_movimiento=N'AJUSTE_POSITIVO'
               AND m.observaciones LIKE N'%[[]REVERSA_GARANTIA:%')<>2
         OR ABS(ISNULL((
                SELECT SUM(m.monto_movimiento)
                FROM dbo.msp_movimientos_garantia m
                INNER JOIN dbo.msp_tipos_movimiento_garantia t
                    ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
                WHERE m.id_garantia_tienda=cc.id_garantia_tienda
                  AND t.codigo_movimiento=N'AJUSTE_POSITIVO'
                  AND m.observaciones LIKE N'%[[]REVERSA_GARANTIA:%'
            ),0)-200000)>0.009
      );

    MERGE dbo.msp_garantias_tienda_revision_historial AS destino
    USING (
        SELECT
            r.id_garantia_tienda,
            r.id_contrato_arriendo,
            c.resumen_revision,
            r.cantidad_locales,
            r.monto_pactado,
            r.monto_recibido,
            r.monto_reservado,
            r.monto_aplicado,
            r.total_devuelto,
            r.monto_disponible
        FROM @casos c
        INNER JOIN @casos_conciliados cc
            ON cc.id_contrato_arriendo=c.id_contrato_arriendo
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_garantia_tienda=cc.id_garantia_tienda
    ) AS fuente
        ON destino.id_garantia_tienda=fuente.id_garantia_tienda
       AND destino.origen_revision=N'MIGRACION_BLOQUE3'
    WHEN MATCHED THEN UPDATE SET
        destino.decision=N'APROBADA',
        destino.resumen_revision=fuente.resumen_revision,
        destino.cantidad_locales=fuente.cantidad_locales,
        destino.monto_pactado=fuente.monto_pactado,
        destino.monto_recibido=fuente.monto_recibido,
        destino.monto_reservado=fuente.monto_reservado,
        destino.monto_aplicado=fuente.monto_aplicado,
        destino.monto_devuelto_historico=fuente.total_devuelto,
        destino.monto_disponible=fuente.monto_disponible
    WHEN NOT MATCHED THEN INSERT(
        id_garantia_tienda,id_contrato_arriendo,decision,origen_revision,resumen_revision,
        cantidad_locales,monto_pactado,monto_recibido,monto_reservado,monto_aplicado,
        monto_devuelto_historico,monto_disponible
    ) VALUES(
        fuente.id_garantia_tienda,fuente.id_contrato_arriendo,N'APROBADA',N'MIGRACION_BLOQUE3',
        fuente.resumen_revision,fuente.cantidad_locales,fuente.monto_pactado,fuente.monto_recibido,
        fuente.monto_reservado,fuente.monto_aplicado,fuente.total_devuelto,fuente.monto_disponible
    );

    MERGE dbo.msp_garantias_tienda_revision_historial AS destino
    USING (
        SELECT
            r.id_garantia_tienda,
            r.id_contrato_arriendo,
            CONCAT(
                N'Conciliacion pendiente: la fotografia actual no coincide con el caso auditado. ',
                N'Actual: locales=',r.cantidad_locales,
                N', pactado=',CONVERT(NVARCHAR(40),r.monto_pactado),
                N', recibido=',CONVERT(NVARCHAR(40),r.monto_recibido),
                N', reservado=',CONVERT(NVARCHAR(40),r.monto_reservado),
                N', aplicado=',CONVERT(NVARCHAR(40),r.monto_aplicado),
                N', devuelto=',CONVERT(NVARCHAR(40),r.total_devuelto),
                N', disponible=',CONVERT(NVARCHAR(40),r.monto_disponible),N'.'
            ) AS resumen_revision,
            r.cantidad_locales,
            r.monto_pactado,
            r.monto_recibido,
            r.monto_reservado,
            r.monto_aplicado,
            r.total_devuelto,
            r.monto_disponible
        FROM @casos c
        INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
            ON r.id_contrato_arriendo=c.id_contrato_arriendo
        LEFT JOIN @casos_conciliados cc
            ON cc.id_contrato_arriendo=c.id_contrato_arriendo
        WHERE cc.id_contrato_arriendo IS NULL
          AND (r.requiere_revision_manual=1 OR r.estado_revision IN(N'PENDIENTE',N'OBSERVADA'))
    ) AS fuente
        ON destino.id_garantia_tienda=fuente.id_garantia_tienda
       AND destino.origen_revision=N'MIGRACION_BLOQUE3_OBSERVADA'
    WHEN MATCHED THEN UPDATE SET
        destino.decision=N'OBSERVADA',
        destino.resumen_revision=fuente.resumen_revision,
        destino.cantidad_locales=fuente.cantidad_locales,
        destino.monto_pactado=fuente.monto_pactado,
        destino.monto_recibido=fuente.monto_recibido,
        destino.monto_reservado=fuente.monto_reservado,
        destino.monto_aplicado=fuente.monto_aplicado,
        destino.monto_devuelto_historico=fuente.total_devuelto,
        destino.monto_disponible=fuente.monto_disponible,
        destino.fecha_revision=SYSDATETIME()
    WHEN NOT MATCHED THEN INSERT(
        id_garantia_tienda,id_contrato_arriendo,decision,origen_revision,resumen_revision,
        cantidad_locales,monto_pactado,monto_recibido,monto_reservado,monto_aplicado,
        monto_devuelto_historico,monto_disponible
    ) VALUES(
        fuente.id_garantia_tienda,fuente.id_contrato_arriendo,N'OBSERVADA',N'MIGRACION_BLOQUE3_OBSERVADA',
        fuente.resumen_revision,fuente.cantidad_locales,fuente.monto_pactado,fuente.monto_recibido,
        fuente.monto_reservado,fuente.monto_aplicado,fuente.total_devuelto,fuente.monto_disponible
    );

    UPDATE gt
    SET gt.requiere_revision_manual=0,
        gt.estado_revision=N'APROBADA',
        gt.motivo_revision=h.resumen_revision,
        gt.observaciones=CASE
            WHEN ISNULL(gt.observaciones,N'') LIKE N'%[MIGRACION_BLOQUE3]%' THEN gt.observaciones
            ELSE CONCAT(NULLIF(gt.observaciones,N''),CASE WHEN NULLIF(gt.observaciones,N'') IS NULL THEN N'' ELSE N' | ' END,
                        N'[MIGRACION_BLOQUE3] Conciliacion historica aprobada sin modificar importes.')
        END,
        gt.fecha_actualizacion=SYSDATETIME()
    FROM dbo.msp_garantias_tienda gt
    INNER JOIN dbo.msp_garantias_tienda_revision_historial h
        ON h.id_garantia_tienda=gt.id_garantia_tienda
       AND h.origen_revision=N'MIGRACION_BLOQUE3'
       AND h.decision=N'APROBADA';

    IF EXISTS(SELECT 1 FROM dbo.msp_garantias WHERE id_garantia_tienda IS NULL)
        THROW 51807, 'Existen garantias historicas sin garantia de tienda.', 1;
    IF EXISTS(SELECT 1 FROM dbo.msp_garantia_recepciones WHERE id_garantia_tienda IS NULL)
        THROW 51808, 'Existen recepciones sin garantia de tienda.', 1;
    IF EXISTS(SELECT 1 FROM dbo.msp_movimientos_garantia WHERE id_garantia_tienda IS NULL)
        THROW 51809, 'Existen movimientos sin garantia de tienda.', 1;
    IF EXISTS(SELECT 1 FROM dbo.msp_garantia_devoluciones WHERE id_garantia_tienda IS NULL)
        THROW 51810, 'Existen devoluciones sin garantia de tienda.', 1;
    IF EXISTS(SELECT 1 FROM dbo.msp_garantia_reversas WHERE id_garantia_tienda IS NULL)
        THROW 51811, 'Existen reversas sin garantia de tienda.', 1;
    IF EXISTS (
        SELECT 1
        FROM dbo.msp_garantias_tienda gt
        INNER JOIN dbo.msp_contratos_arriendo c
            ON c.id_contrato_arriendo=gt.id_contrato_arriendo
        WHERE gt.id_tienda<>c.id_tienda
    )
        THROW 51812, 'Existe una garantia vinculada a una tienda distinta a la del contrato.', 1;
    IF EXISTS (
        SELECT 1
        FROM dbo.msp_vw_garantias_tienda_resumen
        WHERE ABS(monto_pactado-monto_pactado_fuentes)>0.009
    )
        THROW 51813, 'Existen diferencias entre garantia por tienda y sus fuentes historicas.', 1;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE()<>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO

CREATE OR ALTER VIEW dbo.msp_vw_garantias_tienda_migracion_final
AS
SELECT
    COUNT_BIG(*) AS garantias_tienda,
    SUM(CASE WHEN requiere_revision_manual=1 OR estado_revision IN(N'PENDIENTE',N'OBSERVADA') THEN 1 ELSE 0 END) AS revisiones_pendientes,
    CAST(SUM(monto_pactado) AS DECIMAL(18,2)) AS monto_pactado,
    CAST(SUM(monto_recibido) AS DECIMAL(18,2)) AS monto_recibido,
    CAST(SUM(monto_reservado) AS DECIMAL(18,2)) AS monto_reservado,
    CAST(SUM(monto_aplicado) AS DECIMAL(18,2)) AS monto_aplicado,
    CAST(SUM(total_devuelto) AS DECIMAL(18,2)) AS monto_devuelto_historico,
    CAST(SUM(monto_disponible) AS DECIMAL(18,2)) AS monto_disponible,
    CASE
        WHEN SUM(CASE WHEN requiere_revision_manual=1 OR estado_revision IN(N'PENDIENTE',N'OBSERVADA') THEN 1 ELSE 0 END)=0
         AND SUM(CASE WHEN ABS(monto_pactado-monto_pactado_fuentes)>0.009 THEN 1 ELSE 0 END)=0
        THEN N'LISTA'
        ELSE N'REQUIERE_REVISION'
    END AS estado_migracion
FROM dbo.msp_vw_garantias_tienda_resumen;
GO

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT ON OBJECT::dbo.msp_garantias_tienda TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_garantias_tienda_revision_historial TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_garantias_tienda_migracion_auditoria TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_tienda_fuentes TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_tienda_resumen TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_tienda_control_migracion TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantia_tienda_historial_integral TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_tienda_control_integral TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_tienda_migracion_final TO portalgp_runtime_role;
    GRANT EXECUTE ON OBJECT::dbo.msp_garantia_tienda_operar_cargo TO portalgp_runtime_role;
    GRANT EXECUTE ON OBJECT::dbo.msp_garantia_tienda_aplicar_documento TO portalgp_runtime_role;
    GRANT EXECUTE ON OBJECT::dbo.msp_garantia_tienda_devolver_operativa TO portalgp_runtime_role;
    GRANT EXECUTE ON OBJECT::dbo.msp_garantia_tienda_revertir_operacion TO portalgp_runtime_role;
END;
GO

PRINT N'Garantia por tienda - bloque 3 instalado correctamente.';
PRINT N'Los casos coincidentes quedaron aprobados y las diferencias permanecen observadas sin modificar importes.';
GO
