const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch();
    try {
        const page = await browser.newPage();
        const css = ['style', 'audit-logs', 'backup'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
        for (const file of ['system_administrator/audit_logs', 'system_administrator/backup_restore', 'administrator/database_backup']) {
            const source = fs.readFileSync(`src/frontend/components/${file}.php`, 'utf8');
            const caption = source.match(/<caption class="(?:visually-hidden|sr-only)">[^<]+<\/caption>/)[0];
            for (const width of [390, 1440]) {
                await page.setViewportSize({width, height: 900});
                await page.setContent(`<style>${css}</style><main class="audit-log-page"><table>${caption}<tr><th>Record</th></tr><tr><td>Example</td></tr></table></main>`);
                const layout = await page.locator('caption').evaluate(el => {
                    const style = getComputedStyle(el);
                    return {position: style.position, clipPath: style.clipPath, width: el.getBoundingClientRect().width};
                });
                assert.equal(layout.position, 'absolute', `${file}: caption must leave the visible table layout`);
                assert.equal(layout.clipPath, 'inset(50%)', `${file}: caption must be visually clipped`);
                assert.ok(layout.width <= 1, `${file}: caption must not occupy a visible row`);
                assert.match(await page.locator('table').ariaSnapshot(), new RegExp(caption.replace(/<[^>]+>/g, '')), 'Table keeps its accessible name');
            }
        }
        console.log('Hidden captions: passed (3 page templates, mobile and desktop, accessible table names)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
