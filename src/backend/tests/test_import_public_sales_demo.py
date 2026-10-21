import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from import_public_sales_demo import read_experiment
from public_sales_experiment import SOURCE_URL


class PublicDemoImportTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.codes = [str(code) for code in range(10000, 10020)]
        grid = pd.MultiIndex.from_product([self.codes, pd.date_range('2011-01-01', periods=140)],
                                          names=['product_id', 'date'])
        self.history = grid.to_frame(index=False).assign(units_sold=2)
        self.products = pd.DataFrame({'product_id': self.codes, 'description': 'Example'})
        self.meta = {'dataset_kind': 'external_experiment', 'source_url': SOURCE_URL,
                     'products': self.codes, 'observations': 2800,
                     'data_window_start': '2011-01-01', 'data_window_end': '2011-05-20'}
        self.write()

    def write(self):
        self.history.to_csv(self.path / 'daily_history.csv', index=False)
        self.products.to_csv(self.path / 'products.csv', index=False)
        (self.path / 'metrics.json').write_text(json.dumps({'metadata': self.meta}), encoding='utf-8')

    def test_complete_daily_history_retains_external_codes_and_quantities(self):
        history, products, _ = read_experiment(self.path)
        self.assertEqual(len(history), 2800)
        self.assertEqual(products.index.tolist(), self.codes)
        self.assertEqual(history.units_sold.sum(), 5600)

    def test_invalid_history_is_rejected_before_import(self):
        for change in ('fraction', 'negative', 'missing', 'duplicate', 'long_name', 'wrong_source'):
            with self.subTest(change=change):
                if change == 'fraction':
                    self.history['units_sold'] = 1.5
                elif change == 'negative':
                    self.history['units_sold'] = -1
                elif change == 'missing':
                    self.history = self.history.iloc[1:]
                elif change == 'duplicate':
                    self.products.loc[0, 'product_id'] = self.codes[1]
                elif change == 'long_name':
                    self.products['description'] = 'x' * 145
                else:
                    self.meta['source_url'] = 'https://example.invalid'
                self.write()
                with self.assertRaises(ValueError):
                    read_experiment(self.path)
                # Recreate a clean fixture for the next independent invalid input.
                self.history = pd.MultiIndex.from_product(
                    [self.codes, pd.date_range('2011-01-01', periods=140)], names=['product_id', 'date']
                ).to_frame(index=False).assign(units_sold=2)
                self.products = pd.DataFrame({'product_id': self.codes, 'description': 'Example'})
                self.meta['source_url'] = SOURCE_URL

    def test_store_and_remote_destinations_are_refused_before_connection(self):
        script = Path(__file__).resolve().parents[1] / 'scripts' / 'import_public_sales_demo.py'
        environment = dict(os.environ, APP_ENV='development', DATABASE_ENV='local',
                           DB_HOST='127.0.0.1', DB_NAME='retailmind_demo_test')
        for invalid in ({'DB_NAME': 'retailmind'}, {'DB_HOST': 'example.invalid'},
                        {'APP_ENV': 'production'}, {'DATABASE_ENV': 'hosted'}):
            run = subprocess.run([sys.executable, str(script), 'missing-directory'],
                                 env=dict(environment, **invalid), capture_output=True, text=True)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn('local retailmind_demo_* database', run.stderr)


if __name__ == '__main__':
    unittest.main()
