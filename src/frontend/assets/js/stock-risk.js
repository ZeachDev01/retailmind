(() => {
    const root = document.getElementById('stock-risk');
    if (!root || !root.querySelector('#risk-table-body')) return;
    const rows = Array.from(root.querySelectorAll('[data-risk-row]'));
    const initialOrder = new Map(rows.map((row,index) => [row,index]));
    const search = root.querySelector('#risk-search');
    const body = root.querySelector('#risk-table-body');
    const filters = Array.from(root.querySelectorAll('[data-risk-filter]'));
    const sorters = Array.from(root.querySelectorAll('[data-risk-sort]'));
    const previous = root.querySelector('#risk-previous');
    const next = root.querySelector('#risk-next');
    const collator = new Intl.Collator(undefined, {numeric:true, sensitivity:'base'});
    let filter = 'all';
    let page = 1;
    let sort = '';
    let direction = 1;
    function render() {
        const query = search.value.trim().toLowerCase();
        const matching = rows.filter(row => (filter === 'all' || row.dataset.flags.split(' ').includes(filter)) && row.dataset.search.includes(query));
        const pages = Math.max(1, Math.ceil(matching.length / 10));
        page = Math.min(page, pages);
        rows.forEach(row => {
            row.hidden = true;
            const button = row.querySelector('[data-risk-review]');
            button.setAttribute('aria-expanded', 'false');
            root.querySelector('#risk-detail-' + button.dataset.riskReview).hidden = true;
        });
        matching.slice((page - 1) * 10, page * 10).forEach(row => { row.hidden = false; });
        root.querySelector('#risk-empty').hidden = matching.length > 0;
        root.querySelector('#risk-page-summary').textContent = matching.length ? `Showing ${(page - 1) * 10 + 1}–${Math.min(page * 10, matching.length)} of ${matching.length} products` : 'No products to show';
        root.querySelector('#risk-page-status').textContent = `Page ${page} of ${pages}`;
        previous.disabled = page === 1;
        next.disabled = page === pages;
    }
    filters.forEach(button => button.addEventListener('click', () => {
        filter = button.dataset.riskFilter;
        page = 1;
        filters.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        render();
    }));
    search.addEventListener('input', () => { page = 1; render(); });
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-risk-review]');
        if (!button) return;
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(expanded));
        root.querySelector('#risk-detail-' + button.dataset.riskReview).hidden = !expanded;
    });
    function sortRows(key, order) {
        direction = order;
        sort = key;
        rows.sort((a,b) => key === 'priority' ? initialOrder.get(a) - initialOrder.get(b) : direction * (key === 'name' ? collator.compare(a.dataset.name,b.dataset.name) : Number(a.dataset[key]) - Number(b.dataset[key])));
        rows.forEach(row => {
            body.append(row, root.querySelector('#risk-detail-' + row.querySelector('[data-risk-review]').dataset.riskReview));
        });
        body.append(root.querySelector('#risk-empty'));
        sorters.forEach(item => {
            const active = item.dataset.riskSort === key;
            item.closest('th').setAttribute('aria-sort', active ? (direction === 1 ? 'ascending' : 'descending') : 'none');
            item.querySelector('i').className = active ? (direction === 1 ? 'bi bi-arrow-up' : 'bi bi-arrow-down') : 'bi bi-arrow-down-up';
        });
        page = 1;
        render();
    }
    sorters.forEach(button => button.addEventListener('click', () => {
        const key = button.dataset.riskSort;
        sortRows(key, sort === key ? -direction : 1);
    }));
    root.querySelector('#risk-sort-mobile').addEventListener('change', event => {
        const key = event.target.value;
        sortRows(key, key === 'name' ? 1 : -1);
    });
    previous.addEventListener('click', () => { if (page > 1) { page--; render(); } });
    next.addEventListener('click', () => { page++; render(); });
    render();
})();
