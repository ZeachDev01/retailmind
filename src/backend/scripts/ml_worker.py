import os
import sys
import argparse
import time
from datetime import datetime, timedelta
from pathlib import Path
import mysql.connector
import uuid
import joblib

ROOT_DIR = Path(__file__).resolve().parents[3]
MODEL_PATH = ROOT_DIR / "src" / "backend" / "storage" / "ml_models" / "random_forest_candidate_v20261009082757_4f75e5.joblib"

def get_connection():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "127.0.0.1"),
        port=int(os.getenv("DB_PORT", "3306")),
        user=os.getenv("DB_USER", "root"),
        password=os.getenv("DB_PASSWORD", ""),
        database=os.getenv("DB_NAME", "inventory_system"),
        charset=os.getenv("DB_CHARSET", "utf8mb4"),
        autocommit=False,
    )

def process_jobs():
    conn = get_connection()
    cursor = conn.cursor(dictionary=True)
    try:
        cursor.execute("""
            SELECT job_id, trigger_type 
            FROM ml_jobs 
            WHERE status = 'queued' OR (status = 'running' AND lease_expires_at < NOW())
            ORDER BY queued_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED
        """)
        job = cursor.fetchone()
        if not job:
            conn.commit()
            return

        job_id = job['job_id']
        trigger = job['trigger_type']
        
        cursor.execute("UPDATE ml_jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1, lease_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE job_id = %s", (job_id,))
        conn.commit()
        
        if trigger == 'forecast_generation':
            run_forecast_generation(conn, cursor, job_id)
        
        cursor.execute("UPDATE ml_jobs SET status = 'succeeded', completed_at = NOW() WHERE job_id = %s", (job_id,))
        conn.commit()
    except Exception as e:
        conn.rollback()
        print(f"Error processing job: {e}")
    finally:
        cursor.close()
        conn.close()

def run_forecast_generation(conn, cursor, job_id):
    today = datetime.now().date()
    
    # Load model
    if not MODEL_PATH.exists():
        raise RuntimeError(f"Model artifact not found at {MODEL_PATH}")
    
    artifact = joblib.load(MODEL_PATH)
    model = artifact['model']
    model_version = artifact['metadata'].get('model_version', 'v_unknown')
    
    # Get all products
    cursor.execute("SELECT id, price FROM products WHERE branch_id = 1")
    products = cursor.fetchall()
    
    for p in products:
        product_id = p['id']
        price = p['price'] or 0.0
        # Dummy lag_1 for now, in a real app this would query demand history
        lag_1 = 10.0 
        
        # We need to predict 28 days
        # The expected features are [product_id, horizon_day, lag_1, price]
        import numpy as np
        X = np.array([[product_id, day, lag_1, price] for day in range(1, 29)])
        predictions = model.predict(X)
        
        for i, pred in enumerate(predictions):
            target_date = today + timedelta(days=i+1)
            predicted_units = max(0, int(round(pred)))
            
            cursor.execute("""
                INSERT INTO demand_forecasts 
                (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) 
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """, (job_id, product_id, today, target_date, i+1, predicted_units, model_version, 'High'))
    
    conn.commit()

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true")
    args = parser.parse_args()

    if args.once:
        process_jobs()
    else:
        while True:
            process_jobs()
            time.sleep(10)
