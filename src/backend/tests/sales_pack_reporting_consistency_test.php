<?php

declare(strict_types=1);

/**
 * Ticket #253: reporting, demand, gross profit, and replenishment quantities
 * stay in Base Units for mixed piece/pack activity.
 *
 * The fixture drives the real checkout, Cash Refund, inventory, demand,
 * gross-profit, and replenishment entry points against a disposable SQLite
 * database. A Sales Pack is expected to contribute its historical contents
 * exactly once, a Pack Opening is expected to contribute nothing, and expired
 * physical stock is expected to stay visible without becoming saleable.
 */

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../app/Services/SalesWorkflowService.php';
require_once __DIR__ . '/../app/Services/CashRefundService.php';
require_once __DIR__ . '/../app/Services/GrossProfitService.php';
require_once __DIR__ . '/../app/Services/InventoryService.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';
require_once __DIR__ . '/../app/Forecasting/ReplenishmentQueueService.php';
require_once __DIR__ . '/../app/Receipts/ReceiptDetailsService.php';

use App\Forecasting\DemandHistoryService;
use App\Forecasting\ReplenishmentQueueService;
use App\Services\CashRefundService;
use App\Services\GrossProfitService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        echo "  FAIL - {$message}\n";
    } else {
        echo "  ok - {$message}\n";
    }
};

function createReportingPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => '2026-10-20 10:00:00');
    $pdo->sqliteCreateFunction('CURDATE', static fn(): string => '2026-10-20');
    $pdo->sqliteCreateFunction('LEAST', static fn(...$values) => min($values));
    $pdo->sqliteCreateFunction('GREATEST', static fn(...$values) => max($values));
    $pdo->sqliteCreateFunction(
        'DATE_ADD',
        static fn(string $date, string $interval, int $days): string => date('Y-m-d', strtotime($date . ' +' . $days . ' days')),
        3
    );

    $pdo->exec("
        CREATE TABLE branches (
            branch_id INTEGER PRIMARY KEY,
            branch_name TEXT,
            branch_code TEXT,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE categories (
            category_id INTEGER PRIMARY KEY,
            category_name TEXT
        );
        CREATE TABLE roles (
            role_id INTEGER PRIMARY KEY,
            role_name TEXT
        );
        CREATE TABLE users (
            user_id INTEGER PRIMARY KEY,
            full_name TEXT,
            username TEXT,
            password_hash TEXT,
            role_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE registers (
            register_id INTEGER PRIMARY KEY,
            name TEXT
        );
        CREATE TABLE cashier_shifts (
            shift_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            register_id INTEGER,
            opening_cash REAL,
            status TEXT DEFAULT 'open',
            opened_at TEXT DEFAULT (datetime('now')),
            locked_at TEXT NULL
        );
        CREATE TABLE products (
            product_id INTEGER PRIMARY KEY AUTOINCREMENT,
            branch_id INTEGER DEFAULT 1,
            sku TEXT,
            barcode TEXT,
            case_barcode TEXT NULL,
            pack_size INTEGER NULL,
            pack_barcode TEXT NULL,
            pack_price REAL NULL,
            product_name TEXT,
            unit_price REAL,
            cost_price REAL NULL,
            reorder_level INTEGER DEFAULT 0,
            safety_stock INTEGER DEFAULT 0,
            minimum_order_quantity INTEGER DEFAULT 1,
            units_per_package INTEGER DEFAULT 1,
            category_id INTEGER DEFAULT 1,
            quantity_sold INTEGER DEFAULT 0,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE inventory (
            product_id INTEGER PRIMARY KEY,
            quantity_on_hand INTEGER
        );
        CREATE TABLE product_batches (
            batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            receiving_id INTEGER NULL,
            batch_number TEXT,
            quantity INTEGER,
            remaining_quantity INTEGER,
            sealed_quantity INTEGER NOT NULL DEFAULT 0,
            loose_quantity INTEGER NOT NULL DEFAULT 0,
            cost_price REAL NULL,
            expiration_date TEXT NULL,
            date_received TEXT DEFAULT (datetime('now')),
            supplier TEXT NULL
        );
        CREATE TABLE stock_receiving (
            receiving_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cost_price REAL NULL,
            total_cost REAL NULL,
            accepted_qty INTEGER NULL,
            received_qty INTEGER NULL
        );
        CREATE TABLE sales (
            sale_id INTEGER PRIMARY KEY AUTOINCREMENT,
            cashier_id INTEGER,
            branch_id INTEGER DEFAULT 1,
            shift_id INTEGER,
            total_amount REAL,
            gross_amount REAL NULL,
            payment_method TEXT,
            cash_received REAL NULL,
            change_due REAL NULL,
            payment_reference TEXT NULL,
            discount_type TEXT DEFAULT 'none',
            discount_value REAL DEFAULT 0,
            discount_amount REAL DEFAULT 0,
            discount_reason TEXT NULL,
            discount_authorized_by INTEGER NULL,
            promotion_id INTEGER NULL,
            promotion_name TEXT NULL,
            sale_date TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE sale_items (
            sale_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            product_id INTEGER,
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NULL,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE sale_item_batches (
            sale_item_batch_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_item_id INTEGER,
            batch_id INTEGER,
            quantity INTEGER,
            unit_cost REAL NULL,
            cost_price REAL NULL,
            cost_status TEXT DEFAULT 'missing',
            cost_source TEXT NULL,
            cost_provenance TEXT NULL
        );
        CREATE TABLE checkout_attempts (
            cashier_id INTEGER,
            attempt_id TEXT,
            request_hash TEXT,
            sale_id INTEGER NULL,
            result_json TEXT,
            PRIMARY KEY (cashier_id, attempt_id)
        );
        CREATE TABLE sale_receipt_details (
            sale_id INTEGER PRIMARY KEY,
            details_json TEXT NOT NULL
        );
        CREATE TABLE refund_receipt_details (
            refund_id INTEGER PRIMARY KEY,
            details_json TEXT NOT NULL
        );
        CREATE TABLE store_calendar (
            calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
            calendar_date TEXT NOT NULL,
            status TEXT NOT NULL,
            reason TEXT,
            recorded_by INTEGER,
            recorded_at TEXT DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE cash_refunds (
            refund_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            shift_id INTEGER,
            cashier_id INTEGER,
            refund_amount REAL,
            payment_method TEXT,
            reason TEXT,
            note TEXT NULL,
            original_cashier_id INTEGER NULL,
            approved_by INTEGER NULL,
            exception_reason TEXT NULL,
            payment_reference TEXT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE cash_refund_items (
            refund_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            refund_id INTEGER,
            sale_item_id INTEGER,
            product_id INTEGER,
            selling_form TEXT NOT NULL DEFAULT 'piece',
            pack_size INTEGER NULL,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL,
            disposition TEXT NOT NULL DEFAULT 'restockable'
        );
        CREATE TABLE sale_reversal_items (
            reversal_item_id INTEGER PRIMARY KEY AUTOINCREMENT,
            reversal_id INTEGER,
            sale_item_id INTEGER,
            product_id INTEGER,
            quantity INTEGER,
            unit_price REAL,
            subtotal REAL
        );
        CREATE TABLE forecast_sales_imports (
            product_id INTEGER,
            sale_date TEXT,
            quantity INTEGER,
            PRIMARY KEY (product_id, sale_date)
        );
        CREATE TABLE sale_reversals (
            reversal_id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER,
            status TEXT DEFAULT 'pending',
            settlement_method TEXT NULL,
            refund_amount REAL DEFAULT 0,
            approved_at TEXT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE cash_drawer_movements (
            drawer_movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            shift_id INTEGER,
            movement_type TEXT,
            amount REAL,
            recorded_by INTEGER,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE stock_movements (
            movement_id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER,
            change_qty INTEGER,
            reason TEXT,
            moved_by INTEGER,
            moved_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE activity_log (
            log_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            category TEXT,
            module TEXT,
            record_id INTEGER NULL,
            metadata TEXT NULL,
            previous_value TEXT NULL,
            new_value TEXT NULL,
            ip_address TEXT NULL,
            created_at TEXT DEFAULT (datetime('now'))
        );
        CREATE TABLE promotions (
            promotion_id INTEGER PRIMARY KEY AUTOINCREMENT,
            promotion_name TEXT,
            discount_type TEXT,
            discount_value REAL,
            scope TEXT,
            starts_at TEXT,
            ends_at TEXT,
            status TEXT DEFAULT 'active'
        );
        CREATE TABLE fiscal_periods (
            period_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_name TEXT NOT NULL,
            start_date TEXT,
            end_date TEXT,
            status TEXT NOT NULL DEFAULT 'open'
        );
        CREATE TABLE fiscal_period_locks (
            lock_id INTEGER PRIMARY KEY AUTOINCREMENT,
            period_id INTEGER,
            table_name TEXT
        );
        CREATE TABLE store_write_gate (
            gate_key TEXT PRIMARY KEY,
            paused_at TEXT NULL,
            paused_by TEXT NULL
        );
        CREATE TABLE platform_settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
        );
        CREATE TABLE notification_preferences (
            user_id INTEGER PRIMARY KEY,
            notify_low_stock INTEGER DEFAULT 1,
            low_stock_threshold INTEGER NULL
        );
        CREATE TABLE notifications (
            notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            type TEXT,
            title TEXT,
            message TEXT,
            reference_id INTEGER NULL,
            reference_type TEXT NULL,
            is_read INTEGER DEFAULT 0,
            created_at TEXT DEFAULT (datetime('now'))
        );
    ");

    $pdo->exec("INSERT INTO branches (branch_id, branch_name, branch_code, status) VALUES (1, 'Reporting Store', 'REPORT', 'active')");
    $pdo->exec("INSERT INTO categories (category_id, category_name) VALUES (1, 'Snacks'), (2, 'Oil')");
    $pdo->exec("INSERT INTO roles (role_id, role_name) VALUES (1, 'cashier'), (2, 'admin')");
    $pdo->exec("INSERT INTO users (user_id, full_name, username, password_hash, role_id, branch_id) VALUES (1, 'Casey Cashier', 'cashier1', 'hash', 1, 1)");
    $pdo->exec("INSERT INTO registers (register_id, name) VALUES (1, 'Till 1')");
    $pdo->exec("INSERT INTO cashier_shifts (shift_id, cashier_id, register_id, opening_cash, status) VALUES (1, 1, 1, 500.0, 'open')");
    $pdo->exec("INSERT INTO fiscal_periods (period_name, start_date, end_date, status) VALUES ('Reporting FY2026', '2026-01-01', '2026-12-31', 'open')");

    // One pack of 12 plus three loose pieces; the pack is cheaper than 12 pieces.
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, pack_size, pack_barcode, pack_price, product_name, unit_price, cost_price, reorder_level, category_id)
                VALUES (1, 'BIS-01', 'BIS-PC', 12, 'BIS-PK', 100.00, 'Chocolate Biscuit', 10.00, 7.00, 5, 1)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (1, 27)");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, cost_price, expiration_date)
                VALUES (1, 1, 'BIS-BATCH', 27, 27, 2, 3, 7.00, '2026-12-31')");

    // Oil stays a whole-pouch product: no pack configuration and no litres.
    $pdo->exec("INSERT INTO products (product_id, sku, barcode, product_name, unit_price, cost_price, reorder_level, category_id, minimum_order_quantity, units_per_package)
                VALUES (2, 'OIL-250', 'OIL-250-BC', 'Cooking Oil 250 mL', 20.00, 12.00, 0, 2, 1, 1)");
    $pdo->exec("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (2, 10)");
    $pdo->exec("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by, moved_at)
                VALUES (1, 27, 'opening', 1, '2026-01-01 00:00:00'), (2, 10, 'opening', 1, '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, cost_price, expiration_date)
                VALUES (2, 2, 'OIL-BATCH', 10, 10, 0, 10, 12.00, NULL)");

    return $pdo;
}

$pdo = createReportingPdo();
$sales = new SalesWorkflowService($pdo);
$refunds = new CashRefundService($pdo, new App\Services\CashierShiftService($pdo));
$profit = new GrossProfitService($pdo);
$demand = new DemandHistoryService();
$inventory = new InventoryService($pdo);
$replenishment = new ReplenishmentQueueService($pdo);

$quantityOnHand = static fn(int $productId): int => (int)$pdo->query(
    "SELECT quantity_on_hand FROM inventory WHERE product_id = {$productId}"
)->fetchColumn();
// Movements are written at wall-clock time; move the newest one into the test day.
$retimeLastMovement = static function (string $at) use ($pdo): void {
    $pdo->exec("UPDATE stock_movements SET moved_at = '{$at}' WHERE moved_at >= date('now')");
};
// A past calendar day keeps the gross-profit range closed (today is capped at "now").
$saleDay = date('Y-m-d', strtotime('-1 day'));

echo "Starting Ticket #253 reporting consistency checks...\n";

// -------------------------------------------------------------------------
// 1. Mixed-form sale: pack contributes its contents exactly once
// -------------------------------------------------------------------------
echo "\n--- Mixed-form sale ---\n";
$checkout = $sales->checkout([
    ['product_id' => 1, 'selling_form' => 'pack', 'qty' => 1],
    ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 2],
], 1, 'cashier', 'cash', ['cash_received' => 200.00]);
$saleId = (int)$checkout['sale_id'];
$pdo->exec("UPDATE sales SET sale_date = '{$saleDay} 10:00:00' WHERE sale_id = {$saleId}");
$retimeLastMovement($saleDay . ' 10:00:00');

$assert((float)$checkout['total'] === 120.00, 'Mixed cart charges pack price 100 plus 2 piece sales of 10');
$assert($quantityOnHand(1) === 13, 'Mixed cart deducts 14 Base Units: 1 pack (12) plus 2 pieces');
$batch = $pdo->query('SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE batch_id = 1')->fetch();
$assert((int)$batch['sealed_quantity'] === 1 && (int)$batch['loose_quantity'] === 1, 'One sealed pack and one loose piece remain');
$assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 1')->fetchColumn() === 14,
    'Product sale counter records Base Units, not one pack line');

$saleItem = $pdo->query('SELECT sale_item_id FROM sale_items WHERE sale_id = ' . $saleId . " AND selling_form = 'pack'")->fetch();
$assert((int)$pdo->query('SELECT quantity FROM sale_items WHERE sale_item_id = ' . (int)$saleItem['sale_item_id'])->fetchColumn() === 1,
    'Historical sale line keeps the pack selling form quantity');
$assert((int)$pdo->query('SELECT COALESCE(SUM(quantity), 0) FROM sale_item_batches WHERE sale_item_id = ' . (int)$saleItem['sale_item_id'])->fetchColumn() === 12,
    'Historical pack cost allocation preserves its 12 contents');

$dayProfit = $profit->calculate(['from' => $saleDay, 'to' => $saleDay, 'store_id' => 1]);
$assert($dayProfit['summary']['total_units'] === 14, 'Gross profit net units are 14 Base Units for the mixed sale');
$assert($dayProfit['summary']['total_net_sales'] === 120.00, 'Gross profit uses the actual pack and piece prices charged');
$assert($dayProfit['summary']['total_net_cost'] === 98.00, 'Gross profit uses preserved source-batch costs (14 x 7.00)');
$assert($dayProfit['summary']['total_gross_profit'] === 22.00, 'Gross profit is 22.00 for the mixed sale');

$dayEvidence = $demand->getDailyEvidence($pdo, 1, $saleDay, $saleDay, $saleDay . ' 23:59:59')[0];
$assert($dayEvidence['valid_sales_units'] === 14, 'Demand counts the pack as its 12 contents plus the 2 pieces');
$assert($dayEvidence['end_of_day_stock'] === 13, 'Demand stock evidence remains 13 Base Units after the sale');

$summary = $inventory->getProductsWithBatchSummaries($saleDay);
$biscuitSummary = array_values(array_filter($summary, static fn(array $row): bool => (int)$row['product_id'] === 1))[0];
$assert($biscuitSummary['physical_sealed_packs'] === 1 && $biscuitSummary['physical_loose_pieces'] === 1,
    'Inventory summary shows physical sealed and loose quantities');
$assert($biscuitSummary['saleable_sealed_packs'] === 1 && $biscuitSummary['saleable_loose_pieces'] === 1,
    'Inventory summary shows saleable sealed and loose quantities');

$recommendations = array_column($replenishment->getRecommendations(), null, 'product_id');
$assert($recommendations[1]['current_stock'] === 13, 'Replenishment reads the product total in Base Units');

// -------------------------------------------------------------------------
// 2. Historical catalog edits never rewrite the sold pack
// -------------------------------------------------------------------------
echo "\n--- Historical price and cost edits ---\n";
$pdo->exec('UPDATE products SET pack_price = 200.00, unit_price = 25.00, cost_price = 99.00 WHERE product_id = 1');
$afterEdit = $profit->calculate(['from' => $saleDay, 'to' => $saleDay, 'store_id' => 1]);
$assert($afterEdit['summary']['total_net_sales'] === 120.00, 'Today\'s pack and piece prices do not reprice the recorded sale');
$assert($afterEdit['summary']['total_net_cost'] === 98.00, 'Today\'s catalog cost does not replace preserved source-batch costs');

$receiptItems = (new App\Receipts\ReceiptDetailsService($pdo))->fetchItems($saleId);
$packReceiptLine = array_values(array_filter($receiptItems, static fn(array $item): bool => ($item['selling_form'] ?? 'piece') === 'pack'))[0];
$assert((float)$packReceiptLine['unit_price'] === 100.00 && (int)$packReceiptLine['pack_size'] === 12,
    'Receipt keeps the original pack price and contents after catalog edits');
$pdo->exec('UPDATE products SET pack_price = 100.00, unit_price = 10.00, cost_price = 7.00 WHERE product_id = 1');

// -------------------------------------------------------------------------
// 3. Sealed-pack refund uses actual money and converted quantities
// -------------------------------------------------------------------------
echo "\n--- Sealed-pack refund ---\n";
$refundId = $refunds->refund(
    1,
    'cashier',
    $saleId,
    [(int)$saleItem['sale_item_id'] => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]],
    'customer_return'
);
$pdo->exec("UPDATE cash_refunds SET created_at = '{$saleDay} 12:00:00' WHERE refund_id = {$refundId}");
$retimeLastMovement($saleDay . ' 12:00:00');

$refundLine = $pdo->query("SELECT selling_form, pack_size, quantity, subtotal FROM cash_refund_items WHERE refund_id = {$refundId}")->fetch();
$assert($refundLine['selling_form'] === 'pack' && (int)$refundLine['pack_size'] === 12 && (int)$refundLine['quantity'] === 1,
    'Refund keeps the historical pack form and contents');
$assert((float)$refundLine['subtotal'] === 100.00, 'Refund records the original pack money, not the edited pack price');
$assert((float)$pdo->query("SELECT refund_amount FROM cash_refunds WHERE refund_id = {$refundId}")->fetchColumn() === 100.00,
    'Refund amount is the actual money returned');
$assert($quantityOnHand(1) === 25, 'Sealed-pack refund restores 12 Base Units');
$batch = $pdo->query('SELECT sealed_quantity, loose_quantity FROM product_batches WHERE batch_id = 1')->fetch();
$assert((int)$batch['sealed_quantity'] === 2 && (int)$batch['loose_quantity'] === 1, 'Refund restores sealed stock, not loose stock');
$assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 1')->fetchColumn() === 2,
    'Pack refund rewinds the product sale counter by its 12 Base Units');

$refundProfit = $profit->calculate(['from' => $saleDay, 'to' => $saleDay, 'store_id' => 1]);
$assert($refundProfit['rows'][0]['units_sold'] === 14, 'Gross profit still reports 14 Base Units sold');
$assert($refundProfit['rows'][0]['units_refunded'] === 12, 'Gross profit reports the refunded pack as 12 Base Units');
$assert($refundProfit['rows'][0]['net_units'] === 2, 'Gross profit net units leave the 2 unreturned pieces');
$assert($refundProfit['rows'][0]['refunds'] === 100.00, 'Gross profit subtracts the actual pack refund money');
$assert($refundProfit['rows'][0]['net_sales'] === 20.00, 'Gross profit net sales leave the 2 piece sales');
$assert($refundProfit['rows'][0]['net_cost'] === 14.00, 'Gross profit reverses the full pack cost and leaves 2 pieces of cost');
$assert($refundProfit['rows'][0]['gross_profit'] === 6.00, 'Gross profit remains 6.00 after the pack refund');
$assert($refundProfit['summary']['total_units'] === 2, 'Summary Base Units match the refunded pack conversion');

$refundEvidence = $demand->getDailyEvidence($pdo, 1, $saleDay, $saleDay, $saleDay . ' 23:59:59')[0];
$assert($refundEvidence['observed_gross_sales'] === 14, 'Demand observed sales remain 14 Base Units');
$assert($refundEvidence['refunded_units'] === 12, 'Demand subtracts the pack refund as 12 Base Units');
$assert($refundEvidence['valid_sales_units'] === 2, 'Demand valid sales leave the 2 unreturned pieces');

// -------------------------------------------------------------------------
// 4. Pack Opening is invisible to trading reports
// -------------------------------------------------------------------------
echo "\n--- Automatic Pack Opening ---\n";
$openingSale = $sales->checkout([
    ['product_id' => 1, 'selling_form' => 'piece', 'qty' => 5],
], 1, 'cashier', 'cash', ['cash_received' => 100.00]);
$openingSaleId = (int)$openingSale['sale_id'];
$pdo->exec("UPDATE sales SET sale_date = '{$saleDay} 15:00:00' WHERE sale_id = {$openingSaleId}");
$retimeLastMovement($saleDay . ' 15:00:00');

$batch = $pdo->query('SELECT sealed_quantity, loose_quantity, remaining_quantity FROM product_batches WHERE batch_id = 1')->fetch();
$assert((int)$batch['sealed_quantity'] === 1 && (int)$batch['loose_quantity'] === 8 && (int)$batch['remaining_quantity'] === 20,
    'Selling 5 pieces opens one pack and conserves the leftover 8 loose pieces');
$assert($quantityOnHand(1) === 20, 'Only the 5 sold pieces reduce the product total');

$openingLog = $pdo->query("SELECT COUNT(*) FROM activity_log WHERE action = 'Automatic pack opening at checkout' AND record_id = {$openingSaleId}")->fetchColumn();
$assert((int)$openingLog === 1, 'Pack Opening is recorded as checkout history once');
$purchaseMovements = (int)$pdo->query("SELECT COUNT(*) FROM stock_movements WHERE reason = 'purchase'")->fetchColumn();
$assert($purchaseMovements === 0, 'Pack Opening creates no purchase movement');
$openingMovement = $pdo->query("SELECT change_qty FROM stock_movements WHERE product_id = 1 ORDER BY movement_id DESC LIMIT 1")->fetch();
$assert((int)$openingMovement['change_qty'] === -5, 'Pack Opening creates no extra stock movement; only the sale is recorded');
$assert((int)$pdo->query('SELECT quantity_sold FROM products WHERE product_id = 1')->fetchColumn() === 7,
    'Pack Opening adds no product sale counter units');

$openingProfit = $profit->calculate([
    'from' => $saleDay,
    'to' => $saleDay,
    'store_id' => 1,
    'product_id' => 1,
]);
$assert($openingProfit['rows'][0]['units_sold'] === 19, 'Gross profit counts only 19 sold Base Units, not opened contents');
$assert($openingProfit['rows'][0]['gross_sales'] === 170.00, 'Gross profit revenue excludes Pack Opening (120 + 50)');

$openingEvidence = $demand->getDailyEvidence($pdo, 1, $saleDay, $saleDay, $saleDay . ' 23:59:59')[0];
$assert($openingEvidence['observed_gross_sales'] === 19, 'Demand counts 19 sold Base Units and no opened contents');
$assert($openingEvidence['valid_sales_units'] === 7, 'Demand valid units are 19 sold less the 12 refunded');

// -------------------------------------------------------------------------
// 5. Expired physical stock stays visible but never saleable
// -------------------------------------------------------------------------
echo "\n--- Expired stock ---\n";
$pdo->exec("INSERT INTO product_batches (batch_id, product_id, batch_number, quantity, remaining_quantity, sealed_quantity, loose_quantity, cost_price, expiration_date)
            VALUES (3, 1, 'BIS-EXPIRED', 27, 5, 2, 3, 7.00, '2026-01-01')");
$pdo->exec('UPDATE inventory SET quantity_on_hand = quantity_on_hand + 5 WHERE product_id = 1');

$summary = $inventory->getProductsWithBatchSummaries($saleDay);
$expiredSummary = array_values(array_filter($summary, static fn(array $row): bool => (int)$row['product_id'] === 1))[0];
$assert($expiredSummary['physical_sealed_packs'] === 3 && $expiredSummary['physical_loose_pieces'] === 11,
    'Expired packs and pieces remain visible in the physical totals');
$assert($expiredSummary['saleable_sealed_packs'] === 1 && $expiredSummary['saleable_loose_pieces'] === 8,
    'Expired stock is excluded from saleable sealed and loose totals');
$assert($expiredSummary['expired_sealed_packs'] === 2 && $expiredSummary['expired_loose_pieces'] === 3,
    'Expired sealed and loose quantities are reported separately');
$assert($expiredSummary['quantity_on_hand'] === 25 && $expiredSummary['expired_quantity'] === 5,
    'Physical total keeps expired stock while labelling it expired');

$packPlan = SalesWorkflowService::planCombinedDemand($pdo, 1, 'Chocolate Biscuit', 2, 0, 12, 7.00, true, 25);
$assert($packPlan['allocated_packs'] === 1, 'Only the one saleable sealed pack can satisfy pack demand');
$assert(count($packPlan['conflicts']) === 1, 'A second sealed pack is refused while expired packs remain');
$assert($quantityOnHand(1) === 25, 'Quoting pack availability changes no stock');

$recommendations = array_column($replenishment->getRecommendations(), null, 'product_id');
$assert($recommendations[1]['current_stock'] === 25, 'Replenishment keeps expired stock in the physical Base Unit total');
$assert($recommendations[1]['basis'] === 'Stock rules', 'Replenishment still explains its stock-rule basis');

// -------------------------------------------------------------------------
// 6. Oil pouches stay whole-pouch Base Units
// -------------------------------------------------------------------------
echo "\n--- Prepared oil pouches ---\n";
$oilSale = $sales->checkout([
    ['product_id' => 2, 'selling_form' => 'piece', 'qty' => 3],
], 1, 'cashier', 'cash', ['cash_received' => 100.00]);
$oilSaleId = (int)$oilSale['sale_id'];
$pdo->exec("UPDATE sales SET sale_date = '{$saleDay} 16:00:00' WHERE sale_id = {$oilSaleId}");
$retimeLastMovement($saleDay . ' 16:00:00');
$assert((float)$oilSale['total'] === 60.00, 'Three 250 mL pouches sell at PHP 20 each');
$assert($quantityOnHand(2) === 7, 'Oil deducts three whole pouches');

$oilLine = $pdo->query("SELECT selling_form, pack_size, quantity FROM sale_items WHERE sale_id = {$oilSaleId}")->fetch();
$assert($oilLine['selling_form'] === 'piece' && $oilLine['pack_size'] === null && (int)$oilLine['quantity'] === 3,
    'Oil sale keeps whole-pouch piece quantities with no pack conversion');

$oilProfit = $profit->calculate([
    'from' => $saleDay,
    'to' => $saleDay,
    'store_id' => 1,
    'product_id' => 2,
]);
$assert($oilProfit['rows'][0]['units_sold'] === 3, 'Oil gross profit reports 3 whole pouches');
$assert($oilProfit['rows'][0]['net_sales'] === 60.00, 'Oil gross profit uses PHP 60.00 actual revenue');

$oilEvidence = $demand->getDailyEvidence($pdo, 2, $saleDay, $saleDay, $saleDay . ' 23:59:59')[0];
$assert($oilEvidence['valid_sales_units'] === 3, 'Oil demand stays 3 whole pouches, never litres or fractions');

$recommendations = array_column($replenishment->getRecommendations(), null, 'product_id');
$assert($recommendations[2]['current_stock'] === 7, 'Oil replenishment reads 7 whole pouches');

// -------------------------------------------------------------------------
// 7. Piece and oil refunds keep their existing Base Unit conventions
// -------------------------------------------------------------------------
echo "\n--- Piece refunds ---\n";
$oilItemId = (int)$pdo->query("SELECT sale_item_id FROM sale_items WHERE sale_id = {$oilSaleId}")->fetchColumn();
$oilRefundId = $refunds->refund(
    1,
    'cashier',
    $oilSaleId,
    [$oilItemId => ['quantity' => 1, 'disposition' => CashRefundService::RESTOCKABLE]],
    'customer_return'
);
$pdo->exec("UPDATE cash_refunds SET created_at = '{$saleDay} 17:00:00' WHERE refund_id = {$oilRefundId}");
$retimeLastMovement($saleDay . ' 17:00:00');
$assert($quantityOnHand(2) === 8, 'A one-pouch oil refund restores one Base Unit');
$assert((float)$pdo->query("SELECT refund_amount FROM cash_refunds WHERE refund_id = {$oilRefundId}")->fetchColumn() === 20.00,
    'Oil refund returns the actual PHP 20.00 paid');

$oilProfit = $profit->calculate([
    'from' => $saleDay,
    'to' => $saleDay,
    'store_id' => 1,
    'product_id' => 2,
]);
$assert($oilProfit['rows'][0]['units_refunded'] === 1, 'Oil gross profit reports one refunded pouch');
$assert($oilProfit['rows'][0]['net_units'] === 2, 'Oil gross profit leaves two net pouches');
$assert($oilProfit['rows'][0]['refunds'] === 20.00, 'Oil gross profit subtracts the actual refunded money');

$oilEvidence = $demand->getDailyEvidence($pdo, 2, $saleDay, $saleDay, $saleDay . ' 23:59:59')[0];
$assert($oilEvidence['valid_sales_units'] === 2, 'Oil demand leaves two whole pouches after the refund');

if ($failures !== []) {
    fwrite(STDERR, "\n" . count($failures) . " reporting consistency check(s) failed:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(1);
}

echo "\nPASS: sales_pack_reporting_consistency_test\n";
