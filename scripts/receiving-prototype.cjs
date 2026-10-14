// Throwaway localhost preview, with no database or application requests.
const { spawn } = require('node:child_process');
const path = require('node:path');
console.log('Prototype #176: http://127.0.0.1:8176/components/report/stock_receiving.php?variant=A');
const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:8176', path.join(__dirname, 'receiving-prototype-router.php')], {
  cwd: path.resolve(__dirname, '..'), stdio: 'inherit', env: { ...process.env, RECEIVING_PROTOTYPE: '1' }
});
server.on('error', error => { console.error(error.message); process.exitCode = 1; });
server.on('exit', code => { process.exitCode = code ?? 1; });
process.on('SIGINT', () => server.kill());
