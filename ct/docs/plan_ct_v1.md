# Plan CT V1

## Base de trabajo

Este plan toma como base real la carpeta `ct/db`, que hoy contiene lo necesario para una primera version operativa del modulo CT.

No se toma como referencia principal `ct/docs/modulo_terrenos.dbml`, porque ese diseño tiene mayor alcance que el requerido actualmente.

Capas vigentes en `ct/db`:

- `Predial`
- `Construccion`
- `Tributaria`
- `Contabilidad`

## Objetivo de la V1

Centralizar la gestion de terrenos en un solo sistema, de forma que distintas areas trabajen sobre el mismo terreno y quede trazabilidad de su ciclo de vida.

La V1 debe permitir:

- registrar terrenos
- mantener estado predial y comercial
- registrar titularidad
- registrar operaciones relevantes
- registrar ficha de arquitectura/legal
- registrar avaluo, tasacion y venta
- soportar trazabilidad operativa minima entre areas

## Estado actual del plan (actualizado 2026-04-13)

Leyenda de avance:

- `IMPLEMENTADO`: disponible en modulo operativo (UI + backend del proyecto).
- `PARCIAL`: resuelto en base/procedimientos, pero sin flujo completo en UI o sin validaciones finales de negocio.
- `PENDIENTE`: aun no implementado para operacion real.

Resumen ejecutivo:

- Predial: `IMPLEMENTADO` en flujo operativo principal (alta, adquisicion, titularidad, subdivision, fusion, ficha e historial).
- Construccion: `PARCIAL` (estructura SQL + procedimientos base, sin modulo funcional en UI).
- Tributaria: `PARCIAL` (estructura SQL + procedimientos base, sin modulo funcional en UI).
- Contabilidad/Comercial: `PARCIAL` (estructura SQL + procedimientos base, sin modulo funcional en UI).

## Alcance funcional

### 1. Predial

El terreno es la entidad central del modulo.

La capa predial debe cubrir:

- alta de terreno
- datos base del terreno: rol, rol matriz, superficie, comuna, tipo, estado
- titularidad y porcentaje de derechos
- operaciones prediales
- historial de cambios de estado

Tablas base:

- `ct_terreno`
- `ct_titularidad_terreno`
- `ct_operacion_predial`
- `ct_operacion_terreno`
- `ct_historial_estado_terreno`

Estado de implementacion Predial:

- [x] alta de terreno
- [x] adquisicion completa (alta + titularidad inicial + operacion + estados iniciales)
- [x] titularidad con validaciones de vigencia y porcentaje
- [x] operaciones prediales (subdivision y fusion)
- [x] historial de estado y operaciones en ficha
- [x] trazabilidad operativa de origen/resultado en historial de terreno (apoyada en operaciones)

### 2. Construccion

La capa de construccion debe cubrir la evaluacion de uso del terreno y su factibilidad basica.

Debe permitir:

- registrar ficha arquitectura/legal
- registrar resoluciones
- registrar superficie neta de arquitectura
- registrar factibilidad electrica y sanitaria
- asociar terrenos a proyectos de construccion

Tablas base:

- `ct_terreno_arquitectura_legal`
- `ct_proyecto_construccion`
- `ct_proyecto_construccion_terreno`
- `ct_construccion`

Estado de implementacion Construccion:

- [x] tablas base en `db/20_ct_capa_construccion.sql`
- [x] procedimiento base en `db/21_ct_procedimientos.sql` (`sp_ct_proyecto_construccion_crear`)
- [ ] UI operativa para ficha arquitectura/legal
- [ ] flujo de resoluciones/factibilidades en aplicacion
- [ ] asociacion de terrenos a proyectos desde interfaz de negocio

### 3. Tributaria

La capa tributaria debe cubrir la situacion regulatoria del terreno.

Debe permitir:

