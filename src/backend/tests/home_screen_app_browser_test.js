const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const net = require('node:net');
const os = require('node:os');
const path = require('node:path');
const {chromium} = require('playwright');

(async () => {
    const recovery = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-home-screen-'));
    const browser = await chromium.launch({headless:true});
    try {
        // Exercise the real public PHP header at both supported deployment depths.
        for (const [root, prefix] of [['src/frontend', '/'], ['.', '/src/frontend/']]) {
            const listener = net.createServer();
            await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
            const port = listener.address().port;
            await new Promise(resolve => listener.close(resolve));
            const origin = 'http://127.0.0.1:' + port;
            const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:' + port, '-t', root],
                {env:{...process.env, BACKUP_STORAGE_PATH:recovery}, stdio:'ignore'});
            let serverError;
            server.on('error', error => { serverError = error; });
            const page = await browser.newPage({viewport:{width:390,height:844}});
            try {
                for (let attempt = 0; ; attempt++) {
                    if (serverError) throw serverError;
                    try { if ((await fetch(origin + prefix)).ok) break; } catch (_) {}
                    if (attempt === 100) throw new Error('PHP test server unavailable');
                    await new Promise(resolve => setTimeout(resolve, 50));
                }
                // InfinityFree serves a browser-check HTML page when its cookie is omitted.
                await page.context().addCookies([{name:'rm-host-check', value:'passed', url:origin}]);
                await page.route('**/manifest.webmanifest', async route => {
                    const headers = await route.request().allHeaders();
                    if (!(headers.cookie || '').includes('rm-host-check=passed')) {
                        return route.fulfill({contentType:'text/html', body:'<html>Hosting browser check</html>'});
                    }
                    return route.continue();
                });
                await page.route('https://**/*', route => route.abort());
                await page.goto(origin + prefix);
                assert.equal(await page.locator('link[rel="manifest"]').count(), 1);
                const manifestUrl = await page.locator('link[rel="manifest"]').evaluate(el => el.href);
                assert.equal(manifestUrl, origin + prefix + 'manifest.webmanifest');
                const response = await page.request.get(manifestUrl);
                assert.equal(response.status(), 200);
                const manifest = await response.json();
                assert.equal(manifest.display, 'standalone');
                assert.equal(new URL(manifest.scope, manifestUrl).href, origin + prefix);
                assert.equal(new URL(manifest.start_url, manifestUrl).href, origin + prefix);
                assert.equal(new URL(manifest.id, manifestUrl).href, origin + prefix);
                assert.deepEqual(manifest.icons.map(icon => icon.sizes), ['192x192', '512x512']);
                for (const icon of manifest.icons) {
                    const dimensions = await page.evaluate(async url => {
                        const image = new Image();
                        image.src = url;
                        await image.decode();
                        return image.naturalWidth + 'x' + image.naturalHeight;
                    }, new URL(icon.src, manifestUrl).href);
                    assert.equal(dimensions, icon.sizes);
                }
                const cdp = await page.context().newCDPSession(page);
                const parsed = await cdp.send('Page.getAppManifest');
                assert.equal(parsed.url, manifestUrl);
                assert.deepEqual(parsed.errors, [], 'Chromium accepts the manifest');
                const installability = await cdp.send('Page.getInstallabilityErrors');
                assert.deepEqual(installability.installabilityErrors, [], 'Chromium reports no installation blockers');
                assert.equal(await page.locator('meta[name="apple-mobile-web-app-capable"]').getAttribute('content'), 'yes');
                assert.equal(await page.locator('meta[name="apple-mobile-web-app-status-bar-style"]').getAttribute('content'), 'default');
                const touchIcon = await page.locator('link[rel="apple-touch-icon"]').evaluate(el => el.href);
                assert.equal((await page.request.get(touchIcon)).status(), 200);
                const sidebar = fs.readFileSync('src/frontend/components/sidebar.php', 'utf8');
                const destinations = [...sidebar.matchAll(/'path'\s*=>\s*'([^']+)'/g)].map(match => match[1]);
                assert.ok(destinations.length > 0);
                for (const destination of [...destinations, 'components/auth/logout.php', '?login=1']) {
                    assert.ok(new URL(destination, origin + prefix).href.startsWith(new URL(manifest.scope, manifestUrl).href), destination + ' stays in app scope');
                }
                await page.reload();
                assert.equal(await page.locator('link[rel="manifest"]').evaluate(el => el.href), manifestUrl);
                console.log('PASS: Real PHP app metadata, browser manifest parsing, icon dimensions and sidebar scope at ' + prefix);
            } finally {
                await page.close();
                server.kill();
            }
        }
    } finally {
        await browser.close();
        assert.ok(path.resolve(recovery).startsWith(path.resolve(os.tmpdir()) + path.sep + 'rm-home-screen-'));
        fs.rmSync(recovery, {recursive:true, force:true});
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
