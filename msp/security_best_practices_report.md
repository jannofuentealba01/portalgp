# Security Best Practices Report (MSP)

## Resumen Ejecutivo
En `msp/` existe una base de seguridad central para sesión, CSRF, permisos y consultas parametrizadas. Los dos hallazgos de mayor impacto de esta revisión —worker accesible por HTTP y fallback TLS inseguro— están corregidos en el código actual. La exposición global de errores, los secretos operativos y el TLS de SQL Server continúan pendientes de sus etapas específicas.

La revisión individual de los 21 resultados del análisis Semgrep guardado está registrada en `SEGURIDAD_ETAPA1_REVISION_SEMGREP.md`. Ninguno quedó clasificado como vulnerabilidad confirmada abierta: 15 corresponden a eliminaciones controladas y 6 a falsos positivos del análisis de flujo.

## Contexto de revisión
- Stack detectado: PHP + JavaScript frontend (sin framework).
- Referencias usadas del skill: `javascript-general-web-frontend-security.md`.
- No hay referencia PHP específica en el skill; para backend se aplicó criterio OWASP/PHP secure-by-default.

## Hallazgos Críticos

### CRIT-001: Worker operativo ejecutable sin autenticación/autorización vía endpoint web
- Severidad: Crítica
- Estado: Corregido en el código actual.
- Impacto (1 línea): un atacante puede disparar procesamiento de lotes y envíos sin sesión válida, alterando estado operativo y generando envíos no autorizados.
- Evidencia:
  - `msp/cobros/worker_envio_lotes.php:4` carga bootstrap pero no exige `msp2RequireAccess()`.
  - `msp/cobros/worker_envio_lotes.php:20` procesa opciones.
  - `msp/cobros/worker_envio_lotes.php:29` ejecuta `EnvioLotesProgramadosService::processDueLotes(...)`.
- Recomendación:
  - Bloquear ejecución fuera de CLI (`PHP_SAPI !== 'cli' => 403 + exit`).
  - Mover el worker fuera del webroot o bloquearlo en servidor web (deny por ruta).
  - Mantenerlo invocable solo por scheduler del sistema.
- Corrección aplicada:
  - `msp/cobros/worker_envio_lotes.php` comprueba `PHP_SAPI` antes de cargar el bootstrap y responde 403 fuera de CLI.

## Hallazgos Altos

### HIGH-001: Downgrade de TLS (se desactiva verificación de certificado ante fallos SSL)
- Severidad: Alta
- Estado: Corregido en el helper señalado.
- Impacto: posibilita MITM y manipulación de respuestas de servicios externos.
- Evidencia:
  - `msp/cobros/support/OperacionMensualCommon.php:68-70` fallback inseguro equivalente en cURL.
  - `msp/cobros/support/OperacionMensualCommon.php:118-132` fallback inseguro equivalente en `file_get_contents`.
- Recomendación:
  - Eliminar fallback inseguro y fallar cerrado si TLS no valida.
  - Corregir trust store/CA bundle del entorno (ya existe `config/cacert.pem`).
  - Si se necesita bypass temporal, condicionarlo por flag de entorno explícita y solo en dev.
- Corrección aplicada:
  - `msp/cobros/support/OperacionMensualCommon.php` verifica certificado y nombre del servidor tanto con cURL como con streams.
  - Este estado no cierra el pendiente separado de cifrado para la conexión a SQL Server.

## Hallazgos Medios

### MED-001: Exposición de errores técnicos a usuarios finales
- Severidad: Media
- Estado: Corregido el 03-09-2026 (etapa 2 de seguridad de acceso).
- Impacto: filtración de detalles internos (SQL/estructura/mensajes de runtime) útil para reconocimiento del sistema.
- Evidencia:
  - `msp/rubros/index.php:84`, `msp/comunas/index.php:84`, `msp/documentos_cobro/index.php:1285` muestran “Detalle técnico”.
- Recomendación:
  - Mensaje genérico al usuario; detalle técnico solo a logs internos.
  - Estandarizar manejo de excepciones de producción.
- Corrección aplicada:
  - `pgpPublicException()` registra el diagnóstico privado y entrega una referencia segura al usuario.
  - Se bloquearon por HTTP los diagnósticos de instalación y se desactivó `display_errors`.
  - Las vistas y generadores PDF/reportes auditados ya no imprimen `PDOException` ni rutas internas.

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
1. Completar la auditoría de SQL Injection y SQL dinámico.
2. Completar la auditoría de XSS y templates.
3. Terminar la revisión global de errores técnicos en UI/API.
4. Mover y rotar secretos operativos.
5. Configurar TLS para SQL Server según el entorno.
