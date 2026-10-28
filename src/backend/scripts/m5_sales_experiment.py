"""Offline M5 experiment; never imports public sales into the Store database."""
import argparse
import hashlib
import json
import platform
from pathlib import Path

import numpy as np
import pandas as pd
import sklearn

from feature_pipeline import (
    FEATURE_COLUMNS, FEATURE_VERSION, MODEL_PARAMETERS, _fit_model,
    build_feature_matrix, build_training_rows, extract_training_targets, normalize_history,
)
from ml_cv import rolling_origin_split
from ml_evaluation import calculate_metrics, seasonal_naive_forecast

SOURCE_REVISION = "9eee585c46882aa28aaaf43733a5c55c3e32f058"
SOURCE_URL = f"https://huggingface.co/datasets/skforecast/m5_daily/resolve/{SOURCE_REVISION}/m5_daily.parquet"


def prepare_history(raw, product_count=20):
    """Two calendar years before three 28-day holdouts; sample using past only."""
    data = raw.loc[(raw.store_id == "CA_1") & (raw.cat_id == "FOODS"),
                   ["datetime", "item_id", "sales"]].rename(columns={
                       "datetime": "date", "item_id": "product_id", "sales": "units_sold"})
    data["product_id"] = data.product_id.astype("string")
    data = normalize_history(data)
    if (data.units_sold % 1 != 0).any():
        raise ValueError("M5 sales must be whole units")
    end = data.date.max()
    training_end = end - pd.Timedelta(days=84)
    start = training_end - pd.DateOffset(years=2) + pd.Timedelta(days=1)
    past = data[data.date.between(start, training_end)]
    days = (training_end - start).days + 1
    eligible = past.groupby("product_id").agg(days=("date", "size"), units=("units_sold", "sum"))
    codes = eligible.index[(eligible.days == days) & (eligible.units > 0)].sort_values()
    if product_count not in (20, 50):
        raise ValueError("Choose 20 or 50 products for the matched expansion experiment")
    if len(codes) < product_count:
        raise ValueError(f"Need {product_count} products with two complete training years and positive training sales")
    selected = sorted(np.random.default_rng(42).choice(codes.to_numpy(), 20, replace=False).tolist())
    if product_count == 50:
        remaining = codes[~codes.isin(selected)].to_numpy()
        selected = sorted(selected + np.random.default_rng(42).choice(remaining, 30, replace=False).tolist())
    history = data[data.product_id.isin(selected) & data.date.between(start, end)].copy()
    expected_days = (end - start).days + 1
    if not history.groupby("product_id").size().eq(expected_days).all():
        raise ValueError("Selected products have missing held-out days; do not silently resample")
    return history, {
        "source_url": SOURCE_URL, "source_revision": SOURCE_REVISION,
        "store_id": "CA_1", "category": "FOODS", "seed": 42,
        "products": selected, "eligible_products": len(codes),
        "selection_rule": "Seeded sample of 20 training-eligible products; expansion retains those 20 and adds 30 seeded remaining products" if product_count == 50 else "Seeded uniform sample of sorted products complete and positive in training only",
        "training_start": str(start.date()), "initial_training_end": str(training_end.date()),
        "data_end": str(end.date()), "initial_training_days": days,
        "observations": len(history),
        "target": "Recorded sales units, not unconstrained demand",
    }


def sale_day_metrics(actual, predicted, threshold):
    actual = np.asarray(actual) > 0
    predicted = np.asarray(predicted) >= threshold
    tp = int((actual & predicted).sum())
    fp = int((~actual & predicted).sum())
    fn = int((actual & ~predicted).sum())
    tn = int((~actual & ~predicted).sum())
    return {
        "tp": tp, "fp": fp, "fn": fn, "tn": tn,
        "precision": tp / (tp + fp) if tp + fp else None,
        "recall": tp / (tp + fn) if tp + fn else None,
        "f1": 2 * tp / (2 * tp + fp + fn) if 2 * tp + fp + fn else None,
    }


