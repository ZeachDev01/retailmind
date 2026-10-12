"""Opt-in real CSV -> importer -> queue worker -> forecasts, in a disposable DB."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid
from unittest.mock import patch

import pymysql

BACKEND = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BACKEND / "scripts"))
import ml_worker


class DemoSetupGuardTest(unittest.TestCase):
    def test_refuses_store_hosted_and_operational_targets_before_connecting(self):
        with tempfile.TemporaryDirectory() as directory:
            environment = dict(os.environ, APP_ENV='test', DATABASE_ENV='local',
                               DB_NAME='retailmind_demo_guard', DB_HOST='127.0.0.1',
                               BACKUP_STORAGE_PATH=directory)
            for invalid in ({'DB_NAME': 'retailmind'}, {'DB_NAME': 'inventory_system'},
                            {'DB_HOST': 'example.invalid'}, {'APP_ENV': 'production'}):
                with self.subTest(invalid=invalid):
                    result = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'), 'setup'],
                                            env=dict(environment, **invalid), capture_output=True, text=True)
                    self.assertNotEqual(result.returncode, 0)
                    self.assertIn('local retailmind_demo_* database', result.stderr)


@unittest.skipUnless(os.getenv("RUN_DB_TESTS") == "1", "Set RUN_DB_TESTS=1 for disposable MySQL integration")
class ObservedDemandMysqlTest(unittest.TestCase):
    def test_supplied_csv_through_real_worker(self):
        database = 'retailmind_demo_' + uuid.uuid4().hex[:12]
        with tempfile.TemporaryDirectory() as directory:
            environment = {
                'APP_ENV': 'test', 'DATABASE_ENV': 'local', 'DB_NAME': database,
                'DB_HOST': os.getenv('DB_HOST', '127.0.0.1'),
                'DB_PORT': os.getenv('DB_PORT', '3306'),
                'DB_USER': os.getenv('DB_USER', 'root'),
                'DB_PASSWORD': os.getenv('DB_PASSWORD', os.getenv('DB_PASS', '')),
                'BACKUP_STORAGE_PATH': str(Path(directory) / 'recovery'),
                'DEMO_PASSWORD': 'DemoTest@2026',
            }
            with patch.dict(os.environ, environment), patch.object(ml_worker, 'STORAGE_MODELS_DIR', Path(directory) / 'models'):
                admin = pymysql.connect(host=environment['DB_HOST'], port=int(environment['DB_PORT']),
                                        user=environment['DB_USER'], password=environment['DB_PASSWORD'],
                                        autocommit=True)
                conn = None
                created = False
                try:
                    setup = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'), 'setup'],
                                           capture_output=True, text=True)
                    with admin.cursor() as cursor:
                        cursor.execute('SHOW DATABASES LIKE %s', (database,))
                        created = cursor.fetchone() is not None
                    self.assertEqual(setup.returncode, 0, setup.stdout + setup.stderr)
                    repeat = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'), 'setup'],
                                            capture_output=True, text=True)
                    self.assertNotEqual(repeat.returncode, 0, 'Setup must not overwrite an existing Demo')
                    imported = subprocess.run(['php', str(BACKEND / 'scripts' / 'import_kaggle_capstone.php')],
                                              capture_output=True, text=True)
                    self.assertEqual(imported.returncode, 0, imported.stdout + imported.stderr)
                    self.assertIn('14620', imported.stdout)
                    conn = ml_worker.get_connection()
                    for trigger in ('model_retraining', 'forecast_generation'):
                        queued = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'),
                                                 'train' if trigger == 'model_retraining' else 'forecast'],
                                                capture_output=True, text=True)
                        self.assertEqual(queued.returncode, 0, queued.stdout + queued.stderr)
                        with conn.cursor() as cursor:
                            cursor.execute('SELECT MAX(job_id) AS id FROM ml_jobs')
                            job_id = cursor.fetchone()['id']
                        conn.commit()
                        job = ml_worker.claim_job(conn)
                        self.assertEqual(job['job_id'], job_id)
                        ml_worker.process_job(conn, job)
                        with conn.cursor() as cursor:
                            cursor.execute('SELECT status, diagnostic_reference FROM ml_jobs WHERE job_id = %s', (job_id,))
                            result = cursor.fetchone()
                            self.assertEqual(result['status'], 'succeeded', result['diagnostic_reference'])
                            if trigger == 'model_retraining':
                                review = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'), 'review'],
                                                        capture_output=True, text=True)
                                self.assertEqual(review.returncode, 0, review.stdout + review.stderr)
                                reviewed_candidate = json.loads(review.stdout)
                                self.assertTrue(reviewed_candidate['quality']['passed'])
                                self.assertEqual(reviewed_candidate['evidence']['metadata']['observations'], 14620)
                                cursor.execute('SELECT model_version FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1')
                                version = cursor.fetchone()['model_version']
                                self.assertEqual(reviewed_candidate['model_version'], version)
                                ml_worker.observe_model(version)
                                conn.commit()
                                cursor.execute("SELECT job_id, status FROM ml_jobs WHERE trigger_type = 'forecast_observation'")
                                observation = cursor.fetchone()
                                self.assertEqual(observation['status'], 'succeeded')
                                cursor.execute("SELECT new_value FROM activity_log WHERE action = 'Enqueued Observation ML Job' AND record_id = %s", (observation['job_id'],))
                                audit = json.loads(cursor.fetchone()['new_value'])
                                self.assertEqual(audit['origin'], 'cli')
                                self.assertEqual(audit['model_version'], version)
                                self.assertTrue(audit['initiating_identity'])
                                cursor.execute('SELECT COUNT(*) AS count FROM demand_forecasts WHERE job_id = %s', (observation['job_id'],))
                                self.assertEqual(cursor.fetchone()['count'], 560)
                                cursor.execute("SELECT COUNT(*) AS count FROM platform_settings WHERE setting_key = 'ml_approved_model_version'")
                                self.assertEqual(cursor.fetchone()['count'], 0)
                                approval = subprocess.run(['php', str(BACKEND / 'scripts' / 'demo_forecasting.php'), 'approve', version],
                                                          capture_output=True, text=True)
                                self.assertEqual(approval.returncode, 0, approval.stdout + approval.stderr)
                        conn.commit()
                        if trigger == 'model_retraining':
                            with conn.cursor() as cursor:
                                cursor.execute("SELECT user_id, new_value FROM activity_log WHERE action='Promote Forecasting Model'")
                                approval_audit, = cursor.fetchall()
                                self.assertEqual(approval_audit['user_id'], 1)
                                self.assertEqual(approval_audit['new_value'], version)
                            conn.commit()
                    with conn.cursor() as cursor:
                        cursor.execute('SELECT metrics_json FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1')
                        summary = json.loads(cursor.fetchone()['metrics_json'])
                        meta = summary['metadata']
                        self.assertEqual(meta['observations'], 14620)
                        self.assertEqual(meta['data_window_end'], '2024-01-01')
                        self.assertEqual(meta['products'], list(range(1, 21)))
                        cursor.execute('SELECT COUNT(*) AS count FROM demand_forecasts WHERE job_id = %s', (job_id,))
                        self.assertEqual(cursor.fetchone()['count'], 560)
                        cursor.execute('''SELECT SUM(CASE WHEN horizon_day <= 7 THEN predicted_units ELSE 0 END) AS f7,
                            SUM(CASE WHEN horizon_day <= 14 THEN predicted_units ELSE 0 END) AS f14,
                            SUM(predicted_units) AS f28 FROM demand_forecasts WHERE job_id=%s AND product_id=1''', (job_id,))
                        totals = [int(value) for value in cursor.fetchone().values()]
                        print('MySQL Demo evidence:', json.dumps(summary['evaluations'], sort_keys=True))
                    browser = subprocess.run(['node', str(BACKEND / 'tests' / 'demo_forecasting_browser_check.js')],
                                             env=dict(os.environ, DEMO_FORECAST_JOB=str(job_id), DEMO_FORECAST_TOTALS=json.dumps(totals)),
                                             capture_output=True, text=True, timeout=90)
                    self.assertEqual(browser.returncode, 0, browser.stdout + browser.stderr)
                    reviewed = json.loads(browser.stdout.strip())
                    conn.commit()
                    with conn.cursor() as cursor:
                        cursor.execute('SELECT * FROM replenishment_requests')
                        request, = cursor.fetchall()
                        self.assertEqual(request['source'], 'ml_forecast')
                        self.assertEqual(request['forecast_job_id'], job_id)
                        self.assertIsNone(request['forecast_prediction_id'])
                        self.assertEqual(request['product_id'], reviewed['product_id'])
                        self.assertEqual(request['request_qty'], reviewed['quantity'])
                        self.assertEqual(request['status'], 'pending')
                        cursor.execute('SELECT * FROM forecast_decisions')
                        decision, = cursor.fetchall()
                        self.assertEqual(decision['forecast_job_id'], job_id)
                        self.assertEqual(decision['replenishment_request_id'], request['request_id'])
                        self.assertEqual(decision['decided_by'], 3)
                        cursor.execute('SELECT COUNT(*) AS count FROM purchase_orders')
                        self.assertEqual(cursor.fetchone()['count'], 0)
                finally:
                    if conn:
                        conn.close()
                    if created:
                        with admin.cursor() as cursor:
                            cursor.execute(f'DROP DATABASE `{database}`')
                    admin.close()


if __name__ == '__main__':
    unittest.main()
