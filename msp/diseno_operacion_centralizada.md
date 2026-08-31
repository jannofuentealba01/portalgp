# Diseno Funcional - Centro de Cobranza Mensual (MSP)

## 1. Objetivo
Reducir el flujo mensual a una sola pantalla operativa para secretaria:
1. Ingresar periodo + UF.
2. Cargar datos de AGUA, LUZ y GAS.
3. Subir excels por servicio.
4. Generar cobros por tienda.
5. Pasar directo a cobranza (documentos + pagos).

La base de datos no se simplifica borrando tablas. Se simplifica ocultando complejidad y centralizando acciones.

## 2. Pantalla unica propuesta: `Centro de Cobranza Mensual`

## 2.1 Bloque A - Periodo
Campos:
1. `periodo` (mes/anio).
2. `fecha_uf`.
3. `valor_uf`.
4. `observaciones` (opcional).

Acciones:
1. `Guardar periodo` (crea o reutiliza cierre mensual).
2. `Duplicar desde periodo anterior` (opcional, para precarga de parametros).

Reglas:
1. Un solo cierre por periodo.
2. `valor_uf > 0`.

## 2.2 Bloque B - Servicios (3 tarjetas en la misma vista)
Tarjetas: `AGUA`, `LUZ`, `GAS`.

Cada tarjeta incluye:
1. Datos factura:
   - `numero_factura`
   - `fecha_emision`
   - `fecha_vencimiento`
2. Fecha de toma:
   - `fecha_lectura_servicio` (clave para permitir GAS en otra fecha).
3. Parametros de calculo:
   - AGUA: campos actuales de `msp_proceso_cobro_agua`
   - LUZ: `valor_kwh`
   - GAS: `factor`, `valor_litro`
4. Excel:
   - selector de archivo
   - boton `Cargar Excel`
   - resultado: filas validas, filas error, ultimo archivo
5. Estado visual:
   - `Pendiente`
   - `Con errores`
   - `Listo para generar`

## 2.3 Bloque C - Incidencias consolidadas
Tabla unica de incidencias del periodo:
1. Servicio
2. Archivo
3. Fila
4. Medidor
5. Error
6. Valor observado

Acciones:
1. Filtro por servicio.
2. Descargar csv de errores (opcional).

## 2.4 Bloque D - Resumen previo a generar
Indicadores:
1. Total locales con lectura valida por servicio.
2. Locales sin ocupacion vigente.
3. Tiendas a documentar.
4. Proyeccion de monto total periodo.

Checklist de generacion:
1. UF valida.
2. Al menos un servicio listo.
3. Sin errores bloqueantes.

## 2.5 Bloque E - Generacion
Botones:
1. `Generar cobros` (primera vez).
2. `Regenerar cobros` (si ya existe calculo del periodo).

Comportamiento:
1. Mostrar resumen final en la misma pantalla:
   - documentos generados
   - cobros generados
   - lecturas sin tienda
2. Accesos directos:
   - `Ver documentos`
   - `Registrar pagos`

## 3. Que se mantiene de la BD actual
Tablas que quedan como nucleo:
1. `msp_cierre_mensual`
2. `msp_procesos_cobro_servicio`
3. `msp_proceso_cobro_agua`, `msp_proceso_cobro_luz`, `msp_proceso_cobro_gas`
4. `msp_lecturas_medidores`
5. `msp_cobros_servicios`
6. `msp_documentos_cobro`, `msp_documentos_cobro_detalle`
7. `msp_pagos`

Tablas que siguen existiendo pero fuera del flujo diario:
1. catalogos de estados/tipos
2. importaciones e incidencias (visibles solo en bloque consolidado)
3. mantenedores de arrendatarios/tiendas/locales/medidores

## 4. Cambios recomendados para simplificar de verdad

## 4.1 Sin romper compatibilidad (recomendado primero)
1. Crear una nueva vista/controlador central que use tablas actuales.
2. Hacer `upsert` automatico de cierre y procesos por servicio.
3. No mandar a `procesos_servicios/index.php` en el flujo de secretaria.
4. Dejar esa pantalla como modo admin/soporte.

## 4.2 Ajuste clave de negocio (importante)
Cambiar regla de generacion para soportar fechas distintas por servicio:
1. Hoy el SP exige AGUA+LUZ+GAS obligatorios para generar.
2. Propuesta:
   - modo `completo`: exige todos los servicios requeridos.
   - modo `parcial`: genera con servicios listos y deja otros pendientes.

Esto permite operar si GAS se carga despues, sin bloquear todo el mes.

## 5. Validaciones operativas simples (mensajes para secretaria)
1. `Falta valor UF del periodo.`
2. `AGUA sin factura o sin lecturas validas.`
3. `LUZ sin factura o sin lecturas validas.`
4. `GAS pendiente de carga (puedes generar parcial).`
5. `No se puede regenerar: existen pagos asociados al periodo.`

## 6. Flujo final esperado (secretaria)
1. Entrar a `Centro de Cobranza Mensual`.
2. Seleccionar periodo y guardar UF.
3. Completar AGUA, LUZ y GAS (mismo formulario, sin navegar).
4. Cargar excels y revisar incidencias en la misma pantalla.
5. Generar o regenerar cobros.
6. Ir a `Cobranza` para pagos.

## 7. Fases de implementacion
Fase 1 (UI):
1. Construir pantalla central con bloques A-E.
2. Reusar endpoints actuales por detras.

Fase 2 (Backend):
1. Endpoint unico para guardar periodo y servicios.
2. Endpoint unico para subir excel por servicio.

Fase 3 (Reglas de generacion):
1. Extender SP para modo parcial/completo.
2. Ajustar checklist de wizard central.

Fase 4 (Menu y roles):
1. Secretaria: solo `Operacion Mensual` y `Cobranza`.
2. Admin: modulos tecnicos y catalogos.

## 8. Criterios de exito
1. Cierre mensual operable sin salir de una pantalla.
2. Menos de 10 minutos para cargar insumos de un periodo normal.
3. Cero dudas de navegacion ("a que modulo voy ahora").
4. Cobros de 146 locales generados con trazabilidad y sin duplicados.
