<?php
require_once __DIR__ . '/../../../backend/includes/auth.php';

use App\Authorization\RoleCapabilityPolicy;
use App\Services\ForecastHistoryImportService;

require_capability(RoleCapabilityPolicy::IMPORT_FORECAST_HISTORY);
$storeId = store_scope_id($pdo);
$service = new ForecastHistoryImportService($pdo, $storeId);

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
            $message = "Imported {$result['inserted']} daily totals; skipped {$result['skipped']} existing totals or days with POS sales. Update forecasts to use the new history.";
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
?>
<!DOCTYPE html>
<html lang="en"><head><?php retailmind_theme_head(); ?><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Data Import</title><link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body><div class="app-shell"><?php include __DIR__ . '/../sidebar.php'; ?><main class="main-content">
    <div class="topbar"><div><h1>Forecast Data Import</h1><p class="page-subtitle">Upload past daily sales totals to train demand forecasts.</p></div><a class="btn" href="<?= htmlspecialchars(app_url('components/report/predictions.php')) ?>">Demand Forecasting</a></div>
    <?php if ($message): ?><p class="tag-success" role="status"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="tag-warning" role="alert"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <section class="dashboard-section">
        <h2>Upload sales history</h2>
        <p>Use columns <strong>sale_date, sku, quantity</strong>, with one daily total per product. Match an active product SKU and use YYYY-MM-DD dates before today. Quantity must be a whole number; zero is allowed.</p>
        <p>These totals feed forecasting only. They do not create receipts or change stock and revenue. Days with recorded POS sales and identical existing imports are skipped. Different existing import totals reject the entire import. Missing dates between the first record and today count as zero demand.</p>
        <p><a href="?template=1">Download CSV template</a> · <a href="<?= htmlspecialchars(app_url('components/report/data_readiness.php')) ?>">Check data readiness</a></p>
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <label for="forecast-csv">Sales history CSV (up to 2 MB / 10000 rows)</label>
            <input type="file" id="forecast-csv" name="csv_file" accept=".csv,text/csv" required>
            <button type="submit" class="btn">Preview CSV</button>
        </form>
    </section>
    <?php if ($preview): ?>
    <section class="dashboard-section"><h2>Preview: <?= count($preview['rows']) ?> daily totals</h2><p>Showing the first 20 rows. All rows were validated.</p>
        <div class="table-wrap"><table><thead><tr><th scope="col">Date</th><th scope="col">SKU</th><th scope="col">Product</th><th scope="col">Units sold</th></tr></thead><tbody>
        <?php foreach (array_slice($preview['rows'], 0, 20) as $row): ?><tr><td><?= htmlspecialchars($row['sale_date']) ?></td><td><?= htmlspecialchars($row['sku']) ?></td><td><?= htmlspecialchars($row['product_name']) ?></td><td><?= $row['quantity'] ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <form method="post"><?= csrf_field() ?><button type="submit" class="btn" name="confirm_import" value="1">Import <?= count($preview['rows']) ?> daily totals</button></form>
    </section>
    <?php endif; ?>
</main></div></body></html>
