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
    };
    form.addEventListener('input', update);
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
