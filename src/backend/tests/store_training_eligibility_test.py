"""Exercise Store eligibility, scheduled enqueue, and the worker export boundary without MySQL (#365)."""
import json
import subprocess
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import pandas as pd
import numpy as np

BACKEND = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BACKEND / 'scripts'))
import ml_worker
from feature_pipeline import build_feature_matrix, build_training_rows, train_and_evaluate


class StoreTrainingEligibilityTests(unittest.TestCase):
    def test_overlapping_scheduler_tick_exits_before_database_access(self):
        lock_path = json.dumps(str(BACKEND / 'storage' / 'logs' / 'ml_scheduler.lock'))
        holder = subprocess.Popen(
            ['php', '-r', f'$lock = fopen({lock_path}, "c"); '
             'if (!flock($lock, LOCK_EX | LOCK_NB)) exit(1); '
             'echo "locked\\n"; fflush(STDOUT); fgets(STDIN);'],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        )
        try:
            self.assertEqual(holder.stdout.readline().strip(), 'locked')
            tick = subprocess.run(['php', str(BACKEND / 'scripts' / 'run_scheduler.php')],
                                  capture_output=True, text=True, timeout=15)
            self.assertEqual(tick.returncode, 0, tick.stderr)
            self.assertEqual(tick.stdout, '')
            self.assertEqual(tick.stderr, '')
        finally:
            holder.communicate('\n', timeout=5)

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
    CREATE TABLE store_weekly_schedule (schedule_id INTEGER PRIMARY KEY, effective_date TEXT, monday_open INT, tuesday_open INT, wednesday_open INT, thursday_open INT, friday_open INT, saturday_open INT, sunday_open INT, recorded_by INT, source TEXT, recorded_at TEXT);
    CREATE TABLE cash_refunds (refund_id INT, created_at TEXT);
    CREATE TABLE cash_refund_items (refund_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE sale_reversals (reversal_id INT, status TEXT, approved_at TEXT);
    CREATE TABLE sale_reversal_items (reversal_id INT, sale_item_id INT, product_id INT, quantity INT);
    CREATE TABLE scheduled_demand_drivers (driver_id INT, product_id INT, driver_type TEXT, effective_date TEXT, scheduled_at TEXT);
    CREATE TABLE platform_settings (setting_key TEXT, setting_value TEXT);
    CREATE TABLE model_training_runs (training_run_id INTEGER PRIMARY KEY, status TEXT, metrics_json TEXT);
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
$detected = $scheduler->runScheduler((clone $now)->modify('-20 minutes'));
$first = $scheduler->runScheduler($now);
$second = $scheduler->runScheduler($now);
$pdo->exec("DELETE FROM ml_jobs; UPDATE products SET status='inactive'");
$none = $scheduler->runScheduler($now);
echo json_encode(['payload' => $payload, 'detected' => $detected, 'first' => $first, 'second' => $second, 'none' => $none,
    'input_time' => $now->format('Y-m-d H:i:s e')], JSON_THROW_ON_ERROR);
'''
        result = subprocess.run(['php'], input=php, cwd=BACKEND, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        result = json.loads(result.stdout)
        payload = result['payload']
        eligibility = {row['product_id']: row for row in payload['eligibility']}
        # With Ticket #365 accumulated usable history:
        # Product 1 has 160 days (>= 140) -> eligible
        # Product 2 has 139 days (< 140) -> ineligible (under 140 accumulated days)
        # Product 3 has 159 days (day 100 skipped does not reset history!), but day 100 is inside holdout -> blocked by holdout coverage
        # Product 4 has 140 days (>= 140, genuine zero days count) -> eligible
        # Product 7 has 159 days (day 5 skipped was before holdouts) -> eligible
        self.assertEqual({pid for pid, row in eligibility.items() if row['eligible']}, {1, 4, 7})
        self.assertEqual(eligibility[2]['consecutive_usable_days'], 139)
        self.assertEqual(eligibility[3]['consecutive_usable_days'], 159)
        self.assertFalse(eligibility[3]['eligible'])
        self.assertIn('chronological evaluation coverage', eligibility[3]['reason'].lower())
        self.assertEqual(eligibility[6]['consecutive_usable_days'], 0)
        self.assertNotIn(5, eligibility)
        self.assertTrue(eligibility[2]['reason'])
        self.assertEqual(len(payload['history']), 160 + 140 + 160)
        self.assertEqual(result['detected']['retrain_jobs_queued'], 0)
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

    def test_monday_friday_store_with_weekend_closures_trains_at_140_usable_days(self):
        """
        Verify that a Monday-Friday Store closed every weekend (5 days/week)
        can build feature windows and train once 140 usable records accumulate
        across at least 28 calendar weeks (196 calendar days).
        Calendar-aware windows preserve 7/14/28-calendar-day horizons without inventing zero records.
        """
        # Generate 28 calendar weeks of Mon-Fri sales (28 * 5 = 140 usable days)
        # 2024-01-01 is Monday. 28 weeks end on Friday 2024-07-12 (or cutoff Sunday 2024-07-14)
        start = pd.Timestamp("2024-01-01")
        dates = pd.date_range(start, periods=196, freq="D")
        
        records = []
        for d in dates:
            if d.dayofweek < 5:  # Monday to Friday
                records.append({
                    "date": d,
                    "product_id": 1,
                    "units_sold": float(10 + (d.dayofweek * 2)),
                    "classification": "valid_demand"
                })

        df = pd.DataFrame(records)
        self.assertEqual(len(df), 140)  # Exactly 140 accumulated usable records

        # 1. Feature window construction at Friday cutoff (2024-07-12)
        cutoff = "2024-07-12"
        matrix = build_feature_matrix(df, cutoff, horizon_days=28)
        self.assertEqual(len(matrix), 28)
        self.assertEqual(list(matrix["horizon_day"]), list(range(1, 29)))
        # Verify no invented zero demand records
        self.assertFalse(matrix[["lag_1", "lag_7", "lag_14", "lag_28"]].isna().any().any())

        # 2. Training rows construction across the 28 weeks
        rows = build_training_rows(df, cutoff)
        self.assertGreater(len(rows), 0)
        # Check that excluded weekend days did not become training targets
        self.assertTrue((rows.target_date.dt.dayofweek < 5).all(), "Weekend closures must not become targets")

        # 3. Fit and evaluate candidate model with 3 chronological holdouts
        # With 140 days (28 weeks), we have sufficient history (84 days holdouts + training origins)
        # Note: 28 weeks gives 12 weeks of holdout + 16 weeks of training history
        artifact = train_and_evaluate(df)
        self.assertIn(1, artifact["metadata"]["products"])
        self.assertEqual(len(artifact["metadata"]["folds"]), 3)
        self.assertEqual(len(artifact["evaluations"]), 3)  # 7, 14, 28-day horizons

    def test_mixed_products_mature_product_proceeds_while_incomplete_holdout_is_blocked(self):
        """
        Verify that when Product A has complete holdout coverage and Product B has
        a stockout gap during holdout, Product A proceeds independently to evaluation/training
        while Product B is reported as excluded without aborting the mature Product.
        """
        start = pd.Timestamp("2024-01-01")
        dates = pd.date_range(start, periods=160, freq="D")
        records = []

        for d in dates:
            # P1: Clean history, 160 days
            records.append({
                "date": d, "product_id": 1, "units_sold": 15.0, "classification": "valid_demand"
            })
            # P2: Has 160 days, but stockout during Holdout 1 (e.g. 60 days before cutoff)
            is_stockout = (d == start + pd.Timedelta(days=100))
            records.append({
                "date": d, "product_id": 2, "units_sold": 0.0 if is_stockout else 12.0,
                "classification": "stockout_censored" if is_stockout else "valid_demand"
            })

        df = pd.DataFrame(records)
        artifact = train_and_evaluate(df)

        meta = artifact["metadata"]
        self.assertIn(1, meta["included_products"])
        self.assertIn(2, meta["excluded_products"])
        self.assertIn(2, meta["exclusion_reasons"])
        self.assertIn(1, meta["products"])

    def test_sale_based_weekend_opening_included_in_features(self):
        """
        Verify that a special sale-based weekend opening on a Saturday
        is recognized as an observed usable record in feature preparation.
        """
        # Mon-Fri store + one special Saturday opening with >28 calendar days of prior history
        start = pd.Timestamp("2023-12-25")
        dates = pd.date_range(start, periods=40, freq="D")
        records = []
        for d in dates:
            # Mon-Fri open, or special opening on Saturday 2024-01-27
            if d.dayofweek < 5 or d == pd.Timestamp("2024-01-27"):
                records.append({
                    "date": d, "product_id": 1, "units_sold": 25.0, "classification": "valid_demand"
                })

        df = pd.DataFrame(records)
        matrix = build_feature_matrix(df, "2024-01-27", horizon_days=7)
        self.assertEqual(len(matrix), 7)
        self.assertEqual(matrix.iloc[0]["lag_1"], 25.0)


if __name__ == '__main__':
    unittest.main()
