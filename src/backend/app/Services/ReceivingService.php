<?php
require_once __DIR__ . "/InventorySubmission.php";
// app/Services/ReceivingService.php

require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/BatchInventoryLedger.php';
require_once __DIR__ . '/../Store/StoreWriteGate.php';
use App\Store\StoreWriteGate;
require_once __DIR__ . '/FiscalPeriodGuardService.php';
require_once __DIR__ . '/../../includes/functions.php';

class ReceivingService
{
    private const MAX_COST_CENTS = 9999999999;
    private PDO $pdo;
    private NotificationService $notificationService;
    private FiscalPeriodGuardService $fiscalPeriodGuard;
    private ?bool $hasPulloutColumn = null;
    private ?bool $hasPackColumns = null;
    private ?bool $hasBatchPackColumns = null;
    private ?bool $hasBatchCostColumn = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->notificationService = new NotificationService($pdo);
        $this->fiscalPeriodGuard = new FiscalPeriodGuardService($pdo);
    }

    private array $columnCache = [];

    private function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";
        if (!isset($this->columnCache[$key])) {
            try {
                $this->pdo->query("SELECT {$column} FROM {$table} LIMIT 1");
                $this->columnCache[$key] = true;
            } catch (Throwable) {
                $this->columnCache[$key] = false;
            }
        }
        return $this->columnCache[$key];
    }

    public function receiveStock(array $data, int $userId): array
    {
        return $this->receiveShoppingTrip([$data], $data, $userId)[0];
    }

    public function receiveShoppingTrip(array $lines, array $entry, int $userId): array
    {
        if (function_exists('require_capability')) {
            require_capability(App\Authorization\RoleCapabilityPolicy::MUTATE_INVENTORY);
        }
        $submission = new InventorySubmission($this->pdo, $userId, 'receiving', $entry + ['lines' => $lines]);
        if (($completed = $submission->completed()) !== null) return $completed;
        $productIds = $this->deliveryProductIds($lines);
        $shared = [
            'supplier' => $entry['supplier'] ?? '',
            'invoice_number' => $entry['invoice_number'] ?? '',
        ];
        $this->fiscalPeriodGuard->assertOpenNow('stock_receiving', 'stock receiving');
        // Serialize receipts before the fail-fast Store gate; overlapping receipts can both finish.
        $lockSql = "CONCAT('receiving:', LEFT(SHA2(DATABASE(), 256), 48))";
        if ((int)$this->pdo->query("SELECT GET_LOCK({$lockSql}, 10)")->fetchColumn() !== 1) {
            throw new RuntimeException('Another receiving entry is still saving. Wait a moment, then try again.');
        }
        try {
            StoreWriteGate::begin($this->pdo);
            if (($completed = $submission->completed()) !== null) {
                $this->pdo->rollBack();
                return $completed;
            }
            // Resolve every fallback from the catalog before any delivery line updates it.
            $products = $this->deliveryProducts($productIds, true);
            $groupStmt = $this->pdo->prepare("INSERT INTO stock_receiving_groups (received_by, supplier, invoice_number, notes) VALUES (?, ?, ?, ?)");
            $groupStmt->execute([
                $userId,
                $shared['supplier'] !== '' ? $shared['supplier'] : null,
                $shared['invoice_number'] !== '' ? $shared['invoice_number'] : null,
                !empty($entry['notes']) ? trim((string)$entry['notes']) : null,
            ]);
            $groupId = (int)$this->pdo->lastInsertId();

            $results = [];
            foreach ($lines as $index => $line) {
                try {
                    $productId = (int)$line['product_id'];
                    $allocations = $this->deliveryAllocations($line);
                    $productResults = [];
                    $costs = [];
                    foreach ($allocations as $allocation) {
                        $data = array_replace($allocation, $shared, ['product_id' => $productId, 'group_id' => $groupId,
                            'pullout_id' => $line['pullout_id'] ?? null]);
                        $result = $this->receiveLine($data, $userId, $products[$productId]);
                        if (isset($line['allocations']) && $result['accepted_qty'] <= 0) {
                            throw new RuntimeException('Each batch needs a positive accepted quantity.');
                        }
                        $products[$productId]['quantity_purchased'] += $result['accepted_qty'];
                        if ($result['cost_price'] !== null && $result['accepted_qty'] > 0) {
                            $costs[self::decimal(self::cents((string)$result['cost_price']))] = true;
                        }
                        $productResults[] = $result;
                    }
                    $choice = $this->text($line['current_product_cost'] ?? '', 'Current product cost', 20);
                    if ($choice !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $choice)) {
                        throw new RuntimeException('Choose a valid Current product cost from the final batches.');
                    }
                    if ($choice !== '') $choice = self::decimal(self::cents($choice));
                    if (($choice !== '' && !isset($costs[$choice])) || (count($costs) > 1 && $choice === '')) {
                        throw new RuntimeException('Choose Current product cost from the final batches, then Review again.');
                    }
                    if ($choice === '' && count($costs) === 1) $choice = (string)array_key_first($costs);
                    if ($choice !== '') {
                        $known = $this->hasColumn('products', 'cost_known') ? ', cost_known = 1' : '';
                        $this->pdo->prepare("UPDATE products SET cost_price = ?{$known} WHERE product_id = ?")->execute([$choice, $productId]);
                    }
                    if ($this->hasColumn('stock_receiving', 'current_product_cost')) {
                        $metadata = $this->pdo->prepare('UPDATE stock_receiving SET current_product_cost = ?, quantity_mode = ?, cost_source = ? WHERE receiving_id = ?');
                        foreach ($productResults as &$result) {
                            $mode = $result['pack_size_used'] !== null ? 'packs' : ($result['received_packages'] > 0 ? 'packages' : 'units');
                            $metadata->execute([$choice !== '' ? $choice : null, $mode, $result['cost_source'], $result['receiving_id']]);
                            $result += ['current_product_cost' => $choice !== '' ? (float)$choice : null, 'quantity_mode' => $mode];
                        }
                        unset($result);
                    } elseif (isset($line['allocations'])) {
                        throw new RuntimeException('Receiving multiple batches requires the latest database schema.');
                    }
                    array_push($results, ...$productResults);
                } catch (RuntimeException $e) {
                    if ($e instanceof PDOException) {
                        error_log((string)$e);
                        throw new RuntimeException('Line ' . ($index + 1) . ' could not be saved. Nothing was recorded. Check the product details and try again.', 0, $e);
                    }
                    throw new RuntimeException('Line ' . ($index + 1) . ': ' . $e->getMessage(), 0, $e);
                }
            }
            $submission->remember($results);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            try {
                $this->pdo->query("SELECT RELEASE_LOCK({$lockSql})");
            } catch (Throwable $e) {
                error_log('Receiving lock release failed: ' . $e->getMessage());
            }
        }
        try {
            $this->notificationService->checkAndNotifyLowStock();
            $this->notificationService->checkAndNotifyExpiringStock();
        } catch (Throwable $e) {
            error_log('Receiving notifications failed: ' . $e->getMessage());
        }
        return $results;
    }

    public function reviewShoppingTrip(array $lines, int $userId): array
    {
        if (!$this->hasColumn('stock_receiving', 'current_product_cost')) {
            throw new RuntimeException('Receiving multiple batches requires the latest database schema.');
        }
        $products = $this->deliveryProducts($this->deliveryProductIds($lines));
        $review = [];
        foreach ($lines as $index => $line) {
            $product = $products[(int)$line['product_id']];
            $batches = [];
            foreach ($this->deliveryAllocations($line) as $batchIndex => $allocation) {
                try {
                    $quote = $this->receiveLine(array_replace($allocation, ['product_id' => $line['product_id'],
                        'pullout_id' => $line['pullout_id'] ?? null]), $userId, $product, true);
                    if ($quote['accepted_qty'] <= 0) throw new RuntimeException('Each batch needs a positive accepted quantity.');
                    $product['quantity_purchased'] += $quote['accepted_qty'];
                    $batches[] = $quote;
                } catch (RuntimeException $e) {
                    throw new RuntimeException('Line ' . ($index + 1) . ', batch ' . ($batchIndex + 1) . ': ' . $e->getMessage(), 0, $e);
                }
            }
            $review[] = $batches;
        }
        return $review;
    }

    private function deliveryProductIds(array $lines): array
    {
        if ($lines === [] || !array_is_list($lines)) {
            throw new RuntimeException('Add at least one product line before saving the shopping entry.');
        }
        $products = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) throw new RuntimeException('Each product line must contain receiving details.');
            $productId = $this->wholeNumber($line['product_id'] ?? 0, 'Product on line ' . ($index + 1));
            if ($productId <= 0) throw new RuntimeException('Choose a product on line ' . ($index + 1) . '.');
            if (isset($products[$productId])) {
                throw new RuntimeException('Duplicate product on line ' . ($index + 1) . '. Remove the duplicate line or choose another product.');
            }
            $products[$productId] = true;
        }
        return array_keys($products);
    }

    private function deliveryProducts(array $productIds, bool $lock = false): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $sql = "SELECT p.* FROM products p WHERE product_id = ? AND status = 'active'{$scopeSql}";
        $stmt = $this->pdo->prepare($sql . ($lock ? ' FOR UPDATE' : ''));
        $products = [];
        foreach ($productIds as $productId) {
            $stmt->execute(array_merge([$productId], $scopeParams));
            $products[$productId] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $products;
    }

    private function deliveryAllocations(array $line): array
    {
        $allocations = $line['allocations'] ?? [$line];
        if (!is_array($allocations) || !array_is_list($allocations) || $allocations === []) {
            throw new RuntimeException('Each product needs at least one valid batch.');
        }
        foreach ($allocations as $allocation) {
            if (!is_array($allocation)) throw new RuntimeException('Each batch must contain receiving details.');
        }
        return $allocations;
    }

    private static function costConversions(array $product): array
    {
        $conversions = ['units' => 1];
        $caseSize = (int)($product['units_per_package'] ?? 0);
        $caseName = trim($product['receiving_unit'] ?? '');
        if ($caseSize > 1 || ($caseSize === 1 && (!empty($product['case_barcode']) || !in_array($caseName, ['', 'package'], true)))) {
            $conversions['packages'] = $caseSize;
        }
        if ((int)($product['pack_size'] ?? 0) > 1) $conversions['packs'] = (int)$product['pack_size'];
        return $conversions;
    }

    private static function cents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        return (int)$whole * 100 + (int)str_pad($fraction, 2, '0');
    }

    private static function decimal(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function receiveLine(array $data, int $userId, ?array $product, bool $review = false): array
    {
        $productId = $this->wholeNumber($data['product_id'] ?? 0, 'Product');
        $quantityMode = $data['quantity_mode'] ?? 'units';
        if (!in_array($quantityMode, ['units', 'packages', 'packs'], true)) {
            throw new RuntimeException('Choose Base units, Packages / cases, or Sealed packs & loose pieces.');
        }
        $receivedPackages = $this->wholeNumber(($data['received_packages'] ?? '') === '' && $quantityMode === 'units' ? 0 : ($data['received_packages'] ?? 0), 'Package quantity');
        $receivedQtyInput = $this->wholeNumber(($data['received_qty'] ?? '') === '' && $quantityMode === 'packages' ? 0 : ($data['received_qty'] ?? 0), 'Received quantity');
        $damagedQty = $this->wholeNumber($data['damaged_qty'] ?? 0, 'Damaged quantity');
        $receivedQty = $receivedQtyInput;
        $acceptedQty = 0;
        $unitsPerPackageUsed = 1;
        $costInput = $this->text($data['cost_price'] ?? '', 'Purchase cost', 20);
        if ($costInput !== '' && !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $costInput)) {
            throw new RuntimeException('Purchase cost must be a non-negative amount with at most two decimal places.');
        }
        $supplier = $this->text($data['supplier'] ?? '', 'Shopping store', 150);
        $invoiceNumber = $this->text($data['invoice_number'] ?? '', 'Receipt reference', 100);
        $batchNumber = $this->text($data['batch_number'] ?? '', 'Batch number', 100);
        $expirationDate = validate_calendar_date($data['expiration_date'] ?? null);
        $discrepancyType = $data['discrepancy_type'] ?? 'none';
        $discrepancyQty = $this->wholeNumber($data['discrepancy_qty'] ?? 0, 'Discrepancy quantity');
        $discrepancyNotes = $this->text($data['discrepancy_notes'] ?? '', 'Discrepancy details', 65535);
        $notes = $this->text($data['notes'] ?? '', 'Notes', 65535);
        if ($productId <= 0) {
            throw new RuntimeException('Product is required.');
        }

        $pulloutId = null;
        if (isset($data['pullout_id']) && $data['pullout_id'] !== '' && $data['pullout_id'] !== null) {
            $pulloutId = $this->wholeNumber($data['pullout_id'], 'Linked supplier pullout');
            if ($pulloutId <= 0) {
                throw new RuntimeException('Linked supplier pullout not found.');
            }
            $pulloutStmt = $this->pdo->prepare('SELECT pullout_id, product_id, supplier FROM supplier_pullouts WHERE pullout_id = ?');
            $pulloutStmt->execute([$pulloutId]);
            $pullout = $pulloutStmt->fetch(PDO::FETCH_ASSOC);
            if (!$pullout) {
                throw new RuntimeException('Linked supplier pullout not found.');
            }
            if ((int)$pullout['product_id'] !== $productId) {
                throw new RuntimeException('Linked supplier pullout does not match the product being received.');
            }
            if ($supplier === '' && !empty($pullout['supplier'])) {
                $supplier = trim((string)$pullout['supplier']);
            }
        }
        if (!in_array($discrepancyType, ['none', 'short', 'over', 'damaged', 'documentation', 'other'], true)) {
            throw new RuntimeException('Invalid delivery discrepancy type.');
        }

        if (!$product) {
            throw new RuntimeException('Active product not found.');
        }
        $conversion = match ($quantityMode) {
            'packages' => (string)($product['units_per_package'] ?? 0),
            'packs' => (string)($product['pack_size'] ?? 0),
            default => '1',
        };
        if (isset($data['review_conversion']) && $data['review_conversion'] !== '' && $data['review_conversion'] !== $conversion) {
            throw new RuntimeException('Saved product conversion changed. Review this entry again before saving.');
        }
        $fallbackCost = \App\Inventory\ProductCost::known($product) ? self::decimal(self::cents((string)$product['cost_price'])) : '';
        if ($costInput === '' && array_key_exists('cost_fallback', $data) && $data['cost_fallback'] !== $fallbackCost) {
            throw new RuntimeException('Saved product cost changed. Review this entry again before saving.');
        }
        $basis = $data['cost_basis'] ?? 'units';
        if (!is_string($basis) || !in_array($basis, ['units', 'packages', 'packs'], true)) {
            throw new RuntimeException('Choose a valid purchase cost basis.');
        }
        $costUnits = $costInput === '' ? 1 : (self::costConversions($product)[$basis] ?? 0);
        if (isset($data['review_cost_units']) && $data['review_cost_units'] !== '' && $data['review_cost_units'] !== (string)$costUnits) {
            throw new RuntimeException('Saved purchase cost conversion changed. Review this entry again before saving.');
        }
        if ($costUnits === 0) {
            throw new RuntimeException('Choose a purchase cost basis configured for this product.');
        }
        $hasCostBasis = $this->hasColumn('stock_receiving', 'cost_basis');
        if (!$hasCostBasis && $basis !== 'units' && $costInput !== '') {
            throw new RuntimeException('Package purchase costs require the latest database schema.');
        }

        $packSize = isset($product['pack_size']) && $product['pack_size'] !== null ? (int)$product['pack_size'] : null;

        $hasPacksInput = (isset($data['received_packs']) && $data['received_packs'] !== '')
            || (isset($data['accepted_packs']) && $data['accepted_packs'] !== '')
            || (isset($data['damaged_packs']) && $data['damaged_packs'] !== '')
            || (isset($data['rejected_packs']) && $data['rejected_packs'] !== '');

        $hasLooseInput = (isset($data['received_loose']) && $data['received_loose'] !== '')
            || (isset($data['accepted_loose']) && $data['accepted_loose'] !== '')
            || (isset($data['damaged_loose']) && $data['damaged_loose'] !== '')
            || (isset($data['rejected_loose']) && $data['rejected_loose'] !== '');

        if ($quantityMode === 'packs' && ((!$hasPacksInput && !$hasLooseInput) || $packSize === null || $packSize <= 1)) {
            throw new RuntimeException('Enter sealed packs and loose pieces using a configured Sales Pack.');
        }

        if ($hasPacksInput && ($packSize === null || $packSize <= 1)) {
            throw new RuntimeException('Cannot receive packs: product has no Sales Pack configured.');
        }

        if ($hasPacksInput || $hasLooseInput) {
            $receivedPacks = $this->wholeNumber($data['received_packs'] ?? 0, 'Received packs');
            $damagedPacks = $this->wholeNumber($data['damaged_packs'] ?? 0, 'Damaged packs');
            $rejectedPacks = $this->wholeNumber($data['rejected_packs'] ?? 0, 'Rejected packs');

            if ($damagedPacks > $receivedPacks) {
                throw new RuntimeException('Damaged packs cannot exceed quantity received.');
            }
            if ($rejectedPacks > $receivedPacks) {
                throw new RuntimeException('Rejected packs cannot exceed quantity received.');
            }
            if ($damagedPacks + $rejectedPacks > $receivedPacks) {
                throw new RuntimeException('Damaged and rejected packs cannot exceed quantity received.');
            }

            if (isset($data['accepted_packs']) && $data['accepted_packs'] !== '' && $data['accepted_packs'] !== null) {
                $explicitAcceptedPacks = $this->wholeNumber($data['accepted_packs'], 'Accepted packs');
                if ($explicitAcceptedPacks + $damagedPacks + $rejectedPacks !== $receivedPacks) {
                    throw new RuntimeException('Inconsistent pack quantities: accepted, damaged, and rejected packs must equal received packs.');
                }
                $acceptedPacks = $explicitAcceptedPacks;
            } else {
                $acceptedPacks = max(0, $receivedPacks - $damagedPacks - $rejectedPacks);
            }

            $receivedLoose = $this->wholeNumber($data['received_loose'] ?? 0, 'Received loose pieces');
            $damagedLoose = $this->wholeNumber($data['damaged_loose'] ?? 0, 'Damaged loose pieces');
            $rejectedLoose = $this->wholeNumber($data['rejected_loose'] ?? 0, 'Rejected loose pieces');

            if ($damagedLoose > $receivedLoose) {
                throw new RuntimeException('Damaged loose pieces cannot exceed quantity received.');
            }
            if ($rejectedLoose > $receivedLoose) {
                throw new RuntimeException('Rejected loose pieces cannot exceed quantity received.');
            }
            if ($damagedLoose + $rejectedLoose > $receivedLoose) {
                throw new RuntimeException('Damaged and rejected loose pieces cannot exceed quantity received.');
            }

            if (isset($data['accepted_loose']) && $data['accepted_loose'] !== '' && $data['accepted_loose'] !== null) {
                $explicitAcceptedLoose = $this->wholeNumber($data['accepted_loose'], 'Accepted loose pieces');
                if ($explicitAcceptedLoose + $damagedLoose + $rejectedLoose !== $receivedLoose) {
                    throw new RuntimeException('Inconsistent loose quantities: accepted, damaged, and rejected loose pieces must equal received loose pieces.');
                }
                $acceptedLoose = $explicitAcceptedLoose;
            } else {
                $acceptedLoose = max(0, $receivedLoose - $damagedLoose - $rejectedLoose);
            }

            $packSizeUsed = $packSize;
            $multiplier = $packSize ?? 1;
            if (((float)$receivedPacks * (float)$multiplier) > 2147483647) {
                throw new RuntimeException('Resulting unit quantity is too large.');
            }
            $receivedQty = ($receivedPacks * $multiplier) + $receivedLoose;
            $acceptedQty = ($acceptedPacks * $multiplier) + $acceptedLoose;
            $damagedQty = ($damagedPacks * $multiplier) + $damagedLoose;
            $rejectedQty = ($rejectedPacks * $multiplier) + $rejectedLoose;
            if ($receivedQty > 2147483647) {
                throw new RuntimeException('Resulting unit quantity is too large.');
            }
            $receivedPackages = 0;
            $unitsPerPackageUsed = (int)($product['units_per_package'] ?? 1);
        } else {
            $unitsPerPackageUsed = $quantityMode === 'packages'
                ? $this->wholeNumber($product['units_per_package'] ?? 0, 'Saved pack size') : 1;
            if ($quantityMode === 'packages') {
                if ($unitsPerPackageUsed <= 0) {
                    throw new RuntimeException('Set a positive whole-number pack size on the product before receiving packages.');
                }
                if ($receivedPackages <= 0) {
                    throw new RuntimeException('Package quantity must be greater than zero.');
                }
                $receivedQty = $receivedPackages * $unitsPerPackageUsed;
                if ($receivedQty > 2147483647) {
                    throw new RuntimeException('Resulting unit quantity is too large.');
                }
            } else {
                if ($receivedQtyInput <= 0) {
                    throw new RuntimeException('Received quantity must be greater than zero.');
                }
                $receivedQty = $receivedQtyInput;
                $receivedPackages = 0;
            }
            if ($damagedQty > $receivedQty) {
                throw new RuntimeException('Damaged quantity cannot exceed quantity received.');
            }
            $acceptedQty = $receivedQty - $damagedQty;
            $receivedPacks = 0;
            $acceptedPacks = 0;
            $damagedPacks = 0;
            $rejectedPacks = 0;
            $receivedLoose = $receivedQty;
            $acceptedLoose = $acceptedQty;
            $damagedLoose = $damagedQty;
            $rejectedLoose = 0;
            $packSizeUsed = null;
            $rejectedQty = 0;
        }

        if ((int)$product['quantity_purchased'] + $acceptedQty > 2147483647) {
            throw new RuntimeException('Accepted quantity would exceed the supported stock total. Enter a smaller quantity.');
        }
        if ($acceptedQty <= 0 && $damagedQty <= 0 && $rejectedQty <= 0) {
            throw new RuntimeException('At least one delivered unit must be accepted or reported damaged.');
        }
        $amount = $costInput !== '' ? $costInput : $fallbackCost;
        $costPrice = null;
        $totalCost = '0.00';
        $unitCents = $totalCents = null;
        $roundingDifference = false;
        if ($amount !== '') {
            $amountCents = self::cents($amount);
            $whole = intdiv($amountCents, $costUnits);
            if ($acceptedQty > 0 && $whole > intdiv(self::MAX_COST_CENTS, $acceptedQty)) {
                throw new RuntimeException('Total purchase cost is too large.');
            }
            // Splitting off the whole cents keeps the largest valid remainder product within a signed 64-bit integer.
            $remainder = $amountCents % $costUnits;
            $half = intdiv($costUnits, 2);
            $totalCents = $whole * $acceptedQty + intdiv($remainder * $acceptedQty + $half, $costUnits);
            if ($totalCents > self::MAX_COST_CENTS) {
                throw new RuntimeException('Total purchase cost is too large.');
            }
            $unitCents = $whole + intdiv($remainder + $half, $costUnits);
            $costPrice = self::decimal($unitCents);
            $totalCost = self::decimal($totalCents);
            $roundingDifference = $unitCents * $acceptedQty !== $totalCents;
        }
        $cost = [
            'accepted_qty' => $acceptedQty,
            'cost_price' => $costPrice === null ? null : (float)$costPrice,
            'total_cost' => $costPrice === null ? null : (float)$totalCost,
            'entered_cost' => $costInput === '' ? null : (float)$costInput,
            'cost_basis' => $amount === '' ? null : ($costInput === '' ? 'units' : $basis),
            'cost_units_used' => $amount === '' ? null : $costUnits,
            'cost_source' => $costInput !== '' ? 'explicit' : ($amount !== '' ? 'product' : 'unknown'),
            'rounding_difference' => $roundingDifference,
        ];
        if ($review) return $cost + ['unit_cents' => $unitCents, 'total_cents' => $totalCents];

        if ($acceptedQty > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('purchase_history', 'purchase history');
            $this->fiscalPeriodGuard->assertOpenNow('stock_movements', 'stock movement');
        }
        if ($damagedQty > 0) {
            $this->fiscalPeriodGuard->assertOpenNow('inventory_adjustments', 'inventory adjustment');
        }

        if ($discrepancyType === 'none') {
            if ($damagedQty > 0) {
                $discrepancyType = 'damaged';
                $discrepancyQty = $damagedQty + $rejectedQty;
            } elseif ($rejectedQty > 0) {
                $discrepancyType = 'other';
                $discrepancyQty = $rejectedQty;
                if ($discrepancyNotes === '') {
                    $discrepancyNotes = 'Rejected goods excluded on receipt';
                }
            }
        } elseif ($rejectedQty > 0 && $discrepancyQty === 0) {
            $discrepancyQty = $rejectedQty;
        }

        $groupId = isset($data['group_id']) && (int)$data['group_id'] > 0 ? (int)$data['group_id'] : null;

        $hasPullout = $this->hasColumn('stock_receiving', 'pullout_id');
        if ($pulloutId !== null && !$hasPullout) {
            throw new RuntimeException('Linked supplier pullouts require the latest database schema.');
        }

        $pulloutCol = $hasPullout ? 'pullout_id, ' : '';
        $pulloutPlaceholder = $hasPullout ? '?, ' : '';

        $hasPacks = $this->hasColumn('stock_receiving', 'received_packs');
        $packCols = $hasPacks ? ', received_packs, accepted_packs, damaged_packs, rejected_packs, received_loose, accepted_loose, damaged_loose, rejected_loose, pack_size_used' : '';
        $packPlaceholders = $hasPacks ? ', ?, ?, ?, ?, ?, ?, ?, ?, ?' : '';
        $costCols = $hasCostBasis ? ', entered_cost, cost_basis, cost_units_used' : '';
        $costPlaceholders = $hasCostBasis ? ', ?, ?, ?' : '';

        $stmt = $this->pdo->prepare("INSERT INTO stock_receiving (
                                   group_id, {$pulloutCol}product_id, received_qty, received_packages, units_per_package_used, accepted_qty, damaged_qty, cost_price, total_cost, received_by,
                                   supplier, invoice_number,
                                   batch_number, expiration_date, discrepancy_type, discrepancy_qty,
                                   discrepancy_notes, notes{$packCols}{$costCols}
                               ) VALUES (?, {$pulloutPlaceholder}?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?{$packPlaceholders}{$costPlaceholders})");

        $params = [$groupId];
        if ($hasPullout) {
            $params[] = $pulloutId;
        }
        $params = array_merge($params, [
            $productId, $receivedQty, $receivedPackages, $unitsPerPackageUsed, $acceptedQty, $damagedQty,
            $costPrice, $totalCost, $userId,
            $supplier !== '' ? $supplier : null,
            $invoiceNumber !== '' ? $invoiceNumber : null, $batchNumber !== '' ? $batchNumber : null,
            $expirationDate, $discrepancyType, $discrepancyQty,
            $discrepancyNotes !== '' ? $discrepancyNotes : null, $notes,
        ]);
        if ($hasPacks) {
            $params = array_merge($params, [
                $receivedPacks, $acceptedPacks, $damagedPacks, $rejectedPacks,
                $receivedLoose, $acceptedLoose, $damagedLoose, $rejectedLoose, $packSizeUsed
            ]);
        }
        if ($hasCostBasis) array_push($params, $costInput !== '' ? $costInput : null, $cost['cost_basis'], $cost['cost_units_used']);
        $stmt->execute($params);

        $receivingId = (int)$this->pdo->lastInsertId();
        if ($acceptedQty > 0) {
            (new \App\Services\BatchInventoryLedger($this->pdo, $this->fiscalPeriodGuard))->creditReceiving(
                compact('productId', 'receivingId', 'acceptedQty', 'acceptedPacks', 'acceptedLoose', 'packSizeUsed',
                    'costPrice', 'batchNumber', 'expirationDate', 'supplier'), $userId
            );

            $this->pdo->prepare('UPDATE products SET quantity_purchased = quantity_purchased + ? WHERE product_id = ?')
                ->execute([$acceptedQty, $productId]);

            if ($costPrice !== null) {
                $this->pdo->prepare(
                    "INSERT INTO purchase_history (product_id, supplier, quantity_purchased, cost_price, total_cost, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$productId, $supplier !== '' ? $supplier : null, $acceptedQty, $costPrice, $totalCost, $userId]);
            }
        }

        if ($damagedQty > 0) {
            $damageReason = 'Damaged upon delivery';
            if ($batchNumber !== '') {
                $damageReason .= " (batch {$batchNumber})";
            }
            if ($discrepancyNotes !== '') {
                $damageReason .= ': ' . $discrepancyNotes;
            }
            // Bought damage was excluded before stock was added; no later deduction is due.
            $this->pdo->prepare(
                "INSERT INTO inventory_adjustments (product_id, adjustment_qty, adjustment_type, reported_by, reason, status, review_notes)
                 VALUES (?, ?, 'damaged', ?, ?, ?, ?)"
            )->execute([$productId, -$damagedQty, $userId, $damageReason,
                'cancelled',
                "Receiving #{$receivingId}: excluded from accepted stock; no stock deduction required."]);
        }

        $recordingStmt = $this->pdo->prepare('SELECT sr.received_at, u.display_name FROM stock_receiving sr JOIN users u ON u.user_id = sr.received_by WHERE sr.receiving_id = ?');
        $recordingStmt->execute([$receivingId]);
        $recording = $recordingStmt->fetch(PDO::FETCH_ASSOC);
        return [
            'group_id' => $groupId,
            'pullout_id' => $pulloutId,
            'product_name' => $product['product_name'],
            'recorded_by' => $recording['display_name'],
            'received_at' => $recording['received_at'],
            'receiving_id' => $receivingId,
            'received_qty' => $receivedQty,
            'damaged_qty' => $damagedQty,
            'rejected_qty' => $rejectedQty,
            'discrepancy_type' => $discrepancyType,
            'discrepancy_qty' => $discrepancyQty,
            'received_packages' => $receivedPackages,
            'units_per_package_used' => $unitsPerPackageUsed,
            'received_packs' => $receivedPacks,
            'accepted_packs' => $acceptedPacks,
            'damaged_packs' => $damagedPacks,
            'rejected_packs' => $rejectedPacks,
            'received_loose' => $receivedLoose,
            'accepted_loose' => $acceptedLoose,
            'damaged_loose' => $damagedLoose,
            'rejected_loose' => $rejectedLoose,
            'pack_size_used' => $packSizeUsed,
        ] + $cost;
    }

    public function getActiveProducts(): array
    {
        [$scopeSql, $scopeParams] = $this->productScope();
        $knownSelect = $this->hasColumn('products', 'cost_known') ? 'p.cost_known' : '0 AS cost_known';
        $stmt = $this->pdo->prepare("SELECT p.product_id, p.sku, p.product_name, p.cost_price, {$knownSelect}, p.units_per_package, p.pack_size, p.pack_barcode, p.pack_price, p.base_unit, p.receiving_unit, p.category_id, c.category_name, p.barcode, p.case_barcode, p.variant_label, p.product_image, COALESCE(i.quantity_on_hand, 0) AS quantity_on_hand, p.reorder_level, p.safety_stock FROM products p LEFT JOIN categories c ON p.category_id = c.category_id LEFT JOIN inventory i ON p.product_id = i.product_id WHERE p.status = 'active'{$scopeSql} ORDER BY p.product_name");
        $stmt->execute($scopeParams);
        $products = $stmt->fetchAll();
        foreach ($products as &$product) $product['cost_conversions'] = self::costConversions($product);
        unset($product);
        return $products;
    }

    public function getReceivingHistory(?int $userId): array
    {
        $pulloutSelect = $this->hasColumn('stock_receiving', 'pullout_id') ? 'sr.pullout_id,' : 'NULL AS pullout_id,';
        $costSelect = $this->historyCostColumns();
        $packSelect = $this->hasColumn('stock_receiving', 'received_packs')
            ? 'sr.received_packs, sr.accepted_packs, sr.damaged_packs, sr.rejected_packs, sr.received_loose, sr.accepted_loose, sr.damaged_loose, sr.rejected_loose, sr.pack_size_used,'
            : '0 AS received_packs, 0 AS accepted_packs, 0 AS damaged_packs, 0 AS rejected_packs, sr.received_qty AS received_loose, sr.accepted_qty AS accepted_loose, sr.damaged_qty AS damaged_loose, 0 AS rejected_loose, NULL AS pack_size_used,';
        $stmt = $this->pdo->prepare("SELECT sr.receiving_id, sr.group_id, {$pulloutSelect} {$packSelect} sr.received_qty, sr.accepted_qty, sr.damaged_qty,
                               {$costSelect} sr.cost_price, CASE WHEN sr.cost_price IS NOT NULL OR sr.total_cost > 0 THEN sr.total_cost END AS total_cost,
                               sr.received_packages, sr.units_per_package_used, sr.batch_number, sr.expiration_date, sr.discrepancy_type,
                               sr.discrepancy_qty, sr.received_at, sr.supplier, sr.invoice_number,
                               p.sku, p.product_name, u.display_name
                        FROM stock_receiving sr
                        JOIN products p ON sr.product_id = p.product_id
                        JOIN users u ON sr.received_by = u.user_id
                        WHERE (? IS NULL OR sr.received_by = ?)
                        ORDER BY sr.received_at DESC, sr.receiving_id DESC
                        LIMIT 20");
        $stmt->execute([$userId, $userId]);
        return $stmt->fetchAll();
    }

    private function historyCostColumns(): string
    {
        return $this->hasColumn('stock_receiving', 'cost_basis')
            ? 'sr.entered_cost, sr.cost_basis, sr.cost_units_used,'
            : 'NULL AS entered_cost, NULL AS cost_basis, NULL AS cost_units_used,';
    }

    private const HISTORY_ENTRY_KEY = "CASE WHEN sr.group_id IS NULL THEN CONCAT('r_', sr.receiving_id) ELSE CONCAT('g_', sr.group_id) END";
    private const HISTORY_FROM = 'FROM stock_receiving sr
        LEFT JOIN stock_receiving_groups srg ON srg.group_id = sr.group_id
        JOIN products p ON sr.product_id = p.product_id
        JOIN users u ON sr.received_by = u.user_id';
    private const HISTORY_ORDER = 'entry_time DESC, display_id DESC, entry_key DESC';

    private function matchingReceivingEntriesSql(): string
    {
        return 'SELECT ' . self::HISTORY_ENTRY_KEY . ' AS entry_key,
                    MAX(COALESCE(srg.received_at, sr.received_at)) AS entry_time,
                    MAX(COALESCE(sr.group_id, sr.receiving_id)) AS display_id
                ' . self::HISTORY_FROM . "
                WHERE (? IS NULL OR sr.received_by = ?)
                GROUP BY entry_key
                HAVING MAX(LOCATE(LOWER(?), LOWER(CONCAT_WS(' ', p.product_name, p.sku,
                    COALESCE(srg.supplier, sr.supplier), COALESCE(srg.invoice_number, sr.invoice_number),
                    u.display_name, sr.received_at, sr.batch_number, sr.discrepancy_notes, sr.notes, srg.notes)))) > 0";
    }

    public function getReceivingHistoryNeighbors(?int $userId, string $search, string $entryKey): ?array
    {
        $matches = $this->matchingReceivingEntriesSql();
        $order = self::HISTORY_ORDER;
        $stmt = $this->pdo->prepare("SELECT previous_entry, next_entry FROM (
                SELECT entry_key, LAG(entry_key) OVER (ORDER BY {$order}) AS previous_entry,
                    LEAD(entry_key) OVER (ORDER BY {$order}) AS next_entry
                FROM ({$matches}) matching_entries
            ) ordered_entries WHERE entry_key = ?");
        $stmt->execute([$userId, $userId, $search, $entryKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Returns a scoped entry by key, or one filtered page plus one next-page probe. */
    public function getGroupedReceivingHistory(?int $userId = null, int $page = 1, string $search = '', int $pageSize = 20, ?string $entryKey = null): array
    {
        $pageSize = min(100, max(1, $pageSize));
        $offset = (min(100000, max(1, $page)) - 1) * $pageSize;
        $limit = $pageSize + 1;
        $from = self::HISTORY_FROM;
        $key = self::HISTORY_ENTRY_KEY;
        $order = self::HISTORY_ORDER;
        $scope = [$userId, $userId];
        // Fetch a complete entry by its stable key, even if new entries have moved it to another page.
        $pageJoin = $entryKey === null
            ? 'JOIN (' . $this->matchingReceivingEntriesSql() . " ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}) history_page ON history_page.entry_key = {$key}"
            : '';
        $entryFilter = $entryKey === null ? '' : "AND {$key} = ?";
        $pulloutSelect = $this->hasColumn('stock_receiving', 'pullout_id') ? 'sr.pullout_id,' : 'NULL AS pullout_id,';
        $costSelect = $this->historyCostColumns();
        $allocationSelect = $this->hasColumn('stock_receiving', 'current_product_cost')
            ? 'sr.quantity_mode, sr.cost_source, sr.current_product_cost,'
            : 'NULL AS quantity_mode, NULL AS cost_source, NULL AS current_product_cost,';
        $packSelect = $this->hasColumn('stock_receiving', 'received_packs')
            ? 'sr.received_packs, sr.accepted_packs, sr.damaged_packs, sr.rejected_packs, sr.received_loose, sr.accepted_loose, sr.damaged_loose, sr.rejected_loose, sr.pack_size_used,'
            : '0 AS received_packs, 0 AS accepted_packs, 0 AS damaged_packs, 0 AS rejected_packs, sr.received_qty AS received_loose, sr.accepted_qty AS accepted_loose, sr.damaged_qty AS damaged_loose, 0 AS rejected_loose, NULL AS pack_size_used,';
        $stmt = $this->pdo->prepare("SELECT
                sr.receiving_id, sr.group_id, {$pulloutSelect} {$packSelect} {$allocationSelect} sr.product_id, sr.received_qty, sr.received_packages,
                sr.units_per_package_used, sr.accepted_qty, sr.damaged_qty,
                {$costSelect} sr.cost_price, sr.total_cost, sr.received_by, sr.received_at,
                sr.supplier, sr.invoice_number, sr.batch_number, sr.expiration_date,
                sr.discrepancy_type, sr.discrepancy_qty, sr.discrepancy_notes, sr.notes,
                p.sku, p.product_name,
                srg.group_id AS srg_group_id, srg.received_by AS group_received_by,
                srg.received_at AS group_received_at, srg.supplier AS group_supplier,
                srg.invoice_number AS group_invoice, srg.notes AS group_notes,
                u.display_name AS recorded_by
            {$from}
            {$pageJoin}
            WHERE (? IS NULL OR sr.received_by = ?) {$entryFilter}
            ORDER BY COALESCE(srg.received_at, sr.received_at) DESC, COALESCE(sr.group_id, sr.receiving_id) DESC,
                {$key} DESC, sr.receiving_id ASC");
        $stmt->execute($entryKey === null ? [...$scope, $search, ...$scope] : [...$scope, $entryKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $groups = [];
        $totals = [];
        $productTotals = [];
        foreach ($rows as $row) {
            $hasGroup = $row['group_id'] !== null;
            $groupKey = $hasGroup ? 'g_' . (int)$row['group_id'] : 'r_' . (int)$row['receiving_id'];
            $displayId = $hasGroup ? (int)$row['group_id'] : (int)$row['receiving_id'];
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'entry_key' => $groupKey,
                    'group_id' => $displayId,
                    'has_explicit_group' => $hasGroup,
                    'recorded_by' => $row['recorded_by'],
                    'received_by' => (int)$row['received_by'],
                    'received_at' => $row['group_received_at'] ?? $row['received_at'],
                    'supplier' => $row['group_supplier'] ?? $row['supplier'],
                    'invoice_number' => $row['group_invoice'] ?? $row['invoice_number'],
                    'notes' => $row['group_notes'] ?? $row['notes'],
                    'total_received_qty' => 0,
                    'total_accepted_qty' => 0,
                    'total_damaged_qty' => 0,
                    'has_unknown_cost' => false,
                    'lines' => [],
                ];
            }
            $groups[$groupKey]['total_received_qty'] += (int)$row['received_qty'];
            $groups[$groupKey]['total_accepted_qty'] += (int)$row['accepted_qty'];
            $groups[$groupKey]['total_damaged_qty'] += (int)$row['damaged_qty'];
            $productId = (int)$row['product_id'];
            $productTotals[$groupKey][$productId] ??= ['cents' => null, 'has_unknown_cost' => false];
            $hasRecordedTotal = $row['cost_price'] !== null || (float)$row['total_cost'] > 0;
            if ($hasRecordedTotal) {
                $cents = self::cents((string)$row['total_cost']);
                $totals[$groupKey] = ($totals[$groupKey] ?? 0) + $cents;
                $productTotals[$groupKey][$productId]['cents'] = ($productTotals[$groupKey][$productId]['cents'] ?? 0) + $cents;
            } elseif ((int)$row['accepted_qty'] > 0) {
                $groups[$groupKey]['has_unknown_cost'] = true;
                $productTotals[$groupKey][$productId]['has_unknown_cost'] = true;
            }
            $groups[$groupKey]['lines'][] = [
                'receiving_id' => (int)$row['receiving_id'],
                'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
                'pullout_id' => $row['pullout_id'] !== null ? (int)$row['pullout_id'] : null,
                'product_id' => (int)$row['product_id'],
                'product_name' => $row['product_name'],
                'sku' => $row['sku'],
                'received_qty' => (int)$row['received_qty'],
                'received_packages' => (int)$row['received_packages'],
                'units_per_package_used' => (int)$row['units_per_package_used'],
                'quantity_mode' => $row['quantity_mode'] ?? ((int)$row['received_packages'] > 0 ? 'packages' : ((int)($row['pack_size_used'] ?? 0) > 1 ? 'packs' : 'units')),
                'cost_source' => $row['cost_source'],
                'current_product_cost' => $row['current_product_cost'] !== null ? (float)$row['current_product_cost'] : null,
                'accepted_qty' => (int)$row['accepted_qty'],
                'damaged_qty' => (int)$row['damaged_qty'],
                'received_packs' => (int)($row['received_packs'] ?? 0),
                'accepted_packs' => (int)($row['accepted_packs'] ?? 0),
                'damaged_packs' => (int)($row['damaged_packs'] ?? 0),
                'rejected_packs' => (int)($row['rejected_packs'] ?? 0),
                'received_loose' => (int)($row['received_loose'] ?? 0),
                'accepted_loose' => (int)($row['accepted_loose'] ?? 0),
                'damaged_loose' => (int)($row['damaged_loose'] ?? 0),
                'rejected_loose' => (int)($row['rejected_loose'] ?? 0),
                'pack_size_used' => isset($row['pack_size_used']) && $row['pack_size_used'] !== null ? (int)$row['pack_size_used'] : null,
                'cost_price' => $row['cost_price'] !== null ? (float)$row['cost_price'] : null,
                'total_cost' => $hasRecordedTotal ? (float)$row['total_cost'] : null,
                'entered_cost' => $row['entered_cost'] !== null ? (float)$row['entered_cost'] : null,
                'cost_basis' => $row['cost_basis'],
                'cost_units_used' => $row['cost_units_used'] !== null ? (int)$row['cost_units_used'] : null,
                'rounding_difference' => (int)$row['cost_units_used'] > 1 && $row['cost_price'] !== null
                    && self::cents((string)$row['cost_price']) * (int)$row['accepted_qty'] !== self::cents((string)$row['total_cost']),
                'batch_number' => $row['batch_number'],
                'expiration_date' => $row['expiration_date'],
                'discrepancy_type' => $row['discrepancy_type'],
                'discrepancy_qty' => (int)$row['discrepancy_qty'],
                'discrepancy_notes' => $row['discrepancy_notes'],
                'notes' => $row['notes'],
                'supplier' => $row['supplier'],
                'invoice_number' => $row['invoice_number'],
                'received_at' => $row['received_at'],
            ];
        }

        foreach ($groups as $key => &$group) {
            $group['total_cost'] = isset($totals[$key]) ? (float)self::decimal($totals[$key]) : null;
            foreach ($productTotals[$key] as $id => $productTotal) {
                $group['product_costs'][$id] = [
                    'total_cost' => $productTotal['cents'] !== null ? (float)self::decimal($productTotal['cents']) : null,
                    'has_unknown_cost' => $productTotal['has_unknown_cost'],
                ];
            }
        }
        unset($group);
        return array_values($groups);
    }

    public function countGroupedReceivingHistory(?int $userId = null, string $search = ''): int
    {
        $matches = $this->matchingReceivingEntriesSql();
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ({$matches}) receiving_history");
        $stmt->execute([$userId, $userId, $search]);
        return (int)$stmt->fetchColumn();
    }

    private function wholeNumber($value, string $label): int
    {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/^\d+$/D', (string)$value)
            || strlen((string)$value) > 10 || (float)$value > 2147483647) {
            throw new RuntimeException("{$label} must be a non-negative whole number within range.");
        }
        return (int)$value;
    }

    private function text($value, string $label, int $maxLength): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || strlen((string)$value) > $maxLength) {
            throw new RuntimeException("{$label} must be text no longer than {$maxLength} bytes.");
        }
        return trim((string)$value);
    }

    private function productScope(): array
    {
        if (function_exists('store_product_scope')) {
            return store_product_scope('p');
        }

        return ['', []];
    }
}
