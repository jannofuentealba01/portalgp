# FTE — Diagnóstico de rendimiento GeoVictoria

Fecha: 11 de septiembre de 2026  
Alcance: inspección del código instalado y pruebas controladas de solo lectura.  
Estado productivo: los rangos de 1 a 7 días ya utilizan lotes validados; los
rangos mayores conservan temporalmente el flujo individual anterior.

## Conclusión ejecutiva

1. PortalGP consultaba un trabajador por solicitud. Después de las pruebas, el
   flujo productivo de 1 a 7 días divide la nómina en lotes de hasta 195 y usa
   peticiones individuales solamente para identidades omitidas. Los rangos
   mayores a siete días continúan temporalmente en modo individual.
2. La documentación oficial disponible define `UserIds` como `List<string>` y
   señala que varios identificadores se separan por comas. Las pruebas con 5,
   15 y 70 trabajadores, sin espacios entre comas, respondieron HTTP 200 y
   devolvieron exactamente todas las identidades solicitadas.
3. Un lote de 71 respondió HTTP 200 y devolvió 70 identidades. La identidad
   faltante también devolvió HTTP 400 `NonExistenceOfData` al consultarse sola;
   por tanto, no fue truncamiento por tamaño, sino una persona presente en Buk
   que no existe con ese identificador en GeoVictoria. Esto demuestra que un
   HTTP 200 de lote no garantiza que todos los trabajadores hayan sido
   conciliados.
4. La espera de red/proveedor domina el tiempo. En cinco consultas diarias la
   mediana HTTP fue 0,994 s; en cinco consultas mensuales fue 1,270 s. El tiempo
   local de decodificación y consolidación fue de milisegundos.
5. La pausa de 0,35 s es relevante, pero no explica por sí sola la lentitud. Con
   236 personas suma 82,25 s; las consultas HTTP secuenciales sumarían alrededor
   de 235 a 300 s usando las medianas observadas.
6. La solicitud de los 226 trabajadores elegibles fue rechazada con HTTP 400
   `OutOfLimitException`: GeoVictoria informó que más de 200 usuarios excede el
   límite del endpoint. Una matriz posterior confirmó los siete tamaños de lote
   calculados para 1, 2, 7, 14, 28, 30 y 31 días; incluso 50 personas por 30
   días, exactamente 1.500 registros solicitados, fue aceptado. Siguen sin estar
   documentados los límites de tasa o concurrencia.

## Recorrido real instalado

### Vista diaria / resumen

1. `fte_dashboard.php` pide primero los CECO a
   `fte_api.php?action=cost_centers`.
2. Al actualizar, pide `fte_api.php?action=dashboard` con rango, jornada y CECO.
3. `fte_build_payload()` carga la nómina activa desde Buk, filtra los CECO y
   llama a `fte_fetch_attendance()`.
4. `fte_fetch_attendance()` recorre cada trabajador, espera 0,35 s desde el
   segundo y llama a `AttendanceBook` con un solo identificador.
5. PHP construye todos los registros persona/día, los serializa como JSON y el
   navegador muestra como máximo 1.000 filas en la tabla.
6. El navegador guarda el payload completo en `sessionStorage` para reutilizarlo
   en Gráfico de asistencia y Ausencias.

### Vista mensual

1. `fte_mensual.php` pide `fte_api.php?action=monthly`.
2. `fte_build_monthly_payload()` carga la nómina histórica y las ausencias de
   Buk.
3. Solo cuando se marca `include_attendance`, vuelve a ejecutar
   `fte_fetch_attendance()` para cada trabajador incluido.
4. El endpoint mensual eleva su tiempo PHP a 300 s; el dashboard diario no lo
   hace y conserva el límite general instalado de 120 s.

## Estado del código productivo

- `fte_fetch_attendance()` calcula los días inclusivos del rango.
- Entre 1 y 7 días usa `min(195, floor(1500 / días))`; con la configuración
  actual corresponde a 195 personas por lote.
- Comprueba todas las identidades devueltas y no publica una respuesta que
  contenga personas ajenas a lo solicitado.
