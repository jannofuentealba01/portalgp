/*
===========================================================================
 MSP - MIGRADOR INCREMENTAL PARA PRODUCCION

 Destino: una base PortalGP existente con datos.

 Garantias de alcance:
 - no instala el esquema desde cero;
 - no ejecuta resets, limpiezas ni datos de demostracion;
 - no ejecuta patch_seguridad_acceso_etapa1.sql porque ese parche convierte
   todos los permisos existentes en lectura/escritura/eliminacion;
 - agrega solamente permisos cuyo nombre pertenece al MSP;
 - mantiene usuarios, credenciales y roles existentes.
===========================================================================
*/

:ON ERROR EXIT
:setvar MSP_DB_DIR "msp\db"

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET ARITHABORT ON;
SET NUMERIC_ROUNDABORT OFF;
GO

PRINT '==== MSP produccion: validacion de destino ====';
IF DB_NAME() IN (N'master', N'model', N'msdb', N'tempdb')
    THROW 50040, 'El migrador MSP no puede ejecutarse sobre una base de sistema.', 1;

IF OBJECT_ID(N'dbo.msp_contratos_arriendo', N'U') IS NULL
   OR OBJECT_ID(N'dbo.msp_documentos_cobro', N'U') IS NULL
    THROW 50041, 'No existe una instalacion MSP previa. No se aplico ningun cambio.', 1;

IF OBJECT_ID(N'dbo.cr_usuarios', N'U') IS NULL
   OR OBJECT_ID(N'dbo.cr_permisos', N'U') IS NULL
   OR OBJECT_ID(N'dbo.cr_rol_permisos', N'U') IS NULL
    THROW 50042, 'Faltan catalogos de seguridad requeridos por MSP.', 1;
GO

:r $(MSP_DB_DIR)\patch_schema_migrations.sql

PRINT '==== Documentos, pagos y saldos ====';
:r $(MSP_DB_DIR)\patch_pagos_por_concepto.sql
:r $(MSP_DB_DIR)\patch_prioridad_imputacion_pagos.sql
:r $(MSP_DB_DIR)\patch_documentos_cobro_uuid.sql
:r $(MSP_DB_DIR)\patch_documentos_por_contrato.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_tienda.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_periodo.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_anulaciones_periodo.sql
:r $(MSP_DB_DIR)\patch_saldo_favor_lote_origen.sql
:r $(MSP_DB_DIR)\patch_garantia_pago_documento.sql
:r $(MSP_DB_DIR)\patch_garantia_documentos_cobro.sql
:r $(MSP_DB_DIR)\patch_documentos_cobro_eventos.sql

PRINT '==== Normalizacion contrato-local y cargos ====';
:r $(MSP_DB_DIR)\msp_fase3_cargos_contrato_local.sql
:r $(MSP_DB_DIR)\msp_fase4_sp_negocio.sql

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
:r $(MSP_DB_DIR)\patch_cierre_mensual_transiciones.sql

PRINT '==== Operacion mensual y envios ====';
:r $(MSP_DB_DIR)\patch_catalogo_bancos.sql
:r $(MSP_DB_DIR)\patch_estado_operativo_control_diario.sql
:r $(MSP_DB_DIR)\patch_reglas_cobro_auto.sql
:r $(MSP_DB_DIR)\patch_operacion_mensual_sp.sql
:r $(MSP_DB_DIR)\patch_documentos_arriendo_independiente_servicios.sql
:r $(MSP_DB_DIR)\patch_documentos_arriendo_independiente_servicios_v2.sql
:r $(MSP_DB_DIR)\patch_periodo_estado_borrador_sp.sql
:r $(MSP_DB_DIR)\patch_pool_documentos_periodo.sql
:r $(MSP_DB_DIR)\patch_pool_documentos_solo_servicios.sql
:r $(MSP_DB_DIR)\patch_envio_lotes_programados.sql

PRINT '==== Garantias y tesoreria ====';
:r $(MSP_DB_DIR)\patch_garantias_tesoreria_base.sql
:r $(MSP_DB_DIR)\patch_garantias_devolucion_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_etapa1_operativa.sql
:r $(MSP_DB_DIR)\patch_garantias_resumen_recepcion_real.sql
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
:r $(MSP_DB_DIR)\patch_convenios_pago_cuotas.sql
:r $(MSP_DB_DIR)\patch_correcciones_selectivas.sql
:r $(MSP_DB_DIR)\patch_correcciones_selectivas_v2.sql
:r $(MSP_DB_DIR)\patch_tipo_cargo_obsoletos.sql
:r $(MSP_DB_DIR)\patch_multa_por_tienda.sql
:r $(MSP_DB_DIR)\patch_bandeja_pendientes_gestion.sql
:r $(MSP_DB_DIR)\patch_pago_contrato_archivos.sql
:r $(MSP_DB_DIR)\patch_pago_contrato_operacion_general.sql
:r $(MSP_DB_DIR)\patch_archivos_pdf_generalizacion.sql
:r $(MSP_DB_DIR)\patch_configuracion_correo.sql
:r $(MSP_DB_DIR)\patch_documentos_tienda_carga_masiva.sql
:r $(MSP_DB_DIR)\patch_documentos_tienda_etapa2.sql

PRINT '==== Cierre, vacancias y trazabilidad final ====';
:r $(MSP_DB_DIR)\patch_liquidacion_final.sql
:r $(MSP_DB_DIR)\patch_servicios_tardios_liquidacion.sql
:r $(MSP_DB_DIR)\patch_servicios_tardios_integracion.sql
:r $(MSP_DB_DIR)\patch_cierre_deuda_historica.sql
:r $(MSP_DB_DIR)\patch_comercial_vacancia_documentos.sql
:r $(MSP_DB_DIR)\patch_cierre_integracion_final.sql

PRINT '==== Permisos exclusivos MSP ====';
:r $(MSP_DB_DIR)\patch_permisos_msp_por_funcion.sql
:r $(MSP_DB_DIR)\production_seguridad_acceso_compatible.sql
:r $(MSP_DB_DIR)\patch_seguridad_acceso_etapa2.sql
:r $(MSP_DB_DIR)\patch_seguridad_financiera_etapa2.sql
:r $(MSP_DB_DIR)\production_permisos_msp_compatibles.sql

PRINT '==== Integridad financiera ====';
:r $(MSP_DB_DIR)\patch_seguridad_financiera_etapa4_puntos1_3.sql
:r $(MSP_DB_DIR)\patch_documentos_cobro_flujo_integral.sql
:r $(MSP_DB_DIR)\patch_auditoria_saldo_favor_historico.sql
:r $(MSP_DB_DIR)\patch_seguridad_financiera_etapa4_eliminacion_regeneracion_rollback.sql

PRINT '==== MSP produccion: migracion completada ====';
PRINT 'No se ejecutaron resets, datos demo ni ampliaciones globales de permisos.';
GO
