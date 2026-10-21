const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require('playwright');

(async () => {
    const browser = await chromium.launch({headless: true});
    try {
        const css = ['style', 'notifications', 'notifications-utilities', 'theme'].map(name => fs.readFileSync(`src/frontend/assets/css/${name}.css`, 'utf8')).join('\n');
        const source = fs.readFileSync('src/frontend/components/notification/notifications.php', 'utf8');
        const extract = marker => {
            const start = source.indexOf(marker);
            return source.slice(start, source.indexOf('<?php endforeach; ?>', start)).replace(/<\?[\s\S]*?\?>/g, '');
        };
        const card = extract('<article class="notification-item <?=');
        const priority = extract('<article class="notification-item notification-priority">');
        const script = source.slice(source.lastIndexOf('<script>') + 8, source.lastIndexOf('</script>'));
        const page = await browser.newPage();
        const output = require('node:os').tmpdir() + '/retailmind-notification-rows';
        fs.mkdirSync(output, {recursive: true});
        for (const [type, title, message, values] of [
            ['low_stock', 'Scan WF Short Numeric Code', 'Product Scan WF Short Numeric Code (6931551) is at or below the threshold. Current: 6, Threshold: 10', ['6', '10']],
            ['low_stock', 'Flour (bulk) <bag>', 'Product Flour (bulk) <bag> (SKU-2) is at or below the threshold. Current: 0, Threshold: 2.5', ['0', '2.5']],
            ['low_stock', 'Old notice', 'Historical message without quantities', null],
            ['system', 'System update', 'SKU-' + '0123456789'.repeat(12), null],
        ]) {
            await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width"><style>${css}</style><div class="app-shell"><aside class="sidebar">Navigation</aside><main class="main-content notifications-page"><section class="notification-list">${card}</section></main></div>`);
            await page.locator('.notification-item').evaluate((el, data) => {
                window.readClicks = 0;
                el.dataset.notificationType = data.type;
                el.querySelector('h2').textContent = data.title;
                el.querySelector('.notification-description p').textContent = data.message;
                el.querySelector('.notification-category').append(document.createTextNode(data.type === 'low_stock' ? 'Low stock' : 'System'));
                el.querySelector('.notification-time').textContent = 'Oct 19, 2026 · 1:34 PM';
                el.querySelector('form').addEventListener('submit', event => { event.preventDefault(); window.readClicks = (window.readClicks || 0) + 1; });
            }, {type, title, message});
            await page.addScriptTag({content: script});
            assert.equal(await page.locator('.notification-toggle, .notification-chevron, [aria-expanded], [inert]').count(), 0, 'Rows have no accordion controls');
            assert.equal(await page.locator('h2').textContent(), title);
            assert.equal(await page.locator('h2 > *').count(), 0, 'Product names remain plain text');
            assert.equal(await page.locator('.notification-description').isVisible(), !values);
            if (values) {
                assert.equal(await page.locator('.notification-current').textContent(), values[0]);
                assert.equal(await page.locator('.notification-threshold').textContent(), values[1]);
            } else {
                assert.equal(await page.locator('.notification-description p').textContent(), message, 'Other messages remain intact');
            }
            for (const width of [320, 390, 768, 1440]) {
                await page.setViewportSize({width, height: 900});
                const overflow = await page.locator('.notification-item').evaluate(el => [el, ...el.querySelectorAll('*')]
                    .filter(node => node.getClientRects().length && node.scrollWidth > node.clientWidth + 1).map(node => node.className));
                assert.deepEqual(overflow, [], `Rows fit at ${width}px`);
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
                const button = page.getByRole('button', {name: 'Mark as read', exact: true});
                assert((await button.boundingBox()).height >= 44);
                await button.click();
                if (values && width === 1440) {
                    const boxes = await Promise.all(['.notification-category', 'h2', '.notification-quantities > div:first-child', '.notification-quantities > div:last-child'].map(selector => page.locator(selector).boundingBox()));
                    for (let i = 1; i < boxes.length; i++) assert(boxes[i].x >= boxes[i - 1].x + boxes[i - 1].width, 'Desktop order: warning, name, Current, Threshold');
                }
            }
            assert.equal(await page.evaluate(() => readClicks), 4, 'Read action works at every width');
            if (values && values[0] === '6') {
                for (const theme of ['light', 'dark']) {
                    await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
                    for (const width of [390, 1440]) {
                        await page.setViewportSize({width, height: 900});
                        await page.screenshot({path: `${output}/${theme}-${width}.png`, fullPage: true});
                    }
                }
            }
        }
        await page.setContent(`<style>${css}</style><main class="notifications-page"><section class="active-batch-reminders"><div class="notification-list">${priority}</div></section></main>`);
        assert(await page.getByRole('link', {name: 'Record Supplier Pullout', exact: true}).isVisible(), 'Pullout action is always visible');
        assert.equal(await page.locator('.notification-toggle, [inert]').count(), 0);
        const required = extract('<article class="notification-required-item">');
        await page.setContent(`<!doctype html><meta name="viewport" content="width=device-width"><style>${css}</style><div class="app-shell"><aside class="sidebar">Navigation</aside><main class="main-content notifications-page"><header class="notification-heading"><h1>Notifications</h1></header><section class="notification-required-work" aria-labelledby="required-work-heading"><div class="notification-section-heading"><h2 id="required-work-heading">Action needed</h2><span class="notification-required-count">12</span></div><div class="notification-list">${required.repeat(12)}</div></section><div class="notification-toolbar"><nav class="notification-views" aria-label="Notification view"><a href="#all" aria-current="page">All</a><a href="#unread">Unread <span class="notification-view-count">12</span></a></nav></div></main></div>`);
        await page.locator('.notification-required-item').evaluateAll(items => items.forEach((item, i) => {
            const title = ['Orange Soda 500ml', 'Tortilla Chips Nacho 200g', 'Instant Noodles Chicken 5 Pack', 'SKU-' + '0123456789'.repeat(12)][i % 4];
            const link = item.querySelector('a');
            link.prepend(document.createTextNode((i % 2 ? 'Low Stock Alert: ' : 'Out of stock: ') + title));
            link.href = '#review-condition';
            item.querySelector('p').textContent = `Product ${title} (SKU-BEV-005) is at or below the threshold. Current: 0, Threshold: 10`;
        }));
        await page.addScriptTag({content: script});
        const firstCondition = page.locator('.notification-required-item').first();
        assert.equal(await firstCondition.getByRole('link').innerText(), 'Orange Soda 500ml', 'Stock rows lead with the product name');
        assert.equal(await firstCondition.getByText('Low stock', {exact: true}).count(), 1, 'Stock category stays explicit');
        assert.equal(await firstCondition.locator('dt').allTextContents().then(labels => labels.join(', ')), 'Current, Threshold');
        assert.equal(await firstCondition.locator('dd').allTextContents().then(values => values.join(', ')), '0, 10');
        for (const theme of ['light', 'dark']) {
            await page.locator('html').evaluate((el, theme) => { el.dataset.theme = theme; }, theme);
            for (const width of [320, 390, 768, 1440]) {
                await page.setViewportSize({width, height: 900});
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Required work fits at ${width}px in ${theme}`);
                const rows = page.locator('.notification-required-item');
                assert.equal(await rows.count(), 12, 'Every active condition remains visible');
                for (const row of await rows.all()) {
                    assert(await row.getByText('Current', {exact: true}).isVisible(), 'Stock figures remain visible');
                    assert((await row.locator('a').boundingBox()).height >= 44, 'Review links meet touch target size');
                }
                if ([390, 1440].includes(width)) {
                    await page.screenshot({path: `${output}/required-${theme}-${width}.png`, fullPage: true});
                }
            }
        }
        const review = page.locator('.notification-required-item a').first();
        await review.focus();
        assert.equal(await review.evaluate(el => getComputedStyle(el.closest('article')).outlineStyle), 'solid', 'Keyboard focus outlines the row');
        await review.press('Enter');
        assert.equal(await page.evaluate(() => location.hash), '#review-condition', 'Review destination remains navigable');
        await page.setContent(`<style>${css}</style><main class="notifications-page">${required}</main>`);
        const fallback = page.locator('.notification-required-item');
        for (const [title, message] of [
            ['Pending Stock Issue #42', 'Review the reported damage.'],
            ['Out of stock: Historical alert', 'Historical message without quantities'],
            ['Review condition', 'Current: 0, Threshold: 10'],
        ]) {
            await fallback.locator('a').evaluate((el, title) => { el.textContent = title; }, title);
            await fallback.locator('p').evaluate((el, message) => { el.textContent = message; }, message);
            await page.addScriptTag({content: script});
            assert.equal(await fallback.getByRole('link').innerText(), title, 'Other condition titles stay intact');
            assert(await fallback.getByText(message, {exact: true}).isVisible(), 'Unrecognized conditions retain their complete message');
            assert.equal(await fallback.locator('dl').isVisible(), false, 'Unrecognized conditions do not display stock figures');
        }
        console.log('Notification rows: passed (320–1440px; field order, quantities, safe text, fallback messages, read actions, static reminders). Screenshots: ' + output);
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
