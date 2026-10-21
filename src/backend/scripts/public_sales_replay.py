"""A dated, frozen-model UCI test replay. Never an operational Store seed."""
import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import re

import joblib
import numpy as np
import pandas as pd

from feature_pipeline import FEATURE_COLUMNS, build_feature_matrix, history_at, normalize_history
from ml_evaluation import calculate_metrics, seasonal_naive_forecast
from ml_worker import artifact_directory, dataset_context, get_connection, load_history
from public_sales_experiment import SOURCE_URL


def prepare_replay(directory, end_date):
    train = normalize_history(pd.read_csv(directory / 'train.csv', dtype={'product_id': str}))
    test = normalize_history(pd.read_csv(directory / 'test.csv', dtype={'product_id': str}))
    products = pd.read_csv(directory / 'products.csv', dtype=str)
    if (len(products) != 20 or set(train.product_id) != set(test.product_id)
            or set(train.product_id) != set(products.product_id)
            or train.date.max() + pd.Timedelta(days=1) != test.date.min()):
        raise ValueError('Require 20 matching products and separate, adjacent chronological splits')
    for data, minimum, exact in ((train, 140, False), (test, 84, True)):
        days = (data.date.max() - data.date.min()).days + 1
        if days < minimum or (exact and days != minimum) or not data.groupby('product_id').size().eq(days).all():
            raise ValueError('Require complete training days and exactly 84 complete test days')
        if (data.units_sold % 1 != 0).any():
            raise ValueError('Replay units must be whole numbers')
    end = pd.Timestamp(end_date)
    today = pd.Timestamp(datetime.now(timezone(timedelta(hours=8))).date())
    if end != end.normalize() or end >= today:
        raise ValueError('Replay must end on a completed past Philippine date')
    offset = end - test.date.max()
    original = {'train': [str(train.date.min().date()), str(train.date.max().date())],
                'test': [str(test.date.min().date()), str(test.date.max().date())]}
    train['date'] += offset
    test['date'] += offset
    replay = {'train_end': str(train.date.max().date()), 'test_start': str(test.date.min().date()),
              'test_end': str(test.date.max().date()), 'shift_days': offset.days, 'original_dates': original,
              'train_sha256': hashlib.sha256((directory / 'train.csv').read_bytes()).hexdigest(),
              'test_sha256': hashlib.sha256((directory / 'test.csv').read_bytes()).hexdigest(),
              'label': 'SIMULATED recent timeline; not recent Store sales',
              'stock_scenario': 'Opening stock pre-funds all test sales plus a chosen closing balance of 0/0.5/2/6 training weeks. Inventory is illustrative, not an ordering-policy test.',
              'money': 'Quantity-only replay: prices, revenue and cash are zero placeholders; purchase costs unknown.'}
    meta = {'dataset_kind': 'external_experiment', 'source_url': SOURCE_URL, 'license': 'CC BY 4.0',
            'products': sorted(train.product_id.unique()), 'observations': len(train),
            'data_window_start': str(train.date.min().date()), 'data_window_end': str(train.date.max().date()),
            'replay': replay}
    return train, test, products, meta


def evaluate_replay(model, train, test):
    """Predict three 28-day blocks without fitting or reading future input values."""
    history = pd.concat([train, test], ignore_index=True)
    blocks = []
    totals = {h: {'actual': [], 'predicted': [], 'baseline': []} for h in (7, 14, 28)}
    for block in range(3):
        origin = train.date.max() + pd.Timedelta(days=28 * block)
        features = build_feature_matrix(history, origin)
        predicted = np.maximum(0, np.rint(model.predict(features[FEATURE_COLUMNS])))
        if not np.isfinite(predicted).all():
            raise ValueError('Non-finite replay prediction')
        actual = features.merge(test, left_on=['product_id', 'target_date'], right_on=['product_id', 'date'], validate='one_to_one').units_sold.to_numpy()
        if len(actual) != len(features):
            raise ValueError('Missing test observations')
        baseline = np.concatenate([seasonal_naive_forecast(group.units_sold.to_numpy(), 28)
                                   for _, group in history_at(history, origin).groupby('product_id', sort=True)])
        features['predicted_units'] = predicted.astype(int)
        features['actual_units'] = actual
        blocks.append(features)
        for h, values in totals.items():
            for key, data in (('actual', actual), ('predicted', predicted), ('baseline', baseline)):
                values[key].extend(data.reshape(-1, 28)[:, :h].sum(axis=1))
    metrics = {}
    for h, values in totals.items():
        metrics[h] = calculate_metrics(np.array(values['actual']), np.array(values['predicted']))
        metrics[h].update({f'baseline_{key}': value for key, value in
                          calculate_metrics(np.array(values['actual']), np.array(values['baseline'])).items()})
    future = build_feature_matrix(history, test.date.max())
    prediction = model.predict(future[FEATURE_COLUMNS])
    if not np.isfinite(prediction).all():
        raise ValueError('Non-finite future preview')
    future['predicted_units'] = np.maximum(0, np.rint(prediction)).astype(int)
    return blocks, future, metrics


