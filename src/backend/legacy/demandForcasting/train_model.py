"""Random Forest demand forecasting with backtesting and prediction intervals.

Key improvements:
- Builds a complete daily series for every eligible product, including zero-sale days.
- Uses lag, rolling-demand, calendar, category, and product identity features.
- Performs a chronological holdout evaluation and calculates MAE, RMSE, WAPE,
  SMAPE, R², out-of-bag score, and per-product metrics.
- Produces lower/upper prediction ranges from the individual Random Forest trees.
- Records training history, forecast runs, and product-level evaluations in MySQL.
"""
from __future__ import annotations

import calendar
import json
import math
import os
import socket
import time
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any, Iterable

import joblib
import numpy as np
import pandas as pd
from sklearn.compose import ColumnTransformer
from sklearn.ensemble import RandomForestRegressor
from sklearn.metrics import mean_absolute_error, mean_squared_error, r2_score
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import OneHotEncoder

from db import get_connection

BASE_DIR = Path(__file__).resolve().parent
MODEL_PATH = BASE_DIR / "demand_model.joblib"
METRICS_PATH = BASE_DIR / "model_metrics.json"
TRAIN_LOCK_PATH = BASE_DIR / ".training.lock"
MODEL_VERSION = "rf-v2"
MODEL_NAME = "Random Forest Regressor"
MODEL_TYPE = "random_forest_regression"

DEFAULT_SETTINGS: dict[str, Any] = {
    "minimum_history_days": 30,
    "preferred_history_days": 90,
    "minimum_nonzero_sales_days": 5,
    "history_window_days": 365,
    "forecast_period_days": 30,
    "n_estimators": 300,
    "max_depth": 18,
    "min_samples_split": 4,
    "min_samples_leaf": 2,
    "retrain_frequency_days": 7,
    "retrain_new_sales_records": 100,
    "accuracy_threshold_wape": 35.0,
    "prediction_interval_lower": 10,
    "prediction_interval_upper": 90,
    "holiday_dates": "",
}

NUMERIC_FEATURES = [
    "lag_1",
    "lag_7",
    "rolling_mean_7",
    "rolling_mean_14",
    "rolling_mean_30",
    "rolling_std_7",
    "trend_7_30",
    "day_of_week",
    "week_of_year",
    "month",
    "day_of_month",
    "is_weekend",
    "is_payday",
    "is_holiday",
]
CATEGORICAL_FEATURES = ["category", "product_key"]
FEATURE_COLUMNS = NUMERIC_FEATURES + CATEGORICAL_FEATURES
MODEL_DESCRIPTION = (
    "Random Forest ensemble using complete daily product demand, zero-sale days, "
    "lag demand, rolling averages, rolling volatility, trend, calendar patterns, "
    "product category, and product identity."
)


@dataclass
class ForecastResult:
    demand_7d: int
    demand_30d: int
    lower_7d: int
    upper_7d: int
    lower_30d: int
    upper_30d: int
    lead_time_demand: int
    lead_time_lower: int
    lead_time_upper: int
    confidence: float
    daily: list[dict[str, Any]]


def ensure_ml_schema(conn) -> None:
    cursor = conn.cursor()
    statements = [
        """
        CREATE TABLE IF NOT EXISTS ml_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
        )
        """,
        """
        CREATE TABLE IF NOT EXISTS model_training_runs (
            training_run_id INT AUTO_INCREMENT PRIMARY KEY,
            model_name VARCHAR(100) NOT NULL,
            model_version VARCHAR(30) NOT NULL,
            trigger_type ENUM('manual','scheduled','automatic','cli') NOT NULL DEFAULT 'cli',
            status ENUM('running','completed','failed','skipped') NOT NULL DEFAULT 'running',
            started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            duration_seconds DECIMAL(10,2) NULL,
            sales_records_used INT DEFAULT 0,
            eligible_products INT DEFAULT 0,
            metrics_json LONGTEXT NULL,
            error_message TEXT NULL,
            host_name VARCHAR(150) NULL,
            INDEX idx_model_training_runs_started_at (started_at),
            INDEX idx_model_training_runs_status (status)
        )
        """,
        """
        CREATE TABLE IF NOT EXISTS forecast_evaluations (
            evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
            training_run_id INT NULL,
            product_id INT NOT NULL,
            evaluation_records INT NOT NULL DEFAULT 0,
            actual_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            predicted_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            mae DECIMAL(14,4) NULL,
            rmse DECIMAL(14,4) NULL,
            wape DECIMAL(10,4) NULL,
            smape DECIMAL(10,4) NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_forecast_evaluations_product (product_id, generated_at),
            FOREIGN KEY (training_run_id) REFERENCES model_training_runs(training_run_id) ON DELETE SET NULL,
            FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
        )
        """,
    ]
    for statement in statements:
        cursor.execute(statement)

    # Add newer forecast fields without failing existing installations.
    columns = {
        "lower_bound_7_days": "INT NULL",
        "upper_bound_7_days": "INT NULL",
        "lower_bound_30_days": "INT NULL",
        "upper_bound_30_days": "INT NULL",
        "lead_time_lower_bound": "INT NULL",
        "lead_time_upper_bound": "INT NULL",
        "forecast_explanation": "TEXT NULL",
    }
    cursor.execute("SHOW COLUMNS FROM stock_predictions")
    existing = {row[0] for row in cursor.fetchall()}
    for name, definition in columns.items():
        if name not in existing:
            cursor.execute(f"ALTER TABLE stock_predictions ADD COLUMN {name} {definition}")

    for key, value in DEFAULT_SETTINGS.items():
        cursor.execute(
            "INSERT IGNORE INTO ml_settings (setting_key, setting_value) VALUES (%s, %s)",
            (key, str(value)),
        )
    conn.commit()
    cursor.close()


