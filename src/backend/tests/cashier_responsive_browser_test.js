'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Cashier responsive: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-cashier-responsive-'));
const fixture = action => {
    const result = spawnSync('php', ['src/backend/tests/support/theme_fixture.php', action, database], {encoding: 'utf8'});
    assert.equal(result.status, 0, result.stderr || result.stdout);
    return result.stdout;
};
(async () => {
    let server, browser;
    try {
        const data = JSON.parse(fixture('create'));
        const listener = net.createServer();
        await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
        const port = listener.address().port;
        await new Promise(resolve => listener.close(resolve));
        const origin = 'http://127.0.0.1:' + port;
        server = spawn('php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'],
            {env: {...process.env, RM_THEME_TEST_DATABASE: database}, stdio: ['ignore', 'ignore', 'pipe']});
        let logs = '';
        server.stderr.on('data', chunk => { logs += chunk; });
        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            await new Promise(resolve => setTimeout(resolve, 50));
            if (attempt === 99) throw new Error(logs);
        }
        browser = await chromium.launch();
        const page = await browser.newPage({viewport: {width: 768, height: 1024}, hasTouch: true, reducedMotion: 'reduce'});
        page.setDefaultTimeout(8000);
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));
        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_cashier');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        const routes = ['dashboard.php', 'pos.php', 'gcash.php', 'gcash.php?tab=history', 'gcash.php?tab=funds',
            'shifts.php', 'shifts.php?tab=current', 'shifts.php?tab=close', 'shifts.php?tab=recent', 'history.php', 'history.php?type=refunds',
            'history.php?type=movements', 'history.php?type=shifts', 'refunds.php', 'stock_issues.php', 'findProduct.php',
            'refund_corrections.php', 'refunds.php?sale_id=1', '../invoice/sales.php', '../auth/user_info.php', '../auth/preferences.php', '../notification/notifications.php'];
        const findings = [];
        for (const route of routes) {
            const response = await page.goto(origin + '/components/cashier/' + route);
            assert.equal(response.status(), 200, route);
            const dismiss = page.getByRole('button', {name: 'Not now', exact: true});
            if (await dismiss.isVisible()) await dismiss.tap();
            for (const viewport of [{width: 768, height: 1024}, {width: 744, height: 1133}, {width: 1133, height: 744},
                {width: 1024, height: 768}, {width: 390, height: 844}, {width: 320, height: 740}, {width: 1440, height: 900}]) {
                await page.setViewportSize(viewport);
                const metrics = await page.locator('main').evaluate(main => {
                    const header = main.querySelector('.topbar, .gcash-session-header');
                    const rect = header?.getBoundingClientRect();
                    const controls = [...main.querySelectorAll('a.btn, button, input:not([type=hidden]), select, textarea')]
                        .filter(el => el.checkVisibility() && !el.closest('.table-wrap, .data-table-scroll, [hidden]'));
                    return {
                        overflow: document.documentElement.scrollWidth > innerWidth + 1,
                        headerHidden: rect && rect.top < document.querySelector('.admin-mobile-topbar').getBoundingClientRect().bottom - 1,
                        clipped: controls.filter(el => {
                            const r = el.getBoundingClientRect();
                            if (r.left >= -1 && r.right <= innerWidth + 1) return false;
                            for (let p = el.parentElement; p && p !== main; p = p.parentElement) if (/(auto|scroll)/.test(getComputedStyle(p).overflowX)) return false;
                            return true;
                        }).map(el => el.id || el.textContent.trim()),
                        returnRow: (() => { const a = header?.querySelector('a[href*="pos.php"]'); const h = header?.querySelector('h1'); return a && h ? Math.max(0, a.getBoundingClientRect().top - h.getBoundingClientRect().bottom, h.getBoundingClientRect().top - a.getBoundingClientRect().bottom) : 0; })()
                    };
                });
                const label = `${viewport.width}x${viewport.height} ${route}`;
                if (metrics.overflow || metrics.headerHidden || metrics.clipped.length || (viewport.width >= 641 && metrics.returnRow > 2)) findings.push({label, ...metrics});
                if ([744, 1133, 390].includes(viewport.width) && !route.includes('?')) await page.screenshot({path: path.join(output, `${viewport.width}-${route.replace(/[^a-z0-9.-]/gi, '-')}.png`), fullPage: true});
            }
        }
        console.log('Captures: ' + output);
        console.log(JSON.stringify(findings, null, 2));
        assert.deepEqual(findings, [], 'Cashier pages fit and headers align');
        console.log(`Cashier responsive: passed (${routes.length} routes, seven viewports, authenticated disposable Store)`);
    } finally {
        await browser?.close();
        server?.kill();
        fixture('cleanup');
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
