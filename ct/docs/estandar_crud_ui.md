# Estandar CRUD UI - CT

## Objetivo

Definir un estandar visual y tecnico unico para todos los CRUD del modulo CT.

Este documento es la referencia base para nuevos modulos y refactors.

## Base tecnica obligatoria

Todo CRUD nuevo debe cargar:

- `assets/ct_forms.css`
- `assets/ct_crud.css`
- componentes `templates/components/*` reutilizables (labels, switches, searchable-select, confirm modal, flash toast)
- para listados nuevos, usar `templates/components/crud_table.php` (`ctRenderCrudTable`)

## Estructura de pagina recomendada

Orden sugerido:

1. `Toolbar` (titulo, hint, acciones primarias)
2. `Filtros` (busqueda + selects + submit)
3. `Tabs de vista` (`tabla` / `cards` si aplica)
4. `Contenido principal` (tabla o cards)
5. `Resumen + paginacion`
6. `Modales` (crear/editar/eliminar/import)

## Convenciones de clases

Para consistencia de nombres:

- Bloques CRUD comunes: `ct-crud-*`
- Bloques de modulo especifico: `ct-<modulo>-*`

Ejemplos:

- `ct-crud-toolbar`, `ct-crud-filters`, `ct-crud-table`, `ct-crud-actions`
- `ct-terceros-...`, `ct-terrenos-...`, `ct-ventas-...`

## Componente reusable de tabla

API base:

- `ctRenderCrudTable(array $config): void`

Bloques esperados en `$config`:

- `filters`: formulario integrado (campos declarativos o `type=custom` con render callback)
- `columns`: definición de cabeceras (`label`, `key`, `sort_url`, `sort_icon`, `render`)
- `rows`: filas del módulo
- `actions`: columna de acciones por fila con patrón estándar:
  - `primary`: 1 acción visible principal
  - `secondary`: menú desplegable con acciones secundarias
- `meta`: resumen + paginación

Helpers comunes disponibles:

- `ctCrudBuildQuery()`
- `ctCrudSortLink()`
- `ctCrudSortIcon()`

## Semantica de estados

Estados soportados por defecto:

- `ok`
- `warning`
- `error`
- `omitido`
- `sin_cambios`

Representacion visual:

- chips con `.ct-crud-pill-*`
- color de fila (opcional) con clase del modulo
- texto corto y estable (no variable por modulo)

## Reglas de UX

- Boton principal a la derecha (`Registrar`, `Crear`, `Nuevo`)
- Acciones secundarias en outline
- Siempre mostrar feedback de conteos (`total`, `ready`, `error`, etc.)
- Inputs obligatorios indicados con `ctRenderFieldLabel(..., true)`
- Soporte teclado en elementos custom (dropzone, toggles, filtros)
- `toast` para resultado de acciones y errores puntuales

## Reglas de animacion

Animaciones permitidas:

- entrada suave de bloques (`.ct-crud-fade-in`)
- hover/focus de inputs y dropzones

No permitido:

- animaciones largas, rebotes, loops decorativos

Accesibilidad:

- respetar `prefers-reduced-motion`

## Reglas de JS

Estructura recomendada por modulo:

- `filters` (submit automatico / enter)
- `crudModals` (hydrate create/edit/delete)
- `formatters` (rut, montos, fechas)
- `importPreview` (si aplica)

Evitar:

- funciones gigantes monoliticas sin secciones
- mezclar logica de negocio con manipulación visual

## Checklist de aceptacion para nuevos CRUD

Antes de cerrar un modulo:

1. Usa `ct-crud.css` + `ct_forms.css`.
2. Tiene toolbar + filtros + tabla/cards + paginacion consistentes.
3. Estados visuales uniformes (`ok/warning/error/...`).
4. Acciones con iconos y labels accesibles.
5. Flujos de modal con `ctCsrfField()`.
6. Mensajes de resultado con flash toast.
7. Mobile usable (<=768px).
8. Sin valores de color/spacer duplicados innecesarios fuera del estandar.

## Backlog de estandarizacion (prioridad)

1. Migrar `predial/terceros` para usar clases base `ct-crud-*` en todos los bloques (hoy hay mezcla con `ct-terceros-*`).
2. Adoptar `ctRenderCrudTable` en `predial/terceros`, `contabilidad` y catálogos administrativos.
3. Crear partial reusable para `resumen de preview import`.
4. Extraer utilidades JS comunes de CRUD a `assets/ct_crud.js` (fase 2).
