const fs = require('node:fs');
const {execFileSync,spawnSync} = require('node:child_process');
let src=execFileSync('git',['show','HEAD:src/backend/tests/smoke_checks.php'],{encoding:'utf8'});
src=src.replace("$text = is_readable($path) ? file_get_contents($path) : '';", "$text = (string)shell_exec('git show ' . escapeshellarg('HEAD:' . $check['file']));");
fs.writeFileSync('output/playwright/shift-notices/baseline-smoke.php',src);
const result=spawnSync('php',['output/playwright/shift-notices/baseline-smoke.php'],{encoding:'utf8'});
console.log(result.stdout+result.stderr); console.log('Baseline exit: '+result.status);
