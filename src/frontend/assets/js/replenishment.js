(() => {
    const table = document.querySelector('.queue-list table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('.queue-item'));
    const cards = Array.from(document.querySelectorAll('[data-queue-stock]'));
    const search = document.getElementById('queue-search');
    const stock = document.getElementById('queue-stock');
    const sort = document.getElementById('queue-sort');
    const pageSize = document.getElementById('queue-page-size');
    const pageStatus = document.getElementById('queue-page-status');
    const pagination = document.getElementById('queue-pagination');
    const priority = { 'Out of stock': 0, 'Low stock': 1, 'In stock': 2 };
    const originalOrder = new Map(rows.map((row, index) => [row, index]));
    let lastSort;
    let currentPage = 1;
    function button(label, page, ariaLabel, disabled, current = false) {
        const element = document.createElement('button');
        element.type = 'button';
        element.textContent = label;
        element.setAttribute('aria-label', ariaLabel);
        if (current) element.setAttribute('aria-current', 'page');
        element.disabled = disabled;
        element.addEventListener('click', () => { currentPage = page; update(); });
        return element;
    }
    function update() {
        const query = search.value.trim().toLocaleLowerCase();
        const sortChanged = sort.value !== lastSort;
        if (sortChanged) {
            lastSort = sort.value;
            rows.sort((a, b) => {
                if (sort.value === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                if (sort.value === 'stock') return Number(a.dataset.onHand) - Number(b.dataset.onHand);
                if (sort.value === 'quantity') return Number(b.dataset.quantity) - Number(a.dataset.quantity);
                return priority[a.dataset.stock] - priority[b.dataset.stock]
                    || Number(a.dataset.onHand) - Number(b.dataset.onHand)
                    || originalOrder.get(a) - originalOrder.get(b);
            });
        }
        const filtered = rows.filter(row =>
            `${row.dataset.name} ${row.dataset.sku}`.toLocaleLowerCase().includes(query)
            && (!stock.value || row.dataset.stock === stock.value));
        const size = Number(pageSize?.value) || filtered.length || 1;
        const pages = Math.max(1, Math.ceil(filtered.length / size));
        currentPage = Math.min(currentPage, pages);
        const start = (currentPage - 1) * size;
        rows.forEach(row => {
            row.hidden = true;
            if (sortChanged) table.tBodies[0].append(row);
        });
        filtered.slice(start, start + size).forEach(row => { row.hidden = false; });
        cards.forEach(card => {
            if (stock.value && card.dataset.queueStock === stock.value) {
                card.setAttribute('aria-current', 'true');
            } else {
                card.removeAttribute('aria-current');
            }
        });
        document.getElementById('queue-results').textContent = pageSize
            ? `Showing ${filtered.length ? start + 1 : 0}–${Math.min(start + size, filtered.length)} of ${filtered.length} products`
            : `Showing ${filtered.length} of ${rows.length} products`;
        document.getElementById('queue-no-results').hidden = filtered.length !== 0;
        if (pageStatus) pageStatus.textContent = `Page ${currentPage} of ${pages}`;
        if (pagination) {
            pagination.replaceChildren(
                button('«', 1, 'First page', currentPage === 1),
                button('‹', Math.max(1, currentPage - 1), 'Previous page', currentPage === 1)
            );
            for (let page = Math.max(1, currentPage - 2); page <= Math.min(pages, currentPage + 2); page++) {
                pagination.append(button(String(page), page, `Page ${page}`, false, page === currentPage));
            }
            pagination.append(
                button('›', Math.min(pages, currentPage + 1), 'Next page', currentPage === pages),
                button('»', pages, 'Last page', currentPage === pages)
            );
        }
    }
    search.addEventListener('input', () => { currentPage = 1; update(); });
    [stock, sort].forEach(control => control.addEventListener('change', () => { currentPage = 1; update(); }));
    pageSize?.addEventListener('change', () => { currentPage = 1; update(); });
    cards.forEach(card => {
        card.addEventListener('click', () => {
            search.value = '';
            stock.value = stock.value === card.dataset.queueStock ? '' : card.dataset.queueStock;
            currentPage = 1;
            update();
        });
    });
    update();
})();
