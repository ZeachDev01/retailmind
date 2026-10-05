const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const {webcrypto} = require('node:crypto');

async function main() {
    const storage = new Map();
    let response = {sale: null};
    let navigation = null;
    let fetches = 0;
    let blocked = false;
    let lockQueue = Promise.resolve();
    const context = vm.createContext({crypto: webcrypto, Uint8Array,
        navigator: {locks: {request: (key, callback) => { const next = lockQueue.then(callback); lockQueue = next.catch(() => {}); return next; }}},
        localStorage: {getItem: key => storage.get(key) || null, setItem: (key, value) => storage.set(key, value)},
        window: {location: {replace: url => { navigation = url; }}},
        FormData: class { constructor(form) { this.form = form; } *[Symbol.iterator]() { for (const [key, field] of Object.entries(this.form.elements)) yield [key, field.value]; } },
        fetch: async () => { fetches++; if (blocked) throw Error('Lost connection'); return {ok: true, json: async () => response}; }
    });
    vm.runInContext(fs.readFileSync('src/frontend/assets/js/checkout-attempt.js', 'utf8') + '\nglobalThis.Attempt = CheckoutAttempt;', context);
    const form = {elements: {checkout_attempt: {value: ''}, cash_received: {value: '50'}, csrf_token: {value: 'secret-token'}, discount_approver_password: {value: 'secret-password'}, cart: {value: '[{"product_id":1,"qty":2}]'}}};
    const cart = {1: {product_id: 1, qty: 2, name: 'Fixture Item'}};
    let restored = null;
    let message = '';
    const options = {recoverUrl: '/pos.php', restore: value => {restored = value;}, message: value => {message = value;}};
    const first = new context.Attempt(form, '1', options);
    const staleTab = new context.Attempt(form, '1', options);
    await first.recover();
    await staleTab.recover();
    await first.save(cart);
    const id = form.elements.checkout_attempt.value;
    assert.match(id, /^[a-f0-9]{32}$/);
    assert(!storage.get(first.key).includes('secret-'));
    await first.save(cart);
    assert.equal(form.elements.checkout_attempt.value, id, 'Double click preserves identity');
    await assert.rejects(staleTab.save(cart), /Another tab changed/);
    assert.equal(JSON.parse(storage.get(first.key)).id, id, 'Already-open tab cannot overwrite a lost response identity');
    const reload = new context.Attempt(form, '1', options);
    blocked = true;
    await reload.recover();
    assert.equal(reload.ready, false, 'Unavailable recovery blocks another payment submission');
    await assert.rejects(reload.save(cart));
    assert(storage.has(first.key), 'Navigation preserves unresolved attempt');
    blocked = false;
    await reload.recover();
    assert.equal(reload.ready, true);
    assert.equal(JSON.stringify(restored), JSON.stringify(cart), 'Reload restores cart for same-attempt retry');
    assert.match(message, /Do not collect payment again/);
    await reload.save(cart);
    assert.equal(form.elements.checkout_attempt.value, id);
    response = {sale: {sale_id: 42}, receipt_url: '/receipt.php?sale_id=42'};
    const lostResponse = new context.Attempt(form, '1', options);
    await lostResponse.recover();
    assert.equal(navigation, response.receipt_url, 'Lost response recovers committed receipt before another payment');
    assert.equal(lostResponse.ready, false);
    assert.equal(JSON.parse(storage.get(first.key)).id, id, 'Receipt navigation loss retains recovery');
    const other = new context.Attempt(form, '2', options);
    await other.recover();
    await other.save(cart);
    assert.notEqual(form.elements.checkout_attempt.value, id, 'New Cashier gets an independent identity');
    storage.clear();
    const tabA = new context.Attempt(form, '3', options);
    const tabB = new context.Attempt(form, '3', options);
    await Promise.all([tabA.recover(), tabB.recover()]);
    const racing = await Promise.allSettled([tabA.save(cart), tabB.save(cart)]);
    assert.equal(racing.filter(result => result.status === 'fulfilled').length, 1, 'Simultaneous tabs persist one identity only');
    assert(fetches >= 3);
    console.log('Checkout browser state behavior: passed (double click, reload, unavailable recovery, lost response, simultaneous/stale tabs, Cashier scope, credential exclusion)');
}
main().catch(error => {console.error(error); process.exit(1);});
