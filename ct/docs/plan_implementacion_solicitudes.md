# Plan de implementacion - Solicitudes CT

Este plan baja `plan_mvp_solicitudes.md` a fases de implementacion en PHP + SQL.

## Fase 1 - Compatibilidad con modelo nuevo

Objetivo: que el modulo existente deje de depender del esquema legacy y vuelva a operar sobre las tablas nuevas.

Incluye:

- Crear solicitudes `ADQUISICION` usando `ct_solicitud.id_gerente_usuario`.
- Crear instancias de area desde `ct_solicitud_tipo_area`.
- Guardar formulario general en `ct_solicitud_adquisicion`.
- Guardar titulares en `ct_solicitud_adquisicion_titular`.
- Asignar usuarios por area en `ct_solicitud_area_asignacion`.
- Guardar respuestas de Legal/Arquitectura en tablas tipadas.
- Guardar comentarios y adjuntos usando `id_area_instancia`.

Estado: implementado como primera pasada.

## Fase 2 - UI especifica por area

Objetivo: reemplazar el textarea JSON por formularios reales.

Incluye:

- Formulario Legal con campos de `ct_solicitud_adquisicion_legal`.
- Formulario Arquitectura con campos de `ct_solicitud_adquisicion_arquitectura`.
- Validaciones por area antes de marcar como completa.
- Indicadores claros de pendiente, en proceso, observado y completo.

Estado: pendiente.

## Fase 3 - Aprobacion y materializacion

Objetivo: cerrar el flujo de adquisicion hacia `ct_terreno`.

Incluye:

- Validar estado `LISTA_PARA_APROBAR` antes de aprobar.
- Materializar terreno y operacion predial.
- Registrar relacion en `ct_solicitud_terreno`.
- Bloquear cambios en estados terminales.
- Dejar trazabilidad completa en historial.

Estado: parcialmente soportado; falta alinear completamente con el modelo nuevo.

## Fase 4 - Notificaciones y outbox

Objetivo: hacer operativa `ct_solicitud_notificacion`.

Incluye:

- Crear notificaciones al asignar areas.
- Crear notificaciones por observacion/completitud.
- Worker o proceso manual para despachar pendientes.
- Registro de intentos y errores.

Estado: pendiente.

## Fase 5 - Nuevos tipos de solicitud

Objetivo: habilitar `FUSION`, `SUBDIVISION` y `MODIFICACION`.

Incluye:

- Formularios tipados por tipo.
- Reglas de terrenos origen/resultado.
- Materializacion hacia operaciones prediales existentes.
- Vistas especializadas por tipo.

Estado: pendiente.
