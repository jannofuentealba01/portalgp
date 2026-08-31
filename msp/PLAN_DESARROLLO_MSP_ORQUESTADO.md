# Plan De Desarrollo Orquestado MSP

## 1. Contexto
Este plan consolida la informacion ya existente en el modulo `msp/` para convertirla en una hoja de ruta ejecutable. Se asume que:

- ya existe una base de datos inicial en SQL Server;
- ya esta definida la idea general del flujo de negocio mensual;
- el sistema corre sobre PHP 8.3 + SQL Server en un entorno tipo WAMP;
- la meta es llevar el modulo a una salida operativa controlada, priorizando estabilidad del flujo de cobro, contratos y pagos.

## 2. Objetivo General
Construir una version operativa del sistema MSP que permita:

1. administrar maestros base del negocio;
2. ejecutar el flujo mensual de cobro por periodo;
3. generar documentos de cobro por tienda;
4. registrar pagos parciales o totales sin perder trazabilidad;
5. soportar contratos, garantias, cierres y estados con reglas centralizadas en BD;
6. dejar una base tecnica mantenible para evolucion posterior.

## 3. Objetivos Especificos

1. ordenar la base de datos en una estrategia clara de baseline + migraciones;
2. mover la logica de negocio transaccional critica a procedimientos almacenados;
3. reducir la logica monolitica en PHP y separar por servicios/casos de uso;
4. cerrar brechas de seguridad, portabilidad e instalacion;
5. implementar primero el flujo minimo operativo y luego las capacidades complementarias.

## 4. Estado Actual Consolidado

### 4.1 Lo que ya existe
- scripts SQL base y parches en `msp/db/`;
- CRUDs funcionales para catalogos relevantes;
- modulos creados para contratos, cobros, documentos, pagos, cobranza y cierre mensual;
- avance en desacople de `cobros/operacion_mensual.php` hacia servicios PHP;
- procedimientos de negocio de Fase 4 ya presentes en BD;
- trazabilidad parcial de contratos y documentos.

### 4.2 Brechas detectadas
- instalacion de BD mezclada entre baseline y patches;
- logica de negocio aun duplicada entre PHP y SQL en algunos flujos;
- riesgos de seguridad por manejo de credenciales;
- alta complejidad en `cobros/operacion_mensual.php`;
- falta de una ruta unica de despliegue, validacion y salida operativa;
- pruebas manuales dispersas y sin criterio de salida unificado.

## 5. Principios De Implementacion

1. La BD es la fuente de verdad para reglas de negocio criticas.
2. PHP debe orquestar HTTP, permisos, validaciones basicas y UX.
3. Todo cambio de BD debe salir como patch versionado y reversible.
4. No mezclar desarrollo funcional con refactor tecnico sin control de alcance.
5. Cada fase debe cerrar con evidencia de prueba y criterio de aceptacion.
6. Priorizar primero flujo operativo, despues automatizacion y optimizacion.

## 6. Alcance Funcional Priorizado

### 6.1 MVP Operativo
Incluye:

1. cierre mensual con periodo y UF;
2. carga de insumos de servicios;
3. importacion y validacion de lecturas;
4. generacion de cobros por periodo;
5. generacion de documento por tienda;
6. registro de pagos parciales/totales;
7. anulacion de pagos con recalculo de saldo;
8. cierre financiero de contratos con reglas unificadas.

### 6.2 Fase Posterior
Incluye:

1. envio de documentos por correo;
2. reporteria consolidada y KPIs;
3. observabilidad transaccional avanzada;
4. endurecimiento de concurrencia y rendimiento;
5. integraciones contables y automatizaciones operativas.

## 7. Workstreams Del Proyecto

### WS1. Base de datos y migraciones
Objetivo: estabilizar estructura, versionado y reglas de negocio.

Entregables:
- baseline oficial de instalacion;
- set de migraciones incremental;
- README tecnico de despliegue;
- convencion de patches y orden de ejecucion.

### WS2. Flujo mensual de cobro
Objetivo: dejar operativo el proceso desde insumos hasta documento emitido.

Entregables:
- cierre mensual;
- carga por servicio;
- importacion de lecturas;
- generacion de cobros y documentos;
- validaciones de incidencias.

### WS3. Contratos, garantias y cierre
Objetivo: consolidar reglas de contrato, vigencia, garantias y cierre financiero.

Entregables:
- unificacion de `msp_contrato_cerrar`;
- flujo de cierre financiero consistente;
- historial y bitacora alineados;
- prechecks confiables antes de cerrar.

