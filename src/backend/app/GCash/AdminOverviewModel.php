<?php

declare(strict_types=1);

namespace App\GCash;

/**
 * Resolved GCash Admin Overview state for one request.
 *
 * The page and the fragment endpoint both read this model, so filter
 * resolution and the single adminOverview() read live in one place.
 */
final class AdminOverviewModel
{
    public function __construct(
        public readonly ?array $overview,
        public readonly array $filters,
        public readonly int $page,
        public readonly string $activeTab,
        public readonly string $overviewFace,
        public readonly bool $isFilteredActivity,
        public readonly ?string $filterError,
        public readonly string $period,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?int $cashierId,
    ) {
    }
}
