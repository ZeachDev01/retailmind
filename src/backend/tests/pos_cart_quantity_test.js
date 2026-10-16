'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const alerts = [];

const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
const context = {
    cart: { 1: { name: 'Milk', qty: 1, stock: 3 } },
    cartChangesAllowed: () => true,
    renderCart: () => {},
    showCartMessage: (...args) => alerts.push(args),
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('function updateCartQty('), source.indexOf('async function clearCart(')), context);

context.changeCartQty(1, -1);
assert.equal(context.cart[1].qty, 1, 'minus at one keeps the item');
assert.equal(alerts[0][1], 'warning', 'invalid quantity shows a warning toast');
for (const quantity of [0, -1, 1.5, NaN]) {
    context.updateCartQty(1, quantity);
    assert.equal(context.cart[1].qty, 1, 'invalid quantity keeps the item');
}
context.changeCartQty(1, 1);
assert.equal(context.cart[1].qty, 2, 'plus still increases quantity');
context.updateCartQty(1, 4);
assert.equal(context.cart[1].qty, 2, 'stock limit is preserved');
context.removeFromCart(1);
assert.equal(context.cart[1], undefined, 'delete still removes the item');
context.cartWorkspace = { state: { cart: { 1: { qty: 2 } }, heldSaleId: 7, unresolved: true } };
context.resumedHeldSaleInput = { value: '' };
context.loadHeldSales = () => {};
let navigationType = 'navigate';
context.performance = { getEntriesByType: () => [{ type: navigationType }] };
vm.runInContext(source.slice(source.indexOf('function restoreState('), source.indexOf('async function reviewCurrentCart(')), context);
const alertsBeforeRestore = alerts.length;
context.restoreState();
assert.equal(alerts.length, alertsBeforeRestore, 'normal navigation does not show recovery alert');
navigationType = 'back_forward';
context.restoreState();
assert.equal(alerts.length, alertsBeforeRestore, 'back navigation does not show recovery alert');
navigationType = 'reload';
context.restoreState();
assert.equal(context.cart[1].qty, 2, 'recovery preserves the saved cart');
assert.equal(context.resumedHeldSaleInput.value, '7');
assert.match(alerts.at(-1)[0], /^Sale restored/, 'recovery shows a toast');
const alertCount = alerts.length;
context.cartWorkspace.state = { cart: {} };
context.restoreState();
assert.equal(alerts.length, alertCount, 'empty cart does not show recovery alert');
console.log('POS cart quantity: passed');
