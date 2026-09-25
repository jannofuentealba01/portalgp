# FTE · Dotación mensual · Etapa 3

## Resultado

La fotografía mensual guardada puede aprobarse y congelarse. Desde ese momento, el informe FTE usa su detalle diario trabajador–CECO como fuente oficial de dotación del período y deja de reconstruirla desde la nómina actual de Buk.

## Flujo operativo

1. Calcular el mes y guardar la fotografía completa desde Buk.
2. Revisar el detalle trabajador–CECO y corregir cualquier día sin CECO.
3. Escribir manualmente la cantidad de personas únicas usada como control.
4. Confirmar **Aprobar y congelar**. La cantidad debe coincidir con el borrador.
5. Recalcular el mes. La cobertura mostrará `OFICIAL APROBADA` y la versión utilizada.

## Controles

- Solo usuarios con nivel `eliminación` del permiso FTE pueden aprobar.
- Guardar requiere `escritura`; consultar requiere `lectura`.
- No se aprueban fotografías vacías, incompletas o con días sin CECO.
- Se registra usuario, fecha y observación de aprobación.
- Triggers de base impiden modificar la cabecera o el detalle una vez aprobados.
- Un período aprobado no puede ser reemplazado por una nueva consulta a Buk.

La aprobación oficializa únicamente la **dotación e ingresos/salidas**. El resultado FTE completo continúa marcado como preliminar mientras vacaciones, licencias, permisos, horas extra y reglas de asistencia no formen parte de un cierre integral.
