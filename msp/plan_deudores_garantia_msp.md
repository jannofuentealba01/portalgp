# Plan Deudores y Garantia MSP

## Objetivo

Disenar una capa de gestion de deudores y garantia para MSP que permita:

- registrar la garantia al inicio de la ocupacion
- descontar de la garantia deudas reales y cargos de salida
- reservar garantia para servicios consumidos pero aun no facturados
- calcular cuanto se devuelve al arrendatario al retirarse
- dejar trazabilidad completa del uso de la garantia

## Diagnostico del modelo actual

Hoy la BD `msp_*` ya resuelve parte del problema:

- deuda emitida:
  - `msp_documentos_cobro`
  - `msp_documentos_cobro_detalle`
- pagos:
  - `msp_pagos`
- saldo a favor operativo:
  - `msp_saldos_favor_tienda`
  - `msp_movimientos_saldo_favor_tienda`
- relacion tienda/local y vigencia:
  - `msp_tiendas`
  - `msp_ocupacion_locales`

Pero falta modelar 4 cosas clave:

1. La garantia como fondo separado del saldo a favor comun.
2. El ciclo contractual o de ocupacion que da origen a esa garantia.
3. La liquidacion de salida del arrendatario.
4. Los cargos pendientes al retiro, especialmente servicios del mes actual aun no facturados.

## Problema principal de diseno

La garantia no deberia colgar directo de `msp_arrendatarios` ni solo de `msp_tiendas`.

Motivo:

- un arrendatario puede pasar por varias ocupaciones
- una tienda puede tener historico
- una ocupacion puede involucrar uno o mas locales
- la garantia pertenece a un ciclo de arriendo concreto, no a la persona de forma indefinida

Por eso, antes de gestionar bien deudores y garantia, conviene introducir una entidad contractual o de ciclo.

## Propuesta base

Propongo agregar una capa de contratos/salida y no mezclar la garantia con `msp_saldos_favor_tienda`.

`msp_saldos_favor_tienda` sirve para credito operativo corriente.

La garantia es distinta porque:

- nace al inicio del arriendo
- tiene reglas de uso especificas
- puede quedar retenida al cierre
- puede devolverse parcial o totalmente
- requiere liquidacion formal al termino

## Tablas nuevas propuestas

## 1. `msp_contratos_arriendo`

Entidad central para el ciclo de ocupacion comercial.

Campos sugeridos:

- `id_contrato_arriendo`
- `id_tienda`
- `id_arrendatario`
- `fecha_inicio`
- `fecha_termino_pactada`
- `fecha_salida_real`
- `dia_cobro`
- `monto_garantia_pactada`
- `monto_arriendo_pactado`
- `estado_contrato` -- borrador, vigente, en_salida, cerrado, anulado
- `observaciones_salida`

Uso:

- anclar garantia, deuda y salida a un ciclo concreto
- separar ocupacion historica de relacion contractual

Nota:

Aunque hoy exista `msp_ocupacion_locales`, esa tabla representa cruce tienda/local. No alcanza sola para controlar garantia y salida.

## 2. `msp_garantias`

Cabecera del fondo de garantia por contrato.

Campos sugeridos:

- `id_garantia`
- `id_contrato_arriendo`
- `fecha_constitucion`
- `monto_inicial`
- `saldo_disponible`
- `saldo_reservado`
- `saldo_aplicado`
- `saldo_devuelto`
- `estado_garantia` -- vigente, reservada, aplicada_parcial, liquidada, devuelta

Uso:

- mantener un solo saldo vivo por contrato
- distinguir disponible vs reservado

## 3. `msp_movimientos_garantia`

Libro auditable de todos los movimientos de garantia.

Campos sugeridos:

- `id_movimiento_garantia`
- `id_garantia`
- `fecha_movimiento`
- `tipo_movimiento_garantia`
- `monto_movimiento`
- `id_documento_cobro`
- `id_pago`
- `id_liquidacion_salida`
- `id_cargo_salida`
- `observaciones`

Tipos sugeridos:

- `CONSTITUCION`
- `RESERVA_SALIDA`
- `APLICACION_DEUDA`
- `APLICACION_MULTA`
- `APLICACION_SERVICIO_ESTIMADO`
- `AJUSTE_SERVICIO_REAL`
- `LIBERACION_RESERVA`
- `DEVOLUCION`
- `CASTIGO_MANUAL`
- `REVERSA`

Regla:

- nunca editar saldo directo; siempre recalcular desde movimientos

## 4. `msp_tipos_cargo_salida`

Catalogo para clasificar descuentos de salida.

Campos sugeridos:

