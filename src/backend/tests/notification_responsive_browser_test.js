const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require('playwright');

(async () => {
    const browser = await chromium.launch({ headless: true });
    try {
        const css = ['style', 'notifications', 'theme'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
        const source = fs.readFileSync('src/frontend/components/notification/notifications.php', 'utf8');
        const start = source.indexOf('<div class="notification-item');
        const card = source.slice(start, source.indexOf('<?php endforeach; ?>', start)).replace(/<\?[\s\S]*?\?>/g, '');
        assert(card.includes('btn-mark-read'), 'Fixture uses the shipped notification card');
        const page = await browser.newPage();
        await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width"><style>${css}</style><div class="app-shell"><aside class="sidebar">Navigation</aside><main class="main-content">${card}</main></div>`);
        await page.locator('.notification-time').evaluate(el => { el.textContent = 'Oct 9, 2026 10:45 AM'; });
        await page.locator('form').evaluate(el => el.addEventListener('submit', event => { event.preventDefault(); window.readClicks = (window.readClicks || 0) + 1; }));
        for (const width of [320, 375, 425, 768, 1024, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            for (const message of ['Review quantities for products flagged as low stock.', 'SKU-' + '0123456789'.repeat(12)]) {
                await page.locator('.notification-content').evaluate((el, message) => {
                    el.querySelector('h4').textContent = 'Inventory threshold exceeded';
                    el.querySelector('p').textContent = message;
                }, message);
                const overflow = await page.locator('.notification-item').evaluate(el => [el, ...el.querySelectorAll('*')]
                    .filter(node => node.getClientRects().length && node.scrollWidth > node.clientWidth + 1)
                    .map(node => node.className));
                assert.deepEqual(overflow, [], `Notification content stays within its card at ${width}px`);
                const button = await page.locator('.btn-mark-read').boundingBox();
                const cardBounds = await page.locator('.notification-item').boundingBox();
                assert(button.x >= cardBounds.x && button.x + button.width <= cardBounds.x + cardBounds.width,
                    `Mark Read stays within the card at ${width}px`);
                await page.locator('.btn-mark-read').click();
            }
        }
        assert.equal(await page.evaluate(() => readClicks), 12, 'Mark Read remains usable at every width');
        console.log('Notification responsive browser: passed (320, 375, 425, 768, 1024, 1440px; production card, ordinary/long messages, action reachability)');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
