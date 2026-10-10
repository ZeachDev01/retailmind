"""One cutoff-aware feature contract for observed daily demand."""
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
    columns = ["date", "product_id", "units_sold"]
    versioned = "available_date" in df
    if versioned:
        columns.append("available_date")
    data = df.rename(columns={
        "Date": "date", "Product ID": "product_id", "Units Sold": "units_sold",
    })[columns].copy()
    data["date"] = pd.to_datetime(data["date"], errors="raise")
    data["units_sold"] = pd.to_numeric(data["units_sold"], errors="raise")
    if data.empty or data[["date", "product_id"]].isna().any().any():
        raise ValueError("No usable demand history or missing observations")
    if (data.date != data.date.dt.normalize()).any():
        raise ValueError("Demand history must use daily dates")
    values = data.units_sold.dropna() if versioned else data.units_sold
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
    if data.empty:
        raise ValueError("No usable demand history at cutoff")
    return data


def build_feature_matrix(df, cutoff_date, horizon_days=28):
    """Lag 1 is the observed origin day; never fill missing days with demand."""
    data = history_at(df, cutoff_date)
    cutoff = pd.Timestamp(cutoff_date)
    expected = pd.date_range(cutoff - pd.Timedelta(days=27), cutoff)
    rows = []
    for pid, group in data.groupby("product_id", sort=True):
        window = group[group.date.isin(expected)]
        if len(window) != 28:
            raise ValueError(f"Product {pid} requires 28 consecutive observed days at {cutoff.date()}")
        sales = window.units_sold.to_numpy(dtype=float)
        lags = {f"lag_{lag}": float(sales[-lag]) for lag in (1, 7, 14, 28)}
        rolling = {}
        for days in (7, 28):
            rolling[f"rolling_mean_{days}"] = float(np.mean(sales[-days:]))
            rolling[f"rolling_std_{days}"] = float(np.std(sales[-days:]))
        for h in range(1, horizon_days + 1):
            target = cutoff + pd.Timedelta(days=h)
            rows.append({
                "product_id": pid, "origin_date": cutoff, "target_date": target,
                "horizon_day": h, **lags, **rolling,
                "day_of_week": target.dayofweek, "day_of_month": target.day,
                "month": target.month, "is_weekend": int(target.dayofweek >= 5),
            })
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
    """Weekly origins, with all 28 labels observed by the training cutoff."""
    data = history_at(df, cutoff_date)
    cutoff = pd.Timestamp(cutoff_date)
    data = data[data.date <= cutoff]
    if data.empty:
        raise ValueError("No usable demand history")
    first_origin = data.groupby("product_id").date.min().max() + pd.Timedelta(days=27)
    last_origin = min(cutoff, data.date.max()) - pd.Timedelta(days=28)
    rows = []
    for origin in pd.date_range(first_origin, last_origin, freq="7D"):
        features = build_feature_matrix(df, origin)
        features["units_sold"] = extract_training_targets(df, origin, label_cutoff=cutoff).to_numpy()
        rows.append(features)
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
    """Three rolling 28-day holdouts; score per-product 7/14/28-day totals."""
    history = normalize_history(df)
    data = history_at(history, history.date.max())
    training = build_training_rows(history, data.date.max())
    folds = []
    totals = {h: {"actual": [], "predicted": [], "baseline": []} for h in (7, 14, 28)}
    for past, held_out in rolling_origin_split(data, "date", 28, n_splits=3):
        origin = held_out.date.min() - pd.Timedelta(days=1)
        # Purge entire origins whose final label was not observable at this cutoff.
        fit_rows = (build_training_rows(history, origin) if "available_date" in history else
                    training[training.origin_date + pd.Timedelta(days=28) <= origin])
        if fit_rows.empty:
            raise ValueError("Insufficient history for three chronological holdouts")
        model = _fit_model(fit_rows)
        features = build_feature_matrix(history, origin)
        actual = extract_training_targets(history, origin).to_numpy().reshape(-1, 28)
        predicted = model.predict(features[FEATURE_COLUMNS]).reshape(-1, 28)
        baseline = np.array([
            seasonal_naive_forecast(group.units_sold.to_numpy(), 28)
            for _, group in history_at(history, origin).groupby("product_id", sort=True)
        ])
        fold = {
            "origin": str(origin.date()),
            "training_label_end": str(fit_rows.target_date.max().date()),
            "training_rows": len(fit_rows),
            "validation_start": str((origin + pd.Timedelta(days=1)).date()),
            "validation_end": str((origin + pd.Timedelta(days=28)).date()),
            "evaluations": {},
        }
        for horizon in totals:
            a = actual[:, :horizon].sum(axis=1)
            p = predicted[:, :horizon].sum(axis=1)
            b = baseline[:, :horizon].sum(axis=1)
            metrics = calculate_metrics(a, p)
            metrics.update({f"baseline_{k}": v for k, v in calculate_metrics(a, b).items()})
            fold["evaluations"][str(horizon)] = metrics
            for key, values in (("actual", a), ("predicted", p), ("baseline", b)):
                totals[horizon][key].extend(values)
        folds.append(fold)
    if len(folds) != 3:
        raise ValueError("Insufficient history for three chronological holdouts")
    evaluations = {}
    for horizon, values in totals.items():
        actual = np.array(values["actual"])
        metrics = calculate_metrics(actual, np.array(values["predicted"]))
        metrics.update({f"baseline_{k}": v for k, v in
                        calculate_metrics(actual, np.array(values["baseline"])).items()})
        evaluations[horizon] = metrics
    model = _fit_model(training)
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
            "products": data.product_id.unique().tolist(),
            "observations": len(data), "folds": folds,
        },
    }
