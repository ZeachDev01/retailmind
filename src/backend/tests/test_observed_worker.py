"""Worker DB/artifact boundary using isolated SQLite and synthetic demand."""
import json
import os
import sqlite3
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from datetime import datetime
from types import SimpleNamespace

import joblib
import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
import ml_worker


class ForecastEstimator:
    def __init__(self, error=None):
        self.error = error

    def predict(self, features):
        if self.error:
            raise ValueError(self.error)
        return np.full(len(features), 3.0)


class Cursor:
    def __init__(self, db):
        self.cursor = db.cursor()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.cursor.close()

    def execute(self, sql, params=()):
        import re
        s = re.sub(r"DATE_ADD\(NOW\(\),\s*INTERVAL\s*(\d+)\s*MINUTE\)", r"datetime('now', '+\1 minutes')", sql, flags=re.I)
        s = s.replace("%s", "?").replace("NOW()", "CURRENT_TIMESTAMP").replace("FOR UPDATE", "")
        self.cursor.execute(s, params)
        return self

    def fetchone(self):
        row = self.cursor.fetchone()
        return dict(row) if row else None

    def fetchall(self):
        return [dict(row) for row in self.cursor.fetchall()]


class Connection:
    def __init__(self, database="retailmind_demo_test"):
        self.db = sqlite3.connect(":memory:")
        self.db.row_factory = sqlite3.Row
        self.db.create_function("DATABASE", 0, lambda: database)
        self.db.executescript("""
            CREATE TABLE platform_settings (setting_key TEXT, setting_value TEXT);
            CREATE TABLE products (product_id INTEGER, sku TEXT);
            CREATE TABLE sales (sale_id INTEGER, cashier_id INTEGER, sale_date TEXT);
            CREATE TABLE sale_items (sale_id INTEGER, product_id INTEGER, quantity REAL);
            CREATE TABLE ml_jobs (job_id INTEGER, trigger_type TEXT, status TEXT,
                model_version TEXT, evidence_cutoff TEXT, attempts INTEGER DEFAULT 1,
                lease_expires_at TEXT, queued_at TEXT, started_at TEXT,
                completed_at TEXT, operator_message TEXT, diagnostic_reference TEXT);
            INSERT INTO ml_jobs VALUES (1, 'model_retraining', 'running', NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, NULL);
            CREATE TABLE ml_model_evaluations (model_version TEXT, horizon_days INTEGER,
                mae REAL, rmse REAL, wape REAL, baseline_mae REAL, baseline_rmse REAL,
                baseline_wape REAL, candidate_gate_outcome TEXT, missing_metric_reason TEXT,
                data_window_start TEXT, data_window_end TEXT, created_at TEXT);
            CREATE TABLE model_training_runs (model_name TEXT, model_version TEXT,
                trigger_type TEXT, status TEXT, metrics_json TEXT, started_at TEXT,
                completed_at TEXT, sales_records_used INTEGER, eligible_products INTEGER);
            CREATE TABLE demand_forecasts (job_id INTEGER, product_id INTEGER,
                forecast_origin TEXT, target_date TEXT, horizon_day INTEGER,
                predicted_units INTEGER, model_version TEXT, readiness_level TEXT,
                limitation_marker TEXT);
        """)

    def cursor(self):
        return Cursor(self.db)

    def commit(self):
        self.db.commit()

    def rollback(self):
        self.db.rollback()

    def seed(self):
        dates = pd.date_range("2023-01-01", periods=140)
        for pid in range(1, 21):
            self.db.execute("INSERT INTO products VALUES (?, ?)", (pid, f"DEMO-P{pid:04}"))
            for i, date in enumerate(dates):
                sid = pid * 1000 + i
                self.db.execute("INSERT INTO sales VALUES (?, 999, ?)", (sid, str(date)))
                self.db.execute("INSERT INTO sale_items VALUES (?, ?, ?)", (sid, pid, 10 + pid))
        self.db.execute("INSERT INTO platform_settings VALUES ('demo_import_metadata', ?)",
                        (json.dumps({"store_id": "S001", "products_mapped": 20,
                                     "total_records": 2800, "date_window": ["2023-01-01", "2023-05-20"]}),))
        self.commit()


