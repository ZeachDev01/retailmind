"""Scheduled retraining guard.

Run hourly or daily using Task Scheduler/cron. It retrains only when the model is
missing, the configured age is exceeded, enough new sale-item records exist, or
WAPE is above the configured threshold.
"""
from __future__ import annotations

import json
from datetime import datetime

from db import get_connection
import train_model


def should_retrain() -> tuple[bool, list[str]]:
    conn = get_connection()
    try:
        train_model.ensure_ml_schema(conn)
        settings = train_model.load_settings(conn)
        metrics = train_model.load_metrics()
        reasons: list[str] = []
        if train_model.load_artifact() is None:
            reasons.append("model artifact missing or incompatible")

        training_date = metrics.get("training_date")
        if training_date:
            try:
                age_days = (datetime.now() - datetime.fromisoformat(training_date)).total_seconds() / 86400
                if age_days >= settings["retrain_frequency_days"]:
                    reasons.append(f"model is {age_days:.1f} days old")
            except ValueError:
                reasons.append("training date is invalid")
        else:
            reasons.append("no previous training date")

        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM sale_items")
        current_records = int(cursor.fetchone()[0])
        cursor.close()
        prior_records = int(metrics.get("source_sale_item_records", metrics.get("sales_records_used", 0)) or 0)
        if current_records - prior_records >= settings["retrain_new_sales_records"]:
            reasons.append(f"{current_records - prior_records} new sale-item records")

        current_wape = metrics.get("wape")
        if current_wape is not None and float(current_wape) > settings["accuracy_threshold_wape"]:
            reasons.append(f"WAPE {float(current_wape):.2f}% exceeds threshold")
        return bool(reasons), reasons
    finally:
        conn.close()


if __name__ == "__main__":
    retrain, reasons = should_retrain()
    if retrain:
        outcome = train_model.train_and_predict("automatic")
        print(json.dumps({"retrained": True, "reasons": reasons, "metrics": outcome}, indent=2, default=str))
    else:
        print(json.dumps({"retrained": False, "reasons": []}, indent=2))
