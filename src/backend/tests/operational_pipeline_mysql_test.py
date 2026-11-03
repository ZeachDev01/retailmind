"""Opt-in isolated Store workflow; synthetic evidence never touches real Store data."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
import uuid
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from unittest.mock import patch

import pymysql

BACKEND = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(BACKEND / 'scripts'))
import ml_worker


@unittest.skipUnless(os.getenv('RUN_DB_TESTS') == '1', 'Set RUN_DB_TESTS=1 for disposable MySQL acceptance')
class OperationalPipelineTest(unittest.TestCase):
    def test_history_worker_approval_guidance_failure_and_recovery(self):
        database = 'retailmind_acceptance_' + uuid.uuid4().hex[:12]
        today = datetime.now(ZoneInfo('Asia/Manila')).date()
        origin = today - timedelta(days=1)
        environment = dict(APP_ENV='test', APP_DEBUG='false', DATABASE_ENV='local', DB_NAME=database,
                           DB_HOST='127.0.0.1', DB_PORT=os.getenv('DB_PORT', '3306'),
                           DB_USER=os.getenv('DB_USER', 'root'),
                           DB_PASSWORD=os.getenv('DB_PASSWORD', ''), RUN_DB_TESTS='1')
        admin = pymysql.connect(host='127.0.0.1', port=int(environment['DB_PORT']),
                                user=environment['DB_USER'], password=environment['DB_PASSWORD'], autocommit=True)
        conn = None
        with tempfile.TemporaryDirectory(prefix='retailmind_acceptance_') as directory:
            models = Path(directory) / 'models'
            environment['BACKUP_STORAGE_PATH'] = str(Path(directory) / 'recovery')
            def php(action, *args, success=True):
                result = subprocess.run([os.getenv('PHP_BINARY', 'C:/xampp/php/php.exe'), str(BACKEND / 'tests/support/operational_pipeline_fixture.php'), action, *map(str, args)],
                                        capture_output=True, text=True, timeout=90)
                if success:
                    self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                    return json.loads(result.stdout)
                self.assertNotEqual(result.returncode, 0, result.stdout)
                return result.stderr

            with patch.dict(os.environ, environment), patch.object(ml_worker, 'STORAGE_MODELS_DIR', models):
                try:
                    php('setup')
                    conn = ml_worker.get_connection()
                    def query(sql, args=()):
                        conn.commit()
                        with conn.cursor() as cursor:
                            cursor.execute(sql, args)
                            result = cursor.fetchall()
                        conn.commit()
                        return result
                    # Identified synthetic certified-import rows, not genuine Store sales.
                    for day in range(260):
                        date = origin - timedelta(days=259-day)
                        query('INSERT INTO forecast_sales_imports (product_id,sale_date,quantity,imported_by) VALUES (1,%s,%s,1)',
                              (date, 5 + date.weekday() * 2 + day // 28))
                    training = php('queue', 'model_retraining')['job_id']
                    job = ml_worker.claim_job(conn)
                    self.assertEqual(job['job_id'], training)
                    ml_worker.process_job(conn, job)
                    completed = query('SELECT * FROM ml_jobs WHERE job_id=%s', (training,))[0]
                    self.assertEqual(completed['status'], 'succeeded', completed['diagnostic_reference'])
                    run = query('SELECT * FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1')[0]
                    version = run['model_version']
                    self.assertFalse(version.startswith('demo_'))
                    actual_evaluation = json.loads(run['metrics_json'])
                    self.assertEqual(actual_evaluation['metadata']['observations'], 260)
                    ml_worker.observe_model(version)
                    generated_observation = query("SELECT job_id,status FROM ml_jobs WHERE trigger_type='forecast_observation'")[0]
                    self.assertEqual(generated_observation['status'], 'succeeded')
                    observed_rows = query('SELECT limitation_marker FROM demand_forecasts WHERE job_id=%s', (generated_observation['job_id'],))
                    self.assertEqual(len(observed_rows), 28)
                    self.assertTrue(all('Observation only' in r['limitation_marker'] for r in observed_rows))
                    self.assertEqual(php('guidance')['coverage']['forecast_backed_count'], 0)
                    self.assertFalse(php('summary', version, models)['can_approve'])
                    php('approve', version, models, success=False)
                    # Synthetic historical qualification is explicitly isolated workflow evidence.
                    query("UPDATE ml_model_evaluations SET mae=1,rmse=1,wape=0.1,baseline_mae=2,baseline_rmse=2,baseline_wape=0.2,candidate_gate_outcome='passed' WHERE model_version=%s", (version,))
                    query("INSERT INTO ml_jobs (trigger_type,status,queued_at,completed_at,attempts,model_version) VALUES ('forecast_observation','succeeded',NOW(),NOW(),1,%s)", (version,))
                    observation = query('SELECT MAX(job_id) AS id FROM ml_jobs')[0]['id']
                    query("INSERT INTO stock_movements (product_id,change_qty,reason,moved_at) VALUES (1,10000,'receive',%s)", (str(origin - timedelta(days=60)) + ' 00:00:00',))
                    for h in range(1,29):
                        date = origin - timedelta(days=28-h)
                        query("INSERT INTO demand_forecasts (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level,limitation_marker) VALUES (%s,1,%s,%s,%s,5,%s,'Medium','Observation only; isolated synthetic workflow evidence')",
                              (observation, origin-timedelta(days=28), date, h, version))
                    summary = php('summary', version, models)
                    self.assertTrue(summary['can_approve'], json.dumps(summary))
                    self.assertEqual(php('approve', version, models)['decision'], 'approved')
                    audit = query("SELECT user_id FROM activity_log WHERE action='Activate Store Forecasting Model'")
                    self.assertEqual(audit[0]['user_id'], 2)
                    operational = ml_worker.claim_job(conn)
                    self.assertEqual(operational['trigger_type'], 'forecast_generation')
                    ml_worker.process_job(conn, operational)
                    state = query('SELECT * FROM ml_jobs WHERE job_id=%s', (operational['job_id'],))[0]
                    self.assertEqual(state['status'], 'succeeded', state['diagnostic_reference'])
                    rows = query('SELECT * FROM demand_forecasts WHERE job_id=%s ORDER BY horizon_day', (operational['job_id'],))
                    self.assertEqual(len(rows), 28)
                    self.assertEqual(rows[0]['target_date'], today)
                    self.assertEqual(rows[-1]['target_date'], today+timedelta(days=27))
                    totals = [sum(row['predicted_units'] for row in rows[:h]) for h in (7,14,28)]
                    guidance = php('guidance')
                    self.assertEqual(guidance['coverage']['forecast_backed_count'], 1)
                    recommendations = {r['product_id']: r for r in guidance['recommendations']}
                    self.assertEqual(recommendations[1]['expected_sales_28_day'], totals[2])
                    self.assertEqual(recommendations[2]['basis'], 'Stock-rule suggestion')
                    self.assertIsNone(recommendations[2]['expected_sales_28_day'])
                    query("UPDATE products SET status='inactive' WHERE product_id=2")
                    full = php('guidance')['coverage']
                    self.assertEqual(full['scope_count'], 1)
                    self.assertEqual(full['forecast_backed_count'], 1)
                    self.assertEqual(full['stock_rule_count'], 0)
                    query("UPDATE products SET status='active' WHERE product_id=2")
                    browser = subprocess.run(['node', str(BACKEND / 'tests/operational_pipeline_browser_test.js')],
                        env=dict(os.environ, ACCEPTANCE_MODEL=version, ACCEPTANCE_ORIGIN=str(origin), ACCEPTANCE_TOTALS=json.dumps(totals),
                                 ACCEPTANCE_RANGE=today.strftime('%b %d, %Y') + ' to ' + (today+timedelta(days=27)).strftime('%b %d, %Y')),
                        capture_output=True, text=True, timeout=90)
                    self.assertEqual(browser.returncode, 0, browser.stdout + browser.stderr)
                    print(browser.stdout.strip())
                    # Rename only this disposable artifact: exercise the real worker error path.
                    context = ml_worker.dataset_context(conn.cursor())
                    artifact = ml_worker.artifact_directory(context) / f'random_forest_candidate_{version}.joblib'
                    held = artifact.with_suffix('.held')
                    artifact.rename(held)
                    failed_id = php('queue', 'forecast_generation')['job_id']
                    try:
                        for attempt in (1, 2, 3):
                            if attempt > 1:
                                php('tick')
                            failed_job = ml_worker.claim_job(conn)
                            self.assertEqual(failed_job['job_id'], failed_id)
                            ml_worker.process_job(conn, failed_job)
                            failed = query('SELECT * FROM ml_jobs WHERE job_id=%s', (failed_id,))[0]
                            self.assertEqual(failed['status'], 'failed')
                            self.assertEqual(failed['attempts'], attempt)
                            self.assertTrue(failed['diagnostic_reference'].startswith('EXHAUSTED:' if attempt == 3 else 'TRANSIENT:'))
                            self.assertEqual(php('guidance')['coverage']['forecast_backed_count'], 1,
                                             'A failed attempt retains eligible earlier guidance')
                        php('tick')
                        notice = query("SELECT * FROM notifications WHERE attention_key='store.forecasting.failure'")
                        self.assertTrue(notice, 'Real exhausted worker failure generates a notice without inserting one')
                        self.assertEqual(query('SELECT attempts FROM ml_jobs WHERE job_id=%s', (failed_id,))[0]['attempts'], 3)
                        # Invalidate only isolated previous output to verify no usable output fallback.
                        query('UPDATE demand_forecasts SET forecast_origin=DATE_SUB(forecast_origin,INTERVAL 1 DAY) WHERE job_id=%s',
                              (operational['job_id'],))
                        fallback = php('guidance')
                        self.assertEqual(fallback['coverage']['forecast_backed_count'], 0)
                        self.assertTrue(all(r['basis'] == 'Stock-rule suggestion' for r in fallback['recommendations']))
                    finally:
                        held.rename(artifact)
                    recovery_id = php('queue', 'forecast_generation')['job_id']
                    recovery_job = ml_worker.claim_job(conn)
                    self.assertEqual(recovery_job['job_id'], recovery_id)
                    ml_worker.process_job(conn, recovery_job)
                    recovered = query('SELECT * FROM ml_jobs WHERE job_id=%s', (recovery_id,))[0]
                    self.assertEqual(recovered['status'], 'succeeded', recovered['diagnostic_reference'])
                    self.assertEqual(php('guidance')['coverage']['forecast_backed_count'], 1)
                    php('tick')
                    active = query("SELECT is_active FROM attention_states WHERE attention_key='store.forecasting.failure'")
                    self.assertTrue(all(not r['is_active'] for r in active))
                    print(json.dumps({'database': database, 'model': version, 'training_run_id': run['training_run_id'],
                                      'actual_training_gate': actual_evaluation['metadata'].get('outcome'),
                                      'actual_observation_job': generated_observation['job_id'],
                                      'forecast_job': operational['job_id'], 'totals_7_14_28': totals,
                                      'failed_job': failed_id, 'recovered_job': recovery_id, 'notice_ids': [n['notification_id'] for n in notice],
                                      'timestamp_asia_manila': datetime.now(ZoneInfo('Asia/Manila')).isoformat()}))
                finally:
                    if conn:
                        conn.close()
                    with admin.cursor() as cursor:
                        cursor.execute(f'DROP DATABASE IF EXISTS `{database}`')
                    admin.close()


if __name__ == '__main__':
    unittest.main()
