/*
  Garantías MSP: el saldo utilizable nace de recepciones CONFIRMADAS.
  monto_inicial se conserva como monto pactado contractual.
  Ejecutar después de patch_garantias_tesoreria_base.sql.
*/
CREATE OR ALTER VIEW dbo.msp_vw_garantias_resumen
AS
SELECT
    g.id_garantia,
    g.id_contrato_local,
    COALESCE(cl.id_contrato_arriendo, g.id_contrato_arriendo) AS id_contrato_arriendo,
    COALESCE(cl.id_local, g.id_local) AS id_local,
    c.id_tienda,
    c.id_arrendatario,
    g.fecha_constitucion,
    g.estado_garantia,
    g.monto_inicial,
    CAST(ISNULL(rec.monto_recibido, 0) AS DECIMAL(18,2)) AS monto_recibido,
    CAST(ISNULL(mov.total_reserva, 0) AS DECIMAL(18,2)) AS total_reserva,
    CAST(ISNULL(mov.total_liberacion, 0) AS DECIMAL(18,2)) AS total_liberacion,
    CAST(ISNULL(mov.total_aplicado_disponible, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_disponible,
    CAST(ISNULL(mov.total_aplicado_reservado, 0) AS DECIMAL(18,2)) AS total_aplicado_desde_reservado,
    CAST(ISNULL(mov.total_devuelto, 0) AS DECIMAL(18,2)) AS total_devuelto,
    CAST(ISNULL(mov.total_ajuste_positivo, 0) AS DECIMAL(18,2)) AS total_ajuste_positivo,
    CAST(ISNULL(mov.total_ajuste_negativo, 0) AS DECIMAL(18,2)) AS total_ajuste_negativo,
    CAST(
        ISNULL(rec.monto_recibido, 0)
        - ISNULL(mov.total_reserva, 0)
        + ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_disponible, 0)
        - ISNULL(mov.total_devuelto, 0)
        + ISNULL(mov.total_ajuste_positivo, 0)
        - ISNULL(mov.total_ajuste_negativo, 0)
        AS DECIMAL(18,2)
    ) AS saldo_disponible,
    CAST(
        ISNULL(mov.total_reserva, 0)
        - ISNULL(mov.total_liberacion, 0)
        - ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_reservado,
    CAST(
        ISNULL(mov.total_aplicado_disponible, 0)
        + ISNULL(mov.total_aplicado_reservado, 0)
        AS DECIMAL(18,2)
    ) AS saldo_aplicado
FROM dbo.msp_garantias g
LEFT JOIN dbo.msp_contrato_locales cl
    ON cl.id_contrato_local = g.id_contrato_local
INNER JOIN dbo.msp_contratos_arriendo c
    ON c.id_contrato_arriendo = COALESCE(cl.id_contrato_arriendo, g.id_contrato_arriendo)
OUTER APPLY (
    SELECT SUM(r.monto_recibido) AS monto_recibido
    FROM dbo.msp_garantia_recepciones r
    WHERE r.id_garantia = g.id_garantia
      AND r.estado_recepcion = N'CONFIRMADA'
) rec
OUTER APPLY (
    SELECT
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 2 THEN mg.monto_movimiento ELSE 0 END) AS total_reserva,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 3 THEN mg.monto_movimiento ELSE 0 END) AS total_liberacion,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'D' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_disponible,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 4 AND mg.fondo_origen = 'R' THEN mg.monto_movimiento ELSE 0 END) AS total_aplicado_reservado,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 5 THEN mg.monto_movimiento ELSE 0 END) AS total_devuelto,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 6 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_positivo,
        SUM(CASE WHEN mg.id_tipo_movimiento_garantia = 7 THEN mg.monto_movimiento ELSE 0 END) AS total_ajuste_negativo
    FROM dbo.msp_movimientos_garantia mg
    WHERE mg.id_garantia = g.id_garantia
) mov;
