#!/usr/bin/env python3
import sys
from pathlib import Path
import pandas as pd
import numpy as np

# Add scripts directory to sys.path
SCRIPTS_DIR = Path(__file__).resolve().parents[1] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

from feature_pipeline import build_feature_matrix, extract_training_targets

def test_feature_pipeline_lags_and_horizon_conditioning():
    """
    Vertical Slice 1:
    Verify that build_feature_matrix generates 28 horizon rows per product
    with correct historical lag features (lag_1, lag_7, lag_14, lag_28)
    at the forecast origin cutoff without leaking post-cutoff data.
    """
    # Create 35 days of deterministic daily history: 2023-01-01 to 2023-02-04
    dates = pd.date_range("2023-01-01", periods=35, freq="D")
    # sales = 10, 11, 12, ... 44
    sales = [10 + i for i in range(35)]

    df = pd.DataFrame({
        "Date": dates.strftime("%Y-%m-%d"),
        "Store ID": "S001",
        "Product ID": "P0001",
        "Units Sold": sales,
        "Price": 25.0
    })

    cutoff_date = "2023-01-31"  # index 30: sales[30] = 40 (on 2023-01-31)
    # Lags available at origin 2023-01-31:
    # lag_1 (sales on 2023-01-31): sales[30] = 40
    # lag_7 (sales on 2023-01-25): sales[24] = 34
    # lag_14 (sales on 2023-01-18): sales[17] = 27
    # lag_28 (sales on 2023-01-04): sales[3] = 13

    features_df = build_feature_matrix(df, cutoff_date=cutoff_date, horizon_days=28)

    # 1. Verification of row count: exactly 28 rows for the 28-day horizon
    assert len(features_df) == 28, f"Expected 28 rows, got {len(features_df)}"

    # 2. Verification of horizon_day sequence: 1 to 28
    assert list(features_df["horizon_day"]) == list(range(1, 29)), "horizon_day must run from 1 to 28"

    # 3. Verification of known-good lag literals across all rows for this origin
    for _, row in features_df.iterrows():
        assert row["lag_1"] == 40, f"Expected lag_1=40, got {row['lag_1']}"
        assert row["lag_7"] == 34, f"Expected lag_7=34, got {row['lag_7']}"
        assert row["lag_14"] == 27, f"Expected lag_14=27, got {row['lag_14']}"
        assert row["lag_28"] == 13, f"Expected lag_28=13, got {row['lag_28']}"

    # 4. Leakage prevention: check that future days (Feb 1 - Feb 4, sales 41-44)
    # did not overwrite the lag values
    assert 41 not in features_df[["lag_1", "lag_7", "lag_14", "lag_28"]].values

    print("Test passed: test_feature_pipeline_lags_and_horizon_conditioning")

def test_feature_pipeline_rolling_and_calendar_features():
    """
    Vertical Slice 2:
    Verify rolling demand statistics (7d and 28d rolling mean and std)
    and target date calendar features (day_of_week, day_of_month, month, is_weekend).
    """
    # 28 days of constant sales = 10 on 2023-01-04 to 2023-01-31
    # Preceded by 7 days of 10 on 2022-12-28 to 2023-01-03
    # Total 35 days where sales are all exactly 10.0
    dates = pd.date_range("2023-01-01", periods=31, freq="D")
    sales = [10.0] * 31

    df = pd.DataFrame({
        "Date": dates.strftime("%Y-%m-%d"),
        "Store ID": "S001",
        "Product ID": "P0001",
        "Units Sold": sales,
        "Price": 20.0
    })

    cutoff_date = "2023-01-31" # A Tuesday (day_of_week = 1)
    features_df = build_feature_matrix(df, cutoff_date=cutoff_date, horizon_days=7)

    # Check rolling statistics for constant sales of 10.0:
    # rolling_mean_7 should be exactly 10.0, rolling_std_7 should be 0.0
    # rolling_mean_28 should be exactly 10.0, rolling_std_28 should be 0.0
    row1 = features_df.iloc[0]
    assert "rolling_mean_7" in row1, "Missing rolling_mean_7"
    assert "rolling_std_7" in row1, "Missing rolling_std_7"
    assert "rolling_mean_28" in row1, "Missing rolling_mean_28"
    assert "rolling_std_28" in row1, "Missing rolling_std_28"

    assert np.isclose(row1["rolling_mean_7"], 10.0), f"Expected rolling_mean_7=10.0, got {row1['rolling_mean_7']}"
    assert np.isclose(row1["rolling_std_7"], 0.0), f"Expected rolling_std_7=0.0, got {row1['rolling_std_7']}"
    assert np.isclose(row1["rolling_mean_28"], 10.0), f"Expected rolling_mean_28=10.0, got {row1['rolling_mean_28']}"
    assert np.isclose(row1["rolling_std_28"], 0.0), f"Expected rolling_std_28=0.0, got {row1['rolling_std_28']}"

    # Check calendar features for target_date:
    # Horizon 1 target date is 2023-02-01 (Wednesday -> day_of_week=2, month=2, day_of_month=1, is_weekend=0)
    assert row1["day_of_week"] == 2, f"Expected day_of_week=2 for Wednesday, got {row1['day_of_week']}"
    assert row1["day_of_month"] == 1, f"Expected day_of_month=1, got {row1['day_of_month']}"
    assert row1["month"] == 2, f"Expected month=2, got {row1['month']}"
    assert row1["is_weekend"] == 0, f"Expected is_weekend=0, got {row1['is_weekend']}"

    # Horizon 4 target date is 2023-02-04 (Saturday -> day_of_week=5, is_weekend=1)
    row4 = features_df.iloc[3]
    assert row4["day_of_week"] == 5, f"Expected day_of_week=5 for Saturday, got {row4['day_of_week']}"
    assert row4["is_weekend"] == 1, f"Expected is_weekend=1 for Saturday, got {row4['is_weekend']}"

    print("Test passed: test_feature_pipeline_rolling_and_calendar_features")

