/*
===========================================================================
 MSP - Historial general de garantias por arrendatario (bloque 1)

 Objetivo:
 - reunir en un solo flujo recepciones, movimientos y reversas;
 - conservar los identificadores de origen para evitar duplicidades;
 - exponer el arrendatario, tienda, contrato, garantia y local relacionados;
 - calcular saldos historicos disponible, reservado y total;
 - dejar la base de datos lista para una vista paginada de 50 movimientos.

 Este parche no modifica montos ni operaciones financieras existentes.
===========================================================================
*/

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
GO

IF OBJECT_ID(N'dbo.msp_garantias_tienda', N'U') IS NULL
    THROW 51901, 'Falta instalar la garantia por tienda.', 1;
IF OBJECT_ID(N'dbo.msp_garantia_recepciones', N'U') IS NULL
    THROW 51902, 'Falta instalar la recepcion de garantias.', 1;
IF OBJECT_ID(N'dbo.msp_movimientos_garantia', N'U') IS NULL
    THROW 51903, 'Falta instalar los movimientos de garantia.', 1;
IF OBJECT_ID(N'dbo.msp_garantia_reversas', N'U') IS NULL
    THROW 51904, 'Falta instalar las reversas de garantia.', 1;
IF OBJECT_ID(N'dbo.msp_vw_garantias_tienda_resumen', N'V') IS NULL
    THROW 51905, 'Falta instalar el resumen de garantias por tienda.', 1;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id=OBJECT_ID(N'dbo.msp_garantia_reversas')
      AND name=N'IX_msp_grev_garantia_tienda_fecha'
)
BEGIN
    CREATE INDEX IX_msp_grev_garantia_tienda_fecha
        ON dbo.msp_garantia_reversas(
            id_garantia_tienda,
            fecha_reversa,
            id_reversa_garantia
        )
        INCLUDE(tipo_origen,id_origen,monto_reversa);
END;
GO

/*
 La vista de eventos conserva una fila por registro fisico:
 - una recepcion genera una fila RECEPCION;
 - cada movimiento de la garantia genera una fila MOVIMIENTO;
 - cada reversa genera una fila REVERSA.

 Las devoluciones no se agregan como una cuarta fuente porque ya poseen un
 movimiento DEVOLUCION unico. Su tabla se usa para enriquecer ese movimiento.
 En las reversas de aplicacion/devolucion, el ajuste compensatorio ya existe
 como MOVIMIENTO; por ello la fila REVERSA es informativa y no duplica saldo.
*/
CREATE OR ALTER VIEW dbo.msp_vw_garantias_historial_eventos
AS
SELECT
    r.id_garantia_tienda,
    r.id_garantia,
    CAST(N'RECEPCION' AS NVARCHAR(20)) AS origen_evento,
    r.id_recepcion_garantia AS id_evento,
    r.fecha_recepcion AS fecha_evento,
    r.fecha_registro,
    CAST(10 AS TINYINT) AS prioridad_evento,
    CAST(N'RECEPCION' AS NVARCHAR(50)) AS codigo_evento,
    CAST(N'Recepcion de garantia' AS NVARCHAR(120)) AS concepto,
    CAST(CASE
        WHEN r.estado_recepcion IN(N'CONFIRMADA',N'ANULADA')
             AND (r.estado_recepcion=N'CONFIRMADA' OR rev.id_reversa_garantia IS NOT NULL)
            THEN N'ENTRADA'
        ELSE N'INFORMATIVO'
    END AS NVARCHAR(20)) AS naturaleza_evento,
    CAST(CASE
        WHEN r.estado_recepcion IN(N'CONFIRMADA',N'ANULADA')
             AND (r.estado_recepcion=N'CONFIRMADA' OR rev.id_reversa_garantia IS NOT NULL)
            THEN N'+'
        ELSE NULL
    END AS NCHAR(1)) AS signo,
    r.monto_recibido AS monto_operacion,
    CAST(CASE
        WHEN r.estado_recepcion IN(N'CONFIRMADA',N'ANULADA')
             AND (r.estado_recepcion=N'CONFIRMADA' OR rev.id_reversa_garantia IS NOT NULL)
            THEN r.monto_recibido
        ELSE 0
    END AS DECIMAL(18,2)) AS monto_entrada,
    CAST(0 AS DECIMAL(18,2)) AS monto_salida,
    CAST(CASE
        WHEN r.estado_recepcion IN(N'CONFIRMADA',N'ANULADA')
             AND (r.estado_recepcion=N'CONFIRMADA' OR rev.id_reversa_garantia IS NOT NULL)
            THEN r.monto_recibido
        ELSE 0
    END AS DECIMAL(18,2)) AS impacto_disponible,
    CAST(0 AS DECIMAL(18,2)) AS impacto_reservado,
    CAST(CASE
        WHEN r.estado_recepcion IN(N'CONFIRMADA',N'ANULADA')
             AND (r.estado_recepcion=N'CONFIRMADA' OR rev.id_reversa_garantia IS NOT NULL)
            THEN r.monto_recibido
        ELSE 0
    END AS DECIMAL(18,2)) AS impacto_total,
    r.estado_recepcion AS estado_evento,
    CAST(NULL AS CHAR(1)) AS fondo_origen,
    CAST(NULL AS INT) AS id_local_destino,
    CAST(NULL AS INT) AS id_documento_cobro,
    CAST(NULL AS INT) AS id_pago,
    CAST(NULL AS INT) AS id_cargo_contrato_local,
    CAST(NULL AS INT) AS id_cargo_salida,
    r.medio_recepcion AS medio,
    tes.nombre_cuenta AS cuenta,
    COALESCE(r.referencia,r.numero_cheque) AS referencia,
    r.observaciones,
    r.id_usuario,
    CAST(NULL AS NVARCHAR(20)) AS tipo_origen_reversa,
    CAST(NULL AS INT) AS id_origen_reversa
