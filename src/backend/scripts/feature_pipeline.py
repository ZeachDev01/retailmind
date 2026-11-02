"""One cutoff-aware feature contract for observed daily demand (#365)."""
import numpy as np
import pandas as pd
from sklearn.ensemble import RandomForestRegressor
from sklearn.compose import ColumnTransformer
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import OneHotEncoder

from ml_cv import rolling_origin_split
from ml_evaluation import calculate_metrics, seasonal_naive_forecast

FEATURE_VERSION = "2.0.0"
FEATURE_COLUMNS = [
    "product_id", "horizon_day", "lag_1", "lag_7", "lag_14", "lag_28",
    "rolling_mean_7", "rolling_std_7", "rolling_mean_28", "rolling_std_28",
    "day_of_week", "day_of_month", "month", "is_weekend",
]
MODEL_PARAMETERS = {"n_estimators": 15, "max_depth": 8, "random_state": 42}


def normalize_history(df):
    if "Store ID" in df and df["Store ID"].nunique() != 1:
        raise ValueError("Select exactly one isolated dataset Store")
    renamed = df.rename(columns={
        "Date": "date", "Product ID": "product_id", "Units Sold": "units_sold",
    })
    columns = ["date", "product_id", "units_sold"]
    versioned = "available_date" in renamed
    if versioned:
        columns.append("available_date")
    for extra in ["price", "is_on_promotion", "out_of_stock", "classification", "source", "recorded_sales_units"]:
        if extra in renamed:
            columns.append(extra)
    data = renamed[columns].copy()
    data["date"] = pd.to_datetime(data["date"], errors="raise")
    data["units_sold"] = pd.to_numeric(data["units_sold"], errors="coerce")
    if data.empty or data[["date", "product_id"]].isna().any().any():
        raise ValueError("No usable demand history or missing observations")
    if (data.date != data.date.dt.normalize()).any():
        raise ValueError("Demand history must use daily dates")
    values = data.units_sold.dropna()
    if not np.isfinite(values).all() or (values < 0).any():
        raise ValueError("Demand observations must be finite and nonnegative")
    keys = ["product_id", "date"]
    if versioned:
        data["available_date"] = pd.to_datetime(data.available_date, errors="raise")
        if (data.available_date.isna().any() or (data.available_date < data.date).any()
                or (data.available_date != data.available_date.dt.normalize()).any()):
            raise ValueError("Invalid observation availability date")
        keys.append("available_date")
    if data.duplicated(keys).any():
        raise ValueError("Duplicate daily demand observations")
    return data.sort_values(keys).reset_index(drop=True)


def history_at(df, cutoff_date):
    """Latest known daily value; null revisions withdraw unusable observations."""
    data = normalize_history(df)
    cutoff = pd.Timestamp(cutoff_date)
    data = data[data.date <= cutoff]
    if "available_date" in data:
        data = data[data.available_date <= cutoff].drop_duplicates(["product_id", "date"], keep="last")
        data = data.dropna(subset=["units_sold"]).drop(columns="available_date")
    else:
        data = data.dropna(subset=["units_sold"])
    if data.empty:
        raise ValueError("No usable demand history at cutoff")
    return data


