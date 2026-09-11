# FTE — Validación controlada de GeoVictoria

Fecha: 8 de septiembre de 2026
Alcance: conexión y estructura funcional; sin persistencia ni exposición de
datos personales.

## Resultado

- `POST /Login` respondió correctamente y entregó un token temporal.
- `POST /AttendanceBook` respondió para un RUT y un día.
- El RUT retornado coincidió con el trabajador seleccionado desde Buk.
- En una muestra controlada de 10 trabajadores para un día, las 10 consultas
  individuales respondieron: 9 tenían intervalos planificados, 8 marcaciones y
  se procesaron 27 punches.
- GeoVictoria rechazó con `HTTP 400` una cadena de varios RUT separados por
  comas. El cliente quedó configurado para consultas individuales.

## Estructura real confirmada

La respuesta contiene `Users` y `ExtraTimeValues`. Cada usuario puede incluir:

- `PlannedInterval`.
- `TotalWorkedHours`, días trabajados/no trabajados y ausencias agregadas.
- Vacaciones, licencias, permisos pagados/no pagados y faltas sin justificar.
- Identificador, grupo, cargo y código de jornada semanal.

Cada intervalo planificado contiene, entre otros:

- `Date`, `Punches` y `Shifts`.
- `WorkedHours` y `NonWorkedHours`.
- `Delay`, `EarlyLeave` y sus valores después de compensación.
- `AuthorizedOvertimeBefore`, `AuthorizedOvertimeAfter` y
  `TotalAuthorizedOvertime`.
- `TimeOffs` y campos de asignación/cumplimiento de tiempo extra.

Los punches observados usan `Entrada`, `SalidaColacion`, `EntradaColacion`,
`Salida` y `SinAsignar`. Sus fechas usan `YYYYMMDDhhmmss`; las duraciones usan
`HH:mm:ss`.

## Ajustes implementados

- Consulta individual por RUT para evitar el `HTTP 400` del lote heredado.
- Separación entre `TRABAJADO`, `MARCACION_INCOMPLETA`, `SIN_MARCACION`,
  `SIN_JORNADA_INFORMADA` y `ERROR_GEOVICTORIA`.
- La vista de ausencias ya no incluye automáticamente días sin jornada informada
  ni errores del proveedor.
- El parser conserva horas trabajadas informadas, horas extra autorizadas,
  atrasos, salida anticipada y horas no trabajadas como valores separados.

## Límites aún pendientes de RR.HH.

La API entrega campos útiles, pero falta confirmar qué campos utiliza RR.HH.
como fuente oficial para horas extra, permisos, atrasos y ausencias del informe
mensual. Esta validación técnica no convierte todavía esos valores en la fórmula
mensual definitiva.
