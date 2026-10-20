"""Checks the external archive adapter, not Store evidence rules."""
import sys
import subprocess
import tempfile
import unittest
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from public_sales_experiment import prepare_history, split_history


def transactions():
    dates = pd.date_range("2011-01-01", periods=182)
    return pd.DataFrame({"InvoiceNo": "123456", "StockCode": "12345", "Description": "Example",
                         "Quantity": 2.0, "InvoiceDate": dates, "UnitPrice": 1.0})


class PublicSalesExperimentTests(unittest.TestCase):
    def test_split_keeps_complete_future_dates_out_of_training(self):
        history, _, _ = prepare_history(transactions())
        history = pd.concat([history, history.assign(product_id='54321')], ignore_index=True)
        train, test = split_history(history.sample(frac=1, random_state=42))
        self.assertEqual(train.date.nunique(), 96)
        self.assertEqual(test.date.nunique(), 84)
        self.assertLess(train.date.max(), test.date.min())
        self.assertEqual(set(train.product_id), set(test.product_id))
        combined = pd.concat([train, test]).sort_values(['date', 'product_id']).reset_index(drop=True)
        pd.testing.assert_frame_equal(combined, history[combined.columns].sort_values(['date', 'product_id']).reset_index(drop=True))
        changed = history.copy()
        changed.loc[changed.date >= test.date.min(), 'units_sold'] = 999
        changed_train, _ = split_history(changed)
        pd.testing.assert_frame_equal(train.reset_index(drop=True), changed_train.reset_index(drop=True))
        for incomplete in (history.drop(index=0), history[history.date < history.date.min() + pd.Timedelta(days=139)]):
            with self.assertRaisesRegex(ValueError, '140 complete'):
                split_history(incomplete)

    def test_cli_refuses_to_overwrite_existing_results(self):
        with tempfile.TemporaryDirectory() as directory:
            marker = Path(directory) / "keep.txt"
            marker.write_text("previous results", encoding="utf-8")
            run = subprocess.run([sys.executable, str(Path(__file__).resolve().parents[1]
                                 / "scripts" / "public_sales_experiment.py"),
                                 "missing.xlsx", "--output-dir", directory], capture_output=True, text=True)
            self.assertNotEqual(run.returncode, 0)
            self.assertIn("already exists", run.stderr)
            self.assertEqual(marker.read_text(encoding="utf-8"), "previous results")

    def test_returns_nonproducts_boundaries_and_absence_have_explicit_treatment(self):
        raw = transactions()
        raw.loc[10, "InvoiceNo"] = "C123456"
        raw.loc[11, "Quantity"] = -3
        raw.loc[12, "StockCode"] = "POST"
        raw.loc[13, "UnitPrice"] = 0
        history, products, meta = prepare_history(raw)
        self.assertEqual(len(history), 180)
        self.assertEqual(history.date.min(), raw.InvoiceDate.min() + pd.Timedelta(days=1))
        self.assertEqual(history.date.max(), raw.InvoiceDate.max() - pd.Timedelta(days=1))
        for index in (10, 11, 12, 13):
            self.assertEqual(history.loc[history.date == raw.InvoiceDate[index], "units_sold"].item(), 0)
        self.assertEqual(history.units_sold.sum(), (180 - 4) * 2)
        self.assertEqual(products.product_id.tolist(), ["12345"])
        self.assertEqual(meta["dataset_kind"], "external_experiment")

    def test_holdout_changes_cannot_change_product_selection_or_past(self):
        raw = transactions()
        # 21 products tie in the selection period. The 21st must stay excluded
        # even if its held-out sales later dwarf every selected product.
        raw = pd.concat([raw.assign(StockCode=str(code)) for code in range(10000, 10021)], ignore_index=True)
        expected, products, meta = prepare_history(raw)
        cutoff = pd.Timestamp(meta["selection_end"])
        changed = raw.copy()
        changed.loc[(changed.StockCode == "10020") & (changed.InvoiceDate > cutoff), "Quantity"] = 999999
        actual, actual_products, _ = prepare_history(changed)
        self.assertEqual(len(products), 20)
        self.assertNotIn("10020", products.product_id.tolist())
        pd.testing.assert_frame_equal(products, actual_products)
        pd.testing.assert_frame_equal(expected, actual)

    def test_invalid_and_insufficient_archives_fail(self):
        for column, value in (("Quantity", float("inf")), ("Quantity", 1.5),
                              ("InvoiceDate", None), ("UnitPrice", float("nan"))):
            raw = transactions()
            raw.loc[4, column] = value
            with self.subTest(column=column, value=value), self.assertRaises(ValueError):
                prepare_history(raw)
        with self.assertRaisesRegex(ValueError, "140"):
            prepare_history(transactions().iloc[:100])


if __name__ == "__main__":
    unittest.main()
