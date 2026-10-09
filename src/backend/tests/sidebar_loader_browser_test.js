const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        let requests = 0;
        const fixture = name => `<!doctype html><html><head><title>${name}</title></head><body>
            <nav id="appSidebar"><a href="/pages/one">One</a><a href="/pages/two?q=2">Two</a>
            <a href="/pages/broken">Broken</a><a href="/pages/slow">Slow</a>
            <a href="/auth/logout.php">Logout</a></nav>
            <button data-fullscreen-toggle><i></i><span>Full Screen</span></button>
            <main class="main-content"><h1>${name}</h1><button id="counter">Count</button>
            <form action="/pages/form" method="post"><button>Save</button></form></main>
            <script src="/ui.js"></script><script src="/sidebar-loader.js"></script>
            <script>const pageName = ${JSON.stringify(name)};
            document.addEventListener('DOMContentLoaded', () => {
                document.querySelector('#counter').onclick = () => document.querySelector('h1').textContent = pageName + ' clicked';
                document.body.dataset.ready = location.pathname + location.search;
            });</script></body></html>`;
        await page.route('http://retailmind.test/**', async route => {
            const url = new URL(route.request().url());
            if (url.pathname.endsWith('.js')) return route.fulfill({contentType:'text/javascript',
                body:fs.readFileSync('src/frontend/assets/js' + url.pathname, 'utf8')});
            if (url.pathname === '/pages/broken') return route.fulfill({status:500, body:'Failed'});
            if (url.pathname === '/pages/slow') await new Promise(resolve => setTimeout(resolve, 300));
            requests++;
            return route.fulfill({contentType:'text/html', body:fixture(url.pathname)});
        });
        await page.goto('http://retailmind.test/pages/one');
        await page.locator('[data-fullscreen-toggle]').click();
        await page.waitForFunction(() => !!document.fullscreenElement);
        await page.evaluate(() => { window.originalDocument = document; });
        await page.locator('#appSidebar a', {hasText:'Two'}).click();
        const workspace = () => page.frames().find(frame => frame !== page.mainFrame());
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body.dataset.ready === '/pages/two?q=2');
        assert.equal(page.url(), 'http://retailmind.test/pages/two?q=2');
        assert.equal(await page.title(), '/pages/two');
        assert.equal(await page.evaluate(() => document === window.originalDocument && !!document.fullscreenElement), true);
        await workspace().locator('#counter').click();
        assert.equal(await workspace().locator('h1').textContent(), '/pages/two clicked');
        assert.equal(await workspace().locator('[data-fullscreen-toggle]').getAttribute('aria-pressed'), 'true');
        assert.deepEqual(await workspace().evaluate(() => {
            const cases = [
                ['#appSidebar a', {ctrlKey:true}],
                ['#appSidebar a[href="/auth/logout.php"]', {}],
            ];
            return cases.map(([selector, options]) => {
                let intercepted;
                document.addEventListener('click', event => {
                    intercepted = event.defaultPrevented;
                    event.preventDefault();
                }, {once:true});
                document.querySelector(selector).dispatchEvent(new MouseEvent('click', {bubbles:true, cancelable:true, ...options}));
                return intercepted;
            });
        }), [false, false], 'Modified clicks and logout retain their normal handlers');

        await workspace().locator('#appSidebar a', {hasText:'Broken'}).click();
        await workspace().locator('[role="alert"]').waitFor();
        assert.equal(page.url(), 'http://retailmind.test/pages/two?q=2');
        assert.equal(await workspace().locator('.main-content').getAttribute('aria-busy'), null);

        await workspace().locator('#appSidebar a', {hasText:'Slow'}).click();
        await workspace().evaluate(() => { RetailMindUI.navigate('one'); });
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body.dataset.ready === '/pages/one');
        await page.goBack();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body.dataset.ready === '/pages/two?q=2');
        await page.goForward();
        await page.waitForFunction(() => document.querySelector('iframe')?.contentDocument?.body.dataset.ready === '/pages/one');
        await page.waitForFunction(() => !!document.fullscreenElement);

        await workspace().locator('[data-fullscreen-toggle]').click();
        await page.waitForFunction(() => !document.fullscreenElement);
        await workspace().locator('[data-fullscreen-toggle]').click();
        await page.waitForFunction(() => !!document.fullscreenElement);
        // Existing forms still navigate in the workspace and update the address bar.
        await workspace().locator('form button').click();
        await page.waitForURL('http://retailmind.test/pages/form');
        await page.waitForFunction(() => !!document.fullscreenElement);
        await page.goBack();
        await page.waitForURL('http://retailmind.test/pages/one');
        await page.goForward();
        await page.waitForURL('http://retailmind.test/pages/form');
        await page.reload();
        assert.equal(await page.locator('h1').textContent(), '/pages/form');
        assert.equal(await page.locator('iframe').count(), 0);
        assert.ok(requests >= 6);
        assert.deepEqual(errors, []);
        console.log('PASS: Fetch sidebar navigation, page scripts, fullscreen, failure, cancellation, history, forms and refresh');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
