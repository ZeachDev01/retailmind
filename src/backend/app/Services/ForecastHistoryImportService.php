<?php

namespace App\Services;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class ForecastHistoryImportService
{
    public function __construct(private PDO $pdo, private int $storeId)
    {
    }

    public function products(): array
    {
        $stmt = $this->pdo->prepare("SELECT product_id, sku, product_name FROM products WHERE branch_id = ? AND status = 'active'");
        $stmt->execute([$this->storeId]);
        $products = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $product) {
            $products[$product['sku']] = $product;
        }
        return $products;
    }

    public static function parse($stream, array $products, string $today): array
    {
        $header = fgetcsv($stream, 0, ',', '"', '');
        if ($header) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        if ($header !== ['sale_date', 'sku', 'quantity']) {
            throw new RuntimeException('Use the CSV columns sale_date,sku,quantity in that order.');
        }
        $rows = [];
        $seen = [];
        $line = 1;
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $line++;
            if ($row === [null]) continue;
            if (count($row) !== 3) throw new RuntimeException("Row {$line}: expected exactly three columns.");
            [$date, $sku, $quantity] = array_map('trim', $row);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || $date < '1900-01-01' || $date >= $today) {
                throw new RuntimeException("Row {$line}: use a valid past date in YYYY-MM-DD format.");
            }
            if (!isset($products[$sku])) throw new RuntimeException("Row {$line}: SKU {$sku} is not an active product in this store.");
            if (!preg_match('/^(0|[1-9][0-9]{0,6})$/D', $quantity) || (int)$quantity > 1000000) {
                throw new RuntimeException("Row {$line}: quantity must be a whole number from 0 to 1000000.");
            }
            $key = $products[$sku]['product_id'] . ':' . $date;
            if (isset($seen[$key])) throw new RuntimeException("Row {$line}: duplicate product and date. Use one daily total per product.");
            $seen[$key] = true;
            $rows[] = ['sale_date' => $date, 'sku' => $sku, 'quantity' => (int)$quantity,
                'product_id' => (int)$products[$sku]['product_id'], 'product_name' => $products[$sku]['product_name']];
            if (count($rows) > 10000) throw new RuntimeException('Import at most 10000 rows at a time.');
        }
        if (!$rows) throw new RuntimeException('The CSV contains no sales history rows.');
        return $rows;
    }

    public function import(array $rows, int $userId): array
    {
        $result = ['inserted' => 0, 'skipped' => 0];
        $this->pdo->beginTransaction();
        try {
            $product = $this->pdo->prepare("SELECT product_id FROM products WHERE product_id = ? AND sku = ? AND branch_id = ? AND status = 'active' FOR UPDATE");
            $pos = $this->pdo->prepare('SELECT COUNT(*) FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id WHERE si.product_id = ? AND DATE(s.sale_date) = ?');
            $insert = $this->pdo->prepare('INSERT INTO forecast_sales_imports (product_id, sale_date, quantity, imported_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_id = VALUES(product_id)');
            $existing = $this->pdo->prepare('SELECT quantity FROM forecast_sales_imports WHERE product_id = ? AND sale_date = ?');
            foreach ($rows as $row) {
                $product->execute([$row['product_id'], $row['sku'], $this->storeId]);
                if (!$product->fetchColumn()) throw new RuntimeException('A product changed after preview. Upload the CSV again.');
                if ($row['sale_date'] >= date('Y-m-d')) throw new RuntimeException('Import only completed past days.');
                $pos->execute([$row['product_id'], $row['sale_date']]);
                if ((int)$pos->fetchColumn() > 0) {
                    $result['skipped']++;
                    continue;
                }
                $insert->execute([$row['product_id'], $row['sale_date'], $row['quantity'], $userId]);
                $inserted = $insert->rowCount() === 1;
                $existing->execute([$row['product_id'], $row['sale_date']]);
                if ((int)$existing->fetchColumn() !== $row['quantity']) {
                    throw new RuntimeException('Different history already exists for ' . $row['sku'] . ' on ' . $row['sale_date'] . '. No rows were imported.');
                }
                $result[$inserted ? 'inserted' : 'skipped']++;
            }
            \log_activity($this->pdo, $userId, 'Forecast history import', 'Demand Forecasting', null, null, $result);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
