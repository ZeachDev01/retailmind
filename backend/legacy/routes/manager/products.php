<?php
// manager/products.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../app/Services/ProductService.php';
require_role(['admin', 'inventory_manager']);

$productService = new ProductService($pdo);

// Add new category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_category') {
    csrf_verify();

    try {
        $productService->createCategory([
            'category_name' => $_POST['category_name'] ?? '',
        ], $_SESSION['user_id']);
        log_activity($pdo, $_SESSION['user_id'], 'Added category ' . ($_POST['category_name'] ?? ''));
        header('Location: products.php?success=' . urlencode('Category added successfully.'));
        exit;
    } catch (Throwable $e) {
        header('Location: products.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Add new product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    csrf_verify();

    try {
        $productId = $productService->createProduct([
            'barcode' => $_POST['barcode'] ?? '',
            'product_name' => $_POST['product_name'] ?? '',
            'brand' => $_POST['brand'] ?? '',
            'category_id' => $_POST['category_id'] ?? '',
            'cost_price' => $_POST['cost_price'] ?? 0,
            'selling_price' => $_POST['selling_price'] ?? 0,
            'reorder_level' => $_POST['reorder_level'] ?? 10,
            'preferred_supplier' => $_POST['preferred_supplier'] ?? '',
            'supplier_lead_time_days' => $_POST['supplier_lead_time_days'] ?? 7,
            'safety_stock' => $_POST['safety_stock'] ?? 0,
            'minimum_order_quantity' => $_POST['minimum_order_quantity'] ?? 1,
            'units_per_package' => $_POST['units_per_package'] ?? 1,
            'expiration_date' => $_POST['expiration_date'] ?? null,
            'product_image' => $_POST['product_image'] ?? '',
            'status' => $_POST['status'] ?? 'active',
            'initial_stock_quantity' => $_POST['initial_stock_quantity'] ?? 0,
        ], $_SESSION['user_id']);
        $barcode = $_POST['barcode'] ?? '';
        $newProductStmt = $pdo->prepare(
            "SELECT p.*, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON i.product_id = p.product_id
             WHERE p.product_id = ?"
        );
        $newProductStmt->execute([$productId]);
        $newProduct = $newProductStmt->fetch() ?: ['barcode' => $barcode];
        log_activity(
            $pdo,
            (int)$_SESSION['user_id'],
            'Product creation',
            'Products',
            $productId,
            null,
            $newProduct
        );

        $labelQuantity = max(1, min(200, (int)($_POST['label_quantity'] ?? 1)));
        if (!empty($_POST['print_after_create'])) {
            header('Location: print_barcodes.php?product_id=' . $productId . '&quantity=' . $labelQuantity . '&autoprint=1');
            exit;
        }

        $createdBarcode = trim((string)($newProduct['barcode'] ?? ''));
        header('Location: products.php?success=' . urlencode('Product added successfully. Barcode: ' . $createdBarcode));
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: products.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Generate an internal barcode for an existing product that has no barcode.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_barcode') {
    csrf_verify();

    try {
        $productId = (int)($_POST['product_id'] ?? 0);
        $labelQuantity = max(1, min(200, (int)($_POST['label_quantity'] ?? 1)));
        $productService->assignGeneratedBarcode($productId, (int)$_SESSION['user_id']);
        header('Location: print_barcodes.php?product_id=' . $productId . '&quantity=' . $labelQuantity . '&autoprint=1');
        exit;
    } catch (Throwable $e) {
        header('Location: products.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Stock in / purchase module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purchase') {
    csrf_verify();

    try {
        $productService->purchaseStock([
            'search_term' => $_POST['search_term'] ?? '',
            'purchase_quantity' => $_POST['purchase_quantity'] ?? 0,
            'purchase_cost_price' => $_POST['purchase_cost_price'] ?? 0,
            'purchase_date' => $_POST['purchase_date'] ?? '',
            'barcode' => $_POST['barcode'] ?? '',
            'product_name' => $_POST['product_name'] ?? '',
            'brand' => $_POST['brand'] ?? '',
            'category_id' => $_POST['category_id'] ?? '',
            'selling_price' => $_POST['selling_price'] ?? 0,
            'reorder_level' => $_POST['reorder_level'] ?? 10,
            'supplier_lead_time_days' => $_POST['supplier_lead_time_days'] ?? 7,
            'safety_stock' => $_POST['safety_stock'] ?? 0,
            'minimum_order_quantity' => $_POST['minimum_order_quantity'] ?? 1,
            'units_per_package' => $_POST['units_per_package'] ?? 1,
            'expiration_date' => $_POST['expiration_date'] ?? null,
            'product_image' => $_POST['product_image'] ?? '',
            'status' => $_POST['status'] ?? 'active',
            'supplier' => $_POST['supplier'] ?? '',
        ], $_SESSION['user_id']);
        header('Location: products.php');
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        header('Location: products.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

// Stock adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'adjust') {
    csrf_verify();
    
    try {
        $qty_change = (int)$_POST['qty_change'];
        $product_id = (int)$_POST['product_id'];
        $adjustment_reason = trim((string)($_POST['adjustment_reason'] ?? 'adjustment'));

        if ($product_id <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }

        $productService->adjustStock([
            'qty_change' => $qty_change,
            'product_id' => $product_id,
            'adjustment_reason' => $adjustment_reason,
        ], $_SESSION['user_id']);
        header('Location: products.php');
        exit;
    } catch (Throwable $e) {
        header('Location: products.php?error=' . urlencode($e->getMessage()));
        exit;
    }
}

$categories = $productService->getCategories();
$products = $productService->getProductsForManagement();
$active_products = $productService->getActiveProducts();
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Products & Stock</title>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>"></head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../../../modules/sidebar.php'; ?>
    <div class="main-content">
        <h1>Products & Stock</h1>
        <?php if (!empty($_GET['error'])): ?>
            <p style="margin-top:0.75rem;"><span class="tag-warning"><?= htmlspecialchars($_GET['error']) ?></span></p>
        <?php endif; ?>
        <?php if (!empty($_GET['success'])): ?>
            <p style="margin-top:0.75rem;"><span class="tag-success"><?= htmlspecialchars($_GET['success']) ?></span></p>
        <?php endif; ?>

        <div style="display:flex;gap:1rem;margin-top:1.5rem;flex-wrap:wrap;">
            <button type="button" class="btn" id="add-product-btn" style="background:#10b981;padding:0.75rem 1.5rem;font-size:1rem;">➕ Add Product</button>
            <button type="button" class="btn" id="stock-in-btn" style="background:#3b82f6;padding:0.75rem 1.5rem;font-size:1rem;">⚖️ Adjust Stock</button>
            <button type="button" class="btn" id="add-category-btn" style="background:#8b5cf6;padding:0.75rem 1.5rem;font-size:1rem;">➕ Add Category</button>
            <a class="btn" href="print_barcodes.php" style="background:#f59e0b;padding:0.75rem 1.5rem;font-size:1rem;text-decoration:none;">🖨️ Print Barcode Labels</a>
        </div>

        <h3 style="margin-top:2rem;">Product Management</h3>
        <div style="overflow-x:auto;">
            <table>
                <tr>
                    <th>Item ID</th><th>Barcode</th><th>Product</th><th>Brand</th><th>Category</th><th>Cost</th><th>Selling</th><th>Current Stock</th><th>Qty Purchased</th><th>Qty Sold</th><th>Planning</th><th>Expiration</th><th>Status</th><th>Image</th><th>Barcode Label</th><th>Adjust Stock</th>
                </tr>
                <?php foreach ($products as $p): ?>
                <tr>
                    <td>#<?= $p['product_id'] ?></td>
                    <td>
                        <?php $displayBarcode = trim((string)($p['barcode'] ?? '')); ?>
                        <?php if ($displayBarcode !== ''): ?>
                            <strong><?= htmlspecialchars($displayBarcode) ?></strong>
                        <?php else: ?>
                            <span class="tag-warning">Not assigned</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['product_name']) ?></td>
                    <td><?= htmlspecialchars($p['brand'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                    <td>₱<?= number_format((float)$p['cost_price'], 2) ?></td>
                    <td>₱<?= number_format((float)$p['unit_price'], 2) ?></td>
                    <td><?= (int)$p['quantity_on_hand'] ?></td>
                    <td><?= (int)($p['quantity_purchased'] ?? 0) ?></td>
                    <td><?= (int)($p['quantity_sold'] ?? 0) ?></td>
                    <td>
                        Reorder: <?= (int)$p['reorder_level'] ?><br>
                        Safety: <?= (int)($p['safety_stock'] ?? 0) ?><br>
                        Lead: <?= (int)($p['supplier_lead_time_days'] ?? 7) ?>d<br>
                        MOQ/Case: <?= (int)($p['minimum_order_quantity'] ?? 1) ?> / <?= (int)($p['units_per_package'] ?? 1) ?><br>
                        Supplier: <?= htmlspecialchars($p['preferred_supplier'] ?? $p['supplier'] ?? '-') ?>
                    </td>
                    <td><?= htmlspecialchars($p['expiration_date'] ?? '-') ?></td>
                    <td><?= htmlspecialchars(ucfirst($p['status'] ?? 'active')) ?></td>
                    <td>
                        <?php if (!empty($p['product_image'])): ?>
                            <a href="<?= htmlspecialchars($p['product_image']) ?>" target="_blank" rel="noopener">View</a>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($displayBarcode !== ''): ?>
                            <form method="GET" action="print_barcodes.php" target="_blank" style="display:flex;gap:0.4rem;align-items:center;">
                                <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>">
                                <input type="number" name="quantity" value="1" min="1" max="200" aria-label="Number of barcode labels" style="width:64px;">
                                <button class="btn" type="submit" style="padding:0.35rem 0.7rem;background:#f59e0b;">Print</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" target="_blank" style="display:flex;gap:0.4rem;align-items:center;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="generate_barcode">
                                <input type="hidden" name="product_id" value="<?= (int)$p['product_id'] ?>">
                                <input type="hidden" name="label_quantity" value="1">
                                <button class="btn" type="submit" style="padding:0.35rem 0.7rem;background:#10b981;">Generate &amp; Print</button>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display:flex;gap:0.4rem;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="adjust">
                            <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
                            <input type="number" name="qty_change" placeholder="+/-" style="width:70px;">
                            <button class="btn" type="submit" style="padding:0.3rem 0.7rem;">Apply</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div style="margin-top:1.5rem;">
            <!-- Recent purchase history page removed -->
        </div>
    </div>
</div>

<!-- Add Product Modal -->
<div id="add-product-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(148,163,184,0.2);border-radius:16px;padding:2rem;max-width:600px;width:90%;max-height:90vh;overflow-y:auto;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h2 style="margin:0;">Add Product</h2>
            <button type="button" class="close-modal-btn" data-modal="add-product-modal" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b;">✕</button>
        </div>

        <!-- Scanner for Add Product Modal -->
        <div id="add-product-scanner-section" style="display:none;background:#1e293b;padding:1rem;border-radius:10px;margin-bottom:1.5rem;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <strong style="color:#94a3b8;">QR Code Scanner</strong>
                <button type="button" class="btn" id="close-add-product-scanner" style="background:#b91c1c;padding:0.3rem 0.8rem;font-size:0.875rem;">Close</button>
            </div>
            <div id="add-product-scanner-reader" style="width:100%;max-width:420px;border-radius:12px;overflow:hidden;background:#000;margin:0 auto;"></div>
            <div id="add-product-scanner-result" style="margin-top:0.75rem;background:#111827;padding:0.9rem 1rem;border-radius:10px;word-break:break-all;text-align:center;">
                Point camera at a barcode
            </div>
        </div>

        <form method="POST" style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.8rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group" style="grid-column:1 / -1;">
                <label>Barcode</label>
                <div style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                        <input name="barcode" id="create-barcode-input" maxlength="50" placeholder="Scan, enter, generate, or leave blank" style="width:100%;">
                    </div>
                    <button type="button" class="btn" id="add-product-scan-btn" style="padding:0.5rem 1rem;white-space:nowrap;background:#8b5cf6;">🔍 Scan</button>
                    <button type="button" class="btn" id="generate-barcode-btn" style="padding:0.5rem 1rem;white-space:nowrap;background:#f59e0b;">Generate</button>
                </div>
                <small style="display:block;margin-top:0.45rem;color:#94a3b8;">For products without a manufacturer barcode, leave this blank or click Generate. RetailMind will assign an internal barcode.</small>
            </div>
            <div class="form-group"><label>Product Name</label><input name="product_name" required></div>
            <div class="form-group"><label>Brand</label><input name="brand"></div>
            <div class="form-group">
                <label>Category</label>
                <select name="category_id">
                    <option value="">--</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['category_id'] ?>"><?= htmlspecialchars($c['category_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Cost Price</label><input type="number" step="0.01" min="0" name="cost_price" required></div>
            <div class="form-group"><label>Selling Price</label><input type="number" step="0.01" min="0" name="selling_price" required></div>
            <div class="form-group"><label>Current Stock Quantity</label><input type="number" min="0" name="initial_stock_quantity" value="0"></div>
            <div class="form-group"><label>Reorder Level</label><input type="number" min="0" name="reorder_level" value="10"></div>
            <div class="form-group"><label>Preferred Supplier</label><input name="preferred_supplier" placeholder="Supplier name"></div>
            <div class="form-group"><label>Supplier Lead Time (Days)</label><input type="number" min="0" name="supplier_lead_time_days" value="7"></div>
            <div class="form-group"><label>Safety Stock</label><input type="number" min="0" name="safety_stock" value="0"></div>
            <div class="form-group"><label>Minimum Order Quantity</label><input type="number" min="1" name="minimum_order_quantity" value="1"></div>
            <div class="form-group"><label>Units per Package/Case</label><input type="number" min="1" name="units_per_package" value="1"></div>
            <div class="form-group"><label>Expiration Date</label><input type="date" name="expiration_date"></div>
            <div class="form-group"><label>Product Image</label><input name="product_image" placeholder="Optional image URL or path"></div>
            <div class="form-group">
                <label>Status</label>
                <select name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
            <div style="grid-column:1 / -1;display:flex;gap:1rem;align-items:center;flex-wrap:wrap;padding:0.85rem 1rem;background:#1e293b;border-radius:10px;">
                <label style="display:flex;gap:0.5rem;align-items:center;margin:0;">
                    <input type="checkbox" name="print_after_create" value="1" checked style="width:auto;">
                    Open printable barcode labels after saving
                </label>
                <label style="display:flex;gap:0.5rem;align-items:center;margin:0;">
                    Labels:
                    <input type="number" name="label_quantity" value="1" min="1" max="200" style="width:80px;">
                </label>
            </div>
            <div style="grid-column:1 / -1;display:flex;justify-content:flex-end;gap:0.5rem;">
                <button type="button" class="close-modal-btn" data-modal="add-product-modal" style="background:#6b7280;">Cancel</button>
                <button class="btn" type="submit">Add Product</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Category Modal -->
<div id="add-category-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(148,163,184,0.2);border-radius:16px;padding:2rem;max-width:420px;width:90%;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h2 style="margin:0;">➕ Add Category</h2>
            <button type="button" class="close-modal-btn" data-modal="add-category-modal" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b;">✕</button>
        </div>
        <form method="POST" style="display:grid;gap:0.8rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_category">
            <div class="form-group">
                <label>Category Name</label>
                <input type="text" name="category_name" required placeholder="Enter new category name">
            </div>
            <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:0.5rem;">
                <button type="button" class="close-modal-btn" data-modal="add-category-modal" style="background:#6b7280;">Cancel</button>
                <button class="btn" type="submit" style="background:#10b981;">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Adjust Stock Modal -->
<div id="stock-in-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#0f172a;border:1px solid rgba(148,163,184,0.2);border-radius:16px;padding:2rem;max-width:500px;width:90%;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">
            <h2 style="margin:0;">⚖️ Adjust Stock</h2>
            <button type="button" class="close-modal-btn" data-modal="stock-in-modal" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#64748b;">✕</button>
        </div>

        <!-- Scanner Section -->
        <div style="background:#1e293b;padding:1rem;border-radius:10px;margin-bottom:1.5rem;display:none;" id="stock-in-scanner-section">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <strong style="color:#94a3b8;">📱 QR Code Scanner</strong>
                <button type="button" class="btn" id="close-stock-in-scanner" style="background:#b91c1c;padding:0.3rem 0.8rem;font-size:0.875rem;">Close</button>
            </div>
            <div id="scanner-reader" style="width:100%;max-width:420px;border-radius:12px;overflow:hidden;background:#000;margin:0 auto;"></div>
            <div id="scanner-result" style="margin-top:0.75rem;background:#111827;padding:0.9rem 1rem;border-radius:10px;word-break:break-all;text-align:center;color:#94a3b8;">
                Point camera at barcode
            </div>
        </div>

        <form method="POST" style="display:grid;grid-template-columns:1fr;gap:0.8rem;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            
            <div class="form-group" style="display:flex;flex-direction:column;">
                <label style="margin-bottom:0.5rem;font-weight:500;font-size:0.95rem;">Select Product *</label>
                <div style="display:flex;gap:0.5rem;align-items:flex-end;">
                    <select name="product_id" id="adjust-product-select" required style="flex:1;padding:0.6rem 0.9rem;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:0.95rem;">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($active_products as $p): ?>
                            <option value="<?= $p['product_id'] ?>" data-barcode="<?= htmlspecialchars($p['barcode'] ?? $p['sku']) ?>">
                                <?= htmlspecialchars($p['sku'] . ' - ' . $p['product_name']) ?> (<?= $p['quantity_on_hand'] ?> on hand)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn" id="quick-scan-btn" style="padding:0.6rem 1rem;white-space:nowrap;background:#8b5cf6;">🔍 Scan</button>
                </div>
            </div>

            <div class="form-group" style="display:flex;flex-direction:column;">
                <label style="margin-bottom:0.5rem;font-weight:500;font-size:0.95rem;">Quantity Change (+/- or ±) *</label>
                <input type="number" name="qty_change" required placeholder="e.g., 5 to add, -3 to remove" style="padding:0.6rem 0.9rem;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:0.95rem;">
            </div>

            <div class="form-group" style="display:flex;flex-direction:column;">
                <label style="margin-bottom:0.5rem;font-weight:500;font-size:0.95rem;">Adjustment Reason</label>
                <select name="adjustment_reason" style="padding:0.6rem 0.9rem;border:1px solid var(--border);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:0.95rem;">
                    <option value="adjustment">Inventory Adjustment</option>
                    <option value="return">Customer Return</option>
                    <option value="damage">Damaged/Expired</option>
                    <option value="recount">Recount/Correction</option>
                    <option value="transfer">Internal Transfer</option>
                </select>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1rem;">
                <button type="button" class="close-modal-btn" data-modal="stock-in-modal" style="background:#6b7280;padding:0.6rem 1.2rem;border:none;border-radius:8px;color:white;cursor:pointer;font-size:0.95rem;">Cancel</button>
                <button class="btn" type="submit" style="background:#3b82f6;padding:0.6rem 1.2rem;border:none;border-radius:8px;color:white;cursor:pointer;font-size:0.95rem;">✓ Apply Adjustment</button>
            </div>
        </form>
    </div>
</div>

<script src="https://unpkg.com/html5-qrcode"></script>
<script>
    // Modal handling
    const addProductBtn = document.getElementById('add-product-btn');
    const stockInBtn = document.getElementById('stock-in-btn');
    const addCategoryBtn = document.getElementById('add-category-btn');
    const addProductModal = document.getElementById('add-product-modal');
    const stockInModal = document.getElementById('stock-in-modal');
    const addCategoryModal = document.getElementById('add-category-modal');
    const closeModalBtns = document.querySelectorAll('.close-modal-btn');

    addProductBtn.addEventListener('click', () => {
        addProductModal.style.display = 'flex';
    });

    stockInBtn.addEventListener('click', () => {
        stockInModal.style.display = 'flex';
    });

    addCategoryBtn.addEventListener('click', () => {
        addCategoryModal.style.display = 'flex';
    });

    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modalId = e.target.getAttribute('data-modal');
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });

    // Close modal when clicking outside
    [addProductModal, stockInModal, addCategoryModal].forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    });

    // QR Code Scanner for Adjust Stock Modal
    const resultDiv = document.getElementById('scanner-result');
    const quickScanBtn = document.getElementById('quick-scan-btn');
    const closeStockInScannerBtn = document.getElementById('close-stock-in-scanner');
    const stockInScannerSection = document.getElementById('stock-in-scanner-section');
    const adjustProductSelect = document.getElementById('adjust-product-select');
    const html5QrCode = new Html5Qrcode('scanner-reader');

    // QR Code Scanner for Add Product Modal
    const addProductScanBtn = document.getElementById('add-product-scan-btn');
    const closeAddProductScannerBtn = document.getElementById('close-add-product-scanner');
    const addProductScannerSection = document.getElementById('add-product-scanner-section');
    const addProductScannerResult = document.getElementById('add-product-scanner-result');
    const createBarcodeInput = document.getElementById('create-barcode-input');
    const generateBarcodeBtn = document.getElementById('generate-barcode-btn');
    const addProductHtml5QrCode = new Html5Qrcode('add-product-scanner-reader');

    const config = {
        fps: 10,
        qrbox: { width: 250, height: 250 },
        aspectRatio: 1.0
    };

    const onScanSuccess = (decodedText) => {
        const option = Array.from(adjustProductSelect.options).find(opt => opt.getAttribute('data-barcode') === decodedText);
        if (option) {
            adjustProductSelect.value = option.value;
            resultDiv.textContent = `✔ Found: ${option.textContent}`;
            if (navigator.vibrate) navigator.vibrate(200);
        } else {
            resultDiv.textContent = `✗ Barcode not found: ${decodedText}`;
            if (navigator.vibrate) navigator.vibrate([100, 50, 100]);
        }
        setTimeout(() => stopScanner(), 1500);
    };

    const onScanError = () => {
        // Ignore scan noise while the camera is active.
    };

    function startScanner() {
        stockInScannerSection.style.display = 'block';
        html5QrCode.start(
            { facingMode: 'environment' },
            config,
            onScanSuccess,
            onScanError
        ).then(() => {
            resultDiv.textContent = 'Scanner started. Point camera at a barcode.';
        }).catch(err => {
            resultDiv.textContent = `Error: ${err}`;
        });
    }

    function stopScanner() {
        html5QrCode.stop().then(() => {
            stockInScannerSection.style.display = 'none';
        }).catch(() => {});
    }

    quickScanBtn.addEventListener('click', startScanner);
    closeStockInScannerBtn.addEventListener('click', stopScanner);

    // Add Product Modal Scanner Handlers
    function startAddProductScanner() {
        addProductScannerSection.style.display = 'block';
        addProductHtml5QrCode.start(
            { facingMode: 'environment' },
            config,
            (decodedText) => {
                createBarcodeInput.value = decodedText;
                addProductScannerResult.textContent = `✔ ${decodedText}`;
                if (navigator.vibrate) navigator.vibrate(200);
                addProductHtml5QrCode.stop().catch(() => {});
                addProductScannerSection.style.display = 'none';
            },
            () => {}
        ).then(() => {
            addProductScannerResult.textContent = 'Scanner started. Point camera at a barcode.';
        }).catch(err => {
            addProductScannerResult.textContent = `Error: ${err}`;
        });
    }

    function stopAddProductScanner() {
        addProductHtml5QrCode.stop().then(() => {
            addProductScannerSection.style.display = 'none';
        }).catch(() => {});
    }

    function generateInternalBarcode() {
        const now = new Date();
        const datePart = String(now.getFullYear()).slice(-2)
            + String(now.getMonth() + 1).padStart(2, '0')
            + String(now.getDate()).padStart(2, '0');
        let randomNumber;
        if (window.crypto && window.crypto.getRandomValues) {
            const values = new Uint32Array(1);
            window.crypto.getRandomValues(values);
            randomNumber = values[0] % 100000000;
        } else {
            randomNumber = Math.floor(Math.random() * 100000000);
        }
        return `RM${datePart}${String(randomNumber).padStart(8, '0')}`;
    }

    generateBarcodeBtn.addEventListener('click', () => {
        createBarcodeInput.value = generateInternalBarcode();
        createBarcodeInput.focus();
        createBarcodeInput.select();
    });

    addProductScanBtn.addEventListener('click', (e) => {
        e.preventDefault();
        startAddProductScanner();
    });
    closeAddProductScannerBtn.addEventListener('click', stopAddProductScanner);
</script>
</body>
</html>
