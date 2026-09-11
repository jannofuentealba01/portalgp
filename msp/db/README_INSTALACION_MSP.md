# Instalacion MSP (`db/`)

## 1) Caso BD limpia (recomendado)

Ejecuta el instalador core en este orden:

```bash
sqlcmd -S <server> -d <database> -E -b -i db/msp_instalar_core.sql
```

Si usas usuario/password:

```bash
sqlcmd -S <server> -d <database> -U <user> -P <password> -b -i db/msp_instalar_core.sql
```

`msp_instalar_core.sql` aplica, en orden:
- `msp_agrupacion_locales.sql`
- `msp_cobro_servicios.sql`
- `msp_documento_pago.sql`
- `patch_pagos_por_concepto.sql`
- `patch_saldo_favor_tienda.sql`
- `patch_saldo_favor_periodo.sql`
- `patch_reglas_cobro_auto.sql`
- `patch_catalogo_bancos.sql`
- `msp_deudores_garantia.sql`
- `patch_dia_cobro_fijo.sql`
- `patch_contrato_termino_efectivo.sql`
- `patch_contrato_indices_operativos.sql`
- `patch_tiendas_fecha_termino.sql`
- `patch_bitacora_cierre_contrato.sql`
- `patch_historial_contrato.sql`
- `msp_fase1_contrato_locales.sql`
- `msp_fase2_garantia_contrato_local.sql`
- `msp_fase3_cargos_contrato_local.sql`
- `msp_fase4_sp_negocio.sql`
- `patch_documentos_por_contrato.sql`
- `patch_garantia_pago_documento.sql`
- `patch_contrato_traspaso_razon_social.sql`
- `patch_contabilidad_doble_partida.sql`
- `patch_operacion_mensual_sp.sql`
- `patch_periodo_estado_borrador_sp.sql`
- `patch_envio_lotes_programados.sql`
- `patch_pool_documentos_periodo.sql`
- `patch_saldo_favor_lote_origen.sql`
- `patch_pago_contrato_archivos.sql`
- `patch_pago_contrato_operacion_general.sql`
- `patch_archivos_pdf_generalizacion.sql`
- `patch_garantias_tesoreria_base.sql`
- `patch_tesoreria_depositos.sql`
- `patch_garantias_devolucion_operativa.sql`
- `patch_contabilidad_devolucion_garantia.sql`
- `patch_garantias_archivos_respaldo.sql`
- `patch_tesoreria_conciliacion_cierre.sql`
- `patch_garantias_reporte_control.sql`

## 2) Caso BD existente (upgrade)

No ejecutes A1/A21/A22 de nuevo sin evaluar estado actual.

Opción recomendada (nuevo):

```bash
sqlcmd -S <server> -d <database> -E -b -i db/core_msp_migrate.sql
```

Aplica solo incrementales:

```sql
:r .\patch_pagos_por_concepto.sql
:r .\patch_saldo_favor_tienda.sql
:r .\patch_saldo_favor_periodo.sql
:r .\patch_reglas_cobro_auto.sql
:r .\patch_catalogo_bancos.sql
:r .\patch_dia_cobro_fijo.sql
:r .\patch_contrato_termino_efectivo.sql
:r .\patch_contrato_indices_operativos.sql
:r .\patch_tiendas_fecha_termino.sql
:r .\patch_bitacora_cierre_contrato.sql
:r .\patch_historial_contrato.sql
:r .\msp_fase1_contrato_locales.sql
:r .\msp_fase2_garantia_contrato_local.sql
:r .\msp_fase3_cargos_contrato_local.sql
:r .\msp_fase4_sp_negocio.sql
:r .\patch_documentos_por_contrato.sql
:r .\patch_garantia_pago_documento.sql
:r .\patch_contrato_traspaso_razon_social.sql
:r .\patch_contabilidad_doble_partida.sql
:r .\patch_operacion_mensual_sp.sql
:r .\patch_periodo_estado_borrador_sp.sql
:r .\patch_envio_lotes_programados.sql
:r .\patch_pool_documentos_periodo.sql
:r .\patch_saldo_favor_lote_origen.sql
:r .\patch_pago_contrato_archivos.sql
:r .\patch_pago_contrato_operacion_general.sql
:r .\patch_archivos_pdf_generalizacion.sql
```