- Reconsulta solamente las identidades omitidas. Una inexistencia confirmada se
  conserva durante cinco minutos mediante un hash en la sesión para no repetir
  el fallback.
- Ante un rechazo por tamaño divide el lote; ante timeout, 429, autenticación o
  inconsistencia de identidad detiene la publicación y entrega un error seguro.
- Desde 8 días se conserva por ahora el bucle individual anterior, de manera que
  esta primera entrega no altere aún el cálculo mensual.

El cuerpo del HTTP 400 histórico no está conservado. `fte_http_json()` sí recibe
el cuerpo, pero `fte_geovictoria_post()` construye la excepción solamente con el
código HTTP y el error de transporte de cURL; después el bucle descarta esa
excepción. El intento de 2026-09-11, usando el formato oficial, no reprodujo el
400.

## Prueba controlada principal

Identificador de ejecución: `GV-20260911-135202-0433BD`  
Día: 4 de agosto de 2026  
Mes: agosto de 2026  
Muestra: cinco trabajadores anonimizados  
Nómina histórica cargada desde Buk: 452 registros  
Elegibles en el día de muestra: 226 trabajadores

| Medición | Observaciones | Mínimo | Mediana | Máximo | Tamaño mediano |
|---|---:|---:|---:|---:|---:|
| Login GeoVictoria | 1 | — | 0,785 s | — | 395 bytes |
| Un trabajador / un día | 5 | 0,904 s | 0,994 s | 1,078 s | 3.569 bytes |
| Un trabajador / un mes | 5 | 1,233 s | 1,270 s | 8,757 s | 69.985 bytes |
| Dos trabajadores / un día | 1 | — | 0,907 s | — | 6.606 bytes |

Todas las solicitudes respondieron HTTP 200. No hubo 401, 403, 429, timeout,
reintento ni renovación de token. La consulta mensual tuvo una observación
atípica de 8,757 s, por lo que cinco casos no bastan para caracterizar la
variabilidad del proveedor.

### Separación de tiempos HTTP

| Rango individual | DNS mediano | Conexión acumulada | Espera hasta primer byte | Descarga tras primer byte | JSON + consolidación local |
|---|---:|---:|---:|---:|---:|
| Día | 0,004 s | 0,157 s | 0,994 s | < 0,001 s | < 0,001 s |
| Mes | 0,004 s | 0,142 s | 1,134 s | 0,134 s | 0,003 s |

Los valores DNS, conexión y primer byte son métricas acumulativas de cURL y no
se sumaron como fases independientes. La sonda también calculó fases derivadas:
la espera posterior a la conexión fue aproximadamente 0,831 s para el día y
0,992 s para el mes. cURL reportó cero para `appconnect_time` en este entorno,
por lo que el tiempo TLS no quedó disponible como medición separada; esto no
significa que HTTPS no lo haya utilizado.

En toda la prueba principal:

- Carga y normalización fría de Buk por CLI: 25,698 s.
- HTTP total, incluido login y la consulta múltiple: 20,544 s.
- Pausas controladas: 3,562 s.
- Decodificación y consolidación local: 0,016 s.
- Ejecución completa: 49,857 s.

La prueba se ejecutó sin sesión PHP, por lo que la carga de Buk no usó su caché
de sesión de 300 s.

## Validación adicional de consulta múltiple

La segunda ejecución `GV-20260911-135530-68A0FD` volvió a consultar dos
trabajadores y confirmó:

- HTTP 200.
- Dos identidades solicitadas y dos identidades devueltas.
- Sin identidades adicionales.
- Igual cantidad de intervalos y marcaciones que en las consultas individuales.
- Igualdad de todos los campos normalizados que consume actualmente
  `fte_merge_attendance_payload()`.

### Prueba progresiva de cantidad

Se ejecutaron después lotes crecientes para el mismo día histórico:

| Solicitados | HTTP | Devueltos | Tiempo | Resultado |
|---:|---:|---:|---:|---|
| 5 | 200 | 5 | 1,19 s | Completo |
| 15 | 200 | 15 | 1,63 s | Completo |
| 70 | 200 | 70 | 1,72 s | Completo |
| 71 | 200 | 70 | 1,66 s | Una identidad no existe en GeoVictoria |
| 226 | 400 | 0 | 0,84 s | Rechazado por superar 200 usuarios |

