import os
import sys
import argparse
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path
import json
import uuid
import hashlib
import re
import subprocess
import getpass
import pandas as pd
import joblib
import numpy as np

# Ensure scripts directory is in sys.path for local imports
SCRIPTS_DIR = Path(__file__).resolve().parent
if str(SCRIPTS_DIR) not in sys.path:
    sys.path.insert(0, str(SCRIPTS_DIR))

from feature_pipeline import (FEATURE_COLUMNS, FEATURE_VERSION, build_feature_matrix,
                              history_at, normalize_history, train_and_evaluate)

ROOT_DIR = Path(__file__).resolve().parents[3]
STORAGE_MODELS_DIR = ROOT_DIR / "src" / "backend" / "storage" / "ml_models"

RUNNER_CONFIG_PATH = ROOT_DIR / "src" / "backend" / "config" / "forecasting_runner.json"
if RUNNER_CONFIG_PATH.exists():
    try:
        with open(RUNNER_CONFIG_PATH, "r", encoding="utf-8") as _rcf:
            _runner_cfg = json.load(_rcf)
            if _runner_cfg.get("model_storage"):
                STORAGE_MODELS_DIR = Path(_runner_cfg["model_storage"])
    except Exception:
        pass

# One shared ADR-0005 band definition for PHP and this worker.
READINESS_BANDS_PATH = Path(__file__).resolve().parent.parent / "config" / "forecast_readiness_bands.json"


def load_readiness_bands(path=None):
    with open(path or READINESS_BANDS_PATH, "r", encoding="utf-8") as handle:
        return json.load(handle)


def resolve_readiness(days, qualified=True, bands=None):
    """Return (level, limitation_marker) for a usable-history day count."""
    bands = bands if bands is not None else load_readiness_bands()
    thresholds = bands["thresholds"]
    if days < thresholds["low"]:
        key = "insufficient"
    elif days < thresholds["medium"]:
        key = "low"
    elif days < thresholds["high"]:
        key = "medium"
    elif qualified or not bands.get("high_requires_qualification", False):
        key = "high"
    else:
        key = "medium"
    band = bands["bands"][key]
    return band["level"], band["limitation_marker"]


def get_connection():
    db_host = os.getenv("DB_HOST", "127.0.0.1")
    if db_host.lower() not in ("127.0.0.1", "localhost", "::1"):
        raise PermissionError(f"Forecasting database interface must be local; remote host '{db_host}' is not permitted.")
    try:
        import pymysql
        import pymysql.cursors
        connection = pymysql.connect(
            host=db_host,
            port=int(os.getenv("DB_PORT", "3306")),
            user=os.getenv("DB_USER", "root"),
            password=os.getenv("DB_PASSWORD", os.getenv("DB_PASS", "")),
            database=os.getenv("DB_NAME", "inventory_system"),
            charset=os.getenv("DB_CHARSET", "utf8mb4"),
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=False,
        )
    except ImportError:
        import mysql.connector
        connection = mysql.connector.connect(
            host=db_host,
            port=int(os.getenv("DB_PORT", "3306")),
            user=os.getenv("DB_USER", "root"),
            password=os.getenv("DB_PASSWORD", os.getenv("DB_PASS", "")),
            database=os.getenv("DB_NAME", "inventory_system"),
            charset=os.getenv("DB_CHARSET", "utf8mb4"),
            autocommit=False,
        )
    with connection.cursor() as cursor:
        cursor.execute("SET time_zone = '+08:00'")
    return connection

get_db_connection = get_connection

def recover_expired_leases(conn):
    """Fail jobs that exceeded max attempts with expired lease."""
    with conn.cursor() as cursor:
        cursor.execute("""
            UPDATE ml_jobs 
            SET status = 'failed', 
                completed_at = NOW(),
                operator_message = 'Job failed: lease expired and maximum retries (3) exceeded.',
                diagnostic_reference = 'LEASE_TIMEOUT_EXHAUSTED'
            WHERE status = 'running' 
              AND lease_expires_at < NOW()
              AND attempts >= 3
        """)
        conn.commit()