class WorkerEvidenceTests(unittest.TestCase):
    def test_m5_demo_reads_fifty_products_and_refuses_missing_coverage(self):
        conn = Connection('retailmind_demo_m5_test')
        self.addCleanup(conn.db.close)
        conn.db.execute('CREATE TABLE forecast_sales_imports (product_id INTEGER, sale_date TEXT, quantity INTEGER)')
        rows = [(pid, '2016-05-22', 2) for pid in range(1, 51)]
        conn.db.executemany('INSERT INTO forecast_sales_imports VALUES (?,?,?)', rows)
        source = {'source_kind': 'm5_daily', 'store_id': 'CA_1', 'products_mapped': 50,
                  'total_records': 50, 'date_window': ['2016-05-22', '2016-05-22']}
        conn.db.execute("INSERT INTO platform_settings VALUES ('demo_import_metadata', ?)", (json.dumps(source),))
        context = ml_worker.dataset_context(conn.cursor())
        data = ml_worker.load_history(conn.cursor(), context)
        self.assertEqual(set(data.product_id), set(range(1, 51)))
        self.assertEqual(context['history_cutoff'], '2016-05-22')
        conn.db.execute('DELETE FROM forecast_sales_imports WHERE product_id=50')
        with self.assertRaisesRegex(ValueError, 'coverage'):
            ml_worker.load_history(conn.cursor(), context)

    def test_uci_demo_reads_daily_imports_instead_of_fabricated_pos_sales(self):
        conn = Connection()
        self.addCleanup(conn.db.close)
        conn.seed()
        conn.db.execute('CREATE TABLE forecast_sales_imports (product_id INTEGER, sale_date TEXT, quantity INTEGER)')
        conn.db.execute('''INSERT INTO forecast_sales_imports
            SELECT si.product_id, s.sale_date, 2 FROM sales s JOIN sale_items si ON si.sale_id=s.sale_id''')
        source = {'source_kind': 'uci_online_retail', 'source_label': 'UCI Online Retail',
                  'store_id': 'UCI_ONLINE_RETAIL', 'products_mapped': 20, 'total_records': 2800,
                  'date_window': ['2023-01-01', '2023-05-20']}
        conn.db.execute("UPDATE platform_settings SET setting_value=? WHERE setting_key='demo_import_metadata'",
                        (json.dumps(source),))
        conn.commit()
        context = ml_worker.dataset_context(conn.cursor())
        data = ml_worker.load_history(conn.cursor(), context)
        self.assertEqual(len(data), 2800)
        self.assertEqual(set(data.units_sold), {2})
        self.assertEqual(context['dataset_kind'], 'demo')
        conn.db.execute('DELETE FROM forecast_sales_imports WHERE product_id=20')
        with self.assertRaisesRegex(ValueError, 'coverage'):
            ml_worker.load_history(conn.cursor(), context)

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.storage = patch.object(ml_worker, "STORAGE_MODELS_DIR", self.path)
        self.storage.start()
        self.addCleanup(self.storage.stop)
        self.environment = patch.dict(os.environ, {"APP_ENV": "test"})
        self.environment.start()
        self.addCleanup(self.environment.stop)

    def test_demo_candidate_uses_imported_evidence_and_isolated_artifact(self):
        conn = Connection()
        self.addCleanup(conn.db.close)
        conn.seed()
        ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})
        row = dict(conn.db.execute("SELECT * FROM ml_jobs").fetchone())
        self.assertEqual(row["status"], "succeeded", row["diagnostic_reference"])
        artifact_path, = self.path.rglob("*.joblib")
        self.assertIn("demo", artifact_path.parts)
        artifact = joblib.load(artifact_path)
        meta = artifact["metadata"]
        self.assertTrue(meta["model_version"].startswith("demo_"))
        self.assertEqual(meta["dataset_kind"], "demo")
        self.assertEqual(meta["observations"], 2800)
        self.assertEqual(meta["data_window_start"], "2023-01-01")
        self.assertEqual(meta["data_window_end"], "2023-05-20")
        self.assertEqual(len(meta["products"]), 20)
        evaluations = conn.db.execute("SELECT * FROM ml_model_evaluations").fetchall()
        self.assertEqual({r["horizon_days"] for r in evaluations}, {7, 14, 28})
        self.assertTrue(all(r["data_window_start"] == "2023-01-01" for r in evaluations))
        self.assertTrue(all(r["candidate_gate_outcome"] != "passed" for r in evaluations))

        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', ?)",
                        (meta['model_version'],))
        conn.commit()
        ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "forecast_generation"})
        forecasts = conn.db.execute("SELECT * FROM demand_forecasts").fetchall()
        self.assertEqual(len(forecasts), 560)
        self.assertTrue(all(r["forecast_origin"] == "2023-05-20" for r in forecasts))
        self.assertTrue(all("Demo evidence" in r["limitation_marker"] for r in forecasts))
        features = ml_worker.build_feature_matrix(ml_worker.load_history(conn.cursor(), meta), "2023-05-20")
        expected = artifact["model"].predict(features[ml_worker.FEATURE_COLUMNS])
        self.assertEqual([r["predicted_units"] for r in forecasts], [round(float(x)) for x in expected])

    def test_generation_requires_explicit_approval_even_for_evaluated_candidates(self):
        for database in ('retailmind', 'retailmind_demo_test'):
            for outcomes in ((), ('passed', 'failed', 'failed'), ('passed', 'passed', 'passed')):
                with self.subTest(database=database, outcomes=outcomes):
                    conn = Connection(database)
                    self.addCleanup(conn.db.close)
                    if 'demo' in database:
                        conn.seed()
                    version = 'demo_candidate' if 'demo' in database else 'v_candidate'
                    if outcomes:
                        conn.db.execute("""INSERT INTO model_training_runs
                            (model_version, status, completed_at)
                            VALUES (?, 'completed', CURRENT_TIMESTAMP)""", (version,))
                        for horizon, outcome in zip((7, 14, 28), outcomes):
                            conn.db.execute("""INSERT INTO ml_model_evaluations
                                (model_version, horizon_days, candidate_gate_outcome)
                                VALUES (?, ?, ?)""", (version, horizon, outcome))
                    conn.commit()
                    with patch.object(ml_worker.joblib, 'load') as load:
                        ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation'})
                    job = conn.db.execute('SELECT * FROM ml_jobs').fetchone()
                    self.assertEqual(job['status'], 'failed')
                    self.assertIn('No approved forecasting model version configured', job['diagnostic_reference'])
                    self.assertEqual(conn.db.execute('SELECT COUNT(*) FROM demand_forecasts').fetchone()[0], 0)
                    load.assert_not_called()

    def test_legacy_setting_cannot_replace_missing_or_empty_approval(self):
        for approval in (None, '', ' '):
            with self.subTest(approval=approval):
                conn = Connection('retailmind')
                self.addCleanup(conn.db.close)
                conn.db.execute("INSERT INTO platform_settings VALUES ('approved_forecasting_model', 'v_legacy')")
                if approval is not None:
                    conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', ?)",
                                    (approval,))
                conn.commit()
                with patch.object(ml_worker.joblib, 'load') as load:
                    ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation'})
                job = conn.db.execute('SELECT * FROM ml_jobs').fetchone()
                self.assertEqual(job['status'], 'failed')
                self.assertIn('No approved forecasting model version configured', job['diagnostic_reference'])
                self.assertEqual(conn.db.execute('SELECT COUNT(*) FROM demand_forecasts').fetchone()[0], 0)
                load.assert_not_called()

    def test_empty_and_mixed_history_cannot_publish_candidates(self):
        for database, seed, mutate in [
            ("retailmind_demo_test", False, ""),
            ("retailmind", True, ""),
            ("retailmind_demo_test", True, "DELETE FROM sales"),
            ("retailmind_demo_test", True, "DELETE FROM sale_items WHERE product_id = 20"),
        ]:
            with self.subTest(database=database, mutate=mutate):
                conn = Connection(database)
                self.addCleanup(conn.db.close)
                if seed:
                    conn.seed()
                if mutate:
                    conn.db.execute(mutate)
                    conn.commit()
                ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})
                self.assertEqual(conn.db.execute("SELECT status FROM ml_jobs").fetchone()[0], "failed")
                self.assertEqual(conn.db.execute("SELECT COUNT(*) FROM ml_model_evaluations").fetchone()[0], 0)
                self.assertEqual(list(self.path.rglob("*.joblib")), [])

    def test_explicit_store_observation_needs_no_operational_approval(self):
        conn = Connection('retailmind')
        self.addCleanup(conn.db.close)
        conn.db.execute("UPDATE ml_jobs SET trigger_type = 'forecast_observation'")
        conn.commit()
        context = ml_worker.dataset_context(conn.cursor())
        data = pd.DataFrame({'date': pd.date_range(end=context['history_cutoff'], periods=140),
                             'product_id': 1, 'units_sold': 10.0})
        artifact = {'model': ForecastEstimator(),
                    'metadata': dict(context, model_version='v_observation',
                                     products=[1],
                                     feature_version=ml_worker.FEATURE_VERSION,
                                     features=ml_worker.FEATURE_COLUMNS)}
        directory = ml_worker.artifact_directory(context)
        directory.mkdir(parents=True)
        joblib.dump(artifact, directory / 'random_forest_candidate_v_observation.joblib')
        with patch.object(ml_worker, 'load_history', return_value=data):
            ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_observation',
                                        'observation_version': 'v_observation'})
        self.assertEqual(conn.db.execute('SELECT status FROM ml_jobs').fetchone()[0], 'succeeded')
        forecasts = conn.db.execute('SELECT * FROM demand_forecasts').fetchall()
        self.assertEqual(len(forecasts), 28)
        self.assertTrue(all(row['limitation_marker'].startswith('Observation only;') for row in forecasts))
        self.assertEqual(conn.db.execute('SELECT COUNT(*) FROM platform_settings').fetchone()[0], 0)
        self.assertIsNone(conn.db.execute("SELECT job_id FROM ml_jobs WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'").fetchone())

    def test_observation_requires_explicit_valid_candidate_and_preserves_boundaries(self):
        for version in (None, '../model', 'demo_candidate'):
            with self.subTest(version=version):
                conn = Connection('retailmind')
                self.addCleanup(conn.db.close)
                conn.db.execute("UPDATE ml_jobs SET trigger_type = 'forecast_observation'")
                conn.commit()
                with patch.object(ml_worker.joblib, 'load') as load:
                    ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_observation',
                                                'observation_version': version})
                self.assertEqual(conn.db.execute('SELECT status FROM ml_jobs').fetchone()[0], 'failed')
                self.assertEqual(conn.db.execute('SELECT COUNT(*) FROM demand_forecasts').fetchone()[0], 0)
                load.assert_not_called()
        with patch.object(ml_worker, 'get_connection') as connect:
            with self.assertRaisesRegex(ValueError, 'Invalid observation model version'):
                ml_worker.observe_model('../model')
            connect.assert_not_called()

    def test_failed_persistence_rolls_back_evaluations_and_artifact(self):
        conn = Connection()
        self.addCleanup(conn.db.close)
        conn.seed()
        conn.db.executescript("""CREATE TRIGGER fail_success BEFORE UPDATE ON ml_jobs
            WHEN NEW.status = 'succeeded' BEGIN SELECT RAISE(ABORT, 'write failed'); END;""")
        ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})
        self.assertEqual(conn.db.execute("SELECT status FROM ml_jobs").fetchone()[0], "failed")
        self.assertEqual(conn.db.execute("SELECT COUNT(*) FROM ml_model_evaluations").fetchone()[0], 0)
        self.assertEqual(conn.db.execute("SELECT COUNT(*) FROM model_training_runs").fetchone()[0], 0)
        self.assertEqual(list(self.path.rglob("*.joblib")), [])

    def test_store_inference_refuses_demo_version(self):
        conn = Connection("retailmind")
        self.addCleanup(conn.db.close)
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'demo_bad')")
        conn.commit()
        ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "forecast_generation"})
        self.assertEqual(conn.db.execute("SELECT status FROM ml_jobs").fetchone()[0], "failed")
        self.assertEqual(conn.db.execute("SELECT COUNT(*) FROM demand_forecasts").fetchone()[0], 0)

    def test_failed_forecasts_preserve_last_successful_results(self):
        conn = Connection()
        self.addCleanup(conn.db.close)
        conn.seed()
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'demo_test')")
        conn.db.execute("UPDATE ml_jobs SET trigger_type = 'forecast_generation'")
        conn.commit()
        context = ml_worker.dataset_context(conn.cursor())
        artifact = {
            'model': ForecastEstimator(),
            'metadata': dict(context, model_version='demo_test',
                             feature_version=ml_worker.FEATURE_VERSION,
                             features=ml_worker.FEATURE_COLUMNS),
        }
        directory = ml_worker.artifact_directory(context)
        directory.mkdir(parents=True)
        path = directory / 'random_forest_candidate_demo_test.joblib'
        joblib.dump(artifact, path)
        ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation'})
        previous = conn.db.execute('SELECT * FROM demand_forecasts ORDER BY product_id, horizon_day').fetchall()
        self.assertEqual(len(previous), 560)
        self.assertTrue(all(row['predicted_units'] == 3 for row in previous))

        for job_id, failure, error in [
            (2, 'estimator', 'Estimator feature schema mismatch'),
            (3, 'schema', 'Model feature contract is obsolete'),
            (4, 'persistence', 'Forecast write failed'),
        ]:
            with self.subTest(failure=failure):
                artifact['model'] = ForecastEstimator(error if failure == 'estimator' else None)
                artifact['metadata']['features'] = [] if failure == 'schema' else ml_worker.FEATURE_COLUMNS
                joblib.dump(artifact, path)
                conn.db.execute("INSERT INTO ml_jobs (job_id, trigger_type, status, completed_at, operator_message, diagnostic_reference) VALUES (?, 'forecast_generation', 'running', NULL, NULL, NULL)",
                                (job_id,))
                conn.commit()
                if failure == 'persistence':
                    # Fail after one insert, proving the worker rolls back partial output.
                    conn.db.executescript("""CREATE TRIGGER fail_forecast BEFORE INSERT ON demand_forecasts
                        WHEN NEW.job_id = 4 AND NEW.horizon_day = 2
                        BEGIN SELECT RAISE(ABORT, 'Forecast write failed'); END;""")
                ml_worker.process_job(conn, {'job_id': job_id, 'trigger_type': 'forecast_generation'})
                job = conn.db.execute('SELECT * FROM ml_jobs WHERE job_id = ?', (job_id,)).fetchone()
                self.assertEqual(job['status'], 'failed')
                self.assertIsNotNone(job['completed_at'])
                self.assertTrue(job['operator_message'].startswith('Job encountered') or job['operator_message'].startswith('Prerequisite blocked:'))
                self.assertIn(error, job['diagnostic_reference'])
                self.assertRegex(job['diagnostic_reference'], r'\[ERR-[0-9a-f]{8}\]$')
                self.assertEqual(conn.db.execute('SELECT * FROM demand_forecasts ORDER BY product_id, horizon_day').fetchall(),
                                 previous)
                latest = conn.db.execute("""SELECT job_id FROM ml_jobs
                    WHERE trigger_type = 'forecast_generation' AND status = 'succeeded'
                    ORDER BY completed_at DESC, job_id DESC LIMIT 1""").fetchone()
                self.assertEqual(latest['job_id'], 1)

    def test_midday_store_job_uses_previous_completed_day(self):
        conn = Connection("retailmind")
        self.addCleanup(conn.db.close)
        with patch.object(ml_worker, 'datetime') as clock:
            clock.now.return_value = datetime(2026, 10, 10, 12)
            context = ml_worker.dataset_context(conn.cursor())
        self.assertEqual(context['history_cutoff'], '2026-10-09')

    def test_store_generation_succeeds_with_supported_weekend_and_holiday_closures(self):
        conn = Connection('retailmind')
        self.addCleanup(conn.db.close)
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v_test_closure')")
        conn.db.execute("UPDATE ml_jobs SET trigger_type='forecast_generation'")
        conn.commit()
        context = ml_worker.dataset_context(conn.cursor())
        cutoff = '2024-07-07'
        context['history_cutoff'] = cutoff
        dates = pd.date_range('2024-01-01', cutoff, freq='D')
        records = []
        for d in dates:
            d_str = d.strftime('%Y-%m-%d')
            is_weekend = d.dayofweek >= 5
            if is_weekend or d_str == '2024-06-19':
                records.append({'date': d_str, 'product_id': 1, 'units_sold': None, 'classification': 'closed'})
            else:
                records.append({'date': d_str, 'product_id': 1, 'units_sold': 10.0, 'classification': 'valid_demand'})

            if is_weekend:
                records.append({'date': d_str, 'product_id': 2, 'units_sold': None, 'classification': 'closed'})
            elif d_str == '2024-07-04':
                records.append({'date': d_str, 'product_id': 2, 'units_sold': None, 'classification': 'stockout_censored'})
            else:
                records.append({'date': d_str, 'product_id': 2, 'units_sold': 12.0, 'classification': 'valid_demand'})

        data = pd.DataFrame(records)
        artifact = {'model': ForecastEstimator(), 'metadata': dict(context,
            model_version='v_test_closure', products=[1, 2], feature_version=ml_worker.FEATURE_VERSION,
            features=ml_worker.FEATURE_COLUMNS)}
        with patch.object(ml_worker, 'load_history', return_value=data), patch.object(ml_worker.joblib, 'load', return_value=artifact):
            ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation', 'evidence_cutoff': cutoff})

        self.assertEqual(conn.db.execute('SELECT status FROM ml_jobs').fetchone()[0], 'succeeded')
        forecasts = conn.db.execute('SELECT * FROM demand_forecasts').fetchall()
        self.assertEqual(len(forecasts), 28)
        self.assertEqual({row['product_id'] for row in forecasts}, {1})
        self.assertEqual(forecasts[0]['forecast_origin'], cutoff)

    def test_real_worker_training_evaluation_and_generation_end_to_end_with_supported_closures(self):
        """
        Ticket #415 Acceptance Criteria:
        Run identified isolated history with supported closures through actual queue/worker
        training and evaluation to a saved compatible candidate, observation persistence,
        and operational generation. Verify complete 7/14/28-day output, provenance, completed-day cutoff.
        """
        conn = Connection('retailmind')
        self.addCleanup(conn.db.close)

        for h in (7, 14, 28):
            conn.db.execute("INSERT INTO platform_settings VALUES (?, ?)", (f'ml_max_wape_{h}d', '0.50'))
        conn.commit()

        context = ml_worker.dataset_context(conn.cursor())
        cutoff = '2024-07-07'
        context['history_cutoff'] = cutoff

        dates = pd.date_range('2023-12-25', cutoff, freq='D')
        records = []
        for d in dates:
            d_str = d.strftime('%Y-%m-%d')
            is_weekend = d.dayofweek >= 5
            week_num = d.isocalendar()[1]
            is_payweek = (week_num % 2 == 1)
            val1 = 20.0 + (d.dayofweek * 3.0) + (15.0 if is_payweek and d.dayofweek == 4 else 0.0)

            if is_weekend or d_str == '2024-06-19':
                records.append({'date': d_str, 'product_id': 1, 'units_sold': None, 'classification': 'closed'})
            else:
                records.append({'date': d_str, 'product_id': 1, 'units_sold': float(val1), 'classification': 'valid_demand'})

            if is_weekend:
                records.append({'date': d_str, 'product_id': 2, 'units_sold': None, 'classification': 'closed'})
            elif d_str == '2024-07-04':
                records.append({'date': d_str, 'product_id': 2, 'units_sold': None, 'classification': 'stockout_censored'})
            else:
                records.append({'date': d_str, 'product_id': 2, 'units_sold': 12.0, 'classification': 'valid_demand'})

        data = pd.DataFrame(records)

        # 1. Enqueue and process model_retraining job
        conn.db.execute("INSERT INTO ml_jobs (job_id, trigger_type, status, evidence_cutoff) VALUES (10, 'model_retraining', 'queued', ?)", (cutoff,))
        conn.commit()

        with patch.object(ml_worker, 'load_history', return_value=data):
            job1 = ml_worker.claim_job(conn)
            self.assertEqual(job1['job_id'], 10)
            self.assertEqual(job1['trigger_type'], 'model_retraining')
            ml_worker.process_job(conn, job1)

        job1_row = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 10").fetchone())
        self.assertEqual(job1_row['status'], 'succeeded', job1_row.get('diagnostic_reference'))

        evaluations = conn.db.execute("SELECT * FROM ml_model_evaluations ORDER BY horizon_days").fetchall()
        self.assertEqual(len(evaluations), 3, "Evaluations persisted for 7, 14, 28 days")
        candidate_version = evaluations[0]['model_version']
        self.assertTrue(candidate_version.startswith('v'))
        self.assertEqual({r['horizon_days'] for r in evaluations}, {7, 14, 28})
        self.assertTrue(all(r['candidate_gate_outcome'] == 'passed' for r in evaluations))

        artifact_file = self.path / 'store' / ml_worker.artifact_directory(context).name / f"random_forest_candidate_{candidate_version}.joblib"
        self.assertTrue(artifact_file.exists(), f"Candidate artifact must be persisted at {artifact_file}")
        artifact = joblib.load(artifact_file)
        self.assertEqual(artifact['metadata']['products'], [1], "Product 1 included in model")
        self.assertIn(2, artifact['metadata']['excluded_products'], "Product 2 excluded due to stockout during holdout")
        self.assertEqual(artifact['metadata']['data_window_end'], cutoff)
        self.assertEqual(artifact['metadata']['outcome'], 'passed')

        # 2. Enqueue and process forecast_observation job for candidate
        conn.db.execute("INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, evidence_cutoff) VALUES (11, 'forecast_observation', 'queued', ?, ?)", (candidate_version, cutoff))
        conn.commit()

        with patch.object(ml_worker, 'load_history', return_value=data):
            job2 = ml_worker.claim_job(conn)
            self.assertEqual(job2['job_id'], 11)
            self.assertEqual(job2['trigger_type'], 'forecast_observation')
            ml_worker.process_job(conn, job2)

        job2_row = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 11").fetchone())
        self.assertEqual(job2_row['status'], 'succeeded', job2_row.get('diagnostic_reference'))

        obs_forecasts = conn.db.execute("SELECT * FROM demand_forecasts WHERE job_id = 11 ORDER BY horizon_day").fetchall()
        self.assertEqual(len(obs_forecasts), 28, "28 observation forecast days persisted")
        self.assertTrue(all(r['limitation_marker'].startswith('Observation only;') for r in obs_forecasts))
        self.assertTrue(all(r['forecast_origin'] == cutoff for r in obs_forecasts))
        self.assertEqual({r['product_id'] for r in obs_forecasts}, {1})

        # 3. Administrator approves candidate and operational generation runs for Sunday cutoff
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', ?)", (candidate_version,))
        conn.db.execute("INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, evidence_cutoff) VALUES (12, 'forecast_generation', 'queued', ?, ?)", (candidate_version, cutoff))
        conn.commit()

        with patch.object(ml_worker, 'load_history', return_value=data):
            job3 = ml_worker.claim_job(conn)
            self.assertEqual(job3['job_id'], 12)
            self.assertEqual(job3['trigger_type'], 'forecast_generation')
            ml_worker.process_job(conn, job3)

        job3_row = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 12").fetchone())
        self.assertEqual(job3_row['status'], 'succeeded', job3_row.get('diagnostic_reference'))

        op_forecasts = conn.db.execute("SELECT * FROM demand_forecasts WHERE job_id = 12 ORDER BY horizon_day").fetchall()
        self.assertEqual(len(op_forecasts), 28, "Complete 28-day operational forecast persisted")
        self.assertEqual(list(range(1, 29)), [r['horizon_day'] for r in op_forecasts])
        self.assertTrue(all(not r['limitation_marker'].startswith('Observation only;') for r in op_forecasts))
        self.assertTrue(all(r['forecast_origin'] == cutoff for r in op_forecasts))
        self.assertTrue(all(r['predicted_units'] >= 0 for r in op_forecasts))
        self.assertEqual({r['product_id'] for r in op_forecasts}, {1})

        expected_target_dates = [str((pd.Timestamp(cutoff) + pd.Timedelta(days=h)).date()) for h in range(1, 29)]
        self.assertEqual([r['target_date'] for r in op_forecasts], expected_target_dates)

    def test_store_generation_skips_untrained_and_gapped_products(self):
        conn = Connection('retailmind')
        self.addCleanup(conn.db.close)
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v_test')")
        conn.db.execute("UPDATE ml_jobs SET trigger_type='forecast_generation'")
        conn.commit()
        context = ml_worker.dataset_context(conn.cursor())
        dates = pd.date_range(end=context['history_cutoff'], periods=28)
        data = pd.concat([pd.DataFrame({'date': dates[:27] if pid == 2 else dates,
                                       'product_id': pid, 'units_sold': 4}) for pid in (1, 2, 3)])
        artifact = {'model': ForecastEstimator(), 'metadata': dict(context,
            model_version='v_test', products=[1, 2], feature_version=ml_worker.FEATURE_VERSION,
            features=ml_worker.FEATURE_COLUMNS)}
        with patch.object(ml_worker, 'load_history', return_value=data), patch.object(ml_worker.joblib, 'load', return_value=artifact):
            ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation'})
        self.assertEqual(conn.db.execute('SELECT status FROM ml_jobs').fetchone()[0], 'succeeded')
        forecasts = conn.db.execute('SELECT * FROM demand_forecasts').fetchall()
        self.assertEqual(len(forecasts), 28)
        self.assertEqual({row['product_id'] for row in forecasts}, {1})

    def test_zero_actual_demand_cannot_pass_quality_gate(self):
        conn = Connection()
        self.addCleanup(conn.db.close)
        conn.seed()
        conn.db.execute('UPDATE sale_items SET quantity = 0')
        for h in (7, 14, 28):
            conn.db.execute('INSERT INTO platform_settings VALUES (?, ?)', (f'ml_max_wape_{h}d', '0.5'))
        conn.commit()
        ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})
        evaluations = conn.db.execute('SELECT * FROM ml_model_evaluations').fetchall()
        self.assertEqual(len(evaluations), 3)
        self.assertTrue(all(r['candidate_gate_outcome'] == 'incomplete' for r in evaluations))
        self.assertTrue(all(r['missing_metric_reason'] == 'Zero actual demand' for r in evaluations))

    def test_store_readiness_excludes_unknown_versioned_days(self):
        conn = Connection('retailmind')
        self.addCleanup(conn.db.close)
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', 'v_test')")
        conn.commit()
        training = pd.DataFrame({'date': pd.date_range('2023-01-01', periods=140),
                                 'product_id': 1, 'units_sold': 10.0})
        artifact = ml_worker.train_and_evaluate(training)
        with patch.object(ml_worker, 'datetime') as clock:
            clock.now.return_value = datetime(2026, 10, 10, 12)
            context = ml_worker.dataset_context(conn.cursor())
            artifact['metadata'].update(context, model_version='v_test', outcome='passed')
            directory = ml_worker.artifact_directory(context)
            directory.mkdir(parents=True)
            joblib.dump(artifact, directory / 'random_forest_candidate_v_test.joblib')
            dates = pd.date_range(end='2026-10-09', periods=400)
            evidence = pd.DataFrame({'date': dates, 'available_date': dates,
                                     'product_id': 1, 'units_sold': [None] * 372 + [10.0] * 28})
            response = SimpleNamespace(stdout=evidence.to_json(orient='records', date_format='iso'))
            with patch.object(ml_worker.subprocess, 'run', return_value=response):
                ml_worker.process_job(conn, {'job_id': 1, 'trigger_type': 'forecast_generation'})
        forecasts = conn.db.execute('SELECT readiness_level, forecast_origin FROM demand_forecasts').fetchall()
        self.assertEqual(len(forecasts), 28)
        self.assertTrue(all(r['readiness_level'] == 'Insufficient' for r in forecasts))
        self.assertTrue(all(r['forecast_origin'] == '2026-10-09' for r in forecasts))


if __name__ == "__main__":
    unittest.main()