El lote de 70 incluyó 68 intervalos planificados y 220 marcaciones. Que existan
menos intervalos que usuarios no implica pérdida: dos identidades fueron
devueltas correctamente, pero no tenían intervalo para ese día.

La identidad faltante del lote de 71 se probó también sola y GeoVictoria
respondió HTTP 400, código `0007`, categoría `NonExistenceOfData`. La solicitud
de 226 devolvió código `0123`, categoría `OutOfLimitException`, indicando que el
total solicitado era superior a 200.

Conclusión precisa: la consulta múltiple funciona y fue confirmada con hasta 70
identidades válidas en un día. Una sola solicitud no puede traer la nómina
completa actual de 226 personas; será necesario dividirla en lotes y verificar
la conciliación de cada identidad. Los lotes mensuales requieren además respetar
el límite de registros comprobado en la prueba siguiente.

### Prueba solicitada de dos lotes: 195 + 31

La nómina elegible de 226 personas se dividió en un lote base de 195 y un
segundo lote variable de 31.

| Rango | Lote | Solicitados | HTTP | Devueltos | Tiempo | Resultado |
|---|---:|---:|---:|---:|---:|---|
| Un día | 1 | 195 | 200 | 190 | 1,95 s | Aceptado; 5 no conciliados |
| Un día | 2 | 31 | 200 | 31 | 1,29 s | Completo |
| Un mes | 1 | 195 | 400 | 0 | 0,47 s | Supera 1.500 registros |

Los cinco identificadores faltantes del primer lote diario fueron consultados
individualmente. Los cinco devolvieron HTTP 400 `NonExistenceOfData`; no hubo
ningún trabajador válido omitido por la consulta múltiple. Por ello, **195 + 31
funciona para el día probado** y recupera todas las identidades existentes en
GeoVictoria.

El lote mensual de 195 fue rechazado con código `0008`, categoría
`OutOfLimitException`: la cantidad de registros solicitados era superior a
1.500 y GeoVictoria pidió reducir personas o período. La segunda petición
mensual no se ejecutó después del rechazo controlado.

Conclusión: 195 puede adoptarse como base comprobada para consultas de un día,
pero no como número universal. Para rangos mayores, un límite inicial prudente
se puede calcular como `min(195, floor(1500 / días del rango))`; para agosto de
31 días serían 48 personas por lote. La matriz siguiente verificó en la práctica
todas las combinaciones propuestas para un mes de 31 días.

### Matriz completa de períodos dentro de agosto

Se utilizó la misma nómina elegible de 226 personas y se consultaron ventanas
consecutivas desde el 1 de agosto de 2026. Cada respuesta fue validada por las
identidades solicitadas, no solamente por su estado HTTP.

| Período | Distribución | Registros máximos por petición | HTTP | Personas válidas recuperadas | HTTP total |
|---:|---|---:|---|---:|---:|
| 1 día | 195 + 31 | 195 | 200 en 2/2 | 221 | 2,60 s |
| 2 días | 195 + 31 | 390 | 200 en 2/2 | 221 | 3,20 s |
| 7 días | 195 + 31 | 1.365 | 200 en 2/2 | 221 | 7,94 s |
| 14 días | 107 + 107 + 12 | 1.498 | 200 en 3/3 | 221 | 14,85 s |
| 28 días | 53 + 53 + 53 + 53 + 14 | 1.484 | 200 en 5/5 | 221 | 36,21 s |
| 30 días | 50 + 50 + 50 + 50 + 26 | 1.500 | 200 en 5/5 | 221 | 32,93 s |
| 31 días | 48 + 48 + 48 + 48 + 34 | 1.488 | 200 en 5/5 | 221 | 39,30 s |

Las 24 peticiones de asistencia fueron aceptadas. En todos los períodos faltaron
los mismos cinco identificadores. Su comprobación individual devolvió HTTP 400
`NonExistenceOfData` en los cinco casos: pertenecen a Buk, pero no existen con
ese identificador en GeoVictoria. No hubo personas válidas omitidas, identidades
inesperadas, 429 ni respuestas sin resolver.

