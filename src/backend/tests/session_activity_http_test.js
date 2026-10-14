const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
(async () => {
    const temp = fs.mkdtempSync(path.join(os.tmpdir(), 'rm-session-http-'));
    const phpPath = file => JSON.stringify(path.resolve(__dirname, file).replaceAll('\\', '/'));
    const router = path.join(temp, 'router.php');
    fs.writeFileSync(router, `<?php
putenv('BACKUP_STORAGE_PATH=' . ${JSON.stringify(temp.replaceAll('\\', '/'))});
require ${phpPath('../bootstrap/app.php')};
$_ENV['SESSION_SAVE_PATH']=${JSON.stringify(temp.replaceAll('\\', '/') + '/sessions')};
$_ENV['SESSION_LIFETIME']='3600'; $_ENV['SESSION_ABSOLUTE_LIFETIME']='43200';
$_ENV['SESSION_SECURE_COOKIE']='false';
$_SERVER['SCRIPT_NAME']='/components/auth/session_activity.php';
if (isset($_GET['seed'])) {
 App\\Core\\Session::start();
 $_SESSION=['user_id'=>1,'role'=>'cashier','session_version'=>1,'csrf_token'=>'fixture-csrf',
 '_authenticated_at'=>time()-(int)($_GET['age']??100), '_last_activity'=>time()-(int)($_GET['idle']??50),
 '_restore_epoch'=>App\\Backup\\RecoveryStore::epoch()];
 echo json_encode($_SESSION); exit;
}
if (isset($_GET['inspect'])) { App\\Core\\Session::start(); echo json_encode($_SESSION); exit; }
$pdo=new PDO('sqlite::memory:');
$pdo->exec("CREATE TABLE roles (role_id INTEGER, role_name TEXT); INSERT INTO roles VALUES(1,'cashier');
 CREATE TABLE users(user_id INTEGER,role_id INTEGER,status TEXT,session_version INTEGER,full_name TEXT,profile_image TEXT,must_change_password INTEGER,is_recovery_account INTEGER);
 INSERT INTO users VALUES(1,1,'active',1,'Cashier',NULL,1,0);
 CREATE TABLE user_roles(user_id INTEGER,role_id INTEGER,is_primary INTEGER); INSERT INTO user_roles VALUES(1,1,1);");
(new ReflectionProperty(App\\Core\\Database::class,'connection'))->setValue(null,$pdo);
require ${phpPath('../../frontend/components/auth/session_activity.php')};
`);
    const server = spawn(process.env.PHP_BINARY || 'php', ['-S', '127.0.0.1:0', router]);
    try {
        const origin = await new Promise((resolve, reject) => {
            server.on('error', reject);
            server.on('exit', code => reject(new Error('PHP server exited: ' + code)));
            server.stderr.on('data', chunk => {
                const match = chunk.toString().match(/http:\/\/127\.0\.0\.1:(\d+)/);
                if (match) resolve('http://127.0.0.1:' + match[1]);
            });
        });
        const seed = await fetch(origin + '/?seed=1');
        const cookie = seed.headers.get('set-cookie').split(';')[0];
        const initial = await seed.json();
        const request = (url = '/', options = {}) => fetch(origin + url, {redirect:'manual', ...options, headers:{Cookie:cookie,...options.headers}});
        assert.equal((await request()).status, 200, 'Forced password page can check its login');
        assert.equal((await request('/', {method:'POST', body:'csrf_token=wrong', headers:{'Content-Type':'application/x-www-form-urlencoded'}})).status,403);
        const invalid = await (await request('/?inspect=1')).json();
        assert.equal(invalid._last_activity, initial._last_activity, 'Invalid CSRF cannot extend login');
        assert.equal(invalid._authenticated_at, initial._authenticated_at, 'Invalid CSRF cannot reset login age');
        assert.equal((await request('/', {method:'POST', body:'csrf_token=fixture-csrf', headers:{'Content-Type':'application/x-www-form-urlencoded'}})).status,200);
        const active = await (await request('/?inspect=1')).json();
        assert.ok(active._last_activity > initial._last_activity, 'Valid staff activity extends idle limit');
        assert.equal(active._authenticated_at, initial._authenticated_at, 'Activity never resets twelve-hour limit');
        await request('/?seed=1&idle=3600');
        assert.equal((await request('/', {method:'POST', body:'csrf_token=fixture-csrf'})).status,401,'Expired activity cannot revive login');
        await request('/?seed=1&age=43200&idle=0');
        assert.equal((await request()).status,401,'Automatic check enforces absolute expiry');
        console.log('Staff session activity HTTP: passed');
    } finally { server.kill(); fs.rmSync(temp,{recursive:true,force:true}); }
})().catch(error=>{console.error(error);process.exitCode=1;});
