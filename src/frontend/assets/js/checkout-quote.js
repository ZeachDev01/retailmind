/* A reviewed server quote is separate from the durable checkout attempt. */
class CheckoutQuote {
    constructor(form) {
        this.form = form;
        this.reviewed = null;
        this.inputs = null;
    }

    inputSnapshot() {
        return JSON.stringify(['cart', 'held_sale_id', 'payment_method', 'discount_type', 'discount_value',
            'discount_reason', 'discount_approver_username', 'discount_approver_password']
            .map(name => this.form.elements[name].value));
    }

    async review() {
        this.reviewed = null;
        this.form.elements.quote_token.value = '';
        const inputs = this.inputSnapshot();
        const data = new FormData(this.form);
        data.set('action', 'review_quote');
        let response;
        let outcome;
        try {
            response = await fetch(this.form.getAttribute('action') || window.location.href, {
                method: 'POST', body: data, cache: 'no-store'
            });
            outcome = await response.json();
        } catch {
            throw new Error('The final quote is unavailable. Check your connection and try again before collecting payment.');
        }
        if (!response.ok) throw new Error(outcome.message || 'The final quote is unavailable. Try again.');
        if (inputs !== this.inputSnapshot()) throw new Error('The cart or discount changed. Review the final quote again.');
        this.inputs = inputs;
        this.reviewed = outcome.quote;
        this.form.elements.quote_token.value = outcome.token;
        return this.reviewed;
    }

    isCurrent() {
        return this.reviewed !== null && this.inputs === this.inputSnapshot();
    }
}
