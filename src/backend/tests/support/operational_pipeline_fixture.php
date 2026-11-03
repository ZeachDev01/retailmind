<?php
declare(strict_types=1);
// Disposable acceptance database only; all production behavior uses public services.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../../bootstrap/app.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../app/Services/NotificationService.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e); exit(1); });
$config = require __DIR__ . '/../../config/database.php';
$database = (string)$config['database'];
if (!preg_match('/^retailmind_acceptance_[a-f0-9]{12}$/D', $database)
    || $config['host'] !== '127.0.0.1' || getenv('RUN_DB_TESTS') !== '1') {
    throw new RuntimeException('Explicit disposable local acceptance database required.');
}
$pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $config['host'], $config['port']),
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$action = $argv[1] ?? '';
if ($action === 'setup') {
    $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE `{$database}`; SET FOREIGN_KEY_CHECKS=0");
    preg_match_all('/CREATE TABLE `[^`]+` \(.*?\) ENGINE=[^;]+;/s', file_get_contents(__DIR__ . '/../../database/sql/schema.sql'), $tables);
    foreach ($tables[0] as $ddl) $pdo->exec($ddl);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    foreach (['super_admin', 'admin', 'inventory_manager', 'cashier'] as $index => $role) {
        $id = $index + 1;
        $pdo->prepare('INSERT INTO roles (role_id,role_name) VALUES (?,?)')->execute([$id,$role]);
        $pdo->prepare('INSERT INTO users (user_id,first_name,last_name,display_name,username,password_hash,role_id,must_change_password) VALUES (?,?,?,?,?,?,?,0)')
            ->execute([$id,'Acceptance',$role,'Acceptance ' . $role,'acceptance_' . $role,password_hash('AcceptanceTest@2026',PASSWORD_DEFAULT),$id]);
    }
    (new App\Database\MigrationRunner($pdo, __DIR__ . '/../../database/migrations'))->runPending();
    $pdo->exec('ALTER TABLE demand_forecasts MODIFY model_version VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    $pdo->exec("INSERT INTO products (product_id,sku,barcode,product_name,unit_price,cost_price,reorder_level,safety_stock) VALUES
        (1,'ISOLATED-001','ISOLATED-001','Isolated synthetic history',25,10,10,3),
        (2,'ISOLATED-002','ISOLATED-002','Isolated excluded product',25,10,10,3);
        INSERT INTO inventory (product_id,quantity_on_hand) VALUES (1,0),(2,0);
        INSERT INTO platform_settings (setting_key,setting_value) VALUES
        ('ml_max_wape_7d','0.35'),('ml_max_wape_14d','0.35'),('ml_max_wape_28d','0.35')
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $pdo->exec("INSERT INTO registers (register_id,name,created_by) VALUES (1,'Isolated acceptance till',2);
        INSERT INTO fiscal_periods (period_name,start_date,end_date,status,created_by)
        VALUES ('Isolated acceptance','2026-01-01','2099-12-31','open',2)");
    echo '{}'; exit;
}
$pdo->exec("USE `{$database}`");
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
switch ($action) {
    case 'operations':
        require_once __DIR__ . '/../../app/Services/ReceivingService.php';
        require_once __DIR__ . '/../../app/Services/SalesWorkflowService.php';
        require_once __DIR__ . '/../../app/Services/CashRefundService.php';
        require_once __DIR__ . '/../../app/Services/InventoryCountService.php';
        $assert = static function (bool $condition, string $message): void {
            if (!$condition) throw new RuntimeException($message);
        };
        $jobState = static function () use ($pdo, $argv): array {
            $stmt = $pdo->prepare('SELECT job_id,status,attempts,started_at,completed_at FROM ml_jobs WHERE job_id=?');
            $stmt->execute([(int)$argv[2]]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        };
        $beforeJob = $jobState();
        $assert($beforeJob['status'] === $argv[3], 'Required forecasting state before public operations');
        $_SESSION = ['user_id' => 3, 'role' => 'inventory_manager'];
        $counts = new InventoryCountService($pdo, 'inventory_manager');
        $beforeStock = $counts->getProductBatchCoverage(2)['on_hand'];
        $receipt = (new ReceivingService($pdo))->receiveStock(['product_id' => 2, 'received_qty' => 20], 3);
        $assert($counts->getProductBatchCoverage(2)['on_hand'] === $beforeStock + 20, 'Receiving adds twenty Base Units');
        $_SESSION = ['user_id' => 4, 'role' => 'cashier'];
        $shifts = new App\Services\CashierShiftService($pdo);
        $shift = $shifts->getOpenShift(4);
        $shiftId = $shift ? (int)$shift['shift_id'] : $shifts->openShift(4, 'cashier', 1, 100);
        $cashBefore = (float)$shifts->calculateShift($shiftId)['calculated_expected_cash'];
        $sale = (new SalesWorkflowService($pdo))->checkout([['product_id' => 2, 'qty' => 2]], 4, 'cashier', 'cash', ['cash_received' => 50]);
        $assert((float)$sale['total'] === 50.0, 'Two units sell for fifty pesos');
        $assert($counts->getProductBatchCoverage(2)['on_hand'] === $beforeStock + 18, 'Sale depletes two Base Units');
        $refunds = new App\Services\CashRefundService($pdo, $shifts);
        $refundable = $refunds->refundableSaleForCashier(4, (int)$sale['sale_id']);
        $itemId = (int)$refundable['items'][0]['sale_item_id'];
        $refundId = $refunds->refund(4, 'cashier', (int)$sale['sale_id'], [$itemId => ['quantity' => 1, 'disposition' => 'restockable']], 'customer_return');
        $assert($refunds->refundableQuantity($itemId) === 1 && $refunds->refundableAmount((int)$sale['sale_id']) === 25.0, 'Refund leaves one unit and twenty-five pesos refundable');
        $assert($counts->getProductBatchCoverage(2)['on_hand'] === $beforeStock + 19, 'Refund restores one Base Unit');
        $cashAfter = (float)$shifts->calculateShift($shiftId)['calculated_expected_cash'];
        $assert($cashAfter === $cashBefore + 25, 'Drawer retains sale fifty less refund twenty-five');
        $_SESSION = ['user_id' => 3, 'role' => 'inventory_manager'];
        $coverage = $counts->getProductBatchCoverage(2);
        $batches = array_map(static fn(array $batch): array => ['batch_id' => $batch['batch_id'], 'physical_quantity' => $batch['remaining_quantity']], $coverage['batches']);
        $batches[count($batches)-1]['physical_quantity']--;
        $countId = $counts->recordCount(['product_id' => 2, 'physical_quantity' => $beforeStock + 18,
            'discrepancy_reason' => 'Isolated acceptance physical count one unit short', 'batches' => $batches], 3);
        $counts->approveCount($countId, 3);
        $afterStock = $counts->getProductBatchCoverage(2);
        $assert($afterStock['on_hand'] === $beforeStock + 18 && !$afterStock['has_mismatch'], 'Approved count reconciles stock and batches');
        $afterJob = $jobState();
        $assert($afterJob['status'] === $argv[3], 'Required forecasting state after public operations');
        echo json_encode(['boundary' => 'public workflow services; not HTTP', 'forecast_before' => $beforeJob, 'forecast_after' => $afterJob,
            'receiving_id' => $receipt['receiving_id'], 'sale_id' => $sale['sale_id'], 'refund_id' => $refundId,
            'shift_id' => $shiftId, 'count_id' => $countId, 'stock_before' => $beforeStock, 'stock_after' => $afterStock['on_hand'],
            'batch_sum' => $afterStock['batch_sum'], 'sale_total' => $sale['total'], 'refund_amount' => 25,
            'expected_cash_before' => $cashBefore, 'expected_cash_after' => $cashAfter,
            'timestamp_asia_manila' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM)]); break;
    case 'queue':
        $id = (new App\Services\MlJobService($pdo))->enqueue($argv[2], 1, 'super_admin');
        echo json_encode(['job_id' => $id]); break;
    case 'tick':
        $result = (new App\Forecasting\ForecastingSchedulerService($pdo))->runScheduler();
        (new NotificationService($pdo))->checkAndNotifyForecasts();
        echo json_encode($result); break;
    case 'summary':
        $activation = new App\Forecasting\StoreActivationService($pdo, null, null, $argv[3]);
        echo json_encode($activation->getApprovalSummary($argv[2], $yesterday)); break;
    case 'approve':
        $activation = new App\Forecasting\StoreActivationService($pdo, null, null, $argv[3]);
        echo json_encode($activation->activateStoreModel($argv[2], $yesterday, 2, 'Isolated synthetic workflow evidence only', 'admin')); break;
    case 'guidance':
        $forecasts = new App\Forecasting\DemandForecastService($pdo);
        $job = $forecasts->getLatestUsableJobId($today);
        echo json_encode(['job_id' => $job, 'coverage' => $forecasts->getCoverage($job, $today),
            'recommendations' => (new App\Forecasting\ReplenishmentQueueService($pdo))->getRecommendations($job, $today)]); break;
    default: throw new RuntimeException('Unknown acceptance fixture action.');
}
