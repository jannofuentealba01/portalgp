# PortalGP — auditoría maestra de seguridad

Fecha de inicio: 8 de octubre de 2026  
Estado: en ejecución  
Entorno principal auditado: PortalGP temporal en AWS Lightsail  
Objetivo final: preparar una migración segura al servidor definitivo sin cambiar las reglas funcionales de PortalGP

## Forma de trabajo

Este es el documento único de la auditoría. Cada revisión futura deberá actualizar aquí el alcance, la evidencia, los hallazgos, su solución propuesta y el estado de cierre. No se deben copiar credenciales, tokens, datos personales ni datos financieros reales en este archivo.

Estados utilizados:

- `Pendiente`: todavía no se ha revisado.
- `En revisión`: existe evidencia parcial, pero falta una comprobación.
- `Confirmado`: el hallazgo fue reproducido y tiene evidencia.
- `Corregido`: se implementó una solución, pero falta validarla.
- `Cerrado`: la solución fue implementada y verificada.
- `Aceptado`: el riesgo fue entendido y aceptado expresamente.

Severidades utilizadas: `Crítica`, `Alta`, `Media`, `Baja` e `Informativa`.

---

# 1. Alcance exacto

## 1.1 Propósito de esta etapa

Definir con precisión qué componentes forman PortalGP, cuáles están realmente desplegados, qué datos e integraciones atraviesan la aplicación y qué límites deben respetarse antes de revisar o corregir vulnerabilidades.

Esta etapa no autoriza:

- modificar información real de PortalGP;
- enviar correos o notificaciones;
- ejecutar ataques activos contra la base de producción;
- cambiar Nginx, PHP-FPM, SQL Server, firewall o servicios;
- modificar la aplicación `it-conecta` que comparte temporalmente el servidor AWS;
- desplegar correcciones al servidor definitivo.

## 1.2 Línea base exacta del despliegue temporal (histórica, antes de HTTPS)

| Elemento | Línea base observada |
|---|---|
| URL temporal | `http://15.229.113.179/portalgp/` |
| Proveedor | AWS Lightsail |
| Host interno | `ip-172-26-5-140` |
| Sistema | Linux AWS, arquitectura x86_64 |
| Servidor web | Nginx 1.24.0 (Ubuntu) |
| Runtime | PHP 8.3.6 mediante PHP-FPM |
| Ruta desplegada | `/var/www/portalgp` |
| Rama desplegada | `codex/msp-aws-deploy` |
| Commit desplegado | `97997abaa53f2ff91cf160a66a1e6f1188f41257` |
| Estado Git remoto | árbol limpio al momento de la revisión |
| Transporte web | HTTP en puerto 80; no existe servicio HTTPS en 443 |
| Base usada | SQL Server, base `PORTALGP` |
| Endpoint SQL conocido | `216.155.78.65` |

La revisión de seguridad de AWS toma como referencia el SHA desplegado indicado
en la tabla. La rama local `dev` no es idéntica a la rama de AWS; las soluciones
portables deben integrarse explícitamente en ambas ramas y desplegarse solo desde
`codex/msp-aws-deploy`, conservando el SHA exacto como evidencia.

## 1.3 Límite con `it-conecta`

El mismo bloque de Nginx sirve actualmente:

- `it-conecta`, con raíz `/var/www/it-conecta/public`;
- PortalGP, agregado bajo las ubicaciones `/portalgp` y `/portalgp/`.

Por esta razón, toda corrección temporal en AWS deberá aplicarse solamente al `location` o *snippet* de PortalGP cuando sea posible. No se modificarán configuraciones globales, procesos, base de datos, archivos ni reglas de `it-conecta` sin una autorización separada y una prueba específica.

## 1.4 Inventario de código desplegado

El commit de producción temporal contiene 873 archivos versionados:

| Tipo o módulo | Cantidad observada |
|---|---:|
| PHP | 511 |
| SQL | 137 |
| Markdown | 88 |
| CSS | 51 |
| JavaScript | 40 |
| MSP | 477 archivos: 251 PHP y 108 SQL |
| CT | 167 archivos: 102 PHP y 26 SQL |
| RR.HH. | 55 archivos: 23 PHP y 3 SQL |
| Sistema / gestión | 6 archivos PHP |
| Pruebas versionadas | 73 archivos, 66 de ellos PHP |
| Plantillas compartidas | 21 archivos, 20 de ellos PHP |

En la copia de trabajo actual, MSP contiene 252 archivos PHP y concentra la mayor superficie operativa: aproximadamente 80 archivos reciben `POST`, 72 reciben `GET` y 11 procesan cargas de archivos. Estas cifras orientan la revisión; no significan que cada archivo sea un endpoint público independiente.

## 1.5 Componentes incluidos

### Núcleo compartido

- autenticación local y Microsoft Entra;
- sesiones, cookies, CSRF, CSP, cabeceras y manejo seguro de errores;
- conexión PDO a SQL Server;
- usuarios, roles, permisos y perfiles;
- plantillas, estilos y recursos frontend compartidos.

Archivos centrales: `db.php`, `security.php`, `auth_helper.php`, `login_security.php`, `permission_service.php`, `permisos.php`, `secret_paths.php`, `config/` y `templates/`.

### MSP

Se incluye todo el módulo: arrendatarios, tiendas, locales, contratos, garantías, cobros, operación mensual, control diario, documentos, pagos, tesorería, contabilidad, cobranza, correcciones, cierre, reportes, catálogos, cargas y descargas.

También se incluyen:

- 19 servicios de negocio bajo `msp/services/`;
- 108 scripts SQL desplegados en `msp/db/`, incluidos instaladores, parches y reversas;
- generación y entrega de PDF;
- envío de correos y procesamiento por lotes;
- adjuntos contractuales y respaldos de pago/garantía;
- endpoints auxiliares y trabajos programados.

### Otros módulos que comparten seguridad

- CT;
- RR.HH. y FTE;
- gestión de usuarios del sistema;
- página inicial, perfil, login y callback de Microsoft.

Aunque el foco funcional sea MSP, estos componentes quedan incluidos porque comparten sesión, permisos, conexión de base, secretos, Nginx y dependencias. Una vulnerabilidad en cualquiera de ellos puede comprometer MSP.

## 1.6 Datos incluidos

La auditoría cubrirá las categorías ya definidas por PortalGP:

1. autenticación: contraseñas, sesiones, intentos, OAuth y tokens;
2. identidad y contacto: RUT, nombres, correos, teléfonos y direcciones;
3. información financiera y contractual: contratos, cobros, pagos, garantías, saldos, cuentas bancarias y contabilidad;
4. archivos cargados o generados: PDF, planillas, comprobantes y adjuntos;
5. información laboral FTE: personas, asistencia, vacaciones, licencias y métricas;
6. logs, diagnósticos, respaldos y evidencia técnica.

La auditoría evaluará confidencialidad, integridad, disponibilidad, trazabilidad, retención, restauración y privilegios; no copiará el contenido real fuera de sus sistemas.

## 1.7 Base de datos incluida

Se incluye la base SQL Server `PORTALGP` usada por la aplicación temporal y por otros consumidores autorizados. El análisis comprenderá:

- identidad técnica de la aplicación y principio de mínimo privilegio;
- cifrado de transporte y validación del certificado;
- tablas, vistas, procedimientos, funciones, índices y disparadores usados por PortalGP;
- consultas dinámicas, parámetros, transacciones y concurrencia;
- integridad de cobros, pagos, garantías, cierres y contabilidad;
- respaldos, restauración, auditoría y continuidad;
- separación lógica para evitar efectos fuera de MSP.

Las pruebas destructivas o de carga se harán solamente sobre una copia aislada y recuperable, nunca sobre la base activa.

## 1.8 Archivos, secretos y almacenamiento

Rutas incluidas:

- código web: `/var/www/portalgp`;
- secretos generales esperados: `/var/portalgp_secrets`, fuera del árbol web;
- archivos MSP esperados: `/var/msp_storage`, fuera del árbol web;
- temporales, cachés FTE, sesiones, logs de Nginx/PHP y respaldos;
- repositorio Git y archivos ignorados presentes en el servidor.

Se verificará que los archivos solo puedan descargarse mediante un endpoint autenticado y autorizado, que las cargas validen contenido/tamaño/nombre, y que ningún secreto dependa de una extensión PHP para evitar su descarga.

## 1.9 Integraciones incluidas

- SQL Server remoto;
- Microsoft Entra ID;
- SMTP de MSP y CT;
- Buk;
- GeoVictoria;
- GitHub y el procedimiento de despliegue;
- trabajos programados y procesamiento de lotes.

La auditoría revisará almacenamiento de credenciales, TLS, tiempos de espera, validación de respuestas, registros sin secretos, rotación y comportamiento seguro ante fallas. No se enviarán mensajes ni se alterarán fuentes externas durante las pruebas.

## 1.10 Superficie de infraestructura incluida

En AWS se revisará, sin asumir que será el servidor final:

- Nginx y sus reglas específicas de PortalGP;
- PHP-FPM y extensiones;
- usuarios, grupos y permisos de archivos;
- puertos, firewall y exposición pública;
- cabeceras, métodos HTTP, TLS y cookies;
- logs, monitoreo, respaldo y recuperación;
- separación respecto de `it-conecta`.

En el servidor final se repetirá esta revisión desde cero, porque los resultados de AWS no certifican VMware Cloud Director, Windows/WAMP, red, DNS, certificados, SMTP ni respaldo de ese entorno.

## 1.11 Pruebas permitidas por defecto

- lectura de código, configuración no secreta e historial Git;
- análisis estático y búsqueda de patrones;
- lint y pruebas automatizadas que no escriban datos reales;
- solicitudes HTTP sin autenticación que no cambien estado;
- revisión de cabeceras y códigos de respuesta;
- consultas SQL exclusivamente de metadatos cuando exista autorización;
- pruebas activas solo en una copia aislada de aplicación y base.

Toda prueba que pueda escribir, enviar correo, alterar permisos, consumir masivamente una API o afectar disponibilidad requerirá una decisión explícita previa.

## 1.12 Criterio de cierre de la auditoría completa

La plataforma podrá considerarse preparada para migración cuando:

- no existan hallazgos críticos o altos abiertos;
- los hallazgos medios tengan corrección o aceptación documentada;
- autenticación, autorización, CSRF, XSS, SQL Injection, cargas y descargas estén verificadas;
- HTTPS, cookies seguras, secretos externos y TLS SQL estén validados en el servidor final;
- exista un respaldo restaurable y una prueba de recuperación;
- el despliegue sea reproducible desde un commit identificado;
- el monitoreo permita detectar errores y accesos indebidos;
- una regresión funcional confirme que las correcciones no cambian las reglas del portal.

---

# 2. Protección previa y puntos de restauración

Estado: **protección creada y verificada; prueba de restauración SQL pendiente de una identidad administrativa**.

## 2.1 Punto recuperable en Git

- Se creó y publicó la etiqueta inmutable `security-preflight-2026-10-08` sobre
  `81523fb5fd2b113e92c9102891fa6177daebe79c`, que identifica el estado de
  PortalGP anterior a continuar la auditoría.
- La rama desplegable continúa siendo `codex/msp-aws-deploy`.
- El respaldo de ejecución se tomó sobre
  `6625cf107adbb96aa7729baafe73d3deedc2bf22`, con árbol limpio. Después se
  añadieron únicamente los verificadores de restauración y catálogo SQL; AWS
  quedó en `61fe80276a480a778e5ad4a7048c67b5bddd1663`, también limpio, y ese SHA
  fue registrado en `/var/lib/portalgp/deployed_commit`.
- Los procedimientos de respaldo se guardaron en Git, sin secretos, para poder
  reutilizarlos al migrar al servidor definitivo.

