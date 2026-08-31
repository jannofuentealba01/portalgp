# Plan Completo MSP - Cobros Mensuales por Tienda

## 1. Objetivo
Implementar un flujo simple para la secretaria que permita, cada mes, ingresar pocos datos y generar automaticamente los cobros de arriendo y servicios para todas las tiendas.

Resultado esperado:
1. La secretaria carga `valor UF`, datos de factura de `agua/luz/gas` y 3 excels de lecturas.
2. El sistema calcula cobros por local, agrupa por tienda y emite `1 documento mensual por tienda`.
3. El pago se registra en cuotas no fijas, con saldo y estado actualizados automaticamente.

---

## 2. Decisiones de negocio cerradas
1. La unidad de cobro es `tienda + periodo`.
2. Un arrendatario puede tener varias tiendas, y cada tienda recibe su propio documento.
3. El documento mensual de tienda incluye: `ARRIENDO + AGUA + LUZ + GAS`.
4. Arriendo:
   `valor_arriendo_clp = valor_arriendo_uf (por local) * valor_uf (ingresado por secretaria)`.
5. Factura de agua es general del periodo (no va amarrada a medidor local).
6. Lecturas por medidor permiten correccion manual de consumo (`consumo_informado` opcional).
7. Pagos en cuotas no fijas, con posibilidad de anulacion (sin borrar historial).

---

## 3. Alcance funcional

## 3.1 MVP (primera salida operativa)
1. Crear cierre mensual con valor UF.
2. Cargar datos de factura de agua/luz/gas.
3. Importar excels de lecturas por servicio.
4. Generar cobros por local y consolidar documento por tienda.
5. Ver documentos por tienda y registrar pagos parciales/totales.
6. Recalcular periodo (regenerar) si se corrigen insumos.

## 3.2 Fase 2
1. Notificaciones de errores e incidencias avanzadas.
2. Exportes oficiales y reportes de trazabilidad detallados.
3. Reglas de recargo por mora/interes.
4. Automatizacion de envio de documento por correo.

---

## 4. Modelo de datos propuesto (simplificado)

## 4.1 Cabecera de cierre mensual
Tabla sugerida: `msp_cierre_mensual`
1. `id_cierre_mensual`
2. `periodo` (primer dia del mes)
3. `fecha_uf`
4. `valor_uf`
5. `id_estado_cierre` (`Borrador`, `Calculado`, `Cerrado`, `Anulado`)
6. `observaciones`

Regla: `UNIQUE(periodo)`.

## 4.2 Procesos de servicio por cierre
Ajustar `msp_procesos_cobro_servicio` para depender de cierre:
1. agregar `id_cierre_mensual`
2. mantener `id_tipo_servicio`
3. mantener datos de factura del servicio (`numero_factura`, fechas, archivo)

Regla: `UNIQUE(id_cierre_mensual, id_tipo_servicio)`.

## 4.3 Lecturas por medidor
Mantener `msp_lecturas_medidores`:
1. `id_proceso_cobro`
2. `id_medidor`
3. `lectura_anterior`
4. `lectura_actual`
5. `consumo_informado` (opcional)
6. `periodo`

Regla:
1. `UNIQUE(id_proceso_cobro, id_medidor)`.
2. `lectura_actual >= lectura_anterior`.

## 4.4 Cobros por local
Ajustar `msp_cobros_servicios`:
1. agregar `id_tienda`
2. mantener `id_local`, `id_medidor`, `id_lectura`, `id_proceso_cobro`
3. montos calculados por servicio/local

Regla:
1. el local se asigna a tienda vigente en el periodo.
2. si no hay ocupacion vigente, registrar incidencia y excluir del cierre.

## 4.5 Documento mensual por tienda
Ajustar `msp_documentos_cobro`:
1. `id_tienda` obligatorio
2. `id_arrendatario` snapshot del momento de emision
3. `periodo`
4. `subtotal_arriendo`, `subtotal_servicios`, `monto_total`, `saldo_pendiente`
5. `id_estado_documento`

Regla:
`UNIQUE(id_tienda, periodo)`.

## 4.6 Detalle de documento
Mantener `msp_documentos_cobro_detalle`:
1. item tipo `ARRIENDO/AGUA/LUZ/GAS`
2. descripcion, cantidad, valor_unitario, subtotal
3. opcional agregar `id_local` para trazabilidad por local

## 4.7 Pagos en cuotas no fijas
Ajustar `msp_pagos`:
1. agregar `id_estado_pago` (`Aplicado`, `Anulado`)
2. agregar `fecha_anulacion`, `motivo_anulacion`
3. mantener `id_documento_cobro`, `fecha_pago`, `monto_pagado`, `medio_pago`, `referencia`

Regla:
1. no permitir sobrepago aplicado por documento.
2. mantener historial, no borrar pagos.

---

## 5. Reglas de negocio clave
1. No se genera cierre si falta `valor_uf`.
2. No se genera cierre si falta proceso de un servicio obligatorio definido para el mes.
3. No se genera cobro de un servicio si faltan lecturas validas para ese proceso.
4. El consumo facturable de medidor:
   `consumo_facturable = COALESCE(consumo_informado, lectura_actual - lectura_anterior)`.
