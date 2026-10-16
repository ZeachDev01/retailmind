const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../frontend/components/report/stock_receiving.php'), 'utf8');
const save = source.slice(source.indexOf('function saveReceivingDraft()'), source.indexOf("form.addEventListener('input', saveReceivingDraft)"));
const storage = new Map();
const fields = {product_id: '1', quantity_mode: 'packages', received_qty: '', received_packages: '2', damaged_qty: '1', notes: 'Keep this draft'};
const context = vm.createContext({
    canReceive: true,
    receivingDraftKey: 'receiving-draft:1',
    form: {elements: {supplier: {value: 'Store'}, invoice_number: {value: 'REF-1'}}},
    lineContainer: {children: [{querySelectorAll: () => Object.entries(fields).map(([name, value]) => ({dataset: {field: name}, value}))}]},
    sessionStorage: {setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key)},
    document: {getElementById: () => status},
});
const status = {textContent: ''};
vm.runInContext(save + '\nsaveReceivingDraft();', context);
assert.deepEqual(JSON.parse(storage.get('receiving-draft:1')), {supplier: 'Store', invoice_number: 'REF-1', lines: [fields]});
context.canReceive = false;
fields.notes = 'Read-only changes';
vm.runInContext('saveReceivingDraft()', context);
assert.equal(JSON.parse(storage.get('receiving-draft:1')).lines[0].notes, 'Keep this draft');
context.canReceive = true;
context.lineContainer.children = [];
context.form.elements.supplier.value = '';
context.form.elements.invoice_number.value = '';
vm.runInContext('saveReceivingDraft()', context);
assert.equal(storage.size, 0, 'removing all details clears the draft');
context.sessionStorage.removeItem = () => { throw new Error('Storage blocked'); };
vm.runInContext('saveReceivingDraft()', context);
assert.match(status.textContent, /Draft could not be kept/);
console.log('Receiving draft persistence checks passed');
