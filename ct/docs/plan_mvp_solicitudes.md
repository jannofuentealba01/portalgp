# Arquitectura BD CT - Solicitudes Sin Compatibilidad Hacia Atras

## Objetivo

Rehacer la capa `Solicitudes` de CT como una base limpia, con estrategia `drop + rebuild`, sin arrastrar tablas ni contratos del MVP anterior.

El entregable implementado en SQL deja:

- `Adquisicion` modelada con formularios tipados
- workflow por areas y asignaciones materializadas
- trazabilidad formal entre solicitud, estados, comentarios, adjuntos y notificaciones
- estructura preparada para `Fusion` y `Subdivision`
- `Terrenos` como sistema materializado final

## Decision de arquitectura

La BD queda separada por dominios:

- `predial`: terrenos, operaciones y titularidades
- `solicitudes`: intencion de negocio, workflow, formularios, revisiones y outbox
- `construccion`, `tributaria` y `comercial`: dominios paralelos, no dueños del workflow

La capa nueva abandona:

- `ct_solicitud_adquisicion_draft`
- `ct_solicitud_area_respuesta`
- `ct_solicitud_participante` del MVP anterior
- dependencia estructural de `payload_json` como contrato principal

Si esas tablas existen, el nuevo script falla a proposito y obliga a usar reconstruccion completa.

## Modelo implementado

### Catalogos y permisos

- `ct_tipo_solicitud`
- `ct_estado_solicitud`
- `ct_area_solicitud`
- `ct_estado_area_solicitud`
- `ct_rol_solicitud`
- `ct_usuario_rol_solicitud`

Seed inicial:

- tipos: `ADQUISICION`, `FUSION`, `SUBDIVISION`, `MODIFICACION`
- estados solicitud: `BORRADOR`, `EN_REVISION`, `CON_OBSERVACIONES`, `LISTA_PARA_APROBAR`, `APROBADA`, `ANULADA`
- estados area: `PENDIENTE`, `HABILITADA`, `EN_PROCESO`, `CON_OBSERVACIONES`, `COMPLETA`, `CERRADA`
- areas base seed CT: `LEGAL`, `ARQUITECTURA` (legacy de la capa inicial)
- areas operativas vigentes: catalogadas en `cr_departamentos` (incluye `COMERCIAL` cuando aplica)
- rol funcional: `GERENTE_SOLICITUD`

### Workflow y trazabilidad

- `ct_solicitud`
- `ct_participante_solicitud`
- `ct_solicitud_terreno`
- `ct_solicitud_area_instancia`
- `ct_solicitud_area_asignacion`
- `ct_solicitud_historial_estado`
- `ct_solicitud_comentario`
- `ct_solicitud_adjunto`
- `ct_solicitud_notificacion`

Reglas estructurales principales:

- una solicitud representa una intencion de negocio
- la relacion con terrenos se formaliza por `ct_solicitud_terreno`
- cada area requerida se materializa en `ct_solicitud_area_instancia`
- la asignacion de usuarios vive en `ct_solicitud_area_asignacion`
- el historial registra cambios de solicitud y de area
- la outbox de correo queda en `ct_solicitud_notificacion`

### Formularios tipados y versionados

- `ct_formulario_plantilla`
- `ct_formulario_plantilla_version`
- `ct_solicitud_tipo_area`
- `ct_solicitud_tipo_area_participante_default`
- `ct_solicitud_adquisicion`
- `ct_solicitud_adquisicion_titular`
- `ct_solicitud_adquisicion_legal`
- `ct_solicitud_adquisicion_arquitectura`

Seed inicial de plantillas:

- `SOLICITUD_ADQUISICION_GENERAL`
- `SOLICITUD_ADQUISICION_LEGAL`
- `SOLICITUD_ADQUISICION_ARQUITECTURA`

Cada solicitud de `ADQUISICION` queda configurada con:

- area `LEGAL`
- area `ARQUITECTURA`

ambas requeridas y con plantilla propia.

## Automatizacion incluida

Se agrego [16_ct_procedimientos_solicitudes.sql](/mnt/c/wamp64/www/portalgp/ct/db/16_ct_procedimientos_solicitudes.sql) con:

- `sp_ct_solicitud_recalcular_estado`
- trigger de historial para cambios de estado en `ct_solicitud`
- trigger de historial para cambios de estado en `ct_solicitud_area_instancia`
- triggers de recalculo al tocar formularios tipados y titulares

El recalculo actual cubre el MVP de `Adquisicion`:

- `BORRADOR` si no hay actividad
- `EN_REVISION` si hay carga parcial
- `CON_OBSERVACIONES` si un area requerida esta observada
- `LISTA_PARA_APROBAR` si formulario general, titulares y areas requeridas estan completos

`APROBADA` y `ANULADA` quedan como estados terminales respetados por el recálculo.

## Scripts oficiales

### Creacion base

- [core_ct_init.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_init.sql)
- [core_ct_full.sql](/mnt/c/wamp64/www/portalgp/ct/db/core_ct_full.sql)

### Capa nueva

- [15_ct_capa_solicitudes.sql](/mnt/c/wamp64/www/portalgp/ct/db/15_ct_capa_solicitudes.sql)
- [16_ct_procedimientos_solicitudes.sql](/mnt/c/wamp64/www/portalgp/ct/db/16_ct_procedimientos_solicitudes.sql)

### Migraciones incrementales relevantes

- [17_ct_solicitudes_migracion_cr_departamentos.sql](/mnt/c/wamp64/www/portalgp/ct/db/17_ct_solicitudes_migracion_cr_departamentos.sql)
- [2026_05_13_tipo_area_reglas_por_negocio.sql](/mnt/c/wamp64/www/portalgp/ct/db/migrate/2026_05_13_tipo_area_reglas_por_negocio.sql)

## Camino de ejecucion recomendado

Para adoptar el rediseño:

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_full.sql
```

o bien:

```bash
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_drop.sql
sqlcmd -S <SERVER> -d <DATABASE> -E -i db/core_ct_init.sql
```

`core_ct_migrate.sql` solo sirve para incrementales sobre el modelo nuevo. Si encuentra el esquema viejo de solicitudes, corta con error deliberado.

## Alcance pendiente fuera de este entregable

- materializacion final de aprobacion desde solicitudes hacia `ct_terreno` en el codigo PHP
- reapertura automatica por observacion del gerente
- defaults efectivos de participantes por plantilla con usuarios corporativos reales
- UI y servicios de `Fusion`, `Subdivision` y `Modificacion`
- diseno de subtipo para `MODIFICACION` (catalogo de tipo de cambio + reglas tipo/subtipo -> areas)
- integracion operativa de notificaciones salientes

## Criterio de exito tecnico

La BD queda lista para:

- creacion limpia desde cero
- crear solicitudes de adquisicion con formularios tipados
- mantener version exacta de plantilla por area
- asignar usuarios por area sin roles globales obligatorios
- trazar estados, comentarios, adjuntos y notificaciones
- evolucionar formularios futuros sin romper solicitudes historicas