def claim_job(conn):
    """Atomically claim a queued job or eligible expired lease."""
    recover_expired_leases(conn)
    with conn.cursor() as cursor:
        try:
            cursor.execute("""
                SELECT job_id, trigger_type, attempts, model_version, evidence_cutoff 
                FROM ml_jobs 
                WHERE status = 'queued' 
                   OR (status = 'running' AND lease_expires_at < NOW() AND attempts < 3)
                ORDER BY queued_at ASC 
                LIMIT 1 
                FOR UPDATE
            """)
        except Exception:
            cursor.execute("""
                SELECT job_id, trigger_type, attempts 
                FROM ml_jobs 
                WHERE status = 'queued' 
                   OR (status = 'running' AND lease_expires_at < NOW() AND attempts < 3)
                ORDER BY queued_at ASC 
                LIMIT 1 
                FOR UPDATE
            """)
        job = cursor.fetchone()
        if not job:
            conn.commit()
            return None

        # Handle tuple vs dict return
        if isinstance(job, (tuple, list)):
            job = {'job_id': job[0], 'trigger_type': job[1], 'attempts': job[2],
                   'model_version': job[3] if len(job) > 3 else None,
                   'evidence_cutoff': job[4] if len(job) > 4 else None}

        job_id = job['job_id']
        cursor.execute("""
            UPDATE ml_jobs 
            SET status = 'running', 
                started_at = NOW(), 
                attempts = attempts + 1, 
                lease_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) 
            WHERE job_id = %s
        """, (job_id,))
        conn.commit()
        job['attempts'] += 1
        return job

def renew_lease(conn, job_id):
    with conn.cursor() as cursor:
        cursor.execute("""
            UPDATE ml_jobs
            SET lease_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
            WHERE job_id = %s AND status = 'running'
        """, (job_id,))
    conn.commit()

def process_job(conn, job):
    job_id = job['job_id']
    trigger = job['trigger_type']
    artifact_file = None
    with conn.cursor() as cursor:
        try:
            renew_lease(conn, job_id)
            if trigger == 'forecast_generation':
                run_forecast_generation(conn, cursor, job_id, evidence_cutoff=job.get('evidence_cutoff'))
            elif trigger == 'forecast_observation':
                obs_ver = job.get('observation_version') or job.get('model_version')
                if not obs_ver:
                    cursor.execute("SELECT model_version FROM ml_jobs WHERE job_id = %s", (job_id,))
                    row = cursor.fetchone()
                    obs_ver = (row['model_version'] if isinstance(row, dict) else row[0]) if row else None
                if not obs_ver:
                    raise ValueError("Observation generation requires an explicit candidate version")
                evidence_cutoff = job.get('evidence_cutoff')
                if not evidence_cutoff:
                    try:
                        cursor.execute("SELECT evidence_cutoff FROM ml_jobs WHERE job_id = %s", (job_id,))
                        row_c = cursor.fetchone()
                        evidence_cutoff = (row_c['evidence_cutoff'] if isinstance(row_c, dict) else row_c[0]) if row_c else None
                    except Exception:
                        pass
                run_forecast_generation(conn, cursor, job_id, observation_version=obs_ver, evidence_cutoff=evidence_cutoff)
            elif trigger == 'model_retraining':
                renew_lease(conn, job_id)
                artifact_file = run_model_retraining(conn, cursor, job_id)
            else:
                raise ValueError(f"Unknown job trigger type: {trigger}")

            renew_lease(conn, job_id)
            cursor.execute("""
                UPDATE ml_jobs 
                SET status = 'succeeded', 
                    completed_at = NOW(), 
                    operator_message = 'Job completed successfully.' 
                WHERE job_id = %s
            """, (job_id,))
            conn.commit()
        except Exception as e:
            conn.rollback()
            if artifact_file is not None:
                artifact_file.unlink(missing_ok=True)
            diag_ref = f"ERR-{uuid.uuid4().hex[:8]}"
            err_msg = str(e)
            blocked_markers = [
                'No approved forecasting model version configured',
                'Demo/Store model artifacts cannot cross dataset boundaries',
                'Model artifact belongs to another dataset',
                'Model feature contract is obsolete',
                'Model artifact version does not match',
                'No active products have 140 accumulated usable days for training',
                'No active products have 140 consecutive usable days for training',
                'No active products have 140',
                'No trained products have 28 consecutive observed days for generation',
                'No trained products have supported feature windows',
                'requires 28 consecutive observed days',
                'Invalid observation model version',
                'Imported Demo history does not match',
            ]
            is_blocked = any(m.lower() in err_msg.lower() for m in blocked_markers)
            attempts = job.get('attempts', 1)
            try:
                if is_blocked:
                    cursor.execute("""
                        UPDATE ml_jobs 
                        SET status = 'failed', 
                            completed_at = NOW(), 
                            operator_message = %s,
                            diagnostic_reference = %s
                        WHERE job_id = %s
                    """, (f"Prerequisite blocked: {err_msg}", f"BLOCKED:{err_msg} [{diag_ref}]", job_id))
                elif attempts >= 3:
                    cursor.execute("""
                        UPDATE ml_jobs 
                        SET status = 'failed', 
                            completed_at = NOW(), 
                            operator_message = 'Job failed: maximum retry attempts (3) exceeded.',
                            diagnostic_reference = %s
                        WHERE job_id = %s
                    """, (f"EXHAUSTED:{err_msg} [{diag_ref}]", job_id))
                else:
                    cursor.execute("""
                        UPDATE ml_jobs 
                        SET status = 'failed', 
                            completed_at = NOW(), 
                            operator_message = 'Job encountered a temporary error and will be retried on a later schedule tick.',
                            diagnostic_reference = %s
                        WHERE job_id = %s
                    """, (f"TRANSIENT:{err_msg} [{diag_ref}]", job_id))
                conn.commit()
            except Exception:
                pass
            print(f"Error processing job {job_id}: {e} (Ref: {diag_ref})")

