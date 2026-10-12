import re
import os

# 1. DashboardService.php: 30 days -> 28 days
path = 'src/backend/app/Services/DashboardService.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("predicted_demand_next_30_days", "predicted_demand_next_28_days")
    content = content.replace("df.horizon_day <= 30", "df.horizon_day <= 28")
    content = content.replace("public function getAdminMetrics(string $actorRole, int $days = 30): array", "public function getAdminMetrics(string $actorRole, int $days = 28): array")
    content = content.replace("public function getFastMovingProducts(int $limit = 5, int $days = 30): array", "public function getFastMovingProducts(int $limit = 5, int $days = 28): array")
    content = content.replace("public function getSlowMovingProducts(int $limit = 5, int $days = 30): array", "public function getSlowMovingProducts(int $limit = 5, int $days = 28): array")
    content = content.replace("public function getSalesTrend(int $days = 30, ?DateTimeImmutable $asOf = null): array", "public function getSalesTrend(int $days = 28, ?DateTimeImmutable $asOf = null): array")
    content = content.replace("DATE_SUB(CURDATE(), INTERVAL 30 DAY)", "DATE_SUB(CURDATE(), INTERVAL 28 DAY)")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 2. ProductService.php: Remove ScheduledDemandDriver
path = 'src/backend/app/Services/ProductService.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    block1 = """            $driverService = new \\App\\Store\\ScheduledDemandDriverService();
            $driverService->recordDriver(
                $this->pdo,
                $productId,
                'price',
                date('Y-m-d'),
                date('Y-m-d H:i:s'),
                json_encode(['unit_price' => $sellingPrice], JSON_THROW_ON_ERROR)
            );"""
    content = content.replace(block1, "")

    block2 = """                    $driverService = new \\App\\Store\\ScheduledDemandDriverService();
                    $driverService->recordDriver(
                        $this->pdo,
                        $existingProduct['product_id'],
                        'price',
                        date('Y-m-d'),
                        date('Y-m-d H:i:s'),
                        json_encode(['unit_price' => $sellingPrice], JSON_THROW_ON_ERROR)
                    );"""
    content = content.replace(block2, "")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 3. DemandHistoryService.php: Remove branchId and Data Clumps
path = 'src/backend/app/Forecasting/DemandHistoryService.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    content = content.replace("public function getDailyEvidence(PDO $pdo, int $branchId, int $productId, string $startDate, string $endDate, string $cutoff): array", 
                              "public function getDailyEvidence(PDO $pdo, int $productId, string $startDate, string $endDate, string $cutoff): array")
    content = content.replace("public function getReadinessSummary(PDO $pdo, int $branchId, int $productId, string $startDate, string $endDate, string $cutoff): array",
                              "public function getReadinessSummary(PDO $pdo, int $productId, string $startDate, string $endDate, string $cutoff): array")
    content = content.replace("$evidence = $this->getDailyEvidence($pdo, $branchId, $productId, $startDate, $endDate, $cutoff);",
                              "$evidence = $this->getDailyEvidence($pdo, $productId, $startDate, $endDate, $cutoff);")
    content = content.replace("$calStatus = $calendar->getCalendarDateStatusAtCutoff($pdo, $branchId, $dateStr, $cutoff);",
                              "$calStatus = $calendar->getCalendarDateStatusAtCutoff($pdo, $dateStr, $cutoff);")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 4. StoreCalendar.php: Remove branchId
path = 'src/backend/app/Store/StoreCalendar.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()

    content = content.replace("public function recordCalendarDate(PDO $pdo, int $branchId, string $date, string $status, ?int $userId): void",
                              "public function recordCalendarDate(PDO $pdo, string $date, string $status, ?int $userId): void")
    content = content.replace("INSERT INTO store_calendar (branch_id, calendar_date, status, recorded_by, recorded_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)",
                              "INSERT INTO store_calendar (calendar_date, status, recorded_by, recorded_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)")
    content = content.replace("$stmt->execute([$branchId, $date, $status, $userId]);",
                              "$stmt->execute([$date, $status, $userId]);")

    content = content.replace("public function getCalendarDateStatusAtCutoff(PDO $pdo, int $branchId, string $date, string $cutoffTimestamp): ?string",
                              "public function getCalendarDateStatusAtCutoff(PDO $pdo, string $date, string $cutoffTimestamp): ?string")
    content = content.replace("SELECT status FROM store_calendar WHERE branch_id = ? AND calendar_date = ? AND recorded_at <= ? ORDER BY recorded_at DESC LIMIT 1",
                              "SELECT status FROM store_calendar WHERE calendar_date = ? AND recorded_at <= ? ORDER BY recorded_at DESC LIMIT 1")
    content = content.replace("$stmt->execute([$branchId, $date, $cutoffTimestamp]);",
                              "$stmt->execute([$date, $cutoffTimestamp]);")

    content = content.replace("public function getCalendarDateRangeAtCutoff(PDO $pdo, int $branchId, string $startDate, string $endDate, string $cutoffTimestamp): array",
                              "public function getCalendarDateRangeAtCutoff(PDO $pdo, string $startDate, string $endDate, string $cutoffTimestamp): array")
    content = content.replace("c1.branch_id = ? \n              AND ", "")
    content = content.replace("c2.branch_id = c1.branch_id \n                    AND ", "")
    content = content.replace("$stmt->execute([$branchId, $startDate, $endDate, $cutoffTimestamp, $cutoffTimestamp]);",
                              "$stmt->execute([$startDate, $endDate, $cutoffTimestamp, $cutoffTimestamp]);")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 5. Fix tests for branchId removal
path_test1 = 'src/backend/tests/demand_history_contract.php'
if os.path.exists(path_test1):
    with open(path_test1, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("$service->getDailyEvidence($pdo, 1, 1, ", "$service->getDailyEvidence($pdo, 1, ")
    content = content.replace("$service->getReadinessSummary($pdo, 1, 1, ", "$service->getReadinessSummary($pdo, 1, ")
    content = content.replace("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, ", "INSERT INTO store_calendar (calendar_date, status) VALUES (")
    with open(path_test1, 'w', encoding='utf-8') as f:
        f.write(content)

path_test2 = 'src/backend/tests/store_calendar_contract.php'
if os.path.exists(path_test2):
    with open(path_test2, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("$calendar->recordCalendarDate($pdo, 1, ", "$calendar->recordCalendarDate($pdo, ")
    content = content.replace("$calendar->getCalendarDateStatusAtCutoff($pdo, 1, ", "$calendar->getCalendarDateStatusAtCutoff($pdo, ")
    content = content.replace("$calendar->getCalendarDateRangeAtCutoff($pdo, 1, ", "$calendar->getCalendarDateRangeAtCutoff($pdo, ")
    content = content.replace("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, ", "INSERT INTO store_calendar (calendar_date, status) VALUES (")
    with open(path_test2, 'w', encoding='utf-8') as f:
        f.write(content)

path_test3 = 'src/backend/tests/forecast_navigation_contract.php'
if os.path.exists(path_test3):
    with open(path_test3, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("INSERT INTO store_calendar (branch_id, calendar_date, status) VALUES (1, ", "INSERT INTO store_calendar (calendar_date, status) VALUES (")
    with open(path_test3, 'w', encoding='utf-8') as f:
        f.write(content)

# 6. ForecastingSchedulerService.php: ml_settings -> platform_settings
path = 'src/backend/app/Forecasting/ForecastingSchedulerService.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("ml_settings", "platform_settings")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 7. StoreOperationsDashboardWorkspace.php: fix forecast metrics
path = 'src/backend/app/Dashboard/StoreOperationsDashboardWorkspace.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("'confidence' => 0.0,", "")
    old_eval = """            $evalRow = $this->row(
                "SELECT wape FROM ml_model_evaluations ORDER BY evaluation_id DESC LIMIT 1"
            );
            if ($evalRow && $evalRow['wape'] !== null) {
                $wape = (float)$evalRow['wape'];
                $evaluatedProducts = $this->count("SELECT COUNT(DISTINCT product_id) FROM demand_forecasts WHERE job_id = ?", [$jobId]);
            }
        }
        
        return [
            'evaluated_products' => $evaluatedProducts,
            'accuracy_percent' => $wape === null ? null : round(max(0, 100 - ($wape * 100)), 1),
            'operational_exceptions' => count($exceptions),
            'exceptions' => $exceptions,
        ];"""
    
    new_eval = """            $evaluations = $this->rows(
                "SELECT horizon_days, mae, rmse, wape FROM ml_model_evaluations ORDER BY evaluation_id DESC LIMIT 3"
            );
            $evalMetrics = [];
            foreach ($evaluations as $eval) {
                $evalMetrics[(int)$eval['horizon_days']] = [
                    'mae' => $eval['mae'] !== null ? (float)$eval['mae'] : null,
                    'rmse' => $eval['rmse'] !== null ? (float)$eval['rmse'] : null,
                    'wape' => $eval['wape'] !== null ? (float)$eval['wape'] : null,
                ];
            }
            if (!empty($evalMetrics)) {
                $evaluatedProducts = $this->count("SELECT COUNT(DISTINCT product_id) FROM demand_forecasts WHERE job_id = ?", [$jobId]);
            }
        }
        
        return [
            'evaluated_products' => $evaluatedProducts,
            'evaluations' => $evalMetrics ?? [],
            'operational_exceptions' => count($exceptions),
            'exceptions' => $exceptions,
        ];"""
    content = content.replace(old_eval, new_eval)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 8. src/frontend/components/administrator/dashboard.php
path = 'src/frontend/components/administrator/dashboard.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    old_html = """<div class="metric-pair"><article><span>Evaluated products</span><strong><?= (int)$dashboard['forecast']['evaluated_products'] ?></strong></article><article><span>Forecast accuracy</span><strong><?= $dashboard['forecast']['accuracy_percent'] === null ? 'Not available' : $escape(number_format((float)$dashboard['forecast']['accuracy_percent'], 1) . '%') ?></strong></article></div>"""
    new_html = """<div class="metric-pair">
                            <article><span>Evaluated products</span><strong><?= (int)$dashboard['forecast']['evaluated_products'] ?></strong></article>
                            <article><span>7-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][7]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][7]['mae'], 2)) : 'N/A' ?></strong></article>
                            <article><span>14-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][14]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][14]['mae'], 2)) : 'N/A' ?></strong></article>
                            <article><span>28-Day MAE</span><strong><?= isset($dashboard['forecast']['evaluations'][28]['mae']) ? $escape(number_format($dashboard['forecast']['evaluations'][28]['mae'], 2)) : 'N/A' ?></strong></article>
                        </div>"""
    content = content.replace(old_html, new_html)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 9. CandidateEvaluationService.php: ON DUPLICATE KEY UPDATE
path = 'src/backend/app/Forecasting/CandidateEvaluationService.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    
    old_loop = """        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM platform_settings WHERE setting_key = :key");
        $updateStmt = $this->pdo->prepare("UPDATE platform_settings SET setting_value = :value, updated_by = :user, updated_at = CURRENT_TIMESTAMP WHERE setting_key = :key");
        $insertStmt = $this->pdo->prepare("INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at) VALUES (:key, :value, :user, CURRENT_TIMESTAMP)");
        
        foreach (['7', '14', '28'] as $horizon) {
            $key = "ml_max_wape_{$horizon}d";
            if (isset($thresholds[$key])) {
                $checkStmt->execute(['key' => $key]);
                $exists = (int)$checkStmt->fetchColumn() > 0;
                if ($exists) {
                    $updateStmt->execute([
                        'key' => $key,
                        'value' => (string)$thresholds[$key],
                        'user' => $updatedBy
                    ]);
                } else {
                    $insertStmt->execute([
                        'key' => $key,
                        'value' => (string)$thresholds[$key],
                        'user' => $updatedBy
                    ]);
                }
            }
        }"""
        
    new_loop = """        $stmt = $this->pdo->prepare("
            INSERT INTO platform_settings (setting_key, setting_value, updated_by, updated_at) 
            VALUES (:key, :value, :user, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
        ");
        
        foreach (['7', '14', '28'] as $horizon) {
            $key = "ml_max_wape_{$horizon}d";
            if (isset($thresholds[$key])) {
                $stmt->execute([
                    'key' => $key,
                    'value' => (string)$thresholds[$key],
                    'user' => $updatedBy
                ]);
            }
        }"""
    content = content.replace(old_loop, new_loop)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

# 10. Fix schema for store_calendar
path = 'src/backend/database/migrations/202610090001_store_business_calendar.php'
if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    content = content.replace("`branch_id` INT NOT NULL,", "")
    content = content.replace("CONSTRAINT `fk_store_calendar_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE,", "")
    content = content.replace("INDEX `idx_store_calendar_history` (`branch_id`, `calendar_date`, `recorded_at` DESC)", "INDEX `idx_store_calendar_history` (`calendar_date`, `recorded_at` DESC)")
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)

print("Done")

