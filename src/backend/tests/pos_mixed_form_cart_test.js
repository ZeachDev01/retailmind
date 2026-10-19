'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync('src/frontend/components/cashier/pos.php', 'utf8');

// Mock browser environment
const alerts = [];
const elementMocks = {};
function getMockElement(id) {
    if (!elementMocks[id]) {
        elementMocks[id] = {
            id,
            textContent: '',
            value: '',
            innerHTML: '',
            disabled: false,
            classList: {
                toggle: () => {},
                add: () => {},
                remove: () => {}
            },
            querySelectorAll: () => [],
            querySelector: () => null,
            focus: () => {}
        };
    }
    return elementMocks[id];
}

const context = {
    cart: {},
    resumedHeldSaleId: 0,
    resumedHeldSaleInput: { value: '' },
    posShiftOpen: true,
    lowStockFallback: 5,
    cartWorkspace: {
        context: { locked: false },
        busy: false,
        save: () => {}
    },
    checkoutAttempt: { ready: true, pending: false },
    checkoutButton: getMockElement('checkout-btn'),
    holdSaleButton: getMockElement('hold-sale-btn'),
    voidSaleButton: getMockElement('void-sale-btn'),
    clearCartButton: getMockElement('clear-cart-btn'),
    cartInput: getMockElement('cart-input'),
    skuInput: getMockElement('sku-input'),
    paymentMethod: { value: 'cash', options: [{ text: 'Cash' }], selectedIndex: 0 },
    cashReceived: { value: '150' },
    paymentReference: { value: '' },
    discountType: { value: 'none' },
    discountValue: { value: '0' },
    discountReason: { value: '' },
    changeDue: { value: '' },
    cashQuick: getMockElement('cash-quick'),
    referenceField: getMockElement('reference-field'),
    discountSummary: null,
    supervisorFields: getMockElement('supervisor-fields'),
    checkoutQuote: { isCurrent: () => false },
    RetailMindUI: {
        toast: (...args) => alerts.push(args)
    },
    document: {
        getElementById: id => getMockElement(id),
        querySelectorAll: () => []
    },
    escapeHtml: s => String(s ?? ''),
    money: n => Number(n).toFixed(2),
    cartChangesAllowed: () => true,
    showCartMessage: (...args) => alerts.push(args)
};

vm.createContext(context);

// Extract helper functions
const getHelpers = `
function getCartTotal() {
    return Object.values(cart).reduce((total, item) => total + (Number(item.price) * Number(item.qty)), 0);
}
function getDiscountAmount() { return 0; }
function getNetTotal() { return getCartTotal(); }
function getCartItemCount() {
    return Object.values(cart).reduce((total, item) => total + Number(item.qty || 0), 0);
}
function getLowStockThreshold(item) {
    return Math.max(Number(item.reorder_level || 0), Number(item.safety_stock || 0), lowStockFallback);
}
function setTextIfPresent() {}
function persistCart() {}
function updateQuickProductAvailability() {}
`;

vm.runInContext(getHelpers, context);

// Extract addToCart through validateCheckout
const startSlice = source.indexOf('function addToCart(');
const endSlice = source.indexOf('async function checkoutNow(');
assert.ok(startSlice !== -1 && endSlice !== -1, 'functions found in source');
vm.runInContext(source.slice(startSlice, endSlice), context);

console.log('Testing Mixed-Form Cart JS operations...');

// 1. Add piece line (default selling form)
context.addToCart(1, 'Product A', 10.0, 50, 5, 2, 'SKU-A', 'BAR-A');
assert.ok(context.cart['1:piece'], 'Item added with 1:piece key');
assert.equal(context.cart['1:piece'].selling_form, 'piece');
assert.equal(context.cart['1:piece'].qty, 1);
assert.equal(context.cart['1:piece'].price, 10.0);

// 2. Add pack line for same product
context.addToCart(1, 'Product A', 100.0, 10, 2, 1, 'SKU-A', 'BAR-A-PACK', 'pack', 12);
assert.ok(context.cart['1:pack'], 'Item added with 1:pack key');
assert.equal(context.cart['1:pack'].selling_form, 'pack');
assert.equal(context.cart['1:pack'].pack_size, 12);
assert.equal(context.cart['1:pack'].qty, 1);
assert.equal(context.cart['1:pack'].price, 100.0);

// Verify distinct lines exist simultaneously
assert.equal(Object.keys(context.cart).length, 2, 'Cart has 2 distinct lines for same product');
assert.equal(context.getCartTotal(), 110.0, 'Total is 10 + 100 = 110');
assert.equal(context.getCartItemCount(), 2, 'Item count is 2 lines of qty 1');

// 3. Update quantity with composite string key
context.updateCartQty('1:piece', 3);
assert.equal(context.cart['1:piece'].qty, 3);
assert.equal(context.getCartTotal(), 130.0, 'Total updated to 3*10 + 100 = 130');

// 4. Change quantity via changeCartQty
context.changeCartQty('1:pack', 1);
assert.equal(context.cart['1:pack'].qty, 2);
assert.equal(context.getCartTotal(), 230.0, 'Total updated to 30 + 200 = 230');

// 5. Backwards compatibility with numeric ID
context.changeCartQty(1, -1); // should find 1:piece and decrease to 2
assert.equal(context.cart['1:piece'].qty, 2);
assert.equal(context.getCartTotal(), 220.0);

// 6. Checkout payload serialization
const valid = context.validateCheckout(false);
assert.ok(valid, 'validateCheckout succeeded');
const payload = JSON.parse(context.cartInput.value);
assert.equal(payload.length, 2, 'Payload has 2 distinct line items');
const pieceLine = payload.find(l => l.selling_form === 'piece');
const packLine = payload.find(l => l.selling_form === 'pack');
assert.ok(pieceLine, 'Piece line serialized');
assert.equal(pieceLine.product_id, 1);
assert.equal(pieceLine.qty, 2);
assert.ok(packLine, 'Pack line serialized');
assert.equal(packLine.product_id, 1);
assert.equal(packLine.qty, 2);

// 7. Render cart HTML contains distinct badges
const cartBodyHtml = getMockElement('cart-body').innerHTML;
assert.ok(cartBodyHtml.includes('Pack of 12'), 'Cart renders Pack of 12 badge');
assert.ok(cartBodyHtml.includes('Piece'), 'Cart renders Piece badge');
assert.ok(cartBodyHtml.includes("changeCartQty('1:pack'"), 'Actions button uses composite key 1:pack');
assert.ok(cartBodyHtml.includes("changeCartQty('1:piece'"), 'Actions button uses composite key 1:piece');

// 8. Remove pack line
context.removeFromCart('1:pack');
assert.equal(context.cart['1:pack'], undefined, 'Pack line removed');
assert.ok(context.cart['1:piece'], 'Piece line preserved');
assert.equal(context.getCartTotal(), 20.0, 'Total is 2 * 10 = 20');

console.log('POS Mixed-Form Cart JS tests PASSED!');
