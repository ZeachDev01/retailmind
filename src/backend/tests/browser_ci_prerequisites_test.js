const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const path = require('node:path');

for (const name of ['inventory_counts_scan', 'stock_receiving_scan', 'stock_receiving_empty_catalog', 'forecast_decision']) {
    for (const ci of ['', 'true']) {
        const result = spawnSync(process.execPath, [path.join(__dirname, name + '_browser_test.js')], {
            env: {...process.env, RUN_DB_TESTS: '0', CI: ci},
            encoding: 'utf8',
            timeout: 10000,
        });
        assert.ifError(result.error);
        assert.match(result.stdout, /skipped/);
        assert.equal(result.status, ci ? 1 : 0, name + ': ' + result.stderr);
    }
}
console.log('Browser prerequisites: optional locally, required in CI.');
