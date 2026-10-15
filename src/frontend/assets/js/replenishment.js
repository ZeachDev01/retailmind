(() => {
    const table = document.querySelector('.queue-list table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('.queue-item'));
    const search = document.getElementById('queue-search');
    const stock = document.getElementById('queue-stock');
    const sort = document.getElementById('queue-sort');
    const priority = { 'Out of stock': 0, 'Low stock': 1, 'In stock': 2 };
    const originalOrder = new Map(rows.map((row, index) => [row, index]));
    function update() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        rows.sort((a, b) => {
            if (sort.value === 'name') return a.dataset.name.localeCompare(b.dataset.name);
            if (sort.value === 'stock') return Number(a.dataset.onHand) - Number(b.dataset.onHand);
            if (sort.value === 'quantity') return Number(b.dataset.quantity) - Number(a.dataset.quantity);
            return priority[a.dataset.stock] - priority[b.dataset.stock]
                || Number(a.dataset.onHand) - Number(b.dataset.onHand)
                || originalOrder.get(a) - originalOrder.get(b);
        });
        rows.forEach(row => {
            row.hidden = !(`${row.dataset.name} ${row.dataset.sku}`.toLocaleLowerCase().includes(query)
                && (!stock.value || row.dataset.stock === stock.value));
            if (!row.hidden) visible++;
            table.tBodies[0].append(row);
        });
        document.getElementById('queue-results').textContent = `Showing ${visible} of ${rows.length} products`;
        document.getElementById('queue-no-results').hidden = visible !== 0;
    }
    search.addEventListener('input', update);
    [stock, sort].forEach(control => control.addEventListener('change', update));
    document.querySelectorAll('[data-queue-stock]').forEach(card => {
        card.addEventListener('click', () => {
            search.value = '';
            stock.value = card.dataset.queueStock;
            update();
        });
    });
    update();
})();
