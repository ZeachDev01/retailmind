<?php
// modules/inventory_management/csv_import.php
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/includes/csrf.php';
require_once __DIR__ . '/../../../backend/app/Services/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../../backend/app/Services/ProductService.php';
require_role(['admin', 'inventory_manager']);

$message = '';
$error = '';
$import_results = null;
$fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
$productService = new ProductService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');

    $import_type = $_POST['import_type'] ?? '';
    $file = $_FILES['csv_file'];

    if (!in_array($import_type, ['products', 'inventory'])) {
        $error = 'Invalid import type';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $error = 'File upload error';
    } elseif ($file['type'] !== 'text/csv' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $error = 'Only CSV files are allowed';
    } else {
        // Process the file
        $fp = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($fp);

        $successful = 0;
        $failed = 0;
        $errors = [];

        if ($import_type === 'products') {
            // Expected columns: sku, barcode, product_name, brand, category_name, unit_price, cost_price, reorder_level, preferred_supplier, supplier_lead_time_days, safety_stock, minimum_order_quantity, units_per_package
            // SKU and barcode may be left blank; RetailMind will generate an internal barcode and use it as the SKU.
            while (($row = fgetcsv($fp)) !== false) {
                if (empty(array_filter($row))) continue;

                try {
                    $sku = trim($row[0] ?? '');
                    $barcode = trim($row[1] ?? '');
                    $product_name = trim($row[2] ?? '');
                    $brand = trim($row[3] ?? '');
                    $category_name = trim($row[4] ?? '');
                    $unit_price = (float)($row[5] ?? 0);
                    $cost_price = (float)($row[6] ?? 0);
                    $reorder_level = (int)($row[7] ?? 10);
                    $preferred_supplier = trim($row[8] ?? '');
                    $supplier_lead_time_days = max(0, (int)($row[9] ?? 7));
                    $safety_stock = max(0, (int)($row[10] ?? 0));
                    $minimum_order_quantity = max(1, (int)($row[11] ?? 1));
                    $units_per_package = max(1, (int)($row[12] ?? 1));

                    if (!$product_name || $unit_price <= 0 || $cost_price <= 0) {
                        $failed++;
                        $errors[] = "Row " . ($successful + $failed) . ": Missing required fields (product_name, unit_price, cost_price)";
                        continue;
                    }

                    if ($barcode === '') {
                        $barcode = $productService->generateUniqueInternalBarcode();
                    }
                    if ($sku === '') {
                        $sku = $barcode;
                    }

                    // Get or create category
                    $category_id = null;
                    if (!empty($category_name)) {
                        $cat_stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = ?");
                        $cat_stmt->execute([$category_name]);
                        $cat = $cat_stmt->fetch();
                        if (!$cat) {
                            $ins_cat = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
                            $ins_cat->execute([$category_name]);
                            $category_id = $pdo->lastInsertId();
                        } else {
                            $category_id = $cat['category_id'];
                        }
                    }

                    // Insert or update product
                    $prod_stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ?");
                    $prod_stmt->execute([$sku]);
                    $prod = $prod_stmt->fetch();

                    if ($prod) {
                        $beforeStmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
                        $beforeStmt->execute([(int)$prod['product_id']]);
                        $beforeProduct = $beforeStmt->fetch();

                        // Update existing
                        $upd = $pdo->prepare("UPDATE products SET barcode = ?, product_name = ?, brand = ?, category_id = ?,
                                            unit_price = ?, cost_price = ?, reorder_level = ?, supplier = ?, preferred_supplier = ?,
                                            supplier_lead_time_days = ?, safety_stock = ?, minimum_order_quantity = ?,
                                            units_per_package = ? WHERE sku = ?");
                        $upd->execute([
                            $barcode,
                            $product_name,
                            $brand,
                            $category_id,
                            $unit_price,
                            $cost_price,
                            $reorder_level,
                            $preferred_supplier,
                            $preferred_supplier,
                            $supplier_lead_time_days,
                            $safety_stock,
                            $minimum_order_quantity,
                            $units_per_package,
                            $sku,
                        ]);
                        $afterStmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
                        $afterStmt->execute([(int)$prod['product_id']]);
                        log_activity(
                            $pdo,
                            (int)$_SESSION['user_id'],
                            'Product modification',
                            'Products',
                            (int)$prod['product_id'],
                            $beforeProduct,
                            $afterStmt->fetch()
                        );
                    } else {
                        // Insert new
                        $ins = $pdo->prepare("INSERT INTO products (sku, barcode, product_name, brand, category_id,
                                            unit_price, cost_price, reorder_level, supplier, preferred_supplier,
                                            supplier_lead_time_days, safety_stock, minimum_order_quantity,
                                            units_per_package, created_by)
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([
                            $sku,
                            $barcode,
                            $product_name,
                            $brand,
                            $category_id,
                            $unit_price,
                            $cost_price,
                            $reorder_level,
                            $preferred_supplier,
                            $preferred_supplier,
                            $supplier_lead_time_days,
                            $safety_stock,
                            $minimum_order_quantity,
                            $units_per_package,
                            $_SESSION['user_id'],
                        ]);
                        $product_id = $pdo->lastInsertId();

                        // Create inventory record
                        $inv_ins = $pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, 0)");
                        $inv_ins->execute([$product_id]);

                        $afterStmt = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
                        $afterStmt->execute([(int)$product_id]);
                        log_activity(
                            $pdo,
                            (int)$_SESSION['user_id'],
                            'Product creation',
                            'Products',
                            (int)$product_id,
                            null,
                            $afterStmt->fetch()
                        );
                    }

                    $successful++;
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Row " . ($successful + $failed) . ": " . $e->getMessage();
                }
            }

            $message = "Product import completed: $successful successful, $failed failed";
        } elseif ($import_type === 'inventory') {
            // Expected columns: sku, quantity_to_add
            while (($row = fgetcsv($fp)) !== false) {
                if (empty(array_filter($row))) continue;

                try {
                    $sku = trim($row[0] ?? '');
                    $qty = (int)($row[1] ?? 0);

                    if (!$sku) {
                        $failed++;
                        $errors[] = "Row " . ($successful + $failed) . ": SKU is required";
                        continue;
                    }

                    // Find product by SKU
                    $prod_stmt = $pdo->prepare("SELECT product_id FROM products WHERE sku = ?");
                    $prod_stmt->execute([$sku]);
                    $prod = $prod_stmt->fetch();

                    if (!$prod) {
                        $failed++;
                        $errors[] = "Row " . ($successful + $failed) . ": Product SKU '$sku' not found";
                        continue;
                    }

                    if ($qty !== 0) {
                        $fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
                    }

                    // Update inventory
                    $product_id = $prod['product_id'];
                    $beforeInvStmt = $pdo->prepare("SELECT product_id, quantity_on_hand FROM inventory WHERE product_id = ?");
                    $beforeInvStmt->execute([(int)$product_id]);
                    $beforeInventory = $beforeInvStmt->fetch();
                    $inv_upd = $pdo->prepare("UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?");
                    $inv_upd->execute([$qty, $product_id]);

                    // Log stock movement
                    $mov_ins = $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by)
                                             VALUES (?, ?, 'purchase', ?)");
                    $mov_ins->execute([$product_id, $qty, $_SESSION['user_id']]);

                    $afterInventory = $beforeInventory ?: ['product_id' => (int)$product_id, 'quantity_on_hand' => 0];
                    $afterInventory['quantity_on_hand'] = (int)($afterInventory['quantity_on_hand'] ?? 0) + $qty;
                    $afterInventory['import_qty'] = $qty;
                    log_activity(
                        $pdo,
                        (int)$_SESSION['user_id'],
                        'CSV inventory import adjustment',
                        'CSV Import',
                        (int)$product_id,
                        $beforeInventory,
                        $afterInventory
                    );

                    $successful++;
                } catch (Exception $e) {
                    $failed++;
                    $errors[] = "Row " . ($successful + $failed) . ": " . $e->getMessage();
                }
            }

            $message = "Inventory import completed: $successful successful, $failed failed";
        }

        fclose($fp);

        // Log the import
        $log_stmt = $pdo->prepare("INSERT INTO csv_import_logs (imported_by, import_type, filename, total_rows, successful_rows, failed_rows, status)
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
        $log_stmt->execute([$_SESSION['user_id'], $import_type, $file['name'], $successful + $failed, $successful, $failed, $failed === 0 ? 'completed' : 'completed']);
        $importId = (int)$pdo->lastInsertId();

        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'CSV import',
            'CSV Import',
            $importId,
            null,
            [
                'import_id' => $importId,
                'import_type' => $import_type,
                'filename' => $file['name'],
                'total_rows' => $successful + $failed,
                'successful_rows' => $successful,
                'failed_rows' => $failed,
                'status' => $failed === 0 ? 'completed' : 'completed_with_errors',
                'errors' => $errors,
            ]
        );

        $import_results = [
            'successful' => $successful,
            'failed' => $failed,
            'errors' => $errors,
            'type' => $import_type,
            'filename' => $file['name']
        ];

        if ($failed === 0) {
            $message = "✓ " . ($message ?? "Import successful!");
        } else {
            $error = "⚠ Some records failed to import. Check details below.";
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Bulk Import</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
    <style>
        .import-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .form-group input[type="file"] {
            padding: 0.5rem;
        }
        .btn-import {
            background: #007bff;
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .btn-import:hover {
            background: #0056b3;
        }
        .message {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .results-section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .results-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        .result-box {
            background: #f5f5f5;
            padding: 1rem;
            border-radius: 4px;
            text-align: center;
        }
        .result-box .number {
            font-size: 2rem;
            font-weight: bold;
            color: #007bff;
        }
        .result-box.success .number {
            color: #28a745;
        }
        .result-box.failed .number {
            color: #dc3545;
        }
        .result-box .label {
            color: #666;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        .errors-list {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            padding: 1rem;
        }
        .errors-list h4 {
            margin-top: 0;
            color: #856404;
        }
        .error-item {
            background: white;
            padding: 0.5rem;
            margin: 0.5rem 0;
            border-left: 4px solid #dc3545;
            font-size: 0.9rem;
            font-family: monospace;
        }
        .template-links {
            background: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .template-links h4 {
            margin-top: 0;
        }
        .template-links ul {
            margin: 0;
            padding-left: 1.5rem;
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <div class="main-content">
        <div class="topbar">
            <h1>CSV Bulk Import</h1>
        </div>

        <?php if ($message): ?>
            <div class="message success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="template-links">
            <h4>📋 CSV Format Guide</h4>
            <ul>
                <li><strong>Products Import:</strong> sku, barcode, product_name, brand, category_name, unit_price, cost_price, reorder_level, preferred_supplier, supplier_lead_time_days, safety_stock, minimum_order_quantity, units_per_package</li>
                <li><strong>Inventory Import:</strong> sku, quantity_to_add</li>
                <li>Ensure first row contains headers matching the format above</li>
                <li>Prices should be numeric (e.g., 15.50)</li>
            </ul>
        </div>

        <div class="import-section">
            <h3>Upload CSV File</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generate_csrf_token()) ?>">

                <div class="form-group">
                    <label for="import_type">Import Type</label>
                    <select name="import_type" id="import_type" required>
                        <option value="">-- Select Import Type --</option>
                        <option value="products">Products (New/Update)</option>
                        <option value="inventory">Inventory Quantities</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="csv_file">CSV File</label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
                </div>

                <button type="submit" class="btn-import">Upload & Import</button>
            </form>
        </div>

        <?php if ($import_results): ?>
            <div class="results-section">
                <h3>Import Results</h3>
                <p><strong>File:</strong> <?= htmlspecialchars($import_results['filename']) ?> |
                   <strong>Type:</strong> <?= htmlspecialchars(ucfirst($import_results['type'])) ?></p>

                <div class="results-summary">
                    <div class="result-box success">
                        <div class="number"><?= $import_results['successful'] ?></div>
                        <div class="label">Successful</div>
                    </div>
                    <div class="result-box <?= $import_results['failed'] > 0 ? 'failed' : 'success' ?>">
                        <div class="number"><?= $import_results['failed'] ?></div>
                        <div class="label">Failed</div>
                    </div>
                    <div class="result-box">
                        <div class="number"><?= $import_results['successful'] + $import_results['failed'] ?></div>
                        <div class="label">Total Records</div>
                    </div>
                </div>

                <?php if (!empty($import_results['errors'])): ?>
                    <div class="errors-list">
                        <h4>⚠️ Error Details</h4>
                        <?php foreach ($import_results['errors'] as $err): ?>
                            <div class="error-item"><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
