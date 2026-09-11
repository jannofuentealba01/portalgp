# FTE — Calendario parametrizado

Fecha: 8 de septiembre de 2026

## Resultado

El calendario mensual quedó implementado como un componente independiente. Para
cada fecha determina regla vigente, día de semana, condición laborable y horas
teóricas. Su resultado alimenta directamente el motor mensual FTE.

## Parámetros disponibles

- Reglas de jornada con fecha de inicio y término.
- Horas distintas para cada día de lunes a domingo.
- Días no laborables con motivo identificable.
- Excepciones por fecha que reemplazan las horas normales.
- Cambios de jornada dentro del mismo mes.

Las reglas iniciales reproducen el Excel analizado:

- Hasta el 26 de abril de 2026: 9 horas lunes-jueves y 8 horas viernes.
- Desde el 27 de abril de 2026: 8,5 horas lunes-jueves y 8 horas viernes.

## Horas reproducidas

| Mes 2026 | Horas teóricas/persona |
|---|---:|
| Enero | 176 |
| Febrero | 176 |
| Marzo | 194 |
| Abril | 184 |
| Mayo | 159,5 |
| Junio | 176,5 |
| Julio | 184,5 |
| Agosto | 176,5 |

Abril se calcula con ambas reglas dentro del mismo mes. Agosto, conectado con el
motor mensual, reproduce 223,04 FTE.

## Controles

- Rechaza fechas, horas y períodos inválidos.
- Rechaza reglas de jornada superpuestas.
- Advierte si existen fechas sin una regla vigente.
- Conserva detalle diario y motivo de cada excepción.
- Una división por cero posterior sigue entregando `N/A` mediante el motor
  mensual.

## Pendiente de confirmación

La planilla de enero excluye el 2 de enero y necesita esa exclusión para llegar
a 176 horas. Se configuró como una excepción observada y explícitamente
pendiente de confirmar con RR.HH.; no se afirmó que sea un feriado legal.

Los demás días no laborables incluidos son únicamente los observados al
reproducir el Excel. Antes de usar el calendario para un cierre oficial debe
definirse la fuente corporativa de feriados y excepciones.

## Archivos

- Configuración: `fte_calendar_config.php`.
- Motor: `fte_calendar_engine.php`.
- Pruebas: `tests/fte_calendar_engine.php`.

Pruebas esperadas: 18/18 correctas.
