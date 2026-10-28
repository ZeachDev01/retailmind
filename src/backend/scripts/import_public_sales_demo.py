"""Import prepared public daily observations into a new local Demo, never Store sales."""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path

import pandas as pd
import numpy as np

from feature_pipeline import normalize_history
from ml_worker import get_connection
from ml_evaluation import calculate_metrics
from public_sales_experiment import SOURCE_URL
from m5_sales_experiment import SOURCE_URL as M5_SOURCE_URL


def read_experiment(directory):
    saved = json.loads((directory / 'metrics.json').read_text(encoding='utf-8'))
    is_m5 = saved.get('source_url') == M5_SOURCE_URL
    report = dict(saved, dataset_kind='external_experiment', data_window_start=saved.get('training_start'),
                  data_window_end=saved.get('data_end')) if is_m5 else saved.get('metadata', {})
    if report.get('dataset_kind') != 'external_experiment' or report.get('source_url') not in (SOURCE_URL, M5_SOURCE_URL):
        raise ValueError('Use a prepared UCI Online Retail or M5 experiment')
    if is_m5 and (report.get('store_id') != 'CA_1' or report.get('category') != 'FOODS'
                  or len(report.get('products', [])) not in (20, 50)):
        raise ValueError('M5 Demo requires the selected 20 or 50 CA_1 food products')
    history = normalize_history(pd.read_csv(directory / 'daily_history.csv', dtype={'product_id': str}))
    products = (pd.DataFrame({'product_id': report['products'], 'description': ['M5 ' + code for code in report['products']]})
                if is_m5 else pd.read_csv(directory / 'products.csv', dtype=str, keep_default_na=False))
    codes = sorted(history.product_id.unique())
    count = len(report['products']) if is_m5 else 20
    if (len(codes) != count or len(products) != count or products.product_id.duplicated().any()
            or set(products.product_id) != set(codes)
            or codes != report.get('products') or len(history) != report.get('observations')
            or (history.units_sold % 1 != 0).any() or (history.units_sold > 2147483647).any()):
        raise ValueError('Expected the declared product experiment with whole daily quantities')
    start, end = history.date.min(), history.date.max()
    if ([str(start.date()), str(end.date())] != [report.get('data_window_start'), report.get('data_window_end')]
            or not history.groupby('product_id').size().eq((end - start).days + 1).all()):
        raise ValueError('Incomplete or changed experiment date coverage')
    if (products.description.str.len() > 144).any() or products.description.str.strip().eq('').any():
        raise ValueError('Product descriptions must contain 1 to 144 characters')
    pattern = r'FOODS_[1-3]_[0-9]{3}' if is_m5 else r'[0-9]{5}[A-Za-z]{0,40}'
    if not products.product_id.str.fullmatch(pattern).all():
        raise ValueError('Invalid source product code')
    return history, products.set_index('product_id'), report


