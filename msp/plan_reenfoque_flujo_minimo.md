# Plan de Reenfoque MSP (Flujo Minimo Operativo)

## 1. Objetivo real del sistema
Enfocar MSP en 2 tareas operativas:
1. Generar cobros mensuales por tienda (arriendo + servicios).
2. Gestionar cobranza (visualizar cobros tipo excel, registrar cuotas, enviar por correo).

Todo lo demas (mantenedores y catalogos) queda fuera del flujo diario de secretaria.

## 2. Flujo objetivo (2 pantallas)

## 2.1 Pantalla 1: `Generar Cobros`
Campos principales:
1. `fecha_periodo` (mes de cobro).
2. `valor_uf` (ingresado manualmente).
3. Parametros por servicio: AGUA, LUZ, GAS.
4. Carga de Excel por servicio.

Formato de carga simplificado por fila:
1. `cod_local`
2. `consumo_actual`
3. `periodo` en formato `DD-MM`

Acciones:
1. Validar carga.
2. Generar cobros por tienda.
3. Mostrar resumen de generacion.
4. Preguntar `Enviar cobros a todos` (correo principal de arrendatario).

## 2.2 Pantalla 2: `Cobranza`
Funciones:
1. Vista tipo excel de cobros generados.
2. Filtros por `arrendatario`, `tienda`, `periodo`, `estado`.
3. Detalle de documento por tienda.
4. Registro de pagos en cuotas no fijas.
5. Estado y saldo en tiempo real hasta pago total.

## 3. Diagnostico de lo actual (msp2/msp_full_dbo.sql)

## 3.1 Lo que YA sirve y se mantiene
1. Base comercial:
   - `msp_arrendatarios`, `msp_arrendatarios_correos`, `msp_tiendas`, `msp_locales`, `msp_ocupacion_locales`.
2. Medicion:
   - `msp_medidores`.
3. Cierre y calculo:
   - `msp_cierre_mensual`, `msp_procesos_cobro_servicio`,
   - `msp_proceso_cobro_agua`, `msp_proceso_cobro_luz`, `msp_proceso_cobro_gas`,
   - `msp_lecturas_medidores`, `msp_cobros_servicios`.
4. Cobranza:
   - `msp_documentos_cobro`, `msp_documentos_cobro_detalle`, `msp_pagos`.
5. Integridad financiera:
   - trigger `TR_msp_pagos_recalcula_documento` (mantener).

Conclusion:
No conviene rehacer toda la BD. El problema principal es de experiencia de uso y reglas operativas.

## 3.2 Lo que complica hoy
1. Demasiadas pantallas intermedias (`cierre`, `procesos`, `importaciones`, `documentos`, `pagos`).
2. Importador actual exige enfoque por `codigo_medidor` + `lectura_anterior/actual`.
3. Generacion muy rigida: exige AGUA+LUZ+GAS listos para generar.

## 4. Simplificacion recomendada (sin destruir estructura)

## 4.1 Simplificar en UI (prioridad alta)
1. Crear `Operacion Mensual` (pantalla unica) y ocultar `procesos_servicios/index.php` para secretaria.
2. Dejar menu operativo en solo 2 entradas:
   - `Generar Cobros`
   - `Cobranza`
3. Mover todo lo demas a perfil admin.

## 4.2 Simplificar importacion (prioridad alta)
Adaptar importador para aceptar formato simple:
1. Entrada: `cod_local`, `consumo_actual`, `periodo DD-MM`.
2. Resolver internamente:
   - `cod_local -> id_local`.
   - `id_local + servicio -> medidor activo` (ya hay regla de un medidor activo por tipo).
3. Guardado:
   - `fecha_lectura`: construir con `DD-MM` + anio del cierre.
   - `periodo`: primer dia del mes de cierre (mantener regla actual).
   - `consumo_actual`: guardar como `consumo_informado`.

Nota:
Si despues quieres volver a lecturas completas, se puede dejar modo avanzado opcional.

## 4.3 Simplificar generacion (prioridad alta)
Cambiar SP de generacion a 2 modos:
1. `modo_completo`: exige AGUA+LUZ+GAS.
2. `modo_parcial`: genera con servicios listos y deja pendientes para reproceso.

Esto evita bloquear todo el cierre cuando GAS se ingresa en otra fecha.

## 4.4 Simplificar envio de cobros (prioridad media)
1. Agregar accion masiva `Enviar cobros a todos`.
2. Usar correo principal de `msp_arrendatarios_correos` (`es_principal = 1`).
3. Registrar resultado de envio (exito/error) por documento.

Sugerencia minima de trazabilidad:
1. Crear tabla `msp_envios_documentos` (id_documento, correo_destino, fecha_envio, estado, mensaje_error).

## 5. Que mantener vs que esconder

Mantener (core):
1. Cierre mensual.
2. Procesos por servicio.
3. Lecturas/importacion (adaptadas al formato simple).
4. Motor de cobros.
5. Documentos y pagos.

Esconder del flujo diario:
1. Catalogos de estados.
2. Trazabilidad tecnica avanzada.
3. Pantallas de procesos/importaciones separadas.

No eliminar por ahora:
1. Tablas de estado y catalogos.
2. Tablas de importacion/incidencias.
3. Triggers de integridad de pagos y consistencia.

## 6. Plan por etapas (implementable)

## Etapa 1 - Recorte UX (rapido impacto)
1. Dejar solo 2 menu items para secretaria.
2. Nueva pantalla `Generar Cobros` con bloques:
   - Periodo + UF.
   - Parametros por servicio.
   - Carga Excel por servicio.
   - Boton `Generar`.
3. Resumen post-generacion en la misma vista.

Entregable:
Secretaria cierra el mes sin navegar entre modulos tecnicos.

## Etapa 2 - Importador simple por `cod_local`
1. Extender parser de Excel con columnas simples.
2. Resolver local->medidor automaticamente.
3. Guardar incidencias claras por fila.

Entregable:
Planilla corta, facil de preparar y cargar.

## Etapa 3 - Generacion parcial/completa
1. Ajustar `msp_generar_cierre_mensual` para modo parcial.
2. Permitir reproceso sin duplicados (ya existe base para reemplazo).

Entregable:
No se bloquea el mes por un servicio pendiente.

## Etapa 4 - Cobranza tipo excel + cuotas
1. Grid de documentos con filtros por arrendatario/tienda.
2. Detalle y carga de cuotas no fijas.
3. Estado/saldo automatico (aprovechando trigger actual).

Entregable:
Proceso de cobranza claro hasta pagar total.

## Etapa 5 - Envio masivo de cobros
1. Boton `Enviar a todos`.
2. Bitacora de envios.
3. Reintento de fallidos.

Entregable:
Despacho de cobros en un clic con control.

## 7. Riesgos y mitigacion
1. Riesgo: ambiguedad de `consumo_actual` (consumo vs lectura).
   - Mitigacion: definir explicitamente que en modo simple se guarda como `consumo_informado`.
2. Riesgo: local sin medidor activo del servicio.
   - Mitigacion: registrar incidencia y no bloquear toda la carga.
3. Riesgo: envio sin correo principal.
   - Mitigacion: marcar documento como pendiente de envio y mostrar listado de faltantes.

## 8. Criterio de exito
1. Flujo mensual ejecutable en 2 pantallas.
2. Carga de 146 locales sin abrir modulos tecnicos.
3. Cobros agrupados por tienda, visibles en grilla tipo excel.
4. Registro de cuotas hasta saldo cero sin recalculos manuales.
5. Envio masivo de cobros con trazabilidad de resultado.
