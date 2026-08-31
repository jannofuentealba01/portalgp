# Modulo Terrenos: Diseno de Datos y Flujos

## Objetivo

Este documento explica el criterio del modelo de datos del modulo de terrenos y propone los procedimientos base para operar los flujos principales:

- inventario de terrenos
- adquisicion de terrenos
- subdivisiones y fusiones
- venta de terrenos
- control de tasacion y valor comercial
- control de avaluo y contribuciones
- ficha de arquitectura y legal

El modelo asociado esta en [modulo_terrenos.dbml](/mnt/c/wamp64/www/portalgp/ct/docs/modulo_terrenos.dbml).

## Principios de modelado

1. No modelar una tabla por hoja Excel.
2. Separar datos maestros, eventos, historicos y calculos.
3. Guardar solo como columna lo que sea dato real o resultado oficial.
4. Los calculos de resumen y variaciones deben salir desde vistas o reportes.
5. Mantener trazabilidad completa de subdivisiones, fusiones, ventas y contribuciones.
6. Convencion de nombres: toda tabla del modulo debe comenzar con prefijo `ct_`.

## Entidades Base

### `ct_terreno`

Representa el inventario actual de terrenos.

Por que existe:
- es la entidad principal del modulo
- permite saber que terrenos existen hoy y cual es su estado actual
- concentra los datos maestros minimos del terreno

Campos clave:
- `id_terreno`
- `codigo`
- `estado`
- `rol_asignado`
- `rol_matriz`
- `superficie_m2`
- `direccion`
- `comuna`
- `fecha_ingreso`

Reglas:
- `rol_asignado` es el rol vigente del terreno
- `rol_matriz` se usa cuando el terreno nace por subdivision y tiene una matriz unica
- en fusion no se debe forzar `rol_matriz`; el origen debe salir del historial

### `ct_cliente`

Representa al comprador en el flujo de venta.

Por que existe:
- la venta puede involucrar uno o varios clientes
- evita duplicar datos de comprador en cada venta

Campos clave:
- `id_cliente`
- `tipo`
- `rut`
- `nombre`

## Entidades de Trazabilidad Predial

### `ct_movimiento_terreno`

Cabecera comun para los eventos del terreno.

Por que existe:
- unifica adquisicion, subdivision, fusion, venta y cambios de uso
- permite construir una linea de tiempo comun del terreno

Campos clave:
- `tipo`
- `estado`
- `fecha_movimiento`
- `referencia`
- `numero_expediente`

### `ct_movimiento_terreno_detalle`

Relaciona el movimiento con los terrenos involucrados.

Por que existe:
- un movimiento puede involucrar uno o varios terrenos
- sirve para distinguir `ORIGEN`, `RESULTADO` o `AFECTADO`

Ejemplos:
- subdivision: 1 origen, N resultados
- fusion: N origenes, 1 resultado
- venta: 1 terreno afectado

### `ct_movimiento_terreno_historial`

Mantiene la trazabilidad explicita entre terrenos origen y terrenos resultado.

Por que existe:
- resuelve el punto mas importante del modulo: el historial real
- permite saber de que terrenos viene un terreno actual
- soporta fusion y subdivision sin perder el arbol historico

Ejemplos:
- subdivision: `A -> B`, `A -> C`
- fusion: `A -> D`, `B -> D`, `C -> D`

## Entidades de Flujos de Negocio

### `ct_terreno_adquisicion`

Detalle del ingreso inicial del terreno al inventario.

Por que existe:
- adquisicion tiene datos propios que no aplican a otros eventos
- evita ensuciar `ct_movimiento_terreno` con campos notariales y registrales

Campos clave:
- `modalidad`
- `monto_total`
- `fecha_escritura`
- `notaria`
- `repertorio`
- `fecha_inscripcion`
- `fojas`
- `numero_inscripcion`

### `ct_terreno_venta`

Detalle de una venta de terreno.

Por que existe:
- la venta tiene datos contractuales y comerciales propios
- separa el acto de venta del inventario del terreno

Campos clave:
- `estado`
- `precio_total`
- `precio_total_uf`
- `fecha_valor_uf`
- `superficie_referencia_m2`
- `tipo_superficie_referencia`
- `valor_venta_uf_m2`
- `fecha_contrato`
- `numero_contrato`

### `ct_terreno_venta_cliente`

Relacion de venta con uno o varios clientes.

Por que existe:
- una venta puede repartirse por porcentaje entre varios clientes
- la suma de porcentajes por venta debe llegar a 100

Campos clave:
- `id_venta`
- `id_cliente`
- `porcentaje_derecho`
- `monto_asignado`
- `es_principal`

