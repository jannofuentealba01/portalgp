# FTE — Etapa 2: fotografía mensual por trabajador

Estado: implementada el 23-09-2026.

## Qué resuelve

La dotación histórica ya no depende únicamente de lo que Buk responda en una consulta futura. Para cada mes se puede guardar una fotografía con:

- trabajador, identificación y código Buk;
- CECO histórico y nombre del CECO;
- primer y último día vigente dentro del mes;
- cantidad de días calendario y hábiles;
- marca de vigencia al cierre;
- fechas vigentes exactas y cargos Buk que respaldaron la asignación;
- totales mensuales, regla de conteo, fuente, usuario, fecha y hash de integridad.

## Funcionamiento

1. Se calcula el mes en `FTE mensual`.
2. El panel `Fotografía mensual de dotación` informa si existe una versión.
3. `Guardar fotografía mensual` consulta nuevamente Buk desde el servidor y guarda el mes completo, aunque la pantalla esté filtrada por CECO.
4. Un guardado idéntico no duplica versiones.
5. Si Buk cambió, la versión anterior queda `REEMPLAZADO` y se crea una nueva versión `BORRADOR`.
6. `Ver detalle guardado` permite revisar las filas trabajador–CECO registradas.

## Seguridad e integridad

- El guardado es `POST`, exige sesión, permiso FTE y token CSRF.
- La base impide dos versiones vigentes del mismo mes.
- Se utiliza bloqueo transaccional por período para evitar guardados simultáneos.
- El usuario de ejecución solo recibió `SELECT/INSERT/UPDATE` en la cabecera y `SELECT/INSERT` en el detalle; no tiene eliminación.
- La etapa 2 no cambia el cálculo vigente ni aprueba el mes. La aprobación y congelamiento corresponden a la etapa 3.

## Instalación

La migración idempotente se encuentra en `rrhh/fte/db/patch_fte_dotacion_snapshot.sql` y ya fue aplicada sobre la base local `PORTALGP`.
