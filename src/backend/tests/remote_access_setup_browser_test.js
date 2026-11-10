const assert = require('node:assert/strict');
const fs = require('node:fs');
const {spawnSync} = require('node:child_process');
const {chromium} = require('playwright');

// Production section and controllers; no Store database or live service mutations.
const rendered = spawnSync('php', ['-r', `
    function csrf_field() { return '<input type="hidden" name="csrf_token" value="fixture">'; }
    $remotePublicUrl = 'https://store.ngrok-free.dev/retailmind/src/frontend/';
    $tunnelStatus = ['mode' => 'ngrok', 'token_configured' => false, 'url' => $remotePublicUrl];
    $remoteTunnelState = 'online'; $remoteModeClass = 'is-on'; $remoteModeIcon = 'bi-globe2';
    $remoteModeLabel = 'Online'; $remoteProviderLabel = 'ngrok Free'; $remoteSetupProvider = 'ngrok';
    $ngrokHostname = 'store.ngrok-free.dev'; $localAppPort = 80; $tunnelNotifyEnabled = false;
    $generatedTunnelBlockType = ''; $generatedTunnelBlock = '';
    $source = file_get_contents('src/frontend/components/system_administrator/system_settings.php');
    $start = strpos($source, '<section class="dashboard-section remote-access-section"');
    $end = strpos($source, '</section>', $start) + strlen('</section>');
    eval('?>' . substr($source, $start, $end - $start));
`], {encoding: 'utf8'});
assert.equal(rendered.status, 0, rendered.stderr);
const settings = fs.readFileSync('src/frontend/components/system_administrator/system_settings.php', 'utf8');
const clipboardScript = [...settings.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(script => script.includes('[data-copy-target]'));
const url = 'https://store.ngrok-free.dev/retailmind/src/frontend/';
const config = {state: 'online', mode: 'ngrok', configuredUrl: url, apiUrl: '/remote-access-api.php', csrfToken: 'fixture'};

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const page = await browser.newPage();
        const output = '.impeccable/review/remote-access';
        fs.mkdirSync(output, {recursive: true});
        for (const theme of ['light', 'dark']) {
            for (const width of [1440, 1024, 768, 390]) {
                await page.setViewportSize({width, height: 960});
                await page.setContent('<!doctype html><html lang="en" data-theme="' + theme + '"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="platform-settings-page"><main class="main-content"><nav class="platform-settings-nav" aria-label="Platform settings sections"><a href="#" class="active">Remote Access</a></nav><div class="platform-settings-content">' + rendered.stdout + '</div></main><script type="application/json" id="remote-access-toggle-config">' + JSON.stringify(config) + '</script></body></html>');
                for (const css of ['style', 'theme', 'platform-settings', 'store-settings']) {
                    await page.addStyleTag({path: 'src/frontend/assets/css/' + css + '.css'});
                }
                await page.addStyleTag({content: '.main-content{margin:0!important;width:100%}'});
                await page.locator('[data-platform-section]').evaluate(section => section.hidden = false);
                await page.evaluate(() => {
                    window.fixturePolls = [];
                    window.fixtureRequests = 0;
                    window.setInterval = callback => { window.fixturePolls.push(callback); return window.fixturePolls.length; };
                    window.fetch = async () => { window.fixtureRequests++; return {json: async () => window.fixtureStatus}; };
                    document.execCommand = () => { window.fixtureCopied = document.activeElement.value; return true; };
                });
                await page.addScriptTag({content: clipboardScript});
                await page.addScriptTag({path: 'src/frontend/assets/js/remote-access-toggle.js'});

                assert.equal(await page.locator('#remote-active-provider').innerText(), 'ngrok Free');
                assert.match(await page.evaluate(() => getComputedStyle(document.body).fontFamily), /Segoe UI/, 'The section inherits RetailMind system typography');
                assert.equal(await page.locator('#tunnel-public-url').getAttribute('href'), url);
                assert.equal(await page.locator('#tunnel-public-url').isVisible(), true);
                assert.equal(await page.getByRole('button', {name: 'Generate free setup block'}).isVisible(), true);
                assert.equal(await page.locator('#ngrok_token').inputValue(), '');
                const linkBox = await page.locator('#tunnel-public-url').boundingBox();
                assert.ok(linkBox.y + linkBox.height < 960, 'Public link is visible without scrolling');

                await page.getByRole('button', {name: 'Cloudflare', exact: true}).click();
                assert.equal(await page.locator('#remote-cloudflare-panel').isVisible(), true);
                assert.equal(await page.locator('#remote-ngrok-panel').isVisible(), false);
                assert.equal(await page.getByRole('button', {name: 'Generate custom domain setup block'}).isDisabled(), true);
                assert.equal(await page.locator('#remote-active-provider').innerText(), 'ngrok Free', 'Viewing Cloudflare must not change the installed provider');
                assert.equal(await page.evaluate(() => window.fixtureRequests), 0, 'Provider navigation must not call the tunnel API');
                await page.screenshot({path: `${output}/${width}-${theme}-cloudflare.png`, fullPage: true, animations: 'disabled'});
                await page.getByRole('button', {name: 'ngrok Free', exact: true}).focus();
                await page.keyboard.press('Space');
                assert.equal(await page.locator('#remote-ngrok-panel').isVisible(), true, 'Provider controls work from the keyboard');

                await page.getByRole('button', {name: 'Copy link', exact: true}).click();
                assert.equal(await page.evaluate(() => window.fixtureCopied), url, 'Clipboard fallback copies the saved public URL');
                assert.equal(await page.locator('.remote-copy-buffer').count(), 0);

                await page.evaluate(async saved => {
                    window.fixtureStatus = {success: true, status: 'offline', online: false, mode: 'ngrok', configured_url: saved};
                    await window.fixturePolls[0]();
                }, url);
                assert.equal(await page.locator('#remote-mode-label').innerText(), 'Offline');
                assert.equal(await page.locator('#remote-access-toggle').isChecked(), false);
                assert.equal(await page.locator('#tunnel-public-url').isVisible(), true, 'Saved link remains available while offline');
                assert.equal(await page.locator('#remote-active-provider').innerText(), 'ngrok Free');
                assert.match(await page.locator('#remote-link-note').innerText(), /not been verified/);
                await page.screenshot({path: `${output}/${width}-${theme}-offline.png`, fullPage: true, animations: 'disabled'});
                await page.evaluate(async saved => {
                    window.fixtureStatus = {success: true, status: 'online', online: true, mode: 'ngrok', url: saved, configured_url: saved};
                    await window.fixturePolls[0]();
                }, url);
                assert.equal(await page.locator('#remote-mode-label').innerText(), 'Online');
                assert.equal(await page.locator('#remote-access-feedback').innerText(), '');
                const overflow = await page.evaluate(() => [...document.querySelectorAll('input:not([type=hidden]),button,textarea,a')]
                    .filter(el => el.getClientRects().length && !el.closest('[hidden]'))
                    .map(el => ({id: el.id, right: el.getBoundingClientRect().right, left: el.getBoundingClientRect().left}))
                    .filter(box => box.left < -1 || box.right > innerWidth + 1));
                assert.deepEqual(overflow, [], `Controls fit ${width}px ${theme}`);
                assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
                await page.screenshot({path: `${output}/${width}-${theme}-ngrok.png`, fullPage: true, animations: 'disabled'});
            }
        }
        console.log('Remote Access UI: provider views, keyboard, copy, persistent offline link, recovery and 4 widths in light/dark passed.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
