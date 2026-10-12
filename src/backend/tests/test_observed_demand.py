"""Observed-demand pipeline contracts; deterministic fixtures are not Store evidence."""
import sys
import unittest
from pathlib import Path

import pandas as pd

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from feature_pipeline import build_feature_matrix


def history(days=140, units=10):
    return pd.DataFrame({
        "date": pd.date_range("2023-01-01", periods=days),
        "product_id": 1,
        "units_sold": float(units),
    })


class ObservedDemandTests(unittest.TestCase):
    def test_missing_calendar_day_is_not_a_substitute_lag(self):
        data = history(35).drop(index=20)
        with self.assertRaisesRegex(ValueError, "28 consecutive"):
            build_feature_matrix(data, "2023-02-04")

    def test_training_uses_observed_targets_available_by_cutoff(self):
        from feature_pipeline import build_training_rows, FEATURE_COLUMNS
        data = history(90)
        data.loc[data.date > "2023-03-01", "units_sold"] = 999
        rows = build_training_rows(data, "2023-03-01")
        self.assertEqual(set(rows.horizon_day), set(range(1, 29)))
        self.assertEqual(set(rows.units_sold), {10.0})
        self.assertLessEqual(rows.target_date.max(), pd.Timestamp("2023-03-01"))
        self.assertGreater(rows.target_date.min(), rows.origin_date.min())
        self.assertNotIn("price", FEATURE_COLUMNS)
        self.assertIn("product_id", FEATURE_COLUMNS)
        changed = data.copy()
        changed.loc[changed.date <= "2023-03-01", "units_sold"] = 20
        other = build_training_rows(changed, "2023-03-01")
        self.assertEqual(set(other.units_sold), {20.0})
        self.assertEqual(set(other.lag_1), {20.0})

    def test_rolling_evaluation_holds_out_actual_demand(self):
        from feature_pipeline import train_and_evaluate
        data = history()
        data.loc[data.date >= "2023-02-26", "units_sold"] = 100
        result = train_and_evaluate(data)
        first = result["metadata"]["folds"][0]
        self.assertEqual(first["origin"], "2023-02-25")
        self.assertEqual(first["training_label_end"], "2023-02-25")
        self.assertEqual(first["evaluations"]["7"]["mae"], 630.0)
        self.assertEqual(first["evaluations"]["7"]["baseline_mae"], 630.0)
        self.assertEqual(set(result["evaluations"]), {7, 14, 28})
        self.assertEqual(result["metadata"]["data_window_end"], "2023-05-20")
        self.assertEqual(result["metadata"]["products"], [1])
        for fold in result["metadata"]["folds"]:
            self.assertLessEqual(fold["training_label_end"], fold["origin"])
            self.assertGreater(fold["validation_start"], fold["origin"])

    def test_excluded_columns_and_future_values_cannot_change_features(self):
        data = history(90)
        expected = build_feature_matrix(data, "2023-02-01")
        data.loc[data.date > "2023-02-01", "units_sold"] = 9000
        for column in ("Demand Forecast", "Category", "Region", "Price", "Holiday/Promotion"):
            data[column] = "untrusted future value"
        pd.testing.assert_frame_equal(expected, build_feature_matrix(data, "2023-02-01"))

    def test_corrections_enter_features_and_labels_only_when_known(self):
        from feature_pipeline import build_training_rows
        data = history(140)
        data["available_date"] = data.date
        correction = pd.DataFrame({"date": [pd.Timestamp("2023-02-19")], "product_id": [1],
                                   "units_sold": [5.0], "available_date": [pd.Timestamp("2023-03-01")]})
        data = pd.concat([data, correction], ignore_index=True)
        before = build_feature_matrix(data, "2023-02-24")
        after = build_feature_matrix(data, "2023-03-01")
        self.assertEqual(before.rolling_mean_28.iloc[0], 10.0)
        self.assertAlmostEqual(after.rolling_mean_28.iloc[0], 275 / 28)
        self.assertEqual(set(build_training_rows(data, "2023-02-25").units_sold), {10.0})
        self.assertIn(5.0, set(build_training_rows(data, "2023-03-10").units_sold))

    def test_invalid_or_insufficient_observations_fail(self):
        from feature_pipeline import train_and_evaluate
        for data in (history(0), history(55), history(140, -1), history(140, float("nan")),
                     pd.concat([history(), history().iloc[:1]])):
            with self.subTest(rows=len(data)), self.assertRaises(ValueError):
                train_and_evaluate(data)

    def test_supplied_demo_csv_has_reproducible_evaluation(self):
        from feature_pipeline import train_and_evaluate
        path = Path(__file__).resolve().parents[1] / "dataset" / "retail_store_inventory.csv"
        data = pd.read_csv(path)
        selected = data[data["Store ID"] == "S001"]
        result = train_and_evaluate(selected)
        meta = result["metadata"]
        self.assertEqual(meta["observations"], 14620)
        self.assertEqual(meta["data_window_start"], "2022-01-01")
        self.assertEqual(meta["data_window_end"], "2024-01-01")
        self.assertEqual(meta["products"], [f"P{i:04}" for i in range(1, 21)])
        self.assertEqual(len(meta["folds"]), 3)
        print("Demo CSV evaluation:", {h: round(m["mae"], 4) for h, m in result["evaluations"].items()})


if __name__ == "__main__":
    unittest.main()
