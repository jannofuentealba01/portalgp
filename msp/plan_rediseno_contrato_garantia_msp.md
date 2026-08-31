# Plan Rediseno Contrato y Garantia MSP

## Objetivo

Redisenar la capa de contrato, ocupacion y garantia de MSP para que:

- el contrato sea la entidad principal del negocio
- la ocupacion fisica de locales dependa del contrato y no al reves
- la garantia tenga trazabilidad contable fuerte
- el usuario ingrese la menor cantidad posible de datos manuales
- las reglas criticas vivan en la BD y no repartidas en varios PHP
- el sistema pueda evolucionar despues a una capa contable sin rehacer la base

Este plan esta pensado para ejecucion por IA, por etapas, sin fechas fijas.

## Estado de avance (ejecutado)

- Fase 1 completada: `db/msp_fase1_contrato_locales.sql`
- Fase 2 completada: `db/msp_fase2_garantia_contrato_local.sql`
- Fase 3 completada: `db/msp_fase3_cargos_contrato_local.sql`
- Fase 4 completada en BD: `db/msp_fase4_sp_negocio.sql`
- Fase 4 completada en backend critico:
  - `tiendas/guardar_cargo.php` ahora prioriza `dbo.msp_cargo_crear_manual`
  - `contratos/movimiento_garantia_cargo.php` ahora prioriza `dbo.msp_garantia_reservar`, `dbo.msp_garantia_liberar_reserva` y `dbo.msp_garantia_aplicar`
  - `contratos/devolver_garantia_local.php` ahora prioriza `dbo.msp_garantia_devolver`
  - `contratos/cerrar.php` ahora prioriza `dbo.msp_contrato_cerrar`
  - `bootstrap.php` agrega helper `msp2ProcedureExists(...)`
- Fase 5 iniciada:
  - `contratos/index.php` creado como punto de entrada propio para contratos (alta simplificada + listado)
  - `contratos/guardar.php` creado para crear contrato + contrato_locales (+ garantia opcional uniforme por local)
  - `msp_menu.php` agrega acceso directo a `Contratos`
  - `contratos/editar.php` y `contratos/actualizar.php` agregados para edición rápida en pestañas (Contrato/Locales/Garantías/Resumen)
  - `tiendas/guardar.php` inicia retiro progresivo: deja de procesar contrato/garantía por defecto y deriva al módulo Contratos (con flag legacy opcional)
  - `tiendas/index.php` reduce UI contractual residual:
    - quita captura de contrato/garantía en modales crear/editar tienda
    - agrega CTA directo a `contratos/index.php` / `contratos/editar.php`
    - mantiene resumen contractual como lectura operativa
  - edición contractual habilita retiro de locales con validaciones:
    - bloquea salida si hay cargos pendientes/reservados
    - bloquea salida si hay garantía activa o saldos de garantía
    - al retirar local, cierra `msp_contrato_locales` (estado 2 + fecha_termino)
    - sincroniza ocupación operativa (`msp_ocupacion_locales`) con locales vigentes del contrato
    - registra evento en `msp_historial_contrato`
  - `contratos/index.php` pasa a flujo wizard de 4 pasos:
    - ahora en modal de creación en `contratos/index.php` (`#modalNuevoContrato`)
  - `contratos/index.php` queda como listado + filtros + accesos (nuevo/editar/historial)
  - `contratos/index.php` agrega paginación real (líneas por página, total, navegación)
  - `tiendas/confirmar_importacion.php` deja lógica legacy y ahora usa modelo nuevo:
    - crea/actualiza contrato y sincroniza `msp_contrato_locales`
    - crea garantía inicial solo cuando falta (no reescribe `monto_inicial`)
    - bloquea cierre de contrato-local por importación si hay deuda/garantía activa

Siguiente fase recomendada: cerrar Fase 5 con wizard contractual completo y retirar UI contractual residual en `tiendas/index.php`.

## Diagnostico actual

## Lo bueno

- Existe una base operativa util:
  - `msp_tiendas`
  - `msp_ocupacion_locales`
  - `msp_contratos_arriendo`
  - `msp_garantias`
  - `msp_movimientos_garantia`
  - `msp_cargos_salida`
  - `msp_vw_garantias_resumen`
  - `msp_vw_deuda_garantia_local`
- Ya existe una vista operativa usable en `deuda_garantia/index.php`.
- Ya existe bitacora e historial contractual.
- Ya existe un libro de movimientos para garantia por local.