def load_settings(conn=None) -> dict[str, Any]:
    own = conn is None
    conn = conn or get_connection()
    ensure_ml_schema(conn)
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT setting_key, setting_value FROM ml_settings")
    raw = {row["setting_key"]: row["setting_value"] for row in cursor.fetchall()}
    cursor.close()
    if own:
        conn.close()

    settings = dict(DEFAULT_SETTINGS)
    settings.update(raw)
    int_keys = {
        "minimum_history_days",
        "preferred_history_days",
        "minimum_nonzero_sales_days",
        "history_window_days",
        "forecast_period_days",
        "n_estimators",
        "max_depth",
        "min_samples_split",
        "min_samples_leaf",
        "retrain_frequency_days",
        "retrain_new_sales_records",
        "prediction_interval_lower",
        "prediction_interval_upper",
    }
    float_keys = {"accuracy_threshold_wape"}
    for key in int_keys:
        try:
            settings[key] = int(settings[key])
        except (TypeError, ValueError):
            settings[key] = int(DEFAULT_SETTINGS[key])
    for key in float_keys:
        try:
            settings[key] = float(settings[key])
        except (TypeError, ValueError):
            settings[key] = float(DEFAULT_SETTINGS[key])
    return settings


def start_training_run(conn, trigger_type: str) -> int:
    cursor = conn.cursor()
    cursor.execute(
        """
        INSERT INTO model_training_runs
            (model_name, model_version, trigger_type, status, host_name)
        VALUES (%s, %s, %s, 'running', %s)
        """,
        (MODEL_NAME, MODEL_VERSION, trigger_type, socket.gethostname()[:150]),
    )
    run_id = int(cursor.lastrowid)
    conn.commit()
    cursor.close()
    return run_id


