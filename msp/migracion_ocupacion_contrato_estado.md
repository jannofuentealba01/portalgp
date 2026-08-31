# Migración ocupación -> contrato (estado)

Fecha: 2026-03-30

## Decisión recomendada
- Mantener `msp_contratos_arriendo` + `msp_contrato_locales` como fuente única de verdad para asignación `tienda <-> local`.
- Dejar `msp_ocupacion_locales` solo como compatibilidad temporal (o eliminarla en fase final).

## Qué se migró en código
- `cobros/operacion_mensual.php`: ya no usa `msp_ocupacion_locales`.
- `db/msp_documento_pago.sql`: ya no usa `msp_ocupacion_locales`.
- `cobros/operacion_individual.php`: migrado a joins con `msp_contrato_locales` + `msp_contratos_arriendo`.
- `documentos_cobro/index.php`: migrado a joins con `msp_contrato_locales` + `msp_contratos_arriendo`.

## Regla de vigencia aplicada
- Relación local-contrato activa:
  - `cl.estado_relacion = 1`
  - `cl.fecha_inicio <= EOMONTH(periodo|fecha)`
  - `cl.fecha_termino IS NULL OR cl.fecha_termino >= periodo|fecha`
- Contrato activo:
  - `ca.fecha_inicio <= EOMONTH(periodo|fecha)`
  - `ca.fecha_termino_efectiva IS NULL OR ca.fecha_termino_efectiva >= periodo|fecha`
  - `ca.estado_contrato IN (1,2,3)`

## Riesgo pendiente (importante)
- Si existen múltiples contratos activos para el mismo local en la misma fecha, algunos reportes podrían duplicar montos.
- Mitigación recomendada: en consultas críticas de servicios, usar `OUTER APPLY (SELECT TOP 1 ...)` para resolver un único contrato/local por fecha de lectura.

## Próximos pasos recomendados
1. Crear restricción operativa para impedir traslape de vigencias por `id_local` en `msp_contrato_locales`.
2. Cerrar migración: eliminar lecturas de `msp_ocupacion_locales` en vistas/reportes restantes.
3. Cuando no haya dependencias, deprecar formalmente `msp_ocupacion_locales`.
