<?php
declare(strict_types=1);

if (!function_exists('ctSearchableSelectEscape')) {
    function ctSearchableSelectEscape(string $value): string
    {
        if (function_exists('ctEscape')) {
            /** @var callable $esc */
            $esc = 'ctEscape';
            return (string) $esc($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('ctRenderSearchableSelectAssets')) {
    function ctRenderSearchableSelectAssets(): void
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

            const initSearchableSelect = (root) => {
                if (!(root instanceof HTMLElement)) {
                    return null;
                }
                const key = root.id || String(Math.random());
                if (instances.has(key)) {
                    return instances.get(key);
                }
                if (root.dataset.searchableBound === '1') {
                    return null;
                }
                root.dataset.searchableBound = '1';

                const hiddenInput = root.querySelector('[data-searchable-hidden]');
                const dropdownBtn = root.querySelector('[data-searchable-btn]');
                const dropdownFilter = root.querySelector('[data-searchable-filter]');
                const dropdownList = root.querySelector('[data-searchable-list]');
                const errorTargetId = root.dataset.errorTarget || '';
                const errorTarget = errorTargetId !== '' ? document.getElementById(errorTargetId) : null;
                if (
                    !(hiddenInput instanceof HTMLInputElement)
                    || !(dropdownBtn instanceof HTMLButtonElement)
                    || !(dropdownFilter instanceof HTMLInputElement)
                    || !(dropdownList instanceof HTMLElement)
                ) {
                    return null;
                }

                const options = Array.from(dropdownList.querySelectorAll('.js-searchable-option'));
                let highlightedIndex = -1;
                let openedByKeyboard = false;

                const getVisibleOptions = () => options.filter((option) => !option.classList.contains('d-none'));

                const updateHighlight = (index) => {
                    const visible = getVisibleOptions();
                    visible.forEach((option) => option.classList.remove('active'));
                    if (visible.length === 0) {
                        highlightedIndex = -1;
                        return;
                    }
                    const safeIndex = Math.max(0, Math.min(index, visible.length - 1));
                    highlightedIndex = safeIndex;
                    const activeOption = visible[safeIndex];
                    activeOption.classList.add('active');
                    activeOption.scrollIntoView({ block: 'nearest' });
                };

                const closeDropdown = () => {
                    const bsDropdown = window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(dropdownBtn) : null;
                    if (bsDropdown) {
                        bsDropdown.hide();
                        return;
                    }
                    root.classList.remove('show');
                    const menu = root.querySelector('.dropdown-menu');
                    if (menu) {
                        menu.classList.remove('show');
                    }
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                };

                const selectOption = (option) => {
                    if (dropdownBtn.disabled) {
                        return;
                    }
                    hiddenInput.value = option.dataset.value || '';
                    hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    const label = option.dataset.label || dropdownBtn.dataset.placeholder || 'Selecciona...';
                    dropdownBtn.textContent = label;
                    dropdownBtn.title = label;
                    dropdownBtn.classList.remove('is-invalid');
                    if (errorTarget instanceof HTMLElement) {
                        errorTarget.classList.add('d-none');
                    }
                    closeDropdown();
                };

                const filterOptions = () => {
                    if (dropdownBtn.disabled) {
                        return;
                    }
                    const term = dropdownFilter.value.trim().toLowerCase();
                    options.forEach((option) => {
                        if (option.hidden) {
                            option.classList.add('d-none');
                            return;
                        }
                        const searchable = String(option.dataset.search || '').toLowerCase();
                        option.classList.toggle('d-none', !(term === '' || searchable.includes(term)));
                    });
                    updateHighlight(0);
                };

                const selectHighlightedOrFirst = () => {
                    const visible = getVisibleOptions();
                    if (visible.length === 0) {
                        return;
                    }
                    const option = highlightedIndex >= 0 && highlightedIndex < visible.length
                        ? visible[highlightedIndex]
                        : visible[0];
                    selectOption(option);
                };

                options.forEach((option) => {
                    option.addEventListener('click', () => selectOption(option));
                    option.addEventListener('mouseenter', () => {
                        const visible = getVisibleOptions();
                        const idx = visible.indexOf(option);
                        if (idx >= 0) {
                            updateHighlight(idx);
                        }
                    });
                });

                dropdownFilter.addEventListener('input', filterOptions);
                const handleNavKeydown = (event) => {
                    const visible = getVisibleOptions();
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
                        selectHighlightedOrFirst();
                        return;
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeDropdown();
                    }
                };
                dropdownFilter.addEventListener('keydown', handleNavKeydown);
                dropdownBtn.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter') {
                        openedByKeyboard = true;
                    }
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeDropdown();
                    }
                });

                const focusFilterAndPrime = () => {
                    if (dropdownBtn.disabled) {
                        return;
                    }
                    dropdownFilter.focus();
                    if (openedByKeyboard) {
                        openedByKeyboard = false;
                    }
                    updateHighlight(0);
                };

                root.addEventListener('shown.bs.dropdown', focusFilterAndPrime);
                dropdownBtn.addEventListener('shown.bs.dropdown', focusFilterAndPrime);
                dropdownBtn.addEventListener('click', () => {
                    window.setTimeout(() => {
                        if (root.classList.contains('show')) {
                            focusFilterAndPrime();
                        }
                    }, 0);
                });

                const setValue = (value) => {
                    hiddenInput.value = String(value || '').trim();
                    const selected = options.find((option) => (option.dataset.value || '') === hiddenInput.value);
                    if (selected) {
                        const label = selected.dataset.label || dropdownBtn.dataset.placeholder || 'Selecciona...';
                        dropdownBtn.textContent = label;
                        dropdownBtn.title = label;
                    } else {
                        const placeholder = dropdownBtn.dataset.placeholder || 'Selecciona...';
                        dropdownBtn.textContent = placeholder;
                        dropdownBtn.title = placeholder;
                    }
                    hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                    hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                };

                const initialValue = hiddenInput.value.trim();
                if (initialValue !== '') {
                    setValue(initialValue);
                }

                const instance = {
                    setValue,
                    getValue: () => hiddenInput.value,
                    clear: () => setValue(''),
                };
                instances.set(key, instance);
                return instance;
            };

            const init = (rootOrId) => {
                const root = typeof rootOrId === 'string'
                    ? document.getElementById(rootOrId)
                    : rootOrId;
                if (!(root instanceof HTMLElement)) {
                    return null;
                }
                return initSearchableSelect(root);
            };

            const get = (rootId) => {
                const key = String(rootId || '');
                if (key !== '' && instances.has(key)) {
                    return instances.get(key);
                }
                return init(rootId);
            };

            const bindAll = () => {
                document.querySelectorAll('[data-ct-searchable-select]').forEach(initSearchableSelect);
            };

            window.CtSearchableSelect = { init, get };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bindAll);
            } else {
                bindAll();
            }
        })();
        </script>
        <?php
    }
}

