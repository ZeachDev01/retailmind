<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$rows = get_forecasting_readiness($pdo);
$historyReadyCount = count(array_filter($rows, static fn(array $row): bool => (int)$row['accumulated_usable_days'] >= (int)$row['required_usable_days']));
$recentReadyCount = count(array_filter($rows, static fn(array $row): bool => !empty($row['has_feature_window'])));
$testingReadyCount = count(array_filter($rows, static fn(array $row): bool => !empty($row['has_holdout_coverage'])));
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
$perPage = 25;
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
        .forecast-definitions-section + .forecast-definitions-section {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color, #e2e8f0);
        }
        .forecast-definitions-section h3 {
            margin: 0 0 0.5rem;
            font-size: 1rem;
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
            </div>
        </header>

        <?php forecast_workflow('data'); ?>
        <?php if ($isHistoricalDemo): ?>
            <p class="forecast-notice"><strong>Historical demo.</strong> Old records can test past predictions; they do not fill today’s missing dates. <a href="forecast_replay.php">See the historical comparison</a>.</p>
        <?php endif; ?>
        <section class="forecast-readiness-visual" aria-label="Three required data checks">
            <?php foreach ([
                ['history', 'Enough history', $historyReadyCount, '140 usable sales days per product.', 'clock-history'],
                ['recent', 'Recent records', $recentReadyCount, 'Supported records for the latest 28 days.', 'calendar-check'],
                ['testing', 'Testing records', $testingReadyCount, 'Three supported 28-day testing periods.', 'clipboard-check'],
            ] as [$check, $label, $passed, $help, $icon]): ?>
                <div>
                    <h2><i class="bi bi-<?= $icon ?>" aria-hidden="true"></i> <?= $label ?><?php forecast_help('check-' . $check, $help, 'About ' . strtolower($label)); ?></h2>
                    <strong><?= $passed ?><span> / <?= count($rows) ?> products</span></strong>
                    <meter min="0" max="<?= max(1, count($rows)) ?>" value="<?= $passed ?>" aria-label="<?= $label ?>: <?= $passed ?> of <?= count($rows) ?> products"><?= $passed ?> / <?= count($rows) ?></meter>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="dashboard-section" aria-label="Product readiness management">
            <div class="section-header">
                <h2>Product readiness</h2>
            </div>

            <form method="GET" action="" class="filter-toolbar" role="search" aria-label="Filter products">
                <div class="filter-group">
                    <input type="search" name="search" class="input-text" autocomplete="off" placeholder="Search product, SKU, category…" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
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
                <table class="data-table readiness-catalog forecast-stack-table" data-no-smart-table data-no-ghost-rows aria-label="Product data readiness">
                    <thead>
                        <tr>
                            <th scope="col">Product</th>
                            <th scope="col">Status</th>
                            <th scope="col">Usable days</th>
                            <th scope="col">Data checks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedRows as $row):
                            $readinessState = forecast_readiness_state($row);
                            $firstSale = $row['first_sale_date'] ?? null;
                            $lastSale = $row['last_sale_date'] ?? null;
                            $historyRange = ($firstSale && $lastSale)
                                ? htmlspecialchars($firstSale) . ' &ndash; ' . htmlspecialchars(substr((string)$lastSale, 0, 10))
                                : 'No history';
                        ?>
                            <tr>
                                <td data-label="Product">
                                    <strong><?= htmlspecialchars($row['product_name']) ?></strong><br>
                                    <span class="u-text-muted" style="font-size:0.8rem;"><?= htmlspecialchars($row['sku']) ?> &middot; <?= htmlspecialchars($row['category']) ?></span>
                                </td>
                                <td data-label="Status">
                                    <div class="readiness-status-line">
                                        <span><?= htmlspecialchars($readinessState['label']) ?></span>
                                        <?php forecast_help('readiness-' . (int)$row['product_id'], $readinessState['reason'] . ' ' . $readinessState['next'], 'Readiness details for ' . $row['product_name']); ?>
                                    </div>
                                </td>
                                <td class="readiness-days" data-label="Usable days">
                                    <span><?= (int)$row['accumulated_usable_days'] ?> / <?= (int)$row['required_usable_days'] ?></span>
                                    <meter min="0" max="<?= max(1, (int)$row['required_usable_days']) ?>" value="<?= min((int)$row['accumulated_usable_days'], max(1, (int)$row['required_usable_days'])) ?>" aria-label="Usable sales days for <?= htmlspecialchars($row['product_name']) ?>"></meter>
                                </td>
                                <td data-label="Data checks">
                                    <button type="button" class="btn btn-small btn-secondary" data-readiness-details="readiness-evidence-<?= (int)$row['product_id'] ?>" aria-haspopup="dialog" aria-controls="readiness-drawer" aria-label="Data checks for <?= htmlspecialchars($row['product_name']) ?>">Details <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
                                    <template id="readiness-evidence-<?= (int)$row['product_id'] ?>">
                                        <section class="readiness-evidence-product">
                                            <h3><?= htmlspecialchars($row['product_name']) ?></h3>
                                            <p class="u-text-muted"><?= htmlspecialchars($row['sku']) ?> &middot; <?= htmlspecialchars($row['category']) ?></p>
                                            <p class="readiness-evidence-status"><?= htmlspecialchars($readinessState['label']) ?></p>
                                            <p><?= htmlspecialchars($readinessState['reason']) ?></p>
                                        </section>
                                        <section class="readiness-evidence-section">
                                            <h3>Data checks</h3>
                                            <dl class="readiness-evidence-list">
                                                <?php foreach ([
                                                    ['Usable sales history', (int)$row['accumulated_usable_days'] >= (int)$row['required_usable_days']],
                                                    ['Recent 28 days', $row['has_feature_window']],
                                                    ['Three test periods', $row['has_holdout_coverage']],
                                                ] as [$checkLabel, $passed]): ?>
                                                    <div><dt><?= $checkLabel ?></dt><dd class="readiness-check-result <?= $passed ? 'is-passed' : 'is-blocked' ?>"><i class="bi bi-<?= $passed ? 'check-circle' : 'exclamation-circle' ?>" aria-hidden="true"></i> <?= $passed ? 'Passed' : 'Blocked' ?></dd></div>
                                                <?php endforeach; ?>
                                                <div><dt>Usable days</dt><dd><?= (int)$row['accumulated_usable_days'] ?> / <?= (int)$row['required_usable_days'] ?></dd></div>
                                            </dl>
                                        </section>
                                        <section class="readiness-evidence-section">
                                            <h3>Sales history</h3>
                                            <dl class="readiness-evidence-list">
                                                <div><dt>Checked through</dt><dd><?= htmlspecialchars($row['eligibility_through_date']) ?></dd></div>
                                                <div><dt>History dates</dt><dd><?= $historyRange ?></dd></div>
                                                <div><dt>Days with sales</dt><dd><?= (int)$row['days_with_sales'] ?></dd></div>
                                                <div><dt>Confirmed zero-sales days</dt><dd><?= (int)$row['genuine_zero_days'] ?></dd></div>
                                                <div><dt>Units sold</dt><dd><?= number_format((int)$row['total_qty_sold']) ?></dd></div>
                                            </dl>
                                        </section>
                                        <?php foreach (['Needs attention' => $row['eligibility_blockers'], 'Next steps' => $row['eligibility_actions']] as $heading => $details): ?>
                                            <?php if ($details): ?><section class="readiness-evidence-section"><h3><?= $heading ?></h3><ul><?php foreach ($details as $detail): ?><li><?= htmlspecialchars($detail) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if (!empty($row['forecast_reason'])): ?><section class="readiness-evidence-section"><h3>Forecast note</h3><p><?= htmlspecialchars($row['forecast_reason']) ?></p></section><?php endif; ?>
                                    </template>
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
        <details class="forecast-details">
            <summary>Definitions &amp; statuses</summary>
            <section class="forecast-definitions-section">
                <h3>Training eligibility</h3>
                <?php require __DIR__ . '/training_eligibility_policy.php'; ?>
            </section>
            <section class="forecast-definitions-section">
                <h3>Status counts</h3>
                <div class="forecast-overview" aria-label="Readiness overview stats">
                    <div><small>Products</small><strong><?= number_format($counts['total']) ?></strong></div>
                    <div><small>Forecast generated</small><strong><?= number_format($counts['Forecast Generated']) ?></strong></div>
                    <div><small>Ready for training</small><strong><?= number_format($counts['Ready for training'] + $counts['Ready for Forecasting']) ?></strong></div>
                    <div><small>Data checks blocked</small><strong><?= number_format($counts['Insufficient Data']) ?></strong></div>
                    <div><small>Needs retraining</small><strong><?= number_format($counts['Model Requires Retraining']) ?></strong></div>
                </div>
            </section>
        </details>

    </main>
</div>
<div class="rm-drawer-overlay" id="readiness-drawer" aria-hidden="true">
    <aside class="rm-drawer" role="dialog" aria-modal="true" aria-labelledby="readiness-drawer-title">
        <header class="rm-drawer-header">
            <div><h2 id="readiness-drawer-title">Product data checks</h2><p>Sales history and training readiness</p></div>
            <button type="button" class="rm-close" data-close-drawer aria-label="Close data checks"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="rm-drawer-body" id="readiness-drawer-body"></div>
    </aside>
</div>
<script>
(() => {
    const drawer = document.getElementById('readiness-drawer');
    const body = document.getElementById('readiness-drawer-body');
    document.querySelector('.readiness-catalog').addEventListener('click', event => {
        const button = event.target.closest('[data-readiness-details]');
        if (!button) return;
        body.replaceChildren(document.getElementById(button.dataset.readinessDetails).content.cloneNode(true));
        body.scrollTop = 0;
        RetailMindUI.openOverlay(drawer);
    });
})();
</script>
</body>
</html>
