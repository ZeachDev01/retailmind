<?php

declare(strict_types=1);

namespace App\GCash;

use App\Services\PhilippineTime;
use DomainException;
use Throwable;

/**
 * Admin overview read adapter. Composes GCash Live Custody (M1), Activity Scope (M2),
 * and exchange review (M4) into the legacy overview contract (ADR-0025 preserved).
 */
final class AdminOverviewReport
{
    public const TABS = ['overview', 'transactions', 'reconciliation', 'unresolved', 'money'];

    public function __construct(
        private GCashLiveCustodySession $custody,
        private GCashActivityScopeReport $activity,
        private GCashExchangeReview $review,
    ) {
    }

    /** Filter bag for GCash reporting/overview. */
    public function filters(array $query, string $role, int $actorId): array
    {
        return [
            'type' => (string)($query['type'] ?? 'all'),
            'search' => trim((string)($query['search'] ?? '')),
            'actor_role' => $role,
            'actor_id' => $actorId,
            'period' => (string)($query['period'] ?? ((isset($query['start_date']) || isset($query['end_date'])) ? 'custom' : 'today')),
            'start_date' => isset($query['start_date']) ? trim((string)$query['start_date']) : PhilippineTime::today(),
            'end_date' => isset($query['end_date']) ? trim((string)$query['end_date']) : PhilippineTime::today(),
            'cashier_id' => $query['cashier_id'] ?? null,
            'page' => filter_var($query['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]) ?: 1,
        ];
    }

    /** Assembled admin overview (legacy shape). Caller enforces authorization. */
    public function overview(array $filters): array
    {
        $custody = $this->custody->custodyOverviewState();
        $activity = $this->activity->filteredOverview($filters);
        $unresolved = $this->review->unresolvedExchanges();
        $differences = $this->review->reconciliationDifferences();

        return $custody + [
            'type_counts' => $activity['type_counts'],
            'filtered_activity' => $activity['filtered_activity'],
            'completed_exchange_count' => $activity['completed_exchange_count'],
            'matching_record_count' => $activity['matching_record_count'],
            'cash_in_principal' => $activity['cash_in_principal'],
            'cash_out_principal' => $activity['cash_out_principal'],
            'gross_fees_cash' => $activity['gross_fees_cash'],
            'gross_fees_wallet' => $activity['gross_fees_wallet'],
            'gross_fees_total' => $activity['gross_fees_total'],
            'total_transactions' => $activity['total_transactions'],
            'fees_charged' => $activity['fees_charged'],
            'fees_returned' => $activity['fees_returned'],
            'fees_after_returns' => $activity['fees_after_returns'],
            'unresolved_activity' => $unresolved,
            'needs_checking' => $unresolved,
            'unresolved_count' => count($unresolved),
            'needs_checking_count' => count($unresolved),
            'reconciliation_differences' => $differences,
            'differences_count' => count(array_filter($differences, static fn(array $difference): bool => empty($difference['is_reviewed']))),
            'transactions' => $activity['transactions'],
            'has_next' => $activity['has_next'],
            'page' => $activity['page'],
            'cashiers' => $activity['cashiers'],
            'filters' => $activity['filters'],
        ];
    }

    public function forRequest(array $query, string $role, int $actorId): AdminOverviewModel
    {
        $activeTab = in_array($query['tab'] ?? '', self::TABS, true) ? (string)$query['tab'] : 'overview';
        $isFilteredActivity = (isset($query['period']) && $query['period'] !== 'today')
            || isset($query['start_date'])
            || isset($query['end_date'])
            || (($query['face'] ?? '') === 'activity');

        $filters = $this->filters($query, $role, $actorId);
        $cashierId = isset($filters['cashier_id']) && $filters['cashier_id'] !== '' && $filters['cashier_id'] !== null
            ? (int)$filters['cashier_id']
            : null;
        $period = (string)$filters['period'];
        $startDate = (string)$filters['start_date'];
        $endDate = (string)$filters['end_date'];
        $overview = null;
        $filterError = null;

        try {
            $overview = $this->overview($filters);
            $period = (string)($overview['filters']['period'] ?? $period);
            $startDate = (string)($overview['filters']['start_date'] ?? $startDate);
            $endDate = (string)($overview['filters']['end_date'] ?? $endDate);
            $cashierId = isset($overview['filters']['cashier_id']) ? (int)$overview['filters']['cashier_id'] : null;
        } catch (DomainException $exception) {
            $filterError = $exception->getMessage();
        } catch (Throwable) {
            $filterError = 'Unable to load GCash overview. Try again.';
        }

        return new AdminOverviewModel(
            overview: $overview,
            filters: $filters,
            page: (int)$filters['page'],
            activeTab: $activeTab,
            overviewFace: $isFilteredActivity ? 'activity' : 'balances',
            isFilteredActivity: $isFilteredActivity,
            filterError: $filterError,
            period: $period,
            startDate: $startDate,
            endDate: $endDate,
            cashierId: $cashierId,
        );
    }
}
