# Analisis Contrato vs Ocupacion (MSP)

Fecha: 2026-03-27

## Contexto

Hoy el sistema tiene dos modelos para representar casi lo mismo:

- `msp_ocupacion_locales` (operativo/facturacion).
- `msp_contratos_arriendo` + `msp_contrato_locales` (contractual).

En la practica, la contabilidad y la facturacion usan mayormente ocupacion.

## Hallazgos clave

1. Facturacion y documentos dependen de ocupacion.
   - `db/msp_documento_pago.sql` usa `msp_ocupacion_locales` para arriendo y mapeo de servicios (ej: lineas 484-493, 511-523, 627-630).
   - `cobros/operacion_mensual.php` y `cobros/operacion_individual.php` tambien calculan en base a ocupacion.

2. El modelo de contrato existe, pero esta en transicion.
   - `db/msp_fase1_contrato_locales.sql` migra desde ocupacion (lineas 146-199).
   - `db/msp_fase2_garantia_contrato_local.sql` y `db/msp_fase3_cargos_contrato_local.sql` mantienen compatibilidad legacy.

3. Hay doble escritura y no siempre sincronizada.
   - `contratos/guardar.php` crea contrato y contrato-local, pero no escribe ocupacion.
   - `contratos/actualizar.php` si sincroniza ocupacion (lineas 423-440).
   - `tiendas/guardar.php` modifica ocupacion directamente (lineas 220-303), sin sincronizar contrato-local.

4. Reglas de integridad aun mezclan ambos mundos.
   - `db/msp_deudores_garantia.sql` valida garantias/cargos contra `msp_ocupacion_locales` (lineas 317-359).
   - Esto vuelve fragile la consistencia si contrato y ocupacion divergen.

## Diagnostico

El problema principal no es "tener contrato y ocupacion", sino tener **dos fuentes de verdad** sin una orquestacion unica.

## Solucion recomendada (pragmatica)

### Decidir una fuente canonica operativa ahora

Dado tu uso actual, recomiendo:

- Canonico operativo: `msp_ocupacion_locales`.
- Contrato: mantenerlo como capa tecnica/legal sincronizada automaticamente (no como dato que el usuario deba mantener en paralelo).

### Implementar una sola puerta de escritura

Crear SP unica (ejemplo: `dbo.msp_upsert_tienda_ocupacion`) y hacer que **todas** estas rutas la usen:

- `tiendas/guardar.php`
- `contratos/guardar.php`
- `contratos/actualizar.php`
- `contratos/import_service_confirmar.php`

La SP debe, en una sola transaccion:

1. Reemplazar ocupacion vigente de la tienda (con fechas).
2. Crear/actualizar contrato activo de la tienda (si aplica).
3. Sincronizar `msp_contrato_locales` con la ocupacion resultante.
4. Cerrar relaciones que salen.
5. Ejecutar actualizacion de estado de locales.

## Quick wins (alto impacto)

1. Hotfix inmediato: en `contratos/guardar.php` agregar sincronizacion a `msp_ocupacion_locales` al crear contrato.
2. Bloquear edicion dual temporal:
   - O se edita ocupacion en Tiendas,
   - o se edita en Contratos,
   - pero no ambas rutas sin SP comun.
3. Agregar monitoreo diario de desalineacion (query abajo).

## Query de control de desalineacion

```sql
;WITH ocupacion_activa AS (
    SELECT DISTINCT
        ol.id_tienda,
        ol.id_local
    FROM dbo.msp_ocupacion_locales ol
    WHERE ol.fecha_inicio <= CONVERT(date, SYSDATETIME())
      AND (ol.fecha_termino IS NULL OR ol.fecha_termino >= CONVERT(date, SYSDATETIME()))
),
contrato_local_activo AS (
    SELECT DISTINCT
        c.id_tienda,
        cl.id_local
    FROM dbo.msp_contrato_locales cl
    INNER JOIN dbo.msp_contratos_arriendo c
        ON c.id_contrato_arriendo = cl.id_contrato_arriendo
    WHERE cl.estado_relacion = 1
      AND c.estado_contrato IN (1,2,3)
)
SELECT
    COALESCE(o.id_tienda, ca.id_tienda) AS id_tienda,
    COALESCE(o.id_local, ca.id_local) AS id_local,
    CASE
        WHEN o.id_tienda IS NULL THEN 'SOLO_CONTRATO'
        WHEN ca.id_tienda IS NULL THEN 'SOLO_OCUPACION'
        ELSE 'OK'
    END AS estado_cruce
FROM ocupacion_activa o
FULL OUTER JOIN contrato_local_activo ca
    ON ca.id_tienda = o.id_tienda
   AND ca.id_local = o.id_local
WHERE o.id_tienda IS NULL
   OR ca.id_tienda IS NULL;
```

## Resultado esperado

Con esto bajas complejidad operativa ahora (contabilidad sigue funcionando con ocupacion), sin perder el modelo contractual para cuando empieces a usarlo de verdad.

