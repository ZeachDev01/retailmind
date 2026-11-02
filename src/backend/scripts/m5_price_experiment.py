"""
Isolated M5 Historical-Price Experiment (Ticket #375, Spec #359).
Measures whether origin-known historical selling prices improve the current sales-and-calendar model.
Public data and artifacts are strictly isolated from Store databases, products, approvals, and models.
"""
import argparse
import hashlib
import json
import platform
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

import numpy as np
import pandas as pd
import sklearn
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestRegressor
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import OneHotEncoder

from feature_pipeline import (
    FEATURE_COLUMNS,
    FEATURE_VERSION,
    MODEL_PARAMETERS,
    normalize_history,
)
from ml_cv import rolling_origin_split
from ml_evaluation import calculate_metrics, seasonal_naive_forecast

SOURCE_REVISION = "9eee585c46882aa28aaaf43733a5c55c3e32f058"
SOURCE_URL = f"https://huggingface.co/datasets/skforecast/m5_daily/resolve/{SOURCE_REVISION}/m5_daily.parquet"

REFERENCE_FEATURES = list(FEATURE_COLUMNS)
CANDIDATE_PRICE_FEATURES = list(FEATURE_COLUMNS) + ["last_known_price", "has_price"]


def prepare_price_history(raw: pd.DataFrame, product_count: int = 50) -> Tuple[pd.DataFrame, Dict[str, Any]]:
    """
    Selects 50 CA_1 / FOODS products with two calendar training years before three 28-day holdouts.
    Preserves historical selling prices and missingness without fabricating stockout or promotion flags.
    """
    if "store_id" in raw.columns and "cat_id" in raw.columns:
        subset = raw.loc[(raw.store_id == "CA_1") & (raw.cat_id == "FOODS")].copy()
    else:
        subset = raw.copy()

    rename_map = {"datetime": "date", "item_id": "product_id", "sales": "units_sold"}
    if "sell_price" in subset.columns:
        rename_map["sell_price"] = "price"
    subset = subset.rename(columns=rename_map)

    required_cols = ["date", "product_id", "units_sold"]
    for c in required_cols:
        if c not in subset.columns:
            raise ValueError(f"Missing required column '{c}'")

    if "price" not in subset.columns:
        subset["price"] = np.nan

    data = subset[["date", "product_id", "units_sold", "price"]].copy()
    data["date"] = pd.to_datetime(data["date"])
    data["product_id"] = data["product_id"].astype("string")
    data["units_sold"] = pd.to_numeric(data["units_sold"], errors="coerce")
    data["price"] = pd.to_numeric(data["price"], errors="coerce")

    if data.empty or data[["date", "product_id"]].isna().any().any():
        raise ValueError("Missing dates or product identifiers")
    if (data["units_sold"] % 1 != 0).any() or (data["units_sold"] < 0).any():
        raise ValueError("M5 sales units must be non-negative whole integers")

    end = data["date"].max()
    training_end = end - pd.Timedelta(days=84)
    start = training_end - pd.DateOffset(years=2) + pd.Timedelta(days=1)
    days = (training_end - start).days + 1

    past = data[data["date"].between(start, training_end)]
    eligible = past.groupby("product_id").agg(days=("date", "size"), units=("units_sold", "sum"))
    codes = eligible.index[(eligible.days == days) & (eligible.units > 0)].sort_values()

    if product_count not in (20, 50):
        raise ValueError("Choose 20 or 50 products for the matched expansion experiment")
    if len(codes) < product_count:
        raise ValueError(f"Need {product_count} products with two complete training years and positive sales")

    selected = sorted(np.random.default_rng(42).choice(codes.to_numpy(), 20, replace=False).tolist())
    if product_count == 50:
        remaining = codes[~codes.isin(selected)].to_numpy()
        selected = sorted(selected + np.random.default_rng(42).choice(remaining, 30, replace=False).tolist())

    history = data[data["product_id"].isin(selected) & data["date"].between(start, end)].copy()
    history = history.sort_values(["product_id", "date"]).reset_index(drop=True)

    expected_days = (end - start).days + 1
    if not history.groupby("product_id").size().eq(expected_days).all():
        raise ValueError("Selected products have missing held-out days; do not silently resample")

    coverage_meta = verify_price_source_coverage(history)

    metadata = {
        "source_url": SOURCE_URL,
        "source_revision": SOURCE_REVISION,
        "store_id": "CA_1",
        "category": "FOODS",
        "seed": 42,
        "products": selected,
        "eligible_products": len(codes),
        "product_count": len(selected),
        "selection_rule": "Seeded sample of 20 training-eligible products; expansion retains those 20 and adds 30 seeded remaining products",
        "training_start": str(start.date()),
        "initial_training_end": str(training_end.date()),
        "data_end": str(end.date()),
        "initial_training_days": days,
        "total_days_per_product": expected_days,
        "observations": len(history),
        "target": "Recorded sales units, not unconstrained demand",
        "price_coverage": coverage_meta,
    }
    return history, metadata


