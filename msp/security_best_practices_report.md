# Security Best Practices Report (MSP)

## Resumen Ejecutivo
En `msp/` el baseline de seguridad es razonable (CSRF global y uso amplio de prepared statements), pero hay hallazgos de alto impacto: un worker ejecutable sin control de acceso por HTTP y downgrade explícito de verificación TLS. También hay exposición de errores técnicos y manejo de secretos mejorable.

## Contexto de revisión
- Stack detectado: PHP + JavaScript frontend (sin framework).
- Referencias usadas del skill: `javascript-general-web-frontend-security.md`.
- No hay referencia PHP específica en el skill; para backend se aplicó criterio OWASP/PHP secure-by-default.

## Hallazgos Críticos

### CRIT-001: Worker operativo ejecutable sin autenticación/autorización vía endpoint web
- Severidad: Crítica
- Impacto (1 línea): un atacante puede disparar procesamiento de lotes y envíos sin sesión válida, alterando estado operativo y generando envíos no autorizados.
- Evidencia:
  - `msp/cobros/worker_envio_lotes.php:4` carga bootstrap pero no exige `msp2RequireAccess()`.
  - `msp/cobros/worker_envio_lotes.php:20` procesa opciones.
  - `msp/cobros/worker_envio_lotes.php:29` ejecuta `EnvioLotesProgramadosService::processDueLotes(...)`.
- Recomendación:
  - Bloquear ejecución fuera de CLI (`PHP_SAPI !== 'cli' => 403 + exit`).
  - Mover el worker fuera del webroot o bloquearlo en servidor web (deny por ruta).
  - Mantenerlo invocable solo por scheduler del sistema.

## Hallazgos Altos

### HIGH-001: Downgrade de TLS (se desactiva verificación de certificado ante fallos SSL)
- Severidad: Alta
- Impacto: posibilita MITM y manipulación de respuestas de servicios externos.
- Evidencia:
  - `msp/cobros/support/OperacionMensualCommon.php:68-70` fallback inseguro equivalente en cURL.
  - `msp/cobros/support/OperacionMensualCommon.php:118-132` fallback inseguro equivalente en `file_get_contents`.
- Recomendación:
  - Eliminar fallback inseguro y fallar cerrado si TLS no valida.
  - Corregir trust store/CA bundle del entorno (ya existe `config/cacert.pem`).
  - Si se necesita bypass temporal, condicionarlo por flag de entorno explícita y solo en dev.

## Hallazgos Medios

### MED-001: Exposición de errores técnicos a usuarios finales
- Severidad: Media
- Impacto: filtración de detalles internos (SQL/estructura/mensajes de runtime) útil para reconocimiento del sistema.
- Evidencia:
  - `msp/rubros/index.php:84`, `msp/comunas/index.php:84`, `msp/documentos_cobro/index.php:1285` muestran “Detalle técnico”.
- Recomendación:
  - Mensaje genérico al usuario; detalle técnico solo a logs internos.
  - Estandarizar manejo de excepciones de producción.

### MED-002: Secretos en archivos locales dentro de `msp/` (higiene operativa)
- Severidad: Media
- Impacto: riesgo de exposición accidental en backups, despliegues o configuración insegura del servidor.
- Evidencia:
  - `msp/config/mail.php:17` contiene credencial SMTP.
  - `msp/.gitignore:8` intenta excluir archivos de credenciales del versionado.
- Recomendación:
  - Migrar secretos a variables de entorno/secret manager.
  - Rotar secretos actualmente expuestos en el entorno local.
  - Evitar archivos de secretos bajo webroot cuando sea posible.

## Buenas Prácticas Detectadas
- CSRF global consistente para POST (`msp/bootstrap.php:339-355`).
- Tokens CSRF robustos (`msp/bootstrap.php:292-300` con `random_bytes`).
- Uso predominante de prepared statements en módulos CRUD/reportes.
- Firma y expiración de enlaces sensibles (`msp/bootstrap.php:406-433`).

## Prioridad de Remediación
1. `CRIT-001` bloquear worker por HTTP (solo CLI).
2. `HIGH-001` eliminar fallback TLS inseguro.
3. `MED-001` ocultar errores técnicos en UI/API.
4. `MED-002` mover/rotar secretos operativos.