### WS4. Pagos, saldo favor y cobranza
Objetivo: asegurar consistencia financiera y trazabilidad.

Entregables:
- SP orquestador para pagos;
- anulacion segura;
- recalculo de saldo;
- control de sobrepago;
- soporte a saldo a favor.

### WS5. Backend PHP y modularizacion
Objetivo: reducir acoplamiento y dejar el codigo mantenible.

Entregables:
- controladores delgados;
- servicios por caso de uso;
- helpers comunes aislados;
- reduccion del controlador monolitico mensual.

### WS6. Seguridad, despliegue y operacion
Objetivo: bajar riesgo tecnico antes de puesta en marcha.

Entregables:
- externalizacion de credenciales;
- hardening de conexion;
- guia de instalacion por ambiente;
- checklist de salida a operacion.

### WS7. QA funcional y salida operativa
Objetivo: definir criterios objetivos de aprobacion.

Entregables:
- matriz de casos criticos;
- set de pruebas por flujo;
- evidencia de validacion;
- acta de salida MVP.

## 8. Roadmap Propuesto

## Fase 0. Alineacion y cierre de alcance
Duracion sugerida: 2 a 4 dias.

Objetivo:
congelar alcance del MVP y definir linea base tecnica.

Tareas:
1. consolidar flujo oficial del negocio mensual;
2. listar modulos dentro del alcance MVP;
3. confirmar que `msp/` es el perimetro oficial del proyecto;
4. inventariar scripts SQL activos y puntos de entrada PHP;
5. definir ambientes: desarrollo, QA y produccion.

Criterio de salida:
- backlog priorizado aprobado;
- definicion de MVP cerrada;
- ruta de despliegue conocida.

## Fase 1. Saneamiento de base tecnica
Duracion sugerida: 1 semana.

Objetivo:
reducir riesgo tecnico antes de seguir agregando funcionalidad.

Tareas:
1. separar baseline y migraciones en `msp/db/`;
2. corregir instalacion no portable;
3. mover credenciales fuera de codigo fuente;
4. documentar estrategia de versionado DB;
5. revisar objetos SQL criticos ya existentes y marcar los canonicos.

Criterio de salida:
- instalacion reproducible;
- credenciales fuera de repo;
- orden de scripts definido.

## Fase 2. Flujo minimo mensual
Duracion sugerida: 2 semanas.

Objetivo:
dejar operativo el flujo base del cobro mensual.

Tareas:
1. validar `cierre_mensual/` como punto de inicio del periodo;
2. estandarizar procesos por servicio;
3. cerrar importacion de lecturas con staging y validaciones;
4. consolidar generacion de cobros por SP;
5. generar documento por tienda por periodo;
6. asegurar manejo de incidencias funcionales.

Criterio de salida:
- un usuario puede crear el periodo, cargar insumos y generar documentos sin usar SQL manual.

## Fase 3. Pagos y consistencia financiera
Duracion sugerida: 1 a 2 semanas.

Objetivo:
asegurar el circuito completo de emision y recaudacion.

Tareas:
1. unificar logica de registro de pago en un SP orquestador;
2. validar anulacion de pago con recalculo;
3. bloquear sobrepagos;
4. revisar saldo a favor y orden de locks;
5. dejar trazabilidad de cada movimiento financiero.

Criterio de salida:
- el sistema soporta multiples cuotas, anulacion y saldo consistente.

## Fase 4. Contratos, garantias y cierre financiero
Duracion sugerida: 1 a 2 semanas.

Objetivo:
eliminar duplicacion de reglas entre PHP y SQL para contratos.

Tareas:
1. fortalecer `dbo.msp_contrato_cerrar` como unica fuente de verdad;
2. adelgazar `contratos/finalizar_cierre_financiero.php`;
3. estandarizar mensajes de error SQL -> UX;
4. validar historial, bitacora, devolucion de garantia y terminos efectivos.

Criterio de salida:
- un cierre financiero ejecuta todas las validaciones desde un solo contrato transaccional.

## Fase 5. Refactor operativo y observabilidad
Duracion sugerida: 1 semana.

Objetivo:
reducir deuda tecnica de los flujos que ya quedaron operativos.

Tareas:
1. seguir fragmentando `cobros/operacion_mensual.php`;
2. instrumentar bitacora de operaciones financieras;
3. agregar metricas basicas de duracion y errores;
4. revisar triggers y concurrencia;
5. preparar soporte para diagnostico de incidentes.