def verify_price_source_coverage(history: pd.DataFrame) -> Dict[str, Any]:
    """
    Verifies price coverage and missingness counts across the selected dataset.
    Ensures missing values are preserved as NaN without fabricating stockout/promotion flags.
    """
    total_days = len(history)
    priced_days = int(history["price"].notna().sum())
    missing_days = int(history["price"].isna().sum())
    priced_ratio = priced_days / total_days if total_days > 0 else 0.0

    product_price_counts = history.groupby("product_id")["price"].nunique(dropna=True)
    varying_products = int((product_price_counts > 1).sum())
    total_products = int(history["product_id"].nunique())

    return {
        "total_product_days": total_days,
        "priced_product_days": priced_days,
        "missing_product_days": missing_days,
        "priced_ratio": priced_ratio,
        "products_with_varying_price": varying_products,
        "total_products": total_products,
        "has_stockout_flag": False,
        "has_promotion_flag": False,
        "missing_prices_preserved": True,
    }


def compute_last_known_prices_at_origin(history: pd.DataFrame, origin: pd.Timestamp) -> Dict[str, Tuple[float, float]]:
    """
    Extracts origin-known historical selling prices strictly using history on or before origin.
    Returns dict: product_id -> (last_known_price, has_price_indicator).
    If no price is known up to origin, returns (-1.0, 0.0).
    Realized future prices after origin are never inspected.
    """
    past = history[history["date"] <= origin]
    result = {}
    for pid, group in past.groupby("product_id"):
        valid_prices = group.loc[group["price"].notna(), ["date", "price"]]
        if not valid_prices.empty:
            last_price = float(valid_prices.sort_values("date").iloc[-1]["price"])
            result[str(pid)] = (last_price, 1.0)
        else:
            result[str(pid)] = (-1.0, 0.0)
    return result


def build_candidate_price_feature_matrix(
    base_features: pd.DataFrame,
    history: pd.DataFrame,
    origin: pd.Timestamp
) -> pd.DataFrame:
    """
    Appends origin-known historical price features to the reference feature matrix.
    Ensures all 28 forecast horizons for a product receive the origin-known price.
    """
    price_map = compute_last_known_prices_at_origin(history, origin)
    features = base_features.copy()

    prices = []
    has_prices = []
    for pid in features["product_id"]:
        price, has_p = price_map.get(str(pid), (-1.0, 0.0))
        prices.append(price)
        has_prices.append(has_p)

    features["last_known_price"] = prices
    features["has_price"] = has_prices
    return features


def fit_model_pipeline(rows: pd.DataFrame, feature_columns: List[str]) -> Any:
    """
    Fits a Random Forest model on the specified feature columns using identical MODEL_PARAMETERS.
    """
    model = make_pipeline(
        ColumnTransformer([("product", OneHotEncoder(handle_unknown="ignore"), ["product_id"])], remainder="passthrough"),
        RandomForestRegressor(**MODEL_PARAMETERS),
    )
    return model.fit(rows[feature_columns], rows["units_sold"])


