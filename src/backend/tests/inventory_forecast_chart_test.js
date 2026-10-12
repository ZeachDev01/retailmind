const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../frontend/components/inventory_management/inventory_overview.php'), 'utf8');
const start = source.indexOf('    if (forecastChartInstance) forecastChartInstance.destroy();');
const end = source.indexOf("    const statusCtx =", start);
assert.ok(start >= 0 && end > start, 'Forecast initialization must exist');
const initialization = '{' + source.slice(start, end) + '}';
const colors = { textColor: '#111', mutedColor: '#555', gridColor: '#ddd' };
const forecast = { labels: ['Product A', 'Product B'], stock: [0, 50], demand7: [3, 0], demand30: [12, 0] };
let created = 0;
let destroyed = 0;
const context = vm.createContext({
    colors,
    inventoryChartData: { forecast },
    forecastChartInstance: null,
    document: { getElementById: () => ({}) },
    Chart: function (canvas, config) {
        created++;
        assert.equal(config.type, 'bar');
        assert.equal(config.data.labels, forecast.labels);
        assert.equal(config.data.datasets[0].data, forecast.stock);
        assert.equal(config.data.datasets[1].data, forecast.demand7);
        assert.equal(config.data.datasets[2].data, forecast.demand30);
        assert.equal(config.options.scales.y.beginAtZero, true);
        this.destroy = () => destroyed++;
    }
});
vm.runInContext(initialization, context);
vm.runInContext(initialization, context);
assert.equal(created, 2);
assert.equal(destroyed, 1, 'Reinitializing must destroy the previous chart');
context.document.getElementById = () => null;
vm.runInContext(initialization, context);
assert.equal(created, 2, 'An empty forecast must not create a chart');
console.log('Inventory forecast chart checks passed');
