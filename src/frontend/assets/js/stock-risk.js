(() => {
    const root = document.getElementById('stock-risk');
    if (!root || !root.querySelector('#risk-table-body')) return;
    const rows = Array.from(root.querySelectorAll('[data-risk-row]'));
    const search = root.querySelector('#risk-search');
    const stockFilter = root.querySelector('#movement-stock');
    const salesMetric = root.querySelector('#movement-sales-metric');
    const salesCharts = new Map();
    const reportButton = root.querySelector('#movement-report');
    const body = root.querySelector('#risk-table-body');
    const sorters = Array.from(root.querySelectorAll('[data-risk-sort]'));
    const previous = root.querySelector('#risk-previous');
    const next = root.querySelector('#risk-next');
    const drawer = root.querySelector('#movement-drawer');
    const detailBody = root.querySelector('#movement-detail-body');
    const collator = new Intl.Collator(undefined, {numeric:true, sensitivity:'base'});
    const numberFormat = new Intl.NumberFormat('en-PH');
    let page = 1;
    let sort = 'days';
    let direction = 1;
    let trigger = null;
    let bodyOverflow = '';
    function matchingRows() {
        const query = search.value.trim().toLowerCase();
        return rows.filter(row => (stockFilter.value === 'all' || Number(row.dataset.stock) > 0) && row.dataset.search.includes(query));
    }
    function renderSales() {
        if (!root.getClientRects().length) return;
        const metric = salesMetric.value;
        const unit = metric === 'days' ? 'selling days' : 'base units sold';
        const color = getComputedStyle(root).getPropertyValue('--primary').trim() || '#2563eb';
        for (const [row, chart] of salesCharts) {
            if (!row.hidden) continue;
            chart.canvas.parentElement.hidden = true;
            chart.destroy();
            salesCharts.delete(row);
        }
        rows.filter(row => !row.hidden).forEach(row => {
            const values = Array.from(row.querySelectorAll('.sr-sales-value'), value => {
                const amount = value.dataset[metric] === '' ? null : Number(value.dataset[metric]);
                value.textContent = amount === null ? '—' : numberFormat.format(amount);
                value.nextElementSibling.textContent = amount === null ? unit + ' unavailable' : unit;
                return value.dataset.coverage === 'unavailable' ? null : amount;
            });
            if (!window.Chart) return;
            const canvas = row.querySelector('.sr-sales-chart canvas');
            const chart = salesCharts.get(row);
            try {
                if (chart) {
                    chart.data.datasets[0].data = values;
                    chart.data.datasets[0].backgroundColor = color;
                    chart.update();
                    return;
                }
                const context = canvas.getContext('2d');
                if (!context) return;
                canvas.parentElement.hidden = false;
                salesCharts.set(row, new window.Chart(context, {
                    type:'bar',
                    data:{labels:values.map((_, index) => index), datasets:[{data:values, backgroundColor:color, maxBarThickness:36}]},
                    options:{
                        maintainAspectRatio:false, animation:false, events:[],
                        plugins:{legend:{display:false}},
                        scales:{x:{display:false}, y:{display:false, beginAtZero:true}}
                    }
                }));
            } catch {
                canvas.parentElement.hidden = true;
                salesCharts.delete(row);
                window.Chart.getChart?.(canvas)?.destroy();
            }
        });
    }
    function render() {
        const matching = matchingRows();
        reportButton.disabled = matching.length === 0;
        const pages = Math.max(1, Math.ceil(matching.length / 10));
        page = Math.min(page, pages);
        rows.forEach(row => { row.hidden = true; });
        matching.slice((page - 1) * 10, page * 10).forEach(row => { row.hidden = false; });
        root.querySelector('#risk-empty').hidden = matching.length > 0;
        root.querySelector('#risk-page-summary').textContent = matching.length ? 'Showing ' + ((page - 1) * 10 + 1) + '–' + Math.min(page * 10, matching.length) + ' of ' + matching.length + ' products' : 'No products to show';
        root.querySelector('#risk-page-status').textContent = 'Page ' + page + ' of ' + pages;
        previous.disabled = page === 1;
        next.disabled = page === pages;
        renderSales();
    }
    search.addEventListener('input', () => { page = 1; render(); });
    stockFilter.addEventListener('change', () => { page = 1; render(); });
    salesMetric.addEventListener('change', renderSales);
    root.addEventListener('retailmind:stockmovementopen', renderSales);
    window.addEventListener('retailmind:themechange', renderSales);
    function outsideDrawer(event) {
        if (event.target !== drawer) return;
        const rect = drawer.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) drawer.close();
    }
    function containDrawerFocus(event) {
        if (event.key !== 'Tab') return;
        const controls = Array.from(drawer.querySelectorAll('button,summary')).filter(el => !el.disabled && el.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        if ((event.shiftKey && document.activeElement === first) || (!event.shiftKey && document.activeElement === last)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        }
    }
    root.addEventListener('click', event => {
        const button = event.target.closest('[data-risk-review]');
        if (!button || drawer.open) return;
        detailBody.replaceChildren(root.querySelector('#risk-detail-' + button.dataset.riskReview).content.cloneNode(true));
        trigger = button;
        button.setAttribute('aria-expanded', 'true');
        bodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        drawer.showModal();
        drawer.addEventListener('click', outsideDrawer);
        drawer.addEventListener('keydown', containDrawerFocus);
    });
    root.querySelector('#movement-drawer-close').addEventListener('click', () => drawer.close());
    drawer.addEventListener('close', () => {
        drawer.removeEventListener('click', outsideDrawer);
        drawer.removeEventListener('keydown', containDrawerFocus);
        document.body.style.overflow = bodyOverflow;
        trigger?.setAttribute('aria-expanded', 'false');
        trigger?.focus();
        detailBody.replaceChildren();
    });
    function sortRows(key, order) {
        direction = order;
        sort = key;
        rows.sort((a,b) => direction * (key === 'name' ? collator.compare(a.dataset.name,b.dataset.name) : Number(a.dataset[key]) - Number(b.dataset[key])));
        rows.forEach(row => body.append(row));
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
        sortRows(key, key === 'stock' ? -1 : 1);
    });
    previous.addEventListener('click', () => { if (page > 1) { page--; render(); } });
    next.addEventListener('click', () => { page++; render(); });
    reportButton.addEventListener('click', () => {
        const report = window.open('', '_blank');
        if (!report) {
            root.querySelector('#movement-report-status').textContent = 'Allow pop-ups for RetailMind, then generate the report again.';
            return;
        }
        root.querySelector('#movement-report-status').textContent = '';
        report.opener = null;
        report.document.title = 'Stock Movement Report';
        report.document.documentElement.lang = 'en';
        report.document.documentElement.dataset.theme = 'light';
        document.querySelectorAll('link[rel="stylesheet"],style').forEach(link => {
            const copy = link.cloneNode(true);
            if (link.href) copy.href = link.href;
            report.document.head.append(copy);
        });
        report.document.body.className = 'stock-movement-report';
        const actions = report.document.createElement('div');
        actions.className = 'sr-report-actions';
        const print = report.document.createElement('button');
        print.type = 'button';
        print.className = 'btn btn-primary';
        print.textContent = 'Print / Save as PDF';
        print.addEventListener('click', () => report.print());
        actions.append(print);
        const snapshot = root.querySelector('#movement-report-template').content.firstElementChild.cloneNode(true);
        const summary = snapshot.querySelector('tbody');
        const summaryRows = new Map(Array.from(summary.rows, row => [row.dataset.productId, row]));
        const matching = matchingRows();
        summary.replaceChildren(...matching.map(row => summaryRows.get(row.dataset.productId)));
        const generated = new Intl.DateTimeFormat('en-PH', {dateStyle:'medium', timeStyle:'short', timeZone:'Asia/Manila'}).format(new Date()) + ' PHT';
        snapshot.querySelector('.sr-report-stamp strong').textContent = generated;
        snapshot.querySelector('.sr-report-scope').textContent = stockFilter.selectedOptions[0].textContent + ' · Search: ' + (search.value.trim() || 'All') + ' · ' + matching.length + ' products · Quantities in base units';
        const review = report.document.createElement('p');
        review.className = 'sr-caption';
        review.textContent = root.querySelector('#movement-period-help').textContent;
        snapshot.append(review);
        matching.forEach(row => {
            const section = report.document.createElement('section');
            section.className = 'sr-report-product';
            section.append(root.querySelector('#risk-detail-' + row.dataset.productId).content.cloneNode(true));
            section.querySelectorAll('[id]').forEach(el => el.removeAttribute('id'));
            section.querySelector('.sr-coverage-note').remove();
            section.querySelectorAll('details').forEach(el => { el.open = true; });
            snapshot.append(section);
        });
        // Shared calculation limitations belong once in a complete report.
        const basis = Array.from(snapshot.querySelector('.sr-report-product').querySelectorAll('.sr-basis')).map(el => el.textContent).join(' ');
        snapshot.querySelectorAll('.sr-basis').forEach(el => el.remove());
        const note = report.document.createElement('p');
        note.className = 'sr-caption';
        note.textContent = basis;
        snapshot.append(note);
        const footer = report.document.createElement('footer');
        footer.className = 'sr-report-footer';
        const store = report.document.createElement('span');
        store.textContent = snapshot.querySelector('.sr-report-brand strong').textContent + ' · RetailMind';
        const end = report.document.createElement('span');
        end.textContent = 'End of report · ' + matching.length + ' products · ' + generated;
        footer.append(store, end);
        snapshot.append(footer);
        report.document.body.append(actions, snapshot);
    });
    render();
})();
