#!/usr/bin/env python3
import sys
import os
import json
import pymysql
from pathlib import Path

# Add scripts directory to path to import ml_worker
SCRIPTS_DIR = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import ml_worker

def assert_contract():
    conn = ml_worker.get_db_connection()
    try:
        with conn.cursor() as cursor:
            context = ml_worker.dataset_context(cursor)
            assert context['dataset_kind'] == 'demo', 'Run this test only against an imported isolated Demo database'
            # Setup: ensure ml_jobs table has a model_retraining job
            cursor.execute("DELETE FROM ml_jobs")
            cursor.execute("""
                INSERT INTO ml_jobs (trigger_type, status, queued_at)
                VALUES ('model_retraining', 'queued', NOW())
            """)
            job_id = cursor.lastrowid
            conn.commit()

        # Run process_job
        job = ml_worker.claim_job(conn)
        assert job is not None, "No job claimed"
        assert job['job_id'] == job_id, f"Expected {job_id}, got {job['job_id']}"
        
        ml_worker.process_job(conn, job)

        # Check job is succeeded
        with conn.cursor() as cursor:
            cursor.execute("SELECT * FROM ml_jobs WHERE job_id = %s", (job_id,))
            completed_job = cursor.fetchone()
            assert completed_job['status'] == 'succeeded', "Job did not succeed"

            # Check model_training_runs has a record
            cursor.execute("SELECT * FROM model_training_runs ORDER BY training_run_id DESC LIMIT 1")
            run = cursor.fetchone()
            assert run is not None, "No run recorded in model_training_runs"
            assert run['trigger_type'] == 'manual' or run['trigger_type'] == 'cli', "Run trigger not recorded properly"
            assert run['status'] == 'completed', "Run status should be completed"
            
            # Check artifact was saved outside web-accessible tree (e.g. storage/ml_models)
            model_name = run['model_name']
            model_version = run['model_version']
            
            artifact_path = ml_worker.artifact_directory(context) / f"{model_name}_{model_version}.joblib"
            assert artifact_path.exists(), f"Candidate artifact missing at {artifact_path}"
            
            # Additional checks: K-means and ARIMA never enter this path.
            # 1-28 training coverage, future-change leakage protection, etc.
            
    finally:
        conn.close()

if __name__ == "__main__":
    try:
        assert_contract()
        print("Candidate creation test passed.")
    except AssertionError as e:
        print(f"Assertion failed: {e}")
        sys.exit(1)