- `id_tipo_cargo_salida`
- `codigo_tipo_cargo`
- `nombre_tipo_cargo`
- `requiere_documento`
- `permite_estimacion`
- `activo`

Ejemplos:

- `ARRIENDO_VENCIDO`
- `SERVICIO_PENDIENTE`
- `SERVICIO_ESTIMADO`
- `MULTA_CONTRACTUAL`
- `DANOS`
- `ASEO_REPARACION`
- `OTRO_DESCUENTO`

## 5. `msp_cargos_salida`

Cargos asociados a la liquidacion de un contrato.

Campos sugeridos:

- `id_cargo_salida`
- `id_contrato_arriendo`
- `id_tipo_cargo_salida`
- `fecha_cargo`
- `origen_cargo` -- DOCUMENTO, ESTIMACION, MULTA, MANUAL
- `id_documento_cobro`
- `periodo_referencia`
- `monto_cargo`
- `estado_cargo` -- pendiente, reservado, aplicado, anulado
- `es_estimado`
- `requiere_regularizacion`
- `detalle_origen`

Uso:

- reunir en una misma capa toda la deuda de salida
- distinguir deuda real de deuda estimada

## 6. `msp_liquidaciones_salida`

Cabecera del proceso de cierre de contrato.

Campos sugeridos:

- `id_liquidacion_salida`
- `id_contrato_arriendo`
- `fecha_salida_real`
- `fecha_corte_deuda`
- `fecha_liquidacion`
- `estado_liquidacion` -- borrador, en_revision, cerrada, pagada, anulada
- `monto_garantia_inicial`
- `monto_cargos`
- `monto_reservado`
- `monto_a_devolver`
- `monto_por_cobrar`
- `observaciones`

Uso:

- ser el expediente de salida del arrendatario
- consolidar deudas, reservas y devolucion

## 7. `msp_liquidaciones_salida_detalle`

Detalle de componentes de la liquidacion.

Campos sugeridos:

- `id_liquidacion_salida_detalle`
- `id_liquidacion_salida`
- `origen_detalle` -- GARANTIA, DOCUMENTO, CARGO, RESERVA, DEVOLUCION, AJUSTE
- `id_documento_cobro`
- `id_cargo_salida`
- `descripcion_detalle`
- `monto_debito`
- `monto_credito`
- `orden_detalle`

Uso:

- mostrar claramente por que se retuvo o devolvio dinero
- imprimir finiquito o respaldo de liquidacion

## Tablas opcionales de fase 2

## `msp_multas`

Solo si quieren separar multas del resto de cargos manuales.

## `msp_compromisos_pago`

Solo si, despues de usar toda la garantia, queda saldo pendiente por cobrar al ex arrendatario.

Campos sugeridos:

- `id_compromiso_pago`
- `id_contrato_arriendo`
- `fecha_compromiso`
- `monto_comprometido`
- `fecha_vencimiento`
- `estado_compromiso`

## Que se puede reutilizar del modelo actual

No todo hay que crearlo.

### Se reutiliza como deuda ya emitida

- `msp_documentos_cobro`
- `msp_documentos_cobro_detalle`
- `msp_pagos`

### Se reutiliza como vigencia operacional

- `msp_tiendas`
- `msp_ocupacion_locales`

### Lo que NO conviene reutilizar como garantia

- `msp_saldos_favor_tienda`
- `msp_movimientos_saldo_favor_tienda`

Esas tablas hoy funcionan mejor como credito operativo general, no como deposito de garantia contractual.

## Flujo propuesto

## Caso 1. Inicio de arriendo

1. Se crea `msp_contratos_arriendo`.
2. Se registra `msp_garantias` con `monto_inicial`.
3. Se inserta movimiento `CONSTITUCION` en `msp_movimientos_garantia`.

Resultado:

- la garantia queda separada del flujo normal de pagos mensuales

## Caso 2. Durante la vigencia

La deuda corriente sigue viniendo desde:

- `msp_documentos_cobro`
- `msp_pagos`

No se toca la garantia salvo que exista una decision formal de aplicarla.

## Caso 3. Inicio de salida del arrendatario

Cuando el arrendatario avisa o se retira:

1. el contrato pasa a estado `en_salida`
2. se crea `msp_liquidaciones_salida`
3. el sistema levanta deuda abierta desde `msp_documentos_cobro`
4. el usuario agrega multas o danos si corresponde
5. el sistema calcula servicios pendientes del mes actual

## Caso 4. Servicios consumidos pero aun no facturados

Este es el caso que describiste y debe quedar modelado explicitamente.

Propuesta:

- no esperar la factura real para cerrar la salida
- generar un `msp_cargo_salida` de tipo `SERVICIO_ESTIMADO`
- reservar parte de la garantia con movimiento `RESERVA_SALIDA`

