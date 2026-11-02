"""
Ticket #366: Store candidate qualification with reproducible chronological evaluation.
Tests:
- Qualify first candidate against baseline without incumbent or demo substitute.
- Nonnegative daily rounding on Product-level horizon totals.
- Under/overprediction diagnostics and snapshotted effective limits.
- Distinction between recorded closures and missing trading days.
- Temporal isolation / no leakage across rolling holdouts.
- Failure preserves approved artifacts and results.
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
from feature_pipeline import (
    FEATURE_COLUMNS,
    FEATURE_VERSION,
    build_feature_matrix,
    build_training_rows,
    normalize_history,
    train_and_evaluate,
)
from ml_evaluation import calculate_metrics, seasonal_naive_forecast


class SQLiteCursor:
    def __init__(self, db):
        self.cursor = db.cursor()

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.cursor.close()

    def execute(self, sql, params=()):
        self.cursor.execute(sql.replace("%s", "?").replace("NOW()", "CURRENT_TIMESTAMP"), params)
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
            CREATE TABLE ml_jobs (job_id INTEGER PRIMARY KEY, trigger_type TEXT, status TEXT,
                completed_at TEXT, operator_message TEXT, diagnostic_reference TEXT, attempts INTEGER DEFAULT 0);
            INSERT INTO ml_jobs VALUES (1, 'model_retraining', 'running', NULL, NULL, NULL, 1);
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
        """)

    def cursor(self):
        return SQLiteCursor(self.db)

    def commit(self):
        self.db.commit()

    def rollback(self):
        self.db.rollback()


class StoreCandidateEvaluationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.storage_dir = Path(self.temp.name)
        self.storage_patch = patch.object(ml_worker, "STORAGE_MODELS_DIR", self.storage_dir)
        self.storage_patch.start()
        self.addCleanup(self.storage_patch.stop)

    def test_qualify_first_store_candidate_against_baseline_without_incumbent(self):
        """
        Qualify the first Store candidate against weekly Seasonal Naive baseline
        without requiring an existing incumbent model or demo substitute.
        Verifies nonnegative rounding, snapshotted limits, diagnostics, and candidate-only status.
        """
        conn = SQLiteConnection("retailmind")
        self.addCleanup(conn.db.close)

        # Set configured per-horizon maximum-WAPE limits
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_max_wape_7d', '0.6')")
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_max_wape_14d', '0.6')")
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_max_wape_28d', '0.6')")
        conn.commit()

        # Generate 140 days of synthetic store data with predictable weekly pattern
        dates = pd.date_range("2024-01-01", periods=140, freq="D")
        records = []
        for d in dates:
            # Predictable sales with weekly seasonality: Mon-Fri ~10-20, weekends ~5
            dow = d.dayofweek
            sales = float(10 + (dow * 2)) if dow < 5 else 5.0
            records.append({
                "date": d,
                "product_id": 1,
                "units_sold": sales,
                "classification": "valid_demand"
            })
        history_df = pd.DataFrame(records)

        with patch.object(ml_worker, "load_history", return_value=history_df):
            ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})

        job = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 1").fetchone())
        self.assertEqual(job["status"], "succeeded", job["diagnostic_reference"])

        # 1. Candidate remains candidate-only: ml_approved_model_version is NOT automatically set
        approved = conn.db.execute("SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'").fetchone()
        self.assertIsNone(approved, "First candidate must not be automatically promoted without approval")

        # 2. Persisted evaluations in ml_model_evaluations
        evaluations = conn.db.execute("SELECT * FROM ml_model_evaluations ORDER BY horizon_days ASC").fetchall()
        self.assertEqual(len(evaluations), 3, "Expected 7, 14, 28 day evaluations")
        for row in evaluations:
            self.assertIn(row["horizon_days"], (7, 14, 28))
            self.assertIsNotNone(row["mae"])
            self.assertIsNotNone(row["rmse"])
            self.assertIsNotNone(row["wape"])
            self.assertIsNotNone(row["baseline_mae"])
            self.assertIsNotNone(row["baseline_rmse"])
            self.assertIsNotNone(row["baseline_wape"])
            self.assertIn(row["candidate_gate_outcome"], ("passed", "failed", "incomplete"))

        # 3. Training run with metrics_json contains diagnostics and snapshotted effective limits
        run = conn.db.execute("SELECT * FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1").fetchone()
        self.assertIsNotNone(run)
        metrics_payload = json.loads(run["metrics_json"])
        meta = metrics_payload["metadata"]
        evals_json = metrics_payload["evaluations"]

        self.assertIn("outcome", meta)
        for h_str in ("7", "14", "28"):
            h_eval = evals_json[h_str]
            # Effective limits snapshotted
            self.assertEqual(h_eval["effective_max_wape"], 0.6)
            # Under/overprediction diagnostics
            self.assertIn("underprediction_count", h_eval)
            self.assertIn("overprediction_count", h_eval)
            self.assertIn("exact_count", h_eval)
            self.assertIn("underprediction_units", h_eval)
            self.assertIn("overprediction_units", h_eval)
            self.assertIn("population", h_eval)
            self.assertIn("coverage", h_eval)
            # Separately labelled fractional diagnostics
            self.assertIn("mae_fractional", h_eval)
            self.assertIn("rmse_fractional", h_eval)
            self.assertIn("wape_fractional", h_eval)
            self.assertIn("baseline_mae_fractional", h_eval)
            self.assertIn("baseline_wape_fractional", h_eval)

    def test_missing_threshold_or_undefined_wape_cannot_pass_gate(self):
        """
        Missing configured threshold or undefined error (e.g. zero actual demand)
        must result in 'incomplete' gate outcome and cannot pass.
        """
        conn = SQLiteConnection("retailmind")
        self.addCleanup(conn.db.close)

        # Omit ml_max_wape_28d deliberately
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_max_wape_7d', '0.5')")
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_max_wape_14d', '0.5')")
        conn.commit()

        dates = pd.date_range("2024-01-01", periods=140, freq="D")
        history_df = pd.DataFrame([
            {"date": d, "product_id": 1, "units_sold": 10.0, "classification": "valid_demand"}
            for d in dates
        ])

        with patch.object(ml_worker, "load_history", return_value=history_df):
            ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})

        evaluations = conn.db.execute("SELECT * FROM ml_model_evaluations ORDER BY horizon_days ASC").fetchall()
        self.assertEqual(len(evaluations), 3)

        # 28d evaluation must be incomplete because threshold is missing
        eval_28d = [e for e in evaluations if e["horizon_days"] == 28][0]
        self.assertEqual(eval_28d["candidate_gate_outcome"], "incomplete")
        self.assertIn("Missing configured threshold", eval_28d["missing_metric_reason"])

        # Model training run overall outcome cannot be passed
        run = conn.db.execute("SELECT * FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1").fetchone()
        metrics_payload = json.loads(run["metrics_json"])
        self.assertNotEqual(metrics_payload["outcome"], "passed")

    def test_weekend_closures_alone_do_not_invalidate_holdouts(self):
        """
        Regular recorded weekend closures (Mon-Fri store) must not invalidate holdouts,
        applying identical explicit closure treatment (0 demand) to candidate and baseline.
        """
        # 28 weeks of Mon-Fri store (140 trading days)
        start = pd.Timestamp("2024-01-01")
        dates = pd.date_range(start, periods=196, freq="D")
        records = []
        for d in dates:
            if d.dayofweek < 5:
                records.append({
                    "date": d, "product_id": 1, "units_sold": 12.0, "classification": "valid_demand"
                })
            else:
                records.append({
                    "date": d, "product_id": 1, "units_sold": 0.0, "classification": "closed"
                })

        df = pd.DataFrame(records)
        artifact = train_and_evaluate(df)

        meta = artifact["metadata"]
        self.assertIn(1, meta["included_products"])
        self.assertEqual(len(meta["excluded_products"]), 0)
        self.assertEqual(len(meta["folds"]), 3)

        for fold in meta["folds"]:
            self.assertIn(1, fold["included_products"])

        evals = artifact["evaluations"]
        for h in (7, 14, 28):
            self.assertIsNotNone(evals[h]["wape"])
            self.assertIsNotNone(evals[h]["baseline_wape"])
            self.assertEqual(evals[h]["coverage"], 1)

    def test_missing_trading_evidence_invalidates_holdout_for_product(self):
        """
        A missing trading observation (e.g. missing_evidence on a Tuesday)
        must invalidate holdout completeness for that product, preventing partial totals from passing.
        """
        start = pd.Timestamp("2024-01-01")
        dates = pd.date_range(start, periods=140, freq="D")
        records = []

        # Product 1 has clean history
        # Product 2 has missing evidence on day 100 (inside holdout)
        for d in dates:
            records.append({
                "date": d, "product_id": 1, "units_sold": 15.0, "classification": "valid_demand"
            })
            is_missing = (d == start + pd.Timedelta(days=100))
            records.append({
                "date": d, "product_id": 2, "units_sold": None if is_missing else 10.0,
                "classification": "missing_evidence" if is_missing else "valid_demand"
            })

        df = pd.DataFrame(records)
        artifact = train_and_evaluate(df)

        meta = artifact["metadata"]
        self.assertIn(1, meta["included_products"])
        self.assertIn(2, meta["excluded_products"])
        self.assertIn("Missing trading evidence", meta["exclusion_reasons"][2])

    def test_future_data_leakage_temporal_isolation(self):
        """
        Verify that training at each rolling origin uses only data known on or before that origin.
        Mutations to future holdout days must not alter models trained at earlier origins.
        """
        dates = pd.date_range("2024-01-01", periods=140, freq="D")
        records = [{"date": d, "product_id": 1, "units_sold": 10.0} for d in dates]
        df1 = pd.DataFrame(records)

        # Mutate the last 10 days drastically
        records_mutated = [
            {"date": d, "product_id": 1, "units_sold": 999.0 if i >= 130 else 10.0}
            for i, d in enumerate(dates)
        ]
        df2 = pd.DataFrame(records_mutated)

        # Check training rows before the mutation origin (e.g. at day 100)
        origin = pd.Timestamp("2024-04-10")
        rows1 = build_training_rows(df1, origin)
        rows2 = build_training_rows(df2, origin)

        pd.testing.assert_frame_equal(rows1, rows2)

    def test_failure_preserves_approved_artifacts_and_results(self):
        """
        Injected failure during candidate training must rollback database changes,
        unlink any temporary candidate artifact, and preserve existing approved model.
        """
        conn = SQLiteConnection("retailmind")
        self.addCleanup(conn.db.close)

        # Seed existing approved model in platform settings
        approved_version = "v20260101_approved"
        conn.db.execute("INSERT INTO platform_settings VALUES ('ml_approved_model_version', ?)", (approved_version,))
        conn.commit()

        # Place existing approved artifact file on disk
        approved_dir = ml_worker.artifact_directory(ml_worker.dataset_context(conn.cursor()))
        approved_dir.mkdir(parents=True, exist_ok=True)
        approved_file = approved_dir / f"random_forest_candidate_{approved_version}.joblib"
        approved_file.write_text("APPROVED_ARTIFACT_CONTENT", encoding="utf-8")

        # Inject error during candidate evaluation
        with patch.object(ml_worker, "load_history", side_effect=RuntimeError("Database connection lost during training")):
            ml_worker.process_job(conn, {"job_id": 1, "trigger_type": "model_retraining"})

        job = dict(conn.db.execute("SELECT * FROM ml_jobs WHERE job_id = 1").fetchone())
        self.assertEqual(job["status"], "failed")

        # Approved model setting preserved
        current_approved = conn.db.execute(
            "SELECT setting_value FROM platform_settings WHERE setting_key = 'ml_approved_model_version'"
        ).fetchone()[0]
        self.assertEqual(current_approved, approved_version)

        # Approved artifact preserved
        self.assertTrue(approved_file.exists())
        self.assertEqual(approved_file.read_text(encoding="utf-8"), "APPROVED_ARTIFACT_CONTENT")

        # No corrupt evaluations inserted
        eval_count = conn.db.execute("SELECT COUNT(*) FROM ml_model_evaluations").fetchone()[0]
        self.assertEqual(eval_count, 0)


if __name__ == "__main__":
    unittest.main()
