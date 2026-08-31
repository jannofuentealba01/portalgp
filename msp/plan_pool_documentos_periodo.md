# Plan Pool Documentos por Periodo

## Objetivo

Rediseñar el flujo mensual para que opere sobre un pool identificado de documentos potenciales por `periodo + contrato/tienda`, en vez de regenerar masivamente toda la capa documental del periodo en cada etapa.

El objetivo funcional correcto es:

1. Crear un pool estable de candidatos por periodo.
2. Evaluar por proceso que candidatos estan `100% listos`.
3. Generar/programar lotes solo con los listos de la etapa.
4. Aplicar saldo a favor solo a los documentos incluidos en ese lote.
5. No resetear ni borrar documentos ya construidos por otras etapas.
6. Mantener trazabilidad de por que un contrato/tienda sigue pendiente o ya salio del pool.

## Estado Actual

### DB

- `dbo.msp_documentos_cobro` tiene unicidad por `id_tienda + periodo_facturacion`.
  - Referencia: [db/msp_documento_pago.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_documento_pago.sql:116)
- El SP base `dbo.msp_generar_documentos_cobro_periodo` trabaja a nivel periodo completo.
  - Con `@reemplazar = 1` borra todos los documentos/detalles del periodo.
  - Luego vuelve a construir arriendo + servicios para todo el universo.
  - Referencia: [db/msp_documento_pago.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_documento_pago.sql:381)
- El saldo a favor global se materializa en `dbo.msp_saldos_favor_tienda` y se recalcula desde `dbo.msp_movimientos_saldo_favor_tienda` por trigger.
  - Referencia: [db/patch_saldo_favor_tienda.sql](/mnt/c/wamp64/www/portalgp/msp/db/patch_saldo_favor_tienda.sql:97)
- La aplicacion de saldo usa `dbo.msp_aplicar_saldo_favor_documento`, que valida contra `dbo.msp_saldos_favor_tienda`.
  - Referencia: [db/msp_documento_pago.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_documento_pago.sql:1350)
- Los lotes programados existen en:
  - `dbo.msp_envio_lotes_programados`
  - `dbo.msp_envio_lote_destinatarios`
  - `dbo.msp_envio_lote_documentos`
  - Referencia: [db/patch_envio_lotes_programados.sql](/mnt/c/wamp64/www/portalgp/msp/db/patch_envio_lotes_programados.sql:12)

### Servicios PHP

- `DocumentosCobroService::generateDocumentsForCierre()` sigue dependiendo del SP masivo y despues recompone documentos/items del periodo.
  - Referencia: [cobros/services/DocumentosCobroService.php](/mnt/c/wamp64/www/portalgp/msp/cobros/services/DocumentosCobroService.php:8)
- La reconciliacion actual mezcla tres responsabilidades:
  - Definir universo de tiendas.
  - Crear/recrear documentos.
  - Recalcular items por servicios/arriendo.
- `EnvioLotesProgramadosService::fetchCompletionCandidatesByStage()` hoy arma candidatos leyendo documentos ya generados e inferiendo completitud por items presentes.
  - Referencia: [cobros/services/EnvioLotesProgramadosService.php](/mnt/c/wamp64/www/portalgp/msp/cobros/services/EnvioLotesProgramadosService.php:1528)

### operacion_mensual.php

- `generar_etapa_completitud` y `generar_y_programar_etapa_completitud` siguen operando con mentalidad de regeneracion de periodo.
- Si ya existen documentos del periodo, se llama a `OperacionMensualService::borrarGeneracion(..., borrarDocumentos=true, ...)`.
  - Esto resetea documentos previos aun si fueron utiles para una etapa anterior.
  - Referencia: [cobros/operacion_mensual.php](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:3666)
  - Referencia: [cobros/operacion_mensual.php](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:3915)
- Luego se regeneran cobros y documentos para todo el periodo.
- El saldo automatico ya fue movido a nivel lote, pero sigue dependiendo de una capa documental que se regenera y de un saldo global fuera del contexto del pool.
  - Referencia: [cobros/operacion_mensual.php](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:3997)
  - Referencia: [cobros/operacion_mensual.php](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:4107)