## 2.2 Respaldo de aplicación y servidor AWS

Se creó el punto final:

`/var/backups/portalgp/security-preflight-20261008T174146Z`

Contenido:

| Archivo | Contenido | Tamaño |
|---|---|---:|
| `portalgp-runtime.tar.gz` | Código y dependencias en ejecución, excluyendo `.git` y secretos locales | 9.734.979 bytes |
| `msp-storage.tar.gz` | Adjuntos y documentos MSP ubicados fuera del árbol web | 1.078.138 bytes |
| `runtime-secrets.tar.gz` | Configuración secreta separada del resto del respaldo | 469 bytes |
| `server-config.tar.gz` | Nginx, PHP-FPM y ODBC relevantes para PortalGP | 35.943 bytes |

Controles comprobados:

1. directorios con modo `700` y archivos con modo `600`, todos propiedad de
   `root:root`;
2. lectura completa de los cuatro archivos con `tar -tzf`;
3. hashes SHA-256 registrados y comprobados correctamente;
4. extracción completa en un directorio aislado, validación de archivos clave y
   eliminación automática del temporal mediante
   `deploy/scripts/verify_preflight_backup.sh`;
5. manifiesto con fecha UTC, host, versiones, rama, SHA y estado limpio;
6. Nginx y PHP-FPM activos;
7. PortalGP e `it-conecta` siguieron respondiendo HTTP 200 después del proceso.

El script `deploy/scripts/create_preflight_backup.sh` evita escribir el índice
Git cuando se ejecuta como `root`. Durante la primera ejecución se detectó que
Git había actualizado únicamente `.git/index` con propietario `root`; se
restauró su propietario original, se corrigió el script y se verificó que una
nueva ejecución conserva el índice como `ubuntu` y el árbol limpio. No se
alteraron archivos funcionales ni datos de negocio.

## 2.3 Respaldo de SQL Server

El 8 de octubre de 2026 se ejecutó un respaldo completo de `PORTALGP` mediante
la identidad técnica existente `portal`, que tiene permiso `BACKUP DATABASE`:

`PORTALGP_COPY_ONLY_SECURITY_20261008_174730.bak`

Propiedades solicitadas y confirmadas por la operación:

- `COPY_ONLY`, para no alterar la cadena normal de respaldos;
- compresión;
- `CHECKSUM` durante la generación;
- destino en la ruta predeterminada de respaldos de SQL Server;
- registro confirmado en `msdb`: 56.712.192 bytes sin comprimir y 8.470.186
  bytes comprimidos, con `is_copy_only=1` y `has_backup_checksums=1`;
- sin escritura funcional adicional en las tablas de PortalGP.

La herramienta portable quedó en `scripts/security_backup_sqlserver.php`, no
contiene credenciales y solo declara éxito cuando encuentra la fila exacta en
`msdb`. Un primer intento que no apareció en el catálogo se descartó como
evidencia y provocó este endurecimiento; el único respaldo nuevo aceptado en
esta etapa es el archivo `...174730.bak` indicado arriba. El historial observado también registra respaldos
completos anteriores con `COPY_ONLY` y checksum de los días 25 de septiembre,
6 de octubre y 8 de octubre de 2026.

La comprobación `RESTORE VERIFYONLY ... WITH CHECKSUM` no pudo ejecutarse con
`portal`: SQL Server exige permiso administrativo en `master`. Esto es correcto
desde el principio de mínimo privilegio y **no se ampliaron los permisos de la
cuenta de la aplicación**. El archivo fue creado, pero su restaurabilidad no se
dará por certificada hasta realizar esa prueba con una identidad administrativa.

## 2.4 Límites y pasos de cierre

La protección actual permite recuperar código desde Git, reconstruir la
configuración y conservar los datos/archivos operativos. Aun así:

1. el respaldo de aplicación está en el mismo disco de Lightsail y no protege
   por sí solo contra pérdida total de esa instancia;
2. el `.bak` está en el host de SQL Server y todavía no tiene una restauración
   aislada comprobada;
3. antes de una migración o cambio destructivo se debe copiar el respaldo AWS a
   una ubicación externa cifrada o crear un snapshot de Lightsail;
4. un administrador SQL debe ejecutar `RESTORE VERIFYONLY` y, preferiblemente,
   una restauración de prueba bajo un nombre temporal, sin reemplazar `PORTALGP`.

No se modificó `it-conecta`, no se enviaron correos y no se registraron datos de
negocio durante esta etapa.

---

# 4. Sistema operativo y servidor web

## SEC-006 — Actualizaciones de seguridad y reinicio pendientes

- Severidad: **Media**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: Ubuntu 24.04 mantiene 6 actualizaciones de seguridad pendientes y
  existe `/var/run/reboot-required`; entre los paquetes pendientes aparecen
  `sudo`, `libc6` y componentes del kernel.
- Riesgo: el servidor continúa ejecutando versiones anteriores aun cuando las
  actualizaciones automáticas descarguen o instalen parte de las correcciones.

Solución propuesta:

1. Usar el respaldo ya creado y programar una ventana de mantenimiento.
2. Aplicar las actualizaciones, reiniciar y comprobar el kernel activo.
3. Validar PortalGP e `it-conecta` después del reinicio y establecer una alerta
   de reinicio pendiente; no reiniciar automáticamente el servidor compartido.

Corrección aplicada:

- se instalaron todas las actualizaciones estándar, incluido el kernel AWS
  `7.0.0-1014`, y el servidor se reinició correctamente;
- se retiraron Composer y seis bibliotecas Symfony del sistema que no eran
  utilizadas por el despliegue y cuyos parches exigían Ubuntu Pro;
- no quedan paquetes actualizables ni reinicio pendiente;
- PortalGP, `it-conecta`, la conexión SQL y la generación de PDF fueron
  verificados después del reinicio.

## SEC-007 — SSH público permite acceso directo de `root`

- Severidad: **Alta**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: el puerto 22 escucha públicamente en IPv4 e IPv6, UFW está inactivo,
  `PermitRootLogin` permite llaves y la misma llave RSA está autorizada para
  `root` y `ubuntu`. Se observaron 857 intentos de usuario inválido en 7 días.
- Riesgo: aumenta la superficie de fuerza bruta y una sola llave comprometida
  entrega acceso administrativo directo al servidor.

Solución propuesta:

1. Restringir SSH en el firewall de Lightsail a las IP administrativas o a una
   VPN y activar UFW como segunda capa sin cerrar la sesión vigente.
2. Probar una llave individual para `ubuntu`, configurar `PermitRootLogin no` y
   retirar la llave de `/root/.ssh/authorized_keys`.
3. Desactivar X11, reenvío de agente y reenvío TCP si no son necesarios, limitar
   usuarios con `AllowUsers` e instalar protección contra intentos reiterados.

Corrección aplicada:

- `PermitRootLogin no`, autenticación por contraseña e interacción de teclado
  deshabilitadas; X11 y los reenvíos de agente y TCP quedaron bloqueados;
- la llave directa de `root` fue retirada con respaldo recuperable;
- la llave RSA compartida de Lightsail fue reemplazada por una llave Ed25519
  individual, probada antes del retiro; la llave anterior ya no autentica;
- UFW permite SSH solo desde la IP administrativa comprobada y el rango de la
  consola web AWS, manteniendo HTTP/HTTPS disponibles;
- Fail2ban quedó activo para SSH. Si cambia la IP administrativa, se debe
  actualizar la regla mediante la consola AWS antes de intentar conectar.

## SEC-008 — PortalGP e `it-conecta` comparten usuario y pool PHP-FPM

- Severidad: **Alta**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: ambas aplicaciones ejecutan PHP mediante el pool `www` como
  `www-data`. Ese usuario puede leer el secreto de base de PortalGP y el `.env`
  de `it-conecta`, escribir en `/var/msp_storage` y escribir en el árbol de
  `it-conecta`.
- Riesgo: comprometer cualquiera de las dos aplicaciones permitiría leer
  secretos o alterar archivos de la otra, aunque sean proyectos independientes.

Solución propuesta:

1. Crear un usuario y pool PHP-FPM exclusivos para PortalGP con un socket propio.
2. Cambiar solo la ubicación PHP de `/portalgp` al nuevo socket; mantener
   `it-conecta` en su pool actual hasta probar una separación equivalente.
3. Entregar al usuario de PortalGP acceso mínimo a sus secretos y almacenamiento,
   dejando el código en modo solo lectura salvo carpetas operativas explícitas.

Corrección aplicada:

- PortalGP ejecuta PHP con el usuario y pool `portalgp`, mediante el socket
  `/run/php/php8.3-fpm-portalgp.sock`; `it-conecta` permanece en el pool `www`;
- PortalGP dispone de directorio propio de sesiones y acceso mínimo a sus
  secretos, configuración FTE y `/var/msp_storage`;
- `www-data` ya no puede leer el secreto SQL de PortalGP y `portalgp` no puede
  leer el `.env` ni escribir en el árbol de `it-conecta`;
- la restricción sobre `it-conecta` es una ACL dirigida exclusivamente al usuario
  técnico `portalgp`: no cambió contenido, propietario ni permisos funcionales;
- `admin_2` y `respinoza` permanecen habilitados con rol Administrador. No se
  modificaron sus contraseñas, permisos, roles ni datos de sesión.

## SEC-009 — Dependencias PHP expuestas y modificables por el proceso web

- Severidad: **Alta**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: `/portalgp/vendor/autoload.php` responde `200`, un ejemplo ejecutable
  de una dependencia responde `500` y 1.452 archivos de `vendor` son escribibles
  por el grupo de `www-data`.
- Riesgo: amplía los puntos PHP accesibles y permitiría persistencia o ejecución
  de código si una vulnerabilidad obtiene escritura con la identidad web.

Solución propuesta:

1. Denegar completamente `/portalgp/vendor/` desde Nginx.
2. Dejar código y dependencias como solo lectura para PHP-FPM, con propietario de
   despliegue y permisos `755` en directorios y `644` en archivos.
3. Mover cachés temporales —incluidas fuentes de PDF— a una ruta escribible fuera
   del árbol web y ejecutar Composer como usuario de despliegue con `--no-dev`.

Corrección aplicada:

- Nginx deniega completamente `/portalgp/vendor/`; `autoload.php` pasó de
  responder `200` a responder `403`;
- el script de despliegue normaliza `vendor` a directorios `0755` y archivos
  `0644`, preserva ejecutables y deja como propietario al usuario de despliegue;
- ni `portalgp` ni `www-data` pueden modificar `vendor/autoload.php`;
- la carga de Composer, las exportaciones XLSX y la generación Dompdf fueron
  verificadas con las dependencias en modo de solo lectura; el PDF generado
  comenzó correctamente con `%PDF-`.

## SEC-010 — El host de la solicitud se acepta como URL confiable

- Severidad: **Media**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: Nginx responde `200` ante un encabezado `Host` arbitrario y el código
  usa `HTTP_HOST` y, en algunos casos, `X-Forwarded-Proto` para construir enlaces
  absolutos cuando no existe una URL canónica configurada.
- Riesgo: un encabezado manipulado podría contaminar enlaces de notificaciones,
  redirecciones o integraciones y dirigir al usuario hacia un dominio ajeno.

Solución propuesta:

1. Definir la URL canónica de PortalGP en la configuración de cada entorno.
2. Rechazar en Nginx cualquier `Host` que no pertenezca a la lista permitida.
3. Confiar en `X-Forwarded-Proto` solo detrás de un proxy conocido que reemplace
   el encabezado y probar login, Entra, correos y enlaces absolutos.

Corrección aplicada:

- `PORTALGP_CANONICAL_BASE_URL` quedó fijada en el pool exclusivo de PHP-FPM a
  `http://15.229.113.179`; deberá sustituirse por HTTPS y el dominio definitivo
  al migrar;
