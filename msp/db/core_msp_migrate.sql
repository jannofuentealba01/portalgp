/* MSP - MIGRADOR INCREMENTAL SEGURO
   Para bases MSP existentes con datos. No elimina datos ni crea el esquema
   base. Para una base nueva usar msp_instalar_core.sql.
   sqlcmd -S <SERVER> -d <DATABASE> -E -b -i db/core_msp_migrate.sql
*/
:ON ERROR EXIT
:setvar MSP_DB_DIR "msp\db"
PRINT '==== MSP migracion incremental: inicio ====';
IF OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL OR OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NULL
BEGIN
    THROW 50030, 'No existe una base MSP instalada. Para una base nueva ejecuta msp_instalar_core.sql.', 1;
END;
GO

PRINT '==== Documentos, pagos y saldos ====';
:r $(MSP_DB_DIR)\patch_pagos_por_concepto.sql
:r $(MSP_DB_DIR)\patch_documentos_cobro_uuid.sql
:r $(MSP_DB_DIR)\patch_documentos_por_contrato.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_tienda.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_periodo.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_anulaciones_periodo.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_lote_origen.sql
:r $(MSP_DB_DIR)\patch_garantia_pago_documento.sql
:r $(MSP_DB_DIR)\patch_garantia_documentos_cobro.sql
:r $(MSP_DB_DIR)\patch_documentos_cobro_eventos.sql

PRINT '==== Contratos, arriendos y cierre ====';
:r $(MSP_DB_DIR)\patch_dia_cobro_fijo.sql
:r $(MSP_DB_DIR)\patch_contrato_termino_efectivo.sql
:r $(MSP_DB_DIR)\patch_contrato_indices_operativos.sql
:r $(MSP_DB_DIR)\patch_tiendas_fecha_termino.sql
:r $(MSP_DB_DIR)\patch_bitacora_cierre_contrato.sql
:r $(MSP_DB_DIR)\patch_historial_contrato.sql
:r $(MSP_DB_DIR)\patch_contrato_traspaso_razon_social.sql
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase1.sql
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase2.sql
:r $(MSP_DB_DIR)\patch_arriendo_modalidades_fase3.sql
:r $(MSP_DB_DIR)\patch_arriendo_descuentos_entidad.sql
:r $(MSP_DB_DIR)\patch_arriendo_inicio_prorrata.sql
:r $(MSP_DB_DIR)\patch_arriendo_termino_prorrata.sql
:r $(MSP_DB_DIR)\patch_clp_fijo_contrato.sql

PRINT '==== Operacion mensual y envios ====';
:r $(MSP_DB_DIR)\patch_catalogo_bancos.sql
:r $(MSP_DB_DIR)\patch_estado_operativo_control_diario.sql
:r $(MSP_DB_DIR)\patch_reglas_cobro_auto.sql
:r $(MSP_DB_DIR)\patch_operacion_mensual_sp.sql
:r $(MSP_DB_DIR)\patch_periodo_estado_borrador_sp.sql
:r $(MSP_DB_DIR)\patch_pool_documentos_periodo.sql
:r $(MSP_DB_DIR)\patch_envio_lotes_programados.sql

PRINT '==== Garantias y tesoreria ====';
:r $(MSP_DB_DIR)\patch_garantias_tesoreria_base.sql
:r $(MSP_DB_DIR)\patch_garantias_etapa1_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_devolucion_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_archivos_respaldo.sql
:r $(MSP_DB_DIR)\patch_garantias_reporte_control.sql
:r $(MSP_DB_DIR)\patch_tesoreria_conciliacion_cierre.sql
:r $(MSP_DB_DIR)\patch_tesoreria_depositos.sql
:r $(MSP_DB_DIR)\patch_tesoreria_reapertura_caja.sql

PRINT '==== Contabilidad ====';
:r $(MSP_DB_DIR)\patch_contabilidad_doble_partida.sql
:r $(MSP_DB_DIR)\patch_contabilidad_devolucion_garantia.sql
:r $(MSP_DB_DIR)\patch_garantias_etapa2_contabilidad.sql

PRINT '==== Cobranza, correcciones y soporte ====';
:r $(MSP_DB_DIR)\patch_gestion_cobranza_operacional.sql
:r \patch_convenios_pago_cuotas.sql
:r $(MSP_DB_DIR)\patch_correcciones_selectivas.sql
:r $(MSP_DB_DIR)\patch_tipo_cargo_obsoletos.sql
:r $(MSP_DB_DIR)\patch_bandeja_pendientes_gestion.sql
:r $(MSP_DB_DIR)\patch_pago_contrato_archivos.sql
:r $(MSP_DB_DIR)\patch_pago_contrato_operacion_general.sql
:r $(MSP_DB_DIR)\patch_archivos_pdf_generalizacion.sql
:r $(MSP_DB_DIR)\patch_configuracion_correo.sql

PRINT '==== MSP migracion incremental: completada ====';
PRINT 'El Job de SQL Agent requiere ejecucion separada con permisos sysadmin.';
GO






