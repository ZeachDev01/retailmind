import numpy as np

def calculate_metrics(actual: np.ndarray, predicted: np.ndarray) -> dict:
    actual = np.asarray(actual, dtype=float)
    predicted = np.asarray(predicted, dtype=float)
    
    population = int(len(actual))
    if population == 0:
        return {
            'mae': None,
            'rmse': None,
            'wape': None,
            'population': 0,
            'underprediction_count': 0,
            'overprediction_count': 0,
            'exact_count': 0,
            'underprediction_units': 0.0,
            'overprediction_units': 0.0,
            'missing_metric_reason': "Insufficient coverage"
        }
        
    diff = predicted - actual
    abs_diff = np.abs(diff)
    
    mae = float(np.mean(abs_diff))
    rmse = float(np.sqrt(np.mean(diff ** 2)))
    total_actual = float(np.sum(actual))
    
    under_mask = predicted < actual
    over_mask = predicted > actual
    exact_mask = predicted == actual
    
    underprediction_count = int(np.sum(under_mask))
    overprediction_count = int(np.sum(over_mask))
    exact_count = int(np.sum(exact_mask))
    
    underprediction_units = float(np.sum(np.maximum(0.0, actual - predicted)))
    overprediction_units = float(np.sum(np.maximum(0.0, predicted - actual)))
    
    if total_actual == 0:
        wape = None
        missing_metric_reason = "Zero actual demand"
    elif not np.isfinite(total_actual) or total_actual < 0:
        wape = None
        missing_metric_reason = "Invalid actual demand total"
    else:
        wape = float(np.sum(abs_diff) / total_actual)
        missing_metric_reason = None
        
    return {
        'mae': mae,
        'rmse': rmse,
        'wape': wape,
        'population': population,
        'underprediction_count': underprediction_count,
        'overprediction_count': overprediction_count,
        'exact_count': exact_count,
        'underprediction_units': underprediction_units,
        'overprediction_units': overprediction_units,
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
        steps_back = season_length - (i % season_length)
        if len(history) >= steps_back:
            forecast[i] = history[-steps_back]
        elif len(history) > 0:
            forecast[i] = history[-1]
        else:
            forecast[i] = 0.0
            
    return forecast
