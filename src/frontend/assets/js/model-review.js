(() => {
    const metrics = document.querySelector('[data-review-metrics]');
    const horizonSwitch = metrics?.querySelector('.model-review-switch');
    if (horizonSwitch) {
        const buttons = [...horizonSwitch.querySelectorAll('[data-review-horizon]')];
        const periods = [...metrics.querySelectorAll('[data-review-period]')];
        function showHorizon(horizon) {
            buttons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.reviewHorizon === horizon)));
            periods.forEach(period => { period.hidden = period.dataset.reviewPeriod !== horizon; });
        }
        horizonSwitch.hidden = false;
        showHorizon('28');
        horizonSwitch.addEventListener('click', event => {
            const button = event.target.closest('[data-review-horizon]');
            if (button) showHorizon(button.dataset.reviewHorizon);
        });
    }

    const products = document.querySelector('[data-review-products]');
    const search = products?.querySelector('#review-product-search');
    if (!search) return;

    const table = products.querySelector('table');
    const rows = [...table.querySelectorAll('[data-review-product]')];
    const pagination = products.querySelector('.table-pagination');
    const nav = pagination.querySelector('nav');
    const count = pagination.querySelector('[data-review-page-count]');
    const sortHeading = table.querySelector('[data-review-sort-heading]');
    const sort = sortHeading.querySelector('button');
    const noMatches = products.querySelector('.model-review-empty');
    let pageSize = 15;
    let page = 1;
    let order = 0;
    let openProduct = null;
    RetailMindUI.tablePageSize(pagination, pageSize, size => { pageSize = size; page = 1; render(); });

    function closeProduct() {
        if (!openProduct) return;
        openProduct.setAttribute('aria-expanded', 'false');
        document.getElementById(openProduct.getAttribute('aria-controls')).hidden = true;
        openProduct = null;
    }

    function render() {
        closeProduct();
        const query = search.value.trim().toLowerCase();
        const matches = rows.filter(row => row.dataset.reviewProduct.toLowerCase().includes(query));
        if (order) {
            matches.sort((left, right) => {
                const a = left.dataset.difference;
                const b = right.dataset.difference;
                if (a === '' || b === '') return Number(a === '') - Number(b === '');
                return order * (Number(a) - Number(b));
            });
        }
        const pages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(page, pages);
        const start = (page - 1) * pageSize;
        rows.forEach(row => { row.hidden = true; });
        matches.slice(start, start + pageSize).forEach(row => {
            row.hidden = false;
            table.append(row);
        });
        noMatches.hidden = matches.length > 0;
        count.textContent = matches.length
            ? (start + 1) + '–' + Math.min(start + pageSize, matches.length) + ' of ' + matches.length + ' products'
            : '0 products';
        nav.hidden = false;
        RetailMindUI.renderPageButtons(nav, page, pages, target => { page = target; render(); });
    }

    rows.forEach(row => {
        const toggle = row.querySelector('.model-review-product-toggle');
        if (!toggle) return;
        toggle.hidden = false;
        row.querySelector('[data-review-product-label]').hidden = true;
    });
    search.closest('.model-review-product-search').hidden = false;
    sortHeading.querySelector('span').hidden = true;
    sort.hidden = false;
    pagination.hidden = false;
    search.addEventListener('input', () => {
        page = 1;
        render();
    });
    sort.addEventListener('click', () => {
        order = order === -1 ? 1 : -1;
        sortHeading.setAttribute('aria-sort', order === -1 ? 'descending' : 'ascending');
        sort.querySelector('i').className = order === -1 ? 'bi bi-sort-down' : 'bi bi-sort-up';
        page = 1;
        render();
    });
    table.addEventListener('click', event => {
        const toggle = event.target.closest('.model-review-product-toggle');
        if (!toggle) return;
        const wasOpen = toggle === openProduct;
        closeProduct();
        if (!wasOpen) {
            openProduct = toggle;
            toggle.setAttribute('aria-expanded', 'true');
            document.getElementById(toggle.getAttribute('aria-controls')).hidden = false;
        }
    });
    render();
})();
