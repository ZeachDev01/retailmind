/* Persist before sending payment confirmation; navigation cannot invent a retry. */
class CheckoutAttempt {
    constructor(form, cashierId, options) {
        this.form = form;
        this.options = options;
        this.key = 'retailmind.checkout.' + cashierId;
        this.ready = false;
        this.pending = null;
    }

    async recover() {
        this.ready = false;
        try {
            this.pending = JSON.parse(localStorage.getItem(this.key) || 'null');
            if (!this.pending) {
                this.ready = true;
                return;
            }
            if (this.form.elements.checkout_attempt) this.form.elements.checkout_attempt.value = this.pending.id;
            if (this.form.elements.checkout_attempt_id) this.form.elements.checkout_attempt_id.value = this.pending.id;
            const response = await fetch(this.options.recoverUrl + '?recover_checkout=' + encodeURIComponent(this.pending.id), {cache: 'no-store'});
            if (!response.ok) throw new Error('Recovery unavailable');
            const outcome = await response.json();
            if (outcome.sale) {
                // Keep the identity until receipt navigation succeeds. Returning
                // to POS recovers the same receipt if that navigation is lost.
                if (window.RetailMindNavigation) await window.RetailMindNavigation.navigate(outcome.receipt_url, {history: 'none'});
                else window.location.replace(outcome.receipt_url);
                return;
            }
            if (outcome.cancelled) {
                this.clearCancelled();
                return;
            }
            for (const [name, value] of Object.entries(this.pending.fields)) {
                const input = this.form.elements[name];
                if (input) {
                    if (name === 'promotion_id' && value && !Array.from(input.options).some(option => option.value === value)) {
                        input.add(new Option('Previously selected promotion (refreshing)', value));
                    }
                    input.value = value;
                }
            }
            this.options.restore(this.pending.cart);
            this.options.message('Previous checkout recovered for retry. No saved receipt yet. Do not collect payment again; retry the same checkout.', 'warning');
            this.ready = true;
        } catch (error) {
            this.options.message('Unable to recover the previous checkout. Reload to retry recovery before collecting another payment.', 'error');
        }
    }

    async cancel() {
        if (!this.pending) return false;
        if (!navigator.locks) throw new Error('Checkout cancellation requires a secure browser context with Web Locks.');
        return navigator.locks.request(this.key, async () => {
            const shared = JSON.parse(localStorage.getItem(this.key) || 'null');
            if (!shared || shared.id !== this.pending?.id) {
                this.ready = false;
                throw new Error('Another tab changed the checkout. Reload to recover it.');
            }
            this.ready = false;
            const data = new FormData(this.form);
            data.set('action', 'cancel_checkout');
            data.set('checkout_attempt', this.pending.id);
            data.set('no_outstanding_payment', '1');
            const response = await fetch(this.options.recoverUrl, {method: 'POST', body: data, cache: 'no-store'});
            const outcome = await response.json();
            if (!response.ok) throw new Error(outcome.message || 'Checkout cancellation failed. Reload to recover before collecting payment.');
            if (outcome.sale) {
                if (window.RetailMindNavigation) await window.RetailMindNavigation.navigate(outcome.receipt_url, {history: 'none'});
                else window.location.replace(outcome.receipt_url);
                return false;
            }
            if (!outcome.cancelled) throw new Error('Cancellation was not confirmed. Reload to recover the checkout.');
            this.clearCancelled();
            return true;
        });
    }

    clearCancelled() {
        const shared = JSON.parse(localStorage.getItem(this.key) || 'null');
        if (shared && shared.id !== this.pending.id) throw new Error('Another tab changed the checkout. Reload to recover it.');
        if (shared) localStorage.removeItem(this.key);
        this.pending = null;
        for (const name of ['checkout_attempt', 'checkout_attempt_id', 'cash_received', 'payment_reference', 'payment_verified', 'quote_token']) {
            if (this.form.elements[name]) this.form.elements[name].value = '';
        }
        this.ready = true;
        this.options.cancelled?.();
    }

    async save(cart) {
        if (typeof navigator !== 'undefined' && 'onLine' in navigator && !navigator.onLine) {
            throw new Error('Checkout requires an active internet connection. Please check your network and try again.');
        }
        if (!this.ready) throw new Error('Recovery required');
        if (!navigator.locks) throw new Error('Checkout requires a secure browser context with Web Locks');
        return navigator.locks.request(this.key, () => this.persist(cart));
    }

    persist(cart) {
        // Tabs opened before a lost response must see the first tab's identity.
        // Serialize the read/write pair so simultaneous tabs cannot both invent
        // identities. A stale tab must recover before confirming another payment.
        const shared = JSON.parse(localStorage.getItem(this.key) || 'null');
        if ((shared && (!this.pending || shared.id !== this.pending.id)) || (!shared && this.pending)) {
            this.ready = false;
            throw new Error('Another tab changed the checkout. Reload to recover it before collecting payment.');
        }
        const bytes = new Uint8Array(16);
        const id = this.pending ? this.pending.id : Array.from(crypto.getRandomValues(bytes), byte => byte.toString(16).padStart(2, '0')).join('');
        if (this.form.elements.checkout_attempt) this.form.elements.checkout_attempt.value = id;
        if (this.form.elements.checkout_attempt_id) this.form.elements.checkout_attempt_id.value = id;
        const fields = {};
        for (const [name, value] of new FormData(this.form)) {
            if (name !== 'csrf_token' && !name.includes('password')) fields[name] = value;
        }
        this.pending = {id, cart, fields};
        localStorage.setItem(this.key, JSON.stringify(this.pending));
    }
}
