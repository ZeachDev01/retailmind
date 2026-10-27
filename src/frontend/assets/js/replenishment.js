(() => {
    const table = document.querySelector('.queue-list table');
    if (!table) return;
    let rows = Array.from(table.querySelectorAll('.queue-item'));
    const search = document.getElementById('queue-search');
    const stock = document.getElementById('queue-stock');
    const sort = document.getElementById('queue-sort');
    const pageSize = document.getElementById('queue-page-size');
    const pageStatus = document.getElementById('queue-page-status');
    const pagination = document.getElementById('queue-pagination');
    const recalculateBtn = document.getElementById('queue-recalculate');
    const actions = document.getElementById('queue-actions');
    const actionsTrigger = document.getElementById('queue-actions-trigger');
    const actionsMenu = document.getElementById('queue-actions-menu');
    const priority = { 'Out of stock': 0, 'Low stock': 1, 'In stock': 2 };
    const originalOrder = new Map(rows.map((row, index) => [row, index]));
    let lastSort;
    let currentPage = 1;

    const SNAPSHOT_KEY = 'retailmind_replenishment_snapshot_v2';
    const storage = (() => {
        try {
            return typeof window !== 'undefined' && window.sessionStorage ? window.sessionStorage : null;
        } catch (_) {
            return null;
        }
    })();
    const isRecalculate = typeof window !== 'undefined' && window.location && new URLSearchParams(window.location.search).has('recalculate');

    if (storage && isRecalculate) {
        try {
            storage.removeItem(SNAPSHOT_KEY);
        } catch (_) {}
    }

    if (recalculateBtn && storage) {
        recalculateBtn.addEventListener('click', () => {
            try {
                storage.removeItem(SNAPSHOT_KEY);
            } catch (_) {}
        });
    }

    if (actions && actionsTrigger && actionsMenu) {
        const setActionsOpen = open => {
            actionsMenu.hidden = !open;
            actionsTrigger.setAttribute('aria-expanded', String(open));
            if (open) actionsMenu.querySelector('[role="menuitem"]')?.focus();
        };
        actionsTrigger.addEventListener('click', () => setActionsOpen(actionsMenu.hidden));
        document.addEventListener('click', event => {
            if (!actions.contains(event.target)) setActionsOpen(false);
        });
        actions.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                setActionsOpen(false);
                actionsTrigger.focus();
            }
        });
    }

    function createSnapshot(itemsToSave) {
        return {
            timestamp: Date.now(),
            items: itemsToSave.map(row => ({
                name: row.dataset.name,
                sku: row.dataset.sku,
                stock: row.dataset.stock,
                onHand: Number(row.dataset.onHand),
                quantity: Number(row.dataset.quantity),
                html: row.outerHTML
            }))
        };
    }

    function renderRow(item) {
        if (item.html) return item.html;
        const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        const stockStatus = item.stock || 'In stock';
        const stockClass = stockStatus === 'Out of stock' ? 'tag-danger' : (stockStatus === 'Low stock' ? 'tag-warning' : 'tag-success');
        const rowClass = stockStatus.toLowerCase().replace(/\s+/g, '-');
        const onHand = Number(item.onHand || 0);
        const qty = Number(item.quantity || 0);
        return `<tr class="queue-item queue-${rowClass}" data-stock="${esc(stockStatus)}" data-name="${esc(item.name)}" data-sku="${esc(item.sku)}" data-on-hand="${onHand}" data-quantity="${qty}">` +
            `<td><span class="queue-priority ${stockClass}">${esc(stockStatus)}</span></td>` +
            `<td><a class="queue-product" href="../products/product_detail.php?id=${encodeURIComponent(String(item.productId || 0))}">${esc(item.name)}</a><small class="muted queue-sku">${esc(item.sku)}</small></td>` +
            `<td><strong class="queue-number ${onHand <= 0 ? 'queue-out' : ''}">${onHand}</strong> <small class="muted">units</small></td>` +
            `<td><strong class="queue-number queue-suggested">${qty}</strong> <small class="muted">units</small></td>` +
            `</tr>`;
    }

    let lastSnapRaw = null;
    function syncFromSnapshot() {
        if (!storage) return;
        let raw;
        try { raw = storage.getItem(SNAPSHOT_KEY); } catch (_) { return; }
        if (!raw || raw === lastSnapRaw) return;
        lastSnapRaw = raw;
        try {
            const snap = JSON.parse(raw);
            if (snap && Array.isArray(snap.items) && snap.items.length > 0 && table.tBodies?.[0]) {
                table.tBodies[0].innerHTML = snap.items.map(renderRow).join('');
                rows = Array.from(table.querySelectorAll('.queue-item'));
                originalOrder.clear();
                rows.forEach((r, idx) => originalOrder.set(r, idx));
            }
        } catch (_) {}
    }

    if (storage) {
        try {
            const snap = createSnapshot(rows);
            lastSnapRaw = JSON.stringify(snap);
            storage.setItem(SNAPSHOT_KEY, lastSnapRaw);
        } catch (_) {}
    }

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
        syncFromSnapshot();
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
        const filtered = rows.filter(row => {
            const matchesQuery = `${row.dataset.name} ${row.dataset.sku}`.toLocaleLowerCase().includes(query);
            let matchesStock = true;
            if (stock.value === 'needs_replenishment') {
                matchesStock = Number(row.dataset.quantity) > 0 || row.dataset.stock === 'Out of stock' || row.dataset.stock === 'Low stock';
            } else if (stock.value) {
                matchesStock = row.dataset.stock === stock.value;
            }
            return matchesQuery && matchesStock;
        });
        const size = Number(pageSize?.value) || filtered.length || 1;
        const pages = Math.max(1, Math.ceil(filtered.length / size));
        currentPage = Math.min(currentPage, pages);
        const start = (currentPage - 1) * size;
        rows.forEach(row => {
            row.hidden = true;
            if (sortChanged) table.tBodies[0].append(row);
        });
        filtered.slice(start, start + size).forEach(row => { row.hidden = false; });
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
    syncFromSnapshot();
    update();
})();