def finish_training_run(
    conn,
    run_id: int,
    status: str,
    started: float,
    metrics: dict[str, Any] | None = None,
    error: str | None = None,
) -> None:
    metrics = metrics or {}
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE model_training_runs
        SET status = %s,
            completed_at = NOW(),
            duration_seconds = %s,
            sales_records_used = %s,
            eligible_products = %s,
            metrics_json = %s,
            error_message = %s
        WHERE training_run_id = %s
        """,
        (
            status,
            round(time.monotonic() - started, 2),
            int(metrics.get("sales_records_used", 0) or 0),
            int(metrics.get("eligible_products", 0) or 0),
            json.dumps(metrics, default=str),
            error[:4000] if error else None,
            run_id,
        ),
    )
    conn.commit()
    cursor.close()


def load_product_catalog(conn) -> pd.DataFrame:
    query = """
        SELECT
            p.product_id,
            CONCAT('P', p.product_id) AS product_key,
            COALESCE(c.category_name, 'Uncategorized') AS category,
            MAX(COALESCE(p.reorder_level, 10)) AS reorder_level,
            MAX(COALESCE(p.supplier_lead_time_days, 7)) AS supplier_lead_time_days,
            MAX(COALESCE(p.safety_stock, 0)) AS safety_stock,
            MAX(COALESCE(p.minimum_order_quantity, 1)) AS minimum_order_quantity,
            MAX(COALESCE(p.units_per_package, 1)) AS units_per_package,
            MAX(COALESCE(NULLIF(p.preferred_supplier, ''), p.supplier, '')) AS preferred_supplier,
            MIN(DATE(s.sale_date)) AS first_sale_day,
            MAX(DATE(s.sale_date)) AS last_sale_day,
            COUNT(DISTINCT DATE(s.sale_date)) AS nonzero_sales_days,
            COUNT(si.sale_item_id) AS sale_item_records
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN sale_items si ON si.product_id = p.product_id
        LEFT JOIN sales s ON s.sale_id = si.sale_id
        WHERE p.status = 'active'
        GROUP BY p.product_id, c.category_name
        HAVING first_sale_day IS NOT NULL
        ORDER BY p.product_id
    """
    return pd.read_sql(query, conn)


def load_sales_totals(conn) -> pd.DataFrame:
    query = """
        SELECT si.product_id, DATE(s.sale_date) AS sale_day, SUM(si.quantity) AS qty_sold
        FROM sale_items si
        JOIN sales s ON s.sale_id = si.sale_id
        GROUP BY si.product_id, DATE(s.sale_date)
        ORDER BY si.product_id, sale_day
    """
    return pd.read_sql(query, conn)


def build_complete_daily_history(conn, settings: dict[str, Any]) -> tuple[pd.DataFrame, pd.DataFrame]:
    catalog = load_product_catalog(conn)
    sales = load_sales_totals(conn)
    if catalog.empty:
        return pd.DataFrame(), catalog

    sales["sale_day"] = pd.to_datetime(sales["sale_day"])
    today = pd.Timestamp(date.today())
    earliest_allowed = today - pd.Timedelta(days=max(30, settings["history_window_days"]) - 1)
    frames: list[pd.DataFrame] = []

    for row in catalog.to_dict("records"):
        first_day = max(pd.Timestamp(row["first_sale_day"]), earliest_allowed)
        if first_day > today:
            continue
        dates = pd.date_range(first_day, today, freq="D")
        frame = pd.DataFrame({"sale_day": dates})
        frame["product_id"] = int(row["product_id"])
        frame["product_key"] = str(row["product_key"])
        frame["category"] = str(row["category"] or "Uncategorized")
        for column in [
            "reorder_level",
            "supplier_lead_time_days",
            "safety_stock",
            "minimum_order_quantity",
            "units_per_package",
            "preferred_supplier",
        ]:
            frame[column] = row[column]
        product_sales = sales[sales["product_id"] == row["product_id"]][["sale_day", "qty_sold"]]
        frame = frame.merge(product_sales, on="sale_day", how="left")
        frame["qty_sold"] = frame["qty_sold"].fillna(0).astype(float)
        frames.append(frame)

    history = pd.concat(frames, ignore_index=True) if frames else pd.DataFrame()
    return history, catalog


def _extra_holidays(settings: dict[str, Any]) -> set[str]:
    values = str(settings.get("holiday_dates", "") or "").replace(";", ",").split(",")
    return {value.strip() for value in values if value.strip()}


def is_holiday_day(day: pd.Timestamp, extras: set[str]) -> int:
    fixed = {
        (1, 1),
        (4, 9),
        (5, 1),
        (6, 12),
        (11, 1),
        (11, 30),
        (12, 8),
        (12, 24),
        (12, 25),
        (12, 30),
        (12, 31),
    }
    return int((day.month, day.day) in fixed or day.strftime("%Y-%m-%d") in extras)


def add_calendar_features(df: pd.DataFrame, settings: dict[str, Any]) -> pd.DataFrame:
    result = df.copy()
    result["sale_day"] = pd.to_datetime(result["sale_day"])
    extras = _extra_holidays(settings)
    result["day_of_week"] = result["sale_day"].dt.dayofweek
    result["week_of_year"] = result["sale_day"].dt.isocalendar().week.astype(int)
    result["month"] = result["sale_day"].dt.month
    result["day_of_month"] = result["sale_day"].dt.day
    result["is_weekend"] = (result["day_of_week"] >= 5).astype(int)
    last_days = result["sale_day"].map(lambda x: calendar.monthrange(x.year, x.month)[1])
    result["is_payday"] = ((result["day_of_month"] == 15) | (result["day_of_month"] == last_days)).astype(int)
    result["is_holiday"] = result["sale_day"].map(lambda x: is_holiday_day(x, extras))
    return result


def build_features(df: pd.DataFrame, settings: dict[str, Any]) -> pd.DataFrame:
    result = add_calendar_features(df, settings)
    result = result.sort_values(["product_id", "sale_day"]).reset_index(drop=True)
    grouped = result.groupby("product_id", group_keys=False)["qty_sold"]
    result["lag_1"] = grouped.shift(1)
    result["lag_7"] = grouped.shift(7)
    result["rolling_mean_7"] = grouped.transform(lambda s: s.shift(1).rolling(7, min_periods=1).mean())
    result["rolling_mean_14"] = grouped.transform(lambda s: s.shift(1).rolling(14, min_periods=1).mean())
    result["rolling_mean_30"] = grouped.transform(lambda s: s.shift(1).rolling(30, min_periods=1).mean())
    result["rolling_std_7"] = grouped.transform(lambda s: s.shift(1).rolling(7, min_periods=2).std())
    result["trend_7_30"] = result["rolling_mean_7"] - result["rolling_mean_30"]
    for column in NUMERIC_FEATURES:
        result[column] = pd.to_numeric(result[column], errors="coerce").fillna(0.0)
    result["category"] = result["category"].fillna("Uncategorized").astype(str)
    result["product_key"] = result["product_key"].astype(str)
    return result


def eligible_product_ids(history: pd.DataFrame, catalog: pd.DataFrame, settings: dict[str, Any]) -> list[int]:
    if history.empty or catalog.empty:
        return []
    calendar_days = history.groupby("product_id")["sale_day"].nunique()
    nonzero_map = catalog.set_index("product_id")["nonzero_sales_days"].fillna(0).astype(int)
    return [
        int(product_id)
        for product_id, days in calendar_days.items()
        if int(days) >= settings["minimum_history_days"]
        and int(nonzero_map.get(product_id, 0)) >= settings["minimum_nonzero_sales_days"]
    ]


def build_model(settings: dict[str, Any]) -> Pipeline:
    preprocessor = ColumnTransformer(
        transformers=[
            ("numeric", "passthrough", NUMERIC_FEATURES),
            (
                "categorical",
                OneHotEncoder(handle_unknown="ignore", sparse_output=True),
                CATEGORICAL_FEATURES,
            ),
        ]
    )
    regressor = RandomForestRegressor(
        n_estimators=max(50, settings["n_estimators"]),
        max_depth=max(2, settings["max_depth"]),
        min_samples_split=max(2, settings["min_samples_split"]),
        min_samples_leaf=max(1, settings["min_samples_leaf"]),
        random_state=42,
        n_jobs=-1,
        bootstrap=True,
        oob_score=True,
    )
    return Pipeline([("preprocessor", preprocessor), ("regressor", regressor)])


def chronological_split(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    unique_days = sorted(pd.to_datetime(df["sale_day"].unique()))
    if len(unique_days) < 10:
        split = max(1, int(len(df) * 0.8))
        return df.iloc[:split], df.iloc[split:]
    eval_days = max(7, int(len(unique_days) * 0.2))
    cutoff = unique_days[-eval_days]
    train = df[df["sale_day"] < cutoff]
    evaluation = df[df["sale_day"] >= cutoff]
    if train.empty or evaluation.empty:
        split = max(1, min(len(df) - 1, int(len(df) * 0.8)))
        return df.iloc[:split], df.iloc[split:]
    return train, evaluation


def safe_metric(value: Any, decimals: int = 4) -> float | None:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return None
    if not math.isfinite(number):
        return None
    return round(number, decimals)


def wape(actual: Iterable[float], predicted: Iterable[float]) -> float | None:
    actual_arr = np.asarray(list(actual), dtype=float)
    predicted_arr = np.asarray(list(predicted), dtype=float)
    denominator = np.abs(actual_arr).sum()
    if denominator <= 0:
        return None
    return float(np.abs(actual_arr - predicted_arr).sum() / denominator * 100)


def smape(actual: Iterable[float], predicted: Iterable[float]) -> float | None:
    actual_arr = np.asarray(list(actual), dtype=float)
    predicted_arr = np.asarray(list(predicted), dtype=float)
    denominator = np.abs(actual_arr) + np.abs(predicted_arr)
    valid = denominator > 0
    if not np.any(valid):
        return None
    return float(np.mean(2 * np.abs(actual_arr[valid] - predicted_arr[valid]) / denominator[valid]) * 100)


def grouped_feature_importance(model: Pipeline) -> dict[str, float]:
    preprocessor = model.named_steps["preprocessor"]
    regressor = model.named_steps["regressor"]
    names = preprocessor.get_feature_names_out()
    grouped = {name: 0.0 for name in FEATURE_COLUMNS}
    for transformed, importance in zip(names, regressor.feature_importances_):
        raw = transformed.split("__", 1)[-1]
        if transformed.startswith("categorical__category_"):
            raw = "category"
        elif transformed.startswith("categorical__product_key_"):
            raw = "product_key"
        if raw in grouped:
            grouped[raw] += float(importance)
    return dict(sorted(((k, round(v, 5)) for k, v in grouped.items()), key=lambda x: x[1], reverse=True))


def save_metrics(metrics: dict[str, Any]) -> None:
    METRICS_PATH.write_text(json.dumps(metrics, indent=2, default=str), encoding="utf-8")


def load_metrics() -> dict[str, Any]:
    try:
        value = json.loads(METRICS_PATH.read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def save_artifact(model: Pipeline, settings: dict[str, Any], metrics: dict[str, Any]) -> None:
    joblib.dump(
        {
            "model": model,
            "model_version": MODEL_VERSION,
            "model_name": MODEL_NAME,
            "feature_columns": FEATURE_COLUMNS,
            "settings": settings,
            "metrics": metrics,
            "saved_at": datetime.now().isoformat(timespec="seconds"),
        },
        MODEL_PATH,
    )


def load_artifact() -> dict[str, Any] | None:
    if not MODEL_PATH.exists():
        return None
    try:
        artifact = joblib.load(MODEL_PATH)
    except (OSError, EOFError, ValueError, TypeError):
        return None
    if not isinstance(artifact, dict) or artifact.get("model_version") != MODEL_VERSION:
        return None
    model = artifact.get("model")
    regressor = getattr(model, "named_steps", {}).get("regressor")
    if regressor is None or regressor.__class__.__name__ != "RandomForestRegressor":
        return None
    return artifact


def prediction_distribution(model: Pipeline, features: pd.DataFrame) -> np.ndarray:
    transformed = model.named_steps["preprocessor"].transform(features)
    estimators = model.named_steps["regressor"].estimators_
    return np.asarray([tree.predict(transformed)[0] for tree in estimators], dtype=float)


def build_future_row(
    product_id: int,
    product_key: str,
    category: str,
    future_day: pd.Timestamp,
    demand_history: list[float],
    settings: dict[str, Any],
) -> pd.DataFrame:
    def lag(days: int) -> float:
        return float(demand_history[-days]) if len(demand_history) >= days else 0.0

    def mean(days: int) -> float:
        values = demand_history[-days:]
        return float(np.mean(values)) if values else 0.0

    values7 = demand_history[-7:]
    rolling_std = float(np.std(values7, ddof=1)) if len(values7) >= 2 else 0.0
    extras = _extra_holidays(settings)
    last_day = calendar.monthrange(future_day.year, future_day.month)[1]
    row = {
        "lag_1": lag(1),
        "lag_7": lag(7),
        "rolling_mean_7": mean(7),
        "rolling_mean_14": mean(14),
        "rolling_mean_30": mean(30),
        "rolling_std_7": rolling_std,
        "trend_7_30": mean(7) - mean(30),
        "day_of_week": future_day.dayofweek,
        "week_of_year": int(future_day.isocalendar().week),
        "month": future_day.month,
        "day_of_month": future_day.day,
        "is_weekend": int(future_day.dayofweek >= 5),
        "is_payday": int(future_day.day == 15 or future_day.day == last_day),
        "is_holiday": is_holiday_day(future_day, extras),
        "category": category,
        "product_key": product_key,
    }
    return pd.DataFrame([row], columns=FEATURE_COLUMNS)


def forecast_product(
    model: Pipeline,
    product_history: pd.DataFrame,
    settings: dict[str, Any],
    forecast_days: int,
) -> ForecastResult:
    rows = product_history.sort_values("sale_day")
    last = rows.iloc[-1]
    product_id = int(last["product_id"])
    product_key = str(last["product_key"])
    category = str(last["category"] or "Uncategorized")
    demand_history = [float(value) for value in rows["qty_sold"].tolist()]
    lower_q = max(0, min(49, settings["prediction_interval_lower"]))
    upper_q = max(51, min(100, settings["prediction_interval_upper"]))
    start_day = pd.Timestamp(date.today())
    daily: list[dict[str, Any]] = []

    for offset in range(1, forecast_days + 1):
        day = start_day + pd.Timedelta(days=offset)
        features = build_future_row(product_id, product_key, category, day, demand_history, settings)
        tree_predictions = np.clip(prediction_distribution(model, features), 0, None)
        predicted = float(np.mean(tree_predictions))
        lower = float(np.percentile(tree_predictions, lower_q))
        upper = float(np.percentile(tree_predictions, upper_q))
        daily.append({"date": day.strftime("%Y-%m-%d"), "prediction": predicted, "lower": lower, "upper": upper})
        demand_history.append(predicted)

    def total(key: str, days: int) -> int:
        return max(0, int(round(sum(float(row[key]) for row in daily[:days]))))

    demand_7 = total("prediction", min(7, len(daily)))
    demand_30 = total("prediction", min(30, len(daily)))
    lower_7 = total("lower", min(7, len(daily)))
    upper_7 = total("upper", min(7, len(daily)))
    lower_30 = total("lower", min(30, len(daily)))
    upper_30 = total("upper", min(30, len(daily)))
    lead_days = max(0, int(last.get("supplier_lead_time_days", 7) or 7))
    lead_days = min(lead_days, len(daily))
    lead_demand = total("prediction", lead_days)
    lead_lower = total("lower", lead_days)
    lead_upper = total("upper", lead_days)
    interval_width = max(0, upper_30 - lower_30)
    confidence = max(0.05, min(0.99, 1 - (interval_width / max(upper_30, 1))))

    return ForecastResult(
        demand_7d=demand_7,
        demand_30d=demand_30,
        lower_7d=lower_7,
        upper_7d=upper_7,
        lower_30d=lower_30,
        upper_30d=upper_30,
        lead_time_demand=lead_demand,
        lead_time_lower=lead_lower,
        lead_time_upper=lead_upper,
        confidence=round(confidence, 2),
        daily=daily,
    )


def round_order_quantity(raw: float, minimum: int, package: int) -> int:
    if raw <= 0:
        return 0
    quantity = max(int(math.ceil(raw)), max(1, int(minimum)))
    package = max(1, int(package))
    return int(math.ceil(quantity / package) * package)




def compact_metrics(metrics: dict[str, Any]) -> dict[str, Any]:
    """Return a database-safe summary; product detail lives in forecast_evaluations."""
    return {key: value for key, value in metrics.items() if key != "product_metrics"}

def create_forecast_run(cursor, metrics: dict[str, Any], forecast_period_days: int) -> int:
    cursor.execute(
        """
        INSERT INTO forecast_runs (model_name, model_version, forecast_period_days, evaluation_result)
        VALUES (%s, %s, %s, %s)
        """,
        (MODEL_NAME, MODEL_VERSION, forecast_period_days, json.dumps(compact_metrics(metrics), default=str)),
    )
    return int(cursor.lastrowid)


def incoming_stock(cursor, product_id: int) -> int:
    cursor.execute(
        """
        SELECT COALESCE(SUM(GREATEST(rr.request_qty - COALESCE(r.received_to_date, 0), 0)), 0)
        FROM replenishment_requests rr
        LEFT JOIN (
            SELECT replenishment_request_id, SUM(accepted_qty) AS received_to_date
            FROM stock_receiving
            WHERE replenishment_request_id IS NOT NULL
            GROUP BY replenishment_request_id
        ) r ON r.replenishment_request_id = rr.request_id
        WHERE rr.product_id = %s AND rr.status IN ('approved','partially_received')
        """,
        (product_id,),
    )
    row = cursor.fetchone()
    return int(row[0] or 0) if row else 0


def update_actual_demand(conn) -> None:
    cursor = conn.cursor()
    cursor.execute(
        """
        UPDATE stock_predictions sp
        SET sp.actual_demand = (
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM sale_items si
            JOIN sales s ON s.sale_id = si.sale_id
            WHERE si.product_id = sp.product_id
              AND s.sale_date > sp.generated_at
              AND s.sale_date <= DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY)
        )
        WHERE sp.actual_demand IS NULL
          AND DATE_ADD(sp.generated_at, INTERVAL sp.forecast_period_days DAY) <= NOW()
        """
    )
    conn.commit()
    cursor.close()


def save_product_evaluations(conn, training_run_id: int, eval_df: pd.DataFrame, predictions: np.ndarray) -> list[dict[str, Any]]:
    result = eval_df[["product_id", "qty_sold"]].copy()
    result["predicted"] = np.clip(predictions, 0, None)
    rows: list[dict[str, Any]] = []
    cursor = conn.cursor()
    for product_id, group in result.groupby("product_id"):
        actual = group["qty_sold"].to_numpy(dtype=float)
        predicted = group["predicted"].to_numpy(dtype=float)
        metrics = {
            "product_id": int(product_id),
            "evaluation_records": int(len(group)),
            "actual_total": round(float(actual.sum()), 2),
            "predicted_total": round(float(predicted.sum()), 2),
            "mae": safe_metric(mean_absolute_error(actual, predicted)),
            "rmse": safe_metric(math.sqrt(mean_squared_error(actual, predicted))),
            "wape": safe_metric(wape(actual, predicted)),
            "smape": safe_metric(smape(actual, predicted)),
        }
        rows.append(metrics)
        cursor.execute(
            """
            INSERT INTO forecast_evaluations
                (training_run_id, product_id, evaluation_records, actual_total, predicted_total, mae, rmse, wape, smape)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                training_run_id,
                metrics["product_id"],
                metrics["evaluation_records"],
                metrics["actual_total"],
                metrics["predicted_total"],
                metrics["mae"],
                metrics["rmse"],
                metrics["wape"],
                metrics["smape"],
            ),
        )
    conn.commit()
    cursor.close()
    return sorted(rows, key=lambda item: (item["wape"] is None, item["wape"] or 0))


