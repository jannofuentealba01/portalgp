/*
===========================================================================
 MSP - INSTALADOR CORE (ORDENADO)
 SQL Server / sqlcmd mode

 Uso recomendado:
   sqlcmd -S <server> -d <database> -E -b -i db/msp_instalar_core.sql

 Notas:
 - Este instalador asume BD limpia.
 - Si no esta limpia, ejecutar primero db/msp_limpiar.sql.
 - Usa comandos :r, por lo tanto requiere sqlcmd (CLI o SSMS con SQLCMD Mode).
===========================================================================
*/

:ON ERROR EXIT
:setvar MSP_DB_DIR "msp\db"

PRINT '==== MSP install: A1 base ====';
:r $(MSP_DB_DIR)\msp_agrupacion_locales.sql

PRINT '==== MSP install: A21 cobro servicios ====';
:r $(MSP_DB_DIR)\msp_cobro_servicios.sql

PRINT '==== MSP install: A22 documentos y pagos ====';
:r $(MSP_DB_DIR)\msp_documento_pago.sql

PRINT '==== MSP install: feriados ====';
:r $(MSP_DB_DIR)\msp_feriados.sql

PRINT '==== MSP install: patch pagos por concepto ====';
:r $(MSP_DB_DIR)\patch_pagos_por_concepto.sql

PRINT '==== MSP install: prioridad unica de imputacion de pagos ====';
:r $(MSP_DB_DIR)\patch_prioridad_imputacion_pagos.sql

PRINT '==== MSP install: patch uuid documentos cobro ====';
:r $(MSP_DB_DIR)\patch_documentos_cobro_uuid.sql

PRINT '==== MSP install: patch saldo a favor ====';
:r $(MSP_DB_DIR)\patch_saldo_favor_tienda.sql

PRINT '==== MSP install: patch trazabilidad saldo a favor por periodo ====';
:r $(MSP_DB_DIR)\patch_saldo_favor_periodo.sql

PRINT '==== MSP install: patch reglas de cobro automatico ====';
:r $(MSP_DB_DIR)\patch_reglas_cobro_auto.sql

PRINT '==== MSP install: patch catalogo bancos ====';
:r $(MSP_DB_DIR)\patch_catalogo_bancos.sql

PRINT '==== MSP install: deuda y garantia base ====';
:r $(MSP_DB_DIR)\msp_deudores_garantia.sql

PRINT '==== MSP install: patch tipos de cargo obsoletos ====';
:r $(MSP_DB_DIR)\patch_tipo_cargo_obsoletos.sql

PRINT '==== MSP install: patch dia cobro fijo ====';
:r $(MSP_DB_DIR)\patch_dia_cobro_fijo.sql

PRINT '==== MSP install: patch termino efectivo contrato ====';
:r $(MSP_DB_DIR)\patch_contrato_termino_efectivo.sql

PRINT '==== MSP install: patch indices contrato operativo/cierre ====';
:r $(MSP_DB_DIR)\patch_contrato_indices_operativos.sql

PRINT '==== MSP install: patch fecha termino tienda ====';
:r $(MSP_DB_DIR)\patch_tiendas_fecha_termino.sql

PRINT '==== MSP install: patches historicos (idempotentes) ====';
:r $(MSP_DB_DIR)\patch_bitacora_cierre_contrato.sql
:r $(MSP_DB_DIR)\patch_historial_contrato.sql

PRINT '==== MSP install: Fase 1 contrato_locales ====';
:r $(MSP_DB_DIR)\msp_fase1_contrato_locales.sql

PRINT '==== MSP install: patch arriendo modalidades fase 1 ====';
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase1.sql

PRINT '==== MSP install: patch arriendo modalidades fase 2 ====';
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase2.sql

PRINT '==== MSP install: patch arriendo modalidades fase 3 ====';
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase3.sql

PRINT '==== MSP install: patch arriendo descuentos entidad ====';
:r $(MSP_DB_DIR)\patch_arriendo_descuentos_entidad.sql

PRINT '==== MSP install: patch arriendo termino prorrata ====';
:r $(MSP_DB_DIR)\patch_arriendo_termino_prorrata.sql

PRINT '==== MSP install: Fase 2 garantia -> contrato_local ====';
:r $(MSP_DB_DIR)\msp_fase2_garantia_contrato_local.sql

PRINT '==== MSP install: Fase 3 cargos -> contrato_local ====';
:r $(MSP_DB_DIR)\msp_fase3_cargos_contrato_local.sql

PRINT '==== MSP install: patch estado operativo y control diario ====';
:r $(MSP_DB_DIR)\patch_estado_operativo_control_diario.sql

PRINT '==== MSP install: Fase 4 SPs de negocio ====';
:r $(MSP_DB_DIR)\msp_fase4_sp_negocio.sql

