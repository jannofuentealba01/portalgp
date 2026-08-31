<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('gpCrudTableInvoke')) {
    /**
     * @param callable $callback
     * @param array<int, mixed> $args
     * @return mixed
     */
    function gpCrudTableInvoke(callable $callback, array $args = []): mixed
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

if (!function_exists('gpCrudTableCapture')) {
    /**
     * @param callable $callback
     * @param array<int, mixed> $args
     */
    function gpCrudTableCapture(callable $callback, array $args = []): string
    {
        ob_start();
        $result = gpCrudTableInvoke($callback, $args);
        $buffer = (string) ob_get_clean();
        if (is_string($result) && $result !== '') {
            return $buffer . $result;
        }

        return $buffer;
    }
}

if (!function_exists('gpRenderCrudTableAssets')) {
    function gpRenderCrudTableAssets(): void
    {
        static $assetsRendered = false;
        if ($assetsRendered) {
            return;
        }
        $assetsRendered = true;
        ?>
        <style>
        .gp-crud-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 16px 0 10px;
        }

        .gp-crud-shell {
            border: 1px solid var(--color-border, #d9dee7);
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow-sm, 0 2px 8px rgba(15, 23, 42, 0.06));
            overflow: hidden;
        }

        .gp-crud-footer {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-top: 12px;
            gap: 8px;
        }

        .gp-crud-primary-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 10px;
            border: 1px solid #0b3a6e;
            background: #0b3a6e;
            color: #fff;
            font-weight: 600;
            padding: 8px 13px;
            line-height: 1.1;
            box-shadow: 0 6px 16px rgba(11, 58, 110, 0.2);
        }

        .gp-crud-primary-btn:hover,
        .gp-crud-primary-btn:focus {
            background: #082a4e;
            border-color: #082a4e;
            color: #fff;
        }

        .gp-crud-actions-menu .dropdown-toggle::after {
            display: none;
        }

        .gp-crud-actions-trigger {
            min-width: 42px;
            min-height: 32px;
            border-radius: 10px;
        }

        .gp-crud-actions-menu .dropdown-menu {
            min-width: 220px;
            border-radius: 12px;
            border-color: var(--color-border, #d9dee7);
            box-shadow: 0 12px 30px rgba(16, 24, 40, 0.14);
        }

        .gp-crud-actions-menu .dropdown-item {
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 767.98px) {
            .gp-crud-meta {
                flex-direction: column;
                align-items: stretch;
            }
        }
        </style>
        <?php
    }
}

if (!function_exists('gpCrudTableRenderSlot')) {
    /**
     * @param string|callable|null $slot
     * @param array<int, mixed> $args
     */
    function gpCrudTableRenderSlot(string|callable|null $slot, array $args = []): string
    {
        if ($slot === null || $slot === '') {
            return '';
        }
        if (is_callable($slot)) {
            return gpCrudTableCapture($slot, $args);
        }

        return (string) $slot;
    }
}

if (!function_exists('gpRenderCrudPrimaryAction')) {
    /**
     * @param array{
     *   label?: string,
     *   icon?: string,
     *   href?: string,
     *   class?: string,
     *   attrs?: array<string,mixed>
     * } $options
     */
    function gpRenderCrudPrimaryAction(array $options = []): void
    {
        gpRenderCrudTableAssets();

        $label = trim((string) ($options['label'] ?? 'Acción'));
        $icon = trim((string) ($options['icon'] ?? ''));
        $href = trim((string) ($options['href'] ?? ''));
        $class = trim((string) ($options['class'] ?? 'btn gp-crud-primary-btn'));
        $attrs = is_array($options['attrs'] ?? null) ? $options['attrs'] : [];
        $iconHtml = $icon !== ''
            ? '<i class="' . gpComponentEscape($icon) . '" aria-hidden="true"></i>'
            : '';

        if ($href !== '') {
            echo '<a href="' . gpComponentEscape($href) . '" class="' . gpComponentEscape($class) . '"' . gpComponentAttrs($attrs) . '>' . $iconHtml . '<span>' . gpComponentEscape($label) . '</span></a>';
            return;
        }

        if (!isset($attrs['type'])) {
            $attrs['type'] = 'button';
        }
        echo '<button class="' . gpComponentEscape($class) . '"' . gpComponentAttrs($attrs) . '>' . $iconHtml . '<span>' . gpComponentEscape($label) . '</span></button>';
    }
}