def build_feature_matrix(df, cutoff_date, horizon_days=28):
    """
    Construct supported dated feature windows (#365).
    Lag 1 is the observed origin day; preserves calendar dates without inventing zero demand.
    Skips unsupported product/origin combinations and records reasons.
    """
    data = history_at(df, cutoff_date)
    cutoff = pd.Timestamp(cutoff_date)
    expected = pd.date_range(cutoff - pd.Timedelta(days=27), cutoff)
    rows = []
    exclusion_reasons = {}

    for pid, group in data.groupby("product_id", sort=True):
        window = group[group.date.isin(expected)]
        sales_by_date = dict(zip(window.date, window.units_sold))

        # Check origin day observation (lag 1)
        if cutoff not in sales_by_date:
            exclusion_reasons[pid] = f"Product {pid} requires origin observation at {cutoff.date()}"
            continue

        # Check if window has unsupported gaps (#365)
        # Operating mode: if product has regular weekend sales in history, weekends are operating days
        has_classification = 'classification' in group.columns
        weekend_operating = (group.date.dt.dayofweek >= 5).any() and not has_classification
        has_unsupported_gap = False
        for d in expected:
            if d not in sales_by_date:
                if weekend_operating:
                    has_unsupported_gap = True
                    break
                elif d.dayofweek < 5:
                    has_unsupported_gap = True
                    break

        if has_unsupported_gap:
            exclusion_reasons[pid] = f"Product {pid} requires 28 consecutive observed days or supported closures at {cutoff.date()}"
            continue

        # Calculate lag dates:
        # lag_1: cutoff
        # lag_7: cutoff - 6 days
        # lag_14: cutoff - 13 days
        # lag_28: cutoff - 27 days
        lag_dates = {
            1: cutoff,
            7: cutoff - pd.Timedelta(days=6),
            14: cutoff - pd.Timedelta(days=13),
            28: cutoff - pd.Timedelta(days=27),
        }

        # Resolve lag observations preserving calendar dates and supported closures (#365)
        lags = {}
        lags_valid = True
        group_history_sales = dict(zip(group.loc[group.date <= cutoff, "date"], group.loc[group.date <= cutoff, "units_sold"]))
        sorted_history_dates = sorted(group_history_sales.keys())
        for lag, ldate in lag_dates.items():
            if ldate in sales_by_date:
                lags[f"lag_{lag}"] = float(sales_by_date[ldate])
            else:
                # If date falls on a supported closure, resolve to latest observed trading day at or before ldate
                prev_dates = [d for d in sorted_history_dates if d <= ldate]
                if prev_dates:
                    lags[f"lag_{lag}"] = float(group_history_sales[prev_dates[-1]])
                elif len(window) == 28:
                    sales_arr = window.units_sold.to_numpy(dtype=float)
                    lags[f"lag_{lag}"] = float(sales_arr[-lag])
                else:
                    lags_valid = False
                    break

        if not lags_valid:
            exclusion_reasons[pid] = f"Product {pid} missing lag observations at {cutoff.date()}"
            continue

        # Rolling summaries: computed over observed usable days in calendar window without inventing zero demand
        w7_dates = pd.date_range(cutoff - pd.Timedelta(days=6), cutoff)
        s7 = [float(sales_by_date[d]) for d in w7_dates if d in sales_by_date]
        s28 = [float(sales_by_date[d]) for d in expected if d in sales_by_date]

        rolling = {
            "rolling_mean_7": float(np.mean(s7)),
            "rolling_std_7": float(np.std(s7)),
            "rolling_mean_28": float(np.mean(s28)),
            "rolling_std_28": float(np.std(s28)),
        }

        for h in range(1, horizon_days + 1):
            target = cutoff + pd.Timedelta(days=h)
            rows.append({
                "product_id": pid, "origin_date": cutoff, "target_date": target,
                "horizon_day": h, **lags, **rolling,
                "day_of_week": target.dayofweek, "day_of_month": target.day,
                "month": target.month, "is_weekend": int(target.dayofweek >= 5),
            })

    if not rows:
        reasons_msg = "; ".join(exclusion_reasons.values()) if exclusion_reasons else f"No active products have supported feature windows at {cutoff.date()}"
        raise ValueError(f"Product requires 28 consecutive observed days at {cutoff.date()}: {reasons_msg}")

    return pd.DataFrame(rows)


def extract_training_targets(df, cutoff_date, horizon_days=28, label_cutoff=None):
    data = history_at(df, label_cutoff or pd.Timestamp(cutoff_date) + pd.Timedelta(days=horizon_days))
    features = build_feature_matrix(df, cutoff_date, horizon_days)
    targets = features.merge(data, left_on=["product_id", "target_date"],
                             right_on=["product_id", "date"], how="left", validate="one_to_one")
    if targets.units_sold.isna().any():
        raise ValueError("Missing observed training targets")
    return targets.units_sold