def seed(directory):
    database = os.getenv('DB_NAME', '')
    if (not re.fullmatch(r'retailmind_demo_[a-z0-9_]+', database)
            or os.getenv('APP_ENV') not in ('development', 'test') or os.getenv('DATABASE_ENV') != 'local'
            or os.getenv('DB_HOST') not in ('127.0.0.1', 'localhost', '::1')):
        raise ValueError('Replay requires an isolated local retailmind_demo_* database')
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            context = dataset_context(cursor)
            source = context['source']
            if source.get('source_kind') != 'uci_online_retail' or not source.get('replay'):
                raise ValueError('Import the prepared train-only replay first')
            for table in ('sales', 'stock_receiving', 'stock_movements'):
                cursor.execute(f'SELECT COUNT(*) AS n FROM {table}')
                if cursor.fetchone()['n']:
                    raise ValueError('Replay seeding requires a fresh Demo with no sales or stock movements')
            cursor.execute("SELECT model_version FROM model_training_runs WHERE status='completed' ORDER BY training_run_id DESC LIMIT 1")
            version = cursor.fetchone()['model_version']
            if not re.fullmatch(r'demo_[A-Za-z0-9_]+', version):
                raise ValueError('Require a Demo candidate')
            artifact = joblib.load(artifact_directory(context) / f'random_forest_candidate_{version}.joblib')
            meta = artifact['metadata']
            if (meta.get('dataset_database') != database or meta.get('dataset_kind') != 'demo'
                    or meta['data_window_end'] != source['replay']['train_end']
                    or meta['training_window_end'] > source['replay']['train_end']):
                raise ValueError('Candidate must be fitted only to this replay training split')
            train = load_history(cursor, context)
            test_bytes = (directory / 'test.csv').read_bytes()
            expected = json.loads((directory / 'metrics.json').read_text())['metadata']['replay']
            if expected != source['replay']:
                raise ValueError('Replay directory does not match the imported split')
            if hashlib.sha256(test_bytes).hexdigest() != expected.get('prepared_test_sha256'):
                raise ValueError('Prepared test data changed after import')
            test = normalize_history(pd.read_csv(directory / 'test.csv', dtype={'product_id': str}))
            test['product_id'] = test.product_id.map(source['product_mapping'])
            test = normalize_history(test)
            if (len(test) != 1680 or test.date.min() != train.date.max() + pd.Timedelta(days=1)
                    or str(test.date.max().date()) != expected['test_end']):
                raise ValueError('Changed test coverage')
            blocks, future, metrics = evaluate_replay(artifact['model'], train, test)
            jobs = []
            for frame in [*blocks, future]:
                cursor.execute("""INSERT INTO ml_jobs (trigger_type,status,requester_id,queued_at,started_at,completed_at,operator_message)
                    VALUES ('forecast_observation','succeeded',1,NOW(),NOW(),NOW(),'Simulated test replay; not approved for routine purchasing')""")
                job = cursor.lastrowid
                jobs.append(job)
                cursor.executemany('''INSERT INTO demand_forecasts
                    (job_id,product_id,forecast_origin,target_date,horizon_day,predicted_units,model_version,readiness_level,limitation_marker)
                    VALUES (%s,%s,%s,%s,%s,%s,%s,'Medium','SIMULATED replay preview; frozen train-only model; not approved for purchasing')''',
                    [(job, row.product_id, str(row.origin_date.date()), str(row.target_date.date()), row.horizon_day, row.predicted_units, version)
                     for row in frame.itertuples()])
            balances = seed_inventory_sales(cursor, train, test)
            result = {'model_version': version, 'jobs': jobs[:3], 'preview_job': jobs[3], 'metrics': metrics,
                      'test_start': expected['test_start'], 'test_end': expected['test_end'],
                      'training_end': expected['train_end'], 'test_rows': len(test),
                      'seeded_test_sha256': hashlib.sha256(test_bytes).hexdigest(),
                      'validation_outcome': meta['outcome'], 'stock': balances}
            cursor.execute("INSERT INTO platform_settings (setting_key,setting_value) VALUES ('demo_replay_result',%s)", (json.dumps(result),))
            cursor.execute("INSERT INTO activity_log (user_id,action,category,module,new_value,created_at) VALUES (1,'Seed simulated test replay','platform_setting','Demand Forecasting',%s,NOW())", (json.dumps({'model_version': version, 'test_rows': len(test), 'simulated': True}),))
        connection.commit()
        print(json.dumps(result, indent=2))
    except Exception:
        connection.rollback()
        raise
    finally:
        connection.close()


