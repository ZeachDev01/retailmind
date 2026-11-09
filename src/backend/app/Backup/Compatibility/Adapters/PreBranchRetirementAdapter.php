<?php

namespace App\Backup\Compatibility\Adapters;

use App\Backup\Compatibility\RowTransform;
use App\Backup\Compatibility\SchemaAdapter;
use RuntimeException;

/**
 * Structures from before the single-store retirement (branches, stored JSON
 * carts/payment totals, atomic user names) plus the October storage retirement.
 */
final class PreBranchRetirementAdapter implements SchemaAdapter
{
    private const RETIRED_TABLES = [
        'branches', 'purchase_order_items', 'purchase_orders', 'replenishment_requests',
        'supplier_products', 'suppliers', 'user_privileges', 'role_privileges', 'privileges',
        'restore_preserved_activity', 'emergency_access_sessions',
    ];

    private const RETIRED_COLUMNS = [
        'users.full_name', 'users.branch_id', 'products.branch_id', 'store_calendar.branch_id',
        'held_sales.cart_json', 'cashier_shifts.payment_totals',
        'stock_receiving.replenishment_request_id', 'stock_receiving.purchase_order_id',
        'stock_receiving.purchase_order_item_id', 'stock_receiving.po_number',
        'forecast_decisions.replenishment_request_id', 'notification_preferences.notify_replenishment',
        'csv_import_logs.error_details', 'forecast_evaluations.evaluation_records',
        'forecast_evaluations.actual_total', 'forecast_evaluations.predicted_total',
        'model_training_runs.host_name', 'sale_reversals.exchange_details',
    ];

    public static function key(): string
    {
        return 'pre-202611010001';
    }

    public static function label(): string
    {
        return 'Older single-store structure (before branch retirement)';
    }

    public function supports(array $source, array $appliedMigrations): bool
    {
        foreach (self::RETIRED_TABLES as $table) {
            if (isset($source[$table])) return true;
        }
        foreach (self::RETIRED_COLUMNS as $column) {
            [$table, $name] = explode('.', $column, 2);
            if (isset($source[$table]) && in_array($name, $source[$table]['columns'], true)) return true;
        }
        return false;
    }

    public function declaredChanges(): array
    {
        return ['tables_removed' => self::RETIRED_TABLES, 'columns_removed' => self::RETIRED_COLUMNS,
            'changed' => [], 'enums' => []];
    }

    public function upgrade(array $rows, array $source, array $destination): array
    {
        $heldSaleItems = $this->heldSaleItems($rows, $source, $destination);
        $paymentTotals = $this->paymentTotals($rows, $source, $destination);

        $upgraded = [];
        foreach ($destination as $table => $definition) {
            $upgraded[$table] = [];
        }
        foreach ($source as $table => $definition) {
            if (!isset($destination[$table])) continue;
            $records = [];
            foreach ($rows[$table] ?? [] as $row) {
                if ($table === 'users') $row = RowTransform::rename($row, ['full_name' => 'display_name']);
                $records[] = RowTransform::keep($row, $destination[$table]['columns'], $this->defaults($table));
            }
            $upgraded[$table] = $records;
        }
        if (isset($destination['held_sale_items'])) $upgraded['held_sale_items'] = $heldSaleItems;
        if (isset($destination['cashier_shift_payment_totals'])) $upgraded['cashier_shift_payment_totals'] = $paymentTotals;
        return $upgraded;
    }

    private function defaults(string $table): array
    {
        return match ($table) {
            'users' => ['first_name' => null, 'last_name' => null, 'display_name' => ''],
            default => [],
        };
    }

    /** Mirrors 202611010004_normalize_held_sale_items, including row order. */
    private function heldSaleItems(array $rows, array $source, array $destination): array
    {
        if (!isset($source['held_sales']['columns']) || !in_array('cart_json', $source['held_sales']['columns'], true)) return [];
        $items = [];
        foreach ($rows['held_sales'] ?? [] as $sale) {
            $json = $sale['cart_json'] ?? null;
            if ($json === null || $json === '') continue;
            try {
                $cart = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Cannot normalize held sale ' . $sale['held_sale_id'] . ': its saved cart is invalid JSON.', 0, $exception);
            }
            if (!is_array($cart)) throw new RuntimeException('Cannot normalize held sale ' . $sale['held_sale_id'] . ': its saved cart is not a line list.');
            $position = 0;
            foreach ($cart as $key => $line) {
                if (!is_array($line) || !isset($line['product_id'], $line['selling_form'], $line['name'], $line['qty'], $line['price'])) {
                    throw new RuntimeException('Cannot normalize held sale ' . $sale['held_sale_id'] . ': a cart line is missing required values.');
                }
                $items[] = RowTransform::keep([
                    'held_sale_id' => (string)$sale['held_sale_id'],
                    'cart_key' => (string)$key,
                    'line_position' => (string)$position++,
                    'product_id' => (string)$line['product_id'],
                    'selling_form' => (string)$line['selling_form'],
                    'pack_size' => isset($line['pack_size']) ? (string)$line['pack_size'] : null,
                    'product_name' => (string)$line['name'],
                    'quantity' => (string)$line['qty'],
                    'price' => (string)$line['price'],
                    'server_price' => isset($line['server_price']) ? (string)$line['server_price'] : (string)$line['price'],
                    'stock' => isset($line['stock']) && is_numeric($line['stock']) ? (string)(int)$line['stock'] : null,
                    'reorder_level' => (string)(int)($line['reorder_level'] ?? 0),
                    'safety_stock' => (string)(int)($line['safety_stock'] ?? 0),
                    'sku' => isset($line['sku']) ? (string)$line['sku'] : null,
                    'barcode' => isset($line['barcode']) ? (string)$line['barcode'] : null,
                    'product_image' => isset($line['product_image']) ? (string)$line['product_image'] : null,
                ], $destination['held_sale_items']['columns'], ['held_sale_item_id' => null]);
            }
        }
        return $items;
    }

    /** Mirrors 202611010006_normalize_shift_payment_totals, including payment-type order. */
    private function paymentTotals(array $rows, array $source, array $destination): array
    {
        if (!isset($source['cashier_shifts']['columns']) || !in_array('payment_totals', $source['cashier_shifts']['columns'], true)) return [];
        $totals = [];
        foreach ($rows['cashier_shifts'] ?? [] as $shift) {
            $json = $shift['payment_totals'] ?? null;
            if ($json === null || $json === '') continue;
            try {
                $decoded = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.', 0, $exception);
            }
            if (!is_array($decoded) || array_diff(array_keys($decoded), ['cash', 'card', 'ewallet']) !== []) {
                throw new RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.');
            }
            foreach (['cash', 'card', 'ewallet'] as $type) {
                if (!array_key_exists($type, $decoded)) continue;
                $amount = $decoded[$type];
                if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0 || (float)$amount > 9999999999.99) {
                    throw new RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.');
                }
                $totals[] = RowTransform::keep([
                    'shift_id' => (string)$shift['shift_id'], 'payment_type' => $type,
                    'amount' => number_format((float)$amount, 2, '.', ''),
                ], $destination['cashier_shift_payment_totals']['columns']);
            }
        }
        return $totals;
    }
}
