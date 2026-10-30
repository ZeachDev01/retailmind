<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;

require_capability(RoleCapabilityPolicy::VIEW_FORECASTS);

$rows = get_forecasting_readiness($pdo);

$counts = [
    'total' => count($rows),
    'Forecast Generated' => 0,
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
        if ($statusFilter !== '' && ($row['forecast_status'] ?? '') !== $statusFilter) {
            return false;
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
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <header class="page-heading">
            <div>
                <h1>Forecast Data Readiness</h1>
            </div>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">View Demand Forecasts</a>
        </header>

        <section class="card-grid" aria-label="Readiness overview stats">
            <div class="stat-card">
                <div class="value"><?= number_format($counts['total']) ?></div>
                <div class="label">Total Products</div>
            </div>
            <div class="stat-card success">
                <div class="value"><?= number_format($counts['Forecast Generated']) ?></div>
                <div class="label">Forecast Generated</div>
            </div>
            <div class="stat-card">
                <div class="value"><?= number_format($counts['Ready for Forecasting']) ?></div>
                <div class="label">Ready for Forecasting</div>
            </div>
            <div class="stat-card warning">
                <div class="value"><?= number_format($counts['Insufficient Data']) ?></div>
                <div class="label">Needs More History</div>
            </div>
        </section>

        <section class="dashboard-section" aria-label="Product readiness management">
            <div class="section-header">
                <h2>Product Readiness Catalog</h2>
            </div>

            <form method="GET" action="" class="filter-toolbar" role="search" aria-label="Filter products">
                <?= csrf_field() ?>
                <div class="filter-group">
                    <input type="search" name="search" class="input-text" placeholder="Search product, SKU, category" value="<?= htmlspecialchars($search) ?>" aria-label="Search products">
                    <select name="status" class="input-select" aria-label="Filter by forecast status">
                        <option value="">All Statuses</option>
                        <option value="Forecast Generated" <?= $statusFilter === 'Forecast Generated' ? 'selected' : '' ?>>Forecast Generated</option>
                        <option value="Ready for Forecasting" <?= $statusFilter === 'Ready for Forecasting' ? 'selected' : '' ?>>Ready for Forecasting</option>
                        <option value="Model Requires Retraining" <?= $statusFilter === 'Model Requires Retraining' ? 'selected' : '' ?>>Model Requires Retraining</option>
                        <option value="Insufficient Data" <?= $statusFilter === 'Insufficient Data' ? 'selected' : '' ?>>Insufficient Data</option>
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
                            <th scope="col">Status</th>
                            <th scope="col">Calendar History</th>
                            <th scope="col">Active Days</th>
                            <th scope="col">Recorded Volume</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagedRows as $row):
                            $status = $row['forecast_status'] ?? 'Insufficient Data';
                            $badgeClass = match ($status) {
                                'Forecast Generated' => 'status-badge-gen',
                                'Ready for Forecasting' => 'status-badge-ready',
                                'Model Requires Retraining' => 'status-badge-retrain',
                                default => 'status-badge-need',
                            };
                            $firstSale = $row['first_sale_date'] ?? null;
                            $lastSale = $row['last_sale_at'] ?? null;
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
                                    <span class="status-badge <?= $badgeClass ?>"><?= htmlspecialchars($status) ?></span>
                                    <?php if (!empty($row['forecast_reason'])): ?>
                                        <div class="u-text-muted" style="font-size:0.75rem; margin-top:0.25rem;" title="<?= htmlspecialchars($row['forecast_reason']) ?>">
                                            <?= htmlspecialchars(mb_strimwidth($row['forecast_reason'], 0, 65, '...')) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= (int)$row['calendar_history_days'] ?> days</strong><br>
                                    <span class="u-text-muted" style="font-size:0.75rem;"><?= $historyRange ?></span>
                                </td>
                                <td>
                                    <span><?= (int)$row['nonzero_sales_days'] ?> active</span><br>
                                    <span class="u-text-muted" style="font-size:0.75rem;"><?= (int)$row['zero_sales_days'] ?> zero sales</span>
                                </td>
                                <td>
                                    <strong><?= number_format((int)$row['sales_records']) ?> records</strong><br>
                                    <span class="u-text-muted" style="font-size:0.75rem;"><?= number_format((int)$row['total_qty_sold']) ?> units sold</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pagedRows): ?>
                            <tr>
                                <td colspan="5" class="u-text-muted u-text-center" style="padding:2rem;">No matching product readiness records found.</td>
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
