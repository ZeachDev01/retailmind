<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$rows = get_forecasting_readiness($pdo);
$historyReadyCount = count(array_filter($rows, static fn(array $row): bool => (int)$row['accumulated_usable_days'] >= (int)$row['required_usable_days']));
$recentReadyCount = count(array_filter($rows, static fn(array $row): bool => !empty($row['has_feature_window'])));
$testingReadyCount = count(array_filter($rows, static fn(array $row): bool => !empty($row['has_holdout_coverage'])));
$trainingReadyCount = count(array_filter($rows, static fn(array $row): bool => !empty($row['training_eligible'])));
$isHistoricalDemo = !empty(get_key_value_settings($pdo, 'platform_settings', ['demo_import_metadata' => ''])['demo_import_metadata']);

$counts = [
    'total' => count($rows),
    'Forecast Generated' => 0,
    'Ready for training' => 0,
    'Ready for Forecasting' => 0,
    'Insufficient Data' => 0,
    'Model Requires Retraining' => 0,
];

foreach ($rows as $row) {
    $st = $row['forecast_status'] ?? 'Insufficient Data';
    if (isset($counts[$st])) {
        $counts[$st]++;
    } else {
        $counts[$st] = 1;
    }
}

// Filter handling
$search = trim((string)($_GET['search'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$filteredRows = $rows;
if ($search !== '' || $statusFilter !== '') {
    $filteredRows = array_filter($rows, static function (array $row) use ($search, $statusFilter): bool {
        if ($statusFilter !== '') {
            $rowStatus = $row['forecast_status'] ?? '';
            if (in_array($statusFilter, ['Ready for training', 'Ready for Forecasting'], true)) {
                if (!in_array($rowStatus, ['Ready for training', 'Ready for Forecasting'], true)) {
                    return false;
                }
            } elseif ($rowStatus !== $statusFilter) {
                return false;
            }
        }
        if ($search !== '') {
            $term = mb_strtolower($search);
            $target = mb_strtolower(($row['product_name'] ?? '') . ' ' . ($row['sku'] ?? '') . ' ' . ($row['category'] ?? ''));
            if (mb_strpos($target, $term) === false) {
                return false;
            }
        }
        return true;
    });
    $filteredRows = array_values($filteredRows);
}

// Pagination handling
$perPage = 15;
$totalFiltered = count($filteredRows);
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]) ?: 1;
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$pagedRows = array_slice($filteredRows, $offset, $perPage);

function build_query(array $params): string {
    $current = $_GET;
    foreach ($params as $k => $v) {
        if ($v === null) {
            unset($current[$k]);
        } else {
            $current[$k] = $v;
        }
    }
    return '?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Forecast Data Readiness</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/reports.css')) ?>">
    <style>
        .filter-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
            padding: 0.85rem 1rem;
            background: var(--card-bg, #ffffff);
            border: 1px solid var(--border-color, #e2e8f0);
            border-radius: 10px;
        }
        .filter-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        .status-badge-gen { background: #dcfce7; color: #15803d; }
        .status-badge-ready { background: #dbeafe; color: #1e40af; }
        .status-badge-need { background: #fef3c7; color: #92400e; }
        .status-badge-retrain { background: #f3e8ff; color: #6b21a8; }
        .table-pagination-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding: 0.75rem 1rem;
            border-top: 1px solid var(--border-color, #e2e8f0);
            background: var(--theme-surface-soft, #f8fafc);
            font-size: 0.85rem;
            border-radius: 0 0 10px 10px;
        }
        .table-pagination-nav .pagination-controls {
            display: flex;
            align-items: center;
            gap: 0.35rem;
        }
        .table-pagination-nav .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            height: 34px;
            padding: 0 0.5rem;
            border: 1px solid var(--border-color, #cbd5e1);
            border-radius: 6px;
            background: var(--card-bg, #ffffff);
            color: var(--text, #1e293b);
            text-decoration: none;
            font-weight: 500;
        }
        .table-pagination-nav .page-btn.active {
            background: var(--primary, #2563eb);
            color: #ffffff;
            border-color: var(--primary, #2563eb);
            cursor: default;
        }
        .table-pagination-nav .page-btn[aria-disabled="true"] {
            opacity: 0.45;
            pointer-events: none;
            cursor: not-allowed;
        }
    </style>
    <?php require_once __DIR__ . '/../report/forecast_ui.php'; ?>
</head>
<body class="forecast-workspace">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Forecast Data Readiness</h1>
                <p class="page-subtitle">See what is ready, what is missing, and what to do next.</p>
            </div>
            <div class="page-heading-actions">
                <?php if (has_capability(RoleCapabilityPolicy::CONFIGURE_MODELS)): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/ml_settings.php')) ?>">Model Training</a>
                <?php endif; ?>
                <?php if (has_capability(RoleCapabilityPolicy::APPROVE_MODELS)): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/administrator/model_review.php')) ?>">Model Review &amp; Approval</a>
                <?php endif; ?>
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Demand Forecast</a>
            </div>
        </header>

        <?php forecast_workflow('data'); ?>
        <?php
        $blockedRows = array_values(array_filter($rows, static fn(array $row): bool => empty($row['training_eligible'])));
        $firstBlocker = $blockedRows ? forecast_readiness_state($blockedRows[0]) : null;
        forecast_status_summary(
            $rows ? "{$trainingReadyCount} of " . count($rows) . ' products ready to train' : 'No product readiness results',
            $firstBlocker ? $firstBlocker['reason'] . (count($blockedRows) > 1 ? ' Check the product rows for other blockers.' : '') : ($rows ? 'None in the sales data checks. Model quality and approval are separate.' : 'No product history is available to check.'),
            $firstBlocker ? $firstBlocker['next'] : ($rows ? 'The Super Administrator can review Model Training.' : 'Add products and record or import their daily sales.')
        );
        ?>
        <?php if ($isHistoricalDemo): ?>
            <p class="forecast-notice"><strong>Historical demo.</strong> Old records can test past predictions; they do not fill today’s missing dates. <a href="forecast_replay.php">See the historical comparison</a>.</p>
        <?php endif; ?>
        <ul class="forecast-data-checks" aria-label="Three required data checks">
            <li><strong>Enough history</strong><?= $historyReadyCount ?> / <?= count($rows) ?> products have 140 usable days</li>
            <li><strong>Recent records</strong><?= $recentReadyCount ?> / <?= count($rows) ?> products support the latest 28 days</li>
            <li><strong>Testing records</strong><?= $testingReadyCount ?> / <?= count($rows) ?> products support three 28-day tests</li>
        </ul>

        <details class="forecast-details">
            <summary>How training eligibility works</summary>
            <?php require __DIR__ . '/training_eligibility_policy.php'; ?>
        </details>

        <details class="forecast-details"><summary>Other forecast statuses</summary>
        <section class="forecast-overview" aria-label="Readiness overview stats">
            <div><small>Products</small><strong><?= number_format($counts['total']) ?></strong></div>
            <div><small>Forecast generated</small><strong><?= number_format($counts['Forecast Generated']) ?></strong></div>
            <div><small>Ready for training</small><strong><?= number_format($counts['Ready for training'] + $counts['Ready for Forecasting']) ?></strong></div>
            <div><small>Data checks blocked</small><strong><?= number_format($counts['Insufficient Data']) ?></strong></div>
            <div><small>Needs retraining</small><strong><?= number_format($counts['Model Requires Retraining']) ?></strong></div>
        </section>
        </details>

        <section class="dashboard-section" aria-label="Product readiness management">
            <div class="section-header">
                <h2>Product readiness</h2>
            </div>

            <form method="GET" action="" class="filter-toolbar" role="search" aria-label="Filter products">
                <?= csrf_field() ?>
                <div class="filter-group">
                    <input type="search" name="search" class="input-text" placeholder="Search product, SKU, category" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
                    <select name="status" class="input-select" aria-label="Filter by forecast status">
                        <option value="">All Statuses</option>
                        <option value="Forecast Generated" <?= $statusFilter === 'Forecast Generated' ? 'selected' : '' ?>>Forecast Generated</option>
                        <option value="Ready for training" <?= in_array($statusFilter, ['Ready for training', 'Ready for Forecasting'], true) ? 'selected' : '' ?>>Ready for training</option>
                        <option value="Model Requires Retraining" <?= $statusFilter === 'Model Requires Retraining' ? 'selected' : '' ?>>Model Requires Retraining</option>
                        <option value="Insufficient Data" <?= $statusFilter === 'Insufficient Data' ? 'selected' : '' ?>>Data checks blocked</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <?php if ($search !== '' || $statusFilter !== ''): ?>
                        <a href="data_readiness.php" class="btn btn-quiet btn-sm">Reset</a>
                    <?php endif; ?>
                </div>
                <div class="u-text-muted" style="font-size:0.85rem;">
                    <?= number_format($totalFiltered) ?> <?= $totalFiltered === 1 ? 'product' : 'products' ?> found
                </div>
            </form>

            <div class="table-wrap" role="region" aria-label="Product readiness catalog" tabindex="0">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">What is happening</th>
                            <th scope="col">Required data checks</th>
                            <th scope="col">History dates</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedRows as $row):
                            $status = $row['forecast_status'] ?? 'Insufficient Data';
                            $readinessState = forecast_readiness_state($row);
                            $badgeClass = match ($status) {
                                'Forecast Generated' => 'status-badge-gen',
                                'Ready for training', 'Ready for Forecasting' => 'status-badge-ready',
                                'Model Requires Retraining' => 'status-badge-retrain',
                                default => 'status-badge-need',
                            };
                            $firstSale = $row['first_sale_date'] ?? null;
                            $lastSale = $row['last_sale_date'] ?? null;
                            $historyRange = ($firstSale && $lastSale)
                                ? htmlspecialchars($firstSale) . ' &ndash; ' . htmlspecialchars(substr((string)$lastSale, 0, 10))
                                : 'No history';
                        ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong><br>
                                    <span class="u-text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($row['sku']) ?> &middot; <?= htmlspecialchars($row['category']) ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($readinessState['label']) ?></strong>
                                    <p><?= htmlspecialchars($readinessState['reason']) ?></p>
                                    <details>
                                        <summary>Training details</summary>
                                        <p><strong>Next:</strong> <?= htmlspecialchars($readinessState['next']) ?></p>
                                        <p>Checked through <?= htmlspecialchars($row['eligibility_through_date']) ?></p>
                                        <p>Training Eligibility: <?= $row['training_eligible'] ? 'Ready for training' : 'Insufficient Data' ?></p>
                                        <?php foreach (array_merge($row['eligibility_blockers'], $row['eligibility_actions']) as $detail): ?>
                                            <p><?= htmlspecialchars($detail) ?></p>
                                        <?php endforeach; ?>
                                        <p><?= (int)$row['days_with_sales'] ?> days with sales; <?= (int)$row['genuine_zero_days'] ?> confirmed zero-sales days; <?= number_format((int)$row['total_qty_sold']) ?> units sold.</p>
                                    </details>
                                    <?php if (!empty($row['forecast_reason'])): ?>
                                        <?php forecast_help('readiness-' . (int)$row['product_id'], $row['forecast_reason'], 'Readiness details for ' . $row['product_name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="readiness-checks">
                                        <span>Enough history <strong><?= (int)$row['accumulated_usable_days'] >= (int)$row['required_usable_days'] ? 'Passed' : 'Missing' ?></strong></span>
                                        <small><?= (int)$row['accumulated_usable_days'] ?> / <?= (int)$row['required_usable_days'] ?> usable days</small>
                                        <span>Recent 28 days <strong><?= $row['has_feature_window'] ? 'Passed' : 'Blocked' ?></strong></span>
                                        <span>Three test periods <strong><?= $row['has_holdout_coverage'] ? 'Passed' : 'Blocked' ?></strong></span>
                                    </div>
                                </td>
                                <td>
                                    <?= $historyRange ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pagedRows): ?>
                            <tr>
                                <td colspan="4" class="u-text-muted u-text-center" style="padding:2rem;"><?= $search !== '' || $statusFilter !== '' ? 'No matching products. Clear the filters to view all products.' : 'No products yet. Add products before checking their sales history.' ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <nav class="table-pagination-nav" aria-label="Product readiness pages">
                    <div>
                        Showing <?= $totalFiltered > 0 ? $offset + 1 : 0 ?>&ndash;<?= min($offset + $perPage, $totalFiltered) ?> of <?= number_format($totalFiltered) ?>
                    </div>
                    <div class="pagination-controls">
                        <a href="<?= htmlspecialchars(build_query(['page' => $page - 1])) ?>" class="page-btn" aria-label="Previous page" <?= $page <= 1 ? 'aria-disabled="true"' : '' ?>>&laquo;</a>
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($p = $startPage; $p <= $endPage; $p++):
                        ?>
                            <a href="<?= htmlspecialchars(build_query(['page' => $p])) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?>><?= $p ?></a>
                        <?php endfor; ?>
                        <a href="<?= htmlspecialchars(build_query(['page' => $page + 1])) ?>" class="page-btn" aria-label="Next page" <?= $page >= $totalPages ? 'aria-disabled="true"' : '' ?>>&raquo;</a>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
