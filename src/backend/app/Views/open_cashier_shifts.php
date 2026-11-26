<?php
$shiftEscape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<div class="section-header">
    <h2 id="open-shifts-heading">Open cashier shifts <span class="tag"><?= $openShifts === null ? 'Unavailable' : count($openShifts) ?></span></h2>
</div>
<?php if ($openShifts === null): ?>
    <p role="status">Open shifts unavailable. Retrying automatically.</p>
<?php elseif ($openShifts === []): ?>
    <p role="status">No open cashier shifts.</p>
<?php else: ?>
    <div class="table-wrap" role="region" aria-label="Open cashier shifts" tabindex="0">
        <table data-no-smart-table data-no-ghost-rows>
            <thead><tr><th scope="col">Counter</th><th scope="col">Cashier</th><th scope="col">Opened (Philippine time)</th><th scope="col">Status</th><th scope="col">Report</th></tr></thead>
            <tbody>
            <?php foreach ($openShifts as $openShift): ?>
                <tr>
                    <td><?= $shiftEscape($openShift['register_name'] ?? 'Unassigned counter') ?></td>
                    <td><?= $shiftEscape($openShift['display_name']) ?></td>
                    <td><time datetime="<?= $shiftEscape((new DateTimeImmutable($openShift['opened_at'], new DateTimeZone('Asia/Manila')))->format(DATE_ATOM)) ?>"><?= $shiftEscape((new DateTimeImmutable($openShift['opened_at']))->format('M j, Y g:i A')) ?></time></td>
                    <td><span class="tag <?= empty($openShift['locked_at']) ? 'tag-success' : 'tag-warning' ?>"><?= empty($openShift['locked_at']) ? 'Open' : 'Locked' ?></span></td>
                    <td><a class="btn btn-small" href="<?= $shiftEscape(app_url('components/administrator/shift_report.php?shift_id=' . (int)$openShift['shift_id'])) ?>" aria-label="View shift report for <?= $shiftEscape($openShift['display_name']) ?>">View report</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
