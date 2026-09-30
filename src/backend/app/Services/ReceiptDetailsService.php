<?php

namespace App\Services;

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

        // The shift owns the Register identity. Resolving its current name makes
        // earlier receipts follow a rename without assigning legacy sales a till.
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
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
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
            . '<div>Date/Time: ' . $escape((string)$sale['sale_date']) . '</div>'
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