def process_jobs():
    conn = get_connection()
    try:
        job = claim_job(conn)
        if job:
            process_job(conn, job)
    finally:
        conn.close()

def dataset_context(cursor):
    cursor.execute("SELECT DATABASE() AS database_name")
    row = cursor.fetchone()
    database = row['database_name'] if isinstance(row, dict) else row[0]
    cursor.execute("SELECT setting_value FROM platform_settings WHERE setting_key = 'demo_import_metadata'")
    row = cursor.fetchone()
    imported = json.loads(row['setting_value'] if isinstance(row, dict) else row[0]) if row else None
    isolated = any(marker in database.lower() for marker in ('demo', 'kaggle', 'test'))
    if imported is not None:
        if not isolated or os.getenv('APP_ENV', '').lower() in ('production', 'live', 'infinityfree'):
            raise ValueError("Demo evidence requires an isolated non-operational database")
        is_m5 = imported.get('source_kind') == 'm5_daily'
        expected_store = 'CA_1' if is_m5 else ('UCI_ONLINE_RETAIL' if imported.get('source_kind') == 'uci_online_retail' else 'S001')
        if imported.get('store_id') != expected_store or imported.get('products_mapped') not in ((20, 50) if is_m5 else (20,)):
            raise ValueError("Demo selection must match its declared source and product count")
    elif any(marker in database.lower() for marker in ('demo', 'kaggle')):
        raise ValueError("Demo database has no imported demand provenance")
    return {
        'dataset_kind': 'demo' if imported is not None else 'store',
        'dataset_database': database,
        'dataset_host': os.getenv('DB_HOST', '127.0.0.1'),
        'dataset_port': os.getenv('DB_PORT', '3306'),
        'source': imported,
        'history_cutoff': imported['date_window'][1] if imported else
                          str(datetime.now(timezone(timedelta(hours=8))).date() - timedelta(days=1)),
    }


def artifact_directory(context):
    identity = ':'.join(str(context[k]) for k in ('dataset_host', 'dataset_port', 'dataset_database'))
    return STORAGE_MODELS_DIR / context['dataset_kind'] / hashlib.sha256(identity.encode()).hexdigest()[:16]


