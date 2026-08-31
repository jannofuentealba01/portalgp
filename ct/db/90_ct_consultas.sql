/*
  Script: 90_ct_consultas.sql
  Objetivo:
  - Consultas base para flujos prediales
*/

/* 1) Terrenos con catalogos principales */
SELECT
    t.id_terreno,
    t.rol_asignado,
    t.superficie_m2,
    c.nombre AS comuna,
    ep.nombre AS estado_predial,
    ec.nombre AS estado_comercial,
    ti.nombre AS tipo_inmueble
FROM dbo.ct_terreno t
INNER JOIN dbo.ct_comuna c
    ON c.id_comuna = t.id_comuna
INNER JOIN dbo.ct_estado_terreno_predial ep
    ON ep.id_estado_predial = t.id_estado_predial
INNER JOIN dbo.ct_estado_terreno_comercial ec
    ON ec.id_estado_comercial = t.id_estado_comercial
INNER JOIN dbo.ct_tipo_inmueble ti
    ON ti.id_tipo_inmueble = t.id_tipo_inmueble;

/* 2) Titulares vigentes por terreno */
SELECT
    tt.id_terreno,
    te.rut,
    te.nombre_razon_social,
    tt.porcentaje_derecho,
    tt.vigente_desde,
    tt.vigente_hasta
FROM dbo.ct_titularidad_terreno tt
INNER JOIN dbo.ct_tercero te
    ON te.id_tercero = tt.id_tercero
WHERE tt.vigente_hasta IS NULL;

/* 3) Historial de cambios de estado (ultimos 50) */
SELECT TOP (50)
    h.id_historial_estado,
    h.id_terreno,
    h.tipo_estado,
    h.id_estado_anterior,
    h.id_estado_nuevo,
    h.fecha_cambio,
    h.id_operacion,
    h.id_venta,
    h.id_usuario
FROM dbo.ct_historial_estado_terreno h
ORDER BY h.fecha_cambio DESC;

/* 4) Operaciones prediales con sus terrenos */
SELECT
    op.id_operacion,
    op.tipo_operacion,
    op.fecha_operacion,
    op.documento_fuente,
    ot.id_terreno,
    ot.rol_en_operacion
FROM dbo.ct_operacion_predial op
INNER JOIN dbo.ct_operacion_terreno ot
    ON ot.id_operacion = op.id_operacion
ORDER BY op.fecha_operacion DESC, op.id_operacion DESC;
