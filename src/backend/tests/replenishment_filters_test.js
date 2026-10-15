const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const control = () => ({ value: '', addEventListener(type, callback) { this[type] = callback; } });
const controls = Object.fromEntries(['queue-search', 'queue-stock', 'queue-sort', 'queue-results', 'queue-no-results'].map(id => [id, control()]));
const row = (name, sku, stock, onHand, quantity) => ({ dataset: { name, sku, stock, onHand, quantity } });
const rows = [
    row('Rice', 'RICE-01', 'Low stock', '2', '12'),
    row('Milk', 'MILK-02', 'Out of stock', '0', '8'),
    row('Salt', 'SALT-03', 'In stock', '24', '0'),
];
let ordered = [];
const table = { querySelectorAll: () => rows, tBodies: [{ append(item) { ordered = ordered.filter(existing => existing !== item); ordered.push(item); } }] };
const document = { querySelector: () => table, querySelectorAll: () => [], getElementById: id => controls[id] };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/replenishment.js'), 'utf8'), { document });
assert.deepEqual(ordered.map(item => item.dataset.stock), ['Out of stock', 'Low stock', 'In stock']);
controls['queue-search'].value = '  rice-01 ';
controls['queue-search'].input();
assert.deepEqual(rows.filter(item => !item.hidden).map(item => item.dataset.name), ['Rice']);
controls['queue-stock'].value = 'In stock';
controls['queue-stock'].change();
assert.equal(controls['queue-no-results'].hidden, false);
assert.equal(controls['queue-results'].textContent, 'Showing 0 of 3 products');
controls['queue-search'].value = '';
controls['queue-stock'].value = 'Out of stock';
controls['queue-stock'].change();
assert.deepEqual(rows.filter(item => !item.hidden).map(item => item.dataset.name), ['Milk']);
controls['queue-stock'].value = '';
for (const [sort, expected] of [
    ['quantity', ['Rice', 'Milk', 'Salt']],
    ['stock', ['Milk', 'Rice', 'Salt']],
    ['name', ['Milk', 'Rice', 'Salt']],
    ['priority', ['Milk', 'Rice', 'Salt']],
]) {
    controls['queue-sort'].value = sort;
    controls['queue-sort'].change();
    assert.deepEqual(ordered.map(item => item.dataset.name), expected);
}
assert.equal(controls['queue-no-results'].hidden, true);
console.log('Replenishment filter and sorting checks passed.');
