<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use App\Backup\DatabaseBackupService;
use App\Backup\RecoveryStore;
use App\Store\StoreScope;
use App\Store\StoreWriteGate;
use DomainException;
use PDO;
use Throwable;

final class OperationalDataResetService
{
    // Child records precede parents so foreign-key checks stay enabled.
    public const TABLES = [
        'forecast_decisions', 'cash_refund_exception_access', 'refund_receipt_details',
        'cash_refund_items', 'cash_refunds', 'sale_reversal_items', 'sale_reversals',
        'sale_receipt_details', 'sale_item_batches', 'held_sales', 'checkout_attempts',
        'sale_items', 'sales', 'inventory_adjustment_revisions', 'inventory_counts',
        'stock_movements', 'inventory_adjustments', 'product_batches', 'stock_receiving',
        'purchase_history', 'purchase_order_items', 'purchase_orders', 'replenishment_requests',
        'cash_drawer_movements', 'cashier_shifts', 'cycle_count_schedules', 'barcode_scans',
        'stock_predictions', 'forecast_evaluations', 'forecast_runs', 'model_training_runs',
        'forecast_sales_imports', 'attention_states', 'promotions', 'supplier_products', 'inventory', 'products',
    ];

    public function __construct(private PDO $pdo, private RoleCapabilityPolicy $policy, private ?string $modelDirectory = null)
    {
    }

    public function summary(): array
    {
        $existing = $this->tables();
        $counts = [];
        foreach (self::TABLES as $table) {
            $filter = $table === 'promotions' ? ' WHERE product_id IS NOT NULL' : '';
            $counts[$table] = isset($existing[$table]) ? (int)$this->pdo->query("SELECT COUNT(*) FROM `{$table}`{$filter}")->fetchColumn() : 0;
        }
        $counts['inventory_units'] = (int)$this->pdo->query('SELECT COALESCE(SUM(quantity_on_hand), 0) FROM inventory')->fetchColumn();
        return $counts;
    }

    public function blockers(): array
    {
        $blockers = [];
        if ((int)$this->pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status <> 'closed'")->fetchColumn() > 0) {
            $blockers[] = 'Close all cashier shifts before resetting data.';
        }
        if ((int)$this->pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status IN ('closed', 'locked')")->fetchColumn() > 0
            || (int)$this->pdo->query('SELECT COUNT(*) FROM fiscal_period_locks')->fetchColumn() > 0) {
            $blockers[] = 'Fiscal records are protected. Reopen and unlock fiscal periods before resetting data.';
        }
        return $blockers;
    }

