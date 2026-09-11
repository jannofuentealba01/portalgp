# PortalGP — Cierre completo de la etapa 3 de seguridad

Fecha: 8 de septiembre de 2026
Alcance: transporte, privilegios, secretos, Entra ID y protección de datos.

## Resultado ejecutivo

Los once puntos de la etapa 3 quedaron implementados en el código y en la base
local operativa. La aplicación mantiene su funcionamiento con la cuenta técnica
restringida y `admin_2` no fue eliminado ni modificado: continúa habilitado,
con rol Administrador, `security_version=0` y 22 permisos.

## Implementación

1. CSP aplicada y recursos frontend locales; el detalle de los puntos 1 al 3
   está en `SEGURIDAD_ETAPA3_PUNTOS1_3_HTTP.md`.
2. SQL Server usa conexión cifrada. Una conexión runtime real informó
   `encrypt_option=TRUE`. Producción falla de forma segura si se deshabilita TLS
   o si no se valida el certificado del servidor.
3. `portalgp_runtime` no es `sysadmin` ni `db_owner`. Se retiraron las
   concesiones DML/EXECUTE sobre todo `dbo` y se reemplazaron por 1.037 permisos
   de objeto para el inventario actual. El parche debe repetirse después de
   incorporar nuevos objetos de aplicación.
4. Base de datos, SMTP de MSP/CT y Entra cargan secretos desde
   `C:\xampp\portalgp_secrets`, fuera de `htdocs`, con ACL exclusiva para el
   usuario local, SYSTEM y Administradores.
5. El aprovisionador SQL conserva TLS y mínimo privilegio, y escribe la nueva
   credencial solamente en el almacén externo.
6. La rotación Entra permite un secreto actual y uno anterior durante una
   transición controlada. El secreto anterior deja de utilizarse al retirarlo
   del entorno.
7. El `id_token` de Entra se verifica mediante RS256 y las claves JWK oficiales
   de Microsoft. Se comprueban firma, emisor, audiencia, tenant, expiración,
   identidad y `nonce`; ya no se confía en un payload meramente decodificado.
8. Los datos quedaron clasificados en autenticación, identidad/contacto,
   financieros/contractuales, archivos y logs técnicos. Se definieron acceso,
   almacenamiento, retención y cifrado en `config/data_protection.php` y
   `SEGURIDAD_ETAPA3_DATOS.md`.
9. La autorización MSP ya no usa la compatibilidad `MSP Arriendos`. El registro
   histórico del permiso no se eliminó de la base para no alterar los 22
   permisos existentes de `admin_2`; simplemente dejó de conceder acceso.

## Verificación realizada

- Cierre etapa 3: 46/46 comprobaciones.
- Seguridad HTTP: 43/43.
- Cierre etapa 2: 27/27.
- Seguridad financiera: 20/20.
- Regresión MSP: 21/21.
- Sintaxis: 209 archivos PHP modificados o nuevos, 0 errores.
- Dependencias Composer: 0 avisos de seguridad.
- Claves Entra: 5 claves oficiales descargadas y 5 interpretadas correctamente.
- SQL cifrado real: `encrypt_option=TRUE`.

## Acciones externas antes de producción

El código está terminado, pero tres operaciones pertenecen a la infraestructura
y requieren credenciales administrativas externas:

- Rotar en Microsoft Entra el secreto que haya sido usado históricamente y
  después retirar `MS_ENTRA_CLIENT_SECRET_PREVIOUS`.
- Rotar la credencial SMTP en el proveedor de correo.
- Instalar un certificado SQL confiable y activar cifrado de base/volumen y de
  respaldos en producción. En desarrollo local se mantiene
  `TrustServerCertificate=true` y la base local no utiliza TDE.

Ninguna de estas acciones externas cambia la cuenta, contraseña, rol ni permisos
de `admin_2`.