## Problemas estructurales

1. El contrato nace hoy desde `tiendas/guardar.php`.
   Eso hace que el contrato dependa de la tienda y de la ocupacion, cuando deberia ser el eje legal/comercial.

2. La ocupacion fisica y la relacion contractual estan mezcladas.
   `msp_ocupacion_locales` hoy funciona como pieza operativa y casi como ancla del contrato.

3. La garantia se edita parcialmente por actualizacion directa de cabecera.
   `monto_inicial` no deberia mutar una vez constituido. Los cambios deben vivir como movimientos.

4. La semantica de `msp_cargos_salida` ya quedo chica.
   El nombre sugiere solo salida de arrendatario, pero hoy el concepto ya sirve como deuda local operativa.

5. Reglas importantes viven en PHP.
   Reserva, liberacion, aplicacion y devolucion de garantia estan controladas en archivos PHP. Eso es fragil para concurrencia, integridad y mantenibilidad.

6. La UX exige demasiados campos tecnicos para el usuario.
   Contrato, ocupacion, garantias y cargos aparecen en el mismo flujo y con demasiada libertad de entrada.

7. No hay una politica unica de cierre.
   El cierre contractual controla algunas condiciones, pero no existe una orquestacion completa de liquidacion final.

## Decision de modelo objetivo

El modelo objetivo debe quedar asi:

`Arrendatario -> Contrato -> Locales del contrato -> Ocupacion operativa -> Garantia -> Cargos / Pagos / Liquidacion`

Regla central:

- el contrato define la relacion legal/comercial
- los locales asociados materializan el alcance fisico del contrato
- la ocupacion es consecuencia operativa del contrato activo
- la garantia nace desde el contrato-local

## Principios de diseno

1. Un solo origen de verdad por capa.
2. No editar saldos derivados manualmente.
3. Todo fondo de garantia se recalcula desde movimientos.
4. Toda deuda operativa debe quedar ligada a un contrato y a un local.
5. Las reglas de negocio criticas deben vivir en SQL Server.
6. La UI debe pedir pocos datos y derivar lo demas.
7. Toda accion importante debe dejar historial y usuario responsable.

## Modelo objetivo de datos

## 1. Contrato como cabecera real

Mantener `msp_contratos_arriendo`, pero convertirla en entidad principal y no secundaria.

### Rol

- representar la relacion legal/comercial con el arrendatario
- definir vigencia, estado y condiciones generales
- servir de ancla para locales, garantia, deuda y cierre

### Campos a mantener o fortalecer

- `id_contrato_arriendo`
- `id_arrendatario`
- `id_tienda`:
  - puede mantenerse por compatibilidad
  - pero la tienda debe verse como agrupador comercial, no como origen legal
- `fecha_firma`
- `fecha_inicio`
- `fecha_termino_pactada`
- `fecha_cierre_real`
- `dia_cobro`
- `estado_contrato`
- `monto_arriendo_base`
- `moneda_arriendo`
- `rubro_contrato`
- `observaciones`
- `origen_creacion`
- `id_usuario_creacion`
- `id_usuario_ultima_actualizacion`
- `fecha_registro`
- `fecha_actualizacion`

### Estados recomendados

- `BORRADOR`
- `VIGENTE`
- `EN_REVISION`
- `EN_CIERRE`
- `CERRADO`
- `ANULADO`

No usar estados para reflejar saldos de garantia. El estado del contrato y el estado financiero no deben mezclarse.

## 2. Nueva capa `msp_contrato_locales`

Agregar una tabla nueva. Esta es la pieza que hoy falta.

### Rol

- representar que locales cubre un contrato
- separar alcance contractual de ocupacion fisica
- permitir trazabilidad si un contrato cambia de locales

### Campos sugeridos

- `id_contrato_local`
- `id_contrato_arriendo`
- `id_local`
- `fecha_inicio`
- `fecha_termino`
- `orden_visual`
- `estado_relacion`
- `monto_arriendo_local`
- `observaciones`
- `fecha_registro`

### Regla

- un contrato puede tener varios locales
- un local puede pasar por varios contratos en el tiempo
- no debe haber solapamiento activo del mismo local entre contratos

## 3. Ocupar `msp_ocupacion_locales` como derivado operativo

No eliminar de inmediato `msp_ocupacion_locales`, pero cambiar su rol.

