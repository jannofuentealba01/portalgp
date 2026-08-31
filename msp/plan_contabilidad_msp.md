# Plan Contabilidad MSP

## Objetivo

Extender la BD actual de MSP para soportar calculo contable, tipos de movimiento, generacion de asientos y gestion contable basica, sin romper el modelo operativo ya existente.

La idea no es rehacer MSP. La idea es montar una capa contable sobre lo que ya existe en `dbo` con prefijo `msp_`.

## Decision de arquitectura (actual)

- La capa contable se definira desacoplada de la capa operativa.
- Se usara prefijo `msp_acc_*` para tablas, vistas y procedimientos contables nuevos.
- No es prioridad inmediata: queda como plan tecnico y roadmap.
- La capa `msp_*` operativa se mantiene sin cambios estructurales por ahora.
- Integracion por referencia de origen (`tabla_origen`, `id_origen`, `id_origen_detalle`), sin mezclar reglas contables en tablas operativas.

## Diagnostico de la BD actual

En `msp/db/` ya existe una base operativa razonablemente solida:

- Maestros de negocio:
  - `msp_arrendatarios`
  - `msp_tiendas`
  - `msp_locales`
  - `msp_ocupacion_locales`
- Proceso de cobro:
  - `msp_cierre_mensual`
  - `msp_procesos_cobro_servicio`
  - `msp_medidores`
  - `msp_lecturas_medidores`
  - `msp_cobros_servicios`
- Capa documental y de recaudacion:
  - `msp_documentos_cobro`
  - `msp_documentos_cobro_detalle`
  - `msp_pagos`
  - `msp_saldos_favor_tienda`
  - `msp_movimientos_saldo_favor_tienda`

Conclusion: ya existe el flujo operacional. Lo que falta no es tanto "mas operacion", sino una capa contable propia:

- catalogo de tipos de movimiento contable
- plan de cuentas
- reglas de contabilizacion
- periodos contables
- asientos contables
- detalle debe/haber
- trazabilidad entre origen MSP y asiento generado

## Criterio de diseno

Propongo no mezclar directamente la logica contable dentro de `msp_documentos_cobro` o `msp_pagos`.

Conviene separar en 3 capas:

1. Capa operativa:
   - sigue viviendo en las tablas `msp_*` actuales
2. Capa de configuracion contable:
   - define tipos de movimiento, cuentas y reglas
3. Capa de contabilizacion:
   - genera asientos y detalle contable a partir de eventos de negocio

Con esto se gana:

- trazabilidad
- recalculo controlado
- anulaciones limpias
- exportacion futura a Softland u otro ERP
- auditoria historica

## Propuesta general

No hace falta partir agregando "muchas tablas" al mismo tiempo. Para una primera version ordenada, propondria 9 tablas nuevas y 2 vistas/procedimientos.

## Tablas nuevas propuestas

### 1. `msp_acc_periodos_contables`

Define los periodos abiertos/cerrados para contabilizar.

Campos sugeridos:

- `id_periodo_contable`
- `anio`
- `mes`
- `fecha_inicio`
- `fecha_fin`
- `estado_periodo`  -- borrador, abierto, cerrado, bloqueado
- `fecha_cierre`
- `id_usuario_cierre`

Uso:

- bloquear contabilizaciones fuera de periodo
- permitir cierre contable distinto del cierre operacional

### 2. `msp_acc_tipos_movimiento_contable`

Catalogo central para lo que tu mencionas como "guardar los tipos de movimiento".

Campos sugeridos:

- `id_tipo_movimiento_contable`
- `codigo_movimiento`
- `nombre_movimiento`
- `origen_negocio`  -- DOCUMENTO, PAGO, SALDO_FAVOR, AJUSTE, ANULACION
- `requiere_documento`
- `requiere_pago`
- `genera_automatico`
- `activo`

Ejemplos iniciales:

- `EMISION_ARRIENDO`
- `EMISION_SERVICIO`
- `PAGO_APLICADO`
- `PAGO_ANTICIPADO`
- `SALDO_FAVOR_GENERADO`
- `SALDO_FAVOR_APLICADO`
- `AJUSTE_DEBITO`
- `AJUSTE_CREDITO`
- `ANULACION_DOCUMENTO`
- `ANULACION_PAGO`

### 3. `msp_acc_plan_cuentas`

Plan de cuentas interno de MSP.

Campos sugeridos:

- `id_cuenta_contable`
- `codigo_cuenta`
- `nombre_cuenta`
- `tipo_cuenta` -- ACTIVO, PASIVO, PATRIMONIO, INGRESO, GASTO, ORDEN
- `naturaleza` -- DEUDORA, ACREEDORA
- `acepta_movimiento`
- `id_cuenta_padre`
- `nivel_cuenta`
- `activo`

Uso:

- soportar jerarquia contable
- independizar MSP del plan del ERP externo
- mapear luego a Softland si hace falta

### 4. `msp_acc_auxiliares_contables`

Catalogo de auxiliar contable para terceros de negocio.

Campos sugeridos:

- `id_auxiliar_contable`
- `tipo_auxiliar` -- ARRENDATARIO, TIENDA, OTRO
- `id_arrendatario`
- `id_tienda`
- `rut_auxiliar`
- `nombre_auxiliar`
- `activo`

Uso:

- asociar un asiento a tercero
- evitar duplicar nombres al momento de contabilizar

Nota:

En una primera fase se puede poblar automaticamente desde `msp_arrendatarios` y `msp_tiendas`.

### 5. `msp_acc_reglas_contables`

Cabecera de reglas de contabilizacion.

Campos sugeridos:

- `id_regla_contable`
- `id_tipo_movimiento_contable`
- `codigo_regla`
- `nombre_regla`
- `vigente_desde`
- `vigente_hasta`
- `por_tipo_item`  -- si aplica segun item documental
- `por_tipo_servicio` -- si aplica segun agua/luz/gas
- `activo`

Uso:

- versionar reglas
- cambiar cuentas sin tocar historico

### 6. `msp_acc_reglas_contables_detalle`

Detalle debe/haber por regla.

Campos sugeridos:

- `id_regla_contable_detalle`
- `id_regla_contable`
- `lado_movimiento` -- D o H
- `orden_linea`
- `id_cuenta_contable`
- `usa_auxiliar`
- `auxiliar_desde` -- ARRENDATARIO, TIENDA, DOCUMENTO, PAGO, NULL
- `usa_centro_costo`
- `centro_costo_desde`
- `formula_monto` -- TOTAL_DOCUMENTO, TOTAL_ITEM, TOTAL_PAGO, SALDO_FAVOR, MANUAL
- `glosa_template`

Uso:

- parametrizar el asiento
- no hardcodear cuentas en PHP ni en procedimientos

### 7. `msp_acc_asientos_contables`

Cabecera del asiento contable.

Campos sugeridos:

- `id_asiento_contable`
- `id_periodo_contable`
- `id_tipo_movimiento_contable`
- `fecha_contable`
- `numero_asiento`
- `glosa`
- `estado_asiento` -- borrador, contabilizado, anulado
- `tabla_origen`
- `id_origen`
- `id_origen_detalle`
- `hash_origen`
- `fecha_registro`
- `id_usuario_registro`
- `fecha_anulacion`
- `id_asiento_reversa`

Uso:

- un asiento por evento contable
- trazabilidad directa al origen MSP

Clave recomendada:

- indice unico por `hash_origen` para impedir doble contabilizacion del mismo evento

### 8. `msp_acc_asientos_contables_detalle`

Lineas debe/haber.

Campos sugeridos:

- `id_asiento_contable_detalle`
- `id_asiento_contable`
- `linea`
- `id_cuenta_contable`
- `id_auxiliar_contable`
- `id_tienda`
- `id_arrendatario`
- `id_local`
- `id_documento_cobro`
- `id_pago`
- `debe`
- `haber`
- `glosa_detalle`

