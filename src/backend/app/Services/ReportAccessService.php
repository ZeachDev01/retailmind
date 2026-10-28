<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

require_once __DIR__ . '/GrossProfitService.php';

/**
 * Access control and catalog policy for reporting workflows.
 * Enforces role and workspace boundaries for store reports.
 */
final class ReportAccessService
{
    /** Reports restricted from Inventory Manager workspace. */
    public const FINANCIAL_REPORTS = [
        'sales_cashier',
        'refunds',
        'gross_profit',
    ];

    /** Standard store reporting catalog. */
    public const REPORT_CATALOG = [
        'sales_period' => 'Daily, Weekly and Monthly Sales',
        'sales_product' => 'Sales by Product',
        'sales_category' => 'Sales by Category',
        'sales_cashier' => 'Sales by Cashier',
        'refunds' => 'Refund History',
        'fast_moving' => 'Fast-Moving Products',
        'slow_moving' => 'Slow-Moving Products',
        'low_stock' => 'Low-Stock and Out-of-Stock Products',
        'inventory_valuation' => 'Inventory Valuation',
        'stock_movements' => 'Stock Movement History',
        'adjustments' => 'Damaged, Missing and Expired Items',
        'forecast_actual' => 'Forecast Versus Actual Demand',
        'expiration' => 'Expiration Report',
        'count_variance' => 'Physical-Count Variance Report',
    ];

    /**
     * Returns the allowed report catalog for a given active role.
     *
     * @param string $activeRole Active workspace role
     * @return array<string, string>
     */
    public static function catalogForRole(string $activeRole): array
    {
        $catalog = self::REPORT_CATALOG;

        if ($activeRole === 'admin') {
            $catalog['gross_profit'] = 'Gross Profit Report';
        }

        if ($activeRole === 'inventory_manager') {
            unset($catalog['sales_cashier'], $catalog['refunds']);
        }

        return $catalog;
    }

    /**
     * Checks if a report is accessible in the active workspace.
     */
    public static function isAllowed(string $activeRole, string $report): bool
    {
        $catalog = self::catalogForRole($activeRole);
        return isset($catalog[$report]);
    }

    /**
     * Enforces report access for the active workspace.
     * Sets HTTP 403 and throws DomainException on denial.
     */
    public static function enforceAccess(?string $activeRole, string $report): void
    {
        $role = $activeRole ?? (\function_exists('current_role') ? \current_role() : ($_SESSION['role'] ?? null)) ?? '';

        if ($report === 'gross_profit') {
            GrossProfitService::enforceAdministratorWorkspace($role);
            return;
        }

        if ($role === 'inventory_manager' && in_array($report, self::FINANCIAL_REPORTS, true)) {
            if (\function_exists('http_response_code') && !headers_sent()) {
                \http_response_code(403);
            }
            throw new DomainException("The active Inventory Manager workspace cannot access financial or cashier performance reports ({$report}).");
        }

        if (!self::isAllowed($role, $report)) {
            if (\function_exists('http_response_code') && !headers_sent()) {
                \http_response_code(403);
            }
            throw new DomainException("Report '{$report}' is not accessible in the current workspace.");
        }
    }
}