FROM dbo.msp_garantia_recepciones r
LEFT JOIN dbo.msp_garantia_reversas rev
    ON rev.id_garantia_tienda=r.id_garantia_tienda
   AND rev.tipo_origen=N'RECEPCION'
   AND rev.id_origen=r.id_recepcion_garantia
OUTER APPLY (
    SELECT TOP (1) tc.nombre_cuenta
    FROM dbo.msp_tesoreria_movimientos tm
    LEFT JOIN dbo.msp_tesoreria_cuentas tc
        ON tc.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
    WHERE tm.id_recepcion_garantia=r.id_recepcion_garantia
    ORDER BY CASE WHEN tm.estado_movimiento=N'VIGENTE' THEN 0 ELSE 1 END,
             tm.id_movimiento_tesoreria DESC
) tes

UNION ALL

SELECT
    m.id_garantia_tienda,
    m.id_garantia,
    CAST(N'MOVIMIENTO' AS NVARCHAR(20)),
    m.id_movimiento_garantia,
    m.fecha_movimiento,
    m.fecha_registro,
    CAST(20 AS TINYINT),
    t.codigo_movimiento,
    t.nombre_movimiento,
    CAST(CASE
        WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN N'ENTRADA'
        WHEN t.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION',N'AJUSTE_NEGATIVO') THEN N'SALIDA'
        WHEN t.codigo_movimiento IN(N'RESERVA',N'LIBERACION_RESERVA') THEN N'TRASPASO'
        ELSE N'INFORMATIVO'
    END AS NVARCHAR(20)),
    CAST(CASE
        WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN N'+'
        WHEN t.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION',N'AJUSTE_NEGATIVO') THEN N'-'
        WHEN t.codigo_movimiento IN(N'RESERVA',N'LIBERACION_RESERVA') THEN N'↔'
        ELSE NULL
    END AS NCHAR(1)),
    m.monto_movimiento,
    CAST(CASE WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN m.monto_movimiento ELSE 0 END AS DECIMAL(18,2)),
    CAST(CASE WHEN t.codigo_movimiento IN(N'APLICACION_CARGO',N'DEVOLUCION',N'AJUSTE_NEGATIVO') THEN m.monto_movimiento ELSE 0 END AS DECIMAL(18,2)),
    CAST(CASE
        WHEN t.codigo_movimiento=N'RESERVA' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'LIBERACION_RESERVA' THEN m.monto_movimiento
        WHEN t.codigo_movimiento=N'APLICACION_CARGO' AND ISNULL(m.fondo_origen,'D')='D' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'DEVOLUCION' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN m.monto_movimiento
        WHEN t.codigo_movimiento=N'AJUSTE_NEGATIVO' THEN -m.monto_movimiento
        ELSE 0
    END AS DECIMAL(18,2)),
    CAST(CASE
        WHEN t.codigo_movimiento=N'RESERVA' THEN m.monto_movimiento
        WHEN t.codigo_movimiento=N'LIBERACION_RESERVA' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'APLICACION_CARGO' AND m.fondo_origen='R' THEN -m.monto_movimiento
        ELSE 0
    END AS DECIMAL(18,2)),
    CAST(CASE
        WHEN t.codigo_movimiento=N'APLICACION_CARGO' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'DEVOLUCION' THEN -m.monto_movimiento
        WHEN t.codigo_movimiento=N'AJUSTE_POSITIVO' THEN m.monto_movimiento
        WHEN t.codigo_movimiento=N'AJUSTE_NEGATIVO' THEN -m.monto_movimiento
        ELSE 0
    END AS DECIMAL(18,2)),
    CAST(CASE
        WHEN rev.id_reversa_garantia IS NOT NULL THEN N'REVERTIDA'
        WHEN d.id_devolucion_garantia IS NOT NULL THEN d.estado_devolucion
        ELSE N'VIGENTE'
    END AS NVARCHAR(20)),
    m.fondo_origen,
    COALESCE(cl.id_local,cs.id_local),
    m.id_documento_cobro,
    m.id_pago,
    m.id_cargo_contrato_local,
    m.id_cargo_salida,
    d.medio_devolucion,
    td.nombre_cuenta,
    COALESCE(
        d.referencia_transferencia,
        d.numero_cheque,
        CASE WHEN m.id_pago IS NOT NULL THEN CONCAT(N'Pago #',m.id_pago) END
    ),
    m.observaciones,
    COALESCE(m.id_usuario_autoriza,m.id_usuario_solicita,d.id_usuario),
    CAST(NULL AS NVARCHAR(20)),
    CAST(NULL AS INT)
