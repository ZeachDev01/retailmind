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
            this.form.elements.checkout_attempt.value = this.pending.id;
            const response = await fetch(this.options.recoverUrl + '?recover_checkout=' + encodeURIComponent(this.pending.id), {cache: 'no-store'});
            if (!response.ok) throw new Error('Recovery unavailable');
            const outcome = await response.json();
            if (outcome.sale) {
                // Keep the identity until receipt navigation succeeds. Returning
                // to POS recovers the same receipt if that navigation is lost.
                window.location.replace(outcome.receipt_url);
                return;
            }
            for (const [name, value] of Object.entries(this.pending.fields)) {
                if (this.form.elements[name]) this.form.elements[name].value = value;
            }
            this.options.restore(this.pending.cart);
            this.options.message('Previous checkout recovered for retry. No saved receipt yet. Do not collect payment again; retry the same checkout.');
            this.ready = true;
        } catch (error) {
            this.options.message('Unable to recover the previous checkout. Reload to retry recovery before collecting another payment.');
        }
    }

    async save(cart) {
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
        this.form.elements.checkout_attempt.value = id;
        const fields = {};
        for (const [name, value] of new FormData(this.form)) {
            if (name !== 'csrf_token' && !name.includes('password')) fields[name] = value;
        }
        this.pending = {id, cart, fields};
        localStorage.setItem(this.key, JSON.stringify(this.pending));
    }
}
