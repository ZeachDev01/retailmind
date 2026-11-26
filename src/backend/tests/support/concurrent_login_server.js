const {spawn, spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

module.exports = async function startServer() {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-concurrent-login-'));
    const database = process.env.RUN_DB_TESTS === '1' ? 'retailmind_theme_test_' + randomBytes(6).toString('hex') : '';
    const env = {...process.env, RM_CONCURRENT_TEST_DIR: temp, BACKUP_STORAGE_PATH: temp, RM_CONCURRENT_TEST_DATABASE: database};
    const fixture = action => {
        const result = spawnSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'theme_fixture.php'), action, database], {env, encoding: 'utf8'});
        if (result.status !== 0) throw new Error(result.stderr || result.stdout);
    };
    if (database) fixture('create');
    const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:0', '-t', path.resolve(__dirname, '../../../frontend'), path.join(__dirname, 'concurrent_login_router.php')], {
        env,
    });
    let errors = '';
    server.stderr.on('data', chunk => { errors += chunk; });
    const origin = await new Promise((resolve, reject) => {
        server.on('error', reject);
        server.on('exit', code => reject(new Error(`PHP server exited: ${code}\n${errors}`)));
        server.stderr.on('data', chunk => {
            const match = String(chunk).match(/http:\/\/127\.0\.0\.1:(\d+)/);
            if (match) resolve(match[0]);
        });
    });
    return {origin, username: role => database ? 'theme_' + role : role,
        password: database ? 'ThemeTest@2026' : 'Fixture-password-123!', errors: () => errors, async close() {
        const stopped = new Promise(resolve => server.once('exit', resolve));
        server.kill();
        await stopped;
        if (database) fixture('cleanup');
        fs.rmSync(temp, {recursive: true, force: true});
    }};
};

if (require.main === module) {
    module.exports().then(server => {
        console.log(server.origin);
        const close = async () => { await server.close(); process.exit(); };
        process.on('SIGINT', close);
        process.on('SIGTERM', close);
    }).catch(error => { console.error(error); process.exitCode = 1; });
}