def write_predictions(conn, history: pd.DataFrame, model: Pipeline, metrics: dict[str, Any], settings: dict[str, Any]) -> None:
    cursor = conn.cursor()
    forecast_days = max(30, settings["forecast_period_days"])
    run_id = create_forecast_run(cursor, metrics, settings["forecast_period_days"])

    for product_id, product_history in history.groupby("product_id"):
        last = product_history.sort_values("sale_day").iloc[-1]
        lead_days = max(0, int(last.get("supplier_lead_time_days", 7) or 7))
        result = forecast_product(model, product_history, settings, max(forecast_days, lead_days))
        cursor.execute("SELECT COALESCE(quantity_on_hand, 0) FROM inventory WHERE product_id = %s", (int(product_id),))
        stock_row = cursor.fetchone()
        current_stock = int(stock_row[0] or 0) if stock_row else 0
        incoming = incoming_stock(cursor, int(product_id))
        safety = max(0, int(last.get("safety_stock", 0) or 0))
        minimum = max(1, int(last.get("minimum_order_quantity", 1) or 1))
        package = max(1, int(last.get("units_per_package", 1) or 1))
        raw = result.lead_time_demand + safety - current_stock - incoming
        suggested = round_order_quantity(raw, minimum, package)
        explanation = {
            "lead_time_demand": result.lead_time_demand,
            "safety_stock": safety,
            "current_stock": current_stock,
            "incoming_stock": incoming,
            "raw_suggested_quantity": raw,
            "minimum_order_quantity": minimum,
            "units_per_package": package,
            "final_suggested_quantity": suggested,
            "formula": "lead_time_demand + safety_stock - current_stock - incoming_stock",
        }
        cursor.execute(
            """
            INSERT INTO stock_predictions (
                forecast_run_id, product_id, forecast_period_days, forecast_value,
                predicted_demand_next_7_days, predicted_demand_next_30_days,
                lower_bound_7_days, upper_bound_7_days,
                lower_bound_30_days, upper_bound_30_days,
                forecasted_demand_during_lead_time, lead_time_lower_bound, lead_time_upper_bound,
                actual_demand, evaluation_result, supplier_lead_time_days,
                safety_stock_used, current_stock_used, incoming_stock_used,
                minimum_order_quantity_used, units_per_package_used,
                reorder_suggested, suggested_reorder_qty, confidence_score,
                model_version, forecast_explanation
            ) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
            """,
            (
                run_id,
                int(product_id),
                settings["forecast_period_days"],
                result.demand_30d,
                result.demand_7d,
                result.demand_30d,
                result.lower_7d,
                result.upper_7d,
                result.lower_30d,
                result.upper_30d,
                result.lead_time_demand,
                result.lead_time_lower,
                result.lead_time_upper,
                None,
                json.dumps(compact_metrics(metrics), default=str),
                lead_days,
                safety,
                current_stock,
                incoming,
                minimum,
                package,
                suggested > 0,
                suggested,
                result.confidence,
                MODEL_VERSION,
                json.dumps(explanation),
            ),
        )
    conn.commit()
    cursor.close()


