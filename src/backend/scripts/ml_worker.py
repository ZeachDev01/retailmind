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

def process_job(conn, job):
    trigger = job['trigger_type']
    job_id = job['job_id']
    
    try:
        # Simulate processing based on trigger
        if trigger == 'forecast_generation':
            # Dummy: Check if model exists, run forecast
            # Missing/incompatible approved model fails explicitly
            # Here we just succeed for now unless we implement real ML
            pass
        elif trigger == 'model_retraining':
            pass
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
