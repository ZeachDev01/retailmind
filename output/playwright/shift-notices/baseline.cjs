const fs = require('node:fs');
const {execFileSync} = require('node:child_process');
const src = execFileSync('git', ['show', 'HEAD:src/backend/tests/cashier_alert_contract.php'], {encoding:'utf8'})
 .replace("__DIR__ . '/../bootstrap/app.php'", "'" + process.cwd().replaceAll('\\','/') + "/src/backend/bootstrap/app.php'")
 .replace("$read = static fn(string $relative): string => (string)@file_get_contents($root . '/' . $relative);", "$read = static fn(string $relative): string => (string)shell_exec('git show ' . escapeshellarg('HEAD:' . $relative));");
fs.writeFileSync('output/playwright/shift-notices/baseline-alert.php',src);
const result = require('node:child_process').spawnSync('php', ['output/playwright/shift-notices/baseline-alert.php'], {encoding:'utf8'});
console.log(result.stdout + result.stderr);
console.log('Baseline exit: ' + result.status);