Reglas:

- `debe >= 0`
- `haber >= 0`
- una linea no puede tener ambos valores en cero
- validacion de cuadratura por asiento

### 9. `msp_acc_movimientos_contables_log`

Bitacora de generacion, regeneracion, anulacion y errores.

Campos sugeridos:

- `id_log_contable`
- `id_asiento_contable`
- `id_tipo_movimiento_contable`
- `tabla_origen`
- `id_origen`
- `accion_log` -- GENERADO, REGENERADO, ANULADO, ERROR
- `resultado`
- `mensaje`
- `payload_snapshot`
- `fecha_registro`
- `id_usuario`

Uso:

- auditoria operacional
- soporte al troubleshooting

## Tablas opcionales para una fase 2

Estas no son obligatorias para empezar:

### `msp_acc_centros_costo`

Solo si realmente necesitan analitica por centro de costo distinta de tienda/local.

### `msp_acc_cuentas_contables_equivalencia`

Solo si quieren mapear el plan de cuentas MSP hacia cuentas de Softland u otro ERP.

Campos sugeridos:

- `id_equivalencia`
- `id_cuenta_contable`
- `sistema_destino`
- `codigo_cuenta_destino`
- `nombre_cuenta_destino`
- `activo`

### `msp_acc_comprobantes_exportacion`

Solo si luego se quiere marcar que asientos fueron exportados al ERP externo.

## Que reutilizar de la BD actual

La clave es que no todo debe partir desde cero.

### Origenes de movimientos ya disponibles

- `msp_documentos_cobro`
  - sirve para contabilizar emision de documento
- `msp_documentos_cobro_detalle`
  - sirve para separar arriendo vs servicios vs ajustes
- `msp_pagos`
  - sirve para contabilizar ingreso de pago
- `msp_movimientos_saldo_favor_tienda`
  - sirve para contabilizar anticipos, aplicaciones y reversas
- `msp_cobros_servicios`
  - sirve para analitica y soporte del monto de servicio

### Lo que NO conviene hacer

- no meter columnas contables sueltas en todas las tablas operativas
- no guardar solo un "estado contabilizado" sin guardar el asiento
- no hardcodear cuentas contables en la aplicacion
- no contabilizar directamente desde UI sin reglas versionadas

## Modelo de contabilizacion sugerido

### Caso 1. Emision de documento

Origen:

- `msp_documentos_cobro`
- `msp_documentos_cobro_detalle`

Movimiento:

- tipo movimiento: `EMISION_ARRIENDO` o `EMISION_SERVICIO`

Asiento ejemplo:

- Debe: cuenta por cobrar arrendatario
- Haber: ingreso por arriendo
- Haber: ingreso por servicios

### Caso 2. Pago aplicado

Origen:

- `msp_pagos`

Movimiento:

- tipo movimiento: `PAGO_APLICADO`

Asiento ejemplo:

- Debe: caja/banco
- Haber: cuenta por cobrar arrendatario

### Caso 3. Pago que genera saldo a favor

Origen:

- `msp_pagos`
- `msp_movimientos_saldo_favor_tienda`

Movimiento:

- tipo movimiento: `SALDO_FAVOR_GENERADO`

Asiento ejemplo:

- Debe: caja/banco
- Haber: anticipo de cliente / pasivo por saldo a favor

### Caso 4. Aplicacion de saldo a favor

Origen:

- `msp_movimientos_saldo_favor_tienda`

Movimiento:

- tipo movimiento: `SALDO_FAVOR_APLICADO`

Asiento ejemplo:

- Debe: pasivo por anticipo
- Haber: cuenta por cobrar

### Caso 5. Anulaciones

No recomiendo borrar asientos.

Conviene:

- marcar el asiento original como referenciado
- generar asiento reversa
- vincular ambos con `id_asiento_reversa`

## Flujo recomendado

### Fase 1. Base contable minima

