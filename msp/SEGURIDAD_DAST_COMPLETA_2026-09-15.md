# PortalGP MSP — cierre de auditoría DAST completa

Fecha: 15 de septiembre de 2026  
Entorno operativo observado: `http://localhost/portalgp` sobre Apache/XAMPP  
Entorno de ataque: copia temporal del proyecto y de `PORTALGP`, sin SMTP ni secretos externos  
Alcance: autenticación, MSP, servidor HTTP local, perfiles Administrador y MSP de solo lectura

## Resultado ejecutivo

La auditoría dinámica se completó sobre una copia aislada y recuperable. El
ataque no modificó la base operativa, no envió correos, no utilizó integraciones
externas de negocio y no cambió la cuenta `admin_2`.

No se confirmó SQL Injection, SQL Injection temporizada, XSS reflejado o
persistente, ejecución de comandos, traversal, inclusión de archivos, XXE,
SSRF, CRLF Injection, open redirect, omisión de autenticación, omisión real de
CSRF ni escritura con el perfil de solo lectura en la cobertura ejecutada.

Se confirmaron cuatro problemas de configuración y una condición operativa:

1. **Alta — `phpinfo()` público en `/info.php`.** La página responde `200` y
   divulga versiones, módulos, rutas y configuración del equipo. Apache escucha
   en `0.0.0.0:80` y `[::]:80`, por lo que no debe asumirse que la página queda
   limitada al navegador local. No se encontraron en su salida los nombres de
   secretos de PortalGP comprobados, pero la exposición sigue siendo grave.
2. **Media — HTTP TRACE habilitado.** `TRACE /portalgp/login.php` respondió
   `200` y reflejó un encabezado marcador enviado por la prueba.
3. **Media — firma detallada del servidor.** El encabezado `Server` publica
   `Apache/2.4.58 (Win64) OpenSSL/3.1.3 PHP/8.2.12`.
4. **Media en el entorno actual — sesión transportada por HTTP.** La cookie usa
   `HttpOnly` y `SameSite=Lax`, pero no puede usar `Secure` mientras PortalGP se
   ejecute por HTTP. Como Apache escucha en todas las interfaces, trabajar desde
   una red no confiable aumenta el riesgo. El código sí activa `Secure` al usar
   HTTPS; falta certificar esa configuración en el entorno de despliegue.
5. **Media operativa — el rastreo no es seguro sobre la base real.** Algunas
   vistas GET sincronizan datos derivados y el crawler también reutiliza tokens
   válidos de formularios. La copia registró cambios en cobranza, pendientes y
   tesorería durante el ataque. Toda futura prueba activa debe conservar el
   aislamiento usado en esta ejecución.

Como hallazgo bajo, permanecen recursos predeterminados de XAMPP como
`/icons/README` y `/img/`. `server-status` y `server-info` responden desde el
equipo local, pero su configuración contiene `Require local`; no se confirmó
que estén disponibles para otros equipos.

## Entorno aislado

- Se generó un backup `COPY_ONLY` con checksum de `PORTALGP`, se verificó y se
  restauró como una base temporal `PORTALGP_DAST_*`.
- Se copió el árbol del proyecto a una carpeta temporal. Los PDFs, adjuntos y
  demás archivos generados quedaron bajo un almacenamiento temporal separado.
- El servidor de prueba usó un nombre de cookie distinto y un almacén de
  sesiones separado.
- El almacén de secretos se sustituyó por una carpeta vacía: SMTP, Entra y las
  integraciones externas de negocio no estuvieron disponibles.
- Se usó una sesión equivalente a Administrador y otra de MSP de solo lectura.
  En la copia se retiraron escritura y eliminación al segundo perfil.

## Cobertura ejecutada

### Rastreo y ataque automático

- Wapiti 3.0.9 descubrió **3.939 URL y formularios** durante un rastreo
  autenticado de profundidad 6 y cinco minutos de duración máxima.
- Se atacaron entradas con los módulos de SQLi, SQLi temporizada, XSS, ejecución
  de comandos, archivos/traversal, XXE, SSRF, redirecciones y CRLF.
- Se comprobaron además CSP, cookies, cabeceras, CSRF, métodos HTTP, bypass de
  `.htaccess`, archivos de respaldo, rutas comunes y huellas del servidor.
- El informe de inyección terminó sin vulnerabilidades ni anomalías confirmadas.

