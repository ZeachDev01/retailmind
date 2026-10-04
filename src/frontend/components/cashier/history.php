<?php
// Private, read-only Cashier operational history (#98).
require_once __DIR__ . '/../../../backend/includes/auth.php';
require_once __DIR__ . '/../../../backend/includes/functions.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierOperationalHistoryService.php';
require_once __DIR__ . '/../../../backend/app/Services/CashierOperationalHistoryRequest.php';

use App\Services\CashierOperationalHistoryService;
use App\Services\CashierOperationalHistoryRequest;
use App\Services\PhilippineTime;

require_role(['cashier']);

$cashierId = (int)$_SESSION['user_id'];
// The account identifier comes only from the authenticated session. Request
// filters, URLs, and form data cannot change the owner of any query.
$request = CashierOperationalHistoryRequest::resolve($pdo, $cashierId, $_GET);
['types' => $types, 'type' => $type, 'page' => $page, 'recordId' => $recordId,
    'record' => $record, 'rows' => $rows, 'hasNext' => $hasNext,
    'fromDay' => $fromDay, 'throughDay' => $throughDay, 'dateError' => $dateError] = $request;
$tabs = array_map(static fn(array $metadata): string => $metadata['label'], $types);
$columns = $types[$type]['columns'];
$idColumn = $types[$type]['id'];
$moneyColumns = ['total_amount', 'cash_received', 'change_due', 'refund_amount', 'amount', 'opening_cash', 'expected_cash', 'actual_cash', 'cash_variance'];
$display = static function (string $column, mixed $value) use ($moneyColumns): string {
    if ($value === null || $value === '') {
        return '—';
    }
    if (in_array($column, $moneyColumns, true)) {
        return '₱' . number_format((float)$value, 2);
    }
    if (in_array($column, ['sale_date', 'created_at', 'opened_at', 'closed_at'], true)) {
        return htmlspecialchars(PhilippineTime::format($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$url = static fn(array $params): string => app_url('components/cashier/history.php?' . http_build_query($params + ['date_from' => $fromDay, 'date_to' => $throughDay]));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php retailmind_theme_head(); ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Operational History</title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/../sidebar.php'; ?>
    <main class="main-content">
        <div class="topbar"><div><h1>My Operational History</h1><p class="page-subtitle">Your sales, Cash Refunds, drawer movements and shifts. Dates and day filters use Philippine time (Asia/Manila). Customer receipt lookup and reprinting are in Receipt History; Stock Issues have separate report history.</p></div></div>
        <nav class="actions-row" aria-label="History type" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1rem">
            <?php foreach ($tabs as $key => $label): ?>
                <a class="btn <?= $key === $type ? '' : 'btn-secondary' ?>" href="<?= htmlspecialchars($url(['type' => $key])) ?>" <?= $key === $type ? 'aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <form method="GET" class="actions-row" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem" aria-label="Filter operational history by Philippine date">
            <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
            <label>From <input type="date" name="date_from" value="<?= htmlspecialchars($fromDay ?? '') ?>"></label>
            <label>Through <input type="date" name="date_to" value="<?= htmlspecialchars($throughDay ?? '') ?>"></label>
            <button type="submit" class="btn">Apply dates</button>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(app_url('components/cashier/history.php?' . http_build_query(['type' => $type]))) ?>">Clear dates</a>
        </form>
        <?php if ($dateError !== ''): ?><p role="alert"><?= htmlspecialchars($dateError) ?></p><?php endif; ?>
        <?php if ($recordId !== null): ?>
            <p><a href="<?= htmlspecialchars($url(['type' => $type, 'page' => $page])) ?>">← Back to <?= htmlspecialchars($tabs[$type]) ?></a></p>
            <?php if ($record === null): ?>
                <p>This record is unavailable.</p>
            <?php else: ?>
                <div class="card" style="padding:1rem"><h2><?= htmlspecialchars($tabs[$type]) ?> #<?= (int)$record[$idColumn] ?></h2>
                    <dl><?php foreach ($columns as $column => $label): ?>
                        <dt><?= htmlspecialchars($label) ?></dt><dd><?= $display($column, $record[$column] ?? null) ?></dd>
                    <?php endforeach; ?></dl>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="overflow-x:auto"><table><thead><tr>
                <?php foreach ($columns as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?>
            </tr></thead><tbody>
                <?php foreach ($rows as $row): ?><tr>
                    <?php foreach ($columns as $column => $label): ?><td>
                        <?php if ($column === $idColumn): ?><a href="<?= htmlspecialchars($url(['type' => $type, 'id' => (int)$row[$idColumn], 'page' => $page])) ?>">#<?= (int)$row[$idColumn] ?></a>
                        <?php else: ?><?= $display($column, $row[$column] ?? null) ?><?php endif; ?>
                    </td><?php endforeach; ?>
                </tr><?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="<?= count($columns) ?>">No <?= htmlspecialchars(strtolower($tabs[$type])) ?> recorded yet.</td></tr><?php endif; ?>
            </tbody></table></div>
            <nav aria-label="History pages" style="display:flex;gap:1rem;margin-top:1rem">
                <?php if ($page > 1): ?><a href="<?= htmlspecialchars($url(['type' => $type, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                <?php if ($hasNext): ?><a href="<?= htmlspecialchars($url(['type' => $type, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
