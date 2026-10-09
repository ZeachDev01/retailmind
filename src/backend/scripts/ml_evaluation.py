import numpy as np

def calculate_metrics(actual: np.ndarray, predicted: np.ndarray) -> dict:
    mae = float(np.mean(np.abs(actual - predicted)))
    rmse = float(np.sqrt(np.mean((actual - predicted)**2)))
    
    total_actual = float(np.sum(actual))
    
    if total_actual == 0:
        wape = None
        missing_metric_reason = "Zero actual demand"
    else:
        wape = float(np.sum(np.abs(actual - predicted)) / total_actual)
        missing_metric_reason = None
        
    return {
        'mae': mae,
        'rmse': rmse,
        'wape': wape,
        'missing_metric_reason': missing_metric_reason
    }

def seasonal_naive_forecast(history: np.ndarray, horizon: int, season_length: int = 7) -> np.ndarray:
    """
    Generate a seasonal naive forecast.
    If there is enough history, use the same day from the previous season.
    If not, use the last available value.
    """
    forecast = np.zeros(horizon)
    for i in range(horizon):
        # We need the value from `season_length` days ago.
        # But we might be forecasting more than one season into the future.
        # e.g., if forecasting day 8, we need day 1 of the forecast, which is day -6 of history.
        
        # Determine the historical index to pull from
        # steps back from the *start* of the forecast window
        steps_back = season_length - (i % season_length)
        
        if len(history) >= steps_back:
            forecast[i] = history[-steps_back]
        elif len(history) > 0:
            forecast[i] = history[-1]
        else:
            forecast[i] = 0.0
            
    return forecast
