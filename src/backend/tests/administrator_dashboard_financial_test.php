<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../bootstrap/app.php';
use App\Attention\Clock;
use App\Authorization\RoleCapabilityPolicy;
use App\Dashboard\StoreOperationsDashboardWorkspace;
use App\Services\GrossProfitService;
final class FinancialDashboardClock implements Clock {
    public function __construct(private string $time = '2026-10-23 02:00:00Z') {}
    public function now(): DateTimeImmutable { return new DateTimeImmutable($this->time); }
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    'attention_settings (setting_scope TEXT, setting_key TEXT, setting_value TEXT, updated_by INTEGER, updated_at TEXT, PRIMARY KEY(setting_scope,setting_key))',
    'roles (role_id INTEGER, role_name TEXT)', 'users (user_id INTEGER,full_name TEXT,role_id INTEGER,status TEXT)',
    'sales (sale_id INTEGER,gross_amount REAL,total_amount REAL,discount_type TEXT,discount_value REAL,discount_amount REAL DEFAULT 0,sale_date TEXT)',
    'sale_items (sale_item_id INTEGER,sale_id INTEGER,product_id INTEGER,quantity INTEGER,unit_price REAL,subtotal REAL)',
    'sale_item_batches (sale_item_batch_id INTEGER,sale_item_id INTEGER,batch_id INTEGER,quantity INTEGER,unit_cost REAL,cost_status TEXT)',
    'categories (category_id INTEGER,category_name TEXT)',
    'products (product_id INTEGER,product_name TEXT,sku TEXT,barcode TEXT,branch_id INTEGER,category_id INTEGER,cost_price REAL,reorder_level INTEGER,status TEXT)',
    'cash_refunds (refund_id INTEGER,sale_id INTEGER,refund_amount REAL,created_at TEXT)',
    'cash_refund_items (refund_item_id INTEGER,refund_id INTEGER,sale_item_id INTEGER,product_id INTEGER,quantity INTEGER,unit_price REAL,subtotal REAL,disposition TEXT)',
    'sale_reversals (reversal_id INTEGER,sale_id INTEGER,reversal_type TEXT,status TEXT,reason TEXT,created_at TEXT)',
    'cashier_shifts (shift_id INTEGER,cashier_id INTEGER,opened_at TEXT,status TEXT,closed_at TEXT,cash_variance REAL,reviewed_at TEXT)',
    'inventory (product_id INTEGER,quantity_on_hand INTEGER)',
    'inventory_adjustments (adjustment_id INTEGER,product_id INTEGER,adjustment_qty INTEGER,adjustment_type TEXT,reported_at TEXT,status TEXT)',
    'fiscal_periods (period_name TEXT,start_date TEXT,end_date TEXT,status TEXT)',
    'ml_jobs (job_id INTEGER,trigger_type TEXT,status TEXT,completed_at TEXT)',
    'demand_forecasts (product_id INTEGER,job_id INTEGER,readiness_level TEXT)',
    'ml_model_evaluations (evaluation_id INTEGER,horizon_days INTEGER,mae REAL,rmse REAL,wape REAL)'
] as $schema) { $pdo->exec('CREATE TABLE ' . $schema); }
$pdo->exec("INSERT INTO products VALUES (1,'Coffee','COF','',1,1,999,1,'active')");
$pdo->exec("INSERT INTO sales (sale_id,gross_amount,total_amount,discount_type,discount_value,sale_date) VALUES
    (1,200,180,'fixed',20,'2026-10-23 08:00:00'),
    (2,100,100,'none',0,'2026-08-25 00:00:00'),
    (3,999,999,'none',0,'2026-10-23 10:00:01'),
    (4,50,50,'none',0,'2026-10-23 10:00:00')");
$pdo->exec('INSERT INTO sale_items VALUES (1,1,1,2,100,200),(2,2,1,1,100,100),(3,3,1,1,999,999),(4,4,1,1,50,50)');
$pdo->exec("INSERT INTO sale_item_batches VALUES (1,1,1,2,60,'recorded'),(2,2,1,1,60,'recorded'),(3,3,1,1,60,'recorded'),(4,4,1,1,20,'recorded')");
$pdo->exec("INSERT INTO cash_refunds VALUES (1,1,90,'2026-10-23 08:30:00'),(2,2,100,'2026-10-23 09:00:00')");
$pdo->exec("INSERT INTO cash_refund_items VALUES (1,1,1,1,1,100,90,'restockable'),(2,2,2,1,1,100,100,'damaged')");
$check = static function(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
$load = static function() use ($pdo): array {
    StoreOperationsDashboardWorkspace::invalidateCache();
    return (new StoreOperationsDashboardWorkspace($pdo,new FinancialDashboardClock(),new RoleCapabilityPolicy()))->load('admin');
};
$view=$load();
$profit=$view['gross_profit']['periods']['today'];
$check(isset($profit['points']), 'Profit exposes hourly results through dashboard contract');
$check(count($profit['points'])===11, 'Only elapsed hours including the ongoing hour');
$check($profit['points'][8]['gross_profit']===30.0, 'Discounted sale and partial restock refund yield 30 profit');
$check($profit['points'][9]['gross_profit']===-100.0, 'Damaged return of older sale retains original cost');
$check($profit['points'][0]['gross_profit']===0.0, 'Empty elapsed interval is real zero');
$check($profit['total_gross_profit']===-40.0, 'Exact as-of sale included and future sale excluded');

$sales=$view['net_sales']['periods']['today'];
$check($sales['total_gross_sales']===250.0 && $sales['total_discounts']===20.0 && $sales['total']===40.0, 'Gross Sales distinguish pre-discount revenue from Net Sales');
foreach (['today','week','month'] as $period) {
    $report=(new GrossProfitService($pdo))->calculate(['period'=>$period,'as_of'=>(new FinancialDashboardClock())->now()])['summary'];
    $actual=$view['gross_profit']['periods'][$period];
    foreach (['total_gross_sales','total_discounts','total_refunds','total_net_sales','total_net_cost','total_gross_profit','status'] as $metric) {
        $check($actual[$metric]===$report[$metric], "$period $metric reconciles with authoritative report");
    }
    $check(round(array_sum(array_column($actual['points'],'gross_profit')),2)===$report['total_gross_profit'], "$period intervals sum to report profit");
}
$history=$view['financial_history'];
$check($history['period_start']==='2026-09-24' && $history['period_end']==='2026-10-23' && count($history['points'])===30, 'Current history spans today plus preceding 29 calendar days');
$check($history['previous_start']==='2026-08-25' && $history['previous_end']==='2026-09-23', 'Comparison is preceding 30 complete days');
$check($history['net_sales']['previous']===100.0 && $history['net_sales']['current']===40.0 && $history['net_sales']['change_percent']===-60.0, 'Historical comparison includes the first previous date');
$check($history['gross_profit']['previous']===40.0 && $history['gross_profit']['current']===-40.0, 'Historical financial windows reconcile');
foreach ([['2026-09-24','2026-10-23','current'],['2026-08-25','2026-09-23','previous']] as [$from,$to,$side]) {
    $report=(new GrossProfitService($pdo))->calculate(['from'=>$from,'to'=>$to,'as_of'=>(new FinancialDashboardClock())->now()])['summary'];
    $check($history['net_sales'][$side]===$report['total_net_sales'] && $history['gross_profit'][$side]===$report['total_gross_profit'], "$side historical window reconciles to report");
}
$pdo->exec("INSERT INTO cash_refunds VALUES (3,4,50,'2026-10-23 10:00:01')");
$pdo->exec("INSERT INTO cash_refund_items VALUES (3,3,4,1,1,50,50,'restockable')");
$futureRefund=$load();
$check($futureRefund['gross_profit']['periods']['today']['total_gross_profit']===-40.0, 'Future refund and its return cost excluded through exact shared as-of');
$pdo->exec("UPDATE cash_refund_items SET disposition='restockable' WHERE refund_id=2");
$oldRestock=$load();
$check($oldRestock['gross_profit']['periods']['today']['points'][9]['net_cost']===-60.0 && $oldRestock['gross_profit']['periods']['today']['points'][9]['gross_profit']===-40.0, 'Older sale restock refund reverses original snapshot cost at issue time');
$pdo->exec("UPDATE cash_refund_items SET disposition='damaged' WHERE refund_id=2");
$pdo->exec("UPDATE sale_item_batches SET cost_status='estimated' WHERE sale_item_id=4");
$estimated=$load();
$check($estimated['gross_profit']['periods']['today']['status']==='estimated' && $estimated['gross_profit']['periods']['today']['points'][10]['status']==='estimated', 'Estimated historical snapshots remain visible without substituting catalog cost');
$pdo->exec('UPDATE sale_item_batches SET unit_cost=NULL WHERE sale_item_id=1');
$missing=$load();
$check($missing['gross_profit']['periods']['today']['total_gross_profit']===null && $missing['gross_profit']['periods']['today']['points'][8]['gross_profit']===null, 'Missing sold or restocked cost creates a gap and unavailable period total');
$check($missing['gross_profit']['periods']['today']['points'][9]['gross_profit']===-100.0, 'Missing costs do not erase independent known intervals');
$check($missing['financial_history']['gross_profit']['current']===null && $missing['financial_history']['gross_profit']['change_percent']===null, 'Incomplete historical profit withholds comparisons');
$pdo->exec("UPDATE sale_item_batches SET unit_cost=60,cost_status='recorded' WHERE sale_item_id=1");
$pdo->exec("UPDATE sales SET total_amount=0 WHERE sale_id=2");
$zero=$load();
$check($zero['financial_history']['net_sales']['previous']===0.0 && $zero['financial_history']['net_sales']['change_percent']===null, 'Zero previous sales have no comparable percentage');
$check($zero['financial_history']['gross_profit']['previous']===-60.0 && $zero['financial_history']['gross_profit']['change_percent']===null, 'Negative previous profit has no comparable percentage');
$pdo->exec("INSERT INTO sales (sale_id,gross_amount,total_amount,discount_type,discount_value,sale_date) VALUES
    (5,70,70,'none',0,'2026-10-26 00:00:00'),(6,80,80,'none',0,'2026-10-31 23:59:59'),
    (7,90,90,'none',0,'2026-11-01 00:00:00'),(8,999,999,'none',0,'2026-11-01 00:00:01')");
$pdo->exec('INSERT INTO sale_items VALUES (5,5,1,1,70,70),(6,6,1,1,80,80),(7,7,1,1,90,90),(8,8,1,1,999,999)');
$pdo->exec("INSERT INTO sale_item_batches VALUES (5,5,1,1,20,'recorded'),(6,6,1,1,20,'recorded'),(7,7,1,1,20,'recorded'),(8,8,1,1,20,'recorded')");
foreach (['2026-10-26 00:00:00+08:00'=>['2026-10-26','2026-10-01',1], '2026-11-01 00:00:00+08:00'=>['2026-10-26','2026-11-01',1]] as $instant=>$expected) {
    StoreOperationsDashboardWorkspace::invalidateCache();
    $boundary=(new StoreOperationsDashboardWorkspace($pdo,new FinancialDashboardClock($instant),new RoleCapabilityPolicy()))->load('admin');
    $check($boundary['gross_profit']['periods']['week']['date_range']['from']===$expected[0], 'Profit week begins Monday at Philippine midnight');
    $check($boundary['gross_profit']['periods']['month']['date_range']['from']===$expected[1], 'Profit month begins first Philippine calendar day');
    $check(count($boundary['gross_profit']['periods']['today']['points'])===$expected[2], 'Midnight profit includes only ongoing first hour');
    $check($boundary['gross_profit']['periods']['today']['total_gross_profit']===($expected[1]==='2026-11-01' ? 70.0 : 50.0), 'Midnight sale contributes only to its Philippine date, excluding next second');
}
$pdo->exec('DROP TABLE sale_item_batches');
$unavailable=$load();
$check($unavailable['gross_profit']===null && $unavailable['financial_history']===null && $unavailable['net_sales']!==null, 'Unavailable cost service retains sales and operational context');
StoreOperationsDashboardWorkspace::invalidateCache();
echo "Administrator dashboard financial tests: passed\n";