- autenticación y notificaciones CT usan un helper común y ya no construyen
  enlaces desde `HTTP_HOST`; `X-Forwarded-Proto` queda ignorado salvo activación
  explícita para un proxy confiable;
- Nginx rechaza con `421` cualquier `Host` distinto de la IP autorizada, tanto
  en PHP como en contenido estático y en la raíz `/portalgp`;
- la prueba automatizada confirmó que un `Host` inyectado no modifica el origen
  canónico. Microsoft Entra sigue sin credenciales configuradas en AWS, condición
  previa existente y no causada por esta corrección.

## SEC-011 — Métodos HTTP no utilizados reciben respuesta normal

- Severidad: **Media**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: `PUT`, `DELETE`, `PATCH` y `OPTIONS` sobre el login responden `200`,
  aunque PortalGP opera mediante `GET`, `HEAD` y `POST`; solo `TRACE` devuelve
  `405`.
- Riesgo: se mantiene una superficie HTTP innecesaria y un comportamiento
  ambiguo frente a proxys, cachés o futuros endpoints.

Solución propuesta:

1. Responder `405` en `/portalgp` para métodos distintos de `GET`, `HEAD` y
   `POST`; conservar `OPTIONS` únicamente donde una integración lo requiera.
2. Probar formularios, cargas PDF, descargas, Microsoft Entra y APIs antes de
   desplegar la restricción.

Corrección aplicada:

- el inventario de formularios, JavaScript y endpoints confirmó que PortalGP
  utiliza únicamente `GET`, `HEAD` y `POST`;
- Nginx conserva esos tres métodos y responde `405` a `PUT`, `PATCH`, `DELETE`
  y `OPTIONS` en PHP, recursos estáticos y la raíz del portal;
- `GET`, `HEAD` y `POST` sobre el login continúan respondiendo, las dependencias,
  exportaciones y PDF permanecen operativos, e `it-conecta` conserva respuesta
  `200` sin cambios funcionales.

## SEC-012 — El log de rendimiento conserva parámetros de consulta

- Severidad: **Media**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: el formato de `portalgp_timing.log` registra `$request_uri`, que
  incluye la consulta; se encontraron 328 líneas con parámetros. Además, un
  archivo rotado conserva permisos de lectura mundial `0644`.
- Riesgo: identificadores, referencias o futuros tokens en la URL podrían quedar
  disponibles en logs por más tiempo o para más usuarios de lo necesario.

Solución propuesta:

1. Registrar `$uri` sin argumentos y añadir solo campos de diagnóstico
   expresamente permitidos.
2. Normalizar logs actuales y rotados a `0640`, propiedad administrativa, y
   revisar retención y acceso.
3. Comprobar especialmente callbacks de autenticación y rutas con parámetros.

Corrección aplicada:

- el formato versionado registra `$uri` en lugar de `$request_uri`, por lo que
  conserva la ruta pero excluye toda la query string;
- una solicitud de comprobación con parámetros quedó registrada únicamente como
  `/portalgp/login.php`, sin el parámetro enviado;
- todos los archivos actuales y rotados de `portalgp_timing.log` quedaron con
  permisos `0640`, propietario `www-data` y grupo administrativo `adm`;
- se conserva la rotación diaria ya instalada por Nginx, con 14 rotaciones. Los
  registros históricos con parámetros no fueron alterados y desaparecerán con
  esa retención, pero ya no tienen lectura pública.

## SEC-013 — PHP-FPM no limita ni diagnostica solicitudes prolongadas

- Severidad: **Media**.
- Estado: **Cerrado el 8 de octubre de 2026**.
- Evidencia: el pool activo tiene `request_slowlog_timeout = 0`,
  `request_terminate_timeout = 0` y `pm.max_requests = 0`.
- Riesgo: una solicitud bloqueada o abusiva puede ocupar indefinidamente uno de
  los cinco procesos disponibles y no dejar traza suficiente para investigar.

Solución propuesta:

1. Activar un slowlog protegido para solicitudes superiores a 3 segundos.
2. Medir operaciones legítimas y luego fijar un tiempo máximo seguro —por ejemplo,
   120 segundos— sin cortar importaciones o generación de documentos.
3. Reciclar procesos con `pm.max_requests` entre 300 y 500; no aumentar todavía
   `pm.max_children` y validar rendimiento antes de endurecer el servicio systemd.

Corrección aplicada:

- se analizaron 1.182 solicitudes: percentil 95 de `5,230 s`, percentil 99 de
  `7,414 s`, máximo de `12,388 s` y ninguna solicitud superior a 30 segundos;
- el pool exclusivo de PortalGP activa slowlog desde `3 s` y termina solicitudes
  que excedan `120 s`, manteniendo un margen amplio sobre la carga observada;
- el slowlog se guarda fuera del árbol web como `root:adm` y `0640`, con rotación
  semanal y 12 archivos de retención;
- `pm.max_requests = 400` recicla procesos de forma controlada y
  `pm.max_children = 5` no fue aumentado;
- PHP-FPM, Nginx, Logrotate, PortalGP e `it-conecta` fueron verificados después
  de la recarga. El slowlog permanece vacío hasta que ocurra una solicitud real
  superior a tres segundos, que es el comportamiento esperado.

Las correcciones SEC-006 a SEC-013 fueron desplegadas hasta el commit
`97997abaa53f2ff91cf160a66a1e6f1188f41257`. `it-conecta` no recibió cambios de
código o configuración funcional; solo se añadió una ACL que impide el acceso
del usuario técnico de PortalGP a su árbol. `admin_2` y `respinoza` continúan
habilitados con rol Administrador y no se modificaron contraseñas ni permisos.

---

# 5. Auditoría del código realmente desplegado

## 5.1 Línea base y método

La revisión se realizó sobre la rama activa de AWS `codex/msp-aws-deploy`, en el
commit `97997abaa53f2ff91cf160a66a1e6f1188f41257`, con el árbol Git limpio. No se
usó la rama local `dev` como sustituto del código de producción.

Se aplicaron las siguientes comprobaciones sin registrar ni modificar datos de
negocio:

- análisis de sintaxis de 514 archivos PHP fuera de `vendor`: **0 errores**;
- `composer validate --strict`: configuración válida;
- `composer audit --locked`: **0 vulnerabilidades y 0 dependencias abandonadas**;
- revisión de las 21 alertas del informe Semgrep existente y revisión manual de
  las dos zonas donde el analizador agotó su tiempo;
- búsqueda de ejecución de comandos, deserialización insegura, inclusiones
  dinámicas, SQL construido con entrada externa, redirecciones y lecturas de
  archivos controlables por el usuario;
- revisión de autenticación, sesiones, permisos, CSRF, cargas, descargas y
  almacenamiento fuera del árbol público;
- matriz HTTP de rutas sensibles y métodos no permitidos contra AWS, sin
  autenticarse ni enviar formularios;
