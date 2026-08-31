# Revisión Técnica MSP (PHP 8.3 + SQL Server + ERP)

## Alcance revisado
- Carpeta `db/` completa (instalador, fases, parches, triggers, vistas y SPs).
- Flujos críticos: `cobros/operacion_mensual.php`, `contratos/*.php`, `pagos/guardar.php`, `documentos_cobro/index.php`, `cobranza/registrar_pago.php`, `bootstrap.php`.

## Hallazgos críticos
1. Credenciales de BD embebidas en código fuente.
- Evidencia: `../db.php:4-8`.
- Riesgo ERP: exposición de acceso total a SQL Server, incumplimiento de segregación de secretos y riesgo de fuga operativa.
- Recomendación: mover a variables de entorno (`.env` + loader), usar usuario SQL de mínimo privilegio y rotar credenciales actuales.

2. Doble lógica de cierre financiero con criterios distintos entre PHP y SP.
- Evidencia PHP: `contratos/finalizar_cierre_financiero.php:122-145`.
- Evidencia SQL (más robusta): `db/msp_fase4_sp_negocio.sql:829-838`, `876+`.
- Riesgo ERP: falso bloqueo o inconsistencia en cierres por conteo duplicado de cargos legacy ya migrados.
- Recomendación: centralizar cierre en `dbo.msp_contrato_cerrar` y retirar la lógica duplicada en PHP.

## Hallazgos altos
3. Instalador no portable por ruta absoluta Windows.
- Evidencia: `db/msp_instalar_core.sql:17`.
- Impacto: falla en CI/CD, servidores Linux y entornos no WAMP.
- Recomendación: usar ruta relativa (`:r .\archivo.sql`) o variable externa requerida al invocar `sqlcmd`.

4. Mezcla de scripts base no idempotentes con parches en un mismo “core installer”.
- Evidencia: `db/msp_instalar_core.sql` invoca `msp_documento_pago.sql` + patches.
- Evidencia de base no idempotente: `db/msp_documento_pago.sql:92`, `141`, `179`, `224` (`CREATE TABLE` sin guardas).
- Impacto: reinstalaciones parciales frágiles y upgrades más riesgosos.
- Recomendación: separar claramente `baseline` vs `migrations` versionadas.

5. Uso de `MERGE` dentro de trigger para recalcular saldos.
- Evidencia: `db/patch_saldo_favor_tienda.sql:97-145`.
- Impacto: mayor complejidad de bloqueo/concurrencia en horas pico de caja.
- Recomendación: reemplazar por patrón `UPDATE ...; IF @@ROWCOUNT=0 INSERT ...` con `UPDLOCK/HOLDLOCK` controlado.

6. Flujo de pagos hace orquestación transaccional en PHP sobre SPs transaccionales.
- Evidencia: `pagos/guardar.php:186-233` + SPs en `db/patch_saldo_favor_tienda.sql`.
- Impacto: acoplamiento transaccional difícil de razonar y depurar (anidamiento).
- Recomendación: crear un SP orquestador único para “aplicar saldo + registrar pago” atómico.

7. SQL dinámico embebido en flujo mensual monolítico.
- Evidencia: `cobros/operacion_mensual.php:3410-3510`.
- Impacto: mantenibilidad baja, plan cache fragmentado, pruebas unitarias casi imposibles.
- Recomendación: mover generación a SPs versionados y dejar PHP como capa de coordinación/UI.

## Hallazgos medios
8. `cobros/operacion_mensual.php` concentra demasiada responsabilidad.
- Evidencia: archivo de `7586` líneas.
- Impacto: alto costo de cambio y riesgo de regresión funcional.
- Recomendación: dividir en servicios por caso de uso (periodo, lectura, cobro, documento, corrección, envío).

9. Verificaciones de metadatos repetidas por request (`INFORMATION_SCHEMA`).
- Evidencia: `bootstrap.php:394-440`.
- Impacto: overhead evitable en vistas de alto tráfico.
- Recomendación: cachear capacidades de esquema por request/session o resolver vía “feature flags” de versión de DB.

10. Reglas de negocio con fecha fija hardcodeada.
- Evidencia: `db/patch_reglas_cobro_auto.sql:138` (`2026-04-01`).
- Impacto ERP: necesidad de parche manual por vigencias futuras.
- Recomendación: parametrizar fecha de vigencia (tabla de configuración o seed por ambiente).

11. SPs de negocio de Fase 4 no están siendo aprovechados por los flujos PHP.
- Evidencia: `rg` sin llamadas desde `contratos/`, `cobros/`, `pagos/`, `cobranza/`.
- Impacto: duplicación de reglas entre app y BD.
- Recomendación: migrar endpoints a SPs de dominio y mantener una sola fuente de verdad.

## Fortalezas observadas
- Buen uso de `TRY/CATCH`, `XACT_ABORT ON` y `THROW` con códigos de negocio en SQL.
- Modelo de datos con `CHECK`, `UNIQUE`, FKs e índices operativos bien encaminado.
- Controles de seguridad web presentes (`msp2RequireAccess`, CSRF en `bootstrap.php`).
- Trazabilidad funcional de contratos (bitácora/historial) ya implementada.

## Prioridad sugerida (orden de ejecución)
1. Seguridad de credenciales y hardening de conexión SQL.
2. Unificar cierre financiero en SP (`msp_contrato_cerrar`) y ajustar endpoint PHP.
3. Separar baseline/migraciones y volver instalador portable.
4. Desacoplar `operacion_mensual.php` hacia SPs + servicios PHP más pequeños.
5. Optimizar triggers/saldos para concurrencia y observabilidad.
