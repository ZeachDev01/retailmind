<?php

namespace App\Receipts;

use App\Backup\RecordProvenance;
use App\Services\PhilippineTime;
use PDO;
use RuntimeException;

require_once __DIR__ . '/../Services/PhilippineTime.php';
require_once __DIR__ . '/../Services/SalesWorkflowService.php';
require_once __DIR__ . '/../Backup/RecordProvenance.php';
require_once __DIR__ . '/ReceiptLogo.php';

/**
 * Authoritative engine for Sale, Refund, and GCash receipt generation,
 * snapshot management, dynamic paper-width resolution, conditional HTTP caching,
 * and presentation rendering.
 */
final class ReceiptEngine
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Resolve 58mm or 80mm layout at print time based on the active Cashier Shift / Register context.
     * Paper layout is separate from the immutable receipt snapshot (ADR-0006).
     */
    public function resolvePaperWidth(?int $actorId = null, ?string $actorRole = null): int
    {
        if ($actorId === null) {
            $actorId = (int)($_SESSION['user_id'] ?? 0);
        }
        if ($actorRole === null) {
            $actorRole = (string)(function_exists('current_role') ? current_role() : ($_SESSION['role'] ?? ''));
        }

        if ($actorRole !== 'cashier' || $actorId <= 0) {
            return 80;
        }

        $statement = $this->pdo->prepare(
            "SELECT r.paper_width_mm FROM cashier_shifts cs
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE cs.cashier_id = ? AND cs.status = 'open'
             ORDER BY cs.opened_at DESC LIMIT 1"
        );
        $statement->execute([$actorId]);
        return (string)$statement->fetchColumn() === '58' ? 58 : 80;
    }

    /**
     * Fetch sale details with preserved snapshot if available, or current database records for legacy sales.
     */
    public function fetchSale(int $saleId, ?int $storeId = null): ?array
    {
        $columns = $this->saleColumns();
        $userCols = $this->userColumns();
        $optional = static fn(string $column, string $fallback): string => isset($columns[$column])
            ? 's.' . $column
            : $fallback;

        $hasDisplayName = isset($userCols['display_name']);
        $cashierCol = $hasDisplayName ? 'u.display_name' : (isset($userCols['full_name']) ? 'u.full_name' : "'Cashier'");
        $hasBranchScope = $storeId !== null && isset($userCols['branch_id']);
        $scopeSql = $hasBranchScope ? ' AND (
            u.branch_id = ? OR EXISTS (
                SELECT 1 FROM sale_items scope_si
                JOIN products scope_p ON scope_p.product_id = scope_si.product_id
                WHERE scope_si.sale_id = s.sale_id AND scope_p.branch_id = ?
            )
        )' : '';

        $statement = $this->pdo->prepare(
            'SELECT s.sale_id, s.cashier_id, s.total_amount, s.payment_method, s.sale_date,
                    ' . $optional('cash_received', 'NULL') . ' AS cash_received,
                    ' . $optional('change_due', 'NULL') . ' AS change_due,
                    ' . $optional('payment_reference', 'NULL') . ' AS payment_reference,
                    ' . $optional('discount_amount', '0.00') . ' AS discount_amount,
                    ' . $optional('promotion_name', 'NULL') . ' AS promotion_name,
                    ' . $optional('discount_reason', 'NULL') . ' AS discount_reason,
                    ' . $cashierCol . ' AS cashier_name, r.name AS register_name
             FROM sales s
             JOIN users u ON u.user_id = s.cashier_id
             LEFT JOIN cashier_shifts cs ON cs.shift_id = s.shift_id
             LEFT JOIN registers r ON r.register_id = cs.register_id
             WHERE s.sale_id = ?' . $scopeSql
        );
        $params = $hasBranchScope ? [$saleId, $storeId, $storeId] : [$saleId];
        $statement->execute($params);
        $sale = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($sale === null) {
            return null;
        }

        $snapshot = $this->snapshot($saleId);
        if ($snapshot !== null) {
            $sale = array_replace($sale, $snapshot['sale']);
            $sale['receipt_store'] = $snapshot['store'];
            $sale['receipt_items'] = $snapshot['items'];
            $sale['verification_code'] = $snapshot['verification_code'];
            $sale['verification_url'] = $snapshot['verification_url'];
            $sale['receipt_cache_hash'] = $snapshot['cache_hash'];
        } else {
            $sale['receipt_historical_notice'] = true;
        }

        $identity = (new RecordProvenance($this->pdo))->actor('sales', ['sale_id' => $saleId], null, (int)$sale['cashier_id']);
        if ($identity !== null) {
            $sale['cashier_provenance'] = $identity;
        }
        return $sale;
    }

    /**
     * Freeze customer-facing details at checkout transaction time.
     */
    public function preserveSale(int $saleId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Sale receipt details require the checkout transaction.');
        }
        if (!$this->hasSnapshotTable()) {
            throw new RuntimeException('Sale receipt storage is unavailable.');
        }
        $sale = $this->fetchSale($saleId);
        if ($sale === null) {
            throw new RuntimeException('Sale receipt could not be preserved.');
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

    /**
     * Store info fallback settings.
     */
    public function storeInfo(): array
    {
        $settings = $this->storeSettings();
        $contact = array_filter([$settings['store_phone'] ?? '', $settings['store_email'] ?? '']);
        return [
            'name' => $settings['store_name'] ?: 'Shalom Store',
            'logo' => ReceiptLogo::source($settings['receipt_logo'] ?? ''),
            'tagline' => 'Official sales receipt',
            'address' => $settings['store_address'] ?: 'Address not configured',
            'contact' => $contact ? implode(' / ', $contact) : 'Contact not configured',
            'tin' => $settings['business_identifier'] ?: 'Business ID not configured',
            'currency_symbol' => $settings['currency_symbol'] ?: '₱',
            'footer' => $settings['receipt_footer'] ?: 'Thank you for shopping with us.',
        ];
    }

    /**
     * Fetch items from preserved snapshot if available, or from live records.
     */
    public function fetchItems(int $saleId): array
    {
        $snapshot = $this->snapshot($saleId);
        return $snapshot === null ? $this->fetchLiveItems($saleId) : $snapshot['items'];
    }

    /**
     * Generate customer dispute verification code.
     */
    public static function verificationCode(array $sale): string
    {
        $raw = implode('|', [
            $sale['sale_id'] ?? '',
            $sale['sale_date'] ?? '',
            number_format((float)($sale['total_amount'] ?? 0), 2, '.', ''),
        ]);
        return implode('-', str_split(substr(strtoupper(hash('sha256', $raw)), 0, 12), 4));
    }

    /**
     * Render receipt metadata header safely without exposing internal session/shift identifiers.
     */
    public static function renderMetadata(array $sale): string
    {
        $cashier = trim((string)($sale['cashier_name'] ?? ''));
        $register = trim((string)($sale['register_name'] ?? ''));
        $cashier = $cashier !== '' ? $cashier : 'Legacy / Unassigned';
        $register = $register !== '' ? $register : 'Legacy / Unassigned';
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div class="receipt-meta">'
            . '<div><strong>Transaction #' . (int)$sale['sale_id'] . '</strong></div>'
            . '<div>Date/Time: ' . $escape(PhilippineTime::format($sale['sale_date'])) . '</div>'
            . '<div>Cashier: ' . $escape($cashier) . '</div>'
            . '<div>Register: ' . $escape($register) . '</div>'
            . '</div>';
    }

    /** Paper width and template revision are part of the representation, not transaction facts. */
    public static function computeSaleETag(array $sale, int $paperWidthMm = 80): string
    {
        $material = ($sale['receipt_cache_hash'] ?? json_encode($sale, JSON_THROW_ON_ERROR))
            . '|' . ($paperWidthMm === 58 ? 58 : 80) . '|receipt-v2';
        return '"sale-' . (int)$sale['sale_id'] . '-' . substr(hash('sha256', $material), 0, 24) . '"';
    }

    public function sendCacheHeaders(array $sale): bool
    {
        if (headers_sent()) return false;
        $etag = self::computeSaleETag($sale, $this->resolvePaperWidth());
        // Revalidate because a different current Register can change the printed representation.
        header('Cache-Control: private, max-age=86400, immutable');
        header('ETag: ' . $etag);
        $candidates = explode(',', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                http_response_code(304);
                return true;
            }
        }
        // Transaction time cannot validate later layout or representation changes.
        return false;
    }

    /** Validate immutable evidence in one lookup, without item queries or snapshot decoding. */
    public function checkConditionalHit(int $saleId, bool $canManageAll, int $userId, ?int $storeId = null): bool
    {
        if (empty($_SERVER['HTTP_IF_NONE_MATCH']) || !$this->hasSnapshotTable()) return false;
        $scope = '';
        $params = [$saleId];
        if (!$canManageAll) {
            $scope .= ' AND s.cashier_id = ?';
            $params[] = $userId;
        }
        if ($storeId !== null && isset($this->userColumns()['branch_id'])) {
            $scope .= ' AND (u.branch_id = ? OR EXISTS (
                SELECT 1 FROM sale_items si JOIN products p ON p.product_id = si.product_id
                WHERE si.sale_id = s.sale_id AND p.branch_id = ?))';
            array_push($params, $storeId, $storeId);
        }
        $statement = $this->pdo->prepare('SELECT s.sale_id, d.details_json FROM sales s
            JOIN users u ON u.user_id = s.cashier_id
            JOIN sale_receipt_details d ON d.sale_id = s.sale_id
            WHERE s.sale_id = ?' . $scope);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;
        return $this->sendCacheHeaders([
            'sale_id' => $saleId, 'receipt_cache_hash' => hash('sha256', $row['details_json']),
        ]);
    }

    /**
     * Prepare structured view data for Sale Receipt templates without database queries or closures in templates.
     */
    public static function prepareSaleViewData(array $sale, array $items, array $store, int $paperWidthMm = 80): array
    {
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $currency = (string)($store['currency_symbol'] ?? '₱');
        $escape = self::escape(...);
        $money = static fn($value): string => self::money($value, $currency);

        $preparedItems = [];
        $itemSubtotal = 0.0;
        $quantityTotal = 0;

        foreach ($items as $item) {
            $subtotal = (float)($item['subtotal'] ?? 0.0);
            $itemSubtotal += $subtotal;
            $qty = (int)($item['quantity'] ?? 0);
            if (($item['selling_form'] ?? 'piece') === 'pack') {
                $quantityTotal += $qty * max(1, (int)($item['pack_size'] ?? 1));
            } else {
                $quantityTotal += $qty;
            }

            $preparedItems[] = [
                'raw' => $item,
                'name' => $escape($item['product_name'] ?? ''),
                'sku' => $escape($item['sku'] ?? ''),
                'quantity' => $qty,
                'unit_price_formatted' => $money($item['unit_price'] ?? 0),
                'subtotal_formatted' => $money($subtotal),
                'line_quantity_description' => $qty . ' × ' . $money($item['unit_price'] ?? 0),
            ];
        }

        $discount = max((float)($sale['discount_amount'] ?? 0), $itemSubtotal - (float)($sale['total_amount'] ?? 0));

        return [
            'paper_width_mm' => $paperWidthMm,
            'is_historical' => !empty($sale['receipt_historical_notice']),
            'store' => [
                'name' => $escape($store['name'] ?? ''),
                'logo' => empty($sale['receipt_historical_notice']) ? ReceiptLogo::source($store['logo'] ?? '') : '',
                'tagline' => $escape($store['tagline'] ?? ''),
                'address' => $escape($store['address'] ?? ''),
                'contact' => $escape($store['contact'] ?? ''),
                'tin' => $escape($store['tin'] ?? ''),
                'footer' => $escape($store['footer'] ?? ''),
                'currency_symbol' => $currency,
            ],
            'metadata_html' => self::renderMetadata($sale),
            'items' => $preparedItems,
            'payment_method' => $escape(strtoupper((string)($sale['payment_method'] ?? ''))),
            'promotion_name' => !empty($sale['promotion_name']) ? $escape($sale['promotion_name']) : null,
            'discount_reason' => !empty($sale['discount_reason']) ? $escape($sale['discount_reason']) : null,
            'payment_reference' => !empty($sale['payment_reference']) ? $escape($sale['payment_reference']) : null,
            'totals' => [
                'total_quantity' => $quantityTotal,
                'subtotal_formatted' => $money($itemSubtotal),
                'discount_formatted' => $money($discount),
                'total_amount_formatted' => $money($sale['total_amount'] ?? 0),
                'cash_received_formatted' => isset($sale['cash_received']) && $sale['cash_received'] !== null ? $money($sale['cash_received']) : '-',
                'change_due_formatted' => isset($sale['change_due']) && $sale['change_due'] !== null ? $money($sale['change_due']) : '-',
            ],
        ];
    }

    /**
     * Prepare structured view data for Refund Receipt templates.
     */
    public static function prepareRefundViewData(array $details, int $paperWidthMm = 80): array
    {
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $refund = $details['refund'] ?? [];
        $items = $details['items'] ?? [];
        $store = $details['store'] ?? [];
        $currency = (string)($store['currency_symbol'] ?? '₱');
        $escape = self::escape(...);
        $money = static fn($value): string => self::money($value, $currency);

        $preparedItems = [];
        foreach ($items as $item) {
            $preparedItems[] = [
                'name' => $escape($item['product_name'] ?? ''),
                'sku' => $escape($item['sku'] ?? ''),
                'quantity' => (int)($item['quantity'] ?? 0),
                'subtotal_formatted' => $money($item['subtotal'] ?? 0),
            ];
        }

        return [
            'paper_width_mm' => $paperWidthMm,
            'store' => [
                'name' => $escape($store['name'] ?? ''),
                'logo' => ReceiptLogo::source($store['logo'] ?? ''),
                'address' => $escape($store['address'] ?? ''),
                'contact' => $escape($store['contact'] ?? ''),
                'tin' => $escape($store['tin'] ?? ''),
                'footer' => $escape($store['footer'] ?? ''),
            ],
            'refund' => [
                'refund_id' => (int)($refund['refund_id'] ?? 0),
                'sale_id' => (int)($refund['sale_id'] ?? 0),
                'created_at_formatted' => $escape(PhilippineTime::format($refund['created_at'] ?? '')),
                'cashier_name' => $escape($refund['cashier_name'] ?? ''),
                'register_name' => $escape($refund['register_name'] ?: 'Legacy / Unassigned'),
                'reason' => $escape($refund['reason'] ?? ''),
                'payment_method' => $escape(strtoupper((string)($refund['payment_method'] ?? ''))),
                'payment_method_raw' => (string)($refund['payment_method'] ?? ''),
                'refund_amount_formatted' => $money($refund['refund_amount'] ?? 0),
                'payment_reference' => !empty($refund['payment_reference']) ? $escape($refund['payment_reference']) : null,
            ],
            'items' => $preparedItems,
        ];
    }

    /**
     * Prepare structured view data for GCash Service Receipt templates.
     */
    public static function prepareGCashViewData(array $receipt, int $paperWidthMm = 80): array
    {
        require_once __DIR__ . '/../../includes/barcode.php';
        $paperWidthMm = $paperWidthMm === 58 ? 58 : 80;
        $store = $receipt['store'] ?? [];
        $currency = (string)($store['currency_symbol'] ?? '₱');
        $escape = self::escape(...);
        $money = static fn($value): string => self::money($value, $currency);

        $amounts = [];
        foreach (['cash_collected', 'cash_paid_out', 'wallet_sent', 'wallet_received'] as $key) {
            $val = (string)($receipt[$key] ?? '0.00');
            $amounts[$key] = $val;
            $amounts[$key . '_formatted'] = $money($val);
        }

        return [
            'paper_width_mm' => $paperWidthMm,
            'store' => [
                'name' => $escape($store['name'] ?? ''),
                'logo' => ReceiptLogo::source($store['logo'] ?? ''),
                'address' => $escape($store['address'] ?? ''),
                'contact' => $escape($store['contact'] ?? ''),
                'tin' => $escape($store['tin'] ?? ''),
                'footer' => $escape($store['footer'] ?? ''),
            ],
            'transaction_id' => (int)($receipt['transaction_id'] ?? 0),
            'transaction_type' => $escape($receipt['transaction_type'] ?? ''),
            'completed_at_formatted' => $escape(PhilippineTime::format($receipt['completed_at'] ?? '')),
            'cashier_name' => $escape($receipt['cashier_name'] ?? ''),
            'register_name' => $escape($receipt['register_name'] ?? ''),
            'principal_amount_formatted' => $money($receipt['principal_amount'] ?? 0),
            'fee_amount_formatted' => $money($receipt['fee_amount'] ?? 0),
            'fee_method' => $escape($receipt['fee_method'] ?? ''),
            'amounts' => $amounts,
            'barcode_svg' => code128_svg($receipt['reference_number'] ?? ''),
            'reference_number' => $escape($receipt['reference_number'] ?? ''),
        ];
    }

    /**
     * Render Sale Receipt HTML.
     */
    public static function renderSale(array $sale, array $items, array $store, int $paperWidthMm = 80): void
    {
        $view = self::prepareSaleViewData($sale, $items, $store, $paperWidthMm);
        require __DIR__ . '/../Views/sale_receipt.php';
    }

    /**
     * Render Refund Receipt HTML.
     */
    public static function renderRefund(array $details, int $paperWidthMm = 80): void
    {
        $view = self::prepareRefundViewData($details, $paperWidthMm);
        require __DIR__ . '/../Views/refund_receipt.php';
    }

    /**
     * Render GCash Receipt HTML.
     */
    public static function renderGCash(array $receipt, int $paperWidthMm = 80): void
    {
        $view = self::prepareGCashViewData($receipt, $paperWidthMm);
        require __DIR__ . '/../Views/gcash_receipt.php';
    }

    public function preserveRefund(int $refundId): void
    {
        require_once __DIR__ . '/../Services/CashRefundService.php';
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Refund receipt details require the refund transaction.');
        }
        $columns = $this->userColumns();
        $cashierColumn = isset($columns['display_name']) ? 'u.display_name' : 'u.full_name';
        $statement = $this->pdo->prepare("SELECT cr.refund_id, cr.sale_id, cr.refund_amount,
            cr.payment_method, cr.payment_reference, cr.reason, cr.created_at, {$cashierColumn} AS cashier_name, r.name AS register_name
            FROM cash_refunds cr JOIN users u ON u.user_id = cr.cashier_id
            JOIN cashier_shifts cs ON cs.shift_id = cr.shift_id
            LEFT JOIN registers r ON r.register_id = cs.register_id WHERE cr.refund_id = ?");
        $statement->execute([$refundId]);
        $refund = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new RuntimeException('Refund receipt could not be preserved.');
        }
        $refund['reason'] = \App\Services\CashRefundService::REFUND_REASONS[$refund['reason']];
        $criCols = \SalesWorkflowService::getTableColumns($this->pdo, 'cash_refund_items');
        $siCols = \SalesWorkflowService::getTableColumns($this->pdo, 'sale_items');
        $formSelect = isset($criCols['selling_form'])
            ? ', cri.selling_form'
            : (isset($siCols['selling_form']) ? ', si.selling_form' : ", 'piece' AS selling_form");
        $packSelect = isset($criCols['pack_size'])
            ? ', cri.pack_size'
            : (isset($siCols['pack_size']) ? ', si.pack_size' : ', NULL AS pack_size');
        $statement = $this->pdo->prepare("SELECT p.product_name, p.sku, cri.quantity, cri.subtotal{$formSelect}{$packSelect}
            FROM cash_refund_items cri JOIN products p ON p.product_id = cri.product_id
            LEFT JOIN sale_items si ON si.sale_item_id = cri.sale_item_id
            WHERE cri.refund_id = ? ORDER BY cri.refund_item_id");
        $statement->execute([$refundId]);
        $details = ['refund' => $refund, 'items' => $statement->fetchAll(PDO::FETCH_ASSOC),
            'store' => $this->storeInfo()];
        $statement = $this->pdo->prepare('INSERT INTO refund_receipt_details (refund_id, details_json) VALUES (?, ?)');
        $statement->execute([$refundId, json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }

    public function fetchRefundForCashier(int $refundId, int $cashierId, int $storeId): ?array
    {
        // Live ownership and Store checks precede access to immutable customer data.
        $statement = $this->pdo->prepare('SELECT sale_id FROM cash_refunds WHERE refund_id = ? AND cashier_id = ?');
        $statement->execute([$refundId, $cashierId]);
        $saleId = $statement->fetchColumn();
        if ($saleId === false || $this->fetchSale((int)$saleId, $storeId) === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT details_json FROM refund_receipt_details WHERE refund_id = ?');
        $statement->execute([$refundId]);
        $json = $statement->fetchColumn();
        if ($json === false) return null;
        $details = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
        $identity = (new \App\Backup\RecordProvenance($this->pdo))->actor('cash_refunds', ['refund_id' => $refundId], null, $cashierId);
        if ($identity !== null) $details['cashier_provenance'] = $identity;
        return $details;
    }

    public function preserveGCash(int $transactionId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('GCash receipt details require the completion transaction.');
        }
        $columns = $this->userColumns();
        $cashierColumn = isset($columns['display_name']) ? 'u.display_name' : 'u.full_name';
        $statement = $this->pdo->prepare("SELECT t.*, {$cashierColumn} AS cashier_name, u.username AS cashier_username, r.name AS register_name
            FROM gcash_transactions t JOIN users u ON u.user_id = t.cashier_id
            LEFT JOIN cashier_shifts cs ON cs.shift_id = t.shift_id
            LEFT JOIN registers r ON r.register_id = cs.register_id
            WHERE t.transaction_id = ? AND t.verification_status = 'Completed'");
        $statement->execute([$transactionId]);
        $tx = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$tx || !$tx['completed_at'] || !$tx['reference_number']) {
            throw new RuntimeException('Only a completed exchange can preserve a GCash Service Receipt.');
        }
        if ($tx['receipt_details_json'] !== null) {
            return;
        }
        $principal = (int)round((float)$tx['principal_amount'] * 100);
        $fee = (int)round((float)$tx['gross_fee'] * 100);
        $cashIn = $tx['transaction_type'] === 'Cash-In';
        $cashFee = $tx['fee_method'] === 'cash';
        $decimal = static fn(int $centavos): string => number_format($centavos / 100, 2, '.', '');
        $store = $this->storeInfo();
        $store['tagline'] = 'GCash Service Receipt';
        $details = [
            'store' => $store,
            'transaction_id' => (int)$tx['transaction_id'],
            'cashier_id' => (int)$tx['cashier_id'],
            'cashier_name' => $tx['cashier_name'] ?: $tx['cashier_username'],
            'register_name' => $tx['register_name'] ?: 'Unassigned',
            'completed_at' => $tx['completed_at'],
            'transaction_type' => $tx['transaction_type'],
            'reference_number' => $tx['reference_number'],
            'principal_amount' => $decimal($principal),
            'fee_amount' => $decimal($fee),
            'fee_method' => $cashFee ? 'Fee in cash' : 'Fee via GCash',
            'cash_collected' => $decimal($cashIn ? $principal + $fee : ($cashFee ? $fee : 0)),
            'cash_paid_out' => $decimal($cashIn ? 0 : $principal),
            'wallet_sent' => $decimal($cashIn ? $principal : 0),
            'wallet_received' => $decimal($cashIn ? 0 : $principal + ($cashFee ? 0 : $fee)),
        ];
        $this->pdo->prepare('UPDATE gcash_transactions SET receipt_details_json = ? WHERE transaction_id = ? AND receipt_details_json IS NULL')
            ->execute([json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $transactionId]);
    }

    public function fetchGCash(int $transactionId, int $actorId, string $actorRole): ?array
    {
        if ($actorId <= 0 || !in_array($actorRole, ['cashier', 'admin', 'super_admin'], true)) {
            return null;
        }
        $statement = $this->pdo->prepare("SELECT receipt_details_json FROM gcash_transactions
            WHERE transaction_id = ? AND verification_status = 'Completed'" . ($actorRole === 'cashier' ? ' AND cashier_id = ?' : ''));
        $statement->execute($actorRole === 'cashier' ? [$transactionId, $actorId] : [$transactionId]);
        $json = $statement->fetchColumn();
        if (!$json) return null;
        $details = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $identity = (new \App\Backup\RecordProvenance($this->pdo))->actor('gcash_transactions', ['transaction_id' => $transactionId], (int)$details['cashier_id']);
        if ($identity !== null) $details['cashier_provenance'] = $identity;
        return $details;
    }

    private static function escape(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function money(mixed $value, string $currency): string
    {
        return self::escape($currency) . number_format((float)$value, 2);
    }

    private function fetchLiveItems(int $saleId): array
    {
        $cols = $this->saleItemColumns();
        $formSelect = isset($cols['selling_form']) ? ', si.selling_form' : ", 'piece' AS selling_form";
        $packSelect = isset($cols['pack_size']) ? ', si.pack_size' : ', NULL AS pack_size';
        $statement = $this->pdo->prepare("SELECT si.quantity, si.unit_price, si.subtotal, p.sku, p.product_name{$formSelect}{$packSelect}
            FROM sale_items si JOIN products p ON p.product_id = si.product_id
            WHERE si.sale_id = ? ORDER BY si.sale_item_id");
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
        if ($json === false) return null;
        $snapshot = json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
        $snapshot['cache_hash'] = hash('sha256', (string)$json);
        return $snapshot;
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
            'store_name' => 'Shalom Store',
            'store_address' => 'Tangub City',
            'store_phone' => '',
            'store_email' => '',
            'business_identifier' => '',
            'receipt_footer' => 'Thank you for shopping with us.',
            'currency_symbol' => '₱',
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

    private function saleColumns(): array
    {
        return \SalesWorkflowService::getTableColumns($this->pdo, 'sales');
    }

    private function userColumns(): array
    {
        return \SalesWorkflowService::getTableColumns($this->pdo, 'users');
    }

    private function saleItemColumns(): array
    {
        return \SalesWorkflowService::getTableColumns($this->pdo, 'sale_items');
    }
}
