<div data-fragment="transactions-pagination">
<?php
$rows = $overview['transactions'] ?? [];
$pageSize = $report->filters['limit'] ?? 15;
$totalRows = (int)($overview['matching_record_count'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $pageSize));
$page = min($totalPages, $page);
$hasNext = $page < $totalPages;
$paginationFilters = array_filter($_GET, static fn($value, $key): bool => is_scalar($value) && !in_array($key, ['page', 'length'], true), ARRAY_FILTER_USE_BOTH);
$paginationFilters['tab'] = 'transactions';
$paginationLabel = 'GCash transaction pages';
$url = static fn(array $params): string => '?' . http_build_query($params + $paginationFilters + ['length' => $pageSize]);
require __DIR__ . '/../table_pagination.php';
?>
</div>
