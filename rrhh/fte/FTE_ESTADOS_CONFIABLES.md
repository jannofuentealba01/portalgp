# Estados confiables de asistencia FTE

## Objetivo

Evitar que una falla de integración, una identidad sin conciliar o un día sin
jornada informada se presenten como una ausencia del trabajador. Cada fila
persona/día recibe un código, una descripción y una señal de revisión.

## Estados disponibles

- `TRABAJADO`: existen marcaciones de entrada y salida.
- `MARCACION_INCOMPLETA`: existen marcas, pero falta entrada o salida.
- `SIN_MARCACION`: GeoVictoria respondió, existe una jornada planificada y no
  hay marcas. Requiere revisión; por sí solo no acredita ausencia injustificada.
- `SIN_JORNADA_INFORMADA`: no hay marcas ni una jornada esperada suficiente
  para clasificar el día.
- `VACACIONES`, `LICENCIA`, `ACCIDENTE`, `PERMISO_DIA` y `PERMISO_HORA`:
  ausencias o permisos justificados.
- `DIA_LIBRE`: no correspondía trabajar según la jornada informada.
- `ARTICULO_22`: persona exenta de marcación.
- `NO_CONCILIADO`: GeoVictoria respondió, pero no devolvió a la persona
  solicitada.
- `ERROR_GEOVICTORIA`: la consulta individual al proveedor falló.
- `FUERA_DE_CONTRATO`: el día está fuera de la vigencia laboral considerada.

## Orden de decisión

Primero se validan vigencia, disponibilidad del proveedor y conciliación de
identidad. Después se consideran ausencias justificadas y excepciones de
jornada. Finalmente se evalúan las marcaciones. Así, una caída de GeoVictoria
nunca se transforma en `SIN_MARCACION`.

## Integración actual

- El dashboard muestra el estado por persona/día y permite buscar por él.
- GeoVictoria determina `TRABAJADO`, `MARCACION_INCOMPLETA`, `SIN_MARCACION`,
  `SIN_JORNADA_INFORMADA`, `NO_CONCILIADO` y `ERROR_GEOVICTORIA`.
- La vista Ausencias cruza vacaciones y licencias consultadas desde Buk.
- El motor ya soporta accidente, permisos, día libre y artículo 22, pero su
  activación automática requiere confirmar con RR.HH. el endpoint o campo
  oficial de cada concepto.

## Regla de seguridad funcional

Ninguno de estos estados marca automáticamente una ausencia injustificada.
Los casos ambiguos incluyen `attendance_requires_review=true` y deben ser
revisados antes de usarse en remuneraciones o decisiones disciplinarias.
