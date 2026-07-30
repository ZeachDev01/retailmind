<?php
// app/Services/ProductService.php

require_once __DIR__ . '/NotificationService.php';
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

        $stmt = $this->pdo->prepare(
            "SELECT p.*, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             WHERE p.barcode = ? OR p.sku = ? OR p.product_name = ?
             LIMIT 1"
        );
        $stmt->execute([$term, $term, $term]);
        $product = $stmt->fetch();
        if ($product) {
            return $product;
        }

        $stmt = $this->pdo->prepare(
            "SELECT p.*, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             WHERE p.barcode LIKE ? OR p.product_name LIKE ?
             ORDER BY p.product_name
             LIMIT 1"
        );
        $likeTerm = '%' . $term . '%';
        $stmt->execute([$likeTerm, $likeTerm]);
        return $stmt->fetch() ?: null;
    }

    public function createProduct(array $data, int $userId): int
    {
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
        $expirationDate = (($data['expiration_date'] ?? '') !== '') ? $data['expiration_date'] : null;
        $batchNumber = trim((string)($data['batch_number'] ?? ''));
        $productImage = trim((string)($data['product_image'] ?? ''));
        $status = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $initialStock = max(0, (int)($data['initial_stock_quantity'] ?? 0));

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

        if ($initialStock > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('purchase_history', 'purchase history');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO products (
                    sku, barcode, product_name, brand, category_id, unit_price, cost_price,
                    quantity_purchased, quantity_sold, reorder_level, supplier, preferred_supplier,
                    supplier_lead_time_days, safety_stock, minimum_order_quantity, units_per_package, expiration_date,
                    product_image, status, created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $barcode,
                $barcode,
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
                $expirationDate,
                $productImage !== '' ? $productImage : null,
                $status,
                $userId,
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

        $this->pdo->beginTransaction();
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
        $qtyChange = (int)($data['qty_change'] ?? 0);
        $productId = (int)($data['product_id'] ?? 0);
        $adjustmentReason = trim((string)($data['adjustment_reason'] ?? 'adjustment'));

        if ($productId <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }

        $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');

        $this->pdo->beginTransaction();
        try {
            $beforeStmt = $this->pdo->prepare(
                "SELECT p.product_id, p.sku, p.product_name, i.quantity_on_hand
                 FROM products p
                 JOIN inventory i ON i.product_id = p.product_id
                 WHERE p.product_id = ?
                 FOR UPDATE"
            );
            $beforeStmt->execute([$productId]);
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
        if ($productId <= 0) {
            throw new RuntimeException('Invalid product selected.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT product_id, sku, barcode, product_name FROM products WHERE product_id = ? FOR UPDATE"
            );
            $stmt->execute([$productId]);
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

        $stmt = $this->pdo->prepare(
            "SELECT p.product_id, p.sku, p.barcode, p.product_name, p.brand, p.unit_price,
                    p.status, c.category_name
             FROM products p
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE p.product_id = ?
             LIMIT 1"
        );
        $stmt->execute([$productId]);
        return $stmt->fetch() ?: null;
    }

    private function assertBarcodeAvailable(string $barcode): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT product_id FROM products WHERE barcode = ? OR sku = ? LIMIT 1'
        );
        $stmt->execute([$barcode, $barcode]);
        if ($stmt->fetch()) {
            throw new RuntimeException('That barcode is already assigned to another product.');
        }
    }

    public function generateUniqueInternalBarcode(): string
    {
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $barcode = 'RM' . date('ymd') . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            $stmt = $this->pdo->prepare(
                'SELECT product_id FROM products WHERE barcode = ? OR sku = ? LIMIT 1'
            );
            $stmt->execute([$barcode, $barcode]);
            if (!$stmt->fetch()) {
                return $barcode;
            }
        }

        throw new RuntimeException('Unable to generate a unique barcode. Please try again.');
    }

    public function getProductsForManagement(): array
    {
        return $this->pdo->query(
            "SELECT p.*, i.quantity_on_hand, c.category_name
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             LEFT JOIN categories c ON p.category_id = c.category_id
             ORDER BY p.product_name"
        )->fetchAll();
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
        return $this->pdo->query(
            "SELECT p.product_id, p.sku, p.barcode, p.product_name, i.quantity_on_hand
             FROM products p
             JOIN inventory i ON p.product_id = i.product_id
             WHERE p.status = 'active'
             ORDER BY p.product_name"
        )->fetchAll();
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