## 3) Reset completo (solo ambiente dev)

```bash
sqlcmd -S <server> -d <database> -E -b -i db/msp_limpiar.sql
sqlcmd -S <server> -d <database> -E -b -i db/msp_instalar_core.sql
```

Alternativa equivalente:

```bash
sqlcmd -S <server> -d <database> -E -b -i db/core_msp_full.sql
```

## 4) Nota sobre `:r`

Los `:r` funcionan en:
- `sqlcmd` (CLI)
- SSMS con `Query -> SQLCMD Mode` habilitado

Si no usas SQLCMD mode, ejecuta cada `.sql` manualmente en el orden indicado.

## 5) Envío automático de lotes (SQL Server Agent)

`initial_msp.sql` y `msp_instalar_core.sql` no crean automáticamente el Job de SQL Agent.

Esto es intencional porque el Job requiere configuración por ambiente:
- ruta de `php.exe` en el servidor
- ruta de `worker_envio_lotes.php`
- permisos de `sysadmin` en SQL Server Agent

Para habilitar ejecución automática de lotes programados, ejecutar manualmente:

```sql
:r .\patch_sql_agent_envio_lotes_job.sql
```

Antes de ejecutar, edita variables `@PhpExePath` y `@WorkerScriptPath` dentro de `patch_sql_agent_envio_lotes_job.sql`.

## 6) Registro y verificación permanente de parches

El migrador incremental incluye `patch_schema_migrations.sql`, que crea el
registro `dbo.msp_schema_migrations`. Después de una migración exitosa, registra
la huella SHA-256 exacta de cada parche y ejecuta la auditoría completa:

```bash
php msp/db/verificar_instalacion.php --registrar
```

Para comprobaciones posteriores usa el modo de solo lectura:

```bash
php msp/db/verificar_instalacion.php
```

La verificación falla si detecta un parche operativo fuera del migrador, un
archivo modificado después de aplicarse, objetos o columnas ausentes, o permisos
MSP sin instalar. También revisa los índices declarados por los parches.

`--registrar` debe utilizarse únicamente después de que `core_msp_migrate.sql`
haya terminado sin errores. El Job de SQL Server Agent se mantiene como parche
opcional porque sus rutas dependen del servidor donde se despliegue MSP.

## 7) Cuenta técnica de ejecución local

La aplicación web debe operar con la cuenta restringida `portalgp_runtime`, no
con la identidad Windows que administra SQL Server. Para crear o rotar esta
cuenta y generar la configuración en el almacén externo de secretos, ejecuta desde
una sesión Windows autorizada:

```bash
php scripts/provision_runtime_database.php
```

La cuenta técnica recibe permisos específicos sobre los objetos actuales de la
aplicación; no recibe permisos globales sobre el esquema. Puede consultar y
modificar datos y ejecutar los procedimientos autorizados,
pero no es `sysadmin`, no pertenece a `db_owner`, no puede crear objetos ni
alterar el registro de migraciones. Las migraciones siguen siendo una tarea
administrativa explícita. En PowerShell, una ejecución administrativa puntual
puede usar la conexión integrada así:

```powershell
$env:PORTALGP_DB_INTEGRATED='1'
php msp/db/verificar_instalacion.php --registrar
Remove-Item Env:PORTALGP_DB_INTEGRATED
```

## 8) Integridad financiera: etapa 4, puntos 1 a 3

El migrador también ejecuta
`patch_seguridad_financiera_etapa4_puntos1_3.sql`. Este parche protege pagos,
garantías y saldos a favor mediante restricciones, índices y triggers de
integridad. Además, corrige la reversa de garantías para conservar el movimiento
original y registrar una única contrapartida compensatoria, sin duplicar el
saldo recuperado.

Después de migrar, la comprobación específica puede ejecutarse con:

```bash
php tests/security_financial_stage4_points1_3.php
```

La auditoría y las decisiones de conservación histórica se documentan en
`msp/SEGURIDAD_ETAPA4_PUNTOS1_3.md`.
