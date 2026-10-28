<?php
// Contract for Store Overview attention alignment (Ticket #330 / Spec #326).
require_once __DIR__ . '/../bootstrap/app.php';

use App\Attention\AttentionItem;
use App\Attention\AttentionNotificationService;
use App\Attention\AttentionRuleService;
use App\Attention\AttentionSettingsService;
use App\Attention\Clock;
use App\Attention\DatabaseAttentionSignalSource;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

final class StoreOverviewAttentionClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
}

try {
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    foreach ([
        'CREATE TABLE attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY (setting_scope, setting_key))',
        'CREATE TABLE attention_states (user_id INTEGER NOT NULL, attention_key VARCHAR(120) NOT NULL, fingerprint VARCHAR(64) NOT NULL, notification_level INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL, first_detected_at DATETIME NOT NULL, last_detected_at DATETIME NOT NULL, resolved_at DATETIME NULL, PRIMARY KEY (user_id, attention_key))',
        'CREATE TABLE notifications (notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, workspace TEXT NOT NULL DEFAULT "", inapp_enabled INTEGER NOT NULL DEFAULT 1, email_pending INTEGER NOT NULL DEFAULT 0, type VARCHAR(50) NOT NULL, title VARCHAR(255) NOT NULL, message TEXT NOT NULL, reference_id INTEGER NULL, reference_type VARCHAR(100) NULL, attention_key VARCHAR(120) NULL, attention_severity VARCHAR(20) NULL, attention_count INTEGER NULL, attention_destination VARCHAR(255) NULL, is_read INTEGER NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)',
        'CREATE TABLE roles (role_id INTEGER PRIMARY KEY, role_name TEXT)',
        'CREATE TABLE users (user_id INTEGER PRIMARY KEY, full_name TEXT, role_id INTEGER, status TEXT)',
        'CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, cashier_id INTEGER, total_amount REAL, discount_type TEXT, discount_value REAL, discount_amount REAL, sale_date TEXT)',
        'CREATE TABLE sale_reversals (reversal_id INTEGER PRIMARY KEY, sale_id INTEGER, reversal_type TEXT, status TEXT, reason TEXT, created_at TEXT)',
        'CREATE TABLE cashier_shifts (shift_id INTEGER PRIMARY KEY, cashier_id INTEGER, register_id INTEGER, opened_at TEXT, status TEXT, closed_at TEXT, cash_variance REAL, variance_threshold REAL, variance_review_required INTEGER, closing_notes TEXT, reviewed_at TEXT, reviewed_by INTEGER, investigation_notes TEXT)',
        'CREATE TABLE products (product_id INTEGER PRIMARY KEY, product_name TEXT, sku TEXT, cost_price REAL, reorder_level INTEGER, status TEXT)',
        'CREATE TABLE inventory (product_id INTEGER, quantity_on_hand INTEGER)',
        'CREATE TABLE inventory_adjustments (adjustment_id INTEGER PRIMARY KEY, product_id INTEGER, adjustment_qty INTEGER, adjustment_type TEXT, reported_at TEXT, status TEXT)',
        'CREATE TABLE fiscal_periods (period_id INTEGER PRIMARY KEY, period_name TEXT, start_date TEXT, end_date TEXT, status TEXT)',
        'CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, completed_at TEXT)',
        'CREATE TABLE demand_forecasts (product_id INTEGER, job_id INTEGER, readiness_level TEXT)',
        'CREATE TABLE ml_model_evaluations (evaluation_id INTEGER PRIMARY KEY, horizon_days INTEGER, mae REAL, rmse REAL, wape REAL)',
        'CREATE TABLE cash_refunds (refund_id INTEGER PRIMARY KEY, sale_id INTEGER, shift_id INTEGER, cashier_id INTEGER, refund_amount REAL, payment_method TEXT, reason TEXT, note TEXT, created_at TEXT)',
        'CREATE TABLE gcash_transactions (transaction_id INTEGER PRIMARY KEY, cashier_id INTEGER, session_id INTEGER, shift_id INTEGER, transaction_type TEXT, amount REAL, gross_fee REAL, net_amount REAL, fee_method TEXT, verification_status TEXT, reference_number TEXT, notes TEXT, created_at TEXT, service_cash_delta REAL DEFAULT 0, wallet_delta REAL DEFAULT 0, is_legacy INTEGER DEFAULT 0)',
        'CREATE TABLE gcash_service_sessions (session_id INTEGER PRIMARY KEY, shift_id INTEGER, cashier_id INTEGER, opening_service_cash REAL, opening_wallet_balance REAL, opened_at TEXT, status TEXT, expected_service_cash REAL, expected_wallet_balance REAL, closing_service_cash REAL, closing_wallet_balance REAL, cash_variance REAL, wallet_variance REAL, closing_notes TEXT, closed_at TEXT, closed_by INTEGER, unresolved_carryover_count INTEGER DEFAULT 0, waiver_notes TEXT, waived_by INTEGER, waived_at TEXT)',
    ] as $sql) {
        $pdo->exec($sql);
    }

    $pdo->exec("INSERT INTO roles VALUES (1, 'admin'), (2, 'cashier'), (3, 'inventory_manager')");
    $pdo->exec("INSERT INTO users VALUES (1, 'Ada Admin', 1, 'active'), (2, 'Casey Cashier', 2, 'active'), (3, 'Ivy Inventory', 3, 'active')");
    $pdo->exec("INSERT INTO products VALUES (10, 'Coffee', 'COF-1', 20.00, 5, 'active')");
    $pdo->exec("INSERT INTO inventory VALUES (10, 50)");
    $pdo->exec("INSERT INTO fiscal_periods VALUES (1, 'October 2026', '2026-10-01', '2026-10-31', 'open')");

    $clock = new StoreOverviewAttentionClock(new DateTimeImmutable('2026-10-28 10:00:00'));
    $policy = new RoleCapabilityPolicy();
    $workspace = new StoreOperationsDashboardWorkspace($pdo, $clock, $policy);

    // 1. Initial State: Both GCash counts are 0 -> GCash attention row must be hidden
    $view = $workspace->load('admin', 30);
    $assert(isset($view['gcash_attention']), 'Dashboard payload must expose gcash_attention');
    $assert($view['gcash_attention']['visible'] === false, 'GCash attention row must be hidden when both counts are known zero');
    $assert($view['gcash_attention']['status'] === 'ready', 'Status must be ready when counts are known zero');
    $assert($view['gcash_attention']['unresolved_count'] === 0, 'Unresolved count must be 0');
    $assert($view['gcash_attention']['differences_count'] === 0, 'Differences count must be 0');

    // 2. Global GCash counts independent of report date and Cashier filters
    // Seed unresolved exchange dated far outside the 30-day window and under a different cashier
    $pdo->exec("INSERT INTO gcash_transactions (transaction_id, cashier_id, transaction_type, verification_status, created_at)
        VALUES (101, 2, 'Cash-In', 'Needs checking', '2025-01-15 09:00:00')");
    // Seed unreviewed closing difference dated outside 30-day window
    $pdo->exec("INSERT INTO gcash_service_sessions (session_id, shift_id, cashier_id, status, cash_variance, wallet_variance, closed_at, waived_at)
        VALUES (201, 1, 2, 'closed', -50.00, 0.00, '2025-02-10 17:00:00', NULL)");

    StoreOperationsDashboardWorkspace::invalidateCache();
    $view = $workspace->load('admin', 7); // 7-day range selected
    $assert($view['gcash_attention']['visible'] === true, 'GCash attention must be visible when unfinished counts exist');
    $assert($view['gcash_attention']['unresolved_count'] === 1, 'Unresolved count must include all dates/cashiers regardless of 7-day filter, got: ' . ($view['gcash_attention']['unresolved_count'] ?? 'null'));
    $assert($view['gcash_attention']['differences_count'] === 1, 'Differences count must include all dates/cashiers regardless of 7-day filter, got: ' . ($view['gcash_attention']['differences_count'] ?? 'null'));
    $assert($view['gcash_attention']['unresolved_destination'] === 'components/administrator/gcash_overview.php?tab=unresolved', 'Unresolved destination must link to unresolved tab');
    $assert($view['gcash_attention']['differences_destination'] === 'components/administrator/gcash_overview.php?tab=reconciliation', 'Differences destination must link to reconciliation log tab');

    // 3. Partial Failure and Full Failure States
    // Simulate partial failure: drop gcash_service_sessions table
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec('DROP TABLE gcash_service_sessions');
    $partialView = $workspace->load('admin', 30);
    $assert($partialView['gcash_attention']['visible'] === true, 'GCash attention must remain visible during partial failure');
    $assert($partialView['gcash_attention']['status'] === 'partial_failure', 'Status must indicate partial failure, got: ' . $partialView['gcash_attention']['status']);
    $assert($partialView['gcash_attention']['unresolved_count'] === 1, 'Working query count must be retained');
    $assert($partialView['gcash_attention']['differences_count'] === null, 'Failing query count must be null');

    // Simulate full failure: drop gcash_transactions table as well
    StoreOperationsDashboardWorkspace::invalidateCache();
    $pdo->exec('DROP TABLE gcash_transactions');
    $failView = $workspace->load('admin', 30);
    $assert($failView['gcash_attention']['visible'] === true, 'GCash attention must NOT hide on failure');
    $assert($failView['gcash_attention']['status'] === 'unavailable', 'Status must be unavailable on failure, got: ' . $failView['gcash_attention']['status']);
    $assert($failView['gcash_attention']['unresolved_count'] === null, 'Unresolved count must be null');
    $assert($failView['gcash_attention']['differences_count'] === null, 'Differences count must be null');

    // Recreate tables and verify zero state hides row
    $pdo->exec('CREATE TABLE gcash_transactions (transaction_id INTEGER PRIMARY KEY, cashier_id INTEGER, session_id INTEGER, shift_id INTEGER, transaction_type TEXT, amount REAL, gross_fee REAL, net_amount REAL, fee_method TEXT, verification_status TEXT, reference_number TEXT, notes TEXT, created_at TEXT, service_cash_delta REAL DEFAULT 0, wallet_delta REAL DEFAULT 0, is_legacy INTEGER DEFAULT 0)');
    $pdo->exec('CREATE TABLE gcash_service_sessions (session_id INTEGER PRIMARY KEY, shift_id INTEGER, cashier_id INTEGER, opening_service_cash REAL, opening_wallet_balance REAL, opened_at TEXT, status TEXT, expected_service_cash REAL, expected_wallet_balance REAL, closing_service_cash REAL, closing_wallet_balance REAL, cash_variance REAL, wallet_variance REAL, closing_notes TEXT, closed_at TEXT, closed_by INTEGER, unresolved_carryover_count INTEGER DEFAULT 0, waiver_notes TEXT, waived_by INTEGER, waived_at TEXT)');

    // 4. Stock Issue oversight:
    // Normal pending Stock Issues show Store status: count and "Waiting for Inventory Manager; Administrator oversight is read-only."
    // Overdue/high-value cases say "Follow up with Inventory Manager"
    $pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_at, status)
        VALUES (1, 10, -2, 'damaged', '2026-10-28 09:30:00', 'pending')"); // 30 mins old, value 40.00 (normal)
    $pdo->exec("INSERT INTO inventory_adjustments (adjustment_id, product_id, adjustment_qty, adjustment_type, reported_at, status)
        VALUES (2, 10, -300, 'theft', '2026-10-28 09:00:00', 'pending')"); // value 6000.00 (high value >= 5000)

    StoreOperationsDashboardWorkspace::invalidateCache();
    $stockView = $workspace->load('admin', 30);
    // Store status headline
    $assert(($stockView['headline']['inventory_risks']['value'] ?? 0) === 2, 'Pending Stock Issues count must show total pending');
    $assert(str_contains($stockView['headline']['inventory_risks']['detail'], 'Waiting for Inventory Manager'), 'Normal Stock Issue oversight must state Waiting for Inventory Manager');
    $assert(str_contains($stockView['headline']['inventory_risks']['detail'], 'read-only'), 'Must state oversight is read-only');

    // Exceptions list: overdue/high-value must say "Follow up with Inventory Manager"
    $followUpItems = array_filter($stockView['exceptions'], static fn(array $item): bool => $item['title'] === 'Follow up with Inventory Manager');
    $assert(count($followUpItems) === 1, 'Only high-value/overdue item should escalate to Needs attention queue with title Follow up with Inventory Manager');
    $assert(!in_array('Pending Stock Issue', array_column($stockView['exceptions'], 'title'), true), 'Retired Pending Stock Issue label must not appear in exceptions');

    // 5. Size-only Unusual discount and Legacy Reversal removal
    // Seed high-percentage discount and pending reversal
    $pdo->exec("INSERT INTO sales (sale_id, cashier_id, total_amount, discount_type, discount_value, discount_amount, sale_date)
        VALUES (501, 2, 100.00, 'percentage', 80.0, 80.0, '2026-10-28 08:00:00')");
    $pdo->exec("INSERT INTO sale_reversals (reversal_id, sale_id, reversal_type, status, reason, created_at)
        VALUES (601, 501, 'refund', 'pending', 'Customer dispute', '2026-10-28 08:30:00')");

    StoreOperationsDashboardWorkspace::invalidateCache();
    $cleanView = $workspace->load('admin', 30);

    // No unusual discount or sales reversal exceptions
    $exceptionTitles = array_column($cleanView['exceptions'], 'title');
    $assert(!in_array('Unusual discount', $exceptionTitles, true), 'Unusual discount exceptions must be removed');
    $assert(!in_array('Sales reversal', $exceptionTitles, true), 'Sales reversal exceptions must be removed');

    // Comparison metrics: no unusual_discounts or reversals
    $assert(!isset($cleanView['comparison']['unusual_discounts']), 'Comparison must not track unusual discounts');
    $assert(!isset($cleanView['comparison']['reversals']), 'Comparison must not track reversals');

    // Actions list: no retired sales reversals link
    $actionDestinations = array_column($cleanView['actions'], 'destination');
    foreach ($actionDestinations as $dest) {
        $assert(!str_contains($dest, 'tab=reversals'), 'Retired reversals link must be removed from actions');
    }

    // Underlying records must remain intact (no deletion of sales or sale_reversals)
    $assert((int)$pdo->query("SELECT COUNT(*) FROM sales WHERE sale_id = 501")->fetchColumn() === 1, 'Underlying sale record must be preserved');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM sale_reversals WHERE reversal_id = 601")->fetchColumn() === 1, 'Underlying reversal record must be preserved');

    // 6. Attention Rules & Settings cleanup
    $settings = new AttentionSettingsService($pdo, $clock);
    $storeDefaults = $settings->storeSettings();
    $assert(!isset($storeDefaults['reversal_count']), 'reversal_count setting must be removed');
    $assert(!isset($storeDefaults['unusual_discount_percent']), 'unusual_discount_percent setting must be removed');
    $assert(!isset($storeDefaults['inventory_escalation_count']), 'inventory_escalation_count setting must be removed');
    $assert(!isset($storeDefaults['inventory_unresolved_hours']), 'inventory_unresolved_hours setting must be removed');

    $assert(isset($storeDefaults['cash_variance_amount']), 'cash_variance_amount must be retained');
    $assert(isset($storeDefaults['inventory_overdue_hours']), 'inventory_overdue_hours must be retained');
    $assert(isset($storeDefaults['inventory_high_value_amount']), 'inventory_high_value_amount must be retained');
    $assert(isset($storeDefaults['fiscal_period_days_to_close']), 'fiscal_period_days_to_close must be retained');

    // DatabaseAttentionSignalSource must not emit reversal_count or max_discount_percent
    $signals = (new DatabaseAttentionSignalSource($pdo, $clock))->store();
    $assert(!isset($signals['reversal_count']), 'reversal_count signal must be removed');
    $assert(!isset($signals['max_discount_percent']), 'max_discount_percent signal must be removed');

    // AttentionRuleService must not generate store.sales.reversals or store.sales.unusual-discounts
    $ruleItems = (new AttentionRuleService($settings, $clock))->evaluate('admin', $signals);
    $ruleKeys = array_map(static fn(AttentionItem $item): string => $item->key, $ruleItems);
    $assert(!in_array('store.sales.reversals', $ruleKeys, true), 'store.sales.reversals rule must be removed');
    $assert(!in_array('store.sales.unusual-discounts', $ruleKeys, true), 'store.sales.unusual-discounts rule must be removed');

    // 7. Migration retires notifications and deduplication state
    $migration = require __DIR__ . '/../database/migrations/202610280001_retire_unusual_discount_and_reversal_attention.php';
    $pdo->exec("INSERT INTO notifications (user_id, type, title, message, attention_key, created_at) VALUES
        (1, 'attention', 'Old reversal', 'Sales reversal alert', 'store.sales.reversals', '2026-10-27 10:00:00'),
        (1, 'attention', 'Old discount', 'Unusual discount alert', 'store.sales.unusual-discounts', '2026-10-27 10:00:00'),
        (1, 'attention', 'Retained cash', 'Cash variance alert', 'store.cash.variance', '2026-10-27 10:00:00')");
    $pdo->exec("INSERT INTO attention_states (user_id, attention_key, fingerprint, is_active, first_detected_at, last_detected_at) VALUES
        (1, 'store.sales.reversals', 'rev1', 1, '2026-10-27 10:00:00', '2026-10-27 10:00:00'),
        (1, 'store.sales.unusual-discounts', 'disc1', 1, '2026-10-27 10:00:00', '2026-10-27 10:00:00'),
        (1, 'store.cash.variance', 'cash1', 1, '2026-10-27 10:00:00', '2026-10-27 10:00:00')");

    $migration['up']($pdo);
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key IN ('store.sales.reversals', 'store.sales.unusual-discounts')")->fetchColumn() === 0, 'Migration must remove retired discount and reversal notifications');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE attention_key = 'store.cash.variance'")->fetchColumn() === 1, 'Migration must preserve cash variance notifications');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE attention_key IN ('store.sales.reversals', 'store.sales.unusual-discounts')")->fetchColumn() === 0, 'Migration must remove retired attention states');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM attention_states WHERE attention_key = 'store.cash.variance'")->fetchColumn() === 1, 'Migration must preserve cash variance attention states');

} catch (Throwable $exception) {
    $failures[] = 'Store overview attention contract threw: ' . $exception->getMessage() . "\n" . $exception->getTraceAsString();
}

if ($failures) {
    fwrite(STDERR, "Store overview attention contract failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Store overview attention contract: passed\n";