PRINT '==== MSP install: patch documentos por contrato ====';
:r $(MSP_DB_DIR)\patch_documentos_por_contrato.sql

PRINT '==== MSP install: patch garantia pago documento ====';
:r $(MSP_DB_DIR)\patch_garantia_pago_documento.sql

PRINT '==== MSP install: garantia contra documentos de cobro ampliada ====';
:r $(MSP_DB_DIR)\patch_garantia_documentos_cobro.sql

PRINT '==== MSP install: eventos canonicos documentos de cobro ====';
:r $(MSP_DB_DIR)\patch_documentos_cobro_eventos.sql

PRINT '==== MSP install: patch traspaso contrato razon social ====';
:r $(MSP_DB_DIR)\patch_contrato_traspaso_razon_social.sql

PRINT '==== MSP install: patch contabilidad doble partida ====';
:r $(MSP_DB_DIR)\patch_contabilidad_doble_partida.sql

PRINT '==== MSP install: patch contabilidad devolucion garantia ====';
:r $(MSP_DB_DIR)\patch_contabilidad_devolucion_garantia.sql

PRINT '==== MSP install: patch SP operacion mensual ====';
:r $(MSP_DB_DIR)\patch_operacion_mensual_sp.sql

PRINT '==== MSP install: patch estado periodo (solo Borrador para generar/corregir) ====';
:r $(MSP_DB_DIR)\patch_periodo_estado_borrador_sp.sql

PRINT '==== MSP install: patch envio lotes programados ====';
:r $(MSP_DB_DIR)\patch_envio_lotes_programados.sql

PRINT '==== MSP install: patch pool documentos periodo ====';
:r $(MSP_DB_DIR)\patch_pool_documentos_periodo.sql

PRINT '==== MSP install: patch saldo favor lote origen ====';
:r $(MSP_DB_DIR)\patch_saldo_favor_lote_origen.sql

PRINT '==== MSP install: patch archivos pago contrato ====';
:r $(MSP_DB_DIR)\patch_pago_contrato_archivos.sql

PRINT '==== MSP install: patch pago contrato operacion general ====';
:r $(MSP_DB_DIR)\patch_pago_contrato_operacion_general.sql

PRINT '==== MSP install: patch archivos pdf generalizacion ====';
:r $(MSP_DB_DIR)\patch_archivos_pdf_generalizacion.sql

PRINT '==== MSP install: patch gestion bandeja global de pendientes ====';
:r $(MSP_DB_DIR)\patch_bandeja_pendientes_gestion.sql

PRINT '==== MSP install: patch gestion operacional de cobranza ====';
:r $(MSP_DB_DIR)\patch_gestion_cobranza_operacional.sql
:r \patch_convenios_pago_cuotas.sql

PRINT '==== MSP install: patch correcciones selectivas ====';
:r $(MSP_DB_DIR)\patch_correcciones_selectivas.sql

PRINT '==== MSP install: prorrata de inicio y modalidad CLP fija ====';
:r $(MSP_DB_DIR)\patch_arriendo_inicio_prorrata.sql
:r $(MSP_DB_DIR)\patch_clp_fijo_contrato.sql

PRINT '==== MSP install: cierre financiero con deuda histórica ====';
:r $(MSP_DB_DIR)\patch_cierre_deuda_historica.sql

PRINT '==== MSP install: saldo a favor y anulaciones por periodo ====';
:r $(MSP_DB_DIR)\patch_saldo_favor_anulaciones_periodo.sql

PRINT '==== MSP install: base operativa de tesoreria ====';
:r $(MSP_DB_DIR)\patch_garantias_tesoreria_base.sql
:r $(MSP_DB_DIR)\patch_garantias_resumen_recepcion_real.sql
:r $(MSP_DB_DIR)\patch_tesoreria_conciliacion_cierre.sql
:r $(MSP_DB_DIR)\patch_tesoreria_depositos.sql
:r $(MSP_DB_DIR)\patch_tesoreria_reapertura_caja.sql

PRINT '==== MSP install: garantias operativas, archivos, reportes y devoluciones ====';
:r $(MSP_DB_DIR)\patch_garantias_etapa1_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_devolucion_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_archivos_respaldo.sql
:r $(MSP_DB_DIR)\patch_garantias_reporte_control.sql
:r $(MSP_DB_DIR)\patch_garantias_etapa2_contabilidad.sql

PRINT '==== MSP install: configuracion de correo ====';
:r $(MSP_DB_DIR)\patch_configuracion_correo.sql

PRINT '==== MSP install: nota ====';
PRINT 'SQL Agent Job de envio lotes NO se instala automaticamente.';
PRINT 'Si necesitas ejecucion automatica, ejecutar manualmente patch_sql_agent_envio_lotes_job.sql con rutas/permiso sysadmin.';

PRINT '==== MSP install completado ====';