def import_m5_preview(cursor, directory, history, report):
    if report['source_url'] != M5_SOURCE_URL:
        raise ValueError('Historical preview requires an M5 experiment')
    cursor.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_replay_result'")
    if cursor.fetchone():
        raise ValueError('Preview already exists; choose a fresh Demo')
    cursor.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")
    row = cursor.fetchone()
    source = json.loads(row['setting_value']) if row else {}
    checksum = hashlib.sha256((directory / 'daily_history.csv').read_bytes()).hexdigest()
    if source.get('source_kind') != 'm5_daily' or source.get('prepared_history_sha256') != checksum:
        raise ValueError('Preview must match the imported M5 history')
    cursor.execute("SELECT model_version,metrics_json FROM model_training_runs WHERE status='completed' ORDER BY training_run_id DESC LIMIT 1")
    candidate = cursor.fetchone()
    if not candidate:
        raise ValueError('Train the isolated M5 candidate before importing its experiment preview')
    meta = json.loads(candidate['metrics_json'])['metadata']
    if (meta.get('dataset_kind') != 'demo' or meta.get('source') != source
            or meta.get('parameters') != report.get('parameters')):
        raise ValueError('Candidate evidence does not match the imported M5 experiment')
    if [fold['fold'] for fold in report.get('folds', [])] != [1, 2, 3]:
        raise ValueError('Require the three original M5 evaluation folds')
    daily = pd.read_csv(directory / 'daily_predictions.csv', dtype={'product_id': str}, parse_dates=['target_date'])
    numbers = daily[['actual', 'predicted', 'baseline', 'horizon_day', 'fold']]
    if (not np.isfinite(numbers).all().all() or (numbers < 0).any().any()
            or (daily.horizon_day % 1 != 0).any() or (daily.fold % 1 != 0).any()
            or daily.duplicated(['fold', 'product_id', 'horizon_day']).any()
            or set(daily.fold) != {1, 2, 3}):
        raise ValueError('Invalid or duplicate M5 held-out predictions')
    observations = daily.merge(history, left_on=['product_id', 'target_date'], right_on=['product_id', 'date'], how='left', validate='many_to_one')
    if observations.units_sold.isna().any() or not observations.actual.eq(observations.units_sold).all():
        raise ValueError('Held-out actual quantities must match original M5 history')
    for fold in report['folds']:
        block = daily[daily.fold == fold['fold']]
        if (set(block.product_id) != set(report['products']) or len(block) != 28 * len(report['products'])
                or not block.horizon_day.between(1, 28).all()
                or not block.target_date.eq(pd.Timestamp(fold['origin']) + pd.to_timedelta(block.horizon_day, unit='D')).all()):
            raise ValueError('Changed M5 fold dates or product coverage')
    jobs = []
    for fold in report['folds']:
        cursor.execute("""INSERT INTO ml_jobs (trigger_type,status,requester_id,queued_at,started_at,completed_at,operator_message)
            VALUES ('forecast_observation','succeeded',1,NOW(),NOW(),NOW(),'Imported historical M5 backtest evidence; not approved purchasing guidance')""")
        job = cursor.lastrowid
        jobs.append(job)
        block = daily[daily.fold == fold['fold']]
        cursor.executemany('''INSERT INTO demand_forecasts
            (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level,limitation_marker)
            VALUES (%s,%s,%s,%s,%s,%s,%s,'Medium','Historical M5 experiment; original dates; rounded daily display; not an approved forecast')''',
            [(job, source['product_mapping'][r.product_id], fold['origin'], str(r.target_date.date()), int(r.horizon_day),
              int(round(r.predicted)), candidate['model_version']) for r in block.itertuples()])
    test = daily[daily.fold == 3]
    metrics = {}
    for horizon in (7, 14, 28):
        totals = test[test.horizon_day <= horizon].groupby('product_id')[['actual', 'predicted', 'baseline']].sum()
        metrics[horizon] = calculate_metrics(totals.actual.to_numpy(), totals.predicted.to_numpy())
        metrics[horizon].update({f'baseline_{key}': value for key, value in calculate_metrics(totals.actual.to_numpy(), totals.baseline.to_numpy()).items()})
    source['replay'] = {'kind': 'm5_historical', 'shift_days': 0, 'stock_scenario': source['scenario'],
                        'money': 'Quantity-only demonstration. Product prices and costs are placeholders, not M5 observations.'}
    result = {'kind': 'm5_historical', 'model_version': candidate['model_version'], 'jobs': jobs,
              'preview_job': jobs[-1], 'metrics': metrics, 'training_end': report['initial_training_end'],
              'test_start': str(test.target_date.min().date()), 'test_end': str(test.target_date.max().date()),
              'test_rows': len(test), 'validation_outcome': meta['outcome']}
    cursor.execute("UPDATE platform_settings SET setting_value=%s WHERE setting_key='demo_import_metadata'", (json.dumps(source),))
    cursor.execute("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('demo_replay_result',%s)", (json.dumps(result),))
    cursor.execute("INSERT INTO activity_log (user_id,action,category,module,new_value,created_at) VALUES (1,'Import historical M5 preview','platform_setting','Demand Forecasting',%s,NOW())", (json.dumps(result),))
    print(f'Saved {len(daily)} historical M5 observation predictions; no model approved and no stock changed.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('experiment', type=Path)
    parser.add_argument('--preview', action='store_true', help='Import measured M5 backtest predictions after candidate training')
    args = parser.parse_args()
    database = os.getenv('DB_NAME', '')
    if (not re.fullmatch(r'retailmind_demo_[a-z0-9_]+', database)
            or os.getenv('APP_ENV', '').lower() not in ('development', 'test')
            or os.getenv('DATABASE_ENV') != 'local'
            or os.getenv('DB_HOST') not in ('localhost', '127.0.0.1', '::1')):
        parser.error('Use APP_ENV=development/test, DATABASE_ENV=local and a local retailmind_demo_* database')
    history, products, report = read_experiment(args.experiment)
    mapping = {code: index for index, code in enumerate(sorted(products.index), 1)}
    is_m5 = report['source_url'] == M5_SOURCE_URL
    label = 'M5 CA_1 FOODS' if is_m5 else 'UCI Online Retail'
    prefix = 'M5-' if is_m5 else 'UCI-'
    source = {
        'source_kind': 'm5_daily' if is_m5 else 'uci_online_retail', 'source_label': label,
        'store_id': 'CA_1' if is_m5 else 'UCI_ONLINE_RETAIL', 'products_mapped': len(mapping),
        'total_records': len(history),
        'date_window': [str(history.date.min().date()), str(history.date.max().date())],
        'product_mapping': mapping, 'experiment': report,
        'prepared_history_sha256': hashlib.sha256((args.experiment / 'daily_history.csv').read_bytes()).hexdigest(),
        'scenario': 'Opening stock 0; lead time 7 days; reorder 20; safety 10; packs/MOQ 1; costs unknown. These are illustrative Demo settings, not source observations.',
    }
    if report.get('replay'):
        source['replay'] = report['replay']
        source['source_label'] = 'UCI train/test replay — SIMULATED recent dates'
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            if args.preview:
                import_m5_preview(cursor, args.experiment, history, report)
                connection.commit()
                return
            for table in ('products', 'inventory', 'sales', 'forecast_sales_imports', 'ml_jobs'):
                cursor.execute(f'SELECT COUNT(*) AS count FROM {table}')
                if cursor.fetchone()['count']:
                    raise ValueError('Import requires an empty Demo. Run setup with a fresh database name.')
            cursor.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")
            if cursor.fetchone():
                raise ValueError('Demo already imported; choose a fresh database')
            cursor.execute('INSERT INTO categories (category_id, category_name) VALUES (999, %s)', ('External Demo - ' + label,))
            for code, product_id in mapping.items():
                cursor.execute('''INSERT INTO products
                    (product_id, sku, barcode, product_name, category_id, unit_price, cost_price, branch_id,
                     reorder_level, safety_stock, supplier_lead_time_days, minimum_order_quantity, units_per_package)
                    VALUES (%s,%s,%s,%s,999,0,0,1,20,10,7,1,1)''',
                               (product_id, prefix + code, prefix + code, 'Demo: ' + products.loc[code, 'description']))
                cursor.execute('INSERT INTO inventory (product_id,quantity_on_hand) VALUES (%s,0)', (product_id,))
            cursor.executemany('''INSERT INTO forecast_sales_imports
                (product_id, sale_date, quantity, imported_by) VALUES (%s,%s,%s,1)''',
                               [(mapping[row.product_id], str(row.date.date()), int(row.units_sold))
                                for row in history.itertuples()])
            cursor.execute("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('demo_import_metadata',%s)",
                           (json.dumps(source),))
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()
    print(f'Imported {len(history)} {label} daily observations into {database}; no customer sales or stock movements invented.')


if __name__ == '__main__':
    main()
