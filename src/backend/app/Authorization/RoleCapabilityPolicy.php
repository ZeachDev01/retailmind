<?php

namespace App\Authorization;

final class RoleCapabilityPolicy
{
    public const PLATFORM_GOVERNANCE = 'platform_governance';
    public const STORE_OPERATIONS = 'store_operations';
    public const VIEW_INVENTORY = 'view_inventory';
    public const MUTATE_INVENTORY = 'mutate_inventory';
    public const OPERATE_POINT_OF_SALE = 'operate_point_of_sale';
    public const VIEW_STORE_AUDIT = 'view_store_audit';
    public const VIEW_PLATFORM_AUDIT = 'view_platform_audit';
    public const VIEW_SALES_HISTORY = 'view_sales_history';
    public const VIEW_STORE_REPORTS = 'view_store_reports';
    public const MANAGE_SALE_REVERSALS = 'manage_sale_reversals';
    public const MANAGE_USERS = 'manage_users';
    public const ASSIGN_ROLES = 'assign_roles';
    public const ASSIGN_PRIVILEGES = 'assign_privileges';
    public const ACTIVATE_EMERGENCY_ACCESS = 'activate_emergency_access';
    public const MANAGE_DATABASE_BACKUP = 'manage_database_backup';

    private const BASE_CAPABILITIES = [
        'super_admin' => [
            self::PLATFORM_GOVERNANCE,
            self::VIEW_INVENTORY,
            self::VIEW_STORE_AUDIT,
            self::VIEW_PLATFORM_AUDIT,
            self::VIEW_SALES_HISTORY,
            self::VIEW_STORE_REPORTS,
            self::MANAGE_USERS,
            self::ASSIGN_ROLES,
            self::ASSIGN_PRIVILEGES,
            self::ACTIVATE_EMERGENCY_ACCESS,
            self::MANAGE_DATABASE_BACKUP,
        ],
        // Shared preservation authority: the Administrator may create and
        // download a Database Backup but never restoration authority, which
        // stays exclusive to the Super Administrator (see ADR-0003).
        'admin' => [
            self::STORE_OPERATIONS,
            self::VIEW_INVENTORY,
            self::VIEW_STORE_AUDIT,
            self::VIEW_SALES_HISTORY,
            self::VIEW_STORE_REPORTS,
            self::MANAGE_SALE_REVERSALS,
            self::MANAGE_USERS,
            self::ASSIGN_ROLES,
            self::MANAGE_DATABASE_BACKUP,
        ],
        'inventory_manager' => [
            self::VIEW_INVENTORY,
            self::MUTATE_INVENTORY,
            self::VIEW_SALES_HISTORY,
            self::VIEW_STORE_REPORTS,
            self::MANAGE_SALE_REVERSALS,
        ],
        'cashier' => [
            self::VIEW_INVENTORY,
            self::OPERATE_POINT_OF_SALE,
            self::VIEW_SALES_HISTORY,
        ],
    ];

    private const ADMIN_DELEGATED_ROLES = ['inventory_manager', 'cashier'];
    private const EMERGENCY_CAPABILITIES = [self::STORE_OPERATIONS, self::MUTATE_INVENTORY];

    public function allows(
        string $actorRole,
        string $capability,
        ?string $targetRole = null,
        ?AuthorizationContext $context = null,
        ?int $actorUserId = null
    ): bool {
        if (!isset(self::BASE_CAPABILITIES[$actorRole])) {
            return false;
        }

        if ($actorRole === 'super_admin'
            && ($context ?? AuthorizationContext::standard())->hasActiveEmergencyAccess($actorUserId)
            && in_array($capability, self::EMERGENCY_CAPABILITIES, true)
        ) {
            return true;
        }

        if (!in_array($capability, self::BASE_CAPABILITIES[$actorRole], true)) {
            return false;
        }

        if ($actorRole === 'admin'
            && $targetRole !== null
            && in_array($capability, [self::MANAGE_USERS, self::ASSIGN_ROLES], true)
        ) {
            return in_array($targetRole, self::ADMIN_DELEGATED_ROLES, true);
        }

        return true;
    }
}
