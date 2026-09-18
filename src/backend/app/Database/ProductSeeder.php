<?php

namespace App\Database;

use App\Store\StoreScope;
use PDO;
use RuntimeException;
use Throwable;

final class ProductSeeder
{
    public static function assertSafeTarget(string $environment, array $config, string $confirmation): void
    {
        if (!in_array($environment, ['local', 'development'], true)
            || ($config['driver'] ?? '') !== 'mysql'
            || !in_array($config['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true)
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $config['database'] ?? '')
            || !preg_match('/^[0-9]{1,5}$/D', (string)($config['port'] ?? ''))
            || !preg_match('/^[a-zA-Z0-9_]+$/D', $config['charset'] ?? '')
            || $confirmation !== $config['database']) {
            throw new RuntimeException('Seed refused: require explicit local/development APP_ENV, loopback MySQL, and --confirm-local-database matching the database name.');
        }
    }

    public static function loadProducts(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Cannot read product seed CSV.');
        }
        try {
            $expected = explode(',', 'sku,barcode,product_name,brand,category_name,unit_price,cost_price,reorder_level,preferred_supplier,supplier_lead_time_days,safety_stock,minimum_order_quantity,units_per_package');
            if (fgetcsv($handle, 0, ',', '"', '') !== $expected) {
                throw new RuntimeException('Unexpected product seed CSV header.');
            }
            $products = [];
            $identifiers = [];
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($expected)) {
                    throw new RuntimeException('Invalid product seed CSV row.');
                }
                $row = array_combine($expected, array_map('trim', $values));
                foreach (['sku' => 50, 'barcode' => 50, 'product_name' => 150, 'brand' => 100, 'category_name' => 100, 'preferred_supplier' => 150] as $field => $limit) {
                    if ($row[$field] === '' || strlen($row[$field]) > $limit) {
                        throw new RuntimeException('Invalid seed field: ' . $field);
                    }
                }
                foreach (['sku', 'barcode'] as $field) {
                    if (isset($identifiers[$row[$field]])) {
                        throw new RuntimeException('Duplicate seed identifier.');
                    }
                    $identifiers[$row[$field]] = true;
                }
                foreach (['unit_price', 'cost_price'] as $field) {
                    if (!preg_match('/^[0-9]{1,8}(\.[0-9]{1,2})?$/D', $row[$field])) {
                        throw new RuntimeException('Invalid seed price.');
                    }
                }
                foreach (['reorder_level', 'supplier_lead_time_days', 'safety_stock', 'minimum_order_quantity', 'units_per_package'] as $field) {
                    $minimum = in_array($field, ['minimum_order_quantity', 'units_per_package'], true) ? 1 : 0;
                    if (filter_var($row[$field], FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => 2147483647]]) === false) {
                        throw new RuntimeException('Invalid seed quantity.');
                    }
                }
                $products[] = $row;
            }
            if ($products === []) {
                throw new RuntimeException('Product seed CSV is empty.');
            }
            return $products;
        } finally {
            fclose($handle);
        }
    }

    public static function openingStock(array $row): int
    {
        $number = (int)substr($row['barcode'], -3);
        return match ($number % 5) {
            0 => 0,
            1 => max(1, intdiv((int)$row['reorder_level'], 2)),
            2 => (int)$row['reorder_level'] + (int)$row['units_per_package'],
            3 => (int)$row['reorder_level'] + 3 * (int)$row['units_per_package'],
            4 => (int)$row['reorder_level'] + 6 * (int)$row['units_per_package'],
        };
    }

    private static function seedOpeningStock(PDO $pdo, int $productId, array $row): string
    {
        $quantity = self::openingStock($row);
        if ($quantity === 0) {
            return 'no_stock_requested';
        }
        $stmt = $pdo->prepare('SELECT p.quantity_purchased, p.quantity_sold, p.cost_price, p.status, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id WHERE p.product_id = ? FOR UPDATE');
        $stmt->execute([$productId]);
        $current = $stmt->fetch();
        if (!$current) {
            throw new RuntimeException('Missing inventory for opening stock.');
        }
        // Any history or existing stock means this is no longer an untouched fixture.
        // Never replenish sold-out products or overwrite user-entered quantities.
        foreach (['product_batches', 'purchase_history', 'stock_movements'] as $table) {
            $history = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE product_id = ?");
            $history->execute([$productId]);
            if ((int)$history->fetchColumn() > 0) {
                return 'preserved';
            }
        }
        if ((int)$current['quantity_purchased'] !== 0 || (int)$current['quantity_sold'] !== 0
            || (int)$current['quantity_on_hand'] !== 0 || $current['status'] !== 'active') {
            return 'preserved';
        }
        require_once __DIR__ . '/../Services/FiscalPeriodGuardService.php';
        $guard = new \FiscalPeriodGuardService($pdo);
        $guard->assertOpenNow('purchase_history', 'sample opening stock');
        $guard->assertOpenNow('stock_movements', 'sample opening stock');
        $batch = 'RM-SEED-OPENING-V1-' . $row['sku'];
        $pdo->prepare('INSERT INTO product_batches (product_id, batch_number, quantity, remaining_quantity, supplier) VALUES (?, ?, ?, ?, ?)')
            ->execute([$productId, $batch, $quantity, $quantity, 'RetailMind development seed']);
        $pdo->prepare('INSERT INTO purchase_history (product_id, supplier, quantity_purchased, cost_price, total_cost) VALUES (?, ?, ?, ?, ? * ?)')
            ->execute([$productId, 'RetailMind development seed', $quantity, $current['cost_price'], $current['cost_price'], $quantity]);
        $pdo->prepare("INSERT INTO stock_movements (product_id, change_qty, reason) VALUES (?, ?, 'purchase')")
            ->execute([$productId, $quantity]);
        $pdo->prepare('UPDATE products SET quantity_purchased = quantity_purchased + ? WHERE product_id = ?')
            ->execute([$quantity, $productId]);
        $pdo->prepare('UPDATE inventory SET quantity_on_hand = quantity_on_hand + ? WHERE product_id = ?')
            ->execute([$quantity, $productId]);
        return 'stocked';
    }

    public static function run(PDO $pdo, array $products, bool $withStock = false): array
    {
        // Serialize seed runs; categories do not have a unique name constraint.
        $lock = $pdo->query("SELECT GET_LOCK('retailmind_product_seed', 10)")->fetchColumn();
        if ((int)$lock !== 1) {
            throw new RuntimeException('Could not acquire product seed lock.');
        }
        try {
            $engines = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('branches','categories','products','inventory')")->fetchAll(PDO::FETCH_KEY_PAIR);
            if (count($engines) !== 4 || count(array_filter($engines, static fn ($engine) => strtoupper((string)$engine) !== 'INNODB')) > 0) {
                throw new RuntimeException('Seed requires existing InnoDB branches, categories, products and inventory tables. No migrations were run.');
            }
            $pdo->beginTransaction();
            if ($withStock) {
                $stockEngines = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('product_batches','purchase_history','stock_movements')")->fetchAll(PDO::FETCH_KEY_PAIR);
                if (count($stockEngines) !== 3 || count(array_filter($stockEngines, static fn ($engine) => strtoupper((string)$engine) !== 'INNODB')) > 0) {
                    throw new RuntimeException('Opening stock requires existing InnoDB batch, purchase and stock movement tables.');
                }
            }
            $storeId = (new StoreScope($pdo))->migrate();
            $find = $pdo->prepare('SELECT product_id, sku, barcode FROM products WHERE branch_id = ? AND (sku IN (?, ?) OR barcode IN (?, ?) OR case_barcode IN (?, ?)) FOR UPDATE');
            $category = $pdo->prepare('SELECT category_id FROM categories WHERE category_name = ? ORDER BY category_id LIMIT 1');
            $addCategory = $pdo->prepare('INSERT INTO categories (category_name) VALUES (?)');
            $insert = $pdo->prepare('INSERT INTO products (sku, barcode, product_name, brand, category_id, unit_price, cost_price, reorder_level, supplier, preferred_supplier, supplier_lead_time_days, safety_stock, minimum_order_quantity, units_per_package, branch_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $inventory = $pdo->prepare('INSERT INTO inventory (product_id, quantity_on_hand) VALUES (?, 0)');
            $verify = $pdo->prepare('SELECT p.product_id, p.sku, p.barcode, p.product_name, p.status, p.reorder_level, i.quantity_on_hand FROM products p JOIN inventory i ON i.product_id = p.product_id JOIN categories c ON c.category_id = p.category_id WHERE p.product_id = ? AND p.branch_id = ?');
            $result = ['inserted' => 0, 'skipped' => 0, 'records' => []];
            foreach ($products as $row) {
                $find->execute([$storeId, $row['sku'], $row['barcode'], $row['sku'], $row['barcode'], $row['sku'], $row['barcode']]);
                $matches = $find->fetchAll();
                if ($matches !== []) {
                    if (count($matches) !== 1 || $matches[0]['sku'] !== $row['sku'] || $matches[0]['barcode'] !== $row['barcode']) {
                        throw new RuntimeException('Seed identifier collision; transaction rolled back.');
                    }
                    $productId = (int)$matches[0]['product_id'];
                    $result['skipped']++;
                } else {
                    $category->execute([$row['category_name']]);
                    $categoryId = $category->fetchColumn();
                    if ($categoryId === false) {
                        $addCategory->execute([$row['category_name']]);
                        $categoryId = $pdo->lastInsertId();
                    }
                    $insert->execute([$row['sku'], $row['barcode'], $row['product_name'], $row['brand'], $categoryId, $row['unit_price'], $row['cost_price'], $row['reorder_level'], $row['preferred_supplier'], $row['preferred_supplier'], $row['supplier_lead_time_days'], $row['safety_stock'], $row['minimum_order_quantity'], $row['units_per_package'], $storeId]);
                    $productId = (int)$pdo->lastInsertId();
                    $inventory->execute([$productId]);
                    $result['inserted']++;
                }
                $stockResult = $withStock ? self::seedOpeningStock($pdo, $productId, $row) : 'not_requested';
                $verify->execute([$productId, $storeId]);
                $record = $verify->fetch();
                if (!$record) {
                    throw new RuntimeException('Seed verification failed: missing category or inventory. No existing records repaired.');
                }
                $record['stock_seed'] = $stockResult;
                $result['records'][] = $record;
            }
            $pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('retailmind_product_seed')");
        }
    }
}