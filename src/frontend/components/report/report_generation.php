<?php
// report/report_generation.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/PhilippineTime.php';
require_once __DIR__ . '/../../../backend/app/Services/GrossProfitService.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\GrossProfitService;
use App\Services\PhilippineTime;

require_capability(RoleCapabilityPolicy::VIEW_STORE_REPORTS);

$reportCatalog = [
    'sales_period' => 'Daily, Weekly and Monthly Sales',
    'sales_product' => 'Sales by Product',
    'sales_category' => 'Sales by Category',
    'sales_cashier' => 'Sales by Cashier',
    'refunds' => 'Refund History',
    'fast_moving' => 'Fast-Moving Products',
    'slow_moving' => 'Slow-Moving Products',
    'low_stock' => 'Low-Stock and Out-of-Stock Products',
    'inventory_valuation' => 'Inventory Valuation',
    'stock_movements' => 'Stock Movement History',
    'adjustments' => 'Damaged, Missing and Expired Items',
    'forecast_actual' => 'Forecast Versus Actual Demand',
    'expiration' => 'Expiration Report',
    'count_variance' => 'Physical-Count Variance Report',
];

if (current_role() === 'admin') {
    $reportCatalog['gross_profit'] = 'Gross Profit Report';
}

function normalize_date(string $value, string $default): string
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date ? $date->format('Y-m-d') : $default;
}

function request_value(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default));
}

function money_value($value): string
{
    return number_format((float)$value, 2);
}

function metric_value($value, int $decimals = 2): string
{
    return $value === null ? '-' : number_format((float)$value, $decimals);
}

function percentage_value($value, int $decimals = 2): string
{
    return $value === null ? '-' : number_format((float)$value, $decimals) . '%';
}

function send_csv(string $filename, array $rows, array $headers): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, array_map(static fn($header) => (string)($row[$header] ?? ''), $headers));
    }
    fclose($output);
    exit;
}

function product_filters(array $filters, string $alias = 'p'): array
{
    $where = ["{$alias}.branch_id = ?"];
    $params = [$filters['store_id']];

    if ($filters['product_id'] > 0) {
        $where[] = "{$alias}.product_id = ?";
        $params[] = $filters['product_id'];
    }

    if ($filters['category_id'] > 0) {
        $where[] = "{$alias}.category_id = ?";
        $params[] = $filters['category_id'];
    }

    return [$where, $params];
}