def evaluate(history):
    """Frozen recipe: first two blocks validate tau; last block is the final test."""
    history = normalize_history(history)
    training = build_training_rows(history, history.date.max())
    predictions, folds = [], []
    for number, (_, held_out) in enumerate(rolling_origin_split(history, "date", 28, 3), 1):
        origin = held_out.date.min() - pd.Timedelta(days=1)
        rows = training[training.origin_date + pd.Timedelta(days=28) <= origin]
        if rows.empty:
            raise ValueError("Insufficient training before holdout")
        model = _fit_model(rows)
        features = build_feature_matrix(history, origin)
        frame = features[["product_id", "target_date", "horizon_day"]].copy()
        frame["actual"] = extract_training_targets(history, origin).to_numpy()
        frame["predicted"] = model.predict(features[FEATURE_COLUMNS])
        frame["baseline"] = np.concatenate([
            seasonal_naive_forecast(group.units_sold.to_numpy(), 28)
            for _, group in history[history.date <= origin].groupby("product_id", sort=True)
        ])
        if not np.isfinite(frame[["actual", "predicted", "baseline"]]).all().all():
            raise ValueError("Nonfinite experiment values")
        frame["fold"] = number
        frame["split"] = "test" if number == 3 else "validation"
        predictions.append(frame)
        folds.append({"fold": number, "origin": str(origin.date()),
                      "training_label_end": str(rows.target_date.max().date()),
                      "training_rows": len(rows), "split": frame.split.iloc[0]})
        print(f"Finished {frame.split.iloc[0]} fold {number}, origin {origin.date()}", flush=True)
    daily = pd.concat(predictions, ignore_index=True)
    validation = daily[daily.split == "validation"]
    thresholds = [0.25, 0.5, 0.75, 1.0, 1.5, 2.0]
    threshold = max(thresholds, key=lambda tau: (
        sale_day_metrics(validation.actual, validation.predicted, tau)["f1"] or 0))
    scores = []
    for (fold, split), group in daily.groupby(["fold", "split"]):
        for horizon in (7, 14, 28):
            totals = group[group.horizon_day <= horizon].groupby("product_id")[[
                "actual", "predicted", "baseline"]].sum()
            for pid, values in [("ALL", totals), *[(pid, row.to_frame().T) for pid, row in totals.iterrows()]]:
                for name in ("predicted", "baseline"):
                    scores.append({"fold": int(fold), "split": split, "horizon": horizon,
                                   "product_id": pid, "model": "random_forest" if name == "predicted" else "seasonal_naive",
                                   **calculate_metrics(values.actual.to_numpy(), values[name].to_numpy())})
    classification = {}
    for split, group in daily.groupby("split"):
        classification[split] = {
            "random_forest": sale_day_metrics(group.actual, group.predicted, threshold),
            "seasonal_naive": sale_day_metrics(group.actual, group.baseline, 0.5),
            "always_sale": sale_day_metrics(group.actual, np.ones(len(group)), 0.5),
        }
    return daily, pd.DataFrame(scores), {"folds": folds, "sale_day_threshold": threshold,
                                         "classification": classification}


