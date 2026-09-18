<?php

namespace App\Audit;

use InvalidArgumentException;

final class AuditRecordCategory
{
    public const STORE_OPERATION = 'store_operation';
    public const SECURITY = 'security';
    public const RECOVERY = 'recovery';
    public const PLATFORM_SETTING = 'platform_setting';
    public const RECOVERY_ACCOUNT = 'recovery_account';

    public const ALL = [
        self::STORE_OPERATION,
        self::SECURITY,
        self::RECOVERY,
        self::PLATFORM_SETTING,
        self::RECOVERY_ACCOUNT,
    ];

    public static function requireValid(string $category): string
    {
        if (!in_array($category, self::ALL, true)) {
            throw new InvalidArgumentException('Unknown Protected Audit Record category.');
        }

        return $category;
    }

    public static function classify(?string $module, string $action, $details = null, $previousDetails = null): string
    {
        $subject = strtolower(trim((string)$module) . ' ' . trim($action));

        if (in_array(strtolower(trim((string)$module)), ['users', 'user access'], true)) {
            $roles = [];
            foreach ([$details, $previousDetails] as $snapshot) {
                if (is_string($snapshot)) {
                    $decoded = json_decode($snapshot, true);
                    $snapshot = is_array($decoded) ? $decoded : [];
                }
                if (is_array($snapshot)) {
                    $roles[] = (string)($snapshot['role'] ?? $snapshot['target_role'] ?? '');
                }
            }
            if (array_intersect($roles, ['super_admin', 'admin']) !== []) {
                return self::SECURITY;
            }
            return array_intersect($roles, ['cashier', 'inventory_manager']) !== []
                ? self::STORE_OPERATION
                : self::SECURITY;
        }
        if (str_contains($subject, 'recovery account')) {
            return self::RECOVERY_ACCOUNT;
        }
        if (str_contains($subject, 'backup & restore') || preg_match('/\b(database )?(backup|restore)\b/', $subject)) {
            return self::RECOVERY;
        }
        if (str_contains($subject, 'platform setting')
            || str_contains($subject, 'ml setting')
            || str_contains($subject, 'demand forecasting')
        ) {
            return self::PLATFORM_SETTING;
        }
        if (str_contains($subject, 'authentication')
            || preg_match('/\b(login|logout|password changed|password change)\b/', $subject)
        ) {
            return self::SECURITY;
        }

        return self::STORE_OPERATION;
    }
}
