<?php
// Sale Cashier Shift attribution contract (ticket #89).
//
// A sale is written by a Cashier, in the active Cashier workspace, under that
// Cashier's own open Cashier Shift. The contract exercises the three refusals —
// wrong workspace, no open shift, another Cashier's shift — then proves the
// attribution a successful sale carries: the Cashier, the Cashier Shift, and a
// Register determinable from that shift rather than supplied by the client. It
// also proves the write is atomic, that an ordinary sale is not duplicated into
// Protected Audit Records, and that an unlinked sale from before the deployment
// is left exactly as it is.
//
// The SQLite fixture stands in for the MySQL constraints in its SQLite
// equivalent, so the rule under test is identical and only the dialect differs.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';

use App\Services\ReceiptTableService;

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
    } catch (DomainException | RuntimeException $exception) {
        // Expected: an authorization/validation refusal (DomainException) or a
        // stock failure (RuntimeException) the operator can act on, never a raw
        // database error (ADR-0002).
        if (trim($exception->getMessage()) === '') {
            $failures[] = $message . ' (and it explains itself)';
        }
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // MySQL date functions the checkout path uses, expressed in SQLite terms so
    // only the dialect differs and the rule under test is identical.
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
    $pdo->exec('CREATE TABLE product_batches (
        batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        batch_number TEXT NULL,
        expiration_date TEXT NULL,
        date_received TEXT NULL,
        remaining_quantity INTEGER NOT NULL DEFAULT 0
    )');
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

    $pdo->exec('CREATE TABLE sale_items (
        sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL
    )');
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
    $pdo->exec('CREATE TABLE stock_movements (
        stock_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        change_qty INTEGER NOT NULL,
        reason TEXT NULL,
        moved_by INTEGER NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');
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
        new_value TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec('CREATE TABLE notification_preferences (user_id INTEGER PRIMARY KEY, notify_low_stock INTEGER DEFAULT 1, low_stock_threshold INTEGER NULL)');
    $pdo->exec('CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, type TEXT NOT NULL, title TEXT, message TEXT, reference_id INTEGER NULL, reference_type TEXT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $pdo->exec('CREATE TABLE store_write_gate (gate_key TEXT PRIMARY KEY, paused_at TEXT NULL, paused_by TEXT NULL)');
    // The single-Store compatibility scope the receipt ledger reads through.
    $pdo->exec("CREATE TABLE branches (
        branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
        branch_name TEXT NOT NULL,
        branch_code TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'RetailMind Store', 'RETAILMIND-STORE', 'active')");

    // Every fixture account stores the Cashier role, including the one that will
    // attempt an administrative workspace. The stored role is deliberately
    // never consulted, so this is what proves the active workspace alone decides
    // who may sell (#86) — and it keeps the post-commit expiry notification,
    // which only runs for oversight roles, out of this Store population.
    $insertUser = static function (string $name) use ($pdo): int {
        $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier'")->fetchColumn();
        $pdo->prepare("INSERT INTO users (full_name, role_id, status) VALUES (?, ?, 'active')")->execute([$name, $roleId]);
        return (int)$pdo->lastInsertId();
    };

    $casey = $insertUser('Casey Cashier');
    $dana = $insertUser('Dana Cashier');
    $alex = $insertUser('Alex Cashier-Also-Administrator');

    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (10, 'Front Counter'), (11, 'Back Counter'), (12, 'Side Counter')");
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([1, 'SKU-1', 'Bottled Water', 25.00]);
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50)');
    $pdo->exec('UPDATE users SET branch_id = 1');

    $service = new SalesWorkflowService($pdo);
    $cart = [['product_id' => 1, 'qty' => 2]];

    // --- AC 1: the three refusals -----------------------------------------

    // Alex holds the Cashier role AND will hold a valid open shift for the whole
    // of this block. Every refusal below is therefore attributable to the rule
    // under test and nothing else: with a shift in hand, the only thing that can
    // refuse these requests is the workspace itself. This is the multi-role case
    // from #86 — the account's stored role must never be what decides.
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 300.00)')
        ->execute([$alex, 12]);
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE cashier_id = {$alex} AND status = 'open'")->fetchColumn() === 1,
        'the administrative-workspace actor starts with a valid open shift'
    );

    $expectRefusal(
        static fn() => $service->checkout($cart, $alex, 'admin', 'cash', ['cash_received' => 100]),
        'checkout is refused outside the Cashier workspace even with an open shift'
    );
    $expectRefusal(
        static fn() => $service->checkout($cart, $alex, 'super_admin', 'cash', ['cash_received' => 100]),
        'checkout is refused in the Super Administrator workspace even with an open shift'
    );
    $expectRefusal(
        static fn() => $service->checkout($cart, $alex, 'inventory_manager', 'cash', ['cash_received' => 100]),
        'checkout is refused in the Inventory Manager workspace even with an open shift'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === 0,
        'a refused checkout writes no sale'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 50,
        'a refused checkout leaves stock untouched'
    );

    // Without an open Cashier Shift.
    $expectRefusal(
        static fn() => $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]),
        'checkout is refused without an open Cashier Shift'
    );

    // Another Cashier's open shift must never authorize this Cashier's sale.
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 500.00)')
        ->execute([$dana, 11]);
    $expectRefusal(
        static fn() => $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]),
        'checkout is refused when only another Cashier has an open shift'
    );
    // A closed shift is not an open one.
    $pdo->exec("UPDATE cashier_shifts SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE cashier_id = {$dana}");
    $expectRefusal(
        static fn() => $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]),
        'checkout is refused when the Cashier Shift is closed'
    );

    // --- AC 2 + AC 3: attribution and atomicity ----------------------------
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 250.00)')
        ->execute([$casey, 10]);
    $shiftId = (int)$pdo->query("SELECT shift_id FROM cashier_shifts WHERE cashier_id = {$casey}")->fetchColumn();

    $result = $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $saleId = (int)$result['sale_id'];

    $sale = $pdo->query("SELECT * FROM sales WHERE sale_id = {$saleId}")->fetch(PDO::FETCH_ASSOC);
    $assert($sale !== false, 'a permitted sale is written');
    $assert((int)$sale['cashier_id'] === $casey, 'the sale records the Cashier who sold it');
    $assert((int)$sale['shift_id'] === $shiftId, 'the sale records the open Cashier Shift it ran under');
    $assert(abs((float)$sale['total_amount'] - 50.00) < 0.001, 'the sale total is the cart total');
    $assert(abs((float)$sale['change_due'] - 50.00) < 0.001, 'the payment is recorded with the sale');
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 48,
        'the stock mutation lands with the sale'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'sale' AND moved_by = " . $casey)->fetchColumn() === 1,
        'the stock movement is attributed to the Cashier'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE sale_id = {$saleId}")->fetchColumn() === 1,
        'the sale line is written with the sale'
    );

    // The Register is determinable from the shift the sale names, and is not a
    // second copy on the sale that a client could have supplied.
    $registerFromSale = $pdo->query(
        "SELECT r.register_id, r.name
         FROM sales s
         JOIN cashier_shifts cs ON cs.shift_id = s.shift_id
         JOIN registers r ON r.register_id = cs.register_id
         WHERE s.sale_id = {$saleId}"
    )->fetch(PDO::FETCH_ASSOC);
    $assert((int)($registerFromSale['register_id'] ?? 0) === 10, 'the sale\'s Register is determinable from its Cashier Shift');
    $assert(($registerFromSale['name'] ?? null) === 'Front Counter', 'the determined Register is the one the shift anchors');
    $saleColumns = array_map('strtolower', array_keys($sale));
    $assert(!in_array('register_id', $saleColumns, true), 'the sale stores no Register of its own');

    // A Register named by the request must not redirect the attribution: the
    // shift already decided it, and the request is never consulted.
    $pdo->prepare('INSERT INTO cashier_shifts (cashier_id, register_id, opening_cash) VALUES (?, ?, 100.00)')
        ->execute([$dana, 11]);
    $danaShiftId = (int)$pdo->query("SELECT shift_id FROM cashier_shifts WHERE cashier_id = {$dana} AND status = 'open'")->fetchColumn();
    $service->checkout($cart, $casey, 'cashier', 'cash', [
        'cash_received' => 100,
        'register_id' => 11,
        'shift_id' => $danaShiftId,
    ]);
    $attributed = (int)$pdo->query("SELECT shift_id FROM sales WHERE cashier_id = {$casey} ORDER BY sale_id DESC LIMIT 1")->fetchColumn();
    $assert(
        $attributed === $shiftId,
        'a client-supplied Cashier Shift and Register never redirect a sale'
    );

    // --- Ticket #90: a locked Register authorizes nothing --------------------
    // A Cashier who locks the till for a break is not at the till. The lock has
    // to be settled from the same row-locked read that picks the attribution,
    // otherwise a shift locked between the check and the sale lets one
    // transaction through. The lock is set directly here so this contract stays
    // about the sale seam; CashierShiftService's own behaviour is covered by
    // cashier_shift_register_lock_contract.php.
    $salesBeforeLock = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $stockBeforeLock = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
    $closedBeforeLock = (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed'")->fetchColumn();
    $pdo->exec("UPDATE cashier_shifts SET locked_at = '2026-09-29 10:15:00' WHERE shift_id = {$shiftId}");
    $expectRefusal(
        static fn() => $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]),
        'checkout is refused while the Register is locked'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeLock,
        'a refused checkout on a locked Register writes no sale'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === $stockBeforeLock,
        'a refused checkout on a locked Register leaves stock untouched'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'closed'")->fetchColumn() === $closedBeforeLock,
        'locking the Register for a break closes nothing and reconciles nothing'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE shift_id = {$shiftId} AND status = 'open'")->fetchColumn() === 1,
        'the Cashier Shift stays open across a lock'
    );

    // Resuming the same shift authorizes the same sale again, on the same
    // Register, without a second shift ever being opened.
    $pdo->exec("UPDATE cashier_shifts SET locked_at = NULL WHERE shift_id = {$shiftId}");
    $resumed = $service->checkout($cart, $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $assert(
        (int)$pdo->query('SELECT shift_id FROM sales WHERE sale_id = ' . (int)$resumed['sale_id'])->fetchColumn() === $shiftId,
        'the resumed Cashier Shift authorizes sales again'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cashier_shifts WHERE cashier_id = ' . $casey)->fetchColumn() === 1,
        'resuming never opens a second Cashier Shift'
    );

    // --- AC 4: an ordinary sale is not duplicated as an audit record ------
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM activity_log')->fetchColumn() === 0,
        'an ordinary sale writes no Protected Audit Record'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Sales'")->fetchColumn() === 0,
        'an ordinary sale is not mirrored into the sales audit module'
    );

    // --- AC 3: a failure part-way through rolls the whole sale back -------
    // Stock is taken to zero so the checkout fails while mutating, which is the
    // point at which a sale, its lines, and its stock movement have all been
    // written but not yet committed.
    $pdo->exec('UPDATE inventory SET quantity_on_hand = 1 WHERE product_id = 1');
    $salesBefore = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $expectRefusal(
        static fn() => $service->checkout([['product_id' => 1, 'qty' => 2]], $casey, 'cashier', 'cash', ['cash_received' => 100]),
        'a checkout that fails part-way is refused'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBefore,
        'a failed checkout leaves no half-written sale'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 1,
        'a failed checkout leaves stock unchanged'
    );
    $pdo->exec('UPDATE inventory SET quantity_on_hand = 50 WHERE product_id = 1');

    // --- AC 5: an unlinked sale is left alone and labelled ------------------
    $pdo->prepare('INSERT INTO sales (cashier_id, total_amount, payment_method) VALUES (?, ?, ?)')
        ->execute([$dana, 12.00, 'cash']);
    $legacySaleId = (int)$pdo->lastInsertId();
    $legacyBefore = $pdo->query("SELECT * FROM sales WHERE sale_id = {$legacySaleId}")->fetch(PDO::FETCH_ASSOC);
    $assert($legacyBefore['shift_id'] === null, 'a sale with no shift stays unlinked');

    $table = new ReceiptTableService($pdo);
    $rows = $table->fetch(['draw' => 1, 'start' => 0, 'length' => 25]);
    $byId = [];
    foreach ($rows['data'] as $row) {
        $byId[(int)$row['sale_id']] = $row;
    }
    $assert(isset($byId[$saleId]), 'the ledger lists the attributed sale');
    $assert(
        ($byId[$saleId]['attribution_label'] ?? null) === 'Shift #' . $shiftId,
        'the ledger names the Cashier Shift an attributed sale ran under'
    );
    $assert(
        ($byId[$saleId]['register_name'] ?? null) === 'Front Counter',
        'the ledger resolves the Register from the Cashier Shift'
    );
    $assert(
        ($byId[$legacySaleId]['attribution_label'] ?? null) === 'Legacy / Unassigned',
        'the ledger presents an unlinked sale as Legacy / Unassigned'
    );
    // `??` cannot be used here: it treats a legitimate NULL as absent, which is
    // exactly the value being asserted. The key must exist and be null.
    $assert(
        array_key_exists('shift_id', $byId[$legacySaleId]) && $byId[$legacySaleId]['shift_id'] === null,
        'the ledger does not infer a Cashier Shift for an unlinked sale'
    );
    $assert(
        array_key_exists('register_name', $byId[$legacySaleId]) && $byId[$legacySaleId]['register_name'] === null,
        'the ledger does not infer a Register for an unlinked sale'
    );
    $legacyAfter = $pdo->query("SELECT * FROM sales WHERE sale_id = {$legacySaleId}")->fetch(PDO::FETCH_ASSOC);
    $assert(
        (int)$legacyAfter['cashier_id'] === $dana && abs((float)$legacyAfter['total_amount'] - 12.00) < 0.001,
        'reading the ledger leaves an unlinked sale unchanged'
    );

    // The Cashier Shift column is appended, not inserted: DataTables order
    // indices are positional, and receipt_table_contract.php sorts on the
    // pre-existing indices. Inserting beside "Cashier" would silently repoint
    // that contract at the wrong columns, so pin the order here.
    $root = dirname(__DIR__, 3);
    $service = (string)@file_get_contents($root . '/src/backend/app/Services/ReceiptTableService.php');
    $page = (string)@file_get_contents($root . '/src/frontend/components/invoice/sales.php');
    $assert($service !== '' && $page !== '', 'Cashier Shift presentation sources must be readable');
    $assert(
        preg_match('/private const ORDER_COLUMNS = \[(.*?)\];/s', $service, $matches) === 1
            && preg_match('/3 => \'item_count\',.*?4 => \'s\.total_amount\',.*?5 => \'s\.payment_method\',.*?6 => \'reversal_count\',.*?7 => \'s\.shift_id\',/s', $matches[1]) === 1,
        'the Cashier Shift column is appended at index 7, leaving the existing order indices in place'
    );
    $headerOrder = [];
    if (preg_match('/<th>Receipt #<\/th>(.*?)<th>Actions<\/th>/s', $page, $matches) === 1) {
        preg_match_all('/<th>([^<]+)<\/th>/', $matches[1], $headers);
        $headerOrder = $headers[1];
    }
    $assert(
        $headerOrder === ['Date', 'Cashier', 'Items', 'Total', 'Payment', 'Reversals', 'Cashier Shift'],
        'the Cashier Shift header sits after Reversals so the rendered columns match ORDER_COLUMNS'
    );
} catch (Throwable $exception) {
    $failures[] = 'Sale Cashier Shift attribution contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Sale Cashier Shift attribution contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Sale Cashier Shift attribution contract: passed\n";
