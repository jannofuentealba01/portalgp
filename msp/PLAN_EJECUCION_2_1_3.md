# Plan de Ejecucion 2 -> 1 -> 3

## Objetivo
Cerrar deuda tecnica y riesgo transaccional en tres frentes:
1. Desacoplar `cobros/operacion_mensual.php` en SPs + servicios PHP.
2. Unificar cierre financiero en `dbo.msp_contrato_cerrar` y adelgazar endpoint PHP.
3. Optimizar saldos/triggers para concurrencia y observabilidad operativa.

## Enfoque de implementacion
- Orden estricto: **Topico 2 -> Topico 1 -> Topico 3**.
- Entrega incremental por PR pequeno, con rollback SQL por patch.
- Regla: logica de negocio transaccional en SQL Server; PHP solo orquesta HTTP/UX.

## Fase A - Topico 2 (en curso)
### A1. Ya implementado
- `cobros/services/OperacionMensualService.php`.
- SPs `dbo.msp_generar_cobros_periodo` y `dbo.msp_borrar_generacion_periodo` en `db/patch_operacion_mensual_sp.sql`.
- `cobros/operacion_mensual.php` ya delega `generar_cobros` y `borrar_generacion`.

### A2. PR de lecturas e importacion (completado)
- Crear `cobros/services/ImportacionLecturasService.php`.
- Extraer handlers:
  - `preparar_lecturas_directas` (hecho)
  - `actualizar_lecturas_directas` (hecho)
  - `importar_lecturas` (hecho)
  - `confirmar_importacion` (hecho)
- Mantener validaciones funcionales actuales y mensajes UX.

### A3. PR posterior (envio demo)
- Crear `cobros/services/EnvioDemoService.php`. (hecho)
- Extraer `enviar_demo` y `enviar_demo_batch`. (hecho)
- Unificar construccion de destinatarios/documentos para evitar duplicacion.

### A4. Segmentacion de helpers comunes (hecho)
- Crear `cobros/support/OperacionMensualCommon.php`.
- Mover utilitarios de:
  - formato/fechas/feriados,
  - request/json flags,
  - configuracion SMTP y armado de mailer.
- Mantener `operacion_mensual.php` con foco en flujo de acciones.

### A5. Segmentacion de generacion documental (hecho)
- Crear `cobros/services/DocumentosCobroService.php`.
- Extraer `omGenerateDocumentsForCierre(...)` desde el controlador.
- Mantener los mismos llamados de negocio desde acciones `generar_cobros` / `generar_documentos`.

### A6. Segmentacion de helpers de importacion (hecho)
- Crear `cobros/support/ImportacionLecturasHelper.php`.
- Mover helpers de:
  - parseo de planilla (columnas/codigos/fechas),
  - persistencia temporal de previsualizacion en sesion.

### DoD Topico 2
- `cobros/operacion_mensual.php` < 4000 lineas.
- Sin SQL de negocio pesado en controlador.
- Flujos actuales sin regresion funcional visible.

## Fase B - Topico 1
### B1. PR DB
- Crear `db/patch_contrato_cierre_unificado.sql`.
- Extender `dbo.msp_contrato_cerrar` para validar en SP:
  - periodo de corte,
  - cierre mensual cerrado,
  - documentos con saldo,
  - estado contrato/cargos/reservas,
  - actualizacion de `msp_tiendas` + bitacora.
- Estandarizar `THROW` por codigo/mensaje.

### B2. PR PHP
- Refactor `contratos/finalizar_cierre_financiero.php` a thin controller.
- Dejar solo validacion de formato + llamada a SP.
- Mapear codigos `THROW` a `flash` claros.

### B3. PR UI
- Ajustar `contratos/index.php` (modal cierre) al contrato final del SP.
- Resolver decision de negocio: motivo obligatorio u opcional con default controlado.

### DoD Topico 1
- Una sola fuente de verdad para cierre financiero: `dbo.msp_contrato_cerrar`.
- Endpoint PHP sin duplicar reglas de negocio.

## Fase C - Topico 3
### C1. Quick wins concurrencia
- En `msp_registrar_pago_documento`, `msp_aplicar_saldo_favor_documento`, `msp_anular_pago_documento`:
  - mover `BEGIN TRANSACTION` antes de lecturas con `UPDLOCK/HOLDLOCK`.
  - unificar orden de lock: documento -> saldo tienda -> pago.
- Reemplazar `MERGE` en trigger de saldo por `UPDATE` + `INSERT` idempotente.

### C2. Observabilidad
- Crear `dbo.msp_bitacora_operacion_financiera` (duracion_ms, error_code, sp_name, actor, correlation_id, payload acotado).
- Instrumentar SPs y endpoints PHP de pagos/saldos.

### C3. Hardening
- Evolucionar recalculo total a enfoque delta (`inserted/deleted`) donde aplique.
- Agregar chequeos de invariante de saldos/documentos para deteccion temprana de descuadres.

### DoD Topico 3
- Reduccion de deadlocks/timeouts en flujos de pago.
- Trazabilidad tecnica util para auditar fallas y rendimiento.

## Dependencias y riesgos
- Dependencia critica: contrato de errores SQL (`THROW`) estable para PHP.
- Riesgo: divergencia entre logica legacy y SP nuevo.
- Mitigacion: despliegue por flags de endpoint + pruebas regresivas por caso de negocio.

## Plan operativo inmediato
1. Ejecutar **A2** (servicio de lecturas/importacion) como siguiente commit.
2. Ejecutar **B1** (patch unificado `msp_contrato_cerrar`) en paralelo de revision funcional.
3. Abrir **C1** en cuanto cierre B2 para no mezclar regresiones funcionales con tuning de concurrencia.
