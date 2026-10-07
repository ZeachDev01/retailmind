const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const file = path.resolve('src/backend/tests/theme_account_browser_test.js');
const source = fs.readFileSync(file, 'utf8');
const start = source.indexOf("        for (const role of receiptsOnly ?");
const end = source.indexOf('        const fresh =', start);
if (start < 0 || end < 0) throw new Error('Account test boundaries changed');
const setup = `
        for (const role of Object.keys(savedModes)) {
            await login(role);
            await select(savedModes[role]);
            await page.goto('/components/auth/logout.php');
        }
`;
const test = new Module(file);
test.filename = file;
test.paths = Module._nodeModulePaths(path.dirname(file));
// Focused run omits the three sales created by the full receipt workflow.
test._compile((source.slice(0, start) + setup + source.slice(end)).replace('finalState.sales,4', 'finalState.sales,1'), file);
