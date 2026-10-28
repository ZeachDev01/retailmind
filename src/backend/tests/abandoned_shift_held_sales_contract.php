<?php
// Contract test for Ticket #345:
// Cancel unpaid Held Sales during abandoned-Shift closure.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';

use App\Audit\AuditRecordCategory;
use App\Services\CashierShiftService;
use App\Services\HeldSaleService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$expectRefusal = static function (callable $operation, string $message) use (&$failures): void {
    try {
        $operation();
        $failures[] = $message . ' (expected refusal, but succeeded)';
    } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
        if (trim($exception->getMessage()) === '') {
            $failures[] = $message . ' (refused, but message was empty)';
        }
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction(
        'DATE_ADD',
        static function (string $date, string $interval, int $days): string {
            return date('Y-m-d', strtotime($date . ' +' . $days . ' days'));
        },
        3
    );

    $pdo->exec('CREATE TABLE users (
        user_id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        username TEXT NULL,
        password_hash TEXT NULL,
        role_id INTEGER NULL,
        status TEXT NOT NULL DEFAULT \'active\',
        branch_id INTEGER NULL
    )');
    $pdo->exec('CREATE TABLE roles (role_id INTEGER PRIMARY KEY AUTOINCREMENT, role_name TEXT NOT NULL UNIQUE)');
    $pdo->exec("INSERT INTO roles (role_name) VALUES ('cashier'), ('admin'), ('super_admin'), ('inventory_manager')");
    $pdo->exec("CREATE TABLE registers (
        register_id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        disabled_at TEXT NULL,
        created_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE cashier_shifts (
        shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        register_id INTEGER NULL,
        opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
        opening_cash REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT 'open',
        closed_at TEXT NULL,
        expected_cash REAL NULL,
        actual_cash REAL NULL,
        cash_variance REAL NULL,
        variance_threshold REAL NULL,
        variance_review_required INTEGER NOT NULL DEFAULT 0,
        payment_totals TEXT NULL,
        closing_notes TEXT NULL,
        closed_by INTEGER NULL,
        intervention_reason TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE held_sales (
        held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        shift_id INTEGER NULL,
        reference_no TEXT NOT NULL,
        customer_label TEXT NULL,
        cart_json TEXT NOT NULL,
        item_count INTEGER NOT NULL DEFAULT 0,
        total_amount REAL NOT NULL DEFAULT 0.00,
        status TEXT NOT NULL DEFAULT 'held',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        expires_at TEXT NULL,
        resolved_at TEXT NULL,
        sale_id INTEGER NULL,
        discard_reason TEXT NULL,
        discard_note TEXT NULL,
        discarded_by INTEGER NULL
    )");
    $pdo->exec("CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE products (
        product_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sku TEXT NULL,
        barcode TEXT NULL,
        product_name TEXT NOT NULL,
        category_id INTEGER NULL,
        unit_price REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'active',
        quantity_purchased INTEGER NOT NULL DEFAULT 0,
        quantity_sold INTEGER NOT NULL DEFAULT 0,
        reorder_level INTEGER NOT NULL DEFAULT 0,
        safety_stock INTEGER NOT NULL DEFAULT 0,
        branch_id INTEGER NULL
    )");
    $pdo->exec('CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec("CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_number TEXT NULL,
        expiration_date TEXT NULL,
        date_received TEXT NULL,
        remaining_quantity INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE sales (
        sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
        cashier_id INTEGER NOT NULL,
        shift_id INTEGER NULL,
        total_amount REAL NOT NULL,
        gross_amount REAL NULL,
        discount_type TEXT NOT NULL DEFAULT 'none',
        discount_value REAL NOT NULL DEFAULT 0.00,
        discount_amount REAL NOT NULL DEFAULT 0.00,
        discount_reason TEXT NULL,
        discount_authorized_by INTEGER NULL,
        promotion_id INTEGER NULL,
        promotion_name TEXT NULL,
        payment_method TEXT DEFAULT 'cash',
        cash_received REAL NULL,
        change_due REAL NULL,
        payment_reference TEXT NULL,
        sale_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL
    )");
    $pdo->exec('CREATE TABLE sale_item_batches (sale_item_id INTEGER NOT NULL, batch_id INTEGER NOT NULL, quantity INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE sale_receipt_details (sale_id INTEGER PRIMARY KEY, details_json TEXT NOT NULL)');
    $pdo->exec("CREATE TABLE sale_reversals (
        reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        reversal_type TEXT NOT NULL DEFAULT 'refund',
        status TEXT NOT NULL DEFAULT 'pending',
        reason TEXT NULL,
        settlement_method TEXT NULL,
        refund_amount REAL NOT NULL DEFAULT 0,
        requested_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        refund_amount REAL NOT NULL DEFAULT 0.00,
        payment_method TEXT NOT NULL DEFAULT 'cash',
        reason TEXT NOT NULL,
        note TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE stock_movements (
        stock_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NULL,
        moved_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE promotions (
        promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
        promotion_name TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        scope TEXT NOT NULL DEFAULT 'all',
        product_id INTEGER NULL,
        category_id INTEGER NULL,
        minimum_quantity INTEGER NOT NULL DEFAULT 1,
        discount_type TEXT NOT NULL DEFAULT 'percentage',
        discount_value REAL NOT NULL DEFAULT 0,
        starts_at TEXT NULL,
        ends_at TEXT NULL
    )");
    $pdo->exec("CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY AUTOINCREMENT, period_name TEXT NOT NULL, start_date TEXT, end_date TEXT, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2020-01-01', '2099-12-31', 'open')");
    $pdo->exec('CREATE TABLE fiscal_period_locks (lock_id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER NOT NULL, table_name TEXT NULL)');
    $pdo->exec("CREATE TABLE activity_log (
        log_id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NULL,
        action TEXT NOT NULL,
        module TEXT NULL,
        category TEXT NULL,
        record_id INTEGER NULL,
        previous_value TEXT NULL,
        new_value TEXT NULL,
        metadata TEXT NULL,
        ip_address TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE TABLE notification_preferences (user_id INTEGER PRIMARY KEY, notify_low_stock INTEGER DEFAULT 1, low_stock_threshold INTEGER NULL)');
    $pdo->exec('CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, title TEXT, message TEXT, reference_id INTEGER NULL, reference_type TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE store_write_gate (gate_key TEXT PRIMARY KEY, paused_at TEXT NULL, paused_by TEXT NULL)');
    $pdo->exec("CREATE TABLE branches (branch_id INTEGER PRIMARY KEY AUTOINCREMENT, branch_name TEXT NOT NULL, branch_code TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'active')");
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'RetailMind Store', 'RETAILMIND-STORE', 'active')");
    $pdo->exec('CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT)');

    $insertUser = static function (string $name, string $role) use ($pdo): int {
        $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = '{$role}'")->fetchColumn();
        $pdo->prepare("INSERT INTO users (full_name, role_id, status, branch_id) VALUES (?, ?, 'active', 1)")->execute([$name, $roleId]);
        return (int)$pdo->lastInsertId();
    };

    $cashierA = $insertUser('Casey Cashier', 'cashier');
    $cashierB = $insertUser('Dana Cashier', 'cashier');
    $adminUser = $insertUser('Alex Administrator', 'admin');

    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Front Register'), (2, 'Back Register')");
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([1, 'SKU-WATER', 'Bottled Water', 25.00]);
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([2, 'SKU-SNACK', 'Potato Chips', 50.00]);
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 100), (2, 100)');

    $shifts = new CashierShiftService($pdo);
    $held = new HeldSaleService($pdo, $shifts);

    // =========================================================================
    // Scenario 1: Zero held sales on abandoned shift closure
    // =========================================================================
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, 1, 500.00)')->execute([$cashierA]);
    $shiftA = (int)$pdo->lastInsertId();

    $assert(count($shifts->unresolvedHeldSales($shiftA)) === 0, 'Shift A has zero held sales');

    // Admin closes shift with zero held sales
    $summary = $shifts->closeAbandonedShift($adminUser, 'admin', $cashierA, 500.00, '', 'Cashier walked out', null, null, null);
    $assert($summary['status'] === 'closed', 'Abandoned shift with zero held sales closes cleanly');
    $assert((string)$pdo->query("SELECT status FROM cashier_shifts WHERE shift_id = {$shiftA}")->fetchColumn() === 'closed', 'Shift A recorded as closed');

    // =========================================================================
    // Scenario 2: Unpaid held sales - missing/empty reason refusal & unchanged state
    // =========================================================================
    // Open a new shift for Cashier A
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, 1, 300.00)')->execute([$cashierA]);
    $shiftA2 = (int)$pdo->lastInsertId();

    // Casey holds a sale
    $heldSale1 = $held->hold($cashierA, 'cashier', [1 => ['qty' => 2]], 'Customer Alice');
    $heldSale1Id = (int)$heldSale1['held_sale_id'];

    $assert(count($shifts->unresolvedHeldSales($shiftA2)) === 1, 'Shift A2 has 1 unresolved held sale');

    // 2a: Ordinary cashier close is refused
    $expectRefusal(
        static fn() => $shifts->closeShift($cashierA, 300.00, ''),
        'Cashier cannot close shift with unresolved held sale'
    );

    // 2b: Admin closure without cancellation reason is refused
    $expectRefusal(
        static fn() => $shifts->closeAbandonedShift($adminUser, 'admin', $cashierA, 300.00, '', 'Cashier abandoned register'),
        'Admin closure without cancel reason is refused when held sales exist'
    );

    // 2c: Admin closure with empty/whitespace reason is refused
    $expectRefusal(
        static fn() => $shifts->closeAbandonedShift($adminUser, 'admin', $cashierA, 300.00, '', 'Cashier abandoned register', null, null, '   '),
        'Admin closure with whitespace cancel reason is refused'
    );

    // Verify state is 100% UNCHANGED after refusals
    $assert(
        (string)$pdo->query("SELECT status FROM cashier_shifts WHERE shift_id = {$shiftA2}")->fetchColumn() === 'open',
        'Shift A2 remains open after refused closures'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$heldSale1Id}")->fetchColumn() === 'held',
        'Held sale 1 remains held after refused closures'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Held Sales' AND action = 'Held sale cancelled'")->fetchColumn() === 0,
        'No cancellation audit log written on refusal'
    );

    // =========================================================================
    // Scenario 3: Single unpaid held sale cancelled during abandoned shift closure
    // =========================================================================
    $cancelReason = 'Cashier went home sick, customer left the store';
    $closedA2 = $shifts->closeAbandonedShift($adminUser, 'admin', $cashierA, 300.00, 'Count verified by supervisor', 'Cashier absent', null, null, $cancelReason);

    $assert($closedA2['status'] === 'closed', 'Shift A2 closed successfully');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id = {$shiftA2} AND status = 'closed'")->fetchColumn() === 1, 'Shift A2 persisted closed');

    // Check held sale state
    $heldRow = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$heldSale1Id}")->fetch(PDO::FETCH_ASSOC);
    $assert($heldRow['status'] === 'discarded', 'Held sale status transitioned to discarded');
    $assert((int)$heldRow['cashier_id'] === $cashierA, 'Original saver attribution (cashier_id) is retained');
    $assert((int)$heldRow['discarded_by'] === $adminUser, 'Cancelling administrator attribution (discarded_by) recorded');
    $assert($heldRow['discard_note'] === $cancelReason, 'Cancellation reason preserved in held_sales.discard_note');
    $assert($heldRow['resolved_at'] !== null, 'resolved_at timestamp populated');

    // Check audit logging
    $cancelAudit = $pdo->query("SELECT * FROM activity_log WHERE module = 'Held Sales' AND record_id = {$heldSale1Id}")->fetch(PDO::FETCH_ASSOC);
    $assert($cancelAudit !== false, 'Audit log created for held sale cancellation');
    $assert((int)$cancelAudit['user_id'] === $adminUser, 'Audit user_id is the cancelling Administrator');
    $assert($cancelAudit['action'] === 'Held sale cancelled', 'Audit action is Held sale cancelled');
    $assert($cancelAudit['category'] === AuditRecordCategory::STORE_OPERATION, 'Audit category is STORE_OPERATION');

    $auditPayload = json_decode((string)$cancelAudit['new_value'], true);
    $assert(is_array($auditPayload), 'Audit payload is valid JSON');
    $assert((int)$auditPayload['cashier_id'] === $cashierA, 'Audit payload retains original saver cashier_id');
    $assert((int)$auditPayload['cancelled_by'] === $adminUser, 'Audit payload retains cancelling administrator ID');
    $assert($auditPayload['reason'] === $cancelReason, 'Audit payload retains cancellation reason');
    $assert(!empty($auditPayload['cancelled_at']), 'Audit payload includes timestamp');

    // Check no unintended mutations occurred
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0, 'No sale created');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM sale_items')->fetchColumn() === 0, 'No sale items created');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn() === 0, 'No cash refunds created');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === 0, 'No cash drawer movements created');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 100, 'Inventory quantity untouched');

    // =========================================================================
    // Scenario 4: Multiple unpaid held sales (mixed 'held' and 'resumed')
    // =========================================================================
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, 1, 400.00)')->execute([$cashierA]);
    $shiftA3 = (int)$pdo->lastInsertId();

    $multi1 = $held->hold($cashierA, 'cashier', [1 => ['qty' => 1]], 'Cart One');
    $multi1Id = (int)$multi1['held_sale_id'];

    $multi2 = $held->hold($cashierA, 'cashier', [2 => ['qty' => 3]], 'Cart Two');
    $multi2Id = (int)$multi2['held_sale_id'];

    // Resume the second held sale (leaves it in 'resumed' status)
    $held->resume($cashierA, 'cashier', $multi2Id);
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$multi2Id}")->fetchColumn() === 'resumed',
        'Multi 2 is in resumed status'
    );

    $multi3 = $held->hold($cashierA, 'cashier', [1 => ['qty' => 1], 2 => ['qty' => 1]], 'Cart Three');
    $multi3Id = (int)$multi3['held_sale_id'];

    $assert(count($shifts->unresolvedHeldSales($shiftA3)) === 3, 'Shift A3 has 3 unresolved held sales');

    // Close abandoned shift with cancellation
    $multiCancelReason = 'Cashier abandoned station during peak rush';
    $closedA3 = $shifts->closeAbandonedShift($adminUser, 'admin', $cashierA, 400.00, '', 'Abandoned shift', null, null, $multiCancelReason);
    $assert($closedA3['status'] === 'closed', 'Shift A3 closed');

    // Verify all 3 are discarded and audited
    foreach ([$multi1Id, $multi2Id, $multi3Id] as $mId) {
        $row = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$mId}")->fetch(PDO::FETCH_ASSOC);
        $assert($row['status'] === 'discarded', "Held sale {$mId} is discarded");
        $assert((int)$row['cashier_id'] === $cashierA, "Held sale {$mId} retains cashier attribution");
        $assert((int)$row['discarded_by'] === $adminUser, "Held sale {$mId} has admin discarded_by");

        $audit = $pdo->query("SELECT * FROM activity_log WHERE module = 'Held Sales' AND record_id = {$mId} AND action = 'Held sale cancelled'")->fetch(PDO::FETCH_ASSOC);
        $assert($audit !== false, "Audit record exists for held sale {$mId}");
    }

    // =========================================================================
    // Scenario 5: Boundaries & Isolation - Never allow broad tampering
    // =========================================================================
    // Open shift for Cashier B
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, 2, 250.00)')->execute([$cashierB]);
    $shiftB = (int)$pdo->lastInsertId();

    $heldB = $held->hold($cashierB, 'cashier', [1 => ['qty' => 5]], 'Dana Customer');
    $heldBId = (int)$heldB['held_sale_id'];

    // 5a: Admin cannot discard Cashier B's held sale via HeldSaleService::discard
    $expectRefusal(
        static fn() => $held->discard($adminUser, 'admin', $heldBId, 'customer_cancelled'),
        'Administrator cannot discard cart via HeldSaleService::discard (requires cashier workspace)'
    );

    // 5b: Closing abandoned Shift A cannot touch Shift B's held sales
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$heldBId}")->fetchColumn() === 'held',
        'Cashier B held sale is completely unaffected and remains held'
    );

    // 5c: Calling cancelForAbandonedShift on own shift or closed shift is refused
    $expectRefusal(
        static fn() => $held->cancelForAbandonedShift($adminUser, 'admin', $shiftA3, 'Try cancel already closed'),
        'Cannot cancel held sales for an already closed shift'
    );

    // =========================================================================
    // Scenario 6: Normal cashier-led held sale completion & discard
    // =========================================================================
    // Cashier B resumes and completes held sale
    $held->resume($cashierB, 'cashier', $heldBId);
    $salesService = new SalesWorkflowService($pdo);
    $sale = $salesService->checkout([['product_id' => 1, 'qty' => 5]], $cashierB, 'cashier', 'cash', ['cash_received' => 200, 'held_sale_id' => $heldBId]);
    $assert((int)$sale['sale_id'] > 0, 'Cashier B completed held sale via checkout');

    $heldBRow = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$heldBId}")->fetch(PDO::FETCH_ASSOC);
    $assert($heldBRow['status'] === 'completed', 'Held sale B status is completed');
    $assert((int)$heldBRow['sale_id'] === (int)$sale['sale_id'], 'Held sale B links to completed sale');

    // Cashier B holds another sale and discards it normally
    $heldB2 = $held->hold($cashierB, 'cashier', [2 => ['qty' => 1]], 'Dana Customer 2');
    $heldB2Id = (int)$heldB2['held_sale_id'];
    $held->discard($cashierB, 'cashier', $heldB2Id, 'customer_cancelled');

    $heldB2Row = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$heldB2Id}")->fetch(PDO::FETCH_ASSOC);
    $assert($heldB2Row['status'] === 'discarded', 'Held sale B2 discarded by cashier');
    $assert((int)$heldB2Row['discarded_by'] === $cashierB, 'Discarded by cashier');

    // Cashier B can now close their shift normally
    $closedB = $shifts->closeShift($cashierB, 375.00, 'All good');
    $assert($closedB['status'] === 'closed', 'Cashier B closed shift normally');

} catch (Throwable $e) {
    $failures[] = 'Unhandled exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures !== []) {
    echo "Abandoned shift held sales contract FAILED:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "Abandoned shift held sales contract PASSED\n";
