# PortalGP FTE — preparación previa a conexión

Fecha: 8 de septiembre de 2026

El alcance funcional vigente, sus límites y las preguntas pendientes para
RR.HH. se mantienen en `FTE_CONTEXTO_FUNCIONAL_Y_BRECHAS.md`.

## Estado

El módulo quedó preparado hasta el punto inmediatamente anterior a consultar
Buk o GeoVictoria. Durante esta etapa no se efectuó ninguna solicitud a esos
proveedores.

## Cambios realizados

- Se eliminó la dependencia activa de `dbo.cr_pho_trabajadores` y de nombres o
  filtros de trabajadores locales.
- La nómina se obtendrá desde `/api/v1/chile/employees` y los centros de costo se
  construirán desde las personas normalizadas de Buk.
- Buk utilizará únicamente el encabezado `auth_token` documentado.
- Se retiró el token Buk incrustado. La configuración local quedó excluida de
  Git y bloqueada por Apache.
- cURL valida obligatoriamente certificado y host; se eliminó el reintento con
  TLS deshabilitado.
- Las cuatro rutas aplican sesión y el permiso `Ver Dashboard FTE` sin bypass si
  la función de permisos no está disponible.
- `admin_2` continúa habilitado, Administrador y con lectura, escritura y
  eliminación para el permiso FTE.
- Los datos externos se insertarán mediante `textContent` y nodos seguros, sin
  `innerHTML`.
- El caché con información laboral pasó de `localStorage` persistente a
  `sessionStorage`, con vigencia máxima de 15 minutos en las vistas secundarias.
- Bootstrap y Chart.js se cargan desde los assets locales; se retiraron el CDN,
  el CSS inexistente y la ruta rota del menú.
- Los errores públicos ya no exponen excepciones internas y entregan un código
  de referencia; los detalles de log pasan por ocultamiento de credenciales.

## Comprobaciones

- Preparación FTE: 28/28.
- Sintaxis PHP: 7/7 archivos comprobados.
- Sintaxis JavaScript compartido: correcta.
- Configuración local por HTTP: `403`.
- API sin sesión: `401`, antes de cualquier consulta externa.
- Dashboard sin sesión: redirección `302` al login.

## Tarea 1: conexión Buk

Por autorización posterior se trasladó el token heredado a
`fte_config.local.php`, que está ignorado por Git y bloqueado por Apache. La
conexión real a Buk respondió `HTTP 200` y permitió validar la estructura de
empleados y áreas sin registrar ni mostrar datos personales.

Resultado agregado de la consulta completa:

- 231 trabajadores activos con RUT único.
- 231 trabajadores activos con centro de costo.
- 26 centros de costo, todos con nombre.
- La paginación real es de 25 registros y 19 páginas; el cliente fue ajustado
  para respetar `pagination.next` y no detenerse prematuramente.
- Los estados reales son `activo` e `inactivo`; el normalizador ahora excluye
  correctamente estos últimos.

La nómina se conserva como máximo cinco minutos en la sesión para evitar repetir
las 19 consultas en una misma navegación. GeoVictoria no fue contactado durante
esta tarea.

Aunque funciona, el token trasladado había estado incrustado en código y sigue
siendo recomendable rotarlo en Buk cuando se disponga de uno nuevo.

## Próxima ejecución controlada

Cuando se autorice la conexión, el orden será:

1. Consultar una sola página pequeña de empleados Buk y revisar únicamente la
   estructura necesaria.
2. Ajustar el normalizador a la respuesta real y comprobar centros de costo.
3. Probar solamente el login moderno de GeoVictoria.
4. Consultar `AttendanceBook` para un RUT y un día antes de ampliar el alcance.