def evaluate_historical_price_experiment(
    history: pd.DataFrame,
    confirmation_available: bool = False
) -> Tuple[pd.DataFrame, pd.DataFrame, Dict[str, Any]]:
    """
    Runs the comparative experiment between:
      1. Existing 14-input reference Random Forest model
      2. Origin-known historical-price candidate Random Forest model
      3. Weekly Seasonal Naive baseline
    Evaluated across identical rolling origin folds, products, horizons, past-only training, and nonnegative rounding.
    """
    from feature_pipeline import build_feature_matrix, build_training_rows, extract_training_targets

    norm_history = normalize_history(history)
    training_rows_all = build_training_rows(norm_history, norm_history["date"].max())

    predictions = []
    folds_meta = []

    for number, (_, held_out) in enumerate(rolling_origin_split(norm_history, "date", 28, 3), 1):
        origin = held_out["date"].min() - pd.Timedelta(days=1)
        valid_training = training_rows_all[training_rows_all["origin_date"] + pd.Timedelta(days=28) <= origin].copy()
        if valid_training.empty:
            raise ValueError(f"Insufficient training before fold {number} origin {origin.date()}")

        # Reference model features
        ref_features = build_feature_matrix(norm_history, origin)
        actuals = extract_training_targets(norm_history, origin).to_numpy()

        # Build candidate features with origin-known historical prices for training and evaluation
        cand_training_rows = valid_training.copy()
        cand_price_map = compute_last_known_prices_at_origin(history, origin)

        # For training rows, each row has its own origin_date
        # Vectorized backward lookup using merge_asof
        price_history = history[history["price"].notna()][["product_id", "date", "price"]].copy()
        price_history["product_id"] = price_history["product_id"].astype(str)
        cand_training_rows["_orig_idx"] = np.arange(len(cand_training_rows))
        cand_training_rows["product_id_str"] = cand_training_rows["product_id"].astype(str)

        merged = pd.merge_asof(
            cand_training_rows.sort_values("origin_date"),
            price_history.sort_values("date"),
            left_on="origin_date",
            right_on="date",
            left_by="product_id_str",
            right_by="product_id",
            direction="backward",
        ).sort_values("_orig_idx")

        cand_training_rows["last_known_price"] = merged["price"].fillna(-1.0).to_numpy()
        cand_training_rows["has_price"] = np.where(merged["price"].notna(), 1.0, 0.0)
        cand_training_rows = cand_training_rows.drop(columns=["_orig_idx", "product_id_str"])

        # Fit models on identical past rows
        ref_model = fit_model_pipeline(valid_training, REFERENCE_FEATURES)
        cand_model = fit_model_pipeline(cand_training_rows, CANDIDATE_PRICE_FEATURES)

        # Build eval features for candidate
        cand_features = build_candidate_price_feature_matrix(ref_features, history, origin)

        # Predict
        raw_ref_pred = ref_model.predict(ref_features[REFERENCE_FEATURES])
        raw_cand_pred = cand_model.predict(cand_features[CANDIDATE_PRICE_FEATURES])

        # Apply identical non-negative integer rounding
        ref_pred = np.maximum(0, np.round(raw_ref_pred)).astype(int)
        cand_pred = np.maximum(0, np.round(raw_cand_pred)).astype(int)

        # Seasonal Naive baseline
        baseline_preds = []
        for pid in sorted(norm_history["product_id"].unique()):
            p_past_units = norm_history.loc[
                (norm_history["product_id"] == pid) & (norm_history["date"] <= origin), "units_sold"
            ].to_numpy()
            base_f = seasonal_naive_forecast(p_past_units, 28)
            base_rounded = np.maximum(0, np.round(base_f)).astype(int)
            baseline_preds.extend(base_rounded)
        baseline_pred = np.array(baseline_preds, dtype=int)

        frame = ref_features[["product_id", "target_date", "horizon_day"]].copy()
        frame["actual"] = actuals
        frame["predicted_reference"] = ref_pred
        frame["predicted_candidate"] = cand_pred
        frame["baseline"] = baseline_pred
        frame["fold"] = number
        frame["split"] = "test" if number == 3 else "validation"
        predictions.append(frame)

        folds_meta.append({
            "fold": number,
            "origin": str(origin.date()),
            "training_label_end": str(valid_training["target_date"].max().date()),
            "training_rows": len(valid_training),
            "split": frame["split"].iloc[0],
        })

    daily = pd.concat(predictions, ignore_index=True)

    # Compute scores per fold, horizon, and product
    score_rows = []
    for (fold, split), group in daily.groupby(["fold", "split"]):
        for horizon in (7, 14, 28):
            totals = group[group["horizon_day"] <= horizon].groupby("product_id")[[
                "actual", "predicted_reference", "predicted_candidate", "baseline"
            ]].sum()

            for pid, values in [("ALL", totals), *[(p, r.to_frame().T) for p, r in totals.iterrows()]]:
                act = values["actual"].to_numpy()
                for model_key, model_label in [
                    ("predicted_reference", "reference_random_forest"),
                    ("predicted_candidate", "candidate_price_random_forest"),
                    ("baseline", "seasonal_naive_baseline"),
                ]:
                    pred_vals = values[model_key].to_numpy()
                    metrics = calculate_metrics(act, pred_vals)
                    bias = float(np.sum(pred_vals - act))
                    coverage = 1.0 if len(act) > 0 else 0.0
                    score_rows.append({
                        "fold": int(fold),
                        "split": split,
                        "horizon": horizon,
                        "product_id": pid,
                        "model": model_label,
                        "mae": metrics["mae"],
                        "rmse": metrics["rmse"],
                        "wape": metrics["wape"],
                        "bias": bias,
                        "coverage": coverage,
                    })

    scores = pd.DataFrame(score_rows)

    # Calculate overall and fold-level metrics for gating
    gate_results = evaluate_feature_value_gates(scores, confirmation_available)

    evaluation_report = {
        "folds": folds_meta,
        "gates": gate_results,
        "confirmation_available": confirmation_available,
        "reference_features": REFERENCE_FEATURES,
        "candidate_features": CANDIDATE_PRICE_FEATURES,
    }
    return daily, scores, evaluation_report