- registrar informacion tributaria base
- registrar avaluos
- registrar hipotecas y usufructos cuando aplique
- dejar visibilidad sobre condicion del rol y estado SII

Tablas base:

- `ct_terreno_tributario`
- `ct_avaluo_terreno`
- `ct_hipoteca_terreno`
- `ct_usufructo_terreno`

Estado de implementacion Tributaria:

- [x] tablas base en `db/30_ct_capa_tributaria.sql`
- [x] validaciones de consistencia de avaluo (trigger)
- [x] procedimientos base (`sp_ct_avaluo_terreno_upsert`, `sp_ct_hipoteca_terreno_crear`)
- [ ] UI operativa para gestion tributaria
- [ ] flujo completo de estado SII/condicion rol/usufructos en aplicacion

### 4. Contabilidad / Comercial

La capa comercial debe cubrir tasacion, estado comercial y venta.

Debe permitir:

- registrar tasaciones
- definir referencia comercial para venta
- registrar venta de terreno
- registrar compradores y porcentajes

Tablas base:

- `ct_tasacion_terreno`
- `ct_venta_terreno`
- `ct_venta_terreno_tercero`
- `ct_estado_terreno_comercial`

Estado de implementacion Contabilidad / Comercial:

- [x] tablas base en `db/40_ct_capa_contabilidad.sql`
- [x] procedimientos base (`sp_ct_tasacion_terreno_crear`, `sp_ct_venta_terreno_crear`)
- [x] estado comercial integrado en modulo predial (filtro/visualizacion y cambios por operaciones)
- [ ] UI operativa de tasaciones y ventas
- [ ] gate estricto de negocio "venta requiere tasacion previa vigente/referencial"

## Reglas de negocio V1

### Regla 1. Terreno unico

Cada terreno debe existir una sola vez como registro maestro en `ct_terreno`.

Estado: `IMPLEMENTADO` (entidad maestra unica + validaciones de rol asignado unico en flujo).

### Regla 2. Titularidad consistente

La titularidad no puede superar 100 por ciento vigente por terreno y no debe tener solapes inconsistentes.

Estado: `IMPLEMENTADO` (trigger `TR_ct_titularidad_terreno_validacion` + validaciones en servicio).

### Regla 3. Cambio de estado con historial

Todo cambio de estado predial o comercial debe quedar registrado en historial.

Estado: `IMPLEMENTADO` (procedimiento `sp_ct_terreno_cambiar_estado` y uso en operaciones).

### Regla 4. Venta con distribucion valida

Una venta debe distribuir correctamente sus porcentajes entre terceros.

Estado: `PARCIAL` (validacion en `sp_ct_venta_terreno_crear`, pendiente flujo UI de venta).

### Regla 5. Venta con tasacion previa

Para vender un terreno debe existir al menos una tasacion registrada y vigente o referencial segun definicion operativa del negocio.

Estado: `PARCIAL` (definida como regla; falta endurecimiento completo por procedimiento + UI).

### Regla 6. Construccion con ficha previa

Para pasar un terreno a uso o construccion debe existir ficha de arquitectura/legal registrada.

Estado: `PENDIENTE` en aplicacion (sin gate funcional en UI/proceso).

### Regla 7. Avaluo consistente

Los valores tributarios deben mantener consistencia interna y no aceptar montos negativos.

Estado: `IMPLEMENTADO` en capa SQL (trigger de consistencia en avaluo).

## Flujos principales de la V1

### 1. Adquisicion / alta de terreno

Flujo:

1. crear terreno en `ct_terreno`
2. registrar titularidad inicial
3. registrar operacion predial de ingreso
4. dejar estado inicial predial y comercial

Resultado:

El terreno queda disponible para ser trabajado por las otras capas.

Estado del flujo: `IMPLEMENTADO`.

### 2. Subdivision

En la V1 se debe registrar como operacion predial.

Minimo requerido:

- registrar la operacion
- asociar terrenos involucrados
- dejar documentacion de respaldo
- actualizar estados si corresponde