## Entidades de Contabilidad y Tributacion

### `ct_terreno_tasacion`

Historial de tasaciones formales.

Por que existe:
- el Excel separa tasacion de valor comercial y de venta
- una tasacion cambia en el tiempo
- se necesita guardar la mejor tasacion y su contexto

Campos clave:
- `fecha_tasacion`
- `uf_m2_tasacion`
- `uf_total_tasacion`
- `uf_total_liquidacion`
- `tasador`
- `mejor_tasacion`
- `hipotecado`
- `usufructuario`

Nota:
- si el negocio realmente usa `hipotecador` y no un booleano, este campo debe ajustarse

### `ct_terreno_valor_comercial`

Valor comercial de referencia del terreno.

Por que existe:
- no siempre coincide con la tasacion formal
- no siempre coincide con el valor de venta final

Campos clave:
- `fecha_valor`
- `uf_m2_comercial`
- `uf_total_comercial`
- `vigente`

### `ct_terreno_avaluo`

Resultado del avaluo para un periodo.

Por que existe:
- el avaluo es historico, no dato maestro
- se necesita distinguir resultado estimado interno de valor oficial SII

Campos clave:
- `anio`
- `semestre`
- `fuente`
- `es_oficial`
- `avaluo_terreno`
- `avaluo_construcciones`
- `avaluo_total`
- `avaluo_exento`
- `avaluo_afecto`

Reglas:
- `avaluo_total = avaluo_terreno + avaluo_construcciones`
- `avaluo_afecto = avaluo_total - avaluo_exento`

### `ct_terreno_avaluo_detalle`

Desglose del calculo o composicion del avaluo.

Por que existe:
- evita guardar solo un monto final sin explicacion
- permite mostrar por que el exento o afecto da cierto valor
- sirve tanto para estimacion interna como para carga manual

Campos clave:
- `tipo_componente`
- `descripcion`
- `cantidad`
- `valor_unitario`
- `factor`
- `porcentaje`
- `monto`

### `ct_terreno_avaluo_exencion`

Registra la causal y el calculo de exencion.

Por que existe:
- el exento no siempre es un porcentaje simple
- una exencion puede ser monto fijo, porcentaje, total o regla especial
- permite guardar varias candidatas y marcar la aplicada

Campos clave:
- `codigo_exencion`
- `nombre_exencion`
- `fundamento_legal`
- `modo_calculo`
- `monto_base`
- `porcentaje_exencion`
- `monto_exento_resultado`
- `es_aplicada`

### `ct_terreno_contribucion`

Registro del pago o deuda de contribuciones.

Por que existe:
- cada cuota es historica y debe trazarse por periodo
- el terreno puede pagar usando rol matriz o rol asignado segun su estado

Campos clave:
- `anio`
- `cuota`
- `estado_pago`
- `rol_pagado`
- `origen_rol_pagado`
- `fecha_vencimiento`
- `fecha_pago`
- `monto_emitido`
- `monto_pagado`
- `numero_comprobante`

Regla operativa:
- si el terreno esta vendido, normalmente se paga con `rol_asignado`
- si no esta vendido, normalmente se paga con `rol_matriz`
- si `rol_matriz` no existe, se usa `rol_asignado`
- aun asi se debe guardar el `rol_pagado` real

## Entidades de Arquitectura y Legal

### `ct_terreno_ficha`

Ficha consolidada del terreno para analisis tecnico y legal.

Por que existe:
- agrupa antecedentes necesarios antes de construir o regularizar
- permite versionar el estudio del terreno

Campos clave:
- `version`
- `estado`
- `responsable_arquitectura`
- `responsable_legal`
- `superficie_titulo_m2`
- `superficie_levantada_m2`
- `superficie_neta_arquitectura_m2`
- `resolucion`
- `fecha_resolucion`
- `uso_suelo`
- `zonificacion`
- `factibilidad_agua`
- `factibilidad_luz`
- `factibilidad_sanitaria`
- `empresa_sanitaria`
- `garantia`
- `resolucion_garantia`
- `fecha_vencimiento_garantia`
- `modificacion_deslindes`
- `nueva_superficie_m2`
- `situacion_legal`
- `restricciones_normativas`

### `ct_terreno_documento`

Adjunta documentos a distintos objetos del modulo.

Por que existe:
- escritura, plano, certificado, comprobante y otros respaldos no deben quedar solo como texto
- un mismo terreno puede tener documentos asociados a ficha, contribuciones o movimientos

## Entidades Auxiliares

### `ct_terreno_estado_historial`

Historial del estado del terreno.