def empty_metrics(settings: dict[str, Any], status: str) -> dict[str, Any]:
    return {
        "model_name": MODEL_NAME,
        "model_version": MODEL_VERSION,
        "model_type": MODEL_TYPE,
        "model_description": MODEL_DESCRIPTION,
        "training_date": datetime.now().isoformat(timespec="seconds"),
        "sales_records_used": 0,
        "evaluation_records": 0,
        "eligible_products": 0,
        "feature_importance": {},
        "mean_absolute_error": None,
        "root_mean_squared_error": None,
        "wape": None,
        "smape": None,
        "r2_score": None,
        "oob_score": None,
        "baseline_mae": None,
        "baseline_wape": None,
        "model_improvement_vs_baseline_pct": None,
        "model_beats_baseline": None,
        "settings": settings,
        "status": status,
    }


def train_and_predict(trigger_type: str = "cli") -> dict[str, Any]:
    started = time.monotonic()
    conn = get_connection()
    ensure_ml_schema(conn)
    settings = load_settings(conn)
    run_id = start_training_run(conn, trigger_type)

    try:
        update_actual_demand(conn)
        count_cursor = conn.cursor()
        count_cursor.execute("SELECT COUNT(*) FROM sale_items")
        source_sale_item_records = int(count_cursor.fetchone()[0])
        count_cursor.close()
        history, catalog = build_complete_daily_history(conn, settings)
        if history.empty:
            metrics = empty_metrics(settings, "No sales history is available.")
            save_metrics(metrics)
            finish_training_run(conn, run_id, "skipped", started, metrics)
            return metrics

        eligible = eligible_product_ids(history, catalog, settings)
        eligible_history = history[history["product_id"].isin(eligible)].copy()
        if eligible_history.empty:
            metrics = empty_metrics(
                settings,
                "No products meet the minimum calendar-history and nonzero-sales requirements.",
            )
            save_metrics(metrics)
            finish_training_run(conn, run_id, "skipped", started, metrics)
            return metrics

        featured = build_features(eligible_history, settings)
        train_df, eval_df = chronological_split(featured)
        model = build_model(settings)
        model.fit(train_df[FEATURE_COLUMNS], train_df["qty_sold"])
        predictions = np.clip(model.predict(eval_df[FEATURE_COLUMNS]), 0, None)
        actual = eval_df["qty_sold"].to_numpy(dtype=float)
        baseline_predictions = np.clip(eval_df["rolling_mean_7"].to_numpy(dtype=float), 0, None)
        model_wape = safe_metric(wape(actual, predictions))
        baseline_wape_value = safe_metric(wape(actual, baseline_predictions))
        baseline_mae_value = safe_metric(mean_absolute_error(actual, baseline_predictions))
        improvement = None
        if baseline_wape_value not in (None, 0) and model_wape is not None:
            improvement = round(((baseline_wape_value - model_wape) / baseline_wape_value) * 100, 2)
        regressor = model.named_steps["regressor"]

        product_metrics = save_product_evaluations(conn, run_id, eval_df, predictions)
        metrics = {
            "model_name": MODEL_NAME,
            "model_version": MODEL_VERSION,
            "model_type": MODEL_TYPE,
            "model_description": MODEL_DESCRIPTION,
            "training_date": datetime.now().isoformat(timespec="seconds"),
            "training_run_id": run_id,
            "sales_records_used": int(len(featured)),
            "training_records": int(len(train_df)),
            "evaluation_records": int(len(eval_df)),
            "eligible_products": int(len(eligible)),
            "zero_sales_records": int((featured["qty_sold"] == 0).sum()),
            "source_sale_item_records": source_sale_item_records,
            "mean_absolute_error": safe_metric(mean_absolute_error(actual, predictions)),
            "root_mean_squared_error": safe_metric(math.sqrt(mean_squared_error(actual, predictions))),
            "wape": model_wape,
            "baseline_mae": baseline_mae_value,
            "baseline_wape": baseline_wape_value,
            "model_improvement_vs_baseline_pct": improvement,
            "model_beats_baseline": bool(model_wape is not None and baseline_wape_value is not None and model_wape < baseline_wape_value),
            "smape": safe_metric(smape(actual, predictions)),
            "r2_score": safe_metric(r2_score(actual, predictions)) if len(actual) > 1 else None,
            "oob_score": safe_metric(getattr(regressor, "oob_score_", None)),
            "feature_importance": grouped_feature_importance(model),
            "hyperparameters": regressor.get_params(),
            "settings": settings,
            "product_metrics": product_metrics,
            "status": "completed",
        }
        save_artifact(model, settings, metrics)
        save_metrics(metrics)
        write_predictions(conn, eligible_history, model, metrics, settings)
        finish_training_run(conn, run_id, "completed", started, metrics)
        return metrics
    except Exception as exc:
        finish_training_run(conn, run_id, "failed", started, error=str(exc))
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    outcome = train_and_predict(os.getenv("ML_TRIGGER_TYPE", "cli"))
    print(json.dumps(outcome, indent=2, default=str))