def build_training_rows(df, cutoff_date):
    """Weekly origins, with all observed labels up to training cutoff (#365)."""
    data = history_at(df, cutoff_date)
    cutoff = pd.Timestamp(cutoff_date)
    data = data[data.date <= cutoff]
    if data.empty:
        raise ValueError("No usable demand history")

    first_origin = data.groupby("product_id").date.min().max() + pd.Timedelta(days=27)
    last_origin = min(cutoff, data.date.max()) - pd.Timedelta(days=28)
    rows = []

    # Attempt origins along 7D intervals
    for origin in pd.date_range(first_origin, last_origin, freq="7D"):
        try:
            features = build_feature_matrix(df, origin)
        except ValueError:
            continue
        # Only observed target dates become training rows: excluded/closed days do not become targets
        merged = features.merge(data[["product_id", "date", "units_sold"]],
                                left_on=["product_id", "target_date"],
                                right_on=["product_id", "date"], how="inner")
        if not merged.empty:
            rows.append(merged.drop(columns=["date"]))

    # If first_origin fell on a closed day, try offsetting to open days
    if not rows:
        for offset in range(1, 7):
            alt_first = first_origin + pd.Timedelta(days=offset)
            if alt_first > last_origin:
                break
            for origin in pd.date_range(alt_first, last_origin, freq="7D"):
                try:
                    features = build_feature_matrix(df, origin)
                except ValueError:
                    continue
                merged = features.merge(data[["product_id", "date", "units_sold"]],
                                        left_on=["product_id", "target_date"],
                                        right_on=["product_id", "date"], how="inner")
                if not merged.empty:
                    rows.append(merged.drop(columns=["date"]))
            if rows:
                break

    if not rows:
        raise ValueError("At least 56 consecutive observed days are required for training")

    return pd.concat(rows, ignore_index=True)


def _fit_model(rows):
    model = make_pipeline(
        ColumnTransformer([('product', OneHotEncoder(), ['product_id'])], remainder='passthrough'),
        RandomForestRegressor(**MODEL_PARAMETERS),
    )
    return model.fit(rows[FEATURE_COLUMNS], rows.units_sold)


