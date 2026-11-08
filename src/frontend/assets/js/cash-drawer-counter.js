(function () {
    'use strict';
    document.addEventListener('submit', event => {
        if (!event.defaultPrevented && event.target.querySelector('input[name="action"][value="close"]')) {
            event.target.querySelector('[type="submit"]').disabled = true;
        }
    });
    const form = document.getElementById('cash-drawer-counter');
    if (!form) return;
    const rows = [...form.querySelectorAll('.money-counter-row')];
    const cash = form.querySelector('#actual-cash');
    const pesos = cents => (cents / 100).toLocaleString('en-PH', { style: 'currency', currency: 'PHP' });
    const expectedRaw = form.dataset.expected;
    const expectedCents = expectedRaw !== undefined && expectedRaw !== '' ? Math.round(Number(expectedRaw) * 100) : null;
    const varianceEl = form.querySelector('#count-variance');
    const alertEl = form.querySelector('#drawer-variance-alert');
    const alertText = form.querySelector('#drawer-variance-alert-text');

    const update = () => {
        let bills = 0, coins = 0, valid = true;
        for (const row of rows) {
            const input = row.querySelector('input');
            const quantity = Number(input.value);
            const okay = /^[0-9]{1,7}$/.test(input.value) && Number.isInteger(quantity) && quantity <= Number(input.max);
            input.setCustomValidity(okay ? '' : 'Enter a whole quantity from 0 to ' + input.max + '.');
            input.setAttribute('aria-invalid', String(!okay));
            valid = valid && okay;
            const subtotal = okay ? quantity * Number(row.dataset.cents) : 0;
            row.querySelector('.money-counter-subtotal').textContent = pesos(subtotal);
            row.classList.toggle('has-cash', okay && quantity > 0);
            row.querySelector('[data-step="-1"]').disabled = okay && quantity === 0;
            row.querySelector('[data-step="1"]').disabled = okay && quantity === Number(input.max);
            if (row.dataset.group === 'bills') bills += subtotal;
            else coins += subtotal;
        }
        form.querySelector('#count-bills').textContent = pesos(bills);
        form.querySelector('#count-coins').textContent = pesos(coins);
        const cents = valid ? bills + coins : 0;
        cash.value = Math.floor(cents / 100) + '.' + String(cents % 100).padStart(2, '0');

        if (expectedCents !== null && varianceEl) {
            const diffCents = cents - expectedCents;
            const diffPesos = Math.abs(diffCents) / 100;
            const formattedDiff = '₱' + diffPesos.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            varianceEl.classList.remove('is-balanced', 'is-overage', 'is-shortage');
            if (diffCents === 0) {
                varianceEl.textContent = '₱0.00 (Balanced)';
                varianceEl.classList.add('is-balanced');
                if (alertEl) alertEl.style.display = 'none';
            } else if (diffCents > 0) {
                varianceEl.textContent = '+' + formattedDiff + ' (Surplus)';
                varianceEl.classList.add('is-overage');
                if (alertEl && alertText) {
                    alertText.textContent = 'Physical cash exceeds expected register sales by ' + formattedDiff + ' (Surplus). Verify count before closure.';
                    alertEl.style.display = 'flex';
                }
            } else {
                varianceEl.textContent = '-' + formattedDiff + ' (Deficit)';
                varianceEl.classList.add('is-shortage');
                if (alertEl && alertText) {
                    alertText.textContent = 'Physical cash is short of expected register sales by ' + formattedDiff + ' (Deficit). Verify count before closure.';
                    alertEl.style.display = 'flex';
                }
            }
        }
    };
    form.addEventListener('input', update);
    form.addEventListener('focusin', event => {
        if (event.target.matches('.money-counter-stepper input')) {
            event.target.select();
        }
    });
    form.addEventListener('keydown', event => {
        if (event.key === 'Enter' && event.target.matches('.money-counter-stepper input')) {
            event.preventDefault();
            const inputs = rows.map(r => r.querySelector('input'));
            const idx = inputs.indexOf(event.target);
            if (idx >= 0 && idx < inputs.length - 1) {
                inputs[idx + 1].focus();
            } else {
                form.querySelector('[type="submit"]:not(:disabled)')?.focus();
            }
        }
    });
    form.addEventListener('change', event => {
        if (event.target.matches('.money-counter-stepper input') && !event.target.checkValidity()) {
            RetailMindUI.toast(event.target.validationMessage, 'error', undefined, 4300);
        }
    });
    form.addEventListener('click', event => {
        const button = event.target.closest('[data-step]');
        if (!button) return;
        const input = button.parentElement.querySelector('input');
        if (!input.checkValidity()) { input.reportValidity(); return; }
        input.value = Math.max(0, Math.min(Number(input.max), Number(input.value) + Number(button.dataset.step)));
        update();
    });
    form.querySelector('#reset-cash-count').addEventListener('click', async () => {
        const confirmed = await RetailMindUI.confirm({ title: 'Start over?', message: 'Clear all the numbers you entered?', confirmText: 'Start over' });
        if (!confirmed) return;
        for (const row of rows) row.querySelector('input').value = '0';
        update();
        RetailMindUI.toast('Cash count reset.', 'success', undefined, 4300);
    });
    form.addEventListener('submit', () => {
        // Preserve the submit payload while preventing a second click.
        form.querySelector('[type="submit"]').disabled = true;
    });
    update();
})();
