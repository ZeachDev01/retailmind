<?php
// app/Services/BatchAllocationEngine.php

namespace App\Services;

use PDO;
use Throwable;
use DateTimeImmutable;
use DateTimeZone;

require_once __DIR__ . '/PhilippineTime.php';

/**
 * Deep domain module for FEFO batch allocations, sealed/loose splits, and automatic pack opening.
 */
class BatchAllocationEngine
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Plans FEFO batch allocation for combined pack and piece demand, including automatic pack opening.
     */
    public function planCombinedDemand(
        int $productId,
        string $productName,
        int $packDemand,
        int $pieceDemand,
        ?int $packSize,
        ?float $productCost = null,
        bool $hasPackConfig = false,
        int $quantityOnHand = 0
    ): array {
        if ($packDemand > 0 && !$hasPackConfig) {
            return [
                'conflicts' => [[
                    'product_id' => $productId,
                    'selling_form' => 'pack',
                    'product_name' => $productName,
                    'requested_qty' => $packDemand,
                    'available_stock' => 0,
                ]],
                'allocated_packs' => 0,
                'allocated_pieces' => 0,
                'product_plan' => null,
                'pack_batch_plan' => [],
                'piece_batch_plan' => [],
                'batch_state' => [],
            ];
        }

        try {
            $curDate = $this->pdo->query('SELECT CURDATE()')->fetchColumn();
            $todayManila = $curDate ? (string)$curDate : (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
        } catch (Throwable) {
            $todayManila = (new DateTimeImmutable('now', new DateTimeZone(PhilippineTime::ZONE)))->format('Y-m-d');
        }
        $productBatchColumns = self::getTableColumns($this->pdo, 'product_batches');
        $stockReceivingColumns = self::getTableColumns($this->pdo, 'stock_receiving');

        $sealedSelect = isset($productBatchColumns['sealed_quantity']) ? 'COALESCE(pb.sealed_quantity, 0)' : '0';
        $looseSelect = isset($productBatchColumns['loose_quantity']) ? 'COALESCE(pb.loose_quantity, pb.remaining_quantity)' : 'pb.remaining_quantity';

        $selectCols = [
            'pb.batch_id',
            'pb.remaining_quantity',
            "{$sealedSelect} AS sealed_quantity",
            "{$looseSelect} AS loose_quantity",
            'pb.expiration_date',
            'pb.date_received',
        ];

        if (isset($productBatchColumns['cost_price'])) {
            $selectCols[] = 'pb.cost_price AS batch_cost_price';
        } else {
            $selectCols[] = 'NULL AS batch_cost_price';
        }

        $hasSrJoin = false;
        if (isset($productBatchColumns['receiving_id']) && !empty($stockReceivingColumns)) {
            $hasSrJoin = true;
            $selectCols[] = isset($stockReceivingColumns['cost_price']) ? 'sr.cost_price AS receiving_cost_price' : 'NULL AS receiving_cost_price';
            $selectCols[] = isset($stockReceivingColumns['total_cost']) ? 'sr.total_cost AS receiving_total_cost' : 'NULL AS receiving_total_cost';
            $selectCols[] = isset($stockReceivingColumns['accepted_qty']) ? 'sr.accepted_qty AS receiving_accepted_qty' : 'NULL AS receiving_accepted_qty';
            $selectCols[] = isset($stockReceivingColumns['received_qty']) ? 'sr.received_qty AS receiving_received_qty' : 'NULL AS receiving_received_qty';
        } else {
            $selectCols[] = 'NULL AS receiving_cost_price';
            $selectCols[] = 'NULL AS receiving_total_cost';
            $selectCols[] = 'NULL AS receiving_accepted_qty';
            $selectCols[] = 'NULL AS receiving_received_qty';
        }

        $rowLock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $sql = "SELECT " . implode(', ', $selectCols) . "
                FROM product_batches pb ";
        if ($hasSrJoin) {
            $sql .= "LEFT JOIN stock_receiving sr ON sr.receiving_id = pb.receiving_id ";
        }
        $sql .= "WHERE pb.product_id = ?
                   AND pb.remaining_quantity > 0
                   AND (pb.expiration_date IS NULL OR pb.expiration_date >= ?)
                 ORDER BY
                   CASE WHEN pb.expiration_date IS NULL THEN 1 ELSE 0 END,
                   pb.expiration_date ASC,
                   pb.date_received ASC,
                   pb.batch_id ASC" . $rowLock;

        $eligibleBatchesStmt = $this->pdo->prepare($sql);
        $eligibleBatchesStmt->execute([$productId, $todayManila]);
        $eligibleBatches = $eligibleBatchesStmt->fetchAll(PDO::FETCH_ASSOC);

        $stateSealed = isset($productBatchColumns['sealed_quantity']) ? 'sealed_quantity' : '0 AS sealed_quantity';
        $stateLoose = isset($productBatchColumns['loose_quantity']) ? 'loose_quantity' : 'remaining_quantity AS loose_quantity';
        $batchStateStmt = $this->pdo->prepare(
            "SELECT batch_id, remaining_quantity, {$stateSealed}, {$stateLoose}, expiration_date, date_received
             FROM product_batches
             WHERE product_id = ? AND remaining_quantity > 0
             ORDER BY batch_id" . $rowLock
        );
        $batchStateStmt->execute([$productId]);
        $batchStateRows = $batchStateStmt->fetchAll(PDO::FETCH_ASSOC);

        $hasBatches = count($batchStateRows) > 0;

        if ($packDemand > 0 && !$hasBatches) {
            return [
                'conflicts' => [[
                    'product_id' => $productId,
                    'selling_form' => 'pack',
                    'product_name' => $productName,
                    'requested_qty' => $packDemand,
                    'available_stock' => 0,
                ]],
                'allocated_packs' => 0,
                'allocated_pieces' => 0,
                'product_plan' => null,
                'pack_batch_plan' => [],
                'piece_batch_plan' => [],
                'batch_state' => [],
            ];
        }

        if (!$hasBatches) {
            $conflicts = [];
            if ($quantityOnHand < $pieceDemand) {
                $conflicts[] = [
                    'product_id' => $productId,
                    'selling_form' => 'piece',
                    'product_name' => $productName,
                    'requested_qty' => $pieceDemand,
                    'available_stock' => max(0, $quantityOnHand),
                ];
            }
            return [
                'conflicts' => $conflicts,
                'allocated_packs' => 0,
                'allocated_pieces' => min($pieceDemand, max(0, $quantityOnHand)),
                'product_plan' => [
                    'product_id' => $productId,
                    'pack_demand' => 0,
                    'piece_demand' => $pieceDemand,
                    'total_base_units' => $pieceDemand,
                    'sealed_allocations' => [],
                    'loose_allocations' => [],
                    'pack_openings' => [],
                ],
                'pack_batch_plan' => [],
                'piece_batch_plan' => [],
                'batch_state' => [],
            ];
        }

        $batches = [];
        foreach ($eligibleBatches as $b) {
            $batches[$b['batch_id']] = [
                'batch_id' => (int)$b['batch_id'],
                'sealed_quantity' => (int)$b['sealed_quantity'],
                'loose_quantity' => (int)$b['loose_quantity'],
                'remaining_quantity' => (int)$b['remaining_quantity'],
                'expiration_date' => $b['expiration_date'],
                'batch_cost_price' => $b['batch_cost_price'],
                'receiving_cost_price' => $b['receiving_cost_price'],
                'receiving_total_cost' => $b['receiving_total_cost'],
                'receiving_accepted_qty' => $b['receiving_accepted_qty'],
                'receiving_received_qty' => $b['receiving_received_qty'],
            ];
        }

        $remainingPackDemand = $packDemand;
        $sealedAllocations = [];
        $packBatchPlan = [];
        foreach ($batches as &$batch) {
            if ($remainingPackDemand <= 0) break;
            $takePacks = min($remainingPackDemand, $batch['sealed_quantity']);
            if ($takePacks > 0) {
                $baseUnitQty = $takePacks * ($packSize ?? 1);
                $sealedAllocations[] = ['batch_id' => $batch['batch_id'], 'packs' => $takePacks, 'base_units' => $baseUnitQty];
                $costInfo = self::resolveBatchCost($batch, $productCost);
                $packBatchPlan[] = array_merge([
                    'batch_id' => $batch['batch_id'],
                    'quantity' => $baseUnitQty,
                    'available_quantity' => $batch['remaining_quantity'],
                    'expiration_date' => $batch['expiration_date'],
                ], $costInfo);

                $batch['sealed_quantity'] -= $takePacks;
                $remainingPackDemand -= $takePacks;
            }
        }
        unset($batch);

        $allocatedPacks = $packDemand - $remainingPackDemand;

        $remainingPieceDemand = $pieceDemand;
        $looseAllocations = [];
        $pieceBatchPlan = [];
        foreach ($batches as &$batch) {
            if ($remainingPieceDemand <= 0) break;
            $takePieces = min($remainingPieceDemand, $batch['loose_quantity']);
            if ($takePieces > 0) {
                $looseAllocations[] = ['batch_id' => $batch['batch_id'], 'pieces' => $takePieces];
                $costInfo = self::resolveBatchCost($batch, $productCost);
                $pieceBatchPlan[] = array_merge([
                    'batch_id' => $batch['batch_id'],
                    'quantity' => $takePieces,
                    'available_quantity' => $batch['remaining_quantity'],
                    'expiration_date' => $batch['expiration_date'],
                ], $costInfo);

                $batch['loose_quantity'] -= $takePieces;
                $remainingPieceDemand -= $takePieces;
            }
        }
        unset($batch);

        $packOpenings = [];
        if ($remainingPieceDemand > 0 && $hasPackConfig && $packSize > 0) {
            $packsNeeded = (int)ceil($remainingPieceDemand / $packSize);
            $remainingPacksToOpen = $packsNeeded;

            foreach ($batches as &$batch) {
                if ($remainingPacksToOpen <= 0) break;
                $takePacks = min($remainingPacksToOpen, $batch['sealed_quantity']);
                if ($takePacks > 0) {
                    $piecesGenerated = $takePacks * $packSize;
                    $piecesConsumed = min($remainingPieceDemand, $piecesGenerated);
                    $leftoverLoose = $piecesGenerated - $piecesConsumed;

                    $packOpenings[] = [
                        'batch_id' => $batch['batch_id'],
                        'packs' => $takePacks,
                        'pieces_opened' => $piecesGenerated,
                        'pieces_consumed' => $piecesConsumed,
                        'leftover_loose' => $leftoverLoose,
                    ];

                    $costInfo = self::resolveBatchCost($batch, $productCost);
                    $pieceBatchPlan[] = array_merge([
                        'batch_id' => $batch['batch_id'],
                        'quantity' => $piecesConsumed,
                        'available_quantity' => $batch['remaining_quantity'],
                        'expiration_date' => $batch['expiration_date'],
                    ], $costInfo);

                    $batch['sealed_quantity'] -= $takePacks;
                    $batch['loose_quantity'] += $leftoverLoose;
                    $remainingPieceDemand -= $piecesConsumed;
                    $remainingPacksToOpen -= $takePacks;
                }
            }
            unset($batch);
        }

        $allocatedPieces = $pieceDemand - $remainingPieceDemand;

        $conflicts = [];
        if ($remainingPackDemand > 0) {
            $conflicts[] = [
                'product_id' => $productId,
                'selling_form' => 'pack',
                'product_name' => $productName,
                'requested_qty' => $packDemand,
                'available_stock' => $allocatedPacks,
            ];
        }
        if ($remainingPieceDemand > 0) {
            $conflicts[] = [
                'product_id' => $productId,
                'selling_form' => 'piece',
                'product_name' => $productName,
                'requested_qty' => $pieceDemand,
                'available_stock' => $allocatedPieces,
            ];
        }

        return [
            'conflicts' => $conflicts,
            'allocated_packs' => $allocatedPacks,
            'allocated_pieces' => $allocatedPieces,
            'product_plan' => [
                'product_id' => $productId,
                'pack_demand' => $packDemand,
                'piece_demand' => $pieceDemand,
                'total_base_units' => ($packDemand * ($packSize ?? 1)) + $pieceDemand,
                'sealed_allocations' => $sealedAllocations,
                'loose_allocations' => $looseAllocations,
                'pack_openings' => $packOpenings,
            ],
            'pack_batch_plan' => $packBatchPlan,
            'piece_batch_plan' => $pieceBatchPlan,
            'batch_state' => $batchStateRows,
        ];
    }

    public static function resolveBatchCost(array $batch, ?float $productCost): array
    {
        $unitCost = null;
        $costStatus = 'missing';
        $costSource = null;

        if ($batch['batch_cost_price'] !== null && $batch['batch_cost_price'] !== '') {
            $unitCost = round((float)$batch['batch_cost_price'], 2);
            $costStatus = 'recorded';
            $costSource = 'batch';
        } elseif ($batch['receiving_cost_price'] !== null && $batch['receiving_cost_price'] !== '') {
            $unitCost = round((float)$batch['receiving_cost_price'], 2);
            $costStatus = 'recorded';
            $costSource = 'receiving';
        } elseif ($batch['receiving_total_cost'] !== null && (float)$batch['receiving_total_cost'] > 0) {
            $acceptedQty = (int)($batch['receiving_accepted_qty'] ?: ($batch['receiving_received_qty'] ?: 0));
            if ($acceptedQty > 0) {
                $unitCost = round((float)$batch['receiving_total_cost'] / $acceptedQty, 2);
                $costStatus = 'recorded';
                $costSource = 'receiving';
            }
        } elseif ($productCost !== null && $productCost > 0) {
            $unitCost = round((float)$productCost, 2);
            $costStatus = 'estimated';
            $costSource = 'product';
        }

        return [
            'unit_cost' => $unitCost,
            'cost_price' => $unitCost,
            'cost_status' => $costStatus,
            'cost_source' => $costSource,
            'cost_provenance' => $costSource ?? $costStatus,
        ];
    }

    public static function getTableColumns(PDO $pdo, string $table): array
    {
        static $cache = [];
        $key = spl_object_hash($pdo) . ':' . $table;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $cols = [];
        try {
            if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $rawCols = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawCols as $col) {
                    $cols[$col['Field']] = true;
                }
            } else {
                $rawCols = $pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rawCols as $col) {
                    $cols[$col['name']] = true;
                }
            }
        } catch (Throwable) {
            $cols = [];
        }

        return $cache[$key] = $cols;
    }
}