La ejecución completa, incluida la carga fría de Buk, autenticación, pausas y
cinco comprobaciones individuales, tardó 176,59 s. La prueba confirma para esta
nómina y este mes la fórmula `min(195, floor(1500 / días))`; no demuestra todavía
una tasa contractual ni autoriza concurrencia.

## Estimaciones para la nómina completa

Supuesto: solicitudes individuales secuenciales, una petición exitosa por
trabajador, mediana de la muestra y pausa actual; excluye Buk, construcción del
informe, transferencia PortalGP/navegador y reintentos.

| Escenario | HTTP estimado | Pausa | Total estimado |
|---|---:|---:|---:|
| 226 personas, un día | 224,66 s | 78,75 s | 303,41 s (5 min 03 s) |
| 226 personas, un mes | 287,04 s | 78,75 s | 365,79 s (6 min 06 s) |
| 236 personas, un día | 234,60 s | 82,25 s | 316,85 s (5 min 17 s) |
| 236 personas, un mes | 299,74 s | 82,25 s | 381,99 s (6 min 22 s) |

Estas cifras son estimaciones, no promesas. El caso mensual de 8,757 s muestra
que una ejecución real puede desviarse bastante y los reintentos podrían sumar
hasta 75 s adicionales por intento HTTP.

## Procesamiento, serialización y navegador

Se ejecutó además una sonda local sintética con la forma del payload actual:
226 personas × 31 días = 7.006 registros persona/día, de los que la interfaz
renderiza como máximo 1.000.

| Operación local | Mediana de 5 repeticiones |
|---|---:|
| `json_encode` PHP de 4,60 MB | 22,1 ms |
| `JSON.parse` en Chrome | 3,9 ms |
| Escritura de 4,60 MB en `sessionStorage` | 24,6 ms |
| Filtrar 7.006 y limitar a 1.000 | 0,1 ms |
| Crear 1.000 filas / 8.000 celdas | 6,5 ms |
| Forzar layout del navegador | 33,3 ms |

La medición confirma que, en este computador y con datos sintéticos, el
navegador no explica los minutos de espera. Aun así, enviar y guardar el payload
completo es innecesario y 4,60 MB se acerca a cuotas de almacenamiento que pueden
variar por navegador. La carga real puede ser mayor porque también incluye
nómina, trabajos históricos, CECO y resúmenes.

## Autenticación, cachés y sesión

- El token se guarda en la sesión PHP durante 1.200 s. La documentación oficial
  consultada afirma una vigencia de cinco horas; el TTL local es una decisión
  conservadora, no la expiración del proveedor.
- Ante 401 o 403, PortalGP elimina el token, vuelve a autenticar y repite una vez.
  En las pruebas no ocurrió ninguna renovación, por lo que no contribuyó al
  tiempo observado.
- La nómina Buk usa una caché de sesión de 300 s, pero solo conserva una entrada.
  Alternar entre nómina activa y nómina histórica reemplaza la entrada anterior
  y puede provocar nuevas cargas.
- No existe caché de asistencia. Actualizar nuevamente repite las consultas a
  GeoVictoria.
- `fte_api.php` abre la sesión antes de consultar y no la libera. El manejador
  instalado es `files`. Una prueba local mantuvo una sesión durante 4 s y una
  segunda petición con el mismo ID esperó 3,745 s. Por tanto, otra solicitud del
  mismo usuario puede quedar bloqueada mientras FTE termina.
- La pausa actual solo se aplica una vez entre trabajadores. No se aplica entre
  candidatos de un mismo trabajador ni constituye un limitador compartido entre
  usuarios simultáneos.
- La variable de entorno con valor textual `"0"` no desactiva la pausa: el uso
  de `getenv(...) ?: 0.35` vuelve a seleccionar 0,35. Esto fue reproducido en el
  PHP instalado.

## Riesgo de tiempos máximos

