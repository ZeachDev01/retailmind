<?php

namespace App\Receipts;

require_once __DIR__ . '/../Services/PhilippineTime.php';

use App\Services\PhilippineTime;
use PDO;

final class ReceiptDetailsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function fetchSale(int $saleId, ?int $storeId = null): ?array
    {
        $columns = $this->saleColumns();
        $optional = static fn(string $column, string $fallback): string => isset($columns[$column])
            ? 's.' . $column
            : $fallback;

        // The shift owns the Register identity. Legacy receipts reconstruct the
        // current name; preserved receipts replace it with the recorded name.
        $statement = $this->pdo->prepare(
            'SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                    ' . $optional('cash_received', 'NULL') . ' AS cash_received,
                    ' . $optional('change_due', 'NULL') . ' AS change_due,
                    ' . $optional('payment_reference', 'NULL') . ' AS payment_reference,
                    ' . $optional('discount_amount', '0.00') . ' AS discount_amount,
                    ' . $optional('promotion_name', 'NULL') . ' AS promotion_name,
                    ' . $optional('discount_reason', 'NULL') . ' AS discount_reason,
                    u.full_name AS cashier_name, r.name AS register_name
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             LEFT JOIN cashier_shifts cs ON cs.shift_id = s.shift_id
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE s.sale_id = ?' . ($storeId === null ? '' : ' AND (
                 u.branch_id = ? OR EXISTS (
                     SELECT 1 FROM sale_items scope_si
                     JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                     WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
                 )
             )')
        );
        $statement->execute($storeId === null ? [$saleId] : [$saleId, $storeId, $storeId]);
        $sale = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($sale === null) {
            return null;
        }
        $snapshot = $this->snapshot($saleId);
        if ($snapshot !== null) {
            // Authorization above always runs against the live sale before any
            // preserved customer-facing values are returned.
            $sale = array_replace($sale, $snapshot['sale']);
            $sale['receipt_store'] = $snapshot['store'];
            $sale['receipt_items'] = $snapshot['items'];
            $sale['verification_code'] = $snapshot['verification_code'];
            $sale['verification_url'] = $snapshot['verification_url'];
        } else {
            $sale['receipt_historical_notice'] = true;
        }
        return $sale;
    }

    public function preserveSale(int $saleId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new \RuntimeException('Sale receipt details require the checkout transaction.');
        }
        if (!$this->hasSnapshotTable()) {
            throw new \RuntimeException('Sale receipt storage is unavailable.');
        }
        $sale = $this->fetchSale($saleId);
        if ($sale === null) {
            throw new \RuntimeException('Sale receipt could not be preserved.');
        }
        $items = $this->fetchLiveItems($saleId);
        $store = $this->storeInfo();
        $customerSale = array_intersect_key($sale, array_flip([
            'sale_id', 'sale_date', 'cashier_name', 'register_name', 'total_amount',
            'payment_method', 'cash_received', 'change_due', 'payment_reference',
            'discount_amount', 'promotion_name', 'discount_reason',
        ]));
        $payload = [
            'store' => $store,
            'sale' => $customerSale,
            'items' => $items,
            'verification_code' => self::verificationCode($sale),
            'verification_url' => 'components/invoice/sales.php?tab=transactions&sale_id=' . $saleId,
        ];
        $statement = $this->pdo->prepare('INSERT INTO sale_receipt_details (sale_id, details_json) VALUES (?, ?)');
        $statement->execute([$saleId, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function storeInfo(): array
    {
        $settings = $this->storeSettings();
        $contact = array_filter([$settings['store_phone'] ?? '', $settings['store_email'] ?? '']);
        return [
            'name' => $settings['store_name'] ?: 'Shalom Store',
            'tagline' => 'Official sales receipt',
            'address' => $settings['store_address'] ?: 'Address not configured',
            'contact' => $contact ? implode(' / ', $contact) : 'Contact not configured',
            'tin' => $settings['business_identifier'] ?: 'Business ID not configured',
            'currency_symbol' => $settings['currency_symbol'] ?: '₱',
            'footer' => $settings['receipt_footer'] ?: 'Thank you for shopping with us.',
        ];
    }

    public function fetchItems(int $saleId): array
    {
        $snapshot = $this->snapshot($saleId);
        return $snapshot === null ? $this->fetchLiveItems($saleId) : $snapshot['items'];
    }

    public static function verificationCode(array $sale): string
    {
        $raw = implode('|', [$sale['sale_id'] ?? '', $sale['sale_date'] ?? '',
            number_format((float)($sale['total_amount'] ?? 0), 2, '.', '')]);
        return implode('-', str_split(substr(strtoupper(hash('sha256', $raw)), 0, 12), 4));
    }

    private function fetchLiveItems(int $saleId): array
    {
        $statement = $this->pdo->prepare('SELECT si.quantity, si.unit_price, si.subtotal, p.sku, p.product_name
            FROM sale_items si JOIN products p ON p.product_id = si.product_id
            WHERE si.sale_id = ? ORDER BY si.sale_item_id');
        $statement->execute([$saleId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function snapshot(int $saleId): ?array
    {
        if (!$this->hasSnapshotTable()) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT details_json FROM sale_receipt_details WHERE sale_id = ?');
        $statement->execute([$saleId]);
        $json = $statement->fetchColumn();
        return $json === false ? null : json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function hasSnapshotTable(): bool
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return (bool)$this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sale_receipt_details'")->fetchColumn();
        }
        return (bool)$this->pdo->query("SHOW TABLES LIKE 'sale_receipt_details'")->fetchColumn();
    }

    private function storeSettings(): array
    {
        $settings = [
            'store_name' => 'Shalom Store', 'store_address' => 'Tangub City',
            'store_phone' => '', 'store_email' => '', 'business_identifier' => '',
            'receipt_footer' => 'Thank you for shopping with us.', 'currency_symbol' => '₱',
        ];
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $exists = $driver === 'sqlite'
            ? (bool)$this->pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'store_settings'")->fetchColumn()
            : (bool)$this->pdo->query("SHOW TABLES LIKE 'store_settings'")->fetchColumn();
        if ($exists) {
            foreach ($this->pdo->query('SELECT setting_key, setting_value FROM store_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        return $settings;
    }

    public static function renderMetadata(array $sale): string
    {
        $cashier = trim((string)($sale['cashier_name'] ?? ''));
        $register = trim((string)($sale['register_name'] ?? ''));
        $cashier = $cashier !== '' ? $cashier : 'Legacy / Unassigned';
        $register = $register !== '' ? $register : 'Legacy / Unassigned';
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="receipt-meta">'
            . '<div><strong>Transaction #' . (int)$sale['sale_id'] . '</strong></div>'
            . '<div>Receipt #' . (int)$sale['sale_id'] . '</div>'
            . '<div>Date/Time: ' . $escape(PhilippineTime::format($sale['sale_date'])) . ' Philippine time' . '</div>'
            . '<div>Cashier: ' . $escape($cashier) . '</div>'
            . '<div>Register: ' . $escape($register) . '</div>'
            . '</div>';
    }

    private function saleColumns(): array
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = $driver === 'sqlite'
            ? $this->pdo->query('PRAGMA table_info(sales)')->fetchAll(PDO::FETCH_ASSOC)
            : $this->pdo->query('SHOW COLUMNS FROM sales')->fetchAll(PDO::FETCH_ASSOC);
        $columns = [];
        foreach ($rows as $row) {
            $columns[$driver === 'sqlite' ? $row['name'] : $row['Field']] = true;
        }
        return $columns;
    }
}
