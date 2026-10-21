import json
from pathlib import Path
import sys
import tempfile
import unittest

import numpy as np
import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from public_sales_replay import evaluate_replay, prepare_replay


class FrozenModel:
    def predict(self, features):
        return features.lag_1.to_numpy()

    def fit(self, *args):
        raise AssertionError('Never refit the frozen model on test observations')


class ReplayTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name)
        self.history = pd.MultiIndex.from_product(
            [[str(n) for n in range(10000, 10020)], pd.date_range('2010-12-02', periods=372)],
            names=['product_id', 'date']).to_frame(index=False).assign(units_sold=10)
        cutoff = self.history.date.max() - pd.Timedelta(days=84)
        self.train = self.history[self.history.date <= cutoff].copy()
        self.test = self.history[self.history.date > cutoff].copy()
        self.train.to_csv(self.path / 'train.csv', index=False)
        self.test.to_csv(self.path / 'test.csv', index=False)
        pd.DataFrame({'product_id': sorted(self.history.product_id.unique()), 'description': 'Example'}).to_csv(self.path / 'products.csv', index=False)

    def test_shift_preserves_split_and_quantities(self):
        train, test, _, meta = prepare_replay(self.path, '2026-10-20')
        self.assertEqual(str(test.date.max().date()), '2026-10-20')
        self.assertEqual(str(train.date.max().date()), '2026-07-28')
        self.assertEqual(len(train), 5760)
        self.assertEqual(len(test), 1680)
        self.assertLess(train.date.max(), test.date.min())
        self.assertEqual(train.units_sold.tolist(), self.train.units_sold.tolist())
        self.assertEqual(test.units_sold.tolist(), self.test.units_sold.tolist())
        self.assertIn('SIMULATED', meta['replay']['label'])

    def test_frozen_predictions_and_metrics_have_no_future_input_leakage(self):
        blocks, future, metrics = evaluate_replay(FrozenModel(), self.train, self.test)
        self.assertEqual([len(block) for block in blocks], [560, 560, 560])
        self.assertEqual(len(future), 560)
        self.assertEqual(set(metrics), {7, 14, 28})
        self.assertTrue(all(value['mae'] == 0 for value in metrics.values()))
        changed = self.test.copy()
        changed.loc[changed.date > self.train.date.max() + pd.Timedelta(days=28), 'units_sold'] = 1000
        other, _, _ = evaluate_replay(FrozenModel(), self.train, changed)
        pd.testing.assert_frame_equal(blocks[0], other[0])
        self.assertTrue(future.target_date.min() > self.test.date.max())
        self.assertLessEqual(blocks[0].origin_date.max(), self.train.date.max())

    def test_bad_splits_fail_before_database_work(self):
        for broken in (self.test.iloc[1:], self.test.assign(date=self.test.date - pd.Timedelta(days=1))):
            broken.to_csv(self.path / 'test.csv', index=False)
            with self.assertRaises(ValueError):
                prepare_replay(self.path, '2026-10-20')


if __name__ == '__main__':
    unittest.main()
