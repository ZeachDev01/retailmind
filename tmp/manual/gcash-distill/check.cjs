const assert = require('node:assert/strict');
const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const {chromium} = require('playwright');
const database = 'retailmind_theme_test_' + randomBytes(6).toString('hex');
const fixture = action => {
  const result = spawnSync('php', ['tmp/manual/gcash-distill/fixture.php', action, database], {encoding: 'utf8'});
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.replace(/^\uFEFF/, '');
};
(async () => {
  let server, browser;
  try {
    const data = JSON.parse(fixture('create'));
    server = spawn('php', ['-S', '127.0.0.1:0', '-t', 'src/frontend', 'src/backend/tests/support/theme_router.php'], {env: {...process.env, RM_THEME_TEST_DATABASE: database}});
    const origin = await new Promise(resolve => server.stderr.on('data', chunk => {
      const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:\d+/);
      if (match) resolve(match[0]);
    }));
    browser = await chromium.launch();
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('https://**/*', route => route.fulfill({body: ''}));
    await page.goto(origin + '/?login=1');
    await page.locator('#landing-login-username').fill('theme_admin');
    await page.locator('#landing-login-password').fill(data.password);
    await Promise.all([page.waitForURL(url => !url.search.includes('login=1')), page.getByRole('button', {name: 'Log in', exact: true}).click()]);
    await page.goto(origin + '/components/administrator/gcash_overview.php?tab=overview');
    const skip = page.getByRole('button', {name: 'Skip guidance', exact: true});
    if (await skip.isVisible()) {
      await skip.click();
      await skip.waitFor({state: 'hidden'});
    }
    await page.getByRole('heading', {name: 'Service balances', exact: true}).waitFor();
    assert.equal(await page.locator('#admin-expected-cash').innerText(), '₱2,510.00');
    for (const theme of ['light', 'dark']) {
      await page.evaluate(theme => { document.documentElement.dataset.theme = theme; }, theme);
      for (const width of [1440, 390, 320]) {
        await page.setViewportSize({width, height: 1000});
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('.gcash-totals-help').getAttribute('open'), null);
        await page.screenshot({path: `tmp/manual/gcash-distill/${theme}-${width}.png`, fullPage: true});
      }
    }
    await page.getByText('About these totals', {exact: true}).click();
    assert.equal(await page.getByText('Cash-in and cash-out amounts exclude fees. Fees are shown before fee returns.').isVisible(), true);
    await page.getByRole('button', {name: 'View transactions', exact: true}).click();
    assert.equal(await page.locator('#tab-transactions').isVisible(), true);
    await page.getByRole('tab', {name: 'Overview', exact: true}).click();
    await page.getByRole('button', {name: /Unresolved exchanges/}).click();
    assert.equal(await page.locator('#tab-unresolved').isVisible(), true);
    assert.deepEqual(errors, []);
    console.log('GCash visual check: desktop, mobile, light/dark, totals disclosure and tab actions passed.');
  } finally {
    if (browser) await browser.close();
    if (server) server.kill();
    fixture('cleanup');
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
