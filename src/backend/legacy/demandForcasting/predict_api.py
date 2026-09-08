"""Authenticated Flask API for Random Forest forecasts and retraining."""
from __future__ import annotations

import hmac
import json
import os
import time
from pathlib import Path

from flask import Flask, jsonify, request

from db import get_connection
import train_model

app = Flask(__name__)
RETRAIN_LOCK = Path(__file__).resolve().parent / ".api-retrain.lock"


def expected_api_key() -> str:
    return os.getenv("ML_API_KEY", "").strip()


def api_key_is_valid() -> bool:
    expected = expected_api_key()
    supplied = (request.headers.get("X-API-Key") or request.headers.get("Authorization", "").removeprefix("Bearer ")).strip()
    return bool(expected) and bool(supplied) and hmac.compare_digest(expected, supplied)


@app.before_request
def require_api_key():
    if request.endpoint in {"health", "index", "static"} or request.method == "OPTIONS":
        return None
    if not api_key_is_valid():
        return jsonify({"success": False, "message": "Unauthorized ML API request."}), 401
    return None


@app.get("/")
def index():
    return jsonify(
        {
            "success": True,
            "service": "RetailMind Demand Forecasting API",
            "model": train_model.MODEL_NAME,
            "model_version": train_model.MODEL_VERSION,
            "authentication": "X-API-Key required for prediction, metrics, and retraining endpoints",
        }
    )


@app.get("/health")
def health():
    artifact = train_model.load_artifact()
    return jsonify(
        {
            "success": True,
            "status": "healthy",
            "model_loaded": artifact is not None,
            "model_version": train_model.MODEL_VERSION,
        }
    )


def product_history(product_id: int):
    conn = get_connection()
    try:
        train_model.ensure_ml_schema(conn)
        settings = train_model.load_settings(conn)
        history, catalog = train_model.build_complete_daily_history(conn, settings)
        product = catalog[catalog["product_id"] == product_id]
        rows = history[history["product_id"] == product_id]
        return conn, settings, product, rows
    except Exception:
        conn.close()
        raise


@app.post("/predict")
def predict():
    payload = request.get_json(silent=True) or {}
    try:
        product_id = int(payload.get("product_id", 0))
    except (TypeError, ValueError):
        product_id = 0
    if product_id <= 0:
        return jsonify({"success": False, "message": "A valid product_id is required."}), 400

    artifact = train_model.load_artifact()
    if artifact is None:
        return jsonify({"success": False, "message": "No compatible trained Random Forest model is available."}), 503

    conn, settings, catalog, history = product_history(product_id)
    try:
        if catalog.empty or history.empty:
            return jsonify({"success": False, "message": "Product or sales history was not found."}), 404
        eligible = train_model.eligible_product_ids(history, catalog, settings)
        if product_id not in eligible:
            calendar_days = int(history["sale_day"].nunique())
            nonzero = int(catalog.iloc[0].get("nonzero_sales_days", 0) or 0)
            return jsonify(
                {
                    "success": False,
                    "forecast_status": "Insufficient Data",
                    "calendar_history_days": calendar_days,
                    "nonzero_sales_days": nonzero,
                    "minimum_history_days": settings["minimum_history_days"],
                    "minimum_nonzero_sales_days": settings["minimum_nonzero_sales_days"],
                    "message": "More complete daily history is required before forecasting this product.",
                }
            ), 422

        model = artifact["model"]
        lead_days = int(history.iloc[-1].get("supplier_lead_time_days", 7) or 7)
        result = train_model.forecast_product(model, history, settings, max(30, lead_days))
        return jsonify(
            {
                "success": True,
                "product_id": product_id,
                "model_name": train_model.MODEL_NAME,
                "model_version": train_model.MODEL_VERSION,
                "predicted_demand_next_7_days": result.demand_7d,
                "predicted_demand_next_30_days": result.demand_30d,
                "prediction_interval_7_days": [result.lower_7d, result.upper_7d],
                "prediction_interval_30_days": [result.lower_30d, result.upper_30d],
                "lead_time_demand": result.lead_time_demand,
                "lead_time_prediction_interval": [result.lead_time_lower, result.lead_time_upper],
                "confidence_score": result.confidence,
                "daily_forecast": result.daily,
            }
        )
    finally:
        conn.close()


@app.get("/metrics")
def metrics():
    data = train_model.load_metrics()
    return jsonify({"success": bool(data), "metrics": data})


@app.post("/retrain")
def retrain():
    # File lock prevents two web requests from training simultaneously.
    if RETRAIN_LOCK.exists() and time.time() - RETRAIN_LOCK.stat().st_mtime < 3600:
        return jsonify({"success": False, "message": "A model retraining job is already running."}), 409
    RETRAIN_LOCK.write_text(str(time.time()), encoding="utf-8")
    try:
        result = train_model.train_and_predict("manual")
        completed = result.get("status") == "completed"
        return jsonify(
            {
                "success": completed,
                "message": "Random Forest model retrained successfully." if completed else result.get("status", "Training was skipped."),
                "metrics": result,
            }
        ), 200 if completed else 422
    except Exception as exc:
        app.logger.exception("Retraining failed")
        return jsonify({"success": False, "message": "Random Forest retraining failed.", "error": str(exc)}), 500
    finally:
        RETRAIN_LOCK.unlink(missing_ok=True)


if __name__ == "__main__":
    app.run(
        host=os.getenv("ML_API_HOST", "127.0.0.1"),
        port=int(os.getenv("ML_API_PORT", "5000")),
        debug=False,
    )
