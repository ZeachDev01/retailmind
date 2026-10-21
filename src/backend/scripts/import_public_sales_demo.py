"""Import prepared UCI daily observations into a new local Demo, never Store sales."""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path

import pandas as pd

from feature_pipeline import normalize_history
from ml_worker import get_connection
from public_sales_experiment import SOURCE_URL


def read_experiment(directory):
    report = json.loads((directory / 'metrics.json').read_text(encoding='utf-8'))['metadata']
    if report.get('dataset_kind') != 'external_experiment' or report.get('source_url') != SOURCE_URL:
        raise ValueError('Use the prepared UCI Online Retail experiment')
    history = normalize_history(pd.read_csv(directory / 'daily_history.csv', dtype={'product_id': str}))
    products = pd.read_csv(directory / 'products.csv', dtype=str, keep_default_na=False)
    codes = sorted(history.product_id.unique())
    if (len(codes) != 20 or len(products) != 20 or products.product_id.duplicated().any()
            or set(products.product_id) != set(codes)
            or codes != report.get('products') or len(history) != report.get('observations')
            or (history.units_sold % 1 != 0).any() or (history.units_sold > 2147483647).any()):
        raise ValueError('Expected the 20-product experiment with whole daily quantities')
    start, end = history.date.min(), history.date.max()
    if ([str(start.date()), str(end.date())] != [report.get('data_window_start'), report.get('data_window_end')]
            or not history.groupby('product_id').size().eq((end - start).days + 1).all()):
        raise ValueError('Incomplete or changed experiment date coverage')
    if (products.description.str.len() > 144).any() or products.description.str.strip().eq('').any():
        raise ValueError('Product descriptions must contain 1 to 144 characters')
    if not products.product_id.str.fullmatch(r'[0-9]{5}[A-Za-z]{0,40}').all():
        raise ValueError('Invalid UCI product code')
    return history, products.set_index('product_id'), report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('experiment', type=Path)
    args = parser.parse_args()
    database = os.getenv('DB_NAME', '')
    if (not re.fullmatch(r'retailmind_demo_[a-z0-9_]+', database)
            or os.getenv('APP_ENV', '').lower() not in ('development', 'test')
            or os.getenv('DATABASE_ENV') != 'local'
            or os.getenv('DB_HOST') not in ('localhost', '127.0.0.1', '::1')):
        parser.error('Use APP_ENV=development/test, DATABASE_ENV=local and a local retailmind_demo_* database')
    history, products, report = read_experiment(args.experiment)
    mapping = {code: index for index, code in enumerate(sorted(products.index), 1)}
    source = {
        'source_kind': 'uci_online_retail', 'source_label': 'UCI Online Retail',
        'store_id': 'UCI_ONLINE_RETAIL', 'products_mapped': 20,
        'total_records': len(history),
        'date_window': [str(history.date.min().date()), str(history.date.max().date())],
        'product_mapping': mapping, 'experiment': report,
        'prepared_history_sha256': hashlib.sha256((args.experiment / 'daily_history.csv').read_bytes()).hexdigest(),
        'scenario': 'Opening stock 0; lead time 7 days; reorder 20; safety 10; packs/MOQ 1; costs unknown. These are Demo settings, not UCI observations.',
    }
    if report.get('replay'):
        source['replay'] = report['replay']
        source['source_label'] = 'UCI train/test replay — SIMULATED recent dates'
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            for table in ('products', 'inventory', 'sales', 'forecast_sales_imports', 'ml_jobs'):
                cursor.execute(f'SELECT COUNT(*) AS count FROM {table}')
                if cursor.fetchone()['count']:
                    raise ValueError('Import requires an empty Demo. Run setup with a fresh database name.')
            cursor.execute("SELECT setting_value FROM platform_settings WHERE setting_key='demo_import_metadata'")
            if cursor.fetchone():
                raise ValueError('Demo already imported; choose a fresh database')
            cursor.execute("INSERT INTO categories (category_id, category_name) VALUES (999, 'External Demo - UCI')")
            for code, product_id in mapping.items():
                cursor.execute('''INSERT INTO products
                    (product_id, sku, barcode, product_name, category_id, unit_price, cost_price, branch_id,
                     reorder_level, safety_stock, supplier_lead_time_days, minimum_order_quantity, units_per_package)
                    VALUES (%s,%s,%s,%s,999,0,0,1,20,10,7,1,1)''',
                               (product_id, 'UCI-' + code, 'UCI-' + code, 'Demo: ' + products.loc[code, 'description']))
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
    print(f'Imported {len(history)} UCI daily observations into {database}; no customer sales or stock movements invented.')


if __name__ == '__main__':
    main()