## Problemas Estructurales

1. El documento no es una entidad estable del proceso mensual.
   Hoy el documento se crea, borra y recrea por etapa. Eso impide tratarlo como unidad de trabajo identificable.

2. La completitud se calcula tarde.
   Primero se regenera el universo documental y despues se pregunta quien esta listo. El modelo correcto es al reves: primero identificar el pool y despues materializar solo los listos.

3. El proceso esta acoplado a `periodo completo`.
   El SP documental base no fue diseñado para un flujo incremental por candidato.

4. El saldo a favor esta acoplado a una vision global por tienda.
   Eso sirve para contabilidad, pero no para decidir de forma segura y repetible que aplicar en un lote puntual si la capa documental cambia entre etapas.

5. No existe estado operativo persistente del pool.
   No hay tabla que diga:
   - quien pertenece al periodo,
   - que servicios se esperan,
   - que servicios ya estan completos,
   - si ya fue documentado,
   - si ya fue loteado,
   - si ya recibio saldo,
   - por que sigue pendiente.

## Modelo Objetivo

### Unidad de negocio

La unidad de trabajo debe ser `periodo_facturacion + id_tienda + id_contrato_arriendo`.

Si la regla de negocio confirma que en un periodo solo puede haber un contrato activo por tienda, el `id_contrato_arriendo` sigue siendo necesario como snapshot de trazabilidad aunque operativamente el documento siga siendo uno por tienda/periodo.

### Pool persistente

Crear una tabla nueva, por ejemplo:

- `dbo.msp_pool_documentos_periodo`

Campos sugeridos:

- `id_pool_documento`
- `periodo_facturacion`
- `id_tienda`
- `id_contrato_arriendo`
- `estado_pool`
  - `1 pendiente`
  - `2 listo_parcial`
  - `3 documentado`
  - `4 loteado`
  - `5 descartado`
- `perfil_servicios`
  - `LUZ`
  - `LUZ_GAS`
  - `LUZ_AGUA`
  - `LUZ_GAS_AGUA`
- `requiere_luz`
- `requiere_gas`
- `requiere_agua`
- `tiene_luz`
- `tiene_gas`
- `tiene_agua`
- `ready_luz`
- `ready_gas`
- `ready_agua`
- `id_documento_cobro`
- `id_lote_envio_ultimo`
- `saldo_aplicado_total`
- `motivo_pendiente`
- `created_at`
- `updated_at`

Restriccion sugerida:

- `UNIQUE(periodo_facturacion, id_tienda, id_contrato_arriendo)`

### Documento de cobro

`dbo.msp_documentos_cobro` debe dejar de ser “salida regenerable del periodo” y pasar a ser “materializacion del pool”.

Cambio sugerido:

- agregar `id_pool_documento` nullable al inicio y luego obligatorio.
- restriccion unica alternativa:
  - mantener `UNIQUE(id_tienda, periodo_facturacion)` mientras exista un documento por tienda/periodo.
- regla de aplicacion:
  - si ya existe `id_documento_cobro` para el pool, no se regenera.
  - solo se recalculan items faltantes o estados derivados.

### Lotes

El lote debe consumir `pool listos` y no “documentos del periodo inferidos”.

Regla objetivo:

1. seleccionar filas de `msp_pool_documentos_periodo`
2. filtrar `estado_pool in (pendiente, listo_parcial, documentado)` segun etapa
3. exigir flags `ready_*` segun proceso
4. excluir `id_lote_envio_ultimo` activo o `estado_pool = loteado`
5. garantizar que el documento existe antes de insertar en `msp_envio_lote_documentos`

### Saldo a favor

Separar dos capas:

1. Capa contable global:
   - `msp_movimientos_saldo_favor_tienda`
   - `msp_saldos_favor_tienda`

