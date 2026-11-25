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
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../database/sql/schema.sql'), $tables);
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
    $knownCosts = require __DIR__ . '/../database/migrations/202611170001_known_product_costs.php';
    $knownCosts['up']($pdo);
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier')");
    $pdo->exec("INSERT INTO users (user_id, display_name, username, password_hash, role_id) VALUES (1, 'Fixture Cashier', 'checkout-test', 'unused', 1), (2, 'Other Cashier', 'other-test', 'unused', 1)");
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status, created_by) VALUES ('Fixture period', CURDATE() - INTERVAL 1 DAY, CURDATE() + INTERVAL 1 YEAR, 'open', 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Fixture Till')");
    $pdo->exec('INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash) VALUES (1, 1, 1, 100)');
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price) VALUES (1, 'CHECKOUT-TEST', 'CHECKOUT-TEST', 'Fixture Item', 25, 10)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, date_received) VALUES (1, 1, 'TEST-BATCH', 10, 10, 0, 10, NOW())");
    $pdo->exec("INSERT INTO held_sales (held_sale_id, cashier_id, shift_id, reference_no, item_count, total_amount, status) VALUES (1, 1, 1, 'TEST-HELD', 2, 50, 'resumed')");
    App\Store\StoreWriteGate::begin($pdo); $pdo->commit();

    $pdo->exec('UPDATE inventory SET quantity_on_hand=100');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=100, loose_quantity=100, quantity=100');
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (2, 'admin'), (3, 'super_admin')");
    $password = password_hash('fixture-secret', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (user_id, display_name, username, password_hash, role_id) VALUES (3, 'Fixture Administrator', 'approver', ?, 2), (4, 'Fixture Super Administrator', 'technical', ?, 3)")->execute([$password, $password]);
    $service = new SalesWorkflowService($pdo);
    $cart = [['product_id' => 1, 'qty' => 4]];
    $details = ['payment_method' => 'cash', 'cash_received' => 120];
    $review = static fn(array $inputs = []): array => $service->reviewQuote($cart, 1, 'cashier', array_replace($details, $inputs));
    $save = static function (array $quote, array $inputs = [], string $method = 'cash') use ($service, $cart, $details): array {
        return $service->checkout($cart, 1, 'cashier', $method, array_replace($details,
            ['checkout_attempt' => bin2hex(random_bytes(16)), 'reviewed_quote' => $quote['state_hash']], $inputs));
    };
    $snapshot = static function () use ($pdo): array {
        $state = [];
        foreach (['sales', 'sale_items', 'sale_item_batches', 'stock_movements', 'inventory', 'product_batches', 'held_sales', 'checkout_attempts'] as $table) {
            $state[$table] = $pdo->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll();
        }
        return $state;
    };
    $refuse = static function (callable $operation, string $contains) use ($assert, $snapshot): void {
        $before = $snapshot();
        try { $operation(); throw new LogicException('Expected refusal: ' . $contains); }
        catch (DomainException | RuntimeException $e) { $assert(str_contains($e->getMessage(), $contains), $e->getMessage()); }
        $assert($before === $snapshot(), 'Refusal moves no financial, batch, held-sale or inventory records');
    };
    $quote = $review();
    $assert($quote['total'] === 100.0 && $quote['sale']['items'][0]['unit_price'] === 25.0, 'Server quote uses current price and quantities');
    $pdo->exec('UPDATE products SET unit_price=0 WHERE product_id=1');
    $refuse(static fn() => $review(), 'selling price');
    $refuse(static fn() => $save($quote), 'selling price');
    $pdo->exec('UPDATE products SET unit_price=25 WHERE product_id=1');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details + ['checkout_attempt' => bin2hex(random_bytes(16))]), 'Review the final quote again');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0, 'Review records no sale');
    $pdo->exec('UPDATE products SET unit_price=26 WHERE product_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $pdo->exec('UPDATE products SET unit_price=25 WHERE product_id=1');
    $quote = $review();
    $pdo->exec('UPDATE inventory SET quantity_on_hand=99 WHERE product_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $pdo->exec('UPDATE product_batches SET remaining_quantity=99, loose_quantity=99 WHERE batch_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $pdo->exec("INSERT INTO promotions (promotion_name, discount_type, discount_value, scope, starts_at, ends_at, created_by) VALUES ('Fixture promotion', 'percentage', 20, 'all', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_ADD(NOW(), INTERVAL 1 DAY), 3)");
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $quote = $review();
    $assert($quote['total'] === 100.0 && count($quote['eligible_promotions']) === 1, 'Eligible promotions never apply automatically');
    $selected = ['promotion_id' => 1];
    $pdo->exec('UPDATE products SET cost_price=0, cost_known=0 WHERE product_id=1');
    $pdo->exec('UPDATE product_batches SET cost_price=NULL, cost_known=0 WHERE batch_id=1');
    $refuse(static fn() => $review(), 'no confirmed purchase cost');
    $refuse(static fn() => $review($selected), 'no confirmed purchase cost');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details + $selected + ['held_sale_id' => 1]), 'no confirmed purchase cost');
    $pdo->exec('UPDATE products SET unit_price=0 WHERE product_id=1');
    $refuse(static fn() => $review($selected), 'selling price');
    $pdo->exec('UPDATE products SET unit_price=25, cost_known=1 WHERE product_id=1');
    $freeGoods = $review();
    $assert($freeGoods['sale']['items'][0]['batch_plan'][0]['unit_cost'] === 0.0, 'Confirmed zero Product cost is valid for free goods');
    $pdo->exec('UPDATE products SET cost_known=0 WHERE product_id=1');
    $pdo->exec('UPDATE product_batches SET cost_price=12, cost_known=1 WHERE batch_id=1');
    $batchQuote = $review();
    $assert($batchQuote['sale']['items'][0]['batch_plan'][0]['unit_cost'] === 12.0, 'Recorded batch cost works even with unknown Product cost');
    $pdo->exec('UPDATE products SET cost_price=10 WHERE product_id=1');
    $pdo->exec('UPDATE product_batches SET cost_price=30 WHERE batch_id=1');
    $refuse(static fn() => $review(), 'below purchase cost');
    $promotionList = $service->reviewQuote($cart, 1, 'cashier', $details, true);
    $assert(count($promotionList['eligible_promotions']) === 1 && !isset($promotionList['state_hash']), 'Below-cost cart can discover an eligible Promotion without authorizing checkout');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details + ['promotion_preview' => '1']), 'below purchase cost');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details), 'below purchase cost');
    $assert($review($selected)['total'] === 80.0, 'Eligible Administrator Promotion permits an intentional loss');
    $pdo->exec('UPDATE products SET pack_size=2, pack_price=40 WHERE product_id=1');
    $pdo->exec('UPDATE product_batches SET sealed_quantity=2, loose_quantity=96 WHERE batch_id=1');
    $packCart = [['product_id' => 1, 'qty' => 1, 'selling_form' => 'pack']];
    $refuse(static fn() => $service->reviewQuote($packCart, 1, 'cashier', $details), 'below purchase cost');
    $assert($service->reviewQuote($packCart, 1, 'cashier', $details + $selected)['total'] === 32.0, 'Promotion exception covers Sales Packs using per-piece batch cost times pack count');
    $pdo->exec('UPDATE products SET pack_price=0 WHERE product_id=1');
    $refuse(static fn() => $service->reviewQuote($packCart, 1, 'cashier', $details + $selected), 'selling price');
    $pdo->exec('UPDATE products SET pack_size=NULL, pack_price=NULL WHERE product_id=1');
    $pdo->exec('UPDATE product_batches SET sealed_quantity=0, loose_quantity=2, remaining_quantity=2, cost_price=5 WHERE batch_id=1');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, cost_price, cost_known) VALUES (3, 1, 'EXPENSIVE', 98, 98, 0, 98, 30, 1)");
    $refuse(static fn() => $review(), 'below purchase cost');
    $assert($review($selected)['total'] === 80.0, 'Mixed-batch Promotion remains allowed; cheap stock cannot hide ordinary expensive-stock losses');
    $pdo->exec('DELETE FROM product_batches WHERE batch_id=3');
    $pdo->exec('UPDATE product_batches SET remaining_quantity=99, loose_quantity=99, cost_price=12 WHERE batch_id=1');
    $quote = $review();
    $pdo->exec('UPDATE product_batches SET cost_price=13 WHERE batch_id=1');
    $refuse(static fn() => $save($quote), 'Review the final quote again');
    $pdo->exec('UPDATE product_batches SET cost_price=NULL, cost_known=0 WHERE batch_id=1');
    $fallbackQuote = $review();
    $assert($fallbackQuote['sale']['items'][0]['batch_plan'][0]['unit_cost'] === 10.0
        && $fallbackQuote['sale']['items'][0]['batch_plan'][0]['cost_status'] === 'estimated', 'Missing batch cost falls back to confirmed Product cost as an estimate');
    $pdo->exec('UPDATE promotions SET discount_value=100 WHERE promotion_id=1');
    $refuse(static fn() => $review($selected), 'makes Fixture Item free');
    $refuse(static fn() => $service->checkout($cart, 1, 'cashier', 'cash', $details + $selected), 'makes Fixture Item free');
    $pdo->exec('UPDATE products SET unit_price=0.01, cost_price=0, cost_known=1 WHERE product_id=1');
    $pdo->exec('UPDATE promotions SET discount_value=99.99 WHERE promotion_id=1');
    $refuse(static fn() => $review($selected), 'makes Fixture Item free');
    $pdo->exec('UPDATE products SET unit_price=25, cost_price=10 WHERE product_id=1');
    $pdo->exec('UPDATE promotions SET discount_value=20 WHERE promotion_id=1');
    $quote = $review($selected);
    $assert($quote['total'] === 80.0, 'Explicit selection above 10% needs no approval');
    $saved = $save($quote, $selected);
    $assert($saved['discount_amount'] === 20.0, 'Selected promotion persists');
    $savedCosts = $pdo->query('SELECT sib.unit_cost, sib.cost_status, sib.cost_source FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id=sib.sale_item_id WHERE si.sale_id=' . $saved['sale_id'])->fetch();
    $assert((float)$savedCosts['unit_cost'] === 10.0 && $savedCosts['cost_status'] === 'estimated' && $savedCosts['cost_source'] === 'product', 'Sale freezes estimated Product fallback without changing batch cost');
    $assert($pdo->query('SELECT cost_price FROM product_batches WHERE batch_id=1')->fetchColumn() === null, 'Sale-time estimate does not fill missing batch cost');
    $pdo->exec('UPDATE product_batches SET cost_price=30, cost_known=1 WHERE batch_id=1');
    $lossQuote = $review($selected);
    $loss = $save($lossQuote, $selected);
    $lossCost = $pdo->query('SELECT SUM(sib.unit_cost * sib.quantity) FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id=sib.sale_item_id WHERE si.sale_id=' . $loss['sale_id'])->fetchColumn();
    $assert($loss['total'] === 80.0 && (float)$lossCost === 120.0, 'Completed Administrator Promotion preserves the actual loss instead of hiding it');
    $pdo->exec('UPDATE product_batches SET cost_price=NULL, cost_known=0 WHERE batch_id=1');
    $receiptService = new App\Receipts\ReceiptEngine($pdo);
    $pdo->exec("UPDATE promotions SET promotion_name='Changed after sale', discount_value=30 WHERE promotion_id=1");
    $receipt = $receiptService->fetchSale((int)$saved['sale_id']);
    $assert($receipt['promotion_name'] === 'Fixture promotion' && (float)$receipt['discount_amount'] === 20.0
        && (float)$receipt['total_amount'] === 80.0, 'Recorded receipt retains original promotion and paid amount after rule changes');
    $pdo->exec("UPDATE promotions SET promotion_name='Fixture promotion', discount_value=20 WHERE promotion_id=1");
    $recalculated = $service->reviewQuote([['product_id' => 1, 'qty' => 2]], 1, 'cashier', $details + $selected);
    $assert($recalculated['discount']['promotion_id'] === 1 && $recalculated['discount']['discount_amount'] === 10.0
        && $recalculated['total'] === 40.0, 'Still-eligible selection recalculates after quantity changes');
    $pdo->exec("INSERT INTO promotions (promotion_name, discount_type, discount_value, scope, starts_at, ends_at, created_by) VALUES ('Smaller discount', 'fixed', 5, 'all', NOW(), DATE_ADD(NOW(), INTERVAL 1 DAY), 3)");
    $small = ['promotion_id' => 2];
    $quote = $review($small);
    $assert($quote['total'] === 95.0 && $quote['discount']['promotion_id'] === 2, 'Cashier choice beats larger eligible discount without stacking');
    $refuse(static fn() => $save($quote, $selected), 'Review the final quote again');
    $assert($review()['total'] === 100.0, 'Returning to no promotion removes discount');
    foreach ([['discount_type' => 'fixed', 'discount_value' => 1], ['discount_reason' => 'forged'], ['discount_approver_password' => 'forged']] as $forged) {
        $refuse(static fn() => $review($forged), 'Custom discounts');
    }
    foreach (['fake', -1, [1, 2], 999] as $id) $refuse(static fn() => $review(['promotion_id' => $id]), 'promotion');
    $pdo->exec("UPDATE promotions SET status='inactive' WHERE promotion_id=2");
    $refuse(static fn() => $save($quote, $small), 'no longer eligible');
    $pdo->exec("UPDATE promotions SET status='active', ends_at=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE promotion_id=2");
    $refuse(static fn() => $review($small), 'no longer eligible');
    $quote = $review($selected);
    $pdo->exec("UPDATE promotions SET discount_value=21 WHERE promotion_id=1");
    $refuse(static fn() => $save($quote, $selected), 'Review the final quote again');
    $pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Eligible category'), (2, 'Other category')");
    $pdo->exec('UPDATE products SET category_id=1 WHERE product_id=1');
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, category_id)
        VALUES (2, 'OTHER-TEST', 'OTHER-TEST', 'Other item', 10, 5, 2)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 100)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, date_received)
        VALUES (2, 2, 'OTHER-BATCH', 100, 100, 0, 100, NOW())");
    $pdo->exec("UPDATE promotions SET scope='product', product_id=1, minimum_quantity=5 WHERE promotion_id=1");
    $refuse(static fn() => $review($selected), 'no longer eligible');
    $quantityQuote = $service->reviewQuote([['product_id' => 1, 'qty' => 5]], 1, 'cashier', $details + $selected);
    $assert($quantityQuote['total'] === 98.75, 'Minimum matching quantity is inclusive and percentage applies to matching subtotal');
    $pdo->exec("UPDATE promotions SET minimum_quantity=1, discount_type='fixed', discount_value=150 WHERE promotion_id=1");
    $mixedCart = [['product_id' => 1, 'qty' => 4], ['product_id' => 2, 'qty' => 1]];
    $refuse(static fn() => $service->reviewQuote($mixedCart, 1, 'cashier', $details + $selected), 'makes Fixture Item free');
    $pdo->exec('UPDATE promotions SET discount_value=60 WHERE promotion_id=1');
    $scopeQuote = $service->reviewQuote($mixedCart, 1, 'cashier', $details + $selected);
    $assert($scopeQuote['total'] === 50.0 && $scopeQuote['discount']['discount_amount'] === 60.0,
        'Product-scoped discount allows positive payment below cost');
    $pdo->exec('UPDATE products SET cost_price=15 WHERE product_id=2');
    $refuse(static fn() => $service->reviewQuote($mixedCart, 1, 'cashier', $details + $selected), 'Other item is priced below purchase cost');
    $pdo->exec('UPDATE products SET cost_price=5 WHERE product_id=2');
    $pdo->exec("UPDATE promotions SET scope='category', category_id=1 WHERE promotion_id=1");
    $assert($service->reviewQuote($mixedCart, 1, 'cashier', $details + $selected)['total'] === 50.0,
        'Category scope discounts only matching items');
    $pdo->exec('UPDATE promotions SET category_id=2, minimum_quantity=2 WHERE promotion_id=1');
    $refuse(static fn() => $service->reviewQuote($mixedCart, 1, 'cashier', $details + $selected), 'no longer eligible');
    $pdo->exec("UPDATE promotions SET scope='all', minimum_quantity=1, discount_type='percentage', discount_value=33.33 WHERE promotion_id=1");
    $roundedQuote = $service->reviewQuote([['product_id' => 1, 'qty' => 1]], 1, 'cashier', $details + $selected);
    $assert($roundedQuote['discount']['discount_amount'] === 8.33 && $roundedQuote['total'] === 16.67,
        'Percentage discount rounds to currency cents');
    // Freeze this connection's database clock so inclusive boundaries cannot race a second rollover.
    $pdo->exec('SET timestamp=UNIX_TIMESTAMP()');
    $pdo->exec('UPDATE promotions SET starts_at=NOW(), ends_at=NOW() WHERE promotion_id=1');
    $assert($review($selected)['discount']['promotion_id'] === 1, 'Promotion qualifies at both inclusive schedule boundaries');
    $pdo->exec('UPDATE promotions SET starts_at=DATE_ADD(NOW(), INTERVAL 1 SECOND), ends_at=DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE promotion_id=1');
    $refuse(static fn() => $review($selected), 'no longer eligible');
    $pdo->exec('UPDATE promotions SET starts_at=DATE_SUB(NOW(), INTERVAL 1 DAY), ends_at=DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE promotion_id=1');
    $refuse(static fn() => $review($selected), 'no longer eligible');
    $pdo->exec('SET timestamp=0');
    $pdo->exec("UPDATE promotions SET status='inactive'");
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
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, unit_price, cost_price, cost_known) VALUES (3, 'NO-BATCH', 'Legacy unbatched item', 10, 5, 1)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 10)');
    $unbatchedCart = [['product_id' => 3, 'qty' => 1]];
    $unbatched = $service->checkout($unbatchedCart, 1, 'cashier', 'cash', $details);
    $unbatchedCost = $pdo->query('SELECT sib.batch_id, sib.unit_cost, sib.cost_status FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id=sib.sale_item_id WHERE si.sale_id=' . $unbatched['sale_id'])->fetch();
    $assert($unbatchedCost['batch_id'] === null && (float)$unbatchedCost['unit_cost'] === 5.0 && $unbatchedCost['cost_status'] === 'estimated', 'Unbatched stock freezes confirmed Product fallback without inventing a batch');
    $pdo->exec('UPDATE products SET cost_price=0, cost_known=0 WHERE product_id=3');
    $refuse(static fn() => $service->reviewQuote($unbatchedCart, 1, 'cashier', $details), 'no confirmed purchase cost');
    $assert((float)$pdo->query('SELECT sib.unit_cost FROM sale_item_batches sib JOIN sale_items si ON si.sale_item_id=sib.sale_item_id WHERE si.sale_id=' . $unbatched['sale_id'])->fetchColumn() === 5.0, 'Later Product changes preserve the completed fallback snapshot');
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
    echo "Reviewed checkout integration: passed (pricing readiness, confirmed zero cost, batch/Product cost priority and snapshots, piece/pack and mixed-batch loss guards, positive promotional payment, scoped loss exceptions, atomic refusals, explicit promotions, receipt history, quote changes, payment verification, retry and concurrency)\n";
} catch (Throwable $e) { fwrite(STDERR, 'Reviewed checkout integration failed: ' . $e->getMessage() . "\n"); $failed = true; }
finally {
    foreach ($workers as [$process, $pipes]) { foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_terminate($process); proc_close($process); }
    if ($created) $server->exec("DROP DATABASE `{$database}`");
}
exit(isset($failed) ? 1 : 0);