function run_report(PDO $pdo, string $report, array $filters): array
{
    $fromStart = $filters['from'] . ' 00:00:00';
    $toEnd = $filters['to'] . ' 23:59:59';
    [$productWhere, $productParams] = product_filters($filters);
    $productSql = $productWhere ? ' AND ' . implode(' AND ', $productWhere) : '';
    $saleUnitsSql = sales_units_sql($pdo, 'si');
    // Aggregate by sale line first so multiple partial refunds do not multiply
    // the original sold quantity or sales value in grouped sales reports.
    $refundJoin = 'LEFT JOIN (SELECT sale_item_id, SUM(subtotal) AS refunded_amount
                    FROM cash_refund_items GROUP BY sale_item_id) refunds
                   ON refunds.sale_item_id = si.sale_item_id';

    switch ($report) {
        case 'refunds':
            $stmt = $pdo->prepare(
                "SELECT cr.refund_id, cr.sale_id, cr.created_at, u.full_name AS cashier,
                        cr.payment_method, cr.reason, cr.note, cri.quantity, cri.subtotal,
                        cri.disposition, COALESCE(p.barcode, p.sku) AS sku, p.product_name
                 FROM cash_refunds cr
                 JOIN cash_refund_items cri ON cri.refund_id = cr.refund_id
                 JOIN users u ON u.user_id = cr.cashier_id
                 JOIN products p ON p.product_id = cri.product_id
                 WHERE cr.created_at BETWEEN ? AND ? {$productSql}
                 ORDER BY cr.created_at DESC, cr.refund_id DESC, cri.refund_item_id"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Refund', 'Sale', 'Issued At', 'Cashier', 'Method', 'Reason', 'Note', 'SKU', 'Product', 'Quantity', 'Disposition', 'Amount'],
                'rows' => array_map(static fn($row) => [
                    'Refund' => (int)$row['refund_id'],
                    'Sale' => (int)$row['sale_id'],
                    'Issued At' => $row['created_at'],
                    'Cashier' => $row['cashier'],
                    'Method' => strtoupper((string)$row['payment_method']),
                    'Reason' => \App\Services\CashRefundService::REFUND_REASONS[(string)$row['reason']] ?? $row['reason'],
                    'Note' => $row['note'] ?? '',
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Quantity' => (int)$row['quantity'],
                    'Disposition' => ucfirst((string)$row['disposition']),
                    'Amount' => money_value($row['subtotal']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_period':
            $granularity = in_array($filters['granularity'], ['daily', 'weekly', 'monthly'], true) ? $filters['granularity'] : 'daily';
            $periodExpr = [
                'daily' => 'DATE(s.sale_date)',
                'weekly' => "CONCAT(YEAR(s.sale_date), '-W', LPAD(WEEK(s.sale_date, 1), 2, '0'))",
                'monthly' => "DATE_FORMAT(s.sale_date, '%Y-%m')",
            ][$granularity];
            $stmt = $pdo->prepare(
                "SELECT {$periodExpr} AS period_label, COUNT(DISTINCT s.sale_id) AS receipts,
                        COALESCE(SUM({$saleUnitsSql}), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales,
                        COALESCE(SUM(refunds.refunded_amount), 0) AS refunds
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.sale_id
                 {$refundJoin}
                 JOIN products p ON p.product_id = si.product_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY period_label
                 ORDER BY MIN(s.sale_date) DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Period', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
                'rows' => array_map(static fn($row) => [
                    'Period' => $row['period_label'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                    'Refunds' => money_value($row['refunds']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_product':
        case 'fast_moving':
            $order = 'units_sold DESC, total_sales DESC';
            $limit = $report === 'sales_product' ? '' : ' LIMIT 25';
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(SUM({$saleUnitsSql}), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales,
                        COALESCE(SUM(refunds.refunded_amount), 0) AS refunds,
                        COUNT(DISTINCT s.sale_id) AS receipts
                 FROM sale_items si
                 {$refundJoin}
                 JOIN sales s ON s.sale_id = si.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY p.product_id, sku, p.product_name, category_name
                 ORDER BY {$order}{$limit}"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
                'rows' => array_map(static fn($row) => [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                    'Refunds' => money_value($row['refunds']),
                ], $stmt->fetchAll()),
            ];

        case 'slow_moving':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(SUM(CASE WHEN s.sale_id IS NOT NULL THEN {$saleUnitsSql} ELSE 0 END), 0) AS units_sold,
                        COALESCE(SUM(CASE WHEN s.sale_id IS NOT NULL THEN si.subtotal ELSE 0 END), 0) AS total_sales,
                        COALESCE(SUM(CASE WHEN s.sale_id IS NOT NULL THEN refunds.refunded_amount ELSE 0 END), 0) AS refunds,
                        COUNT(DISTINCT s.sale_id) AS receipts
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN sale_items si ON si.product_id = p.product_id
                 {$refundJoin}
                 LEFT JOIN sales s ON s.sale_id = si.sale_id AND s.sale_date BETWEEN ? AND ?
                 WHERE 1=1 {$productSql}
                 GROUP BY p.product_id, sku, p.product_name, category_name
                 ORDER BY units_sold ASC, total_sales ASC, p.product_name
                 LIMIT 25"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
                'rows' => array_map(static fn($row) => [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                    'Refunds' => money_value($row['refunds']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_category':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(c.category_name, 'Uncategorized') AS category_name, COUNT(DISTINCT p.product_id) AS products_sold,
                        COUNT(DISTINCT s.sale_id) AS receipts, COALESCE(SUM({$saleUnitsSql}), 0) AS units_sold,
                        COALESCE(SUM(si.subtotal), 0) AS total_sales,
                        COALESCE(SUM(refunds.refunded_amount), 0) AS refunds
                 FROM sale_items si
                 {$refundJoin}
                 JOIN sales s ON s.sale_id = si.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY category_name
                 ORDER BY total_sales DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Category', 'Products Sold', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
                'rows' => array_map(static fn($row) => [
                    'Category' => $row['category_name'],
                    'Products Sold' => (int)$row['products_sold'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                    'Refunds' => money_value($row['refunds']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_cashier':
            $stmt = $pdo->prepare(
                "SELECT u.full_name AS cashier, COUNT(DISTINCT s.sale_id) AS receipts,
                        COALESCE(SUM({$saleUnitsSql}), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales,
                        COALESCE(SUM(refunds.refunded_amount), 0) AS refunds
                 FROM sales s
                 JOIN users u ON u.user_id = s.cashier_id
                 LEFT JOIN sale_items si ON si.sale_id = s.sale_id
                 {$refundJoin}
                 LEFT JOIN products p ON p.product_id = si.product_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY u.user_id, u.full_name
                 ORDER BY total_sales DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Cashier', 'Receipts', 'Units Sold', 'Total Sales', 'Refunds'],
                'rows' => array_map(static fn($row) => [
                    'Cashier' => $row['cashier'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                    'Refunds' => money_value($row['refunds']),
                ], $stmt->fetchAll()),
            ];

        case 'low_stock':
        case 'inventory_valuation':
            $stockCondition = $report === 'low_stock' ? ' AND COALESCE(i.quantity_on_hand, 0) <= COALESCE(p.reorder_level, 0)' : '';
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, COALESCE(p.reorder_level, 0) AS reorder_level,
                        p.cost_price, p.unit_price, COALESCE(i.quantity_on_hand, 0) * p.cost_price AS inventory_value,
                        CASE WHEN COALESCE(i.quantity_on_hand, 0) = 0 THEN 'Out of stock' ELSE 'Low stock' END AS stock_status
                 FROM products p
                 LEFT JOIN inventory i ON i.product_id = p.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE 1=1 {$productSql}{$stockCondition}
                 ORDER BY quantity_on_hand ASC, p.product_name"
            );
            $stmt->execute($productParams);
            $headers = $report === 'low_stock'
                ? ['SKU', 'Product', 'Category', 'Qty On Hand', 'Reorder Level', 'Status']
                : ['SKU', 'Product', 'Category', 'Qty On Hand', 'Cost Price', 'Retail Price', 'Profit', 'Inventory Value'];
            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                $rows[] = $report === 'low_stock'
                    ? [
                        'SKU' => $row['sku'],
                        'Product' => $row['product_name'],
                        'Category' => $row['category_name'],
                        'Qty On Hand' => (int)$row['quantity_on_hand'],
                        'Reorder Level' => (int)$row['reorder_level'],
                        'Status' => $row['stock_status'],
                    ]
                    : [
                        'SKU' => $row['sku'],
                        'Product' => $row['product_name'],
                        'Category' => $row['category_name'],
                        'Qty On Hand' => (int)$row['quantity_on_hand'],
                        'Cost Price' => money_value($row['cost_price']),
                        'Retail Price' => money_value($row['unit_price']),
                        'Profit' => money_value((float)($row['cost_price'] ?? 0) - (float)($row['unit_price'] ?? 0)),
                        'Inventory Value' => money_value($row['inventory_value']),
                    ];
            }
            return ['headers' => $headers, 'rows' => $rows];

        case 'stock_movements':
            $stmt = $pdo->prepare(
                "SELECT sm.moved_at, COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        sm.change_qty, sm.reason, COALESCE(u.full_name, 'System') AS moved_by
                 FROM stock_movements sm
                 JOIN products p ON p.product_id = sm.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN users u ON u.user_id = sm.moved_by
                 WHERE sm.moved_at BETWEEN ? AND ? {$productSql}
                 ORDER BY sm.moved_at DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Date', 'SKU', 'Product', 'Category', 'Change Qty', 'Reason', 'Moved By'],
                'rows' => array_map(static fn($row) => [
                    'Date' => $row['moved_at'],
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Change Qty' => (int)$row['change_qty'],
                    'Reason' => ucfirst((string)$row['reason']),
                    'Moved By' => $row['moved_by'],
                ], $stmt->fetchAll()),
            ];

        case 'adjustments':
            $stmt = $pdo->prepare(
                "SELECT ia.reported_at, COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        ia.adjustment_type, ia.adjustment_qty, ia.status,
                        COALESCE(reporter.full_name, 'Unknown') AS reported_by,
                        COALESCE(approver.full_name, '-') AS approved_by, ia.reason
                 FROM inventory_adjustments ia
                 JOIN products p ON p.product_id = ia.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN users reporter ON reporter.user_id = ia.reported_by
                 LEFT JOIN users approver ON approver.user_id = ia.approved_by
                 WHERE ia.reported_at BETWEEN ? AND ? {$productSql}
                 ORDER BY ia.reported_at DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Date', 'SKU', 'Product', 'Category', 'Type', 'Qty', 'Status', 'Reported By', 'Approved By', 'Reason'],
                'rows' => array_map(static fn($row) => [
                    'Date' => $row['reported_at'],
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Type' => ucfirst((string)$row['adjustment_type']),
                    'Qty' => (int)$row['adjustment_qty'],
                    'Status' => ucfirst((string)$row['status']),
                    'Reported By' => $row['reported_by'],
                    'Approved By' => $row['approved_by'],
                    'Reason' => $row['reason'],
                ], $stmt->fetchAll()),
            ];

        case 'forecast_actual':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        'DemandForecaster' AS model_name, df.model_version,
                        28 AS forecast_period_days,
                        df.forecast_origin AS generated_at,
                        DATE_ADD(df.forecast_origin, INTERVAL 28 DAY) AS forecast_completed_at,
                        SUM(df.predicted_units) AS forecast_quantity,
                        (
                            SELECT COALESCE(SUM({$saleUnitsSql}), 0)
                            FROM sale_items si
                            JOIN sales s ON s.sale_id = si.sale_id
                            WHERE si.product_id = df.product_id
                              AND s.sale_date >= df.forecast_origin
                              AND s.sale_date < DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)
                        ) AS actual_quantity,
                        MAX(df.readiness_level) AS readiness_level
                 FROM demand_forecasts df
                 JOIN ml_jobs j ON j.job_id = df.job_id
                 JOIN products p ON p.product_id = df.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE DATE_ADD(df.forecast_origin, INTERVAL 28 DAY) <= NOW()
                   AND j.trigger_type = 'forecast_generation' AND j.status = 'succeeded'
                   AND DATE(DATE_ADD(df.forecast_origin, INTERVAL 28 DAY)) BETWEEN ? AND ?
                   {$productSql}
                 GROUP BY df.job_id, df.product_id, df.forecast_origin, p.barcode, p.sku, p.product_name, c.category_name, df.model_version
                 ORDER BY forecast_completed_at DESC,
                          ABS(actual_quantity - forecast_quantity) DESC"
            );
            $stmt->execute(array_merge([$filters['from'], $filters['to']], $productParams));

            $rows = [];
            $sumAbsoluteError = 0.0;
            $sumSquaredError = 0.0;
            $actualValues = [];
            $actualTotal = 0;
            $forecastTotal = 0;

            foreach ($stmt->fetchAll() as $row) {
                $forecast = (int)$row['forecast_quantity'];
                $actual = (int)$row['actual_quantity'];
                $difference = $actual - $forecast;
                $absoluteError = abs($difference);
                $squaredError = $difference ** 2;
                $percentageError = $actual > 0 ? ($absoluteError / $actual) * 100 : ($forecast === 0 ? 0.0 : null);

                $sumAbsoluteError += $absoluteError;
                $sumSquaredError += $squaredError;
                $actualValues[] = $actual;
                $actualTotal += $actual;
                $forecastTotal += $forecast;

                $rows[] = [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Forecast Period' => (int)$row['forecast_period_days'] . ' days',
                    'Generated At' => $row['generated_at'],
                    'Completed At' => $row['forecast_completed_at'],
                    'Forecast' => $forecast,
                    'Actual Sales' => $actual,
                    'Difference' => $difference,
                    'Percentage Error' => percentage_value($percentageError),
                    'Readiness' => $row['readiness_level'],
                    'Model' => trim((string)$row['model_name'] . ' ' . (string)$row['model_version']),
                ];
            }

            $count = count($rows);
            $mae = $count > 0 ? $sumAbsoluteError / $count : null;
            $rmse = $count > 0 ? sqrt($sumSquaredError / $count) : null;
            $r2 = null;

            if ($count > 1) {
                $actualMean = array_sum($actualValues) / $count;
                $totalVariance = array_reduce(
                    $actualValues,
                    static fn($sum, $actual) => $sum + (($actual - $actualMean) ** 2),
                    0.0
                );
                $r2 = $totalVariance > 0 ? 1 - ($sumSquaredError / $totalVariance) : null;
            }

            return [
                'headers' => ['SKU', 'Product', 'Category', 'Forecast Period', 'Generated At', 'Completed At', 'Forecast', 'Actual Sales', 'Difference', 'Percentage Error', 'Readiness', 'Model'],
                'rows' => $rows,
                'metrics' => [
                    'Completed Periods' => number_format($count),
                    'Total Forecast' => number_format($forecastTotal),
                    'Total Actual Sales' => number_format($actualTotal),
                    'MAE' => metric_value($mae),
                    'RMSE' => metric_value($rmse),
                    'R^2' => metric_value($r2, 4),
                ],
            ];

        case 'gross_profit':
            GrossProfitService::enforceAdministratorWorkspace(current_role());
            return (new GrossProfitService($pdo))->runReport($filters);

        case 'expiration':
            $todayManila = PhilippineTime::today();
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(pb.batch_number, '-') AS batch_number,
                        pb.remaining_quantity AS quantity,
                        pb.expiration_date AS expiration_date,
                        DATEDIFF(pb.expiration_date, ?) AS days_remaining
                 FROM product_batches pb
                 JOIN products p ON p.product_id = pb.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE pb.expiration_date IS NOT NULL AND pb.remaining_quantity > 0 {$productSql}
                 ORDER BY pb.expiration_date ASC, p.product_name"
            );
            $stmt->execute(array_merge([$todayManila], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Batch', 'Qty', 'Expiration Date', 'Days Remaining', 'Status'],
                'rows' => array_map(static function ($row) {
                    $days = (int)$row['days_remaining'];
                    return [
                        'SKU' => $row['sku'],
                        'Product' => $row['product_name'],
                        'Category' => $row['category_name'],
                        'Batch' => $row['batch_number'],
                        'Qty' => (int)$row['quantity'],
                        'Expiration Date' => $row['expiration_date'],
                        'Days Remaining' => $days,
                        'Status' => $days < 0 ? 'Expired' : ($days <= 30 ? 'Expiring soon' : 'Active'),
                    ];
                }, $stmt->fetchAll()),
            ];

        case 'count_variance':
            $stmt = $pdo->prepare(
                "SELECT ic.counted_at, COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        ic.system_quantity, ic.physical_quantity, ic.difference_qty,
                        ic.status, COALESCE(counter.full_name, 'Unknown') AS counted_by,
                        COALESCE(approver.full_name, '-') AS approved_by, ic.discrepancy_reason
                 FROM inventory_counts ic
                 JOIN products p ON p.product_id = ic.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN users counter ON counter.user_id = ic.counted_by
                 LEFT JOIN users approver ON approver.user_id = ic.approved_by
                 WHERE ic.counted_at BETWEEN ? AND ? {$productSql}
                 ORDER BY ABS(ic.difference_qty) DESC, ic.counted_at DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Date', 'SKU', 'Product', 'Category', 'System Qty', 'Physical Qty', 'Variance', 'Status', 'Counted By', 'Approved By', 'Reason'],
                'rows' => array_map(static fn($row) => [
                    'Date' => $row['counted_at'],
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'System Qty' => (int)$row['system_quantity'],
                    'Physical Qty' => (int)$row['physical_quantity'],
                    'Variance' => (int)$row['difference_qty'],
                    'Status' => ucfirst((string)$row['status']),
                    'Counted By' => $row['counted_by'],
                    'Approved By' => $row['approved_by'],
                    'Reason' => $row['discrepancy_reason'],
                ], $stmt->fetchAll()),
            ];
    }

    return ['headers' => [], 'rows' => []];
}

$selectedReport = request_value('report', 'sales_period');
if ($selectedReport === 'gross_profit') {
    GrossProfitService::enforceAdministratorWorkspace(current_role());
}
if (!array_key_exists($selectedReport, $reportCatalog)) {
    $selectedReport = 'sales_period';
}

$tz = new DateTimeZone(PhilippineTime::ZONE);
$nowPh = new DateTimeImmutable('now', $tz);
$todayPh = $nowPh->format('Y-m-d');
$dayOfWeek = (int)$nowPh->format('N');
$daysFromMonday = $dayOfWeek - 1;
$weekStartPh = $nowPh->modify("-{$daysFromMonday} days")->format('Y-m-d');
$monthStartPh = $nowPh->setDate((int)$nowPh->format('Y'), (int)$nowPh->format('n'), 1)->format('Y-m-d');

$requestedPeriod = request_value('period', '');
$rawFrom = request_value('from', '');
$rawTo = request_value('to', '');

if ($requestedPeriod === 'today') {
    $fromVal = $todayPh;
    $toVal = $todayPh;
    $activePeriod = 'today';
} elseif ($requestedPeriod === 'week') {
    $fromVal = $weekStartPh;
    $toVal = $todayPh;
    $activePeriod = 'week';
} elseif ($requestedPeriod === 'month') {
    $fromVal = $monthStartPh;
    $toVal = $todayPh;
    $activePeriod = 'month';
} elseif ($rawFrom !== '' || $rawTo !== '') {
    $fromVal = $rawFrom;
    $toVal = $rawTo !== '' ? $rawTo : $todayPh;
    if ($fromVal === $todayPh && $toVal === $todayPh) {
        $activePeriod = 'today';
    } elseif ($fromVal === $weekStartPh && $toVal === $todayPh) {
        $activePeriod = 'week';
    } elseif ($fromVal === $monthStartPh && $toVal === $todayPh) {
        $activePeriod = 'month';
    } else {
        $activePeriod = 'custom';
    }
} else {
    $fromVal = $monthStartPh;
    $toVal = $todayPh;
    $activePeriod = 'month';
}

$validationError = null;
$fromDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fromVal, $tz);
$toDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $toVal, $tz);

if ($fromDateObj === false || $fromDateObj->format('Y-m-d') !== $fromVal
    || $toDateObj === false || $toDateObj->format('Y-m-d') !== $toVal) {
    $validationError = 'Choose a valid Philippine calendar date.';
} elseif (strcmp($fromVal, $toVal) > 0) {
    $validationError = 'Start date must not be after end date.';
}

$filters = [
    'store_id' => store_scope_id($pdo),
    'from' => $fromVal,
    'to' => $toVal,
    'product_id' => max(0, (int)request_value('product_id', '0')),
    'category_id' => max(0, (int)request_value('category_id', '0')),
    'granularity' => request_value('granularity', 'daily'),
    'period' => $activePeriod,
];

$productStatement = $pdo->prepare(
    "SELECT p.product_id, COALESCE(p.barcode, p.sku) AS sku, p.product_name
     FROM products p
     WHERE p.branch_id = ?
     ORDER BY p.product_name"
);
$productStatement->execute([$filters['store_id']]);
$products = $productStatement->fetchAll();
$categoryStatement = $pdo->prepare(
    'SELECT DISTINCT c.category_id, c.category_name
     FROM categories c JOIN products p ON p.category_id = c.category_id
     WHERE p.branch_id = ? ORDER BY c.category_name'
);
$categoryStatement->execute([$filters['store_id']]);
$categories = $categoryStatement->fetchAll();

$reportData = ['headers' => [], 'rows' => [], 'metrics' => [], 'summary' => null];
$loadError = null;

if ($validationError === null) {
    try {
        $reportData = run_report($pdo, $selectedReport, $filters);
    } catch (InvalidArgumentException $e) {
        $validationError = $e->getMessage();
    } catch (DomainException $e) {
        throw $e;
    } catch (\Throwable $e) {
        $loadError = 'Gross profit report data could not be loaded.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    $exportReport = request_value('report', 'sales_period');
    if ($exportReport === 'gross_profit') {
        GrossProfitService::enforceAdministratorWorkspace(current_role());
    }
    if ($validationError === null && !empty($reportData['rows'])) {
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Report generation',
            'Reports',
            null,
            null,
            [
                'report_type' => $selectedReport,
                'format' => 'csv',
                'filters' => $filters,
                'row_count' => count($reportData['rows']),
            ]
        );
        send_csv($selectedReport . '_' . date('Ymd_His') . '.csv', $reportData['rows'], $reportData['headers']);
    }
}

$totalRows = count($reportData['rows']);
$pageTitle = $selectedReport === 'gross_profit' ? 'Gross Profit Report' : 'Report Generation';
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1 id="page-heading"><?= htmlspecialchars($pageTitle) ?></h1>
                <p class="page-subtitle"><?= $selectedReport === 'gross_profit' ? 'Product gross profit, sales, cost, and margin.' : 'Sales, inventory, forecast, and variance reports.' ?></p>
            </div>
            <?php if ($selectedReport === 'gross_profit'): ?>
                <div class="topbar-actions">
                    <a href="<?= htmlspecialchars(app_url('components/administrator/dashboard.php') . '?' . http_build_query(array_filter(['period' => in_array($activePeriod, ['today', 'week', 'month'], true) ? $activePeriod : null, 'from' => $filters['from'], 'to' => $filters['to']]))) ?>" class="btn btn-secondary back-to-dashboard-btn" id="back-to-dashboard">
                        &larr; Back to dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <div class="dashboard-section report-filters">
            <form method="GET" class="report-filter-grid" id="report-filter-form">
                <div class="form-group">
                    <label for="report">Report</label>
                    <select id="report" name="report">
                        <?php foreach ($reportCatalog as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $selectedReport === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($selectedReport === 'gross_profit'): ?>
                    <div class="form-group period-controls-group" id="period_group">
                        <label>Period</label>
                        <div class="period-controls" role="group" aria-label="Report period">
                            <a href="?report=gross_profit&period=today" class="period-btn <?= $activePeriod === 'today' ? 'is-active' : '' ?>" data-period="today">Today</a>
                            <a href="?report=gross_profit&period=week" class="period-btn <?= $activePeriod === 'week' ? 'is-active' : '' ?>" data-period="week">This Week</a>
                            <a href="?report=gross_profit&period=month" class="period-btn <?= $activePeriod === 'month' ? 'is-active' : '' ?>" data-period="month">This Month</a>
                            <span class="period-btn <?= $activePeriod === 'custom' ? 'is-active' : '' ?>" data-period="custom">Custom</span>
                        </div>
                        <input type="hidden" name="period" id="period_input" value="<?= htmlspecialchars($activePeriod) ?>">
                    </div>
                <?php else: ?>
                    <div class="form-group" id="granularity_group">
                        <label for="granularity">Sales Period</label>
                        <select id="granularity" name="granularity">
                            <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= $filters['granularity'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="form-group">
                    <label for="from">From</label>
                    <input type="date" id="from" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
                </div>
                <div class="form-group">
                    <label for="to">To</label>
                    <input type="date" id="to" name="to" value="<?= htmlspecialchars($filters['to']) ?>">
                </div>
                <div class="form-group">
                    <label for="product_id">Product</label>
                    <select id="product_id" name="product_id">
                        <option value="0">All products</option>
                        <?php foreach ($products as $product): ?>
                            <option value="<?= (int)$product['product_id'] ?>" <?= $filters['product_id'] === (int)$product['product_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($product['product_name'] . ' (' . $product['sku'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="category_id">Category</label>
                    <select id="category_id" name="category_id">
                        <option value="0">All categories</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int)$category['category_id'] ?>" <?= $filters['category_id'] === (int)$category['category_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn">Run Report</button>
            </form>
        </div>

        <?php if ($validationError !== null): ?>
            <div class="alert alert-danger u-my-1" role="alert" id="gp-validation-error"><?= htmlspecialchars($validationError) ?></div>
        <?php elseif ($loadError !== null): ?>
            <div class="alert alert-danger u-my-1" role="alert" id="gp-load-error"><?= htmlspecialchars($loadError) ?></div>
        <?php endif; ?>

        <?php if ($validationError === null && $loadError === null): ?>
            <?php if ($selectedReport === 'gross_profit'): ?>
                <!-- Gross Profit Layout C -->
                <div class="dashboard-section u-mt-15">
                    <div class="section-header">
                        <div>
                            <h2 id="gross-profit-heading">Gross Profit Report</h2>
                            <div class="report-meta">
                                <span><?= htmlspecialchars($filters['from']) ?> to <?= htmlspecialchars($filters['to']) ?></span>
                                <span><?= number_format($totalRows) ?> product<?= $totalRows === 1 ? '' : 's' ?></span>
                                <span class="badge"><?= ucfirst($activePeriod) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="gross-profit-layout-c">
                        <!-- Left Panel on desktop: Product Table -->
                        <div class="gp-table-panel">
                            <p class="net-units-note" id="net-units-explanation">
                                <strong>Net units:</strong> Units sold minus units refunded in this period. Negative values can be refunds for earlier sales.
                            </p>

                            <div class="gp-table-wrap" tabindex="0" role="region" aria-label="Products gross profit table container">
                                <table class="gp-product-table" aria-label="Gross Profit by Product">
                                    <thead>
                                        <tr>
                                            <th scope="col">Product</th>
                                            <th scope="col" class="num-cell">Net Units</th>
                                            <th scope="col" class="num-cell">Net Sales</th>
                                            <th scope="col" class="num-cell">Product Cost</th>
                                            <th scope="col" class="num-cell">Gross Profit</th>
                                            <th scope="col">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reportData['rows'] as $row): 
                                            $raw = $row['raw'] ?? [];
                                            $isInc = !empty($raw['is_incomplete']);
                                            $profitVal = $raw['gross_profit'] ?? null;
                                            $costVal = $raw['net_cost'] ?? null;
                                            $salesVal = (float)($raw['net_sales'] ?? 0);
                                            $unitsVal = (int)($raw['net_units'] ?? 0);
                                            $statusVal = strtolower((string)($raw['status'] ?? 'recorded'));
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="product-identity">
                                                        <span class="product-name font-weight-bold"><?= htmlspecialchars($row['Product']) ?></span>
                                                        <span class="product-meta text-muted small"><?= htmlspecialchars($row['SKU']) ?> &middot; <?= htmlspecialchars($row['Category']) ?></span>
                                                    </div>
                                                </td>
                                                <td class="num-cell <?= $unitsVal < 0 ? 'is-negative' : '' ?>">
                                                    <?= number_format($unitsVal) ?>
                                                </td>
                                                <td class="num-cell <?= $salesVal < 0 ? 'is-negative' : '' ?>">
                                                    <?= $salesVal < 0 ? '-₱' : '₱' ?><?= number_format(abs($salesVal), 2) ?>
                                                </td>
                                                <td class="num-cell <?= $costVal !== null && $costVal < 0 ? 'is-negative' : '' ?>">
                                                    <?= $isInc ? '—' : ($costVal !== null && $costVal < 0 ? '-₱' . number_format(abs($costVal), 2) : '₱' . number_format((float)$costVal, 2)) ?>
                                                </td>
                                                <td class="num-cell <?= $profitVal !== null && $profitVal < 0 ? 'is-negative' : '' ?>">
                                                    <?= $isInc ? '—' : ($profitVal !== null && $profitVal < 0 ? '-₱' . number_format(abs($profitVal), 2) : '₱' . number_format((float)$profitVal, 2)) ?>
                                                </td>
                                                <td>
                                                    <span class="profit-status-badge status-<?= $statusVal ?>"><?= htmlspecialchars(ucfirst($statusVal)) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($reportData['rows'])): ?>
                                            <tr>
                                                <td colspan="6" class="empty-table-message" style="text-align:center; padding: 2.5rem 1rem; color: var(--muted);">
                                                    No sales or refund activity in the selected period.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Right Panel on desktop: At a glance summary -->
                        <div class="gp-summary-panel">
                            <?php 
                                $summary = $reportData['summary'] ?? [];
                                $sumStatus = (string)($summary['status'] ?? 'recorded');
                                $isSumInc = $sumStatus === 'incomplete';
                                $sumNetSales = (float)($summary['total_net_sales'] ?? 0);
                                $sumNetCost = $summary['total_net_cost'] ?? null;
                                $sumProfit = $summary['total_gross_profit'] ?? null;
                                $sumMargin = $summary['total_margin'] ?? null;
                            ?>
                            <div class="gp-glance-card" id="at-a-glance-summary">
                                <h3>At a glance</h3>
                                <div class="glance-summary-list">
                                    <div class="glance-line">
                                        <span class="glance-label">Sales before deductions</span>
                                        <span class="glance-val" id="glance-gross-sales">₱<?= number_format((float)($summary['total_gross_sales'] ?? 0), 2) ?></span>
                                    </div>
                                    <div class="glance-line">
                                        <span class="glance-label">Less discounts</span>
                                        <span class="glance-val deduction" id="glance-discounts">-₱<?= number_format((float)($summary['total_discounts'] ?? 0), 2) ?></span>
                                    </div>
                                    <div class="glance-line">
                                        <span class="glance-label">Less refunds issued</span>
                                        <span class="glance-val deduction" id="glance-refunds">-₱<?= number_format((float)($summary['total_refunds'] ?? 0), 2) ?></span>
                                    </div>
                                    <div class="glance-line highlight-net">
                                        <span class="glance-label">Net Sales</span>
                                        <span class="glance-val <?= $sumNetSales < 0 ? 'is-negative' : '' ?>" id="glance-net-sales"><?= $sumNetSales < 0 ? '-₱' : '₱' ?><?= number_format(abs($sumNetSales), 2) ?></span>
                                    </div>
                                    <div class="glance-line">
                                        <span class="glance-label">Less cost of products sold</span>
                                        <span class="glance-val <?= $isSumInc ? 'is-incomplete' : 'deduction' ?>" id="glance-net-cost">
                                            <?= $isSumInc || $sumNetCost === null ? '—' : ($sumNetCost < 0 ? '₱' . number_format(abs($sumNetCost), 2) : '-₱' . number_format($sumNetCost, 2)) ?>
                                        </span>
                                    </div>
                                    <div class="glance-line highlight-profit <?= !$isSumInc && $sumProfit !== null ? ($sumProfit > 0 && $sumStatus === 'recorded' ? 'is-positive' : ($sumProfit < 0 ? 'is-negative' : '')) : '' ?>">
                                        <span class="glance-label">Gross Profit</span>
                                        <span class="glance-val <?= !$isSumInc && $sumProfit !== null && $sumProfit < 0 ? 'is-negative' : '' ?>" id="glance-gross-profit">
                                            <?= $isSumInc || $sumProfit === null ? '—' : ($sumProfit < 0 ? '-₱' . number_format(abs($sumProfit), 2) : '₱' . number_format($sumProfit, 2)) ?>
                                        </span>
                                    </div>
                                    <?php if ($sumStatus === 'recorded' && $sumNetSales > 0 && $sumMargin !== null): ?>
                                        <div class="glance-margin-badge" id="glance-margin">
                                            <span>Profit Margin:</span>
                                            <strong class="<?= $sumMargin < 0 ? 'is-negative' : '' ?>"><?= number_format($sumMargin, 2) ?>%</strong>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($isSumInc): ?>
                                    <div class="profit-incomplete-explanation" id="profit-incomplete-explanation" role="alert">
                                        <span class="alert-icon" aria-hidden="true">⚠️</span>
                                        <div>
                                            <strong><?= (int)($summary['affected_lines_count'] ?? 0) ?> affected sold line<?= ($summary['affected_lines_count'] ?? 0) === 1 ? '' : 's' ?></strong> missing cost snapshots.
                                            Full cost, gross profit, and margin are withheld to prevent misleading financial totals.
                                        </div>
                                    </div>
                                    <div class="known-costs-subtotal" id="known-costs-subtotal">
                                        <h4>Known-Cost Subtotal (Partial)</h4>
                                        <div class="known-subtotal-grid">
                                            <div><span class="known-label">Known Net Sales</span> <span class="known-val">₱<?= number_format((float)($summary['known_net_sales'] ?? 0), 2) ?></span></div>
                                            <div><span class="known-label">Known Product Cost</span> <span class="known-val">₱<?= number_format((float)($summary['known_net_cost'] ?? 0), 2) ?></span></div>
                                            <div><span class="known-label">Known Gross Profit</span> <span class="known-val">₱<?= number_format((float)($summary['known_gross_profit'] ?? 0), 2) ?></span></div>
                                            <?php if (($summary['known_margin'] ?? null) !== null): ?>
                                                <div><span class="known-label">Known Margin</span> <span class="known-val"><?= number_format((float)$summary['known_margin'], 2) ?>%</span></div>
                                            <?php endif; ?>
                                        </div>
                                        <p class="small text-muted" style="margin: 0.5rem 0 0;">Excludes lines without cost snapshots.</p>
                                    </div>
                                <?php endif; ?>

                                <details class="profit-return-help" id="profit-return-help">
                                    <summary>How returns affect profit</summary>
                                    <div class="return-help-drawer">
                                        <p><strong>Restockable returns:</strong> Return their product cost in this period.</p>
                                        <p><strong>Damaged / Disposed returns:</strong> Do not return product cost because the merchandise cannot be resold.</p>
                                    </div>
                                </details>

                                <p class="profit-exclusion-note" id="profit-exclusion-note">Gross profit is product revenue minus product cost; operating expenses are excluded.</p>
                            </div>
                        </div>
                    </div>

                    <div class="report-actions no-print">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="export_csv">
                            <input type="hidden" name="report" value="<?= htmlspecialchars($selectedReport) ?>">
                            <input type="hidden" name="period" value="<?= htmlspecialchars($activePeriod) ?>">
                            <input type="hidden" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
                            <input type="hidden" name="to" value="<?= htmlspecialchars($filters['to']) ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$filters['product_id'] ?>">
                            <input type="hidden" name="category_id" value="<?= (int)$filters['category_id'] ?>">
                            <button type="submit" class="btn" id="export-csv-btn">Export CSV</button>
                        </form>
                        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
                        <button type="button" class="btn btn-secondary" onclick="window.print()">Export PDF</button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Ordinary Reports (preserved) -->
                <div class="dashboard-section u-mt-15">
                    <div class="section-header">
                        <div>
                            <h2><?= htmlspecialchars($reportCatalog[$selectedReport]) ?></h2>
                            <div class="report-meta">
                                <span><?= htmlspecialchars($filters['from']) ?> to <?= htmlspecialchars($filters['to']) ?></span>
                                <span><?= number_format($totalRows) ?> row<?= $totalRows === 1 ? '' : 's' ?></span>
                            </div>
                        </div>
                    </div>
                    <h2 class="report-print-title"><?= htmlspecialchars($reportCatalog[$selectedReport]) ?></h2>
                    <?php if (in_array($selectedReport, ['sales_period', 'sales_product', 'sales_category', 'sales_cashier', 'fast_moving', 'slow_moving'], true)): ?>
                        <p class="section-description">Refunds are separate from sales and grouped by sale date.</p>
                    <?php elseif ($selectedReport === 'refunds'): ?>
                        <p class="section-description">Refunds are grouped by issue date.</p>
                    <?php endif; ?>

                    <?php if (!empty($reportData['metrics'])): ?>
                        <div class="card-grid u-my-1">
                            <?php foreach ($reportData['metrics'] as $label => $value): ?>
                                <div class="stat-card">
                                    <div class="value"><?= htmlspecialchars((string)$value) ?></div>
                                    <div class="label"><?= htmlspecialchars((string)$label) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <?php foreach ($reportData['headers'] as $header): ?>
                                        <th><?= htmlspecialchars($header) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reportData['rows'] as $row): ?>
                                    <tr>
                                        <?php foreach ($reportData['headers'] as $header): ?>
                                            <td><?= htmlspecialchars((string)($row[$header] ?? '')) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$reportData['rows']): ?>
                                    <tr><td colspan="<?= max(1, count($reportData['headers'])) ?>" style="text-align:center;color:var(--muted);">No records match these filters.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="report-actions no-print">
                        <form method="POST">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="export_csv">
                            <input type="hidden" name="report" value="<?= htmlspecialchars($selectedReport) ?>">
                            <input type="hidden" name="granularity" value="<?= htmlspecialchars($filters['granularity']) ?>">
                            <input type="hidden" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
                            <input type="hidden" name="to" value="<?= htmlspecialchars($filters['to']) ?>">
                            <input type="hidden" name="product_id" value="<?= (int)$filters['product_id'] ?>">
                            <input type="hidden" name="category_id" value="<?= (int)$filters['category_id'] ?>">
                            <button type="submit" class="btn">Export CSV</button>
                        </form>
                        <button type="button" class="btn btn-secondary" onclick="window.print()">Print</button>
                        <button type="button" class="btn btn-secondary" onclick="window.print()">Export PDF</button>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
<script>
const reportSelect = document.getElementById('report');
const granularityGroup = document.getElementById('granularity_group');
const periodGroup = document.getElementById('period_group');
function syncReportControls() {
    if (granularityGroup) {
        granularityGroup.style.display = reportSelect.value === 'sales_period' ? '' : 'none';
    }
    if (periodGroup) {
        periodGroup.style.display = reportSelect.value === 'gross_profit' ? '' : 'none';
    }
}
if (reportSelect) {
    reportSelect.addEventListener('change', () => {
        syncReportControls();
        const form = document.getElementById('report-filter-form');
        if (form) form.submit();
    });
    syncReportControls();
}

const fromInput = document.getElementById('from');
const toInput = document.getElementById('to');
const periodInput = document.getElementById('period_input');
if (fromInput && toInput && periodInput) {
    const onDateChange = () => {
        periodInput.value = 'custom';
        document.querySelectorAll('.period-btn').forEach(btn => {
            btn.classList.toggle('is-active', btn.getAttribute('data-period') === 'custom');
        });
    };
    fromInput.addEventListener('change', onDateChange);
    toInput.addEventListener('change', onDateChange);
}
</script>
</body>
</html>