def load_history(cursor, context, training=False):
    if context['dataset_kind'] == 'demo':
        if context['source'].get('source_kind') in ('uci_online_retail', 'm5_daily'):
            cursor.execute('''SELECT sale_date AS date, product_id, quantity AS units_sold
                FROM forecast_sales_imports ORDER BY product_id, sale_date''')
        else:
            cursor.execute("""
            SELECT DATE(s.sale_date) AS date, si.product_id, SUM(si.quantity) AS units_sold
            FROM sales s JOIN sale_items si ON si.sale_id = s.sale_id
            JOIN products p ON p.product_id = si.product_id
            WHERE s.cashier_id = 999 AND si.product_id BETWEEN 1 AND 20
            GROUP BY DATE(s.sale_date), si.product_id
            ORDER BY si.product_id, date
            """)
        rows = cursor.fetchall()
        data = normalize_history(pd.DataFrame(rows, columns=['date', 'product_id', 'units_sold']))
        source = context['source']
        if set(data.product_id) != set(range(1, source['products_mapped'] + 1)) or len(data) != source.get('total_records'):
            raise ValueError("Imported Demo history does not match its recorded product/row coverage")
        if [str(data.date.min().date()), str(data.date.max().date())] != source.get('date_window'):
            raise ValueError("Imported Demo history does not match its recorded date window")
    else:
        # Reuse Store calendar, stockout, and reversal rules; no guessed zero days.
        environment = os.environ.copy()
        environment.update({
            'DATABASE_ENV': 'local', 'DB_NAME': context['dataset_database'],
            'DB_HOST': context['dataset_host'], 'DB_PORT': context['dataset_port'],
            'DB_USER': os.getenv('DB_USER', 'root'),
            'DB_PASSWORD': os.getenv('DB_PASSWORD', os.getenv('DB_PASS', '')),
        })
        command = ['php', str(SCRIPTS_DIR / 'export_demand_history.php'), context['dataset_database'], context['history_cutoff']]
        if training:
            command.append('--training')
        result = subprocess.run(
            command,
            env=environment, capture_output=True, text=True, check=True,
        )
        exported = json.loads(result.stdout)
        if training:
            context['training_eligibility'] = exported['eligibility']
            exported = exported['history']
            if not exported:
                raise ValueError('No active products have 140 accumulated usable days for training')
        data = normalize_history(pd.DataFrame(exported))
    return data


def run_forecast_generation(conn, cursor, job_id, observation_version=None, evidence_cutoff=None):
    context = dataset_context(cursor)
    if evidence_cutoff:
        context['history_cutoff'] = str(evidence_cutoff)
    cursor.execute("""
        SELECT setting_value FROM platform_settings
        WHERE setting_key = 'ml_approved_model_version'
          AND setting_value IS NOT NULL AND setting_value != ''
    """)
    row = cursor.fetchone()
    version = (row['setting_value'] if isinstance(row, dict) else row[0]) if row else None
    if observation_version is not None:
        version = observation_version
    if not version or not re.fullmatch(r'[A-Za-z0-9_-]{1,50}', version):
        raise ValueError("Invalid observation model version" if observation_version is not None
                         else "No approved forecasting model version configured")
    is_demo = 'demo' in version.lower() or 'kaggle' in version.lower()
    if is_demo != (context['dataset_kind'] == 'demo'):
        raise ValueError("Demo/Store model artifacts cannot cross dataset boundaries")
    artifact = joblib.load(artifact_directory(context) / f"random_forest_candidate_{version}.joblib")
    meta = artifact['metadata']
    for key in ('dataset_kind', 'dataset_database', 'dataset_host', 'dataset_port'):
        if meta.get(key) != context[key]:
            raise ValueError("Model artifact belongs to another dataset")
    if meta.get('feature_version') != FEATURE_VERSION or meta.get('features') != FEATURE_COLUMNS:
        raise ValueError("Model feature contract is obsolete; retraining is required")
    if meta.get('model_version') != version:
        raise ValueError("Model artifact version does not match the selected candidate")
    data = load_history(cursor, context)
    origin = data.date.max() if is_demo else pd.Timestamp(context['history_cutoff'])
    if not is_demo:
        data = data[data.product_id.isin(meta.get('products', []))]
        if data.empty:
            raise ValueError('No trained products have supported feature windows')
    features = build_feature_matrix(data, origin)
    predictions = artifact['model'].predict(features[FEATURE_COLUMNS])
    if not np.isfinite(predictions).all():
        raise ValueError("Model produced non-finite forecasts")
    counts = history_at(data, origin).groupby('product_id').date.nunique()
    readiness_bands = load_readiness_bands()
    for row, predicted in zip(features.itertuples(), predictions):
        days = int(counts[row.product_id])
        qualified = days >= readiness_bands['thresholds']['high'] and meta.get('outcome') == 'passed'
        readiness = resolve_readiness(days, qualified, readiness_bands)[0]
        limitation = 'Observed history only; calendar/stockout limitations apply'
        if is_demo:
            limitation = 'Demo evidence; source authenticity unverified; not Store accuracy'
            if context['source'].get('source_kind') == 'uci_online_retail':
                limitation = 'External Demo: UCI Online Retail; gross recorded sales; assumed zero days; not Store accuracy'
        if observation_version is not None:
            limitation = 'Observation only; not approved for routine purchasing. ' + limitation
        cursor.execute("""
            INSERT INTO demand_forecasts
            (job_id, product_id, forecast_origin, target_date, horizon_day, predicted_units,
             model_version, readiness_level, limitation_marker)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        """, (job_id, row.product_id, str(origin.date()), str(row.target_date.date()),
              row.horizon_day, max(0, int(round(float(predicted)))), version, readiness, limitation))


