# DBML CT

Modelos DBML separados por dominio para la base `ct_*`, construidos desde los scripts oficiales en `ct/db`.

## Fuente oficial

- `10_ct_capa_predial.sql`
- `15_ct_capa_solicitudes.sql`
- `20_ct_capa_construccion.sql`
- `30_ct_capa_tributaria.sql`
- `40_ct_capa_contabilidad.sql`
- `50_ct_integridad.sql`

## Archivos

- `ct_predial.dbml`: territorio / base predial
- `ct_solicitudes.dbml`: rediseño limpio del workflow de solicitudes
- `ct_construccion_legal.dbml`: proyectos, construcciones y ficha arquitectura/legal
- `ct_tributaria.dbml`: estado tributario y avaluos
- `ct_contabilidad.dbml`: tasaciones, ventas, usufructos e hipotecas

## Nota de arquitectura

El módulo de `Solicitudes` refleja el rediseño documentado en `ct/docs/plan_mvp_solicitudes.md`:

- sin compatibilidad hacia atrás con el MVP legacy
- workflow materializado por áreas
- formularios tipados y versionados
- trazabilidad formal de estados, comentarios, adjuntos y notificaciones

Las relaciones a `dbo.cr_usuarios` se representan con una tabla stub mínima cuando aplica, porque CT depende de usuarios corporativos definidos fuera de la capa `ct_*`.