Por que existe:
- ayuda a reconstruir en que estado estuvo el terreno en una fecha
- puede simplificar consultas operativas

### `ct_terreno_uso_historial`

Historial de uso del terreno.

Por que existe:
- el uso puede cambiar en el tiempo
- no conviene mezclarlo como un unico campo fijo en `ct_terreno`

## Lo Que No Debe Ser Tabla

Los bloques del Excel `Terrenos por vender`, `Terrenos familia`, `Terrenos vendidos` y `Terrenos otros` no deben modelarse como tablas independientes.

Deben salir desde vistas o consultas sobre el mismo nucleo de datos:

- `Terrenos vendidos`: terrenos con venta formalizada
- `Terrenos por vender`: terrenos disponibles o en gestion comercial
- `Terrenos familia`: filtro por cartera o clasificacion interna
- `Terrenos otros`: filtro por otra clasificacion interna

Tampoco deben guardarse como columnas fisicas:

- `Variacion 2025-2024 Avaluo Total`
- `Variacion 2025-2024 Avaluo Afecto`
- `Variacion 2025-2024 Avaluo Exento`

Eso debe calcularse desde los registros historicos de `ct_terreno_avaluo`.

## Procedimientos Sugeridos

### 1. Registrar terreno

Objetivo:
- crear el terreno base en inventario

Pasos:
1. crear registro en `ct_terreno`
2. registrar `ct_terreno_estado_historial` inicial
3. opcionalmente crear primera `ct_terreno_ficha`

### 2. Registrar adquisicion

Objetivo:
- ingresar formalmente un terreno al inventario

Pasos:
1. crear `ct_movimiento_terreno` tipo `ADQUISICION`
2. crear `ct_movimiento_terreno_detalle` para el terreno afectado
3. crear `ct_terreno_adquisicion`
4. actualizar estado del terreno si corresponde
5. registrar `ct_terreno_estado_historial`

### 3. Registrar subdivision

Objetivo:
- cerrar un terreno origen y crear uno o mas terrenos nuevos

Pasos:
1. crear `ct_movimiento_terreno` tipo `SUBDIVISION`
2. agregar el terreno padre como `ORIGEN`
3. crear los terrenos hijos
4. cada hijo debe tener `rol_asignado` propio y `rol_matriz` del padre
5. agregar hijos como `RESULTADO`
6. registrar relaciones en `ct_movimiento_terreno_historial`
7. cambiar estado del terreno origen a `SUBDIVIDIDO`
8. registrar historial de estado

### 4. Registrar fusion

Objetivo:
- unificar varios terrenos en un terreno nuevo

Pasos:
1. crear `ct_movimiento_terreno` tipo `FUSION`
2. agregar todos los terrenos origen como `ORIGEN`
3. crear el terreno resultado
4. no forzar `rol_matriz` en el terreno resultante
5. agregar terreno nuevo como `RESULTADO`
6. registrar relaciones origen -> resultado en `ct_movimiento_terreno_historial`
7. cambiar estado de los origenes a `FUSIONADO`
8. registrar historial de estado

### 5. Registrar venta

Objetivo:
- formalizar una venta y los porcentajes de compradores

Pasos:
1. crear `ct_movimiento_terreno` tipo `VENTA`
2. agregar el terreno vendido en `ct_movimiento_terreno_detalle`
3. crear `ct_terreno_venta`
4. crear uno o varios registros en `ct_terreno_venta_cliente`
5. validar que la suma de `porcentaje_derecho` sea 100
6. cambiar estado del terreno a `VENDIDO`
7. registrar `ct_terreno_estado_historial`

### 6. Registrar tasacion

Objetivo:
- guardar referencias de tasacion usadas por contabilidad

Pasos:
1. crear registro en `ct_terreno_tasacion`
2. si aplica, marcar `mejor_tasacion`
3. si se marca una mejor tasacion nueva, desmarcar la anterior

### 7. Registrar valor comercial

Objetivo:
- guardar referencia comercial vigente

Pasos:
1. crear registro en `ct_terreno_valor_comercial`
2. marcar `vigente = 1`
3. desactivar el registro vigente anterior del mismo terreno

### 8. Registrar avaluo

Objetivo:
- guardar el resultado del avaluo por periodo

Pasos:
1. crear `ct_terreno_avaluo`
2. registrar sus componentes en `ct_terreno_avaluo_detalle`
3. registrar exenciones candidatas o aplicada en `ct_terreno_avaluo_exencion`
4. marcar `es_oficial = 1` solo si viene de fuente SII o documento oficial

### 9. Registrar contribucion

