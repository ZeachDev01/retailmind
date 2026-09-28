# Use one asynchronous Random Forest forecasting pipeline

RetailMind will use one global scikit-learn Random Forest model to produce per-product daily Demand Forecast values that are summed into fixed 7-, 14-, and 28-day Forecast Horizons. Forecast generation and Model Retraining run outside interactive PHP requests: PHP records a database job, a scheduled Python CLI worker processes it after Store close, and PHP reads persisted results. The existing Flask forecasting service and synchronous request-time retraining path will be retired when this pipeline is implemented. We choose this design because it is straightforward to explain, deploy on a Windows Store PC or Linux VPS, and operate without coupling a user request to a long-running Python process.

The model uses one horizon-conditioned direct design, with one training row per product, forecast origin, and future day (`horizon_day` 1–28). It may use only information available at that forecast origin: lagged sales, rolling demand, calendar fields, product attributes, current or reconstructed stock context, and future price or promotion values only when already scheduled. It does not recursively feed its own predictions back as observed demand.

## Consequences

- Random Forest is the only production forecasting pipeline. K-means may support separate exploratory analysis and ARIMA may remain an academic comparison, but neither participates in production forecasts.
- Chronological rolling-origin backtests evaluate every supported Forecast Horizon. MAE is the primary reported metric, RMSE is secondary, and WAPE is retained for business comparison; FreshRetailNet may be used as a separate reality check but never as a direct accuracy test against a model trained on a different Store.
- Forecast Readiness is Insufficient below 56 history days, Low from 56–179, Medium from 180–364, and High from 365 onward only when validation error is acceptable. Lower-readiness output remains visible and clearly marked.
- Scheduled Demand Drivers may affect a forecast only when their future effective dates are already recorded. Missing days, Store closures, stockouts, reversals, and cancellations require explicit treatment instead of being silently interpreted as zero demand.
- Forecast Generation runs daily after close and may also be requested manually by the Administrator or Super Administrator. Model Retraining runs weekly after close and may be requested manually only by the Super Administrator. Every run records status, model version, data window, metrics, and initiating identity.
- Demand Forecasts and Replenishment Recommendations remain decision support. They never create purchase orders, adjust inventory, or bypass human review.
- Development may use an isolated Kaggle single-Store subset with at least two years of daily history and 20 products initially, expanding toward 50 when the data permits. Demo data and real Store data never share a database or model artifact.
