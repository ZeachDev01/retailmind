'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
const nodes = new Map();
const node = id => {
    if (!nodes.has(id)) nodes.set(id, { value: '', textContent: '', classList: { toggle() {} } });
    return nodes.get(id);
};
let total = 15, items = 1;
const context = {
    document: { getElementById: node },
    paymentMethod: { value: 'cash' },
    cashReceived: { value: '100' },
    changeDue: node('change'),
    referenceField: node('reference'), cashQuick: node('quick'), paymentReference: node('payment-reference'),
    discountSummary: null,
    getNetTotal: () => total, getCartTotal: () => 20, getDiscountAmount: () => 20 - total,
    getCartItemCount: () => items,
    money: value => Number(value).toFixed(2), setTextIfPresent() {},
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function updatePaymentFields('), source.indexOf('function setQuickTender(')), context);
context.updatePaymentFields();
assert.equal(context.changeDue.value, '85.00');
assert.equal(node('drawer-cash-added').textContent, '15.00', 'change is excluded from cash added');
context.cashReceived.value = '10';
context.updatePaymentFields();
assert.equal(node('drawer-cash-added').textContent, '10.00', 'partial cash entry updates preview');
for (const value of ['', '-5', 'invalid']) {
    context.cashReceived.value = value;
    context.updatePaymentFields();
    assert.equal(node('drawer-cash-added').textContent, '0.00', 'invalid or empty cash adds nothing');
}
context.cashReceived.value = '100';
context.paymentMethod.value = 'card';
context.updatePaymentFields();
assert.equal(node('drawer-cash-added').textContent, '0.00', 'noncash payments do not add drawer cash');
context.paymentMethod.value = 'cash';
items = 0;
context.updatePaymentFields();
assert.equal(node('drawer-cash-added').textContent, '0.00', 'empty cart adds nothing');
items = 1;
total = 12.50;
context.updatePaymentFields();
assert.equal(node('drawer-cash-added').textContent, '12.50', 'discounted total determines cash retained');
assert.ok(!source.includes('drawerCash'), 'expected drawer is not exposed before counting');
console.log('POS drawer preview: passed');