Origen de la estimacion:

- ultima lectura conocida
- prorrateo por dias
- consumo promedio historico
- ingreso manual controlado

Campos clave del cargo:

- `es_estimado = 1`
- `requiere_regularizacion = 1`

Resultado:

- la garantia no se devuelve completa si todavia hay consumo no liquidado

## Caso 5. Aplicacion de garantia

Orden recomendado de aplicacion:

1. arriendos o documentos vencidos
2. servicios ya emitidos y no pagados
3. multas o danos validados
4. servicios estimados al retiro
5. otros ajustes aprobados

Cada descuento genera:

- un `msp_cargo_salida`
- un movimiento en `msp_movimientos_garantia`
- una linea en `msp_liquidaciones_salida_detalle`

## Caso 6. Devolucion

Si despues de aplicar y reservar queda remanente:

1. se calcula `monto_a_devolver`
2. se genera movimiento `DEVOLUCION`
3. la garantia queda en estado `devuelta` o `liquidada`

## Caso 7. Regularizacion posterior

Cuando llega la cuenta real del servicio pendiente:

1. se compara real vs estimado
2. si el real es menor:
   - se libera reserva
   - se aumenta devolucion o saldo a devolver
3. si el real es mayor:
   - se aplica diferencia adicional
   - si no alcanza la garantia, queda `monto_por_cobrar`

## Reglas de negocio recomendadas

1. La garantia pertenece al contrato, no al arrendatario en abstracto.
2. No se modifica `saldo_disponible` manualmente.
3. Toda aplicacion a deuda debe quedar respaldada por cargo o documento.
4. Una liquidacion cerrada no se borra; se revierte con movimientos compensatorios.
5. Los cargos estimados deben quedar claramente marcados para regularizacion posterior.
6. La devolucion no puede ejecutarse mientras existan reservas pendientes sin resolver, salvo autorizacion explicita.

## Vistas recomendadas

### `msp_vw_deudores_resumen`

Debe mostrar por contrato o tienda:

- deuda vencida
- deuda vigente
- garantia disponible
- garantia reservada
- monto estimado pendiente
- monto a devolver
- monto por cobrar

### `msp_vw_liquidaciones_salida`

Debe mostrar:

- contrato
- arrendatario
- tienda
- fecha salida
- garantia inicial
- cargos aplicados
- reservas
- devolucion final
- estado

## MVP recomendado

Si quieres partir sin sobredisenar, yo haria esto:

### Tablas minimas

- `msp_contratos_arriendo`
- `msp_garantias`
- `msp_movimientos_garantia`
- `msp_cargos_salida`
- `msp_liquidaciones_salida`
- `msp_liquidaciones_salida_detalle`

### Sincronia con modelo actual

- deuda emitida se lee desde `msp_documentos_cobro`
- pagos se leen desde `msp_pagos`
- ocupacion historica se sigue leyendo desde `msp_ocupacion_locales`

Con eso ya puedes:

- registrar garantia inicial
- abrir salida
- descontar deudas
- retener por servicios estimados
- devolver remanente

## Etapas recomendadas

## Etapa 1. Contrato y garantia

Crear:

- `msp_contratos_arriendo`
- `msp_garantias`
- `msp_movimientos_garantia`

## Etapa 2. Liquidacion de salida

Crear:

- `msp_tipos_cargo_salida`
- `msp_cargos_salida`
- `msp_liquidaciones_salida`
- `msp_liquidaciones_salida_detalle`

## Etapa 3. Reglas de estimacion

Definir como se calcula el servicio pendiente al retiro:

- promedio ultimos meses
- lectura real parcial
- prorrateo por dias
- ingreso manual con motivo obligatorio

## Etapa 4. Reporteria de deudores

Crear vistas y pantallas para:

- deudores vigentes
- salidas pendientes
- garantias retenidas
- devoluciones pendientes

## Recomendacion concreta

Para este problema, la pieza mas importante no es una tabla de deuda sino `msp_contratos_arriendo`.

Sin eso:

- la garantia queda mal anclada
- el historial se mezcla
- la salida no tiene expediente formal

Con eso:

- la gestion de deudores queda ordenada
- la garantia queda auditable
- la devolucion final se puede calcular bien

## Siguiente entregable sugerido

Si este plan te hace sentido, el siguiente paso correcto es uno de estos:

1. escribir el SQL inicial `msp/db/msp_deudores_garantia.sql`
2. definir el modelo exacto de `msp_contratos_arriendo`
3. definir la regla de calculo para `SERVICIO_ESTIMADO` al retiro

