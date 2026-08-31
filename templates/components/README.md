# Componentes globales del portal

Esta carpeta contiene componentes reutilizables a nivel global del portal. La idea es que nuevos modulos usen `templates/components/` en vez de depender de componentes internos de `msp` o `ct`.

## Uso base

Incluye todo el set:

```php
require_once __DIR__ . '/templates/components/index.php';
```

O incluye solo el componente que necesitas:

```php
require_once __DIR__ . '/templates/components/searchable_select.php';
```

Los componentes globales usan prefijo `gp` para evitar choques con helpers de modulos existentes.

## Componentes disponibles

### `helpers.php`

Helpers compartidos de escape HTML, render de atributos y normalizacion de variantes Bootstrap. Es dependencia interna del resto de componentes.

Funciones principales:

- `gpComponentEscape($value)`
- `gpComponentAttrs(array $attrs)`
- `gpComponentVariant(string $type)`
- `gpComponentIconForVariant(string $type)`

### `form_field.php`

Utilidades pequenas para labels, ayudas y errores de campos. Sirve para mantener el mismo lenguaje visual de obligatorio/opcional.

Funciones principales:

- `gpRenderFieldLabel(string $text, bool $required = false, string $optionalText = 'opcional', string $for = '')`
- `gpRenderFieldHint(string $text)`
- `gpRenderFieldError(string $id, string $text, bool $hidden = true)`

### `form_switch.php`

Renderiza un switch Bootstrap para estados booleanos, flags o configuraciones simples.

Funcion principal:

- `gpRenderFormSwitch(array $options)`

Ejemplo:

```php
gpRenderFormSwitch([
    'id' => 'activo',
    'name' => 'activo',
    'label' => 'Activo',
    'checked' => true,
]);
```

### `flash.php`

Renderiza una alerta Bootstrap a partir de un arreglo simple `type/message`.

Funcion principal:

- `gpRenderFlash(?array $flash)`

Ejemplo:

```php
gpRenderFlash(['type' => 'success', 'message' => 'Cambios guardados.']);
```

### `flash_toast.php`

Renderiza un toast Bootstrap temporal para mensajes no bloqueantes. Incluye sus assets JS una sola vez.

Funcion principal:

- `gpRenderFlashToast(?array $toastFlash)`

### `undo_toast.php`

Renderiza un toast con formulario de deshacer. Util para eliminaciones reversibles o acciones temporales.

Funcion principal:

- `gpRenderUndoToast(?array $undoToast)`

### `monto_clp_input.php`

Campo de monto en pesos chilenos con prefijo `$`, sanitizacion de entrada y formato `es-CL` al perder foco.

Funciones principales:

- `gpRenderMontoClpField(array $options)`
- `gpMontoClpFormatValue(string $value, int $decimals = 2)`

### `searchable_select.php`

Select buscable basado en dropdown Bootstrap. Usa un input hidden para enviar el valor real, tiene busqueda interna, navegacion por teclado y scroll interno configurable.

Funcion principal:

- `gpRenderSearchableSelectField(array $options)`

Ejemplo:

```php
gpRenderSearchableSelectField([
    'label' => 'Rol *',
    'input_name' => 'rol_id',
    'input_id' => 'rol_id',
    'picker_id' => 'rol_picker',
    'required' => true,
    'button_placeholder' => 'Selecciona un rol',
    'filter_placeholder' => 'Buscar rol...',
    'list_max_height' => '320px',
    'options' => [
        ['value' => '1', 'label' => 'Administrador'],
        ['value' => '2', 'label' => 'Operador'],
    ],
]);
```

JS disponible:

- `window.GpSearchableSelect.get('rol_picker')`
- `setValue(value)`
- `getValue()`
- `clear()`

### `searchable_multiselect.php`

Multiselect buscable basado en dropdown Bootstrap. Mantiene los valores en un `input hidden` separados por `;`, permite agregar varios elementos, quitarlos como chips y repoblar el control desde JS.

