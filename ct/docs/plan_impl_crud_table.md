# Plan de Implementacion - Tabla Reutilizable CT

## Objetivo

Migrar un listado existente al componente `ctRenderCrudTable` con estilo empresarial, limpio y minimalista, sin romper la logica de negocio ni los hooks JS actuales.

## Alcance

- Estructura visual de listado (filtros, tabla, acciones, meta/paginacion).
- Estandar de acciones por fila: `1 principal visible + dropdown de secundarias`.
- Estilo uniforme de tabla (header unico, zebra suave, hover uniforme).

No incluye cambios de reglas de negocio, queries ni flujos de dominio.

## Pre requisitos

1. Modulo con `*_page.php` y `views/list.php`.
2. Datos del listado ya disponibles en arrays PHP.
3. CSS del modulo en `assets/<modulo>.css`.
4. JS del modulo identificado (selectores/clases a preservar).

## Fase 1 - Preparar pagina

Archivo: `ct/<modulo>/<submodulo>/*_page.php`

1. Agregar:
   - `require_once dirname(__DIR__, 2) . '/templates/components/crud_table.php';`
2. Verificar que cargue:
   - `ct/assets/ct_crud.css`
   - CSS propio del modulo.

## Fase 2 - Migrar vista de listado

Archivo: `ct/<modulo>/<submodulo>/views/list.php`

1. Mapear dataset a `rows` (array de filas listas para render).
2. Definir `columns`:
   - `key`, `label`, `sort_url`, `sort_icon`, `render`, `cell_class`.
3. Definir `filters`:
   - `form_attrs`, `fields`, `actions`.
4. Definir `actions` por fila:
   - `primary` (accion principal).
   - `secondary` (dropdown con resto de acciones).
5. Definir `meta`:
   - resumen + paginacion.
6. Reemplazar markup manual por:
   - `ctRenderCrudTable([...]);`

## Fase 3 - Estilo profesional de tabla

Archivo: `ct/<modulo>/<submodulo>/assets/<modulo>.css`

Aplicar scope en la seccion del listado:

- Clase de contenedor: `ct-theme-enterprise`.
- Reglas recomendadas:
  - header unico (un color),
  - zebra suave,
  - hover uniforme,
  - acciones con botones compactos consistentes.

Importante: evitar estilos por `nth-child` si el HTML es dinamico. Preferir clases en columna (`header_class`, `cell_class`) cuando se requiera control fino.

## Fase 4 - Compatibilidad JS

1. Identificar selectores en JS (`.ct-btn-*`, `#ct-*`, `.js-open-modal-*`).
2. Mantener mismos IDs/clases en la nueva configuracion.
3. Verificar eventos de:
   - editar,
   - eliminar,
   - modales,
   - filtros.

## Fase 5 - Validacion tecnica

1. Lint PHP de archivos tocados:
   - `php -l <archivo>`
2. Revisar visual en desktop y mobile.
3. Verificar:
   - orden por columnas,
   - paginacion,
   - filtros,
   - acciones principales y secundarias.
4. Si no refleja cambios CSS, versionar asset con `filemtime`.

## Checklist de cierre

1. `*_page.php` incluye `crud_table.php`.
2. `views/list.php` usa `ctRenderCrudTable`.
3. Hooks JS preservados.
4. Tabla con estilo profesional (header unico + zebra + hover).
5. `php -l` OK en todos los archivos editados.
6. Prueba funcional manual OK.

## Prompt reutilizable para IA

```txt
Implementa migracion de [RUTA_MODULO] al componente ctRenderCrudTable.

Objetivo visual:
- Empresarial, limpio, minimalista.
- Header de tabla con un solo color uniforme.
- Filas zebra suaves y hover uniforme.

Reglas tecnicas:
- No modificar logica de negocio ni consultas.
- Mantener IDs y clases usados por JS actual.
- Acciones por fila: 1 principal visible + dropdown secundario.
- Scope de estilos al modulo (evitar impacto global).

Entregables:
1) Cambios en *_page.php (require del componente)
2) Cambios en views/list.php (config completa ctRenderCrudTable)
3) Cambios en assets/*.css del modulo
4) Validacion php -l de todos los archivos tocados
5) Resumen final con rutas y puntos validados
```

