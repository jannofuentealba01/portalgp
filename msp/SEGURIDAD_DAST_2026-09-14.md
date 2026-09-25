# PortalGP MSP — Auditoría dinámica de seguridad (DAST)

> Cierre posterior: la ejecución aislada y ampliada del 15 de septiembre de
> 2026 está documentada en `SEGURIDAD_DAST_COMPLETA_2026-09-15.md`.

Fecha: 14 de septiembre de 2026  
Entorno: `http://localhost/portalgp`, Apache/XAMPP y base local `PORTALGP`  
Alcance: MSP, autenticado con una sesión existente de `admin_2` y sin cambiar su cuenta.

## Resultado ejecutivo

Se completó una primera auditoría DAST controlada y no destructiva sobre la
instalación local. Los controles principales de autenticación, CSRF, escape de
HTML y bloqueo de archivos sensibles respondieron correctamente.

La ejecución confirmó dos vulnerabilidades de configuración y una condición
operativa que debe resolverse antes de realizar un ataque automatizado completo:

1. **Media — método HTTP TRACE habilitado.** Apache responde `200` y refleja la
   línea de petición y sus encabezados. Debe configurarse `TraceEnable Off`.
2. **Baja — divulgación de versiones en `Server`.** La respuesta publica las
   versiones exactas de Apache, OpenSSL y PHP, facilitando el reconocimiento del
   entorno. En producción se deben usar `ServerTokens Prod` y
   `ServerSignature Off`, además de comprobar el encabezado resultante.
3. **Media operativa — vistas GET con efectos de escritura.** Abrir determinadas
   páginas de cobranza y operación mensual sincroniza casos o materializa datos
   derivados. Esto hace inseguro ejecutar un crawler activo sobre la base
   operativa y dificulta distinguir navegación de una operación de negocio.

No se confirmó SQL Injection, XSS reflejado, exposición de archivos internos,
omisión de autenticación ni omisión de CSRF en las muestras y coberturas
descritas abajo. Esto no reemplaza una prueba activa completa sobre una copia
aislada.

## Herramientas y metodología

- Se intentó preparar OWASP ZAP 2.17.0, pero Windows Application Control bloqueó
  el instalador. No se desactivó ni eludió esa protección del equipo.
- Se usó Wapiti 3.0.9 desde un entorno virtual temporal para una comprobación
  autenticada inicial y se complementó con pruebas HTTP controladas propias.
- La navegación automatizada con formularios fue detenida al comprobar que el
  crawler reutilizaba tokens CSRF válidos y enviaba formularios. No se continuó
  el ataque activo contra la base operativa.
- Las pruebas posteriores se limitaron a solicitudes GET, solicitudes con token
  CSRF deliberadamente inválido y métodos HTTP sin efectos de negocio.

## Cobertura y resultados

### Autenticación y autorización de rutas

- Se localizaron 174 archivos PHP de MSP que invocan `msp2RequireAccess()`.
- Sin sesión, 173 pantallas respondieron con redirección `303` al login.
- El único `200` fue `bootstrap.php`, que es una biblioteca y devolvió cuerpo
  vacío; no expuso información funcional ni datos de negocio.

Resultado: **aprobado en el alcance comprobado**.

### CSRF

- Se localizaron 93 archivos con control de acceso y tratamiento de POST.
- Los 92 endpoints ejecutables respondieron `419` ante un POST autenticado con
  `_csrf=DAST_INVALID_TOKEN`; el resultado fue corroborado en el access log de
  Apache.
- `bootstrap.php` volvió a comportarse como biblioteca sin acción.

Resultado: **92/92 endpoints ejecutables rechazaron el token inválido**.

### SQL Injection y XSS reflejado en búsquedas

Se probaron las búsquedas de Arrendatarios, Contratos, Garantías y Locales con:

- patrón SQL: `' OR 1=1--`;
- marcador XSS: `<svg/onload=confirm(1)>DASTMARK`.

Las ocho combinaciones respondieron `200`, no revelaron `SQLSTATE`, ODBC,
trazas, rutas internas ni errores fatales. El marcador XSS nunca apareció como
HTML crudo: fue codificado como texto (`&lt;...&gt;`).

Resultado: **sin SQLi ni XSS reflejado confirmado en estas cuatro búsquedas**.

### Archivos sensibles y listados de directorio

- Se enumeraron 204 archivos reales con extensiones o nombres sensibles fuera
  de dependencias: 115 SQL, 78 Markdown y 11 archivos de configuración/control.
- Los 204 respondieron `403`; ninguno fue entregado por HTTP.
- `.git`, `msp/db`, `msp/config`, `vendor` y `tests` respondieron `403` sin
  listado de directorio. La ruta de uploads consultada respondió `404`.

