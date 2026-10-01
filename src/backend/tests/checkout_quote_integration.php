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
if (($argv[1] ?? '') === '--worker') {
    try {
        $database = $argv[2] ?? '';
        $assert((bool)preg_match('/^retailmind_checkout_test_[a-f0-9]{12}$/D', $database), 'Invalid fixture database');
        $service = new SalesWorkflowService($connect($database));
        echo "ready\n"; flush();
        $assert(trim((string)fgets(STDIN)) === 'go', 'Worker barrier');
        echo json_encode($service->checkout([['product_id' => 1, 'qty' => 4]], 1, 'cashier', 'cash',
            ['cash_received' => 120, 'payment_method' => 'cash', 'checkout_attempt' => str_repeat('d', 32), 'reviewed_quote' => $argv[3]]), JSON_THROW_ON_ERROR) . "\n";
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

    $pdo->exec('UPDATE inventory SET quantity_on_hand=100');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=100, quantity=100');
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (2, 'admin'), (3, 'super_admin')");
    $password = password_hash('fixture-secret', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (3, 'Fixture Administrator', 'approver', ?, 2, 1), (4, 'Fixture Super Administrator', 'technical', ?, 3, 1)")->execute([$password, $password]);
    $service = new SalesWorkflowService($pdo);
    $cart = [['product_id' => 1, 'qty' => 4]];
    $details = ['payment_method' => 'cash', 'cash_received' => 120];
    $review = static fn(array $inputs = []): array => $service->reviewQuote($cart, 1, 'cashier', array_replace($details, $inputs));
    $save = static function (array $quote, array $inputs = [], string $method = 'cash') use ($service, $cart, $details): array {
        return $service->checkout($cart, 1, 'cashier', $method, array_replace($details,
            ['checkout_attempt' => bin2hex(random_bytes(16)), 'reviewed_quote' => $quote['state_hash']], $inputs));
    };
    $refuse = static function (callable $operation, string $contains) use ($assert, $pdo): void {
        $before = [(int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(), (int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn()];
        try { $operation(); throw new LogicException('Expected refusal: ' . $contains); }
        catch (DomainException | RuntimeException $e) { $assert(str_contains($e->getMessage(), $contains), $e->getMessage()); }
        $assert($before === [(int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn(), (int)$pdo->query('SELECT quantity_on_hand FROM inventory')->fetchColumn()], 'Refusal moves no financial or inventory records');
    };
    $quote = $review();
    $assert($quote['total'] === 100.0 && $quote['sale']['items'][0]['unit_price'] === 25.0, 'Server quote uses current price and quantities');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details + ['checkout_attempt' => bin2hex(random_bytes(16))]), 'Review the final quote again');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0, 'Review records no sale');
    $pdo->exec('UPDATE products SET unit_price=26 WHERE product_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $pdo->exec('UPDATE products SET unit_price=25 WHERE product_id=1');
    $quote = $review();
    $pdo->exec('UPDATE inventory SET quantity_on_hand=99 WHERE product_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $pdo->exec('UPDATE product_batches SET remaining_quantity=99 WHERE batch_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $pdo->exec("INSERT INTO promotions (promotion_name, discount_type, discount_value, scope, starts_at, ends_at, created_by) VALUES ('Fixture promotion', 'percentage', 20, 'all', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 3)");
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $assert($quote['total'] === 80.0 && count($quote['eligible_promotions']) === 1, 'Best eligible promotion is reviewed');
    $pdo->exec("UPDATE promotions SET promotion_name='Renamed same amount' WHERE promotion_id=1");
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $manual = ['discount_type' => 'percentage', 'discount_value' => 15, 'discount_reason' => 'Fixture adjustment'];
    $quote = $review($manual);
    $saved = $save($quote, $manual);
    $assert($saved['total'] === 80.0 && $saved['discount_amount'] === 20.0, 'A displaced above-threshold manual discount needs no approval and never stacks');
    $pdo->exec("UPDATE promotions SET status='inactive'");
    $boundary = array_replace($manual, ['discount_value' => 10]);
    $quote = $review($boundary);
    $assert($save($quote, $boundary)['total'] === 90.0, 'Exactly 10% requires no approval');
    $above = array_replace($boundary, ['discount_value' => 10.01]);
    $refuse(static fn() => $review($above), 'Administrator approval is required');
    $approved = array_replace($above, ['discount_approver_username' => 'approver', 'discount_approver_password' => 'fixture-secret']);
    $quote = $review($approved);
    $changedInput = array_replace($approved, ['discount_reason' => 'Different sale reason']);
    $refuse(static fn() => $save($quote, $changedInput), 'Review the final quote again');
    $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=3')->execute([password_hash('changed-secret', PASSWORD_DEFAULT)]);
    $refuse(static fn() => $save($quote, $approved), 'Administrator approval failed');
    $changedPassword = array_replace($approved, ['discount_approver_password' => 'changed-secret']);
    $refuse(static fn() => $save($quote, $changedPassword), 'Review the final quote again');
    $quote = $review($changedPassword);
    $pdo->exec("UPDATE users SET status='inactive' WHERE user_id=3");
    $refuse(static fn() => $save($quote, $changedPassword), 'Administrator approval failed');
    $pdo->exec("UPDATE users SET status='active' WHERE user_id=3");
    $quote = $review($changedPassword);
    $saved = $save($quote, $changedPassword);
    $assert(abs($saved['total'] - 89.99) < 0.001, 'Applied approved manual discount recorded');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE action='Approved sale discount'")->fetchColumn() === 1, 'Approval is audited against saved sale and reviewed hash');
    $technical = array_replace($above, ['discount_approver_username' => 'technical', 'discount_approver_password' => 'fixture-secret']);
    $refuse(static fn() => $review($technical), 'requires active Emergency Access');
    $pdo->prepare("INSERT INTO emergency_access_sessions (actor_user_id, reason, activated_at, expires_at, duration_minutes) VALUES (4, 'Fixture operational emergency', ?, ?, 15)")
        ->execute([gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s', time()+900)]);
    $quote = $review($technical);
    $pdo->exec("UPDATE emergency_access_sessions SET status='revoked'");
    $refuse(static fn() => $save($quote, $technical), 'requires active Emergency Access');
    $pdo->exec("UPDATE emergency_access_sessions SET status='active'");
    $quote = $review($technical);
    $save($quote, $technical);
    $metadata = json_decode($pdo->query("SELECT metadata FROM activity_log ORDER BY log_id DESC LIMIT 1")->fetchColumn(), true);
    $assert($metadata['emergency_access_session_id'] === (int)$pdo->query('SELECT session_id FROM emergency_access_sessions')->fetchColumn(), 'Emergency approval retains session attribution');
    $quote = $review();
    $refuse(static fn() => $save($quote, ['cash_received' => 99.99]), 'less than the sale total');
    $refuse(static fn() => $save($quote, ['cash_received' => 'bad']), 'valid cash amount');
    $refuse(static fn() => $save($quote, [], 'other'), 'Choose cash, card or e-wallet');
    $saved = $save($quote);
    $payment = $pdo->query('SELECT cash_received, change_due FROM sales WHERE sale_id=' . $saved['sale_id'])->fetch();
    $assert((float)$payment['cash_received'] === 120.0 && (float)$payment['change_due'] === 20.0, 'Cash covers confirmed amount and change is derived');
    foreach (['card', 'ewallet'] as $method) {
        $inputs = ['payment_method' => $method, 'payment_reference' => 'EXTERNAL-' . $method, 'payment_verified' => true];
        $quote = $review($inputs);
        $refuse(static fn() => $save($quote, array_replace($inputs, ['payment_reference' => '']), $method), 'Payment reference is required');
        $refuse(static fn() => $save($quote, array_replace($inputs, ['payment_verified' => false]), $method), 'Verify the external payment');
        $withoutVerification = $inputs; unset($withoutVerification['payment_verified']);
        $refuse(static fn() => $save($quote, $withoutVerification, $method), 'Verify the external payment');
        $save($quote, $inputs, $method);
    }
    // Reviewed state changes retain the attempt identity without saving a sale.
    $retry = ['checkout_attempt' => str_repeat('c', 32), 'cash_received' => 120, 'payment_method' => 'cash'];
    $quote = $review($retry);
    $pdo->exec('UPDATE products SET unit_price=26');
    $refuse(static fn() => $save($quote, $retry), 'Review the final quote again');
    $assert($service->recoverAttempt($retry['checkout_attempt'], 1, 'cashier') === null, 'Changed quote creates no committed attempt');
    $quote = $review($retry);
    $result = $save($quote, $retry);
    $pdo->exec('UPDATE products SET unit_price=27');
    $assert($service->checkout($cart, 1, 'cashier', 'cash', $retry + ['reviewed_quote' => $quote['state_hash']]) === $result, 'Lost response recovers original saved outcome despite later quote changes');
    $quote = $review();
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $database, $quote['state_hash']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $assert(is_resource($process), 'Reviewed checkout worker starts');
        $workers[] = [$process, $pipes];
        $assert(trim((string)fgets($pipes[1])) === 'ready', 'Reviewed checkout worker reaches barrier');
    }
    foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "go\n"); fflush($pipes[0]); }
    $outcomes = [];
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[0]);
        $outcomes[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Reviewed checkout worker succeeds: ' . $error);
    }
    $workers = [];
    $assert($outcomes[0] === $outcomes[1], 'Concurrent same-quote submissions return identical outcome after stock moves');
    echo "Reviewed checkout integration: passed (price, physical/batch stock, promotions including same amount, boundaries, displaced manual, approval misuse/revocation/Emergency Access, cash/reference/verification, changed quote, lost-response retry, concurrent reviewed submission)\n";
} catch (Throwable $e) { fwrite(STDERR, 'Reviewed checkout integration failed: ' . $e->getMessage() . "\n"); $failed = true; }
finally {
    foreach ($workers as [$process, $pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_terminate($process); proc_close($process); }
    if ($created) $server->exec("DROP DATABASE `{$database}`");
}
exit(isset($failed) ? 1 : 0);
