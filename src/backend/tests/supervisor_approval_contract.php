<?php
require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierShiftService;
use App\Services\CashRefundService;
use App\Services\GCashTransactionService;
use App\Services\RefundExceptionService;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$refused = static function (callable $action, string $message) use (&$failures): void {
    try {
        $action();
        $failures[] = $message;
    } catch (DomainException | InvalidArgumentException $expected) {
    }
};

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY,
            name TEXT NOT NULL
        );
        INSERT INTO registers VALUES (1, 'Main Register');

        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER NOT NULL,
            register_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            locked_at TEXT NULL,
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
            reviewed_at TEXT NULL
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

        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_name TEXT NOT NULL,
            barcode TEXT,
            sku TEXT,
            unit_price REAL NOT NULL,
            pack_size INTEGER NOT NULL DEFAULT 1,
            pack_price REAL NOT NULL DEFAULT 0,
            cost_price REAL NOT NULL DEFAULT 0,
            quantity_sold INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            branch_id INTEGER NULL
        );

        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL,
            original_quantity REAL NOT NULL,
            cost_price REAL NOT NULL,
            received_date TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE inventory (
            inventory_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL DEFAULT 100
        );

        CREATE TABLE cash_refunds (
            refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            shift_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            refund_amount REAL NOT NULL,
            payment_method TEXT NOT NULL,
            reason TEXT NOT NULL,
            note TEXT,
            original_cashier_id INTEGER,
            approved_by INTEGER,
            exception_reason TEXT,
            payment_reference TEXT,
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
            disposition TEXT NOT NULL,
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE refund_receipt_details (
            receipt_detail_id INTEGER PRIMARY KEY AUTOINCREMENT,
            refund_id INTEGER NOT NULL,
            details_json TEXT NOT NULL
        );

        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            quantity REAL NOT NULL,
            movement_type TEXT NOT NULL,
            reference_type TEXT,
            reference_id INTEGER,
            created_by INTEGER,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sale_reversals (
            reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            refund_amount REAL NOT NULL,
            status TEXT NOT NULL,
            settlement_method TEXT NOT NULL
        );

        CREATE TABLE cash_refund_exception_access (
            access_id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT NOT NULL,
            sale_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            shift_id INTEGER NOT NULL,
            approved_by INTEGER NOT NULL,
            exception_reason TEXT NOT NULL,
            expires_at INTEGER NOT NULL,
            used_refund_id INTEGER NULL
        );

        CREATE TABLE cash_drawer_movements (
            drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            movement_type TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NOT NULL,
            note TEXT,
            recorded_by INTEGER NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            category TEXT NOT NULL,
            module TEXT,
            record_id INTEGER,
            previous_value TEXT,
            new_value TEXT,
            ip_address TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'open',
            created_by INTEGER NOT NULL
        );
        INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status, created_by)
        VALUES (1, 'Current Period', '2026-01-01', '2026-12-31', 'open', 1);

        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER NOT NULL,
            table_name TEXT NOT NULL
        );
    ");

    $hash = password_hash('Pass1234!', PASSWORD_DEFAULT);

    // 1. Primary admin
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (1, 'Primary Admin', 'admin_one', 'admin_one@store.test', ?, 1, 'active', 0)")->execute([$hash]);

    // 2. Second primary admin
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (2, 'Second Admin', 'admin_two', 'admin_two@store.test', ?, 1, 'active', 0)")->execute([$hash]);

    // 3. Super admin ONLY (technical-account, no admin role)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (3, 'Super Technical', 'super_tech', 'super_tech@store.test', ?, 2, 'active', 0)")->execute([$hash]);

    // 4. Cashier ONLY
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (4, 'Cashier Sole', 'cashier_sole', 'cashier_sole@store.test', ?, 3, 'active', 0)")->execute([$hash]);

    // 5. Dual-role Cashier + Admin (primary cashier, admin in user_roles)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (5, 'Dual Role Staff', 'dual_staff', 'dual_staff@store.test', ?, 3, 'active', 0)")->execute([$hash]);
    $pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (5, 3, 1), (5, 1, 0)");

    // 6. Disabled admin
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (6, 'Disabled Admin', 'admin_disabled', 'disabled@store.test', ?, 1, 'disabled', 0)")->execute([$hash]);

    // 7. Admin requiring password change
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (7, 'Must Change Admin', 'admin_must_change', 'must_change@store.test', ?, 1, 'active', 1)")->execute([$hash]);

    // Fixture shifts and sales
    // Cashier shift for user 4 (cashier_sole)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash)
        VALUES (1, 4, 1, 'open', 500.0)");
    // Cashier shift for user 5 (dual_staff)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash)
        VALUES (2, 5, 1, 'open', 500.0)");

    // Product fixture
    $pdo->exec("INSERT INTO products (product_id, product_name, barcode, unit_price, pack_price, cost_price, quantity_sold)
        VALUES (100, 'Test Item', '100100', 50.0, 50.0, 30.0, 5)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, original_quantity, cost_price)
        VALUES (100, 100, 50.0, 50.0, 30.0)");

    // Sale made by user 4 (cashier_sole)
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (10, 1, 4, 50.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (10, 10, 100, 1, 50.0, 50.0)");

    // Initialize session to test session preservation
    $_SESSION = [
        'user_id' => 4,
        'role' => 'cashier',
        'roles' => ['cashier'],
        'full_name' => 'Cashier Sole',
    ];
    $sessionSnapshot = $_SESSION;

    // --- TEST 1: RefundExceptionService authorize() ---
    $refundExceptionService = new RefundExceptionService($pdo);

    // 1a. Username approval succeeds
    $auth = $refundExceptionService->authorize('admin_one', 'Pass1234!');
    $check($auth['approved_by'] === 1, 'RefundExceptionService: username approval returns user ID 1');

    // 1b. Email approval succeeds
    $auth = $refundExceptionService->authorize('admin_one@store.test', 'Pass1234!');
    $check($auth['approved_by'] === 1, 'RefundExceptionService: email approval returns user ID 1');

    // 1c. Additional role (user_roles admin) approval succeeds
    $auth = $refundExceptionService->authorize('dual_staff', 'Pass1234!');
    $check($auth['approved_by'] === 5, 'RefundExceptionService: additional admin assignment authorized');
    $auth = $refundExceptionService->authorize('dual_staff@store.test', 'Pass1234!');
    $check($auth['approved_by'] === 5, 'RefundExceptionService: additional admin email authorized');

    // 1d. Super Administrator ALONE is rejected
    $refused(fn() => $refundExceptionService->authorize('super_tech', 'Pass1234!'),
        'RefundExceptionService: super admin alone must be rejected');
    $refused(fn() => $refundExceptionService->authorize('super_tech@store.test', 'Pass1234!'),
        'RefundExceptionService: super admin email alone must be rejected');

    // 1e. Ineligible cashier rejected
    $refused(fn() => $refundExceptionService->authorize('cashier_sole', 'Pass1234!'),
        'RefundExceptionService: cashier sole must be rejected');

    // 1f. Disabled admin rejected
    $refused(fn() => $refundExceptionService->authorize('admin_disabled', 'Pass1234!'),
        'RefundExceptionService: disabled admin must be rejected');

    // 1g. Password change required rejected
    $refused(fn() => $refundExceptionService->authorize('admin_must_change', 'Pass1234!'),
        'RefundExceptionService: admin with must_change_password=1 must be rejected');

    // 1h. Invalid password rejected
    $refused(fn() => $refundExceptionService->authorize('admin_one', 'WrongPass!'),
        'RefundExceptionService: wrong password must be rejected');

    // 1i. Session untouched
    $check($_SESSION === $sessionSnapshot, 'RefundExceptionService: session must remain completely unmodified');

    // --- TEST 2: Two-stage exceptional refund (RefundExceptionService + CashRefundService) ---
    // User 5 (dual_staff in cashier shift 2) refunds sale 10 (made by user 4)
    // Stage 1: openSale approved by admin_one (user 1)
    $token = $refundExceptionService->openSale(5, 'cashier', 10, 'admin_one@store.test', 'Pass1234!', 'Original cashier absent');
    $check(is_string($token) && strlen($token) === 64, 'openSale creates a 64-char hex token');

    // Stage 2: refund approved by DIFFERENT administrator: admin_two (user 2) via email
    $shiftService = new CashierShiftService($pdo);
    $cashRefundService = new CashRefundService($pdo, $shiftService);
    $refundItems = [10 => ['quantity' => 1, 'disposition' => 'damaged']];
    $exceptionData = [
        'token' => $token,
        'username' => 'admin_two@store.test', // Different administrator!
        'password' => 'Pass1234!',
    ];
    $refundId = $cashRefundService->refund(5, 'cashier', 10, $refundItems, 'customer_return', 'Refunded by dual staff', null, false, $exceptionData);
    $check($refundId > 0, 'Two-stage refund succeeds with DIFFERENT eligible Administrator for stage 2');

    // Verify refund row attributions
    $refundRow = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = {$refundId}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$refundRow['cashier_id'] === 5, 'Cashier ID is 5 (issuing cashier)');
    $check((int)$refundRow['original_cashier_id'] === 4, 'Original cashier ID is 4');
    $check((int)$refundRow['approved_by'] === 2, 'Approved by is 2 (the administrator who authorized financial payment)');

    // Verify exception access token is now consumed
    $refused(fn() => $refundExceptionService->access($token, 5, 10, 2),
        'Consumed exception token cannot be reused');

    // Session remains untouched
    $check($_SESSION === $sessionSnapshot, 'CashRefundService: session must remain completely unmodified');

    // --- TEST 3: CashierShiftService authorizeDrawerSpending() ---
    $shiftService = new CashierShiftService($pdo);

    // 3a. Add drawer movement for petty cash with admin email approval
    $movId1 = $shiftService->addDrawerMovement(4, 'cashier', 'cash_out', 10.0, 'petty_cash', null, 'admin_one@store.test', 'Pass1234!');
    $check($movId1 > 0, 'CashierShiftService: drawer movement approved by admin email');
    $movRow1 = $pdo->query("SELECT * FROM activity_log WHERE record_id = {$movId1} AND module = 'Cashier Shifts'")->fetch(PDO::FETCH_ASSOC);
    $audit1 = json_decode($movRow1['new_value'], true);
    $check($audit1['authorization']['approved_by'] === 1, 'Drawer movement audit records approved_by 1');

    // 3b. Self-approval: Dual-role staff (user 5) approves their own drawer spending using their admin credentials
    $movId2 = $shiftService->addDrawerMovement(5, 'cashier', 'cash_out', 15.0, 'supplier_payment', null, 'dual_staff', 'Pass1234!');
    $check($movId2 > 0, 'CashierShiftService: eligible self-approval succeeds');
    $movRow2 = $pdo->query("SELECT * FROM activity_log WHERE record_id = {$movId2} AND module = 'Cashier Shifts'")->fetch(PDO::FETCH_ASSOC);
    $audit2 = json_decode($movRow2['new_value'], true);
    $check($audit2['cashier_id'] === 5 && $audit2['authorization']['approved_by'] === 5,
        'Drawer movement self-approval attributes cashier 5 and approver 5');

    // 3c. Super Administrator ALONE rejected for drawer spending
    $refused(fn() => $shiftService->addDrawerMovement(4, 'cashier', 'cash_out', 5.0, 'petty_cash', null, 'super_tech', 'Pass1234!'),
        'CashierShiftService: super admin alone rejected for drawer spending');

    // 3d. Session remains untouched
    $check($_SESSION === $sessionSnapshot, 'CashierShiftService: session must remain completely unmodified');

    // --- TEST 4: GCashTransactionService authorizeAdmin() ---
    $gcashService = new GCashTransactionService($pdo);

    // 4a. Username approval
    $gcashAuth1 = $gcashService->authorizeAdmin('admin_one', 'Pass1234!');
    $check($gcashAuth1 === 1, 'GCashTransactionService: authorizeAdmin succeeds with username');

    // 4b. Email approval
    $gcashAuth2 = $gcashService->authorizeAdmin('admin_one@store.test', 'Pass1234!');
    $check($gcashAuth2 === 1, 'GCashTransactionService: authorizeAdmin succeeds with email');

    // 4c. Additional admin access
    $gcashAuth3 = $gcashService->authorizeAdmin('dual_staff@store.test', 'Pass1234!');
    $check($gcashAuth3 === 5, 'GCashTransactionService: authorizeAdmin succeeds with additional admin role');

    // 4d. Super Administrator ALONE is rejected (now aligned with spec)
    $refused(fn() => $gcashService->authorizeAdmin('super_tech', 'Pass1234!'),
        'GCashTransactionService: super admin alone must be rejected');
    $refused(fn() => $gcashService->authorizeAdmin('super_tech@store.test', 'Pass1234!'),
        'GCashTransactionService: super admin email alone must be rejected');

    // 4e. Session remains untouched
    $check($_SESSION === $sessionSnapshot, 'GCashTransactionService: session must remain completely unmodified');

} catch (Throwable $e) {
    $failures[] = 'Unexpected exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures !== []) {
    echo "Supervisor approval contract: FAILED\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "Supervisor approval contract: passed\n";
exit(0);
