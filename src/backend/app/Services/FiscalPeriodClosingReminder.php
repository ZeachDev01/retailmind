<?php
namespace App\Services;

use App\Attention\AttentionItem;
use App\Attention\AttentionNotificationService;
use App\Attention\Clock;
use App\Attention\SystemClock;
use DateTimeZone;
use PDO;

require_once __DIR__ . '/NotificationService.php';

final class FiscalPeriodClosingReminder
{
    private Clock $clock;

    public function __construct(private PDO $pdo, ?Clock $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    /** @return AttentionItem[] Live work is independent of whether its notification was read. */
    public function synchronize(int $userId, string $workspace): array
    {
        if (!in_array($workspace, ['cashier', 'inventory_manager', 'admin'], true)
            || !(new \NotificationService($this->pdo))->inWorkspace($userId, $workspace)) {
            return [];
        }
        $now = $this->clock->now()->setTimezone(new DateTimeZone('Asia/Manila'));
        $prefix = match ($workspace) {
            'cashier' => 'cashier', 'inventory_manager' => 'inventory', default => 'oversight',
        };
        $scope = $prefix . '.fiscal-closing.';
        $query = $this->pdo->prepare("SELECT p.period_id, p.period_name, p.end_date, w.kind, w.record_id
            FROM fiscal_periods p JOIN (
                SELECT 'shift' AS kind, shift_id AS record_id, cashier_id AS owner_id, opened_at AS work_date
                FROM cashier_shifts WHERE status = 'open'
                UNION ALL
                SELECT 'gcash', session_id, cashier_id, opened_at FROM gcash_service_sessions WHERE status = 'open'
                UNION ALL
                SELECT 'count', count_id, NULL, counted_at FROM inventory_counts WHERE status = 'pending'
            ) w ON DATE(w.work_date) BETWEEN p.start_date AND p.end_date
            WHERE p.status IN ('open', 'closed', 'locked') AND p.end_date <= ?
                AND (? = 'admin' OR (? = 'inventory_manager' AND w.kind = 'count')
                    OR (? = 'cashier' AND w.kind IN ('shift', 'gcash') AND w.owner_id = ?))
            ORDER BY p.end_date, p.period_id, w.kind, w.record_id");
        $query->execute([$now->modify('+1 day')->format('Y-m-d'), $workspace, $workspace, $workspace, $userId]);
        $items = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $work) {
            $ended = $work['end_date'] < $now->format('Y-m-d');
            $key = $scope . $work['period_id'] . ($workspace === 'admin' ? '' : '.' . $work['kind'] . '.' . $work['record_id']);
            [$action, $destination] = match ($workspace === 'admin' ? 'period' : $work['kind']) {
                'shift' => ['Count your drawer and close your Cashier Shift.', 'components/cashier/shifts.php?tab=close'],
                'gcash' => ['Reconcile and close your GCash session.', 'components/cashier/gcash.php'],
                'count' => ['Review the pending Inventory Count before period closure.', 'components/inventory_management/inventory_counts.php'],
                default => ['Review unfinished Cashier Shifts, GCash sessions and Inventory Counts before closing the period.', 'components/system_administrator/fiscal_periods.php'],
            };
            $items[$key] = new AttentionItem(
                $key, $ended ? 'critical' : 'warning', 'fiscal',
                $ended ? 'Fiscal period has ended' : ($work['end_date'] === $now->format('Y-m-d') ? 'Fiscal period ends today' : 'Fiscal period ends tomorrow'),
                'Fiscal period "' . $work['period_name'] . '" ' . ($ended ? 'ended' : 'ends') . ' on ' . $work['end_date'] . '. '
                    . $action . ' New transactions require an open fiscal period.',
                $now, $destination, null, true, 'fiscal_period', (int)$work['period_id'], 'fiscal_period'
            );
        }
        (new AttentionNotificationService($this->pdo, $this->clock))->synchronize($userId, array_values($items), true, $scope, false);
        return array_values($items);
    }
}
