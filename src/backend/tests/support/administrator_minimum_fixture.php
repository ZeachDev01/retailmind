<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../bootstrap/app.php';

use App\Attention\Clock;
use App\Authorization\DormancyRunner;
use App\Authorization\RoleCapabilityPolicy;
use App\Services\UserAccountNotifier;
use App\Services\UserLifecycleService;
use App\Store\StoreScope;

set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$database = $argv[2] ?? '';
if (!preg_match('/^retailmind_theme_test_[a-f0-9]{12}$/', $database)) throw new RuntimeException('Disposable database required.');
$config = require __DIR__ . '/../../config/database.php';
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};dbname={$database};charset=utf8mb4",
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$notifier = new class extends UserAccountNotifier {
    protected function deliver(string $email, string $subject, string $message): bool { return true; }
};
$service = new UserLifecycleService($pdo, new RoleCapabilityPolicy(), new StoreScope($pdo), $notifier);
if ($argv[1] === 'seed') {
    $pdo->exec('DELETE FROM activity_log WHERE record_id IN (100, 101)');
    $pdo->exec("DELETE FROM notifications WHERE reference_type = 'dormancy_warn'");
    $pdo->exec("UPDATE users SET created_at = NOW(), last_login_at = NOW(), email = NULL");
    $pdo->exec("DELETE FROM user_roles WHERE user_id IN (100, 101)");
    $pdo->exec("DELETE FROM users WHERE user_id IN (100, 101)");
    $pdo->exec("UPDATE users SET status = 'disabled' WHERE user_id <> 2");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id, status, must_change_password, created_at, last_login_at) VALUES
        (100, 'Minimum Primary', 'minimum_primary', 'unused', 1, 1, 'active', 0, '2026-01-01', '2026-01-01'),
        (101, 'Minimum Additional', 'minimum_additional', 'unused', 4, 1, 'active', 0, '2026-01-01', '2026-01-01')");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (100, 1, 1), (101, 4, 1), (101, 1, 0)');
} elseif ($argv[1] === 'management') {
    $pdo->exec("UPDATE users SET status = 'active', email = NULL, last_login_at = NOW() WHERE user_id IN (1, 3, 4)");
    $pdo->exec('INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (3, 1, 0), (4, 1, 0)');
} elseif ($argv[1] === 'one') {
    $pdo->exec("UPDATE users SET status = 'disabled' WHERE user_id = 100");
} elseif ($argv[1] === 'state') {
    echo json_encode(['users' => [$service->get(100), $service->get(101)],
        'audit' => $pdo->query('SELECT * FROM activity_log WHERE record_id IN (100, 101) ORDER BY log_id')->fetchAll()]);
} else {
    // Establish an old repeatable-read snapshot before the parent releases the mutex.
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    echo 'READY ' . $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n"; fflush(STDOUT);
    fgets(STDIN);
    echo "STARTED\n"; fflush(STDOUT);
    try {
        $id = (int)($argv[3] ?? 100);
        if ($argv[1] === 'disable') $service->setStatus(2, 'super_admin', $id, 'disabled');
        elseif ($argv[1] === 'demote') $service->update(2, 'super_admin', $id, array_replace($service->get($id), ['roles' => ['cashier']]));
        elseif ($argv[1] === 'dormancy') {
            $clock = new class implements Clock {
                public function now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-25 12:00:00'); }
            };
            $result = (new DormancyRunner($pdo, $clock, null, $notifier))->run();
        } else throw new RuntimeException('Unknown action.');
        $pdo->commit();
        echo json_encode($result ?? ['changed' => $id]);
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['denied' => $e->getMessage()]);
    }
}
