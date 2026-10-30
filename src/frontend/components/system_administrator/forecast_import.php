<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\ForecastHistoryImportService;

require_capability(RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY);
$storeId = store_scope_id($pdo);
$service = new ForecastHistoryImportService($pdo, $storeId);

if (isset($_GET['cancel'])) {
    unset($_SESSION['forecast_import_preview']);
    $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: app_url('components/system_administrator/forecast_import.php');
    header('Location: ' . $redirectUrl);
    exit;
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="forecast_sales_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['sale_date', 'sku', 'quantity'], ',', '"', '');
    $products = $service->products();
    $product = reset($products);
    if ($product) fputcsv($output, [date('Y-m-d', strtotime('yesterday')), $product['sku'], 5], ',', '"', '');
    fclose($output);
    exit;
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    try {
        if (isset($_POST['confirm_import'])) {
            $preview = $_SESSION['forecast_import_preview'] ?? null;
            if (!$preview || $preview['store_id'] !== $storeId || time() - $preview['created_at'] > 1800) {
                throw new RuntimeException('The preview expired. Upload your CSV again.');
            }
            $result = $service->import($preview['rows'], (int)$_SESSION['user_id']);
            unset($_SESSION['forecast_import_preview']);
            $message = "Imported {$result['inserted']} daily totals; skipped {$result['skipped']} existing totals or days with POS sales. Request model retraining separately to use the new history.";
        } else {
            unset($_SESSION['forecast_import_preview']);
            $file = $_FILES['csv_file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Choose a CSV file and try again.');
            }
            if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv' || $file['size'] > 2 * 1024 * 1024) {
                throw new RuntimeException('Upload a CSV file no larger than 2 MB.');
            }
            $stream = fopen($file['tmp_name'], 'r');
            if (!$stream) throw new RuntimeException('The uploaded file could not be read.');
            try {
                $rows = ForecastHistoryImportService::parse($stream, $service->products(), date('Y-m-d'));
            } finally {
                fclose($stream);
            }
            $_SESSION['forecast_import_preview'] = ['store_id' => $storeId, 'created_at' => time(), 'rows' => $rows];
        }
    } catch (RuntimeException $exception) {
        if ($exception instanceof PDOException) {
            error_log('Forecast import failed: ' . $exception->getMessage());
            $error = 'The import could not be saved. No rows were imported. Check the database setup and try again.';
        } else {
            $error = $exception->getMessage();
        }
    }
}
$preview = $_SESSION['forecast_import_preview'] ?? null;
if ($preview && ($preview['store_id'] !== $storeId || time() - $preview['created_at'] > 1800)) $preview = null;