Criterio de salida:
- codigo mas mantenible;
- incidentes tecnicos trazables.

## Fase 6. Salida a operacion controlada
Duracion sugerida: 1 semana.

Objetivo:
preparar el MVP para uso real.

Tareas:
1. ejecutar QA funcional end-to-end;
2. correr datos de prueba cercanos a produccion;
3. cerrar brechas criticas pendientes;
4. preparar manual operativo resumido;
5. definir protocolo de soporte del primer ciclo mensual.

Criterio de salida:
- checklist de salida aprobado;
- primer cierre mensual ejecutable con supervision controlada.

## 9. Backlog Priorizado

### Prioridad P0
1. externalizar credenciales y endurecer conexion SQL;
2. separar baseline y patches en BD;
3. unificar cierre financiero en SP;
4. asegurar generacion mensual via SPs y no SQL embebido;
5. asegurar pagos/anulaciones sin inconsistencia.

### Prioridad P1
1. modularizar `operacion_mensual.php`;
2. estandarizar importacion de lecturas;
3. mejorar incidencias y mensajes al usuario;
4. agregar trazabilidad operativa;
5. documentar despliegue y rollback.

### Prioridad P2
1. envio de correos;
2. reportes gerenciales;
3. dashboard KPI;
4. optimizaciones de rendimiento;
5. automatizaciones de cierre y lotes.

## 10. Dependencias Criticas

1. definicion final del flujo mensual validada por negocio;
2. acceso controlado a ambiente SQL Server para pruebas;
3. decision de que SPs son canonicos y cuales quedan legacy;
4. disponibilidad de datos reales o anonimizados para QA;
5. criterio comun entre TI y negocio sobre estados y cierres.

## 11. Riesgos Principales Y Mitigacion

### Riesgo 1. Duplicacion de logica entre PHP y SQL
Mitigacion:
- consolidar reglas transaccionales en SPs;
- dejar PHP como capa de orquestacion.

### Riesgo 2. Regresiones por mezcla de refactor y funcionalidad
Mitigacion:
- entregas pequenas por modulo;
- una fase a la vez;
- pruebas por flujo antes de avanzar.

### Riesgo 3. Scripts SQL no reproducibles
Mitigacion:
- baseline oficial;
- migraciones numeradas;
- validacion en BD limpia y BD existente.

### Riesgo 4. Problemas de concurrencia en pagos
Mitigacion:
- orden unico de locks;
- simplificar triggers;
- bitacora tecnica de operaciones.

### Riesgo 5. Salida operativa sin pruebas suficientes
Mitigacion:
- matriz QA minima obligatoria;
- piloto controlado del primer mes.

## 12. Matriz Minima De Pruebas

1. crear cierre mensual con UF valida;
2. impedir generacion sin insumos obligatorios;
3. importar lecturas con errores y revisar incidencias;
4. generar documento unico por tienda y periodo;
5. registrar pago parcial en multiples cuotas;
6. bloquear sobrepago;
7. anular pago y recalcular saldo;
8. cerrar contrato con validaciones correctas;
9. bloquear cierre si existen documentos con saldo;
10. regenerar periodo tras correccion de insumos sin romper trazabilidad.

## 13. Entregables Formales

1. `Plan maestro de desarrollo`;
2. `Mapa de scripts DB` con baseline y patches;
3. `Backlog priorizado` por modulo;
4. `Checklist QA MVP`;
5. `Checklist salida a operacion`;
6. `Manual operativo resumido del ciclo mensual`.

## 14. Propuesta De Secuencia De Entrega

1. Entrega 1: saneamiento tecnico de BD y seguridad.
2. Entrega 2: cierre mensual + importacion + generacion de cobros.
3. Entrega 3: documentos + pagos + anulaciones.
4. Entrega 4: contratos + cierre financiero unificado.
5. Entrega 5: observabilidad, QA final y salida controlada.

## 15. Recomendacion Ejecutiva
No conviene seguir agregando funcionalidad sin antes cerrar tres frentes: seguridad de conexion, orden de migraciones y fuente unica de verdad para reglas transaccionales. Con esos tres puntos resueltos, el MVP puede avanzar con mucho menos riesgo y con entregas funcionales reales en pocas iteraciones.

## 16. Siguiente Paso Inmediato
Ejecutar una sesion corta de validacion funcional y tecnica para cerrar estos puntos:

1. alcance exacto del MVP;
2. lista final de SPs canonicos;
3. orden oficial de fases;
4. responsables por workstream;
5. ambiente donde se validara el primer ciclo completo.
