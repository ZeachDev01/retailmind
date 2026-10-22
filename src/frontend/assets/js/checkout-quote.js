/* A reviewed server quote is separate from the durable checkout attempt. */
class CheckoutQuote {
    constructor(form) {
        this.form = form;
        this.reviewed = null;
        this.inputs = null;
        this.refreshVersion = 0;
    }

    inputSnapshot() {
        return JSON.stringify(['cart', 'held_sale_id', 'payment_method', 'promotion_id']
            .map(name => this.form.elements[name].value));
    }

    invalidate() {
        this.reviewed = null;
        this.form.elements.quote_token.value = '';
    }

    async request(data) {
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
        if (!response.ok) {
            const error = new Error(outcome.message || 'The final quote is unavailable. Try again.');
            if (outcome.conflicts) error.conflicts = outcome.conflicts;
            throw error;
        }
        return outcome;
    }

    showPromotions(promotions) {
        const select = this.form.elements.promotion_id;
        const selected = select.value;
        select.replaceChildren(new Option('No promotion', ''));
        for (const promotion of promotions) {
            const option = new Option(`${promotion.promotion_name} · ₱${Number(promotion.applied_amount).toFixed(2)}`, String(promotion.promotion_id));
            option.dataset.amount = promotion.applied_amount;
            select.add(option);
        }
        select.value = promotions.some(p => String(p.promotion_id) === selected) ? selected : '';
        const invalidated = selected !== '' && select.value === '';
        if (invalidated) this.invalidate();
        this.form.querySelector('#promotion-status').textContent = invalidated
            ? 'Selected promotion no longer qualifies for this cart or is unavailable. Selection cleared; review the updated total before payment.'
            : (promotions.length ? 'Choose one promotion, or No promotion.' : 'No eligible promotions for this cart.');
        return invalidated;
    }

    async refreshPromotions() {
        const version = ++this.refreshVersion;
        this.invalidate();
        const inputs = this.inputSnapshot();
        const data = new FormData(this.form);
        data.set('promotion_id', '');
        const status = this.form.querySelector('#promotion-status');
        status.textContent = 'Loading eligible promotions…';
        try {
            const outcome = await this.request(data);
            if (version !== this.refreshVersion || inputs !== this.inputSnapshot()) return;
            this.showPromotions(outcome.quote.eligible_promotions);
        } catch (error) {
            if (version === this.refreshVersion) status.textContent = 'Promotions unavailable. Review checkout to try again before collecting payment.';
            throw error;
        }
    }

    async review(requireUnchanged = false) {
        ++this.refreshVersion;
        const previous = this.reviewed;
        this.invalidate();
        const inputs = this.inputSnapshot();
        let outcome;
        try {
            outcome = await this.request(new FormData(this.form));
        } catch (error) {
            if (error.message.includes('selected promotion is no longer eligible')) {
                this.form.elements.promotion_id.value = '';
                this.form.querySelector('#promotion-status').textContent = error.message + ' Selection cleared; review the updated total before payment.';
            }
            throw error;
        }
        if (inputs !== this.inputSnapshot()) throw new Error('The cart or promotion changed. Review the final quote again.');
        this.showPromotions(outcome.quote.eligible_promotions);
        if (requireUnchanged && JSON.stringify(previous) !== JSON.stringify(outcome.quote)) {
            throw new Error('Prices, stock or promotions changed. Review the final quote again before recording payment.');
        }
        this.inputs = inputs;
        this.reviewed = outcome.quote;
        this.form.elements.quote_token.value = outcome.token;
        return this.reviewed;
    }

    isCurrent() {
        return this.reviewed !== null && this.inputs === this.inputSnapshot();
    }
}
