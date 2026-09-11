(function () {
  'use strict';

  function element(tag, text, className) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function clear(node) {
    node.replaceChildren();
  }

  function emptyRow(body, columns, message) {
    const row = element('tr');
    const cell = element('td', message, 'text-muted table-empty');
    cell.colSpan = columns;
    row.appendChild(cell);
    body.appendChild(row);
  }

  function textCell(row, value, label, className) {
    const cell = element('td', value, className || '');
    if (label) cell.dataset.label = label;
    row.appendChild(cell);
    return cell;
  }

  function cecoCell(row, item, label, variant) {
    const code = item.cost_center_code || 'SIN CECO';
    const description = item.cost_center_name && item.cost_center_name !== code
      ? item.cost_center_name
      : code;
    const cell = element('td');
    if (label) cell.dataset.label = label;
    const stack = element('span', null, variant === 'graph' ? 'ceco-cell' : 'ceco-stack');
    stack.appendChild(element('span', code, variant === 'graph' ? 'ceco-code' : 'ceco-stack-code'));
    stack.appendChild(element('span', description, variant === 'graph' ? 'ceco-desc' : 'ceco-stack-desc'));
    cell.appendChild(stack);
    row.appendChild(cell);
    return cell;
  }

  function personCell(row, name, identifier) {
    const cell = element('td');
    cell.dataset.label = 'Persona';
    cell.appendChild(element('strong', name || identifier));
    cell.appendChild(document.createElement('br'));
    cell.appendChild(element('small', identifier, 'text-muted'));
    row.appendChild(cell);
    return cell;
  }

  window.FteUi = Object.freeze({ element, clear, emptyRow, textCell, cecoCell, personCell });
}());
