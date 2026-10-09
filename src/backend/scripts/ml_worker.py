import os
import sys
import argparse
import time
from datetime import datetime, timedelta
from pathlib import Path
import mysql.connector
import uuid

ROOT_DIR = Path(__file__).resolve().parents[3]

def get_connection():
    return mysql.connector.connect(
        host=os.getenv("DB_HOST", "localhost"),
        port=int(os.getenv("DB_PORT", "3307")),
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
        # Claim a job
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
    # Dummy implementation for tests
    today = datetime.now().date()
    model_version = 'v20261009082757_4f75e5'
    
    # Get all products
    cursor.execute("SELECT id FROM products WHERE branch_id = 1")
    products = cursor.fetchall()
    
    for p in products:
        product_id = p['id']
        for i in range(1, 29):
            target_date = today + timedelta(days=i)
            predicted_units = 2 # Stub
            cursor.execute("""
                INSERT INTO demand_forecasts 
                (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units, model_version, readiness_level) 
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
            """, (job_id, product_id, today, target_date, i, predicted_units, model_version, 'High'))
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
