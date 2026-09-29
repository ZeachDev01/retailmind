<?php
// Held Sale Cashier Shift ownership contract (ticket #91).
//
// A held sale is suspended work that belongs to a Cashier and to the Cashier
// Shift that was open when it was held. It is not a floating cart: the shift is
// the only place it can live, only its owner can list or resume it, and the shift
// cannot close while one is unresolved. Discarding it is a deliberate, explained
// act with no cash effect, and completing it carries the shift attribution
// through checkout.
//
// The contract exercises the whole ownership lifecycle at the service seam —
// hold, list, resume, discard, close, complete, expire — because that is the
// highest seam at which all of it is observable. The SQLite fixture stands in
// for the MySQL constraints in its SQLite equivalent, so the rule under test is
// identical and only the dialect differs.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/HeldSaleService.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';

// SalesWorkflowService predates the namespace and is still global; the other two
// are namespaced like the rest of App\Services.
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
        $failures[] = $message;
    } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
        // Expected: an authorization/validation refusal the operator can act on,
        // never a raw database error (ADR-0002).
        if (trim($exception->getMessage()) === '') {
            $failures[] = $message . ' (and it explains itself)';
        }
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // MySQL date functions the hold and checkout paths use, expressed in SQLite
    // terms so only the dialect differs and the rule under test is identical.
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
    $pdo->exec('CREATE UNIQUE INDEX idx_registers_name ON registers (name)');
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
        closing_notes TEXT NULL,
        reviewed_by INTEGER NULL,
        reviewed_at TEXT NULL,
        locked_at TEXT NULL
    )");
    // The open-shift exclusivity of ticket #88, expressed as partial indexes.
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_cashier
        ON cashier_shifts (cashier_id) WHERE status = 'open'");
    $pdo->exec("CREATE UNIQUE INDEX uq_cashier_shifts_open_register
        ON cashier_shifts (register_id) WHERE status = 'open'");

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
    $pdo->exec("CREATE UNIQUE INDEX uq_held_sales_reference ON held_sales (reference_no)");
    $pdo->exec("CREATE INDEX idx_held_sales_cashier_status ON held_sales (cashier_id, status)");
    $pdo->exec("CREATE INDEX idx_held_sales_shift_status ON held_sales (shift_id, status)");

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
    $pdo->exec('CREATE INDEX idx_sales_shift ON sales (shift_id)');
    $pdo->exec("CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL
    )");
    $pdo->exec('CREATE TABLE sale_item_batches (sale_item_id INTEGER NOT NULL, batch_id INTEGER NOT NULL, quantity INTEGER NOT NULL)');
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
    $pdo->exec("CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL,
        branch_code TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'RetailMind Store', 'RETAILMIND-STORE', 'active')");

    // Every fixture account stores the Cashier role, so nothing below is decided
    // by the account's stored role but by the active workspace that is passed in
    // explicitly — the multi-role case from #86.
    $insertUser = static function (string $name) use ($pdo): int {
        $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier'")->fetchColumn();
        $pdo->prepare("INSERT INTO users (full_name, role_id, status, branch_id) VALUES (?, ?, 'active', 1)")->execute([$name, $roleId]);
        return (int)$pdo->lastInsertId();
    };

    $casey = $insertUser('Casey Cashier');
    $dana = $insertUser('Dana Cashier');
    $alex = $insertUser('Alex Cashier-Also-Administrator');

    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (10, 'Front Counter'), (11, 'Back Counter'), (12, 'Side Counter')");
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([1, 'SKU-1', 'Bottled Water', 25.00]);
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([2, 'SKU-2', 'Bottled Tea', 40.00]);
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50), (2, 30)');

    $shifts = new CashierShiftService($pdo);
    $held = new HeldSaleService($pdo, $shifts);
    $sales = new SalesWorkflowService($pdo);

    $cart = [1 => ['qty' => 2]];
    $absentHeldSaleId = 987654;
    $idsOf = static fn(array $rows): array => array_map(static fn(array $r): int => (int)$r['held_sale_id'], $rows);

    // --- AC 1: a held sale needs the Cashier's own open shift ---------------

    $expectRefusal(
        static fn() => $held->hold($casey, 'cashier', $cart),
        'holding a sale is refused without an open Cashier Shift'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM held_sales')->fetchColumn() === 0,
        'a refused hold writes no held sale'
    );

    // The active workspace decides, not the stored role: the account refused here
    // holds a valid open shift for the rest of this block.
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 200.00)')
        ->execute([$alex, 12]);
    $expectRefusal(
        static fn() => $held->hold($alex, 'admin', $cart),
        'holding a sale is refused outside the active Cashier workspace'
    );
    $expectRefusal(
        static fn() => $held->hold($alex, 'super_admin', $cart),
        'holding a sale is refused in the Super Administrator workspace'
    );

    $caseyShiftId = $shifts->openShift($casey, 'cashier', 10, 200.00);
    $danaShiftId = $shifts->openShift($dana, 'cashier', 11, 300.00);
    $assert($caseyShiftId > 0 && $danaShiftId > 0, 'both Cashiers hold an open shift of their own');

    $expectRefusal(
        static fn() => $held->hold($casey, 'cashier', []),
        'an empty cart is refused'
    );

    $caseyHeld = $held->hold($casey, 'cashier', $cart, 'Ms. Santos');
    $caseyHeldId = (int)$caseyHeld['held_sale_id'];
    $row = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$caseyHeldId}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$row['cashier_id'] === $casey, 'the held sale records the Cashier who held it');
    $assert((int)$row['shift_id'] === $caseyShiftId, 'the held sale records the open Cashier Shift it was held under');
    $assert((string)$row['status'] === 'held', 'a newly held sale starts unresolved');
    $assert((string)$row['customer_label'] === 'Ms. Santos', 'the customer label is kept');
    $assert((int)$row['item_count'] === 2, 'the held sale records the item count');
    $assert(abs((float)$row['total_amount'] - 50.00) < 0.001, 'the held sale records the cart total');
    $assert($row['expires_at'] !== null, 'a held sale carries an expiry the sweep can consider');

    $secondCaseyHeld = $held->hold($casey, 'cashier', [2 => ['qty' => 1]]);
    $secondCaseyHeldId = (int)$secondCaseyHeld['held_sale_id'];
    $danaHeld = $held->hold($dana, 'cashier', [2 => ['qty' => 1]]);
    $danaHeldId = (int)$danaHeld['held_sale_id'];
    // Two Cashiers are each holding work with both shifts open. Each cart landed
    // on the shift of the Cashier who held it, because the shift is derived from
    // the authenticated Cashier and never taken from the request.
    $assert(
        (int)$pdo->query("SELECT shift_id FROM held_sales WHERE held_sale_id = {$secondCaseyHeldId}")->fetchColumn() === $caseyShiftId,
        'a second Cashier holding work with another shift open still lands on their own shift'
    );
    $assert(
        (int)$pdo->query("SELECT shift_id FROM held_sales WHERE held_sale_id = {$danaHeldId}")->fetchColumn() === $danaShiftId,
        'the other Cashier\'s cart lands on the other Cashier\'s shift'
    );

    // --- AC 2: a Cashier sees and resumes only their own held sales ---------

    $caseyIds = $idsOf($held->openForCashier($casey));
    $assert(in_array($caseyHeldId, $caseyIds, true), 'a Cashier lists their own held sale');
    $assert(in_array($secondCaseyHeldId, $caseyIds, true), 'a Cashier lists every held sale they own');
    $assert(!in_array($danaHeldId, $caseyIds, true), 'a Cashier never lists another Cashier\'s held sale');
    $assert(
        $idsOf($held->openForCashier($dana)) === [$danaHeldId],
        'the other Cashier sees only the held sale they own'
    );

    $expectRefusal(
        static fn() => $held->resume($casey, 'cashier', $danaHeldId),
        'resuming another Cashier\'s held sale is refused'
    );
    $expectRefusal(
        static fn() => $held->discard($casey, 'cashier', $danaHeldId, 'customer_cancelled'),
        'discarding another Cashier\'s held sale is refused'
    );
    $expectRefusal(
        static fn() => $held->discard($dana, 'admin', $danaHeldId, 'customer_cancelled'),
        'discarding outside the active Cashier workspace is refused'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$danaHeldId}")->fetchColumn() === 'held',
        'a refused cross-Cashier or cross-workspace action changes nothing'
    );

    $resumed = $held->resume($casey, 'cashier', $caseyHeldId);
    $assert((int)$resumed['held_sale_id'] === $caseyHeldId, 'a Cashier resumes their own held sale');
    $assert((int)$resumed['shift_id'] === $caseyShiftId, 'a resumed held sale keeps its Cashier Shift');
    $assert(isset($resumed['cart'][1]['qty']) && (int)$resumed['cart'][1]['qty'] === 2, 'the resumed cart is the held cart');
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$caseyHeldId}")->fetchColumn() === 'resumed',
        'a resumed held sale stays unresolved until it is completed or discarded'
    );
    $assert(
        in_array($caseyHeldId, $idsOf($held->openForCashier($casey)), true),
        'a resumed held sale is still listed, so a reloaded till can recover the cart'
    );
    $expectRefusal(
        static fn() => $held->resume($casey, 'cashier', $absentHeldSaleId),
        'resuming a held sale that does not exist is refused'
    );
    $expectRefusal(
        static fn() => $held->resume($casey, 'admin', $secondCaseyHeldId),
        'resuming outside the active Cashier workspace is refused'
    );

    // --- AC 4: a Cashier Shift cannot close over an unresolved held sale ----

    $expectedBeforeDiscard = (float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'];
    $expectRefusal(
        static fn() => $shifts->closeShift($casey, 200.00, ''),
        'a shift with a held sale is refused closure'
    );
    $expectRefusal(
        static fn() => $shifts->closeShift($casey, 200.00, '', $alex),
        'an Administrator cannot close a shift over an unresolved held sale either'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id = {$caseyShiftId} AND status = 'open'")->fetchColumn() === 1,
        'a refused closure leaves the Cashier Shift open'
    );
    $assert(
        $idsOf($shifts->unresolvedHeldSales($caseyShiftId)) === [$caseyHeldId, $secondCaseyHeldId],
        'the shift names every unresolved held sale it owns, resumed ones included, oldest first'
    );
    $assert(
        $idsOf($shifts->unresolvedHeldSales($danaShiftId)) === [$danaHeldId],
        "one Cashier's held sales never block another Cashier's shift"
    );
    $assert(
        ($shifts->unresolvedHeldSales($caseyShiftId)[0]['reference_no'] ?? '') !== '',
        'the closing form is told which carts are in the way, not merely that some are'
    );

    // --- AC 3: discarding needs a structured reason, and moves no cash ------

    $expectRefusal(
        static fn() => $held->discard($casey, 'cashier', $caseyHeldId, ''),
        'discarding without a reason is refused'
    );
    $expectRefusal(
        static fn() => $held->discard($casey, 'cashier', $caseyHeldId, 'because_i_said_so'),
        'discarding with a reason outside the fixed set is refused'
    );
    $expectRefusal(
        static fn() => $held->discard($casey, 'cashier', $caseyHeldId, 'other'),
        'discarding as Other without a note is refused'
    );
    $expectRefusal(
        static fn() => $held->discard($casey, 'cashier', $absentHeldSaleId, 'other', 'No such cart'),
        'discarding a held sale that does not exist is refused'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$caseyHeldId}")->fetchColumn() === 'resumed',
        'a refused discard leaves the held sale unresolved'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Held Sales'")->fetchColumn() === 0,
        'a refused discard writes no Protected Audit Record'
    );

    $salesBeforeDiscard = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $movementsBeforeDiscard = (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn();
    $stockBeforeDiscard = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
    $held->discard($casey, 'cashier', $caseyHeldId, 'customer_cancelled', 'Customer left the queue');
    $discarded = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$caseyHeldId}")->fetch(PDO::FETCH_ASSOC);
    $assert((string)$discarded['status'] === 'discarded', 'a discarded held sale is resolved');
    $assert((string)$discarded['discard_reason'] === 'customer_cancelled', 'the structured reason is recorded');
    $assert((string)$discarded['discard_note'] === 'Customer left the queue', 'the note is recorded');
    $assert((int)$discarded['discarded_by'] === $casey, 'the discarding Cashier is recorded');
    $assert($discarded['resolved_at'] !== null, 'a discarded held sale records when it was resolved');
    $assert($discarded['sale_id'] === null, 'a discarded held sale names no sale');
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeDiscard,
        'discarding a held sale writes no sale'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === $movementsBeforeDiscard,
        'discarding a held sale records no drawer movement'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === $stockBeforeDiscard,
        'discarding a held sale moves no stock'
    );
    $assert(
        abs((float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'] - $expectedBeforeDiscard) < 0.001,
        'discarding a held sale has no cash effect on expected drawer cash'
    );

    $audit = $pdo->query("SELECT * FROM activity_log WHERE module = 'Held Sales'")->fetch(PDO::FETCH_ASSOC);
    $assert($audit !== false, 'discarding a held sale creates a Protected Audit Record');
    $assert((int)$audit['user_id'] === $casey, 'the Protected Audit Record names the acting Cashier');
    $assert((int)$audit['record_id'] === $caseyHeldId, 'the Protected Audit Record names the affected held sale');
    $assert(
        (string)$audit['category'] === 'store_operation',
        'a discarded held sale is a Store operation Protected Audit Record'
    );
    $payload = json_decode((string)$audit['new_value'], true);
    $assert(
        is_array($payload)
            && ($payload['discard_reason'] ?? null) === 'customer_cancelled'
            && ($payload['discard_reason_label'] ?? null) === HeldSaleService::DISCARD_REASONS['customer_cancelled']
            && ($payload['discard_note'] ?? null) === 'Customer left the queue'
            && (int)($payload['shift_id'] ?? 0) === $caseyShiftId
            && (int)($payload['cashier_id'] ?? 0) === $casey,
        'the Protected Audit Record carries the reason, its label, the note, the Cashier, and the Cashier Shift'
    );

    // Discarding the last unresolved held sale releases the shift for closure.
    $held->discard($casey, 'cashier', $secondCaseyHeldId, 'entered_in_error');
    $assert(
        $shifts->unresolvedHeldSales($caseyShiftId) === [],
        'a shift with no unresolved held sale may close'
    );
    $closed = $shifts->closeShift($casey, 200.00, 'All held sales resolved');
    $assert(
        (int)$closed['shift_id'] === $caseyShiftId && (string)$closed['status'] === 'closed',
        'the Cashier Shift closes once every held sale is resolved'
    );

    // --- AC 5: completing a resumed held sale keeps the shift attribution --

    $resumedShiftId = $shifts->openShift($casey, 'cashier', 10, 500.00);
    $pending = $held->hold($casey, 'cashier', [2 => ['qty' => 2]]);
    $pendingId = (int)$pending['held_sale_id'];
    $held->resume($casey, 'cashier', $pendingId);

    $expectRefusal(
        static fn() => $held->assertCompletable($casey, $pendingId, $danaShiftId),
        'completing a held sale against another Cashier Shift is refused'
    );
    $expectRefusal(
        static fn() => $held->assertCompletable($dana, $pendingId, $danaShiftId),
        'completing another Cashier\'s held sale is refused'
    );
    $expectRefusal(
        static fn() => $held->assertCompletable($casey, $absentHeldSaleId, $resumedShiftId),
        'completing a held sale that does not exist is refused'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$pendingId}")->fetchColumn() === 'resumed',
        'a refused completion leaves the held sale unresolved'
    );

    // A cart that is still merely held is not on the till. Completing one would
    // mean marking it sold without its contents ever being sold, and there is no
    // honest flow that needs it: a Cashier who pays for a cart resumes it first.
    $neverResumed = $held->hold($casey, 'cashier', [1 => ['qty' => 1]]);
    $neverResumedId = (int)$neverResumed['held_sale_id'];
    $expectRefusal(
        static fn() => $held->assertCompletable($casey, $neverResumedId, $resumedShiftId),
        'a held sale that was never resumed cannot be completed'
    );
    $expectRefusal(
        static fn() => $sales->checkout(
            [['product_id' => 1, 'qty' => 1]],
            $casey,
            'cashier',
            'cash',
            ['cash_received' => 50, 'held_sale_id' => $neverResumedId]
        ),
        'a checkout cannot complete a held sale that was never resumed'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$neverResumedId}")->fetchColumn() === 'held',
        'a refused completion of a never-resumed held sale leaves it held'
    );

    $salesBeforeCompletion = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $expectRefusal(
        static fn() => $sales->checkout([2 => ['product_id' => 2, 'qty' => 2]], $dana, 'cashier', 'cash', ['cash_received' => 100, 'held_sale_id' => $pendingId]),
        "a checkout cannot complete another Cashier's held sale"
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeCompletion,
        'a refused completion writes no sale'
    );

    $sale = $sales->checkout(
        [['product_id' => 2, 'qty' => 2]],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 100, 'held_sale_id' => $pendingId]
    );
    $completed = $pdo->query("SELECT * FROM held_sales WHERE held_sale_id = {$pendingId}")->fetch(PDO::FETCH_ASSOC);
    $assert((string)$completed['status'] === 'completed', 'a checkout completes the resumed held sale');
    $assert((int)$completed['sale_id'] === (int)$sale['sale_id'], 'the completed held sale names the sale it became');
    $assert(
        (int)$completed['shift_id'] === $resumedShiftId,
        'the completed held sale still names the Cashier Shift it was held under'
    );
    $sold = $pdo->query('SELECT * FROM sales WHERE sale_id = ' . (int)$sale['sale_id'])->fetch(PDO::FETCH_ASSOC);
    $assert((int)$sold['shift_id'] === $resumedShiftId, 'the sale runs under the Cashier Shift that held the cart');
    $assert((int)$sold['cashier_id'] === $casey, 'the sale is still attributed to the Cashier who held it');
    $assert(
        !in_array($pendingId, $idsOf($held->openForCashier($casey)), true),
        'a completed held sale leaves the unresolved list'
    );
    $assert(
        !in_array($pendingId, $idsOf($shifts->unresolvedHeldSales($resumedShiftId)), true),
        'a completed held sale no longer blocks closure'
    );
    $expectRefusal(
        static fn() => $held->assertCompletable($casey, $pendingId, $resumedShiftId),
        'a checkout cannot complete a held sale that is already resolved'
    );

    // An ordinary checkout that names no held sale resolves none.
    $stillHeld = $held->hold($casey, 'cashier', [1 => ['qty' => 1]]);
    $stillHeldId = (int)$stillHeld['held_sale_id'];
    $sales->checkout([['product_id' => 1, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 50]);
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$stillHeldId}")->fetchColumn() === 'held',
        'a checkout that names no held sale resolves none'
    );
    $expectRefusal(
        static fn() => $sales->checkout([['product_id' => 1, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 50, 'held_sale_id' => $absentHeldSaleId]),
        'a checkout naming a held sale that is not the Cashier\'s is refused'
    );

    // --- AC 6: expiry cannot resolve a held sale out from under its shift ---

    $pdo->exec("UPDATE held_sales SET expires_at = '2000-01-01 00:00:00'");
    $swept = $held->sweepExpiredOrphans();
    $assert($swept === 0, 'no held sale expires while its Cashier Shift is still open');
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$stillHeldId}")->fetchColumn() === 'held',
        'a held sale past its expiry is still unresolved and still blocks closure'
    );
    $assert(
        (string)$pdo->query("SELECT status FROM held_sales WHERE held_sale_id = {$danaHeldId}")->fetchColumn() === 'held',
        "another Cashier's held sale is not expired out from under them either"
    );
    $expectRefusal(
        static fn() => $shifts->closeShift($casey, 500.00, 'Trying to walk away from a cart'),
        'an expired held sale still refuses closure until it is resolved'
    );
    $blocking = $idsOf($shifts->unresolvedHeldSales($resumedShiftId));
    $expectedBlocking = [$stillHeldId, $neverResumedId];
    sort($blocking);
    sort($expectedBlocking);
    $assert(
        $blocking === $expectedBlocking,
        'both held sales past their expiry still block their shift from closing'
    );

    $held->discard($casey, 'cashier', $stillHeldId, 'customer_cancelled', 'Nobody came back for it');
    $held->discard($casey, 'cashier', $neverResumedId, 'entered_in_error');
    $shifts->closeShift($casey, 500.00, 'Expired cart discarded with a reason');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id = {$resumedShiftId} AND status = 'closed'")->fetchColumn() === 1,
        'the shift closes only after the expired held sale is discarded with a reason'
    );

    // A held sale belonging to no open shift is an orphan the sweep may clear.
    // That is how a cart recorded before this deployment stops waiting on anybody.
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash, status) VALUES (?, ?, 0.00, ?)')
        ->execute([$dana, 11, 'closed']);
    $pdo->exec("INSERT INTO held_sales (cashier_id, shift_id, reference_no, cart_json, item_count, total_amount, status, expires_at)
        VALUES ({$dana}, NULL, 'ORPHAN-1', '{}', 1, 10.00, 'held', '2000-01-01 00:00:00')");
    $pdo->exec("INSERT INTO held_sales (cashier_id, shift_id, reference_no, cart_json, item_count, total_amount, status, expires_at)
        VALUES ({$dana}, NULL, 'ORPHAN-2', '{}', 1, 10.00, 'resumed', '2000-01-01 00:00:00')");
    $pdo->exec("INSERT INTO held_sales (cashier_id, shift_id, reference_no, cart_json, item_count, total_amount, status, expires_at)
        VALUES ({$dana}, NULL, 'ORPHAN-3', '{}', 1, 10.00, 'held', '2999-01-01 00:00:00')");
    $orphans = $held->sweepExpiredOrphans();
    $assert($orphans === 2, 'the sweep expires exactly the held and resumed orphans that are past their expiry');
    $orphanStatuses = [];
    foreach ($pdo->query("SELECT reference_no, status FROM held_sales WHERE reference_no LIKE 'ORPHAN-%'") as $row) {
        $orphanStatuses[$row['reference_no']] = (string)$row['status'];
    }
    $assert($orphanStatuses === ['ORPHAN-1' => 'expired', 'ORPHAN-2' => 'expired', 'ORPHAN-3' => 'held'], 'an unexpired orphan is left for its owner to resolve');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Held Sales' AND action LIKE '%expire%'")->fetchColumn() === 0,
        'expiring an orphan needs no reason and writes no Protected Audit Record, because no open shift is waiting on it'
    );

    // Suspended work is not a sale: only a discard is audited, and holding,
    // resuming, or completing one never duplicates a completed sale into the
    // audit log (the sales ledger stays authoritative, #89).
    $auditActions = $pdo->query("SELECT DISTINCT action FROM activity_log WHERE module = 'Held Sales'")->fetchAll(PDO::FETCH_COLUMN);
    $assert(
        $auditActions === [HeldSaleService::AUDIT_ACTION_DISCARDED],
        'only discards are audited as held-sale events, and holding, resuming, completing, and expiring are not'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Held Sales'")->fetchColumn() === 4,
        'each of the four discards is recorded exactly once'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Sales'")->fetchColumn() === 0,
        'a completed held sale is not mirrored into the sales audit module'
    );

    // The endpoint is the only surface a browser reaches, and it holds no rules
    // of its own: the sweep that could resolve a cart quietly lives in the
    // service, where it cannot skip the shift a cart belongs to.
    $root = dirname(__DIR__, 3);
    $endpoint = (string)@file_get_contents($root . '/src/frontend/components/barcodeScanner/apiScanner/held_sales.php');
    $assert($endpoint !== '', 'the held sales endpoint source must be readable');
    $assert(
        str_contains($endpoint, 'new HeldSaleService($pdo'),
        'the endpoint delegates to HeldSaleService rather than holding the rules in the page'
    );
    $assert(
        !str_contains($endpoint, 'INSERT INTO activity_log')
            && !str_contains($endpoint, 'INSERT INTO held_sales'),
        'the endpoint writes neither the held sale nor its Protected Audit Record itself'
    );
    $assert(
        !str_contains($endpoint, "SET status='expired'"),
        'the endpoint never expires a held sale with a statement of its own'
    );
    $assert(
        str_contains($endpoint, 'sweepExpiredOrphans()'),
        'the endpoint runs the shift-scoped expiry sweep through the service'
    );
} catch (Throwable $exception) {
    $failures[] = 'Held Sale Cashier Shift ownership contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Held Sale Cashier Shift ownership contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Held Sale Cashier Shift ownership contract: passed\n";
