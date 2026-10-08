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

## 1.2 Línea base exacta del despliegue temporal

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
| Commit desplegado | `6625cf107adbb96aa7729baafe73d3deedc2bf22` |
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
- El estado protegido final de AWS quedó en
  `6625cf107adbb96aa7729baafe73d3deedc2bf22`, árbol limpio, y el mismo SHA fue
  registrado en `/var/lib/portalgp/deployed_commit`.
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
4. manifiesto con fecha UTC, host, versiones, rama, SHA y estado limpio;
5. Nginx y PHP-FPM activos;
6. PortalGP e `it-conecta` siguieron respondiendo HTTP 200 después del proceso.

El script `deploy/scripts/create_preflight_backup.sh` evita escribir el índice
Git cuando se ejecuta como `root`. Durante la primera ejecución se detectó que
Git había actualizado únicamente `.git/index` con propietario `root`; se
restauró su propietario original, se corrigió el script y se verificó que una
nueva ejecución conserva el índice como `ubuntu` y el árbol limpio. No se
alteraron archivos funcionales ni datos de negocio.

## 2.3 Respaldo de SQL Server

El 8 de octubre de 2026 se ejecutó un respaldo completo de `PORTALGP` mediante
la identidad técnica existente `portal`, que tiene permiso `BACKUP DATABASE`:

`PORTALGP_COPY_ONLY_SECURITY_20261008_174132.bak`

Propiedades solicitadas y confirmadas por la operación:

- `COPY_ONLY`, para no alterar la cadena normal de respaldos;
- compresión;
- `CHECKSUM` durante la generación;
- destino en la ruta predeterminada de respaldos de SQL Server;
- sin escritura funcional adicional en las tablas de PortalGP.

La herramienta portable quedó en `scripts/security_backup_sqlserver.php` y no
contiene credenciales. El historial observado también registra respaldos
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

# Hallazgos detectados durante la definición del alcance

## SEC-001 — PortalGP transmite información real por HTTP

- Severidad: **Alta**.
- Estado: **En corrección**.
- Evidencia: el sitio responde en el puerto 80 y no existe servicio en 443. La aplicación activa CSP y cabeceras defensivas, pero no puede marcar la cookie como `Secure` ni proteger credenciales y datos en tránsito sin HTTPS.
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
| 4 | Red y exposición AWS | Pendiente |
| 5 | Sistema operativo, Nginx y PHP-FPM | Pendiente |
| 6 | Código y dependencias | Pendiente |
| 7 | Autenticación, sesiones, usuarios, roles y permisos | Pendiente |
| 8 | SQL Server e integridad financiera | Pendiente |
| 9 | Archivos, PDF, importaciones y correo | Pendiente |
| 10 | DAST aislado y regresión | Pendiente |
| 11 | Monitoreo, respaldo y respuesta | Pendiente |
| 12 | Validación del servidor definitivo y migración | Pendiente |

## Próximo punto

El punto 3 deberá establecer una línea base reproducible del despliegue AWS y
compararla con el manifiesto protegido en este punto. La prueba administrativa
de restauración SQL y la copia externa del respaldo permanecen como requisitos
de continuidad, no como permisos pendientes de la aplicación.

## Avance de SEC-001

El servidor cuenta ahora con Certbot 5.8.0, soporte para certificados de IP y
renovación por temporizador. El desafío HTTP-01 fue verificado públicamente. La
emisión definitiva está pendiente de registrar una cuenta ACME, aceptar el
acuerdo de suscriptor y definir el correo de contacto; después se instalará el
bloque HTTPS separado y la redirección exclusiva de `/portalgp`.
