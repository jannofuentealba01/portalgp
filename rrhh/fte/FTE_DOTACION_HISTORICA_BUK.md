# FTE — Dotación histórica desde Buk

Fecha: 8 de septiembre de 2026

## Implementación

PortalGP ahora conserva, al normalizar la nómina Buk:

- Fecha de ingreso y salida del trabajador.
- Historial de cargos (`jobs`) con inicio, término y CECO.
- Jornada semanal y tipo de jornada cuando Buk los entrega.
- Cambios de CECO dentro de un mismo mes.

El motor `fte_headcount_build_month()` reconstruye la asignación persona/CECO
para cada día del mes y entrega cuatro alternativas sin imponer todavía una
regla de negocio:

- Dotación vigente el último día del mes.
- Personas únicas vigentes en algún momento del mes.
- Dotación promedio por día calendario.
- Dotación promedio por día laborable.

También cuantifica días-persona sin CECO y advierte esas inconsistencias.

## Validación real con Buk

Se consultaron 452 registros históricos, incluyendo personas inactivas, sin
mostrar ni persistir datos personales.

Para agosto de 2026 se obtuvo:

- Personas vigentes en algún momento: 236.
- Dotación al cierre: 233.
- Promedio por día calendario: 228,7419.
- Promedio por día laborable: 229,6667.
- CECO históricos: 25.
- Días-persona sin CECO: 0.

El valor de personas vigentes en algún momento coincide exactamente con la
dotación 236 del Excel de agosto.

## Comparación enero-agosto

| Mes | Excel | Vigentes alguna vez | Al cierre |
|---|---:|---:|---:|
| Enero | 234 | 232 | 218 |
| Febrero | 242 | 240 | 230 |
| Marzo | 243 | 241 | 231 |
| Abril | 231 | 230 | 221 |
| Mayo | 223 | 222 | 214 |
| Junio | 219 | 218 | 213 |
| Julio | 224 | 224 | 218 |
| Agosto | 236 | 236 | 233 |

“Vigentes alguna vez” coincide en julio y agosto y queda entre una y dos
personas por debajo del Excel en los meses anteriores. Ningún mes coincide con
la dotación de cierre.

Esto constituye evidencia fuerte de que el Excel se aproxima a personas
vigentes durante alguna parte del mes, pero no basta para declararlo regla
oficial. Las diferencias pueden corresponder a registros sin RUT, correcciones
posteriores en Buk, inclusiones manuales u otra regla histórica de RR.HH.

## Decisión pendiente

RR.HH. debe confirmar qué representa exactamente “Dotación real”. Hasta
entonces el sistema conserva todas las alternativas y no alimenta el motor FTE
con una selección automática.

## Archivos

- Normalización Buk: `fte_lib.php`.
- Motor histórico: `fte_headcount_history.php`.
- Pruebas: `tests/fte_headcount_history.php`.

Pruebas: 12/12 correctas.
