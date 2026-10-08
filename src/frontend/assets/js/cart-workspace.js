// One recovery envelope: a new Cashier, shift, or workspace clears prior work.
class CartWorkspace {
    constructor(context, storage = sessionStorage) {
        this.context = context;
        this.storage = storage;
        this.key = 'retailmind.cart-workspace';
        this.busy = false;
        storage.removeItem('pos_cart');
        try {
            const saved = JSON.parse(storage.getItem(this.key));
            this.state = saved && saved.cashier === context.cashier && saved.shift === context.shift
                && context.workspace === 'cashier' && context.shift ? saved : null;
        } catch { this.state = null; }
        if (!this.state) storage.removeItem(this.key);
    }
    save(cart, heldSaleId = 0, unresolved = false) {
        if (this.context.workspace !== 'cashier' || !this.context.shift || this.context.locked) return;
        this.state = {...this.context, cart, heldSaleId, unresolved};
        this.storage.setItem(this.key, JSON.stringify(this.state));
    }
    clear() { this.state = null; this.storage.removeItem(this.key); }
    async request(action, payload) {
        const fallback = 'The held sale could not be completed. Check your connection and try again. Tell your Administrator if this keeps happening.';
        let response;
        try {
            response = await fetch(this.context.endpoint, {method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': this.context.csrf},
                body: JSON.stringify({action, ...payload, cashier_context: this.context.cashier, shift_context: this.context.shift})});
        } catch (error) {
            console.error('Held sale request could not reach the server:', error);
            throw new Error(fallback);
        }
        let data;
        try { data = await response.json(); } catch (error) { console.error('Held sale response could not be read:', error); throw new Error(fallback); }
        if (!response.ok || !data.success) throw new Error(data.message || 'Your work is still unfinished. Try again.');
        return data;
    }
    async review(cart) {
        const {review} = await this.request('review', {cart});
        return this.acceptReview(review);
    }
    async acceptReview(review) {
        if (review.changes.length && !await RetailMindUI.confirm({title: 'Review cart changes',
            message: review.changes.join('\n') + '\nAccept these changes? Stock and prices are checked again at checkout.',
            confirmText: 'Accept changes'})) return null;
        return review.cart;
    }
    async holdRequested(cart) {
        const {review} = await this.request('review', {cart});
        if (review.changes.length && !await RetailMindUI.confirm({title: 'Hold requested lines?',
            message: review.changes.join('\n') + '\nHolding preserves requested lines for later review; it reserves no stock or price.',
            confirmText: 'Hold requested lines'})) return null;
        return this.request('hold', {cart});
    }
    async exclusive(operation) {
        if (this.busy) throw new Error('A cart decision is already in progress. Wait for it to finish.');
        this.busy = true;
        try { return await operation(); } finally { this.busy = false; }
    }
    async resolveWork() { return this.exclusive(() => this.resolveUnfinishedWork()); }
    async resolveUnfinishedWork() {
        const state = this.state;
        if (!state || (!Object.keys(state.cart || {}).length && !state.heldSaleId)) return true;
        // Payment recovery must finish before any decision can discard that cart.
        if (localStorage.getItem('retailmind.checkout.' + this.context.cashier)) {
            await RetailMindUI.alert({title: 'Recover checkout first', message: 'Return to Point of Sale and recover the checkout before leaving.'});
            return false;
        }
        const choice = await Swal.fire({title: 'Preserve unfinished work?',
            text: 'Hold the requested cart without reserving stock or prices, or discard it with a reason.',
            showDenyButton: true, showCancelButton: true, confirmButtonText: 'Hold sale', denyButtonText: 'Discard sale', cancelButtonText: 'Keep working'});
        if (choice.isConfirmed) {
            if (!state.heldSaleId && !await this.holdRequested(state.cart)) return false;
            this.clear();
            return true;
        }
        if (!choice.isDenied) return false;
        const reasons = this.context.reasons;
        const reason = await Swal.fire({title: 'Discard unfinished work', input: 'select', inputOptions: reasons,
            inputPlaceholder: 'Choose a reason', showCancelButton: true, confirmButtonText: 'Continue',
            inputValidator: value => value ? undefined : 'Choose a reason.'});
        if (!reason.isConfirmed) return false;
        let note = '';
        if (reason.value === 'other') {
            const detail = await Swal.fire({title: 'Explain the discard', input: 'textarea', inputAttributes: {maxlength: '255'},
                showCancelButton: true, confirmButtonText: 'Discard sale', inputValidator: value => value.trim() ? undefined : 'Add a note.'});
            if (!detail.isConfirmed) return false;
            note = detail.value;
        }
        await this.request(state.heldSaleId ? 'discard' : 'discard_cart', {id: state.heldSaleId, cart: state.cart,
            discard_reason: reason.value, discard_note: note});
        this.clear();
        return true;
    }
    bindNavigation() {
        let resolving = false;
        const run = async continuation => {
            if (resolving) return;
            resolving = true;
            try { if (await this.resolveWork()) continuation(); }
            catch (error) { await RetailMindUI.alert({title: 'Unfinished work preserved', message: error.message}); }
            finally { resolving = false; }
        };
        document.addEventListener('click', event => {
            const link = event.target.closest('a[href*="auth/logout.php"]');
            if (!link || !this.state) return;
            event.preventDefault(); event.stopImmediatePropagation();
            run(() => location.assign(link.href));
        }, true);
        document.addEventListener('submit', event => {
            const form = event.target;
            if (!(form.getAttribute('action') || '').includes('auth/workspace.php') || !this.state) return;
            event.preventDefault(); event.stopImmediatePropagation();
            run(() => {
                window.rmPrepareFullscreenForm?.(form, event.submitter);
                HTMLFormElement.prototype.submit.call(form);
            });
        }, true);
    }
}
if (typeof module !== 'undefined') module.exports = CartWorkspace;
