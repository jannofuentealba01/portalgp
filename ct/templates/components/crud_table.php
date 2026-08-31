<?php
declare(strict_types=1);

if (!function_exists('ctCrudTableEscape')) {
    function ctCrudTableEscape(mixed $value): string
    {
        if (function_exists('ctEscape')) {
            return ctEscape((string) $value);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctCrudTableAttrs')) {
    /**
     * @param array<string, mixed> $attrs
     */
    function ctCrudTableAttrs(array $attrs): string
    {
        $parts = [];
        foreach ($attrs as $name => $value) {
            $attr = trim((string) $name);
            if ($attr === '' || $value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = $attr;
                continue;
            }

            $parts[] = $attr . '="' . ctCrudTableEscape((string) $value) . '"';
        }

        return $parts === [] ? '' : (' ' . implode(' ', $parts));
    }
}

if (!function_exists('ctCrudInvoke')) {
    /**
     * @param callable $callback
     * @param array<int, mixed> $args
     * @return mixed
     */
    function ctCrudInvoke(callable $callback, array $args = []): mixed
    {
        if (is_array($callback) && count($callback) === 2) {
            $ref = new ReflectionMethod($callback[0], (string) $callback[1]);
        } else {
            $ref = new ReflectionFunction(Closure::fromCallable($callback));
        }

        if ($ref->isVariadic()) {
            return $callback(...$args);
        }

        $maxParams = $ref->getNumberOfParameters();
        return $callback(...array_slice($args, 0, $maxParams));
    }
}

if (!function_exists('ctCrudTableCapture')) {
    /**
     * @param callable $callback
     * @param array<int, mixed> $args
     */
    function ctCrudTableCapture(callable $callback, array $args = []): string
    {
        ob_start();
        $result = ctCrudInvoke($callback, $args);
        $buffer = (string) ob_get_clean();
        if (is_string($result) && $result !== '') {
            return $buffer . $result;
        }

        return $buffer;
    }
}

if (!function_exists('ctCrudBuildQuery')) {
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     */
    function ctCrudBuildQuery(array $base, array $override = []): string
    {
        $merged = array_merge($base, $override);
        foreach ($merged as $key => $value) {
            if ($value === '' || $value === null) {
                unset($merged[$key]);
            }
        }

        $qs = http_build_query($merged);
        return $qs === '' ? '' : ('?' . $qs);
    }
}

if (!function_exists('ctCrudSortLink')) {
    /**
     * @param array<string, mixed> $base
     */
    function ctCrudSortLink(string $field, array $base, string $currentField, string $currentDir): string
    {
        $nextDir = ($currentField === $field && strtolower($currentDir) === 'asc') ? 'desc' : 'asc';
        return ctCrudBuildQuery($base, ['orden' => $field, 'dir' => $nextDir, 'pagina' => 1]);
    }
}

if (!function_exists('ctCrudSortIcon')) {
    function ctCrudSortIcon(string $field, string $currentField, string $currentDir): string
    {
        if ($currentField !== $field) {
            return 'bi-arrow-down-up';
        }

        return strtolower($currentDir) === 'asc' ? 'bi-sort-up' : 'bi-sort-down';
    }
}

if (!function_exists('ctCrudResolveValue')) {
    /**
     * @param mixed $value
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     * @return mixed
     */
    function ctCrudResolveValue(mixed $value, array $row, array $context): mixed
    {
        if (is_callable($value)) {
            return ctCrudInvoke($value, [$row, $context]);
        }

        return $value;
    }
}

if (!function_exists('ctCrudRenderFilterField')) {
    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $context
     */
    function ctCrudRenderFilterField(array $field, array $context): void
    {
        $type = strtolower(trim((string) ($field['type'] ?? 'input')));

        if ($type === 'custom') {
            $render = $field['render'] ?? null;
            if (is_callable($render)) {
                echo ctCrudTableCapture($render, [$field, $context]);
            } elseif (isset($field['html'])) {
                echo (string) $field['html'];
            }
            return;
        }

        $wrapperClass = trim((string) ($field['wrapper_class'] ?? 'col-12 col-md-3'));
        $label = trim((string) ($field['label'] ?? ''));
        $name = trim((string) ($field['name'] ?? ''));
        $id = trim((string) ($field['id'] ?? $name));
        $value = (string) ($field['value'] ?? '');
        $inputClass = trim((string) ($field['input_class'] ?? 'form-control ct-control-input'));

        echo '<div class="' . ctCrudTableEscape($wrapperClass) . '">';
        if ($label !== '') {
            echo '<label class="form-label small text-muted" for="' . ctCrudTableEscape($id) . '">' . ctCrudTableEscape($label) . '</label>';
        }

        if ($type === 'select') {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            echo '<select class="' . ctCrudTableEscape($inputClass) . '" id="' . ctCrudTableEscape($id) . '" name="' . ctCrudTableEscape($name) . '">';
            foreach ($options as $option) {
                if (!is_array($option)) {
                    continue;
                }
                $optValue = (string) ($option['value'] ?? '');
                $optLabel = (string) ($option['label'] ?? $optValue);
                $selected = ((string) $value === $optValue) ? ' selected' : '';
                echo '<option value="' . ctCrudTableEscape($optValue) . '"' . $selected . '>' . ctCrudTableEscape($optLabel) . '</option>';
            }
            echo '</select>';
        } else {
            $placeholder = trim((string) ($field['placeholder'] ?? ''));
            echo '<input class="' . ctCrudTableEscape($inputClass) . '" id="' . ctCrudTableEscape($id) . '" name="' . ctCrudTableEscape($name) . '" value="' . ctCrudTableEscape($value) . '"';
            if ($placeholder !== '') {
                echo ' placeholder="' . ctCrudTableEscape($placeholder) . '"';
            }
            echo '>';
        }

        echo '</div>';
    }
}

if (!function_exists('ctCrudRenderFilterAction')) {
    /**
     * @param array<string, mixed> $action
     */
    function ctCrudRenderFilterAction(array $action): void
    {
        $type = strtolower(trim((string) ($action['type'] ?? 'submit')));
        $label = trim((string) ($action['label'] ?? 'Acción'));
        $icon = trim((string) ($action['icon'] ?? ''));
        $class = trim((string) ($action['class'] ?? 'btn btn-outline-primary ct-crud-filter-submit'));
        $attrs = is_array($action['attrs'] ?? null) ? $action['attrs'] : [];

        $iconHtml = $icon !== ''
            ? '<i class="' . ctCrudTableEscape($icon) . ' me-1" aria-hidden="true"></i>'
            : '';

        if ($type === 'link') {
            $href = (string) ($action['href'] ?? '?');
            echo '<a href="' . ctCrudTableEscape($href) . '" class="' . ctCrudTableEscape($class) . '"' . ctCrudTableAttrs($attrs) . '>' . $iconHtml . ctCrudTableEscape($label) . '</a>';
            return;
        }

        $buttonType = ($type === 'button') ? 'button' : 'submit';
        echo '<button type="' . ctCrudTableEscape($buttonType) . '" class="' . ctCrudTableEscape($class) . '"' . ctCrudTableAttrs($attrs) . '>' . $iconHtml . ctCrudTableEscape($label) . '</button>';
    }
}

if (!function_exists('ctCrudRenderFilters')) {
    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $context
     */
    function ctCrudRenderFilters(array $filters, array $context = []): void
    {
        $formAttrs = is_array($filters['form_attrs'] ?? null) ? $filters['form_attrs'] : [];
        $fields = is_array($filters['fields'] ?? null) ? $filters['fields'] : [];
        $hidden = is_array($filters['hidden'] ?? null) ? $filters['hidden'] : [];
        $actions = is_array($filters['actions'] ?? null) ? $filters['actions'] : [];

        if (!isset($formAttrs['method'])) {
            $formAttrs['method'] = 'get';
        }

        echo '<form' . ctCrudTableAttrs($formAttrs) . '>';

        foreach ($hidden as $name => $value) {
            $nameString = trim((string) $name);
            if ($nameString === '' || $value === '' || $value === null) {
                continue;
            }
            echo '<input type="hidden" name="' . ctCrudTableEscape($nameString) . '" value="' . ctCrudTableEscape((string) $value) . '">';
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            ctCrudRenderFilterField($field, $context);
        }

        if ($actions !== []) {
            $actionWrapperClass = trim((string) ($actions['wrapper_class'] ?? 'col-12'));
            $actionInnerClass = trim((string) ($actions['inner_class'] ?? 'd-flex justify-content-end gap-2'));
            $items = is_array($actions['items'] ?? null) ? $actions['items'] : [];

            echo '<div class="' . ctCrudTableEscape($actionWrapperClass) . '">';
            echo '<div class="' . ctCrudTableEscape($actionInnerClass) . '">';
            foreach ($items as $action) {
                if (!is_array($action)) {
                    continue;
                }
                ctCrudRenderFilterAction($action);
            }
            echo '</div></div>';
        }

        echo '</form>';
    }
}

if (!function_exists('ctCrudRenderActionControl')) {
    /**
     * @param array<string, mixed> $action
     */
    function ctCrudRenderActionControl(array $action, bool $dropdownItem = false): string
    {
        $type = strtolower(trim((string) ($action['type'] ?? 'button')));
        $label = trim((string) ($action['label'] ?? 'Acción'));
        $icon = trim((string) ($action['icon'] ?? ''));
        $disabled = (bool) ($action['disabled'] ?? false);
        $showLabel = (bool) ($action['show_label'] ?? true);
        $attrs = is_array($action['attrs'] ?? null) ? $action['attrs'] : [];

        $baseClass = $dropdownItem
            ? 'dropdown-item'
            : 'btn btn-outline-secondary btn-sm';
        $class = trim($baseClass . ' ' . (string) ($action['class'] ?? ''));

        $iconHtml = $icon !== '' ? '<i class="' . ctCrudTableEscape($icon) . ($showLabel ? ' me-2' : '') . '" aria-hidden="true"></i>' : '';
        $labelHtml = $showLabel
            ? ctCrudTableEscape($label)
            : '<span class="visually-hidden">' . ctCrudTableEscape($label) . '</span>';

        if ($type === 'link') {
            $href = (string) ($action['href'] ?? '#');
            if ($disabled) {
                return '<span class="' . ctCrudTableEscape($class . ' disabled') . '" aria-disabled="true"' . ctCrudTableAttrs($attrs) . '>' . $iconHtml . $labelHtml . '</span>';
            }

            return '<a href="' . ctCrudTableEscape($href) . '" class="' . ctCrudTableEscape($class) . '"' . ctCrudTableAttrs($attrs) . '>' . $iconHtml . $labelHtml . '</a>';
        }

        $buttonType = strtolower(trim((string) ($action['button_type'] ?? 'button')));
        if ($buttonType !== 'submit' && $buttonType !== 'button') {
            $buttonType = 'button';
        }
        if ($disabled) {
            $attrs['disabled'] = true;
        }

        return '<button type="' . ctCrudTableEscape($buttonType) . '" class="' . ctCrudTableEscape($class) . '"' . ctCrudTableAttrs($attrs) . '>' . $iconHtml . $labelHtml . '</button>';
    }
}

if (!function_exists('ctCrudRenderActionsCell')) {
    /**
     * @param array<string, mixed> $actionsConfig
     * @param array<string, mixed> $row
     * @param array<string, mixed> $context
     */
    function ctCrudRenderActionsCell(array $actionsConfig, array $row, array $context): string
    {
        $primary = ctCrudResolveValue($actionsConfig['primary'] ?? null, $row, $context);
        $secondary = ctCrudResolveValue($actionsConfig['secondary'] ?? null, $row, $context);

        $containerClass = trim('ct-crud-actions ' . (string) ($actionsConfig['container_class'] ?? ''));
        $dropdownToggle = is_array($actionsConfig['dropdown_toggle'] ?? null)
            ? $actionsConfig['dropdown_toggle']
            : [];

        $html = '<div class="' . ctCrudTableEscape($containerClass) . '">';

        if (is_array($primary) && $primary !== []) {
            $html .= ctCrudRenderActionControl($primary, false);
        }

        $secondaryItems = is_array($secondary) ? $secondary : [];
        $hasSecondary = false;
        foreach ($secondaryItems as $item) {
            if (is_array($item) && ((string) ($item['type'] ?? '') !== '')) {
                $hasSecondary = true;
                break;
            }
        }

        if ($hasSecondary) {
            $toggleClass = trim('btn btn-outline-secondary btn-sm dropdown-toggle ' . (string) ($dropdownToggle['class'] ?? ''));
            $toggleIcon = trim((string) ($dropdownToggle['icon'] ?? 'bi bi-three-dots'));
            $toggleTitle = trim((string) ($dropdownToggle['title'] ?? 'Más acciones'));
            $toggleAttrs = is_array($dropdownToggle['attrs'] ?? null) ? $dropdownToggle['attrs'] : [];
            $toggleAttrs['data-bs-toggle'] = 'dropdown';
            $toggleAttrs['aria-expanded'] = 'false';
            if ($toggleTitle !== '') {
                $toggleAttrs['title'] = $toggleTitle;
            }

            $html .= '<div class="dropdown">';
            $html .= '<button type="button" class="' . ctCrudTableEscape($toggleClass) . '"' . ctCrudTableAttrs($toggleAttrs) . '>';
            if ($toggleIcon !== '') {
                $html .= '<i class="' . ctCrudTableEscape($toggleIcon) . '" aria-hidden="true"></i>';
            }
            $html .= '<span class="visually-hidden">' . ctCrudTableEscape($toggleTitle !== '' ? $toggleTitle : 'Más acciones') . '</span>';
            $html .= '</button>';
            $html .= '<ul class="dropdown-menu dropdown-menu-end">';

            foreach ($secondaryItems as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemType = strtolower(trim((string) ($item['type'] ?? '')));
                if ($itemType === 'divider') {
                    $html .= '<li><hr class="dropdown-divider"></li>';
                    continue;
                }

                $html .= '<li>' . ctCrudRenderActionControl($item, true) . '</li>';
            }

            $html .= '</ul></div>';
        }

        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('ctRenderCrudTable')) {
    /**
     * @param array<string, mixed> $config
     */
    function ctRenderCrudTable(array $config): void
    {
        $context = is_array($config['context'] ?? null) ? $config['context'] : [];

        $filters = is_array($config['filters'] ?? null) ? $config['filters'] : [];
        if ($filters !== []) {
            ctCrudRenderFilters($filters, $context);
        }

        $shellClass = trim((string) ($config['shell_class'] ?? 'border rounded p-3 bg-white ct-crud-table-shell'));
        $tableWrapClass = trim((string) ($config['table_wrap_class'] ?? 'table-responsive ct-crud-table-wrap'));
        $tableClass = trim((string) ($config['table_class'] ?? 'table table-sm align-middle mb-0 ct-crud-table'));
        $tbodyId = trim((string) ($config['tbody_id'] ?? ''));

        $columns = is_array($config['columns'] ?? null) ? $config['columns'] : [];
        $rows = is_array($config['rows'] ?? null) ? $config['rows'] : [];

        $actionsConfig = is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $hasActions = $actionsConfig !== [];

        $rowClassResolver = $config['row_class'] ?? null;
        $rowAttrsResolver = $config['row_attrs'] ?? null;
        $cellRenderer = $config['render_cell'] ?? null;

        $emptyText = trim((string) ($config['empty_text'] ?? 'Sin registros para mostrar.'));

        echo '<div class="' . ctCrudTableEscape($shellClass) . '">';
        echo '<div class="' . ctCrudTableEscape($tableWrapClass) . '">';
        echo '<table class="' . ctCrudTableEscape($tableClass) . '">';

        echo '<thead><tr>';
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $label = trim((string) ($column['label'] ?? 'Columna'));
            $headerClass = trim((string) ($column['header_class'] ?? ''));
            $sortUrl = trim((string) ($column['sort_url'] ?? ''));
            $sortIcon = trim((string) ($column['sort_icon'] ?? ''));
            $headerLinkClass = trim((string) ($column['header_link_class'] ?? 'link-dark text-decoration-none ct-crud-table-head-link'));

            echo '<th' . ($headerClass !== '' ? (' class="' . ctCrudTableEscape($headerClass) . '"') : '') . '>';
            if ($sortUrl !== '') {
                echo '<a class="' . ctCrudTableEscape($headerLinkClass) . '" href="' . ctCrudTableEscape($sortUrl) . '">';
                echo ctCrudTableEscape($label);
                if ($sortIcon !== '') {
                    echo ' <i class="bi ' . ctCrudTableEscape($sortIcon) . '" aria-hidden="true"></i>';
                }
                echo '</a>';
            } else {
                echo ctCrudTableEscape($label);
            }
            echo '</th>';
        }

        if ($hasActions) {
            $actionsHeader = trim((string) ($actionsConfig['header_label'] ?? 'Acciones'));
            $actionsHeaderClass = trim((string) ($actionsConfig['header_class'] ?? 'text-center'));
            echo '<th class="' . ctCrudTableEscape($actionsHeaderClass) . '">' . ctCrudTableEscape($actionsHeader) . '</th>';
        }

        echo '</tr></thead>';

        echo '<tbody' . ($tbodyId !== '' ? (' id="' . ctCrudTableEscape($tbodyId) . '"') : '') . '>';
        if ($rows === []) {
            $colspan = 0;
            foreach ($columns as $column) {
                if (is_array($column)) {
                    $colspan++;
                }
            }
            if ($hasActions) {
                $colspan++;
            }
            if ($colspan < 1) {
                $colspan = 1;
            }

            echo '<tr><td colspan="' . $colspan . '" class="text-muted text-center py-4 ct-crud-table-empty">' . ctCrudTableEscape($emptyText) . '</td></tr>';
        } else {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $rowClass = '';
                if (is_callable($rowClassResolver)) {
                    $resolvedClass = ctCrudInvoke($rowClassResolver, [$row, $context]);
                    if (is_string($resolvedClass)) {
                        $rowClass = trim($resolvedClass);
                    }
                }

                $rowAttrs = [];
                if (is_callable($rowAttrsResolver)) {
                    $resolvedAttrs = ctCrudInvoke($rowAttrsResolver, [$row, $context]);
                    if (is_array($resolvedAttrs)) {
                        $rowAttrs = $resolvedAttrs;
                    }
                }

                if ($rowClass !== '') {
                    $rowAttrs['class'] = trim((string) ($rowAttrs['class'] ?? '') . ' ' . $rowClass);
                }

                echo '<tr' . ctCrudTableAttrs($rowAttrs) . '>';
                foreach ($columns as $column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $key = (string) ($column['key'] ?? '');
                    $cellClass = trim((string) ($column['cell_class'] ?? ''));
                    $escapeCell = (bool) ($column['escape'] ?? true);

                    $content = '';
                    $columnRenderer = $column['render'] ?? null;
                    if (is_callable($columnRenderer)) {
                        $content = ctCrudTableCapture($columnRenderer, [$row, $column, $context]);
                    } elseif (is_callable($cellRenderer)) {
                        $content = ctCrudTableCapture($cellRenderer, [$row, $column, $context]);
                    } elseif ($key !== '') {
                        $content = (string) ($row[$key] ?? '');
                    }

                    echo '<td' . ($cellClass !== '' ? (' class="' . ctCrudTableEscape($cellClass) . '"') : '') . '>';
                    echo $escapeCell ? ctCrudTableEscape($content) : $content;
                    echo '</td>';
                }

                if ($hasActions) {
                    $actionsCellClass = trim((string) ($actionsConfig['cell_class'] ?? 'text-center'));
                    echo '<td class="' . ctCrudTableEscape($actionsCellClass) . '">';
                    echo ctCrudRenderActionsCell($actionsConfig, $row, $context);
                    echo '</td>';
                }

                echo '</tr>';
            }
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';

        $meta = is_array($config['meta'] ?? null) ? $config['meta'] : [];
        if ($meta !== []) {
            $metaClass = trim((string) ($meta['wrapper_class'] ?? 'd-flex flex-wrap justify-content-between align-items-center mt-3 gap-2 ct-crud-table-meta'));
            $leftHtml = '';
            $rightHtml = '';

            $left = $meta['left_html'] ?? '';
            if (is_callable($left)) {
                $leftHtml = ctCrudTableCapture($left, [$context]);
            } else {
                $leftHtml = (string) $left;
            }

            $right = $meta['right_html'] ?? '';
            if (is_callable($right)) {
                $rightHtml = ctCrudTableCapture($right, [$context]);
            } else {
                $rightHtml = (string) $right;
            }

            echo '<div class="' . ctCrudTableEscape($metaClass) . '">';
            echo '<div class="ct-crud-table-meta-left">' . $leftHtml . '</div>';
            echo '<div class="ct-crud-table-meta-right">' . $rightHtml . '</div>';
            echo '</div>';
        }

        echo '</div>';
    }
}
