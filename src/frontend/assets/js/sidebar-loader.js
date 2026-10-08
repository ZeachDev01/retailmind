(() => {
    'use strict';

    // Keep legacy page scripts in their own document so globals and listeners reset on navigation.
    const host = window.parent !== window && window.parent.RetailMindNavigation
        ? window.parent : window;
    if (host === window) {
        let pending;
        let frame;
        let currentUrl = location.href;
        let lastNotice;

        const navigate = async (url, replace = false) => {
            pending?.abort();
            const request = new AbortController();
            pending = request;
            const source = frame?.contentWindow || window;
            const content = source.document.querySelector('.main-content') || source.document.body;
            content.setAttribute('aria-busy', 'true');
            lastNotice?.remove();
            const notice = source.document.createElement('div');
            lastNotice = notice;
            notice.setAttribute('role', 'status');
            notice.textContent = 'Loading page…';
            notice.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:100000;padding:1rem;background:Canvas;color:CanvasText;border:1px solid;border-radius:.5rem';
            source.document.body.append(notice);
            try {
                const response = await fetch(url, {credentials: 'same-origin', signal: request.signal});
                if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) {
                    throw new Error('Page unavailable');
                }
                const target = new URL(response.url);
                if (target.origin !== location.origin) throw new Error('Unexpected redirect');
                const html = await response.text();
                if (request.signal.aborted) return;
                if (replace) history.replaceState(null, '', target);
                else history.pushState(null, '', target);
                currentUrl = target.href;
                const next = document.createElement('iframe');
                next.title = 'RetailMind workspace';
                next.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;border:0;background:Canvas;z-index:10000';
                // Preserve the fullscreen element (the outer document root).
                document.body.replaceChildren(next);
                frame = next;
                next.addEventListener('load', () => {
                    const page = next.contentDocument;
                    if (!page) return;
                    document.title = page.title;
                    const loadedUrl = next.contentWindow.location.href;
                    if (loadedUrl !== currentUrl && loadedUrl !== 'about:blank') {
                        history.pushState(null, '', loadedUrl);
                        currentUrl = loadedUrl;
                    }
                });
                // document.open inherits the host URL, keeping relative links and location APIs correct.
                const page = next.contentDocument;
                page.open();
                page.write(html);
                page.close();
                next.focus();
            } catch (error) {
                if (error.name === 'AbortError') return;
                if (replace) history.replaceState(null, '', currentUrl);
                notice.setAttribute('role', 'alert');
                notice.textContent = 'Could not load the page. Check your connection and try again.';
                notice.style.cursor = 'pointer';
                notice.title = 'Click to dismiss';
                notice.addEventListener('click', () => notice.remove(), {once: true});
                setTimeout(() => { if (notice.isConnected) notice.remove(); }, 6000);
                return;
            } finally {
                if (pending === request) content.removeAttribute('aria-busy');
                if (request.signal.aborted) notice.remove();
            }
            notice.remove();
        };
        window.RetailMindNavigation = {navigate};
        window.addEventListener('popstate', () => navigate(location.href, true));
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('#appSidebar a[href], .admin-mobile-logo, .global-notification-button');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey
            || event.shiftKey || event.altKey || link.hasAttribute('download')
            || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin || !['http:', 'https:'].includes(url.protocol)
            || url.pathname.includes('/auth/logout.php')
            || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
        event.preventDefault();
        host.RetailMindNavigation.navigate(url.href);
    });
})();