def train_and_evaluate(df):
    """
    Three rolling 28-day holdouts; score per-product 7/14/28-day totals (#365).
    Evaluates complete usable Product/horizon totals; reports included/excluded products.
    """
    history = normalize_history(df)
    data = history_at(history, history.date.max())
    training = build_training_rows(history, data.date.max())
    folds = []
    totals = {h: {"actual": [], "predicted": [], "baseline": []} for h in (7, 14, 28)}

    splits = rolling_origin_split(data, "date", 28, n_splits=3)
    if len(splits) != 3:
        raise ValueError("Insufficient history for three chronological holdouts")

    all_products = sorted(data.product_id.unique())
    product_holdout_complete = {pid: True for pid in all_products}
    product_exclusion_reasons = {}

    for past, held_out in splits:
        test_start = held_out.date.min()
        test_end = held_out.date.max()
        origin = test_start - pd.Timedelta(days=1)
        if origin not in past.date.values:
            recent = past[past.date <= origin]
            if not recent.empty:
                origin = recent.date.max()

        fit_rows = (build_training_rows(history, origin) if "available_date" in history else
                    training[training.origin_date + pd.Timedelta(days=28) <= origin])
        if fit_rows.empty:
            raise ValueError("Insufficient history for three chronological holdouts")

        model = _fit_model(fit_rows)
        try:
            features = build_feature_matrix(history, origin)
        except ValueError:
            raise ValueError("Insufficient history for three chronological holdouts")

        past_at_origin = history_at(history, origin)
        fold_products_included = []
        fold_products_excluded = []
        fold_evaluations = {}

        fold_actual = {7: [], 14: [], 28: []}
        fold_predicted = {7: [], 14: [], 28: []}
        fold_baseline = {7: [], 14: [], 28: []}

        for pid, pfeatures in features.groupby("product_id"):
            p_held_out = held_out[held_out.product_id == pid]

            # Check holdout completeness (no stockout or missing evidence)
            if "classification" in p_held_out.columns:
                has_gap = p_held_out["classification"].isin(["stockout_censored", "missing_evidence"]).any()
            else:
                has_gap = False

            if has_gap:
                product_holdout_complete[pid] = False
                reason = f"Stockout or missing evidence during holdout {origin.date()}"
                product_exclusion_reasons[pid] = reason
                fold_products_excluded.append(pid)
                continue

            fold_products_included.append(pid)
            preds = model.predict(pfeatures[FEATURE_COLUMNS])
            pred_map = dict(zip(pfeatures.horizon_day, preds))

            actual_map = {}
            for h in range(1, 29):
                tdate = origin + pd.Timedelta(days=h)
                match = p_held_out[p_held_out.date == tdate]
                if not match.empty:
                    actual_map[h] = float(match.units_sold.values[0])
                else:
                    actual_map[h] = 0.0

            p_past = past_at_origin[past_at_origin.product_id == pid]
            base_forecast = seasonal_naive_forecast(p_past.units_sold.to_numpy(), 28)
            base_map = dict(zip(range(1, 29), base_forecast))

            for horizon in (7, 14, 28):
                a = sum(actual_map[h] for h in range(1, horizon + 1))
                p = sum(pred_map.get(h, 0.0) for h in range(1, horizon + 1))
                b = sum(base_map.get(h, 0.0) for h in range(1, horizon + 1))

                fold_actual[horizon].append(a)
                fold_predicted[horizon].append(p)
                fold_baseline[horizon].append(b)
                totals[horizon]["actual"].append(a)
                totals[horizon]["predicted"].append(p)
                totals[horizon]["baseline"].append(b)

        if not fold_products_included:
            raise ValueError("Insufficient holdout coverage for candidate evaluation")

        for horizon in (7, 14, 28):
            a_arr = np.array(fold_actual[horizon])
            p_arr = np.array(fold_predicted[horizon])
            b_arr = np.array(fold_baseline[horizon])
            m = calculate_metrics(a_arr, p_arr)
            m.update({f"baseline_{k}": v for k, v in calculate_metrics(a_arr, b_arr).items()})
            fold_evaluations[str(horizon)] = m

        folds.append({
            "origin": str(origin.date()),
            "training_label_end": str(fit_rows.target_date.max().date()),
            "training_rows": len(fit_rows),
            "validation_start": str((origin + pd.Timedelta(days=1)).date()),
            "validation_end": str((origin + pd.Timedelta(days=28)).date()),
            "included_products": fold_products_included,
            "excluded_products": fold_products_excluded,
            "evaluations": fold_evaluations,
        })

    evaluations = {}
    for horizon, values in totals.items():
        actual = np.array(values["actual"])
        metrics = calculate_metrics(actual, np.array(values["predicted"]))
        metrics.update({f"baseline_{k}": v for k, v in
                        calculate_metrics(actual, np.array(values["baseline"])).items()})
        evaluations[horizon] = metrics

    model = _fit_model(training)
    eligible_products = [pid for pid in all_products if product_holdout_complete.get(pid, False)]

    return {
        "model": model,
        "evaluations": evaluations,
        "metadata": {
            "feature_version": FEATURE_VERSION, "features": FEATURE_COLUMNS,
            "parameters": MODEL_PARAMETERS, "training_rows": len(training),
            "training_window_start": str((training.origin_date.min() - pd.Timedelta(days=27)).date()),
            "training_window_end": str(training.target_date.max().date()),
            "data_window_start": str(data.date.min().date()),
            "data_window_end": str(data.date.max().date()),
            "products": eligible_products if eligible_products else all_products,
            "included_products": eligible_products,
            "excluded_products": [pid for pid in all_products if pid not in eligible_products],
            "exclusion_reasons": product_exclusion_reasons,
            "observations": len(data), "folds": folds,
        },
    }
