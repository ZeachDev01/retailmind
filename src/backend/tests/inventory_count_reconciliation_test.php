<?php
// Contract and integration test for Ticket #218: Reconcile inventory through physical batch counts.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/InventoryCountService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        fwrite(STDERR, "  fail - {$message}\n");
    } else {
        echo "  ok - {$message}\n";
    }
};

try {
    // 1. Static Contract & UI Checks on inventory_counts.php
    echo "Checking inventory_counts.php static contract for batch reconciliation...\n";
    $countPageSource = (string)file_get_contents(dirname(__DIR__, 2) . '/frontend/components/inventory_management/inventory_counts.php');

    $assert(str_contains($countPageSource, 'id="batch_reconciliation_section"'), 'inventory_counts.php has batch_reconciliation_section');
    $assert(str_contains($countPageSource, 'id="batch_mismatch_alert"'), 'inventory_counts.php has batch_mismatch_alert');
    $assert(str_contains($countPageSource, 'id="unbatched_stock_alert"'), 'inventory_counts.php has unbatched_stock_alert');
    $assert(str_contains($countPageSource, 'id="batch_counts_table"'), 'inventory_counts.php has batch_counts_table');
    $assert(str_contains($countPageSource, 'id="add_extra_batch_btn"'), 'inventory_counts.php has add_extra_batch_btn');
    $assert(str_contains($countPageSource, 'id="batch_detail_modal"'), 'inventory_counts.php has batch_detail_modal');
    $assert(str_contains($countPageSource, 'action=batch_coverage'), 'inventory_counts.php supports batch_coverage action');
    $assert(str_contains($countPageSource, 'action=count_batches'), 'inventory_counts.php supports count_batches action');
    $assert(str_contains($countPageSource, 'Expiration date (optional)') && str_contains($countPageSource, 'Leave unknown expiration dates blank.'), 'inventory_counts.php explains optional, unknown expiration dates');

    // 2. Database Integration Setup with in-memory SQLite
    echo "Setting up SQLite test database...\n";
    class SqliteTestPdo extends PDO {
        #[\ReturnTypeWillChange]
        public function prepare(string $query, array $options = []): PDOStatement|false {
            $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
            $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "DATE(CURDATE(), '+' || ? || ' day')", $query);
            return parent::prepare($query, $options);
        }
        #[\ReturnTypeWillChange]
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            $query = preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
            $query = str_replace('DATE_ADD(CURDATE(), INTERVAL ? DAY)', "DATE(CURDATE(), '+' || ? || ' day')", $query);
            return parent::query($query, ...$fetchModeArgs);
        }
    }

    $pdo = new SqliteTestPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->sqliteCreateFunction('GET_LOCK', static fn(): int => 1, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static fn(): int => 1, 1);
    $pdo->sqliteCreateFunction('SHA2', static fn(string $val, int $len): string => hash('sha256', $val), 2);
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'test', 0);
    $pdo->sqliteCreateFunction('CONCAT', static fn(...$args): string => implode('', $args));
    $pdo->sqliteCreateFunction('LEFT', static fn(string $str, int $len): string => substr($str, 0, $len), 2);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('DATE', static fn(string $d): string => substr($d, 0, 10), 1);
    $pdo->sqliteCreateFunction('DATEDIFF', static fn(string $d1, string $d2): int => (int)((strtotime($d1) - strtotime($d2)) / 86400), 2);

    $pdo->exec("
        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY,
            branch_name TEXT,
            branch_code TEXT,
            status TEXT DEFAULT 'active'
        );
        INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Branch', 'MAIN', 'active');

        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY AUTOINCREMENT,
            role_name TEXT NOT NULL UNIQUE
        );
        INSERT INTO roles (role_name) VALUES ('cashier'), ('admin'), ('super_admin'), ('inventory_manager');

        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            full_name TEXT NOT NULL DEFAULT 'User',
            username TEXT NULL,
            password_hash TEXT NULL,
            role_id INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'active',
            branch_id INTEGER NOT NULL DEFAULT 1
        );
        INSERT INTO users (user_id, full_name, username, role_id, branch_id) VALUES (1, 'Inventory Mgr', 'mgr', 4, 1);

        CREATE TABLE user_roles (
            user_id INTEGER,
            role_id INTEGER
        );
        INSERT INTO user_roles (user_id, role_id) VALUES (1, 4);

        CREATE TABLE categories (
            category_id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_name TEXT NOT NULL
        );
        INSERT INTO categories (category_id, category_name) VALUES (1, 'Bakery');

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sku TEXT,
            barcode TEXT,
            case_barcode TEXT,
            parent_product_id INTEGER DEFAULT NULL,
            variant_label TEXT DEFAULT NULL,
            product_name TEXT NOT NULL,
            brand TEXT DEFAULT NULL,
            category_id INTEGER DEFAULT NULL,
            unit_price REAL NOT NULL DEFAULT 0.0,
            cost_price REAL NOT NULL DEFAULT 0.0,
            quantity_purchased INTEGER NOT NULL DEFAULT 0,
            quantity_sold INTEGER NOT NULL DEFAULT 0,
            reorder_level INTEGER NOT NULL DEFAULT 10,
            supplier TEXT DEFAULT NULL,
            preferred_supplier TEXT DEFAULT NULL,
            supplier_lead_time_days INTEGER NOT NULL DEFAULT 7,
            safety_stock INTEGER NOT NULL DEFAULT 0,
            minimum_order_quantity INTEGER NOT NULL DEFAULT 1,
            units_per_package INTEGER NOT NULL DEFAULT 1,
            base_unit TEXT NOT NULL DEFAULT 'piece',
            receiving_unit TEXT NOT NULL DEFAULT 'package',
            expiration_date TEXT DEFAULT NULL,
            warning_lead_time_days INTEGER DEFAULT NULL,
            product_image TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            branch_id INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NOT NULL DEFAULT 1,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory (
            product_id INTEGER PRIMARY KEY,
            quantity_on_hand INTEGER NOT NULL DEFAULT 0,
            branch_id INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            receiving_id INTEGER,
            batch_number TEXT,
            quantity INTEGER NOT NULL,
            remaining_quantity INTEGER NOT NULL,
            expiration_date TEXT,
            date_received TEXT DEFAULT CURRENT_TIMESTAMP,
            supplier TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory_counts (
            count_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            system_quantity INTEGER NOT NULL,
            physical_quantity INTEGER NOT NULL,
            difference_qty INTEGER NOT NULL,
            discrepancy_reason TEXT NOT NULL,
            counted_by INTEGER NOT NULL,
            approved_by INTEGER DEFAULT NULL,
            counted_at TEXT DEFAULT CURRENT_TIMESTAMP,
            approved_at TEXT DEFAULT NULL,
            status TEXT DEFAULT 'pending',
            related_adjustment_id INTEGER DEFAULT NULL
        );

        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            change_qty INTEGER NOT NULL,
            reason TEXT NOT NULL,
            moved_by INTEGER NOT NULL,
            moved_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory_adjustments (
            adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            adjustment_qty INTEGER NOT NULL,
            adjustment_type TEXT NOT NULL,
            reported_by INTEGER NOT NULL,
            reason TEXT,
            status TEXT DEFAULT 'pending',
            review_notes TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            category TEXT,
            module TEXT,
            record_id INTEGER,
            previous_value TEXT,
            new_value TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE store_write_gate (
            gate_key TEXT PRIMARY KEY,
            is_paused INTEGER NOT NULL DEFAULT 0,
            paused_by INTEGER DEFAULT NULL,
            paused_at TEXT DEFAULT NULL
        );

        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open'
        );
        INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('FY2026', '2026-01-01', '2026-12-31', 'open');

        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER NOT NULL,
            table_name TEXT NULL
        );

        CREATE TABLE store_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
        );
        INSERT INTO store_settings (setting_key, setting_value) VALUES ('expiry_alert_days', '90,60,30,14,7');

        CREATE TABLE notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            type TEXT,
            title TEXT,
            message TEXT,
            reference_id INTEGER,
            reference_type TEXT,
            is_read INTEGER DEFAULT 0,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE notification_preferences (
            user_id INTEGER PRIMARY KEY,
            notify_inapp INTEGER DEFAULT 1,
            notify_low_stock INTEGER DEFAULT 1,
            notify_expiring_stock INTEGER DEFAULT 1,
            low_stock_threshold INTEGER DEFAULT NULL
        );
    ");

    // 2b. Run migration 202610180001_inventory_count_batches to create inventory_count_batches
    echo "Running migration 202610180001_inventory_count_batches...\n";
    $migration = require dirname(__DIR__) . '/database/migrations/202610180001_inventory_count_batches.php';
    $assert(is_array($migration) && isset($migration['up']), 'Migration file loaded correctly');
    ($migration['up'])($pdo);

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='inventory_count_batches'")->fetchAll(PDO::FETCH_COLUMN);
    $assert(count($tables) === 1, 'inventory_count_batches table exists after migration');

    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'inventory_manager';
    $_SESSION['user'] = ['role_name' => 'inventory_manager', 'user_id' => 1];

    $countService = new InventoryCountService($pdo);

    // 3. Unbatched on-hand detection and mismatch reporting
    echo "Testing unbatched on-hand detection and mismatch reporting...\n";
    // Product 1: On-hand 25, Batch sum 20 -> 5 unbatched, mismatch = true
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, expiration_date) VALUES (1, 'SKU-UNBATCHED', 'Unbatched Milk', '2026-10-01')");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 25)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES (101, 1, 'BATCH-01', 20, 20, '2026-11-01')");

    $coverage1 = $countService->getProductBatchCoverage(1);
    $assert($coverage1['on_hand'] === 25, 'Product 1 on_hand is 25');
    $assert($coverage1['batch_sum'] === 20, 'Product 1 batch_sum is 20');
    $assert($coverage1['unbatched_qty'] === 5, 'Product 1 has 5 unbatched units');
    $assert($coverage1['has_mismatch'] === true, 'Product 1 has_mismatch flagged true');
    $assert(count($coverage1['batches']) === 1, 'Product 1 has 1 active batch');

    // Product 2: Legacy product with 12 on hand, 0 batches -> 12 unbatched, mismatch = true
    $pdo->exec("INSERT INTO products (product_id, sku, product_name, expiration_date) VALUES (2, 'SKU-LEGACY', 'Legacy Bread', '2026-09-01')");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 12)");

    $coverage2 = $countService->getProductBatchCoverage(2);
    $assert($coverage2['on_hand'] === 12, 'Product 2 on_hand is 12');
    $assert($coverage2['batch_sum'] === 0, 'Product 2 batch_sum is 0');
    $assert($coverage2['unbatched_qty'] === 12, 'Product 2 has 12 unbatched units');
    $assert($coverage2['has_mismatch'] === true, 'Product 2 has_mismatch flagged true');
    $assert(count($coverage2['batches']) === 0, 'Product 2 has 0 active batches');

    // Product 3: Perfectly matched batches: 15 on hand, Batch A = 10, Batch B = 5 -> 0 unbatched, mismatch = false
    $pdo->exec("INSERT INTO products (product_id, sku, product_name) VALUES (3, 'SKU-MATCHED', 'Matched Cereal')");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (3, 15)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES (301, 3, 'B301', 10, 10, '2026-12-15')");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, expiration_date) VALUES (302, 3, 'B302', 5, 5, '2026-10-10')"); // Expired relative to later dates

    $coverage3 = $countService->getProductBatchCoverage(3);
    $assert($coverage3['on_hand'] === 15, 'Product 3 on_hand is 15');
    $assert($coverage3['batch_sum'] === 15, 'Product 3 batch_sum is 15');
    $assert($coverage3['unbatched_qty'] === 0, 'Product 3 unbatched_qty is 0');
    $assert($coverage3['has_mismatch'] === false, 'Product 3 has_mismatch flagged false');
    $assert(count($coverage3['batches']) === 2, 'Product 3 has 2 active batches');

    // 4. Recording batch count with shortages, extras, and undated stock
    echo "Testing recording batch count with shortages, extras, and undated stock...\n";
    // For Product 1:
    // Existing Batch 101 (system 20): counted 18 (shortage 2)
    // Extra batch 1: physical 4, expiration '2027-01-20', batch_number 'EXTRA-01'
    // Extra batch 2: physical 3, expiration null (undated), batch_number 'EXTRA-UNDATED'
    // Total physical = 18 + 4 + 3 = 25
    $countId1 = $countService->recordCount([
        'product_id' => 1,
        'physical_quantity' => 25,
        'discrepancy_reason' => 'Physical batch recount found shortage in batch 1 and extra unbatched units',
        'batches' => [
            [
                'batch_id' => 101,
                'physical_quantity' => 18,
            ],
            [
                'batch_id' => null,
                'physical_quantity' => 4,
                'expiration_date' => '2027-01-20',
                'batch_number' => 'EXTRA-01',
                'notes' => 'Found on top shelf',
            ],
            [
                'batch_id' => null,
                'physical_quantity' => 3,
                'expiration_date' => '', // explicitly blank/undated
                'batch_number' => 'EXTRA-UNDATED',
                'notes' => 'Undated stock',
            ],
        ],
    ], 1);

    $countRow1 = $pdo->query("SELECT * FROM inventory_counts WHERE count_id = {$countId1}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$countRow1['system_quantity'] === 25, 'Count 1 system_quantity recorded as 25');
    $assert((int)$countRow1['physical_quantity'] === 25, 'Count 1 physical_quantity recorded as 25');
    $assert((int)$countRow1['difference_qty'] === 0, 'Count 1 difference_qty is 0');
    $assert($countRow1['status'] === 'pending', 'Count 1 status is pending');

    $countBatches1 = $countService->getCountBatches($countId1);
    $assert(count($countBatches1) === 3, 'Count 1 has 3 batch records in inventory_count_batches');

    // Batch 1 line:
    $assert((int)$countBatches1[0]['batch_id'] === 101, 'Line 1 is batch 101');
    $assert((int)$countBatches1[0]['system_quantity'] === 20, 'Line 1 system_quantity is 20');
    $assert((int)$countBatches1[0]['physical_quantity'] === 18, 'Line 1 physical_quantity is 18');
    $assert((int)$countBatches1[0]['difference_qty'] === -2, 'Line 1 difference_qty is -2 (shortage)');
    $assert($countBatches1[0]['expiration_date'] === '2026-11-01', 'Line 1 retains original batch expiration date');

    // Extra batch 1 line:
    $assert($countBatches1[1]['batch_id'] === null, 'Line 2 has null batch_id (extra)');
    $assert((int)$countBatches1[1]['system_quantity'] === 0, 'Line 2 system_quantity is 0');
    $assert((int)$countBatches1[1]['physical_quantity'] === 4, 'Line 2 physical_quantity is 4');
    $assert((int)$countBatches1[1]['difference_qty'] === 4, 'Line 2 difference_qty is 4');
    $assert($countBatches1[1]['expiration_date'] === '2027-01-20', 'Line 2 has confirmed expiration date');

    // Extra batch 2 line:
    $assert($countBatches1[2]['batch_id'] === null, 'Line 3 has null batch_id (extra)');
    $assert((int)$countBatches1[2]['system_quantity'] === 0, 'Line 3 system_quantity is 0');
    $assert((int)$countBatches1[2]['physical_quantity'] === 3, 'Line 3 physical_quantity is 3');
    $assert((int)$countBatches1[2]['difference_qty'] === 3, 'Line 3 difference_qty is 3');
    $assert($countBatches1[2]['expiration_date'] === null, 'Line 3 has NULL expiration date (explicitly undated!)');
    $assert($countBatches1[2]['batch_number'] === 'EXTRA-UNDATED', 'Line 3 has batch_number');

    // 4b. Strict validation checks
    echo "Testing strict validation on batch counts...\n";
    // Invalid calendar date
    $invDateThrew = false;
    try {
        $countService->recordCount([
            'product_id' => 1,
            'physical_quantity' => 5,
            'discrepancy_reason' => 'Invalid date test',
            'batches' => [
                ['batch_id' => null, 'physical_quantity' => 5, 'expiration_date' => '2026-02-31'],
            ],
        ], 1);
    } catch (RuntimeException $e) {
        $invDateThrew = true;
        $assert($e->getMessage() === 'Enter a valid expiration date.', 'Strict calendar date validation rejects 2026-02-31');
    }
    $assert($invDateThrew, 'Invalid date threw RuntimeException');

    // Sum mismatch
    $sumMismatchThrew = false;
    try {
        $countService->recordCount([
            'product_id' => 1,
            'physical_quantity' => 20, // Sum of batches is 15
            'discrepancy_reason' => 'Mismatch test',
            'batches' => [
                ['batch_id' => 101, 'physical_quantity' => 15],
            ],
        ], 1);
    } catch (RuntimeException $e) {
        $sumMismatchThrew = true;
        $assert(str_contains($e->getMessage(), 'Physically counted quantity does not match the sum of batch quantities'), 'Sum mismatch rejected');
    }
    $assert($sumMismatchThrew, 'Sum mismatch threw RuntimeException');

    // Duplicate batch line
    $dupBatchThrew = false;
    try {
        $countService->recordCount([
            'product_id' => 1,
            'physical_quantity' => 20,
            'discrepancy_reason' => 'Duplicate batch test',
            'batches' => [
                ['batch_id' => 101, 'physical_quantity' => 10],
                ['batch_id' => 101, 'physical_quantity' => 10],
            ],
        ], 1);
    } catch (RuntimeException $e) {
        $dupBatchThrew = true;
        $assert(str_contains($e->getMessage(), 'Duplicate batch line'), 'Duplicate batch line rejected');
    }
    $assert($dupBatchThrew, 'Duplicate batch threw RuntimeException');

    // 5. Approval atomically updates product_batches, inventory, and stock_movements
    echo "Testing approval atomically updates product_batches, inventory, and stock_movements...\n";
    $countService->approveCount($countId1, 1);

    // Verify inventory_counts status
    $approvedCount = $pdo->query("SELECT * FROM inventory_counts WHERE count_id = {$countId1}")->fetch(PDO::FETCH_ASSOC);
    $assert($approvedCount['status'] === 'approved', 'Count status updated to approved');
    $assert((int)$approvedCount['approved_by'] === 1, 'Approved by user 1');
    $assert(!empty($approvedCount['approved_at']), 'Approved at timestamp set');

    // Verify inventory on hand
    $inv1 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
    $assert($inv1 === 25, 'Inventory quantity on hand is 25');

    // Verify original batch 101 updated
    $batch101 = $pdo->query("SELECT * FROM product_batches WHERE batch_id = 101")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$batch101['remaining_quantity'] === 18, 'Batch 101 remaining_quantity updated from 20 to 18');
    $assert($batch101['expiration_date'] === '2026-11-01', 'Batch 101 date preserved as 2026-11-01');

    // Verify new batches created
    $newBatches = $pdo->query("SELECT * FROM product_batches WHERE product_id = 1 AND batch_id != 101 ORDER BY batch_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($newBatches) === 2, 'Two new batches created in product_batches');

    // Extra dated batch
    $assert($newBatches[0]['batch_number'] === 'EXTRA-01', 'Extra batch 1 number is EXTRA-01');
    $assert((int)$newBatches[0]['quantity'] === 4 && (int)$newBatches[0]['remaining_quantity'] === 4, 'Extra batch 1 quantity and remaining_quantity is 4');
    $assert($newBatches[0]['expiration_date'] === '2027-01-20', 'Extra batch 1 expiration_date is 2027-01-20');

    // Extra undated batch: NEVER copied legacy product date '2026-10-01'!
    $assert($newBatches[1]['batch_number'] === 'EXTRA-UNDATED', 'Extra batch 2 number is EXTRA-UNDATED');
    $assert((int)$newBatches[1]['quantity'] === 3 && (int)$newBatches[1]['remaining_quantity'] === 3, 'Extra batch 2 quantity and remaining_quantity is 3');
    $assert($newBatches[1]['expiration_date'] === null, 'Extra batch 2 expiration_date remains explicitly NULL (not legacy date!)');

    // Legacy product expiration date unchanged
    $prod1Date = $pdo->query("SELECT expiration_date FROM products WHERE product_id = 1")->fetchColumn();
    $assert($prod1Date === '2026-10-01', 'Product 1 legacy expiration_date preserved unchanged');

    // Verify stock is now 100% reconciled!
    $coverageAfter = $countService->getProductBatchCoverage(1);
    $assert($coverageAfter['on_hand'] === 25, 'Coverage on_hand is 25');
    $assert($coverageAfter['batch_sum'] === 25, 'Coverage batch_sum is 25 (18 + 4 + 3)');
    $assert($coverageAfter['unbatched_qty'] === 0, 'Coverage unbatched_qty is 0');
    $assert($coverageAfter['has_mismatch'] === false, 'Coverage has_mismatch is now FALSE - fully reconciled!');

    // 5b. Approval with net quantity difference creates stock_movements
    echo "Testing approval with net quantity difference creates stock_movements...\n";
    $countId2 = $countService->recordCount([
        'product_id' => 1,
        'physical_quantity' => 20, // Net shortage of 5 (25 -> 20)
        'discrepancy_reason' => 'Physical shortage found',
        'batches' => [
            ['batch_id' => 101, 'physical_quantity' => 15], // 18 -> 15 (-3)
            ['batch_id' => $newBatches[0]['batch_id'], 'physical_quantity' => 3], // 4 -> 3 (-1)
            ['batch_id' => $newBatches[1]['batch_id'], 'physical_quantity' => 2], // 3 -> 2 (-1)
        ],
    ], 1);
    $countService->approveCount($countId2, 1);

    $invAfterCount2 = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 1")->fetchColumn();
    $assert($invAfterCount2 === 20, 'Inventory quantity on hand updated to 20');

    $lastMovement = $pdo->query("SELECT * FROM stock_movements WHERE product_id = 1 ORDER BY movement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $assert(!empty($lastMovement), 'Stock movement recorded on count approval');
    $assert((int)$lastMovement['change_qty'] === -5, 'Stock movement change_qty is -5');
    $assert($lastMovement['reason'] === 'adjustment', 'Stock movement reason is adjustment');
    $assert((int)$lastMovement['moved_by'] === 1, 'Stock movement moved_by is user 1');

    // 6. Concurrency & Stale Count Guards
    echo "Testing stale count guard when inventory aggregate changes concurrently...\n";
    $countIdStaleInv = $countService->recordCount([
        'product_id' => 1,
        'physical_quantity' => 20,
        'discrepancy_reason' => 'Concurrency test count',
        'batches' => [
            ['batch_id' => 101, 'physical_quantity' => 15],
            ['batch_id' => $newBatches[0]['batch_id'], 'physical_quantity' => 3],
            ['batch_id' => $newBatches[1]['batch_id'], 'physical_quantity' => 2],
        ],
    ], 1);

    // Simulate concurrent sale or adjustment changing inventory
    $pdo->exec("UPDATE inventory SET quantity_on_hand = 19 WHERE product_id = 1");

    $staleInvThrew = false;
    try {
        $countService->approveCount($countIdStaleInv, 1);
    } catch (RuntimeException $e) {
        $staleInvThrew = true;
        $assert(str_contains($e->getMessage(), 'Stock has changed since this count was recorded'), 'Approval rejected when inventory changed concurrently');
    }
    $assert($staleInvThrew, 'Stale inventory count threw RuntimeException');

    // Restore inventory to 20
    $pdo->exec("UPDATE inventory SET quantity_on_hand = 20 WHERE product_id = 1");

    echo "Testing stale count guard when batch remaining quantity changes concurrently...\n";
    $countIdStaleBatch = $countService->recordCount([
        'product_id' => 1,
        'physical_quantity' => 19,
        'discrepancy_reason' => 'Batch concurrency test count',
        'batches' => [
            ['batch_id' => 101, 'physical_quantity' => 14], // -1
            ['batch_id' => $newBatches[0]['batch_id'], 'physical_quantity' => 3],
            ['batch_id' => $newBatches[1]['batch_id'], 'physical_quantity' => 2],
        ],
    ], 1);

    // Simulate concurrent batch change (e.g. FEFO sale took 1 unit from batch 101)
    $pdo->exec("UPDATE product_batches SET remaining_quantity = 14 WHERE batch_id = 101");

    $staleBatchThrew = false;
    try {
        $countService->approveCount($countIdStaleBatch, 1);
    } catch (RuntimeException $e) {
        $staleBatchThrew = true;
        $assert(str_contains($e->getMessage(), 'Stock has changed since this count was recorded'), 'Approval rejected when batch balance changed concurrently');
    }
    $assert($staleBatchThrew, 'Stale batch count threw RuntimeException');

    // 7. Duplicate approval rejection
    echo "Testing duplicate approval rejection...\n";
    $dupCountId = $countService->recordCount([
        'product_id' => 3,
        'physical_quantity' => 15,
        'discrepancy_reason' => 'Test duplicate approval',
        'batches' => [
            ['batch_id' => 301, 'physical_quantity' => 10],
            ['batch_id' => 302, 'physical_quantity' => 5],
        ],
    ], 1);

    $countService->approveCount($dupCountId, 1);

    $dupApproveThrew = false;
    try {
        $countService->approveCount($dupCountId, 1);
    } catch (RuntimeException $e) {
        $dupApproveThrew = true;
        $assert($e->getMessage() === 'Only pending counts can be approved.', 'Second approval attempt rejected with exact message');
    }
    $assert($dupApproveThrew, 'Duplicate approval threw RuntimeException');

    // 8. Rollback verification on failure
    echo "Testing atomic rollback on approval failure...\n";
    $rollbackCountId = $countService->recordCount([
        'product_id' => 3,
        'physical_quantity' => 12,
        'discrepancy_reason' => 'Test rollback',
        'batches' => [
            ['batch_id' => 301, 'physical_quantity' => 8],
            ['batch_id' => 302, 'physical_quantity' => 4],
        ],
    ], 1);

    // Drop stock_movements temporarily to force error inside approval transaction
    $pdo->exec("ALTER TABLE stock_movements RENAME TO stock_movements_temp");

    $rollbackThrew = false;
    try {
        $countService->approveCount($rollbackCountId, 1);
    } catch (Throwable $e) {
        $rollbackThrew = true;
    }
    $assert($rollbackThrew, 'Approval threw when write failed');

    // Restore table
    $pdo->exec("ALTER TABLE stock_movements_temp RENAME TO stock_movements");

    // Verify count status was NOT updated
    $unapprovedCount = $pdo->query("SELECT status FROM inventory_counts WHERE count_id = {$rollbackCountId}")->fetchColumn();
    $assert($unapprovedCount === 'pending', 'Count status remains pending after rollback');

    // Verify batches were NOT updated
    $batch301Qty = (int)$pdo->query("SELECT remaining_quantity FROM product_batches WHERE batch_id = 301")->fetchColumn();
    $assert($batch301Qty === 10, 'Batch 301 remaining quantity rolled back to 10');

    // Verify inventory was NOT updated
    $inv3Qty = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 3")->fetchColumn();
    $assert($inv3Qty === 15, 'Inventory quantity on hand rolled back to 15');

    // 9. Backward compatibility for count without batches
    echo "Testing backward compatibility for count without batches...\n";
    $legacyCountId = $countService->recordCount([
        'product_id' => 2,
        'physical_quantity' => 10,
        'discrepancy_reason' => 'Legacy count without batch breakdown',
    ], 1);

    $countBatchesLegacy = $countService->getCountBatches($legacyCountId);
    $assert(count($countBatchesLegacy) === 0, 'Legacy count has no inventory_count_batches entries');

    $countService->approveCount($legacyCountId, 1);
    $invLegacy = (int)$pdo->query("SELECT quantity_on_hand FROM inventory WHERE product_id = 2")->fetchColumn();
    $assert($invLegacy === 10, 'Legacy count approval updated inventory to 10');

} catch (Throwable $e) {
    $failures[] = "Unexpected exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    fwrite(STDERR, "  fail - Unexpected exception: {$e->getMessage()}\n" . $e->getTraceAsString() . "\n");
}

if (!empty($failures)) {
    echo "\nInventory Count Reconciliation test FAILED with " . count($failures) . " failure(s).\n";
    exit(1);
}

echo "\nInventory Count Reconciliation test: ALL PASSED\n";
exit(0);
