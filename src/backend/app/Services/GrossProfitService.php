<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;

/**
 * Gross Profit calculation and reporting service.
 * Gross Profit = Net Sales - Cost of Products Sold.
 * Reconciled against Cash Refunds, discounts, and batch cost snapshots.
 */
class GrossProfitService
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_ESTIMATED = 'estimated';
    public const STATUS_INCOMPLETE = 'incomplete';

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Enforces that the active workspace is Administrator.
     * Cashier, Inventory Manager, and Super Administrator workspaces are denied.
     */
    public static function enforceAdministratorWorkspace(?string $activeRole = null): void
    {
        $role = $activeRole ?? (\function_exists('current_role') ? \current_role() : ($_SESSION['role'] ?? null));
        if ($role !== 'admin') {
            if (\function_exists('http_response_code') && !headers_sent()) {
                \http_response_code(403);
            }
            throw new DomainException('Gross profit reporting requires an active Administrator workspace.');
        }
    }

    /**
     * Resolves and validates date filters according to Asia/Manila boundaries.
     *
     * @return array{from: string, to: string, from_start: string, to_end: string, as_of: string}
     */
    public function resolveDateRange(array $filters): array
    {
        $tz = new DateTimeZone(PhilippineTime::ZONE);
        $asOf = $filters['as_of'] ?? null;
        if ($asOf instanceof DateTimeImmutable) {
            $nowPh = $asOf->setTimezone($tz);
        } elseif (is_string($asOf) && $asOf !== '') {
            $nowPh = new DateTimeImmutable($asOf, $tz);
        } else {
            $nowPh = new DateTimeImmutable('now', $tz);
        }

        $period = (string)($filters['period'] ?? '');
        if ($period === 'today') {
            $from = $nowPh->format('Y-m-d');
            $to = $nowPh->format('Y-m-d');
        } elseif ($period === 'week') {
            $dayOfWeek = (int)$nowPh->format('N'); // 1 = Monday
            $daysFromMonday = $dayOfWeek - 1;
            $from = $nowPh->modify("-{$daysFromMonday} days")->format('Y-m-d');
            $to = $nowPh->format('Y-m-d');
        } elseif ($period === 'month') {
            $from = $nowPh->setDate((int)$nowPh->format('Y'), (int)$nowPh->format('n'), 1)->format('Y-m-d');
            $to = $nowPh->format('Y-m-d');
        } else {
            $from = trim((string)($filters['from'] ?? ''));
            $to = trim((string)($filters['to'] ?? ''));
            if ($from === '') {
                $from = $nowPh->setDate((int)$nowPh->format('Y'), (int)$nowPh->format('n'), 1)->format('Y-m-d');
            }
            if ($to === '') {
                $to = $nowPh->format('Y-m-d');
            }
        }

        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if ($fromDate === false || $fromDate->format('Y-m-d') !== $from
            || $toDate === false || $toDate->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Choose a valid Philippine calendar date.');
        }

        if (strcmp($from, $to) > 0) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }

        $fromStart = $from . ' 00:00:00';
        $toEnd = $to . ' 23:59:59';
        $todayStr = $nowPh->format('Y-m-d');
        $nowStr = $nowPh->format('Y-m-d H:i:s');

        // Activity only through current time if to >= today
        if (strcmp($to, $todayStr) >= 0) {
            $effectiveToEnd = strcmp($toEnd, $nowStr) > 0 ? $nowStr : $toEnd;
        } else {
            $effectiveToEnd = $toEnd;
        }

        return [
            'from' => $from,
            'to' => $to,
            'from_start' => $fromStart,
            'to_end' => $effectiveToEnd,
            'as_of' => $nowStr,
        ];
    }

    /**
     * Executes the gross profit calculation and returns raw structured result.
     */
    public function calculate(array $filters): array
    {
        $range = $this->resolveDateRange($filters);
        $storeId = max(1, (int)($filters['store_id'] ?? 1));
        $productId = max(0, (int)($filters['product_id'] ?? 0));
        $categoryId = max(0, (int)($filters['category_id'] ?? 0));

        // 1. Fetch completed sales in period
        $salesWhere = ['s.sale_date >= ?', 's.sale_date <= ?'];
        $salesParams = [$range['from_start'], $range['to_end']];

        $filterExists = 'EXISTS (
            SELECT 1 FROM sale_items si_f
            JOIN products p_f ON p_f.product_id = si_f.product_id
            WHERE si_f.sale_id = s.sale_id AND p_f.branch_id = ?';
        $filterParams = [$storeId];
        if ($productId > 0) {
            $filterExists .= ' AND p_f.product_id = ?';
            $filterParams[] = $productId;
        }
        if ($categoryId > 0) {
            $filterExists .= ' AND p_f.category_id = ?';
            $filterParams[] = $categoryId;
        }
        $filterExists .= ')';
        $salesWhere[] = $filterExists;

        $salesStmt = $this->pdo->prepare(
            'SELECT s.sale_id, s.total_amount, s.gross_amount, s.sale_date
             FROM sales s
             WHERE ' . implode(' AND ', $salesWhere) . '
             ORDER BY s.sale_date ASC, s.sale_id ASC'
        );
        $salesStmt->execute(array_merge($salesParams, $filterParams));
        $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

        $saleIds = array_map(static fn($s) => (int)$s['sale_id'], $sales);

        $recoverHistorical = !empty($filters['recover_historical']) || !empty($filters['establish_historical']);
        if ($recoverHistorical && !empty($saleIds)) {
            $this->establishHistoricalCosts($saleIds, [
                'store_id' => $storeId,
                'fallback_to_product_cost' => $filters['fallback_to_product_cost'] ?? true,
            ]);
        }

        // Fetch all lines for these sales to calculate sale-level discount allocations
        $saleLinesBySale = [];
        $saleItemsMap = [];
        if (!empty($saleIds)) {
            $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
            $linesStmt = $this->pdo->prepare(
                "SELECT si.sale_item_id, si.sale_id, si.product_id, si.quantity, si.unit_price, si.subtotal,
                        p.sku, COALESCE(p.barcode, p.sku) AS barcode_or_sku, p.product_name, p.category_id, p.branch_id,
                        COALESCE(c.category_name, 'Uncategorized') AS category_name
                 FROM sale_items si
                 JOIN products p ON p.product_id = si.product_id
                 LEFT JOIN categories c ON c.category_id = p.category_id
                 WHERE si.sale_id IN ({$placeholders})
                 ORDER BY si.sale_id ASC, si.sale_item_id ASC"
            );
            $linesStmt->execute($saleIds);
            foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $sId = (int)$line['sale_id'];
                $siId = (int)$line['sale_item_id'];
                $saleLinesBySale[$sId][] = $line;
                $saleItemsMap[$siId] = $line;
            }
        }

        // Allocate discounts across lines for each sale in integer cents
        $settledSoldLines = [];
        foreach ($sales as $sale) {
            $sId = (int)$sale['sale_id'];
            $lines = $saleLinesBySale[$sId] ?? [];
            if (empty($lines)) {
                continue;
            }
            $grossCents = 0;
            foreach ($lines as $l) {
                $grossCents += (int)round((float)$l['subtotal'] * 100);
            }
            $paidCents = (int)round((float)$sale['total_amount'] * 100);
            $cumulativeGross = 0;
            $allocatedCents = 0;
            foreach ($lines as $line) {
                $cumulativeGross += (int)round((float)$line['subtotal'] * 100);
                $cumulativeNet = $grossCents > 0
                    ? (int)round($paidCents * $cumulativeGross / $grossCents) : 0;
                $netCents = $cumulativeNet - $allocatedCents;
                $allocatedCents = $cumulativeNet;

                // Check if line matches scope filters
                if ((int)$line['branch_id'] !== $storeId) {
                    continue;
                }
                if ($productId > 0 && (int)$line['product_id'] !== $productId) {
                    continue;
                }
                if ($categoryId > 0 && (int)$line['category_id'] !== $categoryId) {
                    continue;
                }

                $subtotalCents = (int)round((float)$line['subtotal'] * 100);
                $discountCents = $subtotalCents - $netCents;

                $line['net_cents'] = $netCents;
                $line['net_sales'] = $netCents / 100.0;
                $line['discount_cents'] = $discountCents;
                $line['discount_amount'] = $discountCents / 100.0;
                $settledSoldLines[(int)$line['sale_item_id']] = $line;
            }
        }

        // 2. Fetch sale_item_batches allocations for settled sold lines
        $soldLineAllocations = [];
        if (!empty($settledSoldLines)) {
            $siIds = array_keys($settledSoldLines);
            $placeholders = implode(',', array_fill(0, count($siIds), '?'));
            $sibCols = $this->getTableColumns('sale_item_batches');
            $costStatusCol = isset($sibCols['cost_status']) ? 'sib.cost_status' : "'recorded' AS cost_status";
            $unitCostCol = isset($sibCols['unit_cost']) ? 'sib.unit_cost' : (isset($sibCols['cost_price']) ? 'sib.cost_price AS unit_cost' : 'NULL AS unit_cost');

            $sibStmt = $this->pdo->prepare(
                "SELECT sib.sale_item_batch_id, sib.sale_item_id, sib.batch_id, sib.quantity,
                        {$unitCostCol}, {$costStatusCol}
                 FROM sale_item_batches sib
                 WHERE sib.sale_item_id IN ({$placeholders})
                 ORDER BY sib.sale_item_id ASC, sib.sale_item_batch_id ASC"
            );
            $sibStmt->execute($siIds);
            foreach ($sibStmt->fetchAll(PDO::FETCH_ASSOC) as $alloc) {
                $soldLineAllocations[(int)$alloc['sale_item_id']][] = $alloc;
            }
        }

        // 3. Fetch cash refunds issued in period
        $refundWhere = ['cr.created_at >= ?', 'cr.created_at <= ?', 'p.branch_id = ?'];
        $refundParams = [$range['from_start'], $range['to_end'], $storeId];
        if ($productId > 0) {
            $refundWhere[] = 'p.product_id = ?';
            $refundParams[] = $productId;
        }
        if ($categoryId > 0) {
            $refundWhere[] = 'p.category_id = ?';
            $refundParams[] = $categoryId;
        }

        $refundStmt = $this->pdo->prepare(
            'SELECT cr.refund_id, cr.sale_id, cr.created_at,
                    cri.refund_item_id, cri.sale_item_id, cri.product_id, cri.quantity,
                    cri.unit_price, cri.subtotal, cri.disposition,
                    p.sku, COALESCE(p.barcode, p.sku) AS barcode_or_sku, p.product_name, p.category_id, p.branch_id,
                    COALESCE(c.category_name, ' . "'Uncategorized'" . ') AS category_name
             FROM cash_refunds cr
             JOIN cash_refund_items cri ON cri.refund_id = cr.refund_id
             JOIN products p ON p.product_id = cri.product_id
             LEFT JOIN categories c ON c.category_id = p.category_id
             WHERE ' . implode(' AND ', $refundWhere) . '
             ORDER BY cr.created_at ASC, cr.refund_id ASC, cri.refund_item_id ASC'
        );
        $refundStmt->execute($refundParams);
        $periodRefundItems = $refundStmt->fetchAll(PDO::FETCH_ASSOC);

        // For all refund items in period, we need to map them against all-time allocations of their sale_item_id
        $refundSaleItemIds = array_values(array_unique(array_filter(array_map(
            static fn($r) => (int)$r['sale_item_id'],
            $periodRefundItems
        ))));

        $allTimeRefundsBySaleItem = [];
        $allTimeAllocationsBySaleItem = [];
        if (!empty($refundSaleItemIds)) {
            $placeholders = implode(',', array_fill(0, count($refundSaleItemIds), '?'));

            // Fetch all-time refunds for these sale_items
            $allRefundsStmt = $this->pdo->prepare(
                "SELECT cr.refund_id, cr.created_at, cri.refund_item_id, cri.sale_item_id, cri.quantity, cri.disposition
                 FROM cash_refunds cr
                 JOIN cash_refund_items cri ON cri.refund_id = cr.refund_id
                 WHERE cri.sale_item_id IN ({$placeholders})
                 ORDER BY cr.created_at ASC, cr.refund_id ASC, cri.refund_item_id ASC"
            );
            $allRefundsStmt->execute($refundSaleItemIds);
            foreach ($allRefundsStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $allTimeRefundsBySaleItem[(int)$ar['sale_item_id']][] = $ar;
            }

            // Fetch all-time allocations for these sale_items
            $sibCols = $this->getTableColumns('sale_item_batches');
            $costStatusCol = isset($sibCols['cost_status']) ? 'sib.cost_status' : "'recorded' AS cost_status";
            $unitCostCol = isset($sibCols['unit_cost']) ? 'sib.unit_cost' : (isset($sibCols['cost_price']) ? 'sib.cost_price AS unit_cost' : 'NULL AS unit_cost');

            $allAllocStmt = $this->pdo->prepare(
                "SELECT sib.sale_item_batch_id, sib.sale_item_id, sib.batch_id, sib.quantity,
                        {$unitCostCol}, {$costStatusCol}
                 FROM sale_item_batches sib
                 WHERE sib.sale_item_id IN ({$placeholders})
                 ORDER BY sib.sale_item_id ASC, sib.sale_item_batch_id ASC"
            );
            $allAllocStmt->execute($refundSaleItemIds);
            foreach ($allAllocStmt->fetchAll(PDO::FETCH_ASSOC) as $aa) {
                $allTimeAllocationsBySaleItem[(int)$aa['sale_item_id']][] = $aa;
            }
        }

        // Map reversed costs for refund items in the current period
        $periodRefundReversals = []; // indexed by refund_item_id
        $periodRefundItemIds = array_flip(array_map(static fn($r) => (int)$r['refund_item_id'], $periodRefundItems));

        foreach ($refundSaleItemIds as $siId) {
            $allocs = $allTimeAllocationsBySaleItem[$siId] ?? [];
            $refunds = $allTimeRefundsBySaleItem[$siId] ?? [];

            // Build unit slices from allocations
            $allocationSlices = [];
            foreach ($allocs as $al) {
                $q = (int)$al['quantity'];
                $uCost = $al['unit_cost'] !== null ? (float)$al['unit_cost'] : null;
                $st = (string)($al['cost_status'] ?? 'missing');
                if ($uCost === null) {
                    $st = self::STATUS_INCOMPLETE;
                }
                for ($u = 0; $u < $q; $u++) {
                    $allocationSlices[] = [
                        'unit_cost' => $uCost,
                        'status' => $st,
                    ];
                }
            }

            $consumedUnits = 0;
            foreach ($refunds as $rf) {
                $rfId = (int)$rf['refund_item_id'];
                $rfQty = (int)$rf['quantity'];
                $isRestockable = ($rf['disposition'] ?? '') === 'restockable';
                $isInPeriod = isset($periodRefundItemIds[$rfId]);

                $reversedCost = 0.0;
                $reversalStatus = self::STATUS_RECORDED;
                $reversalMissing = false;
                $reversalEstimated = false;

                for ($u = 0; $u < $rfQty; $u++) {
                    $sliceIndex = $consumedUnits + $u;
                    if (isset($allocationSlices[$sliceIndex])) {
                        $slice = $allocationSlices[$sliceIndex];
                        if ($isRestockable) {
                            if ($slice['unit_cost'] === null || $slice['status'] === self::STATUS_INCOMPLETE) {
                                $reversalMissing = true;
                            } else {
                                $reversedCost += $slice['unit_cost'];
                                if ($slice['status'] === self::STATUS_ESTIMATED) {
                                    $reversalEstimated = true;
                                }
                            }
                        }
                    } else {
                        // Sold unit had no allocation in batches
                        if ($isRestockable) {
                            $reversalMissing = true;
                        }
                    }
                }

                $consumedUnits += $rfQty;

                if ($isInPeriod) {
                    if ($reversalMissing) {
                        $reversalStatus = self::STATUS_INCOMPLETE;
                    } elseif ($reversalEstimated) {
                        $reversalStatus = self::STATUS_ESTIMATED;
                    } else {
                        $reversalStatus = self::STATUS_RECORDED;
                    }

                    $periodRefundReversals[$rfId] = [
                        'reversed_cost' => $reversedCost,
                        'status' => $reversalStatus,
                        'sale_item_id' => $siId,
                    ];
                }
            }
        }

        // 4. Product Aggregation
        $productsMap = [];
        $affectedSoldLines = []; // sale_item_id => true for missing costs

        // Process settled sales
        foreach ($settledSoldLines as $siId => $line) {
            $pId = (int)$line['product_id'];
            if (!isset($productsMap[$pId])) {
                $productsMap[$pId] = [
                    'product_id' => $pId,
                    'sku' => $line['barcode_or_sku'],
                    'product_name' => $line['product_name'],
                    'category_name' => $line['category_name'],
                    'units_sold' => 0,
                    'units_refunded' => 0,
                    'gross_sales' => 0.0,
                    'discounts' => 0.0,
                    'refunds' => 0.0,
                    'sold_cost' => 0.0,
                    'reversed_cost' => 0.0,
                    'has_missing' => false,
                    'has_estimated' => false,
                ];
            }

            $productsMap[$pId]['units_sold'] += (int)$line['quantity'];
            $productsMap[$pId]['gross_sales'] += (float)$line['subtotal'];
            $productsMap[$pId]['discounts'] += (float)$line['discount_amount'];

            // Cost calculation for line
            $allocs = $soldLineAllocations[$siId] ?? [];
            $allocQty = 0;
            $lineCost = 0.0;
            $lineMissing = false;
            $lineEstimated = false;

            foreach ($allocs as $al) {
                $q = (int)$al['quantity'];
                $allocQty += $q;
                $uCost = $al['unit_cost'] !== null ? (float)$al['unit_cost'] : null;
                $st = (string)($al['cost_status'] ?? 'missing');

                if ($uCost === null || $st === self::STATUS_INCOMPLETE || $st === 'missing') {
                    $lineMissing = true;
                } else {
                    $lineCost += $q * $uCost;
                    if ($st === self::STATUS_ESTIMATED) {
                        $lineEstimated = true;
                    }
                }
            }

            if ($allocQty < (int)$line['quantity']) {
                $lineMissing = true;
            }

            if ($lineMissing) {
                $productsMap[$pId]['has_missing'] = true;
                $affectedSoldLines[$siId] = true;
            } elseif ($lineEstimated) {
                $productsMap[$pId]['has_estimated'] = true;
                $productsMap[$pId]['sold_cost'] += $lineCost;
            } else {
                $productsMap[$pId]['sold_cost'] += $lineCost;
            }
        }

        // Process period refunds
        foreach ($periodRefundItems as $rf) {
            $pId = (int)$rf['product_id'];
            $rfId = (int)$rf['refund_item_id'];
            if (!isset($productsMap[$pId])) {
                $productsMap[$pId] = [
                    'product_id' => $pId,
                    'sku' => $rf['barcode_or_sku'],
                    'product_name' => $rf['product_name'],
                    'category_name' => $rf['category_name'],
                    'units_sold' => 0,
                    'units_refunded' => 0,
                    'gross_sales' => 0.0,
                    'discounts' => 0.0,
                    'refunds' => 0.0,
                    'sold_cost' => 0.0,
                    'reversed_cost' => 0.0,
                    'has_missing' => false,
                    'has_estimated' => false,
                ];
            }

            $productsMap[$pId]['units_refunded'] += (int)$rf['quantity'];
            $productsMap[$pId]['refunds'] += (float)$rf['subtotal'];

            $rev = $periodRefundReversals[$rfId] ?? [
                'reversed_cost' => 0.0,
                'status' => self::STATUS_RECORDED,
                'sale_item_id' => (int)$rf['sale_item_id'],
            ];

            if ($rev['status'] === self::STATUS_INCOMPLETE) {
                $productsMap[$pId]['has_missing'] = true;
                $affectedSoldLines[(int)$rf['sale_item_id']] = true;
            } elseif ($rev['status'] === self::STATUS_ESTIMATED) {
                $productsMap[$pId]['has_estimated'] = true;
                $productsMap[$pId]['reversed_cost'] += (float)$rev['reversed_cost'];
            } else {
                $productsMap[$pId]['reversed_cost'] += (float)$rev['reversed_cost'];
            }
        }

        // Build product rows
        $rows = [];
        $totalUnits = 0;
        $totalGrossSales = 0.0;
        $totalDiscounts = 0.0;
        $totalRefunds = 0.0;
        $totalNetSales = 0.0;
        $totalNetCost = 0.0;
        $totalGrossProfit = 0.0;

        $hasAnyMissing = false;
        $hasAnyEstimated = false;

        $knownNetSales = 0.0;
        $knownNetCost = 0.0;
        $knownGrossProfit = 0.0;

        // Sort products by net sales DESC, then product_name ASC
        uasort($productsMap, static function ($a, $b) {
            $netA = round($a['gross_sales'] - $a['discounts'] - $a['refunds'], 2);
            $netB = round($b['gross_sales'] - $b['discounts'] - $b['refunds'], 2);
            if ($netA !== $netB) {
                return $netB <=> $netA;
            }
            return strcmp((string)$a['product_name'], (string)$b['product_name']);
        });

        foreach ($productsMap as $pId => $item) {
            $netUnits = $item['units_sold'] - $item['units_refunded'];
            $grossSales = round($item['gross_sales'], 2);
            $discounts = round($item['discounts'], 2);
            $refunds = round($item['refunds'], 2);
            $netSales = round($grossSales - $discounts - $refunds, 2);
            $netCost = round($item['sold_cost'] - $item['reversed_cost'], 2);
            $grossProfit = round($netSales - $netCost, 2);

            $status = self::STATUS_RECORDED;
            if ($item['has_missing']) {
                $status = self::STATUS_INCOMPLETE;
                $hasAnyMissing = true;
            } elseif ($item['has_estimated']) {
                $status = self::STATUS_ESTIMATED;
                $hasAnyEstimated = true;
            }

            $margin = null;
            if ($status === self::STATUS_RECORDED && $netSales > 0) {
                $margin = round(($grossProfit / $netSales) * 100, 2);
            }

            $totalUnits += $netUnits;
            $totalGrossSales += $grossSales;
            $totalDiscounts += $discounts;
            $totalRefunds += $refunds;
            $totalNetSales += $netSales;

            if ($status !== self::STATUS_INCOMPLETE) {
                $totalNetCost += $netCost;
                $totalGrossProfit += $grossProfit;

                $knownNetSales += $netSales;
                $knownNetCost += $netCost;
                $knownGrossProfit += $grossProfit;
            }

            $rows[] = [
                'product_id' => $pId,
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'category_name' => $item['category_name'],
                'units_sold' => (int)$item['units_sold'],
                'units_refunded' => (int)$item['units_refunded'],
                'net_units' => $netUnits,
                'gross_sales' => $grossSales,
                'discounts' => $discounts,
                'refunds' => $refunds,
                'net_sales' => $netSales,
                'net_cost' => $status === self::STATUS_INCOMPLETE ? null : $netCost,
                'gross_profit' => $status === self::STATUS_INCOMPLETE ? null : $grossProfit,
                'margin' => $margin,
                'status' => $status,
                'is_incomplete' => $status === self::STATUS_INCOMPLETE,
                'is_estimated' => $status === self::STATUS_ESTIMATED,
            ];
        }

        $overallStatus = self::STATUS_RECORDED;
        if ($hasAnyMissing) {
            $overallStatus = self::STATUS_INCOMPLETE;
        } elseif ($hasAnyEstimated) {
            $overallStatus = self::STATUS_ESTIMATED;
        }

        $totalMargin = null;
        if ($overallStatus === self::STATUS_RECORDED && $totalNetSales > 0) {
            $totalMargin = round(($totalGrossProfit / $totalNetSales) * 100, 2);
        }

        $knownMargin = null;
        if ($knownNetSales > 0 && !$hasAnyEstimated) {
            $knownMargin = round(($knownGrossProfit / $knownNetSales) * 100, 2);
        }

        $summary = [
            'date_range' => $range,
            'total_units' => $totalUnits,
            'total_gross_sales' => round($totalGrossSales, 2),
            'total_discounts' => round($totalDiscounts, 2),
            'total_refunds' => round($totalRefunds, 2),
            'total_net_sales' => round($totalNetSales, 2),
            'total_net_cost' => $overallStatus === self::STATUS_INCOMPLETE ? null : round($totalNetCost, 2),
            'total_gross_profit' => $overallStatus === self::STATUS_INCOMPLETE ? null : round($totalGrossProfit, 2),
            'total_margin' => $totalMargin,
            'status' => $overallStatus,
            'affected_lines_count' => count($affectedSoldLines),
            'known_net_sales' => round($knownNetSales, 2),
            'known_net_cost' => round($knownNetCost, 2),
            'known_gross_profit' => round($knownGrossProfit, 2),
            'known_margin' => $knownMargin,
            'is_empty' => empty($rows),
        ];

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * Report formatting for UI display and CSV export.
     */
    public function runReport(array $filters): array
    {
        self::enforceAdministratorWorkspace();

        try {
            $calculated = $this->calculate($filters);
        } catch (\Throwable $e) {
            if ($e instanceof DomainException || $e instanceof InvalidArgumentException) {
                throw $e;
            }
            return [
                'headers' => ['Status', 'Message'],
                'rows' => [
                    ['Status' => 'Unavailable', 'Message' => 'Gross profit report data is unavailable.']
                ],
                'metrics' => [
                    'Status' => 'Unavailable',
                ],
            ];
        }

        $summary = $calculated['summary'];
        $rawRows = $calculated['rows'];

        $headers = [
            'SKU',
            'Product',
            'Category',
            'Net Units',
            'Gross Sales',
            'Discounts',
            'Refunds',
            'Net Sales',
            'Net Cost',
            'Gross Profit',
            'Margin',
            'Status',
        ];

        $displayRows = [];
        foreach ($rawRows as $r) {
            $displayRows[] = [
                'SKU' => $r['sku'],
                'Product' => $r['product_name'],
                'Category' => $r['category_name'],
                'Net Units' => (int)$r['net_units'],
                'Gross Sales' => number_format((float)$r['gross_sales'], 2),
                'Discounts' => number_format((float)$r['discounts'], 2),
                'Refunds' => number_format((float)$r['refunds'], 2),
                'Net Sales' => number_format((float)$r['net_sales'], 2),
                'Net Cost' => $r['is_incomplete'] ? '-' : number_format((float)$r['net_cost'], 2),
                'Gross Profit' => $r['is_incomplete'] ? '-' : number_format((float)$r['gross_profit'], 2),
                'Margin' => $r['margin'] !== null ? number_format((float)$r['margin'], 2) . '%' : '-',
                'Status' => ucfirst((string)$r['status']),
                // Semantic aliases
                'Units Sold' => (int)$r['net_units'],
                'Revenue' => number_format((float)$r['net_sales'], 2),
                'Cost of Sales' => $r['is_incomplete'] ? '-' : number_format((float)$r['net_cost'], 2),
                'raw' => $r,
            ];
        }

        $metrics = [
            'Net Sales' => number_format((float)$summary['total_net_sales'], 2),
            'Net Cost' => $summary['status'] === self::STATUS_INCOMPLETE ? 'Incomplete' : number_format((float)$summary['total_net_cost'], 2),
            'Gross Profit' => $summary['status'] === self::STATUS_INCOMPLETE ? 'Incomplete' : number_format((float)$summary['total_gross_profit'], 2),
            'Margin' => $summary['total_margin'] !== null ? number_format((float)$summary['total_margin'], 2) . '%' : '-',
            'Status' => ucfirst((string)$summary['status']),
        ];

        if ($summary['status'] === self::STATUS_INCOMPLETE) {
            $metrics['Affected Lines'] = (string)$summary['affected_lines_count'];
            $metrics['Known Net Sales'] = number_format((float)$summary['known_net_sales'], 2);
            $metrics['Known Net Cost'] = number_format((float)$summary['known_net_cost'], 2);
            $metrics['Known Profit'] = number_format((float)$summary['known_gross_profit'], 2);
            $metrics['Known Margin'] = $summary['known_margin'] !== null ? number_format((float)$summary['known_margin'], 2) . '%' : '-';
        }

        return [
            'headers' => $headers,
            'rows' => $displayRows,
            'metrics' => $metrics,
            'summary' => $summary,
            'raw_rows' => $rawRows,
        ];
    }

    private function getTableColumns(string $table): array
    {
        static $cache = [];
        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $cols = [];
        $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $this->pdo->query("PRAGMA table_info({$table})");
            if ($stmt) {
                while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $cols[$col['name']] = true;
                }
            }
        } else {
            $stmt = $this->pdo->prepare('SHOW COLUMNS FROM ' . $table);
            $stmt->execute();
            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[$col['Field']] = true;
            }
        }

        return $cache[$table] = $cols;
    }

    /**
     * Establishes stable historical cost snapshots for older sales.
     *
     * Provenance hierarchy:
     * 1. Sale item batch allocation -> product_batches -> cost_price (recorded, batch)
     * 2. Product batch -> stock_receiving -> cost_price / total_cost normalized to base unit (recorded, receiving)
     * 3. Current product catalog cost_price > 0 (estimated, product)
     * 4. Null / zero default -> missing / incomplete (never assume zero)
     *
     * Unallocated quantities (opening stock / missing batch links) receive estimated
     * fallback or missing treatment and are persisted as explicit allocation snapshots.
     *
     * @param int[]|null $saleIds Optional specific sale IDs to establish.
     * @param array $options Options: ['store_id' => int, 'force' => bool, 'fallback_to_product_cost' => bool]
     * @return array{processed_sales: int, established_lines: int, recovered_allocations: int}
     */
    public function establishHistoricalCosts(?array $saleIds = null, array $options = []): array
    {
        $storeId = max(0, (int)($options['store_id'] ?? 0));
        $force = !empty($options['force']);
        $fallbackToProductCost = $options['fallback_to_product_cost'] ?? true;

        if ($saleIds !== null) {
            $targetSaleIds = array_values(array_filter(array_map('intval', $saleIds)));
        } else {
            $sql = "SELECT DISTINCT s.sale_id
                    FROM sales s
                    JOIN sale_items si ON si.sale_id = s.sale_id";
            $where = [];
            $params = [];
            if ($storeId > 0) {
                $sql .= " JOIN products p ON p.product_id = si.product_id";
                $where[] = "p.branch_id = ?";
                $params[] = $storeId;
            }
            if (!$force) {
                $where[] = "(
                    NOT EXISTS (SELECT 1 FROM sale_item_batches sib WHERE sib.sale_item_id = si.sale_item_id)
                    OR EXISTS (
                        SELECT 1 FROM sale_item_batches sib2
                        WHERE sib2.sale_item_id = si.sale_item_id
                          AND (sib2.unit_cost IS NULL AND (sib2.cost_source IS NULL OR sib2.cost_source = ''))
                    )
                    OR (
                        (SELECT COALESCE(SUM(sib3.quantity), 0) FROM sale_item_batches sib3 WHERE sib3.sale_item_id = si.sale_item_id) < si.quantity
                    )
                )";
            }
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
            $sql .= " ORDER BY s.sale_id ASC";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $targetSaleIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if (empty($targetSaleIds)) {
            return [
                'processed_sales' => 0,
                'established_lines' => 0,
                'recovered_allocations' => 0,
            ];
        }

        $sibCols = $this->getTableColumns('sale_item_batches');
        $hasUnitCost = isset($sibCols['unit_cost']);
        $hasCostPrice = isset($sibCols['cost_price']);
        $hasCostStatus = isset($sibCols['cost_status']);
        $hasCostSource = isset($sibCols['cost_source']);
        $hasCostProvenance = isset($sibCols['cost_provenance']);

        $processedSales = count($targetSaleIds);
        $establishedLines = 0;
        $recoveredCount = 0;
        $batchIdIsNullable = $this->isBatchIdNullable();

        foreach ($targetSaleIds as $sId) {
            $prodCols = $this->getTableColumns('products');
            $costCol = isset($prodCols['cost_price']) ? 'p.cost_price' : 'NULL AS cost_price';
            $branchCol = isset($prodCols['branch_id']) ? 'p.branch_id' : '1 AS branch_id';

            $itemStmt = $this->pdo->prepare(
                "SELECT si.sale_item_id, si.sale_id, si.product_id, si.quantity, si.unit_price, si.subtotal,
                        {$costCol} AS product_cost_price, {$branchCol} AS branch_id
                 FROM sale_items si
                 JOIN products p ON p.product_id = si.product_id
                 WHERE si.sale_id = ?
                 ORDER BY si.sale_item_id ASC"
            );
            $itemStmt->execute([$sId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as $si) {
                if ($storeId > 0 && (int)$si['branch_id'] !== $storeId) {
                    continue;
                }

                $siId = (int)$si['sale_item_id'];
                $lineQty = (int)$si['quantity'];
                $prodCost = $si['product_cost_price'] !== null && $si['product_cost_price'] !== ''
                    ? (float)$si['product_cost_price'] : null;

                $selectAllocCols = ['sib.sale_item_batch_id', 'sib.sale_item_id', 'sib.batch_id', 'sib.quantity'];
                if ($hasUnitCost) $selectAllocCols[] = 'sib.unit_cost';
                if ($hasCostStatus) $selectAllocCols[] = 'sib.cost_status';
                if ($hasCostSource) $selectAllocCols[] = 'sib.cost_source';
                if ($hasCostProvenance) $selectAllocCols[] = 'sib.cost_provenance';

                $allocStmt = $this->pdo->prepare(
                    "SELECT " . implode(', ', $selectAllocCols) . "
                     FROM sale_item_batches sib
                     WHERE sib.sale_item_id = ?
                     ORDER BY sib.sale_item_batch_id ASC"
                );
                $allocStmt->execute([$siId]);
                $existingAllocs = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

                $allocatedQty = 0;
                $lineTouched = false;

                foreach ($existingAllocs as $alloc) {
                    $allocatedQty += (int)$alloc['quantity'];
                    $sibId = (int)$alloc['sale_item_batch_id'];

                    if (!$force) {
                        $alreadyCosted = isset($alloc['unit_cost']) && $alloc['unit_cost'] !== null
                            && in_array($alloc['cost_status'] ?? '', ['recorded', 'estimated'], true);
                        $alreadySourced = !empty($alloc['cost_source']);
                        if ($alreadyCosted || $alreadySourced) {
                            continue;
                        }
                    }

                    $resolved = $this->resolveUnitProvenanceCost(
                        (int)$si['product_id'],
                        isset($alloc['batch_id']) && $alloc['batch_id'] !== null ? (int)$alloc['batch_id'] : null,
                        $prodCost,
                        $fallbackToProductCost
                    );

                    $updateCols = [];
                    $updateVals = [];
                    if ($hasUnitCost) { $updateCols[] = 'unit_cost = ?'; $updateVals[] = $resolved['unit_cost']; }
                    if ($hasCostPrice) { $updateCols[] = 'cost_price = ?'; $updateVals[] = $resolved['unit_cost']; }
                    if ($hasCostStatus) { $updateCols[] = 'cost_status = ?'; $updateVals[] = $resolved['cost_status']; }
                    if ($hasCostSource) { $updateCols[] = 'cost_source = ?'; $updateVals[] = $resolved['cost_source']; }
                    if ($hasCostProvenance) { $updateCols[] = 'cost_provenance = ?'; $updateVals[] = $resolved['cost_provenance']; }

                    if (!empty($updateCols)) {
                        $updateVals[] = $sibId;
                        $upStmt = $this->pdo->prepare("UPDATE sale_item_batches SET " . implode(', ', $updateCols) . " WHERE sale_item_batch_id = ?");
                        $upStmt->execute($updateVals);
                        $recoveredCount++;
                        $lineTouched = true;
                    }
                }

                // Check for unallocated quantities (opening stock / missing batch records)
                $unallocatedQty = $lineQty - $allocatedQty;
                if ($unallocatedQty > 0) {
                    $resolved = $this->resolveUnitProvenanceCost(
                        (int)$si['product_id'],
                        null,
                        $prodCost,
                        $fallbackToProductCost
                    );

                    $insertCols = ['sale_item_id', 'batch_id', 'quantity'];
                    $insertVals = [$siId, $batchIdIsNullable ? null : 0, $unallocatedQty];
                    $insertPlaceholders = ['?', '?', '?'];

                    if ($hasUnitCost) { $insertCols[] = 'unit_cost'; $insertPlaceholders[] = '?'; $insertVals[] = $resolved['unit_cost']; }
                    if ($hasCostPrice) { $insertCols[] = 'cost_price'; $insertPlaceholders[] = '?'; $insertVals[] = $resolved['unit_cost']; }
                    if ($hasCostStatus) { $insertCols[] = 'cost_status'; $insertPlaceholders[] = '?'; $insertVals[] = $resolved['cost_status']; }
                    if ($hasCostSource) { $insertCols[] = 'cost_source'; $insertPlaceholders[] = '?'; $insertVals[] = $resolved['cost_source']; }
                    if ($hasCostProvenance) { $insertCols[] = 'cost_provenance'; $insertPlaceholders[] = '?'; $insertVals[] = $resolved['cost_provenance']; }

                    $inStmt = $this->pdo->prepare("INSERT INTO sale_item_batches (" . implode(', ', $insertCols) . ") VALUES (" . implode(', ', $insertPlaceholders) . ")");
                    $inStmt->execute($insertVals);
                    $recoveredCount++;
                    $lineTouched = true;
                }

                if ($lineTouched) {
                    $establishedLines++;
                }
            }
        }

        return [
            'processed_sales' => $processedSales,
            'established_lines' => $establishedLines,
            'recovered_allocations' => $recoveredCount,
        ];
    }

    /**
     * Alias for establishHistoricalCosts.
     */
    public function recoverHistoricalCosts(?array $saleIds = null, array $options = []): array
    {
        return $this->establishHistoricalCosts($saleIds, $options);
    }

    /**
     * Resolves individual unit provenance cost from batch, receiving, or product estimate.
     *
     * @return array{unit_cost: ?float, cost_status: string, cost_source: ?string, cost_provenance: ?string}
     */
    public function resolveUnitProvenanceCost(
        int $productId,
        ?int $batchId,
        ?float $productCost,
        bool $fallbackToProductCost = true
    ): array {
        $unitCost = null;
        $costStatus = self::STATUS_INCOMPLETE;
        $costSource = null;
        $costProvenance = null;

        if ($batchId !== null && $batchId > 0) {
            $pbCols = $this->getTableColumns('product_batches');
            $costCol = isset($pbCols['cost_price']) ? 'pb.cost_price' : 'NULL AS cost_price';
            $recIdCol = isset($pbCols['receiving_id']) ? 'pb.receiving_id' : 'NULL AS receiving_id';

            $pbStmt = $this->pdo->prepare("SELECT pb.batch_id, {$costCol}, {$recIdCol} FROM product_batches pb WHERE pb.batch_id = ?");
            $pbStmt->execute([$batchId]);
            $batch = $pbStmt->fetch(PDO::FETCH_ASSOC);

            if ($batch) {
                // 1. Direct batch purchase cost
                if ($batch['cost_price'] !== null && $batch['cost_price'] !== '') {
                    $unitCost = round((float)$batch['cost_price'], 2);
                    $costStatus = self::STATUS_RECORDED;
                    $costSource = 'batch';
                    $costProvenance = 'batch';
                } elseif (!empty($batch['receiving_id'])) {
                    // 2. Receiving provenance
                    $srCols = $this->getTableColumns('stock_receiving');
                    if (!empty($srCols)) {
                        $srCostCol = isset($srCols['cost_price']) ? 'sr.cost_price' : 'NULL AS cost_price';
                        $srTotalCol = isset($srCols['total_cost']) ? 'sr.total_cost' : 'NULL AS total_cost';
                        $srAcceptedCol = isset($srCols['accepted_qty']) ? 'sr.accepted_qty' : 'NULL AS accepted_qty';
                        $srReceivedCol = isset($srCols['received_qty']) ? 'sr.received_qty' : 'NULL AS received_qty';

                        $srStmt = $this->pdo->prepare("SELECT {$srCostCol}, {$srTotalCol}, {$srAcceptedCol}, {$srReceivedCol} FROM stock_receiving sr WHERE sr.receiving_id = ?");
                        $srStmt->execute([(int)$batch['receiving_id']]);
                        $receiving = $srStmt->fetch(PDO::FETCH_ASSOC);

                        if ($receiving) {
                            $totalCost = $receiving['total_cost'] !== null && $receiving['total_cost'] !== '' ? (float)$receiving['total_cost'] : null;
                            $acceptedQty = (int)($receiving['accepted_qty'] ?: ($receiving['received_qty'] ?: 0));

                            if ($totalCost !== null && $totalCost > 0 && $acceptedQty > 0) {
                                $unitCost = round($totalCost / $acceptedQty, 2);
                                $costStatus = self::STATUS_RECORDED;
                                $costSource = 'receiving';
                                $costProvenance = 'receiving';
                            } elseif ($receiving['cost_price'] !== null && $receiving['cost_price'] !== '') {
                                $rawCost = (float)$receiving['cost_price'];
                                $unitCost = round($rawCost, 2);
                                $costStatus = self::STATUS_RECORDED;
                                $costSource = 'receiving';
                                $costProvenance = 'receiving';
                            }
                        }
                    }
                }
            }
        }

        // 3. Current product catalog cost fallback
        if ($costStatus === self::STATUS_INCOMPLETE && $fallbackToProductCost) {
            if ($productCost !== null && $productCost > 0) {
                $unitCost = round($productCost, 2);
                $costStatus = self::STATUS_ESTIMATED;
                $costSource = 'product';
                $costProvenance = $batchId ? 'batch_missing_source' : 'unallocated';
            } else {
                // Ambiguous zero default or null remains uncertain
                $unitCost = null;
                $costStatus = self::STATUS_INCOMPLETE;
                $costSource = 'missing';
                $costProvenance = $batchId ? 'batch_missing_source' : 'unallocated';
            }
        }

        return [
            'unit_cost' => $unitCost,
            'cost_status' => $costStatus === self::STATUS_INCOMPLETE ? 'missing' : $costStatus,
            'cost_source' => $costSource,
            'cost_provenance' => $costProvenance,
        ];
    }

    private function isBatchIdNullable(): bool
    {
        static $nullable = null;
        if ($nullable !== null) {
            return $nullable;
        }

        try {
            $driver = (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->query("PRAGMA table_info('sale_item_batches')");
                if ($stmt) {
                    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (($col['name'] ?? '') === 'batch_id') {
                            return $nullable = ((int)($col['notnull'] ?? 0) === 0);
                        }
                    }
                }
            } else {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM `sale_item_batches` LIKE 'batch_id'");
                if ($stmt) {
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row) {
                        return $nullable = (strtoupper($row['Null'] ?? '') === 'YES');
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $nullable = true;
    }
}

