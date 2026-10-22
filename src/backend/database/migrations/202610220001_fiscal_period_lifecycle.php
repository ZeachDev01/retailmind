<?php

use App\Database\Schema;

return [
    'key' => '202610220001_fiscal_period_lifecycle',
    'description' => 'Enforce one open fiscal period and fiscal lifecycle metadata',
    'up' => static function (PDO $pdo): void {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        if (!Schema::columnExists($pdo, 'fiscal_periods', 'open_period_key')) {
            $conflict = (int)$pdo->query(
                "SELECT COUNT(*) FROM fiscal_periods WHERE status = 'open'"
            )->fetchColumn();
            if ($conflict > 1) {
                throw new RuntimeException('Cannot enable fiscal lifecycle: multiple open periods require review.');
            }
            $pdo->exec(
                "ALTER TABLE fiscal_periods
                 ADD COLUMN open_period_key TINYINT
                 GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED"
            );
        }
        Schema::addUniqueKeyIfMissing($pdo, 'fiscal_periods', 'uq_fiscal_period_one_open', 'open_period_key');
    },
];