def _json_default(obj):
    if isinstance(obj, (np.integer, np.int64, np.int32)):
        return int(obj)
    if isinstance(obj, (np.floating, np.float64, np.float32)):
        return float(obj)
    if isinstance(obj, (np.bool_, bool)):
        return bool(obj)
    if isinstance(obj, (pd.Timestamp, datetime)):
        return str(obj)
    raise TypeError(f"Object of type {type(obj).__name__} is not JSON serializable")


def run_model_retraining(conn, cursor, job_id):
    context = dataset_context(cursor)
    data = load_history(cursor, context, training=True)
    artifact = train_and_evaluate(data)
    timestamp = datetime.now().strftime('%Y%m%d%H%M%S')
    prefix = 'demo_' if context['dataset_kind'] == 'demo' else 'v'
    version = f"{prefix}{timestamp}_{uuid.uuid4().hex[:6]}"
    model_name = 'random_forest_candidate'
    cursor.execute("SELECT setting_key, setting_value FROM platform_settings WHERE setting_key LIKE 'ml_max_wape_%'")
    thresholds = {}
    for row in cursor.fetchall():
        key, value = (row['setting_key'], row['setting_value']) if isinstance(row, dict) else row
        threshold = float(value)
        if not np.isfinite(threshold) or threshold < 0:
            raise ValueError("Invalid forecasting quality threshold")
        thresholds[key] = threshold
    meta = artifact['metadata']
    outcomes = []
    for horizon, metrics in artifact['evaluations'].items():
        threshold = thresholds.get(f'ml_max_wape_{horizon}d')
        wape, baseline = metrics['wape'], metrics['baseline_wape']
        coverage = metrics.get('coverage', len(meta.get('products', [])))

        # Snapshot effective configured threshold and baseline comparison (#366)
        metrics['effective_max_wape'] = threshold

        if threshold is None or wape is None or baseline is None or coverage <= 0:
            outcome = 'incomplete'
            if threshold is None and not metrics.get('missing_metric_reason'):
                metrics['missing_metric_reason'] = f"Missing configured threshold ml_max_wape_{horizon}d"
            elif coverage <= 0 and not metrics.get('missing_metric_reason'):
                metrics['missing_metric_reason'] = "Insufficient holdout coverage"
        else:
            outcome = 'passed' if (wape <= threshold and wape < baseline) else 'failed'
        outcomes.append(outcome)
        metrics['candidate_gate_outcome'] = outcome
        cursor.execute("""
            INSERT INTO ml_model_evaluations
            (model_version, horizon_days, mae, rmse, wape, baseline_mae, baseline_rmse,
             baseline_wape, candidate_gate_outcome, missing_metric_reason,
             data_window_start, data_window_end, created_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        """, (version, horizon, metrics['mae'], metrics['rmse'], wape,
              metrics['baseline_mae'], metrics['baseline_rmse'], baseline, outcome,
              metrics['missing_metric_reason'], meta['data_window_start'], meta['data_window_end']))
    meta.update(context)
    meta.update({
        'model_name': model_name, 'model_version': version, 'job_id': job_id,
        'created_at': datetime.now(timezone.utc).isoformat(),
        'outcome': 'passed' if all(o == 'passed' for o in outcomes) else
                   'incomplete' if 'incomplete' in outcomes else 'failed',
        'evidence_label': ('External Demo: UCI Online Retail; not Store accuracy'
                           if (context.get('source') or {}).get('source_kind') == 'uci_online_retail'
                           else 'Demo evidence; provenance unverified') if context['dataset_kind'] == 'demo'
                          else 'Store evidence known at each completed-day cutoff',
    })
    cursor.execute("""
        INSERT INTO model_training_runs
        (model_name, model_version, trigger_type, status, metrics_json,
         sales_records_used, eligible_products, started_at, completed_at)
        VALUES (%s, %s, 'cli', 'completed', %s, %s, %s, NOW(), NOW())
    """, (model_name, version, json.dumps({'metadata': meta, 'evaluations': artifact['evaluations'],
                                          'model_version': version, 'outcome': meta['outcome']},
                                         default=_json_default),
          meta['observations'], len(meta['products'])))
    directory = artifact_directory(context)
    directory.mkdir(parents=True, exist_ok=True)
    path = directory / f'{model_name}_{version}.joblib'
    temporary = path.with_suffix('.tmp')
    try:
        joblib.dump(artifact, temporary)
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)
    return path