### Nuevo criterio

- `msp_contrato_locales` es la fuente contractual
- `msp_ocupacion_locales` pasa a ser proyeccion operativa
- la ocupacion se sincroniza automaticamente desde contratos activos

### Beneficio

- se evita que el usuario cree ocupaciones sueltas sin sustento contractual
- se mantiene compatibilidad con vistas actuales

## 4. Garantia ligada a contrato-local, no solo a contrato + local

La mejor opcion objetivo es:

- `msp_garantias` debe referenciar `id_contrato_local`

Si la migracion total es pesada, fase intermedia:

- mantener `id_contrato_arriendo` + `id_local`
- agregar `id_contrato_local`
- migrar vistas y backend gradualmente

### Rol de `msp_garantias`

- representar la cabecera del fondo de garantia por local dentro de un contrato
- no almacenar saldos editables, solo datos de constitucion y estado

### Campos sugeridos

- `id_garantia`
- `id_contrato_local`
- `fecha_constitucion`
- `monto_pactado`
- `monto_inicial`
- `moneda`
- `estado_garantia`
- `observaciones`
- `fecha_registro`

### Regla clave

- `monto_inicial` se inserta una sola vez
- si cambia el monto de garantia, se registra ajuste, no update directo del principal

## 5. `msp_movimientos_garantia` como libro inmutable

Esta tabla debe quedar como el libro auditable de garantia.

### Ajustes requeridos

- agregar `id_usuario`
- agregar `origen_sistema`
- agregar `referencia_externa`
- agregar `motivo_movimiento`
- mantener referencias opcionales a:
  - `id_cargo_contrato_local`
  - `id_documento_cobro`
  - `id_pago`
  - `id_liquidacion_contrato`

### Regla

- nunca borrar ni editar movimientos salvo anulacion controlada
- si hay error, se crea reversa

### Tipos recomendados

- `CONSTITUCION`
- `AJUSTE_POSITIVO`
- `AJUSTE_NEGATIVO`
- `RESERVA`
- `LIBERACION_RESERVA`
- `APLICACION`
- `DEVOLUCION`
- `REVERSA`

## 6. Reemplazar `msp_cargos_salida`

El nombre actual ya no refleja el uso real. Propuesta:

- crear `msp_cargos_contrato_local`
- migrar desde `msp_cargos_salida`
- dejar una vista o compatibilidad temporal con nombre antiguo

### Rol

- registrar deuda local ligada a contrato
- servir tanto para deuda normal como para cierre

### Campos sugeridos

- `id_cargo_contrato_local`
- `id_contrato_local`
- `id_tipo_cargo`
- `fecha_cargo`
- `periodo_referencia`
- `origen_cargo`
- `id_documento_cobro`
- `id_pago`
- `descripcion`
- `monto_cargo`
- `monto_aplicado_garantia`
- `monto_pagado_directo`
- `estado_cargo`
- `es_estimado`
- `requiere_regularizacion`
- `fecha_registro`

### Estados recomendados

- `PENDIENTE`
- `RESERVADO`
- `APLICADO_GARANTIA`
- `PAGADO`
- `REGULARIZADO`
- `ANULADO`

## 7. Liquidacion contractual final

Agregar una capa propia de cierre.

### Tablas sugeridas

- `msp_liquidaciones_contrato`
- `msp_liquidaciones_contrato_detalle`

### Rol

- consolidar estado final del contrato
- definir devolucion, castigo o saldo por cobrar
- separar el cierre legal/operativo del contrato de la cobranza corriente

## Reglas de negocio objetivo

## Contrato

- no puede existir contrato vigente sin al menos un local asociado
- no puede existir local activo en dos contratos vigentes solapados
- cierre de contrato requiere estado financiero consistente

## Garantia

- toda garantia pertenece a un `contrato_local`
- no se actualiza `monto_inicial` por `UPDATE`
- todo cambio monetario queda en movimientos
- no se devuelve si hay saldo reservado
- no se cierra si hay cargos pendientes sin politica final

## Deuda

- toda deuda relevante debe caer en `contrato_local`
- los cargos documentales pueden generarse automaticamente desde documentos
- los cargos manuales deben ser excepcion, no flujo principal

## Ocupacion

- se genera o actualiza automaticamente desde contrato activo
- no es la entidad maestra del proceso legal

## UX objetivo

