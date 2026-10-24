'use strict';

// Run: node src/backend/tests/page_load_performance_browser_test.js
// Uses a disposable database; PERFORMANCE_PUBLIC_URL only measures a public GET.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { randomBytes } = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('playwright');

const root = path.resolve(__dirname, '../../..');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-page-performance-'));
const env = {
    ...process.env,
    BACKUP_STORAGE_PATH: path.join(temporary, 'recovery'),
    SESSION_SAVE_PATH: path.join(temporary, 'sessions'),
    SESSION_SECURE_COOKIE: 'false',
};

function fixture(action) {
    const result = spawnSync(process.env.PHP_BINARY || 'php',
        ['src/backend/tests/support/theme_fixture.php', action, database],
        { cwd: root, env, encoding: 'utf8' });
    assert.equal(result.status, 0, 'Disposable fixture ' + action + ' failed');
    return result.stdout;
}

async function measure(browser, origin, screen, mobile, password, publicOnly = false) {
    const context = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1440, height: 900 } });
    try {
        if (screen.role) {
            const landing = await context.request.get(origin + '/?login=1');
            const csrf = (await landing.text()).match(/name="csrf_token" value="([^"]+)"/);
            assert.ok(csrf, 'Login must provide a CSRF token');
            const login = await context.request.post(origin + '/', {
                form: { username: 'theme_' + screen.role, password, csrf_token: csrf[1] }, maxRedirects: 0,
            });
            assert.equal(login.status(), 302, 'Disposable account must log in');
            assert.ok(login.headers().location?.includes('/components/'), 'Login must enter a workspace');
        }
        const page = await context.newPage();
        const errors = [];
        const serverErrors = [];
        page.on('pageerror', () => errors.push(true));
        page.on('response', response => { if (response.status() >= 500) serverErrors.push(true); });
        const cdp = await context.newCDPSession(page);
        await cdp.send('Network.enable');
        if (mobile) {
            await cdp.send('Network.emulateNetworkConditions', {
                offline: false, latency: 150, downloadThroughput: 200000, uploadThroughput: 93750,
            });
            await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
        }
        const results = [];
        for (const repeat of [false, true]) {
            let serviceWorkerResponses = 0;
            const cached = ({ response }) => {
                if (new URL(response.url).origin !== new URL(origin).origin) return;
                if (response.fromServiceWorker) serviceWorkerResponses++;
            };
            cdp.on('Network.responseReceived', cached);
            try {
                const response = await page.goto(publicOnly ? origin : origin + screen.url, { waitUntil: 'domcontentloaded', timeout: 60000 });
                assert.equal(response.status(), 200, screen.name + ' must load');
                if (!publicOnly) assert.match(response.headers()['cache-control'] || '', /no-store/i, 'Session HTML must not be cached');
                const control = page.locator(screen.control);
                await control.waitFor({ state: 'visible' });
                assert.ok(await control.isEnabled(), screen.name + ' control must be enabled');
                await control.fill('Theme');
                assert.equal(await control.inputValue(), 'Theme', screen.name + ' control must accept input');
                await control.fill('');
                const usableMs = Math.round(await page.evaluate(() => performance.now()));
                await page.waitForLoadState('load', { timeout: 60000 });
                await page.waitForTimeout(300);
                assert.equal(errors.length, 0, screen.name + ' must not throw browser errors');
                assert.equal(serverErrors.length, 0, screen.name + ' must not return server errors');
                const timing = await page.evaluate(() => {
                    const navigation = performance.getEntriesByType('navigation')[0];
                    const resources = performance.getEntriesByType('resource');
                    const safePath = value => { const url = new URL(value); return url.origin + url.pathname; };
                    return {
                        ttfbMs: Math.round(navigation.responseStart - navigation.requestStart),
                        domReadyMs: Math.round(navigation.domContentLoadedEventStart),
                        loadMs: Math.round(navigation.loadEventEnd),
                        htmlBytes: navigation.encodedBodySize,
                        resourceTransferBytes: resources.reduce((sum, entry) => sum + entry.transferSize, 0),
                        resourceCount: resources.length,
                        slowRequests: resources.slice().sort((a, b) => b.duration - a.duration).slice(0, 5).map(entry => ({
                            url: safePath(entry.name), durationMs: Math.round(entry.duration), transferBytes: entry.transferSize,
                        })),
                    };
                });
                results.push({ page: screen.name, environment: publicOnly ? 'public-read-only' : 'disposable-fixture-one-product',
                    device: mobile ? '390x844-150ms-200000Bps-cpu4' : '1440x900-unthrottled',
                    visit: repeat ? 'repeat' : 'first', usableMs, ...timing, serviceWorkerResponses });
            } finally {
                cdp.off('Network.responseReceived', cached);
            }
        }
        return results;
    } finally {
        await context.close();
    }
}

(async () => {
    let browser, server;
    try {
        const account = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn(process.env.PHP_BINARY || 'php',
            ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            { cwd: root, env: { ...env, RM_THEME_TEST_DATABASE: database }, stdio: 'ignore' });
        for (let attempt = 0; ; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            assert.ok(attempt < 100, 'Disposable PHP server must start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }
        browser = await chromium.launch({ headless: true });
        const screens = [
            { name: 'login', url: '/?login=1', control: '#landing-login-username' },
            { name: 'cashier-pos', url: '/components/cashier/pos.php', role: 'cashier', control: '#sku-input' },
            { name: 'stock-overview', url: '/components/inventory_management/inventory_overview.php', role: 'inventory_manager', control: '#overview-product-search' },
        ];
        const results = [];
        for (const mobile of [false, true]) {
            for (const screen of screens) results.push(...await measure(browser, origin, screen, mobile, account.password));
            if (process.env.PERFORMANCE_PUBLIC_URL) {
                const url = new URL(process.env.PERFORMANCE_PUBLIC_URL);
                assert.ok(['http:', 'https:'].includes(url.protocol) && !url.username && !url.password, 'Public measurement requires an HTTP(S) URL without credentials');
                results.push(...await measure(browser, url.href, screens[0], mobile, null, true));
            }
        }
        const json = JSON.stringify({ measuredAt: new Date().toISOString(), results }, null, 2) + '\n';
        if (process.env.PERFORMANCE_OUTPUT) fs.writeFileSync(process.env.PERFORMANCE_OUTPUT, json);
        else process.stdout.write(json);
    } finally {
        await browser?.close();
        if (server) {
            server.kill();
            await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve));
        }
        try { fixture('cleanup'); } finally { fs.rmSync(temporary, { recursive: true, force: true }); }
    }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