if (!function_exists('gpRenderCrudActionsMenu')) {
    /**
     * @param array{
     *   trigger_title?: string,
     *   menu_class?: string,
     *   trigger_class?: string,
     *   wrapper_class?: string,
     *   items?: array<int, array{
     *      type?: string,
     *      label?: string,
     *      icon?: string,
     *      href?: string,
     *      attrs?: array<string,mixed>,
     *      class?: string,
     *      form_attrs?: array<string,mixed>,
     *      fields?: array<string,mixed>,
     *      button_attrs?: array<string,mixed>,
     *      button_class?: string
     *   }>
     * } $options
     */
    function gpRenderCrudActionsMenu(array $options = []): void
    {
        gpRenderCrudTableAssets();

        $triggerTitle = trim((string) ($options['trigger_title'] ?? 'Más acciones'));
        $menuClass = trim((string) ($options['menu_class'] ?? 'dropdown-menu dropdown-menu-end'));
        $triggerClass = trim((string) ($options['trigger_class'] ?? 'btn btn-outline-secondary btn-sm gp-crud-actions-trigger'));
        $wrapperClass = trim((string) ($options['wrapper_class'] ?? 'dropdown gp-crud-actions-menu'));
        $items = is_array($options['items'] ?? null) ? $options['items'] : [];
        ?>
        <div class="<?php echo gpComponentEscape($wrapperClass); ?>">
            <button
                class="<?php echo gpComponentEscape($triggerClass); ?>"
                type="button"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                aria-expanded="false"
                title="<?php echo gpComponentEscape($triggerTitle); ?>">
                <i class="bi bi-three-dots-vertical" aria-hidden="true"></i>
            </button>
            <ul class="<?php echo gpComponentEscape($menuClass); ?>">
                <?php foreach ($items as $item): ?>
                    <?php
                    $type = strtolower(trim((string) ($item['type'] ?? 'button')));
                    if ($type === 'divider'):
                        ?>
                        <li><hr class="dropdown-divider"></li>
                        <?php
                        continue;
                    endif;

                    $label = trim((string) ($item['label'] ?? 'Acción'));
                    $icon = trim((string) ($item['icon'] ?? ''));
                    $iconHtml = $icon !== '' ? '<i class="' . gpComponentEscape($icon) . '" aria-hidden="true"></i>' : '';
                    ?>
                    <li>
                        <?php if ($type === 'link'): ?>
                            <?php
                            $href = trim((string) ($item['href'] ?? '#'));
                            $class = trim((string) ($item['class'] ?? 'dropdown-item'));
                            $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                            ?>
                            <a href="<?php echo gpComponentEscape($href); ?>" class="<?php echo gpComponentEscape($class); ?>"<?php echo gpComponentAttrs($attrs); ?>>
                                <?php echo $iconHtml; ?><span><?php echo gpComponentEscape($label); ?></span>
                            </a>
                        <?php elseif ($type === 'form'): ?>
                            <?php
                            $formAttrs = is_array($item['form_attrs'] ?? null) ? $item['form_attrs'] : [];
                            if (!isset($formAttrs['method'])) {
                                $formAttrs['method'] = 'POST';
                            }
                            $buttonClass = trim((string) ($item['button_class'] ?? 'dropdown-item'));
                            $buttonAttrs = is_array($item['button_attrs'] ?? null) ? $item['button_attrs'] : [];
                            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
                            ?>
                            <form class="m-0"<?php echo gpComponentAttrs($formAttrs); ?>>
                                <?php foreach ($fields as $fieldName => $fieldValue): ?>
                                    <input type="hidden" name="<?php echo gpComponentEscape((string) $fieldName); ?>" value="<?php echo gpComponentEscape((string) $fieldValue); ?>">
                                <?php endforeach; ?>
                                <button class="<?php echo gpComponentEscape($buttonClass); ?>"<?php echo gpComponentAttrs($buttonAttrs); ?>>
                                    <?php echo $iconHtml; ?><span><?php echo gpComponentEscape($label); ?></span>
                                </button>
                            </form>
                        <?php else: ?>
                            <?php
                            $class = trim((string) ($item['class'] ?? 'dropdown-item'));
                            $attrs = is_array($item['attrs'] ?? null) ? $item['attrs'] : [];
                            if (!isset($attrs['type'])) {
                                $attrs['type'] = 'button';
                            }
                            ?>
                            <button class="<?php echo gpComponentEscape($class); ?>"<?php echo gpComponentAttrs($attrs); ?>>
                                <?php echo $iconHtml; ?><span><?php echo gpComponentEscape($label); ?></span>
                            </button>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }
}

