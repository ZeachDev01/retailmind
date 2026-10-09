import numpy as np

def calculate_metrics(actual: np.ndarray, predicted: np.ndarray) -> dict:
    mae = np.mean(np.abs(actual - predicted))
    rmse = np.sqrt(np.mean((actual - predicted)**2))
    
    total_actual = np.sum(actual)
    
    if total_actual == 0:
        wape = None
        missing_metric_reason = "Zero actual demand"
    else:
        wape = np.sum(np.abs(actual - predicted)) / total_actual
        missing_metric_reason = None
        
    return {
        'mae': mae,
        'rmse': rmse,
        'wape': wape,
        'missing_metric_reason': missing_metric_reason
    }
