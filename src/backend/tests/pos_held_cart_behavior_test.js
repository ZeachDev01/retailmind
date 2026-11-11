'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');
const requests = [];
const elements = {'held-count': {}, 'confirm-discard-sale': {focus() {}}};
const context = {
    cart: {}, heldSales: [], resumedHeldSaleId: 0, discardingHeldSaleId: 0,
    resumedHeldSaleInput: {value: ''}, holdList: {}, discardModal: {},
    holdSaleButton: {focus() {}}, skuInput: {focus() {}}, cartChangesAllowed: () => true,
    document: {getElementById: id => elements[id]},
    RetailMindUI: {openOverlay() {}, closeOverlay() {}}, setTimeout: fn => fn(),
    resetPaymentState() { context.resumedHeldSaleId = 0; },
    renderCart() {}, persistCart() {}, showCartMessage(message, type) {
        assert.notEqual(type, 'error', message);
    },
    escapeHtml: value => value, money: value => value,
    cartWorkspace: {busy: false, acceptReview: async review => review.cart},
};
vm.createContext(context);
vm.runInContext(source.slice(source.indexOf('async function resumeHeldSale('), source.indexOf('function voidCurrentSale(')), context);
context.apiHeldSale = async (action, payload) => {
    requests.push({action, ...payload});
    return action === 'resume' ? {id: 42, cart: {1: {qty: 2}}, review: {cart: {1: {qty: 2}}},
        held_sales: [{id: 42, status: 'resumed'}, {id: 43, status: 'held'}]} : {held_sales: [{id: 43, status: 'held'}]};
};
(async () => {
    await context.resumeHeldSale(42);
    assert.equal(context.cart[1].qty, 2, 'resume moves the items to the current sale');
    assert.equal(context.resumedHeldSaleInput.value, '42', 'checkout keeps the held-sale attribution');
    assert.equal(elements['held-count'].textContent, 1, 'active resumed cart is excluded from held count');
    assert(!context.holdList.innerHTML.includes('data-resume-held-sale="42"'), 'active cart is removed from held list');
    context.renderHeldSales();
    assert.equal(elements['held-count'].textContent, 1, 'refresh keeps the active cart out of held list');
    context.openDiscardModal(42);
    await context.confirmDiscardHeldSale();
    assert.equal(requests.at(-1).action, 'discard');
    assert.equal(requests.at(-1).discard_reason, undefined, 'discard needs no reason');
    assert.equal(context.resumedHeldSaleId, 0);
    assert.equal(Object.keys(context.cart).length, 0);
    context.cart = {1: {qty: 1}};
    context.openDiscardModal(0);
    await context.confirmDiscardHeldSale();
    assert.equal(requests.at(-1).action, 'discard_cart');
    assert.equal(Object.keys(context.cart).length, 0, 'confirmed fresh cart discard clears the current sale');
    console.log('POS held cart behavior: passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