Crear:

- `msp_acc_periodos_contables`
- `msp_acc_tipos_movimiento_contable`
- `msp_acc_plan_cuentas`
- `msp_acc_auxiliares_contables`
- `msp_acc_reglas_contables`
- `msp_acc_reglas_contables_detalle`
- `msp_acc_asientos_contables`
- `msp_acc_asientos_contables_detalle`
- `msp_acc_movimientos_contables_log`

Entregable:

- estructura lista para contabilizar

### Fase 2. Contabilizacion automatica de documentos

Implementar procedimiento tipo:

- `dbo.msp_acc_generar_asientos_documentos_periodo`

Origen:

- `msp_documentos_cobro`
- `msp_documentos_cobro_detalle`

Entregable:

- documentos emitidos generan asientos cuadrables

### Fase 3. Contabilizacion automatica de pagos y saldo a favor

Implementar procedimientos tipo:

- `dbo.msp_acc_generar_asientos_pagos_periodo`
- `dbo.msp_acc_generar_asientos_saldo_favor_periodo`

Entregable:

- pagos y anticipos quedan trazados contablemente

### Fase 4. Anulaciones y reversas

Implementar:

- anulacion de asiento por reversa
- bloqueo de borrado fisico si ya existe impacto contable

### Fase 5. Reporteria contable

Vistas recomendadas:

- `dbo.msp_acc_vw_libro_diario`
- `dbo.msp_acc_vw_saldos_cuentas`
- `dbo.msp_acc_vw_movimientos_por_auxiliar`
- `dbo.msp_acc_vw_trazabilidad_origen_contable`

## Propuesta de relaciones clave

### Relacion de origen a asiento

`msp_acc_asientos_contables` debe guardar:

- `tabla_origen`
- `id_origen`
- `id_origen_detalle`
- `id_tipo_movimiento_contable`

Esto permite contabilizar distintos eventos sin crear una tabla de enlace distinta para cada caso.

### Relacion de tercero

`msp_acc_asientos_contables_detalle` puede guardar ambos:

- `id_auxiliar_contable`
- referencias directas de negocio como `id_tienda`, `id_arrendatario`, `id_documento_cobro`, `id_pago`

Eso sirve para auditoria y para reportes operativos.

## Reglas de integridad importantes

1. Un origen no debe contabilizarse dos veces para el mismo tipo de movimiento.
2. Todo asiento debe cuadrar: suma(debe) = suma(haber).
3. No se debe contabilizar en periodo cerrado.
4. Un documento anulado no debe generar un nuevo asiento normal; debe generar reversa.
5. Si un pago fue anulado, su asiento debe quedar revertido, no borrado.
6. Las reglas contables deben versionarse por vigencia.

## Procedimientos recomendados

Como base, propondria estos SP:

- `dbo.msp_acc_generar_asientos_documentos_periodo`
- `dbo.msp_acc_generar_asientos_pagos_periodo`
- `dbo.msp_acc_generar_asientos_saldo_favor_periodo`
- `dbo.msp_acc_revertir_asiento_contable`
- `dbo.msp_acc_reconstruir_asiento_desde_origen`

## Vista minima de arranque

Si hay que ser pragmaticos, yo partiria asi:

### MVP contable

Tablas nuevas minimas:

- `msp_acc_tipos_movimiento_contable`
- `msp_acc_plan_cuentas`
- `msp_acc_reglas_contables`
- `msp_acc_reglas_contables_detalle`
- `msp_acc_asientos_contables`
- `msp_acc_asientos_contables_detalle`

Y reutilizaria como origen:

- `msp_documentos_cobro`
- `msp_documentos_cobro_detalle`
- `msp_pagos`

Con eso ya puedes:

- guardar tipos de movimiento
- parametrizar cuentas
- generar asiento de emision
- generar asiento de pago
- tener libro diario basico

## Recomendacion concreta

No propondria agregar "muchas tablas" de una vez. Propondria hacerlo en dos olas:

### Ola 1

- capa contable basica
- contabilizacion de documentos
- contabilizacion de pagos

### Ola 2

- saldo a favor
- anulaciones con reversa
- equivalencia con ERP externo
- reporteria contable mas completa

## Orden tecnico recomendado

1. Crear tablas de configuracion contable.
2. Cargar catalogo inicial de tipos de movimiento.
3. Cargar plan de cuentas base.
4. Definir reglas contables por movimiento.
5. Crear tablas de asientos.
6. Construir SP de generacion automatica.
7. Crear vistas de auditoria y libro diario.
8. Recien despues hacer pantalla de gestion contable.

## Siguiente entregable sugerido

Si este plan te hace sentido, el siguiente paso correcto es uno de estos:

1. armar el script SQL inicial `msp/db/msp_acc_contabilidad.sql`
2. definir el catalogo real de tipos de movimiento contable
3. definir el plan de cuentas base para arriendo, servicios, cuentas por cobrar, caja/banco y anticipos

## Catalogo base sugerido (presentacion cliente)

Regla base:

- Activo y Gasto aumentan en Debe.
- Pasivo, Patrimonio e Ingreso aumentan en Haber.

### Cuentas recomendadas para el SW (MVP)

| Codigo | Cuenta | Tipo | Aumenta en | Uso en MSP |
|---|---|---|---|---|
| 1.1.01 | Caja | Activo | Debe | Pagos en efectivo |
| 1.1.02 | Bancos | Activo | Debe | Pagos por transferencia/depósito |
| 1.1.10 | CxC Arrendatarios (auxiliar por tienda) | Activo | Debe | Deuda por arriendo/servicios |
| 1.1.11 | CxC Multas y Danos (auxiliar por tienda) | Activo | Debe | Deuda por multas, danos, remodelacion |
| 2.1.01 | Anticipos de Arrendatarios | Pasivo | Haber | Sobrepagos y saldos a favor |
| 2.1.02 | Garantias Recibidas | Pasivo | Haber | Garantias de contratos |
| 2.1.03 | IVA Debito Fiscal | Pasivo | Haber | IVA de conceptos afectos |
| 2.1.10 | CxP Proveedores | Pasivo | Haber | Facturas por pagar (si se incorpora) |
| 4.1.01 | Ingreso por Arriendo | Ingreso | Haber | Neto de arriendo |
| 4.1.02 | Ingreso por Servicios Refacturados | Ingreso | Haber | Cobro de luz, gas, agua |
| 4.1.03 | Ingreso por Multas de Mora | Ingreso | Haber | Mora por atraso |
| 4.1.04 | Ingreso por Danos/Remodelacion | Ingreso | Haber | Cargos extraordinarios |
| 5.1.01 | Gasto por Servicios | Gasto | Debe | Costo interno de servicios (si se registra) |
| 5.1.02 | Gasto por Reparaciones/Mantencion | Gasto | Debe | Mantencion y reparaciones |
| 5.1.03 | Gastos Bancarios | Gasto | Debe | Comisiones bancarias |

### Ejemplos de asientos del negocio

1. Emision arriendo afecto IVA (ejemplo neto 1.000.000 + IVA 190.000)
- Debe: CxC Arrendatarios (tienda) 1.190.000
- Haber: Ingreso por Arriendo 1.000.000
- Haber: IVA Debito Fiscal 190.000

2. Emision servicio sin IVA adicional (ejemplo 100.000)
- Debe: CxC Arrendatarios (tienda) 100.000
- Haber: Ingreso por Servicios Refacturados 100.000

3. Pago parcial distribuido por concepto (ejemplo total 200.000)
- Debe: Bancos/Caja 200.000
- Haber: CxC Arrendatarios (tienda) 200.000

Nota:
- La distribucion por concepto (arriendo, luz, gas, agua, multa) se guarda en detalle operacional del pago.
- El asiento contable refleja el total aplicado a la CxC de la tienda.