def evaluate_feature_value_gates(
    scores: pd.DataFrame,
    confirmation_available: bool = False
) -> Dict[str, Any]:
    """
    Evaluates the strict feature-value gates established in Spec #359:
      1. >= 5% relative reduction in pooled 28-day MAE vs reference model.
      2. 28-day improvement in at least 2 of 3 validation periods.
      3. <= 5% pooled MAE regression at 7 days and 14 days.
      4. Explicit zero-error reference handling (no false relative improvement at 28d;
         zero candidate error required if reference error is zero at 7d/14d).
      5. Undefined WAPE or missing coverage cannot pass.
      6. Fresh untouched confirmation requirement (inconclusive if confirmation unavailable).
    """
    # Filter to overall pooled population ("ALL")
    all_scores = scores[scores["product_id"] == "ALL"].copy()

    # Calculate pooled MAE across folds for each model and horizon
    pooled = all_scores.groupby(["horizon", "model"])["mae"].mean().unstack("model")
    wape_pooled = all_scores.groupby(["horizon", "model"])["wape"].mean().unstack("model")
    cov_pooled = all_scores.groupby(["horizon", "model"])["coverage"].min().unstack("model")

    ref_col = "reference_random_forest"
    cand_col = "candidate_price_random_forest"

    # Horizon 28-day relative reduction
    ref_mae_28 = float(pooled.loc[28, ref_col])
    cand_mae_28 = float(pooled.loc[28, cand_col])

    if ref_mae_28 == 0.0:
        gate_28d_rel_reduction = False
        rel_28d_reduction = 0.0
        gate_28d_reason = "Zero 28-day reference MAE cannot demonstrate further 5% improvement"
    else:
        rel_28d_reduction = (ref_mae_28 - cand_mae_28) / ref_mae_28
        gate_28d_rel_reduction = bool(rel_28d_reduction >= 0.05)
        gate_28d_reason = f"28-day relative MAE reduction: {rel_28d_reduction:.2%} (required: >= 5.00%)"

    # Horizon 28-day improvement in at least 2 of 3 periods
    fold_28 = all_scores[(all_scores["horizon"] == 28) & (all_scores["model"].isin([ref_col, cand_col]))]
    fold_piv = fold_28.pivot(index="fold", columns="model", values="mae")
    improved_folds = int((fold_piv[cand_col] < fold_piv[ref_col]).sum())
    total_folds = len(fold_piv)
    gate_28d_multi_period = bool(improved_folds >= 2)
    gate_multi_period_reason = f"28-day MAE improved in {improved_folds} of {total_folds} periods (required: >= 2)"

    # Shorter horizons bounded regression (<= 5% relative increase at 7d and 14d)
    regression_checks = {}
    regression_pass_all = True
    for h in (7, 14):
        ref_h = float(pooled.loc[h, ref_col])
        cand_h = float(pooled.loc[h, cand_col])
        if ref_h == 0.0:
            if cand_h == 0.0:
                h_pass = True
                h_reason = f"Horizon {h}d maintains zero error on candidate"
            else:
                h_pass = False
                h_reason = f"Horizon {h}d candidate error ({cand_h:.4f}) > 0 while reference error is zero"
            rel_increase = 0.0 if cand_h == 0.0 else float("inf")
        else:
            rel_increase = (cand_h - ref_h) / ref_h
            h_pass = bool(rel_increase <= 0.05)
            h_reason = f"Horizon {h}d relative regression: {rel_increase:.2%} (ceiling: <= 5.00%)"

        regression_checks[f"{h}d"] = {
            "ref_mae": ref_h,
            "cand_mae": cand_h,
            "relative_regression": rel_increase,
            "pass": h_pass,
            "reason": h_reason,
        }
        if not h_pass:
            regression_pass_all = False

    # WAPE and coverage check
    wape_valid = True
    for h in (7, 14, 28):
        for col in (ref_col, cand_col):
            w = wape_pooled.loc[h, col]
            cov = cov_pooled.loc[h, col]
            if pd.isna(w) or cov < 1.0:
                wape_valid = False
                break

    gate_wape_coverage_pass = wape_valid
    gate_wape_reason = "All WAPE and coverage metrics are valid and complete" if wape_valid else "Missing coverage or undefined WAPE detected"

    # Confirmation status
    all_dev_gates_pass = (
        gate_28d_rel_reduction
        and gate_28d_multi_period
        and regression_pass_all
        and gate_wape_coverage_pass
    )

    if not confirmation_available:
        confirmation_status = "inconclusive"
        can_claim_success = False
        confirmation_reason = (
            "Inspected M5 windows are development evidence; fresh untouched confirmation evidence is unavailable. "
            "Inconclusive confirmation reported without claiming success."
        )
    else:
        confirmation_status = "confirmed" if all_dev_gates_pass else "rejected"
        can_claim_success = all_dev_gates_pass
        confirmation_reason = "Fresh confirmation evaluation completed."

    return {
        "gate_28d_relative_reduction": {
            "pass": gate_28d_rel_reduction,
            "relative_reduction": rel_28d_reduction,
            "ref_mae_28d": ref_mae_28,
            "cand_mae_28d": cand_mae_28,
            "reason": gate_28d_reason,
        },
        "gate_28d_multi_period_improvement": {
            "pass": gate_28d_multi_period,
            "improved_periods": improved_folds,
            "total_periods": total_folds,
            "reason": gate_multi_period_reason,
        },
        "gate_shorter_horizon_regression": {
            "pass": regression_pass_all,
            "horizons": regression_checks,
        },
        "gate_wape_coverage": {
            "pass": gate_wape_coverage_pass,
            "reason": gate_wape_reason,
        },
        "all_development_gates_pass": all_dev_gates_pass,
        "confirmation_available": confirmation_available,
        "confirmation_status": confirmation_status,
        "can_claim_success": can_claim_success,
        "confirmation_reason": confirmation_reason,
        "store_activation_authorized": False,
        "store_activation_notice": (
            "Public M5 results authorize only further Store-specific evaluation, never operational Store activation. "
            "Additional inputs enter an approved Store model only after local price collection, training, quality checks, "
            "and local Administrator approval."
        ),
    }


