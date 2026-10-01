<?php
// Every financial fixture lives in a disposable database, never the Store.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (getenv('RUN_CHECKOUT_DB_TESTS') !== '1') {
    echo "Checkout attempts: skipped (set RUN_CHECKOUT_DB_TESTS=1; requires CREATE DATABASE)\n";
    exit;
}
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';

$config = require __DIR__ . '/../config/database.php';
$connect = static fn(?string $database = null): PDO => new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', $config['host'], $config['port'], $database ? ';dbname=' . $database : ''),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$cart = [['product_id' => 1, 'qty' => 2]];
$details = ['checkout_attempt' => str_repeat('a', 32), 'cash_received' => 50, 'held_sale_id' => 1];
if (($argv[1] ?? '') === '--worker') {
    try {
        $database = $argv[2] ?? '';
        $assert((bool)preg_match('/^retailmind_checkout_test_[a-f0-9]{12}$/D', $database), 'Invalid fixture database');
        $service = new SalesWorkflowService($connect($database));
        echo "ready\n"; flush();
        $assert(trim((string)fgets(STDIN)) === 'go', 'Worker barrier');
        echo json_encode($service->checkout($cart, 1, 'cashier', 'cash', $details), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n";
    } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
    exit;
}

$database = 'retailmind_checkout_test_' . bin2hex(random_bytes(6));
$server = $connect();
$created = false;
$workers = [];
try {
    $server->exec("CREATE DATABASE `{$database}`");
    $created = true;
    $pdo = $connect($database);
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../sql/schema.sql'), $tables);
    $assert(count($tables[0]) > 0, 'Canonical schema must be parsed');
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $gateMigration = require __DIR__ . '/../database/migrations/202609260001_shared_database_backups.php';
    $gateMigration['up']($pdo);
    $pdo->exec('DROP TABLE checkout_attempts');
    $migration = require __DIR__ . '/../database/migrations/202610010001_checkout_attempts.php';
    $migration['up']($pdo);
    $migration['up']($pdo);
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code) VALUES (1, 'Fixture Store', 'TEST')");
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Fixture Cashier', 'checkout-test', 'unused', 1, 1), (2, 'Other Cashier', 'other-test', 'unused', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Fixture Till')");
    $pdo->exec('INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 100)');
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, branch_id) VALUES (1, 'CHECKOUT-TEST', 'Fixture Item', 25, 1)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, date_received) VALUES (1, 1, 'TEST-BATCH', 10, 10, NOW())");
    $pdo->exec("INSERT INTO held_sales (held_sale_id, cashier_id, shift_id, reference_no, cart_json, item_count, total_amount, status) VALUES (1, 1, 1, 'TEST-HELD', '[]', 2, 50, 'resumed')");
    App\Store\StoreWriteGate::begin($pdo); $pdo->commit();

    // Both requests leave the barrier together; either may win the gate race.
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $assert(is_resource($process), 'Checkout worker starts');
        $workers[] = [$process, $pipes];
        $assert(trim((string)fgets($pipes[1])) === 'ready', 'Checkout worker reaches barrier');
    }
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fflush($pipes[0]); }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[0]);
        $results[] = json_decode(trim(stream_get_contents($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
        $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Checkout worker succeeds: ' . $error);
    }
    $workers = [];
    $assert($results[0] === $results[1], 'Concurrent double submission returns the identical saved outcome');
    $service = new SalesWorkflowService($pdo);
    $assert($service->checkout($cart, 1, 'cashier', 'cash', $details) === $results[0], 'Repeated double click returns same sale');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'One sale only');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sale_receipt_details')->fetchColumn() === 1, 'One receipt only');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn() === 8, 'Inventory depleted once');
    $assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches')->fetchColumn() === 8, 'Batch depleted once');
    $assert((int)$pdo->query('SELECT sale_id FROM held_sales')->fetchColumn() === $results[0]['sale_id'], 'Held Sale completed by that sale');
    $pdo->exec("UPDATE cashier_shifts SET status='closed' WHERE shift_id=1");
    $assert($service->recoverAttempt($details['checkout_attempt'], 1, 'cashier') === $results[0], 'Lost response recovers after navigation and shift closure');
    $assert($service->checkout($cart, 1, 'cashier', 'cash', $details) === $results[0], 'Committed replay does not require another payment or open shift');
    $assert($service->recoverAttempt($details['checkout_attempt'], 2, 'cashier') === null, 'Another Cashier cannot recover the receipt');
    foreach ([[$cart, 'cash', array_replace($details, ['cash_received' => 100])], [[['product_id' => 1, 'qty' => 1]], 'cash', $details], [$cart, 'card', array_replace($details, ['payment_reference' => 'NEW'])]] as [$retryCart, $method, $retryDetails]) {
        try { $service->checkout($retryCart, 1, 'cashier', $method, $retryDetails); throw new LogicException('Incompatible reuse succeeded'); }
        catch (DomainException $e) { $assert(str_contains($e->getMessage(), 'different cart or payment'), 'Reuse explains incompatibility'); }
    }
    $pdo->exec("UPDATE cashier_shifts SET status='open' WHERE shift_id=1");

    // Failure after sale/lines/stock writes but before the customer copy commits.
    $pdo->exec("INSERT INTO held_sales (held_sale_id, cashier_id, shift_id, reference_no, cart_json, status) VALUES (2, 1, 1, 'ATOMIC-HELD', '[]', 'resumed')");
    $pdo->exec("CREATE TRIGGER fail_receipt BEFORE INSERT ON sale_receipt_details FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected receipt failure'");
    $atomic = ['checkout_attempt' => str_repeat('b', 32), 'cash_received' => 50, 'held_sale_id' => 2];
    try { $service->checkout($cart, 1, 'cashier', 'cash', $atomic); throw new LogicException('Injected failure succeeded'); }
    catch (PDOException $e) { $assert(str_contains($e->getMessage(), 'Injected receipt failure'), 'Receipt failure injected'); }
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 1, 'Partial sale rolled back');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sale_items')->fetchColumn() === 1, 'Partial lines rolled back');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM stock_movements')->fetchColumn() === 1, 'Partial movement rolled back');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn() === 8, 'Inventory rollback');
    $assert((int)$pdo->query('SELECT remaining_quantity FROM product_batches')->fetchColumn() === 8, 'Batch rollback');
    $assert($pdo->query('SELECT status FROM held_sales WHERE held_sale_id=2')->fetchColumn() === 'resumed', 'Held Sale rollback');
    $assert($service->recoverAttempt($atomic['checkout_attempt'], 1, 'cashier') === null, 'Failed attempt has no committed outcome');
    $pdo->exec('DROP TRIGGER fail_receipt');

    $notifications = new class($pdo) extends NotificationService {
        public int $calls = 0;
        public function checkAndNotifyLowStock(): void { $this->calls++; throw new RuntimeException('Injected notification failure'); }
        public function checkAndNotifyExpiringStock(int $days = 30): void { $this->calls++; throw new RuntimeException('Injected notification failure'); }
    };
    $service = new SalesWorkflowService($pdo, null, $notifications);
    $saved = $service->checkout($cart, 1, 'cashier', 'cash', $atomic);
    $assert($notifications->calls === 2, 'Both notification failures are isolated');
    $assert($saved['sale_id'] !== $results[0]['sale_id'], 'A new attempt has a new sale');
    $assert($service->recoverAttempt($atomic['checkout_attempt'], 1, 'cashier') === $saved, 'Post-commit failure still returns and recovers success');
    $receipt = (new App\Services\ReceiptDetailsService($pdo))->fetchSale($saved['sale_id']);
    $assert(isset($receipt['receipt_items']), 'Saved customer receipt remains available');
    echo "Checkout attempts integration: passed (concurrency, replay, lost response, scope, incompatible reuse, atomic rollback, notification failure)\n";
} catch (Throwable $e) { fwrite(STDERR, 'Checkout attempts integration failed: ' . $e->getMessage() . "\n"); $failed = true; }
finally {
    foreach ($workers as [$process, $pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_terminate($process); proc_close($process); }
    if ($created) $server->exec("DROP DATABASE `{$database}`");
}
exit(isset($failed) ? 1 : 0);
