# Plan Maestro Deuda y Garantia MSP

## Objetivo

Implementar en MSP una capa operativa de contrato, garantia y deuda que permita avanzar ordenadamente desde el flujo actual de tiendas hacia una gestion real de:

- contrato por tienda
- garantia por local
- deuda asignada manualmente a un local
- aplicacion de garantia sobre deuda del mismo local
- trazabilidad basica de movimientos

El objetivo inmediato no es resolver toda la salida legal del arrendatario. El objetivo es dejar operativa la parte que realmente importa hoy:

- registrar garantias
- ver deuda por local
- reservar/aplicar garantia
- dejar una base limpia para evolucion futura

## Avance actual (2026-03-18)

- Etapa 0: pendiente de validacion final en SQL Server productivo.
- Etapa 1: implementada en `msp/tiendas/index.php` y `msp/tiendas/guardar.php`.
- Etapa 2: implementada en `msp/tiendas/index.php` con resumen visible de contrato/garantia por tienda y por local.
- Etapa 3: MVP extendido implementado en `msp/tiendas/index.php`, `msp/tiendas/guardar_cargo.php`, `msp/tiendas/anular_cargo.php` y `msp/tiendas/editar_cargo.php` (alta, listado, edicion y anulacion de cargos pendientes por local).
- Etapa 4: implementada en `msp/contratos/editar.php`, `msp/contratos/movimiento_garantia_cargo.php` y `msp/contratos/devolver_garantia_local.php` (reserva, aplicacion, liberacion y devolucion por local).
- Etapa 5: implementada (primer corte) en `msp/deuda_garantia/index.php` y enlazada en `msp/msp_menu.php`.
- Etapa 6: en curso (`msp/contratos/cerrar.php`, `msp/tiendas/guardar.php` y `msp/contratos/historial.php` con controles y trazabilidad).

## Decision de modelo ya acordada

Estas decisiones ya estan cerradas:

1. El contrato es por `tienda`.
2. La garantia es por `local`.
3. Un contrato puede tener varias garantias.
4. Cada garantia solo cubre deuda de su mismo local.
5. La deuda se asigna manualmente al local.
6. Los servicios pendientes se estiman manualmente.
7. Las multas y danos se asignan a un local.
8. La garantia queda fija desde el inicio.
9. `msp_ocupacion_locales` sirve como validacion operativa, pero no reemplaza el modelo financiero.

## Estado actual del repositorio

Ya quedaron preparados estos activos:

- SQL de base:
  - [msp_deudores_garantia.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_deudores_garantia.sql)
- Modelo DBML:
  - [msp_deudores_garantia.dbml](/mnt/c/wamp64/www/portalgp/msp/db/msp_deudores_garantia.dbml)
- Plan operativo corto:
  - [plan_deuda_garantia_operativo.md](/mnt/c/wamp64/www/portalgp/msp/plan_deuda_garantia_operativo.md)

Y el flujo actual donde conviene integrar esto es:

- UI:
  - [index.php](/mnt/c/wamp64/www/portalgp/msp/tiendas/index.php)
- guardado:
  - [guardar.php](/mnt/c/wamp64/www/portalgp/msp/tiendas/guardar.php)

## Enfoque general

La implementacion se debe hacer en capas.

No conviene empezar por pantallas de deuda avanzadas ni por automatizaciones de salida. Conviene avanzar asi:

1. integrar contrato y garantia al flujo de tiendas
2. dejar visible el estado de garantia por local
3. habilitar creacion de cargos por local
4. habilitar reserva/aplicacion de garantia
5. recien despues construir pantallas de gestion mas completas

## Arquitectura funcional objetivo

### Capa 1. Maestro comercial

- `msp_tiendas`
- `msp_arrendatarios`
- `msp_locales`
- `msp_ocupacion_locales`

Rol:

- definir quien ocupa que locales

### Capa 2. Cabecera financiera

- `msp_contratos_arriendo`

Rol:

- representar el contrato comercial/financiero por tienda

### Capa 3. Garantia

- `msp_garantias`
- `msp_movimientos_garantia`

Rol:

- representar monto inicial, reservas, aplicaciones, devoluciones y ajustes por local

### Capa 4. Deuda local

- `msp_cargos_salida`

Rol:

- representar deuda o cargo asignado manualmente a un local especifico

### Capa 5. Reporte operativo

- `msp_vw_garantias_resumen`
- `msp_vw_deuda_garantia_local`

Rol:

- mostrar saldos y deuda por local de forma util para operacion

## Donde se integra en la aplicacion

## Punto de entrada principal

La entrada natural para contratos y garantias es el flujo de `tiendas`.

Motivo:

- ahi ya se selecciona arrendatario
- ahi ya se define la tienda
- ahi ya se asignan locales
- la garantia depende de cada local asignado

Por eso el orden correcto es:

1. guardar tienda
2. guardar ocupacion
3. crear o actualizar contrato
4. crear o actualizar garantias por local

## Archivos involucrados en la primera etapa

### UI

- [index.php](/mnt/c/wamp64/www/portalgp/msp/tiendas/index.php)

### Backend

- [guardar.php](/mnt/c/wamp64/www/portalgp/msp/tiendas/guardar.php)

### SQL

- [msp_deudores_garantia.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_deudores_garantia.sql)

## Etapas de implementacion

## Etapa 0. Instalacion y validacion de base

### Objetivo

Dejar las tablas nuevas creadas en SQL Server y validar que el esquema calza con MSP actual.

### Trabajo

1. Ejecutar `msp_deudores_garantia.sql`.
2. Confirmar que existen:
   - `msp_contratos_arriendo`
   - `msp_garantias`
   - `msp_cargos_salida`
   - `msp_movimientos_garantia`
3. Confirmar que las vistas responden.
4. Validar que las FK con `msp_tiendas`, `msp_locales`, `msp_documentos_cobro` y `msp_pagos` no fallen.

### Entregable

Base lista para empezar integración en aplicación.

### Criterio de listo

- script ejecutado sin errores
- tablas visibles
- vistas consultables

## Etapa 1. Integracion de contrato y garantia en el flujo de tiendas

### Objetivo

Hacer que al crear o editar una tienda se puedan registrar:

- datos del contrato
- garantia por cada local seleccionado

### Cambios UI en `msp/tiendas/index.php`

Agregar 2 bloques al modal crear/editar:

1. `Datos del contrato`
   - `fecha_inicio_contrato`
   - `fecha_termino_pactada`
   - `dia_cobro`
   - `rubro_contrato`

Nota actual:

- `monto_arriendo_pactado` se mantiene en backend, pero se retiro temporalmente de la UI.

2. `Garantias por local`
   - tabla dinamica segun locales seleccionados
   - columnas:
     - local
     - fecha_constitucion
     - monto_garantia
     - observaciones

### Cambios backend en `msp/tiendas/guardar.php`

Extender el guardado para:

1. insertar o actualizar `msp_contratos_arriendo`
2. insertar o actualizar `msp_garantias`
3. validar que cada garantia corresponda a un local de la tienda
4. hacerlo dentro de la misma transaccion

### Reglas recomendadas

1. Si no hay locales seleccionados, no se crea contrato.
2. Si hay locales y datos de contrato, se crea o actualiza contrato.
3. Si se quita un local de la tienda y ese local tiene garantia o movimientos, no se debe borrar silenciosamente.
4. Si un local ya tiene garantia asociada al contrato, se actualiza; no se duplica.

### Entregable

Crear/editar tienda deja contrato y garantias cargados.

### Criterio de listo

- crear tienda con 1 o mas locales
- guardar contrato
- guardar una garantia por local
- editar sin duplicar registros

## Etapa 2. Visualizacion basica de contrato y garantia

### Objetivo

Hacer visible para operacion lo que ya se guardo.

### Trabajo UI

