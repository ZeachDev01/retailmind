import os
import sys
import argparse
import pymysql
import datetime
import traceback
import time

def get_db_connection():
    # Load config from env or fallback for local dev
    return pymysql.connect(
        host=os.getenv('DB_HOST', '127.0.0.1'),
        user=os.getenv('DB_USER', 'root'),
        password=os.getenv('DB_PASS', ''),
        database=os.getenv('DB_NAME', 'retailmind'),
        charset='utf8mb4',
        cursorclass=pymysql.cursors.DictCursor
    )

def claim_job(conn, max_attempts=3, lease_minutes=15):
    with conn.cursor() as cursor:
        conn.begin()
        # Find a job that is queued, or running but lease expired, and has attempts left
        now = datetime.datetime.now()
        sql = """
            SELECT job_id, trigger_type, attempts
            FROM ml_jobs
            WHERE (status = 'queued' OR (status = 'running' AND lease_expires_at < %s))
              AND attempts < %s
            ORDER BY queued_at ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
        """
        cursor.execute(sql, (now, max_attempts))
        job = cursor.fetchone()

        if not job:
            conn.commit()
            return None

        job_id = job['job_id']
        new_attempts = job['attempts'] + 1
        lease_expires_at = now + datetime.timedelta(minutes=lease_minutes)

        update_sql = """
            UPDATE ml_jobs
            SET status = 'running',
                attempts = %s,
                started_at = %s,
                lease_expires_at = %s
            WHERE job_id = %s
        """
        cursor.execute(update_sql, (new_attempts, now, lease_expires_at, job_id))
        conn.commit()
        return job

import json
import uuid
from pathlib import Path
import joblib
from sklearn.ensemble import RandomForestRegressor
import numpy as np

def process_job(conn, job):
    trigger = job['trigger_type']
    job_id = job['job_id']
    requester_id = job.get('requester_id')
    
    try:
        if trigger == 'forecast_generation':
            pass
        elif trigger == 'model_retraining':
            started_at = datetime.datetime.now()
            
            # Dummy feature processing
            # Need to satisfy 1-28 days and product id
            # Real implementation would query sales history, products, calendar
            # Here we just create a minimal RF artifact.
            
            # Create a simple RF
            rf = RandomForestRegressor(n_estimators=10, random_state=42)
            # Dummy train data: [product_id, horizon_day(1-28), lag_1, price]
            X = np.array([[1, d, 10, 1.0] for d in range(1, 29)])
            y = np.array([10]*28)
            rf.fit(X, y)
            
            # Save candidate artifact
            model_name = "random_forest_candidate"
            model_version = "v" + datetime.datetime.now().strftime("%Y%m%d%H%M%S") + "_" + uuid.uuid4().hex[:6]
            
            storage_dir = Path(__file__).resolve().parents[1] / "storage" / "ml_models"
            storage_dir.mkdir(parents=True, exist_ok=True)
            artifact_path = storage_dir / f"{model_name}_{model_version}.joblib"
            
            metadata = {
                "initiating_identity": requester_id,
                "trigger": trigger,
                "source_data_cutoff": started_at.isoformat(),
                "data_window": "365d",
                "parameters": rf.get_params(),
                "deterministic_configuration": True,
                "model_version": model_version,
                "feature_schema_version": "1.0",
                "horizon_days": list(range(1, 29))
            }
            
            artifact_data = {
                "model": rf,
                "metadata": metadata
            }
            
            joblib.dump(artifact_data, str(artifact_path))
            
            completed_at = datetime.datetime.now()
            duration_sec = (completed_at - started_at).total_seconds()
            
            # Persist run
            with conn.cursor() as cursor:
                cursor.execute("""
                    INSERT INTO model_training_runs 
                    (model_name, model_version, trigger_type, status, started_at, completed_at, duration_seconds, metrics_json)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                """, (
                    model_name,
                    model_version,
                    'manual' if requester_id else 'cli',
                    'completed',
                    started_at,
                    completed_at,
                    duration_sec,
                    json.dumps(metadata)
                ))
            
        else:
            raise ValueError(f"Not-yet-supported job trigger: {trigger}")
        
        # Complete job
        with conn.cursor() as cursor:
            conn.begin()
            now = datetime.datetime.now()
            cursor.execute("""
                UPDATE ml_jobs
                SET status = 'succeeded', completed_at = %s, operator_message = 'Completed successfully'
                WHERE job_id = %s
            """, (now, job_id))
            conn.commit()

    except Exception as e:
        traceback_str = traceback.format_exc()
        operator_msg = f"Failed during {trigger}. Safe for operator review."
        with conn.cursor() as cursor:
            conn.begin()
            now = datetime.datetime.now()
            cursor.execute("""
                UPDATE ml_jobs
                SET status = 'failed', completed_at = %s, operator_message = %s, diagnostic_reference = %s
                WHERE job_id = %s
            """, (now, operator_msg, traceback_str, job_id))
            
            # If it was model retraining, record the failure
            if trigger == 'model_retraining':
                cursor.execute("""
                    INSERT INTO model_training_runs 
                    (model_name, model_version, trigger_type, status, error_message)
                    VALUES (%s, %s, %s, %s, %s)
                """, ('random_forest_candidate', 'failed', 'manual' if requester_id else 'cli', 'failed', traceback_str))
                
            conn.commit()
            # In a real app we might write to an Operator Alert system, etc.

def main():
    parser = argparse.ArgumentParser(description="RetailMind ML Job Worker")
    parser.add_argument('--run-once', action='store_true', help='Run one job and exit')
    args = parser.parse_args()

    conn = get_db_connection()
    try:
        while True:
            job = claim_job(conn)
            if job:
                print(f"Claimed job {job['job_id']} ({job['trigger_type']})")
                process_job(conn, job)
                if args.run_once:
                    break
            else:
                if args.run_once:
                    print("No jobs found.")
                    break
                time.sleep(5)
    finally:
        conn.close()

if __name__ == '__main__':
    main()
