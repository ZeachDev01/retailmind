<?php
// Contract test for Ticket #343:
// Complete Refund Correction Reviews through responsible roles.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/RefundCorrectionReviewService.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/ProductService.php';

use App\Audit\AuditRecordCategory;
use App\Services\RefundCorrectionReviewService;
use App\Services\CashierShiftService;
use App\Services\ProductService;

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

    // Schema setup
    $pdo->exec("
        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY,
            role_name TEXT UNIQUE NOT NULL
        );
        INSERT INTO roles (role_id, role_name) VALUES
            (1, 'admin'),
            (2, 'super_admin'),
            (3, 'cashier'),
            (4, 'inventory_manager');

        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE,
            password_hash TEXT NOT NULL,
            role_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            branch_id INTEGER NULL DEFAULT 1,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            is_recovery_account INTEGER NOT NULL DEFAULT 0,
            locked_until TEXT NULL
        );

        CREATE TABLE user_roles (
            user_id INTEGER NOT NULL,
            role_id INTEGER NOT NULL,
            is_primary INTEGER DEFAULT 0,
            PRIMARY KEY (user_id, role_id)
        );

        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_name TEXT NOT NULL,
            branch_code TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active'
        );
        INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Branch', 'MAIN', 'active');

        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active'
        );
        INSERT INTO registers (register_id, name) VALUES (1, 'Main Register');

        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            register_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            opening_cash REAL NOT NULL DEFAULT 1000.0,
            opened_at TEXT DEFAULT CURRENT_TIMESTAMP,
            closed_at TEXT NULL,
            expected_cash REAL DEFAULT 1000.0,
            actual_cash REAL NULL,
            cash_variance REAL NULL,
            variance_threshold REAL NULL,
            variance_review_required INTEGER DEFAULT 0,
            payment_totals TEXT NULL,
            closing_notes TEXT NULL,
            closed_by INTEGER NULL,
            intervention_reason TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL,
            locked_at TEXT NULL
        );

        CREATE TABLE cash_drawer_movements (
            drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NOT NULL,
            note TEXT NULL,
            cashier_id INTEGER NOT NULL,
            recorded_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            total_amount REAL NOT NULL,
            payment_method TEXT NOT NULL DEFAULT 'cash',
            discount_amount REAL DEFAULT 0,
            sale_date TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE cash_refunds (
            refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            shift_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            refund_amount REAL NOT NULL,
            payment_method TEXT NOT NULL DEFAULT 'cash',
            reason TEXT NOT NULL,
            note TEXT NULL,
            original_cashier_id INTEGER NULL,
            approved_by INTEGER NULL,
            exception_reason TEXT NULL,
            payment_reference TEXT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE cash_refund_items (
            refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            refund_id INTEGER NOT NULL,
            sale_item_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            restock INTEGER NOT NULL DEFAULT 1,
            disposition TEXT NOT NULL DEFAULT 'restockable',
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER NULL DEFAULT 1,
            product_name TEXT NOT NULL,
            barcode TEXT,
            sku TEXT,
            unit_price REAL NOT NULL,
            cost_price REAL NOT NULL DEFAULT 0,
            reorder_level INTEGER NOT NULL DEFAULT 5,
            target_stock INTEGER NOT NULL DEFAULT 20,
            warning_lead_time_days INTEGER NULL DEFAULT 7,
            quantity_sold INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active'
        );

        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL UNIQUE,
            quantity_on_hand INTEGER NOT NULL DEFAULT 0,
            reorder_level INTEGER NOT NULL DEFAULT 5,
            target_stock INTEGER NOT NULL DEFAULT 20
        );

        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            batch_number TEXT NOT NULL DEFAULT 'B001',
            quantity INTEGER NOT NULL DEFAULT 10,
            quantity_received INTEGER NOT NULL DEFAULT 10,
            remaining_quantity INTEGER NOT NULL DEFAULT 10,
            cost_price REAL NOT NULL DEFAULT 0,
            expiration_date TEXT NULL,
            date_received TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory_adjustments (
            adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            adjustment_qty INTEGER NOT NULL,
            adjustment_type TEXT NOT NULL,
            reported_by INTEGER NOT NULL,
            shift_id INTEGER NULL,
            reported_at TEXT DEFAULT CURRENT_TIMESTAMP,
            approved_by INTEGER NULL,
            approved_at TEXT NULL,
            reason TEXT NULL,
            review_notes TEXT NULL,
            status TEXT DEFAULT 'pending',
            form TEXT NOT NULL DEFAULT 'piece',
            reported_qty INTEGER NOT NULL DEFAULT 0,
            pack_size INTEGER NULL
        );

        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            change_qty INTEGER NOT NULL,
            reason TEXT NOT NULL,
            moved_by INTEGER NOT NULL,
            adjustment_id INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            closed_at TEXT NULL,
            closed_by INTEGER NULL
        );
        INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status) VALUES (1, 'FY2026', '2026-01-01', '2026-12-31', 'open');

        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER NOT NULL,
            table_name TEXT NOT NULL
        );

        CREATE TABLE attention_states (
            user_id INTEGER NOT NULL,
            attention_key VARCHAR(120) NOT NULL,
            fingerprint VARCHAR(64) NOT NULL,
            notification_level INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL,
            first_detected_at DATETIME NOT NULL,
            last_detected_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            PRIMARY KEY (user_id, attention_key)
        );

        CREATE TABLE notification_preferences (
            user_id INTEGER PRIMARY KEY,
            notify_low_stock INTEGER DEFAULT 1,
            low_stock_threshold INTEGER NULL
        );

        CREATE TABLE notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            workspace TEXT NOT NULL DEFAULT '',
            inapp_enabled INTEGER NOT NULL DEFAULT 1,
            email_pending INTEGER NOT NULL DEFAULT 0,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            reference_id INTEGER NULL,
            reference_type VARCHAR(100) NULL,
            attention_key VARCHAR(120) NULL,
            attention_severity VARCHAR(20) NULL,
            attention_count INTEGER NULL,
            attention_destination VARCHAR(255) NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            category TEXT NOT NULL,
            module TEXT NOT NULL,
            record_id INTEGER NOT NULL,
            previous_value TEXT NULL,
            new_value TEXT NULL,
            ip_address TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed Users
    // 1: Admin Alice
    // 2: Cashier Casey
    // 3: Inventory Manager Ian
    // 4: Super Admin Sam
    // 5: Cashier/Admin Dual Dan
    $passHash = password_hash('Pass1234!', PASSWORD_BCRYPT);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([1, 'Alice Admin', 'admin_alice', 'alice@store.test', $passHash, 1]);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([2, 'Casey Cashier', 'cashier_casey', 'casey@store.test', $passHash, 3]);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([3, 'Ian Inventory', 'inv_ian', 'ian@store.test', $passHash, 4]);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([4, 'Sam Super', 'super_sam', 'sam@store.test', $passHash, 2]);
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([5, 'Dan Dual', 'dual_dan', 'dan@store.test', $passHash, 3]);
    $pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (5, 1, 0)"); // Dan has admin secondary

    // Seed product & inventory
    $pdo->exec("INSERT INTO products (product_id, product_name, barcode, sku, unit_price, cost_price) VALUES (10, 'Notebook', '111', 'NB-01', 50.0, 30.0)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (10, 20)");

    // Seed sale & refund
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method) VALUES (100, 1, 2, 100.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal) VALUES (1, 100, 10, 2, 50.0, 100.0)");
    $pdo->exec("INSERT INTO cash_refunds (refund_id, sale_id, shift_id, cashier_id, refund_amount, payment_method, reason, note) VALUES (50, 100, 1, 2, 50.0, 'cash', 'wrong_item', 'Wrong item returned')");
    $pdo->exec("INSERT INTO cash_refund_items (refund_item_id, refund_id, sale_item_id, product_id, quantity, unit_price, subtotal, restock, disposition) VALUES (1, 50, 1, 10, 1, 50.0, 50.0, 1, 'restockable')");

    // Open shift for Casey (shift 1) and Dan (shift 2)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash) VALUES (1, 2, 1, 'open', 1000.0)");
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash) VALUES (2, 5, 1, 'open', 1000.0)");

    // Instantiate service
    $service = new RefundCorrectionReviewService($pdo);

    // =========================================================================
    // 1. Role restrictions & creation validations
    // =========================================================================
    // Only Administrator workspace can open/approve refund correction review
    $expectRefusal(
        fn() => $service->createReview(2, 'cashier', 50, 'cash_drawer', 2, null, 'Mistake in cash return amount'),
        'Cashier workspace cannot create refund correction review'
    );
    $expectRefusal(
        fn() => $service->createReview(3, 'inventory_manager', 50, 'stock_only', null, 3, 'Mistake in stock disposition'),
        'Inventory Manager workspace cannot create refund correction review'
    );
    $expectRefusal(
        fn() => $service->createReview(4, 'super_admin', 50, 'combined', 2, 3, 'Super admin attempt'),
        'Super Administrator workspace alone cannot create review'
    );
    // Invalid correction type
    $expectRefusal(
        fn() => $service->createReview(1, 'admin', 50, 'unknown_type', 2, null, 'Invalid type'),
        'Invalid correction type is refused'
    );
    // Missing required responsible cashier for cash_drawer
    $expectRefusal(
        fn() => $service->createReview(1, 'admin', 50, 'cash_drawer', null, null, 'Missing cashier'),
        'Cash drawer correction without responsible cashier is refused'
    );
    // Missing required responsible inventory manager for stock_only
    $expectRefusal(
        fn() => $service->createReview(1, 'admin', 50, 'stock_only', null, null, 'Missing inventory manager'),
        'Stock only correction without responsible inventory manager is refused'
    );
    // Non-existent refund
    $expectRefusal(
        fn() => $service->createReview(1, 'admin', 9999, 'cash_drawer', 2, null, 'Non existent refund'),
        'Non-existent refund is refused'
    );
    // Empty / short explanation
    $expectRefusal(
        fn() => $service->createReview(1, 'admin', 50, 'cash_drawer', 2, null, '   '),
        'Empty explanation is refused'
    );

    // =========================================================================
    // 2. Review creation preserves originals and creates NO movements
    // =========================================================================
    $initialSales = $pdo->query("SELECT * FROM sales WHERE sale_id = 100")->fetch(PDO::FETCH_ASSOC);
    $initialRefund = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = 50")->fetch(PDO::FETCH_ASSOC);
    $initialMovements = (int)$pdo->query("SELECT COUNT(*) FROM cash_drawer_movements")->fetchColumn();
    $initialStockMovements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();

    $reviewId = $service->createReview(
        1,
        'admin',
        50,
        'combined',
        2, // responsible cashier
        3, // responsible inventory manager
        'Refund was recorded for cash 50 and restocked, but customer kept item and received 40.',
        ['cash_delta' => 10.0, 'stock_delta' => -1, 'product_id' => 10]
    );

    $assert($reviewId > 0, 'Review created with ID > 0');

    // Verify originals untouched
    $afterSales = $pdo->query("SELECT * FROM sales WHERE sale_id = 100")->fetch(PDO::FETCH_ASSOC);
    $afterRefund = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = 50")->fetch(PDO::FETCH_ASSOC);
    $assert($initialSales === $afterSales, 'Original sale record untouched after review creation');
    $assert($initialRefund === $afterRefund, 'Original refund record untouched after review creation');

    // Verify NO cash or stock movements created
    $afterMovements = (int)$pdo->query("SELECT COUNT(*) FROM cash_drawer_movements")->fetchColumn();
    $afterStockMovements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
    $assert($initialMovements === $afterMovements, 'No drawer cash movement created upon review creation');
    $assert($initialStockMovements === $afterStockMovements, 'No stock movement created upon review creation');

    // Verify initial status: Reviewed—correction outstanding
    $review = $service->getReview($reviewId);
    $assert($review['status'] === RefundCorrectionReviewService::STATUS_REVIEWED_OUTSTANDING, 'Initial review status is Reviewed—correction outstanding');
    $assert($review['cash_status'] === 'pending', 'Cash task is pending');
    $assert($review['stock_status'] === 'pending', 'Stock task is pending');
    $assert((int)$review['reviewed_by'] === 1, 'Reviewed by Admin Alice (1)');
    $assert((int)$review['responsible_cashier_id'] === 2, 'Responsible cashier is Casey (2)');
    $assert((int)$review['responsible_inventory_manager_id'] === 3, 'Responsible inventory manager is Ian (3)');

    // Verify Audit log recorded for review creation
    $auditLogs = $pdo->query("SELECT * FROM activity_log WHERE module = 'Refund Correction Reviews' AND record_id = {$reviewId}")->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($auditLogs) === 1, 'Audit log recorded for review creation');
    $assert($auditLogs[0]['action'] === 'Refund correction review approved', 'Audit action is Refund correction review approved');
    $assert($auditLogs[0]['category'] === AuditRecordCategory::STORE_OPERATION, 'Audit category is STORE_OPERATION');

    // =========================================================================
    // 3. Outstanding tasks & role execution restrictions
    // =========================================================================
    // Non-responsible Cashier (Dan) cannot execute Casey's cash correction task
    $expectRefusal(
        fn() => $service->executeCashCorrection($reviewId, 5, 'cashier', 10.0, 'Returning 10 over-refunded cash to drawer'),
        'Non-responsible cashier cannot execute drawer correction'
    );
    // Administrator cannot directly execute cashier drawer correction
    $expectRefusal(
        fn() => $service->executeCashCorrection($reviewId, 1, 'admin', 10.0, 'Admin direct cash correction'),
        'Admin cannot execute cashier drawer correction directly'
    );
    // Non-responsible inventory manager cannot execute Ian's stock correction
    $expectRefusal(
        fn() => $service->executeStockCorrection($reviewId, 1, 'admin', 10, -1, 'Admin stock adjustment'),
        'Admin cannot execute stock adjustment directly'
    );

    // =========================================================================
    // 4. Responsible Cashier executes cash correction via Shift controls
    // =========================================================================
    $cashCorrectionResult = $service->executeCashCorrection(
        $reviewId,
        2, // Casey
        'cashier',
        10.0, // cash_in 10.0
        'Correction for Refund #50: 10 over-refunded cash returned'
    );
    $assert($cashCorrectionResult['drawer_movement_id'] > 0, 'Drawer movement created for cash correction');

    // Check review state: still Reviewed—correction outstanding because stock task remains!
    $reviewMid = $service->getReview($reviewId);
    $assert($reviewMid['status'] === RefundCorrectionReviewService::STATUS_REVIEWED_OUTSTANDING, 'Status remains Reviewed—correction outstanding while stock task remains');
    $assert($reviewMid['cash_status'] === 'completed', 'Cash task marked completed');
    $assert($reviewMid['stock_status'] === 'pending', 'Stock task still pending');
    $assert((int)$reviewMid['cash_drawer_movement_id'] === (int)$cashCorrectionResult['drawer_movement_id'], 'Drawer movement ID linked to review');

    // Drawer movement created with actor attribution
    $mov = $pdo->query("SELECT * FROM cash_drawer_movements WHERE drawer_movement_id = {$cashCorrectionResult['drawer_movement_id']}")->fetch(PDO::FETCH_ASSOC);
    $assert((int)$mov['cashier_id'] === 2, 'Cashier ID on drawer movement is Casey (2)');
    $assert((int)$mov['recorded_by'] === 2, 'Recorded by Casey (2)');
    $assert((float)$mov['amount'] === 10.0, 'Amount is 10.0');

    // Cannot re-execute completed cash correction
    $expectRefusal(
        fn() => $service->executeCashCorrection($reviewId, 2, 'cashier', 10.0, 'Repeat cash correction'),
        'Cannot re-execute completed cash correction'
    );

    // =========================================================================
    // 5. Responsible Inventory Manager executes stock adjustment
    // =========================================================================
    $stockCorrectionResult = $service->executeStockCorrection(
        $reviewId,
        3, // Ian
        'inventory_manager',
        10,
        -1,
        'Correction for Refund #50: item was not actually returned by customer'
    );
    $assert($stockCorrectionResult['adjustment_id'] > 0, 'Inventory adjustment created for stock correction');

    // Check review state: now Corrected!
    $reviewDone = $service->getReview($reviewId);
    $assert($reviewDone['status'] === RefundCorrectionReviewService::STATUS_CORRECTED, 'Status transitions to Corrected once both tasks are complete');
    $assert($reviewDone['cash_status'] === 'completed', 'Cash task completed');
    $assert($reviewDone['stock_status'] === 'completed', 'Stock task completed');
    $assert((int)$reviewDone['inventory_adjustment_id'] === (int)$stockCorrectionResult['adjustment_id'], 'Inventory adjustment linked');
    $assert(!empty($reviewDone['completed_at']), 'completed_at timestamp recorded');

    // Stock movement exists
    $stockMov = $pdo->query("SELECT * FROM stock_movements WHERE adjustment_id = {$stockCorrectionResult['adjustment_id']}")->fetch(PDO::FETCH_ASSOC);
    $assert($stockMov !== false, 'Stock movement created');
    $assert((int)$stockMov['change_qty'] === -1, 'Stock movement change is -1');
    $assert((int)$stockMov['moved_by'] === 3, 'Stock moved by Ian (3)');

    // =========================================================================
    // 6. Cash-only correction review
    // =========================================================================
    $cashOnlyId = $service->createReview(
        1,
        'admin',
        50,
        'cash_drawer',
        2,
        null,
        'Cash-only refund amount mistake'
    );
    $revCash = $service->getReview($cashOnlyId);
    $assert($revCash['correction_type'] === 'cash_drawer', 'Correction type is cash_drawer');
    $assert($revCash['cash_status'] === 'pending', 'Cash task pending');
    $assert($revCash['stock_status'] === 'not_required', 'Stock task not_required');
    $assert($revCash['status'] === RefundCorrectionReviewService::STATUS_REVIEWED_OUTSTANDING, 'Initial status Reviewed—correction outstanding');

    // Casey completes cash task
    $service->executeCashCorrection($cashOnlyId, 2, 'cashier', 5.0, 'Cash adjustment for cash-only review');
    $revCashDone = $service->getReview($cashOnlyId);
    $assert($revCashDone['status'] === RefundCorrectionReviewService::STATUS_CORRECTED, 'Cash-only review is Corrected upon cash completion');

    // =========================================================================
    // 7. Stock-only correction review
    // =========================================================================
    $stockOnlyId = $service->createReview(
        1,
        'admin',
        50,
        'stock_only',
        null,
        3,
        'Stock disposition mistake'
    );
    $revStock = $service->getReview($stockOnlyId);
    $assert($revStock['correction_type'] === 'stock_only', 'Correction type is stock_only');
    $assert($revStock['cash_status'] === 'not_required', 'Cash task not_required');
    $assert($revStock['stock_status'] === 'pending', 'Stock task pending');
    $assert($revStock['status'] === RefundCorrectionReviewService::STATUS_REVIEWED_OUTSTANDING, 'Initial status Reviewed—correction outstanding');

    // Ian completes stock task
    $service->executeStockCorrection($stockOnlyId, 3, 'inventory_manager', 10, 1, 'Restock 1 unit');
    $revStockDone = $service->getReview($stockOnlyId);
    $assert($revStockDone['status'] === RefundCorrectionReviewService::STATUS_CORRECTED, 'Stock-only review is Corrected upon stock completion');

    // =========================================================================
    // 8. Dual-role self-approval permitted
    // =========================================================================
    // Dual Dan (user 5) has primary cashier and assigned admin role
    $dualReviewId = $service->createReview(
        5,
        'admin', // Dan in admin workspace
        50,
        'cash_drawer',
        5, // Dan responsible cashier
        null,
        'Dual Dan approved own review'
    );
    $revDual = $service->getReview($dualReviewId);
    $assert((int)$revDual['reviewed_by'] === 5, 'Dual Dan reviewed_by is 5');
    $assert((int)$revDual['responsible_cashier_id'] === 5, 'Dual Dan responsible_cashier_id is 5');

    // Dan executes cash correction in cashier workspace
    $service->executeCashCorrection($dualReviewId, 5, 'cashier', 10.0, 'Dual Dan executes own drawer correction');
    $revDualDone = $service->getReview($dualReviewId);
    $assert($revDualDone['status'] === RefundCorrectionReviewService::STATUS_CORRECTED, 'Dual-role review successfully marked Corrected');

    // =========================================================================
    // 9. Query & Pending review listing
    // =========================================================================
    $pendingCashier2 = $service->getPendingReviewsForCashier(2);
    $assert(count($pendingCashier2) === 0, 'No pending reviews for Casey (all completed)');

    // Create a new pending combined review
    $newRevId = $service->createReview(1, 'admin', 50, 'combined', 2, 3, 'Another mistake');
    $pendingCasey = $service->getPendingReviewsForCashier(2);
    $assert(count($pendingCasey) === 1, 'Casey has 1 pending review');
    $assert((int)$pendingCasey[0]['review_id'] === $newRevId, 'Pending review ID matches');

    $pendingIan = $service->getPendingReviewsForInventoryManager(3);
    $assert(count($pendingIan) === 1, 'Ian has 1 pending review');
    $assert((int)$pendingIan[0]['review_id'] === $newRevId, 'Pending review ID matches');

    // A failed completion must roll back the movement, so retry cannot double it.
    $beforeMovements = (int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn();
    $pdo->exec("CREATE TRIGGER fail_review_update BEFORE UPDATE ON refund_correction_reviews BEGIN SELECT RAISE(ABORT, 'injected review update failure'); END");
    try {
        $service->executeCashCorrection($newRevId, 2, 'cashier', 10.0, 'Regression rollback check');
        $assert(false, 'Injected review update failure must fail correction');
    } catch (PDOException $expected) {}
    $assert((int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === $beforeMovements, 'Failed review completion rolls back its cash movement');
    $assert($service->getReview($newRevId)['cash_status'] === 'pending', 'Failed correction leaves task pending');
    $pdo->exec('DROP TRIGGER fail_review_update');
    $service->executeCashCorrection($newRevId, 2, 'cashier', 10.0, 'Regression retry check');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM cash_drawer_movements')->fetchColumn() === $beforeMovements + 1, 'Retry posts exactly one cash movement');
    $beforeStock = (int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=10')->fetchColumn();
    $pdo->exec("CREATE TRIGGER fail_stock_review BEFORE UPDATE ON refund_correction_reviews BEGIN SELECT RAISE(ABORT, 'injected stock review failure'); END");
    try {
        $service->executeStockCorrection($newRevId, 3, 'inventory_manager', 10, 1, 'Regression stock rollback');
        $assert(false, 'Injected review update failure must fail stock correction');
    } catch (PDOException $expected) {}
    $assert((int)$pdo->query('SELECT quantity_on_hand FROM inventory WHERE product_id=10')->fetchColumn() === $beforeStock, 'Failed review completion rolls back stock');
    $pdo->exec('DROP TRIGGER fail_stock_review');

    $allStoreReviews = $service->listReviews();
    $assert(count($allStoreReviews) >= 4, 'List reviews returns all Store reviews');

} catch (Throwable $e) {
    $failures[] = 'Unexpected error: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures !== []) {
    echo "Refund correction review contract FAILED:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "Refund correction review contract: passed\n";
exit(0);
