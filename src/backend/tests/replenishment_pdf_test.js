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
    const button = { addEventListener(event, callback) { click = callback; } };
    const context = {
        fillRect() {}, fillText(value) { text.push(value); },
        measureText(value) { return { width: Array.from(value).length * 10 }; },
        beginPath() {}, moveTo() {}, lineTo() {}, stroke() {},
    };
    const rows = Array.from({ length: 100 }, (_, i) => ({
        hidden: true,
        dataset: { stock: 'Low stock', name: `商品 café (${i})`, sku: `SKU-${i}`, onHand: '2', quantity: '10' },
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
        alert() { alerts++; },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../frontend/assets/js/replenishment-pdf.js'), 'utf8'), { document, window, console: { error() {} } });
    await click();
    assert.equal(filename, 'replenishment-recommendations.pdf');
    assert.ok(pages > 1);
    assert.equal(images, pages);
    assert.ok(text.includes('商品 café (99)'), 'Export includes hidden rows and Unicode names');
    assert.equal(button.disabled, false);
    window.jspdf = undefined;
    await click();
    assert.equal(alerts, 1);
    assert.equal(button.disabled, false);
    console.log('Replenishment PDF tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