if (!function_exists('ctRenderSearchableSelectField')) {
    /**
     * @param array{
     *   wrapper_class?: string,
     *   label?: string,
     *   input_name?: string,
     *   input_id?: string,
     *   picker_id?: string,
     *   button_id?: string,
     *   filter_id?: string,
     *   list_id?: string,
     *   error_id?: string,
     *   error_message?: string,
     *   button_placeholder?: string,
     *   filter_placeholder?: string,
     *   empty_message?: string,
     *   button_class?: string,
     *   input_class?: string,
     *   required?: bool,
     *   show_requirement?: bool,
     *   optional_text?: string,
     *   disabled?: bool,
     *   value?: string,
     *   options?: array<int, array{value:string, label:string, search?:string, attrs?:array<string, scalar|null>}>
     * } $options
     */
    function ctRenderSearchableSelectField(array $options = []): void
    {
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'col-12 col-md-4'));
        $label = trim((string) ($options['label'] ?? 'Seleccionar'));
        $inputName = trim((string) ($options['input_name'] ?? 'searchable_value'));
        $inputId = trim((string) ($options['input_id'] ?? 'searchable_value'));
        $pickerId = trim((string) ($options['picker_id'] ?? ($inputId . '_picker')));
        $buttonId = trim((string) ($options['button_id'] ?? ($inputId . '_btn')));
        $filterId = trim((string) ($options['filter_id'] ?? ($inputId . '_filter')));
        $listId = trim((string) ($options['list_id'] ?? ($inputId . '_list')));
        $errorId = trim((string) ($options['error_id'] ?? ($inputId . '_error')));
        $errorMessage = trim((string) ($options['error_message'] ?? 'Debes seleccionar una opcion.'));
        $buttonPlaceholder = trim((string) ($options['button_placeholder'] ?? 'Selecciona...'));
        $filterPlaceholder = trim((string) ($options['filter_placeholder'] ?? 'Buscar...'));
        $emptyMessage = trim((string) ($options['empty_message'] ?? 'Sin opciones disponibles.'));
        $buttonClass = trim((string) ($options['button_class'] ?? 'btn btn-outline-secondary dropdown-toggle w-100 text-start'));
        $inputClass = trim((string) ($options['input_class'] ?? ''));
        $required = (bool) ($options['required'] ?? false);
        $showRequirement = (bool) ($options['show_requirement'] ?? false);
        $optionalText = trim((string) ($options['optional_text'] ?? 'opcional'));
        $disabled = (bool) ($options['disabled'] ?? false);
        $currentValue = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo ctSearchableSelectEscape($wrapperClass); ?>">
            <?php if ($label !== ''): ?>
                <?php if ($showRequirement): ?>
                    <label class="form-label ct-label">
                        <span><?php echo ctSearchableSelectEscape($label); ?></span>
                        <?php if ($required): ?>
                            <span class="ct-required" aria-hidden="true">*</span>
                            <span class="visually-hidden">(Obligatorio)</span>
                        <?php else: ?>
                            <span class="ct-optional">(<?php echo ctSearchableSelectEscape($optionalText); ?>)</span>
                        <?php endif; ?>
                    </label>
                <?php else: ?>
                    <label class="form-label"><?php echo ctSearchableSelectEscape($label); ?></label>
                <?php endif; ?>
            <?php endif; ?>
            <div class="dropdown w-100" id="<?php echo ctSearchableSelectEscape($pickerId); ?>" data-ct-searchable-select data-error-target="<?php echo ctSearchableSelectEscape($errorId); ?>">
                <input
                    type="hidden"
                    data-searchable-hidden
                    id="<?php echo ctSearchableSelectEscape($inputId); ?>"
                    name="<?php echo ctSearchableSelectEscape($inputName); ?>"
                    class="<?php echo ctSearchableSelectEscape($inputClass); ?>"
                    value="<?php echo ctSearchableSelectEscape($currentValue); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="<?php echo ctSearchableSelectEscape($buttonClass); ?>"
                    type="button"
                    id="<?php echo ctSearchableSelectEscape($buttonId); ?>"
                    data-searchable-btn
                    data-placeholder="<?php echo ctSearchableSelectEscape($buttonPlaceholder); ?>"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"
                    <?php echo $disabled ? 'disabled aria-disabled="true"' : ''; ?>><?php echo ctSearchableSelectEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100">
                    <input
                        type="text"
                        id="<?php echo ctSearchableSelectEscape($filterId); ?>"
                        data-searchable-filter
                        class="form-control form-control-sm mb-2"
                        placeholder="<?php echo ctSearchableSelectEscape($filterPlaceholder); ?>"
                        <?php echo $disabled ? 'disabled' : ''; ?>>
                    <div class="list-group list-group-flush overflow-auto" id="<?php echo ctSearchableSelectEscape($listId); ?>" data-searchable-list style="max-height: 260px;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo ctSearchableSelectEscape($emptyMessage); ?></div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemValue = (string) ($item['value'] ?? '');
                                $itemLabel = (string) ($item['label'] ?? $itemValue);
                                $itemSearch = (string) ($item['search'] ?? mb_strtolower($itemLabel, 'UTF-8'));
                                $itemAttrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                                ?>
                                <button
                                    type="button"
                                    class="list-group-item list-group-item-action js-searchable-option"
                                    data-value="<?php echo ctSearchableSelectEscape($itemValue); ?>"
                                    data-label="<?php echo ctSearchableSelectEscape($itemLabel); ?>"
                                    data-search="<?php echo ctSearchableSelectEscape($itemSearch); ?>"
                                    <?php foreach ($itemAttrs as $attrKey => $attrValue): ?>
                                        <?php
                                        $attrKeyNorm = strtolower(trim((string) $attrKey));
                                        if ($attrKeyNorm === '' || !preg_match('/^[a-z0-9_-]+$/', $attrKeyNorm)) {
                                            continue;
                                        }
                                        ?>
                                        data-<?php echo ctSearchableSelectEscape($attrKeyNorm); ?>="<?php echo ctSearchableSelectEscape((string) ($attrValue ?? '')); ?>"
                                    <?php endforeach; ?>
                                    ><?php echo ctSearchableSelectEscape($itemLabel); ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="invalid-feedback d-block d-none" id="<?php echo ctSearchableSelectEscape($errorId); ?>"><?php echo ctSearchableSelectEscape($errorMessage); ?></div>
        </div>
        <?php
    }
}