Nota:

La trazabilidad completa origen -> resultado todavia no esta materializada de forma explicita en `ct/db` como una tabla de historial de relaciones entre terrenos. Para la V1 puede resolverse operativamente con `ct_operacion_predial` y `ct_operacion_terreno`, pero este es el principal punto a reforzar en una V2.

Estado del flujo: `IMPLEMENTADO` (con trazabilidad operativa en historial; sin modelo genealogico fuerte dedicado).

### 3. Fusion

Mismo criterio que subdivision.

Se registra como operacion predial y se deja evidencia de terrenos involucrados y cambio de estado correspondiente.

Estado del flujo: `IMPLEMENTADO` (con la misma limitacion estructural de genealogia que subdivision).

### 4. Venta

Flujo esperado:

1. validar estado comercial
2. validar tasacion existente
3. crear venta
4. asociar terceros y porcentajes
5. actualizar estado comercial e historial

Estado del flujo: `PARCIAL` (procedimiento SQL disponible; flujo operativo de interfaz aun pendiente).

### 5. Uso / construccion

Flujo esperado:

1. registrar ficha arquitectura/legal
2. revisar factibilidades y resoluciones
3. asociar el terreno a proyecto si aplica
4. actualizar estado del terreno

Estado del flujo: `PENDIENTE` en aplicacion (solo base de datos/procedimiento inicial).

## Casos de negocio que debe cubrir la V1

### Caso A. Terreno disponible para venta

Un terreno puede ponerse a la venta solo si tiene soporte comercial minimo:

- estado comercial valido
- tasacion registrada
- antecedentes base completos

Estado del caso: `PARCIAL` (criterio definido; falta vista y gate operativo de venta).

### Caso B. Terreno para construccion

Un terreno puede pasar a uso o construccion solo si tiene soporte tecnico/legal minimo:

- ficha arquitectura/legal
- factibilidades registradas
- resolucion o antecedentes disponibles

Estado del caso: `PENDIENTE`.

### Caso C. Terreno con cambios prediales

Un terreno puede cambiar su situacion por subdivision o fusion, y la V1 debe al menos dejar:

- la operacion registrada
- los terrenos involucrados
- los estados actualizados
- el respaldo documental

Estado del caso: `IMPLEMENTADO` en V1 operativa.

## Limites conocidos de la V1

- La trazabilidad fina entre terreno origen y terreno resultado no esta completamente resuelta en `ct/db`.
- Subdivision y fusion existen como concepto operativo, pero aun no como modelo fuerte de genealogia de terrenos.
- La ficha arquitectura/legal existe en formato simple y suficiente para una primera version, no como expediente completo.
- La venta puede registrarse, pero conviene endurecer por procedimiento la obligatoriedad de tasacion previa.

## Prioridades de implementacion

### Prioridad 1. Operacion base

- alta de terreno
- titularidad
- cambio de estados
- operaciones prediales

Estado: `IMPLEMENTADO`.

### Prioridad 2. Gates entre areas

- venta requiere tasacion
- construccion requiere ficha arquitectura/legal

Estado: `PENDIENTE / PARCIAL` (reglas declaradas, falta cierre operativo completo).

### Prioridad 3. Consulta operativa

- ficha unificada del terreno
- historial de estados
- historial de operaciones
- vista de terrenos vendibles
- vista de terrenos aptos para construccion

Estado:

- [x] ficha unificada del terreno
- [x] historial de estados
- [x] historial de operaciones
- [ ] vista de terrenos vendibles
- [ ] vista de terrenos aptos para construccion

## Siguiente etapa recomendada

Una vez estabilizada la V1 sobre `ct/db`, la siguiente mejora natural es reforzar la trazabilidad de subdivision y fusion con una estructura explicita de relacion entre terrenos origen y resultado.

Esa mejora puede hacerse despues, sin frenar la salida de la primera version.
