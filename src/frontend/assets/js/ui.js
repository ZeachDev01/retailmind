(function () {
    'use strict';

    const RM = window.RetailMindUI = window.RetailMindUI || {};

    function qs(selector, root) { return (root || document).querySelector(selector); }
    function qsa(selector, root) { return Array.from((root || document).querySelectorAll(selector)); }

    RM.toast = function (message, type, title, duration) {
        const stack = qs('#rm-toast-stack') || (() => {
            const node = document.createElement('div');
            node.id = 'rm-toast-stack';
            node.className = 'rm-toast-stack';
            node.setAttribute('aria-live', 'polite');
            document.body.appendChild(node);
            return node;
        })();
        const kind = type || 'info';
        const icons = { success: 'bi-check-circle-fill', error: 'bi-exclamation-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
        const labels = { success: 'Success', error: 'Unable to continue', warning: 'Attention', info: 'Update' };
        const toast = document.createElement('div');
        toast.className = 'rm-toast ' + kind;
        toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        toast.innerHTML = '<i class="bi ' + (icons[kind] || icons.info) + ' rm-toast-icon" aria-hidden="true"></i>' +
            '<div class="rm-toast-copy"><strong></strong><span></span></div>' +
            '<button type="button" class="rm-toast-close" aria-label="Dismiss notification"><i class="bi bi-x-lg"></i></button>';
        qs('strong', toast).textContent = title || labels[kind] || labels.info;
        qs('span', toast).textContent = String(message || '');
        const remove = () => { toast.classList.remove('show'); setTimeout(() => toast.remove(), 250); };
        qs('.rm-toast-close', toast).addEventListener('click', remove);
        stack.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(remove, Number(duration || 4800));
        return toast;
    };

    RM.openOverlay = function (overlay) {
        if (!overlay) return;
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        const focusable = qs('input:not([type="hidden"]), select, textarea, button, a[href]', overlay);
        if (focusable) setTimeout(() => focusable.focus(), 30);
    };

    RM.closeOverlay = function (overlay) {
        if (!overlay) return;
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
        if (!qs('.command-overlay.open, .rm-modal-overlay.open, .rm-drawer-overlay.open, .checkout-modal.open, .user-modal-overlay.open, .user-drawer-overlay.open')) {
            document.body.classList.remove('no-scroll');
        }
    };

    RM.confirm = function (options) {
        const opts = options || {};
        return new Promise(resolve => {
            const overlay = document.createElement('div');
            overlay.className = 'rm-modal-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.innerHTML = '<section class="rm-modal" role="dialog" aria-modal="true" aria-labelledby="rm-confirm-title">' +
                '<header class="rm-modal-header"><div><h2 id="rm-confirm-title"></h2><p></p></div><button class="rm-close" type="button" aria-label="Close"><i class="bi bi-x-lg"></i></button></header>' +
                '<div class="rm-modal-body"></div><footer class="rm-modal-actions"><button type="button" class="btn btn-quiet rm-cancel">Cancel</button><button type="button" class="btn rm-accept">Confirm</button></footer></section>';
            qs('h2', overlay).textContent = opts.title || 'Confirm action';
            qs('.rm-modal-header p', overlay).textContent = opts.subtitle || 'Review this action before continuing.';
            qs('.rm-modal-body', overlay).textContent = opts.message || 'Are you sure you want to continue?';
            const accept = qs('.rm-accept', overlay);
            accept.textContent = opts.confirmText || 'Confirm';
            if (opts.danger) accept.classList.add('btn-danger');
            const finish = value => { RM.closeOverlay(overlay); setTimeout(() => overlay.remove(), 200); resolve(value); };
            qs('.rm-close', overlay).addEventListener('click', () => finish(false));
            qs('.rm-cancel', overlay).addEventListener('click', () => finish(false));
            accept.addEventListener('click', () => finish(true));
            overlay.addEventListener('click', event => { if (event.target === overlay) finish(false); });
            document.body.appendChild(overlay);
            RM.openOverlay(overlay);
        });
    };

    function initQueryToasts() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('success')) RM.toast(params.get('success'), 'success');
        if (params.get('error')) RM.toast(params.get('error'), 'error');
        if (params.get('warning')) RM.toast(params.get('warning'), 'warning');
    }

    function initCommandPalette() {
        const overlay = qs('#commandPalette');
        const input = qs('#commandSearch');
        const results = qs('#commandResults');
        if (!overlay || !input || !results) return;
        const rawItems = qsa('#appSidebar a[href]').filter(link => !link.classList.contains('sidebar-logout')).map(link => ({
            label: (link.textContent || '').trim().replace(/\s+/g, ' '),
            href: link.href,
            icon: (qs('i', link) || {}).className || 'bi bi-arrow-right',
            section: (link.closest('.sidebar-section') && qs('.sidebar-section-title', link.closest('.sidebar-section')) || {}).textContent || 'Navigation'
        }));
        const unique = rawItems.filter((item, index, all) => item.label && all.findIndex(candidate => candidate.href === item.href) === index);
        let activeIndex = 0;
        let visibleItems = [];
        let productItems = [];
        let productSearchTimer = null;
        const productsApi = overlay.dataset.productsApi || '';
        const productTarget = overlay.dataset.productTarget || '';

        function render() {
            const term = input.value.trim().toLowerCase();
            const navigationItems = unique.filter(item => !term || (item.label + ' ' + item.section).toLowerCase().includes(term));
            visibleItems = [...productItems, ...navigationItems].filter((item, index, all) => all.findIndex(candidate => candidate.href === item.href && candidate.label === item.label) === index).slice(0, 12);
            activeIndex = Math.min(activeIndex, Math.max(0, visibleItems.length - 1));
            if (!visibleItems.length) {
                results.innerHTML = '<div class="command-empty"><i class="bi bi-search"></i><br>No matching page found.</div>';
                return;
            }
            results.innerHTML = visibleItems.map((item, index) => '<a class="command-result ' + (index === activeIndex ? 'active' : '') + '" href="' + item.href + '" data-index="' + index + '">' +
                '<i class="' + item.icon + '"></i><span class="command-result-copy"><strong></strong><span></span></span></a>').join('');
            qsa('.command-result', results).forEach((node, index) => {
                qs('strong', node).textContent = visibleItems[index].label;
                qs('.command-result-copy span', node).textContent = visibleItems[index].section.trim();
            });
        }
        function open() { RM.openOverlay(overlay); input.value = ''; render(); }
        function close() { RM.closeOverlay(overlay); }
        qsa('[data-command-open]').forEach(button => button.addEventListener('click', open));
        qsa('[data-command-close]', overlay).forEach(button => button.addEventListener('click', close));
        overlay.addEventListener('click', event => { if (event.target === overlay) close(); });
        input.addEventListener('input', function () {
            const term = input.value.trim();
            productItems = [];
            render();
            clearTimeout(productSearchTimer);
            if (term.length < 2 || !productsApi || !productTarget) return;
            productSearchTimer = setTimeout(async function () {
                try {
                    const response = await fetch(productsApi + '?scope=all&q=' + encodeURIComponent(term) + '&limit=6');
                    const data = await response.json();
                    productItems = data.success ? (data.products || []).map(product => ({
                        label: product.name || product.product_name || 'Product',
                        href: productTarget + '?q=' + encodeURIComponent(product.sku || product.barcode || product.name || term),
                        icon: 'bi bi-box-seam',
                        section: 'Product · ' + (product.sku || product.barcode || 'No code')
                    })) : [];
                } catch (error) { productItems = []; }
                render();
            }, 220);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') { event.preventDefault(); activeIndex = Math.min(visibleItems.length - 1, activeIndex + 1); render(); }
            if (event.key === 'ArrowUp') { event.preventDefault(); activeIndex = Math.max(0, activeIndex - 1); render(); }
            if (event.key === 'Enter' && visibleItems[activeIndex]) { window.location.href = visibleItems[activeIndex].href; }
        });
        document.addEventListener('keydown', event => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); overlay.classList.contains('open') ? close() : open(); }
            if (event.key === 'Escape' && overlay.classList.contains('open')) close();
        });
    }

    function initConnectionStatus() {
        const status = qs('#rm-connection-status');
        if (!status) return;
        function update() {
            const online = navigator.onLine;
            status.classList.toggle('offline', !online);
            status.textContent = online ? 'Online' : 'Offline';
            status.title = online ? 'Connection available' : 'Connection unavailable. Unsaved actions may fail.';
            if (!online) RM.toast('Internet connection was lost. Keep this page open and retry when online.', 'warning', 'You are offline', 6500);
        }
        window.addEventListener('online', () => { update(); RM.toast('Connection restored.', 'success'); });
        window.addEventListener('offline', update);
        update();
    }

    function initSidebarState() {
        const collapse = qs('#sidebarCollapse');
        const sidebar = qs('#appSidebar');
        const profile = qs('#sidebarProfile');
        const profileMenu = qs('#sidebarProfileMenu');
        if (collapse && sidebar) {
            const stored = localStorage.getItem('retailmind_sidebar_collapsed') === '1';
            if (stored && window.innerWidth > 900) document.body.classList.add('sidebar-collapsed');
            collapse.addEventListener('click', () => {
                document.body.classList.toggle('sidebar-collapsed');
                const collapsed = document.body.classList.contains('sidebar-collapsed');
                localStorage.setItem('retailmind_sidebar_collapsed', collapsed ? '1' : '0');
                collapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
                const icon = qs('i', collapse);
                if (icon) icon.className = 'bi ' + (collapsed ? 'bi-layout-sidebar-inset' : 'bi-layout-sidebar-inset-reverse');
            });
        }
        if (profile && profileMenu) {
            profile.addEventListener('click', () => {
                profileMenu.classList.toggle('open');
                profile.setAttribute('aria-expanded', profileMenu.classList.contains('open') ? 'true' : 'false');
            });
        }
    }

    function initDialogAccessibility() {
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') return;
            const openOverlay = qs('.rm-drawer-overlay.open, .rm-modal-overlay.open');
            if (openOverlay) RM.closeOverlay(openOverlay);
        });
        qsa('form[data-required-field]').forEach(form => {
            form.addEventListener('submit', event => {
                const field = form.elements[form.dataset.requiredField];
                if (field && !String(field.value || '').trim()) {
                    event.preventDefault();
                    field.focus();
                    RM.toast(form.dataset.requiredMessage || 'Complete the required field before continuing.', 'error');
                }
            });
        });
        qsa('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', async event => {
                if (event.defaultPrevented || form.dataset.confirmed === '1') return;
                event.preventDefault();
                const ok = await RM.confirm({
                    title: form.dataset.confirmTitle || 'Confirm action',
                    message: form.dataset.confirm || 'Continue with this action?',
                    confirmText: form.dataset.confirmButton || 'Continue',
                    danger: form.dataset.confirmDanger === '1'
                });
                if (ok) { form.dataset.confirmed = '1'; form.submit(); }
            });
        });
    }

    function initSmartTables() {
        qsa('.main-content .table-wrap table').forEach((table, tableIndex) => {
            if (table.matches('.data-table, .cart-table, .receipt-table, [data-no-smart-table]') || table.closest('#product-table')) return;
            const body = table.tBodies[0];
            if (!body) return;
            const rows = qsa('tr', body).filter(row => row.querySelector('td'));
            if (rows.length < 8) return;
            const wrap = table.closest('.table-wrap');
            if (!wrap || wrap.previousElementSibling?.classList.contains('auto-table-toolbar')) return;

            const toolbar = document.createElement('div');
            toolbar.className = 'table-toolbar auto-table-toolbar';
            toolbar.innerHTML = '<label class="toolbar-search"><i class="bi bi-search"></i><input type="search" placeholder="Search this table" aria-label="Search table"></label>' +
                '<button type="button" class="btn btn-quiet btn-icon auto-table-clear"><i class="bi bi-x-circle"></i>Clear</button>' +
                '<span class="toolbar-count"></span>';
            wrap.parentNode.insertBefore(toolbar, wrap);
            const footer = document.createElement('div');
            footer.className = 'table-pagination auto-table-pagination';
            footer.innerHTML = '<span class="auto-page-summary"></span><label>Rows <select aria-label="Rows per page"><option>10</option><option selected>25</option><option>50</option><option>100</option></select></label><div class="pagination-buttons"></div>';
            wrap.insertAdjacentElement('afterend', footer);
            const search = qs('input', toolbar);
            const count = qs('.toolbar-count', toolbar);
            const pageSize = qs('select', footer);
            const summary = qs('.auto-page-summary', footer);
            const buttons = qs('.pagination-buttons', footer);
            let page = 1;
            let filtered = rows.slice();

            function render(reset = false) {
                if (reset) page = 1;
                const term = search.value.trim().toLowerCase();
                filtered = rows.filter(row => !term || row.textContent.toLowerCase().includes(term));
                const size = Number(pageSize.value || 25);
                const pages = Math.max(1, Math.ceil(filtered.length / size));
                page = Math.min(page, pages);
                const start = (page - 1) * size;
                const visible = new Set(filtered.slice(start, start + size));
                rows.forEach(row => row.hidden = !visible.has(row));
                count.textContent = filtered.length + ' result' + (filtered.length === 1 ? '' : 's');
                summary.textContent = filtered.length ? `Showing ${start + 1}–${Math.min(start + size, filtered.length)} of ${filtered.length}` : 'Showing 0 results';
                buttons.innerHTML = '';
                const add = (label, target, disabled, active) => { const button = document.createElement('button'); button.type='button'; button.textContent=label; button.disabled=disabled; button.classList.toggle('active',!!active); button.addEventListener('click',()=>{page=target;render();}); buttons.appendChild(button); };
                add('‹', Math.max(1,page-1), page===1, false);
                for (let p=Math.max(1,page-2); p<=Math.min(pages,Math.max(1,page-2)+4); p++) add(String(p),p,false,p===page);
                add('›', Math.min(pages,page+1), page===pages, false);
            }
            search.addEventListener('input', () => render(true));
            pageSize.addEventListener('change', () => render(true));
            qs('.auto-table-clear', toolbar).addEventListener('click', () => { search.value=''; render(true); search.focus(); });
            render();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initQueryToasts();
        initCommandPalette();
        initConnectionStatus();
        initSidebarState();
        initDialogAccessibility();
        initSmartTables();
    });
})();