- PHP general instalado: 120 s.
- Acción mensual: eleva explícitamente a 300 s.
- Dashboard diario: no eleva el límite.
- Login GeoVictoria: 30 s por petición.
- `AttendanceBook`: 75 s por intento.
- Conexión HTTP: 10 s.
- Rango funcional máximo: 93 días.

Las estimaciones de 303 a 382 s superan los límites de 120 y 300 s antes de
sumar Buk. Mantener la consulta completa dentro de una petición web es, por
tanto, estructuralmente frágil aunque GeoVictoria responda correctamente.

### Límite aplicado a rangos cortos

La primera entrega ya aplica un límite de 45 segundos a consultas de 1 a 7 días,
tanto en el servidor como en la espera visible del navegador. El límite mensual
de 90 segundos sigue pendiente hasta implementar y medir ese rango:

| Rango | Tiempo completo esperado inicialmente | Límite provisional |
|---|---:|---:|
| 1 día | 13,49 s en la prueba productiva del motor | 45 s aplicado |
| 1 semana | 15,34 s en la prueba productiva del motor | 45 s aplicado |
| 1 mes | pendiente de prueba real | 90 s |

Los límites deben conservar holgura, pero no permitir una espera indefinida. Si
se superan, la vista debe terminar de forma controlada y mostrar un mensaje
específico según la causa observada —timeout del proveedor, HTTP 429, error de
autenticación, lote incompleto, falta de conciliación u otra falla— junto con una
referencia segura que permita diagnosticar y corregir el problema.

Los 13,49 y 15,34 s corresponden al tramo GeoVictoria completo del motor con 226
personas, no incluyen una carga fría de Buk ni el renderizado final del
navegador. Por eso el límite conserva holgura y deberá volver a medirse cuando
Buk se lea desde SQL. Los rangos largos, como seis meses, deben evaluarse como
trabajos en segundo plano y no heredar automáticamente el límite mensual.

## Límites del proveedor

La documentación pública revisada especifica el formato múltiple, pero no
publica el máximo. La respuesta operativa sí confirmó que una solicitud con más
de 200 usuarios es rechazada. Todavía no se conocen:

- si exactamente 200 usuarios son aceptados de manera estable;
- máximo de días o tamaño de respuesta;
- solicitudes permitidas por minuto;
- concurrencia admitida por cuenta, token o IP;
- política de `429` y `Retry-After`;
- límites particulares de la cuenta de Grupo Patagual.

Preguntas necesarias para GeoVictoria:

1. ¿El máximo contractual es exactamente 200 `UserIds` y es estable para todos
   los rangos?
2. ¿Cuál es el rango máximo recomendado y admitido por `AttendanceBook`?
3. ¿Qué límites de tasa y concurrencia aplican por empresa, credencial, token e
   IP?
4. ¿Debe respetarse `Retry-After` y qué política de reintento recomiendan?
5. ¿Puede una respuesta HTTP 200 truncar usuarios o intervalos por tamaño?
6. ¿Recomiendan reutilizar el token durante su vigencia o autenticar cada
   petición para esta versión del endpoint?

## Estado de implementación y trabajo siguiente

### Prioridad 1 — Observabilidad segura

- Incorporar métricas anónimas de estado, duración, bytes, intentos, identidad
  conciliada y `run_id`.
- Conservar el código HTTP y un resumen redacted del error, sin tokens, RUT,
  nombres ni payloads laborales.
- Distinguir éxito HTTP de identidad conciliada y registrar explícitamente 400,
  401/403, 429 y timeout.

Beneficio: permite decidir con evidencia y diagnosticar trabajadores puntuales.
Riesgo: bajo si los registros mantienen la anonimización probada.

### Prioridad 2 — Lotes conservadores con verificación

- **Implementado para rangos de 1 a 7 días.** La extensión al mes continúa
  pendiente de una segunda entrega.
- Confirmar con GeoVictoria los límites de tasa, concurrencia y rango. La
  cantidad operativa observada rechaza más de 200 usuarios y más de 1.500
  registros usuario/día por solicitud.
- Los lotes dinámicos ya usan coma sin espacios y la base comprobada
  `min(195, floor(1500 / días inclusivos del rango))`.
