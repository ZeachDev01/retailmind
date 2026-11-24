(() => {
    const root = document.getElementById('stock-risk');
    if (!root || !root.querySelector('#risk-table-body')) return;
    const rows = Array.from(root.querySelectorAll('[data-risk-row]'));
    const search = root.querySelector('#risk-search');
    const stockFilter = root.querySelector('#movement-stock');
    const chartDataNode = root.querySelector('#movement-chart-data');
    const chartData = chartDataNode ? JSON.parse(chartDataNode.textContent) : null;
    const reportButton = root.querySelector('#movement-report');
    const body = root.querySelector('#risk-table-body');
    const sorters = Array.from(root.querySelectorAll('[data-risk-sort]'));
    const previous = root.querySelector('#risk-previous');
    const next = root.querySelector('#risk-next');
    const drawer = root.querySelector('#movement-drawer');
    const detailBody = root.querySelector('#movement-detail-body');
    const collator = new Intl.Collator(undefined, {numeric:true, sensitivity:'base'});
    const numberFormat = new Intl.NumberFormat('en-PH');
    let page = 1, pageSize = 15;
    const footer = root.querySelector('.sr-pagination');
    RetailMindUI.tablePageSize(footer, pageSize, size => { pageSize = size; page = 1; render(); });
    let sort = 'days';
    let direction = 1;
    let trigger = null;
    let bodyOverflow = '';
    let pageCharts = [];
    let drawerChart = null;
    let chartRetryBound = false;
    function colors() {
        const styles = getComputedStyle(root);
        return {primary:styles.getPropertyValue('--primary').trim() || '#2563eb',
            muted:styles.getPropertyValue('--muted').trim() || '#626b7a',
            grid:styles.getPropertyValue('--border').trim() || '#e5e7eb'};
    }
    function destroyPageCharts() { pageCharts.forEach(chart => chart.destroy()); pageCharts = []; }
    function chartFallback(show) {
        root.querySelectorAll('.sr-card-chart').forEach(box => {
            const existing = box.parentElement.querySelector('.sr-chart-fallback');
            if (show && !existing) {
                const note = document.createElement('p');
                note.className = 'sr-chart-fallback';
                note.textContent = 'Charts are unavailable in this browser.';
                box.after(note);
            } else if (!show && existing) {
                existing.remove();
            }
        });
    }
    function renderCharts() {
        destroyPageCharts();
        if (!chartData || !root.getClientRects().length) return;
        if (!window.Chart) {
            chartFallback(true);
            if (!chartRetryBound) { chartRetryBound = true; window.addEventListener('load', renderCharts, {once:true}); }
            return;
        }
        chartFallback(false);
        const {primary, grid, muted} = colors();
        const base = {responsive:true, maintainAspectRatio:false, animation:false, plugins:{legend:{display:false}}};
        const axis = {beginAtZero:true, grid:{color:grid}, ticks:{font:{size:10}, precision:0}};
        try {
            pageCharts.push(new window.Chart(root.querySelector('#movement-chart-weekly'), {
                type:'line',
                data:{labels:chartData.weekly.labels, datasets:[{data:chartData.weekly.values, borderColor:primary,
                    backgroundColor:'rgba(37,99,235,.12)', fill:true, tension:.3, pointRadius:0, borderWidth:2}]},
                options:{...base, scales:{x:{grid:{display:false}, ticks:{autoSkip:true, maxTicksLimit:7, font:{size:10}}}, y:axis}}
            }));
            pageCharts.push(new window.Chart(root.querySelector('#movement-chart-top'), {
                type:'bar',
                data:{labels:chartData.top.labels, datasets:[{data:chartData.top.values, backgroundColor:primary, borderRadius:3, maxBarThickness:16}]},
                options:{...base, indexAxis:'y', scales:{x:axis, y:{grid:{display:false}, ticks:{font:{size:10}}}}}
            }));
            pageCharts.push(new window.Chart(root.querySelector('#movement-chart-coverage'), {
                type:'doughnut',
                data:{labels:chartData.coverage.labels, datasets:[{data:chartData.coverage.values,
                    backgroundColor:[primary, '#f59e0b', muted, '#94a3b8'], borderWidth:0}]},
                options:{...base, cutout:'66%'}
            }));
        } catch {
            destroyPageCharts();
            chartFallback(true);
        }
    }
    function renderDrawerChart() {
        const canvas = detailBody.querySelector('canvas[data-movement-weekly]');
        drawerChart?.destroy();
        drawerChart = null;
        if (!canvas || !window.Chart) return;
        let values = [];
        try { values = JSON.parse(canvas.dataset.movementWeekly || '[]'); } catch { return; }
        const {primary, grid} = colors();
        try {
            drawerChart = new window.Chart(canvas, {
                type:'bar',
                data:{labels:chartData?.weekly?.labels ?? values.map((_, index) => index),
                    datasets:[{data:values, backgroundColor:primary, borderRadius:3, maxBarThickness:18}]},
                options:{responsive:true, maintainAspectRatio:false, animation:false,
                    plugins:{legend:{display:false}, tooltip:{callbacks:{label:item => item.raw === null ? 'No figure' : numberFormat.format(item.raw) + ' units'}}},
                    scales:{x:{grid:{display:false}, ticks:{autoSkip:true, maxTicksLimit:6, font:{size:10}}},
                        y:{beginAtZero:true, grid:{color:grid}, ticks:{precision:0}}}}
            });
        } catch { drawerChart = null; }
    }
    function matchingRows() {
        const query = search.value.trim().toLowerCase();
        return rows.filter(row => (stockFilter.value === 'all' || Number(row.dataset.stock) > 0) && row.dataset.search.includes(query));
    }
    function render() {
        const matching = matchingRows();
        reportButton.disabled = matching.length === 0;
        const pages = Math.max(1, Math.ceil(matching.length / pageSize));
        page = Math.min(page, pages);
        rows.forEach(row => { row.hidden = true; });
        matching.slice((page - 1) * pageSize, page * pageSize).forEach(row => { row.hidden = false; });
        root.querySelector('#risk-empty').hidden = matching.length > 0;
        root.querySelector('#risk-page-summary').textContent = matching.length ? 'Showing ' + ((page - 1) * pageSize + 1) + '–' + Math.min(page * pageSize, matching.length) + ' of ' + matching.length + ' products' : 'No products to show';
        root.querySelector('#risk-page-status').textContent = 'Page ' + page + ' of ' + pages;
        const numbers = root.querySelector('#risk-page-numbers');
        RetailMindUI.renderPageButtons(numbers, page, pages, target => { page = target; render(); }, false);
        previous.disabled = page === 1;
        next.disabled = page === pages;
    }
    search.addEventListener('input', () => { page = 1; render(); });
    stockFilter.addEventListener('change', () => { page = 1; render(); });
    root.addEventListener('retailmind:stockmovementopen', renderCharts);
    window.addEventListener('retailmind:themechange', () => { renderCharts(); if (drawer.open) renderDrawerChart(); });
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
        renderDrawerChart();
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
        drawerChart?.destroy();
        drawerChart = null;
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
        const viewport = report.document.createElement('meta');
        viewport.name = 'viewport';
        viewport.content = 'width=device-width, initial-scale=1';
        report.document.head.append(viewport);
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
        const matching = matchingRows();
        snapshot.querySelectorAll('tbody').forEach(tableBody => {
            const reportRows = new Map(Array.from(tableBody.rows, row => [row.dataset.productId, row]));
            tableBody.replaceChildren(...matching.map(row => reportRows.get(row.dataset.productId)));
        });
        const generated = new Intl.DateTimeFormat('en-PH', {dateStyle:'medium', timeStyle:'short', timeZone:'Asia/Manila'}).format(new Date()) + ' PHT';
        snapshot.querySelector('.sr-report-stamp strong').textContent = generated;
        snapshot.querySelector('.sr-report-scope').textContent = stockFilter.selectedOptions[0].textContent + ' · Search: ' + (search.value.trim() || 'All') + ' · ' + matching.length + ' products · Quantities in base units';
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
