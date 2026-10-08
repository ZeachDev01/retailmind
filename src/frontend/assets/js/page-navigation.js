(() => {
    'use strict';
    if (window.RetailMindNavigation) return;
    const workspace = document.currentScript.dataset.workspace;
    const nativeAdd = EventTarget.prototype.addEventListener;
    const nativeRemove = EventTarget.prototype.removeEventListener;
    const nativeSubmit = HTMLFormElement.prototype.submit;
    const nativeFetch = window.fetch.bind(window);
    let page = {listeners: [], timers: [], ready: [], abort: new AbortController()};
    let owner = null;
    let initializing = false;
    let queued;
    let pending;
    let sequence = 0;
    let currentURL = location.href;
    const loaded = new Set(Array.from(document.scripts, script => script.src).filter(Boolean));
    const pageNodes = [];
    const pageAssets = [];

    // Legacy pages register document handlers and timers; give them the lifetime of their content.
    function currentOwner() {
        return owner || (document.currentScript?.hasAttribute('data-rm-page') ? page : null);
    }
    function run(scope, callback, target, args) {
        if (scope?.abort.signal.aborted) return;
        const previous = owner;
        owner = scope;
        try { return callback.apply(target, args); }
        finally { owner = previous; }
    }
    EventTarget.prototype.addEventListener = function (type, callback, options) {
        const scope = currentOwner();
        if (!scope || !callback) return nativeAdd.call(this, type, callback, options);
        const existing = scope.listeners.find(item => item.target === this && item.type === type && item.callback === callback && item.capture === !!(typeof options === 'boolean' ? options : options?.capture));
        if (existing) return;
        const wrapped = function (...args) {
            if (options?.once) scope.listeners = scope.listeners.filter(item => item.wrapped !== wrapped);
            return run(scope, typeof callback === 'function' ? callback : callback.handleEvent, typeof callback === 'function' ? this : callback, args);
        };
        scope.listeners.push({target: this, type, callback, wrapped, options, capture: !!(typeof options === 'boolean' ? options : options?.capture)});
        if (initializing && ((this === document && type === 'DOMContentLoaded') || (this === window && type === 'load'))) {
            scope.ready.push(() => wrapped.call(this, new Event(type)));
            return;
        }
        return nativeAdd.call(this, type, wrapped, options);
    };
    EventTarget.prototype.removeEventListener = function (type, callback, options) {
        const capture = !!(typeof options === 'boolean' ? options : options?.capture);
        const item = page.listeners.find(item => item.target === this && item.type === type && item.callback === callback && item.capture === capture);
        if (item) page.listeners.splice(page.listeners.indexOf(item), 1);
        return nativeRemove.call(this, type, item?.wrapped || callback, options);
    };
    for (const [set, clear] of [['setTimeout', 'clearTimeout'], ['setInterval', 'clearInterval'], ['requestAnimationFrame', 'cancelAnimationFrame']]) {
        const schedule = window[set].bind(window);
        window[set] = function (callback, ...args) {
            const scope = currentOwner();
            const id = schedule(scope && typeof callback === 'function' ? function (...values) { return run(scope, callback, this, values); } : callback, ...args);
            if (scope) scope.timers.push(() => window[clear](id));
            return id;
        };
    }

    function cleanup(retainedAssets) {
        for (const item of page.listeners.filter(item => item.type === 'pagehide')) item.wrapped.call(item.target, new Event('pagehide'));
        page.abort.abort();
        page.listeners.forEach(item => nativeRemove.call(item.target, item.type, item.wrapped, item.options));
        page.timers.forEach(cancel => cancel());
        Object.values(window.Chart?.instances || {}).forEach(chart => chart.destroy());
        if (window.DataTable?.tables) window.DataTable.tables({api: true}).destroy();
        document.querySelectorAll('video').forEach(video => video.srcObject?.getTracks().forEach(track => track.stop()));
        const themeNotice = document.querySelector('.theme-save-alert');
        if (themeNotice) document.body.append(themeNotice);
        pageNodes.splice(0).forEach(node => node.remove());
        pageAssets.splice(0).forEach(node => { if (!retainedAssets.includes(node)) node.remove(); });
        page = {listeners: [], timers: [], ready: [], abort: new AbortController()};
        window.__retailMindActionInProgress = false;
    }

    function eligible(url) {
        return url.origin === location.origin && /^https?:$/.test(url.protocol) &&
            !/\/auth\/(?:logout|login|change_password|reset_password|forgot_password)\.php$/.test(url.pathname);
    }
    function activeMenu(url) {
        document.querySelectorAll('#appSidebar a[href]').forEach(link => {
            const active = new URL(link.href).pathname === url.pathname;
            link.classList.toggle('active', active);
            if (active) {
                link.setAttribute('aria-current', 'page');
                const dropdown = link.closest('.dropdown');
                dropdown?.classList.add('open');
                dropdown?.querySelector('.dropbtn')?.setAttribute('aria-expanded', 'true');
            } else link.removeAttribute('aria-current');
        });
    }
    function status(content, error = false) {
        content.querySelector('[data-navigation-status]')?.remove();
        const notice = document.createElement('div');
        notice.dataset.navigationStatus = '';
        notice.className = 'page-navigation-status';
        notice.setAttribute('role', error ? 'alert' : 'status');
        if (!error) {
            const spinner = document.createElement('span');
            spinner.className = 'page-navigation-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            notice.append(spinner);
        }
        notice.append(document.createTextNode(error ? 'This page could not be loaded completely. Check your connection and try the navigation again.' : 'Loading…'));
        content.prepend(notice);
        content.setAttribute('aria-busy', error ? 'false' : 'true');
    }
    function once(url) {
        return url.origin !== location.origin || /\/(?:theme|ui|page-navigation|cart-workspace|backup_status|checkout-attempt|checkout-quote)\.js$/.test(url.pathname);
    }
    async function execute(script, url) {
        if (script.type && !['text/javascript', 'application/javascript', 'module'].includes(script.type)) return;
        const source = script.getAttribute('src');
        const assetURL = source && new URL(source, url);
        if (assetURL && once(assetURL) && loaded.has(assetURL.href)) return;
        const node = document.createElement('script');
        for (const attr of script.attributes) if (!['src', 'async', 'defer'].includes(attr.name)) node.setAttribute(attr.name, attr.value);
        node.setAttribute('data-rm-page', '');
        if (assetURL) {
            node.src = assetURL.href;
            node.async = false;
                await new Promise((resolve, reject) => {
                node.onload = resolve;
                node.onerror = () => reject(new Error('Page script unavailable'));
                document.body.append(node);
            });
            loaded.add(assetURL.href);
        } else {
            node.textContent = script.textContent;
            document.body.append(node);
        }
        pageNodes.push(node);
    }
    async function navigate(destination, options = {}) {
        if (initializing) { queued = {destination, options}; return; }
        const url = new URL(destination, location.href);
        if (!eligible(url)) { location.assign(url.href); return; }
        const content = document.querySelector('.main-content');
        if (!content) { location.assign(url.href); return; }
        pending?.abort();
        pending = new AbortController();
        const request = ++sequence;
        status(content);
        let committed = false;
        try {
            const response = await nativeFetch(url.href, {
                ...options.fetch, signal: pending.signal, credentials: 'same-origin', cache: 'no-store',
                headers: {'X-RetailMind-Navigation': '1', ...options.fetch?.headers},
            });
            if (request !== sequence) return;
            if (response.headers.get('Content-Disposition')?.includes('attachment')) {
                const blob = URL.createObjectURL(await response.blob());
                const link = document.createElement('a');
                link.href = blob;
                link.download = response.headers.get('Content-Disposition').match(/filename="?([^";]+)/)?.[1] || 'download';
                link.click();
                setTimeout(() => URL.revokeObjectURL(blob), 1000);
                return;
            }
            if (!response.ok) throw new Error('Page unavailable');
            if (!response.headers.get('Content-Type')?.includes('application/vnd.retailmind.page+json')) {
                // Authentication gates and standalone documents deliberately leave the application shell.
                if (response.redirected || !eligible(new URL(response.url))) { location.assign(response.url); return; }
                throw new Error('Unexpected page response');
            }
            const payload = await response.json();
            if (request !== sequence) return;
            if (payload.role !== workspace) { location.assign(response.url); return; }
            const incoming = new DOMParser().parseFromString(payload.html, 'text/html');
            const main = incoming.querySelector('.main-content');
            if (!main) throw new Error('Missing content');
            const finalURL = new URL(response.url);
            const scripts = Array.from(incoming.querySelectorAll('script')).filter(script => !script.type || ['text/javascript', 'application/javascript', 'module'].includes(script.type));
            const headScripts = scripts.filter(script => incoming.head.contains(script));
            scripts.forEach(script => script.remove());
            const assets = Array.from(incoming.querySelectorAll('style, link[rel="stylesheet"]'));
            // Resolve assets before changing the URL and wait for CSS before revealing the new page.
            const nextAssets = [];
            await Promise.all(assets.map(async asset => {
                if (asset.href) asset.setAttribute('href', new URL(asset.getAttribute('href'), finalURL).href);
                const existing = asset.href && Array.from(document.querySelectorAll('link[rel="stylesheet"]')).find(link => link.href === asset.href);
                asset.remove();
                if (existing) { if (pageAssets.includes(existing)) nextAssets.push(existing); return; }
                const node = document.importNode(asset, true);
                nextAssets.push(node);
                if (node.tagName === 'LINK') {
                    await new Promise((resolve, reject) => {
                        node.onload = resolve;
                        node.onerror = () => reject(new Error('Page stylesheet unavailable'));
                        document.head.append(node);
                    });
                } else document.head.append(node);
            })).catch(error => { nextAssets.filter(node => !pageAssets.includes(node)).forEach(node => node.remove()); throw error; });
            if (request !== sequence) { nextAssets.filter(node => !pageAssets.includes(node)).forEach(node => node.remove()); return; }
            cleanup(nextAssets);
            pageAssets.push(...nextAssets);
            const persistentClasses = ['sidebar-collapsed', 'no-scroll'].filter(name => document.body.classList.contains(name));
            document.body.className = incoming.body.className;
            document.body.classList.add(...persistentClasses);
            for (const attr of Array.from(document.body.attributes)) {
                if (attr.name.startsWith('data-') && !['data-rm-authenticated', 'data-rm-backup-status'].includes(attr.name)) document.body.removeAttribute(attr.name);
            }
            for (const attr of incoming.body.attributes) if (attr.name.startsWith('data-')) document.body.setAttribute(attr.name, attr.value);
            const replacement = document.importNode(main, true);
            replacement.id = 'page-content';
            content.replaceWith(replacement);
            // Some legacy pages keep dialogs after </main>; move those with the page as well.
            main.remove();
            incoming.querySelector('.app-shell')?.replaceWith(...incoming.querySelector('.app-shell').childNodes);
            for (const child of Array.from(incoming.body.childNodes)) {
                if (child.nodeType === Node.TEXT_NODE && !child.textContent.trim()) continue;
                const node = document.importNode(child, true);
                document.body.append(node);
                pageNodes.push(node);
            }
            document.title = incoming.title;
            if (options.history === 'none') history.replaceState({}, '', finalURL.href);
            else history.pushState({}, '', finalURL.href);
            currentURL = finalURL.href;
            activeMenu(finalURL);
            const profile = document.querySelector('.sidebar-profile-copy strong');
            if (profile && payload.shell?.name) profile.textContent = payload.shell.name;
            const avatar = document.querySelector('#sidebarProfile .sidebar-avatar');
            if (avatar && payload.shell?.avatar) avatar.outerHTML = payload.shell.avatar;
            const mobileAvatar = document.querySelector('.admin-mobile-avatar');
            if (mobileAvatar && payload.shell?.mobileAvatar) mobileAvatar.innerHTML = payload.shell.mobileAvatar;
            const notification = document.querySelector('.global-notification-button');
            if (notification && Number.isFinite(payload.shell?.notifications)) {
                notification.querySelector('strong')?.remove();
                const count = payload.shell.notifications;
                notification.setAttribute('aria-label', 'Notifications' + (count ? ': ' + count + ' unread' : ''));
                if (count) { const badge = document.createElement('strong'); badge.textContent = count > 99 ? '99+' : count; notification.append(badge); }
            }
            committed = true;
            initializing = true;
            for (const script of headScripts.filter(script => !script.hasAttribute('defer'))) await execute(script, finalURL);
            if (payload.cart && window.cartWorkspace) Object.assign(window.cartWorkspace, new CartWorkspace(payload.cart));
            for (const script of scripts.filter(script => !headScripts.includes(script) && !script.hasAttribute('defer'))) await execute(script, finalURL);
            for (const script of scripts.filter(script => script.hasAttribute('defer'))) await execute(script, finalURL);
            for (const ready of page.ready.splice(0)) ready();
            run(page, () => window.RetailMindUI?.initPageContent(), window, []);
            Object.entries(payload.flash || {}).forEach(([kind, message]) => window.RetailMindUI?.queueAlert(message, kind));
            initializing = false;
            currentURL = location.href;
            window.dispatchEvent(new CustomEvent('retailmind:pagechange', {detail: {url: finalURL.href}}));
            replacement.scrollTop = 0;
            window.scrollTo(0, 0);
        } catch (error) {
            if (request !== sequence || error.name === 'AbortError') return;
            initializing = false;
            if (options.history === 'none' && !committed) history.replaceState({}, '', currentURL);
            status(document.querySelector('.main-content'), true);
            console.error('Page navigation failed:', error);
        } finally {
            if (request === sequence) {
                const main = document.querySelector('.main-content');
                main?.setAttribute('aria-busy', 'false');
                if (!main?.querySelector('[role="alert"][data-navigation-status]')) main?.querySelector('[data-navigation-status]')?.remove();
            }
            if (queued && !initializing) {
                const next = queued;
                queued = null;
                navigate(next.destination, next.options);
            }
        }
    }

    function submit(form, submitter) {
        const target = submitter?.getAttribute('formtarget') || form.target;
        const url = new URL(submitter?.getAttribute('formaction') || form.getAttribute('action') || location.href, location.href);
        const method = (submitter?.getAttribute('formmethod') || form.method || 'get').toLowerCase();
        if ((target && target !== '_self') || !eligible(url) || !['get', 'post'].includes(method)) return false;
        const data = submitter ? new FormData(form, submitter) : new FormData(form);
        const params = new URLSearchParams();
        for (const [key, value] of data) params.append(key, value instanceof File ? value.name : value);
        if (method === 'get') { url.search = params.toString(); navigate(url.href); }
        else {
            const enctype = submitter?.getAttribute('formenctype') || form.enctype;
            navigate(url.href, {fetch: {method: 'POST', body: enctype === 'multipart/form-data' ? data : params}});
        }
        return true;
    }
    HTMLFormElement.prototype.submit = function () {
        if (!submit(this)) nativeSubmit.call(this);
    };
    window.RetailMindNavigation = {navigate, initializePage: callback => run(page, callback, window, [])};
    // Kept for existing cart-workspace callers; forms now use the shared fetch submission.
    window.rmPrepareFullscreenForm = () => {};
    window.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-no-navigation') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href);
        if (!eligible(url) || !url.pathname.endsWith('.php')) return;
        if (url.pathname === location.pathname && url.search === location.search && url.hash) return;
        event.preventDefault();
        navigate(url.href);
    });
    window.addEventListener('submit', event => {
        if (!event.defaultPrevented && submit(event.target, event.submitter)) event.preventDefault();
    });
    window.addEventListener('popstate', () => navigate(location.href, {history: 'none'}));
    document.addEventListener('DOMContentLoaded', () => {
        const content = document.querySelector('.main-content');
        if (content) content.id = 'page-content';
        document.querySelectorAll('script[src]').forEach(script => loaded.add(script.src));
        pageAssets.push(...document.querySelectorAll('style[data-rm-page-asset], link[data-rm-page-asset][rel="stylesheet"]'));
        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT);
        let end;
        while (walker.nextNode()) if (walker.currentNode.textContent === 'rm-shell-end') end = walker.currentNode;
        if (end) {
            for (let node = end.nextSibling; node; node = node.nextSibling) if (node !== content) pageNodes.push(node);
            for (const node of document.body.childNodes) if (!node.contains(end) && node !== content && !node.matches?.('.theme-save-alert, #rm-toast-stack')) pageNodes.push(node);
        }
        history.replaceState({}, '', location.href);
        activeMenu(new URL(location.href));
    });
})();