- Exigir que regresen todas y solo las identidades solicitadas.
- Comparar y conciliar los datos por identificador; ante 400 o faltantes,
  dividir el lote y terminar con fallback individual.
- Hacer que `geovictoria_attendance_batch_size` sea un parámetro realmente usado.

Una petición de 70 personas tardó 1,72 s. Consultarlas individualmente habría
requerido aproximadamente 93,7 s usando la mediana diaria y la pausa observadas.
La mejora potencial es muy grande, pero corresponde a una sola observación de
un día y debe validarse para rangos mensuales antes de cambiar producción.

### Prioridad 3 — Sincronización y caché de asistencia

- Mover la obtención masiva fuera de la petición web a un proceso programado,
  recuperable y con checkpoints.
- Guardar asistencia normalizada por trabajador/día y mostrar fecha/hora de la
  última sincronización.
- Tratar meses cerrados como estables y volver a consultar una ventana reciente
  para capturar correcciones tardías.
- Permitir reintentar únicamente trabajadores o días fallidos.

Beneficio: la pantalla respondería desde datos locales y dejaría de depender de
centenares de llamadas durante cada clic. Es la mejora de experiencia más
importante aunque el proveedor tarde lo mismo durante la sincronización.

### Prioridad 4 — Sesiones, limitación y reintentos

- Liberar el bloqueo de sesión antes del trabajo largo; almacenar token y cachés
  de forma compartida y con escritura breve/controlada.
- Reemplazar la pausa local por un limitador compartido cuando GeoVictoria
  confirme la tasa permitida.
- Respetar `Retry-After`, usar backoff con jitter y evitar tormentas de reintento.
- Mantener concurrencia desactivada hasta recibir autorización del proveedor.

### Prioridad 5 — Buk y respuesta al navegador

- Separar las cachés de nómina activa e histórica.
- Evitar enviar nuevamente campos de nómina que la vista no utiliza.
- Paginar o virtualizar `person_days` y no guardar el payload completo en
  `sessionStorage`.
- Si temporalmente se mantiene el flujo síncrono, alinear los tiempos máximos;
  esto solo evita cortes, no resuelve el diseño de fondo.

## Artefactos y reproducibilidad

- Herramienta CLI: `rrhh/fte/tools/geovictoria_performance_diagnostic.php`.
- Prueba del flujo productivo corto:
  `rrhh/fte/tools/fte_short_range_smoke.php`.
- Sonda sintética de navegador: `rrhh/fte/tools/fte_frontend_performance_probe.js`.
- Resultado principal anonimizado y local:
  `storage/fte_diagnostics/geovictoria_performance_20260911_155202.json`.
- Validación adicional anonimizada y local:
  `storage/fte_diagnostics/geovictoria_performance_20260911_155530.json`.
- Lotes de 5, 15 y 71:
  `storage/fte_diagnostics/geovictoria_performance_20260911_161157.json`.
- Lotes de 70 y 226:
  `storage/fte_diagnostics/geovictoria_performance_20260911_161314.json`.
- Comprobación individual de la identidad no conciliada:
  `storage/fte_diagnostics/geovictoria_performance_20260911_161444.json`.
- Prueba diaria y mensual de dos lotes 195 + 31:
  `storage/fte_diagnostics/geovictoria_performance_20260911_163031.json`.
- Matriz de 1, 2, 7, 14, 28, 30 y 31 días:
  `storage/fte_diagnostics/geovictoria_performance_20260911_164701.json`.

Los JSON están dentro de `storage/`, ruta ignorada por Git. Se verificó que no
contienen las credenciales configuradas. Las herramientas no publican rutas web
de diagnóstico y el script de GeoVictoria se niega a ejecutarse fuera de CLI.

## Fuentes

- Código instalado de PortalGP FTE al 11 de septiembre de 2026.
- `FTE_GEOVICTORIA_VALIDACION_2026-09-08.md`.
- Documentación oficial GeoVictoria, `API-GV3.pdf`, páginas 7 y 11:
  https://wiki.geovictoria.com/wp-content/uploads/2021/07/API-GV3.pdf