La UI debe bajar drásticamente la cantidad de datos que el usuario escribe.

## Flujo recomendado para crear contrato

### Paso 1. Seleccion basica

Pedir solo:

- arrendatario
- nombre comercial o tienda
- locales
- fecha_inicio

### Paso 2. Condiciones minimas

Pedir solo:

- dia_cobro
- monto arriendo base

Todo lo demas debe ir en panel avanzado o quedar opcional.

### Paso 3. Garantia simplificada

No pedir una fila manual por local como primera opcion.

Ofrecer:

1. `Sin garantia por ahora`
2. `Misma garantia para todos los locales`
3. `Usar valor sugerido por local`
4. `Editar manualmente por local`

La opcion manual debe ser secundaria.

## Flujo recomendado para editar contrato

- mostrar resumen contractual primero
- separar en pestañas:
  - `Contrato`
  - `Locales`
  - `Garantias`
  - `Deuda`
  - `Historial`
- esconder campos raros en `Opciones avanzadas`

## Flujo recomendado para cierre

Wizard de 4 pasos:

1. confirmar fecha real de salida
2. revisar cargos y estimaciones pendientes
3. revisar garantia:
   - disponible
   - reservada
   - aplicada
   - a devolver
4. confirmar cierre

## Backend objetivo

La capa PHP debe dejar de ser la que decide la integridad principal.

## Todo movimiento sensible debe pasar por SP

Crear o refactorizar procedimientos tipo:

- `msp_contrato_crear`
- `msp_contrato_actualizar`
- `msp_contrato_asignar_locales`
- `msp_garantia_constituir`
- `msp_garantia_ajustar`
- `msp_garantia_reservar`
- `msp_garantia_liberar_reserva`
- `msp_garantia_aplicar`
- `msp_garantia_devolver`
- `msp_contrato_preparar_cierre`
- `msp_contrato_cerrar`

## Regla de implementacion

- PHP valida formato y experiencia de usuario
- SQL valida negocio, integridad y concurrencia

## Plan de ejecucion

## Etapa 0. Congelar malas practicas actuales

Antes de redisenar en grande, hacer hardening del modelo actual.

### Trabajo

- prohibir update directo de `monto_inicial`
- agregar auditoria de usuario a movimientos
- revisar `tiendas/guardar.php` y `tiendas/confirmar_importacion.php`
- documentar claramente el modelo actual

### Objetivo

dejar de empeorar la base mientras se migra

## Etapa 1. Introducir `msp_contrato_locales`

### Trabajo

- crear tabla nueva
- migrar desde `msp_ocupacion_locales` para contratos activos
- agregar restricciones de no solapamiento
- poblar `orden_visual`

### Resultado esperado

el contrato ya define formalmente los locales asociados

## Etapa 2. Religar garantia a contrato-local

### Trabajo

- agregar `id_contrato_local` a `msp_garantias`
- poblarlo para datos existentes
- migrar vistas para leer desde esa relacion
- dejar `id_contrato_arriendo` + `id_local` solo como compatibilidad transitoria si hace falta

### Resultado esperado

la garantia deja de estar "colgando" ambiguamente del contrato

## Etapa 3. Reemplazar `msp_cargos_salida`

### Trabajo

- crear `msp_cargos_contrato_local`
- migrar datos desde `msp_cargos_salida`
- adaptar `deuda_garantia/index.php`
- adaptar flujos de reserva/aplicacion de garantia

### Resultado esperado

la deuda queda bien modelada para operacion corriente y cierre

## Etapa 4. Subir reglas criticas a stored procedures

### Trabajo

- sacar la logica de negocio dura desde:
  - `contratos/movimiento_garantia_cargo.php`
  - `contratos/devolver_garantia_local.php`
  - `tiendas/guardar_cargo.php`
  - `contratos/cerrar.php`
- moverla a SPs
- dejar transacciones y bloqueos consistentes

### Resultado esperado

menos fragilidad y menos riesgo de inconsistencias

## Etapa 5. Rehacer UX de contrato

### Trabajo

- sacar contrato del flujo "oculto" de `tiendas/guardar.php`
- crear punto de entrada propio de contratos
- dejar atajos contextuales desde arrendatarios y tiendas
- reducir cantidad de campos iniciales
- mover configuracion avanzada a segunda capa de UI

### Resultado esperado

el usuario entiende que primero existe contrato y luego ocupacion

