# Plan de Simplificacion MSP (Foco: Cobro de Arriendo y Servicios)

## 1. Objetivo
Reducir la complejidad para la secretaria y centrar MSP en lo esencial:
- Ingresar pocos datos mensuales.
- Generar cobros automaticamente para todos los locales/tiendas.
- Registrar pagos sin friccion.

Contexto operativo clave:
- Hoy el dolor es generar cobros manuales para 146 locales.
- El valor real del sistema no es mantener muchos catalogos en el dia a dia, sino cerrar el mes rapido y bien.

## 2. Diagnostico del flujo actual
Hoy la operacion esta distribuida en varios modulos (cierre, procesos, importaciones, documentos, pagos, trazabilidad, catalogos), lo que obliga a navegar mucho.

Problemas de usabilidad:
1. Demasiados puntos de entrada para una tarea mensual repetitiva.
2. El usuario operativo debe decidir "a donde ir" en vez de seguir un flujo unico.
3. Modulos administrativos y modulos operativos estan mezclados en el menu principal.

## 3. Que deberiamos quitar del flujo diario
No significa borrar datos, sino sacarlo del recorrido mensual de la secretaria.

Sacar del flujo mensual (dejar en "Administracion"):
1. Gestionar Arrendatarios (mantener, pero no como paso mensual).
2. Gestionar Tiendas.
3. Catalogos (comunas, rubros, estados, catalogos cobro).
4. Trazabilidad avanzada (dejar para supervisor/auditoria).

Mantener en flujo mensual:
1. Operacion Mensual (UF + facturas + excels + generar).
2. Documentos y Saldos.
3. Pagos.

## 4. Opciones de simplificacion

## Opcion A: 2 pantallas operativas (Recomendada)
### Pantalla 1: "Operacion Mensual"
Incluye todo en un solo lugar:
1. Seleccionar/crear periodo y valor UF.
2. Cargar datos de factura de AGUA/LUZ/GAS.
3. Subir 3 excels (agua/luz/gas).
4. Ver incidencias por fila.
5. Boton unico "Generar cobros".

### Pantalla 2: "Cobranza"
1. Ver documentos por tienda/arrendatario.
2. Registrar pagos y anulaciones.
3. Ver saldo pendiente.

Ventajas:
1. Minima capacitacion.
2. Navegacion casi lineal.
3. Menor riesgo de error operativo.

Riesgo:
1. Requiere fusionar vistas actuales en una UX mas compacta.

## Opcion B: 3 pantallas operativas
1. Operacion Mensual (sin pagos).
2. Documentos.
3. Pagos.

Ventajas:
1. Menor cambio que la Opcion A.
2. Mas simple que el estado actual.

Riesgo:
1. Todavia hay cambio de contexto entre documentos y pagos.

## Opcion C: Mantener modulos actuales pero con "ruta guiada"
1. Dejar modulos existentes.
2. Agregar una portada guiada que obligue orden (paso 1, 2, 3, 4).

Ventajas:
1. Poco impacto tecnico.

Riesgo:
1. Sigue siendo complejo internamente.
2. Menos ganancia de usabilidad a largo plazo.

## 5. Recomendacion
Adoptar Opcion A.

Razon:
- El problema principal es operativo (tiempo y errores en cierre mensual), no de falta de funcionalidades.
- Para 146 locales, el sistema debe optimizar clics y decisiones, no ampliar menus.

## 6. Menu propuesto (rol Secretaria)
Mostrar solo:
1. Operacion Mensual.
2. Cobranza (Documentos + Pagos).

Mover a "Administracion" (rol supervisor/admin):
1. Arrendatarios.
2. Tiendas.
3. Locales y medidores.
4. Catalogos.
5. Reportes de trazabilidad.

## 7. Automatizaciones recomendadas (sin cambiar negocio)
1. Auto-crear procesos AGUA/LUZ/GAS al crear cierre mensual.
2. Recordar ultimo set de parametros por servicio y prellenar valores sugeridos.
3. Validar antes de generar: si falta algo, mostrar checklist claro en la misma pantalla.
4. Generar documentos por tienda automaticamente y abrir resumen final en la misma vista.

## 8. Plan de ejecucion sugerido (sin codigo por ahora)
1. Definir flujo final UX (wireframe de Pantalla 1 y Pantalla 2).
2. Definir que modulos pasan a "Administracion".
3. Definir checklist obligatorio para generar cobros.
4. Definir mensajes de error simples para secretaria (no tecnicos).
5. Probar con un cierre real (un periodo) y medir:
   - tiempo total,
   - cantidad de clics,
   - errores de carga.

## 9. Criterio de exito de simplificacion
El sistema se considera simplificado cuando:
1. La secretaria puede cerrar el mes en un flujo unico sin preguntar "donde voy ahora".
2. La generacion de cobros para todas las tiendas se ejecuta en minutos.
3. Los pagos se registran sin navegar por modulos tecnicos.
