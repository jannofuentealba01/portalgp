# PortalGP — Etapa 3 de seguridad, puntos 1 al 3

Fecha: 7 de septiembre de 2026
Alcance ejecutado: CSP, recursos frontend y cabeceras HTTP/caché.

## Resultado ejecutivo

Los puntos 1 al 3 de la etapa 3 quedaron implementados. PortalGP ya no ejecuta JavaScript ni carga estilos o fuentes directamente desde CDN: 245 referencias distribuidas en 86 vistas fueron reemplazadas por copias locales con versiones fijas. Apache entrega una política CSP activa y un conjunto uniforme de cabeceras de seguridad.

No se modificó la base de datos ni la configuración de usuarios. `admin_2` continúa habilitado, con rol Administrador y 22 permisos.

## Punto 1 — Política CSP

La política activa incluye:

- `default-src`, `base-uri`, `object-src`, `frame-src` y `frame-ancestors`.
- `script-src`, `style-src`, `img-src`, `font-src` y `connect-src`.
- `form-action`, `media-src`, `worker-src` y `manifest-src`.

Scripts, estilos y fuentes se restringen al mismo origen. `object-src` y `frame-src` están bloqueados. Las imágenes HTTPS continúan permitidas porque el sistema admite fotografías configurables. Debido a que las vistas existentes aún contienen scripts, estilos y eventos inline, `script-src` y `style-src` mantienen temporalmente `'unsafe-inline'`; retirarlo requiere una migración separada del frontend y no corresponde a estos tres puntos.

## Punto 2 — Bootstrap, JavaScript, fuentes y CDN

Se revisaron y localizaron:

- Bootstrap 4.5.2, 5.3.0 y 5.3.3.
- Bootstrap Icons 1.10.5 y 1.11.3, incluidas sus fuentes WOFF/WOFF2.
- Chart.js 4.4.3.
- Driver.js 1.3.6.
- HTMX 1.9.12.

Las versiones no se mezclaron ni actualizaron durante el cambio para evitar regresiones visuales. Su inventario, origen y huellas SHA-256 están en `assets/vendor/README.md`.

## Punto 3 — Cabeceras HTTP y caché

Apache entrega globalmente:

- `Content-Security-Policy`.
- `X-Content-Type-Options: nosniff`.
- `Referrer-Policy: strict-origin-when-cross-origin`.
- `X-Frame-Options: SAMEORIGIN` y `frame-ancestors 'self'`.
- `Permissions-Policy` sin cámara, micrófono, geolocalización, pagos ni USB.
- `Cross-Origin-Opener-Policy` y `Cross-Origin-Resource-Policy` en `same-origin`.
- `X-Permitted-Cross-Domain-Policies: none`.
- HSTS durante un acceso HTTPS, nunca durante el desarrollo HTTP local.

Las respuestas PHP usan `no-store, private, max-age=0`; CSS, JavaScript, fuentes e imágenes estáticas usan caché pública e inmutable por siete días. Existe además un fallback PHP para servidores sin `mod_headers`, evitando encabezados duplicados cuando Apache sí posee el módulo.

## Continuación de la etapa 3

Los puntos 4 al 11 se completaron posteriormente. El cierre consolidado y sus
pruebas están documentados en `SEGURIDAD_ETAPA3_CIERRE_COMPLETO.md`.

## Verificación

- `tests/security_stage3_http.php --live`: **47/47** comprobaciones.
- Sintaxis PHP de todos los archivos afectados: **90/90**.
- Recursos locales servidos por Apache: **15/15** con respuesta HTTP 200.
- Regresión de seguridad de la etapa 2: **27/27**.
- Regresión MSP: **21/21**.
- HTTPS local: HSTS, CSP y `no-store` confirmados en la respuesta real de Apache.
