<?php
// Exceptional refund two-stage approval contract (Ticket #342).
// Tests separate receipt access vs refund payment approval,
// same vs different approvers, dual-role self-approval, expired tokens,
// and invalid credentials under Administrator workflow alignment.

require_once __DIR__ . '/../bootstrap/app.php';

use App\Services\CashierShiftService;
use App\Services\CashRefundService;
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
        INSERT INTO registers (register_id, name) VALUES (1, 'Main Register'), (2, 'Register Two');

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
            payment_method TEXT NOT NULL DEFAULT 'cash',
            reason TEXT NOT NULL,
            note TEXT,
            original_cashier_id INTEGER NOT NULL,
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
            disposition TEXT NOT NULL DEFAULT 'damaged',
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NULL
        );

        CREATE TABLE cash_refund_exception_access (
            access_id INTEGER PRIMARY KEY AUTOINCREMENT,
            token_hash TEXT UNIQUE NOT NULL,
            sale_id INTEGER NOT NULL,
            cashier_id INTEGER NOT NULL,
            shift_id INTEGER NOT NULL,
            approved_by INTEGER NOT NULL,
            exception_reason TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
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

        CREATE TABLE refund_receipt_details (
            detail_id INTEGER PRIMARY KEY AUTOINCREMENT,
            refund_id INTEGER UNIQUE NOT NULL,
            details_json TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE sale_reversals (
            reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            refund_amount REAL DEFAULT 0,
            settlement_method TEXT DEFAULT 'cash'
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

    // Users:
    // 1: Primary Administrator A
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (1, 'Admin Alice', 'admin_alice', 'alice@store.test', ?, 1, 'active', 0)")->execute([$hash]);

    // 2: Primary Administrator B
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (2, 'Admin Bob', 'admin_bob', 'bob@store.test', ?, 1, 'active', 0)")->execute([$hash]);

    // 3: Cashier Casey (Original Seller)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (3, 'Cashier Casey', 'cashier_casey', 'casey@store.test', ?, 3, 'active', 0)")->execute([$hash]);

    // 4: Cashier Dana (Issuing Cashier)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (4, 'Cashier Dana', 'cashier_dana', 'dana@store.test', ?, 3, 'active', 0)")->execute([$hash]);

    // 5: Dual-Role Staff Evan (Cashier + Admin, primary cashier, admin in user_roles)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (5, 'Dual Staff Evan', 'dual_evan', 'evan@store.test', ?, 3, 'active', 0)")->execute([$hash]);
    $pdo->exec("INSERT INTO user_roles (user_id, role_id, is_primary) VALUES (5, 3, 1), (5, 1, 0)");

    // 6: Technical-only Super Admin (no ordinary admin assignment)
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (6, 'Super Tech', 'super_tech', 'super@store.test', ?, 2, 'active', 0)")->execute([$hash]);

    // 7: Disabled Admin
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (7, 'Disabled Admin', 'admin_disabled', 'disabled@store.test', ?, 1, 'disabled', 0)")->execute([$hash]);

    // 8: Admin with must_change_password
    $pdo->prepare("INSERT INTO users (user_id, full_name, username, email, password_hash, role_id, status, must_change_password)
        VALUES (8, 'Change Pass Admin', 'admin_change', 'change@store.test', ?, 1, 'active', 1)")->execute([$hash]);

    // Products & Batches
    $pdo->exec("INSERT INTO products (product_id, product_name, barcode, sku, unit_price, pack_price, cost_price, quantity_sold)
        VALUES (10, 'Coffee Beans', 'BAR10', 'SKU10', 100.0, 100.0, 60.0, 10),
               (20, 'Tea Leaves', 'BAR20', 'SKU20', 50.0, 50.0, 30.0, 10)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, quantity, original_quantity, cost_price)
        VALUES (10, 10, 50.0, 50.0, 60.0),
               (20, 20, 50.0, 50.0, 30.0)");

    // Shifts:
    // Shift 1: Casey (closed earlier)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash, closed_at)
        VALUES (1, 3, 1, 'closed', 1000.0, CURRENT_TIMESTAMP)");
    // Shift 2: Dana (currently open, expected cash 1000.0)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash)
        VALUES (2, 4, 1, 'open', 1000.0)");
    // Shift 3: Evan (dual role, currently open, register 2)
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, status, opening_cash)
        VALUES (3, 5, 2, 'open', 1000.0)");

    // Sales by Casey (user 3) in Shift 1:
    // Sale 100: Coffee x 2 = 200.0 cash
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (100, 1, 3, 200.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (1001, 100, 10, 2, 100.0, 200.0)");

    // Sale 200: Tea x 2 = 100.0 cash
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (200, 1, 3, 100.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (2001, 200, 20, 2, 50.0, 100.0)");

    // Sale 300: Coffee x 1 = 100.0 cash
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (300, 1, 3, 100.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (3001, 300, 10, 1, 100.0, 100.0)");

    // Sale 400: Tea x 1 = 50.0 cash
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (400, 1, 3, 50.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (4001, 400, 20, 1, 50.0, 50.0)");

    // Sale 500: by Dana (user 4) - own sale
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (500, 2, 4, 100.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (5001, 500, 10, 1, 100.0, 100.0)");

    $shiftService = new CashierShiftService($pdo);
    $refundService = new CashRefundService($pdo, $shiftService);
    $exceptionService = new RefundExceptionService($pdo);

    // =========================================================================
    // 1. ACCESS APPROVAL ALONE
    // =========================================================================
    // Cashier Dana requests access to Casey's Sale 100.
    // Administrator Alice approves access.
    $token100 = $exceptionService->openSale(4, 'cashier', 100, 'admin_alice', 'Pass1234!', 'Casey sick');
    $check(is_string($token100) && strlen($token100) === 64, 'Stage 1: openSale returns 64-character hex access token');

    // Verify token record in database
    $tokenHash = hash('sha256', $token100);
    $tokenRow = $pdo->query("SELECT * FROM cash_refund_exception_access WHERE token_hash='{$tokenHash}'")->fetch(PDO::FETCH_ASSOC);
    $check($tokenRow !== false, 'Stage 1: access token row exists in DB');
    $check((int)$tokenRow['sale_id'] === 100, 'Stage 1: token bound to sale 100');
    $check((int)$tokenRow['cashier_id'] === 4, 'Stage 1: token bound to requesting cashier 4 (Dana)');
    $check((int)$tokenRow['shift_id'] === 2, 'Stage 1: token bound to Dana open shift 2');
    $check((int)$tokenRow['approved_by'] === 1, 'Stage 1: approved_by is Admin Alice (1)');
    $check($tokenRow['used_refund_id'] === null, 'Stage 1: token used_refund_id is initially NULL');
    $check((int)$tokenRow['expires_at'] > time() + 800, 'Stage 1: token expires in ~15 minutes');

    // Verify activity_log
    $logStmt = $pdo->prepare("SELECT * FROM activity_log WHERE action = 'Cash refund exception sale access' AND record_id = 100");
    $logStmt->execute();
    $log = $logStmt->fetch(PDO::FETCH_ASSOC);
    $check($log !== false, 'Stage 1: activity_log entry created for sale access');
    $check((int)$log['user_id'] === 1, 'Stage 1: activity_log records approver Alice as user_id');

    // Token enables sale inspection via refundableSaleForCashier
    $saleInspection = $refundService->refundableSaleForCashier(4, 100, $token100);
    $check($saleInspection !== null, 'Stage 1: refundableSaleForCashier returns sale details with valid access token');
    $check((float)$saleInspection['total_amount'] === 200.0, 'Stage 1: inspected sale total is 200.0');
    $check((float)$saleInspection['refundable_amount'] === 200.0, 'Stage 1: inspected sale refundable amount is 200.0');
    $check(count($saleInspection['items']) === 1, 'Stage 1: inspected sale has 1 item line');

    // Crucial check: Access approval alone has NOT created any refund or moved drawer cash
    $refundCount = (int)$pdo->query("SELECT COUNT(*) FROM cash_refunds")->fetchColumn();
    $check($refundCount === 0, 'Stage 1 alone: no refund records created in database');
    $danaShiftCash = $shiftService->calculateShift(2);
    $check((float)$danaShiftCash['calculated_expected_cash'] === 1100.0, 'Stage 1 alone: shift expected cash unmodified');

    // Routine access without token to another Cashier's sale returns null
    $unauthorizedInspection = $refundService->refundableSaleForCashier(4, 100);
    $check($unauthorizedInspection === null, 'Routine lookup without token returns null for other cashier sale');

    // Inspection with forged/invalid token throws DomainException
    $refused(fn() => $refundService->refundableSaleForCashier(4, 100, 'badtokenbadtokenbadtokenbadtokenbadtokenbadtokenbadtokenbadtoken'),
        'Inspection with invalid token is refused');

    // =========================================================================
    // 2. PAYMENT APPROVAL ALONE / REFUSAL OF DIRECT SUBMISSION
    // =========================================================================
    // Dana attempts to refund Casey's Sale 100 with NO token ($exception === null)
    $items100 = [1001 => ['quantity' => 1, 'disposition' => 'damaged']];
    $refused(fn() => $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'No token attempt'),
        'Direct refund submission on another cashier sale without token is refused');

    // Dana attempts to refund Casey's Sale 100 with token, but missing approver credentials
    $refused(fn() => $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'Missing creds', null, false, ['token' => $token100]),
        'Refund submission with token but empty credentials is refused');

    // Dana attempts to refund Casey's Sale 100 with token, but invalid password
    $refused(fn() => $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'Wrong pass', null, false, [
        'token' => $token100,
        'username' => 'admin_alice',
        'password' => 'WrongPassword!'
    ]), 'Refund submission with token but invalid password is refused');

    // Verify token was NOT consumed after failed payment attempts
    $tokenRowAfterRefusal = $pdo->query("SELECT * FROM cash_refund_exception_access WHERE token_hash='{$tokenHash}'")->fetch(PDO::FETCH_ASSOC);
    $check($tokenRowAfterRefusal['used_refund_id'] === null, 'Token remains unconsumed after payment refusal');
    $check((int)$pdo->query("SELECT COUNT(*) FROM cash_refunds")->fetchColumn() === 0, 'No refund posted after payment refusal');

    // =========================================================================
    // 3. DIFFERENT APPROVERS (Stage 1 Admin Alice, Stage 2 Admin Bob)
    // =========================================================================
    // Dana now submits payment approval with DIFFERENT administrator: Admin Bob (user 2) via email
    $refundId1 = $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'Approved by Bob', null, false, [
        'token' => $token100,
        'username' => 'bob@store.test',
        'password' => 'Pass1234!'
    ]);
    $check($refundId1 > 0, 'Stage 2: refund succeeds with DIFFERENT administrator (Bob) than stage 1 (Alice)');

    // Verify truthful attribution in cash_refunds and cash_refund_exception_access
    $refundRow1 = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = {$refundId1}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$refundRow1['cashier_id'] === 4, 'Refund row cashier_id is Dana (4)');
    $check((int)$refundRow1['original_cashier_id'] === 3, 'Refund row original_cashier_id is Casey (3)');
    $check((int)$refundRow1['approved_by'] === 2, 'Refund row approved_by is Admin Bob (2)');
    $check((int)$refundRow1['shift_id'] === 2, 'Refund row shift_id is Dana shift 2');
    $check((float)$refundRow1['refund_amount'] === 100.0, 'Refund amount is 100.0');

    // Verify access token is now consumed
    $tokenRowConsumed = $pdo->query("SELECT * FROM cash_refund_exception_access WHERE token_hash='{$tokenHash}'")->fetch(PDO::FETCH_ASSOC);
    $check((int)$tokenRowConsumed['used_refund_id'] === $refundId1, 'Access token used_refund_id is updated to refund ID');

    // Consumed token cannot be reused
    $refused(fn() => $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'Reuse token', null, false, [
        'token' => $token100,
        'username' => 'admin_bob',
        'password' => 'Pass1234!'
    ]), 'Consumed access token cannot be reused');

    // =========================================================================
    // 4. SAME APPROVER (Stage 1 Admin Alice, Stage 2 Admin Alice)
    // =========================================================================
    // Dana requests access to Casey's Sale 200, approved by Admin Alice
    $token200 = $exceptionService->openSale(4, 'cashier', 200, 'alice@store.test', 'Pass1234!', 'Casey still sick');
    $items200 = [2001 => ['quantity' => 1, 'disposition' => 'damaged']];

    // Dana submits payment approval with the SAME administrator: Admin Alice
    $refundId2 = $refundService->refund(4, 'cashier', 200, $items200, 'customer_return', 'Approved by Alice again', null, false, [
        'token' => $token200,
        'username' => 'admin_alice',
        'password' => 'Pass1234!'
    ]);
    $check($refundId2 > 0, 'Stage 2: refund succeeds with SAME administrator (Alice) for both stages');

    $refundRow2 = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = {$refundId2}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$refundRow2['approved_by'] === 1, 'Same approver: refund row approved_by is Alice (1)');
    $check((int)$refundRow2['cashier_id'] === 4, 'Same approver: cashier is Dana (4)');
    $check((int)$refundRow2['original_cashier_id'] === 3, 'Same approver: original cashier is Casey (3)');

    // =========================================================================
    // 5. DUAL-ROLE SELF-APPROVAL (Cashier + Admin)
    // =========================================================================
    // Evan (user 5) has open Shift 3. Primary role is Cashier, but assigned ordinary Admin in user_roles.
    // Casey made Sale 300 (Coffee x 1 = 100.0).

    // 5a. Dual-role self-approves BOTH Stage 1 and Stage 2
    $token300 = $exceptionService->openSale(5, 'cashier', 300, 'dual_evan', 'Pass1234!', 'Casey absent, self-approving access');
    $check(is_string($token300) && strlen($token300) === 64, 'Dual-role: self-approves stage 1 access');

    $token300Row = $pdo->query("SELECT * FROM cash_refund_exception_access WHERE token_hash='" . hash('sha256', $token300) . "'")->fetch(PDO::FETCH_ASSOC);
    $check((int)$token300Row['cashier_id'] === 5, 'Dual-role stage 1: cashier_id is 5');
    $check((int)$token300Row['approved_by'] === 5, 'Dual-role stage 1: approved_by is 5');

    $items300 = [3001 => ['quantity' => 1, 'disposition' => 'damaged']];
    $refundId3 = $refundService->refund(5, 'cashier', 300, $items300, 'customer_return', 'Self-approved refund payment', null, false, [
        'token' => $token300,
        'username' => 'evan@store.test', // Email login identifier test
        'password' => 'Pass1234!'
    ]);
    $check($refundId3 > 0, 'Dual-role: self-approves stage 2 payment');

    $refundRow3 = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = {$refundId3}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$refundRow3['cashier_id'] === 5, 'Truthful identity: cashier_id is Evan (5)');
    $check((int)$refundRow3['original_cashier_id'] === 3, 'Truthful identity: original_cashier_id is Casey (3)');
    $check((int)$refundRow3['approved_by'] === 5, 'Truthful identity: approved_by is Evan (5)');

    // 5b. Dual-role self-approves Stage 1, another Admin (Bob) approves Stage 2
    // Casey made Sale 400 (Tea x 1 = 50.0).
    $token400 = $exceptionService->openSale(5, 'cashier', 400, 'dual_evan', 'Pass1234!', 'Self-approved access');
    $items400 = [4001 => ['quantity' => 1, 'disposition' => 'damaged']];

    $refundId4 = $refundService->refund(5, 'cashier', 400, $items400, 'customer_return', 'Bob approved payment', null, false, [
        'token' => $token400,
        'username' => 'admin_bob',
        'password' => 'Pass1234!'
    ]);
    $check($refundId4 > 0, 'Dual-role self-approves stage 1, Admin Bob approves stage 2');
    $refundRow4 = $pdo->query("SELECT * FROM cash_refunds WHERE refund_id = {$refundId4}")->fetch(PDO::FETCH_ASSOC);
    $check((int)$refundRow4['cashier_id'] === 5, 'Cashier is Evan (5)');
    $check((int)$refundRow4['approved_by'] === 2, 'Approved by is Bob (2)');

    // =========================================================================
    // 6. EXPIRED ACCESS TOKENS
    // =========================================================================
    // Create new sale by Casey for testing expiration
    $pdo->exec("INSERT INTO sales (sale_id, shift_id, cashier_id, total_amount, payment_method)
        VALUES (600, 1, 3, 50.0, 'cash')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
        VALUES (6001, 600, 20, 1, 50.0, 50.0)");

    $token600 = $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'Pass1234!', 'Testing expiration');
    // Expire the token by setting expires_at to 10 seconds ago
    $pdo->exec("UPDATE cash_refund_exception_access SET expires_at = " . (time() - 10) . " WHERE sale_id = 600");

    // Inspection with expired token must be refused
    $refused(fn() => $refundService->refundableSaleForCashier(4, 600, $token600),
        'Inspection with expired token is refused');

    // Refund submission with expired token must be refused
    $items600 = [6001 => ['quantity' => 1, 'disposition' => 'damaged']];
    $refused(fn() => $refundService->refund(4, 'cashier', 600, $items600, 'customer_return', 'Expired attempt', null, false, [
        'token' => $token600,
        'username' => 'admin_alice',
        'password' => 'Pass1234!'
    ]), 'Refund submission with expired access token is refused');

    // =========================================================================
    // 7. INVALID CREDENTIALS & INELIGIBLE ACCOUNTS
    // =========================================================================
    // 7a. Stage 1 (openSale) invalid credential tests:
    // Non-existent user
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'nonexistent_user', 'Pass1234!', 'Reason'),
        'Stage 1: non-existent username is refused');
    // Wrong password
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'WrongPassword!', 'Reason'),
        'Stage 1: wrong password is refused');
    // Super Administrator alone (no ordinary admin assignment)
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'super_tech', 'Pass1234!', 'Reason'),
        'Stage 1: super administrator alone is rejected');
    // Cashier alone (no admin role)
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'cashier_casey', 'Pass1234!', 'Reason'),
        'Stage 1: cashier account alone is rejected');
    // Disabled Admin
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_disabled', 'Pass1234!', 'Reason'),
        'Stage 1: disabled admin is rejected');
    // Admin with must_change_password
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_change', 'Pass1234!', 'Reason'),
        'Stage 1: admin requiring password change is rejected');

    // 7b. Stage 2 (refund payment) invalid credential tests:
    // Generate fresh valid token for sale 600
    $token600Fresh = $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'Pass1234!', 'Fresh token');
    // Super Admin alone at Stage 2
    $refused(fn() => $refundService->refund(4, 'cashier', 600, $items600, 'customer_return', 'Super admin payment', null, false, [
        'token' => $token600Fresh,
        'username' => 'super_tech',
        'password' => 'Pass1234!'
    ]), 'Stage 2: super administrator alone is rejected');
    // Cashier alone at Stage 2
    $refused(fn() => $refundService->refund(4, 'cashier', 600, $items600, 'customer_return', 'Cashier payment', null, false, [
        'token' => $token600Fresh,
        'username' => 'cashier_casey',
        'password' => 'Pass1234!'
    ]), 'Stage 2: cashier alone is rejected');
    // Disabled admin at Stage 2
    $refused(fn() => $refundService->refund(4, 'cashier', 600, $items600, 'customer_return', 'Disabled admin payment', null, false, [
        'token' => $token600Fresh,
        'username' => 'admin_disabled',
        'password' => 'Pass1234!'
    ]), 'Stage 2: disabled admin is rejected');
    // Admin with must_change_password at Stage 2
    $refused(fn() => $refundService->refund(4, 'cashier', 600, $items600, 'customer_return', 'Must change pass payment', null, false, [
        'token' => $token600Fresh,
        'username' => 'admin_change',
        'password' => 'Pass1234!'
    ]), 'Stage 2: admin requiring password change is rejected');

    // =========================================================================
    // 8. SCOPE, SHIFT & OWN-SALE GUARDS
    // =========================================================================
    // 8a. Own-sale rejection: Dana cannot use openSale for her own sale 500
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 500, 'admin_alice', 'Pass1234!', 'My own sale'),
        'openSale refused for cashier own sale');

    // 8b. Shift binding: Token created for Dana (Shift 2) cannot be used by Evan (Shift 3)
    $refused(fn() => $refundService->refund(5, 'cashier', 600, $items600, 'customer_return', 'Cross-shift use', null, false, [
        'token' => $token600Fresh,
        'username' => 'admin_alice',
        'password' => 'Pass1234!'
    ]), 'Token bound to Dana Shift 2 cannot be used by Evan Shift 3');

    // 8c. Sale binding: Token created for Sale 600 cannot be used for Sale 100
    $refused(fn() => $refundService->refund(4, 'cashier', 100, $items100, 'customer_return', 'Cross-sale use', null, false, [
        'token' => $token600Fresh,
        'username' => 'admin_alice',
        'password' => 'Pass1234!'
    ]), 'Token bound to Sale 600 cannot be used to refund Sale 100');

    // 8d. Locked register guard
    $pdo->exec("UPDATE cashier_shifts SET locked_at = CURRENT_TIMESTAMP WHERE shift_id = 2");
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'Pass1234!', 'Locked register'),
        'openSale refused when register is locked');
    $pdo->exec("UPDATE cashier_shifts SET locked_at = NULL WHERE shift_id = 2");

    // 8e. Workspace guard: openSale requires 'cashier' workspace
    $refused(fn() => $exceptionService->openSale(4, 'admin', 600, 'admin_alice', 'Pass1234!', 'Admin workspace'),
        'openSale refused when workspace is not cashier');

    // 8f. Reason validation: blank or too long
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'Pass1234!', '   '),
        'openSale refused when reason is whitespace');
    $refused(fn() => $exceptionService->openSale(4, 'cashier', 600, 'admin_alice', 'Pass1234!', str_repeat('x', 256)),
        'openSale refused when reason exceeds 255 chars');

} catch (Throwable $e) {
    $failures[] = 'Unexpected error: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures !== []) {
    echo "Exceptional refund two-stage approval contract FAILED:\n- " . implode("\n- ", $failures) . "\n";
    exit(1);
}

echo "Exceptional refund two-stage approval contract: passed\n";
exit(0);
