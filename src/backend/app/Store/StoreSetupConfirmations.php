<?php

namespace App\Store;

use DomainException;
use InvalidArgumentException;
use PDO;

final class StoreSetupConfirmations
{
    public const TASK_PRODUCTS = 'products';
    public const TASK_STORE_CALENDAR = 'store_calendar';

    public const TASKS = [
        self::TASK_PRODUCTS => [
            'key' => self::TASK_PRODUCTS,
            'title' => 'Products and opening stock',
            'role' => 'inventory_manager',
            'role_label' => 'Inventory Manager',
            'description' => 'Set up products, counted opening stock, package conversions and stock rules in Add Product.',
            'link' => 'inventory_management/products.php',
            'link_label' => 'Set up Products',
            'action_label' => 'Confirm product setup',
        ],
        self::TASK_STORE_CALENDAR => [
            'key' => self::TASK_STORE_CALENDAR,
            'title' => 'Store calendar review',
            'role' => 'admin',
            'role_label' => 'Administrator',
            'description' => 'Set trading days and planned closures in Store Calendar.',
            'link' => 'administrator/store_calendar.php',
            'link_label' => 'Review Store calendar',
            'action_label' => 'Confirm calendar review',
        ],
    ];

    /**
     * Get all confirmations indexed by task_key.
     * @return array<string, array{task_key: string, confirmed_by_user_id: int, confirmed_workspace: string, confirmed_at: string, confirmed_by_name?: string}>
     */
    public static function all(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query('SELECT c.task_key, c.confirmed_by_user_id, c.confirmed_workspace, c.confirmed_at, u.full_name AS confirmed_by_name FROM store_setup_confirmations c LEFT JOIN users u ON u.user_id = c.confirmed_by_user_id ORDER BY c.confirmed_at ASC');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $result = [];
            foreach ($rows as $row) {
                $result[$row['task_key']] = $row;
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check if all required setup tasks are confirmed.
     */
    public static function isComplete(PDO $pdo): bool
    {
        $confirmed = self::all($pdo);
        foreach (array_keys(self::TASKS) as $taskKey) {
            if (!isset($confirmed[$taskKey])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Explicitly confirm a task for the active Workspace.
     */
    public static function confirm(PDO $pdo, string $taskKey, int $userId, string $activeRole): void
    {
        if (!array_key_exists($taskKey, self::TASKS)) {
            throw new InvalidArgumentException('Invalid Store setup task.');
        }

        $expectedRole = self::TASKS[$taskKey]['role'];
        if ($activeRole !== $expectedRole) {
            throw new DomainException('Your active Workspace cannot confirm this Store setup task.');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO store_setup_confirmations (task_key, confirmed_by_user_id, confirmed_workspace, confirmed_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE confirmed_by_user_id = VALUES(confirmed_by_user_id), confirmed_workspace = VALUES(confirmed_workspace), confirmed_at = NOW()'
        );
        $stmt->execute([$taskKey, $userId, $activeRole]);
    }
}