## Etapa 6. Sincronizacion de ocupacion

### Trabajo

- crear mecanismo de sincronizacion contrato -> ocupacion
- decidir si sera trigger, SP o proceso controlado por backend
- actualizar pantallas dependientes que hoy leen `msp_ocupacion_locales`

### Resultado esperado

ocupacion fisica consistente con contrato vigente

## Etapa 7. Liquidacion final

### Trabajo

- agregar tablas de liquidacion
- crear wizard de cierre
- mover `contratos/cerrar.php` a proceso completo
- obligar resolucion de garantia antes de cierre final

### Resultado esperado

el cierre deja de ser solo cambio de estado y pasa a ser expediente completo

## Refactor de pantallas

## Mantener

- `arrendatarios/index.php`:
  - como maestro comercial
- `tiendas/index.php`:
  - como vista de agrupacion comercial y estado operativo
- `deuda_garantia/index.php`:
  - como tablero operacional de contrato/local

## Cambiar

- sacar la creacion principal de contrato desde `tiendas/index.php`
- convertir contratos en modulo propio
- dejar en tiendas solo:
  - ver contrato activo
  - ir a contratos
  - ver ocupacion
  - ver garantia y deuda resumida

## Crear o reforzar

- `contratos/index.php`:
  - listado contractual
- `contratos/editar.php`:
  - editor principal
- `contratos/cierre.php`:
  - wizard de cierre

## Migracion de datos

## Estrategia

1. no romper tablas actuales de inmediato
2. agregar columnas y tablas nuevas
3. poblar estructuras nuevas
4. adaptar vistas
5. adaptar PHP
6. apagar codigo viejo

## Reglas de migracion

- cada contrato activo debe producir filas en `msp_contrato_locales`
- cada garantia debe apuntar al `contrato_local` correcto
- cada cargo existente debe mapearse a contrato-local
- si no hay correspondencia unica, marcar excepcion para revision manual

## Validaciones post-migracion

- sin locales solapados entre contratos activos
- sin garantias huérfanas
- sin cargos huérfanos
- sin movimientos de garantia sin garantia valida
- sin contratos activos sin locales

## Criterios de aceptacion

El rediseno se considera listo cuando se cumplan todas estas condiciones:

1. El contrato se puede crear sin entrar a una pantalla tecnica de ocupacion.
2. Los locales del contrato tienen tabla propia.
3. La ocupacion se sincroniza desde el contrato.
4. La garantia ya no depende de editar manualmente montos base.
5. Toda reserva/aplicacion/devolucion queda en movimientos auditables.
6. La deuda local ya no usa una tabla con nombre semantico incorrecto.
7. El cierre contractual obliga a resolver garantia y deuda.
8. El usuario promedio puede crear contrato con un flujo corto y entendible.

## No hacer

- no seguir ampliando logica critica dentro de `tiendas/guardar.php`
- no seguir usando `msp_ocupacion_locales` como sustituto del modelo contractual
- no seguir actualizando `monto_inicial` de garantia por `UPDATE`
- no agregar mas reglas de negocio solo en JavaScript o solo en PHP
- no mezclar cierre contractual con cobranza corriente en la misma tabla de deuda sin contexto

## Orden real recomendado de implementacion

Si hubiera que ejecutar esto con el menor riesgo posible, el orden deberia ser:

1. hardening del modelo actual
2. `msp_contrato_locales`
3. reanclaje de garantia
4. SPs de negocio
5. nuevo modulo de contratos
6. renombre/migracion de cargos
7. liquidacion de cierre

## Archivo objetivo del plan

Este plan debe tomarse como documento rector para cualquier cambio futuro en:

- BD:
  - `db/msp_deudores_garantia.sql`
  - nuevos parches de migracion
- backend:
  - `tiendas/guardar.php`
  - `contratos/movimiento_garantia_cargo.php`
  - `contratos/devolver_garantia_local.php`
  - `tiendas/guardar_cargo.php`
  - `contratos/cerrar.php`
- UI:
  - `tiendas/index.php`
  - nuevo modulo `contratos/`
  - `deuda_garantia/index.php`

## Siguiente paso recomendado para ejecutar

Primero hacer un parche corto de estabilizacion:

- bloquear edicion directa de monto inicial de garantia
- agregar `id_usuario` a movimientos de garantia
- documentar el modelo actual y sus excepciones

Despues recien empezar el rediseno estructural.
