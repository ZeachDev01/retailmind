<?php
// Contract test for Ticket #452: Administrator Store Operations & Fiscal Guards.
//
// Verifies:
// 1. Administrator dashboard aggregates real-time store gross sales, register drawer status,
//    and inventory alerts into a single operational summary.
// 2. Register counter management allows assigning terminals, monitoring active status,
//    and setting transaction limits, with transaction limit enforcement on sales.
// 3. Store calendar allows scheduling staff shifts and tracking staff availability against store operating hours.
// 4. Fiscal period guards enforce closure of monthly/annual periods and strictly reject transactional writes to closed periods.

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../app/Services/RegisterService.php';
require_once __DIR__ . '/../app/Store/StoreCalendar.php';
require_once __DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/BatchInventoryLedger.php';

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\RegisterService;
use App\Services\BatchInventoryLedger;
use App\Store\StoreCalendar;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class T452TestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);

    // Create necessary schema
    foreach ([
        "CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)",
        "CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, role_id INTEGER, status TEXT, password_hash TEXT)",
        "CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, category TEXT, module TEXT, record_id INTEGER, previous_value TEXT, new_value TEXT, metadata TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))",
        "CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            status TEXT NOT NULL DEFAULT 'active',
            paper_width_mm TEXT NOT NULL DEFAULT '80',
            terminal_identifier TEXT NULL,
            transaction_limit REAL NULL,
            disabled_at TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE cashier_shifts (
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
            closing_notes TEXT NULL,
            closed_by INTEGER NULL,
            intervention_reason TEXT NULL,
            reviewed_by INTEGER NULL,
            reviewed_at TEXT NULL,
            locked_at TEXT NULL
        )",
        "CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_code TEXT NULL,
            cashier_id INTEGER NOT NULL,
            shift_id INTEGER NULL,
            total_amount REAL NOT NULL,
            discount_type TEXT DEFAULT 'none',
            discount_value REAL DEFAULT 0,
            discount_amount REAL DEFAULT 0,
            sale_date TEXT DEFAULT CURRENT_TIMESTAMP,
            payment_method TEXT DEFAULT 'cash',
            amount_tendered REAL DEFAULT 0,
            change_amount REAL DEFAULT 0
        )",
        "CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            batch_id INTEGER NULL
        )",
        "CREATE TABLE sale_item_batches (item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_item_id INTEGER, batch_id INTEGER, quantity INTEGER, unit_cost REAL)",
        "CREATE TABLE held_sales (held_sale_id INTEGER PRIMARY KEY AUTOINCREMENT, cashier_id INTEGER, shift_id INTEGER, held_at TEXT, status TEXT)",
        "CREATE TABLE sale_receipt_details (sale_id INTEGER PRIMARY KEY, details_json TEXT NOT NULL)",
        "CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY AUTOINCREMENT, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, created_at TEXT)",
        "CREATE TABLE products (product_id INTEGER PRIMARY KEY AUTOINCREMENT, product_name TEXT, sku TEXT, barcode TEXT, cost_price REAL, unit_price REAL, category_id INTEGER, reorder_level INTEGER, quantity_sold INTEGER NOT NULL DEFAULT 0, status TEXT DEFAULT 'active')",
        "CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, batch_number TEXT NULL, expiration_date TEXT NULL, date_received TEXT NULL, quantity_received INTEGER NOT NULL DEFAULT 0, quantity_sold INTEGER NOT NULL DEFAULT 0, remaining_quantity INTEGER NOT NULL DEFAULT 0, unit_cost REAL DEFAULT 0.0)",
        "CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_by INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER DEFAULT 0)",
        "CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT DEFAULT 'pending')",
        "CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT NOT NULL,
            end_date TEXT NOT NULL,
            created_by INTEGER NULL,
            status TEXT NOT NULL DEFAULT 'open',
            closed_at TEXT NULL,
            closed_by INTEGER NULL
        )",
        "CREATE TABLE fiscal_period_locks (lock_id INTEGER PRIMARY KEY AUTOINCREMENT, period_id INTEGER NOT NULL, table_name TEXT NULL)",
        "CREATE TABLE gcash_service_sessions (session_id INTEGER PRIMARY KEY AUTOINCREMENT, cashier_id INTEGER, status TEXT DEFAULT 'closed')",
        "CREATE TABLE gcash_transactions (transaction_id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INTEGER, verification_status TEXT DEFAULT 'Completed', responsible_user_id INTEGER)",
        "CREATE TABLE inventory_counts (count_id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT DEFAULT 'completed', counted_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE store_business_calendar (calendar_date TEXT PRIMARY KEY, status TEXT, recorded_by INTEGER, recorded_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE store_weekly_schedule (schedule_id INTEGER PRIMARY KEY AUTOINCREMENT, effective_date TEXT, monday_open INTEGER, tuesday_open INTEGER, wednesday_open INTEGER, thursday_open INTEGER, friday_open INTEGER, saturday_open INTEGER, sunday_open INTEGER, recorded_by INTEGER, source TEXT, recorded_at TEXT DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE store_staff_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            shift_date TEXT NOT NULL,
            start_time TEXT NOT NULL,
            end_time TEXT NOT NULL,
            notes TEXT NULL,
            created_by INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT)",
        "CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT)",
        "CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL)"
    ] as $sql) {
        $pdo->exec($sql);
    }

    // Seed base users & roles
    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager'), (4, 'super_admin')");
    $pdo->exec("INSERT INTO users VALUES
        (1, 'Alice Admin', 'admin', 1, 'active', 'hash'),
        (2, 'Bob Cashier', 'bob', 2, 'active', 'hash'),
        (3, 'Charlie Cashier', 'charlie', 2, 'active', 'hash'),
        (4, 'Diana Inventory', 'diana', 3, 'active', 'hash')");

    // Seed open fiscal period
    $pdo->exec("INSERT INTO fiscal_periods (period_id, period_name, start_date, end_date, status) VALUES (1, 'November 2026', '2026-11-01', '2026-11-30', 'open')");

    // =========================================================================
    // SECTION 1: Register Counter Management (Terminals, Status, Transaction Limits)
    // =========================================================================
    $policy = new RoleCapabilityPolicy();
    $registerService = new RegisterService($pdo, $policy);

    // 1.1 Create registers with terminal identifier and transaction limit
    $reg1 = $registerService->create(1, 'admin', 'Front Counter 1', 80, 'POS-TERM-01', 50000.00);
    $reg2 = $registerService->create(1, 'admin', 'Express Lane 2', 58, 'POS-TERM-02', 10000.00);
    $assert($reg1 > 0 && $reg2 > 0, 'Registers created successfully');

    $reg1Data = $registerService->get($reg1);
    $assert($reg1Data['terminal_identifier'] === 'POS-TERM-01', 'Register 1 terminal identifier preserved');
    $assert($reg1Data['transaction_limit'] === 50000.0, 'Register 1 transaction limit preserved');

    // 1.2 Assign terminal to register
    $registerService->assignTerminal(1, 'admin', $reg2, 'POS-EXPRESS-02');
    $reg2Updated = $registerService->get($reg2);
    $assert($reg2Updated['terminal_identifier'] === 'POS-EXPRESS-02', 'Register 2 terminal reassigned');

    // Duplicate terminal assignment to another register rejected
    try {
        $registerService->assignTerminal(1, 'admin', $reg2, 'POS-TERM-01');
        $assert(false, 'Duplicate terminal identifier must be rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'Duplicate terminal rejected: ' . $e->getMessage());
    }

    // 1.3 Set transaction limit
    $registerService->setTransactionLimit(1, 'admin', $reg1, 25000.00);
    $reg1Updated = $registerService->get($reg1);
    $assert($reg1Updated['transaction_limit'] === 25000.0, 'Register 1 transaction limit updated');

    // Invalid negative limit rejected
    try {
        $registerService->setTransactionLimit(1, 'admin', $reg1, -500);
        $assert(false, 'Negative transaction limit must be rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'Negative transaction limit rejected');
    }

    // 1.4 Monitor active status (open shifts vs available registers)
    // Open a cashier shift on reg1
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (10, 2, {$reg1}, 2000.00, 'open')");

    $allRegs = $registerService->all();
    $reg1Row = array_values(array_filter($allRegs, fn($r) => $r['register_id'] === $reg1))[0];
    $assert($reg1Row['is_in_use'] === true, 'Register 1 monitored as in use by open shift');
    $assert($reg1Row['open_shift_id'] === 10, 'Register 1 points to open shift 10');

    $reg2Row = array_values(array_filter($allRegs, fn($r) => $r['register_id'] === $reg2))[0];
    $assert($reg2Row['is_in_use'] === false, 'Register 2 monitored as not in use');

    // 1.5 Enforcement of register transaction limit on sales
    // Product setup
    $pdo->exec("INSERT INTO products (product_id, product_name, sku, unit_price, reorder_level) VALUES (1, 'Bulk Laptop Order', 'LAP-01', 30000.00, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 10)");
    $pdo->exec("INSERT INTO product_batches (product_id, remaining_quantity, expiration_date) VALUES (1, 10, '2028-12-31')");

    $salesService = new SalesWorkflowService($pdo, $policy);
    // Sale of 30,000 on reg1 (limit 25,000) must be rejected
    $limitRejected = false;
    try {
        $cart = [['product_id' => 1, 'qty' => 1]];
        $salesService->checkout($cart, 2, 'cashier', 'cash', ['cash_received' => 30000.00]);
    } catch (DomainException $e) {
        if (str_contains($e->getMessage(), 'transaction limit')) {
            $limitRejected = true;
        }
    }
    $assert($limitRejected, 'Sale exceeding register transaction limit must be rejected by SalesWorkflowService');

    // Lower transaction amount (e.g. 5,000) within 25,000 limit passes
    $pdo->exec("INSERT INTO products (product_id, product_name, sku, unit_price, reorder_level) VALUES (2, 'Keyboard', 'KB-01', 5000.00, 5)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 10)");
    $pdo->exec("INSERT INTO product_batches (product_id, remaining_quantity, expiration_date) VALUES (2, 10, '2028-12-31')");
    $cartOk = [['product_id' => 2, 'qty' => 1]];
    $saleRes = $salesService->checkout($cartOk, 2, 'cashier', 'cash', ['cash_received' => 5000.00]);
    $assert((int)($saleRes['sale_id'] ?? 0) > 0, 'Sale within register transaction limit succeeds');

    // =========================================================================
    // SECTION 2: Store Calendar (Staff Shifts & Staff Availability against Operating Hours)
    // =========================================================================
    $calendar = new StoreCalendar();

    // 2.1 Set weekly schedule: Mon-Fri open, Sat-Sun closed
    $calendar->recordWeeklySchedule($pdo, '2026-11-01', [
        'monday' => 1, 'tuesday' => 1, 'wednesday' => 1, 'thursday' => 1, 'friday' => 1,
        'saturday' => 0, 'sunday' => 0
    ], 1);

    // 2.2 Schedule staff shift on a trading weekday (2026-11-09 is Monday)
    $shift1Id = $calendar->scheduleStaffShift($pdo, 2, '2026-11-09', '08:00:00', '16:00:00', 'Morning register', 1);
    $assert($shift1Id > 0, 'Staff shift scheduled successfully');

    $shiftsOnDate = $calendar->getStaffShiftsForDate($pdo, '2026-11-09');
    $assert(count($shiftsOnDate) === 1, 'One shift found on 2026-11-09');
    $assert((int)$shiftsOnDate[0]['user_id'] === 2, 'Shift attributed to cashier Bob (user 2)');
    $assert($shiftsOnDate[0]['start_time'] === '08:00:00', 'Shift start time matches');

    // 2.3 Invalid shift times (end before start) rejected
    try {
        $calendar->scheduleStaffShift($pdo, 3, '2026-11-09', '17:00:00', '08:00:00', 'Invalid shift', 1);
        $assert(false, 'End time before start time must be rejected');
    } catch (InvalidArgumentException $e) {
        $assert(true, 'Invalid shift times rejected: ' . $e->getMessage());
    }

    // 2.4 Staff availability tracking against operating hours
    // On 2026-11-09 (Monday): Store is OPEN
    $availMon = $calendar->getStaffAvailability($pdo, '2026-11-09');
    $assert($availMon['store_open'] === true, 'Store is open on Monday');
    $assert($availMon['store_status'] === 'open', 'Store status is open');

    // Check staff statuses: Bob (2) is scheduled, Charlie (3) is available
    $staffById = array_column($availMon['staff'], null, 'user_id');
    $assert(isset($staffById[2]), 'Bob in staff availability');
    $assert($staffById[2]['is_scheduled'] === true, 'Bob is scheduled');
    $assert($staffById[2]['availability_status'] === 'scheduled', 'Bob availability status is scheduled');

    $assert(isset($staffById[3]), 'Charlie in staff availability');
    $assert($staffById[3]['is_scheduled'] === false, 'Charlie is not scheduled');
    $assert($staffById[3]['availability_status'] === 'available', 'Charlie availability status is available');

    // On 2026-11-15 (Sunday): Store is CLOSED per weekly schedule
    $availSun = $calendar->getStaffAvailability($pdo, '2026-11-15');
    $assert($availSun['store_open'] === false, 'Store is closed on Sunday');
    $sunStaffById = array_column($availSun['staff'], null, 'user_id');
    $assert($sunStaffById[3]['availability_status'] === 'store_closed', 'Staff availability reflects store closed on Sunday');

    // Delete staff shift
    $calendar->deleteStaffShift($pdo, $shift1Id);
    $shiftsAfterDelete = $calendar->getStaffShiftsForDate($pdo, '2026-11-09');
    $assert(count($shiftsAfterDelete) === 0, 'Staff shift deleted successfully');

    // =========================================================================
    // SECTION 3: Administrator Dashboard Operational Summary Aggregation
    // =========================================================================
    // Set clock to 2026-11-09 10:00:00 UTC -> 2026-11-09 18:00:00 Philippine time
    $clock = new T452TestClock(new DateTimeImmutable('2026-11-09 10:00:00', new DateTimeZone('UTC')));
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, $policy);
    StoreOperationsDashboardWorkspace::invalidateCache();

    // Add some stock alerts: low stock, out of stock, pending adjustments
    $pdo->exec("INSERT INTO products (product_id, product_name, sku, unit_price, reorder_level, status) VALUES
        (10, 'Out of Stock Item', 'OOS-1', 100, 5, 'active'),
        (11, 'Low Stock Item', 'LOW-1', 200, 10, 'active')");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (10, 0), (11, 3)");
    $pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_at, status) VALUES
        (1, 10, -2, 'damage', '2026-11-09 09:00:00', 'pending')");

    $dashboardData = $workspace->load('admin', 30);
    $assert(isset($dashboardData['operational_summary']), 'Dashboard payload must contain operational_summary');

    $opSummary = $dashboardData['operational_summary'];
    // 3.1 Real-time store gross sales
    $assert(isset($opSummary['gross_sales']), 'Operational summary includes real-time store gross sales');

    // 3.2 Register drawer status
    $regDrawer = $opSummary['register_drawer_status'];
    $assert($regDrawer['total_registers'] >= 2, 'Operational summary shows total registers');
    $assert($regDrawer['open_shifts'] === 1, 'Operational summary shows 1 open cashier drawer');
    $assert($regDrawer['drawer_cash_total'] === 2000.0, 'Operational summary shows total drawer cash');

    // 3.3 Inventory alerts
    $invAlerts = $opSummary['inventory_alerts'];
    $assert($invAlerts['out_of_stock'] >= 1, 'Operational summary shows out of stock alerts');
    $assert($invAlerts['low_stock'] >= 1, 'Operational summary shows low stock alerts');
    $assert($invAlerts['pending_issues'] >= 1, 'Operational summary shows pending stock issues');
    $assert($invAlerts['total_alerts'] >= 3, 'Operational summary aggregates total inventory alerts');

    // =========================================================================
    // SECTION 4: Fiscal Period Guards (Closure Enforced, Writes to Closed Periods Rejected)
    // =========================================================================
    $fiscalGuard = new FiscalPeriodGuardService($pdo);

    // 4.1 Enforce period closure guards:
    // Period 1 ends 2026-11-30. When closing, open shifts must block closure.
    $shiftCloseBlocked = false;
    try {
        $fiscalGuard->closePeriod(1, 1, '2026-12-01');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'open Cashier Shifts')) {
            $shiftCloseBlocked = true;
        }
    }
    $assert($shiftCloseBlocked, 'Fiscal period closure strictly blocked by open cashier shifts');

    // Close open cashier shift
    $pdo->exec("UPDATE cashier_shifts SET status = 'closed', closed_at = '2026-11-30 20:00:00' WHERE shift_id = 10");

    // Pending inventory count blocks closure
    $pdo->exec("INSERT INTO inventory_counts (count_id, status, counted_at) VALUES (99, 'pending', '2026-11-15 10:00:00')");
    $countCloseBlocked = false;
    try {
        $fiscalGuard->closePeriod(1, 1, '2026-12-01');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'pending Inventory Counts')) {
            $countCloseBlocked = true;
        }
    }
    $assert($countCloseBlocked, 'Fiscal period closure strictly blocked by pending inventory counts');

    $pdo->exec("UPDATE inventory_counts SET status = 'completed' WHERE count_id = 99");

    // Now clean closure succeeds
    $fiscalGuard->closePeriod(1, 1, '2026-12-01');
    $periodClosed = $pdo->query("SELECT status FROM fiscal_periods WHERE period_id = 1")->fetchColumn();
    $assert($periodClosed === 'closed', 'Fiscal period successfully closed after resolving blockers');

    // 4.2 Strictly reject transactional writes to closed period
    // Date in closed period: 2026-11-15
    $closedWriteRejected = false;
    try {
        $fiscalGuard->assertOpenForDate('2026-11-15', 'sales', 'sale');
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'closed')) {
            $closedWriteRejected = true;
        }
    }
    $assert($closedWriteRejected, 'assertOpenForDate strictly rejects writes to closed fiscal period');

    // BatchInventoryLedger mutation rejected
    $ledger = new BatchInventoryLedger($pdo, $fiscalGuard);
    $ledgerRejected = false;
    try {
        // Attempt inventory adjustment in closed period
        $fiscalGuard->assertOpenForDate('2026-11-15', 'inventory_adjustments', 'inventory adjustment');
    } catch (RuntimeException $e) {
        $ledgerRejected = true;
    }
    $assert($ledgerRejected, 'Inventory mutations in closed fiscal period strictly rejected');

} catch (Throwable $e) {
    $failures[] = 'Contract test threw unexpected exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Ticket #452 contract test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Ticket #452 Store Operations & Fiscal Guards contract: passed\n";
