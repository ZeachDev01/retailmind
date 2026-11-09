<?php
declare(strict_types=1);

namespace App\Forecasting;

require_once __DIR__ . '/ForecastUsability.php';
require_once __DIR__ . '/StoreTrainingEligibilityService.php';

/**
 * Deep module for Forecast Readiness composition (read path).
 *
 * Owns Demand History SQL composition, Store Training Eligibility evaluation,
 * approved-model settings lookup, and ForecastUsability ladder mapping behind
 * one interface. Returns the exact row contract consumed by existing callers.
 */
final class ForecastReadinessQuery
{
    public function __construct(
        private \PDO $pdo,
        private ?StoreTrainingEligibilityService $eligibilityService = null
    ) {}

    /**
     * Sale quantities in Base Units for a sale_items alias. Falls back to the raw
     * quantity when the schema predates the Sales Pack columns.
     */
    public static function salesUnitsSql(?\PDO $pdo = null, string $alias = 'si'): string
    {
        static $cache = null;
        $cache ??= new \WeakMap();
        $alias = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias) === 1 ? $alias : 'si';

        if ($pdo === null) {
            return "{$alias}.quantity";
        }

        if (!isset($cache[$pdo])) {
            $columns = [];
            try {
                if ((string)$pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    foreach ($pdo->query('PRAGMA table_info(sale_items)')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                        $columns[(string)$column['name']] = true;
                    }
                } else {
                    foreach ($pdo->query('SHOW COLUMNS FROM `sale_items`')->fetchAll(\PDO::FETCH_ASSOC) as $column) {
                        $columns[(string)$column['Field']] = true;
                    }
                }
            } catch (\Throwable) {
            }
            $cache[$pdo] = $columns;
        }

        if (!isset($cache[$pdo]['selling_form'], $cache[$pdo]['pack_size'])) {
            return "{$alias}.quantity";
        }