En el listado o detalle de tienda mostrar:

- estado de contrato
- fecha inicio contrato
- cantidad de locales con garantia
- total garantia del contrato
- resumen por local:
  - monto inicial
  - saldo disponible
  - saldo reservado
  - saldo aplicado

### Fuente

- `msp_contratos_arriendo`
- `msp_vw_garantias_resumen`

### Entregable

La administracion puede revisar garantias sin ir directo a SQL.

### Criterio de listo

- resumen visible en pantalla
- datos coinciden con BD

### Estado actual

- Implementado en el listado de `tiendas`.

## Etapa 3. Registro de deuda por local

### Objetivo

Permitir crear deuda o cargos asociados a un local especifico.

### Trabajo

Crear una pantalla o bloque operativo para registrar cargos:

- contrato
- local
- tipo de cargo
- descripcion
- monto
- periodo
- servicio referenciado si aplica
- documento relacionado si aplica

### Fuente de verdad

- `msp_cargos_salida`

### Regla de operacion

La asignacion al local es siempre manual.

### Entregable

El usuario puede registrar:

- arriendo vencido
- servicio pendiente emitido
- servicio estimado manual
- multa
- danos
- otros cargos

### Criterio de listo

- alta de cargo
- edicion de cargo pendiente
- anulacion de cargo
- visualizacion por local

### Estado actual

- Alta manual implementada.
- Visualizacion por local implementada en modal de cargos por tienda.
- Edicion de cargo pendiente implementada.
- Anulacion de cargos pendientes implementada.

## Etapa 4. Reserva y aplicacion de garantia

### Objetivo

Permitir reservar y aplicar garantia contra cargos del mismo local.

### Trabajo tecnico

Crear procedimientos o acciones equivalentes:

1. `msp_reservar_garantia_cargo`
2. `msp_aplicar_garantia_cargo`
3. `msp_liberar_reserva_garantia`
4. `msp_devolver_garantia_local`

### Reglas de negocio

1. Una garantia solo cubre cargos de su mismo local.
2. Reserva y aplicacion deben registrar movimiento.
3. La aplicacion debe indicar si sale de saldo disponible o reservado.
4. No se toca saldo manualmente.

### Entregable

La garantia pasa de ser un dato estatico a un saldo operativo usable.

### Criterio de listo

- reservar contra cargo
- aplicar contra cargo
- liberar reserva
- ver saldo actualizado en vistas

### Estado actual

- Boton y modal operativo por cargo en `msp/tiendas/index.php`.
- Endpoint operativo en `msp/contratos/movimiento_garantia_cargo.php`.
- Acciones implementadas: `RESERVAR`, `APLICAR_DESDE_DISPONIBLE`, `APLICAR_DESDE_RESERVADO`, `LIBERAR_RESERVA`.
- El estado del cargo se recalcula al registrar movimiento.
- Boton de devolucion por local en resumen de garantia (`tiendas`).
- Endpoint de devolucion en `msp/contratos/devolver_garantia_local.php`.

## Etapa 5. Pantalla de deuda y garantia por contrato/local

### Objetivo

Construir una vista operativa para administracion.

### Vista deseada

Filtros por:

- tienda
- arrendatario
- local
- estado contrato
- estado cargo

Datos visibles:

- garantias por local
- cargos por local
- saldo disponible
- saldo reservado
- monto aplicado
- deuda pendiente

### Fuente

- `msp_vw_garantias_resumen`
- `msp_vw_deuda_garantia_local`
- `msp_cargos_salida`

### Entregable

Una pantalla unica de consulta y operacion basica.

### Criterio de listo

- usuario identifica rapidamente garantia y deuda por local

### Estado actual

- Pantalla operativa disponible en `msp/deuda_garantia/index.php`.
- Filtros implementados: tienda, arrendatario, local, estado contrato y estado cargo.
- Resumen implementado: deuda activa, total cargos y saldos de garantia (disponible/reservado/aplicado).
- Tabla implementada por contrato/local con:
  - saldos de garantia
  - deuda por estado de cargo
  - cantidad de cargos por estado
  - acceso directo a `tiendas` para gestion operativa.

