SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
CREATE OR ALTER VIEW dbo.msp_vw_garantias_control_integral
AS
WITH movimientos AS (
 SELECT mg.id_garantia,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=2 THEN mg.monto_movimiento ELSE 0 END) reserva,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=3 THEN mg.monto_movimiento ELSE 0 END) liberacion,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=4 AND mg.fondo_origen='D' THEN mg.monto_movimiento ELSE 0 END) aplicado_d,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=4 AND mg.fondo_origen='R' THEN mg.monto_movimiento ELSE 0 END) aplicado_r,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=5 THEN mg.monto_movimiento ELSE 0 END) devuelto,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=6 THEN mg.monto_movimiento ELSE 0 END) ajuste_pos,
  SUM(CASE WHEN mg.id_tipo_movimiento_garantia=7 THEN mg.monto_movimiento ELSE 0 END) ajuste_neg
 FROM dbo.msp_movimientos_garantia mg GROUP BY mg.id_garantia
), recepciones AS (
 SELECT id_garantia,SUM(CASE WHEN estado_recepcion=N'CONFIRMADA' THEN monto_recibido ELSE 0 END) recibido
 FROM dbo.msp_garantia_recepciones GROUP BY id_garantia
), base AS (
 SELECT g.id_garantia,COALESCE(cl.id_contrato_arriendo,g.id_contrato_arriendo) id_contrato_arriendo,g.id_contrato_local,
  COALESCE(cl.id_local,g.id_local) id_local,c.id_tienda,c.id_arrendatario,a.nombre_locatario,a.rut,t.nombre_comercial,
  l.cdo_local,l.desc_local,g.fecha_constitucion,g.estado_garantia,CAST(g.monto_inicial AS DECIMAL(18,2)) pactado,
  CAST(ISNULL(r.recibido,0) AS DECIMAL(18,2)) recibido,
  CAST(ISNULL(m.reserva,0)-ISNULL(m.liberacion,0)-ISNULL(m.aplicado_r,0) AS DECIMAL(18,2)) reservado,
  CAST(ISNULL(m.aplicado_d,0)+ISNULL(m.aplicado_r,0) AS DECIMAL(18,2)) aplicado,
  CAST(ISNULL(m.devuelto,0) AS DECIMAL(18,2)) devuelto,
  CAST(g.monto_inicial-ISNULL(m.reserva,0)+ISNULL(m.liberacion,0)-ISNULL(m.aplicado_d,0)-ISNULL(m.devuelto,0)+ISNULL(m.ajuste_pos,0)-ISNULL(m.ajuste_neg,0) AS DECIMAL(18,2)) disponible_modelo
 FROM dbo.msp_garantias g
 LEFT JOIN dbo.msp_contrato_locales cl ON cl.id_contrato_local=g.id_contrato_local
 JOIN dbo.msp_contratos_arriendo c ON c.id_contrato_arriendo=COALESCE(cl.id_contrato_arriendo,g.id_contrato_arriendo)
 JOIN dbo.msp_arrendatarios a ON a.id_arrendatario=c.id_arrendatario JOIN dbo.msp_tiendas t ON t.id_tienda=c.id_tienda
 JOIN dbo.msp_locales l ON l.id_local=COALESCE(cl.id_local,g.id_local)
 LEFT JOIN movimientos m ON m.id_garantia=g.id_garantia LEFT JOIN recepciones r ON r.id_garantia=g.id_garantia
), metricas AS (
 SELECT b.*,
  CAST(CASE WHEN b.pactado-b.recibido>0 THEN b.pactado-b.recibido ELSE 0 END AS DECIMAL(18,2)) pendiente,
  CAST(CASE WHEN b.disponible_modelo<(b.recibido-b.aplicado-b.devuelto) THEN CASE WHEN b.disponible_modelo>0 THEN b.disponible_modelo ELSE 0 END ELSE CASE WHEN b.recibido-b.aplicado-b.devuelto>0 THEN b.recibido-b.aplicado-b.devuelto ELSE 0 END END AS DECIMAL(18,2)) disponible
 FROM base b
)
SELECT m.id_garantia,m.id_contrato_arriendo,m.id_contrato_local,m.id_local,m.id_tienda,m.id_arrendatario,m.nombre_locatario,m.rut,m.nombre_comercial,m.cdo_local,m.desc_local,m.fecha_constitucion,m.estado_garantia,
 m.pactado monto_pactado,m.recibido monto_recibido,m.pendiente monto_pendiente_recepcion,m.reservado monto_reservado,m.aplicado monto_aplicado,m.devuelto monto_devuelto,m.disponible monto_disponible,
 CASE WHEN m.recibido=0 THEN N'NO_RECIBIDA' WHEN m.recibido<m.pactado THEN N'PARCIAL' WHEN m.recibido=m.pactado THEN N'COMPLETA' ELSE N'EXCEDIDA' END estado_recepcion,
 CASE WHEN m.pactado=0 THEN N'SIN_MONTO' WHEN m.recibido=0 THEN N'NO_RECIBIDA' WHEN m.recibido<m.pactado THEN N'RECEPCION_PARCIAL' WHEN m.recibido>m.pactado THEN N'RECEPCION_EXCEDIDA' WHEN m.reservado>0 THEN N'SALDO_RESERVADO' WHEN m.recibido-m.aplicado-m.devuelto<0 THEN N'INCONSISTENCIA_SALDO' ELSE N'OK' END alerta_codigo,
 CASE WHEN m.pactado=0 THEN N'Garantía configurada sin monto pactado.' WHEN m.recibido=0 THEN N'Existe monto pactado sin recepción confirmada.' WHEN m.recibido<m.pactado THEN N'La recepción de garantía está incompleta.' WHEN m.recibido>m.pactado THEN N'El monto recibido supera lo pactado.' WHEN m.reservado>0 THEN N'Existe saldo reservado por cargos.' WHEN m.recibido-m.aplicado-m.devuelto<0 THEN N'Aplicaciones y devoluciones superan lo recibido.' ELSE N'Sin alertas.' END alerta_descripcion,
 CASE WHEN m.recibido-m.aplicado-m.devuelto<0 OR m.recibido>m.pactado THEN 3 WHEN m.pactado>0 AND m.recibido<m.pactado THEN 2 WHEN m.pactado=0 OR m.reservado>0 THEN 1 ELSE 0 END alerta_nivel
FROM metricas m;
GO
PRINT N'Reporte integral de garantías instalado.';
GO