Resultado: **204/204 archivos bloqueados y ningún listado confirmado**.

### Entradas e identificadores inválidos

Se consultaron fichas, comprobantes, PDF y gestión de cobranza con identificadores
malformados o inexistentes. Las respuestas fueron redirecciones controladas,
`400`, `404` o una vista válida sin revelar SQL, ODBC, stack trace, error fatal ni
ruta local.

Resultado: **sin divulgación interna confirmada**.

### Cabeceras HTTP y métodos

Confirmado en respuestas reales:

- CSP activa;
- `X-Frame-Options: SAMEORIGIN`;
- `X-Content-Type-Options: nosniff`;
- `Referrer-Policy: strict-origin-when-cross-origin`;
- `Permissions-Policy` restrictiva;
- páginas PHP con `Cache-Control: no-store, private, max-age=0`.

Wapiti informó además durante la ejecución del 14 de septiembre:

- `script-src` inseguro por la presencia entonces existente de `'unsafe-inline'`;
- ausencia de HSTS en HTTP local, esperable porque HSTS solamente debe emitirse
  sobre HTTPS y queda pendiente comprobarlo en producción;
- ausencia de `X-XSS-Protection`, encabezado obsoleto que los navegadores
modernos retiraron y que no se recomienda reactivar como solución.

El hallazgo de `'unsafe-inline'` quedó **corregido el 15 de septiembre de 2026**
mediante nonce declarado por las plantillas y hashes exactos generados desde
código fuente confiable. La evidencia de cierre está en
`SEGURIDAD_CSP_ESTRICTA_2026-09-15.md`.

El método `TRACE` respondió `200` y reflejó un encabezado marcador, por lo que el
hallazgo se considera confirmado. `OPTIONS` se procesa como una navegación
normal en algunas rutas; no se observó CORS permisivo.

### Regresión existente

`php tests/security_stage3_http.php`: **48/48 comprobaciones aprobadas**
con la política estricta actual,
incluida la verificación de que `admin_2` conserva su estado, rol y permisos.

## Efectos detectados durante el crawler y recuperación

Aunque se eligieron módulos pasivos, el crawler de Wapiti envió 149 formularios
usando tokens CSRF encontrados en las páginas. Se detuvo la ejecución apenas se
confirmó el comportamiento y se auditó la base:

- se eliminaron, mediante transacciones acotadas, 9 estados `EN_REVISION` y 15
  entradas de bitácora creados por la prueba;
- se eliminaron 44 casos de cobranza nuevos creados al abrir las vistas;
- no se modificaron pagos ni documentos de cobro;
- 25 respaldos PDF existentes fueron regenerados y sus hashes, tamaños, estado y
  marcas de tiempo quedaron actualizados;
- se invocó la purga de archivos PDF huérfanos; la ejecución no conservó el
  contador de archivos y no es posible afirmar retrospectivamente si eliminó
  alguno;
- siete casos de cobranza preexistentes actualizaron su fecha de sincronización;
  uno quedó recalculado como `RESUELTO` según el estado derivado actual;
- 814 filas derivadas del pool mensual actualizaron su marca de sincronización.

Los estados nuevos y bitácoras atribuibles de forma exacta al crawler fueron
revertidos. Las regeneraciones y sincronizaciones deterministas no se revirtieron
porque no se dispone de sus marcas de tiempo previas. Este comportamiento confirma
que la prueba activa completa no debe repetirse en la base operativa.

## Trabajo necesario para cerrar la auditoría DAST completa

1. Crear una copia aislada y recuperable de la base y del proyecto, sin SMTP ni
   integraciones externas habilitadas.
2. Corregir o aislar las escrituras activadas por GET y documentar qué rutas
   materializan estado derivado.
3. Ejecutar en esa copia el spider y ataque autenticado completo: SQLi, XSS,
   traversal, carga de archivos, control de acceso horizontal/vertical,
   redirecciones, SSRF y lógica financiera.
4. Repetir la prueba con un usuario de privilegios limitados para comparar la
   superficie accesible con la de `admin_2`.
5. Corregir `TRACE` y la firma detallada del servidor, y repetir las pruebas para
   adjuntar evidencia de cierre.
6. Ejecutar DAST en cada entrega relevante y antes de producción, manteniendo
   los resultados fuera del directorio público.

## Estado de `admin_2`

La cuenta no fue eliminada, deshabilitada ni modificada. La prueba de regresión
confirma que continúa con `id=1030`, estado activo, rol Administrador y al menos
los permisos protegidos existentes. No se cambió su contraseña.