## Etapa 6. Reglas avanzadas y workflow

### Objetivo

Agregar control operacional sin entrar todavia en liquidacion legal completa.

### Posibles mejoras

1. bloquear devolucion si hay cargos pendientes
2. bloquear cierre de contrato con cargos activos
3. historial de cambios de contrato
4. bitacora operativa de usuario
5. workflow de revision/aprobacion

### Nota

Esta etapa es util, pero no es bloqueante para arrancar.

### Estado actual

- Bloqueo de devolución con cargos pendientes ya operativo en `msp/contratos/devolver_garantia_local.php`.
- Bloqueo de cierre de contrato con cargos activos (pendiente/reservado) operativo en `msp/contratos/cerrar.php`.
- Acción de cierre disponible desde `msp/deuda_garantia/index.php` con motivo obligatorio.
- Bitácora de cierre de contrato implementada en `dbo.msp_bitacora_cierre_contrato` (patch: `msp/db/patch_bitacora_cierre_contrato.sql`).
- Historial de cambios de contrato implementado en `dbo.msp_historial_contrato` (base: `msp/db/msp_deudores_garantia.sql`, patch: `msp/db/patch_historial_contrato.sql`).
- Registro automático de `CREACION`/`ACTUALIZACION` en `msp/tiendas/guardar.php` y `CIERRE` en `msp/contratos/cerrar.php`.
- Vista de consulta del historial en `msp/contratos/historial.php` y acceso desde `msp/deuda_garantia/index.php` + `msp/msp_menu.php`.

## Orden tecnico recomendado

1. ejecutar SQL
2. integrar contrato+garantia en `tiendas`
3. mostrar resumen de garantias
4. crear registro de cargos
5. crear acciones de reserva/aplicacion
6. crear vista de gestion unificada

## Riesgos y mitigacion

### Riesgo 1. Mezclar ocupacion con contrato

Mitigacion:

- `msp_ocupacion_locales` sigue como capa operativa
- `msp_contratos_arriendo` queda como capa financiera

### Riesgo 2. Eliminar locales con garantia ya creada

Mitigacion:

- bloquear baja silenciosa
- exigir revision antes de quitar local del contrato

### Riesgo 3. Aplicar garantia al local equivocado

Mitigacion:

- validacion por trigger ya incluida
- UI debe mostrar siempre local afectado

### Riesgo 4. Querer resolver salida legal demasiado pronto

Mitigacion:

- dejar fuera esa parte por ahora
- operar solo deuda y garantia

## Lo que queda explicitamente fuera por ahora

1. liquidacion legal final
2. salida parcial formalizada
3. automatizacion de distribucion de deuda
4. contabilidad
5. generacion de documentos legales de devolucion

## Proximo corte recomendado

El corte correcto para avanzar ya es:

### Sprint 1

- instalar SQL
- integrar contrato en `tiendas`
- integrar garantias por local en `tiendas`

### Sprint 2

- mostrar resumen de garantias
- crear pantalla o modal de cargos por local

### Sprint 3

- crear reserva y aplicacion de garantia
- construir vista unificada deuda/garantia

## Checklist de salida de cada etapa

### Etapa 1

- crear tienda con contrato
- asignar 2 locales
- guardar 2 garantias
- editar tienda sin duplicar contrato

### Etapa 3

- crear cargo documental
- crear cargo manual
- crear servicio estimado manual

### Etapa 4

- reservar parte de garantia
- aplicar parte de garantia
- liberar reserva
- devolver saldo restante

## Recomendacion final

No abrir aun un modulo grande de “Deudores”.

Primero hay que hacer bien esto:

- `tienda`
- `ocupacion`
- `contrato`
- `garantia`
- `cargo`
- `movimiento`

Cuando eso este firme, la pantalla de deudores sale casi sola.