def write_discussion(scores, report, output):
    lines = ["# M5 forecasting experiment results", "",
             f"Runtime: {report['runtime']}. Dataset revision: `{SOURCE_REVISION}`.",
             f"Initial training: {report['training_start']}–{report['initial_training_end']} "
             f"({report['initial_training_days']} days), {len(report['products'])} CA_1 FOODS products.",
             "First two 28-day blocks validate the sale-day threshold; the final 28 days are the test.", "",
             "| Test horizon | RF MAE (units) | RF RMSE (units) | RF WAPE | Baseline WAPE |",
             "|---|---:|---:|---:|---:|"]
    test = scores[(scores.split == "test") & (scores.product_id == "ALL")]
    for h in (7, 14, 28):
        group = test[test.horizon == h].set_index("model")
        rf, base = group.loc["random_forest"], group.loc["seasonal_naive"]
        percent = lambda value: "undefined" if pd.isna(value) else f"{100 * value:.2f}%"
        lines.append(f"| {h} days | {rf.mae:.3f} | {rf.rmse:.3f} | {percent(rf.wape)} | {percent(base.wape)} |")
    lines += ["", "MAE/RMSE above measure per-product horizon totals, not daily errors. "
              "WAPE is an error fraction displayed as a percentage, not an accuracy score.", "",
              f"Any-sale-day classification (threshold {report['sale_day_threshold']}, chosen on validation only):"]
    for name, metrics in report["classification"]["test"].items():
        lines.append(f"- {name}: `{json.dumps(metrics)}`")
    per_product = scores[(scores.split == "test") & (scores.product_id != "ALL") & (scores.horizon == 28)]
    comparison = per_product.pivot(index="product_id", columns="model", values="mae")
    losses = int((comparison.random_forest >= comparison.seasonal_naive).sum())
    lines += ["", "## Interpretation and weaknesses", "",
              f"Random Forest fails to improve 28-day absolute error over baseline for {losses}/{len(report['products'])} products. "
              "Inspect per-product scores; aggregate improvement can hide weak products.",
              "High sale-day F1 can come from frequently positive sales. Compare with always_sale; "
              "it does not establish quantity accuracy or stockout detection.",
              "Only one final 28-day test window is available; two training years contain few annual cycles. "
              "Walmart sales and US calendar effects do not establish local Store performance.",
              "Recorded zeros do not identify stockouts or lost demand. The shared model currently uses "
              "sales lags and calendar fields, not prices, promotions, or stock availability.", "",
              "## Improvements to investigate", "",
              "1. Inspect worst product/horizon errors and sustained over/underprediction in daily_predictions.csv.",
              "2. Use validation to test price/promotion features only when known at the forecast origin; "
              "do not use realized future prices or tune against this final test.",
              "3. Evaluate additional future windows and locally collected Store history before operational approval.", "",
              "## Integration boundary", "",
              "Collect actual Store sales first. Train an isolated candidate when complete usable history "
              "and chronological validation are sufficient; retain review/approval before daily forecast generation. "
              "Public M5 history and models stay separate. Forecasts support replenishment and never change stock.",
              "The existing readiness bands are below 56 days: Insufficient; 56–179: Low; 180–364: Medium; "
              "365+: High only with acceptable validation. Minimum feature history is not sufficient "
              "evidence for reliable validation or automatic approval.",
              "No production activation or accuracy pass is inferred from this experiment."]
    (output / "discussion.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("parquet", type=Path)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--products", type=int, choices=[20, 50], default=20)
    parser.add_argument("--runtime", choices=["local Python", "Google Colab"], default="local Python")
    args = parser.parse_args()
    if args.output_dir.exists():
        parser.error("Output directory already exists; choose a new experiment directory")
    raw = pd.read_parquet(args.parquet, columns=["datetime", "item_id", "sales", "store_id", "cat_id"],
                          filters=[("store_id", "==", "CA_1"), ("cat_id", "==", "FOODS")])
    history, metadata = prepare_history(raw, args.products)
    daily, scores, evaluation = evaluate(history)
    report = {**metadata, **evaluation, "feature_version": FEATURE_VERSION,
              "parameters": MODEL_PARAMETERS, "features": FEATURE_COLUMNS,
              "runtime": args.runtime,
              "software": {"python": platform.python_version(), "numpy": np.__version__,
                           "pandas": pd.__version__, "scikit_learn": sklearn.__version__}}
    with args.parquet.open("rb") as stream:
        report["source_sha256"] = hashlib.file_digest(stream, "sha256").hexdigest()
    args.output_dir.mkdir(parents=True, exist_ok=False)
    history.to_csv(args.output_dir / "daily_history.csv", index=False)
    daily.to_csv(args.output_dir / "daily_predictions.csv", index=False)
    scores.to_csv(args.output_dir / "scores.csv", index=False)
    (args.output_dir / "metrics.json").write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    write_discussion(scores, report, args.output_dir)
    print((args.output_dir / "discussion.md").read_text(encoding="utf-8"))


if __name__ == "__main__":
    main()
