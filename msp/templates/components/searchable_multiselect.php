<?php
declare(strict_types=1);

if (!function_exists('msp2SearchableMultiSelectEscape')) {
    function msp2SearchableMultiSelectEscape(string $value): string
    {
        if (function_exists('msp2Escape')) {
            /** @var callable $esc */
            $esc = 'msp2Escape';
            return (string) $esc($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('msp2RenderSearchableMultiSelectAssets')) {
    function msp2RenderSearchableMultiSelectAssets(): void
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
                const emptyText = String(root.dataset.emptyText || 'Sin locales seleccionados.');
                const sumPrefix = String(root.dataset.sumPrefix || 'Suma referencia UF locales (legado): ');
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
                const options = Array.from(list.querySelectorAll('.js-msp-ms-option'));
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
                const buttonPlaceholder = String(button.dataset.placeholder || button.textContent || 'Agregar local...').trim();
                button.dataset.placeholder = buttonPlaceholder;

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

                const updateButtonSummary = () => {
                    if (selected.length === 0) {
                        button.textContent = buttonPlaceholder;
                        button.title = buttonPlaceholder;
                        return;
                    }

                    if (selected.length === 1) {
                        const one = `1 local: ${selected[0]}`;
                        button.textContent = one;
                        button.title = one;
                        return;
                    }

                    const preview = selected.slice(0, 3).join(', ');
                    const text = selected.length <= 3
                        ? `${selected.length} locales: ${preview}`
                        : `${selected.length} locales seleccionados`;
                    button.textContent = text;
                    button.title = `${selected.length} locales: ${selected.join(', ')}`;
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

                    const header = document.createElement('div');
                    header.className = 'd-flex align-items-center justify-content-between gap-2';

                    const title = document.createElement('div');
                    title.className = 'small text-muted';
                    title.textContent = `Locales seleccionados: ${selected.length}`;
                    header.appendChild(title);

                    if (selected.length > 1) {
                        const clearButton = document.createElement('button');
                        clearButton.type = 'button';
                        clearButton.className = 'btn btn-link btn-sm p-0 text-decoration-none';
                        clearButton.dataset.action = 'clear';
                        clearButton.textContent = 'Quitar todos';
                        header.appendChild(clearButton);
                    }

                    selectedContainer.appendChild(header);

                    const chips = document.createElement('div');
                    chips.className = 'd-flex flex-wrap gap-2';

                    selected.forEach((code) => {
                        const chip = document.createElement('div');
                        chip.className = 'badge rounded-pill bg-light text-dark border d-inline-flex align-items-center gap-2 py-2 px-3';

                        const label = document.createElement('span');
                        label.className = 'fw-normal';
                        label.textContent = code;

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'btn btn-sm p-0 border-0 bg-transparent text-danger';
                        removeButton.dataset.code = code;
                        removeButton.setAttribute('aria-label', `Quitar ${code}`);
                        removeButton.textContent = 'x';

                        chip.appendChild(label);
                        chip.appendChild(removeButton);
                        chips.appendChild(chip);
                    });

                    selectedContainer.appendChild(chips);
                };

                const applyFilterAndAvailability = () => {
                    const term = searchInput.value.trim().toLowerCase();
                    options.forEach((option) => {
                        const optionCode = normalizeLocalCode(option.dataset.code || '');
                        const isSelected = selected.some((item) => codeKey(item) === codeKey(optionCode));
                        const search = String(option.dataset.search || '').toLowerCase();
                        const label = String(option.dataset.label || '').toLowerCase();
                        const matches = term === '' || search.includes(term) || label.includes(term) || optionCode.toLowerCase().includes(term);
                        option.disabled = false;
                        option.classList.toggle('d-none', !matches || isSelected);
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
                    updateButtonSummary();
                };

                const removeCode = (rawCode) => {
                    const key = codeKey(rawCode);
                    selected = selected.filter((item) => codeKey(item) !== key);
                    syncHidden();
                    applyFilterAndAvailability();
                    renderSelected();
                    updateSum();
                    updateButtonSummary();
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
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    const clearBtn = target.closest('button[data-action="clear"]');
                    if (clearBtn) {
                        selected = [];
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateSum();
                        updateButtonSummary();
                        return;
                    }
                    const btn = target.closest('button[data-code]');
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
                updateButtonSummary();
                syncHidden();

                return {
                    setSelectedFromString: (raw) => {
                        selected = parseCodes(raw);
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateSum();
                        updateButtonSummary();
                    },
                    clear: () => {
                        selected = [];
                        syncHidden();
                        applyFilterAndAvailability();
                        renderSelected();
                        updateSum();
                        updateButtonSummary();
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
                document.querySelectorAll('[data-msp-searchable-multiselect]').forEach((root) => init(root));
            };

            window.MspSearchableMultiSelect = { init, get };
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

if (!function_exists('msp2RenderSearchableMultiSelectField')) {
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
    function msp2RenderSearchableMultiSelectField(array $options = []): void
    {
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12'));
        $label = trim((string) ($options['label'] ?? 'Locales'));
        $inputName = trim((string) ($options['input_name'] ?? 'cod_locales'));
        $inputId = trim((string) ($options['input_id'] ?? 'cod_locales'));
        $pickerId = trim((string) ($options['picker_id'] ?? ($inputId . '_picker')));
        $buttonId = trim((string) ($options['button_id'] ?? ($inputId . '_btn')));
        $searchId = trim((string) ($options['search_id'] ?? ($inputId . '_search')));
        $listId = trim((string) ($options['list_id'] ?? ($inputId . '_list')));
        $selectedContainerId = trim((string) ($options['selected_container_id'] ?? ($inputId . '_selected')));
        $buttonPlaceholder = trim((string) ($options['button_placeholder'] ?? 'Seleccionar locales...'));
        $searchPlaceholder = trim((string) ($options['search_placeholder'] ?? 'Buscar local por código o descripción'));
        $emptyOptionsMessage = trim((string) ($options['empty_options_message'] ?? 'No hay locales disponibles.'));
        $emptySelectedMessage = trim((string) ($options['empty_selected_message'] ?? 'Sin locales seleccionados.'));
        $sumTargetId = trim((string) ($options['sum_target_id'] ?? ''));
        $sumPrefix = trim((string) ($options['sum_prefix'] ?? 'Suma referencia UF locales (legado): '));
        $sumEmpty = trim((string) ($options['sum_empty'] ?? '-'));
        $required = (bool) ($options['required'] ?? false);
        $value = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo msp2SearchableMultiSelectEscape($wrapperClass); ?>">
            <label for="<?php echo msp2SearchableMultiSelectEscape($inputId); ?>" class="form-label"><?php echo msp2SearchableMultiSelectEscape($label); ?></label>
            <div
                class="dropdown w-100"
                id="<?php echo msp2SearchableMultiSelectEscape($pickerId); ?>"
                data-msp-searchable-multiselect
                data-sum-target="<?php echo msp2SearchableMultiSelectEscape($sumTargetId); ?>"
                data-sum-prefix="<?php echo msp2SearchableMultiSelectEscape($sumPrefix); ?>"
                data-sum-empty="<?php echo msp2SearchableMultiSelectEscape($sumEmpty); ?>"
                data-empty-text="<?php echo msp2SearchableMultiSelectEscape($emptySelectedMessage); ?>">
                <input type="hidden" id="<?php echo msp2SearchableMultiSelectEscape($inputId); ?>" name="<?php echo msp2SearchableMultiSelectEscape($inputName); ?>" data-msms-hidden value="<?php echo msp2SearchableMultiSelectEscape($value); ?>" <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="btn btn-outline-secondary dropdown-toggle w-100 text-start"
                    type="button"
                    id="<?php echo msp2SearchableMultiSelectEscape($buttonId); ?>"
                    data-msms-button
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"><?php echo msp2SearchableMultiSelectEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100" aria-labelledby="<?php echo msp2SearchableMultiSelectEscape($buttonId); ?>">
                    <input type="text" id="<?php echo msp2SearchableMultiSelectEscape($searchId); ?>" data-msms-search class="form-control form-control-sm mb-2" placeholder="<?php echo msp2SearchableMultiSelectEscape($searchPlaceholder); ?>">
                    <div class="list-group list-group-flush overflow-auto" id="<?php echo msp2SearchableMultiSelectEscape($listId); ?>" data-msms-list style="max-height: 220px;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo msp2SearchableMultiSelectEscape($emptyOptionsMessage); ?></div>
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
                                    class="list-group-item list-group-item-action py-2 px-2 js-msp-ms-option"
                                    data-code="<?php echo msp2SearchableMultiSelectEscape($code); ?>"
                                    data-label="<?php echo msp2SearchableMultiSelectEscape($labelItem); ?>"
                                    data-search="<?php echo msp2SearchableMultiSelectEscape($searchItem); ?>"
                                    data-arriendo-uf="<?php echo msp2SearchableMultiSelectEscape($arriendoUf); ?>"><?php echo msp2SearchableMultiSelectEscape($labelItem); ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="form-text mb-2">Puedes seleccionar uno o varios locales.</div>
                <div id="<?php echo msp2SearchableMultiSelectEscape($selectedContainerId); ?>" data-msms-selected class="vstack gap-2"></div>
            </div>
        </div>
        <?php
    }
}
