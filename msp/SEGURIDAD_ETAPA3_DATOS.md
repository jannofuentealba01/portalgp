# PortalGP — Clasificación y protección de datos

Fecha: 8 de septiembre de 2026

## Alcance

Esta política cubre los datos personales, contractuales y financieros que MSP
procesa: RUT, nombres, correos, teléfonos, direcciones, contratos, pagos,
garantías, cuentas bancarias, documentos de cobro, comprobantes y archivos.
También cubre credenciales, sesiones, tokens OAuth y registros técnicos.

La configuración verificable está en `config/data_protection.php`. Los plazos
definidos son una línea base interna conservadora y no una afirmación de una
obligación legal concreta. Antes de habilitar eliminaciones automáticas deben
ser aprobados por las áreas legal y contable. Una retención legal siempre
prevalece sobre la eliminación.

## Controles implementados

- La aplicación usa TLS hacia SQL Server. En producción se rechaza una conexión
  sin cifrado o que confíe en un certificado sin validarlo.
- Los secretos de base de datos, SMTP y Entra están fuera de `htdocs`, bajo un
  directorio con ACL restringida. No se imprimen en diagnósticos.
- `portalgp_runtime` no es `sysadmin` ni `db_owner` y ya no tiene concesiones de
  lectura, escritura o ejecución sobre todo el esquema `dbo`; sus permisos se
  conceden por objeto existente de PortalGP.
- Las contraseñas se conservan como hash unidireccional. Los tokens OAuth viven
  en la sesión y no deben registrarse en logs.
- Entra valida criptográficamente el `id_token` con las claves públicas de
  Microsoft y comprueba algoritmo, firma, emisor, audiencia, tenant, vigencia,
  identidad y `nonce`.
- Los permisos funcionales MSP se aplican directamente. La compatibilidad de
  autorización mediante `MSP Arriendos` quedó retirada del código.

## Cifrado en reposo

La instancia local de desarrollo no tiene TDE habilitado. Esto no impide el
desarrollo, pero una instalación productiva debe usar cifrado de base de datos
o del volumen y respaldos cifrados. Los campos que deben poder buscarse —como
RUT, correo y cuenta— no se cifran individualmente en esta etapa; se protegen
con cifrado en reposo, TLS, permisos y trazabilidad.

## Retención base

- Tokens OAuth: duración de la sesión.
- Temporales de importación: 24 horas.
- Intentos de autenticación y logs técnicos: 90 días.
- Expedientes contractuales/financieros y archivos confirmados: 10 años desde
  el cierre, sujeto a aprobación legal y contable.

La eliminación automática queda deshabilitada hasta contar con esa aprobación.

## Operación obligatoria

Las claves de SMTP y Entra que hayan existido históricamente dentro del sitio
deben rotarse en sus proveedores. El código admite `MS_ENTRA_CLIENT_SECRET` y,
durante una rotación controlada, `MS_ENTRA_CLIENT_SECRET_PREVIOUS`. Terminada la
rotación, se elimina el secreto anterior del entorno.