Objetivo:
- guardar una cuota emitida o pagada

Pasos:
1. determinar el `rol_pagado` segun regla operativa
2. crear `ct_terreno_contribucion`
3. asociar `id_avaluo` si el cobro se basa en un avaluo conocido
4. guardar comprobante y observaciones

### 10. Generar o versionar ficha

Objetivo:
- mantener ficha de arquitectura y legal actualizada

Pasos:
1. crear o copiar una nueva `ct_terreno_ficha`
2. incrementar `version`
3. dejar la anterior como `OBSOLETA` si ya no es vigente
4. adjuntar respaldos en `ct_terreno_documento`

## Reglas de Integridad Recomendadas

1. `ct_terreno_venta_cliente`
- la suma de porcentajes por venta debe ser 100

2. `ct_movimiento_terreno_historial`
- no debe existir relacion origen -> resultado sin `ct_movimiento_terreno`

3. `ct_terreno_avaluo`
- un terreno puede tener varios avaluos por periodo si cambian las fuentes
- pero solo uno deberia estar marcado como oficial por periodo

4. `ct_terreno_valor_comercial`
- solo un registro vigente por terreno

5. `ct_terreno_tasacion`
- idealmente una sola `mejor_tasacion` activa por terreno

6. `ct_terreno_ficha`
- una sola ficha vigente por terreno

## Campos o Entidades que Aun Podrian Faltar

Estos puntos todavia deben confirmarse con el negocio:

- `propietario`
  - si es distinto del cliente comprador, probablemente requiere entidad propia

- `identificacion_propiedad`
  - si no coincide con `codigo` o `nombre`, puede requerir campo propio

- `detalle_tgr`
  - si tiene estructura real, podria necesitar tabla o campo especifico

- `estado_en_sii`
- `condicion_rol`
- `destino_sii`
  - parecen datos tributarios o catastrales propios del terreno

- `valor_libro_contable_uf`
  - si cambia en el tiempo, deberia ir en tabla historica contable

- `clasificacion_cartera`
  - necesaria si se quiere separar `familia`, `otros`, `por vender`

- `construccion`
  - aun no esta modelado como modulo propio
  - si la hoja de construccion maneja permisos, obras, etapas o unidades, requerira su propio diseno

## Vistas Recomendadas

1. `vw_terrenos_vendidos`
2. `vw_terrenos_por_vender`
3. `vw_terrenos_familia`
4. `vw_terrenos_otros`
5. `vw_terreno_resumen_contable`
6. `vw_terreno_resumen_arquitectura_legal`

## Conclusion

El modelo actual ya permite cubrir el nucleo del modulo:

- inventario
- trazabilidad predial
- venta
- contabilidad base
- avaluo y contribuciones
- ficha de arquitectura y legal

Lo correcto ahora no es seguir agregando tablas sin control, sino cerrar:

1. que campos del Excel son datos reales
2. que campos son calculos o vistas
3. que flujos necesitan procedimiento
4. que dominios faltan, especialmente `construccion`

## Implementacion SQL Server de Reglas Criticas

Para pasar de diseno a operacion, se agrego un script idempotente de integridad:

- [10_ct_terrenos_integridad.sql](/mnt/c/wamp64/www/portalgp/ct/db/10_ct_terrenos_integridad.sql)

El script materializa reglas clave del modelo:

1. `CHECK` de periodos y montos:
- `semestre` solo `1` o `2`
- `cuota` solo `1` a `4`
- `porcentaje_derecho` entre `0` y `100`
- consistencia de `avaluo_total` y `avaluo_afecto`

2. Unicidad operativa con indices filtrados:
- una sola tasacion `mejor_tasacion = 1` por terreno
- un solo valor comercial `vigente = 1` por terreno
- una sola ficha `estado = VIGENTE` por terreno
- un solo avaluo `es_oficial = 1` por terreno y periodo
- una sola exencion `es_aplicada = 1` por avaluo

3. Trazabilidad y calidad de flujo con triggers:
- venta `FORMALIZADA` debe sumar `100.00` (al editar clientes o al formalizar la venta)
- al confirmar movimiento:
  - `SUBDIVISION`: 1 `ORIGEN` y al menos 2 `RESULTADO`
  - `FUSION`: al menos 2 `ORIGEN` y 1 `RESULTADO`
  - `VENTA`: exactamente 1 `AFECTADO`
  - `SUBDIVISION`/`FUSION` requieren filas en `ct_movimiento_terreno_historial`

Recomendacion de despliegue:

1. correr el script en ambiente de QA
2. corregir datos historicos que no cumplan reglas
3. desplegar a produccion en ventana controlada