    public function deleteProduct(int $actorId, string $role, int $productId, string $password, string $confirmation): string
    {
        if (!$this->policy->allows($role, RoleCapabilityPolicy::RESET_OPERATIONAL_DATA)) {
            throw new DomainException('Only an Administrator or Super Administrator can delete products.');
        }
        if ($confirmation !== 'DELETE PRODUCT') throw new DomainException('Type DELETE PRODUCT to confirm.');
        $actor = $this->pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? AND status = 'active'");
        $actor->execute([$actorId]);
        $hash = $actor->fetchColumn();
        if (!$hash || !password_verify($password, $hash)) throw new DomainException('Your current password is incorrect.');
        $storeId = (new StoreScope($this->pdo))->id();
        StoreWriteGate::begin($this->pdo);
        try {
            $statement = $this->pdo->prepare('SELECT * FROM products WHERE product_id = ? AND branch_id = ? FOR UPDATE');
            $statement->execute([$productId, $storeId]);
            $product = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$product) throw new DomainException('Product not found in this store.');
            $stock = $this->pdo->prepare('SELECT quantity_on_hand FROM inventory WHERE product_id = ? FOR UPDATE');
            $stock->execute([$productId]);
            $preserve = (int)$stock->fetchColumn() !== 0;
            $references = $this->pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'products'")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($references as $reference) {
                if (in_array($reference['TABLE_NAME'], ['inventory', 'supplier_products', 'promotions'], true)) continue;
                $table = str_replace('`', '``', $reference['TABLE_NAME']);
                $column = str_replace('`', '``', $reference['COLUMN_NAME']);
                $exists = $this->pdo->prepare("SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1");
                $exists->execute([$productId]);
                if ($exists->fetchColumn()) $preserve = true;
            }
            if ($preserve) {
                $this->pdo->prepare("UPDATE products SET status = 'inactive' WHERE product_id = ?")->execute([$productId]);
            } else {
                foreach (['promotions', 'supplier_products', 'inventory', 'products'] as $table) {
                    $this->pdo->prepare("DELETE FROM `{$table}` WHERE product_id = ?")->execute([$productId]);
                }
            }
            \log_activity($this->pdo, $actorId, $preserve ? 'Product deactivated' : 'Product deleted', 'Products', $productId, $product, $preserve ? ['status' => 'inactive'] : null);
            $this->pdo->commit();
            return $preserve ? 'Product made inactive because it has stock or linked records. Its history was preserved.' : 'Product deleted. Other products and their data were preserved.';
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function reset(int $actorId, string $role, string $password, string $confirmation): array
    {
        if (!$this->policy->allows($role, RoleCapabilityPolicy::RESET_OPERATIONAL_DATA)) {
            throw new DomainException('Only an Administrator or Super Administrator can reset data.');
        }
        if ($confirmation !== 'RESET ALL DATA') throw new DomainException('Type RESET ALL DATA to confirm.');
        $actor = $this->pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? AND status = 'active'");
        $actor->execute([$actorId]);
        $hash = $actor->fetchColumn();
        if (!$hash || !password_verify($password, $hash)) throw new DomainException('Your current password is incorrect.');
        (new StoreScope($this->pdo))->id();
        if ($this->blockers()) throw new DomainException(implode(' ', $this->blockers()));
        $tables = $this->tables();
        foreach (array_merge(self::TABLES, ['inventory', 'users', 'activity_log']) as $table) {
            if (isset($tables[$table]) && strtoupper((string)$tables[$table]) !== 'INNODB') {
                throw new DomainException('Data reset requires transactional database tables. No data was reset.');
            }
        }

        $requestLock = RecoveryStore::exclusive();
        $forecastLock = false;
        try {
            $forecastLock = (int)$this->pdo->query("SELECT GET_LOCK('retailmind_forecast_pipeline', 0)")->fetchColumn() === 1;
            if (!$forecastLock) throw new DomainException('Forecast training is running. Wait for it to finish, then try again.');
            $blockers = $this->blockers();
            if ($blockers) throw new DomainException(implode(' ', $blockers));
            $backup = (new DatabaseBackupService($this->pdo, $this->policy))->create($actorId, $role);
            if ($backup['status'] !== DatabaseBackupService::OUTCOME_READY) {
                throw new DomainException('A backup is already running. Wait for it to finish before resetting data.');
            }
            StoreWriteGate::begin($this->pdo);
            try {
                $before = $this->summary();
                foreach (self::TABLES as $table) {
                    $filter = $table === 'promotions' ? ' WHERE product_id IS NOT NULL' : '';
                    if (isset($tables[$table])) $this->pdo->exec("DELETE FROM `{$table}`{$filter}");
                }
                $this->pdo->prepare('UPDATE users SET session_version = session_version + 1 WHERE user_id <> ?')->execute([$actorId]);
                \log_activity($this->pdo, $actorId, 'Operational data reset', 'Data Reset', null, $before,
                    ['backup_id' => $backup['backup_id']], null, AuditRecordCategory::RECOVERY);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
            $warnings = [];
            foreach (['demand_model.joblib', 'model_metrics.json'] as $filename) {
                $path = ($this->modelDirectory ?? dirname(__DIR__, 2) . '/legacy/demandForcasting') . '/' . $filename;
                if (is_file($path) && !unlink($path)) {
                    $warnings[] = 'An old model file could not be removed. It has been invalidated and cannot be used.';
                }
            }
            return ['backup' => $backup, 'removed' => $before, 'warnings' => array_unique($warnings)];
        } finally {
            if ($forecastLock) $this->pdo->query("SELECT RELEASE_LOCK('retailmind_forecast_pipeline')");
            fclose($requestLock);
        }
    }

    private function tables(): array
    {
        $tables = [];
        foreach ($this->pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $table) {
            $tables[$table['TABLE_NAME']] = $table['ENGINE'];
        }
        return $tables;
    }
}
