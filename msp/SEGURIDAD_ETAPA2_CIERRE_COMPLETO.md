# PortalGP MSP — Cierre completo de la etapa 2 de seguridad

Fecha: 7 de septiembre de 2026
Alcance: SQL Injection, XSS, exposición de información, cargas de archivos y autorización por objetos.

## Resultado ejecutivo

Los diez puntos definidos para la etapa 2 quedaron revisados e implementados en el alcance actual de PortalGP. La aplicación mantiene su modelo interno de permisos por módulo y acción, elimina detalles técnicos de las respuestas públicas, protege los cargadores actuales y bloquea preventivamente el acceso de una futura sesión de arrendatario a MSP y CT.

No se modificaron usuarios, contraseñas, estados, roles ni asignaciones de permisos. `admin_2` conserva `id=1030`, `estado_id=1`, `rol_id=1`, contraseña configurada y las 22 asignaciones de su rol.

## Estado de los diez puntos

1. **Auditoría de SQL dinámico — completado.** Se inventariaron consultas, procedimientos, `EXEC`, filtros y ordenamientos. Los hallazgos y falsos positivos están en `SEGURIDAD_ETAPA2_PUNTO1_AUDITORIA_SQL.md`.
2. **Parametrización y listas permitidas — completado.** Los valores variables usan parámetros y los identificadores u ordenamientos dinámicos pasan por validadores o listas cerradas.
3. **Auditoría de salidas — completado.** Se revisaron salidas HTML, atributos, URL, JSON y JavaScript, incluidos componentes compartidos.
4. **XSS reflejado y almacenado — completado.** Se corrigieron salidas legacy, alertas JavaScript, errores impresos, un DOM-XSS y el contrato de HTML confiable del selector buscable.
5. **Excepciones públicas — completado.** Los usos públicos de mensajes de excepción pasan ahora por una frontera central que conserva validaciones de negocio y sustituye fallos técnicos por un mensaje genérico con referencia.
6. **SQLSTATE, rutas y estructura interna — completado.** El navegador ya no recibe SQLSTATE, ODBC, nombres inválidos de tablas/columnas, procedimientos, restricciones, trazas o rutas PHP. El detalle se conserva únicamente en el registro privado con una referencia correlacionable.
7. **Logs y secretos — completado.** Se centralizó el registro de excepciones y se redactan tokens Bearer/OAuth, contraseñas, secretos de cliente, correos y RUT. También se impiden saltos de línea y se limita el tamaño del registro.
8. **Autorización de identificadores — completado.** Se inventariaron 91 entradas MSP que reciben identificadores de contratos, pagos, garantías, documentos u otros objetos; ninguna carece del control de acceso MSP. El método HTTP determina lectura, escritura o eliminación y las mutaciones conservan CSRF.
9. **Cargas de archivos — completado.** Todos los cargadores MSP usan el validador central de planillas o el validador específico de garantías. Los XLSX se inspeccionan como ZIP, con límite de entradas y tamaño expandido, rechazo de rutas internas peligrosas y estructura mínima. CT valida MIME, ZIP y XML sin red. Garantías comprueba firma real de PDF/JPEG/PNG, tamaño, nombre seguro, almacenamiento aleatorio fuera del webroot, integridad SHA-256 y descarga autorizada.
10. **Aislamiento del futuro portal de arrendatarios — completado para el diseño actual.** MSP y CT exigen audiencia de sesión `internal`; una sesión marcada `arrendatario` queda denegada aunque posea accidentalmente un rol interno. El portal externo todavía no existe, por lo que al desarrollarlo sus consultas deberán además vincular cada objeto al arrendatario y contrato de la sesión; nunca deberá reutilizar rutas internas.

## Modelo de autorización verificado

PortalGP opera hoy como sistema interno de una sola empresa. Por ese motivo, un usuario autorizado puede consultar los objetos completos del módulo que su rol permite; actualmente no existe propiedad de registros por usuario interno. Esto no debe trasladarse al futuro portal de arrendatarios, cuya separación por cliente y contrato será obligatoria.

## Cargas revisadas

- Planillas de arrendatarios, pagos por contrato, lecturas mensuales, contratos/tiendas, locales, medidores y respaldos de pagos.
- Importador CT de terceros en CSV/XLSX.
- Comprobantes de recepción y devolución de garantías en PDF, JPEG o PNG.

## Verificación automatizada

- Sintaxis PHP: **33/33** archivos intervenidos sin errores.
- `tests/security_stage2_completion.php`: **27/27** comprobaciones de errores, logs, audiencia, autorización, archivos y `admin_2`.
- `tests/security_stage2_sql_xss.php`: **19/19** comprobaciones.
- `tests/security_financial_stage2.php`: **20/20** comprobaciones, incluida una operación financiera completa revertida transaccionalmente.
- `tests/msp_regression_suite.php`: **21/21** comprobaciones funcionales y de integridad.

La prueba heredada `tests/security_stage2.php` crea una base de datos desechable y no puede ejecutarse con la conexión operativa de privilegios reducidos, que correctamente carece de `CREATE DATABASE`. No es un fallo de la aplicación y no dejó cambios parciales.

## Resultado

Etapa 2 cerrada. Los controles nuevos son compatibles con el acceso de desarrollo existente y no alteraron `admin_2`.