def seed_inventory_sales(cursor, train, test):
    balances = {}
    batches = {}
    opened = str((test.date.min() - pd.Timedelta(days=1)).date()) + ' 07:00:00'
    week = train[train.date > train.date.max() - pd.Timedelta(days=7)].groupby('product_id').units_sold.sum()
    for index, (pid, group) in enumerate(test.groupby('product_id', sort=True)):
        sold = int(group.units_sold.sum())
        closing = int(round(float(week[pid]) * (0, 0.5, 2, 6)[index % 4]))
        opening = sold + closing
        reference = f'SIMULATED-UCI-OPENING-{pid}'
        cursor.execute('''INSERT INTO stock_receiving (product_id,received_qty,accepted_qty,received_loose,accepted_loose,
            received_by,received_at,invoice_number,batch_number,notes) VALUES (%s,%s,%s,%s,%s,3,%s,%s,%s,%s)''',
            (pid, opening, opening, opening, opening, opened, reference, reference, 'SIMULATED pre-funded test stock; quantity-only replay; not observed supplier delivery'))
        receipt = cursor.lastrowid
        cursor.execute('''INSERT INTO product_batches (product_id,receiving_id,batch_number,quantity,remaining_quantity,
            sealed_quantity,loose_quantity,date_received) VALUES (%s,%s,%s,%s,%s,0,%s,%s)''',
            (pid, receipt, reference, opening, opening, opening, opened))
        batches[pid] = cursor.lastrowid
        cursor.execute("INSERT INTO stock_movements (product_id,change_qty,reason,moved_by,moved_at) VALUES (%s,%s,'purchase',3,%s)", (pid, opening, opened))
        cursor.execute('UPDATE inventory SET quantity_on_hand=%s WHERE product_id=%s AND quantity_on_hand=0', (opening, pid))
        if cursor.rowcount != 1:
            raise ValueError('Opening inventory must be untouched zero stock')
        cursor.execute('UPDATE products SET quantity_purchased=%s WHERE product_id=%s', (opening, pid))
        balances[int(pid)] = {'opening': opening, 'sold': sold, 'closing': closing}
    for date, group in test.groupby('date', sort=True):
        day = str(date.date())
        cursor.execute("""INSERT INTO cashier_shifts (cashier_id,opened_at,opening_cash,status,closed_at,expected_cash,actual_cash,cash_variance,closing_notes)
            VALUES (4,%s,0,'closed',%s,0,0,0,'SIMULATED UCI daily aggregates; no customer-level or monetary evidence')""", (day + ' 07:30:00', day + ' 21:30:00'))
        shift = cursor.lastrowid
        positive = group[group.units_sold > 0]
        if positive.empty:
            continue
        cursor.execute("""INSERT INTO sales (cashier_id,shift_id,total_amount,gross_amount,payment_method,payment_reference,sale_date)
            VALUES (4,%s,0,0,'cash',%s,%s)""", (shift, 'SIMULATED-UCI-' + day, day + ' 12:00:00'))
        sale = cursor.lastrowid
        for row in positive.itertuples():
            pid, qty = int(row.product_id), int(row.units_sold)
            cursor.execute('INSERT INTO sale_items (sale_id,product_id,quantity,unit_price,subtotal) VALUES (%s,%s,%s,0,0)', (sale, pid, qty))
            item = cursor.lastrowid
            cursor.execute('INSERT INTO sale_item_batches (sale_item_id,batch_id,quantity) VALUES (%s,%s,%s)', (item, batches[pid], qty))
            cursor.execute('UPDATE product_batches SET remaining_quantity=remaining_quantity-%s,loose_quantity=loose_quantity-%s WHERE batch_id=%s AND remaining_quantity>=%s AND loose_quantity>=%s', (qty, qty, batches[pid], qty, qty))
            if cursor.rowcount != 1:
                raise ValueError('Insufficient simulated batch stock')
            cursor.execute('UPDATE inventory SET quantity_on_hand=quantity_on_hand-%s WHERE product_id=%s AND quantity_on_hand>=%s', (qty, pid, qty))
            if cursor.rowcount != 1:
                raise ValueError('Insufficient simulated inventory')
            cursor.execute('UPDATE products SET quantity_sold=quantity_sold+%s WHERE product_id=%s', (qty, pid))
            cursor.execute("INSERT INTO stock_movements (product_id,change_qty,reason,moved_by,moved_at) VALUES (%s,%s,'sale',4,%s)", (pid, -qty, day + ' 12:00:00'))
    return balances


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--end-date', help='Map final test date to this completed Philippine date')
    parser.add_argument('--output-dir', type=Path)
    parser.add_argument('--seed', action='store_true', help='Seed the prepared replay after training its isolated Demo')
    args = parser.parse_args()
    if args.seed:
        seed(args.directory)
        return
    if not args.end_date or args.output_dir is None:
        parser.error('Preparation requires --end-date and a fresh --output-dir')
    train, test, products, meta = prepare_replay(args.directory, args.end_date)
    args.output_dir.mkdir(parents=True, exist_ok=False)
    train.to_csv(args.output_dir / 'daily_history.csv', index=False)
    test.to_csv(args.output_dir / 'test.csv', index=False)
    meta['replay']['prepared_test_sha256'] = hashlib.sha256((args.output_dir / 'test.csv').read_bytes()).hexdigest()
    products.to_csv(args.output_dir / 'products.csv', index=False)
    (args.output_dir / 'metrics.json').write_text(json.dumps({'metadata': meta}, indent=2), encoding='utf-8')
    print(json.dumps(meta, indent=2))


if __name__ == '__main__':
    main()
