const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
(async () => {
 const root = process.cwd();
 const browser = await chromium.launch();
 try {
  for (const [file, marker] of [['stock_issues', 'if (!$openShift):'], ['pos', 'if (!$posShiftOpen):'], ['shifts', 'if ($ownRegisterLocked):'], ['refunds', 'if ($registerLocked):']]) {
   const source = fs.readFileSync(`src/frontend/components/cashier/${file}.php`, 'utf8');
   const start = source.indexOf(marker);
   if (start < 0) throw Error('Missing marker: ' + file);
   const htmlSource = source.slice(source.indexOf('?>', start) + 2, source.indexOf('<?php endif;', start));
   const html = execFileSync('php', ['-r', `function app_url($p) { return '/'.$p; } class CashierShiftService { const LOCKED_MESSAGE = 'This Register is locked. Unlock it with your account password to resume your Cashier Shift.'; } eval('?>'.stream_get_contents(STDIN));`], {input: htmlSource, encoding:'utf8'});
   const styles = ['assets/css/theme.css', ...new Set(source.match(/assets\/css\/[\w-]+\.css/g))];
   const page = await browser.newPage();
   await page.route('http://notice.test/**', route => {
    const p = new URL(route.request().url()).pathname;
    return route.fulfill(p.startsWith('/assets/') ? {path: path.join(root, 'src/frontend', p)} : {contentType:'text/html', body:`<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1">${styles.map(s=>`<link rel="stylesheet" href="/${s}">`).join('')}</head><body class="${source.match(/<body class="([^"]+)"/)[1]}"><main class="main-content">${html}</main></body></html>`});
   });
   for (const theme of ['light','dark']) for (const width of [1440, 768, 640, 390, 320]) {
    await page.setViewportSize({width, height:500});
    await page.goto('http://notice.test/'); await page.evaluate(t => document.documentElement.dataset.theme=t, theme);
    await page.screenshot({path:`output/playwright/shift-notices/${process.argv[2]}-${file}-${theme}-${width}.png`});
    if (process.argv[2] === 'after') {
     const assert = require('node:assert/strict');
     const notice = page.locator('.workflow-notice');
     assert.equal(await notice.count(), 1, file); assert(await notice.evaluate(el => getComputedStyle(el.querySelector('p')).color === getComputedStyle(el).color), file+' semantic text color');
     assert(await notice.isVisible());
     assert.equal(await notice.getAttribute('role'), 'note');
     assert.equal(await notice.locator('h2').evaluate(el=>getComputedStyle(el).fontSize), '16px', file+' heading'); assert.equal(await notice.locator('h2').evaluate(el=>getComputedStyle(el).marginBottom), '4px', file+' gap'); const button = notice.getByRole('link');
     assert.match(await button.getAttribute('href'), /components\/cashier\/(shifts|pos)\.php$/);
     await button.focus();
     assert(await button.evaluate(el => el === document.activeElement));
     assert(await button.evaluate(el => el.getBoundingClientRect().height >= 44));
     assert(await notice.evaluate(el => el.scrollWidth <= el.clientWidth), file + ' fits ' + width);
     assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), file + ' viewport ' + width);
     await page.evaluate(() => document.documentElement.style.fontSize='200%'); assert(await notice.evaluate(el => el.scrollWidth <= el.clientWidth), file+' text zoom'); if (width <= 640) assert(await notice.evaluate(el => { const a=el.querySelector('a').getBoundingClientRect(), p=el.querySelector('p').getBoundingClientRect(); return a.top >= p.bottom; }));
    }
   }
   await page.close();
  }
 } finally { await browser.close(); }
 console.log('Notice captures/checks complete: ' + process.argv[2]);
})().catch(e=>{console.error(e);process.exit(1)});


