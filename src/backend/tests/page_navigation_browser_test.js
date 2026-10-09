const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const {spawnSync} = require('node:child_process');
const {chromium, firefox} = require('playwright');
const php = process.env.PHP_BINARY || (process.platform === 'win32' ? 'C:/xampp/php/php.exe' : 'php');
const ui = fs.readFileSync('src/frontend/assets/js/ui.js', 'utf8');
const navigation = fs.readFileSync('src/frontend/assets/js/page-navigation.js', 'utf8');
const css = fs.readFileSync('src/frontend/assets/css/page-navigation.css', 'utf8');
const render = (html, partial) => {
    const result = spawnSync(php, ['src/backend/tests/support/navigation_response_fixture.php', partial ? '1' : ''], {input: html, encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
};

(async () => {
    let submitted;
    const server = http.createServer(async (req, res) => {
        if (req.url === '/navigation.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(navigation); return; }
        if (req.url === '/deferred.js') { res.setHeader('Content-Type', 'text/javascript'); res.end(`document.addEventListener('DOMContentLoaded', () => { document.querySelector('#deferred').textContent = 'Ready'; });`); return; }
        if (req.url.startsWith('/shared.css')) { res.setHeader('Content-Type', 'text/css'); res.end('#control { color: rgb(12, 34, 56); }'); return; }
        if (req.url === '/error.php') { res.writeHead(500); res.end('SQLSTATE: private error'); return; }
        if (req.url === '/expired.php') { res.writeHead(302, {Location: '/login.php'}); res.end(); return; }
        if (req.url === '/login.php') { res.setHeader('Content-Type', 'text/html'); res.end('<h1>Sign in</h1>'); return; }
        if (req.url === '/download.php') { res.writeHead(200, {'Content-Disposition': 'attachment; filename="report.csv"'}); res.end('a,b'); return; }
        if (req.url === '/slow.php') await new Promise(resolve => setTimeout(resolve, 300));
        if (req.method === 'POST') { submitted = ''; for await (const chunk of req) submitted += chunk; }
        const partial = req.headers['x-retailmind-navigation'] === '1';
        const contentTag = req.url.startsWith('/second.php') ? 'div' : 'main';
        const html = `<!doctype html><html><head><title>${req.url}</title><link rel="stylesheet" href="/shared.css"><style>${css}</style><script src="/deferred.js" defer></script></head>
            <body class="page-${req.url.split('.')[0].slice(1)}"><div class="app-shell">
            <!--rm-shell-start--><script src="/navigation.js" data-workspace="admin"></script>
            <aside id="appSidebar" class="open"><a id="first" href="/first.php">First</a><a id="second" href="/second.php">Second</a><a id="slow" href="/slow.php">Slow</a><a id="error" href="/error.php">Error</a><a id="expired" href="/expired.php">Expired</a><a id="download" href="/download.php">Download</a></aside>
            <header id="navbar"><button data-fullscreen-toggle><i></i><span>Full Screen</span></button></header><script>${ui}</script><!--rm-shell-end-->
            <${contentTag} class="main-content" ${req.url.startsWith('/first.php') ? 'id="first-content"' : ''}><h1>${req.url}</h1><span id="deferred"></span><button id="control" onclick="changeControl()">Control</button>
            <form method="post" enctype="multipart/form-data"><input name="csrf" value="token"><input type="file" name="photo"><button name="action" value="save">Save</button></form>
            <form id="get-form" method="get"><input name="search" value="needle"><button>Search</button></form>
            <form id="ajax-form" method="post"><button>Existing AJAX</button></form></${contentTag}>
            <div id="page-modal" class="rm-modal-overlay" aria-hidden="true">Modal ${req.url}</div></div>
            <script>
            const localValue = 'Working';
            function changeControl() { document.querySelector('#control').textContent = localValue; }
            document.addEventListener('test-page-handler', () => window.handlerCalls = (window.handlerCalls || 0) + 1);
            document.addEventListener('DOMContentLoaded', () => window.readyCalls = (window.readyCalls || 0) + 1);
            document.addEventListener('submit', event => { if (event.target.id === 'ajax-form') { event.preventDefault(); window.ajaxHandled = true; } });
            setInterval(() => { window.ticks = (window.ticks || 0) + 1; }, 20);
            </script></body></html>`;
        const output = render(html, partial);
        if (partial) {
            assert(!JSON.parse(output).html.includes('id="appSidebar"'), 'Partial response excludes persistent shell');
            res.setHeader('Content-Type', 'application/vnd.retailmind.page+json');
        } else res.setHeader('Content-Type', 'text/html');
        res.end(output);
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    const origin = `http://127.0.0.1:${server.address().port}`;
    try {
        for (const browserType of process.env.NAVIGATION_FIREFOX === '1' ? [chromium, firefox] : [chromium]) {
            console.log(`Checking navigation in ${process.env.NAVIGATION_CHANNEL || browserType.name()}`);
            const browser = await browserType.launch({headless: true, timeout: 15000, ...(process.env.NAVIGATION_CHANNEL ? {channel: process.env.NAVIGATION_CHANNEL} : {})});
            try {
                for (const fallback of [false, true]) {
                    submitted = undefined;
                    const page = await browser.newPage({viewport: {width: 1280, height: 800}});
                    page.setDefaultTimeout(10000);
                    const errors = [];
                    page.on('pageerror', error => errors.push(error.message));
                    await page.goto(origin + '/first.php');
                    assert.equal(await page.locator('.main-content').getAttribute('id'), 'first-content', 'Initial navigation preserves page anchors');
                    await page.evaluate(() => { window.originalDocument = document; window.originalSidebar = document.querySelector('#appSidebar'); window.originalNavbar = document.querySelector('#navbar'); });
                    if (fallback) await page.evaluate(() => Object.defineProperty(document.documentElement, 'requestFullscreen', {value: () => Promise.reject(new Error('Unavailable'))}));
                    await page.locator('[data-fullscreen-toggle]').click();
                    await page.waitForFunction(() => !!document.fullscreenElement || document.documentElement.classList.contains('rm-app-fullscreen'));
                    const waitPage = path => page.waitForFunction(path => location.pathname + location.search === path && !document.querySelector('[data-navigation-status]'), path);
                    for (const id of ['second', 'first', 'second']) {
                        await page.evaluate(() => RetailMindUI.openOverlay(document.querySelector('#page-modal')));
                        await page.evaluate(() => { RetailMindUI.confirm({message: 'Pending page action'}); });
                        assert(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')));
                        await page.locator('#' + id).click();
                        await waitPage('/' + id + '.php');
                        assert.equal(await page.locator('.main-content').getAttribute('id'), id === 'first' ? 'first-content' : 'page-content', 'Page switching preserves authored IDs and supplies a fallback');
                        assert(await page.evaluate(() => document === originalDocument && document.querySelector('#appSidebar') === originalSidebar && document.querySelector('#navbar') === originalNavbar));
                        assert(await page.evaluate(() => !!document.fullscreenElement || document.documentElement.classList.contains('rm-app-fullscreen')));
                        assert.equal(await page.locator('body').evaluate(node => node.classList.contains('no-scroll')), false, 'Navigation releases removed modal scroll lock');
                        assert.equal(await page.locator('.rm-modal-overlay').count(), 1, 'Navigation removes temporary confirmation nodes');
                        assert.equal(await page.locator('iframe').count(), 0);
                        assert.equal(await page.locator('#page-modal').count(), 1);
                        assert.equal(await page.locator('#appSidebar .active').getAttribute('id'), id);
                        assert(await page.locator('#appSidebar').evaluate(node => node.classList.contains('open')));
                        assert.equal(await page.locator('#deferred').textContent(), 'Ready');
                        await page.locator('#control').click();
                        assert.equal(await page.locator('#control').textContent(), 'Working');
                        assert.equal(await page.locator('#control').evaluate(node => getComputedStyle(node).color), 'rgb(12, 34, 56)');
                        assert.equal(await page.evaluate(() => { window.handlerCalls = 0; document.dispatchEvent(new Event('test-page-handler')); return handlerCalls; }), 1, 'Old document handlers are removed');
                    }
                    await page.goBack(); await waitPage('/first.php');
                    await page.goForward(); await waitPage('/second.php');
                    await page.getByRole('button', {name: 'Existing AJAX', exact: true}).click();
                    assert(await page.evaluate(() => window.ajaxHandled));
                    assert.equal(submitted, undefined, 'Existing AJAX handlers retain submission ownership');
                    await page.locator('input[type=file]').setInputFiles({name: 'photo.txt', mimeType: 'text/plain', buffer: Buffer.from('upload content')});
                    await page.getByRole('button', {name: 'Save', exact: true}).click();
                    await page.waitForFunction(() => window.readyCalls === 7 && !document.querySelector('[data-navigation-status]'));
                    assert(submitted.includes('upload content') && submitted.includes('token') && submitted.includes('save'), 'Multipart upload, CSRF and submitter preserved');
                    await page.locator('#get-form').evaluate(form => form.submit());
                    await waitPage('/second.php?search=needle');
                    const download = page.waitForEvent('download');
                    await page.locator('#download').click();
                    assert.equal((await download).suggestedFilename(), 'report.csv');
                    await page.locator('#error').click();
                    await page.locator('[data-navigation-status][role=alert]').waitFor();
                    assert(!(await page.locator('#page-content').textContent()).includes('SQLSTATE'));
                    assert.equal(new URL(page.url()).search, '?search=needle');
                    await page.locator('#slow').click();
                    await page.locator('[data-navigation-status][role=status]').waitFor();
                    await page.locator('#first').click(); await waitPage('/first.php');
                    assert(await page.evaluate(() => !!document.fullscreenElement || document.documentElement.classList.contains('rm-app-fullscreen')));
                    assert.deepEqual(errors, []);
                    await page.locator('#expired').click();
                    await page.getByRole('heading', {name: 'Sign in'}).waitFor();
                    await page.close();
                }
            } finally { await browser.close(); }
        }
        console.log('Fetch navigation: passed (fullscreen, persistent shell, scripts, history, forms/uploads, CSS, downloads, errors, authentication redirect, competing requests)');
    } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); }
})().catch(error => { console.error(error); process.exitCode = 1; });
