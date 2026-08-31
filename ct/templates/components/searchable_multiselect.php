<?php
declare(strict_types=1);

if (!function_exists('ctSearchableMultiSelectEscape')) {
    function ctSearchableMultiSelectEscape(string $value): string
    {
        if (function_exists('ctEscape')) {
            /** @var callable $esc */
            $esc = 'ctEscape';
            return (string) $esc($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctRenderSearchableMultiSelectAssets')) {
    function ctRenderSearchableMultiSelectAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <script>
        (() => {
            const instances = new Map();

            const normalizeLocalCode = (value) => {
                const code = String(value || '').trim();
                if (!code) return '';

                const withSuffix = code.match(/^([A-Za-z])-([0-9]+)([A-Za-z])$/);
                if (withSuffix) return `${withSuffix[1].toUpperCase()}-${withSuffix[2]}${withSuffix[3].toLowerCase()}`;

                const withoutSuffix = code.match(/^([A-Za-z])-([0-9]+)$/);
                if (withoutSuffix) return `${withoutSuffix[1].toUpperCase()}-${withoutSuffix[2]}`;

                return code.toUpperCase();
            };

            const codeKey = (value) => normalizeLocalCode(value).toUpperCase();
            const parseCodes = (raw) => {
                const parts = String(raw || '').split(/[;|,/\n\r]+/);
                const unique = [];
                const seen = new Set();
                parts.forEach((part) => {
                    const code = normalizeLocalCode(part);
                    const key = codeKey(code);
                    if (!code || seen.has(key)) return;
                    seen.add(key);
                    unique.push(code);
                });
                return unique;
            };

            const buildInstance = (root) => {
                if (!(root instanceof HTMLElement)) {
                    return null;
                }

                const hiddenInput = root.querySelector('[data-msms-hidden]');
                const button = root.querySelector('[data-msms-button]');
                const searchInput = root.querySelector('[data-msms-search]');
                const list = root.querySelector('[data-msms-list]');
                const selectedContainer = root.querySelector('[data-msms-selected]');
                const sumTargetId = String(root.dataset.sumTarget || '').trim();
                const sumTarget = sumTargetId !== '' ? document.getElementById(sumTargetId) : null;
                const emptyText = String(root.dataset.emptyText || 'Sin elementos seleccionados.');
                const sumPrefix = String(root.dataset.sumPrefix || 'Suma: ');
                const sumEmpty = String(root.dataset.sumEmpty || '-');
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
                const options = Array.from(list.querySelectorAll('.js-ct-ms-option'));
                const ufByCode = new Map();
                options.forEach((option) => {
                    const key = codeKey(option.dataset.code || '');
                    if (key === '') {
                        return;
                    }
                    const raw = String(option.dataset.arriendoUf || '').replace(',', '.').trim();
                    const parsed = Number.parseFloat(raw);
                    ufByCode.set(key, Number.isFinite(parsed) ? parsed : 0);
                });

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
                    const safe = Math.max(0, Math.min(index, visible.length - 1));
                    highlightedIndex = safe;
                    const active = visible[safe];
                    active.classList.add('active');
                    active.scrollIntoView({ block: 'nearest' });
                };

                const syncHidden = () => {
                    hiddenInput.value = selected.join(';');
                    hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const updateSum = () => {
                    if (!(sumTarget instanceof HTMLElement)) {
                        return;
                    }
                    if (selected.length === 0) {
                        sumTarget.textContent = `${sumPrefix}${sumEmpty}`;
                        return;
                    }
                    let sum = 0;
                    selected.forEach((code) => {
                        sum += ufByCode.get(codeKey(code)) || 0;
                    });
                    sumTarget.textContent = `${sumPrefix}${sum.toFixed(2)}`;
                };

                const renderSelected = () => {
                    selectedContainer.innerHTML = '';
                    if (selected.length === 0) {
                        const empty = document.createElement('div');
                        empty.className = 'text-muted small';
                        empty.textContent = emptyText;
                        selectedContainer.appendChild(empty);
                        return;
                    }

                    selected.forEach((code) => {
                        const row = document.createElement('div');
                        row.className = 'input-group';

                        const input = document.createElement('input');
                        input.type = 'text';
                        input.className = 'form-control';
                        input.readOnly = true;
                        input.value = code;

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'btn btn-outline-danger';
                        removeButton.dataset.code = code;
                        removeButton.textContent = 'Quitar';

                        row.appendChild(input);
                        row.appendChild(removeButton);
                        selectedContainer.appendChild(row);
                    });
                };

                const applyFilterAndAvailability = () => {
                    const term = searchInput.value.trim().toLowerCase();
                    options.forEach((option) => {
                        const optionCode = normalizeLocalCode(option.dataset.code || '');
                        const isSelected = selected.some((item) => codeKey(item) === codeKey(optionCode));
                        const search = String(option.dataset.search || '').toLowerCase();
                        const label = String(option.dataset.label || '').toLowerCase();
                        const matches = term === '' || search.includes(term) || label.includes(term) || optionCode.toLowerCase().includes(term);
                        option.disabled = isSelected;
                        option.classList.toggle('d-none', !matches);
                    });
                    updateHighlight(0);
                };

                const addCode = (rawCode) => {
                    const normalized = normalizeLocalCode(rawCode);
                    if (!normalized) return;
                    const key = codeKey(normalized);
                    if (selected.some((item) => codeKey(item) === key)) return;
                    selected.push(normalized);
                    syncHidden();
                    applyFilterAndAvailability();
                    renderSelected();
                    updateSum();
                };

                const removeCode = (rawCode) => {
                    const key = codeKey(rawCode);
                    selected = selected.filter((item) => codeKey(item) !== key);
                    syncHidden();
                    applyFilterAndAvailability();
                    renderSelected();
                    updateSum();
                };

                options.forEach((option) => {
                    option.addEventListener('click', () => {
                        if (option.disabled) return;
                        addCode(option.dataset.code || '');
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
                        addCode(selectedOption.dataset.code || '');
                        return;
                    }
                    if (event.key === 'Backspace' && searchInput.value.trim() === '' && selected.length > 0) {
                        event.preventDefault();
                        removeCode(selected[selected.length - 1]);
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
                    const btn = event.target.closest('button[data-code]');
                    if (!btn) return;
                    removeCode(btn.dataset.code || '');
                });

                root.addEventListener('shown.bs.dropdown', () => {
                    searchInput.focus();
                    updateHighlight(0);
                });

                applyFilterAndAvailability();
                renderSelected();
                updateSum();
                syncHidden();

                return {
                    setSelectedFromString: (raw) => {
                        selected = parseCodes(raw);
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateSum();
                    },
                    clear: () => {
                        selected = [];
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateSum();
                    },
                    getSelected: () => [...selected],
                };
            };

            const init = (rootOrId) => {
                const root = typeof rootOrId === 'string'
                    ? document.getElementById(rootOrId)
                    : rootOrId;
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
                document.querySelectorAll('[data-ct-searchable-multiselect]').forEach((root) => init(root));
            };

            window.CtSearchableMultiSelect = { init, get };
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

if (!function_exists('ctRenderSearchableMultiSelectField')) {
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
     *   sum_target_id?: string,
     *   sum_prefix?: string,
     *   sum_empty?: string,
     *   required?: bool,
     *   value?: string,
     *   options?: array<int, array{code:string, label:string, search?:string, arriendo_uf?:string}>
     * } $options
     */
    function ctRenderSearchableMultiSelectField(array $options = []): void
    {
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12'));
        $label = trim((string) ($options['label'] ?? 'Elementos'));
        $inputName = trim((string) ($options['input_name'] ?? 'codigos'));
        $inputId = trim((string) ($options['input_id'] ?? 'codigos'));
        $pickerId = trim((string) ($options['picker_id'] ?? ($inputId . '_picker')));
        $buttonId = trim((string) ($options['button_id'] ?? ($inputId . '_btn')));
        $searchId = trim((string) ($options['search_id'] ?? ($inputId . '_search')));
        $listId = trim((string) ($options['list_id'] ?? ($inputId . '_list')));
        $selectedContainerId = trim((string) ($options['selected_container_id'] ?? ($inputId . '_selected')));
        $buttonPlaceholder = trim((string) ($options['button_placeholder'] ?? 'Agregar elemento...'));
        $searchPlaceholder = trim((string) ($options['search_placeholder'] ?? 'Buscar...'));
        $emptyOptionsMessage = trim((string) ($options['empty_options_message'] ?? 'No hay elementos disponibles.'));
        $emptySelectedMessage = trim((string) ($options['empty_selected_message'] ?? 'Sin elementos seleccionados.'));
        $sumTargetId = trim((string) ($options['sum_target_id'] ?? ''));
        $sumPrefix = trim((string) ($options['sum_prefix'] ?? 'Suma: '));
        $sumEmpty = trim((string) ($options['sum_empty'] ?? '-'));
        $required = (bool) ($options['required'] ?? false);
        $value = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo ctSearchableMultiSelectEscape($wrapperClass); ?>">
            <label for="<?php echo ctSearchableMultiSelectEscape($inputId); ?>" class="form-label"><?php echo ctSearchableMultiSelectEscape($label); ?></label>
            <div
                class="dropdown w-100"
                id="<?php echo ctSearchableMultiSelectEscape($pickerId); ?>"
                data-ct-searchable-multiselect
                data-sum-target="<?php echo ctSearchableMultiSelectEscape($sumTargetId); ?>"
                data-sum-prefix="<?php echo ctSearchableMultiSelectEscape($sumPrefix); ?>"
                data-sum-empty="<?php echo ctSearchableMultiSelectEscape($sumEmpty); ?>"
                data-empty-text="<?php echo ctSearchableMultiSelectEscape($emptySelectedMessage); ?>">
                <input type="hidden" id="<?php echo ctSearchableMultiSelectEscape($inputId); ?>" name="<?php echo ctSearchableMultiSelectEscape($inputName); ?>" data-msms-hidden value="<?php echo ctSearchableMultiSelectEscape($value); ?>" <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="btn btn-outline-secondary dropdown-toggle w-100 text-start"
                    type="button"
                    id="<?php echo ctSearchableMultiSelectEscape($buttonId); ?>"
                    data-msms-button
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"><?php echo ctSearchableMultiSelectEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100" aria-labelledby="<?php echo ctSearchableMultiSelectEscape($buttonId); ?>">
                    <input type="text" id="<?php echo ctSearchableMultiSelectEscape($searchId); ?>" data-msms-search class="form-control form-control-sm mb-2" placeholder="<?php echo ctSearchableMultiSelectEscape($searchPlaceholder); ?>">
                    <div class="list-group list-group-flush overflow-auto" id="<?php echo ctSearchableMultiSelectEscape($listId); ?>" data-msms-list style="max-height: 220px;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo ctSearchableMultiSelectEscape($emptyOptionsMessage); ?></div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $code = trim((string) ($item['code'] ?? ''));
                                if ($code === '') {
                                    continue;
                                }
                                $labelItem = (string) ($item['label'] ?? $code);
                                $searchItem = (string) ($item['search'] ?? mb_strtolower($labelItem, 'UTF-8'));
                                $arriendoUf = (string) ($item['arriendo_uf'] ?? '');
                                ?>
                                <button
                                    type="button"
                                    class="list-group-item list-group-item-action py-2 px-2 js-ct-ms-option"
                                    data-code="<?php echo ctSearchableMultiSelectEscape($code); ?>"
                                    data-label="<?php echo ctSearchableMultiSelectEscape($labelItem); ?>"
                                    data-search="<?php echo ctSearchableMultiSelectEscape($searchItem); ?>"
                                    data-arriendo-uf="<?php echo ctSearchableMultiSelectEscape($arriendoUf); ?>"><?php echo ctSearchableMultiSelectEscape($labelItem); ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-text mb-2">Puedes seleccionar uno o varios elementos.</div>
                <div id="<?php echo ctSearchableMultiSelectEscape($selectedContainerId); ?>" data-msms-selected class="vstack gap-2"></div>
            </div>
        </div>
        <?php
    }
}
