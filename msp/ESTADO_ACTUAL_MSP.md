# Estado Actual MSP

Fecha de corte: 2026-04-14.

## 1. Que hace hoy el proyecto

El modulo `msp/` opera el ciclo comercial y de cobro de locales/arrendatarios del Mercado San Pedro, incluyendo:

- catalogos base (`arrendatarios`, `locales`, `medidores`, estados y rubros);
- contratos y garantia (alta, edicion, historial, cierre operativo y cierre financiero);
- facturacion mensual por servicios (`LUZ`, `GAS`, `AGUA`) y arriendo;
- generacion de documentos de cobro y emision de PDF;
- cobranza y registro de pagos con aplicacion por concepto;
- saldo a favor y anulaciones/reversiones de pagos;
- reportes operativos (aging y consumos por servicio);
- envio programado de lotes de cobro (modo real/demo).

## 2. Avance visible en el codigo actual (working tree)

Segun los cambios actualmente presentes en el arbol de trabajo:

- `documentos_cobro/*` incorpora soporte de `uuid_documento` para acceso a PDF por UUID firmado y ajuste de "total a pagar" redondeado.
- `db/patch_documentos_cobro_uuid.sql` agrega columna UUID, backfill, default e indice unico; `db/msp_instalar_core.sql` ya incluye este patch.
- `cobros/operacion_mensual.php` y `cobros/services/EnvioLotesProgramadosService.php` agregan flujo "generar + programar" por etapa, bloqueo por lotes no cancelados, conversion horaria cliente/SQL Server y eliminacion permanente de lote.
- `cobranza/registrar_pago.php` mejora UX de prelacion de aplicacion (Arriendo -> Luz -> Gas -> Agua -> Otros), autodistribucion de monto y validaciones de consistencia.
- `cobros/plantilla_lecturas.php` y `cobros/services/ImportacionLecturasService.php` ajustan logica de fechas para plantillas/importaciones de servicios.
- `medidores/importar.php`, `medidores/plantilla_import.php`, `locales/index.php` agregan `fecha_medicion_valor_inicial`.
- `reportes/consumo_electrico.php` y `reportes/consumo_gas.php` aparecen como nuevos endpoints de reporte; `msp_menu.php` incorpora seccion dedicada de Reportes.
- templates de correo (`cobros/mail_templates/vale_cobro_email.php`, `cobranza/mail_templates/vale_pago_email.php`) incorporan orden/rotulacion y montos "payable" redondeados en bloques clave.

## 3. Estado de planes (implementado vs pendiente)

### 3.1 Planes con avance implementado/parcial

- `PLAN_TOPICOS_2_1_3.md`
  - A1: marcado como completado en el propio plan.
  - A2: hay avance parcial (cambios en `ImportacionLecturasService`), pero no se observa cierre integral del topico.
  - B/C: pendientes segun el plan.

- `plan_flujo_tienda_contrato_msp.md`
  - El propio documento reporta avances implementados en fases B, D y E (parcial), alineados con la estructura actual de `contratos/`, wrappers en `tiendas/` y patches DB operativos.

- `plan_rediseno_contrato_garantia_msp.md`
  - El propio documento declara Fases 1-4 completadas y Fase 5 iniciada; coincide con presencia de SQL por fases y modulos de contratos activos.

- `plan_dashboard_kpi_msp.md`
  - Existe `dashboard/index.php` y acceso en menu historico, por lo que hay implementacion funcional base (nivel MVP/parcial segun alcance final deseado).

### 3.2 Planes mayormente pendientes

- `plan_cdn_sri_csp.md`: estrategia definida, sin evidencia de implementacion completa en este corte.
- `plan_qa_flujos_msp.md`: checklist de validacion; no registra resultados de ejecucion en el documento.

## 4. Recomendacion de commits para subir ordenado

Para no mezclar funcionalidad y documentacion, se recomienda:

1. Commit funcional DB + documentos/lotes/reportes/pagos (codigo PHP + SQL).
2. Commit de documentacion de estado y planes (este archivo + planes/analisis que se quiera versionar).
3. (Opcional) Commit de limpieza de ruido local si aplica (`.codex`, borradores no deseados).

Sugerencia de mensaje para el commit documental:

`docs(msp): documentar estado actual del modulo y avance de planes`
