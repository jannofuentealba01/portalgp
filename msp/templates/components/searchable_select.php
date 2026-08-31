<?php
declare(strict_types=1);

if (!function_exists('msp2SearchableSelectEscape')) {
    function msp2SearchableSelectEscape(string $value): string
    {
        if (function_exists('msp2Escape')) {
            /** @var callable $esc */
            $esc = 'msp2Escape';
            return (string) $esc($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('msp2RenderSearchableSelectAssets')) {
    function msp2RenderSearchableSelectAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .msp-searchable-select-menu {
            min-width: 100%;
        }

        .msp-searchable-select-list {
            overflow-y: auto;
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        [data-msp-searchable-select] [data-searchable-btn] {
            display: block;
            width: 100%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            padding-right: 2.25rem;
        }
        </style>
        <script>
        (() => {
            const initSearchableSelect = (root) => {
                if (!(root instanceof HTMLElement)) {
                    return;
                }
                if (root.dataset.searchableBound === '1') {
                    return;
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
                    return;
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

                const initialValue = hiddenInput.value.trim();
                if (initialValue !== '') {
                    const selected = options.find((option) => (option.dataset.value || '') === initialValue);
                    if (selected) {
                        const label = selected.dataset.label || dropdownBtn.dataset.placeholder || 'Selecciona...';
                        dropdownBtn.textContent = label;
                        dropdownBtn.title = label;
                    }
                }
            };

            const bindAll = () => {
                document.querySelectorAll('[data-msp-searchable-select]').forEach(initSearchableSelect);
            };

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

if (!function_exists('msp2RenderSearchableSelectField')) {
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
    function msp2RenderSearchableSelectField(array $options = []): void
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
        $errorMessage = trim((string) ($options['error_message'] ?? 'Debes seleccionar una opción.'));
        $buttonPlaceholder = trim((string) ($options['button_placeholder'] ?? 'Selecciona...'));
        $filterPlaceholder = trim((string) ($options['filter_placeholder'] ?? 'Buscar...'));
        $emptyMessage = trim((string) ($options['empty_message'] ?? 'Sin opciones disponibles.'));
        $buttonClass = trim((string) ($options['button_class'] ?? 'btn btn-outline-secondary dropdown-toggle w-100 text-start'));
        $listMaxHeight = trim((string) ($options['list_max_height'] ?? '260px'));
        $required = (bool) ($options['required'] ?? false);
        $currentValue = trim((string) ($options['value'] ?? ''));
        $items = is_array($options['options'] ?? null) ? $options['options'] : [];
        ?>
        <div class="<?php echo msp2SearchableSelectEscape($wrapperClass); ?>">
            <label class="form-label"><?php echo msp2SearchableSelectEscape($label); ?></label>
            <div class="dropdown w-100" id="<?php echo msp2SearchableSelectEscape($pickerId); ?>" data-msp-searchable-select data-error-target="<?php echo msp2SearchableSelectEscape($errorId); ?>">
                <input
                    type="hidden"
                    data-searchable-hidden
                    id="<?php echo msp2SearchableSelectEscape($inputId); ?>"
                    name="<?php echo msp2SearchableSelectEscape($inputName); ?>"
                    value="<?php echo msp2SearchableSelectEscape($currentValue); ?>"
                    <?php echo $required ? 'required' : ''; ?>>
                <button
                    class="<?php echo msp2SearchableSelectEscape($buttonClass); ?>"
                    type="button"
                    id="<?php echo msp2SearchableSelectEscape($buttonId); ?>"
                    data-searchable-btn
                    data-placeholder="<?php echo msp2SearchableSelectEscape($buttonPlaceholder); ?>"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    aria-expanded="false"><?php echo msp2SearchableSelectEscape($buttonPlaceholder); ?></button>
                <div class="dropdown-menu p-2 w-100 msp-searchable-select-menu">
                    <input
                        type="text"
                        id="<?php echo msp2SearchableSelectEscape($filterId); ?>"
                        data-searchable-filter
                        class="form-control form-control-sm mb-2"
                        placeholder="<?php echo msp2SearchableSelectEscape($filterPlaceholder); ?>">
                    <div class="list-group list-group-flush msp-searchable-select-list" id="<?php echo msp2SearchableSelectEscape($listId); ?>" data-searchable-list style="max-height: <?php echo msp2SearchableSelectEscape($listMaxHeight); ?>;">
                        <?php if ($items === []): ?>
                            <div class="small text-muted px-2 py-1"><?php echo msp2SearchableSelectEscape($emptyMessage); ?></div>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <?php
                                $itemValue = (string) ($item['value'] ?? '');
                                $itemLabel = (string) ($item['label'] ?? $itemValue);
                                $itemLabelHtmlRaw = isset($item['label_html']) ? (string) $item['label_html'] : '';
                                $itemLabelHtml = $itemLabelHtmlRaw !== '' ? $itemLabelHtmlRaw : null;
                                $itemSearch = (string) ($item['search'] ?? mb_strtolower($itemLabel, 'UTF-8'));
                                $itemAttrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                                ?>
                                <button
                                    type="button"
                                    class="list-group-item list-group-item-action js-searchable-option"
                                    data-value="<?php echo msp2SearchableSelectEscape($itemValue); ?>"
                                    data-label="<?php echo msp2SearchableSelectEscape($itemLabel); ?>"
                                    data-search="<?php echo msp2SearchableSelectEscape($itemSearch); ?>"
                                    <?php foreach ($itemAttrs as $attrKey => $attrValue): ?>
                                        <?php
                                        $attrKeyNorm = strtolower(trim((string) $attrKey));
                                        if ($attrKeyNorm === '' || !preg_match('/^[a-z0-9_-]+$/', $attrKeyNorm)) {
                                            continue;
                                        }
                                        ?>
                                        data-<?php echo msp2SearchableSelectEscape($attrKeyNorm); ?>="<?php echo msp2SearchableSelectEscape((string) ($attrValue ?? '')); ?>"
                                    <?php endforeach; ?>
                                    ><?php if ($itemLabelHtml !== null): ?><?php echo $itemLabelHtml; ?><?php else: ?><?php echo msp2SearchableSelectEscape($itemLabel); ?><?php endif; ?></button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="invalid-feedback d-block d-none" id="<?php echo msp2SearchableSelectEscape($errorId); ?>"><?php echo msp2SearchableSelectEscape($errorMessage); ?></div>
        </div>
        <?php
    }
}
