<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderSearchableMultiSelectAssets')) {
    function gpRenderSearchableMultiSelectAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .gp-searchable-multiselect-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 48px;
            border: 1px solid var(--color-border, #ced4da);
            border-radius: var(--radius-sm, 6px);
            background: #fff;
            color: var(--color-text, #212529);
            font-weight: 400;
            box-shadow: none;
        }

        .gp-searchable-multiselect-btn:hover,
        .gp-searchable-multiselect-btn:focus,
        .gp-searchable-multiselect-btn.show {
            background: #fff;
            color: var(--color-text, #212529);
            border-color: var(--color-primary, #0d6efd);
            box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .18);
        }

        .gp-searchable-multiselect-btn::after {
            margin-left: auto;
        }

        .gp-searchable-multiselect-menu {
            min-width: 100%;
        }

        .gp-searchable-multiselect-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 1px solid var(--color-border, #ced4da);
            border-radius: 999px;
            background: var(--color-primary-soft, #e7eff8);
            color: var(--color-text, #212529);
            font-size: 13px;
        }

        .gp-searchable-multiselect-chip button {
            border: 0;
            background: transparent;
            color: var(--color-danger, #b42318);
            padding: 0;
            line-height: 1;
        }

        .gp-searchable-multiselect-table-wrap {
            border: 1px solid var(--color-border, #ced4da);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .gp-searchable-multiselect-table {
            width: 100%;
            border-collapse: collapse;
        }

        .gp-searchable-multiselect-table th,
        .gp-searchable-multiselect-table td {
            border-bottom: 1px solid var(--color-border, #ced4da);
            padding: 8px 10px;
            font-size: 13px;
            vertical-align: middle;
        }

        .gp-searchable-multiselect-table thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .gp-searchable-multiselect-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .gp-searchable-multiselect-table-remove {
            border: 0;
            background: transparent;
            color: var(--color-danger, #b42318);
            font-weight: 700;
            line-height: 1;
            padding: 0 2px;
        }

        .gp-searchable-multiselect-primary-badge {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            background: rgba(11, 58, 110, 0.12);
            color: #0b3a6e;
            font-size: 11px;
            font-weight: 700;
        }
        </style>
        <script>
        (() => {
            const instances = new Map();

            const normalizeCode = (value) => String(value || '').trim();
            const codeKey = (value) => normalizeCode(value).toLowerCase();
            const parseCodes = (raw) => {
                const parts = String(raw || '').split(/[;|,/\n\r]+/);
                const seen = new Set();
                const values = [];
                parts.forEach((part) => {
                    const code = normalizeCode(part);
                    const key = codeKey(code);
                    if (!code || seen.has(key)) {
                        return;
                    }
                    seen.add(key);
                    values.push(code);
                });
                return values;
            };

            const buildInstance = (root) => {
                if (!(root instanceof HTMLElement)) {
                    return null;
                }

                const hiddenInput = root.querySelector('[data-gp-ms-hidden]');
                const button = root.querySelector('[data-gp-ms-button]');
                const searchInput = root.querySelector('[data-gp-ms-search]');
                const list = root.querySelector('[data-gp-ms-list]');
                const selectedContainer = root.querySelector('[data-gp-ms-selected]');
                if (
                    !(hiddenInput instanceof HTMLInputElement)
                    || !(button instanceof HTMLButtonElement)
                    || !(searchInput instanceof HTMLInputElement)
                    || !(list instanceof HTMLElement)
                    || !(selectedContainer instanceof HTMLElement)
                ) {
                    return null;
                }

                const dropdown = window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(button) : null;
                const options = Array.from(list.querySelectorAll('.js-gp-ms-option'));
                const optionMap = new Map();
                options.forEach((option) => {
                    const value = normalizeCode(option.dataset.value || '');
                    if (value !== '') {
                        optionMap.set(codeKey(value), option);
                    }
                });

                const placeholder = String(button.dataset.placeholder || 'Selecciona...').trim();
                const hideSelectedOptions = root.dataset.hideSelectedOptions === '1';
                const closeOnSelect = root.dataset.closeOnSelect === '1';
                const selectedView = String(root.dataset.selectedView || 'chips').toLowerCase();
                const tableShowPrincipal = root.dataset.tableShowPrincipal !== '0';
                let selected = parseCodes(hiddenInput.value);
                let highlightedIndex = -1;

                const visibleOptions = () => options.filter((option) => !option.classList.contains('d-none') && !option.disabled);

                const updateHighlight = (index) => {
                    const visible = visibleOptions();
                    visible.forEach((option) => option.classList.remove('active'));
                    if (visible.length === 0) {
                        highlightedIndex = -1;
                        return;
                    }
                    const safeIndex = Math.max(0, Math.min(index, visible.length - 1));
                    highlightedIndex = safeIndex;
                    visible[safeIndex].classList.add('active');
                    visible[safeIndex].scrollIntoView({ block: 'nearest' });
                };

                const syncHidden = () => {
                    hiddenInput.value = selected.join(';');
                    hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const updateButtonSummary = () => {
                    if (selected.length === 0) {
                        button.textContent = placeholder;
                        button.title = placeholder;
                        return;
                    }

                    if (selected.length === 1) {
                        const option = optionMap.get(codeKey(selected[0]));
                        const text = option ? (option.dataset.label || selected[0]) : selected[0];
                        button.textContent = text;
                        button.title = text;
                        return;
                    }

                    const text = selected.length + ' seleccionados';
                    button.textContent = text;
                    button.title = selected.map((value) => {
                        const option = optionMap.get(codeKey(value));
                        return option ? (option.dataset.label || value) : value;
                    }).join(', ');
                };

                const renderSelected = () => {
                    selectedContainer.innerHTML = '';
                    if (selected.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'text-muted small';
                        empty.textContent = root.dataset.emptyText || 'Sin elementos seleccionados.';
                        selectedContainer.appendChild(empty);
                        return;
                    }

                    if (selectedView === 'table') {
                        const wrap = document.createElement('div');
                        wrap.className = 'gp-searchable-multiselect-table-wrap';

                        const table = document.createElement('table');
                        table.className = 'gp-searchable-multiselect-table';

                        const thead = document.createElement('thead');
                        const headRow = document.createElement('tr');
                        const headerTitles = tableShowPrincipal
                            ? ['Elemento', 'Principal', 'Acción']
                            : ['Elemento', 'Acción'];
                        headerTitles.forEach((title) => {
                            const th = document.createElement('th');
                            th.scope = 'col';
                            th.textContent = title;
                            headRow.appendChild(th);
                        });
                        thead.appendChild(headRow);

                        const tbody = document.createElement('tbody');
                        selected.forEach((value, index) => {
                            const option = optionMap.get(codeKey(value));
                            const label = option ? (option.dataset.label || value) : value;

                            const row = document.createElement('tr');

                            const nameCell = document.createElement('td');
                            nameCell.textContent = label;

                            const actionCell = document.createElement('td');
                            const removeButton = document.createElement('button');
                            removeButton.type = 'button';
                            removeButton.dataset.code = value;
                            removeButton.className = 'gp-searchable-multiselect-table-remove';
                            removeButton.setAttribute('aria-label', 'Quitar ' + label);
                            removeButton.textContent = 'Quitar';
                            actionCell.appendChild(removeButton);

                            row.appendChild(nameCell);
                            if (tableShowPrincipal) {
                                const principalCell = document.createElement('td');
                                if (index === 0) {
                                    const principalBadge = document.createElement('span');
                                    principalBadge.className = 'gp-searchable-multiselect-primary-badge';
                                    principalBadge.textContent = 'Sí';
                                    principalCell.appendChild(principalBadge);
                                } else {
                                    principalCell.textContent = 'No';
                                }
                                row.appendChild(principalCell);
                            }
                            row.appendChild(actionCell);
                            tbody.appendChild(row);
                        });

                        table.appendChild(thead);
                        table.appendChild(tbody);
                        wrap.appendChild(table);
                        selectedContainer.appendChild(wrap);
                        return;
                    }

                    const shell = document.createElement('div');
                    shell.className = 'd-flex flex-wrap gap-2';

                    selected.forEach((value) => {
                        const option = optionMap.get(codeKey(value));
                        const label = option ? (option.dataset.label || value) : value;

                        const chip = document.createElement('div');
                        chip.className = 'gp-searchable-multiselect-chip';

                        const text = document.createElement('span');
                        text.textContent = label;

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.dataset.code = value;
                        removeButton.setAttribute('aria-label', 'Quitar ' + label);
                        removeButton.textContent = 'x';

                        chip.appendChild(text);
                        chip.appendChild(removeButton);
                        shell.appendChild(chip);
                    });

                    selectedContainer.appendChild(shell);
                };

                const applyFilterAndAvailability = () => {
                    const term = searchInput.value.trim().toLowerCase();
                    options.forEach((option) => {
                        const value = normalizeCode(option.dataset.value || '');
                        const label = String(option.dataset.label || '').toLowerCase();
                        const search = String(option.dataset.search || '').toLowerCase();
                        const isSelected = selected.some((item) => codeKey(item) === codeKey(value));
                        const matches = term === '' || label.includes(term) || search.includes(term) || value.toLowerCase().includes(term);
                        option.disabled = !hideSelectedOptions && isSelected;
                        option.classList.toggle('d-none', !matches || (hideSelectedOptions && isSelected));
                    });
                    updateHighlight(0);
                };

                const addValue = (rawValue) => {
                    const value = normalizeCode(rawValue);
                    const key = codeKey(value);
                    if (!value || selected.some((item) => codeKey(item) === key)) {
                        return;
                    }
                    selected.push(value);
                    searchInput.value = '';
                    syncHidden();
                    applyFilterAndAvailability();
                    renderSelected();
                    updateButtonSummary();
                    searchInput.focus();
                    if (closeOnSelect && dropdown) {
                        dropdown.hide();
                    }
                };

                const removeValue = (rawValue) => {
                    const key = codeKey(rawValue);
                    selected = selected.filter((item) => codeKey(item) !== key);
                    syncHidden();
                    applyFilterAndAvailability();
                    renderSelected();
                    updateButtonSummary();
                };

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        if (option.disabled) {
                            return;
                        }
                        addValue(option.dataset.value || '');
                    });
                });

                searchInput.addEventListener('input', applyFilterAndAvailability);
                searchInput.addEventListener('keydown', (event) => {
                    const visible = visibleOptions();
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (visible.length > 0) {
                            updateHighlight(highlightedIndex < 0 ? 0 : highlightedIndex + 1);
                        }
                        return;
                    }
                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (visible.length > 0) {
                            updateHighlight(highlightedIndex < 0 ? 0 : highlightedIndex - 1);
                        }
                        return;
                    }
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (visible.length === 0) {
                            return;
                        }
                        const selectedOption = highlightedIndex >= 0 && highlightedIndex < visible.length
                            ? visible[highlightedIndex]
                            : visible[0];
                        addValue(selectedOption.dataset.value || '');
                        return;
                    }
                    if (event.key === 'Backspace' && searchInput.value.trim() === '' && selected.length > 0) {
                        event.preventDefault();
                        removeValue(selected[selected.length - 1]);
                        return;
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        if (dropdown) {
                            dropdown.hide();
                        }
                    }
                });

                selectedContainer.addEventListener('click', (event) => {
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    const buttonTarget = target.closest('button[data-code]');
                    if (!buttonTarget) {
                        return;
                    }
                    removeValue(buttonTarget.dataset.code || '');
                });

                root.addEventListener('shown.bs.dropdown', () => {
                    searchInput.focus();
                    updateHighlight(0);
                });

                applyFilterAndAvailability();
                renderSelected();
                updateButtonSummary();
                syncHidden();

                return {
                    setSelectedFromString: (raw) => {
                        selected = parseCodes(raw);
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateButtonSummary();
                    },
                    clear: () => {
                        selected = [];
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateButtonSummary();
                    },
                    getSelected: () => [...selected],
                };
            };

            const init = (rootOrId) => {
                const root = typeof rootOrId === 'string' ? document.getElementById(rootOrId) : rootOrId;
                if (!(root instanceof HTMLElement)) {
                    return null;
                }
                const key = root.id || String(Math.random());
                if (instances.has(key)) {
                    return instances.get(key);
                }
                const instance = buildInstance(root);
                if (!instance) {
                    return null;
                }
                instances.set(key, instance);
                return instance;
            };

            const get = (rootId) => {
                const key = String(rootId || '');
                if (key !== '' && instances.has(key)) {
                    return instances.get(key);
                }
                return init(rootId);
            };

            const initAll = () => {
                document.querySelectorAll('[data-gp-searchable-multiselect]').forEach((root) => init(root));
            };

            window.GpSearchableMultiSelect = { init, get };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initAll);
            } else {
                initAll();
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('gpRenderSearchableMultiSelectField')) {
    /**
     * @param array{
     *   wrapper_class?: string,
     *   label?: string,
     *   input_name?: string,
     *   input_id?: string,
     *   picker_id?: string,
     *   button_id?: string,
     *   search_id?: string,
     *   list_id?: string,
     *   selected_container_id?: string,
     *   button_placeholder?: string,
     *   search_placeholder?: string,
     *   empty_options_message?: string,
     *   empty_selected_message?: string,
     *   button_class?: string,
     *   hide_selected_options?: bool,
     *   close_on_select?: bool,
     *   selected_view?: string,
     *   table_show_principal?: bool,
     *   required?: bool,
     *   value?: string,
     *   options?: array<int, array{value:string, label:string, search?:string}>
     * } $options
     */
    function gpRenderSearchableMultiSelectField(array $options = []): void
    {
        gpRenderSearchableMultiSelectAssets();

        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12'));
        $label = trim((string) ($options['label'] ?? 'Elementos'));
        $inputName = trim((string) ($options['input_name'] ?? 'items'));
        $inputId = trim((string) ($options['input_id'] ?? 'items'));
        $pickerId = trim((string) ($options['picker_id'] ?? ($inputId . '_picker')));
        $buttonId = trim((string) ($options['button_id'] ?? ($inputId . '_btn')));
        $searchId = trim((string) ($options['search_id'] ?? ($inputId . '_search')));
        $listId = trim((string) ($options['list_id'] ?? ($inputId . '_list')));
        $selectedContainerId = trim((string) ($options['selected_container_id'] ?? ($inputId . '_selected')));
        $buttonPlaceholder = trim((string) ($options['button_placeholder'] ?? 'Selecciona elementos'));
        $searchPlaceholder = trim((string) ($options['search_placeholder'] ?? 'Buscar...'));
        $emptyOptionsMessage = trim((string) ($options['empty_options_message'] ?? 'No hay opciones disponibles.'));
        $emptySelectedMessage = trim((string) ($options['empty_selected_message'] ?? 'Sin elementos seleccionados.'));
        $buttonClass = trim((string) ($options['button_class'] ?? 'btn gp-searchable-multiselect-btn dropdown-toggle w-100 text-start'));
        $hideSelectedOptions = (bool) ($options['hide_selected_options'] ?? false);
        $closeOnSelect = (bool) ($options['close_on_select'] ?? false);
        $selectedView = trim((string) ($options['selected_view'] ?? 'chips'));
        if (!in_array($selectedView, ['chips', 'table'], true)) {
            $selectedView = 'chips';
        }
        $tableShowPrincipal = (bool) ($options['table_show_principal'] ?? true);
        $required = (bool) ($options['required'] ?? false);
        $value = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo gpComponentEscape($wrapperClass); ?>">
            <label for="<?php echo gpComponentEscape($inputId); ?>" class="form-label"><?php echo gpComponentEscape($label); ?></label>
            <div
                class="dropdown w-100"
                id="<?php echo gpComponentEscape($pickerId); ?>"
                data-gp-searchable-multiselect
                data-hide-selected-options="<?php echo $hideSelectedOptions ? '1' : '0'; ?>"
                data-close-on-select="<?php echo $closeOnSelect ? '1' : '0'; ?>"
                data-selected-view="<?php echo gpComponentEscape($selectedView); ?>"
                data-table-show-principal="<?php echo $tableShowPrincipal ? '1' : '0'; ?>"
                data-empty-text="<?php echo gpComponentEscape($emptySelectedMessage); ?>">
                <input
                    type="hidden"
                    id="<?php echo gpComponentEscape($inputId); ?>"
                    name="<?php echo gpComponentEscape($inputName); ?>"
                    data-gp-ms-hidden
                    value="<?php echo gpComponentEscape($value); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="<?php echo gpComponentEscape($buttonClass); ?>"
                    type="button"
                    id="<?php echo gpComponentEscape($buttonId); ?>"
                    data-gp-ms-button
                    data-placeholder="<?php echo gpComponentEscape($buttonPlaceholder); ?>"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"><?php echo gpComponentEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100 gp-searchable-multiselect-menu" aria-labelledby="<?php echo gpComponentEscape($buttonId); ?>">
                    <input type="text" id="<?php echo gpComponentEscape($searchId); ?>" data-gp-ms-search class="form-control form-control-sm mb-2" placeholder="<?php echo gpComponentEscape($searchPlaceholder); ?>">
                    <div class="list-group list-group-flush overflow-auto" id="<?php echo gpComponentEscape($listId); ?>" data-gp-ms-list style="max-height: 220px;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo gpComponentEscape($emptyOptionsMessage); ?></div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemValue = trim((string) ($item['value'] ?? ''));
                                if ($itemValue === '') {
                                    continue;
                                }
                                $itemLabel = (string) ($item['label'] ?? $itemValue);
                                $itemSearch = (string) ($item['search'] ?? mb_strtolower($itemLabel, 'UTF-8'));
                                ?>
                                <button
                                    type="button"
                                    class="list-group-item list-group-item-action py-2 px-2 js-gp-ms-option"
                                    data-value="<?php echo gpComponentEscape($itemValue); ?>"
                                    data-label="<?php echo gpComponentEscape($itemLabel); ?>"
                                    data-search="<?php echo gpComponentEscape($itemSearch); ?>"><?php echo gpComponentEscape($itemLabel); ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-text mb-2">Puedes seleccionar uno o varios elementos.</div>
                <div id="<?php echo gpComponentEscape($selectedContainerId); ?>" data-gp-ms-selected class="vstack gap-2"></div>
            </div>
        </div>
        <?php
    }
}
