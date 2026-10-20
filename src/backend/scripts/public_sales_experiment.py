"""Offline UCI experiment using the application's shared forecasting engine."""
import argparse
import hashlib
import json
import platform
from pathlib import Path

import joblib
import numpy as np
import pandas as pd
import sklearn

from feature_pipeline import FEATURE_COLUMNS, build_feature_matrix, normalize_history, train_and_evaluate

SOURCE_URL = "https://archive.ics.uci.edu/dataset/352/online+retail"


def split_history(history):
    """Initial training period and the same final 84 days used by the backtest."""
    data = normalize_history(history).sort_values(['date', 'product_id'])
    days = (data.date.max() - data.date.min()).days + 1
    if days < 140 or not data.groupby('product_id').size().eq(days).all():
        raise ValueError('Need at least 140 complete daily observations per product')
    cutoff = data.date.max() - pd.Timedelta(days=84)
    return data[data.date <= cutoff].copy(), data[data.date > cutoff].copy()


def prepare_history(raw):
    data = raw[["InvoiceNo", "StockCode", "Description", "Quantity", "InvoiceDate", "UnitPrice"]].copy()
    data["date"] = pd.to_datetime(data.InvoiceDate, errors="raise").dt.normalize()
    for column in ("Quantity", "UnitPrice"):
        data[column] = pd.to_numeric(data[column], errors="raise")
    if (data.empty or data.date.isna().any()
            or not np.isfinite(data[["Quantity", "UnitPrice"]].to_numpy()).all()
            or (data.Quantity % 1 != 0).any()):
        raise ValueError("Expected dated transactions with finite prices and integer quantities")
    start = data.date.min() + pd.Timedelta(days=1)
    end = data.date.max() - pd.Timedelta(days=1)
    selection_end = end - pd.Timedelta(days=84)
    if (selection_end - start).days < 55:
        raise ValueError("Need at least 140 retained calendar days for the shared evaluation")
    data["StockCode"] = data.StockCode.astype("string").str.strip()
    valid = (data.Quantity.gt(0) & data.UnitPrice.gt(0)
             & ~data.InvoiceNo.astype("string").str.upper().str.startswith("C", na=True)
             & data.StockCode.str.fullmatch(r"[0-9]{5}[A-Za-z]*", na=False)
             & data.date.between(start, end))
    sales = data.loc[valid].copy()
    past = sales[sales.date <= selection_end]
    initial_codes = set(past.loc[past.date == start, "StockCode"])
    ranked = (past[past.StockCode.isin(initial_codes)].groupby("StockCode").date.nunique()
              .rename("sale_days").reset_index()
              .sort_values(["sale_days", "StockCode"], ascending=[False, True]))
    codes = sorted(ranked.head(20).StockCode.tolist())
    if not codes:
        raise ValueError("No eligible products on the first retained date")
    daily = sales[sales.StockCode.isin(codes)].groupby(["StockCode", "date"]).Quantity.sum()
    grid = pd.MultiIndex.from_product([codes, pd.date_range(start, end)], names=["product_id", "date"])
    # shortcut: archive absence means zero recorded sales, never use this rule for Store evidence.
    history = daily.reindex(grid, fill_value=0).rename("units_sold").reset_index()
    products = (past[past.StockCode.isin(codes)].sort_values("InvoiceDate")
                .drop_duplicates("StockCode", keep="last")[["StockCode", "Description"]]
                .rename(columns={"StockCode": "product_id", "Description": "description"})
                .sort_values("product_id"))
    provenance = {
        "dataset_kind": "external_experiment", "source_url": SOURCE_URL,
        "citation": "Chen, D. (2015). Online Retail. UCI. https://doi.org/10.24432/C5BW33",
        "license": "CC BY 4.0", "source_rows": len(raw),
        "eligible_transaction_rows": len(sales),
        "excluded_transaction_rows": len(raw) - len(sales),
        "selected_transaction_rows": int(sales.StockCode.isin(codes).sum()),
        "selection_end": str(selection_end.date()),
        "selection_rule": "Up to 20 products present on first retained day; most pre-holdout sale dates; code breaks ties",
        "target": "Gross positive recorded sales units; return/cancellation rows excluded, not matched back to original sales",
        "zero_assumption": "Complete transaction archive: absent product/date means zero recorded sales, not zero demand",
        "limitations": "UK online gifts/wholesale, all countries pooled; no Store identity, stockout or availability evidence; boundary dates excluded",
        "deployment": "Offline experiment only; not a Store or Demo worker artifact",
    }
    return history, products, provenance


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("workbook", type=Path, help="Original UCI Online Retail.xlsx")
    parser.add_argument("--output-dir", type=Path, required=True, help="New directory; existing directories are refused")
    args = parser.parse_args()
    if args.output_dir.exists():
        parser.error("Output directory already exists; choose a new experiment directory")
    raw = pd.read_excel(args.workbook, engine="openpyxl")
    history, products, provenance = prepare_history(raw)
    train, test = split_history(history)
    result = train_and_evaluate(history)
    provenance.update({
        "source_sha256": hashlib.sha256(args.workbook.read_bytes()).hexdigest(),
        "software": {"python": platform.python_version(), "pandas": pd.__version__,
                     "scikit_learn": sklearn.__version__, "numpy": np.__version__},
    })
    result["metadata"].update(provenance)
    features = build_feature_matrix(history, history.date.max())
    predicted = result["model"].predict(features[FEATURE_COLUMNS])
    if not np.isfinite(predicted).all() or (predicted < 0).any():
        raise ValueError("Invalid experiment predictions")
    forecasts = features[["product_id", "target_date", "horizon_day"]].copy()
    forecasts["predicted_units"] = predicted
    report = {"metadata": result["metadata"], "evaluations": result["evaluations"]}
    args.output_dir.mkdir(parents=True, exist_ok=False)
    history.to_csv(args.output_dir / "daily_history.csv", index=False)
    train.to_csv(args.output_dir / "train.csv", index=False)
    test.to_csv(args.output_dir / "test.csv", index=False)
    products.to_csv(args.output_dir / "products.csv", index=False)
    forecasts.to_csv(args.output_dir / "forecasts.csv", index=False)
    (args.output_dir / "metrics.json").write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    joblib.dump(result, args.output_dir / "model.joblib")
    print(json.dumps({"output_dir": str(args.output_dir), "evaluations": result["evaluations"]}, indent=2))


if __name__ == "__main__":
    main()