def test_feature_pipeline_kaggle_dataset_integration():
    """
    Vertical Slice 3:
    Load the supplied Demo CSV dataset (retail_store_inventory.csv),
    filter to Store S001 (20 products), build feature matrix at cutoff 2023-10-09,
    and extract training targets without leakage or missing values.
    """
    kaggle_csv = Path(__file__).resolve().parents[1] / "dataset" / "retail_store_inventory.csv"
    assert kaggle_csv.exists(), f"Kaggle dataset missing at {kaggle_csv}"

    # Load Kaggle CSV
    df = pd.read_csv(kaggle_csv)
    # Filter to Store S001
    s001_df = df[df["Store ID"] == "S001"].copy()
    assert len(s001_df) == 14620, f"Expected 14,620 rows for S001, got {len(s001_df)}"

    cutoff_date = "2023-10-09"
    features_df = build_feature_matrix(s001_df, cutoff_date=cutoff_date, horizon_days=28)

    # Must produce 20 products * 28 horizon days = 560 rows
    assert len(features_df) == 560, f"Expected 560 feature rows, got {len(features_df)}"

    # Check that all 20 products P0001 - P0020 are present
    unique_products = sorted(features_df["product_id"].unique())
    assert len(unique_products) == 20
    assert unique_products[0] == "P0001"
    assert unique_products[-1] == "P0020"

    # Verify no NaN or null in any feature column
    feature_cols = [
        "horizon_day", "lag_1", "lag_7", "lag_14", "lag_28",
        "rolling_mean_7", "rolling_std_7", "rolling_mean_28", "rolling_std_28",
        "day_of_week", "day_of_month", "month", "is_weekend"
    ]
    for col in feature_cols:
        assert not features_df[col].isna().any(), f"Column {col} has NaN values"

    # Verify target extraction for supervised learning
    targets = extract_training_targets(s001_df, cutoff_date=cutoff_date, horizon_days=28)
    assert len(targets) == 560, f"Expected 560 target values, got {len(targets)}"
    assert not targets.isna().any(), "Target series contains NaN values"

    # Verify a known-good target value directly against the raw CSV:
    # On 2023-10-10 (horizon 1), what were the Units Sold for P0001?
    p1_oct10 = s001_df[(s001_df["Product ID"] == "P0001") & (s001_df["Date"] == "2023-10-10")]
    expected_sales = int(p1_oct10["Units Sold"].iloc[0])

    first_target = targets.iloc[0]
    assert int(first_target) == expected_sales, f"Expected target {expected_sales}, got {first_target}"

    print("Test passed: test_feature_pipeline_kaggle_dataset_integration")

if __name__ == "__main__":
    test_feature_pipeline_lags_and_horizon_conditioning()
    test_feature_pipeline_rolling_and_calendar_features()
    test_feature_pipeline_kaggle_dataset_integration()
