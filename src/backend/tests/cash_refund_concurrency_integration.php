<?php
// Creates and drops its own database; never imports over the configured Store.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
if (getenv('RUN_REFUND_DB_TESTS') !== '1') {
    echo "Cash Refund concurrency: skipped (set RUN_REFUND_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit(0);
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SaleReversalService.php';

use App\Services\CashierShiftService;
use App\Services\CashRefundService;

$config = require __DIR__ . '/../config/database.php';
$connect = static function (?string $database = null) use ($config): PDO {
    return new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s',
        $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
};
$items = [1 => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]];
if (($argv[1] ?? '') === '--worker') {
    try {
        $database = $argv[2] ?? '';
        if (!preg_match('/^retailmind_refund_test_[a-f0-9]{12}$/', $database)) {
            throw new RuntimeException('Invalid disposable database name.');
        }
        $pdo = $connect($database);
        echo "ready\n";
        flush();
        if (trim((string)fgets(STDIN)) !== 'go') {
            exit(1);
        }
        $service = new CashRefundService($pdo, new CashierShiftService($pdo));
        $service->refund(1, 'cashier', 1, $items, 'customer_return');
        echo "refunded\n";
    } catch (DomainException $e) {
        echo "refused\n";
    } catch (PDOException $e) {
        // The Store write gate can reject the competing writer immediately.
        if (!in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true)) {
            fwrite(STDERR, $e->getMessage());
            exit(1);
        }
        echo "refused\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
    exit(0);
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$database = 'retailmind_refund_test_' . bin2hex(random_bytes(6));
$server = $connect();
$created = false;
$workers = [];
$failed = false;
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $created = true;
    $pdo = $connect($database);
    // Use the production DDL, without seed accounts or destructive dump commands.
    $schema = file_get_contents(__DIR__ . '/../sql/schema.sql');
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', $schema, $tables);
    $assert(count($tables[0]) > 0, 'Canonical table definitions must be present.');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) {
        $pdo->exec($ddl);
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $migration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
    $migration['up']($pdo);
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Test Store', 'TEST')");
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id)
        VALUES (1, 'Test Cashier', 'refund-test', 'unused', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Test Register')");
    $pdo->exec('INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 100)');
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, branch_id) VALUES (1, 'REFUND-TEST', 'Test item', 25, 1)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 9)');
    $pdo->exec("INSERT INTO sales (sale_id, cashier_id, shift_id, total_amount, payment_method) VALUES (1, 1, 1, 25, 'cash')");
    $pdo->exec('INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (1, 1, 1, 1, 25, 25)');
    App\Store\StoreWriteGate::begin($pdo);
    $pdo->commit();

    // Hold the sale while both workers enter the refund workflow. One waits on
    // this row, the other must wait or lose the Store write-gate race.
    $pdo->beginTransaction();
    $pdo->query('SELECT sale_id FROM sales WHERE sale_id=1 FOR UPDATE')->fetch();
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $assert(is_resource($process), 'Refund worker must start.');
        $workers[] = [$process, $pipes];
        $assert(trim((string)fgets($pipes[1])) === 'ready', 'Refund worker must connect.');
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], "go\n");
        fflush($pipes[0]);
    }
    usleep(300000);
    $pdo->commit();
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[0]);
        $results[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Refund worker failed: ' . $error);
    }
    $workers = [];
    sort($results);
    $assert($results === ['refunded', 'refused'], 'Exactly one competing refund must succeed.');
    $service = new CashRefundService($pdo, new CashierShiftService($pdo));
    $assert($service->refundableQuantity(1) === 0, 'The last unit can only be returned once.');
    $assert($service->refundableAmount(1) === 0.0, 'The sale amount can only be refunded once.');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=1')->fetchColumn() === 10,
        'The inventory must be restored exactly once.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module='Cash Refunds'")->fetchColumn() === 1,
        'Only the successful refund creates an audit record.');
    $assert((float)(new CashierShiftService($pdo))->calculateShift(1)['calculated_expected_cash'] === 100.0,
        'Only one cash payout leaves the drawer.');

    $legacy = new SaleReversalService($pdo);
    try {
        $legacy->requestReversal(1, 'refund', 'Duplicate return', [1 => 1], 1, 'cash', 25);
        throw new LogicException('Legacy requests must not refund a Cash Refund again.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Cash Refund'), 'Legacy refusal must identify the existing Cash Refund.');
    }
    // A pending request left by an older deployment must also be refused.
    $pdo->exec("INSERT INTO sale_reversals (reversal_id, sale_id, reversal_type, reason, requested_by)
        VALUES (1, 1, 'refund', 'Older request', 1)");
    try {
        $legacy->approveReversal(1, 1);
        throw new LogicException('Legacy approval must not refund a Cash Refund again.');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Cash Refund'), 'Legacy approval must identify the existing Cash Refund.');
    }
    echo "Cash Refund concurrency and legacy reversal isolation: passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Cash Refund database test failed: {$e->getMessage()}\n");
    $failed = true;
} finally {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($workers as [$process, $pipes]) {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
    if ($created) {
        $server->exec("DROP DATABASE `{$database}`");
    }
}
exit($failed ? 1 : 0);
