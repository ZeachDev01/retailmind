const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

(async () => {
    let click;
    let pages = 1;
    let images = 0;
    let filename;
    let alerts = 0;
    const text = [];
    const fills = [];
    const coloredText = [];
    const button = { addEventListener(event, callback) { click = callback; } };
    const context = {
        fillRect(x, y, width, height) { fills.push({ color: this.fillStyle, width, height }); },
        fillText(value) { text.push(value); coloredText.push({ value, color: this.fillStyle }); },
        measureText(value) { return { width: Array.from(value).length * 10 }; },
        beginPath() {}, moveTo() {}, lineTo() {}, stroke() {},
    };
    const rows = Array.from({ length: 100 }, (_, i) => ({
        hidden: true,
        dataset: { stock: ['Out of stock', 'Low stock', 'In stock', 'Unknown'][i % 4], name: `商品 café (${i})`, sku: `SKU-${i}`, onHand: '2', quantity: '10', expectedSales: i === 0 ? '' : i === 1 ? '0' : '42', forecastPeriod: '2026-10-28 – 2026-11-24' },
        querySelector() { return { firstChild: { textContent: 'Low stock' } }; },
    }));
    const document = {
        fonts: { ready: Promise.resolve() },
        getElementById() { return button; },
        querySelectorAll() { return rows; },
        createElement() { return { getContext() { return context; }, toDataURL() { return 'data:image/png;base64,test'; } }; },
    };
    const window = {
        jspdf: { jsPDF: class {
            addPage() { pages++; }
            addImage() { images++; }
            save(value) { filename = value; }
        } },
        RetailMindUI: { toast() { alerts++; } },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/replenishment-pdf.js'), 'utf8'), { document, window, console: { error() {} } });
    await click();
    assert.equal(filename, 'replenishment-recommendations.pdf');
    assert.ok(pages > 1);
    assert.equal(images, pages);
    assert.ok(text.includes('商品 café (99)'), 'Export includes hidden rows and Unicode names');
    assert.equal(fills.filter(fill => fill.color === '#111827' && fill.height === 44).length, pages, 'Every page has a navy table header');
    for (const [value, color, background] of [
        ['Out of stock', '#b91c1c', '#fef2f2'],
        ['Low stock', '#92400e', '#fef3c7'],
        ['In stock', '#166534', '#dcfce7'],
        ['Unknown', '#111827', '#f8fafc'],
    ]) {
        assert.ok(coloredText.some(item => item.value === value && item.color === color), `${value} uses readable priority text`);
        assert.ok(fills.some(fill => fill.color === background), `${value} has a priority background`);
    }
    assert.ok(text.includes('Stock-rule buy') && text.includes('28-day sales'), 'Export separates stock-rule buying from expected sales');
    assert.ok(text.includes('Unavailable') && text.includes('0') && text.includes('42'), 'Missing, genuine zero and positive forecasts remain distinct');
    assert.equal(button.disabled, false);
    window.jspdf = undefined;
    await click();
    assert.equal(alerts, 1);
    assert.equal(button.disabled, false);
    console.log('Replenishment PDF tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
