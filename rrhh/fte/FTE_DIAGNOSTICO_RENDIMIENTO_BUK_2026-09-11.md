# FTE — Diagnóstico de rendimiento Buk

Fecha: 11 de septiembre de 2026  
Período de ausencias consultado: agosto de 2026  
Alcance: consultas reales de solo lectura y resultados anonimizados.  
Estado productivo: no se cambió el flujo habitual de FTE.

## Resultado general

- 8 peticiones externas, todas HTTP 200.
- 0 reintentos, 0 respuestas 429 y 0 errores.
- 4.717.329 bytes recibidos en total.
- 28,635 s acumulados de HTTP y 28,714 s de ejecución completa.
- Trabajadores más centros de costo en frío: 27,044 s.
- Lectura desde la caché de cinco minutos: 0,000054 s y 0 peticiones externas.

La medición anterior de 25,698 s para la carga fría quedó confirmada. La nueva
observación de 27,044 s difiere en 1,346 s, variación normal de una consulta de
red.

## Desglose por fuente

| Fuente | Peticiones | Páginas | Registros | Bytes | Tiempo HTTP | Petición más lenta |
|---|---:|---|---:|---:|---:|---:|
| Trabajadores | 5 | 1–5 | 458 | 4.638.525 | 26,102 s | 7,418 s |
| Centros de costo | 1 | 1 | 79 | 33.993 | 0,871 s | 0,871 s |
| Vacaciones | 1 | 1 | 77 | 25.188 | 0,771 s | 0,771 s |
| Licencias | 1 | 1 | 29 | 19.623 | 0,891 s | 0,891 s |

Las páginas de trabajadores entregaron 100, 100, 100, 100 y 58 registros. Al
aplicar la normalización y deduplicación por RUT que usa actualmente FTE, los
458 registros de origen quedaron en 452 personas únicas.

## Tiempos por página de trabajadores

| Página | Registros | Bytes | Tiempo |
|---:|---:|---:|---:|
| 1 | 100 | 977.233 | 7,418 s |
| 2 | 100 | 1.028.120 | 4,166 s |
| 3 | 100 | 1.044.833 | 5,587 s |
| 4 | 100 | 985.420 | 6,053 s |
| 5 | 58 | 602.919 | 2,878 s |

## Interpretación

El cuello de botella es la nómina: sus cinco páginas consumieron 26,102 s y
aproximadamente el 98 % de los bytes. Centros de costo, vacaciones y licencias
sumaron solamente 2,533 s.

La caché actual es eficaz dentro de una misma sesión: la lectura comprobada fue
prácticamente inmediata. Sin embargo, dura 300 segundos, no es compartida entre
usuarios y no constituye almacenamiento histórico. Vacaciones y licencias no
quedan cubiertas por esa misma caché persistente.

La base SQL propuesta no hará que Buk responda más rápido durante una
sincronización. Sí permitirá sacar estos 27 segundos del camino habitual del
usuario: FTE mostraría la última versión publicada mientras una tarea separada
actualiza los datos.

## Evidencia local

- Herramienta CLI: `rrhh/fte/tools/buk_performance_diagnostic.php`.
- Resultado anonimizado: `storage/fte_diagnostics/buk_performance_20260911_172737.json`.

El JSON no contiene nombres, RUT, token ni cuerpos de respuesta y la herramienta
se niega a ejecutarse desde la web.
