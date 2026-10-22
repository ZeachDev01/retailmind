<?php

use App\Database\Schema;

return [
    'key' => '202610220001_fiscal_period_lifecycle',
    'description' => 'Enforce one open fiscal period and fiscal lifecycle metadata',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $issues = [];
        $periods = $pdo->query(
            'SELECT period_id, start_date, end_date, status FROM fiscal_periods ORDER BY start_date, period_id'
        )->fetchAll(PDO::FETCH_ASSOC);
        $previous = null;
        foreach ($periods as $period) {
            if ($previous) {
                if ($period['start_date'] <= $previous['end_date']) {
                    $issues[] = "overlap between period {$previous['period_id']} and {$period['period_id']}";
                } elseif ($period['start_date'] !== date('Y-m-d', strtotime($previous['end_date'] . ' +1 day'))) {
                    $issues[] = "gap before period {$period['period_id']}";
                }
            }
            $previous = $period;
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'")->fetchColumn() > 1) {
            $issues[] = 'multiple open periods';
        }
        $dateSources = [
            ['sales', 'sale_date'],
            ['purchase_history', 'purchase_date'],
            ['stock_movements', 'created_at'],
            ['cash_refunds', 'created_at'],
            ['cash_drawer_movements', 'created_at'],
            ['gcash_transactions', 'completed_at'],
            ['gcash_funding_movements', 'created_at'],
            ['gcash_adjustments', 'created_at'],
        ];
        foreach ($dateSources as [$table, $column]) {
            if (!Schema::tableExists($pdo, $table) || !Schema::columnExists($pdo, $table, $column) || !$periods) {
                continue;
            }
            $outside = $pdo->query(
                "SELECT COUNT(*) FROM `{$table}` t
                 WHERE DATE(t.`{$column}`) IS NOT NULL
                 AND NOT EXISTS (
                     SELECT 1 FROM fiscal_periods p
                     WHERE DATE(t.`{$column}`) BETWEEN p.start_date AND p.end_date
                 )"
            )->fetchColumn();
            if ((int)$outside > 0) {
                $issues[] = "{$table} has {$outside} transaction(s) outside fiscal-period coverage";
            }
        }
        $unfinished = [];
        if (Schema::tableExists($pdo, 'cashier_shifts')
            && (int)$pdo->query("SELECT COUNT(*) FROM cashier_shifts WHERE status = 'open'")->fetchColumn() > 0) {
            $unfinished[] = 'open Cashier Shifts';
        }
        if (Schema::tableExists($pdo, 'gcash_service_sessions')
            && (int)$pdo->query("SELECT COUNT(*) FROM gcash_service_sessions WHERE status = 'open' OR COALESCE(unresolved_carryover_count, 0) <> 0")->fetchColumn() > 0) {
            $unfinished[] = 'open or unreconciled GCash sessions';
        }
        if (Schema::tableExists($pdo, 'inventory_counts')
            && (int)$pdo->query("SELECT COUNT(*) FROM inventory_counts WHERE status = 'pending'")->fetchColumn() > 0) {
            $unfinished[] = 'pending Inventory Counts';
        }
        if ($unfinished) {
            $issues[] = 'unfinished closure work: ' . implode(', ', $unfinished);
        }
        if ($issues) {
            throw new RuntimeException(
                'Cannot enable fiscal lifecycle enforcement. Reviewed resolution required: ' . implode('; ', $issues) . '.'
            );
        }
        if (!Schema::columnExists($pdo, 'fiscal_periods', 'open_period_key')) {
            $pdo->exec(
                "ALTER TABLE fiscal_periods
                 ADD COLUMN open_period_key TINYINT
                 GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED"
            );
        }
        Schema::addUniqueKeyIfMissing($pdo, 'fiscal_periods', 'uq_fiscal_period_one_open', 'open_period_key');
    },
];