- contraste de las librerías de navegador declaradas con la
  [GitHub Advisory Database](https://docs.github.com/en/code-security/how-tos/report-and-fix-vulnerabilities/fix-reported-vulnerabilities/browse-advisory-database).

La ejecución nueva de Semgrep no pudo iniciarse porque Windows Application
Control bloqueó su binario. Para no eludir esa política se analizó el informe
versionado generado con Semgrep 1.176.0 y se verificaron manualmente sus rutas.
Las pruebas que podían abrir conexiones de base de datos se dejaron fuera de
esta etapa para preservar los datos reales; sí se ejecutó la prueba pura
`security_stage5_url`, que terminó correctamente.

## 5.2 Resultado resumido

Se confirmaron **cuatro hallazgos nuevos**: uno alto, dos medios y uno bajo. No
se encontró evidencia explotable de inyección SQL, ejecución remota de comandos,
SSRF, eliminación arbitraria de archivos o recorrido de rutas en los flujos
marcados por el análisis estático.

## SEC-014 — Credencial real incrustada en una maqueta versionada

- Severidad: **Alta**.
- Estado: **Confirmado; no corregido todavía**.
- Evidencia: `msp/portal_arrendatarios_mockup.html` contiene en texto claro una
  clave que coincide con una credencial real conocida de un usuario operativo.
  Nginx responde `403` al solicitar la maqueta, pero el secreto sigue presente
  en el repositorio, su historial y el sistema de archivos desplegado.
- Impacto: cualquier persona con lectura del repositorio, de una copia o del
  servidor podría reutilizar la credencial. El bloqueo web reduce exposición,
  pero no revoca el secreto.

Solución propuesta:

1. Rotar primero la credencial comprometida y comprobar el acceso de `admin_2`
   y `respinoza` sin cambiar sus roles ni permisos.
2. Retirar el valor de la maqueta y reemplazarlo por un dato ficticio inequívoco.
3. Añadir detección de secretos al control previo a Git; evaluar una limpieza
   coordinada del historial solo después de la rotación y de crear respaldo.

Corrección preparada localmente el 8 de octubre de 2026:

- se retiró la contraseña precargada de la maqueta y se sustituyó por una ayuda
  explícita que no funciona como credencial;
- la prueba `security_stage5_login` impide reintroducir una contraseña no vacía
  dentro de ese formulario de demostración;
- `composer security:precommit` ejecuta la regresión de URL canónica, login CSRF
  y credencial incrustada antes de integrar o desplegar;
- la rotación de la contraseña real y el saneamiento histórico permanecen
  pendientes; SEC-014 no puede cerrarse hasta completar ambas acciones.

## SEC-015 — El inicio de sesión local no está protegido contra login CSRF

- Severidad: **Media**.
- Estado: **Confirmado; no corregido todavía**.
- Evidencia: `login.php` no emite un token de inicio y
  `procesar_login.php` no valida token, `Origin` ni `Referer`. La sesión sí se
  regenera al autenticar y el limitador responde de forma genérica, pero esos
  controles no evitan que un tercero fuerce al navegador a iniciar una sesión
  con una cuenta controlada por el atacante.
- Impacto: confusión o sustitución de sesión de inicio; las acciones posteriores
  de la víctima podrían quedar asociadas a otra cuenta.

Solución propuesta:

1. Iniciar la sesión segura al mostrar el formulario y emitir un token de un
   solo uso destinado exclusivamente al login.
2. Validarlo obligatoriamente en `procesar_login.php` y añadir comprobación de
   mismo origen como defensa adicional.
3. Probar login local con `admin_2` y `respinoza`, cierre de sesión y Microsoft
   Entra, sin alterar contraseñas, roles o permisos.

Corrección preparada localmente el 8 de octubre de 2026:

- cada formulario recibe un token aleatorio almacenado únicamente como hash,
  válido por 15 minutos y consumido tras el primer intento;
- se conservan hasta cinco tokens para no romper formularios abiertos en varias
  pestañas y todos se eliminan después de autenticar;
- si el navegador informa `Origin` o `Referer`, debe coincidir con la URL
  canónica; su ausencia no reemplaza ni omite la validación del token;
- la regresión aislada y la prueba HTTP local confirmaron emisión del token y
  respuestas `403` para token ausente y origen externo;
- Microsoft Entra no fue modificado. La comprobación autenticada final de
  `admin_2` y `respinoza` se realizará después del despliegue coordinado.

## SEC-016 — La regresión de cargas no cubre el nuevo adjunto contractual

- Severidad: **Baja**.
- Estado: **Confirmado; el flujo actual sí está protegido**.
- Evidencia: el servicio `ContratoDocumentoService` limita a 15 MB, exige PDF,
  comprueba carga HTTP, MIME y firma, genera un nombre aleatorio, almacena fuera
  del webroot, registra SHA-256 y valida contención e integridad al descargar.
  Sin embargo, `tests/security_stage2_completion.php` solo reconoce los
  validadores anteriores y no contempla explícitamente este nuevo servicio.
- Impacto: una regresión futura en el cargador contractual podría no ser
  detectada por la puerta de seguridad automatizada.

Solución propuesta:

1. Incorporar `ContratoDocumentoService::cargar` al inventario reconocido por la
   prueba de cargas.
2. Añadir casos aislados para tamaño mayor a 15 MB, MIME/firma inválidos,
   recorrido de ruta, alteración de hash, descarga sin permiso y bloqueo después
   del término operativo.
3. Ejecutar estas pruebas como requisito previo al despliegue, usando fixtures y
   una base de pruebas, nunca la base productiva.

Corrección preparada localmente el 8 de octubre de 2026:

- el inventario reconoce ahora la carga delegada a
  `ContratoDocumentoService::cargar` y exige sus validaciones centrales;
- la validación de contenido e integridad fue separada en funciones puras sin
  relajar el requisito `is_uploaded_file` del flujo web;
- la nueva regresión usa solamente archivos temporales y comprueba 11 casos:
  PDF válido, firma/MIME, extensión, límite de 15 MB, SHA-256, alteración,
  contención de ruta, traversal, estados operativos, término y autorización;
- `composer security:precommit` incorpora la nueva regresión y no abre conexión
  con la base de datos.

## SEC-017 — El marcador de versión desplegada quedó desactualizado

- Severidad: **Media**.
- Estado: **Confirmado; no afecta la ejecución actual**.
- Evidencia: `/var/www/portalgp` está limpio en `97997ab`, pero
  `/var/lib/portalgp/deployed_commit` registra `7d5eee1`, ancestro cinco commits
  anterior. El marcador no representa la versión que realmente sirve AWS.
- Impacto: una auditoría, diagnóstico o reversión podría identificar una versión
  equivocada y omitir correcciones de seguridad ya aplicadas.

Solución propuesta:

1. Escribir el SHA en el marcador únicamente al finalizar correctamente el
   despliegue y sus comprobaciones de salud.
2. Hacer que la verificación posterior falle si `HEAD`, el marcador y la rama
   autorizada no coinciden.
3. Corregir el marcador actual mediante el procedimiento de despliegue, sin
   tocar código, base de datos, usuarios ni `it-conecta`.

Corrección preparada localmente el 8 de octubre de 2026:

- `verify_deployed_commit.sh` falla si la rama no es la autorizada, el árbol no
  está limpio, los SHA no son válidos o `HEAD` y marcador son distintos;
- `finalize_deployment.sh` exige ejecución administrativa, repositorio limpio y
  una URL HTTP(S) de salud explícita; solo después escribe el marcador de forma
  atómica y vuelve a verificar la coincidencia;
- ambos scripts superaron `bash -n` en el servidor Linux sin escribir cambios;
- se confirmó que el health check debe usar la URL pública autorizada: Nginx
  devuelve `421` a `127.0.0.1` y `200` a la IP pública por la política de Host;
- el marcador productivo seguirá desactualizado hasta ejecutar el finalizador
  como último paso del próximo despliegue.

## 5.3 Alertas descartadas después de revisión manual

- Las alertas SQL y de llamada dinámica de `msp/pagos/index.php` son falsos
  positivos: las expresiones de campos son constantes del código y los valores
  externos se envían como parámetros enlazados.
- Las alertas SSRF de `msp/catalogos/feriados.php` son falsos positivos: la URL
  es HTTPS y fija hacia `api.boostr.cl`; el único dato variable es un año
  numérico limitado entre 1900 y 2100.
- Los usos de `unlink()` borran archivos creados mediante `tempnam`, respaldos
  recién creados tras un rollback o rutas normalizadas y confinadas al
  directorio esperado; no se encontró una ruta arbitraria aportada por usuario.
- En `msp/cobros/operacion_mensual.php`, las partes variables de SQL son un
  límite convertido a entero y fragmentos constantes elegidos según la
  estructura disponible. La revisión dirigida de las zonas que agotaron el
  tiempo de Semgrep no encontró interpolación de entrada externa.
- No se encontraron llamadas activas a `eval`, `system`, `shell_exec`,
  `passthru`, `proc_open`, `popen` o `unserialize` en el código PHP desplegado
  fuera de dependencias y pruebas.
- Bootstrap 5.3.0/5.3.3, htmx 1.9.12, Chart.js 4.4.3 y Driver.js 1.3.6 no
  presentaron avisos activos en la consulta realizada. El único aviso devuelto
  para Bootstrap 4.5.2 está
  [retirado oficialmente](https://github.com/advisories/GHSA-vc8w-jr9v-vj7f)
  y esa referencia pertenece además a una pantalla heredada bloqueada.

## 5.4 Controles positivos observados en el código activo

- MSP y CT validan audiencia interna, sesión, permiso funcional y CSRF para
  solicitudes POST mediante sus bootstrap compartidos.
- Las cookies usan modo estricto, `HttpOnly` y `SameSite=Lax`; el identificador
  de sesión se regenera al autenticar y `security_version` permite revocación.
- El login conserva limitación por cuenta e IP, mensaje genérico y hash ficticio
  para evitar diferencias observables entre usuarios válidos e inválidos.
- Los endpoints protegidos redirigen al login; maquetas, Git, dependencias,
  pruebas, informes y SQL responden `403`; host inválido responde `421` y los
  métodos innecesarios responden `405`.
- Las cargas y descargas revisadas comprueban autorización, tipo, integridad y
  contención de ruta; los archivos sensibles se almacenan fuera del webroot.
- La política CSP usa nonce y no se observó exposición de versión de Nginx ni
  `X-Powered-By`.

---

# 6. Usuarios, sesiones y permisos

## 6.1 Alcance y evidencia

La revisión se efectuó sobre el código exacto desplegado en AWS, commit
`97997abaa53f2ff91cf160a66a1e6f1188f41257`, y mediante consultas agregadas y
de solo lectura a la base productiva. No se cambiaron usuarios, contraseñas,
roles, permisos ni sesiones. En particular, `admin_2` y `respinoza` continúan
habilitados y asociados al rol Administrador.

Se revisaron:

1. autenticación local y Microsoft Entra;
2. política de contraseñas, bloqueo de intentos y mensajes de error;
3. creación, almacenamiento, regeneración, expiración y destrucción de sesión;
4. revocación por estado, rol, contraseña y `security_version`;
5. autorización por lectura, escritura y eliminación;
6. gestión de usuarios, roles, permisos y departamentos;
7. unicidad e integridad de las identidades en SQL Server;
8. disponibilidad de trazabilidad sobre eventos de seguridad.

## 6.2 Estado productivo observado

| Control | Resultado |
|---|---:|
| Usuarios registrados | 19 |
| Usuarios habilitados / inhabilitados | 15 / 4 |
| Cuentas habilitadas con rol Administrador | 5 |
| Usuarios o correos duplicados | 0 / 0 |
| Contraseñas almacenadas como bcrypt | 19 de 19 |
| Roles definidos | 8 |
| Rol `Admin MSP` | 0 usuarios; 9 permisos efectivos con control total |
| Rol Administrador | 5 usuarios activos; 9 permisos MSP efectivos con control total |
| Configuración Microsoft Entra en AWS | No configurada |
| Segundo factor obligatorio | No disponible para el login local |
| Directorio de sesiones | Exclusivo, `portalgp:portalgp`, modo `700` |
| Limpieza PHP de sesiones | Activa; `gc_maxlifetime=1440` segundos |

Las tablas mantienen restricciones únicas sobre `UserName` y
`correo_electronico`, por lo que la comprobación previa de la aplicación no es
la única defensa contra identidades duplicadas. El trigger
`tr_cr_usuarios_security_version` está habilitado y aumenta la versión cuando
cambia el estado, el rol o la contraseña del usuario.

## 6.3 Controles positivos confirmados

- El login local limita intentos por cuenta y origen, usa un hash ficticio para
  usuarios inexistentes y devuelve el mismo mensaje para cuenta desconocida,
  deshabilitada o contraseña incorrecta.
- Las contraseñas nuevas deben tener entre 12 y 128 caracteres, no pueden
  contener el usuario o la parte principal del correo y se guardan con bcrypt.
- El identificador de sesión se regenera al autenticar y después de cambiar la
  propia contraseña; las cookies usan modo estricto, solo cookie, `HttpOnly` y
  `SameSite=Lax`. El atributo `Secure` permanece condicionado a resolver
  SEC-001 y habilitar HTTPS.
- Cada solicitud protegida vuelve a comprobar que el usuario esté habilitado y
  que su `security_version` coincida. Cambiar contraseña, rol o estado invalida
  las demás sesiones en la siguiente solicitud.
- La matriz efectiva de permisos se lee nuevamente en cada solicitud, por lo
  que un cambio de permisos del rol se aplica sin depender de una caché
  persistente. Escritura exige lectura y eliminación exige ambas.
- Las operaciones POST de Gestión del Sistema exigen sesión, acción autorizada
  y CSRF. Un usuario no puede deshabilitarse, eliminarse ni cambiar su propio rol
  desde esa pantalla; cambiar la contraseña propia exige la contraseña actual.
- La implementación Entra valida firma RS256, emisor, tenant, audiencia, nonce
  y vigencia, y solo asocia una identidad a un usuario interno habilitado con el
  mismo correo. Ese código existe, pero no está configurado en AWS.

## SEC-018 — La aplicación no impone expiración inactiva y absoluta de sesión

- Severidad: **Media**.
- Estado: **Corregido localmente; despliegue pendiente**.
- Evidencia: la sesión no registra hora de autenticación ni última actividad.
  AWS depende del limpiador de archivos de PHP con `gc_maxlifetime=1440`; el
  temporizador está activo, pero la limpieza es periódica y no constituye un
  límite absoluto. Durante la revisión había 11 archivos de sesión, cinco con
  más de 24 minutos y ninguno con más de 8 horas.
- Impacto: una sesión utilizada continuamente puede mantenerse sin límite y un
  identificador sustraído conserva utilidad hasta que el archivo sea limpiado,
  la cuenta cambie de versión o exista un cierre explícito.

Solución propuesta:

1. Registrar en la sesión `authenticated_at` y `last_activity` después de una
   autenticación válida.
2. Aplicar en el código un límite inactivo inicial de 60 minutos y uno absoluto
   de 12 horas, destruyendo la sesión de forma segura al excederlos.
3. Regenerar el identificador periódicamente y probar jornadas reales con
   `admin_2` y `respinoza` antes de reducir los tiempos.

Corrección preparada localmente el 8 de octubre de 2026:

- el código impone 60 minutos de inactividad, 12 horas absolutas y rotación del
  identificador cada 15 minutos, configurables dentro de límites seguros;
- las sesiones anteriores sin marcas temporales se adoptan una sola vez para no
  cerrar abruptamente el trabajo activo después del despliegue;
- cinco casos puros comprobaron vigencia, borde inactivo, límite absoluto y
  anomalías de reloj. La política todavía no está desplegada en AWS.

## SEC-019 — El cierre de sesión acepta solicitudes GET sin CSRF

- Severidad: **Baja**.
- Estado: **Corregido localmente; despliegue pendiente**.
- Evidencia: `logout.php` destruye la sesión para cualquier método y los menús
  enlazan directamente mediante `<a href="/portalgp/logout.php">`.
- Impacto: un sitio externo puede provocar el cierre involuntario de la sesión.
  No permite tomar la cuenta ni modificar datos, pero interrumpe el trabajo.

Solución propuesta:

1. Aceptar solamente `POST` en `logout.php` y exigir el token CSRF de la sesión.
2. Sustituir los enlaces de las plantillas por un formulario visualmente
   equivalente al botón actual.
3. Probar cierre normal, intento GET y navegación posterior con ambos usuarios
   operativos, sin cambiar sus credenciales ni permisos.

Corrección preparada localmente el 8 de octubre de 2026:

- `logout.php` acepta únicamente `POST`, anuncia `Allow: POST` y valida el token
  CSRF antes de destruir la sesión;
- los tres encabezados reales sustituyeron el enlace GET por un formulario con
  la misma presentación visual;
- los intentos por método incorrecto o token inválido se registran como
  denegados sin cerrar la sesión;
- la regresión estática comprueba endpoint, orden de validación y ausencia de
  enlaces GET. No se cambiaron las cuentas de `admin_2` ni `respinoza`.

## SEC-020 — Las cuentas privilegiadas dependen de contraseña sin segundo factor

- Severidad: **Alta**.
- Estado: **Mitigación portable preparada; activación Entra/MFA pendiente**.
- Evidencia: Entra no tiene tenant, cliente, secreto ni dominios configurados en
  AWS. Los 15 usuarios habilitados, incluidas cinco cuentas Administrador,
  ingresan mediante el formulario local y no existe TOTP u otro segundo factor.
- Impacto: comprometer una sola contraseña administrativa permite operar con
  permisos completos de escritura y eliminación en MSP.

Solución propuesta:

1. Resolver primero HTTPS (SEC-001) y configurar Entra en el servidor final con
   tenant específico, URI exacta y dominios autorizados.
2. Exigir MFA mediante las políticas de acceso condicional de Microsoft para
   roles privilegiados y conservar una sola cuenta local de emergencia,
   protegida y auditada.
3. Vincular y probar primero `admin_2` y `respinoza`; no deshabilitar su acceso
   local hasta confirmar inicio, permisos, cierre y procedimiento de rescate.

Corrección preparada localmente el 8 de octubre de 2026:

- una política externa permite exigir Microsoft a los roles `Administrador` y
  `Admin MSP`, con lista explícita y auditable de cuentas de emergencia;
- el código rechaza login local privilegiado al activar la política, pero deja
  el control deshabilitado por defecto para no bloquear el acceso actual;
- `security_identity_readiness.php` comprueba HTTPS, tenant, cliente, secreto,
  dominios, bitácora y cantidad de cuentas privilegiadas sin mostrar secretos;
- la activación definitiva requiere configurar Entra y MFA condicional en el
  entorno final. No se modificaron `admin_2` ni `respinoza`.

## SEC-021 — La administración de identidades no separa las funciones declaradas

- Severidad: **Media**.
- Estado: **Corregido localmente; despliegue pendiente**.
- Evidencia: poseer `Administrar Usuarios` también permite entrar a Roles y
  Permisos; `pgpCanManagePermissions()` acepta indistintamente `Administrar
  Usuarios`, `Administrar Roles`, `Administrar Permisos` o `Permisos` para
  reemplazar la matriz completa de un rol. En la base actual esas filas tienen
  valor efectivo cero, por lo que hoy la pantalla queda cerrada para todos.
- Impacto: si se concede únicamente administración de usuarios, esa persona
  podría crear roles o permisos y ampliar privilegios, contradiciendo la
  separación que expresan los nombres de los permisos.

Solución propuesta:

1. Exigir el permiso exacto para cada sección: Usuarios, Roles y Permisos.
2. Reservar el reemplazo de matrices para `Administrar Permisos`; permitir a
   `Administrar Roles` solo las operaciones de rol definidas por negocio.
3. Crear pruebas negativas de escalamiento antes de conceder estos permisos y
   mantener intactos `admin_2` y `respinoza` durante la transición.

Corrección preparada localmente el 8 de octubre de 2026:

- Usuarios exige exclusivamente `Administrar Usuarios`, Permisos exige
  `Administrar Permisos` y Roles exige `Administrar Roles`;
- reemplazar la matriz de un rol exige `Administrar Permisos`; la vista de roles
  puede ser consultada por quien administra roles o permisos, pero cada POST se
  valida según su operación real;
- la regresión confirma que no permanece el atajo heredado entre funciones.

## SEC-022 — No existe una bitácora de seguridad de autenticación e identidades

- Severidad: **Media**.
- Estado: **Corregido localmente; migración y despliegue pendientes**.
- Evidencia: `cr_login_intentos` sirve para limitación temporal y elimina el
  registro de cuenta al autenticar correctamente. No existe una tabla que
  conserve login exitoso/fallido, logout, cambio de contraseña, alta/baja de
  usuario, cambio de estado/rol o modificación de permisos con su actor.
- Impacto: ante una cuenta comprometida o un cambio indebido no es posible
  reconstruir de forma confiable quién actuó, sobre qué identidad y cuándo.

Solución propuesta:

1. Crear una bitácora append-only con fecha UTC, evento, resultado, actor,
   objetivo, referencia de solicitud y origen minimizado o seudonimizado.
2. Registrar los cambios administrativos dentro de la misma transacción,
   conservando antes/después sin contraseñas, hashes, tokens ni secretos.
3. Restringir lectura y borrado, definir retención y generar alertas para
   bloqueos reiterados, cambios de rol y cuentas administrativas nuevas.

Corrección preparada localmente el 8 de octubre de 2026:

- el parche crea `cr_seguridad_bitacora`, concede al runtime únicamente
  `INSERT` y un trigger impide `UPDATE` y `DELETE`;
- login correcto, fallido o bloqueado, logout, cambio propio de contraseña y
  altas/cambios/bajas de usuarios, roles y permisos generan eventos;
- los cambios administrativos y su evento se confirman en la misma transacción;
  fallar la trazabilidad revierte el cambio privilegiado;
- la metadata elimina contraseñas, hashes, tokens, secretos y cookies; el origen
  solo se guarda como HMAC cuando existe una clave externa;
- el parche y las pruebas se ejecutaron exclusivamente en SQL Server local: el
  runtime insertó, el trigger rechazó modificación y la transacción de prueba se
  revirtió sin dejar datos. Producción aún no fue modificada.

## SEC-023 — Cinco cuentas mantienen control total sobre todas las funciones MSP

- Severidad: **Media**.
- Estado: **Controles preventivos preparados; recertificación pendiente**.
- Evidencia: cinco cuentas habilitadas comparten el rol Administrador y sus
  nueve permisos MSP efectivos conceden lectura, escritura y eliminación. El
  rol `Admin MSP` existente no reduce ese alcance y actualmente no tiene
  usuarios.
- Impacto: cada credencial administrativa amplía la superficie desde la cual se
  pueden alterar o eliminar datos financieros y contractuales.

Solución propuesta:

1. Recertificar con el responsable del portal qué personas necesitan realmente
   eliminación, configuración, tesorería y cierre mensual.
2. Diseñar roles de operación y secretaría con las acciones mínimas necesarias;
   no reutilizar `Admin MSP` hasta reducir y probar su matriz.
3. Mantener por ahora a `admin_2` y `respinoza` sin cambios; revisar primero las
   otras cuentas y aplicar cualquier ajuste de forma individual y reversible.

Corrección preparada localmente el 8 de octubre de 2026:

- asignar un rol que tenga eliminación completa en MSP exige la acción superior
  de `Administrar Usuarios`;
- esa autorización superior se exige únicamente al otorgar o reactivar acceso
  privilegiado; editar nombre, correo o contraseña de una cuenta que ya lo
  posee no queda bloqueado por esta regla;
- cambiar rol, deshabilitar o eliminar una cuenta privilegiada no puede reducir
  el total por debajo de dos cuentas activas, valor configurable;
- el diagnóstico de identidad informa conteos por rol sin exponer correos,
  contraseñas ni secretos;
- no se reasignó ninguna de las cinco cuentas. La recertificación humana y la
  creación de roles reducidos continúan pendientes antes de cerrar SEC-023.

## 6.4 Orden recomendado de corrección

1. Desplegar primero las correcciones ya preparadas de SEC-014 y SEC-015,
   incluida la rotación pendiente de la credencial comprometida.
2. Desplegar los controles de sesión de SEC-018 y el logout POST de SEC-019;
   validar cierre normal, rechazo de GET y una jornada real.
3. Separar administración y añadir bitácora (SEC-021 y SEC-022) antes de volver
   a habilitar permisos de Gestión del Sistema.
4. Configurar Entra/MFA en el servidor final (SEC-020) y luego recertificar roles
   y cuentas (SEC-023).

No se recomienda cambiar ahora el rol, contraseña o estado de `admin_2` ni de
`respinoza`. Ninguna de las comprobaciones de esta etapa alteró sus accesos.

---

# 7. SQL Server

## 7.1 Alcance, método y límites

Revisión del 8 de octubre de 2026 sobre la base activa `PORTALGP`, endpoint
`216.155.78.65`, con consultas SELECT a catálogos y conteos agregados. La
herramienta CLI `scripts/security_sqlserver_inspect.php` carga los secretos
externos y no imprime contraseñas, hashes, contactos ni contenido financiero.

Se inspeccionaron identidad técnica, permisos efectivos, opciones del motor,
certificado, restricciones MSP, contextos de ejecución de módulos SQL,
protección de históricos, auditoría y catálogo de respaldos. No se ejecutaron
DDL, DML, procedimientos financieros, comandos del sistema operativo desde
SQL, restauraciones ni pruebas de carga. `admin_2` y `respinoza` siguen
habilitados con rol Administrador; no se modificó `it-conecta`.

La conexión utilizada para la inspección fue `database_remote.php`, identidad
SQL `portal`. **No equivale a haber comprobado la identidad actual de PHP-FPM
en AWS**: la primera lectura SSH confirmó el SHA desplegado `97997ab` y el
pool exclusivo, pero los intentos posteriores expiraron antes de leer la
configuración efectiva. Esa diferencia se mantiene como límite de SEC-024.

Tampoco se tuvo acceso administrativo al sistema operativo del host SQL,
firewall GTD, certificados del servicio, cifrado de discos o consola de copias
del proveedor. No se interpreta un catálogo vacío como prueba de ausencia de
respaldos externos, trabajos del proveedor o permisos no visibles en master.

## 7.2 Resultados comprobados

| Control | Resultado |
|---|---|
| Motor | SQL Server 2022, `16.0.1190.2`, Developer Edition |
| Identidad de la inspección | `portal`, `db_owner=1`, `CONTROL=1`, `sysadmin=0` |
| Usuario y rol portable esperado | `portalgp_runtime` y `portalgp_runtime_role` no existen en esta base |
| Configuración de conexión de inspección | `Encrypt=true`, `TrustServerCertificate=true` |
| Prueba separada con certificado obligatorio | Falla TLS/certificado: SQLSTATE `08001` |
| Confirmación desde DMV de conexiones | No disponible: permiso denegado, error 300 |
| Recuperación / verificación de páginas | `FULL` / `CHECKSUM` |
| `TRUSTWORTHY` / encadenamiento entre bases | Desactivados |
| `xp_cmdshell` | Habilitado |
| OLE Automation / consultas Ad Hoc | Desactivadas |
| Claves foráneas MSP | 205; todas habilitadas y confiables |
| Restricciones CHECK MSP | 211; todas habilitadas, tres no confiables |
| Violaciones actuales de esos tres CHECK | Cero en los tres conteos de lectura |
| Módulos SQL visibles | 141; sin `EXECUTE AS` explícito ni propietario |
| Referencias a ejecución externa en esos módulos | No se encontraron `xp_cmdshell`, `OPENROWSET` u `OPENQUERY` |
| Servidores vinculados visibles | Cero; visibilidad limitada por permisos |
| Bitácora de seguridad SEC-022 | Todavía no instalada en producción |
| SQL Audit de base | Cero especificaciones en PORTALGP |
| TDE / cifrado nativo de respaldos registrados | No habilitado / no registrado |
| Historial de copias de PORTALGP | 27 completas: 21 regulares y seis COPY_ONLY |
| Última copia completa visible | 8 de octubre de 2026; coincide con el punto 2.3 |
| Última copia completa regular visible | 3 de agosto de 2026 |
| Diferenciales / logs en el historial consultado | No aparecen |

La fecha de `msdb` corresponde al reloj del servidor SQL; el respaldo de hoy
es el ya documentado en el punto 2, no un respaldo generado por esta auditoría.
El indicador `RTM` no significa motor sin parches: `16.0.1190.2` corresponde
al GDR de julio de 2026.

## SEC-024 — Separación de identidad técnica pendiente en la base activa

- Severidad: **Alta** si la aplicación utiliza la cuenta administrativa.
- Estado: **Mitigaciones de conexión desplegadas; identidad AWS confirmada como db_owner; separación técnica abierta**.
- Evidencia: `portal` pertenece a `db_owner`, tiene `CONTROL`, `ALTER`, creación
  de tablas, cambios de roles/usuarios y escritura sobre todas las tablas
  comprobadas. El usuario y rol `portalgp_runtime` no existen en PORTALGP.
- Actualización del 9 de octubre: la configuración del pool exclusivo no
  sobrescribe las variables SQL. El diagnóstico ejecutado como `portalgp` en
  AWS carga el mismo secreto externo y confirma `portal`, con `db_owner=1`.
- Impacto: usar esta identidad en el proceso web permitiría que una intrusión
  alterara todo PORTALGP, incluidos otros módulos y sus controles de auditoría.

Solución propuesta:

1. Por instrucción del 9 de octubre, no crear cuentas ni cambiar permisos de
   `admin_2`/`respinoza`; no ejecutar el aprovisionador ni los parches de roles.
2. Aplicar defensas portables de conexión: valores DSN validados, cifrado remoto
   obligatorio, credenciales fuera del DSN y trazas ODBC desactivadas.
3. Conservar la identidad efectiva verificada de AWS como evidencia. Mantener
   abierta la separación técnica: estas defensas no eliminan `db_owner` ni
   impiden una consulta arbitraria si el proceso web llega a ser comprometido.

El parche portable todavía clasifica objetos `cr_`, `msp_` y `ct_` por prefijo;
esa clasificación requiere inventario antes de activar una identidad nueva.
En esta ejecución se corrigió la brecha de reaplicación: excluye expresamente
`cr_seguridad_bitacora` del cursor genérico, conserva INSERT y aplica DENY de
SELECT/UPDATE/DELETE, igual que el instalador de la bitácora. Conserva los DENY
de históricos. El trigger inmutable es defensa adicional, no sustituto del
mínimo privilegio. No se ejecutó ese parche sobre la base activa.

Fuente: [Microsoft: roles de base de datos](https://learn.microsoft.com/en-us/sql/relational-databases/security/authentication-access/database-level-roles).

## SEC-025 — El endpoint SQL no supera la validación de certificado

- Severidad: **Alta**.
- Estado: **Confirmado también en AWS; HTTPS web corregido, validación de identidad SQL pendiente**.
- Evidencia: la conexión configurada acepta cualquier certificado SQL. Una
  conexión separada con `Encrypt=1;TrustServerCertificate=0` falla con error
  de certificado/TLS; la configuración activa no fue alterada.
- Impacto: cifrar sin autenticar el servidor no garantiza que el interlocutor
  sea el SQL Server legítimo. No se comprobó un ataque de intermediario.

Solución propuesta:

1. No se exige comprar dominio ni crear DNS para AWS. HTTPS del portal puede
   usar un certificado público para su IP, con renovación automática corta.
   Esto no autentica al servidor SQL, situado en otra IP y otro host.
2. Para SQL, validar una cadena de confianza y una identidad cubierta por el
   certificado (IP en SAN o nombre existente); nunca confiar automáticamente
   en un certificado obtenido por una conexión no autenticada.
3. Activar `TrustServerCertificate=false` y el entorno productivo solo tras
   aprobar la conexión; no cambiarlo ahora, pues la prueba confirmó que fallaría.

La condición de `db.php` depende de `PORTALGP_ENV`: el despliegue definitivo
debe exigir una configuración explícita, evitando conservar los defaults de
desarrollo. El certificado HTTPS del sitio y el del servicio SQL son distintos.

Fuente: [Microsoft: opciones de conexión PHP](https://learn.microsoft.com/en-us/sql/connect/php/connection-options).

## SEC-026 — Ejecución del sistema operativo habilitada mediante xp_cmdshell

- Severidad: **Alta**.
- Estado: **Configuración confirmada; uso y permisos completos pendientes del DBA**.
- Evidencia: `sys.configurations` devuelve `xp_cmdshell.value_in_use=1`.
  No se encontró ninguna llamada en los 141 módulos visibles de PORTALGP ni
  concesión explícita visible a `public` en master.
- Límite: no se ejecutó `xp_cmdshell`; la cuenta no es sysadmin y su permiso
  efectivo EXECUTE en master es 0. La configuración afecta a toda la instancia
  SQL; los jobs/proxies no son visibles con los permisos actuales.
- Impacto: amplía la superficie para ejecutar comandos con una identidad del
  sistema operativo si se compromete una cuenta con permiso suficiente.

Solución propuesta:

1. Inventariar mediante SELECT los jobs, proxies, permisos efectivos y módulos
   visibles sin preguntar previamente al DBA ni ejecutar `xp_cmdshell`.
2. Sustituir esos usos por tareas externas con identidad limitada y desactivar
   `xp_cmdshell` cuando se haya comprobado su compatibilidad.
3. Mantener cualquier excepción temporal con permisos mínimos y auditoría.

Fuente: [Microsoft: configuración xp_cmdshell](https://learn.microsoft.com/en-us/sql/database-engine/configure-windows/xp-cmdshell-server-configuration-option).

Actualización del 9 de octubre: inventario automático ejecutado. La cuenta
inspeccionada tiene EXECUTE efectivo **0** sobre `sys.xp_cmdshell`. Hay 144
bases de usuario visibles, solo una accesible; PORTALGP contiene 141 módulos
con definición visible y cero referencias directas. La consulta a jobs,
proxies y asignaciones en msdb devuelve permiso denegado (229). Cero credenciales
proxy visibles no demuestra ausencia. No se amplían permisos para completar
la auditoría y no se desactiva una función global con ese inventario incompleto.

## SEC-027 — Continuidad de respaldos y recuperación puntual sin acreditar

- Severidad: **Alta**.
- Estado: **Brecha de evidencia confirmada; automatización externa pendiente de revisión**.
- Evidencia: PORTALGP usa `FULL`; msdb contiene 27 copias completas, pero
  ninguna diferencial ni de log. La última completa regular visible es del
  3 de agosto; las seis posteriores son COPY_ONLY, incluida la de hoy.
  Seis copias tienen checksum; 21 no lo tienen.
- Límite: no se ha verificado el sistema de respaldo del proveedor; el
  historial de msdb puede ser purgado. No se concluye que no existan otras copias.
- Impacto: no está demostrado que el trabajo diario pueda recuperarse entre
  copias completas ni que haya alertas ante fallos del respaldo habitual.

Solución propuesta:

1. Confirmar con el DBA/proveedor tareas, retención, última ejecución y copias
   externas; acordar el máximo de información que puede perderse.
2. Establecer completos regulares con checksum y, si se conserva FULL, una
   cadena de logs con frecuencia acorde a ese límite y alertas por fallo.
3. Restaurar una copia aislada y comprobar integridad antes de dar por
   certificada la recuperación; mantener el destino externo cifrado.

No sustituir la política regular por copias COPY_ONLY ni cambiar el modelo de
recuperación sin analizar dependencias. `RESTORE VERIFYONLY` sigue pendiente
desde el punto 2 y no reemplaza una restauración de prueba.

Fuente: [Microsoft: RESTORE VERIFYONLY](https://learn.microsoft.com/en-us/sql/t-sql/statements/restore-statements-verifyonly-transact-sql).

## SEC-028 — Tres reglas de integridad no validaron los datos históricos

- Severidad: **Media**.
- Estado: **Confirmado; sin violaciones actuales detectadas**.
- Evidencia: `is_disabled=0` e `is_not_trusted=1` en:
  `CK_msp_contratos_fechas`, `CK_msp_tesoreria_cierre_estado` y
  `CK_msp_correcciones_estado_v2`. Sus migraciones usan `WITH NOCHECK`.
  Los conteos con los predicados exactos devolvieron cero infracciones.
- Impacto: los nuevos cambios sí se validan, pero no está certificada en el
  catálogo la conformidad de todas las filas existentes; el optimizador
  tampoco puede aprovechar la garantía histórica de esas restricciones.

Solución propuesta:

1. Preparar un parche solo para esas tres reglas, repetir el prechequeo y
   validar en una copia con `WITH CHECK CHECK CONSTRAINT`.
2. Aplicarlo en una ventana breve y confirmar `is_not_trusted=0`; si aparece
   alguna infracción, detenerse y revisar el caso sin borrar ni ajustar datos.

Fuente: [Microsoft: restricciones y confianza](https://learn.microsoft.com/en-us/sql/relational-databases/tables/disable-foreign-key-constraints-with-insert-and-update-statements).

## SEC-029 — Actualización de seguridad SQL posterior a la instalada

- Severidad: **Media**.
- Estado: **Confirmado mediante versión y catálogo oficial**.
- Evidencia: el motor está en `16.0.1190.2` (GDR julio de 2026). El catálogo
  oficial consultado el 8 de octubre publica `16.0.1200.5` (GDR septiembre de
  2026) para la misma rama. No se atribuye ninguna CVE concreta sin evaluación.
- Impacto: faltan correcciones de seguridad posteriores a la versión instalada.

Solución propuesta:

1. El DBA debe revisar el GDR aplicable y su compatibilidad con toda la
   instancia, disponer de restauración comprobada y acordar la ventana.
2. Instalar la actualización, confirmar versión y verificar conectividad y
   operaciones de todos los consumidores, incluidos PortalGP y sistemas ajenos.

Fuente: [Microsoft: versiones y actualizaciones SQL](https://support.microsoft.com/en-us/servicing/sql/kb321185-download-and-install-latest-updates).

## SEC-030 — Cifrado de datos y respaldos en reposo sin acreditar

- Severidad: **Media**.
- Estado: **TDE y cifrado nativo ausentes; protección de almacenamiento en revisión**.
- Evidencia: `is_encrypted=0` y ninguna de las 27 copias registradas declara
  cifrado nativo. No se inspeccionaron BitLocker, discos del proveedor ni ACL.
- Impacto: una copia de MDF/LDF o BAK podría exponer información si no está
  protegida por otra capa de cifrado y control de acceso.

Solución propuesta:

1. Documentar cifrado de discos, permisos y destino externo antes de considerar
   los datos desprotegidos; elegir la capa adecuada con el administrador.
2. Cifrar las copias y custodiar las claves por separado; si se adopta TDE,
   respaldar certificado/clave y probar restauración fuera del servidor.

## 7.3 Observación de edición y controles positivos

`SERVERPROPERTY('Edition')` informa Developer Edition. Es un punto de adecuación
del entorno que debe revisar el responsable de licenciamiento: Microsoft la
destina a desarrollo y pruebas. No se clasifica como una vulnerabilidad técnica
ni se propone cambiar de edición automáticamente en una instancia compartida.
Fuente: [Microsoft: ediciones SQL Server 2022](https://learn.microsoft.com/en-us/sql/sql-server/editions-and-components-of-sql-server-2022).

Controles favorables comprobados:

- checksum de páginas habilitado; TRUSTWORTHY y encadenamiento entre bases
  desactivados;
- 205 relaciones MSP activas y confiables, sin CHECK deshabilitados;
- trigger de revocación de sesiones y trigger del histórico de saldo a favor
  habilitados;
- módulos SQL visibles sin contexto explícito de propietario ni llamadas a
  ejecución externa detectadas;
- consultas e identificadores dinámicos revisados en DocumentoProteccionService,
  Ficha360Service y helpers SQL emplean parámetros o nombres internos validados;
  esta revisión dirigida no certifica la ausencia de SQL injection en todo el portal.

Pendientes de cierre: identidad efectiva de AWS, reglas del firewall SQL,
permisos en master y otros consumidores, cifrado de discos, trabajos del
proveedor, restauración aislada y pruebas de concurrencia en una copia. No se
requiere dar sysadmin a la conexión web para obtener esas evidencias: deben ser
aportadas por el administrador.

## 7.4 Orden de corrección propuesto

1. Acreditar respaldos y restauración (SEC-027).
2. Verificar la conexión AWS y preparar la identidad mínima (SEC-024).
3. Resolver confianza del certificado y validar TLS (SEC-025).
4. Coordinar con el DBA xp_cmdshell y actualización del motor (SEC-026/029).
5. Validar las tres restricciones y protección en reposo (SEC-028/030).

Esta etapa entregó diagnóstico y soluciones. No aplicó cambios a SQL Server ni
desplegó las correcciones locales de etapas anteriores.

## 7.5 Ejecución de altas sin coordinación externa — 8 de octubre de 2026

El usuario solicitó posponer lo que requiera DBA/proveedor e iniciar las altas.
Se implementó la parte portable de SEC-024, sin declarar resuelta la separación
de identidad técnica en producción:

- `patch_seguridad_runtime_objetos.sql` y
  `patch_seguridad_identidades_bitacora.sql` mantienen INSERT para la bitácora
  y deniegan SELECT/UPDATE/DELETE; reaplicar el parche genérico ya no debe
  devolver esos accesos. No se alteraron los permisos de usuarios funcionales.
- `scripts/provision_runtime_database.php` requiere `--apply`, comprueba
  privilegios administrativos y rechaza identidad, rol o secreto ya existentes.
  No rota ni habilita logins existentes. Guarda el candidato en
  `database_runtime.php`, sin sustituir `database.php` ni activar la conexión.
  Verifica DDL mediante consultas de permisos, sin crear una tabla de prueba
  en la base activa. No se ejecutó su modo de aplicación.
- `tests/security_runtime_objects.php` incorpora los DENY de la bitácora;
  la nueva prueba de código `tests/security_stage7_runtime.php` pasa 19
  comprobaciones y forma parte de `composer security:precommit`.
- La prueba SQL `tests/security_stage7_runtime_db.php --isolated-db` está
  limitada a un SQL local y una base desechable generada: comprueba ambos
  órdenes de instalación, reaplicación, permisos efectivos e históricos,
  sin copiar datos reales. **La ejecución quedó SKIP** por conexión
  administrativa local no disponible (`08001`); no acredita prueba SQL aprobada.
- Pasaron lint PHP, la suite de regresión de código y `git diff --check`.
  Ejecutar el aprovisionador sin `--apply` confirmó salida sin conexión ni
  modificaciones. El intento SSH a AWS expiró; no se verificó su identidad SQL.

Pendientes registrados, sin ejecutar cambios globales:

| Hallazgo | Pendiente / motivo |
|---|---|
| SEC-024 — Alta condicional | Verificar identidad AWS, inventariar permisos, probar SQL aislado y crear/activar la cuenta técnica con el administrador. No modificar la cuenta compartida `portal`. |
| SEC-025 — Alta | Certificado y cadena de confianza SQL con el administrador; mantener conexión actual hasta que la validación estricta funcione. |
| SEC-026 — Alta | Inventario de dependencias y eventual desactivación de xp_cmdshell por el DBA; afecta a toda la instancia. |
| SEC-027 — Alta | Confirmación de backups, retención, copias externas y restauración con DBA/proveedor. |
| SEC-028 — Media | Validación de los tres CHECK en copia y ventana posterior; no ejecutada al priorizar altas. |
| SEC-029 — Media | Parches SQL coordinados con DBA y todos los consumidores. |
| SEC-030 — Media | Acreditar cifrado de discos/copias y custodia de claves con el administrador. |

No hubo commit, despliegue, cambios de cuentas, configuración AWS, `it-conecta`
ni modificaciones a SQL Server remoto en esta ejecución. Las altas externas
siguen abiertas; preparar un parche no equivale a corregir producción.

## 7.6 Continuación SEC-024/025/026 con las condiciones del usuario — 9 de octubre

### SEC-024: defensas de código sin nuevas cuentas ni permisos alterados

Se incorporó `database_connection.php` al punto de conexión `db.php`:

- rechaza separadores DSN y caracteres de control en servidor/base para evitar
  que un valor de configuración añada opciones de autenticación o TLS;
- exige cifrado para conexiones remotas incluso si `PORTALGP_ENV` se dejó como
  desarrollo; las conexiones locales explícitas siguen compatibles;
- conserva la regla productiva que exige validación del certificado y no
  cambia la excepción actual de compatibilidad mientras el certificado falla;
- conserva usuario/contraseña existentes fuera del DSN, fija `APP=PortalGP`
  como etiqueta diagnóstica y desactiva `TraceOn`. La etiqueta APP no otorga
  permisos ni autentica a un usuario.

No se ejecutó `provision_runtime_database.php --apply`, ni CREATE USER/LOGIN,
GRANT, REVOKE o cambios de rol. Los parches de permisos preparados el día 8 no
se aplicaron a producción. El diagnóstico confirma ambas cuentas operativas
activas con rol Administrador; no se tocaron contraseñas o asignaciones.
La identidad administrativa `portal` y su `db_owner` permanecen; no se declara
cerrada la separación técnica ni se presenta esta mitigación como equivalente.

### SEC-025: HTTPS por IP viable, instalación pendiente de acceso AWS

La consulta oficial vigente confirma certificados para IPv4/IPv6 sin dominio;
Certbot >=5.4 admite emisión mediante webroot y perfil `shortlived` (160 horas,
aproximadamente seis días). Requiere renovación automática y recarga controlada.
No se generó un certificado autofirmado para simular un HTTPS confiable.

Secuencia prevista, solo en el virtual host de PortalGP:

1. Inspeccionar la configuración real de Nginx, puerto 443, Certbot y timer;
   conservar copia de sus archivos y servir el desafío ACME desde un webroot
   exclusivo (`/var/lib/portalgp-acme`), sin detener Nginx ni editar `it-conecta`.
2. Probar emisión staging con `certbot certonly --staging
   --preferred-profile shortlived --webroot
   --webroot-path /var/lib/portalgp-acme --ip-address 15.229.113.179`;
   emitir después el certificado público sin `--staging`. Certbot certonly no
   instala automáticamente el certificado en Nginx.
3. Habilitar HTTPS para `/portalgp`, actualizar su URL canónica y probar login,
   formularios, descargas y cookies; activar redirección solo de PortalGP tras
   la verificación y comprobar renovación con un deploy-hook protegido.

Esta secuencia no se ejecutó: SSH expira y la herramienta de consola del
navegador no pudo iniciar. HTTP del login respondió 200; conexión al puerto 443
falló desde este equipo. No se cambiaron firewall, Nginx ni otros consumidores.

La prueba independiente SQL `Encrypt=1;TrustServerCertificate=0` volvió a
fallar (`08001`, error de certificado). La conexión de diagnóstico sí mantiene
cifrado. Emitir HTTPS para `15.229.113.179` no corrige el certificado SQL de
`216.155.78.65`: su validación sigue pendiente, sin exigir un dominio AWS.

Fuentes: [Let's Encrypt: disponibilidad de certificados IP](https://letsencrypt.org/2026/01/15/6day-and-ip-general-availability),
[Let's Encrypt: Certbot y certificados IP](https://letsencrypt.org/2026/03/11/shorter-certs-certbot),
[Microsoft: opciones de conexión](https://learn.microsoft.com/en-us/sql/connect/php/connection-options).

### SEC-026: inventario sin pedir información ni alterar la instancia

`scripts/security_sqlserver_inspect.php --config=database_remote.php
--xp-inventory` consulta catálogos de master/msdb y módulos de las bases
accesibles. Solo informa conteos/permisos; nunca imprime cuerpos de comandos
de jobs, que podrían contener secretos. Quedó ejecutado y registrado arriba.
Devuelve expresamente `safe_to_disable=false`: no confunde falta de visibilidad
con inexistencia de consumidores. No ejecuta jobs, xp_cmdshell o sp_configure.

### Verificaciones y límites

- Suite `security:precommit` aprobada, incluidas 18 pruebas puras nuevas de
  conexión y las 19 pruebas previas del parche runtime; lint y diff-check OK.
- La suite SQL/local `security_stage3_completion.php` no logró conectar al SQL
  local (`08001`, Named Pipes 5): no se considera prueba aprobada aunque el
  manejador de `db.php` termine con código de proceso 0.
- Consulta SQL remota de solo lectura y pruebas HTTP/HTTPS ejecutadas. Sin
  datos de prueba, respaldos nuevos, correos, cuentas ni cambios globales.
- Sin commit ni despliegue: las defensas nuevas están preparadas localmente.
  Requiere recuperar acceso administrativo AWS antes de instalar HTTPS y
  desplegar; la investigación global SQL está limitada por permisos existentes.
- SEC-027/028/029 no se ejecutaron en este bloque; SEC-030 sigue pospuesta.

Reintento de acceso del 9 de octubre, tras confirmar el usuario que AWS estaba
abierto en el navegador integrado: la herramienta falló antes de leer una
página (`windows sandbox failed: setup refresh had errors`), incluso tras
reiniciar su sesión. SSH volvió a expirar y no hay AWS CLI instalado. No es
evidencia de credenciales AWS inválidas; es una limitación de la herramienta y
de conectividad administrativa. No se modificó producción ni se instalaron
certificados. Para recuperar SSH, usar la consola autorizada y permitir solo
la IP administrativa comprobada en las capas de firewall pertinentes, nunca
abrir el puerto 22 globalmente ni retirar la autenticación por llave.

---

## 7.7 Despliegue autorizado SEC-024/025/026 — 9 de octubre de 2026

El usuario autorizó comitear y desplegar exclusivamente este bloque y aceptó
expresamente el acuerdo de suscriptor de Let's Encrypt. Se utilizó un checkout
aislado desde el SHA productivo `97997ab`; no se incluyeron los demás cambios
ejecutables locales de etapas 5/6, FTE, permisos runtime o aprovisionamiento.
El documento único conserva el historial de auditoría, no certifica el
despliegue de todas las correcciones mencionadas en sus secciones anteriores.

### Código y configuración publicados

- `10859eb`: helper de DSN/TLS, punto de conexión, diagnóstico CLI de solo
  lectura y prueba de 18 comprobaciones. No DDL, DML, cuentas o roles nuevos.
- `28cee24`: activación HTTPS verificada antes de redirigir, URL canónica del
  pool PortalGP, hook de renovación y verificador sin `curl -k`.
- Rama productiva: `codex/msp-aws-deploy`; pull fast-forward, árbol limpio y
  registro de SHA en `/var/lib/portalgp/deployed_commit` comprobados.
- La cuenta SQL efectiva conserva `portal` y `db_owner`; el secreto declara
  `environment=staging`, `encrypt=true`, `trust_server_certificate=true`.
  No se cambió a producción estricta porque la prueba TLS SQL todavía falla
  con `08001` / error de certificado. No confundir HTTPS web con TLS SQL.

### HTTPS de la IP, sin dominio ni DNS

- Emisión staging/dry-run aprobada y certificado público emitido con Certbot
  5.8.0, webroot `/var/www/letsencrypt` y perfil `shortlived`.
- Emisor Let's Encrypt YE1; SAN `IP Address:15.229.113.179`; vigencia inicial
  del 9 de octubre 12:15:50 UTC al 16 de octubre 04:15:49 UTC (160 horas).
- `https://15.229.113.179/portalgp/login.php` responde 200 con validación de CA
  e IP tanto desde Ubuntu como desde el PC Windows, sin omitir verificación.
- HTTP de PortalGP responde 308 hacia la misma ruta HTTPS. El servidor TLS
  separado no publica it-conecta; `/` HTTP de it-conecta mantiene respuesta 200.
- URL canónica del pool: `https://15.229.113.179`. Se conservan los límites,
  usuario, socket y almacén de sesiones anteriores; no se cambió el pool `www`.
- Certbot utiliza `snap.certbot.renew.timer`, activo, y un deploy-hook root
  que valida Nginx y lo recarga solo si PortalGP usa el certificado renovado.
  Se registró ACME sin correo; requiere supervisar caducidad/timer, no esperar
  avisos por email. No se enviaron correos de prueba.
- Renovación simulada aprobada el 9 de octubre con `--dry-run
  --run-deploy-hooks`; hook ejecutado y `nginx -t` aprobado. El mensaje
  «error output» de Certbot recoge la salida normal de Nginx por stderr:
  el proceso terminó con código 0 y todas las renovaciones simuladas exitosas.
  Timer activo, próxima ejecución observada 13:55 UTC. Las pruebas manuales
  futuras pueden omitir la espera aleatoria con `--no-random-sleep-on-renew`;
  el temporizador conserva su distribución normal para la renovación real.

### Regresión no destructiva y respaldo previo

- PHP lint y 18 pruebas puras de conexión aprobadas en ambos equipos; bash
  syntax-check, `nginx -t` y `php-fpm8.3 -t` aprobados antes de recargas.
- Login GET/HEAD 200; MSP y recepción de garantías GET anónimos responden 303
  hacia login HTTPS. HEAD protegido responde 401, sin conceder acceso.
- Cookies de sesión con `Secure`, `HttpOnly`, `SameSite=Lax`; HSTS y CSP
  presentes. TRACE 405 y Host no autorizado 421.
- Nueve verificaciones de endpoints pasaron: login 200 y ocho artefactos
  internos 403; la cabecera Server no expone versión exacta.
- Las cuentas `admin_2` y `respinoza` siguen activas con rol Administrador.
  No se modificaron contraseñas ni permisos. No se efectuó login con sus claves
  ni se registraron operaciones de negocio para probarlas.
- Los SHA-256 de `/etc/nginx/sites-available/it-conecta`, pool `www` y snippet
  `portalgp-app.conf` son idénticos antes/después; HTTP it-conecta sigue 200.
  Nginx y PHP-FPM activos, sin reiniciar servicios ni alterar esa aplicación.
- Respaldo previo: `/var/backups/portalgp/security-preflight-20261009T131410Z`.
  Runtime, almacenamiento MSP, secretos separados y configuración; hashes y
  extracción aislada verificados. No se publicó ni se descargó el archivo de
  secretos. **No es una nueva copia SQL ni cierra SEC-027.**
- El inventario SEC-026 confirma permiso EXECUTE 0, 144 bases visibles y solo
  PORTALGP accesible, 141 módulos sin referencias directas; jobs/proxies de
  msdb devuelven 229. `xp_cmdshell` sigue habilitado globalmente: no se desactiva
  con un inventario incompleto ni se amplían permisos para poder auditarlo.

SEC-024 permanece parcial; SEC-025 SQL permanece abierto. SEC-001 web queda
cerrado en AWS, sujeto a revalidación completa al migrar. SEC-026 no se declara
cerrado a nivel instancia. SEC-027/028/029 no se implementaron en este bloque;
SEC-030 sigue pospuesto. El respaldo previo no es evidencia de backup automático.

---

# Hallazgos detectados durante la definición del alcance

## SEC-001 — PortalGP transmite información real por HTTP

- Severidad: **Alta**.
- Estado: **Cerrado en AWS el 9 de octubre; revalidar en el servidor definitivo**.
- Evidencia inicial: el sitio respondía en 80 sin servicio en 443. Evidencia actual: certificado público IP válido, HTTPS 200, HTTP 308 exclusivo de PortalGP y cookies Secure; detalle en 7.7.
- Impacto: un tercero con visibilidad de red podría interceptar o alterar sesiones, credenciales e información personal/financiera.

Solución propuesta:

1. Asignar un nombre DNS al servidor definitivo y emitir un certificado confiable.
2. Habilitar HTTPS, redirigir HTTP y verificar `Secure`, HSTS y enlaces absolutos.
3. Repetir login, Microsoft Entra, cargas, PDF y correos en el entorno final.

## SEC-002 — Nginx publica artefactos internos que Apache bloqueaba

- Severidad: **Alta**.
- Estado: **Cerrado**.
- Evidencia: `.git` y `tests` responden `403`, pero `composer.json`, `composer.lock`, `semgrep-msp.json`, informes de seguridad `.md` y scripts de base `.sql` responden `200`. La auditoría local anterior había obtenido `403` para 202 de 202 archivos de este tipo bajo Apache.
- Causa: Nginx no interpreta `.htaccess`; el *snippet* actual solo bloquea maquetas `mockup/prototype` y archivos ocultos mediante la regla general del servidor.
- Impacto: se divulgan dependencias, estructura de base, controles de seguridad, rutas y detalles internos que facilitan ataques dirigidos.

Solución propuesta:

1. Añadir denegaciones específicas dentro de `/portalgp` para `tests`, `scripts`, `config`, `del`, artefactos de repositorio y extensiones internas.
2. Validar `nginx -t`, recargar Nginx y comprobar una matriz de respuestas `403` sin tocar `it-conecta`.
3. Mantener esta comprobación como prueba automática de despliegue.

Corrección aplicada el 8 de octubre de 2026:

- reglas portables guardadas en `deploy/nginx/`;
- despliegue limitado a las ubicaciones de PortalGP;
- matriz HTTP verificada: `.git`, `tests`, `composer.json`, `composer.lock`,
  `semgrep-msp.json`, `.md`, `.sql` y `.pem` responden `403`;
- commit activo verificado: `81523fb5fd2b113e92c9102891fa6177daebe79c`.

## SEC-003 — Configuración FTE con credenciales bajo el árbol web

- Severidad: **Alta**.
- Estado: **Confirmado**. Se deja pendiente para la etapa final solicitada.
- Evidencia: `rrhh/fte/fte_config.local.php` existe tanto en la copia local como en `/var/www/portalgp`; está ignorado por Git y Nginx lo ejecuta como PHP, pero contiene credenciales en texto claro dentro del directorio público. El resto del sistema ya dispone de `/var/portalgp_secrets` fuera del árbol web.
- Impacto: una falla de PHP/Nginx, copia de respaldo, error de publicación o acceso al servidor podría exponer credenciales de Buk/GeoVictoria.

Solución propuesta:

1. Adaptar FTE para cargar sus secretos desde `/var/portalgp_secrets` o variables de entorno.
2. Retirar el archivo local del árbol web y rotar las credenciales externas involucradas.
3. Verificar conexión, lotes, caché y logs sin imprimir secretos.

## SEC-004 — Nginx publica su versión exacta

- Severidad: **Baja**.
- Estado: **Cerrado**.
- Evidencia: las respuestas incluyen `Server: nginx/1.24.0 (Ubuntu)`; PHP sí oculta `X-Powered-By`.
- Impacto: facilita reconocimiento técnico, aunque no crea por sí solo una vía de acceso.

Solución propuesta:

1. Aplicar `server_tokens off` en un contexto que no altere indebidamente `it-conecta`, o dejarlo para el servidor definitivo.
2. Verificar que solo se publique `Server: nginx` y mantener el software actualizado.

Corrección aplicada el 8 de octubre de 2026: las respuestas de PortalGP ya
publican únicamente `Server: nginx`; no se modificó la aplicación `it-conecta`.

## SEC-005 — Ramas local y de despliegue divergentes

- Severidad: **Media**.
- Estado: **Cerrado**.
- Evidencia: producción usa `codex/msp-aws-deploy` en el commit `4d1182c3...`; la copia local está en `dev` y las historias presentan commits exclusivos en ambos lados.
- Impacto: una corrección de seguridad puede quedar fuera de producción o perderse al fusionar/desplegar manualmente.

Solución propuesta:

1. Definir una rama fuente y un procedimiento único para integrar, probar y desplegar.
2. Publicar siempre el SHA desplegado y rechazar despliegues con cambios locales.
3. Incorporar una verificación posterior que compare commit, configuración y pruebas mínimas.

Corrección aplicada el 8 de octubre de 2026:

- AWS se despliega exclusivamente desde `codex/msp-aws-deploy`;
- el script rechaza otra rama o un árbol con cambios locales;
- cada despliegue registra el SHA en `/var/lib/portalgp/deployed_commit`;
- la verificación posterior informa rama, SHA, limpieza y matriz HTTP;
- las plantillas de infraestructura quedan versionadas, sin secretos.

---

# Controles positivos ya comprobados en AWS

- `.git`, `.gitignore` y endpoints de prueba revisados responden `403`.
- `TRACE` responde `405`.
- `/info.php` ya no está publicado.
- PHP oculta `X-Powered-By`.
- CSP estricta con nonce está activa.
- Se entregan `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, COOP y CORP.
- Las respuestas PHP sensibles usan `Cache-Control: no-store`.
- Nginx agrega un identificador de solicitud y mantiene un log temporal específico para PortalGP.
- Los artefactos internos comprobados responden `403` y la versión exacta de
  Nginx dejó de publicarse.
- Los adjuntos MSP y los secretos generales disponen de rutas fuera del árbol web.
- El árbol Git remoto estaba limpio durante esta revisión.

Estos controles son evidencia favorable, pero se volverán a validar durante las etapas específicas y nuevamente en el servidor final.

---

# Índice de etapas

| Punto | Tema | Estado |
|---:|---|---|
| 1 | Alcance exacto | Completado |
| 2 | Recuperación y puntos de restauración | Protección creada; verificación SQL administrativa pendiente |
| 3 | Línea base del despliegue AWS | Pendiente |
| 4 | Sistema operativo y servidor web | Completado: SEC-006 a SEC-013 cerrados |
| 5 | Auditoría del código realmente desplegado | Completado: SEC-014 a SEC-017 confirmados |
| 6 | Usuarios, sesiones y permisos | Auditados; SEC-018 a SEC-023 preparados localmente, Entra y recertificación pendientes |
| 7 | SQL Server | Auditado con límites de evidencia; SEC-024 a SEC-030 registrados |
| 8 | Integridad financiera y concurrencia en copia | Pendiente |
| 9 | Archivos, PDF, importaciones y correo | Pendiente |
| 10 | DAST aislado y regresión | Pendiente |
| 11 | Monitoreo, respaldo y respuesta | Pendiente |
| 12 | Validación del servidor definitivo y migración | Pendiente |

## Próximo punto

Los puntos 5, 6 y 7 tienen sus hallazgos registrados. Las correcciones portables
de etapas 5/6 siguen locales; el bloque específico de conexión y HTTPS de 7.7
sí fue publicado y desplegado. No se equiparan mitigaciones con cierres SQL.
La identidad efectiva AWS está confirmada; acreditar continuidad de respaldos.
La restauración SQL aislada y la copia
externa permanecen pendientes; los cambios de instancia deben coordinarse con
el DBA para preservar otros consumidores y ambas cuentas operativas.

## Avance de SEC-001

Certificado IP público emitido, instalado y verificado con autorización del
usuario. HTTPS y redirección exclusivos de PortalGP activos; URL canónica
actualizada, temporizador y hook de renovación configurados. Evidencia y límites
en 7.7. No se equipara esta corrección con validar el certificado de SQL Server.