$stylePath = __DIR__ . '/../../assets/css/style.css';
$styleVersion = is_file($stylePath) ? (string)filemtime($stylePath) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forecast Data Import</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(theme_url('assets/css/style.css') . '?v=' . rawurlencode($styleVersion)) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <style>
        .forecast-import-page .import-workspace {
            display: grid;
            grid-template-columns: minmax(320px, 1.15fr) minmax(280px, 0.85fr);
            gap: 1.5rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .forecast-import-page .import-workspace {
                grid-template-columns: 1fr;
            }
        }
        .forecast-import-page .form-hint {
            font-size: 0.82rem;
            color: var(--muted, #64748b);
            margin: 0.25rem 0 0.5rem 0;
        }
        .forecast-import-page .btn-group {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 1.25rem;
        }
        .forecast-import-page .format-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .forecast-import-page .format-table th,
        .forecast-import-page .format-table td {
            padding: 0.55rem 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border, #e2e8f0);
        }
        .forecast-import-page .format-table code {
            font-family: monospace;
            font-size: 0.85em;
            padding: 0.15rem 0.35rem;
            border-radius: 4px;
            background: var(--theme-surface-soft, rgba(0, 0, 0, 0.05));
        }
        .forecast-import-page .import-rules-list {
            margin: 1rem 0 0 0;
            padding-left: 1.25rem;
            font-size: 0.875rem;
            line-height: 1.5;
            color: var(--text, #1f2937);
        }
        .forecast-import-page .import-rules-list li + li {
            margin-top: 0.5rem;
        }
        .forecast-import-page .preview-summary-meta {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
    </style>
</head>
<body class="forecast-import-page">
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar">
            <div>
                <h1>Forecast Data Import</h1>
                <p class="page-subtitle">Upload this Store's past daily sales totals for model retraining.</p>
            </div>
            <div class="topbar-actions">
                <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/system_administrator/ml_settings.php')) ?>">
                    <i class="bi bi-cpu" aria-hidden="true"></i> ML Operation
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert tag-success" role="status">
                <i class="bi bi-check-circle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert tag-warning" role="alert">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div class="import-workspace">
            <section class="dashboard-section" aria-labelledby="upload-heading">
                <h2 id="upload-heading">Upload sales history</h2>
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label for="forecast-csv">Sales history CSV file</label>
                        <p class="form-hint" id="forecast-csv-hint">UTF-8 CSV &bull; Max 2 MB &bull; Up to 10,000 rows</p>
                        <input type="file" id="forecast-csv" name="csv_file" accept=".csv,text/csv" aria-describedby="forecast-csv-hint" required>
                    </div>
                    <div class="btn-group">
                        <button type="submit" class="btn">
                            <i class="bi bi-search" aria-hidden="true"></i> Preview and validate
                        </button>
                        <a class="btn btn-secondary" href="?template=1">
                            <i class="bi bi-download" aria-hidden="true"></i> Download CSV template
                        </a>
                        <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">
                            <i class="bi bi-clipboard2-check" aria-hidden="true"></i> Check data readiness
                        </a>
                    </div>
                </form>
            </section>

            <section class="dashboard-section" aria-labelledby="format-heading">
                <h2 id="format-heading">CSV format & import rules</h2>
                <div class="table-wrap">
                    <table class="format-table">
                        <thead>
                            <tr>
                                <th scope="col">Column</th>
                                <th scope="col">Type</th>
                                <th scope="col">Requirement</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>sale_date</code></td>
                                <td>Date</td>
                                <td>Past date (<code>YYYY-MM-DD</code>) before today</td>
                            </tr>
                            <tr>
                                <td><code>sku</code></td>
                                <td>Text</td>
                                <td>Active product SKU in this store</td>
                            </tr>
                            <tr>
                                <td><code>quantity</code></td>
                                <td>Integer</td>
                                <td>Daily units sold (&ge; 0; enter 0 for confirmed zero sales)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <ul class="import-rules-list">
                    <li><strong>Forecasting only:</strong> Does not create sales receipts or affect inventory and revenue.</li>
                    <li><strong>Deduplication:</strong> Automatically skips POS sales days and matching imports; conflicting totals reject import.</li>
                    <li><strong>Model retraining:</strong> Stores historical totals; initiate model retraining separately in ML Operation.</li>
                </ul>
            </section>
        </div>

        <?php if ($preview): ?>
        <section class="dashboard-section" aria-labelledby="preview-heading" style="margin-top: 1.5rem;">
            <div class="section-header">
                <div>
                    <h2 id="preview-heading">Preview: <?= count($preview['rows']) ?> daily totals</h2>
                    <p class="section-description">Showing first <?= min(20, count($preview['rows'])) ?> of <?= count($preview['rows']) ?> rows. All rows were validated.</p>
                </div>
                <div class="preview-summary-meta">
                    <span class="decision-pill ok"><i class="bi bi-check-lg" aria-hidden="true"></i> Validated</span>
                </div>
            </div>
            <div class="table-wrap">
                <table aria-label="CSV Sales History Preview">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">SKU</th>
                            <th scope="col">Product</th>
                            <th scope="col" class="u-num-col">Units sold</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($preview['rows'], 0, 20) as $row): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($row['sale_date']) ?></code></td>
                            <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                            <td class="u-num-col"><?= number_format($row['quantity']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" style="margin-top: 1.25rem;">
                <?= csrf_field() ?>
                <div class="form-actions" style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                    <button type="submit" class="btn" name="confirm_import" value="1">
                        <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i> Import <?= count($preview['rows']) ?> daily totals
                    </button>
                    <a class="btn btn-secondary" href="?cancel=1">
                        <i class="bi bi-x-circle" aria-hidden="true"></i> Discard preview
                    </a>
                </div>
            </form>
        </section>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