FROM dbo.msp_movimientos_garantia m
INNER JOIN dbo.msp_tipos_movimiento_garantia t
    ON t.id_tipo_movimiento_garantia=m.id_tipo_movimiento_garantia
LEFT JOIN dbo.msp_garantia_devoluciones d
    ON d.id_movimiento_garantia=m.id_movimiento_garantia
LEFT JOIN dbo.msp_tesoreria_cuentas td
    ON td.id_cuenta_tesoreria=d.id_cuenta_tesoreria
LEFT JOIN dbo.msp_garantia_reversas rev
    ON rev.id_garantia_tienda=m.id_garantia_tienda
   AND (
        (rev.tipo_origen=N'APLICACION' AND rev.id_origen=m.id_movimiento_garantia)
        OR
        (rev.tipo_origen=N'DEVOLUCION' AND rev.id_origen=d.id_devolucion_garantia)
   )
LEFT JOIN dbo.msp_cargos_contrato_local ccl
    ON ccl.id_cargo_contrato_local=m.id_cargo_contrato_local
LEFT JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local=ccl.id_contrato_local
LEFT JOIN dbo.msp_cargos_salida cs
    ON cs.id_cargo_salida=m.id_cargo_salida

UNION ALL

SELECT
    rv.id_garantia_tienda,
    rv.id_garantia,
    CAST(N'REVERSA' AS NVARCHAR(20)),
    rv.id_reversa_garantia,
    rv.fecha_reversa,
    rv.fecha_registro,
    CAST(30 AS TINYINT),
    CAST(CONCAT(N'REVERSA_',rv.tipo_origen) AS NVARCHAR(50)),
    CAST(CASE rv.tipo_origen
        WHEN N'RECEPCION' THEN N'Reversa de recepcion'
        WHEN N'DEVOLUCION' THEN N'Reversa de devolucion'
        WHEN N'APLICACION' THEN N'Reversa de aplicacion'
        ELSE N'Reversa de garantia'
    END AS NVARCHAR(120)),
    CAST(CASE WHEN rv.tipo_origen=N'RECEPCION' THEN N'SALIDA' ELSE N'INFORMATIVO' END AS NVARCHAR(20)),
    CAST(CASE WHEN rv.tipo_origen=N'RECEPCION' THEN N'-' ELSE NULL END AS NCHAR(1)),
    rv.monto_reversa,
    CAST(0 AS DECIMAL(18,2)),
    CAST(CASE WHEN rv.tipo_origen=N'RECEPCION' THEN rv.monto_reversa ELSE 0 END AS DECIMAL(18,2)),
    CAST(CASE WHEN rv.tipo_origen=N'RECEPCION' THEN -rv.monto_reversa ELSE 0 END AS DECIMAL(18,2)),
    CAST(0 AS DECIMAL(18,2)),
    CAST(CASE WHEN rv.tipo_origen=N'RECEPCION' THEN -rv.monto_reversa ELSE 0 END AS DECIMAL(18,2)),
    CAST(N'REGISTRADA' AS NVARCHAR(20)),
    ma.fondo_origen,
    COALESCE(cl.id_local,cs.id_local),
    ma.id_documento_cobro,
    ma.id_pago,
    ma.id_cargo_contrato_local,
    ma.id_cargo_salida,
    COALESCE(rr.medio_recepcion,dd.medio_devolucion),
    COALESCE(tr.nombre_cuenta,td.nombre_cuenta),
    COALESCE(
        rr.referencia,
        rr.numero_cheque,
        dd.referencia_transferencia,
        dd.numero_cheque,
        CONCAT(rv.tipo_origen,N' #',rv.id_origen)
    ),
    rv.motivo,
    rv.id_usuario,
    rv.tipo_origen,
    rv.id_origen