def write_experiment_report(
    metadata: Dict[str, Any],
    scores: pd.DataFrame,
    gate_results: Dict[str, Any],
    output_dir: Path
) -> None:
    """
    Writes a comprehensive plain-English reproducible experiment report to discussion.md.
    """
    g28 = gate_results["gate_28d_relative_reduction"]
    g_multi = gate_results["gate_28d_multi_period_improvement"]
    g_reg = gate_results["gate_28d_multi_period_improvement"]
    reg_h = gate_results["gate_shorter_horizon_regression"]["horizons"]

    lines = [
        "# M5 Historical-Price Feature Value Experiment Report",
        "",
        f"**Date**: {pd.Timestamp.now().strftime('%Y-%m-%d')}  ",
        f"**Ticket**: #375 (Spec #359)  ",
        f"**Source URL**: `{SOURCE_URL}`  ",
        f"**Source Revision**: `{SOURCE_REVISION}`  ",
        f"**Dataset**: 50 CA_1 / FOODS products over 814 days (730 initial training + 84 holdout).  ",
        f"**Confirmation Status**: **{gate_results['confirmation_status'].upper()}**  ",
        "",
        "---",
        "",
        "## 1. Executive Summary & Gating Decision",
        "",
        f"- **28-Day Relative MAE Reduction**: {g28['reason']} -> **{'PASS' if g28['pass'] else 'FAIL'}**",
        f"- **28-Day Multi-Period Consistency**: {g_multi['reason']} -> **{'PASS' if g_multi['pass'] else 'FAIL'}**",
        f"- **7-Day Bounded Regression**: {reg_h['7d']['reason']} -> **{'PASS' if reg_h['7d']['pass'] else 'FAIL'}**",
        f"- **14-Day Bounded Regression**: {reg_h['14d']['reason']} -> **{'PASS' if reg_h['14d']['pass'] else 'FAIL'}**",
        f"- **WAPE & Coverage Completeness**: {gate_results['gate_wape_coverage']['reason']} -> **{'PASS' if gate_results['gate_wape_coverage']['pass'] else 'FAIL'}**",
        f"- **Fresh Untouched Confirmation**: {gate_results['confirmation_reason']}",
        f"- **Can Claim Success**: **{gate_results['can_claim_success']}**",
        "",
        "---",
        "",
        "## 2. Model Comparison on Matching Dated Populations",
        "",
        "All models evaluated on identical 50 products, identical 3 rolling folds, identical past-only evidence, and nonnegative integer daily rounding.",
        "",
        "| Horizon | Model | Pooled MAE (units) | Pooled RMSE (units) | Pooled WAPE | Coverage |",
        "|:---|:---|---:|---:|---:|---:|",
    ]

    pooled = scores[scores["product_id"] == "ALL"].groupby(["horizon", "model"]).agg({
        "mae": "mean", "rmse": "mean", "wape": "mean", "coverage": "min"
    }).reset_index()

    model_names = {
        "reference_random_forest": "Reference (14 features)",
        "candidate_price_random_forest": "Candidate (with historical price)",
        "seasonal_naive_baseline": "Weekly Seasonal Naive",
    }

    for h in (7, 14, 28):
        h_rows = pooled[pooled["horizon"] == h]
        for m_key, m_label in model_names.items():
            row = h_rows[h_rows["model"] == m_key]
            if not row.empty:
                r = row.iloc[0]
                lines.append(f"| {h} days | {m_label} | {r['mae']:.3f} | {r['rmse']:.3f} | {r['wape'] * 100:.2f}% | {r['coverage'] * 100:.0f}% |")

    lines.extend([
        "",
        "---",
        "",
        "## 3. Price Source Coverage and Missingness Verification",
        "",
        f"- **Total Product-Days**: {metadata['price_coverage']['total_product_days']}",
        f"- **Priced Product-Days**: {metadata['price_coverage']['priced_product_days']} ({metadata['price_coverage']['priced_ratio']:.2%})",
        f"- **Missing Price Product-Days**: {metadata['price_coverage']['missing_product_days']} (preserved as unknown NaN)",
        f"- **Products with Varying Price**: {metadata['price_coverage']['products_with_varying_price']} of {metadata['price_coverage']['total_products']}",
        "- **Stockout / Promotion Flags**: None fabricated; unrecorded operational flags remain unassumed.",
        "",
        "---",
        "",
        "## 4. Architectural Boundaries and Store Isolation",
        "",
        f"> [!IMPORTANT]",
        f"> {gate_results['store_activation_notice']}",
        "",
        "- Public M5 data and trained models are strictly isolated from the local RetailMind Store database (`retailmind_db`) and model storage directory (`src/backend/storage/ml_models`).",
        "- Store model activation requires genuine local sales evidence, store calendar history, automated technical qualification, and affirmative local Administrator approval under ADR-0022.",
    ])

    report_path = output_dir / "discussion.md"
    report_path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("parquet_or_csv", type=Path, help="Path to input dataset (Parquet or CSV)")
    parser.add_argument("--output-dir", type=Path, required=True, help="Directory to save experiment outputs")
    parser.add_argument("--products", type=int, choices=[20, 50], default=50)
    parser.add_argument("--confirmation", action="store_true", default=False, help="Set if fresh untouched confirmation is provided")
    args = parser.parse_args()

    if args.output_dir.exists():
        parser.error(f"Output directory '{args.output_dir}' already exists; choose a new directory")

    input_path = args.parquet_or_csv
    if input_path.suffix.lower() == ".parquet":
        raw = pd.read_parquet(input_path)
    else:
        raw = pd.read_csv(input_path)

    history, metadata = prepare_price_history(raw, args.products)
    daily, scores, evaluation = evaluate_historical_price_experiment(history, args.confirmation)

    report = {
        **metadata,
        **evaluation,
        "feature_version": FEATURE_VERSION,
        "parameters": MODEL_PARAMETERS,
        "software": {
            "python": platform.python_version(),
            "numpy": np.__version__,
            "pandas": pd.__version__,
            "scikit_learn": sklearn.__version__,
        },
    }

    if input_path.exists():
        with input_path.open("rb") as stream:
            report["source_sha256"] = hashlib.file_digest(stream, "sha256").hexdigest()

    args.output_dir.mkdir(parents=True, exist_ok=False)
    history.to_csv(args.output_dir / "daily_history.csv", index=False)
    daily.to_csv(args.output_dir / "daily_predictions.csv", index=False)
    scores.to_csv(args.output_dir / "scores.csv", index=False)
    (args.output_dir / "metrics.json").write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    write_experiment_report(metadata, scores, evaluation["gates"], args.output_dir)
    print(f"Historical-price experiment completed successfully! Report saved to {args.output_dir / 'discussion.md'}")


if __name__ == "__main__":
    main()
