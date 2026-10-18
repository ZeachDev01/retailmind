<?php
// Administrator dashboard transient server cache & event invalidation integration test.
// GitHub Issue #205 / ADR-0012 P3.
require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Dashboard/StoreOperationsDashboardWorkspace.php';
require_once __DIR__ . '/../app/Services/CashierShiftService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/StockIssueService.php';

use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\CashierShiftService;
use App\Services\CashRefundService;

final class TestCountingPdo extends PDO
{
    public int $queryCount = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queryCount++;
        return parent::prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->queryCount++;
        return parent::query(...func_get_args());
    }

    public function exec(string $statement): int|false
    {
        $this->queryCount++;
        return parent::exec($statement);
    }
}

final class MutableTestClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
    public function set(DateTimeImmutable $now): void { $this->now = $now; }
    public function advance(int $seconds): void { $this->now = $this->now->modify("+{$seconds} seconds"); }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    StoreOperationsDashboardWorkspace::invalidateCache();

    $pdo = new TestCountingPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => date('Y-m-d'), 0);
    $pdo->sqliteCreateFunction('GREATEST', static fn(...$vals) => max(...$vals));
    $pdo->sqliteCreateFunction('DATE_ADD', static fn(string $date, string $interval, int $days) => date('Y-m-d', strtotime("{$date} +{$days} days")), 3);

    $tables = [
        'CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))',
        'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, username TEXT, password_hash TEXT, role_id INTEGER, branch_id INTEGER, status TEXT, must_change_password INTEGER DEFAULT 0)',
        'CREATE TABLE registers (register_id INTEGER PRIMARY KEY, name TEXT, branch_id INTEGER, status TEXT DEFAULT "active")',
        'CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, shift_id INTEGER, gross_amount REAL, total_amount REAL, payment_method TEXT, discount_type TEXT, discount_value REAL, discount_amount REAL, sale_date TEXT)',
        'CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL)',
        'CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT)',
        'CREATE TABLE sale_item_batches (sale_item_batch_id INTEGER PRIMARY KEY, sale_item_id INTEGER, batch_id INTEGER, quantity INTEGER, unit_cost REAL, cost_status TEXT)',
        'CREATE TABLE product_batches (batch_id INTEGER PRIMARY KEY, product_id INTEGER, remaining_quantity INTEGER, cost_price REAL, expiration_date TEXT, date_received TEXT)',
        'CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, reversal_type TEXT, status TEXT, reason TEXT, settlement_method TEXT, refund_amount REAL, created_at TEXT)',
        'CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER, opening_cash REAL, expected_cash REAL, actual_cash REAL, cash_variance REAL, opened_at TEXT DEFAULT CURRENT_TIMESTAMP, status TEXT DEFAULT "open", locked_at TEXT, closed_at TEXT, closed_by INTEGER, closing_notes TEXT, variance_threshold REAL, variance_review_required INTEGER DEFAULT 0, payment_totals TEXT, intervention_reason TEXT, reviewed_at TEXT)',
        'CREATE TABLE cash_drawer_movements (movement_id INTEGER PRIMARY KEY, shift_id INTEGER, cashier_id INTEGER, movement_type TEXT, amount REAL, reason TEXT, note TEXT, recorded_by INTEGER, recorded_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, original_cashier_id INTEGER, approved_by INTEGER, exception_reason TEXT, payment_reference TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE cash_refund_items (refund_item_id INTEGER PRIMARY KEY, refund_id INTEGER, sale_item_id INTEGER, product_id INTEGER, quantity INTEGER, unit_price REAL, subtotal REAL, disposition TEXT)',
        'CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, barcode TEXT, branch_id INTEGER, category_id INTEGER DEFAULT 1, unit_price REAL, cost_price REAL, reorder_level INTEGER, quantity_sold INTEGER DEFAULT 0, status TEXT)',
        'CREATE TABLE inventory (product_id INTEGER PRIMARY KEY, quantity_on_hand INTEGER)',
        'CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_by INTEGER, shift_id INTEGER, reason TEXT, status TEXT, approved_by INTEGER, approved_at TEXT, review_notes TEXT, reported_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE stock_issue_revisions (revision_id INTEGER PRIMARY KEY, adjustment_id INTEGER, actor_id INTEGER, action TEXT, from_status TEXT, to_status TEXT, old_values TEXT, new_values TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_by INTEGER, adjustment_id INTEGER, moved_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE stock_issue_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, adjustment_id INTEGER NOT NULL, batch_id INTEGER NOT NULL, quantity INTEGER NOT NULL)',
        'CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT)',
        'CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT)',
        'CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT)',
        'CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL)',
        'CREATE TABLE activity_log (log_id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, category TEXT, module TEXT, record_id INTEGER, previous_value TEXT, new_value TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, message TEXT, link TEXT, is_read INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
        'CREATE TABLE notification_preferences (user_id INTEGER, notify_email INTEGER, notify_low_stock INTEGER, low_stock_threshold INTEGER, notify_expiring INTEGER, expiry_days_threshold INTEGER, notify_reports INTEGER)',
        'CREATE TABLE branches (branch_id INTEGER PRIMARY KEY, branch_name TEXT, branch_code TEXT, status TEXT DEFAULT "active")',
        'CREATE TABLE held_sales (held_sale_id INTEGER PRIMARY KEY, shift_id INTEGER, reference_no TEXT, customer_label TEXT, status TEXT, item_count INTEGER, total_amount REAL, created_at TEXT, expires_at TEXT)',
        'CREATE TABLE fiscal_period_locks (lock_id INTEGER PRIMARY KEY, period_id INTEGER, table_name TEXT)',
        'CREATE TABLE refund_receipt_details (refund_id INTEGER PRIMARY KEY, details_json TEXT)',
        'CREATE TABLE store_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)',
        'CREATE TABLE refund_exceptions (exception_id INTEGER PRIMARY KEY, access_token TEXT, sale_id INTEGER, cashier_id INTEGER, shift_id INTEGER, approved_by INTEGER, exception_reason TEXT, used_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)',
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    $password = password_hash('password123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Main Store', 'MAIN', 'active')");
    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager')");
    $pdo->exec("INSERT INTO users VALUES
        (1, 'Admin User', 'admin', '{$password}', 1, 1, 'active', 0),
        (2, 'Cashier One', 'cashier1', '{$password}', 2, 1, 'active', 0),
        (3, 'Inventory Manager', 'inventory1', '{$password}', 3, 1, 'active', 0)");
    $pdo->exec("INSERT INTO registers VALUES (1, 'Register 1', 1, 'active')");
    $pdo->exec("INSERT INTO categories VALUES (1, 'General')");
    $pdo->exec("INSERT INTO products VALUES (10, 'Coffee Beans', 'SKU-COF', '123456', 1, 1, 100, 50, 5, 10, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (10, 50)");
    $pdo->exec("INSERT INTO product_batches VALUES (1, 10, 50, 50, '2027-01-01', '2026-10-01')");
    $pdo->exec("INSERT INTO fiscal_periods VALUES (1, 'October 2026', '2026-10-01', '2026-10-31', 'open')");

    $initialTime = new DateTimeImmutable('2026-10-16 10:00:00');
    $clock = new MutableTestClock($initialTime);
    $policy = new RoleCapabilityPolicy();
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, $policy);
    $shiftService = new CashierShiftService($pdo, $policy);
    $refundService = new CashRefundService($pdo, $shiftService, $policy);
    $quietNotifications = new class($pdo) extends NotificationService {
        public function checkAndNotifyLowStock(): void {}
        public function checkAndNotifyExpiringStock(int $days = 30): void {}
    };
    $stockIssueService = new StockIssueService($pdo, $quietNotifications, 'inventory_manager');

    // 1. Initial Load: executes database queries and caches result
    $pdo->queryCount = 0;
    $start = microtime(true);
    $firstView = $workspace->load('admin', 30);
    $firstLatency = (microtime(true) - $start) * 1000;

    $assert($firstView['state'] === 'empty', 'Initial view should load cleanly');
    $assert($firstView['freshness']['refresh_seconds'] === StoreOperationsDashboardWorkspace::REFRESH_SECONDS, 'Refresh interval must be 300s');
    $assert($pdo->queryCount > 0, 'First load must execute database queries');
    $initialQueryCount = $pdo->queryCount;

    // 2. Repeat Load within 300 seconds: 0 database queries, <50ms latency
    $pdo->queryCount = 0;
    $start = microtime(true);
    $repeatView = $workspace->load('admin', 30);
    $repeatLatency = (microtime(true) - $start) * 1000;

    $assert($pdo->queryCount === 0, 'Repeat load within 300s must execute ZERO database aggregate queries (executed: ' . $pdo->queryCount . ')');
    $assert($repeatLatency < 50.0, "Repeat load must respond in <50ms (took {$repeatLatency}ms)");
    $assert($repeatView === $firstView, 'Repeat load must return exact cached metrics payload');

    // 3. Different range: cache key keyed by range (e.g. 7 vs 30)
    $pdo->queryCount = 0;
    $range7View = $workspace->load('admin', 7);
    $assert($pdo->queryCount > 0, 'Loading range 7 must be a cache miss when only range 30 was cached');
    $assert($range7View['range_days'] === 7, 'Range 7 view must have range_days = 7');

    $pdo->queryCount = 0;
    $repeatRange7 = $workspace->load('admin', 7);
    $assert($pdo->queryCount === 0, 'Repeat load of range 7 must execute zero queries');
    $assert($repeatRange7 === $range7View, 'Repeat range 7 must return cached range 7 data');

    // 4. Invalidation upon Cashier Shift close
    // Ensure 30-day view is primed in cache
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before shift close');

    // Open and close cashier shift
    $shiftId = $shiftService->openShift(2, 'cashier', 1, 500.0);
    $shiftService->closeShift(2, 500.0, 'Balanced shift');

    // Subsequent load must re-query database
    $pdo->queryCount = 0;
    $afterShiftClose = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Closing Cashier Shift must invalidate dashboard cache (queries executed: ' . $pdo->queryCount . ')');

    // 5. Invalidation upon Cash Refund
    // Prime cache again
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before cash refund');

    // Create a completed sale under a new shift
    $shiftId2 = $shiftService->openShift(2, 'cashier', 1, 500.0);
    $pdo->exec("INSERT INTO sales (sale_id, cashier_id, shift_id, total_amount, payment_method, discount_type, discount_value, discount_amount, sale_date)
                VALUES (1, 2, {$shiftId2}, 100.0, 'cash', 'none', 0, 0, '2026-10-16 10:30:00')");
    $pdo->exec("INSERT INTO sale_items (sale_item_id, sale_id, product_id, quantity, unit_price, subtotal)
                VALUES (1, 1, 10, 1, 100.0, 100.0)");
    $pdo->exec("INSERT INTO sale_item_batches (sale_item_batch_id, sale_item_id, batch_id, quantity)
                VALUES (1, 1, 1, 1)");

    // Issue cash refund
    $refundService->refund(2, 'cashier', 1, [1 => ['quantity' => 1, 'disposition' => 'restockable']], 'customer_return');

    // Subsequent load must re-query database
    $pdo->queryCount = 0;
    $afterRefund = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Recording Cash Refund must invalidate dashboard cache (queries executed: ' . $pdo->queryCount . ')');

    // 6. Invalidation upon Stock Issue decision (approve & reject)
    // Prime cache again
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before stock issue decision');

    // Cashier submits stock issue
    $reporter = new StockIssueService($pdo, $quietNotifications, 'cashier');
    $adjId = $reporter->submitReport(['product_id' => 10, 'category' => 'damaged', 'quantity' => 1, 'explanation' => 'Torn packaging on shelf'], 2);

    // Manager approves stock issue
    $stockIssueService->approveReport($adjId, 3, 'Approved replacement');

    // Subsequent load must re-query database
    $pdo->queryCount = 0;
    $afterApprove = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Approving Stock Issue must invalidate dashboard cache (queries executed: ' . $pdo->queryCount . ')');

    // Prime cache again
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before stock issue reject');

    // Cashier submits another stock issue
    $adjId2 = $reporter->submitReport(['product_id' => 10, 'category' => 'missing', 'quantity' => 1, 'explanation' => 'Count discrepancy in aisle'], 2);

    // Manager rejects stock issue
    $stockIssueService->rejectReport($adjId2, 3, 'Found behind shelf');

    // Subsequent load must re-query database
    $pdo->queryCount = 0;
    $afterReject = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Rejecting Stock Issue must invalidate dashboard cache (queries executed: ' . $pdo->queryCount . ')');

    // Also test decide() method
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before decide()');

    $adjId3 = $reporter->submitReport(['product_id' => 10, 'category' => 'expired', 'quantity' => 1, 'explanation' => 'Past best before date'], 2);
    $stockIssueService->decide($adjId3, 3, 'approved', 'Disposed safely');

    $pdo->queryCount = 0;
    $afterDecide = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'decide() must invalidate dashboard cache');

    // 7. 300-second expiration: advance clock past 300s
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, '30-day view must be cached before time advance');

    // Advance clock by 301 seconds
    $clock->advance(301);

    $pdo->queryCount = 0;
    $afterTtl = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Advancing clock past 300s must trigger cache refresh (queries executed: ' . $pdo->queryCount . ')');

    // 8. Stale prior-day calendar data cannot masquerade as Today (Issue #209 / Acceptance Criteria 6)
    // Prime cache near midnight: 23:59:00
    $clock->set(new DateTimeImmutable('2026-10-16 23:59:00'));
    $pdo->queryCount = 0;
    $nearMidnight = $workspace->load('admin', 30);
    $assert($nearMidnight['net_sales']['as_of'] === '2026-10-16 23:59:00', 'As-of time shares freshness time');
    $assert($nearMidnight['net_sales']['as_of'] === $nearMidnight['freshness']['generated_at'], 'Cached totals and chart points share as-of time');

    // Advance 120 seconds into next day: 00:01:00 (120s < 300s TTL)
    $clock->advance(120);
    $pdo->queryCount = 0;
    $afterMidnight = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Crossing calendar midnight must invalidate cache even if under 300s TTL (queries executed: ' . $pdo->queryCount . ')');
    $assert($afterMidnight['net_sales']['as_of'] === '2026-10-17 00:01:00', 'New calendar day must generate new Today payload');

    // 9. Older cached payload without net_sales cannot break new view
    $clock->set(new DateTimeImmutable('2026-10-17 10:00:00'));
    // Prime cache with legacy payload missing net_sales
    $legacyEntry = [
        'timestamp' => $clock->now()->getTimestamp(),
        'data' => [
            'role' => 'admin',
            'range_days' => 30,
            'state' => 'ready',
            'headline' => [],
            'exceptions' => [],
            'comparison' => [],
            'staff' => [],
            'shifts' => [],
            'forecast' => [],
            'inventory' => [],
            'actions' => [],
            'freshness' => ['generated_at' => '2026-10-17 10:00:00', 'stale_after' => '2026-10-17 10:05:00', 'refresh_seconds' => 300, 'is_stale' => false],
            'message' => null,
            // net_sales missing
        ],
    ];
    // Put legacy entry directly into file cache
    $prefix = substr(md5(dirname(__DIR__, 2) . '/app/Dashboard'), 0, 8);
    $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'retailmind_' . $prefix . '_dashboard_admin_30.json';
    file_put_contents($cacheFile, json_encode($legacyEntry));
    StoreOperationsDashboardWorkspace::invalidateCache('cashier', 30); // invalidate only cashier to leave file intact

    $pdo->queryCount = 0;
    $recoveredView = $workspace->load('admin', 30);
    $assert(isset($recoveredView['net_sales']), 'Older cached payload without net_sales must trigger refresh with net_sales');
    $assert($recoveredView['net_sales']['status'] === 'ongoing', 'Recovered view has ongoing status');

    // 10. Incompatible older #209 cache payload (has net_sales but lacks week/month periods)
    $clock->set(new DateTimeImmutable('2026-10-17 11:00:00'));
    $payload209 = [
        'timestamp' => $clock->now()->getTimestamp(),
        'data' => [
            'role' => 'admin',
            'range_days' => 30,
            'state' => 'ready',
            'headline' => [],
            'exceptions' => [],
            'comparison' => [],
            'staff' => [],
            'shifts' => [],
            'forecast' => [],
            'inventory' => [],
            'actions' => [],
            'freshness' => ['generated_at' => '2026-10-17 11:00:00', 'stale_after' => '2026-10-17 11:05:00', 'refresh_seconds' => 300, 'is_stale' => false],
            'message' => null,
            'net_sales' => [
                'period' => 'today',
                'status' => 'ongoing',
                'as_of' => '2026-10-17 11:00:00',
                'timezone' => 'Asia/Manila',
                'total' => 100.0,
                // periods missing!
            ],
        ],
    ];
    file_put_contents($cacheFile, json_encode($payload209));
    StoreOperationsDashboardWorkspace::invalidateCache('cashier', 30);

    $pdo->queryCount = 0;
    $reloadedView = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Incompatible older payload missing week/month periods must force reload');
    $assert(isset($reloadedView['net_sales']['periods']['week']), 'Reloaded view must include week period');
    $assert(isset($reloadedView['net_sales']['periods']['month']), 'Reloaded view must include month period');

    // 11. Week rollover invalidation: Sunday night -> Monday
    $clock->set(new DateTimeImmutable('2026-10-18 23:59:00')); // Sunday
    $pdo->queryCount = 0;
    $sundayView = $workspace->load('admin', 30);
    $assert($sundayView['net_sales']['as_of'] === '2026-10-18 23:59:00', 'Sunday cache primed');

    $clock->advance(120); // Advance 120s into Monday 00:01:00
    $pdo->queryCount = 0;
    $mondayRolloverView = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Crossing into new week (Monday) must invalidate cache');
    $assert(count($mondayRolloverView['net_sales']['periods']['week']['points']) === 1, 'Monday rollover has 1 daily bucket');

    // 12. Month rollover invalidation: Month end -> 1st of month
    $clock->set(new DateTimeImmutable('2026-10-31 23:59:00')); // Saturday Oct 31
    $pdo->queryCount = 0;
    $octEndView = $workspace->load('admin', 30);
    $assert($octEndView['net_sales']['as_of'] === '2026-10-31 23:59:00', 'Month end cache primed');

    $clock->advance(120); // Advance 120s into Nov 1 00:01:00
    $pdo->queryCount = 0;
    $novRolloverView = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Crossing into new month must invalidate cache');
    $assert(count($novRolloverView['net_sales']['periods']['month']['points']) === 1, 'New month rollover has 1 daily bucket');

    // 13. Gross profit admin payload cached & validated
    $clock->set(new DateTimeImmutable('2026-10-17 14:00:00'));
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->queryCount = 0;
    $adminView = $workspace->load('admin', 30);
    $assert(isset($adminView['gross_profit']), 'Admin view must have gross_profit');
    $assert(isset($adminView['gross_profit']['periods']['today']), 'Admin view must have gross_profit today period');
    $assert(isset($adminView['gross_profit']['periods']['week']), 'Admin view must have gross_profit week period');
    $assert(isset($adminView['gross_profit']['periods']['month']), 'Admin view must have gross_profit month period');

    // Repeat load: cache hit with 0 queries
    $pdo->queryCount = 0;
    $repeatAdminView = $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, 'Repeat load must return cached gross_profit with 0 queries');
    $assert($repeatAdminView['gross_profit'] === $adminView['gross_profit'], 'Cached gross_profit matches exactly');

    // 14. Older cached payload missing gross_profit forces reload for admin
    StoreOperationsDashboardWorkspace::invalidateCache();
    $payloadNoGp = [
        'timestamp' => $clock->now()->getTimestamp(),
        'data' => [
            'role' => 'admin',
            'range_days' => 30,
            'state' => 'ready',
            'headline' => [],
            'exceptions' => [],
            'comparison' => [],
            'staff' => [],
            'shifts' => [],
            'forecast' => [],
            'inventory' => [],
            'actions' => [],
            'freshness' => ['generated_at' => '2026-10-17 14:00:00', 'stale_after' => '2026-10-17 14:05:00', 'refresh_seconds' => 300, 'is_stale' => false],
            'message' => null,
            'net_sales' => $adminView['net_sales'],
        ],
    ];
    file_put_contents($cacheFile, json_encode($payloadNoGp));

    $pdo->queryCount = 0;
    $reloadedAdminView = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Cached payload missing gross_profit must force database reload for admin');
    $assert(isset($reloadedAdminView['gross_profit']), 'Reloaded payload must include gross_profit');

    // 15. Invalidation on sale and refund
    $pdo->queryCount = 0;
    $workspace->load('admin', 30);
    $assert($pdo->queryCount === 0, 'Cache is primed before sale invalidation');

    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->queryCount = 0;
    $afterSaleView = $workspace->load('admin', 30);
    $assert($pdo->queryCount > 0, 'Cache invalidation on sale/refund must trigger database reload');

} catch (Throwable $e) {
    $failures[] = 'Administrator dashboard cache integration test threw: ' . $e->getMessage() . "\n" . $e->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Administrator dashboard cache tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Administrator dashboard cache tests: passed\n";
