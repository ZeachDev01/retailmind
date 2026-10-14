<?php
$private = sys_get_temp_dir() . '/rm-session-policy-' . bin2hex(random_bytes(6));
putenv('BACKUP_STORAGE_PATH=' . $private);
require_once __DIR__ . '/../bootstrap/app.php';
$_ENV['SESSION_SAVE_PATH'] = $private . '/sessions';
$_ENV['SESSION_LIFETIME'] = '3600';
$_ENV['SESSION_ABSOLUTE_LIFETIME'] = '43200';
use App\Core\Session;
function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
try {
    Session::start();
    $_SESSION['user_id'] = 1;
    $_SESSION['_authenticated_at'] = time() - 43200;
    $_SESSION['_last_activity'] = time();
    Session::start();
    check(Session::get('user_id') === null, 'Twelve hours requires fresh login even while active');
    $_SESSION = ['user_id' => 1, '_authenticated_at' => time(), '_last_activity' => time() - 3600, '_restore_epoch' => App\Backup\RecoveryStore::epoch()];
    Session::start();
    check(Session::get('user_id') === null, 'One hour without activity expires at the boundary');
    $_SESSION = ['user_id' => 1, '_authenticated_at' => time(), '_last_activity' => time() - 50, '_restore_epoch' => App\Backup\RecoveryStore::epoch()];
    $_SERVER['SCRIPT_NAME'] = '/retailmind/components/backup/backup_status.php';
    Session::start();
    check(Session::get('_last_activity') <= time() - 50, 'Backup polling does not extend inactivity');
    $_SERVER['SCRIPT_NAME'] = '/components/administrator/dashboard.php';
    $_SERVER['HTTP_X_RETAILMIND_BACKGROUND'] = '1';
    Session::start();
    check(Session::get('_last_activity') <= time() - 50, 'Prefetch and dashboard refresh do not extend inactivity');
    unset($_SERVER['HTTP_X_RETAILMIND_BACKGROUND']);
    $_SERVER['SCRIPT_NAME'] = '/components/barcodeScanner/apiScanner/pairing.php';
    $_GET['action'] = 'poll';
    Session::start();
    check(Session::get('_last_activity') <= time() - 50, 'Scanner polling does not extend inactivity');
    unset($_GET['action']);
    $_SERVER['SCRIPT_NAME'] = '/components/cashier/pos.php';
    Session::start();
    check(Session::get('_last_activity') === time(), 'Deliberate navigation extends inactivity');
    $_SESSION = ['user_id' => 1, '_last_activity' => time(), '_restore_epoch' => App\Backup\RecoveryStore::epoch()];
    Session::start();
    check(Session::get('user_id') === null, 'Unknown login age requires fresh authentication');
    App\Backup\RecoveryStore::write('session-epoch', 'after-restore');
    Session::start();
    $_SESSION['user_id'] = 1;
    $_SESSION['_authenticated_at'] = time() - 43200;
    $_SESSION['_last_activity'] = time();
    Session::start();
    $_SESSION['user_id'] = 1;
    $_SESSION['_authenticated_at'] = time();
    $_SESSION['_last_activity'] = time();
    Session::start();
    check(Session::get('user_id') === 1, 'Fresh login after timeout retains the database restore generation');
    Session::destroy();
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); } finally {
    foreach (glob($private . '/sessions/*') ?: [] as $file) { @unlink($file); }
    @rmdir($private . '/sessions');
    foreach (glob($private . '/*') ?: [] as $file) { if (is_file($file)) { @unlink($file); } }
    @rmdir($private);
}
echo "Staff session policy: passed\n";