2. Capa operacional por periodo/lote:
   - `msp_saldo_favor_periodo_items`
   - `msp_saldo_favor_periodo_aplicaciones`

Regla correcta:

- el saldo se decide y aplica despues de fijar el conjunto exacto del lote.
- no debe revocarse automaticamente por generar otra etapa.
- si un documento ya recibio saldo, esa aplicacion queda ligada al documento/lote salvo correccion manual explicita.

## Cambios Necesarios

### 1. DB

#### Tablas nuevas

- `msp_pool_documentos_periodo`
- opcional: `msp_pool_documentos_eventos`
  - para auditoria de cambios de estado

#### Alter tablas existentes

- `msp_documentos_cobro`
  - agregar `id_pool_documento`
- `msp_envio_lote_documentos`
  - opcional: agregar `id_pool_documento_snapshot`
- `msp_saldo_favor_periodo_aplicaciones`
  - opcional: agregar `id_lote_envio_origen`

#### Procedimientos nuevos

- `msp_pool_documentos_periodo_sync`
  - arma/actualiza el pool base del periodo usando contratos/tiendas vigentes
- `msp_pool_documentos_periodo_refresh_readiness`
  - recalcula `tiene_*` y `ready_*`
- `msp_documentos_cobro_upsert_desde_pool`
  - crea o actualiza documentos solo para pools objetivo
- `msp_pool_documentos_claim_para_lote`
  - selecciona pools listos para una etapa y los marca atomica/transaccionalmente
- `msp_aplicar_saldo_favor_lote`
  - aplica saldo sobre `id_lote_envio` ya cerrado en contenido

#### Procedimientos a deprecar en este flujo

- `msp_generar_documentos_cobro_periodo` como motor principal del proceso mensual por etapa

No necesariamente eliminarlo. Puede quedar para:

- backfill inicial
- regeneracion total controlada
- soporte administrativo

### 2. Servicios PHP

#### Nuevo servicio sugerido

- `cobros/services/PoolDocumentosPeriodoService.php`

Responsabilidades:

- sincronizar pool del periodo
- refrescar readiness por etapa
- construir resumenes del pool
- materializar documentos desde pool
- obtener pendientes y motivos

#### Ajustes en `DocumentosCobroService`

Moverlo desde:

- “generar documentos de todo el periodo”

hacia:

- “upsert de documento para subconjunto de pools”

Debe dejar de borrar documentos del periodo por defecto.

#### Ajustes en `EnvioLotesProgramadosService`

Cambiar origen de candidatos:

- hoy: `fetchCompletionCandidatesByStage()` lee documentos e infiere items
- objetivo: leer `msp_pool_documentos_periodo` y exigir `id_documento_cobro IS NOT NULL`

### 3. operacion_mensual.php

#### Paso 2

Mantener ajuste manual de saldo, pero mostrar tambien:

- tamaño del pool
- listos por etapa
- loteados
- pendientes con motivo

#### Pasos 3, 4 y 5

Cambiar el significado de cada boton.

Hoy:

- recalcula cobros
- borra documentos
- regenera documentos
- programa lote

Objetivo:

- sincronizar/actualizar pool del periodo
- refrescar readiness de la etapa
- materializar solo documentos del subconjunto listo de esa etapa que aun no existen
- programar lote solo con pools listos y no loteados
- aplicar saldo a favor solo a ese lote
- marcar pools como `loteado`

#### Paso 6

La vista de lotes debe poder mostrar:

- cuantos pools siguen pendientes
- cuantos ya loteados por etapa
- cuantos documentos existentes no estan loteados
- cuantos tienen saldo aplicado

## Estrategia de Implementacion

### Fase 0. Congelamiento del flujo destructivo

Objetivo: parar la perdida de estado.

Cambios:

- quitar `borrarGeneracion(... borrarDocumentos=true ...)` de etapas 3/4/5
- dejar de anular automaticamente aplicaciones de saldo al regenerar etapa
- mantener aplicacion de saldo solo por lote

Resultado esperado:

