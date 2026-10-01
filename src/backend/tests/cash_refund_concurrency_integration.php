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
        if (($argv[3] ?? '') === 'drop') {
            (new CashierShiftService($pdo))->addDrawerMovement(1, 'cashier', 'safe_drop', 25, 'excess_cash');
        } elseif (($argv[3] ?? '') === 'withdraw') {
            (new CashierShiftService($pdo))->addDrawerMovement(1, 'cashier', 'cash_out', 25, 'other', 'Cash collected');
        } elseif (($argv[3] ?? '') === 'discount') {
            $service->refund(1,'cashier',14,[14=>['quantity'=>2,'disposition'=>'damaged']],'customer_return',null,'EXTERNAL-RACE',true);
        } else {
            $service->refund(1, 'cashier', (int)($argv[3] ?? 1), (isset($argv[3]) ? [2 => ['quantity'=>1,'disposition'=>'damaged']] : $items), 'customer_return');
        }
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
    // An upgrade creates the same receipt storage as the fresh schema and is repeatable.
    $pdo->exec('DROP TABLE refund_receipt_details');
    $receiptMigration = require __DIR__ . '/../database/migrations/202609300003_refund_receipt_details.php';
    $receiptMigration['up']($pdo);
    $receiptMigration['up']($pdo);
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
    $assert((int)$pdo->query('SELECT COUNT(*) FROM refund_receipt_details')->fetchColumn() === 1,
        'Only the successful refund preserves a customer receipt.');
    $assert((float)(new CashierShiftService($pdo))->calculateShift(1)['calculated_expected_cash'] === 100.0,
        'Only one cash payout leaves the drawer.');
    $receiptService = new \App\Services\RefundReceiptService($pdo);
    $refundId = (int)$pdo->query('SELECT refund_id FROM cash_refunds')->fetchColumn();
    $savedReceipt = $receiptService->forCashier($refundId, 1, 1);
    $assert($savedReceipt !== null, 'The committed refund returns its customer receipt.');
    $pdo->exec("UPDATE products SET product_name='Edited product', sku='EDITED'");
    $pdo->exec("UPDATE registers SET name='Edited Register', paper_width_mm='58'");
    $pdo->exec("UPDATE users SET full_name='Edited Cashier'");
    $pdo->exec("INSERT INTO store_settings (setting_key, setting_value) VALUES ('store_name', 'Edited Store'), ('receipt_footer', 'Edited footer')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $assert($receiptService->forCashier($refundId, 1, 1) === $savedReceipt,
        'Committed customer details survive Store, product, Cashier and Register edits.');
    $assert((new \App\Services\ReceiptPaperService($pdo))->currentWidth(1, 'cashier') === 58,
        'Printing uses the current Register width without rewriting preserved details.');

    $shifts = new CashierShiftService($pdo);
    $pdo->exec("INSERT INTO sales (sale_id,cashier_id,shift_id,total_amount,payment_method) VALUES (2,1,1,25,'cash')");
    $pdo->exec('INSERT INTO sale_items (sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (2,2,1,1,25,25)');
    $shifts->addDrawerMovement(1,'cashier','safe_drop',100,'excess_cash');
    // Independent processes compete for the last 25 pesos, with the parent
    // holding the shared shift row so every contender reaches the write path.
    foreach ([['drop','withdraw'], ['2','drop']] as $operations) {
        $pdo->beginTransaction(); $pdo->query('SELECT shift_id FROM cashier_shifts WHERE shift_id=1 FOR UPDATE')->fetch();
        foreach ($operations as $operation) {
            $process=proc_open([PHP_BINARY,__FILE__,'--worker',$database,$operation],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $assert(is_resource($process),'Payout worker starts'); $workers[]=[$process,$pipes];
            $assert(trim((string)fgets($pipes[1]))==='ready','Payout worker ready');
        }
        foreach ($workers as [$process,$pipes]) { fwrite($pipes[0],"go\n");fflush($pipes[0]); }
        usleep(300000); $pdo->commit(); $results=[];
        foreach ($workers as [$process,$pipes]) {
            fclose($pipes[0]);$results[]=trim(stream_get_contents($pipes[1]));$error=stream_get_contents($pipes[2]);
            fclose($pipes[1]);fclose($pipes[2]);$assert(proc_close($process)===0,'Payout worker: '.$error);
        }
        $workers=[];sort($results);$assert($results===['refunded','refused'],'Only one outgoing operation consumes the available cash');
        $assert((float)$shifts->calculateShift(1)['calculated_expected_cash']===0.0,'Competing payouts never overdraw');
        $shifts->addDrawerMovement(1,'cashier','cash_in',25,'additional_float','Physical float replenished');
    }
    // Ensure insufficient refunds fail before receipt, inventory or audit writes.
    if ($service->refundableQuantity(2)>0) {
        $shifts->addDrawerMovement(1,'cashier','safe_drop',25,'excess_cash');
        $count=(int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn();
        try { $service->refund(1,'cashier',2,[2=>['quantity'=>1,'disposition'=>'damaged']],'customer_return'); throw new LogicException('Insufficient refund succeeds'); }
        catch (DomainException $e) { $assert(str_contains($e->getMessage(),'Insufficient expected'),'Refund explains recorded top-up'); }
        $assert((int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn()===$count,'Insufficient refund appends nothing');
        $shifts->addDrawerMovement(1,'cashier','cash_in',25,'additional_float');
        $service->refund(1,'cashier',2,[2=>['quantity'=>1,'disposition'=>'damaged']],'customer_return');
    }

    // Historical cash sales do not put money into today's paying shift.
    $pdo->exec("INSERT INTO sales (sale_id,cashier_id,total_amount,payment_method) VALUES (3,1,25,'cash'),(4,1,25,'card'),(5,1,25,'ewallet')");
    $pdo->exec('INSERT INTO sale_items (sale_item_id,sale_id,product_id,quantity,unit_price,subtotal) VALUES (3,3,1,1,25,25),(4,4,1,1,25,25),(5,5,1,1,25,25)');
    $balance=(float)$shifts->calculateShift(1)['calculated_expected_cash'];
    if ($balance>0) $shifts->addDrawerMovement(1,'cashier','safe_drop',$balance,'excess_cash');
    try { $service->refund(1,'cashier',3,[3=>['quantity'=>1,'disposition'=>'damaged']],'customer_return'); throw new LogicException('Unfunded historical refund succeeds'); }
    catch (DomainException $e) { $assert(str_contains($e->getMessage(),'Insufficient expected'),'Unfunded refund requires top-up'); }
    $shifts->addDrawerMovement(1,'cashier','cash_in',25,'additional_float');
    $service->refund(1,'cashier',3,[3=>['quantity'=>1,'disposition'=>'damaged']],'customer_return');
    foreach ([4,5] as $saleId) $service->refund(1,'cashier',$saleId,[$saleId=>['quantity'=>1,'disposition'=>'damaged']],'customer_return',null,'EXTERNAL-REFUND-'.$saleId,true);
    $assert((float)$shifts->calculateShift(1)['calculated_expected_cash']===0.0,'Card and e-wallet refunds permitted with empty drawer and do not deduct cash');

    require __DIR__ . '/support/safe_refund_scenarios.php';
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
        $_SESSION=['user_id'=>2,'role'=>'admin'];
        $legacy->approveReversal(1, 2, 'Checked old request', ['no_prior_effects'=>true,'restockable'=>true,'refund_ineligibility_acknowledged'=>true]);
        throw new LogicException('Legacy approval must not refund a Cash Refund again.');
    } catch (DomainException $e) {
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
