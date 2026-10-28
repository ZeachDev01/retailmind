"""Exercise Store eligibility, scheduled enqueue, and the worker export boundary without MySQL."""
import json
import subprocess
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import pandas as pd

BACKEND = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BACKEND / 'scripts'))
import ml_worker
from feature_pipeline import train_and_evaluate


class StoreTrainingEligibilityTests(unittest.TestCase):
    def test_real_reader_scheduler_and_worker_use_the_same_eligible_history(self):
        php = r'''<?php
require 'bootstrap/app.php';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW', static fn() => '2024-06-09 01:10:00');
$pdo->exec("CREATE TABLE products (product_id INT, status TEXT);
    CREATE TABLE forecast_sales_imports (product_id INT, sale_date TEXT, quantity INT);
    CREATE TABLE sales (sale_id INT, sale_date TEXT);
    CREATE TABLE sale_items (sale_item_id INT, sale_id INT, product_id INT, quantity INT);
    CREATE TABLE stock_movements (product_id INT, change_qty INT, moved_at TEXT);
    CREATE TABLE store_calendar (calendar_id INTEGER PRIMARY KEY, calendar_date TEXT, status TEXT, recorded_at TEXT);
    CREATE TABLE cash_refunds (refund_id INT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE sale_reversals (reversal_id INT, status TEXT, approved_at TEXT);
    CREATE TABLE sale_reversal_items (reversal_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE platform_settings (setting_key TEXT, setting_value TEXT);
    CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT, requester_id INT, queued_at TEXT);
    CREATE TABLE activity_log (user_id INT, action TEXT, category TEXT, module TEXT, record_id INT, new_value TEXT, ip_address TEXT, created_at TEXT);
    INSERT INTO products VALUES (1,'active'),(2,'active'),(3,'active'),(4,'active'),(5,'inactive'),(6,'active'),(7,'active')");
$insert = $pdo->prepare('INSERT INTO forecast_sales_imports VALUES (?, ?, ?)');
$start = new DateTimeImmutable('2024-01-01');
foreach ([1,2,3,4,5,7] as $id) {
    for ($day = 0; $day < 160; $day++) {
        if (($id === 2 && $day < 21) || ($id === 3 && $day === 100)
            || ($id === 4 && $day < 20) || ($id === 7 && $day === 5)) continue;
        $insert->execute([$id, $start->modify("+$day days")->format('Y-m-d'), $id === 4 ? 0 : $day % 7]);
    }
}
$reader = new App\Forecasting\DemandHistoryService();
$payload = $reader->getTrainingHistory($pdo, '2024-06-08');
$scheduler = new App\Forecasting\ForecastingSchedulerService($pdo);
$now = new DateTime('2024-06-08 17:10:00', new DateTimeZone('UTC'));
$first = $scheduler->runScheduler($now);
$second = $scheduler->runScheduler($now);
$pdo->exec("DELETE FROM ml_jobs; UPDATE products SET status='inactive'");
$none = $scheduler->runScheduler($now);
echo json_encode(['payload' => $payload, 'first' => $first, 'second' => $second, 'none' => $none,
    'input_time' => $now->format('Y-m-d H:i:s e')], JSON_THROW_ON_ERROR);
'''
        result = subprocess.run(['php'], input=php, cwd=BACKEND, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        result = json.loads(result.stdout)
        payload = result['payload']
        eligibility = {row['product_id']: row for row in payload['eligibility']}
        self.assertEqual({pid for pid, row in eligibility.items() if row['eligible']}, {1, 4, 7})
        self.assertEqual(eligibility[2]['consecutive_usable_days'], 139)
        self.assertEqual(eligibility[3]['consecutive_usable_days'], 59)
        self.assertEqual(eligibility[6]['consecutive_usable_days'], 0)
        self.assertNotIn(5, eligibility)
        self.assertTrue(eligibility[2]['reason'])
        self.assertEqual(len(payload['history']), 420)
        self.assertEqual(result['first']['retrain_jobs_queued'], 1)
        self.assertEqual(result['second']['retrain_jobs_queued'], 0)
        self.assertEqual(result['none']['retrain_jobs_queued'], 0)
        self.assertEqual(result['input_time'], '2024-06-08 17:10:00 UTC')
        context = {'dataset_kind': 'store', 'dataset_database': 'retailmind_test',
                   'dataset_host': 'localhost', 'dataset_port': '3306', 'history_cutoff': '2024-06-08'}
        with patch.object(ml_worker.subprocess, 'run', return_value=SimpleNamespace(stdout=json.dumps(payload))) as export:
            history = ml_worker.load_history(None, context, training=True)
            self.assertEqual(export.call_args.args[0][-1], '--training')
        self.assertEqual(context['training_eligibility'], payload['eligibility'])
        artifact = train_and_evaluate(history)
        self.assertEqual(set(artifact['metadata']['products']), {1, 4, 7})
        self.assertEqual(len(artifact['metadata']['folds']), 3)
        with patch.object(ml_worker.subprocess, 'run', return_value=SimpleNamespace(stdout=json.dumps({'history': [], 'eligibility': []}))):
            with self.assertRaisesRegex(ValueError, 'No active products'):
                ml_worker.load_history(None, context, training=True)


if __name__ == '__main__':
    unittest.main()