- no se pisan documentos previos entre LUZ, GAS y AGUA
- el periodo deja de “rehacerse” completo en cada etapa

### Fase 1. Introducir pool persistente

Objetivo: crear la fuente de verdad operativa.

Cambios:

- patch DB con `msp_pool_documentos_periodo`
- servicio PHP para sincronizarlo
- resumenes UI por estado del pool

Resultado esperado:

- cada contrato/tienda del periodo queda identificado una sola vez
- se puede ver quien falta y por que

### Fase 2. Separar readiness de materializacion

Objetivo: que “estar listo” no implique regenerar documentos.

Cambios:

- refresco de flags `ready_*`
- materializacion/upsert solo para pool seleccionado

Resultado esperado:

- documentos existentes no se borran
- documentos nuevos se crean solo cuando corresponde

### Fase 3. Lotear desde pool

Objetivo: que el lote no dependa de inferencias sobre el periodo completo.

Cambios:

- `EnvioLotesProgramadosService` usa pool
- el lote toma solo `pool listos + documentados + no loteados`

Resultado esperado:

- lote estable, reproducible, auditable
- no mezcla candidatos de otras etapas

### Fase 4. Saldo a favor por lote

Objetivo: que el saldo quede asociado al conjunto exacto del lote.

Cambios:

- aplicar saldo via `id_lote_envio`
- registrar aplicacion con referencia al lote
- eliminar cualquier revocacion automatica por nueva etapa

Resultado esperado:

- saldo consistente
- no desaparece por regenerar AGUA despues de GAS

## Riesgos y Controles

1. Riesgo: duplicar documentos al migrar.
   Control: usar `id_pool_documento` y constraint unica fuerte.

2. Riesgo: lotear documentos incompletos.
   Control: readiness persistente por etapa y transaccion de claim.

3. Riesgo: desalinear saldo global y saldo por periodo.
   Control: toda regularizacion debe entrar por `msp_movimientos_saldo_favor_tienda`, nunca por update directo manual.

4. Riesgo: mezclar tienda y contrato si cambia arrendatario dentro del mismo periodo.
   Control: guardar snapshot de `id_contrato_arriendo` en pool y en documento.

## Recomendacion Operativa

La solucion correcta no es seguir parchando la regeneracion masiva actual.

La ruta correcta es:

1. estabilizar el flujo actual para que no borre estado,
2. introducir pool persistente,
3. mover la logica de readiness y loteo al pool,
4. dejar la capa documental como materializacion incremental del pool.

## Archivos Impactados

### DB

- [db/msp_documento_pago.sql](/mnt/c/wamp64/www/portalgp/msp/db/msp_documento_pago.sql:381)
- [db/patch_envio_lotes_programados.sql](/mnt/c/wamp64/www/portalgp/msp/db/patch_envio_lotes_programados.sql:12)
- [db/patch_saldo_favor_tienda.sql](/mnt/c/wamp64/www/portalgp/msp/db/patch_saldo_favor_tienda.sql:97)
- [db/patch_saldo_favor_periodo.sql](/mnt/c/wamp64/www/portalgp/msp/db/patch_saldo_favor_periodo.sql:12)

### PHP

- [cobros/operacion_mensual.php](/mnt/c/wamp64/www/portalgp/msp/cobros/operacion_mensual.php:3511)
- [cobros/services/DocumentosCobroService.php](/mnt/c/wamp64/www/portalgp/msp/cobros/services/DocumentosCobroService.php:8)
- [cobros/services/EnvioLotesProgramadosService.php](/mnt/c/wamp64/www/portalgp/msp/cobros/services/EnvioLotesProgramadosService.php:373)

## Entregable Siguiente Recomendado

Implementar primero un patch de estabilizacion de bajo riesgo:

- no borrar documentos del periodo en etapas 3/4/5
- no revertir saldo aplicado automaticamente al regenerar otra etapa
- introducir un resumen de pool virtual en UI usando consultas, aun antes de crear la tabla nueva

Ese paso permite frenar el problema actual antes de entrar al rediseño completo.
