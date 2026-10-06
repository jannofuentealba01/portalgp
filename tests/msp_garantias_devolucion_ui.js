'use strict';
// Prueba del JavaScript real del formulario, sin navegador, red ni solicitudes financieras.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../msp/garantias/devoluciones.php'), 'utf8');
const script = [...source.replace(/<\?=[\s\S]*?\?>/g, '').matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)]
    .map(match => match[1]).find(code => code.includes('const garantia='))
    .replace(/<\?php[\s\S]*?\?>/g, 'false');
function runFixture(retry = false) {
    function node(value = '', dataset = {}) {
        return {value, dataset, listeners: {}, disabled: false, required: false, readOnly: false,
            textContent: '', classList: {toggle() {}, add() {}, remove() {}},
            addEventListener(event, callback) { this.listeners[event] = callback; }};
    }
    function select(options, value) {
        const element = node(value);
        element.options = options.map(([v, data]) => ({value: v, dataset: data || {}, hidden: false, disabled: false}));
        Object.defineProperty(element, 'selectedOptions', {get() { return this.options.filter(o => o.value === this.value); }});
        return element;
    }
    const ids = {};
    ids.formDevolucion = node('', {reintento: retry ? '1' : '0'});
    ids.gd_garantia = select([['', {}], ['1', {max: retry ? '0.00' : '21.75', beneficiario: 'Prueba', rut: 'PRUEBA', pactado: '30', recibido: '30', aplicado: '0', devuelto: '0'}]], '1');
    ids.gd_monto = node(retry ? '21.75' : '1.00');
    ids.gd_medio = select([['TRANSFERENCIA', {}], ['EFECTIVO', {}]], 'TRANSFERENCIA');
    ids.gd_cuenta = select([['', {}], ['1', {tipo: 'CAJA', moneda: 'CLP'}], ['7', {tipo: 'BANCO', moneda: 'CLP'}]], '7');
    ids.gd_caja_admin = select([['', {}], ['1', {moneda: 'CLP'}]], '');
    ids.gd_forma = select([['PARCIAL', {}], ['TOTAL', {}]], retry ? 'TOTAL' : 'PARCIAL');
    for (const id of ['gd_beneficiario', 'gd_rut', 'gd_resumen', 'gd_posterior', 'gd_disponible', 'gd_pactado', 'gd_recibido', 'gd_aplicado', 'gd_devuelto', 'gd_emitir']) ids[id] = node();
    if (retry) { ids.gd_beneficiario.value = 'Beneficiario conservado'; ids.gd_rut.value = 'RUT conservado'; }
    const fields = [ids.gd_caja_admin, node(), node(), node()];
    const sections = fields.map(field => ({classList: {toggle() {}}, querySelectorAll() { return [field]; }}));
    const context = {document: {getElementById(id) { return ids[id]; }, querySelectorAll() { return sections; }},
        window: {listeners: {}, addEventListener(event, callback) { this.listeners[event] = callback; }}, confirm: () => true};
    vm.createContext(context);
    vm.runInContext(script, context);
    return {ids, fields, context};
}
const {ids, fields, context} = runFixture();
assert.equal(ids.gd_caja_admin.value, '1');
assert.ok(fields.every(field => field.required && !field.disabled));
assert.ok(ids.gd_cuenta.options.find(option => option.value === '1').disabled);
ids.gd_forma.value = 'TOTAL'; ids.gd_forma.listeners.change();
assert.equal(ids.gd_monto.value, '21.75'); assert.equal(ids.gd_monto.readOnly, true);
ids.gd_forma.value = 'PARCIAL'; ids.gd_forma.listeners.change();
assert.equal(ids.gd_monto.readOnly, false);
ids.gd_medio.value = 'EFECTIVO'; ids.gd_medio.listeners.change();
assert.ok(fields.every(field => !field.required && field.disabled));
assert.equal(ids.gd_cuenta.value, '');
assert.ok(ids.gd_cuenta.options.find(option => option.value === '7').disabled);
let prevented = 0;
context.confirm = () => false;
ids.formDevolucion.listeners.submit({preventDefault() { prevented++; }});
assert.equal(prevented, 1); assert.equal(ids.gd_emitir.disabled, false);
context.confirm = () => true;
ids.formDevolucion.listeners.submit({preventDefault() { prevented++; }});
ids.formDevolucion.listeners.submit({preventDefault() { prevented++; }});
assert.equal(prevented, 2); assert.equal(ids.gd_emitir.disabled, true);
context.window.listeners.pageshow({persisted: true});
assert.equal(ids.gd_emitir.disabled, false);
const repeated = runFixture(true).ids;
assert.equal(repeated.gd_monto.value, '21.75'); assert.equal(repeated.gd_monto.max, '21.75');
assert.equal(repeated.gd_beneficiario.value, 'Beneficiario conservado');
console.log('OK: controles de origen, requisitos dinamicos, parcial/total, confirmacion, doble clic, regreso y reintento sin alterar datos.');
