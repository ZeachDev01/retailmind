<?php
// Append-only Cash Refund contract (ticket #92).
//
// A Cash Refund is a new, append-only record against a completed sale: it never
// edits or deletes the sale, it names the Cashier and the Cashier Shift that
// issued it, and it moves only what it is allowed to move. The contract
// exercises the refusals that authorize it — wrong workspace, no open shift, a
// closed shift, a locked Register, another Cashier's sale — then the six
// acceptance criteria: the remaining-balance caps, the recorded reason,
// Restockable versus Damaged inventory effects, the immutable sale, the effect
// on expected drawer cash, and the atomicity of the refund with its inventory
// effects and its Protected Audit Record.
//
// The SQLite fixture stands in for the MySQL constraints in its SQLite
// equivalent, so the rule under test is identical and only the dialect differs.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Receipts/RefundReceiptPresentation.php';
require_once __DIR__ . '/../app/Receipts/ReceiptPaperService.php';

use App\Services\CashierShiftService;
use App\Services\CashRefundService;

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
    // MySQL date functions the checkout, refund, and reconciliation paths use,
    // expressed in SQLite terms so only the dialect differs and the rule under
    // test is identical.
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    // MySQL's GREATEST, which the sold-count rewind uses to floor at zero.
    $pdo->sqliteCreateFunction(
        'GREATEST',
        static fn($left, $right) => max((float)$left, (float)$right),
        2
    );
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
    $pdo->exec("CREATE TABLE cash_drawer_movements (
        drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL,
        amount REAL NOT NULL,
        reason TEXT NULL,
        recorded_by INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
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
    $pdo->exec('CREATE TABLE sale_item_batches (sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER NOT NULL, batch_id INTEGER NOT NULL, quantity INTEGER NOT NULL)');
    $pdo->exec('CREATE TABLE sale_receipt_details (sale_id INTEGER PRIMARY KEY, details_json TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE refund_receipt_details (refund_id INTEGER PRIMARY KEY, details_json TEXT NOT NULL)');
    // The append-only refund ledger, mirroring the migration and schema.sql.
    $pdo->exec("CREATE TABLE cash_refunds (
        refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
        sale_id INTEGER NOT NULL,
        shift_id INTEGER NOT NULL,
        cashier_id INTEGER NOT NULL,
        refund_amount REAL NOT NULL DEFAULT 0.00,
        payment_method TEXT NOT NULL DEFAULT 'cash',
        reason TEXT NOT NULL,
        note TEXT NULL, original_cashier_id INTEGER NULL, approved_by INTEGER NULL, exception_reason TEXT NULL, payment_reference TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE cash_refund_items (
        refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
        refund_id INTEGER NOT NULL,
        sale_item_id INTEGER NOT NULL,
        product_id INTEGER NOT NULL,
        quantity INTEGER NOT NULL,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL DEFAULT 0.00,
        disposition TEXT NOT NULL DEFAULT 'restockable'
    )");
    // The database-level guard against counting one line twice in one refund.
    $pdo->exec('CREATE UNIQUE INDEX uq_cash_refund_items_refund_line
        ON cash_refund_items (refund_id, sale_item_id)');
    $pdo->exec('CREATE INDEX idx_cash_refunds_sale ON cash_refunds (sale_id)');
    $pdo->exec('CREATE INDEX idx_cash_refunds_shift ON cash_refunds (shift_id)');
    $pdo->exec('CREATE INDEX idx_cash_refunds_cashier ON cash_refunds (cashier_id)');
    $pdo->exec('CREATE INDEX idx_cash_refund_items_sale_item ON cash_refund_items (sale_item_id)');
    $pdo->exec('CREATE INDEX idx_cash_refund_items_product ON cash_refund_items (product_id)');
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
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('Contract period', '2000-01-01', '2100-12-31', 'open')");
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
    foreach ([
        '202610200001_sales_packs_and_stock_forms.php',
        '202610200003_sale_items_sales_pack_forms.php',
        '202610200006_cash_refund_sales_pack_forms.php',
    ] as $migrationFile) {
        $m = require __DIR__ . '/../database/migrations/' . $migrationFile;
        $m['up']($pdo);
    }

    // Every fixture account stores the Cashier role, including the one that will
    // attempt an administrative workspace, so every refusal below is
    // attributable to the active workspace alone (#86).
    $insertUser = static function (string $name, ?string $password = null) use ($pdo): int {
        $roleId = (int)$pdo->query("SELECT role_id FROM roles WHERE role_name = 'cashier'")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO users (full_name, role_id, status, branch_id, password_hash) VALUES (?, ?, 'active', 1, ?)"
        )->execute([$name, $roleId, $password !== null ? password_hash($password, PASSWORD_DEFAULT) : null]);
        return (int)$pdo->lastInsertId();
    };

    $casey = $insertUser('Casey Cashier', 'casey-pass-1');
    $dana = $insertUser('Dana Cashier');
    $noShift = $insertUser('No Shift Cashier');

    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (10, 'Front Counter'), (11, 'Back Counter')");
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([1, 'SKU-1', 'Bottled Water', 25.00]);
    $pdo->prepare("INSERT INTO products (product_id, sku, product_name, unit_price, status, branch_id) VALUES (?, ?, ?, ?, 'active', 1)")
        ->execute([2, 'SKU-2', 'Bottled Tea', 40.00]);
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 50), (2, 30)');
    // A batch to restock into, so a Restockable refund is proved at the batch the
    // unit was sold from and not merely at the inventory total.
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, remaining_quantity, loose_quantity) VALUES (1, 1, 'B-1', 50, 50)");

    $shifts = new CashierShiftService($pdo);
    $refunds = new CashRefundService($pdo, $shifts);
    $sales = new SalesWorkflowService($pdo);

    $caseyShiftId = $shifts->openShift($casey, 'cashier', 10, 200.00);
    $danaShiftId = $shifts->openShift($dana, 'cashier', 11, 50.00);
    $assert($caseyShiftId > 0 && $danaShiftId > 0, 'both Cashiers hold an open Cashier Shift of their own');

    // --- the sales under test ------------------------------------------------
    // Every sale below runs on Casey's own open shift, so the refusals below are
    // attributable to the rule under test and to nothing else.

    /** The sale line for a product on a sale. */
    $lineIdOf = static function (int $id, int $productId) use ($pdo): int {
        return (int)$pdo->query(
            "SELECT si.sale_item_id FROM sale_items si WHERE si.sale_id = {$id} AND si.product_id = {$productId}"
        )->fetchColumn();
    };
    /** One returned line, classified. */
    $returnOf = static fn(int $lineId, int $quantity, string $disposition = CashRefundService::RESTOCKABLE): array => [
        $lineId => ['quantity' => $quantity, 'disposition' => $disposition],
    ];
    $linesOf = static function (PDO $pdo, int $refundId): array {
        $stmt = $pdo->prepare('SELECT * FROM cash_refund_items WHERE refund_id = ? ORDER BY refund_item_id');
        $stmt->execute([$refundId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $refundCount = static fn(PDO $pdo): int => (int)$pdo->query('SELECT COUNT(*) FROM cash_refunds')->fetchColumn();
    $refundRow = static function (PDO $pdo, int $refundId): array {
        $stmt = $pdo->prepare('SELECT * FROM cash_refunds WHERE refund_id = ?');
        $stmt->execute([$refundId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };
    $saleRow = static function (PDO $pdo, int $saleId): array {
        $stmt = $pdo->prepare('SELECT * FROM sales WHERE sale_id = ?');
        $stmt->execute([$saleId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    // Sale A: 3 x water at 25.00 and 1 x tea at 40.00, paid cash. 115.00.
    $sale = $sales->checkout(
        [['product_id' => 1, 'qty' => 3], ['product_id' => 2, 'qty' => 1]],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 200]
    );
    $saleId = (int)$sale['sale_id'];
    $waterItemId = $lineIdOf($saleId, 1);
    $teaItemId = $lineIdOf($saleId, 2);
    $assert($waterItemId > 0 && $teaItemId > 0, 'the sale carries a line for each product');

    // Sale B: a Card sale, so the original payment method has somewhere to be
    // different from the one this refund settles in.
    $cardSale = $sales->checkout(
        [['product_id' => 2, 'qty' => 1]],
        $casey,
        'cashier',
        'card',
        ['payment_reference' => 'AUTH-7781']
    );
    $cardSaleId = (int)$cardSale['sale_id'];
    $cardTeaItemId = $lineIdOf($cardSaleId, 2);

    // Sale C: a plain cash sale, the subject of the Damaged and immutable checks.
    $damagedSale = $sales->checkout([['product_id' => 2, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $damagedSaleId = (int)$damagedSale['sale_id'];
    $damagedTeaItemId = $lineIdOf($damagedSaleId, 2);

    $pdo->exec("INSERT INTO promotions (promotion_id, promotion_name, scope, discount_type, discount_value, starts_at, ends_at)
        VALUES (101, 'Five peso promotion', 'all', 'fixed', 5, '2000-01-01', '2099-01-01'),
               (102, 'One cent promotion', 'all', 'fixed', 0.01, '2000-01-01', '2099-01-01'),
               (103, 'Bundle promotion', 'all', 'fixed', 15, '2000-01-01', '2099-01-01')");

    // Sale D: 4 x water with a 5.00 discount, paid 95.00 — so the refundable
    // balance is what the customer actually paid, not the shelf price.
    $discounted = $sales->checkout(
        [['product_id' => 1, 'qty' => 4]],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 100, 'promotion_id' => 101]
    );
    $discountedSaleId = (int)$discounted['sale_id'];
    $discountedItemId = $lineIdOf($discountedSaleId, 1);

    // Sale E: 2 x water paid cash, the subject of the batch restock check.
    $batchSale = $sales->checkout([['product_id' => 1, 'qty' => 2]], $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $batchSaleId = (int)$batchSale['sale_id'];
    $batchItemId = $lineIdOf($batchSaleId, 1);

    // Sale F: Dana's own sale, so the cross-Cashier refusals have a real target.
    $danaSale = $sales->checkout([['product_id' => 2, 'qty' => 1]], $dana, 'cashier', 'cash', ['cash_received' => 100]);
    $danaSaleId = (int)$danaSale['sale_id'];
    $danaTeaItemId = $lineIdOf($danaSaleId, 2);

    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 41,
        'the sales under test drew down available inventory'
    );
    $assert(
        (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1')->fetchColumn() === 41,
        'the sales under test drew down the batch they were sold from'
    );

    // --- what authorizes a refund: the workspace and the Cashier Shift -------

    $expectRefusal(
        static fn() => $refunds->refund($casey, 'admin', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding is refused outside the active Cashier workspace'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'super_admin', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding is refused in the Super Administrator workspace'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'inventory_manager', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding is refused in the Inventory Manager workspace'
    );
    // A Cashier with no shift of their own cannot refund, even though the Store
    // is full of open shifts and open Registers.
    $expectRefusal(
        static fn() => $refunds->refund($noShift, 'cashier', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding is refused without an open Cashier Shift'
    );
    // A refund pays money out of the drawer, so it must be issued under the
    // authenticated Cashier's own open shift and nobody else's.
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $danaSaleId, $returnOf($danaTeaItemId, 1), 'customer_return'),
        "a Cashier cannot refund against another Cashier's sale"
    );
    $expectRefusal(
        static fn() => $refunds->refund($dana, 'cashier', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        "a Cashier cannot refund their own sales from the Cashier Shift they sold under when another Cashier sold it"
    );
    // A closed shift is not an open one.
    $pdo->exec("UPDATE cashier_shifts SET status = 'closed', closed_at = CURRENT_TIMESTAMP WHERE shift_id = {$danaShiftId}");
    $expectRefusal(
        static fn() => $refunds->refund($dana, 'cashier', $danaSaleId, $returnOf($danaTeaItemId, 1), 'customer_return'),
        "refunding is refused when the Cashier's Cashier Shift is closed"
    );
    $danaShiftId = $shifts->openShift($dana, 'cashier', 11, 50.00);
    // A locked Register is on a break, so it authorizes nothing.
    $shifts->lockRegister($casey, 'cashier');
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding is refused while the Register is locked'
    );
    $shifts->unlockRegister($casey, 'cashier', 'casey-pass-1');
    $assert($refundCount($pdo) === 0, 'every refusal so far has written no Cash Refund');

    // The reason, the note, the lines, and the classification are all settled
    // before anything is written, so a refusal leaves nothing behind.
    $reasonsUnderTest = [
        ['', null, 'refunding without a reason is refused'],
        ['because_i_said_so', null, 'refunding with a reason outside the fixed set is refused'],
        ['other', null, 'refunding as Other without a note is refused'],
        ['other', '   ', 'refunding as Other with a blank note is refused'],
    ];
    foreach ($reasonsUnderTest as [$reason, $note, $message]) {
        $expectRefusal(
            static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 1), $reason, $note),
            $message
        );
    }
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 1), 'customer_return', str_repeat('n', 256)),
        'refunding with a note over 255 characters is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, [], 'customer_return'),
        'refunding with no items is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 0), 'customer_return'),
        'refunding a quantity of zero is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, [$waterItemId => ['quantity' => 1]], 'customer_return'),
        'refunding an item that is classified neither Restockable nor Damaged is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund(
            $casey,
            'cashier',
            $saleId,
            [$waterItemId => ['quantity' => 1, 'disposition' => 'resold_anyway']],
            'customer_return'
        ),
        'refunding an item classified outside Restockable and Damaged is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', 0, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding a sale that does not exist is refused'
    );
    $assert($refundCount($pdo) === 0, 'a refused refund writes no Cash Refund');
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === 41,
        'a refused refund moves no stock'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn() === 0,
        'a refused refund writes no Protected Audit Record'
    );

    // --- AC 2: a refund cannot exceed the remaining refundable balance ------

    $assert(
        abs((float)$refunds->refundableAmount($saleId) - 115.00) < 0.001,
        'a cash sale starts with its full amount refundable'
    );
    $assert(
        (int)$refunds->refundableQuantity($waterItemId) === 3,
        'a sale line starts with its full quantity refundable'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 4), 'customer_return'),
        'refunding more than the remaining quantity of a line is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($danaTeaItemId, 1), 'customer_return'),
        'refunding a line that belongs to another sale is refused'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf(987654, 1), 'customer_return'),
        'refunding a line that does not exist is refused'
    );
    $assert(
        abs((float)$refunds->refundableAmount($discountedSaleId) - 95.00) < 0.001,
        'a discounted sale is refundable only up to the amount the customer paid'
    );
    $partialDiscountedId = $refunds->refund(
        $casey,
        'cashier',
        $discountedSaleId,
        $returnOf($discountedItemId, 3),
        'customer_return'
    );
    $assert($partialDiscountedId > 0, 'a partial refund of a discounted sale within the amount paid is written');
    $assert(
        abs((float)$refunds->refundableAmount($discountedSaleId) - 23.75) < 0.001,
        'the remaining refundable amount of a discounted sale falls by the refund'
    );
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $discountedSaleId, $returnOf($discountedItemId, 2), 'customer_return'),
        'refunding more than the remaining discounted quantity is refused'
    );
    $assert(
        $refundCount($pdo) === 1,
        'a refund over the remaining balance writes no Cash Refund'
    );

    // A sale that was not paid in cash has no cash to hand back, so none of its
    // balance is ever cash refundable. The refund is still recorded against the
    // original payment method, and still consumes the sale's refundable balance.
    $assert(
        abs((float)$refunds->refundableAmount($cardSaleId) - 40.00) < 0.001,
        'a Card sale is refundable up to what the customer paid'
    );
    $assert(
        abs((float)$refunds->cashRefundableAmount($cardSaleId)) < 0.001,
        'a Card sale has no cash left to hand back'
    );
    $assert(
        abs((float)$refunds->cashRefundableAmount($saleId) - 115.00) < 0.001,
        'a cash sale is refundable in cash up to what the customer paid'
    );

    // --- AC 3: the refund records the whole of what it is -------------------

    $refundId = $refunds->refund(
        $casey,
        'cashier',
        $saleId,
        $returnOf($waterItemId, 2),
        'customer_return',
        'Customer brought two bottles back'
    );
    $assert($refundId > 0, 'a partial refund within the remaining balance is written');

    $refund = $refundRow($pdo, $refundId);
    $assert((int)$refund['sale_id'] === $saleId, 'the refund names the original sale');
    $assert((int)$refund['cashier_id'] === $casey, 'the refund names the Cashier who issued it');
    $assert((int)$refund['shift_id'] === $caseyShiftId, 'the refund names the open Cashier Shift it was issued under');
    $assert((string)$refund['payment_method'] === 'cash', "the refund carries the sale's original cash payment method");
    $assert((string)$refund['reason'] === 'customer_return', 'the predefined reason is recorded');
    $assert((string)$refund['note'] === 'Customer brought two bottles back', 'the optional note is recorded');
    $assert(trim((string)$refund['created_at']) !== '', 'the refund records when it was issued');
    $assert(abs((float)$refund['refund_amount'] - 50.00) < 0.001, 'the refund amount is the value of the returned units');

    $lines = $linesOf($pdo, $refundId);
    $assert(count($lines) === 1, 'the refund records its returned lines');
    $assert((int)$lines[0]['sale_item_id'] === $waterItemId, 'a refund line names the sold line it came from');
    $assert((int)$lines[0]['quantity'] === 2, 'the refund line records the returned quantity');
    $assert(abs((float)$lines[0]['subtotal'] - 50.00) < 0.001, 'the refund line records the returned value');
    $assert(
        (string)$lines[0]['disposition'] === CashRefundService::RESTOCKABLE,
        'the refund line records its Restockable or Damaged classification'
    );
    $assert(abs((float)$refunds->refundableAmount($saleId) - 65.00) < 0.001, 'the remaining refundable amount falls by the refund');
    $assert((int)$refunds->refundableQuantity($waterItemId) === 1, 'the remaining refundable quantity falls by the refund');

    // Two partial refunds share one balance, and 'other' is the one reason that
    // needs words, so it keeps them.
    $otherRefundId = $refunds->refund(
        $casey,
        'cashier',
        $saleId,
        $returnOf($waterItemId, 1, CashRefundService::DAMAGED),
        'other',
        'Customer says the bottle arrived already cracked'
    );
    $other = $refundRow($pdo, $otherRefundId);
    $assert((string)$other['reason'] === 'other', 'a refund may be issued as Other');
    $assert(
        (string)$other['note'] === 'Customer says the bottle arrived already cracked',
        'an Other refund keeps the note that explains it'
    );
    $assert(abs((float)$refunds->refundableAmount($saleId) - 40.00) < 0.001, 'two partial refunds share one remaining balance');
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $saleId, $returnOf($waterItemId, 1), 'customer_return'),
        'refunding a line that is already fully refunded is refused'
    );

    // --- AC 4: only Restockable quantities return to available inventory ----

    $stockBefore = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn();
    $soldBefore = (int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 2')->fetchColumn();
    $returnsBefore = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'return'")->fetchColumn();

    $damagedRefundId = $refunds->refund(
        $casey,
        'cashier',
        $damagedSaleId,
        $returnOf($damagedTeaItemId, 1, CashRefundService::DAMAGED),
        'damaged_item'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn() === $stockBefore,
        'a Damaged returned quantity does not return to available inventory'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 2')->fetchColumn() === $soldBefore,
        'a Damaged returned quantity does not rewind the sold count'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'return'")->fetchColumn() === $returnsBefore,
        'a Damaged returned quantity writes no return stock movement'
    );
    $damagedLines = $linesOf($pdo, $damagedRefundId);
    $assert(
        count($damagedLines) === 1 && (string)$damagedLines[0]['disposition'] === CashRefundService::DAMAGED,
        'the Damaged classification is kept on the refund line'
    );
    $assert(
        abs((float)$refunds->refundableAmount($damagedSaleId)) < 0.001,
        'a Damaged refund still consumes the refundable balance, because the money left the drawer'
    );

    $restockRefundId = $refunds->refund(
        $casey,
        'cashier',
        $cardSaleId,
        $returnOf($cardTeaItemId, 1),
        'wrong_item', null, 'CARD-REFUND-123', true
    );
    $assert($restockRefundId > 0, 'a Restockable refund of a Card sale is written');
    $assert(
        (string)$refundRow($pdo, $restockRefundId)['payment_method'] === 'card',
        'a refund of a Card sale is settled on the original payment method'
    );
    $assert(
        abs((float)$refunds->refundableAmount($cardSaleId)) < 0.001,
        'a Restockable refund still consumes the sale\'s refundable balance'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn() === $stockBefore + 1,
        'a Restockable returned quantity returns to available inventory'
    );
    $assert(
        (int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 2')->fetchColumn() === $soldBefore - 1,
        'a Restockable returned quantity rewinds the sold count'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'return' AND change_qty = 1 AND moved_by = {$casey}")->fetchColumn() === 1,
        'a Restockable return writes a stock movement attributed to the Cashier'
    );

    // E-wallet returns use the same ledger and rules. An unknown repayment
    // method is refused before the refund, inventory, or audit is written.
    $walletSale = $sales->checkout([['product_id' => 2, 'qty' => 1]], $casey, 'cashier', 'ewallet',
        ['payment_reference' => 'WALLET-123']);
    $walletSaleId = (int)$walletSale['sale_id'];
    $walletItemId = $lineIdOf($walletSaleId, 2);
    $pdo->exec("UPDATE sales SET payment_method = 'unsupported' WHERE sale_id = {$walletSaleId}");
    $walletRefundsBefore = $refundCount($pdo);
    $walletStockBefore = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn();
    $walletAuditBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn();
    $expectRefusal(static fn() => $refunds->refund($casey, 'cashier', $walletSaleId,
        $returnOf($walletItemId, 1), 'customer_return'), 'unsupported payment method is refused');
    $assert($refundCount($pdo) === $walletRefundsBefore, 'unsupported payment method writes no refund');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn() === $walletStockBefore,
        'unsupported repayment does not mutate inventory');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn() === $walletAuditBefore,
        'unsupported repayment writes no audit record');
    $pdo->exec("UPDATE sales SET payment_method = 'ewallet' WHERE sale_id = {$walletSaleId}");
    $walletDrawerBefore = (float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'];
    $walletRefundId = $refunds->refund($casey, 'cashier', $walletSaleId,
        $returnOf($walletItemId, 1, CashRefundService::DAMAGED), 'other', 'Wallet return', 'WALLET-REFUND-123', true);
    $assert((string)$refundRow($pdo, $walletRefundId)['payment_method'] === 'ewallet',
        'an e-wallet refund keeps the original payment method');
    $assert((string)$refundRow($pdo, $walletRefundId)['note'] === 'Wallet return',
        'an e-wallet refund keeps its required Other note');
    $assert($refunds->refundableAmount($walletSaleId) === 0.0,
        'an e-wallet refund consumes the same remaining balance');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 2')->fetchColumn() === $walletStockBefore,
        'a Damaged e-wallet return does not restock inventory');
    $assert((float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'] === $walletDrawerBefore,
        'an e-wallet refund does not reduce expected drawer cash');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds' AND record_id = {$walletRefundId}")->fetchColumn() === 1,
        'an e-wallet refund creates a Protected Audit Record');

    // A Restockable refund of a unit that was sold out of a batch puts the unit
    // back into that batch, not merely into the inventory total.
    $batchBefore = (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1')->fetchColumn();
    $refunds->refund($casey, 'cashier', $batchSaleId, $returnOf($batchItemId, 1), 'customer_return');
    $assert(
        (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 1')->fetchColumn() === $batchBefore + 1,
        'a Restockable returned quantity returns to the batch the unit was sold from'
    );

    // --- AC 5: the original sale stays immutable, the drawer does not -------

    $saleBeforeRefund = $saleRow($pdo, $damagedSaleId);
    $itemsBeforeRefund = (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE sale_id = {$damagedSaleId}")->fetchColumn();
    $salesBeforeRefund = (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn();
    $expectedBeforeRefund = (float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'];
    // 200.00 opening float, 300.00 of cash sales, 211.25 of cash refunds issued
    // so far on this shift. The Card refund is deliberately not in it: only cash
    // handed back out of the drawer moves the drawer.
    $assert(
        abs($expectedBeforeRefund - 288.75) < 0.001,
        'expected drawer cash is the opening float plus cash sales minus the cash refunds already issued'
    );

    $drawerRefundId = $refunds->refund(
        $casey,
        'cashier',
        $saleId,
        $returnOf($teaItemId, 1),
        'duplicate_sale'
    );
    $drawerRefund = $refundRow($pdo, $drawerRefundId);
    $assert(abs((float)$drawerRefund['refund_amount'] - 40.00) < 0.001, 'the drawer refund is the value of the returned unit');
    $assert(abs((float)$refunds->refundableAmount($saleId)) < 0.001, 'the sale has nothing left to refund');

    $expectedAfterRefund = (float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'];
    $assert(
        abs($expectedAfterRefund - ($expectedBeforeRefund - 40.00)) < 0.001,
        'a cash refund reduces the expected drawer cash of the Cashier Shift that issued it'
    );
    $assert(
        abs((float)$shifts->calculateShift($caseyShiftId)['cash_refunds'] - 251.25) < 0.001,
        'the shift summary names every cash refund it issued, and only the cash ones'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds WHERE shift_id = {$danaShiftId}")->fetchColumn() === 0,
        'no refund lands on the other Cashier\'s drawer'
    );

    $saleAfterRefund = $saleRow($pdo, $damagedSaleId);
    $assert(
        $saleAfterRefund == $saleBeforeRefund,
        'refunding leaves every column of the original completed sale exactly as it was'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM sale_items WHERE sale_id = {$damagedSaleId}")->fetchColumn() === $itemsBeforeRefund,
        'refunding never removes a line from the original sale'
    );
    $assert(
        abs((float)$saleAfterRefund['total_amount'] - 40.00) < 0.001,
        'the original sale total is not rewritten by its refunds'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM sales')->fetchColumn() === $salesBeforeRefund,
        'refunding never writes or removes a sale'
    );

    // --- AC 6: one atomic, audited append ------------------------------------

    $audit = $pdo->query("SELECT * FROM activity_log WHERE module = 'Cash Refunds' ORDER BY log_id DESC LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    $assert($audit !== false, 'a Cash Refund creates a Protected Audit Record');
    $assert((int)$audit['user_id'] === $casey, 'the Protected Audit Record names the acting Cashier');
    $assert((int)$audit['record_id'] === $drawerRefundId, 'the Protected Audit Record names the affected Cash Refund');
    $assert((string)$audit['category'] === 'store_operation', 'a Cash Refund is a Store operation Protected Audit Record');
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds' AND record_id = {$drawerRefundId}")->fetchColumn() === 1,
        'each Cash Refund is recorded exactly once'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn() === $refundCount($pdo),
        'no Cash Refund is recorded without a Protected Audit Record, and none is recorded twice'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Sales'")->fetchColumn() === 0,
        'a Cash Refund is not mirrored into the sales audit module; the sales ledger stays authoritative'
    );
    $payload = json_decode((string)$audit['new_value'], true);
    $assert(
        is_array($payload)
            && (int)($payload['sale_id'] ?? 0) === $saleId
            && (int)($payload['cashier_id'] ?? 0) === $casey
            && (int)($payload['shift_id'] ?? 0) === $caseyShiftId
            && ($payload['reason'] ?? null) === 'duplicate_sale'
            && ($payload['reason_label'] ?? null) === CashRefundService::REFUND_REASONS['duplicate_sale']
            && (float)($payload['refund_amount'] ?? 0) === 40.00
            && ($payload['payment_method'] ?? null) === 'cash',
        'the Protected Audit Record carries the sale, the Cashier, the Cashier Shift, the reason and its label, the amount, and the payment method'
    );
    $assert(
        is_array($payload['lines'] ?? null) && count($payload['lines']) === 1
            && (int)$payload['lines'][0]['quantity'] === 1
            && ($payload['lines'][0]['disposition'] ?? null) === CashRefundService::RESTOCKABLE,
        'the Protected Audit Record carries the item lines and their classification'
    );

    // A refund and its inventory effects are one transaction. The inventory row
    // for the returned product is removed so the restock write matches nothing
    // and has to fail, which is exactly the half-written state the atomicity
    // rule exists to prevent.
    $atomicSale = $sales->checkout([['product_id' => 2, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $atomicSaleId = (int)$atomicSale['sale_id'];
    $atomicItemId = $lineIdOf($atomicSaleId, 2);
    $pdo->exec('DELETE FROM inventory WHERE product_id = 2');
    $refundsBefore = $refundCount($pdo);
    $linesBefore = (int)$pdo->query('SELECT COUNT(*) FROM cash_refund_items')->fetchColumn();
    $auditBefore = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn();
    $expectedBeforeFailure = (float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'];
    $expectRefusal(
        static fn() => $refunds->refund($casey, 'cashier', $atomicSaleId, $returnOf($atomicItemId, 1), 'customer_return'),
        'a refund whose inventory effect cannot be written is refused'
    );
    $assert($refundCount($pdo) === $refundsBefore, 'a failed refund leaves no half-written Cash Refund');
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cash_refund_items')->fetchColumn() === $linesBefore,
        'a failed refund leaves no half-written refund lines'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn() === $auditBefore,
        'a failed refund writes no Protected Audit Record'
    );
    $assert(
        abs((float)$refunds->refundableAmount($atomicSaleId) - 40.00) < 0.001,
        'a failed refund leaves the remaining refundable balance untouched'
    );
    $assert(
        abs((float)$shifts->calculateShift($caseyShiftId)['calculated_expected_cash'] - $expectedBeforeFailure) < 0.001,
        'a failed refund leaves the expected drawer cash untouched'
    );

    // The refund ledger reads back per Cashier, which is the whole of the
    // visibility rule for the operational history this ticket adds.
    $caseyRefundIds = array_map(
        static fn(array $row): int => (int)$row['refund_id'],
        $refunds->recentForCashier($casey)
    );
    $assert($caseyRefundIds[0] === $drawerRefundId, "a Cashier's refund ledger lists their newest refund first");
    $assert(
        count($caseyRefundIds) === $refundCount($pdo),
        "every refund in this Store was issued by the Cashier under test and is listed as theirs"
    );
    $assert(
        $refunds->recentForCashier($dana) === [] && $refunds->recentForCashier($noShift) === [],
        "a Cashier never lists another Cashier's refunds"
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cash_refunds WHERE shift_id NOT IN (SELECT shift_id FROM cashier_shifts)')->fetchColumn() === 0,
        'every refund names a Cashier Shift that exists'
    );
    $assert(
        (int)$pdo->query('SELECT COUNT(*) FROM cash_refunds WHERE sale_id NOT IN (SELECT sale_id FROM sales)')->fetchColumn() === 0,
        'every refund names a sale that exists'
    );

    // Returning the last discounted unit must settle the full amount paid.
    $lastDiscounted = $refunds->refund($casey, 'cashier', $discountedSaleId,
        $returnOf($discountedItemId, 1), 'customer_return');
    $assert((float)$refundRow($pdo, $lastDiscounted)['refund_amount'] === 23.75,
        'the last discounted unit refunds its share of the amount paid');
    $assert($refunds->refundableAmount($discountedSaleId) === 0.0,
        'partial discounted refunds can exhaust the amount paid');
    $assert($refunds->refundableQuantity($discountedItemId) === 0,
        'partial discounted refunds can return every sold unit');

    // A full return of multiple units pays back the net sale, including
    // the last cent. The same sale split into single-unit returns pays equally.
    foreach ([false, true] as $splitReturn) {
        $roundedSale = $sales->checkout([['product_id' => 1, 'qty' => 3]], $casey, 'cashier', 'cash',
            ['cash_received' => 100, 'promotion_id' => 102]);
        $roundedSaleId = (int)$roundedSale['sale_id'];
        $roundedItemId = $lineIdOf($roundedSaleId, 1);
        $amounts = [];
        foreach ($splitReturn ? [1, 1, 1] : [3] as $quantity) {
            $id = $refunds->refund($casey, 'cashier', $roundedSaleId, $returnOf($roundedItemId, $quantity), 'customer_return');
            $amounts[] = (float)$refundRow($pdo, $id)['refund_amount'];
        }
        $assert($amounts === ($splitReturn ? [25.0, 24.99, 25.0] : [74.99]),
            'full and split discounted returns preserve the final cent');
        $assert($refunds->refundableAmount($roundedSaleId) === 0.0,
            'rounding leaves no money stranded after every unit is returned');
    }

    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 30)');
    foreach ([false, true] as $splitLines) {
        $mixed = $sales->checkout([['product_id' => 1, 'qty' => 1], ['product_id' => 2, 'qty' => 1]],
            $casey, 'cashier', 'cash', ['cash_received' => 100, 'promotion_id' => 102]);
        $mixedId = (int)$mixed['sale_id'];
        $beforeReturn = $saleRow($pdo, $mixedId);
        $waterReturn = $returnOf($lineIdOf($mixedId, 1), 1);
        $teaReturn = $returnOf($lineIdOf($mixedId, 2), 1);
        $paidBack = [];
        foreach ($splitLines ? [$teaReturn, $waterReturn] : [$waterReturn + $teaReturn] as $return) {
            $id = $refunds->refund($casey, 'cashier', $mixedId, $return, 'customer_return');
            $paidBack[] = (float)$refundRow($pdo, $id)['refund_amount'];
        }
        $assert($paidBack === ($splitLines ? [39.99, 25.0] : [64.99]),
            'discount cents are allocated across all lines regardless of return order');
        $assert($saleRow($pdo, $mixedId) === $beforeReturn, 'a full discounted return never rewrites the sale');
    }

    // Existing refunds retain the actual amount they paid before net pricing.
    $historical = $sales->checkout([['product_id' => 1, 'qty' => 4]], $casey, 'cashier', 'cash',
        ['cash_received' => 100, 'promotion_id' => 101]);
    $historicalId = (int)$historical['sale_id'];
    $historicalItem = $lineIdOf($historicalId, 1);
    $pdo->prepare("INSERT INTO cash_refunds (sale_id, shift_id, cashier_id, refund_amount, payment_method, reason)
        VALUES (?, ?, ?, 75, 'cash', 'customer_return')")->execute([$historicalId, $caseyShiftId, $casey]);
    $historicalRefund = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cash_refund_items (refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
        VALUES (?, ?, 1, 3, 25, 75, 'damaged')")->execute([$historicalRefund, $historicalItem]);
    $lastHistorical = $refunds->refund($casey, 'cashier', $historicalId,
        $returnOf($historicalItem, 1), 'customer_return');
    $assert((float)$refundRow($pdo, $lastHistorical)['refund_amount'] === 20.0,
        'an existing shelf-price partial refund leaves only the actual unpaid balance');
    $assert((float)$refundRow($pdo, $historicalRefund)['refund_amount'] === 75.0,
        'existing refund history remains immutable');

    $historicalMixed = $sales->checkout([['product_id' => 1, 'qty' => 1], ['product_id' => 2, 'qty' => 1]],
        $casey, 'cashier', 'cash', ['cash_received' => 100, 'promotion_id' => 101]);
    $historicalMixedId = (int)$historicalMixed['sale_id'];
    $pdo->prepare("INSERT INTO cash_refunds (sale_id, shift_id, cashier_id, refund_amount, payment_method, reason)
        VALUES (?, ?, ?, 25, 'cash', 'customer_return')")->execute([$historicalMixedId, $caseyShiftId, $casey]);
    $oldMixedRefund = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cash_refund_items (refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, disposition)
        VALUES (?, ?, 1, 1, 25, 25, 'damaged')")->execute([$oldMixedRefund, $lineIdOf($historicalMixedId, 1)]);
    $mixedRemainder = $refunds->refund($casey, 'cashier', $historicalMixedId,
        $returnOf($lineIdOf($historicalMixedId, 2), 1), 'customer_return');
    $assert((float)$refundRow($pdo, $mixedRemainder)['refund_amount'] === 35.0,
        'historical shelf-price returns on another line cannot overpay the sale');
    $assert((float)$linesOf($pdo, $mixedRemainder)[0]['subtotal'] === 35.0,
        'the capped payout is reflected in the recorded line value');

    foreach (['pending', 'approved'] as $legacyStatus) {
        $legacySale = $sales->checkout([['product_id' => 1, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 100]);
        $legacyId = (int)$legacySale['sale_id'];
        $legacyItem = $lineIdOf($legacyId, 1);
        $pdo->prepare('INSERT INTO sale_reversals (sale_id, status, refund_amount) VALUES (?, ?, 25)')
            ->execute([$legacyId, $legacyStatus]);
        $expectRefusal(static fn() => $refunds->refund($casey, 'cashier', $legacyId,
            $returnOf($legacyItem, 1), 'customer_return'),
            'a sale with a pending or approved legacy reversal cannot be refunded twice');
    }

    // A steep discount can allocate zero cents to a returned unit. It still
    // consumes quantity and can be restocked after all cash has been repaid.
    $pdo->exec("INSERT INTO promotions (promotion_name, scope, discount_type, discount_value, starts_at, ends_at) VALUES ('Tiny remainder', 'all', 'fixed', 74.99, '2000-01-01', '2099-01-01')");
    $tinyPromotionId = (int)$pdo->lastInsertId();
    $tiny = $sales->checkout([['product_id' => 1, 'qty' => 3]], $casey, 'cashier', 'cash',
        ['cash_received' => 100, 'promotion_id' => $tinyPromotionId]);
    $pdo->exec("UPDATE promotions SET status = 'inactive' WHERE promotion_id = {$tinyPromotionId}");
    $tinyId = (int)$tiny['sale_id'];
    $tinyItem = $lineIdOf($tinyId, 1);
    foreach ([0.0, 0.01, 0.0] as $expectedAmount) {
        $tinyRefund = $refunds->refund($casey, 'cashier', $tinyId, $returnOf($tinyItem, 1), 'customer_return');
        $assert((float)$refundRow($pdo, $tinyRefund)['refund_amount'] === $expectedAmount,
            'every discounted unit can be returned even when its allocated value rounds to zero');
    }
    $assert($refunds->refundableQuantity($tinyItem) === 0, 'zero-cent returns still consume quantity');

    // A failure to write the Protected Audit Record rolls back stock and money.
    $auditSale = $sales->checkout([['product_id' => 1, 'qty' => 1]], $casey, 'cashier', 'cash', ['cash_received' => 100]);
    $auditSaleId = (int)$auditSale['sale_id'];
    $auditItemId = $lineIdOf($auditSaleId, 1);
    $stockBeforeAuditFailure = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn();
    $refundsBeforeAuditFailure = $refundCount($pdo);
    $pdo->exec("CREATE TRIGGER fail_refund_audit BEFORE INSERT ON activity_log
        WHEN NEW.module = 'Cash Refunds' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    $expectRefusal(static fn() => $refunds->refund($casey, 'cashier', $auditSaleId,
        $returnOf($auditItemId, 1), 'customer_return'), 'an unauditable refund must fail');
    $assert($refundCount($pdo) === $refundsBeforeAuditFailure, 'audit failure rolls back the refund');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === $stockBeforeAuditFailure,
        'audit failure rolls back returned inventory');
    $assert($refunds->refundableAmount($auditSaleId) === 25.0, 'audit failure preserves the refundable balance');
    $pdo->exec('DROP TRIGGER fail_refund_audit');

    // Receipt failure must roll back the payout, inventory, and audit together.
    $receiptCount = (int)$pdo->query('SELECT COUNT(*) FROM refund_receipt_details')->fetchColumn();
    $auditCount = (int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn();
    $pdo->exec("CREATE TRIGGER fail_refund_receipt BEFORE INSERT ON refund_receipt_details
        BEGIN SELECT RAISE(ABORT, 'receipt unavailable'); END");
    $expectRefusal(static fn() => $refunds->refund($casey, 'cashier', $auditSaleId,
        $returnOf($auditItemId, 1), 'customer_return'), 'a refund without a preserved receipt must fail');
    $assert($refundCount($pdo) === $refundsBeforeAuditFailure, 'receipt failure rolls back the refund');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 1')->fetchColumn() === $stockBeforeAuditFailure,
        'receipt failure rolls back returned inventory');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM refund_receipt_details')->fetchColumn() === $receiptCount,
        'receipt failure leaves no partial receipt');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM activity_log WHERE module = 'Cash Refunds'")->fetchColumn() === $auditCount,
        'receipt failure leaves no audit for a failed refund');
    $pdo->exec('DROP TRIGGER fail_refund_receipt');

    $receiptService = new \App\Receipts\RefundReceiptService($pdo);
    // A multi-item partial refund remains available without issuing another payout.
    $reprintSale = $sales->checkout([['product_id' => 1, 'qty' => 2], ['product_id' => 2, 'qty' => 2]],
        $casey, 'cashier', 'cash', ['cash_received' => 200]);
    $reprintSaleId = (int)$reprintSale['sale_id'];
    $reprintId = $refunds->refund($casey, 'cashier', $reprintSaleId,
        $returnOf($lineIdOf($reprintSaleId, 1), 1) + $returnOf($lineIdOf($reprintSaleId, 2), 1), 'customer_return');
    $reprintSaved = $receiptService->forCashier($reprintId, $casey, 1);
    $assert(count($reprintSaved['items']) === 2 && $refunds->refundableAmount($reprintSaleId) > 0,
        'reprint fixture is a saved multi-item partial refund');
    $saleReceiptBeforeReprint = $pdo->query('SELECT * FROM sale_receipt_details')->fetchAll(PDO::FETCH_ASSOC);
    $refundsBeforeReprint = $refundCount($pdo);
    $saved = $receiptService->forCashier($otherRefundId, $casey, 1);
    $assert($saved !== null && $saved['refund']['reason'] === 'Other', 'successful refund exposes a customer receipt');
    $assert($receiptService->forCashier($otherRefundId, $dana, 1) === null, 'another Cashier cannot fetch a receipt');
    $assert($receiptService->forCashier($otherRefundId, $casey, 2) === null, 'another Store cannot fetch a receipt');
    $assert($receiptService->forCashier(999999, $casey, 1) === null, 'missing refund does not expose receipt data');
    $customerJson = json_encode($saved);
    foreach (['note', 'disposition', 'restockable', 'damaged', 'Customer says the bottle', 'shift_id', 'cashier_id', 'register_id'] as $private) {
        $assert(!str_contains($customerJson, $private), 'customer snapshot excludes ' . $private);
    }
    $pdo->exec("CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)");
    $pdo->exec("INSERT INTO store_settings VALUES ('store_name', 'Edited Store'), ('receipt_footer', 'Edited footer')");
    $pdo->exec("UPDATE products SET product_name = 'Edited product', sku = 'EDITED-SKU'");
    $pdo->exec("UPDATE registers SET name = 'Edited Register ' || register_id");
    $pdo->exec("UPDATE users SET full_name = 'Edited Cashier'");
    $assert($receiptService->forCashier($otherRefundId, $casey, 1) === $saved,
        'later Store, footer, product, Register, and Cashier edits cannot rewrite the receipt');
    foreach ([58, 80, 123] as $width) {
        ob_start();
        \App\Receipts\RefundReceiptPresentation::render($saved, $width);
        $customerHtml = ob_get_clean();
        foreach (['Refund #' . $otherRefundId, 'Original Sale Receipt #' . $saleId, 'Date/Time:', 'Cashier:', 'Register:', 'Other', 'Total refunded', 'Original payment method',
            $saved['store']['footer'], $saved['items'][0]['product_name']] as $required) {
            $assert(str_contains($customerHtml, htmlspecialchars($required, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'customer copy includes ' . $required);
        }
        $assert(str_contains($customerHtml, 'data-paper-width-mm="' . ($width === 58 ? 58 : 80) . '"'), 'thermal width has an 80 mm fallback');
        foreach (['Customer says the bottle', 'Restockable', 'Damaged', 'Edited Store', 'Edited product', 'Edited Register', 'Edited Cashier'] as $private) {
            $assert(!str_contains($customerHtml, $private), 'customer copy excludes later edit or private detail ' . $private);
        }
    }
    $assert($refundCount($pdo) === $refundsBeforeReprint, 'retrieval and re-rendering never create a second refund');

    // The page is the only surface a browser reaches, and it holds no rules of
    // its own: the caps and the classification live in the service, where a
    // hand-crafted request cannot skip them.
    $root = dirname(__DIR__, 3);
    $pageSource = (string)@file_get_contents($root . '/src/frontend/components/cashier/refunds.php');
    $assert($pageSource !== '', 'the Cash Refund page source must be readable');
    // Execute the production GET controller against the same fixture, including
    // a handcrafted other-Cashier request; only bootstrap and shell are excluded.
    $getStart = strpos($pageSource, '// Scoped to this Cashier');
    $getEnd = strpos($pageSource, '?>', $getStart);
    $getController = substr($pageSource, $getStart, $getEnd - $getStart);
    $pdo->exec('ALTER TABLE registers ADD COLUMN paper_width_mm INTEGER NOT NULL DEFAULT 80');
    $refundService = $refunds;
    $actorRole = 'cashier';
    $storeId = 1;
    $saleId = 0; $exceptionToken = '';
    $_GET = ['refund_id' => $reprintId];
    foreach ([$dana, $casey] as $actorId) {
        http_response_code(200);
        eval($getController);
        $assert($actorId === $casey ? $receipt === $reprintSaved && http_response_code() === 200
            : $receipt === null && http_response_code() === 404,
            'direct GET retrieves only the owning Cashier receipt');
    }
    $storeId = 2;
    http_response_code(200);
    eval($getController);
    $assert($receipt === null && http_response_code() === 404, 'direct GET refuses another Store');
    $storeId = 1;
    $actorId = $casey;
    preg_match('/<section\b[^>]*\bid="refund-history"[^>]*>/', $pageSource, $historyMatch, PREG_OFFSET_CAPTURE);
    $historyStart = $historyMatch[0][1] ?? throw new RuntimeException('Refund history section is missing.');
    $historyEnd = strpos($pageSource, '</section>', $historyStart) + strlen('</section>');
    $historyTemplate = substr($pageSource, $historyStart, $historyEnd - $historyStart);
    if (!function_exists('app_url')) {
        function app_url(string $path): string { return '/' . $path; }
    }
    if (!function_exists('format_display_datetime')) {
        function format_display_datetime(string $value): string { return $value; }
    }
    $browserFixtures = [];
    foreach ([80, 58] as $width) {
        $pdo->exec('UPDATE registers SET paper_width_mm = ' . $width);
        http_response_code(200);
        eval($getController);
        $assert($paperWidthMm === $width && $receipt === $reprintSaved,
            'reopened receipt retains all recorded content with the current Register width');
        ob_start();
        eval('?>' . str_replace(['CashRefundService::', '__DIR__ . \'/../../../backend/'], ['\\App\\Services\\CashRefundService::', '__DIR__ . \'/../'], $historyTemplate));
        $historyHtml = ob_get_clean();
        foreach ($refunds as $entry) {
            $assert(str_contains($historyHtml, 'href="/components/cashier/refunds.php?refund_id=' . (int)$entry['refund_id'] . '"'),
                'each My refunds row opens its corresponding receipt');
        }
        ob_start();
        \App\Receipts\RefundReceiptPresentation::render($receipt, $paperWidthMm);
        $browserFixtures[$width] = ['history' => $historyHtml, 'receipt' => ob_get_clean(), 'id' => $reprintId];
    }
    $assert($refundCount($pdo) === $refundsBeforeReprint, 'direct GET and reprint leave refund count unchanged');
    $assert($pdo->query('SELECT * FROM sale_receipt_details')->fetchAll(PDO::FETCH_ASSOC) === $saleReceiptBeforeReprint,
        'reopening leaves every original Sale Receipt unchanged');
    if ($fixturePath = getenv('REFUND_REPRINT_FIXTURE')) {
        file_put_contents($fixturePath, json_encode($browserFixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
    $assert(
        str_contains($pageSource, 'new CashRefundService($pdo'),
        'the Cash Refund page delegates to CashRefundService rather than holding the rules in the page'
    );
    $assert(
        !str_contains($pageSource, 'INSERT INTO cash_refunds')
            && !str_contains($pageSource, 'INSERT INTO activity_log')
            && !str_contains($pageSource, 'UPDATE inventory'),
        'the Cash Refund page writes neither the refund, its Protected Audit Record, nor its inventory effects itself'
    );
    $assert(
        !str_contains($pageSource, 'UPDATE sales'),
        'the Cash Refund page never writes to the original completed sale'
    );
    $assert(
        str_contains($pageSource, '->refund('),
        'the Cash Refund page issues the refund through the service'
    );
    // --- Sales Pack refunds (#252): distinct forms, historical pricing/pack_size, sealed vs damaged restock ---
    $pdo->prepare(
        "INSERT INTO products (product_id, sku, barcode, product_name, unit_price, pack_size, pack_barcode, pack_price, status, quantity_purchased, quantity_sold, branch_id)
         VALUES (3, 'SKU-PACK-1', '11111111', 'Canned Soda', 15.00, 6, '22222222', 80.00, 'active', 36, 0, 1)"
    )->execute();
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 36)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, expiration_date, remaining_quantity, sealed_quantity, loose_quantity)
        VALUES (301, 3, 'B-PACK-1', '2027-01-01', 12, 2, 0),
               (302, 3, 'B-PACK-2', '2027-06-01', 24, 4, 0)");

    // Mixed-form sale: 3 packs (2 from B-PACK-1, 1 from B-PACK-2) + 2 pieces (opens 1 pack in B-PACK-2 -> 2 sealed, 4 loose).
    $mixedSale = $sales->checkout(
        [
            ['product_id' => 3, 'qty' => 3, 'selling_form' => 'pack'],
            ['product_id' => 3, 'qty' => 2, 'selling_form' => 'piece'],
        ],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 500]
    );
    $mixedSaleId = (int)$mixedSale['sale_id'];
    $packLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$mixedSaleId} AND product_id = 3 AND selling_form = 'pack'")->fetchColumn();
    $pieceLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$mixedSaleId} AND product_id = 3 AND selling_form = 'piece'")->fetchColumn();
    $assert($packLineId > 0 && $pieceLineId > 0 && $packLineId !== $pieceLineId, 'mixed-form checkout records distinct pack and piece sale lines');
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 16, 'mixed-form checkout deducts 20 Base Units');
    $assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 3')->fetchColumn() === 20, 'mixed-form checkout increments quantity_sold by 20 Base Units');

    // Mutate catalog price after the sale: historical returns must not reprice.
    // The pack-size change case is covered separately below, where it must be refused.
    $pdo->exec("UPDATE products SET unit_price = 25.00, pack_price = 140.00 WHERE product_id = 3");

    $loadedMixed = $refundService->refundableSaleForCashier($casey, $mixedSaleId);
    $assert($loadedMixed !== null && count($loadedMixed['items']) === 2, 'refundableSaleForCashier exposes both mixed-form lines distinctly');
    $loadedByLine = array_column($loadedMixed['items'], null, 'sale_item_id');
    $assert(
        $loadedByLine[$packLineId]['selling_form'] === 'pack'
            && (int)$loadedByLine[$packLineId]['pack_size'] === 6
            && (int)$loadedByLine[$packLineId]['quantity'] === 3
            && (int)$loadedByLine[$packLineId]['refundable_quantity'] === 3
            && (float)$loadedByLine[$packLineId]['unit_price'] === 80.0,
        'pack line preserves historical selling_form, pack_size (6), quantity (3), and unit_price (80.00)'
    );
    $assert(
        $loadedByLine[$pieceLineId]['selling_form'] === 'piece'
            && (int)$loadedByLine[$pieceLineId]['pack_size'] === 6
            && (int)$loadedByLine[$pieceLineId]['quantity'] === 2
            && (int)$loadedByLine[$pieceLineId]['refundable_quantity'] === 2
            && (float)$loadedByLine[$pieceLineId]['unit_price'] === 15.0,
        'piece line preserves historical selling_form, quantity (2), and unit_price (15.00)'
    );

    // Invalid, fractional, negative, or over-limit pack quantities are refused without writes.
    foreach ([0, -1, 1.5, '0.5', '1.5', 'abc', 4] as $badQty) {
        $expectRefusal(
            static fn() => $refundService->refund($casey, 'cashier', $mixedSaleId, [$packLineId => ['quantity' => $badQty, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return'),
            'pack refund refuses non-whole or over-limit quantity ' . json_encode($badQty)
        );
    }
    $expectRefusal(
        static fn() => $refundService->refund($casey, 'cashier', $mixedSaleId, [$packLineId => ['quantity' => 1, 'disposition' => 'opened_pack']], 'customer_return'),
        'pack refund refuses unsupported disposition'
    );

    // 1) Restockable refund of 1 sealed pack restores 1 sealed pack (6 Base Units) to B-PACK-1 and refunds historical 80.00.
    $packRefund1 = $refundService->refund(
        $casey,
        'cashier',
        $mixedSaleId,
        [$packLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]],
        'customer_return'
    );
    $assert((float)$refundRow($pdo, $packRefund1)['refund_amount'] === 80.00, 'sealed pack refund uses historical pack price (80.00), ignoring catalog edit');
    $packRefund1Lines = $linesOf($pdo, $packRefund1);
    $assert(
        count($packRefund1Lines) === 1
            && $packRefund1Lines[0]['selling_form'] === 'pack'
            && (int)$packRefund1Lines[0]['pack_size'] === 6
            && (int)$packRefund1Lines[0]['quantity'] === 1
            && (float)$packRefund1Lines[0]['subtotal'] === 80.00,
        'cash_refund_items records historical selling_form and pack_size'
    );
    $b301 = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 301')->fetch(PDO::FETCH_ASSOC);
    $assert(
        (int)$b301['sealed_quantity'] === 1 && (int)$b301['remaining_quantity'] === 6 && (int)$b301['loose_quantity'] === 0,
        'restockable pack return restores 1 sealed pack and 6 Base Units to the first source batch'
    );
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 22, 'restockable pack return adds 6 Base Units to inventory');
    $assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 3')->fetchColumn() === 14, 'restockable pack return subtracts 6 Base Units from quantity_sold');
    $lastMove = $pdo->query('SELECT product_id, change_qty, reason FROM stock_movements ORDER BY stock_movement_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $assert((int)$lastMove['product_id'] === 3 && (int)$lastMove['change_qty'] === 6 && $lastMove['reason'] === 'return', 'stock_movements records Base Unit return quantity (6)');
    $assert($refundService->refundableQuantity($packLineId) === 2 && $refundService->refundableQuantity($pieceLineId) === 2, 'pack refund reduces only the pack line refundable balance');

    $packAudit = json_decode((string)$pdo->query("SELECT new_value FROM activity_log WHERE module = 'Cash Refunds' AND record_id = {$packRefund1}")->fetchColumn(), true);
    $assert(
        ($packAudit['lines'][0]['selling_form'] ?? null) === 'pack'
            && ($packAudit['lines'][0]['pack_size'] ?? null) === 6
            && ($packAudit['lines'][0]['base_unit_quantity'] ?? null) === 6,
        'Protected Audit Record captures selling_form, pack_size, and base_unit_quantity'
    );
    $packReceipt = $receiptService->forCashier($packRefund1, $casey, 1);
    ob_start();
    \App\Receipts\RefundReceiptPresentation::render($packReceipt, 80);
    $packReceiptHtml = ob_get_clean();
    $assert(!str_contains($packReceiptHtml, 'Selling Form:') && !str_contains($packReceiptHtml, 'Pack (6 pcs)'), 'Refund Receipt omits selling form and pack size');

    // 2) Damaged (or opened/incomplete) pack return refunds 80.00 and consumes refundable pack count without creating saleable stock.
    $packRefund2 = $refundService->refund(
        $casey,
        'cashier',
        $mixedSaleId,
        [$packLineId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]],
        'damaged_item'
    );
    $assert((float)$refundRow($pdo, $packRefund2)['refund_amount'] === 80.00, 'damaged pack return refunds historical pack price');
    $b301AfterDamaged = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 301')->fetch(PDO::FETCH_ASSOC);
    $b302AfterDamaged = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 302')->fetch(PDO::FETCH_ASSOC);
    $assert(
        $b301AfterDamaged === $b301
            && (int)$b302AfterDamaged['sealed_quantity'] === 2
            && (int)$b302AfterDamaged['loose_quantity'] === 4
            && (int)$b302AfterDamaged['remaining_quantity'] === 16
            && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 22
            && (int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 3')->fetchColumn() === 14,
        'damaged/opened pack return never creates sealed or loose saleable stock'
    );
    $assert($refundService->refundableQuantity($packLineId) === 1, 'damaged pack return consumes 1 refundable pack');

    // 3) Rollback on receipt failure leaves batch sealed/loose/remaining quantities and inventory untouched.
    $pdo->exec("CREATE TRIGGER fail_pack_refund_receipt BEFORE INSERT ON refund_receipt_details
        BEGIN SELECT RAISE(ABORT, 'pack receipt unavailable'); END");
    $expectRefusal(
        static fn() => $refundService->refund($casey, 'cashier', $mixedSaleId, [$packLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return'),
        'pack refund rolls back when receipt persistence fails'
    );
    $pdo->exec('DROP TRIGGER fail_pack_refund_receipt');
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 22
            && (int)$pdo->query('SELECT sealed_quantity FROM product_batches WHERE batch_id = 302')->fetchColumn() === 2
            && $refundService->refundableQuantity($packLineId) === 1,
        'failed pack refund leaves inventory, batch sealed_quantity, and refundable balance unchanged'
    );

    // 4) Restockable refund of the final 1 pack + 1 piece restores 1 sealed pack (6 Base Units) and 1 loose piece (1 Base Unit) to B-PACK-2.
    $packRefund3 = $refundService->refund(
        $casey,
        'cashier',
        $mixedSaleId,
        [
            $packLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE],
            $pieceLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE],
        ],
        'customer_return'
    );
    $assert((float)$refundRow($pdo, $packRefund3)['refund_amount'] === 95.00, 'combined pack + piece refund totals historical 80.00 + 15.00');
    $b302Final = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 302')->fetch(PDO::FETCH_ASSOC);
    $assert(
        (int)$b302Final['sealed_quantity'] === 3
            && (int)$b302Final['loose_quantity'] === 5
            && (int)$b302Final['remaining_quantity'] === 23,
        'third pack restores sealed_quantity (+1 pack, +6 Base Units) on B-PACK-2 while piece restores loose_quantity (+1, +1 Base Unit)'
    );
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === 29, 'combined pack + piece return adds 7 Base Units to inventory');
    $assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 3')->fetchColumn() === 7, 'combined pack + piece return subtracts 7 Base Units from quantity_sold');
    $assert($refundService->refundableQuantity($packLineId) === 0 && $refundService->refundableQuantity($pieceLineId) === 1, 'pack line is exhausted while 1 piece remains refundable');
    $expectRefusal(
        static fn() => $refundService->refund($casey, 'cashier', $mixedSaleId, [$packLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return'),
        'exhausted pack line cannot be refunded again'
    );

    // 4b) A legal pack-size change after the sale cannot reinterpret a historical sealed pack.
    // Selling the last pack leaves zero sealed stock, so the 12 -> 24 edit is allowed by the catalog guard.
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, pack_size, pack_barcode, pack_price, status, quantity_purchased, quantity_sold, branch_id)
        VALUES (6, 'SKU-RESIZE', '66000000', 'Resized Biscuit', 10.00, 12, '66000001', 100.00, 'active', 12, 0, 1)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (6, 12)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, expiration_date, remaining_quantity, sealed_quantity, loose_quantity)
        VALUES (601, 6, 'B-RESIZE', '2027-03-01', 12, 1, 0)");
    $resizeSale = $sales->checkout(
        [['product_id' => 6, 'qty' => 1, 'selling_form' => 'pack']],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 100]
    );
    $resizeSaleId = (int)$resizeSale['sale_id'];
    $resizeLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$resizeSaleId}")->fetchColumn();
    $pdo->exec("UPDATE products SET pack_size = 24 WHERE product_id = 6");
    $expectRefusal(
        static fn() => $refundService->refund($casey, 'cashier', $resizeSaleId, [$resizeLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return'),
        'restockable pack refund is refused when the current pack size differs from the sold pack size'
    );
    $assert(
        (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds WHERE sale_id = {$resizeSaleId}")->fetchColumn() === 0
            && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 6')->fetchColumn() === 0
            && (int)$pdo->query('SELECT remaining_quantity FROM product_batches WHERE batch_id = 601')->fetchColumn() === 0
            && (int)$pdo->query('SELECT sealed_quantity FROM product_batches WHERE batch_id = 601')->fetchColumn() === 0
            && $refundService->refundableQuantity($resizeLineId) === 1,
        'refused pack-size-change refund leaves money, inventory, batch forms, and refundable balance untouched'
    );
    $pdo->exec("UPDATE products SET pack_size = 12 WHERE product_id = 6");
    $resizeRefund = $refundService->refund($casey, 'cashier', $resizeSaleId, [$resizeLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return');
    $resizeBatch = $pdo->query('SELECT remaining_quantity, sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 601')->fetch(PDO::FETCH_ASSOC);
    $assert(
        (float)$refundRow($pdo, $resizeRefund)['refund_amount'] === 100.00
            && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 6')->fetchColumn() === 12
            && (int)$resizeBatch['sealed_quantity'] === 1 && (int)$resizeBatch['loose_quantity'] === 0 && (int)$resizeBatch['remaining_quantity'] === 12,
        'unchanged pack configuration still restores exactly one 12-piece sealed pack'
    );

    // 5) Discounted mixed-form sale allocates actual paid cents accurately across pack and piece lines.
    $pdo->exec("UPDATE products SET unit_price = 15.00, pack_price = 80.00, pack_size = 6 WHERE product_id = 3");
    $discMixedSale = $sales->checkout(
        [
            ['product_id' => 3, 'qty' => 2, 'selling_form' => 'pack'],
            ['product_id' => 3, 'qty' => 1, 'selling_form' => 'piece'],
        ],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 200, 'promotion_id' => 103]
    );
    // Gross = 160 + 15 = 175.00, Discount = 15.00, Paid = 160.00.
    $discMixedSaleId = (int)$discMixedSale['sale_id'];
    $discPackLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$discMixedSaleId} AND selling_form = 'pack'")->fetchColumn();
    $discPieceLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$discMixedSaleId} AND selling_form = 'piece'")->fetchColumn();
    $r1 = $refundService->refund($casey, 'cashier', $discMixedSaleId, [$discPackLineId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]], 'customer_return');
    $r2 = $refundService->refund($casey, 'cashier', $discMixedSaleId, [$discPackLineId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]], 'customer_return');
    $r3 = $refundService->refund($casey, 'cashier', $discMixedSaleId, [$discPieceLineId => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED]], 'customer_return');
    $totalDiscRefunded = round(
        (float)$refundRow($pdo, $r1)['refund_amount']
        + (float)$refundRow($pdo, $r2)['refund_amount']
        + (float)$refundRow($pdo, $r3)['refund_amount'],
        2
    );
    $assert($totalDiscRefunded === 160.00 && $refundService->refundableAmount($discMixedSaleId) === 0.0, 'discounted mixed-form partial refunds sum to exact paid amount (160.00)');

    // 6) Incomplete batch allocation on a restockable pack return is refused atomically so sealed_quantity and inventory never drift.
    $incompleteSale = $sales->checkout(
        [['product_id' => 3, 'qty' => 1, 'selling_form' => 'pack']],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 100]
    );
    $incompleteSaleId = (int)$incompleteSale['sale_id'];
    $incompletePackLineId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$incompleteSaleId}")->fetchColumn();
    $pdo->exec("DELETE FROM sale_item_batches WHERE sale_item_id = {$incompletePackLineId}");
    $invBeforeIncomplete = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn();
    $expectRefusal(
        static fn() => $refundService->refund($casey, 'cashier', $incompleteSaleId, [$incompletePackLineId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]], 'customer_return'),
        'restockable pack return with missing batch allocation is refused atomically'
    );
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 3')->fetchColumn() === $invBeforeIncomplete, 'refused incomplete pack batch restock leaves inventory untouched');

    // 7) Prepared Oil Pouches (250 mL and 500 mL whole-pouch SKUs) preserve whole-pouch piece refund and batch restock behavior.
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, status, quantity_purchased, quantity_sold, branch_id)
        VALUES (4, 'OIL-250ML', '8800250', 'Cooking Oil Pouch 250 mL', 22.00, 'active', 20, 0, 1),
               (5, 'OIL-500ML', '8800500', 'Cooking Oil Pouch 500 mL', 42.00, 'active', 20, 0, 1)");
    $pdo->exec('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (4, 20), (5, 20)');
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, remaining_quantity, sealed_quantity, loose_quantity)
        VALUES (401, 4, 'B-OIL-250', 20, 0, 20), (501, 5, 'B-OIL-500', 20, 0, 20)");
    $oilSale = $sales->checkout(
        [['product_id' => 4, 'qty' => 2], ['product_id' => 5, 'qty' => 2]],
        $casey,
        'cashier',
        'cash',
        ['cash_received' => 200]
    );
    $oilSaleId = (int)$oilSale['sale_id'];
    $oil250Line = $lineIdOf($oilSaleId, 4);
    $oil500Line = $lineIdOf($oilSaleId, 5);
    $oilRefundId = $refundService->refund(
        $casey,
        'cashier',
        $oilSaleId,
        [
            $oil250Line => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE],
            $oil500Line => ['quantity' => 1, 'disposition' => CashRefundService::DAMAGED],
        ],
        'customer_return'
    );
    $assert((float)$refundRow($pdo, $oilRefundId)['refund_amount'] === 64.00, 'Prepared Oil 250 mL and 500 mL whole-pouch refunds settle exact pouch prices (22.00 + 42.00)');
    $assert(
        (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 4')->fetchColumn() === 19
            && (int)$pdo->query('SELECT loose_quantity FROM product_batches WHERE batch_id = 401')->fetchColumn() === 19
            && (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id = 5')->fetchColumn() === 18
            && (int)$pdo->query('SELECT loose_quantity FROM product_batches WHERE batch_id = 501')->fetchColumn() === 18,
        'Prepared Oil 250 mL restockable return restores 1 whole pouch while 500 mL damaged return stays off the shelf'
    );
} catch (Throwable $exception) {
    $failures[] = 'Append-only Cash Refund contract threw: ' . $exception->getMessage();
}

if ($failures) {
    fwrite(STDERR, "Append-only Cash Refund contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Append-only Cash Refund contract: passed\n";
