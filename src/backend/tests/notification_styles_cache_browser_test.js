const assert = require('node:assert/strict');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

(async () => {
    const frontend = path.resolve(__dirname, '../../frontend');
    const source = fs.readFileSync(path.join(frontend, 'components/notification/notifications.php'), 'utf8');
    const links = source.match(/<link rel="stylesheet"[^\n]+/g).join('\n');
    const render = spawnSync(process.env.PHP_BINARY || 'php', ['-r', `
        function app_url($path) { return '/' . $path; }
        require 'src/backend/includes/theme.php';
        eval('?>' . ${JSON.stringify(links)});
    `], {encoding: 'utf8'});
    assert.equal(render.status, 0, render.stderr);
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (url.pathname === '/sw.js' || url.pathname.startsWith('/assets/css/')) {
            response.setHeader('Content-Type', url.pathname.endsWith('.js') ? 'text/javascript' : 'text/css');
            response.end(fs.readFileSync(path.join(frontend, url.pathname)));
        } else {
            response.setHeader('Content-Type', 'text/html');
            response.end(`<!doctype html>${url.pathname === '/notifications' ? render.stdout : ''}
                <main class="notifications-page"><div class="notification-toolbar">Notifications</div>
                <div class="p-5">Details</div></main>`);
        }
    });
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        const origin = 'http://127.0.0.1:' + server.address().port;
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.goto(origin);
        await page.evaluate(async () => {
            await navigator.serviceWorker.register('/sw.js');
            await navigator.serviceWorker.ready;
            const cache = await caches.open('retailmind-static-v2');
            for (const name of ['notifications', 'notifications-utilities']) {
                await cache.put('/assets/css/' + name + '.css', new Response('/* stale stylesheet */', {
                    headers: {'Content-Type': 'text/css'},
                }));
            }
        });
        await page.waitForFunction(() => navigator.serviceWorker.controller !== null);
        await page.goto(origin + '/notifications');
        assert.equal(await page.locator('.notification-toolbar').evaluate(el => getComputedStyle(el).display),
            'flex', 'Notification layout must bypass stale cached CSS');
        assert.equal(await page.locator('.p-5').evaluate(el => getComputedStyle(el).padding),
            '20px', 'Notification utilities must bypass stale cached CSS');
        console.log('Notification stylesheet cache: passed');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
