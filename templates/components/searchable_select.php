<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpRenderSearchableSelectAssets')) {
    function gpRenderSearchableSelectAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .gp-searchable-select-btn {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 38px;
            border: 1px solid var(--color-border, #ced4da);
            background: #fff;
            color: var(--color-text, #212529);
            font-weight: 400;
        }

        .gp-searchable-select-btn:hover,
        .gp-searchable-select-btn:focus,
        .gp-searchable-select-btn.show {
            background: #fff;
            color: var(--color-text, #212529);
            border-color: var(--color-primary, #0d6efd);
            box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .18);
        }

        .gp-searchable-select-btn::after {
            margin-left: auto;
        }

        .gp-searchable-select-menu {
            min-width: 100%;
        }

        .gp-searchable-select-list {
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }
        </style>
        <script>
        (() => {
            const instances = new Map();

            const initSearchableSelect = (root) => {
                if (!(root instanceof HTMLElement)) return null;
                const key = root.id || String(Math.random());
                if (instances.has(key)) return instances.get(key);
                if (root.dataset.searchableBound === '1') return null;
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

                const options = Array.from(dropdownList.querySelectorAll('.js-gp-searchable-option'));
                let highlightedIndex = -1;

                const visibleOptions = () => options.filter((option) => !option.classList.contains('d-none'));

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

                const closeDropdown = () => {
                    const bsDropdown = window.bootstrap ? window.bootstrap.Dropdown.getOrCreateInstance(dropdownBtn) : null;
                    if (bsDropdown) {
                        bsDropdown.hide();
                        return;
                    }
                    root.classList.remove('show');
                    const menu = root.querySelector('.dropdown-menu');
                    if (menu) menu.classList.remove('show');
                    dropdownBtn.setAttribute('aria-expanded', 'false');
                };

                const setValue = (value, emit = true) => {
                    hiddenInput.value = String(value || '').trim();
                    const selected = options.find((option) => (option.dataset.value || '') === hiddenInput.value);
                    const label = selected
                        ? (selected.dataset.label || dropdownBtn.dataset.placeholder || 'Selecciona...')
                        : (dropdownBtn.dataset.placeholder || 'Selecciona...');
                    dropdownBtn.textContent = label;
                    dropdownBtn.title = label;
                    dropdownBtn.classList.remove('is-invalid');
                    if (errorTarget instanceof HTMLElement) errorTarget.classList.add('d-none');
                    if (emit) {
                        hiddenInput.dispatchEvent(new Event('input', { bubbles: true }));
                        hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                };

                const selectOption = (option) => {
                    setValue(option.dataset.value || '');
                    closeDropdown();
                };

                const filterOptions = () => {
                    const term = dropdownFilter.value.trim().toLowerCase();
                    options.forEach((option) => {
                        const searchable = String(option.dataset.search || '').toLowerCase();
                        option.classList.toggle('d-none', !(term === '' || searchable.includes(term)));
                    });
                    updateHighlight(0);
                };

                const selectHighlightedOrFirst = () => {
                    const visible = visibleOptions();
                    if (visible.length === 0) return;
                    selectOption(visible[highlightedIndex >= 0 ? highlightedIndex : 0]);
                };

                options.forEach((option) => {
                    option.addEventListener('click', () => selectOption(option));
                    option.addEventListener('mouseenter', () => {
                        const idx = visibleOptions().indexOf(option);
                        if (idx >= 0) updateHighlight(idx);
                    });
                });

                dropdownFilter.addEventListener('input', filterOptions);
                dropdownFilter.addEventListener('keydown', (event) => {
                    const visible = visibleOptions();
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (visible.length > 0) updateHighlight(highlightedIndex < 0 ? 0 : highlightedIndex + 1);
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (visible.length > 0) updateHighlight(highlightedIndex < 0 ? 0 : highlightedIndex - 1);
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        selectHighlightedOrFirst();
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        closeDropdown();
                    }
                });

                root.addEventListener('shown.bs.dropdown', () => {
                    dropdownFilter.focus();
                    updateHighlight(0);
                });

                dropdownBtn.addEventListener('click', () => {
                    window.setTimeout(() => {
                        if (root.classList.contains('show')) {
                            dropdownFilter.focus();
                            updateHighlight(0);
                        }
                    }, 0);
                });

                if (hiddenInput.value.trim() !== '') {
                    setValue(hiddenInput.value, false);
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
                const root = typeof rootOrId === 'string' ? document.getElementById(rootOrId) : rootOrId;
                return root instanceof HTMLElement ? initSearchableSelect(root) : null;
            };

            const get = (rootId) => {
                const key = String(rootId || '');
                if (key !== '' && instances.has(key)) return instances.get(key);
                return init(rootId);
            };

            const bindAll = () => {
                document.querySelectorAll('[data-gp-searchable-select]').forEach(initSearchableSelect);
            };

            window.GpSearchableSelect = { init, get };
            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bindAll);
            else bindAll();
        })();
        </script>
        <?php
    }
}

if (!function_exists('gpRenderSearchableSelectField')) {
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
     *   list_max_height?: string,
     *   required?: bool,
     *   value?: string,
     *   options?: array<int, array{value:string, label:string, label_html?:string, search?:string, attrs?:array<string, scalar|null>}>
     * } $options
     */
    function gpRenderSearchableSelectField(array $options = []): void
    {
        gpRenderSearchableSelectAssets();

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
        $buttonClass = trim((string) ($options['button_class'] ?? 'btn gp-searchable-select-btn dropdown-toggle w-100 text-start'));
        $listMaxHeight = trim((string) ($options['list_max_height'] ?? '260px'));
        $required = (bool) ($options['required'] ?? false);
        $currentValue = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo gpComponentEscape($wrapperClass); ?>">
            <label class="form-label"><?php echo gpComponentEscape($label); ?></label>
            <div class="dropdown w-100" id="<?php echo gpComponentEscape($pickerId); ?>" data-gp-searchable-select data-error-target="<?php echo gpComponentEscape($errorId); ?>">
                <input
                    type="hidden"
                    data-searchable-hidden
                    id="<?php echo gpComponentEscape($inputId); ?>"
                    name="<?php echo gpComponentEscape($inputName); ?>"
                    value="<?php echo gpComponentEscape($currentValue); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="<?php echo gpComponentEscape($buttonClass); ?>"
                    type="button"
                    id="<?php echo gpComponentEscape($buttonId); ?>"
                    data-searchable-btn
                    data-placeholder="<?php echo gpComponentEscape($buttonPlaceholder); ?>"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"><?php echo gpComponentEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100 gp-searchable-select-menu">
                    <input
                        type="text"
                        id="<?php echo gpComponentEscape($filterId); ?>"
                        data-searchable-filter
                        class="form-control form-control-sm mb-2"
                        placeholder="<?php echo gpComponentEscape($filterPlaceholder); ?>">
                    <div class="list-group list-group-flush gp-searchable-select-list" id="<?php echo gpComponentEscape($listId); ?>" data-searchable-list style="max-height: <?php echo gpComponentEscape($listMaxHeight); ?>;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo gpComponentEscape($emptyMessage); ?></div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemValue = (string) ($item['value'] ?? '');
                                $itemLabel = (string) ($item['label'] ?? $itemValue);
                                $itemLabelHtml = isset($item['label_html']) ? (string) $item['label_html'] : '';
                                $itemSearch = (string) ($item['search'] ?? mb_strtolower($itemLabel, 'UTF-8'));
                                $itemAttrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                                ?>
                                <button
                                    type="button"
                                    class="list-group-item list-group-item-action js-gp-searchable-option"
                                    data-value="<?php echo gpComponentEscape($itemValue); ?>"
                                    data-label="<?php echo gpComponentEscape($itemLabel); ?>"
                                    data-search="<?php echo gpComponentEscape($itemSearch); ?>"
                                    <?php foreach ($itemAttrs as $attrKey => $attrValue): ?>
                                        <?php
                                        $attrKeyNorm = strtolower(trim((string) $attrKey));
                                        if ($attrKeyNorm === '' || !preg_match('/^[a-z0-9_-]+$/', $attrKeyNorm)) {
                                            continue;
                                        }
                                        ?>
                                        data-<?php echo gpComponentEscape($attrKeyNorm); ?>="<?php echo gpComponentEscape((string) ($attrValue ?? '')); ?>"
                                    <?php endforeach; ?>
                                ><?php echo $itemLabelHtml !== '' ? $itemLabelHtml : gpComponentEscape($itemLabel); ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="invalid-feedback d-block d-none" id="<?php echo gpComponentEscape($errorId); ?>"><?php echo gpComponentEscape($errorMessage); ?></div>
        </div>
        <?php
    }
}