if (!function_exists('gpRenderCrudTable')) {
    /**
     * @param array{
     *   meta_left?: string|callable|null,
     *   meta_right?: string|callable|null,
     *   meta_class?: string,
     *   shell_class?: string,
     *   table_class?: string,
     *   headers?: array<int, string|array{label?:string,class?:string,attrs?:array<string,mixed>}>,
     *   rows?: array<int, mixed>,
     *   row_render?: callable|null,
     *   row_context?: array<string, mixed>,
     *   empty_message?: string,
     *   empty_colspan?: int,
     *   pagination?: array{
     *     enabled?: bool,
     *     total_records?: int,
     *     current_page?: int,
     *     total_pages?: int,
     *     items?: array<int, array{page:int|null,label:string,active?:bool}>,
     *     build_url?: callable,
     *     aria_label?: string,
     *     summary_template?: string,
     *     show_summary?: bool
     *   }
     * } $options
     */
    function gpRenderCrudTable(array $options = []): void
    {
        gpRenderCrudTableAssets();

        $metaLeft = $options['meta_left'] ?? null;
        $metaRight = $options['meta_right'] ?? null;
        $metaClass = trim((string) ($options['meta_class'] ?? 'gp-crud-meta'));
        $shellClass = trim((string) ($options['shell_class'] ?? 'gp-crud-shell'));
        $tableClass = trim((string) ($options['table_class'] ?? 'table table-hover align-middle mb-0'));
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $rows = is_array($options['rows'] ?? null) ? $options['rows'] : [];
        $rowRender = $options['row_render'] ?? null;
        $rowContext = is_array($options['row_context'] ?? null) ? $options['row_context'] : [];
        $emptyMessage = trim((string) ($options['empty_message'] ?? 'No hay datos para mostrar.'));
        $emptyColspan = max(1, (int) ($options['empty_colspan'] ?? max(1, count($headers))));
        $pagination = is_array($options['pagination'] ?? null) ? $options['pagination'] : [];

        $metaLeftHtml = gpCrudTableRenderSlot(is_callable($metaLeft) ? $metaLeft : (is_string($metaLeft) ? $metaLeft : null), [$rowContext]);
        $metaRightHtml = gpCrudTableRenderSlot(is_callable($metaRight) ? $metaRight : (is_string($metaRight) ? $metaRight : null), [$rowContext]);
        if ($metaLeftHtml !== '' || $metaRightHtml !== ''):
            ?>
            <div class="<?php echo gpComponentEscape($metaClass); ?>">
                <div><?php echo $metaLeftHtml; ?></div>
                <div><?php echo $metaRightHtml; ?></div>
            </div>
            <?php
        endif;
        ?>
        <div class="<?php echo gpComponentEscape($shellClass); ?>">
            <div class="table-responsive">
                <table class="<?php echo gpComponentEscape($tableClass); ?>">
                    <thead>
                        <tr>
                            <?php foreach ($headers as $header): ?>
                                <?php
                                if (is_array($header)) {
                                    $label = (string) ($header['label'] ?? '');
                                    $class = trim((string) ($header['class'] ?? ''));
                                    $attrs = is_array($header['attrs'] ?? null) ? $header['attrs'] : [];
                                } else {
                                    $label = (string) $header;
                                    $class = '';
                                    $attrs = [];
                                }
                                if (!isset($attrs['scope'])) {
                                    $attrs['scope'] = 'col';
                                }
                                if ($class !== '') {
                                    $attrs['class'] = trim(((string) ($attrs['class'] ?? '')) . ' ' . $class);
                                }
                                ?>
                                <th<?php echo gpComponentAttrs($attrs); ?>><?php echo gpComponentEscape($label); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="<?php echo gpComponentEscape((string) $emptyColspan); ?>"><?php echo gpComponentEscape($emptyMessage); ?></td>
                            </tr>
                        <?php elseif (is_callable($rowRender)): ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <?php echo gpCrudTableCapture($rowRender, [$row, $index, $rowContext]); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php

        $enabled = (bool) ($pagination['enabled'] ?? false);
        if (!$enabled) {
            return;
        }

        $totalRecords = max(0, (int) ($pagination['total_records'] ?? count($rows)));
        $currentPage = max(1, (int) ($pagination['current_page'] ?? 1));
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
        $items = is_array($pagination['items'] ?? null) ? $pagination['items'] : [];
        $buildUrl = $pagination['build_url'] ?? null;
        $ariaLabel = trim((string) ($pagination['aria_label'] ?? 'Paginación'));
        $showSummary = (bool) ($pagination['show_summary'] ?? true);
        $summaryTemplate = trim((string) ($pagination['summary_template'] ?? 'Total: <strong>%d</strong> | Página <strong>%d</strong> de <strong>%d</strong>'));
        ?>
        <div class="gp-crud-footer">
            <div class="small text-muted">
                <?php if ($showSummary): ?>
                    <?php echo sprintf($summaryTemplate, $totalRecords, $currentPage, $totalPages); ?>
                <?php endif; ?>
            </div>
            <?php if ($totalPages > 1 && is_callable($buildUrl)): ?>
                <nav aria-label="<?php echo gpComponentEscape($ariaLabel); ?>">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo gpComponentEscape((string) gpCrudTableInvoke($buildUrl, [max(1, $currentPage - 1)])); ?>" aria-label="Anterior">&laquo;</a>
                        </li>
                        <?php foreach ($items as $item): ?>
                            <?php if (($item['page'] ?? null) === null): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php else: ?>
                                <?php $page = (int) ($item['page'] ?? 1); ?>
                                <li class="page-item <?php echo !empty($item['active']) ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo gpComponentEscape((string) gpCrudTableInvoke($buildUrl, [$page])); ?>">
                                        <?php echo gpComponentEscape((string) ($item['label'] ?? '')); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo gpComponentEscape((string) gpCrudTableInvoke($buildUrl, [min($totalPages, $currentPage + 1)])); ?>" aria-label="Siguiente">&raquo;</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
        <?php
    }
}