Funcion principal:

- `gpRenderSearchableMultiSelectField(array $options)`

Ejemplo:

```php
gpRenderSearchableMultiSelectField([
    'label' => 'Departamentos',
    'input_name' => 'departamento_ids',
    'input_id' => 'departamento_ids',
    'picker_id' => 'departamento_ids_picker',
    'button_placeholder' => 'Selecciona departamentos',
    'search_placeholder' => 'Buscar departamento...',
    'options' => [
        ['value' => '1', 'label' => 'Legal'],
        ['value' => '2', 'label' => 'Finanzas'],
    ],
]);
```

JS disponible:

- `window.GpSearchableMultiSelect.get('departamento_ids_picker')`
- `setSelectedFromString('1;2')`
- `getSelected()`
- `clear()`

### `confirm_action_modal.php`

Modal generico de confirmacion para acciones destructivas o sensibles. Se activa con atributos `data-gp-confirm`.

Funciones principales:

- `gpRenderConfirmActionModal(array $options = [])`
- `gpRenderConfirmActionAssets()`

Ejemplo:

```php
gpRenderConfirmActionModal();
```

```html
<button
    type="submit"
    data-gp-confirm
    data-confirm-title="Eliminar registro"
    data-confirm-message="Esta accion no se puede deshacer.">
    Eliminar
</button>
```

### `section_header.php`

Contenedor global para encabezados de sección (kicker, título, botón volver y ayuda contextual con tooltip), con layout compacto reutilizable.

Función principal:

- `gpRenderSectionHeader(array $options = [])`

Ejemplo:

```php
gpRenderSectionHeader([
    'kicker' => 'Sistema / Gestión',
    'title' => 'Usuarios',
    'back_url' => gpGestionBaseUrl('index.php'),
    'back_label' => 'Volver a Gestión',
    'help_text' => 'Administra cuentas y permisos del portal.',
]);
```

### `crud_table.php`

Boilerplate global para listados CRUD con metadatos superiores, tabla responsive y paginación.

Función principal:

- `gpRenderCrudTable(array $options = [])`
- `gpRenderCrudPrimaryAction(array $options = [])`
- `gpRenderCrudActionsMenu(array $options = [])`

Opciones base:

- `meta_left` / `meta_right` (`string|callable`)
- `headers` (arreglo de columnas)
- `rows` + `row_render` (callback para renderizar cada `<tr>`)
- `empty_message`, `empty_colspan`
- `pagination` (`total_records`, `current_page`, `total_pages`, `items`, `build_url`)

Helpers de patrón visual incluidos:

- Botón primario de cabecera (`gpRenderCrudPrimaryAction`) con estilo homogéneo de gestión.
- Menú de acciones con botón único `...` (`gpRenderCrudActionsMenu`) con items `button`, `link`, `form` y `divider`.

Ejemplo:

```php
gpRenderCrudTable([
    'meta_left' => '<strong>10 registros</strong>',
    'headers' => ['Código', 'Nombre', 'Estado', 'Acciones'],
    'rows' => $rows,
    'row_render' => static function (array $row): void {
        echo '<tr>';
        echo '<td>' . gpComponentEscape($row['codigo'] ?? '') . '</td>';
        echo '<td>' . gpComponentEscape($row['nombre'] ?? '') . '</td>';
        echo '<td>' . gpComponentEscape($row['estado'] ?? '') . '</td>';
        echo '<td>...</td>';
        echo '</tr>';
    },
]);
```

## Componentes aun locales por modulo

Estos componentes siguen existiendo en `msp/templates/components` o `ct/templates/components` porque tienen comportamiento o dependencias mas especificas:

- `crud_table.php`: la version de CT esta muy acoplada a sus convenciones de listado y filtros.
- `quick_access_offcanvas.php`: pertenece a la navegacion interna de MSP.

Cuando otro modulo necesite alguno de esos patrones, conviene extraer una version `gp` limpia en esta carpeta antes de reutilizar la copia del modulo.
