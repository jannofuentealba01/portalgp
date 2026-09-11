(() => {
    'use strict';

    const normalize = (value) => String(value || '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

    const columnKind = (label) => {
        const value = normalize(label);
        if (/^(#|id|cuota|contrato|doc)$/.test(value)) return 'short';
        if (/(accion|acciones|operacion|gestion|decision|editar|revertir|aplicar|limpiar)/.test(value)) return 'actions';
        if (/(monto|saldo|total|pagado|pactado|recibido|aplicado|devuelto|deuda|garantia|debe|haber|neto|iva|subtotal|diferencia|cobrado|pendiente)/.test(value)) return 'number';
        if (/(fecha|emision|vencimiento|venc\.|inicio|termino|periodo)/.test(value)) return 'date';
        if (/(estado|situacion|cuadre|alerta|activo)/.test(value)) return 'state';
        if (/(arrendatario|tienda|local|descripcion|concepto|motivo|observacion|referencia|documento|archivo|cuenta)/.test(value)) return 'description';
        return 'default';
    };

    const directRows = (section) => section ? Array.from(section.rows || []) : [];

    const headerCells = (table) => {
        if (!table.tHead || table.tHead.rows.length === 0) {
            const firstRow = table.rows[0];
            const cells = firstRow ? Array.from(firstRow.cells) : [];
            if (cells.length > 0 && cells.every((cell) => cell.tagName === 'TH')) {
                const head = table.createTHead();
                head.appendChild(firstRow);
            }
        }
        if (!table.tHead || table.tHead.rows.length === 0) return [];
        const rows = Array.from(table.tHead.rows);
        return Array.from(rows[rows.length - 1].cells);
    };

    const headerDefinitions = (table) => {
        if (!table.tHead || table.tHead.rows.length === 0) return [];

        const rows = Array.from(table.tHead.rows);
        const grid = [];

        rows.forEach((row, rowIndex) => {
            grid[rowIndex] ||= [];
            let columnIndex = 0;

            Array.from(row.cells).forEach((cell) => {
                while (grid[rowIndex][columnIndex]) columnIndex += 1;

                const colspan = Math.max(1, Number(cell.getAttribute('colspan') || 1));
                const rowspan = Math.max(1, Number(cell.getAttribute('rowspan') || 1));
                const label = String(cell.textContent || '').replace(/\s+/g, ' ').trim();
                const kind = columnKind(label);

                cell.scope ||= colspan > 1 ? 'colgroup' : 'col';
                cell.dataset.gpColumnKind = kind;

                for (let rowOffset = 0; rowOffset < rowspan; rowOffset += 1) {
                    const targetRow = rowIndex + rowOffset;
                    grid[targetRow] ||= [];
                    for (let columnOffset = 0; columnOffset < colspan; columnOffset += 1) {
                        grid[targetRow][columnIndex + columnOffset] = {cell, label, kind};
                    }
                }
                columnIndex += colspan;
            });
        });

        const columnCount = Math.max(0, ...grid.map((row) => row.length));
        return Array.from({length: columnCount}, (_, columnIndex) => {
            const labels = [];
            let kind = 'default';

            grid.forEach((row) => {
                const definition = row[columnIndex];
                if (!definition) return;
                if (definition.label !== '' && !labels.includes(definition.label)) labels.push(definition.label);
                if (definition.kind !== 'default') kind = definition.kind;
            });

            return {
                label: labels.join(' · ') || `Campo ${columnIndex + 1}`,
                kind,
            };
        });
    };

    const classifyTable = (table, definitions) => {
        if (table.classList.contains('gp-table-matrix')) {
            table.dataset.gpTableType = 'matrix';
            return;
        }

        const columns = definitions.length || Math.max(0, ...directRows(table.tBodies[0]).map((row) => row.cells.length));
        table.classList.add(columns <= 7 ? 'gp-table-compact' : 'gp-table-dense');
        table.classList.add('gp-table-mobile-cards');
        table.dataset.gpTableType = columns <= 7 ? 'compact' : 'dense';
        table.dataset.gpColumnCount = String(columns);
    };

    const prepareWrapper = (table) => {
        const wrapper = table.closest('.table-responsive, .msp-management-table-responsive, .cierre-table-wrap, .dex-table-wrap, .msp-guarantees-table-wrap, .trazabilidad-table-wrap, .ag-table-wrap, .ag-subtable-wrap, .gp-table-matrix-wrap, .control-grid-wrap, .cxp-matrix-wrap');
        if (wrapper) wrapper.classList.add('gp-table-shell');
        return wrapper;
    };

    const prepareHeaders = (table) => {
        headerCells(table);
        return headerDefinitions(table);
    };

    const markEmptyRow = (row, columnCount) => {
        if (row.matches('.collapse, .ag-child, [data-gp-detail-row]')) return false;
        if (row.cells.length !== 1) return false;
        const cell = row.cells[0];
        if (cell.querySelector('table, form, details, .card, .accordion, .collapse')) return false;
        const colspan = Number(cell.getAttribute('colspan') || 1);
        if (colspan < Math.max(2, columnCount)) return false;
        row.classList.add('gp-table-empty-row');
        cell.classList.add('gp-table-empty-cell');
        return true;
    };

    const prepareCell = (cell, definition) => {
        const label = definition?.label || '';
        const kind = definition?.kind || 'default';
        cell.dataset.gpLabel = label;
        cell.dataset.gpColumnKind = kind;

        if (kind === 'number') cell.classList.add('gp-cell-number');
        if (kind === 'state') cell.classList.add('gp-cell-state');
        if (kind === 'actions') cell.classList.add('gp-cell-actions');
        if (kind === 'description') {
            cell.classList.add('gp-cell-description');
            const fullText = String(cell.textContent || '').replace(/\s+/g, ' ').trim();
            if (fullText.length > 36 && !cell.hasAttribute('title')) cell.title = fullText;
        }
    };

    const compactActionCell = (cell) => {
        if (!cell) return;

        const uploadForm = cell.querySelector('form[enctype="multipart/form-data"]');
        if (uploadForm && !uploadForm.closest('.gp-inline-upload')) {
            const uploadDetails = document.createElement('details');
            uploadDetails.className = 'gp-inline-upload';
            const uploadSummary = document.createElement('summary');
            uploadSummary.className = 'btn btn-outline-secondary btn-sm';
            uploadSummary.textContent = 'Adjuntar respaldo';
            uploadSummary.setAttribute('aria-label', 'Mostrar formulario para adjuntar respaldo');
            uploadForm.before(uploadDetails);
            uploadForm.classList.add('gp-inline-upload__form');
            uploadDetails.append(uploadSummary, uploadForm);
        }

        if (cell.querySelector('form, input, select, textarea, [contenteditable="true"]')) return;

        const actions = Array.from(cell.querySelectorAll('a.btn, button.btn')).filter((action) =>
            action.closest('td, th') === cell
        );
        actions.forEach((action) => action.classList.add('btn-sm'));
        if (actions.length <= 2 || cell.querySelector('.gp-row-actions')) return;

        const details = document.createElement('details');
        details.className = 'gp-row-actions';
        const summary = document.createElement('summary');
        summary.className = 'btn btn-outline-secondary btn-sm';
        summary.textContent = 'Acciones';
        summary.setAttribute('aria-label', 'Mostrar acciones del registro');
        const menu = document.createElement('div');
        menu.className = 'gp-row-actions__menu';
        actions.forEach((action) => menu.appendChild(action));
        details.append(summary, menu);

        Array.from(cell.children).forEach((child) => {
            if (child !== details && child.children.length === 0 && String(child.textContent || '').trim() === '') child.remove();
        });
        cell.appendChild(details);
    };

    const tabletPriority = (definition, index) => {
        if (index === 0) return 1000;
        switch (definition?.kind) {
            case 'actions': return 950;
            case 'description': return 850;
            case 'state': return 760;
            case 'number': return 700;
            case 'short': return 620;
            case 'date': return 300;
            default: return 200;
        }
    };

    const prepareTabletDetails = (table, definitions) => {
        const columnCount = definitions.length;
        if (columnCount <= 7 || !table.tHead || table.tHead.rows.length !== 1) return;

        const rows = Array.from(table.tBodies).flatMap((body) => directRows(body));
        const dataRows = rows.filter((row) => !row.classList.contains('gp-table-empty-row'));
        const isSimple = dataRows.every((row) =>
            row.cells.length === columnCount
            && Array.from(row.cells).every((cell) =>
                Number(cell.getAttribute('colspan') || 1) === 1
                && Number(cell.getAttribute('rowspan') || 1) === 1
                && !cell.querySelector('table')
            )
        );
        if (!isSimple) return;

        const visibleLimit = 6;
        const candidates = definitions
            .map((definition, index) => ({definition, index, priority: tabletPriority(definition, index)}))
            .filter(({definition, index}) => index !== 0 && definition.kind !== 'actions')
            .sort((left, right) => left.priority - right.priority || right.index - left.index);
        const secondaryIndexes = candidates
            .slice(0, Math.max(0, columnCount - visibleLimit))
            .map(({index}) => index)
            .sort((left, right) => left - right);

        if (secondaryIndexes.length === 0) return;

        table.classList.add('gp-table-has-tablet-details');
        table.dataset.gpTabletSecondary = secondaryIndexes.join(',');
        const headerRow = table.tHead.rows[0];
        secondaryIndexes.forEach((index) => headerRow.cells[index]?.classList.add('gp-tablet-secondary'));

        dataRows.forEach((row) => {
            if (row.querySelector('.gp-tablet-details')) return;
            const entries = secondaryIndexes.map((index) => ({
                label: definitions[index]?.label || `Campo ${index + 1}`,
                value: String(row.cells[index]?.textContent || '').replace(/\s+/g, ' ').trim() || '—',
            }));
            secondaryIndexes.forEach((index) => row.cells[index]?.classList.add('gp-tablet-secondary'));

            const visibleCells = Array.from(row.cells).filter((cell, index) => !secondaryIndexes.includes(index));
            const host = visibleCells.find((cell) => cell.dataset.gpColumnKind === 'actions')
                || visibleCells[visibleCells.length - 1]
                || null;
            if (!host) return;

            const details = document.createElement('details');
            details.className = 'gp-tablet-details';
            const summary = document.createElement('summary');
            summary.className = 'btn btn-outline-secondary btn-sm';
            summary.textContent = 'Ver detalle';
            summary.setAttribute('aria-label', 'Ver información secundaria del registro');
            const list = document.createElement('dl');
            list.className = 'gp-tablet-details__list';

            entries.forEach(({label, value}) => {
                const item = document.createElement('div');
                const term = document.createElement('dt');
                const description = document.createElement('dd');
                term.textContent = label;
                description.textContent = value;
                item.append(term, description);
                list.appendChild(item);
            });
            details.append(summary, list);
            host.appendChild(details);
        });
    };

    const prepareMatrix = (table, wrapper) => {
        if (!wrapper) return;
        wrapper.classList.add('gp-table-matrix-wrap');
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', table.getAttribute('aria-label') || 'Matriz de datos desplazable');
        if (wrapper.querySelector(':scope > .gp-matrix-scroll-hint')) return;

        const hint = document.createElement('p');
        hint.className = 'gp-matrix-scroll-hint';
        hint.textContent = 'Desliza horizontalmente para consultar todas las columnas.';
        wrapper.prepend(hint);
    };

    const prepareBody = (table, definitions) => {
        const columnCount = Number(table.dataset.gpColumnCount || definitions.length || 0);
        Array.from(table.tBodies).forEach((body) => {
            directRows(body).forEach((row) => {
                if (markEmptyRow(row, columnCount)) return;
                let columnIndex = 0;
                Array.from(row.cells).forEach((cell) => {
                    if (cell.hidden || cell.classList.contains('d-none')) {
                        cell.dataset.gpTableCellSkipped = 'true';
                        return;
                    }
                    prepareCell(cell, definitions[columnIndex]);
                    columnIndex += Math.max(1, Number(cell.getAttribute('colspan') || 1));
                });
                const actionCell = Array.from(row.cells).find((cell) => cell.dataset.gpColumnKind === 'actions')
                    || Array.from(row.cells).find((cell) => cell.querySelector('.btn'));
                if (actionCell) {
                    actionCell.classList.add('gp-cell-actions');
                    compactActionCell(actionCell);
                }
            });
        });

        if (table.tFoot) {
            directRows(table.tFoot).forEach((row) => {
                let columnIndex = 0;
                Array.from(row.cells).forEach((cell) => {
                    prepareCell(cell, definitions[columnIndex]);
                    if (Number(cell.getAttribute('colspan') || 1) > 1) cell.classList.add('gp-table-total-label');
                    columnIndex += Math.max(1, Number(cell.getAttribute('colspan') || 1));
                });
            });
        }

        prepareTabletDetails(table, definitions);
    };

    const enhanceTable = (table) => {
        if (table.dataset.gpTableReady === 'true' || table.closest('[data-gp-table-skip="true"]')) return;
        table.dataset.gpTableReady = 'true';
        const wrapper = prepareWrapper(table);
        const definitions = prepareHeaders(table);
        classifyTable(table, definitions);
        if (table.classList.contains('gp-table-matrix')) {
            prepareMatrix(table, wrapper);
            return;
        }
        prepareBody(table, definitions);

        const rowCount = Array.from(table.tBodies).reduce((total, body) => total + body.rows.length, 0);
        if (rowCount >= 12) {
            table.classList.add('gp-table-sticky');
            wrapper?.classList.add('gp-table-shell--sticky');
        }
    };

    const refreshTable = (table) => {
        if (!(table instanceof HTMLTableElement)) return;
        if (table.dataset.gpTableReady !== 'true') {
            enhanceTable(table);
            return;
        }
        if (table.classList.contains('gp-table-matrix')) return;
        const definitions = prepareHeaders(table);
        prepareBody(table, definitions);
    };

    const closeActionMenus = (event) => {
        document.querySelectorAll('details.gp-row-actions[open]').forEach((details) => {
            if (!details.contains(event.target)) details.removeAttribute('open');
        });
    };

    const init = () => {
        if (!window.location.pathname.startsWith('/portalgp/msp/')) return;
        document.querySelectorAll('main table').forEach(enhanceTable);
        document.addEventListener('click', closeActionMenus);
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.querySelectorAll('details.gp-row-actions[open], details.gp-inline-upload[open]')
                    .forEach((details) => details.removeAttribute('open'));
            }
        });

        const main = document.querySelector('main');
        if (main && 'MutationObserver' in window) {
            const observer = new MutationObserver((mutations) => {
                const affected = new Set();
                mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
                    if (!(node instanceof Element)) return;
                    if (node.matches('table')) affected.add(node);
                    node.querySelectorAll('table').forEach((table) => affected.add(table));
                    const owner = node.closest('table');
                    if (owner) affected.add(owner);
                }));
                affected.forEach(refreshTable);
            });
            observer.observe(main, {childList: true, subtree: true});
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
