# Plan Detallado (Topicos 2 -> 1 -> 3)

## Objetivo general
Reducir riesgo operativo y deuda técnica en flujos de cobro/cierre con una arquitectura más mantenible: SQL Server como capa de negocio transaccional y PHP como orquestador HTTP/UI.

## Fase A - Tópico 2 (Desacople `operacion_mensual.php`)

### A1. Base técnica (completado en esta iteración)
- Crear `cobros/services/OperacionMensualService.php`.
- Mover operaciones pesadas de generación/corrección a SPs:
  - `dbo.msp_generar_cobros_periodo`
  - `dbo.msp_borrar_generacion_periodo`
- Conectar `cobros/operacion_mensual.php` para usar el servicio.
- Registrar patch en `db/msp_instalar_core.sql` y `db/README_INSTALACION_MSP.md`.

### A2. PR siguiente (importaciones y lecturas)
- Extraer handlers a `cobros/services/ImportacionLecturasService.php`:
  - `preparar_lecturas_directas`
  - `actualizar_lecturas_directas`
  - `importar_lecturas`
  - `confirmar_importacion`
- Meta: reducir `operacion_mensual.php` en ~1200-1800 líneas.

### A3. PR siguiente (envío demo y utilitarios)
- Extraer `enviar_demo` y `enviar_demo_batch` a `cobros/services/EnvioDemoService.php`.
- Extraer helpers puros a `cobros/services/OperacionMensualHelpers.php`.
- Meta: dejar `operacion_mensual.php` como controlador de flujo.

### Criterios de cierre de tópico 2
- `operacion_mensual.php` < 4000 líneas.
- SQL crítico fuera del controlador principal.
- Cada acción POST delega a servicio.

## Fase B - Tópico 1 (Unificar cierre financiero en SP)

### B1. Diseño
- Consolidar validaciones de cierre en `dbo.msp_contrato_cerrar`.
- Incorporar en SP lo que hoy valida PHP (periodo, docs pendientes, estado tienda).

### B2. Implementación
- Nuevo patch DB: `patch_contrato_cierre_unificado.sql`.
- Adaptar endpoint `contratos/finalizar_cierre_financiero.php` para:
  - llamar SP único,
  - mapear errores `THROW` a flash messages,
  - eliminar reglas duplicadas.

### B3. Validación
- Casos: cierre exitoso, ya cerrado, contrato inválido, reservas, docs con saldo, concurrencia.

## Fase C - Tópico 3 (Triggers/saldos: concurrencia + observabilidad)

### C1. Concurrencia
- Reemplazar `MERGE` en saldo a favor por patrón `UPDATE` + `INSERT`.
- Revisar índices de rutas calientes (`pagos`, `movimientos_saldo_favor`).

### C2. Observabilidad
- Agregar tabla de eventos técnicos (`error_code`, `sp_name`, `duracion_ms`, `contexto`).
- Instrumentar SPs críticos: pagos, cobros, cierre, corrección.

### C3. Validación
- Pruebas de carga concurrente en pagos/anulación/aplicación saldo.
- Medición de lock waits/deadlocks antes y después.

## Riesgos y mitigaciones
- Riesgo: divergencia entre SP nuevo y lógica legacy.
- Mitigación: feature flag por endpoint + rollback por script.
- Riesgo: cambios SQL en ambiente con datos históricos.
- Mitigación: pruebas en copia de BD y despliegue escalonado.

## Entregables esperados
1. Patches SQL versionados en `db/`.
2. Servicios PHP por dominio (`cobros/services/*`, `contratos/services/*`).
3. Endpoints más delgados y consistentes.
4. Checklist de pruebas funcionales y transaccionales.
