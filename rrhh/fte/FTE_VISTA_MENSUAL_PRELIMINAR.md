# FTE — Vista mensual preliminar

Fecha: 8 de septiembre de 2026

## Implementación

La nueva pantalla `fte_mensual.php` convierte el informe por persona/día en un
respaldo y presenta primero el resultado gerencial mensual. Permite elegir mes,
consultar toda la empresa o un CECO e incorporar GeoVictoria cuando se necesite
el detalle de horas extra y atrasos.

Indicadores superiores:

- Dotación real, FTE calculado y brecha Dotación/FTE.
- FTE/Dotación, horas teóricas, horas extra y horas no disponibles.
- Tasa total de horas no disponibles.

Incluye tabla por CECO, rankings gerenciales, cobertura de cada fuente y avisos
de información pendiente.

## Origen del cálculo

- Calendario: reglas parametrizadas de PortalGP.
- Dotación e ingresos/salidas: historia laboral y CECO de Buk.
- Vacaciones y licencias: endpoints de Buk.
- Horas extra autorizadas y atrasos: GeoVictoria, opcional por el costo de la
  consulta individual de trabajadores.
- Accidentes y permisos: quedan visibles como fuente pendiente; no se inventan
  valores oficiales.

La regla temporal de dotación es “personas vigentes en algún momento del mes”,
porque reproduce agosto de 2026. La pantalla la identifica como una decisión
pendiente de confirmación por RR.HH.

## Validación real

Se ejecutó agosto de 2026 sin la consulta masiva de GeoVictoria:

- 25 CECO.
- Dotación total: 236, coincidente con el Excel de referencia.
- Horas no disponibles automatizadas: 3.573,5.
- FTE parcial: 215,7537.

Este FTE no debe compararse como resultado final con 223,04 del Excel mientras
no se incorporen las 1.336 horas extra y se confirmen todos los descuentos. La
pantalla muestra esta limitación y se denomina explícitamente “preliminar”.

Prueba automatizada: `php tests/fte_monthly_report.php`.
