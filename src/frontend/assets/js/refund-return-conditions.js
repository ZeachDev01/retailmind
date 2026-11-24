(() => {
    const sync = form => {
        const target = form.elements.new_condition.value;
        const current = form.dataset.current;
        const quantity = Number(form.dataset.quantity);
        const unit = form.dataset.sellingForm === 'pack' ? (quantity === 1 ? 'pack' : 'packs') : (quantity === 1 ? 'piece' : 'pieces');
        form.querySelector('.refund-condition-confirm').hidden = target !== 'restockable';
        form.elements.fit_for_sale.required = target === 'restockable';
        form.querySelector('.refund-condition-preview').textContent = !target ? 'Choose the correct condition to preview the stock change.' : target === current ? 'The condition is unchanged. No stock will move.' : `${form.dataset.product} — ${current === 'damaged' ? 'Damaged' : 'Restockable'} → ${target === 'damaged' ? 'Damaged' : 'Restockable'}. ${target === 'restockable' ? `Return ${quantity} ${unit} to` : `Remove ${quantity} ${unit} from`} available stock.`;
        form.querySelector('button[type="submit"]').disabled = !target || target === current;
    };
    document.addEventListener('change', event => {
        const form = event.target.closest('.refund-condition-form');
        if (form) sync(form);
    });
    document.addEventListener('submit', event => {
        const form = event.target.closest('.refund-condition-form');
        if (form) form.querySelector('button[type="submit"]').disabled = true;
    });
    window.initRefundConditions = root => root.querySelectorAll('.refund-condition-form').forEach(sync);
})();
