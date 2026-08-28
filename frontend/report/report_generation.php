<?php
// report/report_generation.php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/includes/functions.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';
require_role(['admin', 'inventory_manager']);

$reportCatalog = [
    'sales_period' => 'Daily, Weekly and Monthly Sales',
    'sales_product' => 'Sales by Product',
    'sales_category' => 'Sales by Category',
    'sales_cashier' => 'Sales by Cashier',
    'fast_moving' => 'Fast-Moving Products',
    'slow_moving' => 'Slow-Moving Products',
    'low_stock' => 'Low-Stock and Out-of-Stock Products',
    'inventory_valuation' => 'Inventory Valuation',
    'stock_movements' => 'Stock Movement History',
    'adjustments' => 'Damaged, Missing and Expired Items',
    'replenishment' => 'Replenishment Request Status',
    'forecast_actual' => 'Forecast Versus Actual Demand',
    'gross_profit' => 'Gross Profit Estimate',
    'expiration' => 'Expiration Report',
    'count_variance' => 'Physical-Count Variance Report',
];

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
        fputcsv($output, array_map(static fn($value) => (string)$value, array_values($row)));
    }
    fclose($output);
    exit;
}

function product_filters(array $filters, string $alias = 'p'): array
{
    $where = [];
    $params = [];

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

    switch ($report) {
        case 'sales_period':
            $granularity = in_array($filters['granularity'], ['daily', 'weekly', 'monthly'], true) ? $filters['granularity'] : 'daily';
            $periodExpr = [
                'daily' => 'DATE(s.sale_date)',
                'weekly' => "CONCAT(YEAR(s.sale_date), '-W', LPAD(WEEK(s.sale_date, 1), 2, '0'))",
                'monthly' => "DATE_FORMAT(s.sale_date, '%Y-%m')",
            ][$granularity];
            $stmt = $pdo->prepare(
                "SELECT {$periodExpr} AS period_label, COUNT(DISTINCT s.sale_id) AS receipts,
                        COALESCE(SUM(si.quantity), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales
                 FROM sales s
                 JOIN sale_items si ON si.sale_id = s.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY period_label
                 ORDER BY MIN(s.sale_date) DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Period', 'Receipts', 'Units Sold', 'Total Sales'],
                'rows' => array_map(static fn($row) => [
                    'Period' => $row['period_label'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_product':
        case 'fast_moving':
            $order = 'units_sold DESC, total_sales DESC';
            $limit = $report === 'sales_product' ? '' : ' LIMIT 25';
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(SUM(si.quantity), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales,
                        COUNT(DISTINCT s.sale_id) AS receipts
                 FROM sale_items si
                 JOIN sales s ON s.sale_id = si.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY p.product_id, sku, p.product_name, category_name
                 ORDER BY {$order}{$limit}"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Receipts', 'Units Sold', 'Total Sales'],
                'rows' => array_map(static fn($row) => [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                ], $stmt->fetchAll()),
            ];

        case 'slow_moving':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name, COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(SUM(CASE WHEN s.sale_id IS NOT NULL THEN si.quantity ELSE 0 END), 0) AS units_sold,
                        COALESCE(SUM(CASE WHEN s.sale_id IS NOT NULL THEN si.subtotal ELSE 0 END), 0) AS total_sales,
                        COUNT(DISTINCT s.sale_id) AS receipts
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN sale_items si ON si.product_id = p.product_id
                 LEFT JOIN sales s ON s.sale_id = si.sale_id AND s.sale_date BETWEEN ? AND ?
                 WHERE 1=1 {$productSql}
                 GROUP BY p.product_id, sku, p.product_name, category_name
                 ORDER BY units_sold ASC, total_sales ASC, p.product_name
                 LIMIT 25"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Receipts', 'Units Sold', 'Total Sales'],
                'rows' => array_map(static fn($row) => [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_category':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(c.category_name, 'Uncategorized') AS category_name, COUNT(DISTINCT p.product_id) AS products_sold,
                        COUNT(DISTINCT s.sale_id) AS receipts, COALESCE(SUM(si.quantity), 0) AS units_sold,
                        COALESCE(SUM(si.subtotal), 0) AS total_sales
                 FROM sale_items si
                 JOIN sales s ON s.sale_id = si.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY category_name
                 ORDER BY total_sales DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Category', 'Products Sold', 'Receipts', 'Units Sold', 'Total Sales'],
                'rows' => array_map(static fn($row) => [
                    'Category' => $row['category_name'],
                    'Products Sold' => (int)$row['products_sold'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
                ], $stmt->fetchAll()),
            ];

        case 'sales_cashier':
            $stmt = $pdo->prepare(
                "SELECT u.full_name AS cashier, COUNT(DISTINCT s.sale_id) AS receipts,
                        COALESCE(SUM(si.quantity), 0) AS units_sold, COALESCE(SUM(si.subtotal), 0) AS total_sales
                 FROM sales s
                 JOIN users u ON u.user_id = s.cashier_id
                 LEFT JOIN sale_items si ON si.sale_id = s.sale_id
                 LEFT JOIN products p ON p.product_id = si.product_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY u.user_id, u.full_name
                 ORDER BY total_sales DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Cashier', 'Receipts', 'Units Sold', 'Total Sales'],
                'rows' => array_map(static fn($row) => [
                    'Cashier' => $row['cashier'],
                    'Receipts' => (int)$row['receipts'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Total Sales' => money_value($row['total_sales']),
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
                : ['SKU', 'Product', 'Category', 'Qty On Hand', 'Cost Price', 'Retail Price', 'Inventory Value'];
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

        case 'replenishment':
            $stmt = $pdo->prepare(
                "SELECT rr.request_date, COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        rr.request_qty, COALESCE(received.accepted_qty, 0) AS accepted_qty,
                        rr.status, rr.source, COALESCE(requester.full_name, 'Unknown') AS requested_by,
                        COALESCE(approver.full_name, '-') AS approved_by
                 FROM replenishment_requests rr
                 JOIN products p ON p.product_id = rr.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN users requester ON requester.user_id = rr.requested_by
                 LEFT JOIN users approver ON approver.user_id = rr.approved_by
                 LEFT JOIN (
                    SELECT replenishment_request_id, SUM(accepted_qty) AS accepted_qty
                    FROM stock_receiving
                    WHERE replenishment_request_id IS NOT NULL
                    GROUP BY replenishment_request_id
                 ) received ON received.replenishment_request_id = rr.request_id
                 WHERE rr.request_date BETWEEN ? AND ? {$productSql}
                 ORDER BY rr.request_date DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['Date', 'SKU', 'Product', 'Category', 'Requested Qty', 'Accepted Qty', 'Open Qty', 'Status', 'Source', 'Requested By', 'Approved By'],
                'rows' => array_map(static fn($row) => [
                    'Date' => $row['request_date'],
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Requested Qty' => (int)$row['request_qty'],
                    'Accepted Qty' => (int)$row['accepted_qty'],
                    'Open Qty' => max(0, (int)$row['request_qty'] - (int)$row['accepted_qty']),
                    'Status' => ucfirst(str_replace('_', ' ', (string)$row['status'])),
                    'Source' => ucfirst(str_replace('_', ' ', (string)$row['source'])),
                    'Requested By' => $row['requested_by'],
                    'Approved By' => $row['approved_by'],
                ], $stmt->fetchAll()),
            ];

        case 'forecast_actual':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        fr.model_name, fr.model_version,
                        sp.forecast_period_days,
                        sp.generated_at,
                        DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY) AS forecast_completed_at,
                        COALESCE(sp.predicted_demand_next_30_days, sp.forecast_value, 0) AS forecast_quantity,
                        COALESCE(sp.actual_demand, (
                            SELECT SUM(si.quantity)
                            FROM sale_items si
                            JOIN sales s ON s.sale_id = si.sale_id
                            WHERE si.product_id = sp.product_id
                              AND s.sale_date >= sp.generated_at
                              AND s.sale_date < DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY)
                        ), 0) AS actual_quantity,
                        sp.confidence_score
                 FROM stock_predictions sp
                 JOIN forecast_runs fr ON fr.forecast_run_id = sp.forecast_run_id
                 JOIN products p ON p.product_id = sp.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY) <= NOW()
                   AND DATE(DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY)) BETWEEN ? AND ?
                   {$productSql}
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
                    'Confidence' => $row['confidence_score'] !== null ? round((float)$row['confidence_score'] * 100) . '%' : '-',
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
                'headers' => ['SKU', 'Product', 'Category', 'Forecast Period', 'Generated At', 'Completed At', 'Forecast', 'Actual Sales', 'Difference', 'Percentage Error', 'Confidence', 'Model'],
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
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(SUM(si.quantity), 0) AS units_sold,
                        COALESCE(SUM(si.subtotal), 0) AS revenue,
                        COALESCE(SUM(si.quantity * p.cost_price), 0) AS estimated_cost,
                        COALESCE(SUM(si.subtotal - (si.quantity * p.cost_price)), 0) AS gross_profit
                 FROM sale_items si
                 JOIN sales s ON s.sale_id = si.sale_id
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE s.sale_date BETWEEN ? AND ? {$productSql}
                 GROUP BY p.product_id, sku, p.product_name, category_name
                 ORDER BY gross_profit DESC"
            );
            $stmt->execute(array_merge([$fromStart, $toEnd], $productParams));
            return [
                'headers' => ['SKU', 'Product', 'Category', 'Units Sold', 'Revenue', 'Estimated Cost', 'Gross Profit'],
                'rows' => array_map(static fn($row) => [
                    'SKU' => $row['sku'],
                    'Product' => $row['product_name'],
                    'Category' => $row['category_name'],
                    'Units Sold' => (int)$row['units_sold'],
                    'Revenue' => money_value($row['revenue']),
                    'Estimated Cost' => money_value($row['estimated_cost']),
                    'Gross Profit' => money_value($row['gross_profit']),
                ], $stmt->fetchAll()),
            ];

        case 'expiration':
            $stmt = $pdo->prepare(
                "SELECT COALESCE(p.barcode, p.sku) AS sku, p.product_name,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name,
                        COALESCE(pb.batch_number, '-') AS batch_number,
                        COALESCE(pb.remaining_quantity, i.quantity_on_hand, 0) AS quantity,
                        COALESCE(pb.expiration_date, p.expiration_date) AS expiration_date,
                        DATEDIFF(COALESCE(pb.expiration_date, p.expiration_date), CURDATE()) AS days_remaining
                 FROM products p
                 LEFT JOIN inventory i ON i.product_id = p.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN product_batches pb ON pb.product_id = p.product_id AND pb.expiration_date IS NOT NULL AND pb.remaining_quantity > 0
                 WHERE COALESCE(pb.expiration_date, p.expiration_date) IS NOT NULL {$productSql}
                 ORDER BY expiration_date ASC, p.product_name"
            );
            $stmt->execute($productParams);
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
if (!array_key_exists($selectedReport, $reportCatalog)) {
    $selectedReport = 'sales_period';
}

$filters = [
    'from' => normalize_date(request_value('from', date('Y-m-01')), date('Y-m-01')),
    'to' => normalize_date(request_value('to', date('Y-m-d')), date('Y-m-d')),
    'product_id' => max(0, (int)request_value('product_id', '0')),
    'category_id' => max(0, (int)request_value('category_id', '0')),
    'granularity' => request_value('granularity', 'daily'),
];

if (strtotime($filters['from']) > strtotime($filters['to'])) {
    [$filters['from'], $filters['to']] = [$filters['to'], $filters['from']];
}

$products = $pdo->query(
    "SELECT p.product_id, COALESCE(p.barcode, p.sku) AS sku, p.product_name
     FROM products p
     ORDER BY p.product_name"
)->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name FROM categories ORDER BY category_name")->fetchAll();
$reportData = run_report($pdo, $selectedReport, $filters);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_csv') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
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

$totalRows = count($reportData['rows']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report Generation</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
<style>
    .report-filter-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:1rem; align-items:end; }
    .report-actions { display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:1rem; }
    .report-meta { display:flex; gap:0.75rem; flex-wrap:wrap; color:var(--muted); font-size:0.9rem; margin-top:0.35rem; }
    .report-print-title { display:none; }
    @media print {
        .sidebar, .topbar, .report-filters, .report-actions, .no-print { display:none !important; }
        .app-shell { display:block; }
        .main-content { padding:0; }
        .dashboard-section { box-shadow:none; border:none; padding:0; }
        .report-print-title { display:block; margin-bottom:1rem; }
        body { background:#fff; }
        table { display:table; white-space:normal; }
    }
</style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../modules/sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <div>
                <h1>Report Generation</h1>
                <p class="page-subtitle">Sales, inventory, stock movement, replenishment, forecast, and variance reporting.</p>
            </div>
            <span class="badge-role">Manager: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        </div>

        <div class="dashboard-section report-filters">
            <form method="GET" class="report-filter-grid">
                <div class="form-group">
                    <label for="report">Report</label>
                    <select id="report" name="report">
                        <?php foreach ($reportCatalog as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $selectedReport === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" id="granularity_group">
                    <label for="granularity">Sales Period</label>
                    <select id="granularity" name="granularity">
                        <?php foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $filters['granularity'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
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

        <div class="dashboard-section" style="margin-top:1.5rem;">
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

            <?php if (!empty($reportData['metrics'])): ?>
                <div class="card-grid" style="margin:1rem 0;">
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
    </div>
</div>
<script>
const reportSelect = document.getElementById('report');
const granularityGroup = document.getElementById('granularity_group');
function syncGranularity() {
    granularityGroup.style.display = reportSelect.value === 'sales_period' ? '' : 'none';
}
reportSelect.addEventListener('change', syncGranularity);
syncGranularity();
</script>
</body>
</html>
