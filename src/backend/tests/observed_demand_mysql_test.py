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
from pymysql.constants import CLIENT

BACKEND = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BACKEND / "scripts"))
import ml_worker


@unittest.skipUnless(os.getenv("RUN_DB_TESTS") == "1", "Set RUN_DB_TESTS=1 for disposable MySQL integration")
class ObservedDemandMysqlTest(unittest.TestCase):
    def test_supplied_csv_through_real_worker(self):
        database = 'retailmind_ticket164_demo_test_' + uuid.uuid4().hex[:8]
        with tempfile.TemporaryDirectory() as directory:
            environment = {
                'APP_ENV': 'test', 'DATABASE_ENV': 'local', 'DB_NAME': database,
                'DB_HOST': os.getenv('DB_HOST', '127.0.0.1'),
                'DB_PORT': os.getenv('DB_PORT', '3306'),
                'DB_USER': os.getenv('DB_USER', 'root'),
                'DB_PASSWORD': os.getenv('DB_PASSWORD', os.getenv('DB_PASS', '')),
                'BACKUP_STORAGE_PATH': str(Path(directory) / 'recovery'),
            }
            with patch.dict(os.environ, environment), patch.object(ml_worker, 'STORAGE_MODELS_DIR', Path(directory) / 'models'):
                admin = pymysql.connect(host=environment['DB_HOST'], port=int(environment['DB_PORT']),
                                        user=environment['DB_USER'], password=environment['DB_PASSWORD'],
                                        client_flag=CLIENT.MULTI_STATEMENTS, autocommit=True)
                conn = None
                created = False
                try:
                    with admin.cursor() as cursor:
                        cursor.execute(f'CREATE DATABASE `{database}` CHARACTER SET utf8mb4')
                        created = True
                        cursor.execute(f'USE `{database}`')
                        cursor.execute((BACKEND / 'sql' / 'schema.sql').read_text(encoding='utf-8'))
                        while cursor.nextset():
                            pass
                        for migration in ('005_ml_evaluation.sql', '006_demand_forecasts.sql'):
                            cursor.execute((BACKEND / 'sql' / 'migrations' / migration).read_text(encoding='utf-8'))
                            while cursor.nextset():
                                pass
                    imported = subprocess.run(['php', str(BACKEND / 'scripts' / 'import_kaggle_capstone.php')],
                                              capture_output=True, text=True)
                    self.assertEqual(imported.returncode, 0, imported.stdout + imported.stderr)
                    self.assertIn('14620', imported.stdout)
                    conn = ml_worker.get_connection()
                    for trigger in ('model_retraining', 'forecast_generation'):
                        with conn.cursor() as cursor:
                            cursor.execute("INSERT INTO ml_jobs (trigger_type, status, queued_at) VALUES (%s, 'queued', NOW())", (trigger,))
                            job_id = cursor.lastrowid
                        conn.commit()
                        job = ml_worker.claim_job(conn)
                        self.assertEqual(job['job_id'], job_id)
                        ml_worker.process_job(conn, job)
                        with conn.cursor() as cursor:
                            cursor.execute('SELECT status, diagnostic_reference FROM ml_jobs WHERE job_id = %s', (job_id,))
                            result = cursor.fetchone()
                            self.assertEqual(result['status'], 'succeeded', result['diagnostic_reference'])
                            if trigger == 'model_retraining':
                                cursor.execute('SELECT model_version FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1')
                                version = cursor.fetchone()['model_version']
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
                                cursor.execute("""INSERT INTO platform_settings (setting_key, setting_value)
                                    VALUES ('ml_approved_model_version', %s)
                                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)""", (version,))
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
                        print('MySQL Demo evidence:', json.dumps(summary['evaluations'], sort_keys=True))
                finally:
                    if conn:
                        conn.close()
                    if created:
                        with admin.cursor() as cursor:
                            cursor.execute(f'DROP DATABASE `{database}`')
                    admin.close()


if __name__ == '__main__':
    unittest.main()