def observe_model(version):
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,50}', version):
        raise ValueError("Invalid observation model version")
    conn = get_connection()
    try:
        with conn.cursor() as cursor:
            cursor.execute("""INSERT INTO ml_jobs
                (trigger_type, status, queued_at, started_at, attempts, lease_expires_at)
                VALUES ('forecast_observation', 'running', NOW(), NOW(), 3,
                        DATE_ADD(NOW(), INTERVAL 30 MINUTE))""")
            job_id = cursor.lastrowid
            cursor.execute("""INSERT INTO activity_log
                (user_id, action, category, module, record_id, new_value, ip_address, created_at)
                VALUES (NULL, 'Enqueued Observation ML Job', 'platform_setting',
                        'Demand Forecasting', %s, %s, '127.0.0.1', NOW())""",
                           (job_id, json.dumps({'trigger_type': 'forecast_observation',
                                               'model_version': version, 'origin': 'cli',
                                               'initiating_identity': getpass.getuser()})))
        conn.commit()
        process_job(conn, {'job_id': job_id, 'trigger_type': 'forecast_observation',
                           'observation_version': version})
        with conn.cursor() as cursor:
            cursor.execute("SELECT status FROM ml_jobs WHERE job_id = %s", (job_id,))
            row = cursor.fetchone()
        if (row['status'] if isinstance(row, dict) else row[0]) != 'succeeded':
            raise RuntimeError(f"Observation job {job_id} failed; inspect its diagnostic reference")
        print(f"Observation job {job_id} completed; excluded from routine purchasing")
    finally:
        conn.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="RetailMind ML Queue Worker")
    parser.add_argument("--once", "--run-once", dest="run_once", action="store_true", help="Process queued jobs once and exit")
    parser.add_argument("--observe-model", metavar="VERSION", help="Generate non-operational observation evidence for an explicit candidate")
    args = parser.parse_args()

    if args.observe_model:
        observe_model(args.observe_model)
    elif args.run_once:
        process_jobs()
    else:
        while True:
            process_jobs()
            time.sleep(10)
