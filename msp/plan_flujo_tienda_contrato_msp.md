# Plan Flujo Tienda-Contrato MSP

## 1) Objetivo

Definir un flujo estable donde:

- `Tienda` se mantiene como entidad comercial persistente (no se elimina por término de contrato).
- `Contrato` gobierna la operación (alta, edición, término operativo, cierre financiero).
- `Ocupación física` se deriva del contrato y sus locales.
- Importación masiva principal = **Importar Contratos** (incluye tienda + ocupación + garantía).

## 2) Estado actual (resumen de revisión)

- `contratos/guardar.php` y `contratos/actualizar.php` ya gestionan `msp_contrato_locales` y sincronizan `msp_ocupacion_locales`.
- `contratos/cerrar.php` quedó como **término operativo** (estado contrato `3`, libera ocupación/locales).
- `contratos/finalizar_cierre_financiero.php` hace cierre definitivo (estado contrato `4`) con validaciones de período/deuda/garantía.
- `contratos/importar.php` reutiliza `tiendas/importar.php` con contexto `contratos`.
- `tiendas/confirmar_importacion.php` todavía contiene lógica fuerte de contratos/garantía en el mismo archivo (acoplamiento legacy).
- UI principal ya está orientada a contratos, pero quedan puntos de soporte en `tiendas/`.

## 3) Riesgos actuales

- Doble “fuente de verdad” de importación (tiendas vs contratos) en el mismo backend.
- Lógica de negocio contractual distribuida entre `contratos/*` y `tiendas/*`.
- Riesgo de regresión al tocar importación por alto acoplamiento.
- Estado `3` históricamente “En revisión” y hoy usado como “En cierre financiero” (debe quedar explícito y uniforme).

## 4) Decisión de arquitectura

### 4.1 Modelo canónico

- `msp_tiendas`: entidad estable del arrendatario/cliente.
- `msp_contratos_arriendo`: ciclo contractual.
- `msp_contrato_locales`: relación contrato-local por período.
- `msp_ocupacion_locales`: proyección física vigente (sincronizada desde contrato).
- `msp_garantias`: garantía por local/contrato.

### 4.2 Ciclo de contrato

- `1/2` = operativo (creación/vigente).
- `3` = **En cierre financiero** (locales liberados físicamente; pendiente conciliación).
- `4` = cerrado financiero definitivo.
- `5` = anulado.

## 5) Flujo objetivo (funcional)

1. **Importar Contratos** (Excel maestro):
   - crea/actualiza arrendatario-tienda si corresponde,
   - crea contrato activo,
   - crea/actualiza locales de contrato,
   - sincroniza ocupación física,
   - crea/actualiza garantía.

2. **Operación diaria**:
   - edición de contrato y locales desde `contratos/editar.php`,
   - operación de garantía desde contrato,
   - cargos/documentos/pagos normales.

3. **Término operativo**:
   - registrar fecha de salida real,
   - cerrar ocupación y pasar a estado `3`.

4. **Cierre financiero**:
   - seleccionar período de corte,
   - validar cierre mensual + deuda + garantía,
   - cerrar definitivo (`4`).

## 6) Plan de implementación

## Fase A - Consolidación de reglas (sin romper flujo actual)

- Estandarizar label de estado `3` en todo MSP: “En cierre financiero”.
- Prohibir creación/edición contractual desde `tiendas/guardar.php` (solo soporte comercial de tienda).
- Mantener `tiendas` fuera del menú operativo principal.

## Fase B - Importación canónica

- Crear servicio único de importación (por ejemplo `contratos/import_service.php`) y mover lógica de:
  - creación/actualización tienda,
  - contrato,
  - contrato-local,
  - ocupación,
  - garantía.
- Dejar `contratos/importar.php` y `contratos/confirmar_importacion.php` como entrypoints reales.
- Convertir `tiendas/importar.php` y `tiendas/confirmar_importacion.php` en wrappers mínimos o deprecarlos.
- Mantener plantilla única de importación contractual.

### Avance Fase B (implementado)

