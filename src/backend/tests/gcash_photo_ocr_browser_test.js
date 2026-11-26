'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {chromium} = require('playwright');

// Exercise the production file-input handler and bundled OCR without a database or customer images.
const root = path.resolve(__dirname, '../../frontend');
const source = fs.readFileSync(path.join(root, 'assets/js/gcash.js'), 'utf8');
const handler = source.slice(source.indexOf('    function closeCamera()'), source.indexOf('    // Session dialogs'));
const server = http.createServer((req, res) => {
    const file = path.resolve(root, '.' + new URL(req.url, 'http://localhost').pathname);
    if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
        res.writeHead(404).end(); return;
    }
    res.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'application/octet-stream');
    fs.createReadStream(file).pipe(res);
});
(async () => {
    let browser;
    try {
        await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
        browser = await chromium.launch({headless: true});
        const page = await browser.newPage();
        const origin = 'http://127.0.0.1:' + server.address().port;
        await page.goto(origin + '/assets/js/gcash-reference.js');
        await page.setContent('<div class="gcash-field"><input id="gcash-reference"><div id="gcash-camera" hidden></div></div>' +
            '<input id="gcash-resolve-reference"><dialog id="gcash-photo-dialog"></dialog><dialog id="gcash-resolve-dialog"></dialog>' +
            '<img id="gcash-photo-preview"><img id="gcash-photo-dialog-image">' +
            ['camera-close', 'photo-preview-open', 'photo-dialog-close', 'camera-open', 'photo-open', 'resolve-camera-open', 'resolve-photo-open', 'ocr-use'].map(id => `<button id="gcash-${id}"></button>`).join('') +
            '<input type="file" id="gcash-camera-file"><input type="file" id="gcash-photo-file">' +
            '<p id="gcash-ocr-status"></p><div id="gcash-ocr-review" hidden><input id="gcash-ocr-candidate"></div>');
        await page.addScriptTag({url: origin + '/assets/js/gcash-reference.js'});
        await page.addScriptTag({content: `(() => {
            const el = id => document.getElementById('gcash-' + id);
            const reference = el('reference'), parser = window.GCashReference;
            const rules = {min: 13, max: 13}, assetBase = '${origin}/assets/';
            let worker, photoUrl, cameraVersion = 0, leaving = false, saving = false;
            let photoReference = reference;
            const scanStatus = () => {}, notify = () => {}, checkReference = async () => {};
            ${handler}
        })();`});
        const images = await page.evaluate(() => [false, true].map(wrapped => {
            const canvas = document.createElement('canvas');
            canvas.width = 1080; canvas.height = 2400;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#007dfe'; ctx.fillRect(0, 0, 1080, 2400);
            ctx.fillStyle = '#fff'; ctx.fillRect(65, 380, 950, 1550);
            ctx.fillStyle = '#153452'; ctx.textAlign = 'center';
            for (const [text, y, size] of [['GCash', 490, 64], ['Successfully sent!', 680, 46], ['PHP 100.00', 820, 64], ['Recipient', 1020, 34], ['09171234567', 1090, 38], ['Ref. No.', 1420, 34], [wrapped ? '0012 345' : '0012 345 678901', 1490, 38], [wrapped ? '678901' : '', 1540, 38], ['Nov 25, 2026 10:30 AM', 1660, 32]]) {
                ctx.font = `${size}px Arial`; ctx.fillText(text, 540, y);
            }
            return canvas.toDataURL('image/png').split(',')[1];
        }));
        for (const image of images) for (const input of ['photo-file', 'camera-file']) {
            await page.locator('#gcash-' + input).setInputFiles({name: 'phone-screenshot.png', mimeType: 'image/png', buffer: Buffer.from(image, 'base64')});
            await page.waitForFunction(() => !document.getElementById('gcash-ocr-review').hidden || document.getElementById('gcash-ocr-status').textContent.startsWith('Could not'));
            assert.equal(await page.locator('#gcash-ocr-candidate').inputValue(), '0012345678901', input + ': ' + await page.locator('#gcash-ocr-status').textContent());
            assert.equal(await page.locator('#gcash-reference').inputValue(), '', 'Suggestion requires confirmation');
            await page.locator('#gcash-camera-close').click();
        }
        console.log('GCash real photo OCR: passed (upload, camera, confirmation required)');
    } finally {
        if (browser) await browser.close();
        await new Promise(resolve => server.close(resolve));
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
