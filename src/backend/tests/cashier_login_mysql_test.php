<?php
// Disposable database only; exercises simultaneous first logins on the production engine.
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierLoginService;

$config = require __DIR__ . '/../config/database.php';
$connect = static fn(string $database = ''): PDO => new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
if (($argv[1] ?? '') === '--worker') {
    $database = $argv[2] ?? '';
    if (!preg_match('/^retailmind_login_test_[a-f0-9]{12}$/D', $database)) throw new RuntimeException('Disposable database required.');
    $service = new CashierLoginService($connect($database));
    echo "ready\n"; flush();
    if (trim((string)fgets(STDIN)) !== 'go') throw new RuntimeException('Worker barrier failed.');
    echo $service->request(1, 1, $argv[3]) . "\n";
    exit;
}
$database = 'retailmind_login_test_' . bin2hex(random_bytes(6));
$server = $connect();
$workers = [];
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $pdo = $connect($database);
    $pdo->exec("CREATE TABLE users(user_id INT PRIMARY KEY, session_version INT, status VARCHAR(20)) ENGINE=InnoDB;
        INSERT INTO users VALUES(1,1,'active')");
    $migration = require __DIR__ . '/../database/migrations/202611260001_cashier_login_sessions.php';
    $migration['up']($pdo);
    foreach (['device-a', 'device-b'] as $token) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, $token],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Worker did not start.');
        $workers[] = [$process, $pipes];
        if (trim((string)fgets($pipes[1])) !== 'ready') throw new RuntimeException('Worker did not reach barrier.');
    }
    foreach ($workers as [, $pipes]) { fwrite($pipes[0], "go\n"); fclose($pipes[0]); }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException($error);
    }
    $workers = [];
    sort($results);
    if ($results !== ['ready', 'waiting']) throw new RuntimeException('Simultaneous logins must issue exactly one active owner: ' . json_encode($results));
    if ((int)$pdo->query('SELECT COUNT(*) FROM cashier_login_sessions')->fetchColumn() !== 1) throw new RuntimeException('Only one ownership row allowed.');
    echo "Cashier login MySQL race: passed\n";
} finally {
    foreach ($workers as [$process, $pipes]) {
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    $server->exec("DROP DATABASE IF EXISTS `{$database}`");
}
