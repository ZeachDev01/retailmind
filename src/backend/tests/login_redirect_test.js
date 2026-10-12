// Run with: node src/backend/tests/login_redirect_test.js (PHP_BINARY may override php).
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

(async () => {
  const root = path.resolve(__dirname, '../../..');
  const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-login-redirect-'));
  const router = path.join(temp, 'router.php');
  const bootstrap = JSON.stringify(path.join(root, 'src/backend/bootstrap/app.php').replaceAll('\\', '/'));
  const landing = JSON.stringify(path.join(root, 'src/frontend/index.php').replaceAll('\\', '/'));
  fs.writeFileSync(router, `<?php
$_ENV['BACKUP_STORAGE_PATH'] = ${JSON.stringify(temp.replaceAll('\\', '/'))};
require ${bootstrap};
$_ENV['SESSION_SECURE_COOKIE'] = 'false';
App\\Core\\Session::start();
if (isset($_GET['test_role'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = $_GET['test_role'];
}
require ${landing};
`);
  const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:0', '-t', root, router]);
  try {
    const address = await new Promise((resolve, reject) => {
      server.on('error', reject);
      server.on('exit', code => reject(new Error('PHP server exited: ' + code)));
      server.stderr.on('data', chunk => {
        const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:(\d+)/);
        if (match) resolve('http://127.0.0.1:' + match[1]);
      });
    });
    const destinations = {
      super_admin: 'super_administrator/dashboard.php',
      admin: 'administrator/dashboard.php',
      inventory_manager: 'inventory_management/inventory_overview.php',
      cashier: 'cashier/pos.php',
    };
    for (const [role, destination] of Object.entries(destinations)) {
      for (const query of ['', '&login_success=1']) {
        const response = await fetch(address + '/?test_role=' + role + query, { redirect: 'manual' });
        assert.equal(response.status, 302, 'Signed-in ' + role + ' must redirect without JavaScript');
        assert.equal(response.headers.get('location'), '/src/frontend/components/' + destination);
      }
    }
    const guest = await fetch(address + '/?login_success=1', { redirect: 'manual' });
    assert.equal(guest.status, 200, 'A query parameter alone must not authenticate a guest');
    console.log('Login server redirect: passed');
  } finally {
    server.kill();
    await new Promise(resolve => server.exitCode !== null ? resolve() : server.once('exit', resolve));
    fs.rmSync(temp, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