- Lógica de preview migrada a `contratos/import_service_preview.php`.
- Lógica de confirmación migrada a `contratos/import_service_confirmar.php`.
- `contratos/importar.php` y `contratos/confirmar_importacion.php` quedaron como entrypoints canónicos.
- `tiendas/importar.php` y `tiendas/confirmar_importacion.php` quedaron como wrappers mínimos hacia contratos.
- Ajuste de trazabilidad: en historial/observaciones de confirmación se registra origen según contexto (`contratos`/`tiendas`).

## Fase C - Reglas de transición de estados

- En `contratos/cerrar.php`:
  - solo término operativo + estado `3`.
- En `contratos/finalizar_cierre_financiero.php`:
  - bloquear si período no existe/cerrado,
  - bloquear si quedan docs/cargos/reservas.
- Registrar ambos hitos en historial/bitácora con tipos de evento distintos.

## Fase D - UI/UX orientada a contrato

- `contratos/index.php`:
  - acciones separadas: término operativo / cierre financiero.
  - helper directo a `cobros/operacion_mensual.php` con período.
- `arrendatarios/index.php`:
  - mantener solo importación arrendatarios.
- `msp_menu.php`:
  - contrato como módulo principal para ciclo comercial.

### Avance Fase D (implementado)

- `msp_menu.php`: `Contratos` quedó primero en Administración (módulo principal).
- `contratos/index.php`: agregado acceso directo a `Facturación mensual` (`cobros/operacion_mensual.php`).
- `deuda_garantia/index.php`: accesos rápidos cambiados de tienda a contrato para navegación operativa.
- `tiendas/index.php`: UI marcada como soporte comercial; importación de tiendas rotulada como legacy y con guía explícita a importar contratos.
- `tiendas/guardar.php`: retirado flujo legacy de creación/actualización de contrato y garantía; ahora guarda solo datos comerciales de tienda + ocupación.
- `tiendas/importar.php` y `tiendas/confirmar_importacion.php`: deprecadas de forma explícita; redirigen a `contratos/index.php` con mensaje para usar importación canónica de contratos.

## Fase E - Endurecimiento de datos (BD)

- Mantener `msp_tiendas.fecha_termino` como metadato comercial.
- Mantener constraints de no solapamiento en ocupación/contrato-local.
- (Opcional) agregar check/index para consultas de estado `3` y fecha término efectiva.

### Avance Fase E (implementado parcial)

- Nuevo patch `db/patch_contrato_indices_operativos.sql`:
  - redefine `UX_msp_contratos_tienda_activo` para solo estado operativo `(1,2)`.
  - agrega `IX_msp_contratos_cierre_financiero` para bandeja estado `3`.
  - agrega `IX_msp_contratos_fecha_termino_efectiva` para cierres/reportes.
- Integrado al instalador core `db/msp_instalar_core.sql`.
- Integrado a guía `db/README_INSTALACION_MSP.md`.
- Ajuste base en `db/msp_deudores_garantia.sql`:
  - estado `3` descrito como “En cierre financiero”.
  - índice único por tienda activo en `(1,2)`.
- Ajuste en `db/msp_fase1_contrato_locales.sql`:
  - validaciones/vista de relación activa alineadas a contratos operativos `(1,2)`.
- Ajuste en `db/msp_fase4_sp_negocio.sql`:
  - `msp_contrato_preparar_cierre` ahora evalúa cierre solo para estado `3`.
  - `msp_contrato_cerrar` ahora cierra a `4` únicamente desde estado `3`.

## 7) Casos de aceptación (QA)

1. Importación inicial desde contratos crea tienda+ocupación+contrato+garantía.
2. Término operativo libera local el mismo día y contrato pasa a `3`.
3. Intento de cierre financiero sin cierre mensual del período falla con mensaje claro.
4. Intento de cierre financiero con deuda o reserva de garantía falla.
5. Cierre financiero exitoso cambia contrato a `4` y mantiene histórico completo.
6. Nueva contratación sobre la misma tienda funciona (nuevo contrato activo) sin duplicar tienda.

## 8) Orden recomendado de trabajo

1. Fase A.
2. Fase C (ya avanzada, ajustar bordes).
3. Fase B (desacoplar importación).
4. Fase D.
5. Fase E.

---

Este plan prioriza estabilidad: mantener datos históricos, evitar romper importaciones y concentrar operación en contratos.
