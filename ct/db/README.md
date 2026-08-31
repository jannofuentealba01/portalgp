# Scripts DB - Modulo CT

## Estado actual

- El modelo oficial sigue el prefijo `ct_*` y se reconstruye desde scripts idempotentes por dominio.
- `Solicitudes` ya no usa el MVP legacy basado en `ct_solicitud_adquisicion_draft` ni `ct_solicitud_area_respuesta`.
- La estrategia de adopcion del rediseño es `DROP + INIT` o `FULL`; no hay backfill ni compatibilidad hacia atras para la capa de solicitudes.

## Orden recomendado

1. [10_ct_capa_predial.sql](/mnt/c/wamp64/www/portalgp/ct/db/10_ct_capa_predial.sql)
2. [15_ct_capa_solicitudes.sql](/mnt/c/wamp64/www/portalgp/ct/db/15_ct_capa_solicitudes.sql)
3. [20_ct_capa_construccion.sql](/mnt/c/wamp64/www/portalgp/ct/db/20_ct_capa_construccion.sql)
4. [30_ct_capa_tributaria.sql](/mnt/c/wamp64/www/portalgp/ct/db/30_ct_capa_tributaria.sql)
5. [40_ct_capa_contabilidad.sql](/mnt/c/wamp64/www/portalgp/ct/db/40_ct_capa_contabilidad.sql)
6. [50_ct_integridad.sql](/mnt/c/wamp64/www/portalgp/ct/db/50_ct_integridad.sql)
7. [11_ct_procedimientos.sql](/mnt/c/wamp64/www/portalgp/ct/db/11_ct_procedimientos.sql)
8. [16_ct_procedimientos_solicitudes.sql](/mnt/c/wamp64/www/portalgp/ct/db/16_ct_procedimientos_solicitudes.sql)
9. [21_ct_procedimientos.sql](/mnt/c/wamp64/www/portalgp/ct/db/21_ct_procedimientos.sql)
10. [31_ct_procedimientos.sql](/mnt/c/wamp64/www/portalgp/ct/db/31_ct_procedimientos.sql)
11. [41_ct_procedimientos.sql](/mnt/c/wamp64/www/portalgp/ct/db/41_ct_procedimientos.sql)

## Scripts operativos

- Limpiar datos manteniendo estructura: [00_ct_terrenos_limpiar_bd.sql](/mnt/c/wamp64/www/portalgp/ct/db/00_ct_terrenos_limpiar_bd.sql)
- Eliminar estructura completa `ct_*`: [99_ct_terrenos_drop_all.sql](/mnt/c/wamp64/www/portalgp/ct/db/99_ct_terrenos_drop_all.sql)
- Alias no destructivo: [core_ct.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct.sql)
- Flujo DROP: [core_ct_drop.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_drop.sql)
- Flujo INIT: [core_ct_init.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_init.sql)
- Flujo MIGRATE: [core_ct_migrate.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_migrate.sql)
- Flujo FULL (`DROP + INIT`): [core_ct_full.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_full.sql)
- Consultas base: [90_ct_consultas.sql](/mnt/c/wamp64/www/portalgp/ct/db/90_ct_consultas.sql)

## Camino recomendado para el rediseño de Solicitudes

Usar uno de estos flujos:

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_full.sql
```

o:

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_drop.sql
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_init.sql
```

`core_ct_migrate.sql` sigue disponible para cambios incrementales, pero si detecta tablas legacy de solicitudes fallara a proposito para forzar reconstruccion limpia.

## Flujos `sqlcmd`

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_drop.sql
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_init.sql
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_migrate.sql
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_full.sql
```

Desde otra ruta:

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -v ROOT="/ruta/a/ct" -i /ruta/a/ct/db/core_ct_full.sql
```

Desde SSMS en SQLCMD Mode:

```sql
USE [PORTALGP];
GO
:r "C:\wamp64\www\portalgp\ct\db\core_ct_full.sql"
GO
```

## Prerequisitos

- `dbo.cr_usuarios` sigue siendo la fuente corporativa para usuarios funcionales y asignados.
- Si `dbo.cr_usuarios` no existe, las FKs de usuario quedan pendientes con `PRINT` de aviso, igual que en las otras capas.

## Conexion

- `CT_DB_DSN`
- `CT_DB_USER`
- `CT_DB_PASSWORD`

Si `CT_DB_DSN` no esta definido, CT usa fallback a la conexion principal de `portalgp/db.php`.
