<?php

declare(strict_types=1);

namespace App\GCash;

use App\Services\GCashTransactionService;
use App\Services\PhilippineTime;
use DomainException;
use Throwable;

/**
 * One read of the GCash Admin Overview for a request's query string.
 *
 * Interface: filters(array $query) -> filter bag for the service;
 *            forRequest(array $query) -> AdminOverviewModel with the typed
 *            view state. Both the page and the fragment endpoint call
 *            forRequest(), so period inference, defaults and the single
 *            adminOverview() read cannot drift between them.
 */
final class AdminOverviewReport
{
    public const TABS = ['overview', 'transactions', 'reconciliation', 'unresolved', 'money'];

    public function __construct(private GCashTransactionService $service)
    {
    }

    /** Filter bag for GCashTransactionService and for CSV export. */
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
            $overview = $this->service->adminOverview($filters);
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
