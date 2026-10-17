<?php
// app/Services/ProductService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class ProductService
{
    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->notificationService = new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
    }

    public function findProductBySearchTerm(string $term): ?array
    {
        $term = trim($term);
        if ($term === '') {
            return null;
        }

           [$scopeSql, $scopeParams] = $this->storeProductScope();
           $stmt = $this->pdo->prepare(
            "SELECT p.*, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
               WHERE (p.barcode = ? OR p.case_barcode = ? OR p.sku = ? OR p.product_name = ?){$scopeSql}
             LIMIT 1"
        );
           $stmt->execute(array_merge([$term, $term, $term, $term], $scopeParams));
        $product = $stmt->fetch();
        if ($product) {
            return $product;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.*, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             WHERE (p.barcode LIKE ? OR p.case_barcode LIKE ? OR p.product_name LIKE ?){$scopeSql}
             ORDER BY p.product_name
             LIMIT 1"
        );
        $likeTerm = '%' . $term . '%';
        $stmt->execute(array_merge([$likeTerm, $likeTerm, $likeTerm], $scopeParams));
        return $stmt->fetch() ?: null;
    }

    public function createProduct(array $data, int $userId): int
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        $barcode = trim((string)($data['barcode'] ?? ''));
        $productName = trim((string)($data['product_name'] ?? ''));
        $brand = trim((string)($data['brand'] ?? ''));
        $categoryId = (($data['category_id'] ?? '') !== '') ? (int)$data['category_id'] : null;
        $costPrice = (float)($data['cost_price'] ?? 0);
        $sellingPrice = (float)($data['selling_price'] ?? 0);
        $reorderLevel = (int)($data['reorder_level'] ?? 10);
        $preferredSupplier = trim((string)($data['preferred_supplier'] ?? ($data['supplier'] ?? '')));
        $supplierLeadTimeDays = max(0, (int)($data['supplier_lead_time_days'] ?? 7));
        $safetyStock = max(0, (int)($data['safety_stock'] ?? 0));
        $minimumOrderQuantity = max(1, (int)($data['minimum_order_quantity'] ?? 1));
        $unitsPerPackage = max(1, (int)($data['units_per_package'] ?? 1));
        $baseUnit = trim((string)($data['base_unit'] ?? 'piece')) ?: 'piece';
        $receivingUnit = trim((string)($data['receiving_unit'] ?? 'package')) ?: 'package';
        $caseBarcode = trim((string)($data['case_barcode'] ?? ''));
        $parentProductId = (int)($data['parent_product_id'] ?? 0);
        $variantLabel = trim((string)($data['variant_label'] ?? ''));
        $expirationDate = (($data['expiration_date'] ?? '') !== '') ? $data['expiration_date'] : null;
        $batchNumber = trim((string)($data['batch_number'] ?? ''));
        $productImage = trim((string)($data['product_image'] ?? ''));
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $initialStock = max(0, (int)($data['initial_stock_quantity'] ?? 0));
        $branchId = function_exists('store_scope_id')
            ? store_scope_id($this->pdo)
            : (new App\Store\StoreScope($this->pdo))->id();

        if ($productName === '') {
            throw new RuntimeException('Product name is required.');
        }

        if ($barcode === '') {
            $barcode = $this->generateUniqueInternalBarcode();
        }
        if (strlen($barcode) > 50) {
            throw new RuntimeException('Barcode must not exceed 50 characters.');
        }
        $this->assertBarcodeAvailable($barcode);
        if ($caseBarcode !== '') {
            if (strlen($caseBarcode) > 80) throw new RuntimeException('Case barcode must not exceed 80 characters.');
            $caseStmt = $this->pdo->prepare('SELECT product_id FROM products WHERE barcode=? OR sku=? OR case_barcode=? LIMIT 1');
            $caseStmt->execute([$caseBarcode,$caseBarcode,$caseBarcode]);
            if ($caseStmt->fetch()) throw new RuntimeException('That case barcode is already assigned.');
        }
        if ($parentProductId > 0) {
            [$scopeSql, $scopeParams] = $this->storeProductScope();
            $parentStmt = $this->pdo->prepare("SELECT product_id FROM products WHERE product_id=? AND status='active'{$scopeSql}");
            $parentStmt->execute(array_merge([$parentProductId], $scopeParams));
            if (!$parentStmt->fetchColumn()) {
                throw new RuntimeException('Selected parent product was not found.');
            }
            if ($variantLabel === '') {
                throw new RuntimeException('Variant label is required when a parent product is selected.');
            }
        } else {
            $parentProductId = 0;
            $variantLabel = '';
        }

        if ($initialStock > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('purchase_history', 'purchase history');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            StoreWriteGate::begin($this->pdo);
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO products (
                    sku, barcode, case_barcode, parent_product_id, variant_label, product_name, brand, category_id, unit_price, cost_price,
                    quantity_purchased, quantity_sold, reorder_level, supplier, preferred_supplier,
                    supplier_lead_time_days, safety_stock, minimum_order_quantity, units_per_package, base_unit, receiving_unit, expiration_date,
                    product_image, status, created_by, branch_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $barcode,
                $barcode,
                $caseBarcode !== '' ? $caseBarcode : null,
                $parentProductId > 0 ? $parentProductId : null,
                $variantLabel !== '' ? $variantLabel : null,
                $productName,
                $brand !== '' ? $brand : null,
                $categoryId,
                $sellingPrice,
                $costPrice,
                $initialStock,
                0,
                $reorderLevel,
                $preferredSupplier !== '' ? $preferredSupplier : null,
                $preferredSupplier !== '' ? $preferredSupplier : null,
                $supplierLeadTimeDays,
                $safetyStock,
                $minimumOrderQuantity,
                $unitsPerPackage,
                $baseUnit,
                $receivingUnit,
                $expirationDate,
                $productImage !== '' ? $productImage : null,
                $status,
                $userId,
                $branchId,
            ]);
            $productId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare("INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, ?)")
                ->execute([$productId, $initialStock]);
            


            if ($initialStock > 0) {
                $purchaseDate = date('Y-m-d H:i:s');
                $totalCost = $costPrice * $initialStock;
                $this->createProductBatch(
                    $productId,
                    $batchNumber !== '' ? $batchNumber : null,
                    $initialStock,
                    $expirationDate,
                    $purchaseDate,
                    $preferredSupplier !== '' ? $preferredSupplier : null
                );
                $this->pdo->prepare(
                    "INSERT INTO purchase_history (product_id, quantity_purchased, cost_price, purchase_date, total_cost, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$productId, $initialStock, $costPrice, $purchaseDate, $totalCost, $userId]);
                $this->pdo->prepare(
                    "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'purchase', ?)"
                )->execute([$productId, $initialStock, $userId]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
                $this->notificationService->checkAndNotifyLowStock();
            }
            return $productId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function purchaseStock(array $data, int $userId): int
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        $searchTerm = trim((string)($data['search_term'] ?? ''));
        $purchaseQuantity = max(0, (int)($data['purchase_quantity'] ?? 0));
        $costPrice = (float)($data['purchase_cost_price'] ?? 0);
        $purchaseDate = (($data['purchase_date'] ?? '') !== '') ? $data['purchase_date'] : date('Y-m-d H:i:s');
        $barcode = trim((string)($data['barcode'] ?? ''));
        $productName = trim((string)($data['product_name'] ?? ''));
        $brand = trim((string)($data['brand'] ?? ''));
        $categoryId = (($data['category_id'] ?? '') !== '') ? (int)$data['category_id'] : null;
        $sellingPrice = (float)($data['selling_price'] ?? 0);
        $reorderLevel = (int)($data['reorder_level'] ?? 10);
        $hasSupplierLeadTime = array_key_exists('supplier_lead_time_days', $data);
        $hasSafetyStock = array_key_exists('safety_stock', $data);
        $hasMinimumOrderQuantity = array_key_exists('minimum_order_quantity', $data);
        $hasUnitsPerPackage = array_key_exists('units_per_package', $data);
        $supplierLeadTimeDays = max(0, (int)($data['supplier_lead_time_days'] ?? 7));
        $safetyStock = max(0, (int)($data['safety_stock'] ?? 0));
        $minimumOrderQuantity = max(1, (int)($data['minimum_order_quantity'] ?? 1));
        $unitsPerPackage = max(1, (int)($data['units_per_package'] ?? 1));
        $baseUnit = trim((string)($data['base_unit'] ?? 'piece')) ?: 'piece';
        $receivingUnit = trim((string)($data['receiving_unit'] ?? 'package')) ?: 'package';
        $caseBarcode = trim((string)($data['case_barcode'] ?? ''));
        $expirationDate = (($data['expiration_date'] ?? '') !== '') ? $data['expiration_date'] : null;
        $batchNumber = trim((string)($data['batch_number'] ?? ''));
        $productImage = trim((string)($data['product_image'] ?? ''));
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $supplier = trim((string)($data['supplier'] ?? ''));

        if ($purchaseQuantity <= 0) {
            throw new RuntimeException('Purchase quantity must be greater than zero.');
        }

        $this->fiscalPeriodGuard->assertOpenForDate($purchaseDate, 'purchase_history', 'purchase history');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

        $existingProduct = $this->findProductBySearchTerm($searchTerm);

        StoreWriteGate::begin($this->pdo);
        try {
            if ($existingProduct) {
                $previousProduct = $existingProduct;
                $updateParts = ['quantity_purchased = quantity_purchased + ?', 'cost_price = ?'];
                $params = [$purchaseQuantity, $costPrice];

                if ($brand !== '') {
                    $updateParts[] = 'brand = ?';
                    $params[] = $brand;
                }
                if ($categoryId !== null) {
                    $updateParts[] = 'category_id = ?';
                    $params[] = $categoryId;
                }
                if ($sellingPrice > 0) {
                    $updateParts[] = 'unit_price = ?';
                    $params[] = $sellingPrice;
                    

                }
                if ($reorderLevel > 0) {
                    $updateParts[] = 'reorder_level = ?';
                    $params[] = $reorderLevel;
                }
                if ($supplier !== '') {
                    $updateParts[] = 'supplier = ?';
                    $params[] = $supplier;
                    $updateParts[] = 'preferred_supplier = ?';
                    $params[] = $supplier;
                }
                if ($hasSupplierLeadTime) {
                    $updateParts[] = 'supplier_lead_time_days = ?';
                    $params[] = $supplierLeadTimeDays;
                }
                if ($hasSafetyStock) {
                    $updateParts[] = 'safety_stock = ?';
                    $params[] = $safetyStock;
                }
                if ($hasMinimumOrderQuantity) {
                    $updateParts[] = 'minimum_order_quantity = ?';
                    $params[] = $minimumOrderQuantity;
                }
                if ($hasUnitsPerPackage) {
                    $updateParts[] = 'units_per_package = ?';
                    $params[] = $unitsPerPackage;
                }
                if ($expirationDate !== null) {
                    $updateParts[] = 'expiration_date = ?';
                    $params[] = $expirationDate;
                }
                if ($productImage !== '') {
                    $updateParts[] = 'product_image = ?';
                    $params[] = $productImage;
                }
                $updateParts[] = 'status = ?';
                $params[] = $status;
                $updateParts[] = 'sku = ?';
                $params[] = $existingProduct['sku'];
                $updateParts[] = 'barcode = ?';
                $params[] = $existingProduct['barcode'] ?: $existingProduct['sku'];

                $params[] = $existingProduct['product_id'];
                $this->pdo->prepare('UPDATE products SET ' . implode(', ', $updateParts) . ' WHERE product_id = ?')
                    ->execute($params);

                $this->pdo->prepare(
                    "UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?"
                )->execute([$purchaseQuantity, $existingProduct['product_id']]);

                $totalCost = $costPrice * $purchaseQuantity;
                $this->createProductBatch(
                    (int)$existingProduct['product_id'],
                    $batchNumber !== '' ? $batchNumber : null,
                    $purchaseQuantity,
                    $expirationDate,
                    $purchaseDate,
                    $supplier !== '' ? $supplier : null
                );
                $this->pdo->prepare(
                    "INSERT INTO purchase_history (product_id, quantity_purchased, cost_price, purchase_date, total_cost, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$existingProduct['product_id'], $purchaseQuantity, $costPrice, $purchaseDate, $totalCost, $userId]);

                $this->pdo->prepare(
                    "INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, 'purchase', ?)"
                )->execute([$existingProduct['product_id'], $purchaseQuantity, $userId]);
                $productId = (int)$existingProduct['product_id'];

                $afterStmt = $this->pdo->prepare(
                    "SELECT p.*, i.quantity_on_hand
                     FROM products p
                     JOIN inventory i ON p.product_id = i.product_id
                     WHERE p.product_id = ?"
                );
                $afterStmt->execute([$productId]);
                log_activity(
                    $this->pdo,
                    $userId,
                    'Product modification',
                    'Products',
                    $productId,
                    $previousProduct,
                    $afterStmt->fetch()
                );
            } else {
                if ($productName === '') {
                    throw new RuntimeException('Product name is required for a new product.');
                }

                $productId = $this->createProduct([
                    'barcode' => $barcode,
                    'product_name' => $productName,
                    'brand' => $brand,
                    'category_id' => $categoryId,
                    'cost_price' => $costPrice,
                    'selling_price' => $sellingPrice,
                    'reorder_level' => $reorderLevel,
                    'preferred_supplier' => $supplier,
                    'supplier_lead_time_days' => $supplierLeadTimeDays,
                    'safety_stock' => $safetyStock,
                    'minimum_order_quantity' => $minimumOrderQuantity,
                    'units_per_package' => $unitsPerPackage,
                    'expiration_date' => $expirationDate,
                    'batch_number' => $batchNumber,
                    'product_image' => $productImage,
                    'status' => $status,
                    'initial_stock_quantity' => $purchaseQuantity,
                ], $userId);
            }

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
            return $productId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function adjustStock(array $data, int $userId): void
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        $qtyChange = (int)($data['qty_change'] ?? 0);
        $productId = (int)($data['product_id'] ?? 0);
        $adjustmentReason = trim((string)($data['adjustment_reason'] ?? 'adjustment'));

        if ($productId <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }
        if ($qtyChange === 0) {
            throw new RuntimeException('Quantity change cannot be zero.');
        }
        $roleStmt = $this->pdo->prepare(
            "SELECT 1
             FROM users u
             JOIN roles primary_role ON primary_role.role_id = u.role_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id
             LEFT JOIN roles assigned_role ON assigned_role.role_id = ur.role_id
             WHERE u.user_id = ?
               AND (primary_role.role_name = 'inventory_manager' OR assigned_role.role_name = 'inventory_manager')
             LIMIT 1"
        );
        $roleStmt->execute([$userId]);
        if ($roleStmt->fetchColumn() === false) {
            throw new RuntimeException('Direct stock adjustments require Inventory Manager access.');
        }
        if (mb_strlen($adjustmentReason) < 8) {
            throw new RuntimeException('Enter a specific adjustment reason of at least 8 characters.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

        StoreWriteGate::begin($this->pdo);
        try {
            [$scopeSql, $scopeParams] = $this->storeProductScope();
            $beforeStmt = $this->pdo->prepare(
                "SELECT p.product_id, p.sku, p.product_name, i.quantity_on_hand
                 FROM products p
                 JOIN inventory i ON i.product_id = p.product_id
                  WHERE p.product_id = ?{$scopeSql}
                 FOR UPDATE"
            );
              $beforeStmt->execute(array_merge([$productId], $scopeParams));
            $before = $beforeStmt->fetch();
            if (!$before) {
                throw new RuntimeException('Inventory record not found for selected product.');
            }

            $stmt = $this->pdo->prepare(
                "UPDATE inventory SET quantity_on_hand = quantity_on_hand + ?
                 WHERE product_id = ? AND quantity_on_hand + ? >= 0"
            );
            $stmt->execute([$qtyChange, $productId, $qtyChange]);

            if ($stmt->rowCount() === 0) {
                throw new RuntimeException('Adjustment would take stock below zero.');
            }

            $this->pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason, moved_by) VALUES (?, ?, ?, ?)")
                ->execute([$productId, $qtyChange, $adjustmentReason, $userId]);

            $after = $before;
            $after['quantity_on_hand'] = (int)$before['quantity_on_hand'] + $qtyChange;
            $after['adjustment_qty'] = $qtyChange;
            $after['reason'] = $adjustmentReason;
            log_activity(
                $this->pdo,
                $userId,
                'Stock adjustment applied',
                'Inventory',
                $productId,
                $before,
                $after
            );

            $this->pdo->commit();
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function assignGeneratedBarcode(int $productId, int $userId): string
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        if ($productId <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }

        StoreWriteGate::begin($this->pdo);
        try {
            [$scopeSql, $scopeParams] = $this->storeProductScope();
            $stmt = $this->pdo->prepare(
                "SELECT product_id, sku, barcode, product_name FROM products WHERE product_id = ?{$scopeSql} FOR UPDATE"
            );
            $stmt->execute(array_merge([$productId], $scopeParams));
            $product = $stmt->fetch();
            if (!$product) {
                throw new RuntimeException('Product not found.');
            }

            $currentBarcode = trim((string)($product['barcode'] ?? ''));
            if ($currentBarcode !== '') {
                $this->pdo->commit();
                return $currentBarcode;
            }

            $barcode = $this->generateUniqueInternalBarcode();
            $sku = trim((string)($product['sku'] ?? ''));
            if ($sku === '') {
                $sku = $barcode;
            }

            $this->pdo->prepare('UPDATE products SET barcode = ?, sku = ? WHERE product_id = ?')
                ->execute([$barcode, $sku, $productId]);

            log_activity(
                $this->pdo,
                $userId,
                'Generated internal barcode',
                'Products',
                $productId,
                ['barcode' => $product['barcode'] ?? null, 'sku' => $product['sku'] ?? null],
                ['barcode' => $barcode, 'sku' => $sku]
            );

            $this->pdo->commit();
            return $barcode;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function getProductForBarcodePrinting(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.brand, p.unit_price,
                    p.status, c.category_name
             FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
               WHERE p.product_id = ?{$scopeSql}
             LIMIT 1"
        );
           $stmt->execute(array_merge([$productId], $scopeParams));
        return $stmt->fetch() ?: null;
    }

    private function assertBarcodeAvailable(string $barcode): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT product_id FROM products WHERE barcode = ? OR sku = ? OR case_barcode = ? LIMIT 1'
        );
        $stmt->execute([$barcode, $barcode, $barcode]);
        if ($stmt->fetch()) {
            throw new RuntimeException('That barcode is already assigned to another product.');
        }
    }

    public function generateUniqueInternalBarcode(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $barcode = 'RM' . date('ymd') . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $stmt = $this->pdo->prepare(
                'SELECT product_id FROM products WHERE barcode = ? OR sku = ? OR case_barcode = ? LIMIT 1'
            );
            $stmt->execute([$barcode, $barcode, $barcode]);
            if (!$stmt->fetch()) {
                return $barcode;
            }
        }

        throw new RuntimeException('Unable to generate a unique barcode. Please try again.');
    }

    public function getProductsForManagement(): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT p.*, i.quantity_on_hand, c.category_name
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             LEFT JOIN categories c ON p.category_id = c.category_id
             WHERE 1 = 1{$scopeSql}
             ORDER BY p.product_name"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function createCategory(array $data, int $userId): int
    {
        $categoryName = trim((string)($data['category_name'] ?? ''));

        if ($categoryName === '') {
            throw new RuntimeException('Category name is required.');
        }

        $existing = $this->pdo->prepare('SELECT category_id FROM categories WHERE category_name = ?');
        $existing->execute([$categoryName]);

        if ($existing->fetch()) {
            throw new RuntimeException('Category already exists.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO categories (category_name) VALUES (?)');
        $stmt->execute([$categoryName]);

        return (int)$this->pdo->lastInsertId();
    }

    public function getCategories(): array
    {
        return $this->pdo->query("SELECT * FROM categories")->fetchAll();
    }

    public function getActiveProducts(): array
    {
        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.barcode, p.product_name, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             WHERE p.status = 'active'{$scopeSql}
             ORDER BY p.product_name"
        );
        $stmt->execute($scopeParams);
        return $stmt->fetchAll();
    }

    public function updateProductCatalogDetails(int $productId, array $data, int $userId): array
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }

        $role = function_exists('current_role') ? current_role() : ($_SESSION['role'] ?? null);
        if ($role !== 'inventory_manager') {
            throw new RuntimeException('Only the active Inventory Manager workspace can edit product catalog details.');
        }

        if ($productId <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }

        [$scopeSql, $scopeParams] = $this->storeProductScope();
        $stmt = $this->pdo->prepare("SELECT p.* FROM products p WHERE p.product_id = ?{$scopeSql}");
        $stmt->execute(array_merge([$productId], $scopeParams));
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new RuntimeException('Product not found.');
        }

        $productName = trim((string)($data['product_name'] ?? ''));
        if ($productName === '') {
            throw new RuntimeException('Product name is required.');
        }

        $hasParent = !empty($existing['parent_product_id']) && (int)$existing['parent_product_id'] > 0;
        if ($hasParent) {
            $variantLabel = trim((string)($data['variant_label'] ?? ''));
            if ($variantLabel === '') {
                throw new RuntimeException('Variant label is required for variant products.');
            }
            if (mb_strlen($variantLabel) > 100) {
                throw new RuntimeException('Variant label must not exceed 100 characters.');
            }
        } else {
            $variantLabel = $existing['variant_label'];
        }

        $brand = trim((string)($data['brand'] ?? ''));
        $brand = $brand !== '' ? $brand : null;

        $categoryId = (($data['category_id'] ?? '') !== '') ? (int)$data['category_id'] : null;
        if ($categoryId !== null && $categoryId > 0) {
            $catStmt = $this->pdo->prepare("SELECT category_id FROM categories WHERE category_id = ?");
            $catStmt->execute([$categoryId]);
            if (!$catStmt->fetch()) {
                throw new RuntimeException('Selected category was not found.');
            }
        } else {
            $categoryId = null;
        }

        $productImage = array_key_exists('product_image', $data) && $data['product_image'] !== null && $data['product_image'] !== ''
            ? trim((string)$data['product_image'])
            : $existing['product_image'];

        $hasSellingPrice = array_key_exists('selling_price', $data);
        $costPrice = (float)($existing['cost_price'] ?? 0);
        $warning = null;

        if ($hasSellingPrice) {
            $rawPrice = $data['selling_price'];
            $trimmedPrice = is_string($rawPrice) ? trim($rawPrice) : $rawPrice;
            if ($trimmedPrice === null || $trimmedPrice === '' || !is_numeric($trimmedPrice)) {
                throw new RuntimeException('Selling price must be a valid number greater than 0.');
            }
            $sellingPrice = round((float)$trimmedPrice, 2);
            if (!is_finite($sellingPrice) || $sellingPrice <= 0) {
                throw new RuntimeException('Selling price must be greater than PHP 0.');
            }
            if ($sellingPrice > 999999999.99) {
                throw new RuntimeException('Selling price is out of range.');
            }

            if ($costPrice > 0 && $sellingPrice < $costPrice) {
                $warning = sprintf('Warning: Selling price (₱%s) is below cost price (₱%s).', number_format($sellingPrice, 2), number_format($costPrice, 2));
            }
        } else {
            $sellingPrice = (float)$existing['unit_price'];
        }

        $hasStatus = array_key_exists('status', $data);
        if ($hasStatus) {
            $rawStatus = is_string($data['status']) ? trim($data['status']) : $data['status'];
            if ($rawStatus !== 'active' && $rawStatus !== 'inactive') {
                throw new RuntimeException("Status must be strictly 'active' or 'inactive'.");
            }
            $status = $rawStatus;
        } else {
            $status = $existing['status'] ?? 'active';
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            StoreWriteGate::begin($this->pdo);
        }
        try {
            $updateStmt = $this->pdo->prepare(
                "UPDATE products
                 SET product_name = ?,
                     variant_label = ?,
                     brand = ?,
                     category_id = ?,
                     product_image = ?,
                     unit_price = ?,
                     status = ?
                 WHERE product_id = ?"
            );
            $updateStmt->execute([
                $productName,
                $variantLabel,
                $brand,
                $categoryId,
                $productImage,
                $sellingPrice,
                $status,
                $productId,
            ]);

            $fetchStmt = $this->pdo->prepare(
                "SELECT p.*, c.category_name, i.quantity_on_hand
                 FROM products p
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 LEFT JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.product_id = ?"
            );
            $fetchStmt->execute([$productId]);
            $updated = $fetchStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($updated) {
                $updated['unit_price'] = (float)$updated['unit_price'];
                $updated['selling_price'] = (float)$updated['unit_price'];
                if ($warning !== null) {
                    $updated['warning'] = $warning;
                }
            }

            if (function_exists('log_activity')) {
                log_activity(
                    $this->pdo,
                    $userId,
                    'Product catalog details updated',
                    'Products',
                    $productId,
                    $existing,
                    $updated
                );
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $updated;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


    private function storeProductScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return (new App\Store\StoreScope($this->pdo))->productScope('p');
    }

    private function createProductBatch(
        int $productId,
        ?string $batchNumber,
        int $quantity,
        ?string $expirationDate,
        string $dateReceived,
        ?string $supplier
    ): void {
        $this->pdo->prepare(
            "INSERT INTO product_batches
                (product_id, batch_number, quantity, remaining_quantity, expiration_date, date_received, supplier)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $productId,
            $batchNumber,
            $quantity,
            $quantity,
            $expirationDate,
            $dateReceived,
            $supplier,
        ]);
    }
}