OWASP ZAP seguía bloqueado por Windows Application Control, por lo que no se
intentó eludir la política del equipo. Wapiti se complementó con verificaciones
HTTP propias y con ejecución real en navegador.

### Validación independiente por endpoint

La prueba reproducible `tests/security_dast_endpoints.php` agrupó el inventario
por ruta, método y conjunto de parámetros para no contar cada contrato o ID como
una cobertura diferente:

- **385** combinaciones únicas descubiertas;
- **211** casos PHP GET probados sin sesión;
- **164** casos PHP POST probados con CSRF inválido;
- **164** casos PHP POST probados con un perfil MSP de solo lectura;
- **234** solicitudes adicionales con marcadores SQL y XSS;
- **773** solicitudes de verificación en total;
- **0** omisiones o exposiciones confirmadas por esta prueba.

### Comprobaciones manuales controladas

- Dos cargas con extensión o contenido falso no guardaron filas ni archivos.
- Un marcador XSS persistente se almacenó en la copia, se mostró codificado como
  texto y luego se eliminó.
- Los parámetros `return_to` y `redirect_to` no permitieron salir del sitio.
- Una sesión de un usuario deshabilitado y una sesión inexistente fueron
  redirigidas al login.
- El login mostró el mismo mensaje para un usuario conocido y uno inexistente;
  el límite de intentos se activó y emitió `Retry-After`.
- Un `Host` externo no fue reflejado en la redirección de autenticación.
- **202/202** archivos SQL, Markdown, configuración e históricos respondieron
  `403` desde Apache.

### Regresión y navegador

- `security_csp_strict.php`: **25/25**.
- `security_stage3_http.php`: **48/48**.
- `security_runtime_objects.php`: **15/15**.
- `msp_regression_suite.php`: **21/21**.
- `security_csp_browser.js`: **43/43** vistas sin violaciones CSP, usando el
  entorno aislado y abriendo componentes interactivos seleccionados.

La verificación de runtime confirmó nuevamente que `admin_2` conserva `id=1030`,
estado activo, rol Administrador, versión de seguridad y permisos.

## Falsos positivos descartados

Wapiti informó 50 formularios como carentes de CSRF porque varios reciben el
campo oculto mediante JavaScript y la herramienta solamente inspecciona el HTML
inicial. La validación real envió un token inválido a los 164 POST únicos y todos
fueron detenidos; por tanto, esos 50 avisos no representan una omisión de CSRF.

La ausencia de `X-XSS-Protection` no se considera una corrección pendiente: es
un encabezado obsoleto. La ausencia de HSTS y de `Secure` en la copia HTTP se
interpretó dentro del contexto local; HSTS debe verificarse sobre HTTPS y no
debe emitirse en una conexión HTTP de desarrollo.

Nikto describió `/info.php?file=...` como posible RFI. La página ignora ese
parámetro y no se confirmó inclusión remota. El hallazgo válido es que el propio
`phpinfo()` está publicado.

## Orden recomendado de corrección

1. Eliminar `C:/xampp/htdocs/info.php` o denegarlo explícitamente y retirar los
   recursos predeterminados de XAMPP que no sean necesarios.
2. Configurar `TraceEnable Off` en Apache y comprobar que TRACE responda `405`.
3. Aplicar `ServerTokens Prod` y `ServerSignature Off` en la configuración del
   servidor, y volver a revisar el encabezado `Server`.
4. Para desarrollo, limitar Apache a loopback o proteger el puerto con firewall;
   para despliegue, usar HTTPS y certificar `Secure` y HSTS.
5. Separar de los GET las sincronizaciones o materializaciones que escriben, o
   declararlas explícitamente y excluirlas de cualquier crawler operativo.

## Límites de la conclusión

El resultado describe la instalación local y las rutas alcanzadas por el
rastreo. No certifica todavía TLS, proxy, firewall, DNS, HSTS ni configuración
del futuro servidor de producción. La prueba debe repetirse en un staging
equivalente a producción y nuevamente antes del despliegue definitivo.

## Cierre del entorno de ataque

Al finalizar se detuvo el servidor HTTP aislado y se eliminaron la base
`PORTALGP_DAST_*`, su respaldo temporal, las sesiones y la copia del proyecto
usada por el ataque. La base operativa `PORTALGP` permaneció en servicio y sus
pruebas finales volvieron a resultar correctas.
