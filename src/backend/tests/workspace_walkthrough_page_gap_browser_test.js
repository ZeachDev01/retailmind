const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const {randomBytes} = require('node:crypto');
const {spawn, spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

if (process.env.RUN_DB_TESTS !== '1') {
    console.log('Walkthrough page gap: skipped (RUN_DB_TESTS=1 and disposable MySQL required)');
    process.exit(0);
}

const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-walkthrough-gap-'));
const env = {...process.env, BACKUP_STORAGE_PATH: path.join(directory, 'recovery')};

const fixture = (action, extra) => {
    const args = ['src/backend/tests/support/theme_fixture.php', action, database];
    if (extra) args.push(extra);
    const result = spawnSync(process.env.PHP_BINARY || 'php', args, {env, encoding: 'utf8'});
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

        server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {
            env: {...env, RM_THEME_TEST_DATABASE: database},
            stdio: 'ignore'
        });

        for (let attempt = 0; attempt < 100; attempt++) {
            try { if ((await fetch(origin)).ok) break; } catch {}
            if (attempt === 99) throw new Error('Test server did not start');
            await new Promise(resolve => setTimeout(resolve, 50));
        }

        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        await page.route('https://**/*', route => route.fulfill({status: 200, body: ''}));

        await page.goto(origin + '/?login=1');
        await page.locator('#landing-login-username').fill('theme_admin');
        await page.locator('#landing-login-password').fill(data.password);
        await Promise.all([page.waitForURL(u => !u.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
        for (const theme of ['light', 'dark']) {
            for (const width of [1920, 1440, 1280, 390, 320]) {
                await page.setViewportSize({width, height: 900});
                await page.goto(origin + '/components/administrator/registers.php?guidance=registers&replay=1');
                await page.locator('#guidance-dock').waitFor();
                await page.evaluate(({theme, width}) => {
                    document.documentElement.dataset.theme = theme;
                    document.documentElement.style.zoom = width === 1920 ? '1.5' : '1';
                }, {theme, width});
                for (let step = 1; step <= 3; step++) {
                    await page.waitForTimeout(200);
                    const metrics = await page.evaluate(() => {
                        const target = document.querySelector('.guidance-spotlight-target');
                        const rect = target.getBoundingClientRect();
                        const top = document.elementFromPoint(rect.left + Math.min(rect.width / 2, 30), rect.top + Math.min(rect.height / 2, 30));
                        const dock = document.querySelector('#guidance-dock');
                        const body = dock.querySelector('.guidance-dock-body');
                        const footer = dock.querySelector('.guidance-dock-footer');
                        return {
                            height: Math.max(document.body.scrollHeight, document.documentElement.scrollHeight),
                            shellHeight: document.querySelector('.app-shell').getBoundingClientRect().height,
                            viewport: innerHeight,
                            targetVisible: top === target || target.contains(top),
                            panelGap: footer.querySelector('button').getBoundingClientRect().top - body.getBoundingClientRect().bottom,
                            expectedGap: parseFloat(getComputedStyle(dock).rowGap) * dock.getBoundingClientRect().width / dock.offsetWidth
                        };
                    });
                    assert.ok(metrics.height <= Math.max(metrics.viewport, metrics.shellHeight) + 2,
                        `${theme}/${width}/step ${step}: blank space below workspace: ${JSON.stringify(metrics)}`);
                    assert.ok(metrics.targetVisible, `${theme}/${width}/step ${step}: highlighted control must remain uncovered`);
                    assert.ok(metrics.panelGap <= metrics.expectedGap + 1,
                        `${theme}/${width}/step ${step}: extra gap inside walkthrough: ${JSON.stringify(metrics)}`);
                    if (step < 3) await page.locator('#guidance-btn-next').click();
                }
            }
        }
        console.log('Walkthrough page gap: passed');
    } finally {
        if (browser) await browser.close();
        if (server) { server.kill(); await new Promise(resolve => server.once('exit', resolve)); }
        fixture('cleanup');
        fs.rmSync(directory, {recursive: true, force: true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
