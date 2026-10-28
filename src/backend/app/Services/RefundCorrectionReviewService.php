<?php

namespace App\Services;

use App\Audit\AuditRecordCategory;
use App\Authorization\RoleCapabilityPolicy;
use DomainException;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class RefundCorrectionReviewService
{
    public const STATUS_REVIEWED_OUTSTANDING = 'Reviewed—correction outstanding';
    public const STATUS_CORRECTED = 'Corrected';

    public const TYPE_CASH_DRAWER = 'cash_drawer';
    public const TYPE_STOCK_ONLY = 'stock_only';
    public const TYPE_COMBINED = 'combined';

    public const VALID_TYPES = [
        self::TYPE_CASH_DRAWER,
        self::TYPE_STOCK_ONLY,
        self::TYPE_COMBINED,
    ];

    private RoleCapabilityPolicy $policy;

    public function __construct(private PDO $pdo, ?RoleCapabilityPolicy $policy = null)
    {
        $this->policy = $policy ?? new RoleCapabilityPolicy();
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $isMysql = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        if ($isMysql) {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS `refund_correction_reviews` (
                    `review_id` INT AUTO_INCREMENT PRIMARY KEY,
                    `refund_id` INT NOT NULL,
                    `correction_type` VARCHAR(50) NOT NULL,
                    `status` VARCHAR(50) NOT NULL,
                    `cash_status` VARCHAR(50) NOT NULL,
                    `stock_status` VARCHAR(50) NOT NULL,
                    `explanation` TEXT NOT NULL,
                    `proposed_correction` TEXT NULL,
                    `reviewed_by` INT NOT NULL,
                    `reviewed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `responsible_cashier_id` INT NULL,
                    `responsible_inventory_manager_id` INT NULL,
                    `cash_drawer_movement_id` INT NULL,
                    `inventory_adjustment_id` INT NULL,
                    `completed_at` TIMESTAMP NULL DEFAULT NULL,
                    INDEX `idx_rcr_refund` (`refund_id`),
                    INDEX `idx_rcr_status` (`status`),
                    INDEX `idx_rcr_cashier` (`responsible_cashier_id`),
                    INDEX `idx_rcr_inventory` (`responsible_inventory_manager_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS refund_correction_reviews (
                    review_id INTEGER PRIMARY KEY AUTOINCREMENT,
                    refund_id INTEGER NOT NULL,
                    correction_type TEXT NOT NULL,
                    status TEXT NOT NULL,
                    cash_status TEXT NOT NULL,
                    stock_status TEXT NOT NULL,
                    explanation TEXT NOT NULL,
                    proposed_correction TEXT NULL,
                    reviewed_by INTEGER NOT NULL,
                    reviewed_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    responsible_cashier_id INTEGER NULL,
                    responsible_inventory_manager_id INTEGER NULL,
                    cash_drawer_movement_id INTEGER NULL,
                    inventory_adjustment_id INTEGER NULL,
                    completed_at TEXT NULL
                );
            ");
        }
    }

    public function createReview(
        int $actorId,
        string $actorRole,
        int $refundId,
        string $correctionType,
        ?int $responsibleCashierId,
        ?int $responsibleInventoryManagerId,
        string $explanation,
        ?array $proposedCorrection = null
    ): int {
        if ($actorRole !== 'admin') {
            throw new DomainException('Only the Administrator workspace can record a refund correction review.');
        }

        // Must have assigned admin role
        $roleCheck = $this->pdo->prepare("
            SELECT 1 FROM users u
            JOIN roles r ON r.role_id = u.role_id
            LEFT JOIN user_roles ur ON ur.user_id = u.user_id
            LEFT JOIN roles r2 ON r2.role_id = ur.role_id
            WHERE u.user_id = ? AND u.status = 'active'
              AND (r.role_name = 'admin' OR r2.role_name = 'admin')
            LIMIT 1
        ");
        $roleCheck->execute([$actorId]);
        if (!$roleCheck->fetchColumn()) {
            throw new DomainException('Operational Administrator assignment required.');
        }

        if (!in_array($correctionType, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException('Choose a valid correction type: cash_drawer, stock_only, or combined.');
        }

        $explanation = trim($explanation);
        if ($explanation === '' || mb_strlen($explanation) < 8) {
            throw new InvalidArgumentException('Provide a specific explanation of the refund mistake (at least 8 characters).');
        }

        // Verify refund exists
        $refundStmt = $this->pdo->prepare("SELECT refund_id, sale_id, cashier_id FROM cash_refunds WHERE refund_id = ?");
        $refundStmt->execute([$refundId]);
        $refund = $refundStmt->fetch(PDO::FETCH_ASSOC);
        if (!$refund) {
            throw new DomainException('The referenced cash refund does not exist.');
        }

        // Validate responsible roles based on correction type
        $cashStatus = 'not_required';
        $stockStatus = 'not_required';

        if ($correctionType === self::TYPE_CASH_DRAWER || $correctionType === self::TYPE_COMBINED) {
            if (!$responsibleCashierId || $responsibleCashierId <= 0) {
                throw new InvalidArgumentException('A responsible Cashier is required for drawer corrections.');
            }
            $cashStatus = 'pending';
        }

        if ($correctionType === self::TYPE_STOCK_ONLY || $correctionType === self::TYPE_COMBINED) {
            if (!$responsibleInventoryManagerId || $responsibleInventoryManagerId <= 0) {
                throw new InvalidArgumentException('A responsible Inventory Manager is required for stock corrections.');
            }
            $stockStatus = 'pending';
        }

        $status = self::STATUS_REVIEWED_OUTSTANDING;
        $proposedJson = $proposedCorrection ? json_encode($proposedCorrection, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO refund_correction_reviews (
                refund_id, correction_type, status, cash_status, stock_status,
                explanation, proposed_correction, reviewed_by,
                responsible_cashier_id, responsible_inventory_manager_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $refundId,
            $correctionType,
            $status,
            $cashStatus,
            $stockStatus,
            $explanation,
            $proposedJson,
            $actorId,
            $responsibleCashierId ?: null,
            $responsibleInventoryManagerId ?: null,
        ]);

        $reviewId = (int)$this->pdo->lastInsertId();

        // Audit log
        $this->pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
        ")->execute([
            $actorId,
            'Refund correction review approved',
            AuditRecordCategory::STORE_OPERATION,
            'Refund Correction Reviews',
            $reviewId,
            json_encode([
                'review_id' => $reviewId,
                'refund_id' => $refundId,
                'correction_type' => $correctionType,
                'status' => $status,
                'responsible_cashier_id' => $responsibleCashierId,
                'responsible_inventory_manager_id' => $responsibleInventoryManagerId,
                'explanation' => $explanation,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'system',
        ]);

        return $reviewId;
    }

    public function getReview(int $reviewId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM refund_correction_reviews WHERE review_id = ?");
        $stmt->execute([$reviewId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listReviews(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM refund_correction_reviews ORDER BY review_id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingReviewsForCashier(int $cashierId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM refund_correction_reviews
            WHERE responsible_cashier_id = ? AND cash_status = 'pending'
            ORDER BY review_id ASC
        ");
        $stmt->execute([$cashierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPendingReviewsForInventoryManager(int $inventoryManagerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM refund_correction_reviews
            WHERE responsible_inventory_manager_id = ? AND stock_status = 'pending'
            ORDER BY review_id ASC
        ");
        $stmt->execute([$inventoryManagerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function executeCashCorrection(
        int $reviewId,
        int $actorId,
        string $actorRole,
        float $amount,
        string $note
    ): array {
        if ($actorRole !== 'cashier') {
            throw new DomainException('Only the Cashier workspace can execute drawer corrections.');
        }

        $review = $this->getReview($reviewId);
        if (!$review) {
            throw new DomainException('Refund correction review not found.');
        }
        if ((int)$review['responsible_cashier_id'] !== $actorId) {
            throw new DomainException('Only the designated responsible Cashier can execute this drawer correction.');
        }
        if ($review['cash_status'] !== 'pending') {
            throw new DomainException('Cash drawer correction is not pending for this review.');
        }

        // Use CashierShiftService to add drawer movement
        $shiftService = new CashierShiftService($this->pdo, $this->policy);
        $movementType = $amount >= 0 ? 'cash_in' : 'cash_out';
        $movementAmount = abs($amount);
        $reason = $movementType === 'cash_in' ? 'cash_return' : 'other';

        $movementId = $shiftService->addDrawerMovement(
            $actorId,
            $actorRole,
            $movementType,
            $movementAmount,
            $reason,
            $note
        );

        // Update review
        $newCashStatus = 'completed';
        $isStockComplete = in_array($review['stock_status'], ['completed', 'not_required'], true);
        $newOverallStatus = $isStockComplete ? self::STATUS_CORRECTED : self::STATUS_REVIEWED_OUTSTANDING;
        $completedAt = $isStockComplete ? date('Y-m-d H:i:s') : null;

        $stmt = $this->pdo->prepare("
            UPDATE refund_correction_reviews
            SET cash_status = ?, status = ?, cash_drawer_movement_id = ?, completed_at = COALESCE(completed_at, ?)
            WHERE review_id = ?
        ");
        $stmt->execute([$newCashStatus, $newOverallStatus, $movementId, $completedAt, $reviewId]);

        // Audit log
        $this->pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $actorId,
            'Refund correction drawer task completed',
            AuditRecordCategory::STORE_OPERATION,
            'Refund Correction Reviews',
            $reviewId,
            json_encode(['status' => $review['status'], 'cash_status' => $review['cash_status']]),
            json_encode(['status' => $newOverallStatus, 'cash_status' => $newCashStatus, 'drawer_movement_id' => $movementId]),
            'system',
        ]);

        return ['drawer_movement_id' => $movementId, 'review' => $this->getReview($reviewId)];
    }

    public function executeStockCorrection(
        int $reviewId,
        int $actorId,
        string $actorRole,
        int $productId,
        int $qtyChange,
        string $reason
    ): array {
        if ($actorRole !== 'inventory_manager') {
            throw new DomainException('Only the Inventory Manager workspace can execute stock adjustments.');
        }

        $review = $this->getReview($reviewId);
        if (!$review) {
            throw new DomainException('Refund correction review not found.');
        }
        if ((int)$review['responsible_inventory_manager_id'] !== $actorId) {
            throw new DomainException('Only the designated responsible Inventory Manager can execute this stock correction.');
        }
        if ($review['stock_status'] !== 'pending') {
            throw new DomainException('Stock correction is not pending for this review.');
        }

        // Execute inventory adjustment via ProductService
        $productService = new \ProductService($this->pdo);
        $productService->adjustStock([
            'product_id' => $productId,
            'qty_change' => $qtyChange,
            'adjustment_reason' => $reason,
        ], $actorId);

        // Find created stock movement id
        $smStmt = $this->pdo->prepare("
            SELECT movement_id FROM stock_movements
            WHERE moved_by = ? AND product_id = ?
            ORDER BY movement_id DESC LIMIT 1
        ");
        $smStmt->execute([$actorId, $productId]);
        $movementId = (int)$smStmt->fetchColumn();

        // Record accountable inventory_adjustment record
        $this->pdo->prepare("
            INSERT INTO inventory_adjustments (
                product_id, adjustment_qty, adjustment_type, reported_by,
                approved_by, approved_at, reason, review_notes, status
            ) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?, ?, 'approved')
        ")->execute([
            $productId,
            $qtyChange,
            'other',
            $actorId,
            $actorId,
            $reason,
            "Executed for Refund Correction Review #{$reviewId}",
        ]);
        $adjustmentId = (int)$this->pdo->lastInsertId();

        if ($movementId > 0) {
            $this->pdo->prepare("UPDATE stock_movements SET adjustment_id = ? WHERE movement_id = ?")
                ->execute([$adjustmentId, $movementId]);
        }

        // Update review
        $newStockStatus = 'completed';
        $isCashComplete = in_array($review['cash_status'], ['completed', 'not_required'], true);
        $newOverallStatus = $isCashComplete ? self::STATUS_CORRECTED : self::STATUS_REVIEWED_OUTSTANDING;
        $completedAt = $isCashComplete ? date('Y-m-d H:i:s') : null;

        $stmt = $this->pdo->prepare("
            UPDATE refund_correction_reviews
            SET stock_status = ?, status = ?, inventory_adjustment_id = ?, completed_at = COALESCE(completed_at, ?)
            WHERE review_id = ?
        ");
        $stmt->execute([$newStockStatus, $newOverallStatus, $adjustmentId ?: null, $completedAt, $reviewId]);

        // Audit log
        $this->pdo->prepare("
            INSERT INTO activity_log (user_id, action, category, module, record_id, previous_value, new_value, ip_address)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $actorId,
            'Refund correction stock task completed',
            AuditRecordCategory::STORE_OPERATION,
            'Refund Correction Reviews',
            $reviewId,
            json_encode(['status' => $review['status'], 'stock_status' => $review['stock_status']]),
            json_encode(['status' => $newOverallStatus, 'stock_status' => $newStockStatus, 'adjustment_id' => $adjustmentId]),
            'system',
        ]);

        return ['adjustment_id' => $adjustmentId, 'review' => $this->getReview($reviewId)];
    }
}
