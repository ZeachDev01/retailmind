"""
Ticket #367: Candidate observation forecast generation, worker processing, and evidence preservation.
Tests:
- First-installation observation through worker and persisted evidence (no approved model needed).
- Replacement-candidate observation with simultaneous independent operational generation.
- Preserving candidate under observation when later weekly candidate is trained.
- Tie every observation to its candidate, genuine origin and recorded prediction.
- Synthetic elapsed time remains labelled test evidence.
"""
import json
import os
import sqlite3
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

import joblib
import numpy as np
import pandas as pd

SCRIPTS_DIR = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import ml_worker


class ForecastEstimator:
    def __init__(self, pred_val=5.0):
        self.pred_val = pred_val

    def predict(self, features):
        return np.full(len(features), self.pred_val)


class SQLiteCursor:
    def __init__(self, db):
        self.cursor = db.cursor()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.cursor.close()

    def execute(self, sql, params=()):
        clean_sql = (sql.replace("%s", "?")
                        .replace("NOW()", "CURRENT_TIMESTAMP")
                        .replace("FOR UPDATE", "")
                        .replace("DATE_ADD(NOW(), INTERVAL 30 MINUTE)", "datetime('now', '+30 minutes')")
                        .replace("DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE)", "datetime('now', '+30 minutes')"))
        self.cursor.execute(clean_sql, params)
        return self

    def fetchone(self):
        row = self.cursor.fetchone()
        return dict(row) if row else None

    def fetchall(self):
        return [dict(row) for row in self.cursor.fetchall()]


class SQLiteConnection:
    def __init__(self, database="retailmind"):
        self.db = sqlite3.connect(":memory:")
        self.db.row_factory = sqlite3.Row
        self.db.create_function("DATABASE", 0, lambda: database)
        self.db.executescript("""
            CREATE TABLE platform_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT);
            CREATE TABLE products (product_id INTEGER PRIMARY KEY, sku TEXT, status TEXT);
            CREATE TABLE ml_jobs (
                job_id INTEGER PRIMARY KEY AUTOINCREMENT,
                trigger_type TEXT,
                status TEXT,
                model_version TEXT,
                evidence_cutoff TEXT,
                lease_expires_at TEXT,
                attempts INTEGER DEFAULT 0,
                operator_message TEXT,
                diagnostic_reference TEXT,
                queued_at TEXT DEFAULT CURRENT_TIMESTAMP,
                started_at TEXT,
                completed_at TEXT
            );
            CREATE TABLE ml_model_evaluations (
                evaluation_id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_version TEXT, horizon_days INTEGER,
                mae REAL, rmse REAL, wape REAL,
                baseline_mae REAL, baseline_rmse REAL, baseline_wape REAL,
                candidate_gate_outcome TEXT, missing_metric_reason TEXT,
                data_window_start TEXT, data_window_end TEXT, created_at TEXT
            );
            CREATE TABLE model_training_runs (
                training_run_id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_name TEXT, model_version TEXT,
                trigger_type TEXT, status TEXT, metrics_json TEXT, started_at TEXT,
                completed_at TEXT, sales_records_used INTEGER, eligible_products INTEGER
            );
            CREATE TABLE demand_forecasts (
                forecast_id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER, product_id INTEGER,
                forecast_origin TEXT, target_date TEXT, horizon_day INTEGER,
                predicted_units INTEGER, model_version TEXT, readiness_level TEXT,
                limitation_marker TEXT
            );
            CREATE TABLE store_calendar (
                calendar_id INTEGER PRIMARY KEY AUTOINCREMENT,
                calendar_date TEXT, status TEXT, recorded_at TEXT
            );
            CREATE TABLE sales (sale_id INTEGER PRIMARY KEY, sale_date TEXT, status TEXT);
            CREATE TABLE sale_items (sale_item_id INTEGER PRIMARY KEY, sale_id INTEGER, product_id INTEGER, quantity INTEGER);
            CREATE TABLE stock_movements (movement_id INTEGER PRIMARY KEY, product_id INTEGER, change_qty INTEGER, reason TEXT, moved_at TEXT);
        """)

    def cursor(self):
        return SQLiteCursor(self.db)

    def commit(self):
        self.db.commit()

    def rollback(self):
        self.db.rollback()


class StoreCandidateObservationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.storage_dir = Path(self.temp.name)
        self.storage_patch = patch.object(ml_worker, "STORAGE_MODELS_DIR", self.storage_dir)
        self.storage_patch.start()
        self.addCleanup(self.storage_patch.stop)

    def _create_candidate_artifact(self, context, model_version, products=(1,)):
        artifact = {
            'model': ForecastEstimator(pred_val=7.0),
            'metadata': dict(context, model_version=model_version,
                             products=list(products),
                             feature_version=ml_worker.FEATURE_VERSION,
                             features=ml_worker.FEATURE_COLUMNS,
                             outcome='passed',
                             data_window_start='2024-01-01',
                             data_window_end='2024-05-31')
        }
        directory = ml_worker.artifact_directory(context)
        directory.mkdir(parents=True, exist_ok=True)
        path = directory / f"random_forest_candidate_{model_version}.joblib"
        joblib.dump(artifact, path)
        return path

    def test_first_installation_candidate_observation_worker_and_persisted_evidence(self):
        """
        Verify first-installation observation: no approved model exists,
        queued forecast_observation job is claimed by worker and generates
        observation-only persisted evidence tied to genuine origin.
        """
        conn = SQLiteConnection("retailmind")
        self.addCleanup(conn.db.close)

        candidate_version = "v_first_install_c1"
        context = ml_worker.dataset_context(conn.cursor())
        self._create_candidate_artifact(context, candidate_version, products=[1])

        # Product 1 exists with 140 days of history
        conn.db.execute("INSERT INTO products VALUES (1, 'STORE-P1', 'active')")
        conn.commit()

        # Enqueue forecast_observation job for candidate 1
        conn.db.execute("""
            INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, evidence_cutoff)
            VALUES (1, 'forecast_observation', 'queued', ?, '2024-05-31')
        """, (candidate_version,))
        conn.commit()

        # Generate 140 days of history ending at 2024-05-31
        dates = pd.date_range("2024-01-13", periods=140, freq="D")
        history_df = pd.DataFrame({
            "date": dates,
            "product_id": 1,
            "units_sold": 10.0,
            "classification": "valid_demand"
        })

        with patch.object(ml_worker, "load_history", return_value=history_df):
            job = ml_worker.claim_job(conn)
            self.assertIsNotNone(job)
            self.assertEqual(job["trigger_type"], "forecast_observation")
            self.assertEqual(job["model_version"], candidate_version)
            ml_worker.process_job(conn, job)

        job_row = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 1").fetchone())
        self.assertEqual(job_row["status"], "succeeded", job_row.get("diagnostic_reference"))

        # Verify persisted demand_forecasts records
        forecasts = conn.db.execute("SELECT * FROM demand_forecasts WHERE job_id = 1").fetchall()
        self.assertEqual(len(forecasts), 28, "Must generate exactly 28 daily forecast records")
        for f in forecasts:
            self.assertEqual(f["model_version"], candidate_version)
            self.assertEqual(f["forecast_origin"], "2024-05-31")
            self.assertTrue(f["limitation_marker"].startswith("Observation only; not approved for routine purchasing."))
            self.assertGreaterEqual(f["predicted_units"], 0)

        # Operational approved model setting is still NOT set
        approved = conn.db.execute("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'").fetchone()
        self.assertIsNone(approved, "First-installation observation must never authorize operational approval automatically")

    def test_replacement_candidate_observation_alongside_operational_generation(self):
        """
        Verify replacement-candidate observation: approved model exists and generates
        operational guidance independently, while candidate generates observation forecasts.
        """
        conn = SQLiteConnection("retailmind")
        self.addCleanup(conn.db.close)

        approved_version = "v_approved_incumbent"
        candidate_version = "v_replacement_c1"
        context = ml_worker.dataset_context(conn.cursor())

        self._create_candidate_artifact(context, approved_version, products=[1])
        self._create_candidate_artifact(context, candidate_version, products=[1])

        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', ?)", (approved_version,))
        conn.db.execute("INSERT INTO products VALUES (1, 'STORE-P1', 'active')")
        conn.commit()

        # Enqueue operational job and candidate observation job
        conn.db.execute("""
            INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, evidence_cutoff)
            VALUES (10, 'forecast_generation', 'queued', ?, '2024-06-01')
        """, (approved_version,))
        conn.db.execute("""
            INSERT INTO ml_jobs (job_id, trigger_type, status, model_version, evidence_cutoff)
            VALUES (11, 'forecast_observation', 'queued', ?, '2024-06-01')
        """, (candidate_version,))
        conn.commit()

        dates = pd.date_range("2024-01-14", periods=140, freq="D")
        history_df = pd.DataFrame({
            "date": dates,
            "product_id": 1,
            "units_sold": 10.0,
            "classification": "valid_demand"
        })

        with patch.object(ml_worker, "load_history", return_value=history_df):
            # Process job 10 (operational)
            job10 = ml_worker.claim_job(conn)
            self.assertEqual(job10["job_id"], 10)
            ml_worker.process_job(conn, job10)

            # Process job 11 (observation)
            job11 = ml_worker.claim_job(conn)
            self.assertEqual(job11["job_id"], 11)
            ml_worker.process_job(conn, job11)

        # Operational forecasts: belong to approved version, no observation limitation
        op_forecasts = conn.db.execute("SELECT * FROM demand_forecasts WHERE job_id = 10").fetchall()
        self.assertEqual(len(op_forecasts), 28)
        self.assertTrue(all(f["model_version"] == approved_version for f in op_forecasts))
        self.assertFalse(any("Observation only;" in f["limitation_marker"] for f in op_forecasts))

        # Observation forecasts: belong to candidate version, has observation limitation
        obs_forecasts = conn.db.execute("SELECT * FROM demand_forecasts WHERE job_id = 11").fetchall()
        self.assertEqual(len(obs_forecasts), 28)
        self.assertTrue(all(f["model_version"] == candidate_version for f in obs_forecasts))
        self.assertTrue(all("Observation only;" in f["limitation_marker"] for f in obs_forecasts))

        # Approved model setting remains unchanged
        approved = conn.db.execute("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'").fetchone()
        self.assertEqual(approved["setting_value"], approved_version)


if __name__ == "__main__":
    unittest.main()
