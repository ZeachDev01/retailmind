<?php

use App\Database\Schema;

return [
    'key' => '202611010006_normalize_shift_payment_totals',
    'description' => 'Store Cashier Shift payment totals as atomic payment-type rows',
    'up' => static function (PDO $pdo): void {
        if (!Schema::tableExists($pdo, 'cashier_shifts')) {
            throw new \RuntimeException("Required table 'cashier_shifts' does not exist.");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS cashier_shift_payment_totals (
            shift_id INT NOT NULL,
            payment_type ENUM('cash','card','ewallet') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            PRIMARY KEY (shift_id, payment_type),
            CONSTRAINT fk_shift_payment_totals_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (shift_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!Schema::columnExists($pdo, 'cashier_shifts', 'payment_totals')) {
            return;
        }

        $rows = $pdo->query('SELECT shift_id, payment_totals FROM cashier_shifts WHERE payment_totals IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        $insert = $pdo->prepare(
            'INSERT INTO cashier_shift_payment_totals (shift_id, payment_type, amount) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
        );
        foreach ($rows as $row) {
            try {
                $totals = json_decode((string)$row['payment_totals'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new \RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.', 0, $exception);
            }
            if (!is_array($totals) || $totals === [] || array_diff(array_keys($totals), ['cash', 'card', 'ewallet']) !== []) {
                throw new \RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.');
            }
            foreach (['cash', 'card', 'ewallet'] as $paymentType) {
                if (!array_key_exists($paymentType, $totals)) {
                    continue;
                }
                $amount = $totals[$paymentType];
                if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount < 0 || (float)$amount > 9999999999.99) {
                    throw new \RuntimeException('Cannot migrate invalid cashier shift payment totals; the original values are preserved.');
                }
                $insert->execute([(int)$row['shift_id'], $paymentType, number_format((float)$amount, 2, '.', '')]);
            }
        }

        $pdo->exec('ALTER TABLE cashier_shifts DROP COLUMN payment_totals');
    },
];
