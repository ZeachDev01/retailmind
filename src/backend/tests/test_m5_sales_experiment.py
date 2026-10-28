"""Checks M5 selection, temporal isolation, and auxiliary classification."""
import json
import sys
import unittest
from pathlib import Path

import numpy as np
import pandas as pd

SCRIPTS = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS))
from m5_sales_experiment import evaluate, prepare_history, sale_day_metrics


def archive(product_count=25):
    dates = pd.date_range("2013-01-01", "2016-05-22")
    return pd.DataFrame([
        {"datetime": date, "item_id": f"FOODS_{pid:03}", "sales": 2,
         "store_id": "CA_1", "cat_id": "FOODS"}
        for pid in range(product_count) for date in dates
    ])


class M5ExperimentTests(unittest.TestCase):
    def test_expansion_retains_original_products_and_ignores_future_sales(self):
        raw = archive(60)
        _, original = prepare_history(raw)
        history, expanded = prepare_history(raw, 50)
        self.assertTrue(set(original["products"]) < set(expanded["products"]))
        self.assertEqual(history.product_id.nunique(), 50)
        raw.loc[raw.datetime > expanded["initial_training_end"], "sales"] = 9999
        _, changed = prepare_history(raw, 50)
        self.assertEqual(expanded["products"], changed["products"])
        with self.assertRaisesRegex(ValueError, "50 products"):
            prepare_history(archive(), 50)
        with self.assertRaisesRegex(ValueError, "Choose 20 or 50"):
            prepare_history(raw, 30)

    def test_selection_uses_two_training_years_and_ignores_future_sales(self):
        raw = archive()
        raw["item_id"] = pd.Categorical(raw.item_id, categories=[*raw.item_id.unique(), "UNOBSERVED"])
        history, meta = prepare_history(raw)
        cutoff = pd.Timestamp(meta["initial_training_end"])
        raw.loc[raw.datetime > cutoff, "sales"] = 9999
        changed, changed_meta = prepare_history(raw)
        self.assertEqual(meta["products"], changed_meta["products"])
        self.assertEqual(history.product_id.nunique(), 20)
        self.assertEqual(meta["initial_training_days"], 730)
        pd.testing.assert_frame_equal(history[history.date <= cutoff], changed[changed.date <= cutoff])
        selected = meta["products"][0]
        missing = raw[~((raw.item_id == selected) & (raw.datetime == raw.datetime.max()))]
        with self.assertRaisesRegex(ValueError, "missing held-out"):
            prepare_history(missing)

    def test_rejects_invalid_sales_and_incomplete_training(self):
        raw = archive()
        for value in (-1, 1.5, np.nan):
            invalid = raw.copy()
            invalid["sales"] = invalid.sales.astype(float)
            invalid.loc[0, "sales"] = value
            with self.subTest(value=value), self.assertRaises(ValueError):
                prepare_history(invalid)
        with self.assertRaisesRegex(ValueError, "20 products"):
            prepare_history(raw[raw.datetime > "2016-01-01"])

    def test_final_test_labels_cannot_change_predictions_or_threshold(self):
        dates = pd.date_range("2024-01-01", periods=180)
        history = pd.DataFrame({"date": dates, "product_id": "example", "units_sold": np.arange(180) % 7})
        daily, _, report = evaluate(history)
        changed = history.copy()
        changed.loc[changed.date > dates[-29], "units_sold"] = 999
        other, _, other_report = evaluate(changed)
        np.testing.assert_array_equal(daily.predicted, other.predicted)
        np.testing.assert_array_equal(daily.baseline, other.baseline)
        self.assertEqual(report["sale_day_threshold"], other_report["sale_day_threshold"])
        for fold in report["folds"]:
            self.assertLessEqual(fold["training_label_end"], fold["origin"])

    def test_classification_counts_and_undefined_denominators(self):
        result = sale_day_metrics([1, 0, 1, 0], [1, 1, 0, 0], 0.5)
        self.assertEqual(result, {"tp": 1, "fp": 1, "fn": 1, "tn": 1,
                                  "precision": 0.5, "recall": 0.5, "f1": 0.5})
        self.assertIsNone(sale_day_metrics([0], [0], 0.5)["f1"])

    def test_colab_bundles_exact_shared_source(self):
        notebook = SCRIPTS.parents[2] / "docs/research/m5_random_forest_colab.ipynb"
        cells = json.loads(notebook.read_text(encoding="utf-8"))["cells"]
        bundle = next("".join(cell["source"]) for cell in cells if "BUNDLED_SOURCES = " in "".join(cell["source"]))
        namespace = {}
        exec(bundle.split("\nfor name, source in BUNDLED_SOURCES.items():")[0], namespace)
        for name, source in namespace["BUNDLED_SOURCES"].items():
            self.assertEqual(source, (SCRIPTS / name).read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
