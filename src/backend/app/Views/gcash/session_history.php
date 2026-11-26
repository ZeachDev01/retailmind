<?php

use App\GCash\GCashSessionHistory;
use App\Services\PhilippineTime;
use App\Support\OperatorAlert;

(static function (PDO $pdo, array $query, ?int $ownCashierId, string $path): void {
    $admin = $ownCashierId === null;
    $title = $admin ? 'GCash Sessions' : 'Recent GCash Sessions';
    $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $money = static fn(mixed $value): string => $value === null ? '—' : '₱' . number_format((float)$value, 2);
    $history = new GCashSessionHistory($pdo);
    $result = null;
    $error = '';
    try {
        $result = $history->page($query, $ownCashierId);
        $cashiers = $admin ? $history->cashiers() : [];
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = OperatorAlert::message($exception, 'GCash sessions are unavailable. Reload to try again.');
    }
    ?>
    <section class="<?= $admin ? 'gcash-overview-section' : 'pos-panel gcash-history' ?> gcash-session-history" aria-labelledby="gcash-session-history-title">
        <div class="pos-panel-header gcash-panel-header">
            <div class="pos-panel-title"><h2 id="gcash-session-history-title"><?= $escape($title) ?></h2></div>
        </div>
        <?php if ($error !== ''): ?>
            <p class="gcash-history-status" role="alert"><?= $escape($error) ?> <a href="<?= $escape($path . '?tab=sessions') ?>">Reload sessions</a></p>
        <?php else:
            ['rows' => $rows, 'page' => $page, 'pageSize' => $pageSize, 'totalRows' => $totalRows, 'totalPages' => $totalPages,
                'cashierId' => $cashierId, 'status' => $status, 'from' => $from, 'to' => $to] = $result;
            $paginationFilters = ['tab' => 'sessions'];
            if ($admin):
                $paginationFilters += ['session_cashier' => $cashierId, 'session_status' => $status, 'session_from' => $from, 'session_to' => $to];
        ?>
            <div class="gcash-transaction-filters">
            <form method="get" action="<?= $escape($path) ?>" class="gcash-filter-bar gcash-compact-filter-bar">
                <input type="hidden" name="tab" value="sessions">
                <input type="hidden" name="session_length" value="<?= $pageSize ?>">
                <div class="gcash-field">
                    <label class="gcash-filter-label" for="gcash-session-cashier">Cashier</label>
                    <select class="form-control" id="gcash-session-cashier" name="session_cashier">
                        <option value="">All cashiers</option>
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?= (int)$cashier['user_id'] ?>" <?= $cashierId === (int)$cashier['user_id'] ? 'selected' : '' ?>><?= $escape($cashier['cashier_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <dialog id="gcash-session-filter-dialog" class="gcash-filter-drawer" aria-labelledby="gcash-session-filter-title">
                <div class="pos-panel-header"><h2 id="gcash-session-filter-title">Session dates</h2><button type="button" class="btn btn-secondary" data-close-dialog>Close</button></div>
                <div class="gcash-field">
                    <label class="gcash-filter-label" for="gcash-session-from">Start date</label>
                    <input class="form-control" type="date" id="gcash-session-from" name="session_from" value="<?= $escape($from) ?>">
                </div>
                <div class="gcash-field">
                    <label class="gcash-filter-label" for="gcash-session-to">End date</label>
                    <input class="form-control" type="date" id="gcash-session-to" name="session_to" value="<?= $escape($to) ?>">
                </div>
                <div class="gcash-actions"><button type="submit" class="btn btn-primary">Apply filters</button></div>
                </dialog>
                <div class="gcash-field">
                    <label class="gcash-filter-label" for="gcash-session-state">Status</label>
                    <select class="form-control" id="gcash-session-state" name="session_status">
                        <option value="">All statuses</option>
                        <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
                <div class="gcash-actions gcash-filter-actions">
                    <button type="button" class="btn btn-secondary" data-open-dialog="gcash-session-filter-dialog" aria-haspopup="dialog">Filters<?= $from !== '' || $to !== '' ? ' · Dates selected' : '' ?></button>
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                    <a class="btn btn-secondary" href="<?= $escape($path . '?tab=sessions&session_length=' . $pageSize) ?>">Reset filters</a>
                </div>
            </form>
            </div>
            <?php endif; ?>
            <div class="gcash-table-wrap" tabindex="0" role="region" aria-labelledby="gcash-session-history-title">
                <table class="gcash-table" data-no-ghost-rows data-no-smart-table>
                    <thead><tr>
                        <th scope="col">Session</th>
                        <?php if ($admin): ?><th scope="col">Cashier</th><?php endif; ?>
                        <th scope="col">Store GCash number</th>
                        <th scope="col">Opened</th>
                        <th scope="col" class="gcash-session-amount">Opening cash</th>
                        <th scope="col" class="gcash-session-amount">Opening wallet</th>
                        <th scope="col">Closed</th>
                        <th scope="col" class="gcash-session-amount">Closing cash</th>
                        <th scope="col" class="gcash-session-amount">Closing wallet</th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($rows as $row):
                            $opened = explode(' · ', PhilippineTime::format($row['opened_at']));
                            $closed = explode(' · ', PhilippineTime::format($row['closed_at']));
                        ?>
                            <tr data-session-id="<?= (int)$row['session_id'] ?>">
                                <th scope="row">#<?= (int)$row['session_id'] ?><small><?= $row['status'] === 'open' ? 'Open' : 'Closed' ?></small></th>
                                <?php if ($admin): ?><td><?= $escape($row['cashier_name']) ?></td><?php endif; ?>
                                <td><?= $escape($row['wallet_number'] ?: 'Not recorded') ?></td>
                                <td><span class="gcash-session-date"><?= $escape($opened[0]) ?></span><?php if (isset($opened[1])): ?><small><?= $escape($opened[1]) ?></small><?php endif; ?></td>
                                <td class="gcash-session-amount"><?= $money($row['opening_service_cash']) ?></td>
                                <td class="gcash-session-amount"><?= $money($row['opening_wallet_balance']) ?></td>
                                <td><span class="gcash-session-date"><?= $escape($closed[0]) ?></span><?php if (isset($closed[1])): ?><small><?= $escape($closed[1]) ?></small><?php endif; ?>
                                    <?php if ($admin && $row['closed_by_name'] !== null): ?><small><?= $escape($row['closed_by_name']) ?></small><?php endif; ?>
                                </td>
                                <td class="gcash-session-amount"><?= $money($row['closing_service_cash']) ?></td>
                                <td class="gcash-session-amount"><?= $money($row['closing_wallet_balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="<?= $admin ? 9 : 8 ?>"><?= $admin ? 'No GCash sessions match these filters.' : 'You have no GCash sessions yet.' ?></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $hasNext = $page < $totalPages;
            $paginationAction = $path;
            $paginationLengthKey = 'session_length';
            $paginationLabel = 'GCash session pages';
            $url = static fn(array $change): string => $path . '?' . http_build_query(
                $paginationFilters + ['session_length' => $pageSize, 'session_page' => $change['page']]
            );
            require __DIR__ . '/../table_pagination.php';
        endif; ?>
    </section>
    <?php
})($pdo, $_GET, $sessionHistoryCashierId, $sessionHistoryPath);