FROM dbo.msp_garantia_reversas rv
LEFT JOIN dbo.msp_garantia_recepciones rr
    ON rv.tipo_origen=N'RECEPCION'
   AND rr.id_recepcion_garantia=rv.id_origen
LEFT JOIN dbo.msp_garantia_devoluciones dd
    ON rv.tipo_origen=N'DEVOLUCION'
   AND dd.id_devolucion_garantia=rv.id_origen
LEFT JOIN dbo.msp_movimientos_garantia ma
    ON (
        rv.tipo_origen=N'APLICACION'
        AND ma.id_movimiento_garantia=rv.id_origen
    ) OR (
        rv.tipo_origen=N'DEVOLUCION'
        AND ma.id_movimiento_garantia=dd.id_movimiento_garantia
    )
LEFT JOIN dbo.msp_cargos_contrato_local ccl
    ON ccl.id_cargo_contrato_local=ma.id_cargo_contrato_local
LEFT JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local=ccl.id_contrato_local
LEFT JOIN dbo.msp_cargos_salida cs
    ON cs.id_cargo_salida=ma.id_cargo_salida
OUTER APPLY (
    SELECT TOP (1) tc.nombre_cuenta
    FROM dbo.msp_tesoreria_movimientos tm
    LEFT JOIN dbo.msp_tesoreria_cuentas tc
        ON tc.id_cuenta_tesoreria=tm.id_cuenta_tesoreria
    WHERE tm.id_recepcion_garantia=rr.id_recepcion_garantia
    ORDER BY tm.id_movimiento_tesoreria DESC
) tr
LEFT JOIN dbo.msp_tesoreria_cuentas td
    ON td.id_cuenta_tesoreria=dd.id_cuenta_tesoreria;
GO

/*
 La vista final agrega identidad comercial y saldos acumulados. La aplicacion
 debe ordenar por nombre_arrendatario, rut, fecha_evento, prioridad_evento,
 fecha_registro e id_evento para obtener el libro alfabetico completo. La
 prioridad deja la constancia informativa de reversa despues de su ajuste.
*/
CREATE OR ALTER VIEW dbo.msp_vw_garantias_historial_arrendatario
AS
WITH base AS (
    SELECT
        r.id_arrendatario,
        r.nombre_locatario AS nombre_arrendatario,
        r.rut,
        r.id_tienda,
        r.nombre_comercial AS tienda,
        r.id_contrato_arriendo,
        r.locales,
        e.*,
        l.cdo_local AS local_destino
    FROM dbo.msp_vw_garantias_historial_eventos e
    INNER JOIN dbo.msp_vw_garantias_tienda_resumen r
        ON r.id_garantia_tienda=e.id_garantia_tienda
    LEFT JOIN dbo.msp_locales l
        ON l.id_local=e.id_local_destino
)
SELECT
    b.*,
    ROW_NUMBER() OVER (
        PARTITION BY b.id_garantia_tienda
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.origen_evento,b.id_evento
    ) AS secuencia_garantia,
    SUM(b.impacto_disponible) OVER (
        PARTITION BY b.id_garantia_tienda
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_disponible_garantia,
    SUM(b.impacto_reservado) OVER (
        PARTITION BY b.id_garantia_tienda
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_reservado_garantia,
    SUM(b.impacto_total) OVER (
        PARTITION BY b.id_garantia_tienda
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_total_garantia,
    SUM(b.impacto_disponible) OVER (
        PARTITION BY b.id_arrendatario
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.id_garantia_tienda,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_disponible_arrendatario,
    SUM(b.impacto_reservado) OVER (
        PARTITION BY b.id_arrendatario
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.id_garantia_tienda,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_reservado_arrendatario,
    SUM(b.impacto_total) OVER (
        PARTITION BY b.id_arrendatario
        ORDER BY b.fecha_evento,b.prioridad_evento,b.fecha_registro,b.id_garantia_tienda,b.origen_evento,b.id_evento
        ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
    ) AS saldo_total_arrendatario
FROM base b;
GO

IF DATABASE_PRINCIPAL_ID(N'portalgp_runtime_role') IS NOT NULL
BEGIN
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_historial_eventos TO portalgp_runtime_role;
    GRANT SELECT ON OBJECT::dbo.msp_vw_garantias_historial_arrendatario TO portalgp_runtime_role;
END;
GO

PRINT N'Historial general de garantias por arrendatario - bloque 1 instalado correctamente.';
PRINT N'No se modificaron montos ni movimientos financieros.';
GO
