<?php
require_once __DIR__ . '/../app/Services/ForecastHistoryImportService.php';
require_once __DIR__ . '/../app/Authorization/RoleCapabilityPolicy.php';
require_once __DIR__ . '/../app/Authorization/AuthorizationContext.php';

use App\Services\ForecastHistoryImportService;
use App\Authorization\RoleCapabilityPolicy;

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function parseCsv(string $csv): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    try {
        return ForecastHistoryImportService::parse($stream, [
            'SKU-A' => ['product_id' => 1, 'sku' => 'SKU-A', 'product_name' => 'Product A'],
        ], '2026-10-10');
    } finally {
        fclose($stream);
    }
}

$rows = parseCsv("\xEF\xBB\xBFsale_date,sku,quantity\n2026-09-01,SKU-A,12\n2026-09-02,SKU-A,0\n");
check(count($rows) === 2 && $rows[0]['quantity'] === 12 && $rows[1]['quantity'] === 0, 'Valid totals and zero demand must parse');
foreach ([
    "date,sku,quantity\n2026-09-01,SKU-A,1",
    "sale_date,sku,quantity\n2026-02-30,SKU-A,1",
    "sale_date,sku,quantity\n2026-10-10,SKU-A,1",
    "sale_date,sku,quantity\n2026-10-11,SKU-A,1",
    "sale_date,sku,quantity\n2026-09-01,OTHER-STORE,1",
    "sale_date,sku,quantity\n2026-09-01,SKU-A,-1",
    "sale_date,sku,quantity\n2026-09-01,SKU-A,1.5",
    "sale_date,sku,quantity\n2026-09-01,SKU-A,1000001",
    "sale_date,sku,quantity\n2026-09-01,SKU-A,1,extra",
    "sale_date,sku,quantity\n2026-09-01,SKU-A,1\n2026-09-01,SKU-A,2",
    "sale_date,sku,quantity\n",
] as $invalid) {
    $rejected = false;
    try { parseCsv($invalid); } catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'Invalid CSV was accepted: ' . $invalid);
}
$policy = new RoleCapabilityPolicy();
// The policy's plain role check does not require an Emergency Access session.
foreach (['admin', 'super_admin', 'inventory_manager'] as $role) {
    check($policy->allows($role, RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY), "{$role} must be able to import history");
}
check(!$policy->allows('cashier', RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY), 'Cashiers must not import forecast history');

if (in_array('--database', $argv, true)) {
    require __DIR__ . '/../config/db.php';
    // Temporary tables shadow application tables on this connection only.
    $pdo->exec("CREATE TEMPORARY TABLE products (product_id INT PRIMARY KEY, sku VARCHAR(80), product_name VARCHAR(80), branch_id INT, status VARCHAR(20)) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE sales (sale_id INT PRIMARY KEY, sale_date DATETIME) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE sale_items (sale_id INT, product_id INT, quantity INT) ENGINE=InnoDB");
    $pdo->exec("CREATE TEMPORARY TABLE forecast_sales_imports (product_id INT, sale_date DATE, quantity INT, imported_by INT, PRIMARY KEY(product_id, sale_date)) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO products VALUES (1, 'SKU-A', 'Product A', 1, 'active'), (2, 'SKU-B', 'Other store', 2, 'active')");
    function log_activity(...$args): void { $GLOBALS['import_audits'][] = $args[6]; }
    $service = new ForecastHistoryImportService($pdo, 1);
    check(count($service->products()) === 1, 'Product lookup must be store-scoped');
    check($service->import($rows, 1) === ['inserted' => 2, 'skipped' => 0], 'First import failed');
    check($service->import($rows, 1) === ['inserted' => 0, 'skipped' => 2], 'Duplicate import must be idempotent');
    $conflict = parseCsv("sale_date,sku,quantity\n2026-09-03,SKU-A,4\n2026-09-01,SKU-A,99");
    try { $service->import($conflict, 1); throw new LogicException('Conflict was accepted'); } catch (RuntimeException $e) {}
    check((int)$pdo->query('SELECT COUNT(*) FROM forecast_sales_imports')->fetchColumn() === 2, 'Conflicting import must roll back the entire batch');
    $pdo->exec("INSERT INTO sales VALUES (1, '2026-09-04 12:00:00')");
    $pdo->exec('INSERT INTO sale_items VALUES (1, 1, 3)');
    check($service->import(parseCsv("sale_date,sku,quantity\n2026-09-04,SKU-A,5"), 1) === ['inserted' => 0, 'skipped' => 1], 'POS overlap must be skipped');
    $pdo->exec("UPDATE products SET branch_id = 2 WHERE product_id = 1");
    try { $service->import($rows, 1); throw new LogicException('Other-store product was accepted'); } catch (RuntimeException $e) {}
    check(count($GLOBALS['import_audits']) === 3, 'Only successful imports should be audited');
}
echo "Forecast history import checks passed\n";
