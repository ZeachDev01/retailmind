#!/usr/bin/env python3
"""Guard the single-Store Product + Day forecasting contract."""
from __future__ import annotations

import sys
from datetime import date as real_date
from pathlib import Path
from unittest.mock import patch

import numpy as np
import pandas as pd

FORECAST_DIR = Path(__file__).resolve().parents[1] / "legacy" / "demandForcasting"
sys.path.insert(0, str(FORECAST_DIR))
import train_model  # noqa: E402


class FixedDate(real_date):
    @classmethod
    def today(cls) -> "FixedDate":
        return cls(2026, 9, 18)


def assert_contract() -> None:
    forbidden_features = [name for name in train_model.FEATURE_COLUMNS if "branch" in name.lower()]
    assert not forbidden_features, f"Branch features entered the model: {forbidden_features}"
    assert "product_key" in train_model.FEATURE_COLUMNS, "Product identity feature is missing"

    history = pd.DataFrame(
        {
            "product_id": [1, 1, 2, 2],
            "product_key": ["P1", "P1", "P2", "P2"],
            "category": ["A", "A", "B", "B"],
            "sale_day": pd.to_datetime(["2026-09-16", "2026-09-17", "2026-09-16", "2026-09-17"]),
            "qty_sold": [1.0, 2.0, 10.0, 20.0],
        }
    )
    featured = train_model.build_features(history, train_model.DEFAULT_SETTINGS)
    grain = featured[["product_id", "sale_day"]]
    assert len(grain) == len(grain.drop_duplicates()), "Training rows are not unique by Product + Day"
    assert featured.loc[featured["product_id"] == 1, "lag_1"].tolist() == [0.0, 1.0]
    assert featured.loc[featured["product_id"] == 2, "lag_1"].tolist() == [0.0, 10.0]

    product_history = history[history["product_id"] == 1].copy()
    product_history["supplier_lead_time_days"] = 3
    settings = dict(train_model.DEFAULT_SETTINGS)
    with patch.object(train_model, "date", FixedDate), patch.object(
        train_model, "prediction_distribution", return_value=np.asarray([2.0, 4.0])
    ):
        result = train_model.forecast_product(object(), product_history, settings, 30)

    assert len(result.daily) == 30, "Forecast no longer returns one prediction per future day"
    assert result.daily[0]["date"] == "2026-09-19", "Forecast day boundary changed"
    assert result.demand_7d == 21 and result.demand_30d == 90, "Forecast aggregation changed"
    assert result.lead_time_demand == 9, "Lead-time aggregation changed"


if __name__ == "__main__":
    try:
        assert_contract()
    except AssertionError as error:
        raise SystemExit(f"Forecast regression failed: {error}") from error
    print("Forecast regression: passed (single-Store Product + Day grain and prediction totals)")
