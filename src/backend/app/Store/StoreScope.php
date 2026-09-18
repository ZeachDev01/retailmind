<?php

namespace App\Store;

use PDO;
use RuntimeException;
use Throwable;

final class StoreScope
{
    public const COMPATIBILITY_NAME = 'RetailMind Store';
    public const COMPATIBILITY_CODE = 'RETAILMIND-STORE';

    public function __construct(private PDO $pdo)
    {
    }

    public function preflight(): array
    {
        $branches = $this->branchInventory();
        $dataBearing = array_values(array_filter(
            $branches,
            static fn(array $branch): bool => $branch['user_count'] > 0 || $branch['product_count'] > 0
        ));

        if (count($dataBearing) > 1) {
            return [
                'status' => 'consolidation_required',
                'store_id' => null,
                'branches' => $dataBearing,
            ];
        }

        if (count($dataBearing) === 1) {
            $branch = $dataBearing[0];
            if ($branch['status'] !== 'active') {
                return [
                    'status' => 'consolidation_required',
                    'store_id' => null,
                    'branches' => $dataBearing,
                ];
            }

            return [
                'status' => 'ready',
                'store_id' => $branch['branch_id'],
                'branches' => [$branch],
            ];
        }

        $active = array_values(array_filter(
            $branches,
            static fn(array $branch): bool => $branch['status'] === 'active'
        ));
        if (count($active) === 1) {
            return [
                'status' => 'ready',
                'store_id' => $active[0]['branch_id'],
                'branches' => [$active[0]],
            ];
        }

        if ($branches === []) {
            return [
                'status' => 'create_required',
                'store_id' => null,
                'branches' => [],
            ];
        }

        return [
            'status' => 'consolidation_required',
            'store_id' => null,
            'branches' => $branches,
        ];
    }

    public function id(): int
    {
        $report = $this->preflight();
        if ($report['status'] === 'ready') {
            return (int)$report['store_id'];
        }
        if ($report['status'] === 'consolidation_required') {
            throw new StoreConsolidationRequired($report);
        }

        throw new RuntimeException('The singleton Store compatibility migration has not been applied.');
    }

    public function migrate(): int
    {
        $report = $this->preflight();
        if ($report['status'] === 'ready') {
            return (int)$report['store_id'];
        }
        if ($report['status'] === 'consolidation_required') {
            throw new StoreConsolidationRequired($report);
        }

        return $this->createCompatibilityRecord();
    }

    private function createCompatibilityRecord(): int
    {
        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            // Recheck under the transaction so concurrent migration attempts remain idempotent.
            $report = $this->preflight();
            if ($report['status'] === 'ready') {
                $storeId = (int)$report['store_id'];
            } elseif ($report['status'] === 'consolidation_required') {
                throw new StoreConsolidationRequired($report);
            } else {
                $statement = $this->pdo->prepare(
                    "INSERT INTO branches (branch_name, branch_code, status) VALUES (?, ?, 'active')"
                );
                $statement->execute([self::COMPATIBILITY_NAME, self::COMPATIBILITY_CODE]);
                $storeId = (int)$this->pdo->lastInsertId();
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }
            return $storeId;
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function branchInventory(): array
    {
        $statement = $this->pdo->query(
            'SELECT b.branch_id, b.branch_name, b.branch_code, b.status,
                (SELECT COUNT(*) FROM users u WHERE u.branch_id = b.branch_id) AS user_count,
                (SELECT COUNT(*) FROM products p WHERE p.branch_id = b.branch_id) AS product_count
             FROM branches b
             ORDER BY b.branch_id'
        );
        if ($statement === false) {
            throw new RuntimeException('Unable to inspect the internal Store compatibility scope.');
        }

        return array_map(
            static fn(array $branch): array => [
                'branch_id' => (int)$branch['branch_id'],
                'branch_name' => (string)$branch['branch_name'],
                'branch_code' => (string)$branch['branch_code'],
                'status' => (string)$branch['status'],
                'user_count' => (int)$branch['user_count'],
                'product_count' => (int)$branch['product_count'],
            ],
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
