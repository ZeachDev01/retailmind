<?php
// Two real connections race for the last sealed Sales Pack. Creates and drops
// its own disposable database; never touches the configured Store database.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_DB_TESTS') !== '1') {
    echo "Last-pack concurrency: skipped (set RUN_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit(0);
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';

$config = require __DIR__ . '/../config/database.php';
$connect = static fn(?string $database = null): PDO => new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
    $config['username'], $config['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$cart = [['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1]];
if (($argv[1] ?? '') === '--worker') {
    try {
        $database = $argv[2] ?? '';
        $assert((bool)preg_match('/^retailmind_packrace_test_[a-f0-9]{12}$/D', $database), 'Invalid fixture database');
        $service = new \SalesWorkflowService($connect($database));
        $details = ['cash_received' => 200];
        $details['reviewed_quote'] = $service->reviewQuote($cart, 1, 'cashier')['state_hash'];
        echo "ready\n";
        flush();
        $assert(trim((string)fgets(STDIN)) === 'go', 'Worker barrier');
        try {
            $service->checkout($cart, 1, 'cashier', 'cash', $details);
            echo "sold\n";
        } catch (\StockConflictException) {
            echo "conflict\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
    exit(0);
}

$database = 'retailmind_packrace_test_' . bin2hex(random_bytes(6));
$server = $connect();
$created = false;
$workers = [];
$failed = false;
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $created = true;
    $pdo = $connect($database);
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../database/sql/schema.sql'), $tables);
    $assert(count($tables[0]) > 0, 'Canonical schema must be parsed');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) { $pdo->exec($ddl); }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $gate = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
    $gate['up']($pdo);

    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Fixture Store', 'TEST')");
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Fixture Cashier', 'packrace-test', 'unused', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Fixture Till')");
    $pdo->exec('INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 100)');
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, pack_size, pack_barcode, pack_price, branch_id)
        VALUES (1, 'PACKRACE', 'PACKRACE-PC', 'Last Pack Biscuit', 10, 6, 12, 'PACKRACE-PK', 100, 1)");
    // Exactly one sealed pack; no loose stock can satisfy a sealed-pack sale.
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 12)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, expiration_date)
        VALUES (1, 1, 'LAST-PACK', 12, 12, 1, 0, DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
    App\Store\StoreWriteGate::begin($pdo);
    $pdo->commit();

    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $assert(is_resource($process), 'Last-pack worker must start.');
        $workers[] = [$process, $pipes];
        $ready = trim((string)fgets($pipes[1]));
        if ($ready !== 'ready') {
            throw new RuntimeException('Last-pack worker ' . $i . ' failed to reach the barrier: ' . trim((string)stream_get_contents($pipes[2])));
        }
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], "go\n");
        fflush($pipes[0]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[0]);
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Last-pack worker failed: ' . $error);
    }
    $workers = [];
    sort($results);
    $assert($results === ['conflict', 'sold'], 'Exactly one of two competing checkouts for the final sealed pack succeeds (' . implode(',', $results) . ')');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Only one sale commits');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE selling_form = 'pack' AND pack_size = 12 AND quantity = 1")->fetchColumn() === 1, 'The winning sale records one historical 12-piece pack line');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 0, 'Inventory holds no phantom stock after the race');
    $batch = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 1')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$batch['remaining_quantity'] === 0 && (int)$batch['sealed_quantity'] === 0 && (int)$batch['loose_quantity'] === 0, 'The batch depletes exactly once with no leftover form counts');
    $movement = $pdo->query("SELECT change_qty FROM stock_movements WHERE product_id = 1 AND reason = 'sale' ORDER BY movement_id DESC LIMIT 1")->fetchColumn();
    $assert((int)$movement === -12, 'Only one sale movement of -12 Base Units is recorded');
    echo "Last-pack concurrency: passed (two real connections, one sealed pack)\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Last-pack concurrency failed: ' . $e->getMessage() . "\n");
    $failed = true;
} finally {
    foreach ($workers as [$process, $pipes]) {
        foreach ($pipes as $pipe) { if (is_resource($pipe)) fclose($pipe); }
        proc_terminate($process);
        proc_close($process);
    }
    if ($created) { $server->exec("DROP DATABASE `{$database}`"); }
}
exit($failed ? 1 : 0);