        return "(CASE WHEN {$alias}.selling_form = 'pack' AND {$alias}.pack_size IS NOT NULL"
            . " THEN {$alias}.quantity * {$alias}.pack_size ELSE {$alias}.quantity END)";
    }

    public static function forecastDailySalesSql(?\PDO $pdo = null): string
    {
        $unitsExpr = self::salesUnitsSql($pdo, 'si');

        return "SELECT si.product_id, DATE(s.sale_date) AS sale_day, SUM({$unitsExpr}) AS qty_sold,
                   COUNT(*) AS sales_records, MAX(s.sale_date) AS changed_at
            FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
            GROUP BY si.product_id, DATE(s.sale_date)
            UNION ALL
            SELECT f.product_id, f.sale_date, f.quantity, 1, f.imported_at
            FROM forecast_sales_imports f
            WHERE NOT EXISTS (
                SELECT 1 FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
                WHERE si.product_id = f.product_id AND DATE(s.sale_date) = f.sale_date
            )";
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(?string $throughDate = null): array
    {
        $dailySalesSql = self::forecastDailySalesSql($this->pdo);
        $eligibilityPolicy = StoreTrainingEligibilityService::policy();
        $requiredUsableDays = $eligibilityPolicy['minimum_usable_days'];
        $platformSettings = get_key_value_settings($this->pdo, 'platform_settings', ['ml_approved_model_version' => 'rf-v2']);
        $currentModelVersion = $platformSettings['ml_approved_model_version'];

        $sql = "SELECT
                p.product_id,
                COALESCE(p.barcode, p.sku) AS sku,
                p.product_name,
                COALESCE(c.category_name, 'Uncategorized') AS category,
                COALESCE(i.quantity_on_hand, 0) AS current_stock,
                COALESCE(p.reorder_level, 10) AS reorder_level,
                COALESCE(p.supplier_lead_time_days, 7) AS supplier_lead_time_days,
                COALESCE(p.safety_stock, 0) AS safety_stock,
                COALESCE(p.minimum_order_quantity, 1) AS minimum_order_quantity,
                COALESCE(p.units_per_package, 1) AS units_per_package,
                COALESCE(NULLIF(p.preferred_supplier, ''), p.supplier, '') AS preferred_supplier,
                COALESCE(stats.nonzero_sales_days, 0) AS nonzero_sales_days,
                CASE WHEN stats.first_sale_date IS NULL THEN 0 ELSE DATEDIFF(CURDATE(), stats.first_sale_date) + 1 END AS calendar_history_days,
                CASE WHEN stats.first_sale_date IS NULL THEN 0 ELSE GREATEST((DATEDIFF(CURDATE(), stats.first_sale_date) + 1) - stats.nonzero_sales_days, 0) END AS zero_sales_days,
                stats.first_sale_date,
                stats.last_sale_date,
                stats.last_sale_at,
                COALESCE(stats.sales_records, 0) AS sales_records,
                COALESCE(stats.total_qty_sold, 0) AS total_qty_sold,
                sp.prediction_id, sp.forecast_run_id, sp.forecast_period_days, sp.forecast_value,
                sp.predicted_demand_next_7_days, sp.predicted_demand_next_30_days,
                sp.lower_bound_7_days, sp.upper_bound_7_days,
                sp.lower_bound_30_days, sp.upper_bound_30_days,
                sp.forecasted_demand_during_lead_time,
                sp.lead_time_lower_bound, sp.lead_time_upper_bound,
                sp.actual_demand, sp.evaluation_result,
                sp.supplier_lead_time_days AS prediction_supplier_lead_time_days,
                sp.safety_stock_used, sp.current_stock_used, sp.incoming_stock_used,
                sp.minimum_order_quantity_used, sp.units_per_package_used,
                sp.reorder_suggested, sp.suggested_reorder_qty, sp.confidence_score,
                sp.forecast_explanation, sp.model_version, sp.generated_at,
                fe.mae AS product_mae, fe.rmse AS product_rmse,
                fe.wape AS product_wape, fe.smape AS product_smape
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            LEFT JOIN inventory i ON i.product_id = p.product_id
            LEFT JOIN (
                SELECT si.product_id,
                       COUNT(DISTINCT CASE WHEN si.qty_sold > 0 THEN si.sale_day END) AS nonzero_sales_days,
                       MIN(si.sale_day) AS first_sale_date,
                       MAX(si.sale_day) AS last_sale_date,
                       MAX(si.changed_at) AS last_sale_at,
                       SUM(si.sales_records) AS sales_records,
                       SUM(si.qty_sold) AS total_qty_sold
                FROM ({$dailySalesSql}) si
                GROUP BY si.product_id
            ) stats ON stats.product_id = p.product_id
            LEFT JOIN stock_predictions sp ON sp.prediction_id = (
                SELECT sp2.prediction_id FROM stock_predictions sp2
                WHERE sp2.product_id = p.product_id
                ORDER BY sp2.generated_at DESC, sp2.prediction_id DESC LIMIT 1
            )
            LEFT JOIN forecast_evaluations fe ON fe.evaluation_id = (
                SELECT fe2.evaluation_id FROM forecast_evaluations fe2
                WHERE fe2.product_id = p.product_id
                ORDER BY fe2.generated_at DESC, fe2.evaluation_id DESC LIMIT 1
            )
            WHERE p.status = 'active'
            ORDER BY p.product_name";

        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute();
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $exception) {
            // Keep the page usable while forecasting migrations are being applied.
            error_log('Forecast readiness requires the forecasting database migrations: ' . $exception->getMessage());
            return [];
        }

        $throughDate = $throughDate ?? date('Y-m-d', strtotime('-1 day'));
        $eligibilityMap = [];
        try {
            $eligService = $this->eligibilityService ?? new StoreTrainingEligibilityService();
            $eligList = $eligService->evaluateAllProducts($this->pdo, $throughDate);
            foreach ($eligList as $item) {
                $eligibilityMap[(int)$item['product_id']] = $item;
            }
        } catch (\Throwable) {
        }

        foreach ($rows as &$row) {
            $calendarDays = (int)$row['calendar_history_days'];
            $nonzeroDays = (int)$row['nonzero_sales_days'];
            $hasPrediction = !empty($row['prediction_id']);
            $modelVersion = (string)($row['model_version'] ?? '');
            $generatedAt = $row['generated_at'] ?? null;
            $lastSaleAt = $row['last_sale_at'] ?? null;
            $row['sales_history_days'] = $calendarDays; // backward-compatible page key
            $row['missing_dates'] = (int)$row['zero_sales_days'];
            $row['can_show_forecast'] = false;
            $row['forecast_status'] = 'Insufficient Data';
            $pid = (int)$row['product_id'];
            $elig = $eligibilityMap[$pid] ?? null;
            $accumulatedUsable = (int)($elig['accumulated_usable_days'] ?? 0);
            $row['accumulated_usable_days'] = $accumulatedUsable;
            $row['required_usable_days'] = $requiredUsableDays;
            $row['usable_days_remaining'] = max(0, $requiredUsableDays - $accumulatedUsable);
            $row['days_with_sales'] = (int)($elig['days_with_sales'] ?? 0);
            $row['genuine_zero_days'] = (int)($elig['genuine_zero_days'] ?? 0);
            $row['calendar_history_days'] = (int)($elig['calendar_span_days'] ?? 0);
            $row['eligibility_blockers'] = $elig['blockers'] ?? ['Training Eligibility could not be checked.'];
            $row['eligibility_actions'] = $elig['actions'] ?? ['Check demand history and retry.'];
            $row['has_feature_window'] = (bool)($elig['has_feature_window'] ?? false);
            $row['has_holdout_coverage'] = (bool)($elig['has_holdout_coverage'] ?? false);
            $isEligible = (bool)($elig['eligible'] ?? false);
            $row['training_eligible'] = $isEligible;
            $row['eligibility_through_date'] = $throughDate;

            // One ladder, owned by ForecastUsability: no forecast -> eligibility;
            // stale model version or a sale after generation -> retraining; else the
            // stored forecast is usable evidence.
            $readiness = ForecastUsability::readinessStatus(
                $elig ?? [],
                $hasPrediction ? ['model_version' => $modelVersion] : null,
                $currentModelVersion,
                $lastSaleAt !== null ? (string)$lastSaleAt : null,
                $generatedAt !== null ? (string)$generatedAt : null
            );
            $row['forecast_status'] = $readiness['status'];
            $row['forecast_reason'] = $readiness['reason'];
            $row['can_show_forecast'] = $readiness['status'] === 'Forecast Generated';
        }
        unset($row);

        $priority = ['Model Requires Retraining' => 0, 'Ready for training' => 1, 'Ready for Forecasting' => 1, 'Forecast Generated' => 2, 'Insufficient Data' => 3];
        usort($rows, static function (array $a, array $b) use ($priority): int {
            return ($priority[$a['forecast_status']] ?? 4) <=> ($priority[$b['forecast_status']] ?? 4)
                ?: strcasecmp((string)$a['product_name'], (string)$b['product_name']);
        });
        return $rows;
    }
}