5. Cada local aporta arriendo y servicios a su tienda vigente del periodo.
6. El estado del documento depende del saldo:
   1. `Emitido` si saldo = monto_total.
   2. `Pagado Parcial` si 0 < saldo < monto_total.
   3. `Pagado` si saldo = 0.
7. Si se anula un pago, el saldo se recalcula automaticamente.

---

## 6. Flujo de usuario (secretaria)
1. Crear cierre del mes e ingresar `valor UF`.
2. Cargar factura de agua, factura de luz y factura de gas.
3. Subir excel de lecturas de agua.
4. Subir excel de lecturas de luz.
5. Subir excel de lecturas de gas.
6. Revisar incidencias (si hay) y corregir.
7. Ejecutar `Generar cobros`.
8. Revisar documentos por tienda.
9. Registrar pagos parciales o totales.

---

## 7. Plan de implementacion por etapas

## Etapa A - Base de pagos y documentos (rapido valor)
1. Ajustar `msp_documentos_cobro` a `UNIQUE(id_tienda, periodo)`.
2. Ajustar `msp_pagos` con estado aplicado/anulado.
3. Trigger para recalcular saldo y estado de documento.
4. Trigger para bloquear sobrepago.
5. CRUD de pagos (crear, anular, listar por documento).

Criterio de aceptacion:
1. Se pueden registrar N cuotas de montos variables.
2. El saldo y estado se recalculan solos.
3. No existe sobrepago aplicado.

## Etapa B - Cierre mensual y UF
1. Crear tabla `msp_cierre_mensual`.
2. Agregar `id_cierre_mensual` a procesos de servicio.
3. CRUD simple de cierre mensual.

Criterio de aceptacion:
1. Solo un cierre por mes.
2. No se puede generar cobros sin valor UF.

## Etapa C - Carga de insumos (facturas + excels)
1. Pantalla de carga por servicio para datos de factura.
2. Importador excel por servicio con validaciones.
3. Tabla de incidencias por fila con razon de error.

Criterio de aceptacion:
1. El usuario ve errores concretos por fila.
2. Se aceptan cargas parciales sin romper todo el proceso.

## Etapa D - Motor de calculo
1. SP `msp_generar_cierre_mensual`.
2. Calcula arriendo CLP por local usando UF del cierre.
3. Calcula cobros de servicios por local.
4. Agrupa por tienda y crea/actualiza documento mensual.

Criterio de aceptacion:
1. Documento unico por tienda por periodo.
2. Totales coherentes entre detalle y cabecera.

## Etapa E - Operacion y control
1. Wizard de 4 pasos (UF -> Facturas -> Excels -> Generar).
2. Vista de documentos y saldos por tienda/arrendatario.
3. Reporte de trazabilidad por local y servicio.

Criterio de aceptacion:
1. La secretaria puede cerrar el mes sin usar SQL.

---

## 8. Backlog tecnico

## 8.1 SQL
1. Migracion `msp2_a3_patch_01_pagos.sql`:
   estado pago + trigger saldo/estado + trigger sobrepago.
2. Migracion `msp2_a3_patch_02_cierre.sql`:
   `msp_cierre_mensual` + FK a procesos.
3. Migracion `msp2_a3_patch_03_tienda_doc.sql`:
   constraints para documento por tienda.
4. Migracion `msp2_a3_patch_04_motor.sql`:
   SP de generacion y recalculo.

## 8.2 Backend PHP
1. `msp2/cierre_mensual/*` CRUD.
2. `msp2/procesos_servicios/*` carga de factura por servicio.
3. `msp2/lecturas/importar.php` por servicio.
4. `msp2/cobros/generar.php` para ejecutar SP.
5. `msp2/documentos/*` listado y detalle.
6. `msp2/pagos/*` crear/anular/listar.

## 8.3 Frontend
1. Wizard mensual unico.
2. Tabla de incidencias de importacion.
3. Panel de documentos con estado y saldo.
4. Modal rapido de registrar pago.

---

## 9. Pruebas funcionales minimas
1. Arrendatario con 1 tienda y 2 locales.
2. Arrendatario con 3 tiendas y multiples locales.
3. Local sin ocupacion vigente en el mes.
4. Lectura con error (actual < anterior).
5. Pago parcial en 3 cuotas de montos distintos.
6. Anulacion de pago y recalculo de saldo.
7. Intento de sobrepago (debe bloquear).

---

## 10. Operacion mensual objetivo (checklist resumido)
1. Crear cierre del mes y cargar UF.
2. Cargar facturas por servicio.
3. Subir excels de lecturas por servicio.
4. Revisar y corregir incidencias.
5. Generar cobros.
6. Revisar documentos por tienda.
7. Registrar pagos.

---

## 11. Siguiente accion recomendada
Implementar primero `Etapa A` (pagos/documentos) para tener valor inmediato y operacion simple, luego avanzar con `Etapa B` y `Etapa C`.
