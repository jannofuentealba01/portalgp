# PortalGP — Cierre del endurecimiento CSP

Fecha: 15 de septiembre de 2026  
Alcance: PortalGP, MSP, CT, gestión de usuarios y FTE.

## Resultado

La política `Content-Security-Policy` continúa en modo enforcing y ya no
contiene `'unsafe-inline'`. Se retiró la excepción tanto de scripts como de
estilos y atributos.

La solución mantiene las vistas existentes mediante autorización precisa:

- cada respuesta PHP genera un nonce impredecible de 144 bits;
- 222 elementos `script` y `style` de 129 plantillas confiables declaran
  explícitamente ese nonce desde el código fuente;
- los eventos HTML y estilos de atributo heredados se autorizan exclusivamente
  por su hash SHA-256 exacto mediante `'unsafe-hashes'`;
- la lista de hashes se genera desde las plantillas del repositorio: contiene
  17 valores de evento y 102 valores de estilo vigentes;
- cuando una respuesta no contiene esos atributos, `script-src-attr` y
  `style-src-attr` quedan en `'none'`;
- los documentos HTML y SVG estáticos reciben desde Apache una política que
  bloquea totalmente el contenido inline.
- el único HTML estático encontrado era un mockup de desarrollo y quedó
  expresamente bloqueado por HTTP, sin eliminarlo del proyecto.

`'unsafe-hashes'` no equivale a `'unsafe-inline'`: solamente permite el texto
exacto cuyo hash aparece en la lista de plantillas confiables. El procesador
final no agrega nonce ni registra hashes nuevos a partir de la respuesta. Por
eso un elemento o atributo introducido mediante XSS permanece bloqueado.

## Inventario absorbido

Antes del cambio existían en el código de aplicación:

- 123 bloques `script` inline en 83 archivos;
- 48 bloques `style` en 45 archivos;
- 30 eventos HTML en 22 archivos;
- 473 atributos `style` en 66 archivos.

La migración se aplicó a las plantillas PHP activas y se incorporó una
herramienta repetible para mantener nonces y hashes sincronizados. Los recursos
externos continúan siendo copias locales con versiones y huellas controladas.

## Compatibilidad con HTMX

HTMX 1.9.12 generaba automáticamente un bloque `style` sin nonce. Se desactivó
esa inyección mediante `htmx-config`, las reglas de indicadores se trasladaron
a `styles.css` y se entrega a HTMX el nonce vigente para cualquier script de
fragmento. Una ficha real de solicitudes CT quedó sin violaciones CSP después
de este ajuste.

También se reemplazaron dos cambios de estilo ejecutados desde atributos
`onerror` por el atributo seguro `hidden`.

## Comprobaciones HTTP

Las páginas revisadas entregaron:

- CSP presente y sin `'unsafe-inline'`;
- nonce distinto por respuesta;
- cero elementos `script` o `style` sin nonce;
- encabezados acotados a los hashes realmente presentes en cada respuesta;
- eventos `onchange` y confirmaciones heredadas operativos mediante hash.

## Verificación ejecutada

- `tests/security_csp_strict.php`: **25/25**.
- `tests/security_stage3_http.php`: **48/48**.
- `tests/security_csp_browser.js`: **43 vistas** sin violaciones CSP, incluida
  una ficha CT descubierta con datos reales.
- `tests/msp_visual_regression_stage12.js` en modo rápido: **26 escenarios**.
- Sintaxis PHP y JavaScript de los archivos modificados: sin errores.

La prueba de navegador ejercita login, perfil, gestión de usuarios, CT, FTE y
las principales vistas MSP. También abre modales, collapse, confirmaciones,
eventos `onchange`, gráficos, tour Driver.js y navegación Bootstrap.

## Operación y mantenimiento

La CSP se genera centralmente en `security.php`. Toda nueva entrada HTML PHP
debe incluir el arranque común (`db.php`, `login.php` o el bootstrap del módulo)
antes de emitir contenido. Después de agregar o modificar una plantilla se debe
ejecutar:

```powershell
php scripts\csp_templates.php --apply-nonces --write-allowlist
php scripts\csp_templates.php
php tests\security_csp_strict.php
php tests\security_stage3_http.php
node tests\security_csp_browser.js
```

La prueba de navegador requiere `MSP_QA_SESSION_ID` con una sesión local activa
y Playwright disponible. No envía formularios de negocio; las confirmaciones se
cancelan y los cambios de selector usados son navegación GET.

## Protección de `admin_2`

No se cambió la contraseña, estado, rol ni permisos de `admin_2`. La regresión
HTTP verifica que continúa habilitado como Administrador y conserva sus permisos.
